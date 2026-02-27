# Parser Optimization Knowledge Base

## Current State
- **Best time:** 0.885s (10M rows, M4 Pro 14-core)
- **Iteration count:** 1
- **Parser architecture:** 10 workers via pcntl_fork, socket pair IPC, 6x loop unrolling, bucket accumulation with 2-byte date IDs, 2MB read chunks
- **Target:** ~0.350-0.450s (top leaderboard entries scale to ~0.43-0.46s on 10M)

## Bottleneck Model
Phase breakdown (estimated from User/System times):
- User time: 1970ms across 10 workers → ~197ms per worker in hot loop
- System time: 437ms → fork/IPC/filesystem overhead
- Wall clock: 885ms → merge + JSON output consumes ~200ms

**Primary bottleneck:** Unclear without profiling. Candidates:
1. Hot loop efficiency (parsing overhead per line)
2. Fork + IPC overhead (socket setup, data transfer)
3. Merge phase (sequential deserialization)
4. JSON output (building the string, ksort)

**Next action:** Profile with microtime instrumentation to identify dominant phase.

## Technique Status

| Technique | Status | Notes |
|-----------|--------|-------|
| Multi-process fork | DONE | 10 workers on M4 Pro (14 CPUs, 10 perf cores) |
| Bucket accumulation (T1) | DONE | Per-slug string append + unpack('v*') + array_count_values |
| 6x loop unrolling (T3) | DONE | Fence at $lastNl - 720 |
| Position-based parsing (T4) | DONE | substr with hardcoded offsets, strpos with +52 skip |
| Socket pair IPC (T6) | DONE | stream_socket_pair, sequential read |
| Fully-qualified calls (T9) | DONE | \ prefix on all global functions |
| gc_disable | DONE | At start of parse() |
| Worker count tuning (T2) | PARTIAL | Tested 10 vs 12. 10 was faster on M4 Pro. Try 8, 14. |
| Adaptive IPC encoding (T5) | UNTESTED | Currently 4-byte pack. Could use 2-byte for counts < 65535 |
| Work stealing (T7) | UNTESTED | Could help with heterogeneous cores |
| Newline skip (T8) | DONE | strpos offset +52 |

## Experiment Results

### Iteration 1: Architecture overhaul (3.906s → 0.885s)
| Candidate | Workers | IPC | Chunk Size | Time |
|---|---|---|---|---|
| A (temp file) | 10 | temp files | 256KB | 914.5ms |
| B (temp file) | 12 | temp files | 160KB | 1024.0ms |
| C (socket) | 10 | sockets | 2MB | **885.0ms** |

**Key findings:**
- Socket IPC beats temp file IPC by ~3% (885 vs 914ms)
- 10 workers beats 12 workers on M4 Pro (914 vs 1024ms)
- 2MB chunks beat 256KB chunks (part of socket win, needs isolation)
- `set_error_handler(fn() => true)` needed to suppress Tempest's ErrorException handler
- Discovery phase `strpos($s, "\n", $pos + 52)` needs bounds check when $pos + 52 >= strlen

## Dead Ends
- 12 workers slower than 10 on M4 Pro (12 workers: 1024ms vs 10: 885ms)
- `error_reporting(0)` does NOT suppress Tempest's error handler → must use `set_error_handler`

## Promising Leads for Next Iteration

### High Priority
1. **Pre-initialize buckets** — avoid set_error_handler overhead. Pass discovered slugs to hot loop, do `$buckets = array_fill_keys($slugs, '')`. Eliminates notice overhead entirely.
2. **Profile phase timings** — instrument with microtime to find where 885ms goes.
3. **Chunk size tuning** — test 1MB, 2MB, 4MB, 8MB on Candidate C architecture.
4. **Worker count fine-tuning** — test 8 and 14 workers.

### Medium Priority
5. **Concurrent socket drain** — use stream_select to read from child sockets as they finish, overlapping with slower workers.
6. **2-byte IPC encoding** — pack('v*') instead of pack('V*') for counts (always < 65535). Halves IPC data.
7. **String-based JSON output** — use buffered write instead of building entire JSON in memory.
8. **sprintf elimination** — candidate B used sprintf for dates, candidate A/C use string concat. Verify no sprintf in hot path.

### Low Priority
9. **Work stealing** — create more segments than workers, assign dynamically via flock.
10. **Eliminate ksort** — pre-sorted date IDs mean we can iterate in ID order.

## Performance Timeline

| Iteration | Best Time | Change | Delta |
|-----------|-----------|--------|-------|
| 0 (baseline) | 3.906s | Naive single-process | — |
| 1 | 0.885s | Multi-process + bucket accumulation + socket IPC | -77.3% |

## Environment
- PHP 8.5.2 (NTS clang 15.0.0)
- macOS, M4 Pro, 14 logical CPUs (10 perf + 4 efficiency)
- shmmax: 4MB (too small for shmop IPC)
- data.csv: 750,949,374 bytes (~751MB, 10M rows)
- hyperfine available
