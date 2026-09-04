<?php
declare(strict_types=1);

function font_dir(): string
{
    return dirname(__DIR__) . '/fonts';
}

function font_format(string $ext): string
{
    if ($ext === 'woff2') {
        return 'woff2';
    }
    if ($ext === 'woff') {
        return 'woff';
    }
    if ($ext === 'otf') {
        return 'opentype';
    }
    return 'truetype';
}

/**
 * @return list<array{url: string, format: string, family: string}>
 */
function discover_fonts(): array
{
    $dir = font_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $found = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)) {
            continue;
        }
        $base = strtolower(pathinfo($name, PATHINFO_FILENAME));
        $family = (strpos($base, 'mono') !== false || strpos($base, 'code') !== false) ? 'YashimaUserMono' : 'YashimaUser';
        $found[] = [
            'url' => 'fonts/' . rawurlencode($name),
            'format' => font_format($ext),
            'family' => $family,
        ];
    }
    return $found;
}

function font_face_css(array $fonts): string
{
    if ($fonts === []) {
        return '';
    }
    $css = '';
    foreach ($fonts as $f) {
        $family = $f['family'];
        $url = $f['url'];
        $format = $f['format'];
        $css .= "@font-face{font-family:\"{$family}\";src:url(\"{$url}\") format(\"{$format}\");font-display:swap;}";
    }
    $hasSans = false;
    $hasMono = false;
    foreach ($fonts as $f) {
        if ($f['family'] === 'YashimaUser') {
            $hasSans = true;
        }
        if ($f['family'] === 'YashimaUserMono') {
            $hasMono = true;
        }
    }
    if ($hasSans) {
        $css .= '.eva-kicker,.eva-sub,.eva-nerv,.eva-chip{font-family:"YashimaUser",var(--font-sans);}';
    }
    if ($hasMono) {
        $css .= '.eva-org,.eva-en{font-family:"YashimaUserMono",var(--font-mono);}';
    }
    return $css;
}
