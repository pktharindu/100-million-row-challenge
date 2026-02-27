<?php

namespace App;

\error_reporting(0);
\gc_disable();

final class Parser
{
    public function parse(string $inputPath, string $outputPath): void
    {
        \error_reporting(0);
        \gc_disable();

        $numWorkers = 10;
        $chunkSize  = 2097152; // 2 MB

        // ------------------------------------------------------------------ //
        // Phase 2: Discover slugs from first 4 MB, preserve first-seen order //
        // ------------------------------------------------------------------ //
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

        // ------------------------------------------------------------------ //
        // Phase 3: Pre-enumerate dates 2019-2028 as 2-byte little-endian IDs //
        // ------------------------------------------------------------------ //
        $dateToId = [];
        $idToDate = [];
        $dateId   = 0;

        $daysInMonth = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($year = 2019; $year <= 2028; $year++) {
            $isLeap = ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0));
            for ($month = 1; $month <= 12; $month++) {
                $days = $daysInMonth[$month];
                if ($month === 2 && $isLeap) {
                    $days = 29;
                }
                for ($day = 1; $day <= $days; $day++) {
                    $dateStr = \sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $packed  = \chr($dateId & 0xFF) . \chr($dateId >> 8);
                    $dateToId[$dateStr] = $packed;
                    $idToDate[$dateId]  = $dateStr;
                    $dateId++;
                }
            }
        }

        // ------------------------------------------------------------------ //
        // Phase 4: Partition file into N segments aligned to newlines         //
        // ------------------------------------------------------------------ //
        $fileSize  = \filesize($inputPath);
        $segSize   = (int)($fileSize / $numWorkers);
        $boundaries = [0];

        $fh = \fopen($inputPath, 'rb');
        for ($w = 1; $w < $numWorkers; $w++) {
            $pos = $segSize * $w;
            \fseek($fh, $pos);
            // Advance to next newline boundary
            \fgets($fh);
            $boundaries[] = \ftell($fh);
        }
        $boundaries[] = $fileSize;
        \fclose($fh);

        // ------------------------------------------------------------------ //
        // Phase 4b: Create socket pairs BEFORE forking                       //
        // ------------------------------------------------------------------ //
        $socketPairs = [];
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                // Fallback: try with STREAM_IPPROTO_TCP
                $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            }
            $socketPairs[$w] = $pair;
        }

        // ------------------------------------------------------------------ //
        // Phase 5: Fork workers                                               //
        // ------------------------------------------------------------------ //
        $childPids = [];
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $pid = \pcntl_fork();
            if ($pid === 0) {
                // CHILD: close parent's end of ALL socket pairs
                for ($j = 0; $j < $numWorkers - 1; $j++) {
                    \fclose($socketPairs[$j][0]);
                    if ($j !== $w) {
                        \fclose($socketPairs[$j][1]);
                    }
                }
                _processSegmentSocket(
                    $inputPath,
                    $boundaries[$w],
                    $boundaries[$w + 1],
                    $dateToId,
                    $socketPairs[$w][1],
                    $chunkSize
                );
                \fclose($socketPairs[$w][1]);
                exit(0);
            }
            $childPids[] = $pid;
        }

        // PARENT: close child's end of all socket pairs
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            \fclose($socketPairs[$w][1]);
        }

        // ------------------------------------------------------------------ //
        // Phase 6+7: Parent processes its own segment (last segment)         //
        // ------------------------------------------------------------------ //
        $parentBuckets = _hotLoop(
            $inputPath,
            $boundaries[$numWorkers - 1],
            $boundaries[$numWorkers],
            $dateToId,
            $chunkSize
        );

        // ------------------------------------------------------------------ //
        // Phase 8: Merge parent results                                       //
        // ------------------------------------------------------------------ //
        $merged = [];
        foreach ($parentBuckets as $slug => $packed) {
            if ($packed === '') continue;
            $counts = \array_count_values(\unpack('v*', $packed));
            foreach ($counts as $dId => $cnt) {
                $merged[$slug][$dId] = ($merged[$slug][$dId] ?? 0) + $cnt;
            }
        }
        unset($parentBuckets);

        // Set read buffers on parent sockets
        $parentSockets = [];
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $parentSockets[$w] = $socketPairs[$w][0];
            \stream_set_read_buffer($parentSockets[$w], 524288);
        }

        // Read from child sockets sequentially
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $sock = $parentSockets[$w];

            // Read 4-byte length prefix
            $lenData = '';
            while (\strlen($lenData) < 4) {
                $chunk = \fread($sock, 4 - \strlen($lenData));
                if ($chunk === false || $chunk === '') break;
                $lenData .= $chunk;
            }
            if (\strlen($lenData) < 4) {
                \fclose($sock);
                continue;
            }
            $totalLen = \unpack('V', $lenData)[1];

            // Read full data
            $data = '';
            while (\strlen($data) < $totalLen) {
                $chunk = \fread($sock, \min(524288, $totalLen - \strlen($data)));
                if ($chunk === false || $chunk === '') break;
                $data .= $chunk;
            }
            \fclose($sock);

            // Deserialize and merge
            $offset  = 0;
            $dataLen = \strlen($data);
            while ($offset < $dataLen) {
                $slugLen = \unpack('V', $data, $offset)[1]; $offset += 4;
                $slug    = \substr($data, $offset, $slugLen); $offset += $slugLen;
                $numPairs = \unpack('V', $data, $offset)[1]; $offset += 4;
                for ($p = 0; $p < $numPairs; $p++) {
                    $dId  = \unpack('V', $data, $offset)[1]; $offset += 4;
                    $cnt  = \unpack('V', $data, $offset)[1]; $offset += 4;
                    $merged[$slug][$dId] = ($merged[$slug][$dId] ?? 0) + $cnt;
                }
            }
            unset($data);
        }

        // Wait for children
        foreach ($childPids as $pid) {
            \pcntl_waitpid($pid, $status);
        }

        // ------------------------------------------------------------------ //
        // Phase 9: JSON Output                                                //
        // ------------------------------------------------------------------ //
        $json      = "{\n";
        $firstSlug = true;
        foreach ($slugOrderList as $slug) {
            if (!isset($merged[$slug])) continue;
            if (!$firstSlug) $json .= ",\n";
            $firstSlug = false;

            $dates = [];
            foreach ($merged[$slug] as $dId => $cnt) {
                $dates[$idToDate[$dId]] = $cnt;
            }
            \ksort($dates);

            $json .= '    ' . \json_encode('/blog/' . $slug) . ': {' . "\n";
            $firstDate = true;
            foreach ($dates as $date => $cnt) {
                if (!$firstDate) $json .= ",\n";
                $firstDate = false;
                $json .= '        "' . $date . '": ' . $cnt;
            }
            $json .= "\n    }";
        }
        $json .= "\n}";
        \file_put_contents($outputPath, $json);
    }
}

// ========================================================================== //
// Worker: process one file segment and write binary result to a socket        //
// ========================================================================== //
function _processSegmentSocket(
    string $inputPath,
    int    $start,
    int    $end,
    array  $dateToId,
    $socket,
    int    $chunkSize
): void {
    \error_reporting(0);

    $buckets = _hotLoop($inputPath, $start, $end, $dateToId, $chunkSize);

    // Serialize to binary
    $out = '';
    foreach ($buckets as $slug => $packed) {
        if ($packed === '') continue;
        $counts = \array_count_values(\unpack('v*', $packed));
        $out .= \pack('V', \strlen($slug)) . $slug . \pack('V', \count($counts));
        foreach ($counts as $dId => $cnt) {
            $out .= \pack('V', $dId) . \pack('V', $cnt);
        }
    }

    // Write length-prefixed data to socket
    \stream_set_write_buffer($socket, 524288);
    $totalLen = \strlen($out);
    \fwrite($socket, \pack('V', $totalLen));
    // Write in 512 KB chunks to avoid blocking on large payloads
    $written = 0;
    while ($written < $totalLen) {
        $n = \fwrite($socket, \substr($out, $written, 524288));
        if ($n === false) break;
        $written += $n;
    }
}

// ========================================================================== //
// Hot loop: read file segment, build per-slug packed date-ID strings          //
// 6x unrolled inner loop for throughput                                       //
// ========================================================================== //
function _hotLoop(
    string $inputPath,
    int    $start,
    int    $end,
    array  $dateToId,
    int    $chunkSize
): array {
    \set_error_handler(function() { return true; });

    $fh = \fopen($inputPath, 'rb');
    \fseek($fh, $start);

    $buckets  = [];
    $leftover = '';
    $segEnd   = $end;

    while (true) {
        $readPos  = $start + \strlen($leftover);
        if ($readPos >= $segEnd) break;

        $toRead = \min($chunkSize, $segEnd - $readPos);
        $raw    = \fread($fh, $toRead);
        if ($raw === false || $raw === '') break;

        $chunk    = $leftover . $raw;
        $chunkLen = \strlen($chunk);

        // Find last newline in chunk
        $lastNl = \strrpos($chunk, "\n");
        if ($lastNl === false) {
            $leftover = $chunk;
            $start    = $readPos + \strlen($raw);
            continue;
        }

        $leftover = \substr($chunk, $lastNl + 1);
        $start    = $readPos + \strlen($raw);

        // Fence: stop before $lastNl - (6 * 120) to stay safe for unrolled reads
        $fence = $lastNl - 720;
        $pos   = 0;

        // 6x unrolled hot loop
        while ($pos <= $fence) {
            // Iteration 1
            $nl1 = \strpos($chunk, "\n", $pos + 52);
            $slug1 = \substr($chunk, $pos + 25, $nl1 - $pos - 51);
            $date1 = \substr($chunk, $nl1 - 25, 10);
            $buckets[$slug1] .= $dateToId[$date1];
            $p2 = $nl1 + 1;

            // Iteration 2
            $nl2 = \strpos($chunk, "\n", $p2 + 52);
            $slug2 = \substr($chunk, $p2 + 25, $nl2 - $p2 - 51);
            $date2 = \substr($chunk, $nl2 - 25, 10);
            $buckets[$slug2] .= $dateToId[$date2];
            $p3 = $nl2 + 1;

            // Iteration 3
            $nl3 = \strpos($chunk, "\n", $p3 + 52);
            $slug3 = \substr($chunk, $p3 + 25, $nl3 - $p3 - 51);
            $date3 = \substr($chunk, $nl3 - 25, 10);
            $buckets[$slug3] .= $dateToId[$date3];
            $p4 = $nl3 + 1;

            // Iteration 4
            $nl4 = \strpos($chunk, "\n", $p4 + 52);
            $slug4 = \substr($chunk, $p4 + 25, $nl4 - $p4 - 51);
            $date4 = \substr($chunk, $nl4 - 25, 10);
            $buckets[$slug4] .= $dateToId[$date4];
            $p5 = $nl4 + 1;

            // Iteration 5
            $nl5 = \strpos($chunk, "\n", $p5 + 52);
            $slug5 = \substr($chunk, $p5 + 25, $nl5 - $p5 - 51);
            $date5 = \substr($chunk, $nl5 - 25, 10);
            $buckets[$slug5] .= $dateToId[$date5];
            $p6 = $nl5 + 1;

            // Iteration 6
            $nl6 = \strpos($chunk, "\n", $p6 + 52);
            $slug6 = \substr($chunk, $p6 + 25, $nl6 - $p6 - 51);
            $date6 = \substr($chunk, $nl6 - 25, 10);
            $buckets[$slug6] .= $dateToId[$date6];
            $pos = $nl6 + 1;
        }

        // Cleanup loop: handle remaining lines up to $lastNl
        while ($pos <= $lastNl) {
            $nl = \strpos($chunk, "\n", $pos + 52);
            if ($nl === false || $nl > $lastNl) break;
            $slug = \substr($chunk, $pos + 25, $nl - $pos - 51);
            $date = \substr($chunk, $nl - 25, 10);
            $buckets[$slug] .= $dateToId[$date];
            $pos = $nl + 1;
        }
    }

    // Process any leftover that didn't end with \n
    if ($leftover !== '') {
        $line = \rtrim($leftover, "\n");
        if (\strlen($line) > 51) {
            $nl = \strlen($line);
            // line format: prefix(25) + slug + comma + date(25) + +00:00
            // find comma from position 25
            $commaPos = \strpos($line, ',', 25);
            if ($commaPos !== false) {
                $slug = \substr($line, 25, $commaPos - 25);
                $date = \substr($line, $commaPos + 1, 10);
                if (isset($dateToId[$date])) {
                    $buckets[$slug] .= $dateToId[$date];
                }
            }
        }
    }

    \fclose($fh);
    return $buckets;
}
