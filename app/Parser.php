<?php

namespace App;

\gc_disable();

function _hotLoop(
    string $inputPath, int $start, int $end,
    array $dateToId, array $slugOrderList, int $chunkSize
): array {
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

final class Parser
{
    public function parse(string $inputPath, string $outputPath): void
    {
        \gc_disable();

        $numWorkers = 10;
        $chunkSize  = 262144; // 256 KB

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

        // Pre-enumerate dates (2019-2028, leap year aware)
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

        // Build slug index for compact IPC
        $slugToIdx = \array_flip($slugOrderList);

        // Create socket pairs before forking (one pair per child worker)
        $sockets = [];
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $sockets[$w] = $pair; // [0] = parent end, [1] = child end
        }

        // Fork workers with socket pair IPC
        $childPids = [];

        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $pid = \pcntl_fork();
            if ($pid === 0) {
                // CHILD: close parent ends of all sockets and sibling child ends
                for ($i = 0; $i < $numWorkers - 1; $i++) {
                    \fclose($sockets[$i][0]);
                    if ($i !== $w) {
                        \fclose($sockets[$i][1]);
                    }
                }

                // Process segment
                $buckets = _hotLoop(
                    $inputPath, $boundaries[$w], $boundaries[$w + 1],
                    $dateToId, $slugOrderList, $chunkSize
                );

                // Serialize: same format as temp file IPC
                $out = '';
                foreach ($buckets as $slug => $packed) {
                    if ($packed === '') continue;
                    $idx = $slugToIdx[$slug] ?? 0xFFFF;
                    $out .= \pack('vV', $idx, \strlen($packed)) . $packed;
                }
                unset($buckets);

                // Write to socket in chunks
                $sock = $sockets[$w][1];
                $len = \strlen($out);
                $written = 0;
                while ($written < $len) {
                    $n = \fwrite($sock, \substr($out, $written, 65536));
                    if ($n === false) break;
                    $written += $n;
                }
                \fclose($sock);
                exit(0);
            }
            $childPids[] = $pid;
        }

        // Parent closes all child ends of sockets
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            \fclose($sockets[$w][1]);
        }

        // Parent processes its own segment (last segment)
        $parentBuckets = _hotLoop(
            $inputPath, $boundaries[$numWorkers - 1], $boundaries[$numWorkers],
            $dateToId, $slugOrderList, $chunkSize
        );

        // Use stream_select to read from children as they finish
        $parentSocks = [];
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $parentSocks[$w] = $sockets[$w][0];
            \stream_set_blocking($parentSocks[$w], false);
        }

        $buffers = \array_fill(0, $numWorkers - 1, '');
        $remaining = $numWorkers - 1;

        while ($remaining > 0) {
            $read = \array_values(\array_filter($parentSocks, fn($s) => $s !== null));
            if (empty($read)) break;
            $write = null;
            $except = null;
            if (\stream_select($read, $write, $except, 1) > 0) {
                foreach ($read as $sock) {
                    $w = \array_search($sock, $parentSocks, true);
                    $data = \fread($sock, 65536);
                    if ($data === '' || $data === false) {
                        // Socket closed = child done
                        \fclose($sock);
                        $parentSocks[$w] = null;
                        $remaining--;
                    } else {
                        $buffers[$w] .= $data;
                    }
                }
            }
        }

        // Wait for children (they should already be done since we read all data)
        foreach ($childPids as $pid) {
            \pcntl_waitpid($pid, $status);
        }

        // Merge: start with parent's buckets
        $mergedBuckets = $parentBuckets;
        unset($parentBuckets);

        // Deserialize child results from buffers
        for ($w = 0; $w < $numWorkers - 1; $w++) {
            $data = $buffers[$w];
            unset($buffers[$w]);
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

        // Fused batch count + JSON output (single pass per slug for cache locality)
        $bufferSize = 65536;
        $fhOut = \fopen($outputPath, 'wb');
        $buf = "{\n";
        $separator = '';
        foreach ($slugOrderList as $slug) {
            $packed = $mergedBuckets[$slug];
            if ($packed === '') continue;
            $counts = \array_count_values(\unpack('v*', $packed));
            \ksort($counts);
            $entries = [];
            foreach ($counts as $dId => $cnt) {
                $entries[] = '        "' . $idToDate[$dId] . '": ' . $cnt;
            }
            $buf .= $separator . '    "\/blog\/' . $slug . '": {' . "\n"
                  . \implode(",\n", $entries) . "\n    }";
            $separator = ",\n";
            if (\strlen($buf) >= $bufferSize) {
                \fwrite($fhOut, $buf);
                $buf = '';
            }
        }
        unset($mergedBuckets);
        $buf .= "\n}";
        \fwrite($fhOut, $buf);
        \fclose($fhOut);
    }
}
