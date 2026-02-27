<?php

namespace App;

\gc_disable();

function _hotLoop(
    string $inputPath, int $start, int $end,
    array $dateToId, array $slugOrderList, int $chunkSize
): array {
    $buckets = \array_fill_keys($slugOrderList, '');

    $fh = \fopen($inputPath, 'rb');
    \stream_set_read_buffer($fh, 0);
    \fseek($fh, $start);
    $remaining = $end - $start;
    $leftover = '';

    do {
        $toRead = \min($chunkSize, $remaining);
        $raw = \fread($fh, $toRead);
        if ($raw === false || $raw === '') break;
        $remaining -= \strlen($raw);

        if ($leftover !== '') {
            $firstNl = \strpos($raw, "\n");
            if ($firstNl === false) {
                $leftover .= $raw;
                continue;
            }
            $line = $leftover . \substr($raw, 0, $firstNl);
            $lineLen = \strlen($line);
            $buckets[\substr($line, 25, $lineLen - 51)] .= $dateToId[\substr($line, $lineLen - 23, 8)];
            $leftover = '';
            $pos = $firstNl + 1;
        } else {
            $pos = 0;
        }

        $lastNl = \strrpos($raw, "\n");
        if ($lastNl === false || $lastNl < $pos) {
            $leftover = ($pos > 0) ? \substr($raw, $pos) : $raw;
            continue;
        }
        $leftover = ($lastNl + 1 < \strlen($raw)) ? \substr($raw, $lastNl + 1) : '';

        $fence = $lastNl - 600;

        if ($pos < $fence) {
            do {
                $nl = \strpos($raw, "\n", $pos + 52);
                $buckets[\substr($raw, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($raw, $nl - 23, 8)];
                $pos = $nl + 1;

                $nl = \strpos($raw, "\n", $pos + 52);
                $buckets[\substr($raw, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($raw, $nl - 23, 8)];
                $pos = $nl + 1;

                $nl = \strpos($raw, "\n", $pos + 52);
                $buckets[\substr($raw, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($raw, $nl - 23, 8)];
                $pos = $nl + 1;

                $nl = \strpos($raw, "\n", $pos + 52);
                $buckets[\substr($raw, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($raw, $nl - 23, 8)];
                $pos = $nl + 1;

                $nl = \strpos($raw, "\n", $pos + 52);
                $buckets[\substr($raw, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($raw, $nl - 23, 8)];
                $pos = $nl + 1;

                $nl = \strpos($raw, "\n", $pos + 52);
                $buckets[\substr($raw, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($raw, $nl - 23, 8)];
                $pos = $nl + 1;
            } while ($pos < $fence);
        }

        if ($pos < $lastNl) {
            do {
                $nl = \strpos($raw, "\n", $pos + 52);
                if ($nl === false || $nl > $lastNl) break;
                $buckets[\substr($raw, $pos + 25, $nl - $pos - 51)] .= $dateToId[\substr($raw, $nl - 23, 8)];
                $pos = $nl + 1;
            } while ($pos < $lastNl);
        }
    } while ($remaining > 0);
    \fclose($fh);
    return $buckets;
}

final class Parser
{
    public function parse(string $inputPath, string $outputPath): void
    {
        \gc_disable();

        $cpuCacheFile = \sys_get_temp_dir() . '/.parser_cpu_perf';
        $perfCores = 0;
        if (\file_exists($cpuCacheFile)) {
            $cached = \file_get_contents($cpuCacheFile);
            if ($cached !== false && $cached !== '') {
                $perfCores = (int)$cached;
            }
        }
        if ($perfCores < 1) {
            $perfCores = (int)\trim((string)@\shell_exec('sysctl -n hw.perflevel0.logicalcpu 2>/dev/null'));
            if ($perfCores < 1) {
                $perfCores = (int)\trim((string)@\shell_exec('sysctl -n hw.ncpu 2>/dev/null'));
            }
            @\file_put_contents($cpuCacheFile, (string)$perfCores);
        }
        $numWorkers = ($perfCores >= 8) ? $perfCores : 12;
        $chunkSize  = ($perfCores >= 8) ? 524288 : 163840;

        $slugOrder = [];
        $fh = \fopen($inputPath, 'rb');
        $sample = \fread($fh, 524288);
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

        $dateToId = [];
        $idToDate = [];
        $dateId = 0;
        $daysInMonth = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($year = 2019; $year <= 2026; $year++) {
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

        $dateJsonPrefix = [];
        foreach ($idToDate as $dId => $dateStr) {
            $dateJsonPrefix[$dId] = '        "' . $dateStr . '": ';
        }

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

        $slugToIdx = \array_flip($slugOrderList);

        // Temp file IPC: parent-as-coordinator, all workers write to temp files
        $tmpDir = \sys_get_temp_dir();
        $childPids = [];
        $pidToWorker = [];

        for ($w = 0; $w < $numWorkers; $w++) {
            $pid = \pcntl_fork();
            if ($pid === 0) {
                // Child: process segment $w and write output to temp file
                $buckets = _hotLoop(
                    $inputPath, $boundaries[$w], $boundaries[$w + 1],
                    $dateToId, $slugOrderList, $chunkSize
                );

                $out = '';
                foreach ($buckets as $slug => $packed) {
                    if ($packed === '') continue;
                    $idx = $slugToIdx[$slug] ?? 0xFFFF;
                    $out .= \pack('vV', $idx, \strlen($packed)) . $packed;
                }
                unset($buckets);

                \file_put_contents($tmpDir . '/parser_w' . $w, $out);
                \posix_kill(\posix_getpid(), 9);
                exit(0);
            }
            $childPids[] = $pid;
            $pidToWorker[$pid] = $w;
        }

        // Parent enters waitpid(-1) drain loop — processes workers in completion order
        $mergedBuckets = \array_fill_keys($slugOrderList, '');
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

            // TLV merge
            $offset = 0;
            $dataLen = \strlen($data);
            while ($offset < $dataLen) {
                $slugIdx = \ord($data[$offset]) | (\ord($data[$offset + 1]) << 8);
                $bucketLen = \ord($data[$offset + 2]) | (\ord($data[$offset + 3]) << 8) | (\ord($data[$offset + 4]) << 16) | (\ord($data[$offset + 5]) << 24);
                $offset += 6;
                $mergedBuckets[$slugOrderList[$slugIdx]] .= \substr($data, $offset, $bucketLen);
                $offset += $bucketLen;
            }
            unset($data);

            $drained++;
        } while ($drained < $numWorkers);

        $numCounters = ($perfCores >= 8) ? 10 : 8;
        $numSlugs = \count($slugOrderList);
        $slugsPerCounter = (int)\ceil($numSlugs / $numCounters);

        // Large socket buffers via sockets extension (counting workers)
        $countPipes = [];
        for ($c = 0; $c < $numCounters; $c++) {
            \socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $rawPair);
            \socket_set_option($rawPair[0], SOL_SOCKET, SO_RCVBUF, 2097152);
            \socket_set_option($rawPair[1], SOL_SOCKET, SO_SNDBUF, 2097152);
            $countPipes[$c] = [\socket_export_stream($rawPair[0]), \socket_export_stream($rawPair[1])];
        }

        $countPids = [];
        for ($c = 0; $c < $numCounters; $c++) {
            $pid = \pcntl_fork();
            if ($pid === 0) {
                for ($i = 0; $i < $numCounters; $i++) {
                    \fclose($countPipes[$i][0]);
                    if ($i !== $c) \fclose($countPipes[$i][1]);
                }

                $myStart = $c * $slugsPerCounter;
                $myEnd = \min(($c + 1) * $slugsPerCounter, $numSlugs);

                $fragment = '';
                $separator = '';

                for ($s = $myStart; $s < $myEnd; $s++) {
                    $slug = $slugOrderList[$s];
                    $packed = $mergedBuckets[$slug];
                    if ($packed === '') continue;

                    $counts = \array_count_values(\unpack('v*', $packed));

                    $fragment .= $separator . '    "\/blog\/' . $slug . '": {' . "\n";
                    $entrySep = '';
                    foreach ($dateJsonPrefix as $dId => $prefix) {
                        if (isset($counts[$dId])) {
                            $fragment .= $entrySep . $prefix . $counts[$dId];
                            $entrySep = ",\n";
                        }
                    }
                    $fragment .= "\n    }";
                    $separator = ",\n";
                }

                $sock = $countPipes[$c][1];
                $len = \strlen($fragment);
                $written = 0;
                while ($written < $len) {
                    $n = \fwrite($sock, \substr($fragment, $written, 524288));
                    if ($n === false) break;
                    $written += $n;
                }
                \fclose($sock);
                \posix_kill(\posix_getpid(), 9);
                exit(0);
            }
            $countPids[] = $pid;
        }

        for ($c = 0; $c < $numCounters; $c++) {
            \fclose($countPipes[$c][1]);
        }
        unset($mergedBuckets);

        $fhOut = \fopen($outputPath, 'wb');
        \fwrite($fhOut, "{\n");
        $needSep = false;

        for ($c = 0; $c < $numCounters; $c++) {
            $fragment = \stream_get_contents($countPipes[$c][0]);
            \fclose($countPipes[$c][0]);

            if ($fragment !== '') {
                if ($needSep) \fwrite($fhOut, ",\n");
                \fwrite($fhOut, $fragment);
                $needSep = true;
            }
        }

        \fwrite($fhOut, "\n}");
        \fclose($fhOut);

        foreach ($countPids as $pid) {
            \pcntl_waitpid($pid, $status);
        }
    }
}
