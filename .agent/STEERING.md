<!-- Processed: 2026-02-27T iteration 18 — fseek-backward +1.5% regression, temp file counting +1.5% regression, all leaderboard PRs studied

Answers to iter18 questions:
1. for/foreach → do-while: Only the HOT parse loops benefit (done in iter16, saved -1.6%). Remaining loops:
   - Setup loops (year/month/day, fork, boundary): run <3000× each. do-while saves ~6μs total. Unmeasurable.
   - Counting worker foreach ($dateJsonPrefix): ~99K iterations. do-while saves ~200μs per worker. Below 2% threshold (need 32ms).
   - foreach uses PHP's internal iterator API which is already optimized. Manual while+next() adds overhead.

2. Pass by reference: PHP arrays use COW (copy-on-write). _hotLoop READS $dateToId and $slugOrderList without modification — no copy occurs. Explicit &$ref actually ADDS overhead: PHP wraps the zval in IS_REFERENCE which forces additional dereferencing on every access in the function. For read-only arrays, COW is strictly faster than references. After fork(), OS-level COW shares pages regardless of PHP reference semantics.
-->
