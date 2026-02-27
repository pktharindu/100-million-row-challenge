# Parser Optimization Knowledge Base

## Current State
- **Best parser time (10M):** ~137ms (median, interleaved A/B test, 12 pairs)
- **Best parser time (100M):** ~1.63s (median, 5-run hyperfine)
- **Best wall-clock (100M):** ~1.95s mean (hyperfine, includes ~320ms PHP+Tempest overhead)
- **Iteration count:** 14
- **Parser architecture:** 12 workers for M1 (10 on M4 Pro), socket_create_pair + socket_export_stream with 2MB SO_SNDBUF/SO_RCVBUF, unbuffered I/O (stream_set_read_buffer 0), sequential stream_get_contents drain + inline TLV merge, 6x loop unrolling, bucket accumulation with 2-byte date IDs (8-char "YY-MM-DD" keys), 512KB read chunks, zero-copy hot loop, 10 counting workers with pre-computed JSON date prefixes (dateJsonPrefix), SIGKILL fast exit, 262KB child write / 512KB count write, 512KB slug sample, fence at lastNl - 600
- **100M OPTIMIZATION STATUS:** Iteration 14 tested 6 experiments at 100M — ALL failed or neutral. Current bucket accumulation + string merge architecture is locally optimal for PHP. The C-level string operations are 2.2x faster than PHP-level array iteration for equivalent data volumes.

## Bottleneck Model (iter14 — 100M scale profiling)
| Phase | Time (100M) | % Internal | Time (10M) | Serial? |
|-------|-------------|-----------|------------|---------|
| setup (sysctl+slugs+dates+prefixes) | ~1.5ms | 0.1% | ~1.5ms | Yes |
| prefork (partition+sockets+fork) | ~0.1ms | 0% | ~3.7ms | Yes |
| hotloop+fork (all workers) | ~1371ms | 86% | ~99ms | Parallel |
| drain I/O (stream_get_contents) | ~80ms | 5% | ~7ms | Yes |
| drain merge (TLV parse + string append) | ~53ms | 3.3% | ~3ms | Yes |
| waitpid | ~0ms | 0% | ~0ms | Yes |
| count_phase (fork+count+JSON+collect+write) | ~100ms | 6.3% | ~22ms | Parallel |
| **Internal total** | **~1596ms** | — | **~137ms** | — |
| PHP + Tempest overhead | **~320ms** | — | **~250ms** | Fixed |
| **Wall time** | **~1950ms** | — | **~387ms** | — |

**Primary bottleneck (100M):** hotloop (~1371ms, 86% of parser time). AT PHP interpreter floor — scales linearly with row count.
**Secondary (100M):** drain I/O + merge (~133ms, 8.3%). Drain exploded from ~10ms (10M) to ~133ms (100M) because bucket accumulation sends O(rows) data through sockets (~180MB total across workers).
**Tertiary (100M):** count_phase (~100ms, 6.3%). 10 workers parallelizing unpack+count+JSON.

### 10M Bottleneck Model (iter11)
| Phase | Time | % Internal | Serial? |
|-------|------|-----------|---------|
| setup | ~1.5ms | 1.1% | Yes |
| prefork | ~3.7ms | 2.7% | Yes |
| parent_hotloop | ~99ms | 72.3% | Parallel |
| drain+merge | ~10ms | 7.3% | Yes |
| count_phase | ~22ms | 16.1% | Parallel |
| **Internal total** | **~137ms** | — | — |

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
| Worker-side counting + compact IPC (100M) | TESTED (iter14) | +12-20% REGRESSION at 100M. Counting extends critical path |
| Flat 1D count array (100M) | TESTED (iter14) | +100% REGRESSION at 100M. 3 hash lookups + arithmetic per row |
| 1MB read chunks (100M) | TESTED (iter14) | NEUTRAL in A/B test at 100M (mean 1.485 vs 1.477, within noise) |
| Skip merge / deferred concat (100M) | TESTED (iter14) | +12% REGRESSION. COW page faults from scattered access |
| 4MB socket buffers (100M) | TESTED (iter14) | NEUTRAL at 100M (~1.64s vs 1.63s) |
| xHeaven-style flat array IPC (100M) | TESTED (iter14) | +4-8% REGRESSION. PHP foreach merge (118ms) slower than C-level string merge (53ms) |
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
### 100M Scale (iter14)
- **Worker-side counting + compact IPC (100M, iter14):** +12-20% REGRESSION. Even though counted format is ~10x smaller at 100M (collision rate ~10x), counting extends the critical path for each worker. Workers take longer to finish, and the total worker+counting time exceeds the current parsing-only time + serial drain cost.
- **Flat 1D count array (100M, iter14):** +100% REGRESSION (3.52s vs 1.63s). Three hash lookups per row (slug→idx, date→dateId, dateId→array offset) + arithmetic makes each row ~2x slower. The flat array approach only works when using simpler data structures (xHeaven uses constant-time slug lookups via known offsets).
- **Skip merge / deferred concat (100M, iter14):** +12% REGRESSION. Storing per-worker buffers and having counting workers concatenate them causes COW page faults from scattered memory access patterns across forked processes.
- **4MB socket buffers (100M, iter14):** NEUTRAL. The 2MB buffers are already sufficient; increasing to 4MB doesn't measurably improve throughput.
- **1MB read chunks (100M, iter14):** NEUTRAL in A/B test. 512KB vs 1MB makes no measurable difference at 100M scale.
- **xHeaven-style flat array IPC (100M, iter14):** +4-8% REGRESSION. The critical insight: PHP's C-level string merge via `.=` (memcpy) at ~53ms beats PHP-level `foreach` array merge at ~118ms for equivalent data volumes. The flat array also added ~70ms worker contention in the hot loop, and ~82ms serial parent counting cost.

### 10M Scale (iter1-13)
- **ob_start + echo JSON (iter13):** No improvement. PHP's output buffer has similar growth/copy characteristics to `.=` string concat. The bottleneck is foreach iteration over 3652 dateJsonPrefix entries, not string allocation.
- **rawLen cache (iter13):** strlen() on zend_string is O(1) — just reads the len field from the struct header. Second call cost is <1ns. Caching it in a variable has zero measurable impact.
- **1MB fwrite chunks (iter13):** At 10M, each worker sends ~1.67MB via socket. Changing from 262KB to 1MB chunks reduces syscalls from 7 to 2, but each fwrite on a 2MB-buffered Unix socket is already fast. Total difference: ~0.5ms across 10 workers, unmeasurable.
- **128KB read chunks (iter13):** No improvement on M4 Pro (192KB L1d). The bottleneck is opcode dispatch, not memory latency.
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

### Fundamental Insight (iter14)
**PHP C-level string operations (memcpy in `.=` append) are ~2.2x faster than PHP-level array iteration for equivalent data volumes.** This means the current bucket accumulation + string merge architecture (drain merge ~53ms at 100M) is locally optimal — any approach that replaces C-level string operations with PHP-level loops (foreach, array merge) will be slower, even if it reduces total IPC data volume. This rules out flat 1D arrays and worker-side counting as viable alternatives in PHP.

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

## Remaining Ideas (reassessed iter14, 100M scale)

### Potentially viable:
1. **Temp file IPC at 100M** — Was +6% at 10M (iter10). At 100M, each worker sends ~18MB via sockets. Temp files might have different I/O characteristics at that volume (write once, read once, OS page cache). Drain I/O is ~80ms — if temp files can reduce this, could save ~20-40ms.
2. **Combined: temp files + worker-side counting** — If workers count AND write results to temp files, total IPC drops from ~180MB to ~1.4MB (constant). But iter14 showed worker-side counting alone regresses +12-20%, so counting cost must be offset by IPC savings.
3. **dtrace/strace syscall profiling** — Profile at 100M to identify if syscall overhead (read/write/mmap) is significant within the 1371ms hotloop. May reveal unexpected bottlenecks.
4. **Reduce worker count at 100M** — 12 workers on M1 may cause contention at 100M (4 perf + 4 efficiency cores). Try 8 workers (perf cores only).
5. **128KB/160KB read chunks for M1** — No effect on M4 Pro but M1 has 128KB L1d. Can't test locally.
6. **Work stealing for M1** — Regresses on M4 Pro but M1 heterogeneous cores might benefit. Can't test locally.

### Exhausted categories (confirmed at both 10M and 100M):
- Hot loop micro-optimizations: AT INTERPRETER FLOOR at both scales.
- Counting phase: ob_start, implode, worker count, balanced distribution all tested.
- IPC format: socket buffers, flat arrays, worker-side counting, skip merge, xHeaven-style — all tested at 100M.
- Setup: sysctl cache, sample size, date prefix precomp all done.
- **Architecture: Bucket accumulation + C-level string merge is locally optimal for PHP** (iter14 fundamental insight).

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
| 14 (100M) | **~1630** | **~1950** | 6 experiments at 100M — ALL failed/neutral | **0%** |

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
- **IMPORTANT (iter14): At 100M, drain+merge explodes from ~10ms to ~133ms (13x) because bucket accumulation sends O(rows) data through sockets (~180MB total). But this is STILL faster than all alternatives tested.**
- **IMPORTANT (iter14): PHP C-level string ops (memcpy via .= append) are ~2.2x faster than PHP-level foreach array iteration for equivalent data volumes. Current architecture is locally optimal.**
- **IMPORTANT (iter14): COW (Copy-on-Write) page faults can add +12% regression when forked processes access parent's scattered memory. Avoid deferred/lazy approaches that cause scattered reads in children.**
- **IMPORTANT (iter14): On macOS, use `grep -oE '[0-9]+\.[0-9]+'` instead of `grep -oP '[\d.]+'` — Perl regex not available. Also `timeout` command not found — use PHP-level or tool-level timeouts.**
- **IMPORTANT (iter13): PHP output buffer (ob_start+echo) has NO measurable advantage over `.=` string concatenation for this workload. Both use C-level internal buffers with exponential growth.**
- **IMPORTANT (iter13): strlen() is O(1) via zend_string header — caching in a variable is pointless.**

## COMPLETE Assessment (iter14, 100M scale)

**Status: APPROACHING COMPLETE.** 3 consecutive 0% iterations (12, 13, 14) across both 10M and 100M scales.

Iteration 14 comprehensively tested the 100M-scale hypotheses that were expected to break the 10M plateau:
- Flat 1D arrays: MASSIVE REGRESSION (+100%)
- Worker-side counting: REGRESSION (+12-20%)
- xHeaven-style flat IPC: REGRESSION (+4-8%)
- Skip merge: REGRESSION (+12%)
- Buffer size tweaks: NEUTRAL

The fundamental insight from iter14 is that PHP's C-level string operations (memcpy in `.=`) are 2.2x faster than PHP-level array iteration. This means the current architecture is locally optimal — no PHP-level restructuring can improve it.

**Priority for iteration 15 (if attempted):**
1. Test temp file IPC at 100M (was +6% at 10M, equation changes at 100M with ~180MB data)
2. Try combined temp files + worker-side counting (constant IPC ~1.4MB)
3. Profile with dtrace for syscall overhead in hotloop
4. Test reduced worker count (8 workers for M1 perf cores only)
5. **Consider COMPLETE if no improvement** — 4 consecutive 0% iterations would confirm the global optimum for this PHP architecture
