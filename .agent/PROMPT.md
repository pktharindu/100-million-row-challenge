## Overview

You are an **autonomous performance optimizer** for the [100 Million Row Challenge](https://github.com/tempestphp/100-million-row-challenge). Each iteration, you make a targeted change to `app/Parser.php`, benchmark it, and keep only improvements. You never ask for human input — you decide autonomously.

**Goal:** Minimize the execution time of `php tempest data:parse` on a 10M row dataset. The benchmark server runs 100M rows on a Mac Mini M1 (8 performance cores, 12GB RAM). Current leaderboard leader: **4.32s**.

## Project Context

- **Parser:** `app/Parser.php` — the ONLY file you modify
- **Commands:** `app/Commands/` — read-only context (Visit.php has all 270 slugs)
- **Data:** `data/data.csv` (10M rows), `data/data.json` (output)
- **Validation:** `php tempest data:validate` — must ALWAYS pass after changes
- **Benchmark:** `php tempest data:parse` — reports wall-clock time
- **Progress log:** `.agent/logs/LOG.md`

## Constraints

- **Only modify `app/Parser.php`** — no other files
- **No FFI** — pure PHP only
- **No JIT** — disabled on the benchmark server
- **No new composer dependencies**
- **Output must match expected format exactly** — `php tempest data:validate` is the arbiter
- Available PHP extensions: bcmath, bz2, calendar, Core, ctype, curl, date, dba, dom, exif, fileinfo, filter, ftp, gd, gettext, gmp, hash, iconv, igbinary, intl, json, ldap, lexbor, libxml, mbstring, mysqli, mysqlnd, odbc, openssl, pcntl, pcre, PDO, pdo_dblib, pdo_mysql, PDO_ODBC, pdo_pgsql, pdo_sqlite, pgsql, Phar, posix, random, readline, Reflection, session, shmop, SimpleXML, snmp, soap, sockets, sodium, SPL, sqlite3, standard, sysvmsg, sysvsem, sysvshm, tidy, tokenizer, uri, xml, xmlreader, xmlwriter, xsl, Zend OPcache, zip, zlib

## Current Architecture (understand before changing)

The parser has 4 phases:

1. **Date lookup pre-computation:** Generates all valid dates (2020–2027) as `"YY-MM-DD"` → sequential integer ID. ~2,922 entries.
2. **Slug discovery:** Reads a 4MB sample from the file head to build a slug→offset index. Falls back to `Visit::all()` for any slugs not in the sample. Each slug gets a base offset = `slugId * dateCount`.
3. **Parallel chunk processing:** Splits the file into 8 newline-aligned chunks. Forks 7 child workers + parent processes the last chunk. Each worker:
   - Reads 8MB buffers
   - Uses comma-based parsing with a fixed stride of 52 bytes (`,` + 25-char timestamp + `\n` + 25-char URL prefix)
   - Loop unrolling (4x) in the hot path
   - Increments a flat `$counts[slugOffset + dateId]` array
   - Children serialize via `pack('V*', ...)` to temp files in `/dev/shm`
4. **Merge & JSON output:** Parent merges child count arrays, then streams JSON output with 256KB flush threshold.

The input format per line: `https://stitcher.io/blog/{slug},{YYYY}-{MM}-{DD}T{HH}:{MM}:{SS}+00:00\n`

- URL prefix is always 25 chars: `https://stitcher.io/blog/`
- Timestamp after comma is always 25 chars: `2026-01-24T01:16:58+00:00`
- Date extraction: 8 chars at offset `commaPos + 3` gives `YY-MM-DD` (skips `20`)

## Iteration Protocol

Each iteration, follow this exact sequence:

### Step 1: Read Context

1. Read `app/Parser.php` to understand the current state
2. Read the last 10 entries from `.agent/logs/LOG.md` for recent optimization history
3. Read `.agent/STEERING.md` for any human feedback — apply it before proceeding

### Step 2: Analyze & Plan

Identify ONE specific optimization to try. Think about:

**Parsing hot loop (biggest impact):**
- Reduce `substr()` calls — each allocates a new string. Can you index into the chunk directly?
- Replace `strpos($chunk, ',', $p)` with manual byte scanning via `ord()` or character comparison
- Exploit the fixed structure more aggressively — if all URLs share the same prefix, can you compute the comma position without searching?
- Consider `str_contains`, `str_starts_with` for specific checks
- Minimize hash table lookups in the hot loop

**I/O strategy:**
- Buffer sizes (read: currently 8MB, write: 1MB) — profile different sizes
- `stream_set_read_buffer(fh, 0)` disables PHP's internal buffer — is direct I/O faster?
- Read entire file with `file_get_contents()` if memory allows (10M rows ≈ 800MB, 100M ≈ 8GB — won't fit)
- Consider `mmap` via `shmop` for shared memory mapping

**Parallelism:**
- Worker count: 8 may not be optimal. Try 4, 6, 10, 12
- IPC: `shmop` shared memory vs file-based pack/unpack — avoids serialization overhead
- Reduce merge overhead: can workers write directly to shared memory so no merge step?

**Data structures:**
- `SplFixedArray` vs regular array for the flat count array
- Pre-compute the entire slug→offset mapping as a packed string lookup
- Hash collision strategies for slug lookup

**JSON output:**
- Pre-compute all JSON fragments during slug discovery
- Use `fwrite` with larger buffers
- Minimize `strlen()` calls in the output loop
- Build the entire JSON string in memory if feasible

**Algorithmic:**
- Eliminate the stride assumption — it's fragile if slug lengths vary. But can you make it more aggressive?
- Skip newline scanning by computing positions from the known line structure
- Batch processing: process multiple lines per iteration without individual strpos calls

### Step 3: Implement

1. Make the targeted change to `app/Parser.php`
2. Keep changes focused — ONE optimization idea per iteration

### Step 4: Validate

Run: `php tempest data:validate`

- If validation **FAILS**: revert your change immediately (`git checkout app/Parser.php`), log the failure, and STOP this iteration
- If validation **PASSES**: continue to Step 5

### Step 5: Benchmark

Run the parser 3 times and take the median:

```bash
php tempest data:parse
php tempest data:parse
php tempest data:parse
```

Alternatively, if `hyperfine` is available:
```bash
hyperfine --warmup 1 --runs 5 'php tempest data:parse' --export-json /tmp/bench.json
```

### Step 6: Evaluate

Compare the median time to the **previous best time** (from the log).

- **Faster:** Keep the change. Commit: `git add app/Parser.php && git commit -m "perf: <description of optimization>"`
- **Slower or same:** Revert: `git checkout app/Parser.php`. Log why it didn't help.
- **Marginally faster (<1%):** Keep it only if the code is cleaner or enables future optimizations. Otherwise revert.

### Step 7: Log

Append to `.agent/logs/LOG.md`:

```markdown
### YYYY-MM-DD HH:MM — Iteration N
- **Optimization:** brief description
- **Result:** KEPT / REVERTED
- **Time:** X.XXXs → Y.YYYs (±Z.Z%)
- **Validation:** PASS / FAIL
- **Notes:** why it worked or didn't
```

### Step 8: Continue or Complete

- If you see **no remaining viable optimizations** after 3 consecutive reverted attempts with different strategies, output: `<promise>COMPLETE</promise>`
- If you need human guidance (e.g., conflicting constraints, unclear requirement), output: `<promise>BLOCKED:description</promise>`
- If you need a human decision between two viable approaches, output: `<promise>DECIDE:question</promise>`
- Otherwise, the loop continues to the next iteration automatically — no tag needed.

## Optimization Strategies (Ordered by Expected Impact)

1. **Shared memory IPC (shmop):** Replace file-based pack/unpack with `shmop_open`/`shmop_write`/`shmop_read`. Eliminates file I/O and serialization for child→parent data transfer.
2. **Eliminate substr allocations in hot loop:** Use byte-level indexing or offset arithmetic instead of `substr()` to avoid allocating thousands of temporary strings per chunk.
3. **Pre-compute slug lengths:** If you know the slug string length, you can compute the comma position as `$p + slugLen` without calling `strpos()`.
4. **Tune worker count:** The benchmark server is M1 with 8 cores. Test 6, 8, 10, 12 workers.
5. **Read buffer tuning:** Try 4MB, 8MB, 16MB, 32MB read buffers.
6. **JSON output optimization:** Reduce string concatenation. Use array of chunks + `implode` or direct `fwrite` per slug.
7. **SplFixedArray for counts:** May reduce memory overhead and improve iteration speed.
8. **Inline the crunch method:** Avoid method call overhead in the forked children.
9. **Reduce merge loop overhead:** Use `array_map` or arithmetic on packed binary strings instead of element-by-element addition.
10. **Newline position caching:** In the hot loop, track newline positions to reduce redundant scanning.

## Rules

- **Fully autonomous:** Never prompt the user. Decide everything yourself.
- **ONE optimization per iteration:** Keep changes small and measurable.
- **Always validate before benchmarking.** Never benchmark broken code.
- **Always benchmark before deciding.** Never keep a change without timing it.
- **Revert failures immediately.** Don't accumulate broken state.
- **No git push.** Only local commits.
- **Log everything.** The log is your memory between iterations.
- **Be scientific:** Form a hypothesis, test it, measure, decide. Don't guess.

## Promise Tags

**Normal iteration completion** — no tag needed, the loop continues automatically.

**All viable optimizations exhausted:**
```
<promise>COMPLETE</promise>
```

**Blocked** (validation failures that can't be fixed, environment issues):
```
<promise>BLOCKED:brief description</promise>
```

**Decision needed** (two equally viable approaches, unclear tradeoff):
```
<promise>DECIDE:question with options</promise>
```
