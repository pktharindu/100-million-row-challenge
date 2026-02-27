# Parser Optimization Knowledge Base

## Current State
- **Best parser time (10M):** ~137ms (median, interleaved A/B test, 12 pairs)
- **Best parser time (100M):** ~1.609s (median, 12-pair interleaved A/B test, iter16)
- **Best wall-clock (100M):** ~2.0s estimated (includes ~320ms PHP+Tempest overhead)
- **Iteration count:** 17
- **Parser architecture:** Parent-as-coordinator + temp file IPC. 12 workers for M1 (10 on M4 Pro). All workers are children (parent does no hotloop). Workers write TLV-encoded output to temp files (file_put_contents). Parent uses waitpid(-1) to drain workers in completion order, overlapping drain with worker execution. Unbuffered I/O (stream_set_read_buffer 0), 6x loop unrolling, bucket accumulation with 2-byte date IDs (8-char "YY-MM-DD" keys), adaptive read chunks (512KB on M4 Pro, 160KB on M1), zero-copy hot loop, adaptive counting workers (10 on M4 Pro, 8 on M1) with pre-computed JSON date prefixes (dateJsonPrefix) via socket IPC, SIGKILL fast exit, 512KB slug sample, fence at lastNl - 600.

## Bottleneck Model (iter16 — 100M scale, MEASURED via microtime instrumentation)
| Phase | Time (100M) | % Internal | Serial? |
|-------|-------------|-----------|---------|
| setup (sysctl+slugs+dates+prefixes) | ~3.7ms | 0.2% | Yes |
| fork (pcntl_fork loop) | ~4.3ms | 0.2% | Yes |
| hotloop+drain (workers+waitpid+TLV merge) | **~1547ms** | **94.3%** | Parallel + overlapped |
| count_fork (socket creation + fork 10 workers) | ~7.8ms | 0.5% | Yes |
| count_collect (parallel count+JSON+read) | ~76ms | 4.6% | Parallel |
| output+reap | ~3.5ms | 0.2% | Yes |
| **Internal total** | **~1642ms** | — | — |
| PHP + Tempest overhead | **~320ms** | — | Fixed |
| **Wall time** | **~2000ms** | — | — |

**NOTE:** Profiled run was 1.909s internal (system was warm). Relative percentages applied to typical 1.642s baseline give the adjusted times above.

**Primary bottleneck (100M):** hotloop+drain (~1547ms, 94.3%). AT PHP INTERPRETER FLOOR.
**Secondary (100M):** count_collect (~76ms, 4.6%). 10 counting workers.
**All other phases:** <1% each.

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
| Chunk size tuning | DONE | 512KB on M4 Pro, 160KB on M1 (adaptive, iter17) |
| Parallel counting | DONE | 10 workers on M4 Pro, 8 on M1 (adaptive, iter17) |
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
| do-while loops (iter16) | DONE | Eliminates JMP opcode per iteration in hot loops. Part of -1.6% combined improvement. |
| Year range 2019-2026 (iter16) | DONE | 3653→2922 dates. 20% less iteration in counting workers. |
| **M1-adaptive chunk size (iter17)** | **DONE** | **160KB on M1 (perfCores<8), 512KB on M4 Pro. Matches xHeaven #1.** |
| **M1-adaptive counting workers (iter17)** | **DONE** | **8 on M1, 10 on M4 Pro. 1:1 with M1 cores.** |
| **Implode-based JSON in counting (iter17)** | **TESTED** | **NEUTRAL on M4 Pro. +0.2% (within noise). Array collection + implode is not faster than .= concat for this workload.** |
| Comma-based parsing (iter16) | TESTED | NEUTRAL. Same opcode count, SIMD trivial. |
| 160KB read chunks (iter16) | TESTED | -16% REGRESSION on M4 Pro. Now used adaptively for M1 only. |
| Temp file IPC only (no coordinator) | TESTED (iter15) | ~1.7% improvement — marginal, below 2% threshold |
| Coordinator + socket IPC | TESTED (iter15) | INVALID at 100M — broken pipe deadlock. |
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

### 100M Scale (iter14-17)
- **Implode-based JSON in counting workers (iter17):** NEUTRAL. array_push + implode(",\n", ...) is not measurably faster than .= concat + $entrySep branching in counting workers. Tested with 12-pair interleaved A/B at 100M: +0.2% difference (noise). The counting phase is too small a fraction (4.6%) for string assembly method to matter.
- **Comma-based parsing (iter16):** NEUTRAL. Scans for comma instead of newline — same opcode count, scan distance difference trivial with SIMD.
- **160KB read chunks (iter16):** -16% REGRESSION on M4 Pro. xHeaven uses 160KB on M1 — M1-specific. Now used adaptively (160KB on M1 only).
- **Coordinator + socket IPC (iter15):** DEADLOCK at 100M. Workers block on fwrite (2MB buffer limit), parent blocks on waitpid. Temp files are REQUIRED.
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
- **Temp files enable coordinator pattern at 100M scale.** Sockets CANNOT support coordinator pattern because of buffer-limit deadlocks.
- **waitpid(-1) enables completion-order drain.**
- **strpos scan distance differences (comma vs newline) are trivial on ARM64 with NEON SIMD** — both fall within a single vectorized scan operation for the typical 9-25 byte differences.
- **Read chunk size strongly affects M4 Pro performance.** 512KB optimal. 160KB causes -16% regression from 3x more fread syscalls. May differ on M1.
- **Implode vs .= concat is NEUTRAL in counting workers (iter17).** The overhead of array creation matches the savings from fewer string reallocations. Neither approach is measurably faster at this workload size (~27 slugs × ~2922 dates per counting worker).
- **Parent-as-worker is WORSE than coordinator pattern** (analysis, iter17). Parent idle time during hotloop is hidden by overlapped drain. Making parent a worker delays drain start, losing the overlap benefit.

## xHeaven PR #3 Deep Analysis (iter16)

Full architecture of the #1 entry:
- **10 workers** (9 children + parent as 10th worker)
- **Bucket accumulation** — same as ours (string append per row, NOT inline counting)
- **Worker-side counting** at END of each worker (unpack + array_count_values → flat 1D array)
- **IPC:** pack('v*', ...$counts) → temp file. ~1.31MB per worker (268×2557×2 bytes). CONSTANT size.
- **Merge:** Dense linear scan: `foreach ($wCounts as $v) { $counts[$j++] += $v; }`. ~685K additions × 9 workers = 6.17M additions. Estimated ~600ms serial merge.
- **JSON generation:** Single-threaded in parent with implode() + 1MB write buffer.
- **Read chunk:** 160KB
- **Loop unroll:** 6x, fence at lastNl-720
- **Year range:** 2020-2026 (2557 dates vs our 2922)

**Key takeaway:** xHeaven's flat-array-merge approach has ~600ms of SERIAL merge in the parent. Our bucket-merge + parallel-counting approach avoids this at the cost of a second fork wave (~76ms). Our approach is FASTER on M4 Pro. xHeaven may still win on M1 due to 160KB chunk advantage and tighter year range.

## Leaderboard Research (iter10, updated iter17)

Top entry techniques (from GitHub PR analysis):
| Technique | xHeaven (#1, 2.999s) | johnwedgbury (#4, 3.417s) | dannyvankooten (#5, 3.427s) | Ours |
|---|---|---|---|---|
| Workers | 10 (9+parent) | 12 | 12 | 12 on M1, 10 on M4 Pro |
| IPC | Temp files (v* packed) | Unix sockets + stream_select | Temp files | Temp files (TLV) |
| Read chunk | 160 KB | 4 MB | 128 KB | 160KB M1 / 512KB M4 Pro |
| Date key | 8 chars (YY-MM-DD) | 8 chars | 8 chars | 8 chars |
| Year range | 2020-2026 | Unknown | Unknown | 2019-2026 |
| Count strategy | Bucket accum + worker-side count → flat array IPC | Flat 1D + adaptive 16/32-bit | Flat 1D + per-entry increment | Bucket accum + parallel counting (separate fork wave) |
| Second fork wave | No | No | No | Yes (10 counting workers on M4 Pro, 8 on M1) |
| Loop unrolling | 6x | 4x | None | 6x |
| Parent role | Processes last segment + merge + JSON | Coordinator | Coordinator | Coordinator only |

## MEASUREMENT CORRECTION (HUMAN-DIRECTED — DO NOT OVERWRITE)

**CRITICAL: Use parser-reported time, NOT hyperfine wall-clock.**
Parser time = time printed by `data:parse` stdout. Extract: `php tempest data:parse 2>&1 | grep -oE '[0-9]+\.[0-9]+'`
Wall-clock includes ~273ms PHP+Tempest overhead that is UNOPTIMIZABLE.
2% threshold applies to PARSER time.

**Measurement methodology:** Use A/B interleaved testing (alternate baseline and candidate in pairs) to control for thermal/system state. 8+ pairs needed for statistical significance.

**Statistical rigor (iter12):** With measurement stddev ~5ms at 10M / ~40ms at 100M, need ~24 interleaved pairs for 80% power to detect a 2% effect.

## Remaining Ideas (reassessed iter17, 100M scale)

### Potentially viable (but expected impact is very low):
1. **Year range 2020-2026** — Tighten from 2019-2026 to 2020-2026 (-365 dates). Local test data has 2019 dates so can't validate locally. Real benchmark is 2020-2026. Would need to submit without local validation.
2. **Temp file IPC for counting workers** — Replace socket IPC for counting with temp files (consistent with parsing worker approach). May reduce socket overhead. But sockets work fine for the small counting output.
3. **Investigate other leaderboard PRs** — PRs not yet studied (e.g., #116 johnwedgbury in detail, #65 dannyvankooten, #46 alexandre-daubois) may have techniques we haven't considered.

### Exhausted categories (DO NOT RETRY):
- **Hot loop micro-optimizations:** AT INTERPRETER FLOOR. ~120ns/row. No further optimization possible with pure PHP.
- **Comma vs newline scanning:** NEUTRAL. Confirmed iter16.
- **IPC format for parsing:** Temp files + TLV + coordinator is optimal.
- **Worker count:** 10 on M4 Pro, 12 on M1 is optimal.
- **Read chunk size:** 512KB on M4 Pro, 160KB on M1 is optimal (adaptive, iter17).
- **Setup phase:** All micro-opts done.
- **Architecture:** Bucket accumulation + C-level string merge is locally optimal for PHP.
- **Worker-side counting:** Extends critical path. +12-20% regression.
- **Counting worker string assembly:** Implode vs .= is NEUTRAL (iter17). JSON assembly method doesn't matter.
- **Parent-as-worker:** WORSE than coordinator (analysis, iter17). Delays drain overlap.

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
| **16 (100M)** | **~1609** | **~2000** | **do-while loops + year range 2019-2026** | **-1.6%** |
| **17 (100M)** | **~1609** | **~2000** | **M1-adaptive params (neutral on M4 Pro, targets M1)** | **0%** |

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB, shmall: 1024 pages (4MB total), shmseg: 8
- kern.ipc.maxsockbuf: 8MB
- PCRE JIT: disabled
- data.csv: **100M rows** (~7.5GB, seed=1709251200, dates ~2019-2024)
- hyperfine available
- sys_get_temp_dir() = /var/folders/zh/yjg3m2ln2xq_7qcxnh175gd80000gn/T
- Max slug length: 48 chars, min: 4 chars, max line: 99 bytes, min line: 55 bytes
- 268 slugs total
- Socket creation overhead: 0.05ms for 10 pairs
- Fork overhead: ~3.5ms for 10 workers
- **IMPORTANT: Userland arithmetic (ord()+math) is SLOWER than PHP's C-level hash lookups.**
- **IMPORTANT: Socket IPC + coordinator pattern DEADLOCKS at 100M. Temp files are REQUIRED.**
- **IMPORTANT: On macOS, use `grep -oE '[0-9]+\.[0-9]+'` instead of `grep -oP '[\d.]+'`.**
- **IMPORTANT: PHP C-level string ops (memcpy via .= append) are ~2.2x faster than PHP-level foreach array iteration.**
- **IMPORTANT: COW page faults add +12% regression for scattered memory access in forked children.**
- **IMPORTANT: strpos scan distance differences are negligible on ARM64 NEON (both comma and newline scanning do ~1 SIMD operation).**
- **IMPORTANT: 160KB chunks cause -16% regression on M4 Pro due to 3x more fread syscalls. Use 512KB on M4 Pro, 160KB on M1 adaptively.**
- **IMPORTANT: Implode vs .= for JSON assembly is NEUTRAL in counting workers (iter17). Array allocation overhead matches realloc savings.**

## COMPLETE Assessment (iter17, 100M scale)

**Status: APPROACHING COMPLETE.** Iteration 17 tested implode-based JSON assembly (NEUTRAL) and applied M1-adaptive parameters (untestable locally). The hotloop is 94.3% at PHP interpreter floor. The counting phase (4.6%) resists further optimization — implode, .=, and structural alternatives are all within noise.

**Consecutive no-improvement iterations on M4 Pro:** 1 (iter17). Iter16 improved -1.6%, iter17 0%.

**For future iterations:**
- The remaining optimization surface is extremely small (~90ms out of 1609ms = 5.6% non-hotloop)
- Most of that 90ms is parallel counting (76ms), which is already heavily optimized
- The only untested ideas are: year range 2020-2026 (can't validate locally), other leaderboard PR techniques (diminishing returns)
- **If the next iteration also finds no improvement, consider emitting COMPLETE**
