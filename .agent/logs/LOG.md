# Parser Optimization Knowledge Base

## Current State
- **Best parser time:** ~137ms (median, interleaved A/B test, 12 pairs)
- **Best wall-clock:** ~387ms mean (hyperfine, includes ~273ms PHP+Tempest overhead)
- **Iteration count:** 13
- **Parser architecture:** 12 workers for M1 (10 on M4 Pro), socket_create_pair + socket_export_stream with 2MB SO_SNDBUF/SO_RCVBUF, unbuffered I/O (stream_set_read_buffer 0), sequential stream_get_contents drain + inline TLV merge, 6x loop unrolling, bucket accumulation with 2-byte date IDs (8-char "YY-MM-DD" keys), 512KB read chunks, zero-copy hot loop, 10 counting workers with pre-computed JSON date prefixes (dateJsonPrefix), SIGKILL fast exit, 262KB child write / 512KB count write, 512KB slug sample, fence at lastNl - 600
- **Target:** ~120-130ms parser time (interpreter floor estimate). Room: ~7-17ms (5-12%)
- **10M OPTIMIZATION PLATEAU:** 2 consecutive iterations (12, 13) with 0% improvement at 10M scale. **100M testing now available** — bottleneck distribution shifts at scale. Flat 1D arrays, worker-side counting, and other scale-dependent techniques can now be tested.

## Bottleneck Model (iter11 — updated with profiling)
| Phase | Time | % Internal | Serial? |
|-------|------|-----------|---------|
| setup (sysctl+slugs+dates+prefixes) | ~1.5ms (was 6.7ms; sysctl cache saves ~5ms) | 1.1% | Yes |
| prefork (partition+sockets+fork) | ~3.7ms | 2.7% | Yes |
| parent_hotloop | ~99ms | 72.3% | Parallel |
| drain+merge | ~10ms | 7.3% | Yes |
| waitpid | ~0ms | 0% | Yes |
| count_phase (fork+count+JSON+collect+write) | ~22ms (now 10 workers) | 16.1% | Parallel |
| **Internal total** | **~137ms** | — | — |
| PHP + Tempest overhead | **~250ms** | — | Fixed |
| **Wall time** | **~387ms** | — | — |

**Primary bottleneck:** parent_hotloop (~99ms, 72% of PARSER time). AT PHP interpreter floor.
**Secondary:** count_phase (~22ms, 16%). Now 10 workers + pre-computed prefixes.
**Tertiary:** drain+merge (~10ms, 7.3%). Sequential stream_get_contents is optimal.

## Technique Status

| Technique | Status | Notes |
|-----------|--------|-------|
| Multi-process fork | DONE | Cached-sysctl adaptive workers |
| Bucket accumulation (T1) | DONE | Per-slug string append + unpack + array_count_values |
| 6x loop unrolling (T3) | DONE | Fence at lastNl - 600. 8x tested no improvement |
| Position-based parsing (T4) | DONE | substr with hardcoded offsets, strpos +52 skip |
| Socket pair IPC (T6) | DONE | socket_create_pair + socket_export_stream + 2MB buffers |
| Fully-qualified calls (T9) | DONE | backslash prefix on all global functions |
| gc_disable | DONE | At top of file and parse() |
| Worker count tuning (T2) | DONE | M1: 12 workers, M4 Pro: 10 workers (perfCores >= 8 → perfCores, else 12) |
| Newline skip (T8) | DONE | strpos offset +52 |
| Chunk size tuning | DONE | 512KB optimal. 256KB, 128KB tested = no improvement on M4 Pro |
| Parallel counting | DONE | 10 workers for batch_count+JSON (was 8, iter12) |
| Optimized JSON output | DONE | Pre-computed dateJsonPrefix, ksort-free |
| Zero-copy hot loop | DONE (iter4) | Eliminated leftover.raw concatenation |
| SIGKILL fast exit | DONE (iter5) | posix_kill(SIGKILL) skips PHP shutdown |
| Large socket buffers | DONE (iter6) | socket_create_pair + 2MB buffers |
| Unbuffered I/O | DONE (iter7) | stream_set_read_buffer(fh, 0) |
| Adaptive worker count | DONE (iter7, iter12) | sysctl perflevel0, M1-tuned to 12 |
| Sequential drain + inline merge | DONE (iter9) | stream_get_contents replaces stream_select |
| 8-char date keys | DONE (iter10) | "YY-MM-DD" instead of "YYYY-MM-DD". -4% parser time |
| 512KB slug sample | DONE (iter10) | Was 2MB. All 268 slugs found in 512KB |
| Tighter fence (600) | DONE (iter10) | Was 720. Max line = 99 bytes |
| Sysctl caching | DONE (iter11) | Cache CPU count in temp file. -5ms per run (after warmup) |
| JSON date prefix pre-computation | DONE (iter11) | Pre-compute '        "YYYY-MM-DD": ' strings. -0.5ms |
| 10 counting workers | DONE (iter12) | Within noise at 10M. Expected ~20ms saving at 100M |
| M1 worker formula (12 workers) | DONE (iter12) | No-op on M4 Pro. Matches top leaderboard entries for M1 |
| ob_start + echo JSON | TESTED (iter13) | No improvement. PHP output buffer ≈ string concat for this workload |
| rawLen cache + 1MB fwrite | TESTED (iter13) | No improvement. strlen is O(1) via zend_string header, fwrite chunk size doesn't matter at 1.67MB |
| 128KB read chunks | TESTED (iter13) | No improvement on M4 Pro (L1d=192KB). May help M1 (L1d=128KB) but can't test |
| Size-balanced counting workers | TESTED (iter12) | No benefit — slug sizes are uniform enough |
| Arithmetic date ID lookup | TESTED (iter11) | +30% REGRESSION. PHP userland arithmetic slower than C-level hash |
| 256KB read chunks | TESTED (iter11) | No improvement over 512KB (within noise) |
| Numeric bucket indices | TESTED (iter10) | No improvement. $slugToIdx hash lookup cost = string-keyed bucket cost |
| Temp file IPC | TESTED (iter10) | +6% REGRESSION vs sockets |
| Work stealing (T7) | TESTED | +4.6% regression on M4 Pro |
| 8x loop unrolling | TESTED | No improvement over 6x |
| Worker-side counting | TESTED | REGRESSION at 10M scale |
| Parent-as-coordinator (iter9) | TESTED | +5.2% regression |
| Merge-during-drain via stream_select (iter9) | TESTED | +3.7% regression |
| 4 counting workers (iter9) | TESTED | +6.5% regression |

## Dead Ends
- **ob_start + echo JSON (iter13):** No improvement. PHP's output buffer has similar growth/copy characteristics to `.=` string concat. The bottleneck is foreach iteration over 3652 dateJsonPrefix entries, not string allocation.
- **rawLen cache (iter13):** strlen() on zend_string is O(1) — just reads the len field from the struct header. Second call cost is <1ns. Caching it in a variable has zero measurable impact.
- **1MB fwrite chunks (iter13):** At 10M, each worker sends ~1.67MB via socket. Changing from 262KB to 1MB chunks reduces syscalls from 7 to 2, but each fwrite on a 2MB-buffered Unix socket is already fast. Total difference: ~0.5ms across 10 workers, unmeasurable.
- **128KB read chunks (iter13):** No improvement on M4 Pro (192KB L1d). The 512KB chunk exceeds L1d on both M1 and M4 Pro, but strpos scanning is fast enough that L2 latency doesn't dominate. The bottleneck is opcode dispatch, not memory latency. May still help M1 but can't validate locally.
- **Size-balanced counting workers (iter12):** No benefit at 10M scale.
- **10 counting workers at 10M (iter12):** Not measurably better than 8.
- **Arithmetic date ID lookup (iter11):** +30% regression. C-level hash > userland arithmetic.
- **256KB read chunks (iter11):** No improvement over 512KB.
- Numeric bucket indices (iter10): No improvement.
- Temp file IPC (iter10): +6% regression vs sockets.
- Parent-as-coordinator (iter9): +5.2% regression.
- Merge-during-drain via stream_select (iter9): +3.7% regression.
- 4 counting workers (iter9): +6.5% regression.
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
- Flat 1D count array (like xHeaven PR#3): merge cost O(978K × 10) additions. Only viable at 100M+.

## Leaderboard Research (iter10)

Top entry techniques (from GitHub PR analysis):
| Technique | xHeaven (#1, 2.999s) | johnwedgbury (#4, 3.417s) | dannyvankooten (#5, 3.427s) | Ours |
|---|---|---|---|---|
| Workers | 12 | 12 | 12 | 12 on M1, 10 on M4 Pro |
| IPC | Temp files | Unix sockets + stream_select | Temp files | Sockets + sequential drain |
| Read chunk | 160 KB | 4 MB | 128 KB | 512 KB |
| Date key | 8 chars (YY-MM-DD) | 8 chars | 8 chars | 8 chars |
| Count strategy | Flat 1D array per worker | Flat 1D array + adaptive 16/32-bit | Flat 1D + per-entry increment | Bucket accumulation + parallel counting |
| Second fork wave | No | No | No | Yes (10 counting workers) |
| Loop unrolling | 6x | 4x | None | 6x |

Key structural difference: Top entries use flat 1D count arrays with NO second fork wave. We use bucket accumulation + counting fork wave. Their approach has CONSTANT IPC size (good at 100M), ours has IPC proportional to row count (problematic at 100M but efficient at 10M).

## MEASUREMENT CORRECTION (HUMAN-DIRECTED — DO NOT OVERWRITE)

**CRITICAL: Use parser-reported time, NOT hyperfine wall-clock.**
Parser time = time printed by `data:parse` stdout. Extract: `php tempest data:parse 2>&1 | grep -oP '[\d.]+'`
Wall-clock includes ~273ms PHP+Tempest overhead that is UNOPTIMIZABLE.
2% threshold applies to PARSER time (~137ms × 2% = ~2.7ms).

**Measurement methodology for iter10+:** Use A/B interleaved testing (alternate baseline and candidate in pairs) to control for thermal/system state. 8+ pairs needed for statistical significance given ~20ms variance.

**Statistical rigor (iter12 learning):** With measurement stddev ~5ms, need ~24 interleaved pairs for 80% power to detect a 3ms (2%) effect. Simple 10-run non-interleaved tests can be misleading due to system state drift. Always use interleaved A/B.

## Remaining Ideas (reassessed iter13)

### Now testable at 100M scale:
1. **Flat 1D array architecture** — Would eliminate second fork wave. Merge cost ~240ms at 10M (too slow) but constant IPC at 100M saves ~87ms in drain. All top entries use this. **NOW TESTABLE with local 100M dataset.**
2. **Worker-side counting** — At 10M, counted format was larger than raw (collision rate ~1.01x). At 100M, collision rate ~10x — counted format should be 10x smaller than raw. **Re-test at 100M.**
3. **128KB/160KB read chunks for M1** — No effect on M4 Pro. May help on M1 with 128KB L1d. Can't test locally (M4 Pro).
4. **Work stealing for M1** — Regresses on M4 Pro but M1 heterogeneous cores might benefit. Can't test locally.

### Exhausted categories:
- Hot loop micro-optimizations: AT INTERPRETER FLOOR. strlen cache, chunk sizes, unrolling, newline skip all tested.
- Counting phase: ob_start, implode, worker count, balanced distribution all tested. C-level unpack+array_count_values dominates.
- IPC: socket buffers, write chunk sizes, temp files, shmop all tested. Sequential drain optimal.
- Setup: sysctl cache, sample size, date prefix precomp all done.

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
| 10 | ~143 | ~416 | 8-char date keys + 512KB sample + fence | -5.3% |
| 11 | **~137** | **~387** | Sysctl caching + JSON date prefix precomp | **-4.2%** |
| 12 | **~137** | **~387** | M1 worker formula + 10 counting workers (neutral at 10M) | **0%** |
| 13 | **~137** | **~387** | ob_start+echo, rawLen cache, 128KB chunks — ALL within noise | **0%** |

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB, shmall: 1024 pages (4MB total), shmseg: 8
- kern.ipc.maxsockbuf: 8MB
- data.csv: **100M rows** (~7.5GB, seed=1709251200, dates ~2019-2024). Generated via `php tempest data:generate 100_000_000 --seed=1709251200`
- hyperfine available
- sys_get_temp_dir() = /var/folders/zh/yjg3m2ln2xq_7qcxnh175gd80000gn/T (macOS app sandbox)
- Profiled iter11 phases: sysctl=5.3ms (eliminated via cache), setup_total=6.7ms→1.5ms, prefork=3.7ms, hotloop=99ms, drain=10ms, count=22ms
- Max slug length: 48 chars, min: 4 chars, max line: 99 bytes, min line: 55 bytes
- 268 slugs total. (len, first, last) fingerprint has 18 collisions.
- **IMPORTANT: Userland arithmetic (ord()+math) is SLOWER than PHP's C-level hash table lookups on short strings.**
- **IMPORTANT: Counting phase per-slug cost dominated by C-level unpack+array_count_values (~0.5ms/slug). PHP-level iteration (isset checks on dateJsonPrefix) is only ~1ms total per counting worker. ksort would cost 7.5ms, 7.5x worse.**
- **IMPORTANT: At 10M scale, measurement noise (stddev ~5ms) makes <3ms improvements undetectable with practical sample sizes.**
- **IMPORTANT (iter13): PHP output buffer (ob_start+echo) has NO measurable advantage over `.=` string concatenation for this workload. Both use C-level internal buffers with exponential growth.**
- **IMPORTANT (iter13): strlen() is O(1) via zend_string header — caching in a variable is pointless.**

## COMPLETE Assessment — RESCINDED (HUMAN-DIRECTED — DO NOT OVERWRITE)

Previous COMPLETE assessment is **void**. New context:
1. **100M dataset is now available locally** (`data/data.csv`, ~7.5GB, seed=1709251200, dates ~2019-2024). The hardcoded 2019-2028 date range works fine with this dataset.
2. **All prior benchmarks were at 10M scale.** The optimization plateau was at 10M. At 100M, the bottleneck distribution shifts — IPC becomes proportionally larger, counting phase collision rates are ~10x higher, and architectural choices that were neutral at 10M may dominate.
3. **The flat 1D array architecture (like top leaderboard entries) can now be tested.** It was rejected at 10M (merge cost too high) but is expected to win at 100M (constant IPC size vs. bucket accumulation's row-proportional IPC).
4. **Re-profile at 100M** — measure actual phase times to update the bottleneck model.

**Priority for iteration 14:**
1. Baseline benchmark at 100M scale with parser-reported time
2. Profile at 100M to update bottleneck model (phase times may shift dramatically)
3. Test flat 1D array architecture — this is the biggest structural change remaining
4. Re-evaluate all "dead ends" that were only tested at 10M (worker-side counting, chunk sizes, work stealing)
