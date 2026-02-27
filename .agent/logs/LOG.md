# Parser Optimization Knowledge Base

## Current State
- **Best time:** 0.480s mean / 0.463s min (10M rows, M4 Pro 14-core, hyperfine 15 runs)
- **Iteration count:** 4
- **Parser architecture:** 10 parsing workers via pcntl_fork, socket pair IPC with stream_select, 6x loop unrolling, bucket accumulation with 2-byte date IDs, **512KB read chunks**, **zero-copy hot loop** (no leftover.raw concatenation), 4 parallel counting workers for batch_count + JSON output
- **Target:** ~0.400-0.450s (approaching top leaderboard range)

## Bottleneck Model (estimated from iter4 analysis)
| Phase | Time | % Internal | Serial? |
|-------|------|-----------|---------|
| setup (slugs+dates) | ~4.5ms | 2.1% | Yes |
| fork + socket pairs | ~3.1ms | 1.4% | Yes |
| parent_hotloop | ~120ms | 55.8% | Parallel |
| drain_wait (stream_select) | ~27ms | 12.6% | Overlap |
| merge (string concat) | ~5ms | 2.3% | Yes |
| fork counting workers | ~3ms | 1.4% | Yes |
| parallel batch_count+JSON | ~28ms | 13.0% | Parallel (4 workers) |
| pipe reading + output write | ~5ms | 2.3% | Yes |
| **Internal total** | **~215ms** | — | — |
| PHP + Tempest overhead | ~265ms | — | Fixed |
| **Wall time** | **~480ms** | — | — |

**Primary bottleneck:** parent_hotloop (~120ms, 55.8% of internal). Per-line cost: ~120ns/row at 1M rows/worker. Components: strpos (~15ns), 2×substr (~90ns for slug+date creation+GC), 2×hash_lookup (~50ns), string_append (~5ns), PHP opcode dispatch (~40ns). The substr allocations are the single biggest cost at ~45ns each (alloc zend_string + copy bytes + eventual free).

**The hot loop is now at or near PHP's efficiency floor.** Iteration 4 proved that eliminating the $leftover.$raw concatenation saves only ~3%. The per-line work (strpos + 2 substr + 2 hash lookups + append) can't be further reduced in PHP without eliminating string creation, which requires either a fundamentally different data structure or functionality not available in PHP (like direct byte hashing without string allocation).

**Secondary bottleneck:** drain_wait (~27ms, 12.6%) — compute imbalance across workers. Not addressable via buffer tuning.

**Tertiary:** PHP/Tempest overhead (~265ms, 55% of wall time). Irreducible.

## Technique Status

| Technique | Status | Notes |
|-----------|--------|-------|
| Multi-process fork | DONE | 10 workers on M4 Pro (14 CPUs, 10 perf cores) |
| Bucket accumulation (T1) | DONE | Per-slug string append + unpack('v*') + array_count_values |
| 6x loop unrolling (T3) | DONE | Fence at $lastNl - 720. 8x tested — no improvement |
| Position-based parsing (T4) | DONE | substr with hardcoded offsets, strpos with +52 skip |
| Socket pair IPC (T6) | DONE | stream_socket_pair + stream_select concurrent drain |
| Fully-qualified calls (T9) | DONE | \ prefix on all global functions |
| gc_disable | DONE | At top of file and parse() |
| Worker count tuning (T2) | DONE | 10 optimal on M4 Pro. 8=+5%, 12=+2.5%, 14=+8% slower |
| Newline skip (T8) | DONE | strpos offset +52 |
| Chunk size tuning | DONE | **512KB optimal with zero-copy**. 256KB was optimal before zero-copy. |
| Parallel counting (NEW) | DONE | 4 workers for batch_count+JSON. Saved ~56ms serial → ~31ms parallel |
| Optimized JSON output | DONE | No json_encode, implode, parallel workers |
| **Zero-copy hot loop** | **DONE (iter4)** | Eliminated $leftover.$raw concatenation. -3.1% |
| Work stealing (T7) | TESTED | **+4.6% regression on M4 Pro** — flock overhead > benefit |
| Socket buffer increase | TESTED | **No improvement** — drain_wait is compute-bound |
| Interleaved drain | TESTED | **No improvement** |
| 8x loop unrolling | TESTED | **No improvement** over 6x |
| Precomputed JSON prefixes | TESTED | **No improvement** |
| 8-char date keys | TESTED (iter4) | **No improvement.** "YY-MM-DD" vs "YYYY-MM-DD" — 2 fewer hash bytes per line negligible |
| do-while loop | TESTED (iter4) | **No improvement.** Saves 1 branch per 6x-unroll iteration, too marginal |
| Merge-during-drain | TESTED (iter4) | **No improvement.** Merge is only 5ms; overlapping with drain saves <5ms |
| Adaptive IPC encoding (T5) | NOT TRIED | Would halve IPC data but drain is compute-bound, not data-bound |

## Experiment Results

### Iteration 4: Zero-copy hot loop + chunk size (495ms → 480ms)
| Candidate | Changes | Time (20 runs) | Delta vs back-to-back baseline |
|---|---|---|---|
| Baseline (fresh, 20 runs) | Iter3 code | 490.3ms / 495.1ms (back-to-back) | — |
| A (zero-copy 256KB) | Process $raw directly, no leftover.raw concat | 477.3ms | -3.6% |
| **B (zero-copy 512KB)** | **A + 512KB chunks** | **475.2ms / 479.8ms** | **-3.1%** |
| C (zero-copy + merge-drain) | A + inline merge during socket drain | 476.9ms | -3.7% |
| D (zero-copy + 8char + do-while) | A + 8-char date keys + do-while loop | 482.8ms | -2.5% |

**Key findings:**
- **Zero-copy helps ~3% across all variants.** Eliminating the $leftover.$raw concatenation saves ~10-15ms by avoiding ~146 string allocations of 512KB per worker (at 512KB chunks).
- **512KB chunks with zero-copy is slightly better** than 256KB. Before zero-copy, larger chunks were slower because the concatenation overhead scaled with chunk size. Now the concatenation is gone, so fewer fread calls (fewer syscalls) outweigh any disadvantage.
- **8-char date keys didn't help.** The DJBX33A hash difference between 8 and 10 bytes (~2 iterations) is < 1ns/lookup. With ~1M lookups per worker, total savings <1ms. Below noise floor.
- **do-while didn't help.** Saves 1 conditional branch per ~567 loop iterations per chunk. At ~2ns per branch × 567 × 146 chunks ≈ 165µs per worker. Below noise floor.
- **Merge-during-drain didn't help.** Overlaps 5ms merge with 27ms drain tail. The 5ms savings is below the noise floor of the 20-run benchmark.
- **Baseline variance increased** this session (σ=21-27ms vs ~15ms in iter3). Suggests system load variability. Back-to-back A/B comparison is essential.

### Iteration 3: Parallel counting (521ms → 483ms)
| Candidate | Changes | Time | Delta |
|---|---|---|---|
| Baseline | Iter2 code | 521ms | — |
| A (work stealing) | 20 segs / 10 workers + flock | 545.2ms | +4.6% WORSE |
| B (8x unroll + JSON prefix) | 8x loop unroll + precomputed date JSON | 517.6ms | -0.65% (noise) |
| G (parallel counting) | Fork 4 workers for batch_count + JSON output | 482.9ms | -7.3% |

### Iteration 2: IPC + chunk + JSON optimization (558ms → 521ms)
| Candidate | Changes | Time | Delta |
|---|---|---|---|
| Baseline | Iter1 code (temp files, 2MB chunks) | 558.3ms | — |
| F (socket+fused) | Socket + fused count/JSON + 256KB | 521.0ms | -6.7% |

### Iteration 1: Architecture overhaul (3.906s → 0.558s)
Multi-process + bucket accumulation + temp file IPC.

## Dead Ends
- **Child-side counting (iter2)**: +55% regression. PHP merge loop vastly slower than C-level batch.
- **Work stealing with flock (iter3)**: +4.6% regression on M4 Pro.
- **Socket buffer increase (iter3)**: No improvement. drain_wait is compute-bound.
- **Interleaved drain (iter3)**: No improvement.
- **12/14 workers on M4 Pro**: 2.5-8% slower than 10.
- **4MB/2MB chunks (pre-zero-copy)**: 6-8% slower than 256KB.
- **8x loop unrolling**: No improvement over 6x.
- **Precomputed JSON date prefixes**: No improvement.
- **8-char date keys (iter4)**: No measurable improvement. Hash savings too small.
- **do-while loop (iter4)**: No measurable improvement. Branch savings too small.
- **Merge-during-drain (iter4)**: No measurable improvement. Merge is only 5ms.
- **Slug string avoidance via numeric keys**: Analyzed but not implemented — each PHP function call (ord, unpack) costs ~30ns, making numeric computation slower than native substr+hash.

## Promising Leads for Next Iteration

### The Diminishing Returns Problem
We've now optimized 4 iterations: 3906ms → 558ms → 521ms → 483ms → 480ms. The gains are: -85.7%, -6.7%, -7.3%, -3.1%. We're hitting diminishing returns. The hot loop per-line cost of ~120ns/row is near PHP's interpreter floor for this operation pattern (strpos+2×substr+2×hash+append). Major improvements now require algorithmic/architectural changes, not micro-optimizations.

### High Priority (may still yield >2%)
1. **Reduce worker count on M1 target**: Our benchmarks are on M4 Pro (14 CPUs), but the real target is M1 (4+4 cores). On M1 with heterogeneous cores, 10 workers means some run on efficiency cores at 1/3 speed. Try 6-8 workers optimized for M1. **However, we can't test this on M4 Pro.**
2. **Reduce PHP/Tempest overhead (265ms, 55% of total)**: This dwarfs all internal time. If we could reduce it even 10%, that's 26ms — more than our entire iter4 gain. **However, we can't modify Tempest code.**
3. **Alternative parsing approach**: Replace strpos+substr with a single C-level call that does both newline finding and field extraction. Candidates: `sscanf()`, `preg_match_all()`. Analyzed in iter4: regex is ~3.4× slower due to match array creation overhead. sscanf has similar overhead.

### Medium Priority (may yield 1-2%)
4. **Counting worker optimization**: Currently 4 counting workers. Profile to see if unpack, array_count_values, ksort, or JSON build is the bottleneck within counting. If one dominates, optimize it.
5. **Reduce fork overhead**: Total fork time is ~6ms (10+4 forks). Using posix_spawn or exec might be faster than fork+COW. But PHP only has pcntl_fork.
6. **Reduce sample size**: 4MB sample for slug discovery → 1MB. Saves ~2ms.

### Low Priority / Requires New Approach
7. **shmop-based IPC**: Shared memory instead of sockets. Avoids serialization and socket overhead. But shmmax is 4MB and we need ~18MB total. Would need multiple segments.
8. **Adaptive IPC encoding (T5)**: Halve IPC data. But drain is compute-bound, not data-bound.
9. **Process-level parallelism instead of fork**: Use `proc_open` to spawn separate PHP processes. Avoids COW overhead but adds process startup time.

### Approaching COMPLETE threshold
- **5 consecutive "noise" experiments** within iter4 (8-char dates, do-while, merge-during-drain all yielded <1% change)
- **All known techniques from leaderboard have been tried** or analyzed
- **Hot loop is at ~120ns/row**, near PHP interpreter floor
- **PHP/Tempest overhead (265ms) is 55% of wall time** and irreducible
- Consider COMPLETE if iter5 also yields <2% improvement

## Performance Timeline

| Iteration | Best Time | Change | Delta |
|-----------|-----------|--------|-------|
| 0 (baseline) | 3.906s | Naive single-process | — |
| 1 | 0.558s | Multi-process + bucket accumulation + temp file IPC | -85.7% |
| 2 | 0.521s | Socket IPC + 256KB chunks + fused count/JSON | -6.7% |
| 3 | 0.483s | Parallel batch_count + JSON output (4 workers) | -7.3% |
| 4 | **0.480s** | Zero-copy hot loop + 512KB chunks | -3.1% |

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB
- kern.ipc.maxsockbuf: 8MB
- net.local.stream.recvspace: 8KB (default socket buffer)
- data.csv: 750,949,374 bytes (~751MB, 10M rows)
- hyperfine available
- **Benchmark variance note**: σ=15-27ms across sessions. Back-to-back A/B comparison essential for reliable results. 20+ runs recommended.

## Leaderboard Research (Iter2)
- **PR #46 (1st, 4.32s/100M)**: Dynamic workers, 262KB chunks, flock work stealing, 65KB JSON buffer
- **PR #3 (2nd, 4.61s/100M)**: 12 workers, 160KB chunks, bucket accumulation, 1MB JSON buffer
- **PR #116 (3rd, 4.66s/100M)**: 12 workers, 4MB chunks, socket pair IPC, adaptive v16/v32 encoding
