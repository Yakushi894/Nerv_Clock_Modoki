<?php
declare(strict_types=1);

function counter_file(): string
{
    return dirname(__DIR__) . '/data/hits.txt';
}

function is_bot_ua(?string $ua): bool
{
    if ($ua === null || $ua === '') {
        return true;
    }
    return (bool) preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|pingdom|uptime|monitor/i', $ua);
}

function read_hits(): int
{
    $file = counter_file();
    if (!is_readable($file)) {
        return 0;
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return 0;
    }
    return max(0, (int) trim($raw));
}

function bump_hits(): int
{
    $dir = dirname(counter_file());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = counter_file();
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return read_hits();
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return read_hits();
    }
    $raw = stream_get_contents($fp);
    $n = max(0, (int) trim((string) $raw)) + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string) $n);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $n;
}
