<?php
/**
 * NTP query for shared hosting. Reads host only from clock.ini (no client-supplied host).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const NTP_UNIX_DELTA = 2208988800.0;
const ATTEMPTS = 3;

function clock_ini(string $path): array
{
    $server = 'ntp.nict.jp';
    $timeout = 2500;
    if (!is_readable($path)) {
        return [$server, $timeout];
    }
    $section = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $raw) {
        $line = trim($raw);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^\[([^\]]+)\]$/', $line, $m)) {
            $section = strtolower($m[1]);
            continue;
        }
        if ($section !== '' && $section !== 'ntp') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = strtolower(trim(substr($line, 0, $eq)));
        $val = trim(substr($line, $eq + 1));
        if ($key === 'server' && $val !== '') {
            $server = $val;
        }
        if ($key === 'timeout_ms' && is_numeric($val)) {
            $timeout = max(400, min(8000, (int) $val));
        }
    }
    return [$server, $timeout];
}

function sanitize_host(string $raw): ?string
{
    $host = strtolower(trim($raw));
    $host = preg_replace('#^(ntp|udp|https?)://#', '', $host) ?? $host;
    $host = explode('/', $host, 2)[0];
    $host = explode(':', $host, 2)[0];
    if ($host === '' || strlen($host) > 253) {
        return null;
    }
    $dns = (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host);
    $ip = (bool) preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $host);
    if (!$dns && !$ip) {
        return null;
    }
    if ($ip) {
        foreach (explode('.', $host) as $n) {
            if ((int) $n > 255) {
                return null;
            }
        }
    }
    return $host;
}

function ntp_stamp_ms(float $seconds, float $fraction): float
{
    return ($seconds - NTP_UNIX_DELTA) * 1000.0 + ($fraction / 4294967296.0) * 1000.0;
}

function pack_ntp_stamp(float $unixMs): string
{
    $ntp = $unixMs / 1000.0 + NTP_UNIX_DELTA;
    $sec = (int) floor($ntp);
    $frac = (int) round(($ntp - $sec) * 4294967296.0);
    return pack('NN', $sec, $frac);
}

function query_once(string $host, int $timeoutMs): array
{
    $timeoutSec = max(0.4, $timeoutMs / 1000);
    $t1ms = microtime(true) * 1000.0;
    $sock = @stream_socket_client(
        'udp://' . $host . ':123',
        $errno,
        $errstr,
        $timeoutSec
    );
    if ($sock === false) {
        return ['ok' => false, 'server' => $host, 'error' => $errstr !== '' ? $errstr : 'socket'];
    }
    stream_set_timeout($sock, (int) floor($timeoutSec), (int) (($timeoutSec - floor($timeoutSec)) * 1000000));
    $packet = "\x1b" . str_repeat("\0", 39) . pack_ntp_stamp($t1ms);
    if (@fwrite($sock, $packet) === false) {
        fclose($sock);
        return ['ok' => false, 'server' => $host, 'error' => 'send'];
    }
    $resp = @fread($sock, 48);
    fclose($sock);
    $t4ms = microtime(true) * 1000.0;
    if (!is_string($resp) || strlen($resp) < 48) {
        return ['ok' => false, 'server' => $host, 'error' => 'timeout'];
    }
    $stratum = ord($resp[1]);
    $mode = ord($resp[0]) & 0x7;
    if ($stratum < 1 || $stratum > 15 || ($mode !== 4 && $mode !== 5)) {
        return ['ok' => false, 'server' => $host, 'error' => 'invalid reply'];
    }
    $t2s = unpack('N', substr($resp, 32, 4))[1];
    $t2f = unpack('N', substr($resp, 36, 4))[1];
    $t3s = unpack('N', substr($resp, 40, 4))[1];
    $t3f = unpack('N', substr($resp, 44, 4))[1];
    if ((int) $t3s === 0) {
        return ['ok' => false, 'server' => $host, 'error' => 'no timestamp'];
    }
    $t3ms = ntp_stamp_ms((float) $t3s, (float) $t3f);
    $t2ms = ((int) $t2s === 0) ? $t3ms : ntp_stamp_ms((float) $t2s, (float) $t2f);
    $unixMs = (int) round(($t2ms + $t3ms) / 2.0);
    return [
        'ok' => true,
        'server' => $host,
        'unixMs' => $unixMs,
        'offsetMs' => 0,
        'rttMs' => (int) round($t4ms - $t1ms),
        'method' => 'ntp',
    ];
}

function query_ntp(string $host, int $timeoutMs): array
{
    $last = ['ok' => false, 'server' => $host, 'error' => 'unreachable'];
    for ($i = 0; $i < ATTEMPTS; $i++) {
        $last = query_once($host, $timeoutMs);
        if (!empty($last['ok'])) {
            return $last;
        }
    }
    return $last;
}

[$rawServer, $timeout] = clock_ini(__DIR__ . '/clock.ini');
$host = sanitize_host($rawServer);
if ($host === null) {
    echo json_encode(['ok' => false, 'server' => $rawServer, 'error' => 'invalid host'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(query_ntp($host, $timeout), JSON_UNESCAPED_UNICODE);
