## Directive: Final Exhaustive Sweep (0.5% threshold)

These experiments are GENUINELY UNTRIED — cross-referenced against ALL 22 Ralph iterations, 16+ manual tests, and 9 competitor PRs. The threshold is now 0.5%.

### IMPORTANT: Benchmark command
```bash
hyperfine --warmup 2 --runs 20 'php tempest data:parse --input-path=data/data.csv --output-path=data/data.json'
```

### Batch 1: Free wins — eliminate wasted work in SIGKILL'd workers
1. **Remove `unset($buckets)` (line 147) AND `fclose($fh)` (line 140) in parse workers** — Workers call `posix_kill(SIGKILL)` on line 150. Both `unset` and `fclose` are wasted work — SIGKILL reclaims everything. The `unset` iterates 268 hash entries and frees ~20MB of strings. The `fclose` flushes buffers and releases fd. Both are pointless before SIGKILL. Test removing both.
2. **Single fwrite for counting worker output** — Replace the while loop (lines 234-238: `while ($written < $len) { fwrite(substr($fragment, $written, 131072)); }`) with a single `fwrite($sock, $fragment)`. Fragments are typically <1MB. The 2MB socket buffer can handle it in one call. This eliminates loop iterations + substr allocations. Note: iter21 tested 524KB fwrite chunks (NEUTRAL), but that still loops — this eliminates the loop entirely.

### Batch 2: Unroll factor variations
3. **5x loop unroll** — Fence = $lastNl - 500. Only 4x, 6x, 8x have been tested. 5x has a smaller L1i footprint than 6x which might benefit M1's efficiency cores (64KB L1i vs 192KB on perf cores).
4. **7x loop unroll** — Fence = $lastNl - 700 (7 × 99 = 693). Between tested 6x (current, optimal) and 8x (neutral). Different instruction scheduling might hit a sweet spot.

### Batch 3: Untested chunk sizes
5. **96KB read chunks (98304)** — Smaller than any tested size. M1's efficiency cores have 64KB L1d cache. 96KB chunks would have 64KB in L1d + 32KB in L2, vs 128KB being entirely in L2 on efficiency cores.
6. **192KB read chunks (196608)** — Between tested 128KB and 256KB. Never tested.

### Batch 4: Worker count variations
7. **6 counting workers** — Between tested 4 (regression) and 8 (current). Less fork + socket overhead. The counting phase is only ~9% of total time.
8. **12 parse workers** — Higher oversubscription. prateekbhujel (#157, 2.86s) and several top entries use 12. On M1 with 8 cores, more workers may hide I/O latency better.
9. **14 parse workers** — AcidBurn86 (#203, 2.94s) uses 14. Even more oversubscription.

### Batch 5: IPC/merge micro-tuning
10. **Integer-keyed `$mergedBuckets`** — Use `$mergedBuckets = array_fill(0, $slugCount, '')` instead of `array_fill_keys($slugOrderList, '')`. Merge with `$mergedBuckets[$slugIdx] .= substr(...)` instead of `$mergedBuckets[$slugOrderList[$slugIdx]] .= substr(...)`. Eliminates string-key hash lookup in merge phase (~2680 operations). NOTE: iter19 tested integer-keyed WORKER buckets (hot loop), this is about the MERGE phase only (non-hot, ~75ms).
11. **Fixed-order TLV (drop slug index bytes)** — Workers write buckets in slug order 0..267. Each entry: just 4-byte length + data (no 2-byte slug index). Parent reads sequentially: `for ($s = 0; $s < $slugCount; $s++) { read 4 bytes length; read data; $mergedBuckets[$s] .= data }`. Saves 2 ord() calls per entry × 268 × 10 = 5360 ord() calls.

### Batch 6: Architectural alternative
12. **Single-process baseline (no fork at all)** — armstrongsam25 (#189) achieved 2.91s on M1 with ZERO forking, 512MB chunks, 8x unroll. Worth testing on M4 Pro to quantify how much of our 1.2s is fork/IPC overhead vs actual parsing speed. If single-process is >1.5s on M4 Pro, our fork approach wins. If <1.5s, the overhead is significant and worth investigating.

### DO NOT RETRY (confirmed iter20-22)
- Raw socket API: +23% regression (iter20)
- Pre-opened temp file FDs: +12% regression (iter20)  
- pcntl_setpriority: EPERM + overhead (iter20)
- declare(strict_types=1): may slow (iter20)
- Skip ksort: +4% regression (iter20)
- error_reporting(0): NEUTRAL (iter21)
- stream_set_chunk_size: NEUTRAL (iter21)
- 160KB chunks: NEUTRAL (iter21)
- 256KB chunks: NEUTRAL (iter20)
- Counting fwrite 524KB: NEUTRAL (iter21)
- Non-blocking counting reads: analyzed+rejected (iter22)
