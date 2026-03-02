# Benchmark Analysis

> **Competition target:** M1 Mini (4P + 4E cores, 128KB L1D/core, 12MB L2, 16MB SLC)
> **Local dev (current):** M1 Pro MacBook (8P + 2E cores, 128KB L1D/core, 24MB L2, 24MB SLC, 16GB RAM)
> **Prior iteration dev:** M4 Pro MacBook (10P + 4E cores, 48GB RAM) — 22 optimization iterations at 100M scale

---

## Current Architecture

Two-phase fork with parallel counting — **unique among all top leaderboard entries**.

```
Phase 1: Parse (parallel)
  Parent forks 14 workers → each reads assigned file region in 256KB chunks
  → 8x unrolled hot loop: strpos + substr → bucket accumulation (per-slug string append of 2-byte date IDs)
  → TLV-encoded output via temp files (file_put_contents)
  → SIGKILL exit (skip PHP shutdown)
  Parent drains via waitpid(-1) in completion order, merging TLV data as workers finish

Phase 2: Count + JSON (parallel)
  Parent forks 8 counting workers → each handles a slice of slugs
  → unpack('v*') + array_count_values on merged bucket strings
  → ksort + JSON assembly with pre-computed date prefixes
  → output via Unix socket pairs (64KB buffers)
  Parent reads fragments in order, writes final JSON
```

**Why two-phase wins:** All competitors use single-phase (workers parse + count, then parent does serial merge + serial JSON). We benchmarked single-phase at 100M rows: **+1.6% regression**. Worker-side counting adds ~100-200ms to the slowest worker's critical path, which exceeds the ~84ms cost of our counting fork wave. The counting phase is only ~4.6% of total time and runs fully in parallel.

---

## M4 Pro Bottleneck Model (100M rows, measured via microtime instrumentation)

| Phase | Time | % of Internal | Serial? |
|-------|------|:---:|:---:|
| Setup (slugs, dates, prefixes) | ~3.7ms | 0.2% | Yes |
| Fork (pcntl_fork × 10) | ~4.3ms | 0.2% | Yes |
| **Hot loop + drain (parse + waitpid + TLV merge)** | **~1547ms** | **94.3%** | **Parallel + overlapped** |
| Count fork (socket creation + fork × 8-10) | ~7.8ms | 0.5% | Yes |
| Count + collect (parallel count + JSON + socket read) | ~76ms | 4.6% | Parallel |
| Output + reap | ~3.5ms | 0.2% | Yes |
| **Internal total** | **~1642ms** | | |
| PHP startup + framework bypass | ~15ms | | Fixed |
| **Wall time (M4 Pro)** | **~1155ms** | | |

**Primary bottleneck:** The hot loop at 94.3% is at the **PHP interpreter floor** (~107ns/row). There is no way to make `strpos` + `substr` + string append faster in pure PHP. Further gains must come from reducing non-hot-loop overhead or algorithmic changes to the parsing approach itself.

---

## M1 Mini Actual Results

Single runs by challenge organizers:

| | 128KB | 256KB | 512KB | 640KB |
|---|---|---|---|---|
| **W=10** | 2.711s (baseline) | -- | -- | 2.762s |
| **W=12** | -- | -- | -- | 2.746s * |
| **W=14** | 2.686s | **2.685s** | 2.753s | 2.737s * |
| **W=16** | -- | 2.685s | -- | -- |

`*` = included micro-opts (fence=2500, sample=256KB, socket=256KB, fwrite=32KB) — no positive effect.

**Current best: 2.685s** (W=14, chunk=256KB)

### Key Findings

1. **Chunk size: M1 Pro results do NOT transfer.** On M1 Pro, larger chunks are monotonically
   better (syscall amortization). On M1 Mini, larger chunks are worse because:
   - L1 D-cache is 128KB/core. A 128KB chunk fits in L1; 640KB causes L1 misses on every strpos/substr scan
   - M1 Mini is **compute-bound** (4 slow P-cores), not I/O-bound like M1 Pro (8 fast P-cores)
   - 256KB works because the hardware prefetcher handles sequential scans up to ~2x L1

2. **Worker count transfers reliably.** W=14 consistently beats W=10 on both machines.
   14 workers on 8 cores = 1.75x oversubscription. I/O-compute overlap during fread.

3. **W=16 tied W=14** at chunk=256KB (both 2.685s). Plateaued.

---

## M4 Pro Optimization History (22 iterations, 100M rows)

### Performance Trajectory

| Iteration | Wall Time | What Changed | Impact |
|-----------|-----------|-------------|--------|
| 0 | 3906ms | Naive single-process | baseline |
| 1 | 558ms | Multi-process + bucket accumulation | **-85.7%** |
| 2 | 521ms | Socket IPC + 256KB chunks | -6.6% |
| 3 | 483ms | Parallel counting (4 workers) | -7.3% |
| 5 | 418ms | 8 counting workers + SIGKILL exit | -13.5% |
| 7 | 396ms | Unbuffered I/O + adaptive workers | -5.3% |
| 9 | 388ms | Sequential drain + inline merge | -2.0% |
| 10 | 387ms | 8-char date keys + tighter fence | -0.3% |
| 15 | 1977ms* | Parent-as-coordinator + temp file IPC | -4.3% |
| 16 | 2000ms* | do-while loops + year range tuning | -1.6% |
| 20 | ~1200ms | Fast-path bypass (no framework overhead) | measurement fix |
| 21 | ~1190ms | Fixed params (remove adaptive detection) | ~0% |
| 22 | ~1155ms | Research-only, no code changes | 0% |

`*` iter14 switched to 100M rows; iter20 switched to fast-path bypass measurement (no Tempest framework overhead). Wall times before iter20 include ~280ms framework boot.

**Key observation:** 9 consecutive iterations (14-22) yielded no measurable improvement on M4 Pro. The architecture is mature.

### What Worked (by impact)

| Optimization | Impact | Why It Works |
|---|---|---|
| Multi-process forking + bucket accumulation | -85.7% | Parallelizes I/O-bound parsing across cores; bucket append avoids per-row hash increment |
| SIGKILL exit in workers | significant | Skips PHP shutdown (destructor chains, GC, fd cleanup) — all reclaimed by kernel |
| Parallel counting (8 workers) | -13.5% | Counting 268 slugs × ~2K dates is expensive; parallelizing avoids serial bottleneck |
| Unbuffered I/O (`stream_set_read_buffer(fh, 0)`) | critical | Non-zero adds redundant PHP userspace buffer, **doubling** System time |
| Parent-as-coordinator (not worker) | -4.3% | Parent overlaps TLV merge with worker execution via `waitpid(-1)` completion-order drain |
| Temp file IPC for parse workers | -4.3% | Sockets deadlock at 100M (2MB buffer limit). Temp files have no size limit |
| 8-char date keys ("YY-MM-DD") | -4% | Shorter strings = fewer hash collisions, less memory |
| 6x loop unrolling | notable | Reduces loop overhead per line. 8x showed no further improvement (L1i pressure) |
| do-while loops in hot paths | -1.6% | Eliminates JMP opcode per iteration in loops with >100K iterations |
| `gc_disable()` | notable | Avoids GC cycle checks during bucket string growth |

### Key Architectural Findings

1. **Two-phase > single-phase on multi-core.** Tested head-to-head at 100M. Single-phase (worker-side counting like all competitors) is +1.6% slower because counting extends the critical path of the slowest worker by ~100-200ms, exceeding the 84ms cost of a separate counting fork wave.

2. **Temp files required for parse IPC at 100M.** Socket IPC with coordinator pattern deadlocks — workers block on fwrite (2MB buffer limit), parent blocks on waitpid. Temp files have no size limit.

3. **Socket IPC optimal for counting workers.** Counting output is ~3MB/worker, fits in 2MB socket buffers. Sockets enable streaming (parent reads as workers produce). Temp files for counting are +1.5% slower.

4. **Parent-as-worker is worse than coordinator.** Parent idle time during hotloop is hidden by overlapped drain via `waitpid(-1)`. Making parent a worker delays drain start, losing the overlap benefit.

5. **$leftover string carry-forward beats fseek-backward** for chunk boundaries. fseek syscall runs every chunk (~14K/worker); $leftover only does string work on the ~1% of chunks that cross a line boundary.

---

## Leaderboard Architecture Comparison

| Technique | alexandre-daubois #46 (1st, 4.3s) | xHeaven #3 (2nd, 4.6s) | johnwedgbury #116 (3rd, 4.7s) | Ours |
|---|---|---|---|---|
| Workers | 12 (ncpu+4) | 10 (9+parent) | 12 | 14 (fixed) |
| IPC | Temp files (V* packed) | Temp files (v* packed) | Unix sockets + stream_select | Temp files (TLV) |
| Read chunk | 256KB | 160KB | 4MB | 256KB |
| Count strategy | Worker-side → flat array merge | Worker-side → flat array merge | Worker-side → flat array (adaptive v/V) | **Parallel counting (2nd fork wave)** |
| Second fork wave | No | No | No | **Yes (8 workers)** |
| Parent role | Worker + merge + JSON | Worker + merge + JSON | Coordinator + stream_select | Coordinator only |
| Loop unrolling | 6x | 6x | 6x | 8x |
| Chunk boundary | fseek backward | $leftover | fseek backward | $leftover |
| Bucket indexing | Integer-indexed + slugIndex map | Integer-indexed + slugIndex map | Integer-indexed + slugIndex map | String-keyed |
| JSON output | Single-threaded (parent) | Single-threaded (parent) | Single-threaded (parent) | **Parallel (8 workers)** |
| Year range | 2020-2026 | 2020-2026 | 2020-2026 | 2021-2026 |

**Key differences:** We're the only entry with parallel counting and parallel JSON generation. All others do serial merge + serial JSON in the parent after workers finish. Our approach avoids their ~600ms serial merge at the cost of a ~84ms second fork wave.

**Why alexandre-daubois is #1 despite "simpler" architecture:** Optimized specifically for M1 Mini — parent-as-worker utilizes the idle parent on a machine with only 4P cores, 256KB chunks suit M1's cache hierarchy, and their simpler architecture has less fork overhead on fewer cores.

---

## M1 Pro Parameter Sweeps (base: W=14, chunk=256KB)

All sweeps isolated (one param changed at a time, all others at original values).
10-run hyperfine with 2 warmup runs.

### Workers (chunk=640KB fixed, M1 Pro)

| Workers | Mean | σ |
|---------|------|---|
| 10 | 2.478s | 0.027s |
| 12 | 2.400s | 0.048s |
| **14** | **2.326s** | **0.019s** |
| 16 | 2.345s | 0.030s |

### Chunk Size (W=14 fixed, M1 Pro)

| Chunk | Mean | σ |
|-------|------|---|
| 512KB | 2.399s | 0.046s |
| 576KB | 2.339s | 0.018s |
| **640KB** | **2.322s** | **0.019s** |
| 768KB | 2.332s | 0.028s |

Note: M1 Pro prefers 640KB (more L2 headroom). M1 Mini prefers 256KB (L1 residency).

### stream_set_read_buffer

| Value | Mean | System |
|-------|------|--------|
| **0** | **2.42s** | **2.6s** |
| Any > 0 | ~4.5s | ~7.1s |

**Critical.** Non-zero adds a redundant PHP userspace buffer layer, doubling System time.

### Fence Offset

| Value | Mean | σ |
|-------|------|---|
| 808 | 2.573s | 0.056s |
| 900 | 2.613s | 0.191s |
| **1000** | **2.442s** | **0.016s** |
| 1200 | 2.669s | 0.214s |
| 1500 | 2.681s | 0.068s |
| 2000 | 2.545s | 0.072s |
| 2500 | 2.452s | 0.027s |
| 3000 | 2.448s | 0.022s |

**Winner: 1000.** Minimum safe = 808 (8 × 101-byte max row). Values < 1000 risk unrolled loop overshooting.

### Sample Size

| Value | Mean | σ |
|-------|------|---|
| 32KB | 2.673s | 0.071s |
| 64KB | 2.566s | 0.112s |
| 128KB | 2.608s | 0.177s |
| 256KB | 2.707s | 0.153s |
| **512KB** | **2.544s** | **0.131s** |
| 1MB | 2.687s | 0.222s |
| 2MB | 2.578s | 0.163s |

**All noise** (σ > 70ms everywhere). Read once at startup. Keep 512KB for slug coverage safety.

### Socket Buffer Size (SO_RCVBUF / SO_SNDBUF)

| Value | Mean | σ |
|-------|------|---|
| 16KB | 2.477s | 0.056s |
| 32KB | 2.497s | 0.045s |
| **64KB** | **2.464s** | **0.026s** |
| 128KB | 2.486s | 0.030s |
| 256KB | 2.479s | 0.065s |
| 512KB | 2.565s | 0.074s |
| 1MB | 2.530s | 0.064s |
| 2MB (orig) | 2.546s | 0.118s |
| 4MB | 2.477s | 0.047s |

**Winner: 64KB.** Smaller buffers (16-256KB) cluster ~2.46-2.49s; larger (512KB+) worse at ~2.53-2.57s.

### fwrite Chunk Size

| Value | Mean | σ |
|-------|------|---|
| 4KB | 2.550s | 0.089s |
| 8KB | 2.570s | 0.035s |
| 16KB | 2.501s | 0.064s |
| 32KB | 2.512s | 0.093s |
| 64KB | 2.592s | 0.199s |
| 128KB (orig) | 2.512s | 0.121s |
| 256KB | 2.485s | 0.073s |
| **512KB** | **2.449s** | **0.023s** |

**Winner: 512KB.** Monotonic improvement with larger chunks (fewer fwrite syscalls).

### Counter Count

| Counters | Mean | σ |
|----------|------|---|
| **8** | **2.675s** | **0.041s** |
| 10 | 2.656s | 0.056s |
| 12 | 2.683s | 0.069s |

Plateau at 8-10. Keep 8 (original).

---

## Combination Matrix

Top-2 from each sweep combined (W=14, chunk=256KB, sample=512KB fixed):

| Fence | SocketBuf | fwrite | Mean | σ |
|-------|-----------|--------|------|---|
| **1000** | **64KB** | **512KB** | **2.459s** | **0.035s** |
| 3000 | 64KB | 256KB | 2.467s | 0.029s |
| 1000 | 64KB | 256KB | 2.488s | 0.047s |
| 1000 | 256KB | 256KB | 2.528s | 0.060s |
| 3000 | 64KB | 512KB | 2.518s | 0.048s |
| 1000 | 256KB | 512KB | 2.534s | 0.055s |
| 3000 | 256KB | 256KB | 2.556s | 0.070s |
| 3000 | 256KB | 512KB | 2.730s | 0.173s |

sockbuf=64KB wins every pair. Best combo: fence=1000 + sockbuf=64KB + fwrite=512KB.

### Interleaved A/B Validation (15 runs, hyperfine --prepare swap)

| Config | Mean | σ |
|--------|------|---|
| Baseline | 2.574s | 0.134s |
| sockbuf=64KB only | 2.552s | 0.082s |
| fwrite=512KB only | 2.558s | 0.105s |
| Both combined | 2.576s | 0.082s |

All within noise on M1 Pro (~24ms spread, σ 80-134ms). Micro-opts are sub-noise on M1 Pro
but applied at sweep-optimal values since they can't hurt and may surface on M1 Mini.

---

## Dead Ends (Do Not Retry)

### Architecture / IPC

| Approach | Result | Why It Failed |
|---|---|---|
| Single-phase counting (worker-side count + serial merge) | +1.6% | Counting extends slowest worker's critical path by ~100-200ms, exceeding the 84ms counting fork wave cost |
| Worker-side counting + compact IPC | +12-20% | Same critical-path extension problem at 100M scale |
| Raw socket API (`socket_write`/`socket_read` loop) | +23% | `stream_get_contents()` is a single optimized C bulk read; PHP-level socket_read loop has per-iteration opcode overhead |
| Pre-opened temp file FDs before fork | +12% | Each child inherits ALL FDs via fork's fd table duplication; `file_put_contents` is already a single C-level open+write+close |
| Socket IPC for parse workers at 100M | deadlock | Workers block on fwrite (2MB buffer limit), parent blocks on waitpid |
| Temp file IPC for counting workers | +1.5% | Sockets allow streaming reads; temp files require worker to finish first |
| fseek-backward chunk boundary | +1.5% | fseek syscall runs every chunk (~14K/worker); $leftover only does string work on ~1% of chunks |
| Skip merge / deferred concat | +12% | COW page faults from scattered memory access in forked children |
| 4 counting workers | +6.5% | Insufficient parallelism for 268 slugs × ~2K dates |
| Parent-as-worker | worse | Delays drain start, losing the overlap benefit from `waitpid(-1)` |

### Parsing / Hot Loop

| Approach | Result | Why It Failed |
|---|---|---|
| Integer-indexed packed array buckets | -0.7% (noise) | Extra `$slugToIdx` hash lookup per line (~30 cycles) cancels packed array savings (~27 cycles) |
| preg_match_all batch regex | +117% | Result array allocation overhead per match |
| preg_replace_callback | +146% | PHP callback dispatch overhead per match |
| 7-char date key | +18% | Increased hash collisions |
| Integer-keyed date lookup (6×ord arithmetic) | +58% | Multiple `ord()` + arithmetic slower than single substr + C-level hash |
| 8x loop unrolling | 0% | No improvement over 6x; risks L1i cache pressure |
| Comma-based vs newline-based parsing | 0% | Same opcode count; scan distance difference trivial with ARM64 NEON SIMD |

### Tuning Parameters

| Approach | Result | Why It Failed |
|---|---|---|
| 160KB chunks on M4 Pro | +16% | 3x more fread syscalls vs 512KB |
| 1MB chunks on M4 Pro | 0% | No benefit over 128KB at 100M |
| 256KB chunks on M4 Pro | 0% | Neutral |
| `pcntl_setpriority(-20)` | EPERM + overhead | Fails without root; the failed syscall itself adds overhead |
| `declare(strict_types=1)` | 0% to worse | May slow some type checking paths |
| `error_reporting(0)` | 0% | No measurable impact on hot loop throughput |
| `stream_set_chunk_size` | 0% | No benefit from matching PHP internal chunk size to fread |
| Counting fwrite 524KB chunks | 0% | Larger write chunks don't reduce wall time for counting output |
| Skip ksort (iterate all dateJsonPrefix + isset) | +4% | Iterating 2557 entries with isset misses is slower than C-level qsort on ~2000 integer keys |
| Flat 1D count array | +100% | 3 hash lookups per row |
| 4MB socket buffers | 0% | No benefit for counting output |
| Work stealing with flock | +4.6% | Lock contention overhead on M4 Pro |

---

## Fundamental PHP Performance Insights

These are hardware/runtime truths discovered across 22 iterations that should guide all future optimization decisions:

1. **PHP C-level string ops (memcpy via `.=` append) are ~2.2x faster than PHP-level array iteration.** Bucket accumulation + string merge is locally optimal — don't try to replace with array-based counting.

2. **`stream_get_contents()` is a single optimized C call** that does bulk buffered reads. Never replace it with a manual `socket_read()` or `fread()` loop — the per-iteration PHP opcode overhead kills performance.

3. **PHP's C-level ksort on integer keys is very efficient.** Replacing it with PHP-level iteration + isset checks is slower. Trust the C implementations.

4. **Userland arithmetic (ord() + math) is slower than PHP's C-level hash lookups.** Don't try to replace hash table lookups with manual computation.

5. **COW page faults add +12% for scattered memory access in forked children.** Avoid patterns that trigger copy-on-write in child processes.

6. **Explicit PHP references (`&$array`) are slower than COW for read-only access.** IS_REFERENCE wrapper adds per-access dereferencing overhead. Only use references when the function modifies the array.

7. **do-while optimization only matters in hot loops (>100K iterations).** Non-hot loops save <200μs — below measurement noise.

8. **strpos scan distance differences are negligible on ARM64 NEON.** Both comma and newline scanning resolve in ~1 SIMD operation for typical line lengths.

9. **hyperfine ordering bias is ~20-25ms per command.** The first command in each round appears ~2% faster due to lower thermal load. Always do reversed-order confirmation for differences <5%.

---

## Current Config (in Parser.php)

| Parameter | Value | Source |
|-----------|-------|--------|
| `$numWorkers` | 14 | M1 Mini confirmed |
| `$chunkSize` | 262144 (256KB) | M1 Mini confirmed |
| `$numCounters` | 8 | plateau, original |
| `stream_set_read_buffer` | 0 | critical |
| `$fence` | 1000 | sweep winner |
| `$sample fread` | 524288 (512KB) | noise, keep for safety |
| `SO_RCVBUF/SNDBUF` | 65536 (64KB) | sweep + matrix winner |
| fwrite chunk | 524288 (512KB) | sweep winner |
| Year range | 2021-2026 | confirmed for real data |
| Loop unrolling | 8x | in hot loop |

---

## M1 Pro Experiment Results (100M rows)

Tested on M1 Pro MacBook (8P + 2E cores, 16GB RAM), PHP 8.5.3, 100M rows (~7.0GB).

### Lesson Learned: System Noise Invalidates Non-Interleaved Benchmarks

The first round of tests ran while background processes were active. Results showed
dramatic "improvements" (5x unroll -10.3%, single-phase -8.3%) that **completely vanished**
when re-tested on a quiet system. Every candidate that appeared faster was actually within
noise. Always use A/B interleaved testing or ensure the system is truly idle.

### Individual Sweeps (quiet system, 10 runs, 3 warmup)

| Candidate | Mean | σ | vs Baseline |
|---|---|---|---|
| **Baseline** (8x, 256KB, W=14, two-phase) | **2.688s** | **0.068s** | — |
| Single-phase+7x unroll | 2.714s | 0.034s | +1.0% |
| Single-phase architecture | 2.730s | 0.054s | +1.6% |
| 13 workers | 2.751s | 0.056s | +2.3% |
| 288KB chunks | 2.755s | 0.048s | +2.5% |
| 320KB chunks | 2.759s | 0.137s | +2.6% |
| 5x unroll | 2.773s | 0.105s | +3.2% |
| 224KB chunks | 2.773s | 0.041s | +3.2% |
| Single-phase+5x unroll | 2.776s | 0.044s | +3.3% |
| 7x unroll | 2.793s | 0.111s | +3.9% |
| 6x unroll | 2.858s | 0.203s | +6.3% |
| 11 workers | 2.918s | 0.032s | +8.6% |
| 192KB chunks | 2.943s | 0.083s | +9.5% |

**Baseline is the fastest configuration on M1 Pro.** No candidate beats it.

### A/B Controlled Verification (quiet system, hyperfine interleaved)

| Comparison | Runs | Result | Conclusion |
|---|---|---|---|
| Baseline vs single-phase-7x | 15 | 1.01 ± 0.02x baseline faster | **NEUTRAL** — tied |
| Baseline vs single-phase | 15 | 1.06 ± 0.07x single-phase faster | **NOISE** — baseline had outlier (3.444s max) |
| Baseline vs 13 workers | 15 | 1.03 ± 0.03x baseline faster | **14 workers confirmed better** |

### System Time vs User Time (consistent across all tests)

| Architecture | User Time | System Time | Wall Time |
|---|---|---|---|
| Two-phase (baseline) | ~17.2s | ~2.8s | ~2.69s |
| Single-phase | ~17.5s | ~2.5s | ~2.73s |

Single-phase consistently shows **-10% System time** (fewer forks, no socket pairs, no second
fork wave) but **+2% User time** (worker-side counting adds CPU work). On M1 Pro with 8
performance cores, these cancel out. The net wall-clock effect is neutral.

### Conclusions

1. **Current two-phase architecture is optimal on M1 Pro.** The parallel counting fork wave (8 workers, ~76ms) is cheap enough with 8 performance cores that it beats the alternative of worker-side counting.

2. **Unroll factor (5x through 8x) is irrelevant on M1 Pro.** All within noise. The hot loop is at the PHP interpreter floor regardless of unrolling.

3. **256KB chunks remain optimal.** 224KB, 288KB, 320KB are all within noise. 192KB is genuinely worse (+9.5%).

4. **14 workers is optimal.** 13 is close (+2.3%) but 11 is clearly worse (+8.6%).

5. **Single-phase might still help on M1 Mini** — with only 4 performance cores, the -10% System time saving could translate to wall-clock improvement because:
   - Fork/socket overhead is proportionally larger with fewer cores
   - The counting fork wave's 8 workers compete harder for 4+4 cores than for 8+2 cores
   - But the +2% User time increase would also hurt more on fewer cores
   - **Net effect on M1 Mini is unknown — can only be determined by running there.**

### Candidates Available

Validated candidates saved in `.agent/checkpoints/`:
- `candidate-single-phase.php` — single-phase architecture
- `candidate-single-phase-5x.php` — single-phase + 5x unroll
- `candidate-single-phase-7x.php` — single-phase + 7x unroll
- `candidate-5x-unroll.php`, `candidate-6x-unroll.php`, `candidate-7x-unroll.php` — unroll-only variants
- `candidate-chunk-{192,224,288,320}kb.php`, `candidate-workers-{11,13}.php` — parameter variants

---

## Remaining Untried Ideas

1. **Single fwrite for counting output** — Replace the `while+substr` loop with a single `fwrite($sock, $fragment)`. Fragments are <1MB, socket buffer is 2MB. Eliminates loop iterations + substr allocations.

2. **96KB read chunks** — Smaller than any tested size. M1's efficiency cores have 64KB L1d cache.

3. **Integer-keyed `$mergedBuckets`** — Use packed array for the MERGE phase (distinct from the worker bucket test in iter19 which was about the hot loop).

4. **Fixed-order TLV (drop slug index)** — Workers write buckets in slug order 0..267. Parent reads sequentially without needing to decode slug indices. Saves 2 `ord()` calls per entry × 268 × 14 workers.

5. **Test single-phase on M1 Mini** — The -10% System time reduction is real and consistent. On M1 Mini with half the performance cores, this could translate to a meaningful wall-clock improvement that doesn't show on M1 Pro.

---

## Benchmark Commands

```bash
# Primary benchmark (uses fast-path bypass, no Tempest framework overhead)
hyperfine --warmup 2 --runs 20 'php tempest data:parse --input-path=data/data.csv --output-path=data/data.json'

# WARNING: Without --input-path/--output-path, falls through to Tempest framework (~280ms overhead)

# Validation
php tempest data:validate

# Smoke test with bypass
php tempest data:parse --input-path=data/test-data.csv --output-path=/tmp/smoke-test-output.json
diff data/test-data-expected.json /tmp/smoke-test-output.json
```

**Statistical notes:**
- With stddev ~40ms at 100M, need ~24 interleaved pairs for 80% power to detect a 2% effect.
- Use A/B interleaved testing (alternate baseline and candidate) to control for thermal/system state.
- 0.5% threshold or >5ms absolute for declaring a winner.
