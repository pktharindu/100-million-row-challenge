# Parser Optimization Knowledge Base

## Current State
- **Best time:** 0.396s mean / 0.385s min (10M rows, M4 Pro 14-core, hyperfine 20 runs)
- **Iteration count:** 8 (no improvement this iteration)
- **Parser architecture:** Adaptive worker count (perflevel0-based, 10 on M4 Pro, 6 on M1), **socket_create_pair + socket_export_stream** with 2MB SO_SNDBUF/SO_RCVBUF, **unbuffered I/O (stream_set_read_buffer 0)**, stream_select concurrent drain with **2MB fread**, 6x loop unrolling, bucket accumulation with 2-byte date IDs, 512KB read chunks, zero-copy hot loop, **8 parallel counting workers** with **ksort-free iteration** (idToDate), SIGKILL fast exit, **262KB child write / 512KB count write**, inline JSON building, 2MB slug sample
- **Target:** ~0.390s (approaching I/O + overhead floor)

## Bottleneck Model (measured via profiling, iter6 — updated with iter7 system time data)
| Phase | Time | % Internal | Serial? |
|-------|------|-----------|---------|
| setup (slugs+dates) | ~4.4ms | 2.8% | Yes |
| prefork (partition+sockets) | ~0.1ms | 0% | Yes |
| fork 9 children | ~4.1ms | 2.6% | Yes |
| parent_hotloop | ~127ms | 81.4% | Parallel |
| drain (stream_select) | ~2ms (est, was 5ms before 2MB fread) | 1.3% | Overlap |
| waitpid | ~0.5ms | 0.3% | Yes |
| merge (string concat) | ~4.3ms | 2.8% | Yes |
| count fork (8 workers) | ~3.4ms | 2.2% | Yes |
| count collect+output | ~12ms (est, was 15ms) | 7.7% | Parallel |
| count waitpid | ~0.5ms | 0.3% | Yes |
| **Internal total** | **~156ms** (est) | — | — |
| PHP + Tempest overhead | **~240ms** | — | Fixed |
| **Wall time** | **~396ms** | — | — |

**After iter7 optimization (unbuffered I/O + larger buffers):**
- System time: 205ms vs 329ms baseline (**-38% syscall reduction**). This is the single largest system-time drop in any iteration.
- User time: 1199ms vs 1245ms baseline (-3.7%)
- Variance: σ=10.5ms vs σ=25.1ms baseline (**stability doubled**)

**Primary bottleneck:** parent_hotloop (~127ms, 81%). At PHP's interpreter floor (~120ns/row).

**Secondary bottleneck:** PHP/Tempest overhead (~240ms, 61% of wall time). Irreducible.

**Tertiary:** count collect+output (~12ms est). Already highly optimized with 8 workers + large socket buffers.

## Technique Status

| Technique | Status | Notes |
|-----------|--------|-------|
| Multi-process fork | DONE | Adaptive workers via perflevel0 sysctl |
| Bucket accumulation (T1) | DONE | Per-slug string append + unpack('v*') + array_count_values |
| 6x loop unrolling (T3) | DONE | Fence at $lastNl - 720. 8x tested — no improvement |
| Position-based parsing (T4) | DONE | substr with hardcoded offsets, strpos with +52 skip |
| Socket pair IPC (T6) | DONE | **socket_create_pair + socket_export_stream + 2MB SO_SNDBUF/SO_RCVBUF** |
| Fully-qualified calls (T9) | DONE | \ prefix on all global functions |
| gc_disable | DONE | At top of file and parse() |
| Worker count tuning (T2) | DONE | **Adaptive: max(perflevel0, 6). M4 Pro=10, M1=6** |
| Newline skip (T8) | DONE | strpos offset +52 |
| Chunk size tuning | DONE | **512KB definitively optimal** (iter8 tested 1MB, 2MB, 75MB — all worse) |
| Parallel counting | DONE | 8 workers for batch_count+JSON |
| Optimized JSON output | DONE | Inline JSON building, **ksort-free idToDate iteration** |
| Zero-copy hot loop | DONE (iter4) | Eliminated $leftover.$raw concatenation |
| SIGKILL fast exit | DONE (iter5) | posix_kill(SIGKILL) skips PHP shutdown |
| 8 counting workers | DONE (iter5) | Doubled from 4 |
| Large socket buffers | DONE (iter6) | socket_create_pair + 2MB buffers |
| Optimized drain loop | DONE (iter6) | Pre-built int→worker map |
| Reduced slug sample | DONE (iter6) | 2MB instead of 4MB |
| **Unbuffered I/O** | **DONE (iter7)** | **stream_set_read_buffer($fh, 0) — eliminates PHP double-buffering** |
| **Large transfer buffers** | **DONE (iter7)** | **Drain 2MB fread, child 262KB write, count 512KB write** |
| **Adaptive worker count** | **DONE (iter7)** | **sysctl perflevel0: M1→6 workers, M4 Pro→10** |
| **Eliminate ksort** | **DONE (iter7)** | **Iterate idToDate (already ordered) instead of ksort** |
| **Manual ord() merge** | **DONE (iter7)** | **Replace unpack('vidx/Vlen') with ord() bit shifts** |
| Work stealing (T7) | TESTED | +4.6% regression on M4 Pro — flock overhead > benefit |
| 8x loop unrolling | TESTED | No improvement over 6x |
| Worker-side counting | TESTED | REGRESSION at 10M scale |
| Micro-optimizations alone (iter6) | TESTED | +1.3% REGRESSION without large buffers |

## Dead Ends
- **Micro-optimizations without socket buffers (iter6)**: +1.3% regression with σ=31.6ms.
- **Worker-side counting (iter5)**: At 10M rows, counted format larger than raw. Would only help at 100M.
- **Child-side counting (iter2)**: +55% regression.
- **Work stealing with flock (iter3)**: +4.6% regression on M4 Pro. MAY help on M1 due to heterogeneous cores — #1 leaderboard entry uses this approach.
- **Interleaved drain (iter3)**: No improvement.
- **12/14 workers on M4 Pro**: 2.5-8% slower than 10.
- **4MB/2MB chunks (pre-zero-copy)**: 6-8% slower than 256KB.
- **8x loop unrolling**: No improvement over 6x.
- **8-char date keys, do-while loop, merge-during-drain (iter4)**: No measurable improvement.
- **shmop IPC (iter5)**: Deadlock at scale.
- **Single-threaded JSON (iter5)**: 4× regression.
- **Candidate C (adaptive+merge) alone (iter7)**: shell_exec overhead (~2ms) negates merge optimization. Combined with A's I/O changes, neutral.
- **Candidate B (ksort elimination) alone (iter7)**: -1.9%, under 2% threshold.
- **2MB read chunks (iter8)**: +1.6% regression. System time 259ms vs 209ms. Larger reads increase page fault/TLB overhead.
- **1MB read chunks (iter8)**: +0.8% regression. System time 228ms vs 209ms. Same mechanism as 2MB.
- **Full-segment single read (iter8)**: +6.5% regression. System time 304ms vs 209ms. Loading 75MB per worker causes massive page fault overhead. String allocation for 75MB is very expensive.
- **Setup micro-opts alone (iter8)**: -1.2% (under 2% threshold). sprintf→manual strings + 1MB slug sample + write loop optimization. σ=9.7ms (very stable). Insufficient to justify adoption.

## Critical Scaling Insight (iter5)
**Data compression ratio depends on scale:**
- At 10M rows: ~3700 visits/slug/worker, ~3652 dates → collision rate ~1.01x → counted data ≥ raw data
- At 100M rows: ~37000 visits/slug/worker, ~3652 dates → collision rate ~10.1x → counted data 5× smaller than raw
- **Any optimization that depends on date collision rate will behave differently at 10M vs 100M scale**

## Key Finding: Chunk Size Is Definitively Optimal at 512KB (iter8)

Systematic chunk size sweep with unbuffered I/O:
| Chunk Size | Mean Time | System Time | vs 512KB |
|---|---|---|---|
| **512KB** | **406.7ms** | **209ms** | **baseline** |
| 1MB | 409.8ms | 228ms | +0.8% |
| 2MB | 413.1ms | 259ms | +1.6% |
| 75MB (full segment) | 433.0ms | 304ms | +6.5% |

**Analysis:** System time increases monotonically with chunk size. The 512KB chunk size is optimal because:
1. Fits within macOS VM page management granularity
2. Minimizes page fault overhead during reads
3. Keeps working set within L2 cache pressure range
4. Previous finding (iter4, pre-unbuffered) that 2MB/4MB were slower is **confirmed** to hold with unbuffered I/O

## Leaderboard Research (iter7)
Top entries on 100M rows (Mac Mini M1):
1. **alexandre-daubois (#46)**: 4.32s — Dynamic workers (hw.ncpu), work stealing (2× segments + flock), file-based IPC, stream_set_read_buffer(0), single-phase architecture
2. **xHeaven (#3)**: 4.61s — 12 workers, temp file IPC, 6x unrolling, pre-computed dates
3. **johnwedgbury (#116)**: 4.66s — 12 workers, socket pairs (stream_socket_pair), 4x unrolling, 4MB read buffer, adaptive 16/32-bit packing

**Key insight:** #1 entry uses SIMPLER architecture (file IPC, no separate counting phase) but wins due to work stealing on heterogeneous M1 cores. Socket-based IPC (#116 at 4.66s) is NOT faster than file-based (#46 at 4.32s) on M1.

## Experiment Results

### Iteration 8: Chunk size and setup optimization (NO IMPROVEMENT)
| Candidate | Changes | Time (20 runs) | Delta vs baseline |
|---|---|---|---|
| Baseline | Iter7 code | 406.7ms ± 20.5ms | — |
| A (2MB chunks) | chunkSize 512KB→2MB | 413.1ms ± 16.3ms | +1.6% WORSE |
| B (full-segment read) | Load entire segment in one fread | 433.0ms ± 27.0ms | +6.5% WORSE |
| C (setup opts) | sprintf elimination + 1MB sample + write opt | 401.7ms ± 9.7ms | -1.2% (under threshold) |
| 1MB chunks (quick test) | chunkSize 512KB→1MB | 409.8ms ± 13.0ms | +0.8% WORSE |

**Key insight:** 512KB is definitively the optimal chunk size with unbuffered I/O. Larger reads monotonically increase system time due to page fault/TLB overhead. Setup micro-opts yield <2% — at the noise floor. **First iteration with zero improvement since iter4's marginal +0.6%.**

### Iteration 7: Unbuffered I/O + larger buffers (414ms → 396ms)
| Candidate | Changes | Time (20 runs) | Delta vs baseline |
|---|---|---|---|
| Baseline (back-to-back, 20 runs) | Iter6 code | 414.4ms ± 25.1ms | — |
| A (I/O optimizations) | stream_set_read_buffer(0) + 2MB drain + 262KB write + 512KB count write | 396.1ms ± 10.0ms | **-4.4%** |
| B (ksort elimination) | Iterate idToDate instead of ksort | 406.6ms ± 14.7ms | -1.9% |
| C (adaptive workers + merge) | perflevel0 workers + ord() merge | 416.2ms ± 16.2ms | +0.4% |
| **Combined A+B+C** | **All above** | **396.0ms ± 10.5ms** | **-4.4%** |

### Iteration 6: Large socket buffers + optimizations (423ms → 406ms)
8 counting workers + large socket buffers + drain optimization. -3.9%.

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

## COMPLETE Assessment (iter8)

**Optimization trajectory:** 3906→558→521→483→480→418→406→396→396ms.
**Gains per iteration:** -85.7%, -6.7%, -7.3%, -0.6%, -12.9%, -3.9%, -4.4%, **0%**.

**COMPLETE criteria check:**
- 5+ consecutive iterations with no improvement? NO — 1 consecutive (iter8). Iter4 had marginal improvement (0.6%), so arguably 2 flat iterations in 8.
- Within 10% of I/O floor? YES — estimated floor ~376ms, we're at 396ms (5.3% above floor).
- All known techniques tried? **YES.** Every technique from the leaderboard catalog has been implemented or tested. Chunk sizes systematically swept. Setup phase fully optimized. Hot loop at interpreter floor. IPC fully optimized with large socket buffers. Counting parallelized with 8 workers.

**Remaining theoretical ideas (all very low confidence):**
1. Work stealing for M1 — can't test locally, regresses on M4 Pro
2. Single-phase architecture — would help at 100M but regresses at 10M
3. Integer-keyed buckets — analyzed in iter8, would add an extra lookup per line, net negative
4. Merge-during-drain — complex to implement, saves ~4ms (1% of wall time), high risk
5. Alternative IPC encoding — saves <0.1ms, trivial

**Verdict:** Approaching COMPLETE. One more iteration to try any remaining ideas. If iter9 also yields no improvement, emit COMPLETE.

## Performance Timeline

| Iteration | Best Time | Change | Delta |
|-----------|-----------|--------|-------|
| 0 (baseline) | 3.906s | Naive single-process | — |
| 1 | 0.558s | Multi-process + bucket accumulation + temp file IPC | -85.7% |
| 2 | 0.521s | Socket IPC + 256KB chunks + fused count/JSON | -6.7% |
| 3 | 0.483s | Parallel batch_count + JSON output (4 workers) | -7.3% |
| 4 | 0.480s | Zero-copy hot loop + 512KB chunks | -0.6% |
| 5 | 0.418s | 8 counting workers + SIGKILL fast exit | -12.9% |
| 6 | 0.406s | Large socket buffers (2MB) + drain/JSON optimization | -3.9% |
| 7 | 0.396s | Unbuffered I/O + larger buffers + adaptive workers | -4.4% |
| **8** | **0.396s** | **No improvement (chunk sizes + setup opts all regress or neutral)** | **0%** |

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB, shmall: 1024 pages (4MB total), shmseg: 8
- kern.ipc.maxsockbuf: 8MB
- data.csv: 750,949,374 bytes (~751MB, 10M rows)
- hyperfine available
- **Benchmark variance note**: σ=10.5ms with unbuffered I/O (very stable, best variance yet).
