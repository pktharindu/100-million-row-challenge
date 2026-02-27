# Parser Optimization Knowledge Base

## Current State
- **Best time:** 0.418s mean / 0.401s min (10M rows, M4 Pro 14-core, hyperfine 20 runs)
- **Iteration count:** 5
- **Parser architecture:** 10 parsing workers via pcntl_fork, socket pair IPC with stream_select, 6x loop unrolling, bucket accumulation with 2-byte date IDs, 512KB read chunks, zero-copy hot loop, **8 parallel counting workers** for batch_count + JSON output, **SIGKILL fast exit** in all child workers
- **Target:** ~0.400s (approaching I/O + overhead floor)

## Bottleneck Model (measured via profiling, iter5)
| Phase | Time | % Internal | Serial? |
|-------|------|-----------|---------|
| setup (slugs+dates) | ~4.5ms | 2.6% | Yes |
| fork + socket pairs | ~0.0ms | 0% | Yes (negligible) |
| parent_hotloop | ~126ms | 73.7% | Parallel |
| drain (stream_select) | ~11.4ms | 6.7% | Overlap |
| waitpid | ~2ms (post-SIGKILL) | 1.2% | Yes |
| merge (string concat) | ~4.2ms | 2.5% | Yes |
| count+json (8 workers) | ~22ms (est, was 61ms with 4) | 12.9% | Parallel |
| **Internal total** | **~171ms** | — | — |
| PHP + Tempest overhead | **~247ms** | — | Fixed |
| **Wall time** | **~418ms** | — | — |

**Primary bottleneck:** parent_hotloop (~126ms, 73.7% of internal). Per-line cost: ~120ns/row. Near PHP interpreter floor.

**Secondary bottleneck:** count+json (~22ms est, 12.9%). Now uses 8 parallel workers (doubled from 4). JSON workers do unpack+array_count_values+ksort+JSON build per slug.

**Tertiary:** PHP/Tempest overhead (~247ms, 59% of wall time). Irreducible.

**Key discovery (iter5):** Previous bottleneck model was significantly off. Actual profiling showed:
- count+json was 61ms with 4 workers (not 28ms estimated) — the single largest post-hotloop phase
- drain was only 11ms (not 27ms estimated)
- waitpid was 17ms of pure PHP exit cleanup (eliminated by SIGKILL)
- ALWAYS PROFILE before optimizing. Estimates can be 2× off.

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
| Chunk size tuning | DONE | 512KB optimal with zero-copy |
| Parallel counting | DONE | **8 workers** for batch_count+JSON (iter5: doubled from 4) |
| Optimized JSON output | DONE | No json_encode, implode, parallel workers |
| Zero-copy hot loop | DONE (iter4) | Eliminated $leftover.$raw concatenation. -3.1% |
| **SIGKILL fast exit** | **DONE (iter5)** | posix_kill(SIGKILL) skips PHP shutdown. -17ms waitpid |
| **8 counting workers** | **DONE (iter5)** | Doubled from 4. Halved parallel counting time. |
| Work stealing (T7) | TESTED | +4.6% regression on M4 Pro — flock overhead > benefit |
| Socket buffer increase | TESTED | No improvement — drain is compute-bound |
| Interleaved drain | TESTED | No improvement |
| 8x loop unrolling | TESTED | No improvement over 6x |
| Precomputed JSON prefixes | TESTED | No improvement |
| 8-char date keys | TESTED (iter4) | No improvement |
| do-while loop | TESTED (iter4) | No improvement |
| Merge-during-drain | TESTED (iter4) | No improvement |
| **Worker-side counting** | **TESTED (iter5)** | **REGRESSION at 10M scale** — see below |

## Dead Ends
- **Worker-side counting (iter5)**: At 10M rows with ~3700 visits/slug/worker across ~3652 dates, the counted format (4 bytes per unique date-count pair) is LARGER than raw bucket format (2 bytes per visit). Average collision rate is ~1.01x — almost no compression. Would help at 100M scale (10x more visits → ~10 counts per date) but can't validate at 10M. Three variants tested: (A) shmop IPC deadlocked at scale (socket buffer exhaustion), (B) socket IPC single-threaded JSON was 4× slower, (C) hybrid with counting fork was still 26% slower due to larger IPC data.
- **Child-side counting (iter2)**: +55% regression. PHP merge loop vastly slower than C-level batch.
- **Work stealing with flock (iter3)**: +4.6% regression on M4 Pro.
- **Socket buffer increase (iter3)**: No improvement. drain is compute-bound.
- **Interleaved drain (iter3)**: No improvement.
- **12/14 workers on M4 Pro**: 2.5-8% slower than 10.
- **4MB/2MB chunks (pre-zero-copy)**: 6-8% slower than 256KB.
- **8x loop unrolling**: No improvement over 6x.
- **Precomputed JSON date prefixes**: No improvement.
- **8-char date keys (iter4)**: No measurable improvement.
- **do-while loop (iter4)**: No measurable improvement.
- **Merge-during-drain (iter4)**: No measurable improvement.
- **shmop IPC (iter5)**: Deadlock at scale — children's counted data (~33KB+) exceeds 8KB socket/shm buffer; parent blocked on waitpid can't drain.
- **Single-threaded JSON (iter5)**: 4× regression. 270 slugs × 3000+ dates = ~90ms serial vs ~22ms parallel.

## Critical Scaling Insight (iter5)
**Data compression ratio depends on scale:**
- At 10M rows: ~3700 visits/slug/worker, ~3652 dates → collision rate ~1.01x → counted data ≥ raw data
- At 100M rows: ~37000 visits/slug/worker, ~3652 dates → collision rate ~10.1x → counted data 5× smaller than raw
- **Any optimization that depends on date collision rate will behave differently at 10M vs 100M scale**
- This affects: worker-side counting, adaptive IPC encoding, any scheme that converts per-visit to per-date

## Experiment Results

### Iteration 5: 8 counting workers + SIGKILL (480ms → 418ms)
| Candidate | Changes | Time (20 runs) | Delta vs baseline |
|---|---|---|---|
| Baseline (back-to-back, 20 runs) | Iter4 code | 473.5ms ± 16.5ms | — |
| A (worker-side counting + shmop) | Count in workers, shmop IPC | FAIL (deadlock) | — |
| B (worker-side counting + socket) | Count in workers, socket IPC, single-threaded JSON | 1957ms (smoke test) | +4× WORSE |
| B-fixed (w/ in-memory JSON) | Same + file_put_contents | 579ms (smoke test) | +22% WORSE |
| C-hybrid (worker count + counting fork) | Count in workers, merge counts, fork JSON workers | 606ms (smoke test) | +26% WORSE |
| **C (8 counters + SIGKILL)** | **8 counting workers + posix_kill fast exit** | **418.3ms ± 16.6ms** | **-11.7%** |
| C-10counters | 10 counting workers + SIGKILL | 429.0ms ± 20.9ms | -9.4% |

**Counting worker sweep:** 6=~437ms, 8=418ms, 10=429ms. 8 is optimal on M4 Pro (14 cores).

### Iteration 4: Zero-copy hot loop + chunk size (495ms → 480ms)
Baseline 490-495ms → Winner B (zero-copy 512KB) 475-480ms. -3.1%.

### Iteration 3: Parallel counting (521ms → 483ms)
Baseline 521ms → Winner G (parallel counting) 483ms. -7.3%.

### Iteration 2: IPC + chunk + JSON optimization (558ms → 521ms)
Baseline 558ms → Winner F (socket+fused) 521ms. -6.7%.

### Iteration 1: Architecture overhaul (3.906s → 0.558s)
Multi-process + bucket accumulation + temp file IPC. -85.7%.

## Promising Leads for Next Iteration

### The Diminishing Returns Assessment
Optimization trajectory: 3906ms → 558ms → 521ms → 483ms → 480ms → 418ms.
Gains: -85.7%, -6.7%, -7.3%, -0.6%, -12.9%.
The iter5 gain of 12.9% was a surprise — profiling revealed mismeasured phases. This suggests ALWAYS PROFILING before assuming diminishing returns.

Internal time is now ~171ms. PHP/Tempest overhead is ~247ms (59% of wall time).

### Medium Priority (may yield 1-5%)
1. **Counting worker optimization**: The 8 workers each do unpack+acv+ksort+JSON for ~34 slugs. Can we make any of these operations faster? Avoid the $entries array + implode for JSON building?
2. **Faster drain loop**: The stream_select + array_filter + array_search pattern is PHP-heavy. Could use a simpler drain approach?
3. **Reduce slug sample from 4MB to 1-2MB**: Saves ~1-2ms.
4. **socket_create_pair with SO_SNDBUF** instead of stream_socket_pair: Set kernel socket buffer to 2MB, allowing children to write all data without blocking. Then parent can read after hotloop without stream_select overhead.

### Low Priority
5. **On M1 target**: 8 workers instead of 10 might be better (avoid efficiency cores). But can't test on M4 Pro.
6. **shmop for counted data at 100M scale**: At 100M, counted data is ~3.9MB/worker (5× smaller than raw). shmop with 4MB shmmax could work. But only validates at 100M scale.
7. **Adaptive IPC encoding (T5)**: Only helps at 100M scale (see scaling insight above).

### Approaching COMPLETE threshold?
- Internal time is ~171ms, with hotloop at 126ms (74%). The hotloop is near PHP floor.
- PHP/Tempest overhead is 59% of wall time and irreducible.
- Counting phase halved (61ms→~22ms) but still takes 13%.
- Further gains likely in the 1-5% range from micro-optimizations.
- If iter6 yields <2% improvement from multiple experiments, consider COMPLETE.

## Performance Timeline

| Iteration | Best Time | Change | Delta |
|-----------|-----------|--------|-------|
| 0 (baseline) | 3.906s | Naive single-process | — |
| 1 | 0.558s | Multi-process + bucket accumulation + temp file IPC | -85.7% |
| 2 | 0.521s | Socket IPC + 256KB chunks + fused count/JSON | -6.7% |
| 3 | 0.483s | Parallel batch_count + JSON output (4 workers) | -7.3% |
| 4 | 0.480s | Zero-copy hot loop + 512KB chunks | -0.6% |
| 5 | **0.418s** | **8 counting workers + SIGKILL fast exit** | **-12.9%** |

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB, shmall: 1024 pages (4MB total), shmseg: 8
- kern.ipc.maxsockbuf: 8MB
- net.local.stream.recvspace: 8KB (default socket buffer)
- data.csv: 750,949,374 bytes (~751MB, 10M rows)
- hyperfine available
- **Benchmark variance note**: σ=16-21ms. Back-to-back A/B comparison essential. 20+ runs recommended.
