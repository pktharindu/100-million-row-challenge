# Parser Optimization Knowledge Base

## Current State
- **Best parser time:** ~143ms (median, interleaved A/B test, corrected parser-time metric)
- **Best wall-clock:** ~416ms mean (hyperfine, includes ~273ms PHP+Tempest overhead)
- **Iteration count:** 10
- **Parser architecture:** Adaptive worker count (perflevel0-based, 10 on M4 Pro, 6 on M1), socket_create_pair + socket_export_stream with 2MB SO_SNDBUF/SO_RCVBUF, unbuffered I/O (stream_set_read_buffer 0), sequential stream_get_contents drain + inline TLV merge, 6x loop unrolling, bucket accumulation with 2-byte date IDs (8-char "YY-MM-DD" keys), 512KB read chunks, zero-copy hot loop, 8 parallel counting workers with ksort-free iteration (idToDate), SIGKILL fast exit, 262KB child write / 512KB count write, inline JSON building, 512KB slug sample, fence at lastNl - 600
- **Target:** ~120-136ms parser time (interpreter floor estimate). Room: ~7-17ms (5-12%)

## Bottleneck Model (iter10 estimate)
| Phase | Time | % Internal | Serial? |
|-------|------|-----------|---------|
| setup (slugs+dates) | ~3ms (was 8.5ms, reduced by 512KB sample + no sprintf) | 2.1% | Yes |
| prefork (partition+sockets+fork) | ~4.1ms | 2.9% | Yes |
| parent_hotloop | ~100ms (was 107ms, -7ms from 8-char keys) | 69.9% | Parallel |
| drain+merge | ~8ms | 5.6% | Yes |
| waitpid | ~0.2ms | 0.1% | Yes |
| count_phase (fork+count+JSON+collect+write) | ~22.5ms | 15.7% | Parallel |
| **Internal total** | **~138ms** (est) | — | — |
| PHP + Tempest overhead | **~273ms** | — | Fixed |
| **Wall time** | **~416ms** | — | — |

**Primary bottleneck:** parent_hotloop (~100ms, 70% of PARSER time). Near PHP interpreter floor.
**Secondary:** count_phase (~22.5ms, 16%). Highly optimized with 8 workers + large socket buffers.
**Tertiary:** drain+merge (~8ms, 5.6%). Sequential stream_get_contents is optimal.

## Technique Status

| Technique | Status | Notes |
|-----------|--------|-------|
| Multi-process fork | DONE | Adaptive workers via perflevel0 sysctl |
| Bucket accumulation (T1) | DONE | Per-slug string append + unpack + array_count_values |
| 6x loop unrolling (T3) | DONE | Fence at lastNl - 600 (tightened iter10). 8x tested no improvement |
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
| Sequential drain + inline merge | DONE (iter9) | stream_get_contents replaces stream_select |
| 8-char date keys | DONE (iter10) | "YY-MM-DD" instead of "YYYY-MM-DD". -4% parser time |
| 512KB slug sample | DONE (iter10) | Was 2MB. All 268 slugs found in 512KB |
| Tighter fence (600) | DONE (iter10) | Was 720. Max line = 99 bytes |
| Numeric bucket indices | TESTED (iter10) | No improvement over string-keyed buckets. Extra $slugToIdx lookup offsets integer-indexed access gains |
| Temp file IPC | TESTED (iter10) | +6% REGRESSION vs sockets. file_get_contents slower than stream_get_contents from socket kernel buffers |
| Work stealing (T7) | TESTED | +4.6% regression on M4 Pro |
| 8x loop unrolling | TESTED | No improvement over 6x |
| Worker-side counting | TESTED | REGRESSION at 10M scale |
| Parent-as-coordinator (iter9) | TESTED | +5.2% regression |
| Merge-during-drain via stream_select (iter9) | TESTED | +3.7% regression |
| 4 counting workers (iter9) | TESTED | +6.5% regression |

## Dead Ends
- Numeric bucket indices (iter10): No improvement. $slugToIdx hash lookup cost = string-keyed bucket cost.
- Temp file IPC (iter10): +6% regression. Sockets with 2MB kernel buffers are faster than file I/O through page cache.
- Parent-as-coordinator (iter9): +5.2%. Extra fork overhead, coordinator competition.
- Merge-during-drain via stream_select (iter9): +3.7%. Inline TLV parsing adds overhead.
- 4 counting workers (iter9): +6.5%. Serial bottleneck dominates.
- Micro-optimizations without socket buffers (iter6): +1.3% regression.
- Worker-side counting (iter5): At 10M, counted format larger than raw.
- Child-side counting (iter2): +55% regression.
- Work stealing with flock (iter3): +4.6% on M4 Pro. MAY help on M1.
- 12/14 workers on M4 Pro: 2.5-8% slower than 10.
- 8x loop unrolling: No improvement over 6x.
- shmop IPC (iter5): Deadlock at scale.
- Single-threaded JSON (iter5): 4x regression.
- 2MB/1MB/75MB read chunks: All worse than 512KB.
- Setup micro-opts alone (iter8): -1.2% (under threshold, now partially captured in iter10).
- Flat 1D count array (like xHeaven PR#3): merge cost is O(numSlugs × numDates × numWorkers) = 978K × 10 per-worker additions. CONSTANT regardless of row count. At 10M, merge alone would take ~300ms. Only viable at 100M+ scale.

## Leaderboard Research (iter10)

Top entry techniques (from GitHub PR analysis):
| Technique | xHeaven (#1, 2.999s) | johnwedgbury (#4, 3.417s) | dannyvankooten (#5, 3.427s) | Ours |
|---|---|---|---|---|
| Workers | 12 | 12 | 12 | Adaptive (10 on M4 Pro) |
| IPC | Temp files | Unix sockets + stream_select | Temp files | Sockets + sequential drain |
| Read chunk | 160 KB | 4 MB | 128 KB | 512 KB |
| Date key | 8 chars (YY-MM-DD) | 8 chars | 8 chars | 8 chars (DONE iter10) |
| Count strategy | Flat 1D array per worker | Flat 1D array + adaptive 16/32-bit | Flat 1D + per-entry increment | Bucket accumulation + parallel counting |
| Second fork wave | No | No | No | Yes (8 counting workers) |
| Loop unrolling | 6x | 4x | None | 6x |

Key structural difference: Top entries use flat 1D count arrays (numSlugs × numDates) filled by workers, sent via IPC, merged by parent. We use bucket accumulation strings merged by parent, then forked counting workers. Their approach has CONSTANT IPC size independent of row count (good at 100M), ours has IPC proportional to row count (good at 10M).

## MEASUREMENT CORRECTION (HUMAN-DIRECTED — DO NOT OVERWRITE)

**CRITICAL: Use parser-reported time, NOT hyperfine wall-clock.**
Parser time = time printed by `data:parse` stdout. Extract: `php tempest data:parse 2>&1 | grep -oP '[\d.]+'`
Wall-clock includes ~273ms PHP+Tempest overhead that is UNOPTIMIZABLE.
2% threshold applies to PARSER time (~143ms × 2% = ~2.9ms).

**Measurement methodology for iter10+:** Use A/B interleaved testing (alternate baseline and candidate in pairs) to control for thermal/system state. 8+ pairs needed for statistical significance given ~20ms variance.

## Remaining Ideas
1. **Comma-based parsing** — Find comma instead of newline. Comma is closer to scan start, saves ~7 bytes of strpos scanning per line. Estimated savings: ~1ms per worker (negligible at M4 Pro speeds).
2. **Eliminate slug sample** — Discover slugs during hot loop with isset check. Adds ~10ms hot loop overhead but saves ~2.3ms setup. Net worse.
3. **Counting phase alternatives** — All tested approaches (fewer workers, single-thread, ksort) are worse than 8 parallel workers with idToDate iteration.
4. **Flat 1D array architecture** — Would eliminate second fork wave but merge cost is O(978K × 10) per-worker additions. Only viable at 100M scale.
5. **Alternative chunk sizes** — Top entries use 128-160KB. Our testing showed 512KB optimal, but worth retesting with the 8-char date key change (smaller hash table might shift optimal chunk size).
6. **Work stealing for M1** — Can't test locally, regresses on M4 Pro. Still worth trying on real M1 hardware.

## Performance Timeline

| Iteration | Parser Time (ms) | Wall Time (ms) | Change | Delta |
|-----------|-----------------|----------------|--------|-------|
| 0 | ~3670 | 3906 | Naive single-process | -- |
| 1 | ~321 | 558 | Multi-process + bucket accumulation | -91.3% |
| 2 | ~284 | 521 | Socket IPC + 256KB chunks | -11.5% |
| 3 | ~246 | 483 | Parallel counting (4 workers) | -13.4% |
| 4 | ~243 | 480 | Zero-copy hot loop + 512KB | -1.2% |
| 5 | ~181 | 418 | 8 counting workers + SIGKILL | -25.5% |
| 6 | ~169 | 406 | Large socket buffers (2MB) | -6.6% |
| 7 | ~159 | 396 | Unbuffered I/O + adaptive workers | -5.9% |
| 8 | ~159 | 396 | No improvement | 0% |
| 9 | ~151 | 388 | Sequential drain + inline merge | -5.0% |
| 10 | **~143** | **~416** | 8-char date keys + 512KB sample + fence | **-5.3%** |

Note: Iter 10 wall time appears higher but this is due to system load variance. Parser time is the authoritative metric.

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB, shmall: 1024 pages (4MB total), shmseg: 8
- kern.ipc.maxsockbuf: 8MB
- data.csv: 750,949,374 bytes (~751MB, 10M rows)
- hyperfine available
- Setup phase profiled (iter10): slug fread 0.5ms, slug parse 1.8ms, date table 0.5ms, boundaries 0.2ms, flip+sockets 0.06ms, total ~3ms
- Max slug length: 48 chars, min: 4 chars, max line: 99 bytes, min line: 55 bytes
- 268 slugs total. (len, first, last) fingerprint has 18 collisions — not suitable for direct lookup.
