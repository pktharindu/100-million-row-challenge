# Parser Optimization Knowledge Base

## Current State
- **Best time:** 0.406s mean / 0.393s min (10M rows, M4 Pro 14-core, hyperfine 20 runs)
- **Iteration count:** 6
- **Parser architecture:** 10 parsing workers via pcntl_fork, **socket_create_pair + socket_export_stream** with 2MB SO_SNDBUF/SO_RCVBUF, stream_select concurrent drain, 6x loop unrolling, bucket accumulation with 2-byte date IDs, 512KB read chunks, zero-copy hot loop, **8 parallel counting workers** for batch_count + JSON output, **SIGKILL fast exit** in all child workers, optimized drain loop (pre-built fd map), inline JSON building, 2MB slug sample
- **Target:** ~0.400s (approaching I/O + overhead floor)

## Bottleneck Model (measured via profiling, iter6 — fresh profile before experiments)
| Phase | Time | % Internal | Serial? |
|-------|------|-----------|---------|
| setup (slugs+dates) | ~4.4ms | 2.5% | Yes |
| prefork (partition+sockets) | ~0.1ms | 0% | Yes |
| fork 9 children | ~4.1ms | 2.3% | Yes |
| parent_hotloop | ~127ms | 72.6% | Parallel |
| drain (stream_select) | ~9.1ms | 5.2% | Overlap |
| waitpid | ~0.5ms | 0.3% | Yes |
| merge (string concat) | ~4.3ms | 2.5% | Yes |
| count fork (8 workers) | ~3.4ms | 1.9% | Yes |
| count collect+output | ~27.9ms | 15.9% | Parallel |
| count waitpid | ~0.5ms | 0.3% | Yes |
| **Internal total** | **~175ms** | — | — |
| PHP + Tempest overhead | **~248ms** | — | Fixed |
| **Wall time** | **~423ms** (pre-optimization baseline) | — | — |

**After iter6 optimization (large socket buffers):**
- count collect+output: reduced from ~28ms to estimated ~15ms (socket buffers eliminate child blocking)
- drain: reduced from ~9ms to estimated ~5ms (larger buffers, fewer syscalls)
- System time: 335ms vs 359ms baseline (24ms reduction confirms fewer syscalls)
- **New wall time: ~406ms** (σ=8.0ms, very stable)

**Primary bottleneck:** parent_hotloop (~127ms, 73%). Per-line cost: ~120ns/row. Near PHP interpreter floor.

**Secondary bottleneck:** count collect+output (~15ms est after iter6). Now using 2MB socket buffers so workers can write all data without blocking.

**Tertiary:** PHP/Tempest overhead (~248ms, 61% of wall time). Irreducible.

## Technique Status

| Technique | Status | Notes |
|-----------|--------|-------|
| Multi-process fork | DONE | 10 workers on M4 Pro (14 CPUs, 10 perf cores) |
| Bucket accumulation (T1) | DONE | Per-slug string append + unpack('v*') + array_count_values |
| 6x loop unrolling (T3) | DONE | Fence at $lastNl - 720. 8x tested — no improvement |
| Position-based parsing (T4) | DONE | substr with hardcoded offsets, strpos with +52 skip |
| Socket pair IPC (T6) | DONE | **socket_create_pair + socket_export_stream + 2MB SO_SNDBUF/SO_RCVBUF** |
| Fully-qualified calls (T9) | DONE | \ prefix on all global functions |
| gc_disable | DONE | At top of file and parse() |
| Worker count tuning (T2) | DONE | 10 optimal on M4 Pro. 8=+5%, 12=+2.5%, 14=+8% slower |
| Newline skip (T8) | DONE | strpos offset +52 |
| Chunk size tuning | DONE | 512KB optimal with zero-copy |
| Parallel counting | DONE | 8 workers for batch_count+JSON |
| Optimized JSON output | DONE | **Inline JSON building (no $entries array, no implode)** |
| Zero-copy hot loop | DONE (iter4) | Eliminated $leftover.$raw concatenation. -3.1% |
| SIGKILL fast exit | DONE (iter5) | posix_kill(SIGKILL) skips PHP shutdown. -17ms waitpid |
| 8 counting workers | DONE (iter5) | Doubled from 4. Halved parallel counting time. |
| **Large socket buffers** | **DONE (iter6)** | **socket_create_pair + 2MB SO_SNDBUF/SO_RCVBUF. -3.9%** |
| **Optimized drain loop** | **DONE (iter6)** | **Pre-built int→worker map, no array_filter/array_search** |
| **Reduced slug sample** | **DONE (iter6)** | **2MB instead of 4MB** |
| Work stealing (T7) | TESTED | +4.6% regression on M4 Pro — flock overhead > benefit |
| Socket buffer increase (stream) | SUPERSEDED | Replaced by sockets extension approach (iter6) |
| Interleaved drain | TESTED | No improvement |
| 8x loop unrolling | TESTED | No improvement over 6x |
| Precomputed JSON prefixes | TESTED | No improvement |
| 8-char date keys | TESTED (iter4) | No improvement |
| do-while loop | TESTED (iter4) | No improvement |
| Merge-during-drain | TESTED (iter4) | No improvement |
| Worker-side counting | TESTED (iter5) | REGRESSION at 10M scale |
| Micro-optimizations alone | TESTED (iter6) | +1.3% REGRESSION when without large buffers |

## Dead Ends
- **Micro-optimizations without socket buffers (iter6)**: Drain loop optimization + inline JSON + 2MB sample WITHOUT large socket buffers = +1.3% regression with much higher variance (σ=31.6ms vs 16.4ms baseline). The drain loop changes interact poorly with small buffers. Only worthwhile combined with large socket buffers.
- **Worker-side counting (iter5)**: At 10M rows, counted format larger than raw. Would only help at 100M.
- **Child-side counting (iter2)**: +55% regression.
- **Work stealing with flock (iter3)**: +4.6% regression on M4 Pro.
- **Interleaved drain (iter3)**: No improvement.
- **12/14 workers on M4 Pro**: 2.5-8% slower than 10.
- **4MB/2MB chunks (pre-zero-copy)**: 6-8% slower than 256KB.
- **8x loop unrolling**: No improvement over 6x.
- **8-char date keys, do-while loop, merge-during-drain (iter4)**: No measurable improvement.
- **shmop IPC (iter5)**: Deadlock at scale.
- **Single-threaded JSON (iter5)**: 4× regression.

## Critical Scaling Insight (iter5)
**Data compression ratio depends on scale:**
- At 10M rows: ~3700 visits/slug/worker, ~3652 dates → collision rate ~1.01x → counted data ≥ raw data
- At 100M rows: ~37000 visits/slug/worker, ~3652 dates → collision rate ~10.1x → counted data 5× smaller than raw
- **Any optimization that depends on date collision rate will behave differently at 10M vs 100M scale**

## Experiment Results

### Iteration 6: Large socket buffers + optimizations (423ms → 406ms)
| Candidate | Changes | Time (20 runs) | Delta vs baseline |
|---|---|---|---|
| Baseline (back-to-back, 20 runs) | Iter5 code | 423.0ms ± 16.4ms | — |
| A (large socket buffers only) | socket_create_pair + 2MB buffers | 407.1ms ± 9.2ms | **-3.8%** |
| B (micro-optimizations only) | Drain opt + inline JSON + 2MB sample | 428.6ms ± 31.6ms | +1.3% (WORSE) |
| **C (combined A+B)** | **All above** | **406.3ms ± 8.0ms** | **-3.9%** |

**Key insight:** Large socket buffers are the primary driver. System time dropped 24ms (359→335ms), confirming significantly fewer syscalls. The 2MB SO_SNDBUF allows counting workers to dump ~1.58MB without blocking, SIGKILL immediately, and parent reads from kernel-buffered data.

### Iteration 5: 8 counting workers + SIGKILL (480ms → 418ms)
8 counting workers + SIGKILL exit. -12.9%.

### Iteration 4: Zero-copy hot loop + chunk size (495ms → 480ms)
Zero-copy 512KB. -3.1%.

### Iteration 3: Parallel counting (521ms → 483ms)
4 counting workers. -7.3%.

### Iteration 2: IPC + chunk + JSON optimization (558ms → 521ms)
Socket IPC + 256KB + fused count/JSON. -6.7%.

### Iteration 1: Architecture overhaul (3.906s → 0.558s)
Multi-process + bucket accumulation. -85.7%.

## Promising Leads for Next Iteration

### Approaching COMPLETE Assessment
Optimization trajectory: 3906→558→521→483→480→418→406ms.
Gains per iteration: -85.7%, -6.7%, -7.3%, -0.6%, -12.9%, -3.9%.

Internal time: ~160ms (est after iter6). PHP/Tempest overhead: ~248ms (61%).
The hotloop at ~127ms is 79% of internal time and at PHP's interpreter floor (~120ns/row).
Non-hotloop internal: ~33ms. Further gains from non-hotloop phases are single-digit ms.

**COMPLETE criteria check:**
- 5+ consecutive iterations with no improvement? NO — iter6 gained 3.9%.
- Within 10% of I/O floor? The I/O floor for 751MB at ~2.8GB/s is ~268ms. Internal time is ~160ms. But PHP overhead adds ~248ms bringing total to ~408ms. We're essentially at floor.
- All known techniques tried? Most major techniques are exhausted. Remaining ideas are low-confidence.

### Low-Priority Ideas (likely <2% impact)
1. **Reduce date enumeration range**: Currently 2019-2028 (3652 dates). If data only uses 2020-2024, fewer IDs and smaller lookup table. But must validate against any input.
2. **On M1 target**: 8 workers instead of 10 (avoid efficiency cores). Can't test locally.
3. **Adaptive worker count from `sysctl hw.ncpu`**: Auto-tune for any hardware. Currently hardcoded to 10.
4. **Merge loop optimization**: Replace unpack('vidx/Vlen') with ord()-based byte access. Saves ~1ms.
5. **Binary output with custom formatter**: Avoid string allocation in JSON. Likely minimal.

## Performance Timeline

| Iteration | Best Time | Change | Delta |
|-----------|-----------|--------|-------|
| 0 (baseline) | 3.906s | Naive single-process | — |
| 1 | 0.558s | Multi-process + bucket accumulation + temp file IPC | -85.7% |
| 2 | 0.521s | Socket IPC + 256KB chunks + fused count/JSON | -6.7% |
| 3 | 0.483s | Parallel batch_count + JSON output (4 workers) | -7.3% |
| 4 | 0.480s | Zero-copy hot loop + 512KB chunks | -0.6% |
| 5 | 0.418s | 8 counting workers + SIGKILL fast exit | -12.9% |
| **6** | **0.406s** | **Large socket buffers (2MB) + drain/JSON optimization** | **-3.9%** |

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB, shmall: 1024 pages (4MB total), shmseg: 8
- kern.ipc.maxsockbuf: 8MB
- net.local.stream.recvspace: 8KB (default socket buffer — now overridden with 2MB via socket_set_option)
- data.csv: 750,949,374 bytes (~751MB, 10M rows)
- hyperfine available
- **Benchmark variance note**: σ=8-10ms with large socket buffers (much more stable than σ=16-21ms before).
