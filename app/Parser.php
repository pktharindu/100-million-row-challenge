<?php

namespace App;

use Exception;

final class Parser
{
    public function parse(string $inputPath, string $outputPath): void
    {
        $result = [];

        $handle = fopen($inputPath, 'r');
        while (($line = fgets($handle)) !== false) {
            [$url, $date] = explode(',', substr($line, 19), 2);
            $date = substr($date, 0, 10);

            $result[$url][$date] = ($result[$url][$date] ?? 0) + 1;
        }
        fclose($handle);

        array_walk($result, fn(&$dates) => ksort($dates));

        file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT));
    }
}
