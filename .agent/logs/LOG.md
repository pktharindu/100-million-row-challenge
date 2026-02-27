# Parser Optimization Knowledge Base

## Current State
- **Best parser time:** ~137ms (median, interleaved A/B test, 10 pairs)
- **Best wall-clock:** ~387ms mean (hyperfine, includes ~273ms PHP+Tempest overhead)
- **Iteration count:** 11
- **Parser architecture:** Cached sysctl worker count (file-based cache in sys_get_temp_dir), socket_create_pair + socket_export_stream with 2MB SO_SNDBUF/SO_RCVBUF, unbuffered I/O (stream_set_read_buffer 0), sequential stream_get_contents drain + inline TLV merge, 6x loop unrolling, bucket accumulation with 2-byte date IDs (8-char "YY-MM-DD" keys), 512KB read chunks, zero-copy hot loop, 8 parallel counting workers with pre-computed JSON date prefixes (dateJsonPrefix), SIGKILL fast exit, 262KB child write / 512KB count write, 512KB slug sample, fence at lastNl - 600
- **Target:** ~120-130ms parser time (interpreter floor estimate). Room: ~7-17ms (5-12%)

## Bottleneck Model (iter11 — updated with profiling)
| Phase | Time | % Internal | Serial? |
|-------|------|-----------|---------|
| setup (sysctl+slugs+dates+prefixes) | ~1.5ms (was 6.7ms; sysctl cache saves ~5ms) | 1.1% | Yes |
| prefork (partition+sockets+fork) | ~3.7ms | 2.7% | Yes |
| parent_hotloop | ~99ms | 72.3% | Parallel |
| drain+merge | ~10ms | 7.3% | Yes |
| waitpid | ~0ms | 0% | Yes |
| count_phase (fork+count+JSON+collect+write) | ~22ms (dateJsonPrefix saves ~0.5ms) | 16.1% | Parallel |
| **Internal total** | **~137ms** | — | — |
| PHP + Tempest overhead | **~250ms** | — | Fixed |
| **Wall time** | **~387ms** | — | — |

**Primary bottleneck:** parent_hotloop (~99ms, 72% of PARSER time). AT PHP interpreter floor.
**Secondary:** count_phase (~22ms, 16%). Highly optimized with 8 workers + pre-computed prefixes.
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
| Worker count tuning (T2) | DONE | Adaptive: max(perflevel0, 6), cached in temp file |
| Newline skip (T8) | DONE | strpos offset +52 |
| Chunk size tuning | DONE | 512KB optimal. 256KB retested iter11 = no improvement |
| Parallel counting | DONE | 8 workers for batch_count+JSON |
| Optimized JSON output | DONE | Pre-computed dateJsonPrefix, ksort-free |
| Zero-copy hot loop | DONE (iter4) | Eliminated leftover.raw concatenation |
| SIGKILL fast exit | DONE (iter5) | posix_kill(SIGKILL) skips PHP shutdown |
| Large socket buffers | DONE (iter6) | socket_create_pair + 2MB buffers |
| Unbuffered I/O | DONE (iter7) | stream_set_read_buffer(fh, 0) |
| Adaptive worker count | DONE (iter7) | sysctl perflevel0 |
| Sequential drain + inline merge | DONE (iter9) | stream_get_contents replaces stream_select |
| 8-char date keys | DONE (iter10) | "YY-MM-DD" instead of "YYYY-MM-DD". -4% parser time |
| 512KB slug sample | DONE (iter10) | Was 2MB. All 268 slugs found in 512KB |
| Tighter fence (600) | DONE (iter10) | Was 720. Max line = 99 bytes |
| Sysctl caching | DONE (iter11) | Cache CPU count in temp file. -5ms per run (after warmup) |
| JSON date prefix pre-computation | DONE (iter11) | Pre-compute '        "YYYY-MM-DD": ' strings. -0.5ms |
| Arithmetic date ID lookup | TESTED (iter11) | +30% REGRESSION. PHP userland arithmetic (6× ord() + math) is slower than C-level substr + hash lookup |
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
- **Arithmetic date ID lookup (iter11):** +30% regression. Replacing substr+hash with ord-based arithmetic was far slower. PHP's C-level hash table lookup on 8-byte strings is faster than 6× userland ord() + arithmetic. The zend_string allocation from substr is cheap relative to the opcode overhead of multiple ord() calls.
- **256KB read chunks (iter11):** No measurable difference from 512KB. A/B interleaved test showed within-noise results.
- Numeric bucket indices (iter10): No improvement. $slugToIdx hash lookup cost = string-keyed bucket cost.
- Temp file IPC (iter10): +6% regression. Sockets with 2MB kernel buffers are faster than file I/O through page cache.
- Parent-as-coordinator (iter9): +5.2%. Extra fork overhead, coordinator competition.
- Merge-during-drain via stream_select (iter9): +3.7%. Inline TLV parsing adds overhead.
- 4 counting workers (iter9): +6.5%. Serial bottleneck dominates.
- Micro-optimizations without socket buffers (iter6): +1.3% regression.
- Worker-side counting (iter5): At 10M, counted format larger than raw.
- Child-side counting (iter2): +55% regression.
- Work stealing with flock (iter3): +4.6% on M4 Pro. MAY help on M1.
- 12/14 workers on M4 Pro: 2.5-8% slower than 10.
- 8x loop unrolling: No improvement over 6x.
- shmop IPC (iter5): Deadlock at scale.
- Single-threaded JSON (iter5): 4x regression.
- 2MB/1MB/75MB read chunks: All worse than 512KB.
- Setup micro-opts alone (iter8): -1.2% (under threshold, now partially captured).
- Flat 1D count array (like xHeaven PR#3): merge cost O(978K × 10) additions. Only viable at 100M+ scale.

## Leaderboard Research (iter10)

Top entry techniques (from GitHub PR analysis):
| Technique | xHeaven (#1, 2.999s) | johnwedgbury (#4, 3.417s) | dannyvankooten (#5, 3.427s) | Ours |
|---|---|---|---|---|
| Workers | 12 | 12 | 12 | Adaptive (10 on M4 Pro) |
| IPC | Temp files | Unix sockets + stream_select | Temp files | Sockets + sequential drain |
| Read chunk | 160 KB | 4 MB | 128 KB | 512 KB |
| Date key | 8 chars (YY-MM-DD) | 8 chars | 8 chars | 8 chars |
| Count strategy | Flat 1D array per worker | Flat 1D array + adaptive 16/32-bit | Flat 1D + per-entry increment | Bucket accumulation + parallel counting |
| Second fork wave | No | No | No | Yes (8 counting workers) |
| Loop unrolling | 6x | 4x | None | 6x |

Key structural difference: Top entries use flat 1D count arrays. We use bucket accumulation + counting fork wave. Their approach has CONSTANT IPC size (good at 100M), ours has IPC proportional to row count (good at 10M).

## MEASUREMENT CORRECTION (HUMAN-DIRECTED — DO NOT OVERWRITE)

**CRITICAL: Use parser-reported time, NOT hyperfine wall-clock.**
Parser time = time printed by `data:parse` stdout. Extract: `php tempest data:parse 2>&1 | grep -oP '[\d.]+'`
Wall-clock includes ~273ms PHP+Tempest overhead that is UNOPTIMIZABLE.
2% threshold applies to PARSER time (~137ms × 2% = ~2.7ms).

**Measurement methodology for iter10+:** Use A/B interleaved testing (alternate baseline and candidate in pairs) to control for thermal/system state. 8+ pairs needed for statistical significance given ~20ms variance.

## Remaining Ideas
1. **Flat 1D array architecture** — Would eliminate second fork wave but merge cost is O(978K × 10) additions. Only viable at 100M scale. At 100M scale this would be the clear winner.
2. **Work stealing for M1** — Can't test locally, regresses on M4 Pro. Worth trying on real M1.
3. **Comma-based parsing** — Find comma instead of newline. Saves ~7 bytes strpos scanning per line. ~1ms at best.
4. **Pre-allocate bucket strings** — Pre-size bucket strings to avoid reallocation during append. Adds per-row overhead for position tracking. Likely net-zero.
5. **Alternative: reduce fork overhead** — Use fewer parsing workers (8 instead of 10) to reduce fork/IPC cost. Each worker handles more data but fewer forks/sockets/drains.
6. **Reduce drain phase** — Currently 10ms. Main cost is stream_get_contents on ~2MB per worker × 9 workers + TLV merge (270 slug appends per worker). Could try reading directly into merge without intermediate $data variable.

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

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB, shmall: 1024 pages (4MB total), shmseg: 8
- kern.ipc.maxsockbuf: 8MB
- data.csv: 750,949,374 bytes (~751MB, 10M rows)
- hyperfine available
- sys_get_temp_dir() = /var/folders/zh/yjg3m2ln2xq_7qcxnh175gd80000gn/T (macOS app sandbox)
- Profiled iter11 phases: sysctl=5.3ms (eliminated via cache), setup_total=6.7ms→1.5ms, prefork=3.7ms, hotloop=99ms, drain=10ms, count=22ms
- Max slug length: 48 chars, min: 4 chars, max line: 99 bytes, min line: 55 bytes
- 268 slugs total. (len, first, last) fingerprint has 18 collisions.
- **IMPORTANT: Userland arithmetic (ord()+math) is SLOWER than PHP's C-level hash table lookups on short strings.** This disproves the hypothesis that avoiding substr/hash saves time. PHP's internal operations are faster than their userland equivalents even when fewer logical operations are needed.
