# Parser Optimization Knowledge Base

> This file is the agent's persistent memory across Ralph iterations.
> Structure will evolve as patterns emerge. Start simple, refactor as needed.

---

## Current State

- **Best time:** ~0.374s (10M rows, unverified baseline — needs proper benchmarking)
- **Iteration count:** 0
- **Parser architecture:** Multi-process fork (8 workers), comma-based parsing with 4x loop unrolling, file-based IPC via pack/unpack, flat count array

## Bottleneck Model

_Not yet profiled. First iteration should instrument and measure phase timings._

Expected phases:
1. Setup (date precomputation, slug discovery) — likely negligible
2. File splitting — negligible
3. Parallel chunk processing (the hot loop) — likely dominant
4. IPC / merge — unknown, could be significant
5. JSON output — likely small but non-trivial

## Technique Status

| Technique | Status | Notes |
|-----------|--------|-------|
| Bucket accumulation (T1) | UNTESTED | Top 5 all use this. Highest priority. |
| Worker count tuning (T2) | UNTESTED | Currently 8. Top entries use 10-12. |
| 6x loop unrolling (T3) | UNTESTED | Currently 4x. |
| Adaptive IPC encoding (T5) | UNTESTED | 2-byte vs 4-byte packing. |
| Unix socket IPC (T6) | UNTESTED | Eliminates temp file I/O. |
| Work stealing (T7) | UNTESTED | Handles M1 heterogeneous cores. |
| Newline skip (T8) | UNTESTED | Minor but free. |
| Fully-qualified calls (T9) | PARTIAL | Some `use function` already present. |

## Dead Ends

_None yet._

## Promising Leads

1. **Bucket accumulation** — single biggest architectural change. All top-5 use it.
2. **Worker count + work stealing** — M1's heterogeneous cores need smart scheduling.
3. **Socket IPC** — eliminates filesystem round-trip for child results.

## Performance Timeline

| Iteration | Best Time | Change | Delta |
|-----------|-----------|--------|-------|
| 0 (baseline) | ~0.374s | Initial state | — |

---
