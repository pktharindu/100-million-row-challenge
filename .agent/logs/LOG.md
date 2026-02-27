# Parser Optimization Knowledge Base

## Current State
- **Best parser time:** ~151ms (reported by `data:parse` stdout — THIS is the real metric, see MEASUREMENT CORRECTION below)
- **Best wall-clock:** 0.388s mean (hyperfine — includes ~237ms PHP+Tempest overhead, do NOT use for comparisons)
- **Iteration count:** 9 (REASSESSING — previous "no improvement" decisions may have been masked by wrong metric)
- **Parser architecture:** Adaptive worker count (perflevel0-based, 10 on M4 Pro, 6 on M1), socket_create_pair + socket_export_stream with 2MB SO_SNDBUF/SO_RCVBUF, unbuffered I/O (stream_set_read_buffer 0), **sequential stream_get_contents drain + inline TLV merge**, 6x loop unrolling, bucket accumulation with 2-byte date IDs, 512KB read chunks, zero-copy hot loop, 8 parallel counting workers with ksort-free iteration (idToDate), SIGKILL fast exit, 262KB child write / 512KB count write, inline JSON building, 2MB slug sample
- **Target:** ~120-136ms parser time (interpreter floor estimate). Room: ~15-31ms (10-20%)

## Bottleneck Model (UPDATED iter9 profiling)
| Phase | Time | % Internal | Serial? |
|-------|------|-----------|---------|
| setup (slugs+dates) | ~8.5ms | 5.3% | Yes |
| prefork (partition+sockets+fork) | ~4.1ms | 2.6% | Yes |
| parent_hotloop | ~107ms | 66.5% | Parallel |
| drain+merge (sequential stream_get_contents) | ~8ms (was 18.3ms with stream_select) | 5.0% | Yes |
| waitpid | ~0.2ms | 0.1% | Yes |
| count_phase (fork+count+JSON+collect+write) | ~22.5ms | 14.0% | Parallel |
| **Internal total** | **~151ms** (est) | — | — |
| PHP + Tempest overhead | **~237ms** | — | Fixed |
| **Wall time** | **~388ms** | — | — |

**Key iter9 discovery:** stream_select drain was 14.6ms, NOT 2ms as previously estimated. Root causes: multiple stream_select iterations, non-blocking mode overhead, O(n^2) string concat in buffers. Sequential stream_get_contents + inline merge: ~8ms total. C-level internal buffering avoids PHP string reallocation.

**Primary bottleneck:** parent_hotloop (~107ms, 66.5% of PARSER time). Near PHP interpreter floor (~120ns/row).
**NOT a bottleneck:** PHP/Tempest overhead (~237ms) is OUTSIDE the measured parser time. `data:parse` only times `Parser::parse()` internally. Hyperfine wall-clock includes this overhead but it is NOT optimizable. Ignore it for comparisons.
**Secondary:** count_phase (~22.5ms). Highly optimized with 8 workers + large socket buffers.

## Technique Status

| Technique | Status | Notes |
|-----------|--------|-------|
| Multi-process fork | DONE | Adaptive workers via perflevel0 sysctl |
| Bucket accumulation (T1) | DONE | Per-slug string append + unpack + array_count_values |
| 6x loop unrolling (T3) | DONE | Fence at lastNl - 720. 8x tested no improvement |
| Position-based parsing (T4) | DONE | substr with hardcoded offsets, strpos +52 skip |
| Socket pair IPC (T6) | DONE | socket_create_pair + socket_export_stream + 2MB buffers |
| Fully-qualified calls (T9) | DONE | backslash prefix on all global functions |
| gc_disable | DONE | At top of file and parse() |
| Worker count tuning (T2) | DONE | Adaptive: max(perflevel0, 6) |
| Newline skip (T8) | DONE | strpos offset +52 |
| Chunk size tuning | DONE | 512KB definitively optimal |
| Parallel counting | DONE | 8 workers for batch_count+JSON |
| Optimized JSON output | DONE | Inline JSON building, ksort-free idToDate iteration |
| Zero-copy hot loop | DONE (iter4) | Eliminated leftover.raw concatenation |
| SIGKILL fast exit | DONE (iter5) | posix_kill(SIGKILL) skips PHP shutdown |
| Large socket buffers | DONE (iter6) | socket_create_pair + 2MB buffers |
| Unbuffered I/O | DONE (iter7) | stream_set_read_buffer(fh, 0) |
| Adaptive worker count | DONE (iter7) | sysctl perflevel0 |
| Sequential drain + inline merge | DONE (iter9) | stream_get_contents replaces stream_select. -10ms |
| Work stealing (T7) | TESTED | +4.6% regression on M4 Pro |
| 8x loop unrolling | TESTED | No improvement over 6x |
| Worker-side counting | TESTED | REGRESSION at 10M scale |
| Parent-as-coordinator (iter9) | TESTED | +5.2% regression |
| Merge-during-drain via stream_select (iter9) | TESTED | +3.7% regression |
| 4 counting workers (iter9) | TESTED | +6.5% regression |

## Dead Ends
- Parent-as-coordinator (iter9 CandA): +5.2%. Extra fork overhead, coordinator competition.
- Merge-during-drain via stream_select (iter9 CandB): +3.7%. Inline TLV parsing adds overhead.
- 4 counting workers (iter9 CandC): +6.5%. Serial bottleneck dominates.
- Micro-optimizations without socket buffers (iter6): +1.3% regression.
- Worker-side counting (iter5): At 10M, counted format larger than raw.
- Child-side counting (iter2): +55% regression.
- Work stealing with flock (iter3): +4.6% on M4 Pro. MAY help on M1.
- 12/14 workers on M4 Pro: 2.5-8% slower than 10.
- 8x loop unrolling: No improvement over 6x.
- shmop IPC (iter5): Deadlock at scale.
- Single-threaded JSON (iter5): 4x regression.
- 2MB/1MB/75MB read chunks: All worse than 512KB.
- Setup micro-opts alone (iter8): -1.2% (under threshold).

## Key Finding: Sequential Drain > stream_select at 10M (iter9)

stream_select drain was consuming 14.6ms (not 2ms estimated). Root cause: multiple iterations, non-blocking mode overhead, O(n^2) string growth. Sequential stream_get_contents + inline merge: ~8ms. Also 31 fewer lines of code.

## MEASUREMENT CORRECTION (HUMAN-DIRECTED — DO NOT OVERWRITE)

**CRITICAL: All previous iterations used hyperfine wall-clock time as the metric. This is WRONG.**

`hyperfine` measures: PHP startup + Tempest boot + `Parser::parse()` + output = ~388ms.
`DataParseCommand` reports: ONLY `Parser::parse()` time = ~151ms (printed to stdout).

The ~237ms of PHP+Tempest overhead is FIXED and UNOPTIMIZABLE — it's outside `Parser.php`. Previous iterations were comparing candidates against a denominator inflated by ~60% fixed overhead. This means:
- A 5ms parser improvement (3.3% of parser time) looked like only 1.3% in hyperfine (5/388) — **below the 2% revert threshold**
- **Real improvements may have been reverted as "noise"**
- The "I/O floor" of ~376ms is meaningless — that includes overhead. The parser's floor is ~120-136ms.

**Corrected metrics:**
- Parser time: ~151ms (the REAL metric going forward)
- Parser floor estimate: ~120-136ms (interpreter cost at ~120ns/row × 10M rows ÷ 10 workers)
- Room for improvement: ~15-31ms (10-20% from current parser time)
- 2% threshold should apply to PARSER time (~151ms × 2% = ~3ms), not wall-clock

**Action:** Extract the parser-reported time from `data:parse` stdout: `php tempest data:parse 2>&1 | grep -oP '[\d.]+'`
Use THAT for all comparisons. Hyperfine is still useful for variance/consistency but NOT for absolute comparison.

**Previous COMPLETE assessment is RESCINDED.** There may be real gains masked by measurement error. Re-evaluate with corrected metrics.

## Remaining Ideas (re-assessed with corrected parser-time metrics)
1. Work stealing for M1 — can't test locally, regresses on M4 Pro. Still worth trying on real M1.
2. Revisit ANY previously reverted candidate that was "under 2% threshold" — it may have been a real improvement when measured against parser time only.
3. Merge-during-drain — saves ~4ms = 2.6% of parser time. NOW above threshold.
4. Setup micro-opts (iter8 candidate C) — was -1.2% of wall-clock (~4.7ms). As % of parser time: ~3.1%. NOW above threshold.
5. Deferred waitpid, fence tightening — small but may be above threshold when measured correctly.

## Performance Timeline

**NOTE:** All times below are hyperfine WALL-CLOCK times (includes ~237ms PHP+Tempest overhead). Parser-only times are ~237ms less. Future iterations should record PARSER time as primary metric.

| Iteration | Best Time (wall) | Change | Delta |
|-----------|-----------|--------|-------|
| 0 | 3.906s | Naive single-process | -- |
| 1 | 0.558s | Multi-process + bucket accumulation | -85.7% |
| 2 | 0.521s | Socket IPC + 256KB chunks | -6.7% |
| 3 | 0.483s | Parallel counting (4 workers) | -7.3% |
| 4 | 0.480s | Zero-copy hot loop + 512KB | -0.6% |
| 5 | 0.418s | 8 counting workers + SIGKILL | -12.9% |
| 6 | 0.406s | Large socket buffers (2MB) | -3.9% |
| 7 | 0.396s | Unbuffered I/O + adaptive workers | -4.4% |
| 8 | 0.396s | No improvement | 0% |
| 9 | 0.388s | Sequential drain + inline merge | -2% |

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB, shmall: 1024 pages (4MB total), shmseg: 8
- kern.ipc.maxsockbuf: 8MB
- data.csv: 750,949,374 bytes (~751MB, 10M rows)
- hyperfine available
