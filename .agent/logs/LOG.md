# Parser Optimization Knowledge Base

## Current State
- **Best parser time (10M):** ~137ms (median, interleaved A/B test, 12 pairs)
- **Best parser time (100M):** ~1.657s (median, 10-pair interleaved A/B test)
- **Best wall-clock (100M):** ~1.977s mean (hyperfine 10 runs, includes ~320ms PHP+Tempest overhead)
- **Iteration count:** 15
- **Parser architecture:** Parent-as-coordinator + temp file IPC. 12 workers for M1 (10 on M4 Pro). All workers are children (parent does no hotloop). Workers write TLV-encoded output to temp files (file_put_contents). Parent uses waitpid(-1) to drain workers in completion order, overlapping drain with worker execution. Unbuffered I/O (stream_set_read_buffer 0), 6x loop unrolling, bucket accumulation with 2-byte date IDs (8-char "YY-MM-DD" keys), 512KB read chunks, zero-copy hot loop, 10 counting workers with pre-computed JSON date prefixes (dateJsonPrefix) via socket IPC, SIGKILL fast exit, 512KB slug sample, fence at lastNl - 600.

## Bottleneck Model (iter15 — 100M scale, estimated)
| Phase | Time (100M) | % Internal | Serial? |
|-------|-------------|-----------|---------|
| setup (sysctl+slugs+dates+prefixes) | ~1.5ms | 0.1% | Yes |
| prefork (partition+fork) | ~0.1ms | 0% | Yes |
| hotloop (all workers, parallel) | ~1371ms | 87% | Parallel |
| drain+merge (waitpid(-1)+file_get_contents+TLV) | ~60ms | 3.8% | Overlapped with workers |
| count_phase (fork+count+JSON+collect+write) | ~100ms | 6.4% | Parallel |
| **Internal total** | **~1533ms** | — | — |
| PHP + Tempest overhead | **~320ms** | — | Fixed |
| **Wall time** | **~1900ms** | — | — |

**Key change from iter14:** drain+merge reduced from ~133ms (serial, after parent hotloop) to ~60ms (overlapped with workers via waitpid(-1) + temp files). Estimated savings: ~73ms.

**Primary bottleneck (100M):** hotloop (~1371ms, 87% of parser time). AT PHP interpreter floor.
**Secondary (100M):** count_phase (~100ms, 6.4%). 10 counting workers.
**Tertiary (100M):** drain+merge (~60ms, 3.8%). Now overlapped with worker execution.

## Technique Status

| Technique | Status | Notes |
|-----------|--------|-------|
| Multi-process fork | DONE | Cached-sysctl adaptive workers |
| Bucket accumulation (T1) | DONE | Per-slug string append + unpack + array_count_values |
| 6x loop unrolling (T3) | DONE | Fence at lastNl - 600. 8x tested no improvement |
| Position-based parsing (T4) | DONE | substr with hardcoded offsets, strpos +52 skip |
| Temp file IPC | DONE (iter15) | file_put_contents in workers, file_get_contents in parent. Replaces sockets for parsing workers. |
| Parent-as-coordinator | DONE (iter15) | Parent forks ALL workers, drains via waitpid(-1) in completion order. -4.3% at 100M. |
| Fully-qualified calls (T9) | DONE | backslash prefix on all global functions |
| gc_disable | DONE | At top of file and parse() |
| Worker count tuning (T2) | DONE | M1: 12 workers, M4 Pro: 10 workers (perfCores >= 8 → perfCores, else 12) |
| Newline skip (T8) | DONE | strpos offset +52 |
| Chunk size tuning | DONE | 512KB optimal |
| Parallel counting | DONE | 10 workers for batch_count+JSON via socket IPC |
| Optimized JSON output | DONE | Pre-computed dateJsonPrefix, ksort-free |
| Zero-copy hot loop | DONE (iter4) | Eliminated leftover.raw concatenation |
| SIGKILL fast exit | DONE (iter5) | posix_kill(SIGKILL) skips PHP shutdown |
| Unbuffered I/O | DONE (iter7) | stream_set_read_buffer(fh, 0) |
| Adaptive worker count | DONE (iter7, iter12) | sysctl perflevel0, M1-tuned to 12 |
| 8-char date keys | DONE (iter10) | "YY-MM-DD" instead of "YYYY-MM-DD". -4% parser time |
| 512KB slug sample | DONE (iter10) | Was 2MB. All 268 slugs found in 512KB |
| Tighter fence (600) | DONE (iter10) | Was 720. Max line = 99 bytes |
| Sysctl caching | DONE (iter11) | Cache CPU count in temp file |
| JSON date prefix pre-computation | DONE (iter11) | Pre-compute date prefix strings |
| 10 counting workers | DONE (iter12) | Expected ~20ms saving at 100M |
| M1 worker formula (12 workers) | DONE (iter12) | Matches top leaderboard entries for M1 |
| Temp file IPC only (no coordinator) | TESTED (iter15) | ~1.7% improvement — marginal, below 2% threshold |
| Coordinator + socket IPC | TESTED (iter15) | INVALID at 100M — broken pipe deadlock. 2MB socket buffer causes children to block on fwrite; parent blocks on waitpid. TEMP FILES REQUIRED for coordinator pattern. |
| ob_start + echo JSON | TESTED (iter13) | No improvement |
| rawLen cache + 1MB fwrite | TESTED (iter13) | No improvement |
| 128KB read chunks | TESTED (iter13) | No improvement on M4 Pro |
| Worker-side counting + compact IPC (100M) | TESTED (iter14) | +12-20% REGRESSION |
| Flat 1D count array (100M) | TESTED (iter14) | +100% REGRESSION |
| 1MB read chunks (100M) | TESTED (iter14) | NEUTRAL |
| Skip merge / deferred concat (100M) | TESTED (iter14) | +12% REGRESSION |
| 4MB socket buffers (100M) | TESTED (iter14) | NEUTRAL |
| xHeaven-style flat array IPC (100M) | TESTED (iter14) | +4-8% REGRESSION |
| Size-balanced counting workers | TESTED (iter12) | No benefit |
| Arithmetic date ID lookup | TESTED (iter11) | +30% REGRESSION |
| 256KB read chunks | TESTED (iter11) | No improvement |
| Numeric bucket indices | TESTED (iter10) | No improvement |
| Socket pair IPC (T6) | SUPERSEDED (iter15) | Replaced by temp file IPC for parsing. Sockets still used for counting. |
| Work stealing (T7) | TESTED | +4.6% regression on M4 Pro |
| 8x loop unrolling | TESTED | No improvement over 6x |
| Parent-as-coordinator (sockets, iter9) | TESTED (iter9) | +5.2% regression at 10M with sockets. DEADLOCKS at 100M (iter15). |
| Merge-during-drain via stream_select (iter9) | TESTED | +3.7% regression |
| 4 counting workers (iter9) | TESTED | +6.5% regression |

## Dead Ends

### 100M Scale (iter14-15)
- **Coordinator + socket IPC (iter15):** DEADLOCK at 100M. Workers block on fwrite (2MB buffer limit), parent blocks on waitpid. Temp files are REQUIRED for coordinator pattern because workers need to write all data without blocking.
- **Worker-side counting + compact IPC (100M, iter14):** +12-20% REGRESSION. Counting extends critical path.
- **Flat 1D count array (100M, iter14):** +100% REGRESSION. 3 hash lookups per row.
- **Skip merge / deferred concat (100M, iter14):** +12% REGRESSION. COW page faults.
- **4MB socket buffers (100M, iter14):** NEUTRAL.
- **1MB read chunks (100M, iter14):** NEUTRAL.
- **xHeaven-style flat array IPC (100M, iter14):** +4-8% REGRESSION.

### 10M Scale (iter1-13)
- ob_start + echo JSON (iter13): No improvement.
- rawLen cache (iter13): strlen() is O(1).
- 1MB fwrite chunks (iter13): Unmeasurable.
- 128KB read chunks (iter13): No improvement on M4 Pro.
- Size-balanced counting workers (iter12): No benefit.
- Arithmetic date ID lookup (iter11): +30% regression.
- 256KB read chunks (iter11): No improvement.
- Numeric bucket indices (iter10): No improvement.
- Temp file IPC alone at 10M (iter10): +6% regression (overhead dominates at small scale).
- Parent-as-coordinator with sockets at 10M (iter9): +5.2% regression.
- stream_select drain (iter9): +3.7% regression.
- 4 counting workers (iter9): +6.5% regression.
- Micro-optimizations without socket buffers (iter6): +1.3% regression.
- Worker-side counting at 10M (iter5): Counted format larger than raw.
- Child-side counting (iter2): +55% regression.
- Work stealing with flock (iter3): +4.6% on M4 Pro.
- 12/14 workers on M4 Pro: 2.5-8% slower than 10.
- 8x loop unrolling: No improvement over 6x.
- shmop IPC (iter5): Deadlock at scale.
- Single-threaded JSON (iter5): 4x regression.
- 2MB/1MB/75MB read chunks: All worse than 512KB.
- Setup micro-opts alone (iter8): -1.2% (under threshold).

### Fundamental Insights
- **PHP C-level string operations (memcpy in `.=` append) are ~2.2x faster than PHP-level array iteration.** Bucket accumulation + string merge is locally optimal.
- **Temp files enable coordinator pattern at 100M scale.** Sockets CANNOT support coordinator pattern because of buffer-limit deadlocks. Temp files write to page cache (no buffer limit), enabling workers to exit immediately without blocking.
- **waitpid(-1) enables completion-order drain.** Instead of draining W0, W1, ... sequentially (waiting for slow workers), the parent processes whichever worker finishes first. This overlaps drain with remaining workers.

## Leaderboard Research (iter10)

Top entry techniques (from GitHub PR analysis):
| Technique | xHeaven (#1, 2.999s) | johnwedgbury (#4, 3.417s) | dannyvankooten (#5, 3.427s) | Ours |
|---|---|---|---|---|
| Workers | 12 | 12 | 12 | 12 on M1, 10 on M4 Pro |
| IPC | Temp files | Unix sockets + stream_select | Temp files | **Temp files (iter15)** |
| Read chunk | 160 KB | 4 MB | 128 KB | 512 KB |
| Date key | 8 chars (YY-MM-DD) | 8 chars | 8 chars | 8 chars |
| Count strategy | Flat 1D array per worker | Flat 1D array + adaptive 16/32-bit | Flat 1D + per-entry increment | Bucket accumulation + parallel counting |
| Second fork wave | No | No | No | Yes (10 counting workers) |
| Loop unrolling | 6x | 4x | None | 6x |
| Coordinator parent | No (parent processes last segment) | No | No | **Yes (iter15)** |

Key structural difference: Top entries use flat 1D count arrays with NO second fork wave. We use bucket accumulation + counting fork wave. Their IPC is CONSTANT size; ours is proportional to rows but drain is now overlapped.

## MEASUREMENT CORRECTION (HUMAN-DIRECTED — DO NOT OVERWRITE)

**CRITICAL: Use parser-reported time, NOT hyperfine wall-clock.**
Parser time = time printed by `data:parse` stdout. Extract: `php tempest data:parse 2>&1 | grep -oE '[0-9]+\.[0-9]+'`
Wall-clock includes ~273ms PHP+Tempest overhead that is UNOPTIMIZABLE.
2% threshold applies to PARSER time.

**Measurement methodology:** Use A/B interleaved testing (alternate baseline and candidate in pairs) to control for thermal/system state. 8+ pairs needed for statistical significance.

**Statistical rigor (iter12):** With measurement stddev ~5ms at 10M / ~40ms at 100M, need ~24 interleaved pairs for 80% power to detect a 2% effect.

## Remaining Ideas (reassessed iter15, 100M scale)

### Potentially viable:
1. **Counting phase optimization** — Count phase is now the biggest serial post-hotloop cost (~100ms, 6.4%). Possible approaches: fewer counting workers (8 instead of 10), or temp file IPC for counting workers too.
2. **Read chunk size for M1** — 128KB/160KB may help M1 L1d (128KB). Can't test locally on M4 Pro.
3. **Work stealing for M1** — Regresses on M4 Pro but M1 heterogeneous cores might benefit.
4. **dtrace/strace syscall profiling** — Profile at 100M to find if syscall overhead in hotloop is significant.
5. **Temp file IPC for counting workers too** — Currently counting uses sockets. At 100M, counting output is ~4MB total (small). Probably not worth changing.
6. **Counting workers overlap** — Start counting workers before all drain is complete? Would require restructuring to allow partial counting, which isn't possible with current bucket merge approach.

### Exhausted categories:
- Hot loop micro-optimizations: AT INTERPRETER FLOOR.
- IPC format for parsing: temp files + coordinator pattern is optimal (iter15).
- Setup: All micro-opts done.
- Architecture: Bucket accumulation + C-level string merge is locally optimal for PHP.

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
| **15 (100M)** | **~1657** | **~1977** | **Parent-as-coordinator + temp file IPC** | **-4.3%** |

Note: iter15 baseline was ~1731ms (system warmer than iter14). Relative improvement is the meaningful metric.

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB, shmall: 1024 pages (4MB total), shmseg: 8
- kern.ipc.maxsockbuf: 8MB
- data.csv: **100M rows** (~7.5GB, seed=1709251200, dates ~2019-2024)
- hyperfine available
- sys_get_temp_dir() = /var/folders/zh/yjg3m2ln2xq_7qcxnh175gd80000gn/T
- Max slug length: 48 chars, min: 4 chars, max line: 99 bytes, min line: 55 bytes
- 268 slugs total
- **IMPORTANT: Userland arithmetic (ord()+math) is SLOWER than PHP's C-level hash lookups.**
- **IMPORTANT: At 100M, drain+merge with sockets was ~133ms. With temp files + coordinator + waitpid(-1), effective drain+merge cost reduced to ~60ms (overlapped with workers).**
- **IMPORTANT: Socket IPC + coordinator pattern DEADLOCKS at 100M. Workers block on fwrite (2MB buffer), parent blocks on waitpid. Temp files are REQUIRED.**
- **IMPORTANT: On macOS, use `grep -oE '[0-9]+\.[0-9]+'` instead of `grep -oP '[\d.]+'`.**
- **IMPORTANT: PHP C-level string ops (memcpy via .= append) are ~2.2x faster than PHP-level foreach array iteration.**
- **IMPORTANT: COW page faults add +12% regression for scattered memory access in forked children.**

## COMPLETE Assessment (iter15, 100M scale)

**Status: NOT YET COMPLETE.** Iteration 15 broke the 3-iteration plateau with -4.3% improvement via architectural restructuring (coordinator pattern + temp files). This demonstrates that higher-level architectural changes can still find gains even when micro-optimizations are exhausted.

**Priority for iteration 16:**
1. Profile the new architecture at 100M to update bottleneck model (the drain overlap changes the critical path)
2. Optimize counting phase (~100ms) — this is now the largest non-hotloop cost
3. Test temp file IPC for counting workers
4. Try reducing counting workers (6-8 instead of 10) with temp files
5. Investigate if we can start counting workers earlier (partial data availability)
