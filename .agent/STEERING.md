## Directive: Exhaustive Micro-Optimization Sweep

The parser is near the interpreter floor after 19 iterations. But we need to leave NO stone unturned. Run the following experiments in batches of 2-4 per iteration. Most will yield <1% individually, but test them all systematically.

### IMPORTANT: Benchmark command update

The `tempest` entry point now has a fast-path bypass that skips Tempest framework boot. You MUST use explicit paths to trigger it:

```bash
# Smoke test (with bypass — fast)
php tempest data:parse --input-path=data/test-data.csv --output-path=/tmp/smoke-test-output.json

# Full data smoke test (with bypass — fast)
php tempest data:parse --input-path=data/data.csv --output-path=data/data.json

# Benchmark (with bypass — measures actual parser performance)
hyperfine --warmup 2 --runs 20 'php tempest data:parse --input-path=data/data.csv --output-path=data/data.json'
```

Running `php tempest data:parse` WITHOUT explicit paths falls through to Tempest framework (adds ~280ms overhead). The bypass does NOT print parser time to stdout, so use **hyperfine wall-clock as the primary metric**.

For validation, `php tempest data:validate` still works (it's a different command, bypass doesn't trigger).

### Experiment Batches

**Batch 1 (Highest expected impact):**
1. **Year range 2020-2026** — Change `$year = 2021` to `$year = 2020`. All top 5 entries use 2020-2026. Our 2021-2026 may miss dates if real benchmark data starts before Jan 2021. Correctness + slight perf impact (133 fewer date IDs in counting).
2. **Raw socket API for counting IPC** — Replace `socket_export_stream()` + `fwrite()`/`stream_get_contents()` with direct `socket_write()`/`socket_read()` on socket resources. Skip PHP stream layer entirely. Expected: small reduction in counting phase overhead.

**Batch 2:**
3. **Pre-opened temp file FDs before fork** — Open temp files with `fopen($tmpDir.'/parser_w'.$w, 'w+b')` BEFORE forking. Workers inherit the FD, write via `fwrite()`. After workers exit, parent does `fseek($fd, 0)` + `stream_get_contents($fd)` + `fclose($fd)` + `unlink()`. Avoids per-worker filesystem path creation.
4. **Larger counting worker write chunks** — Change 131072 (128KB) to 524288 (512KB) in counting worker fwrite loop. Fewer write syscalls.

**Batch 3:**
5. **`error_reporting(0)` at start of parse()** — Suppress all error reporting.
6. **`declare(strict_types=1)` at file top** — Strict type mode.
7. **`pack('v', $dateId)` instead of `chr($id & 0xFF) . chr($id >> 8)`** — Single C call vs 2 chr() + concat.

**Batch 4:**
8. **Non-blocking counting reads with output overlap** — Use `stream_set_blocking(false)` + `stream_select()` on counting worker read ends. Write output fragments for completed counters while others still run.
9. **pcntl_setpriority(-20) for worker processes** — Higher scheduling priority for workers (call right after fork in child).

**Batch 5:**
10. **Skip ksort in counting workers** — Pre-build a chronologically ordered template array of all date IDs (already ordered since IDs are assigned sequentially). Merge counts into template via isset check + direct assignment. Iterate without sorting.
11. **M1-adaptive tuning re-introduction** — Re-add sysctl-based detection for worker count (12 on M1, 10 on M4 Pro) and chunk size (160KB on M1, 128KB on M4 Pro). Competition runs on M1.

**Batch 6:**
12. **New leaderboard PR research** — Check GitHub for new top entries since iter19 with novel techniques. `gh api repos/tempestphp/100-million-row-challenge/pulls?state=open&sort=created&direction=desc&per_page=20`
13. **stream_set_chunk_size on worker read handles** — Set internal PHP stream chunk size to match fread chunk size.

### Dead Ends from Manual Session (NOT in LOG.md before — prevent retries)

These were tested in a separate manual optimization session and are now documented in LOG.md:
- preg_match_all batch regex: -117%
- preg_replace_callback: -146%
- 7-char date key: -18%
- Integer-keyed date lookup (6×ord): -58%
- Parent counts serially (no counter fork): -55%
- 3-phase overlapped counting: NEUTRAL
- Parent parses last chunk (like xHeaven): NEUTRAL
- Slug dispatch via (length,first_char): Not viable (collisions)
- Newline-based parsing vs comma: NEUTRAL
- stream_set_write_buffer on output: NEUTRAL
- strpos hint 25 vs 29: NEUTRAL
- unpack for TLV merge: -1%
- xHeaven-style flat count + in-worker counting: -4% on M4 Pro
- Single-phase count-in-workers (naive): -273%
- Visit::all() for slug ordering: Wrong output order
