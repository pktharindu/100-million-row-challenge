<?php

namespace App;

\gc_disable();

final class Parser
{
    public static function parse(string $inputPath, string $outputPath): void
    {
        $numWorkers = 14;
        $chunkSize = 262144;

        // ── Slug discovery (identical to original) ──────────────────────
        $slugToIdx = [];
        $slugCount = 0;
        $fh = \fopen($inputPath, 'rb');
        $sample = \fread($fh, 524288);
        \fclose($fh);

        $sampleLen = \strlen($sample);
        $sPos = 0;
        while ($sPos + 29 < $sampleLen) {
            $c = \strpos($sample, ',', $sPos + 29);
            if ($c === false) break;
            $slug = \substr($sample, $sPos + 25, $c - $sPos - 25);
            if (!isset($slugToIdx[$slug])) {
                $slugToIdx[$slug] = $slugCount++;
            }
            $sPos = $c + 27;
        }
        $slugOrderList = \array_keys($slugToIdx);
        unset($sample);

        // ── Date ID table (identical to original) ───────────────────────
        $dateToId = [];
        $idToDate = [];
        $dateId = 0;
        $daysInMonth = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($year = 2021; $year <= 2026; $year++) {
            $isLeap = ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0));
            for ($month = 1; $month <= 12; $month++) {
                $days = $daysInMonth[$month];
                if ($month === 2 && $isLeap) $days = 29;
                for ($day = 1; $day <= $days; $day++) {
                    $yy = $year - 2000;
                    $dateStr8 = ($yy < 10 ? '0' : '') . $yy . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($day < 10 ? '0' : '') . $day;
                    $dateToId[$dateStr8] = \chr($dateId & 0xFF) . \chr($dateId >> 8);
                    $idToDate[$dateId] = \sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $dateId++;
                }
            }
        }

        $dateCount = $dateId; // total date IDs (2191 for 2021-2026)

        $dateJsonPrefix = [];
        foreach ($idToDate as $dId => $dateStr) {
            $dateJsonPrefix[$dId] = '        "' . $dateStr . '": ';
        }

        // ── Chunk boundaries (identical to original) ────────────────────
        $fileSize = \filesize($inputPath);
        $boundaries = [0];
        $fh = \fopen($inputPath, 'rb');
        for ($w = 1; $w < $numWorkers; $w++) {
            \fseek($fh, (int)($fileSize * $w / $numWorkers));
            \fgets($fh);
            $boundaries[] = \ftell($fh);
        }
        $boundaries[] = $fileSize;
        \fclose($fh);

        // ── Fork workers ────────────────────────────────────────────────
        $tmpDir = \sys_get_temp_dir();
        $pidToWorker = [];

        for ($w = 0; $w < $numWorkers; $w++) {
            $pid = \pcntl_fork();
            if ($pid === 0) {
                // ── Phase: Parse (identical hot loop) ───────────────────
                $buckets = \array_fill_keys($slugOrderList, '');
                $fh = \fopen($inputPath, 'rb');
                \stream_set_read_buffer($fh, 0);
                $start = $boundaries[$w];
                $end = $boundaries[$w + 1];
                \fseek($fh, $start);
                $remaining = $end - $start;

                do {
                    $toRead = $remaining > $chunkSize ? $chunkSize : $remaining;
                    $raw = \fread($fh, $toRead);
                    if ($raw === false || $raw === '') break;
                    $rawLen = \strlen($raw);
                    $remaining -= $rawLen;

                    $lastNl = \strrpos($raw, "\n");
                    if ($lastNl === false) break;

                    $tail = $rawLen - $lastNl - 1;
                    if ($tail > 0) {
                        \fseek($fh, -$tail, SEEK_CUR);
                        $remaining += $tail;
                    }

                    $i = 25;
                    $fence = $lastNl - 625;

                    if ($i < $fence) {
                        do {
                            $c = \strpos($raw, ',', $i);
                            $buckets[\substr($raw, $i, $c - $i)] .= $dateToId[\substr($raw, $c + 3, 8)];
                            $i = $c + 52;

                            $c = \strpos($raw, ',', $i);
                            $buckets[\substr($raw, $i, $c - $i)] .= $dateToId[\substr($raw, $c + 3, 8)];
                            $i = $c + 52;

                            $c = \strpos($raw, ',', $i);
                            $buckets[\substr($raw, $i, $c - $i)] .= $dateToId[\substr($raw, $c + 3, 8)];
                            $i = $c + 52;

                            $c = \strpos($raw, ',', $i);
                            $buckets[\substr($raw, $i, $c - $i)] .= $dateToId[\substr($raw, $c + 3, 8)];
                            $i = $c + 52;

                            $c = \strpos($raw, ',', $i);
                            $buckets[\substr($raw, $i, $c - $i)] .= $dateToId[\substr($raw, $c + 3, 8)];
                            $i = $c + 52;
                        } while ($i < $fence);
                    }

                    if ($i < $lastNl) {
                        do {
                            $c = \strpos($raw, ',', $i);
                            if ($c === false || $c > $lastNl) break;
                            $buckets[\substr($raw, $i, $c - $i)] .= $dateToId[\substr($raw, $c + 3, 8)];
                            $i = $c + 52;
                        } while ($i < $lastNl);
                    }
                } while ($remaining > 0);

                \fclose($fh);

                // ── Count phase (done inside same worker) ───────────────
                // Build a flat integer array: flatCounts[slugIdx * dateCount + dateId] = count
                $totalSlots = $slugCount * $dateCount;
                $flatCounts = \array_fill(0, $totalSlots, 0);

                foreach ($buckets as $slug => $packed) {
                    if ($packed === '') continue;
                    $base = $slugToIdx[$slug] * $dateCount;
                    $counts = \array_count_values(\unpack('v*', $packed));
                    foreach ($counts as $dId => $cnt) {
                        $flatCounts[$base + $dId] = $cnt;
                    }
                }
                unset($buckets);

                // Write packed uint32 array to temp file
                \file_put_contents($tmpDir . '/parser_w' . $w, \pack('V*', ...$flatCounts));
                \posix_kill(\posix_getpid(), 9);
            }
            $pidToWorker[$pid] = $w;
        }

        // ── Parent: drain workers and merge counts ──────────────────────
        $totalSlots = $slugCount * $dateCount;
        $merged = \array_fill(0, $totalSlots, 0);
        $drained = 0;

        do {
            $pid = \pcntl_waitpid(-1, $status);
            if ($pid <= 0) {
                continue;
            }

            $w = $pidToWorker[$pid];
            $tmpFile = $tmpDir . '/parser_w' . $w;
            $data = \file_get_contents($tmpFile);
            \unlink($tmpFile);

            // unpack('V*', ...) returns 1-indexed array
            $wCounts = \unpack('V*', $data);
            unset($data);

            // Element-wise addition (unpack is 1-indexed, merged is 0-indexed)
            $idx = 0;
            foreach ($wCounts as $val) {
                if ($val !== 0) {
                    $merged[$idx] += $val;
                }
                $idx++;
            }
            unset($wCounts);

            $drained++;
        } while ($drained < $numWorkers);

        // ── Parent: generate JSON serially ──────────────────────────────
        $slugJsonHeaders = [];
        foreach ($slugOrderList as $slug) {
            $slugJsonHeaders[$slug] = '    "\/blog\/' . $slug . '": {' . "\n";
        }

        $fhOut = \fopen($outputPath, 'wb');
        \fwrite($fhOut, "{\n");
        $needSep = false;

        for ($s = 0; $s < $slugCount; $s++) {
            $slug = $slugOrderList[$s];
            $base = $s * $dateCount;

            // Collect non-zero counts for this slug, keyed by dateId
            $counts = [];
            for ($d = 0; $d < $dateCount; $d++) {
                $val = $merged[$base + $d];
                if ($val !== 0) {
                    $counts[$d] = $val;
                }
            }

            if (empty($counts)) continue;

            // ksort by dateId (dateIds are already in chronological order,
            // and we iterate 0..$dateCount-1, so they are already sorted.
            // But ksort to match original behavior exactly.)
            \ksort($counts);

            $dateParts = [];
            foreach ($counts as $dId => $count) {
                $dateParts[] = $dateJsonPrefix[$dId] . $count;
            }

            if ($needSep) \fwrite($fhOut, ",\n");
            \fwrite($fhOut, $slugJsonHeaders[$slug] . \implode(",\n", $dateParts) . "\n    }");
            $needSep = true;
        }

        \fwrite($fhOut, "\n}");
        \fclose($fhOut);
    }
}
