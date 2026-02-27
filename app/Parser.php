<?php

namespace App;

\gc_disable();

function _hotLoop(
    string $inputPath, int $start, int $end,
    array $dateToId, array $slugOrderList, int $chunkSize
): array {
    // Pre-initialize ALL buckets — no undefined key notices, no error handler needed
    $buckets = \array_fill_keys($slugOrderList, '');

    $fh = \fopen($inputPath, 'rb');
    \fseek($fh, $start);
    $remaining = $end - $start;
    $leftover = '';

    while ($remaining > 0) {
        $toRead = \min($chunkSize, $remaining);
        $raw = \fread($fh, $toRead);
        if ($raw === false || $raw === '') break;
        $remaining -= \strlen($raw);

        $chunk = $leftover . $raw;
        $lastNl = \strrpos($chunk, "\n");
        if ($lastNl === false) {
            $leftover = $chunk;
            continue;
        }
        $leftover = \substr($chunk, $lastNl + 1);

        $pos = 0;
        $fence = $lastNl - 720;

        // 6x unrolled
        while ($pos < $fence) {
            $nl = \strpos($chunk, "\n", $pos + 52);
            $buckets[\substr($chunk, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($chunk, $nl - 25, 10)];
            $pos = $nl + 1;

            $nl = \strpos($chunk, "\n", $pos + 52);
            $buckets[\substr($chunk, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($chunk, $nl - 25, 10)];
            $pos = $nl + 1;

            $nl = \strpos($chunk, "\n", $pos + 52);
            $buckets[\substr($chunk, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($chunk, $nl - 25, 10)];
            $pos = $nl + 1;

            $nl = \strpos($chunk, "\n", $pos + 52);
            $buckets[\substr($chunk, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($chunk, $nl - 25, 10)];
            $pos = $nl + 1;

            $nl = \strpos($chunk, "\n", $pos + 52);
            $buckets[\substr($chunk, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($chunk, $nl - 25, 10)];
            $pos = $nl + 1;

            $nl = \strpos($chunk, "\n", $pos + 52);
            $buckets[\substr($chunk, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($chunk, $nl - 25, 10)];
            $pos = $nl + 1;
        }

        // Cleanup remainder
        while ($pos < $lastNl) {
            $nl = \strpos($chunk, "\n", $pos + 52);
            if ($nl === false || $nl > $lastNl) break;
            $buckets[\substr($chunk, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($chunk, $nl - 25, 10)];
            $pos = $nl + 1;
        }
    }
    \fclose($fh);
    return $buckets;
}

function _processSegmentFile(
    string $inputPath, int $start, int $end,
    array $dateToId, array $slugOrderList,
    string $tmpFile, int $chunkSize
): void {
    $buckets = _hotLoop($inputPath, $start, $end, $dateToId, $slugOrderList, $chunkSize);

    // Build slug index for compact IPC
    $slugToIdx = \array_flip($slugOrderList);

    // Serialize: send raw bucket strings (parent will do batch counting)
    $out = '';
    foreach ($buckets as $slug => $packed) {
        if ($packed === '') continue;
        $idx = $slugToIdx[$slug] ?? 0xFFFF;
        $out .= \pack('vV', $idx, \strlen($packed)) . $packed;
    }
    \file_put_contents($tmpFile, $out);
}

final class Parser
{
    public function parse(string $inputPath, string $outputPath): void
    {
        \gc_disable();

        $numWorkers = 10;
        $chunkSize  = 2097152; // 2 MB

        // Discover slugs from first 4 MB
        $slugOrder = [];
        $fh = \fopen($inputPath, 'rb');
        $sample = \fread($fh, 4194304);
        \fclose($fh);

        $sampleLen = \strlen($sample);
        $sPos = 0;
        while ($sPos + 52 < $sampleLen) {
            $nl = \strpos($sample, "\n", $sPos + 52);
            if ($nl === false) break;
            $slug = \substr($sample, $sPos + 25, $nl - $sPos - 51);
            if (!isset($slugOrder[$slug])) {
                $slugOrder[$slug] = true;
            }
            $sPos = $nl + 1;
        }
        $slugOrderList = \array_keys($slugOrder);
        unset($sample, $slugOrder);

        // Pre-enumerate dates (2019–2028, leap year aware)
        $dateToId = [];
        $idToDate = [];
        $dateId = 0;
        $daysInMonth = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($year = 2019; $year <= 2028; $year++) {
            $isLeap = ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0));
            for ($month = 1; $month <= 12; $month++) {
                $days = $daysInMonth[$month];
                if ($month === 2 && $isLeap) $days = 29;
                for ($day = 1; $day <= $days; $day++) {
                    $dateStr = \sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $dateToId[$dateStr] = \chr($dateId & 0xFF) . \chr($dateId >> 8);
                    $idToDate[$dateId] = $dateStr;
                    $dateId++;
                }
            }
        }

        // Partition file into equal segments aligned on line boundaries
        $fileSize = \filesize($inputPath);
        $segSize = (int)($fileSize / $numWorkers);
        $boundaries = [0];
        $fh = \fopen($inputPath, 'rb');
        for ($w = 1; $w < $numWorkers; $w++) {
            \fseek($fh, $segSize * $w);
            \fgets($fh);
            $boundaries[] = \ftell($fh);
        }
        $boundaries[] = $fileSize;
        \fclose($fh);

        // Fork workers with temp file IPC
        $tmpDir = \sys_get_temp_dir();
        $parentPid = \getmypid();
        $childPids = [];

        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $pid = \pcntl_fork();
            if ($pid === 0) {
                // CHILD: process segment, write to temp file, exit
                _processSegmentFile(
                    $inputPath, $boundaries[$w], $boundaries[$w + 1],
                    $dateToId, $slugOrderList,
                    $tmpDir . '/p_' . $parentPid . '_' . $w,
                    $chunkSize
                );
                exit(0);
            }
            $childPids[] = $pid;
        }

        // Parent processes last segment
        $parentBuckets = _hotLoop(
            $inputPath, $boundaries[$numWorkers - 1], $boundaries[$numWorkers],
            $dateToId, $slugOrderList, $chunkSize
        );

        // Wait for ALL children to finish
        foreach ($childPids as $pid) {
            \pcntl_waitpid($pid, $status);
        }

        // Merge: concatenate raw bucket strings from all sources, then batch count
        // Start with parent's buckets
        $mergedBuckets = $parentBuckets;
        unset($parentBuckets);

        // Read child temp files and concatenate bucket strings
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $tmpFile = $tmpDir . '/p_' . $parentPid . '_' . $w;
            $data = \file_get_contents($tmpFile);
            \unlink($tmpFile);
            $offset = 0;
            $dataLen = \strlen($data);
            while ($offset < $dataLen) {
                ['idx' => $slugIdx, 'len' => $bucketLen] = \unpack('vidx/Vlen', $data, $offset);
                $offset += 6;
                $slug = $slugOrderList[$slugIdx];
                $mergedBuckets[$slug] .= \substr($data, $offset, $bucketLen);
                $offset += $bucketLen;
            }
        }

        // Batch count: one unpack + array_count_values per slug (C-level speed)
        $merged = [];
        foreach ($mergedBuckets as $slug => $packed) {
            if ($packed === '') continue;
            $merged[$slug] = \array_count_values(\unpack('v*', $packed));
        }
        unset($mergedBuckets);

        // JSON output — sort by dateId (chronological integer order), build string
        $json = "{\n";
        $firstSlug = true;
        foreach ($slugOrderList as $slug) {
            if (!isset($merged[$slug])) continue;
            if (!$firstSlug) $json .= ",\n";
            $firstSlug = false;

            \ksort($merged[$slug]); // sort by dateId integers (chronological)

            $json .= '    ' . \json_encode('/blog/' . $slug) . ": {\n";
            $firstDate = true;
            foreach ($merged[$slug] as $dId => $cnt) {
                if (!$firstDate) $json .= ",\n";
                $firstDate = false;
                $json .= '        "' . $idToDate[$dId] . '": ' . $cnt;
            }
            $json .= "\n    }";
        }
        $json .= "\n}";
        \file_put_contents($outputPath, $json);
    }
}
