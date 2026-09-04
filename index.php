<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/counter.php';
require_once __DIR__ . '/lib/fonts.php';

$hits = is_bot_ua($_SERVER['HTTP_USER_AGENT'] ?? null) ? read_hits() : bump_hits();
$hitsLabel = 'COUNT ' . str_pad((string) $hits, 6, '0', STR_PAD_LEFT);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
$host = $_SERVER['HTTP_HOST'] ?? '';
$path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
$shareUrl = ($https ? 'https' : 'http') . '://' . $host . $path;
$shareHref = 'https://twitter.com/intent/tweet?text=' . rawurlencode('ヤシマ作戦クロック') . '&url=' . rawurlencode($shareUrl);
$assetV = '20260904g';
$fontCss = font_face_css(discover_fonts());

header('Content-Type: text/html; charset=utf-8');
header('Content-Language: ja');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ja" translate="no">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#000000" />
  <meta http-equiv="content-language" content="ja" />
  <meta name="google" content="notranslate" />
  <meta name="description" content="日本標準時を表示する、エヴァンゲリオンのヤシマ作戦風デジタル時計です。" />
  <title>ヤシマ作戦クロック — 日本標準時</title>
  <link rel="stylesheet" href="assets/app.css?v=<?php echo htmlspecialchars($assetV, ENT_QUOTES); ?>" />
  <?php if ($fontCss !== '') { ?>
  <style><?php echo $fontCss; ?></style>
  <?php } ?>
</head>
<body class="notranslate" translate="no">
  <div class="eva-root is-booting" id="root" data-mode="limit">
    <div class="eva-scan"></div>
    <div class="eva-vignette"></div>
    <div class="eva-ribbon" aria-hidden="true"></div>

    <div class="eva-boot" id="boot">
      <svg class="nerv-mark" viewBox="0 0 48 48" aria-hidden="true">
        <rect x="1.5" y="1.5" width="45" height="45" rx="2"></rect>
        <path d="M8 32 L24 10 L40 32"></path>
        <path d="M14 32 L24 18 L34 32"></path>
        <circle cx="24" cy="34" r="3.2"></circle>
      </svg>
      <p class="ntp-hud">
        <span class="ntp-lamp is-wait" id="bootLamp"></span>
        <span id="bootNtp">NTP QUERY</span>
      </p>
      <ul id="bootLines"></ul>
    </div>

    <div id="app" hidden>
      <header class="eva-top">
        <div class="eva-brand">
          <svg class="nerv-mark" viewBox="0 0 48 48" aria-hidden="true">
            <rect x="1.5" y="1.5" width="45" height="45" rx="2"></rect>
            <path d="M8 32 L24 10 L40 32"></path>
            <path d="M14 32 L24 18 L34 32"></path>
            <circle cx="24" cy="34" r="3.2"></circle>
          </svg>
          <div>
            <p class="eva-org">SPECIAL DUTY ORGANIZATION</p>
            <p class="eva-nerv">COMMAND SYSTEM</p>
          </div>
        </div>
        <ul class="magi-strip">
          <li data-status="ok"><span class="magi-dot"></span>MELCHIOR</li>
          <li data-status="ok"><span class="magi-dot"></span>BALTHASAR</li>
          <li data-status="ok"><span class="magi-dot"></span>CASPER</li>
        </ul>
        <div class="eva-stamp">
          <p class="ntp-hud">
            <span class="ntp-lamp is-wait" id="headLamp"></span>
            <span id="headNtp">NTP QUERY</span>
          </p>
          <p id="headDate">----.--.--</p>
          <p class="eva-hits"><?php echo htmlspecialchars($hitsLabel, ENT_QUOTES); ?></p>
        </div>
      </header>

      <main class="eva-stage">
        <div class="eva-stage-head">
          <div class="eva-fit" id="headFit">
            <p class="eva-kicker" id="kicker">日本標準時</p>
            <p class="eva-sub">
              <span id="subJp">日本標準時 (JST)</span>
              <span class="eva-en" id="subEn">JST  UTC+9</span>
            </p>
          </div>
        </div>
        <div class="eva-timer" id="timer" role="timer" aria-live="polite">
          <div class="eva-fit" id="timerFit"></div>
        </div>
        <div class="eva-utc-row" id="utcRow">
          <div class="eva-fit is-row" id="utcFit">
            <span>世界標準時</span>
            <div class="inline-hms" id="utcTimer"></div>
          </div>
        </div>
      </main>

      <footer class="eva-bottom">
        <div class="eva-stat">
          <span id="footLocalLabel">日本標準時 (JST)</span>
          <strong id="footLocal">--:--:--</strong>
        </div>
        <div class="eva-stat">
          <span>世界標準時 (UTC)</span>
          <strong id="footUtc">--:--:--</strong>
        </div>
        <div class="eva-stat" id="footNtpStat">
          <span class="eva-stat-label">
            <span class="ntp-lamp is-wait" id="footLamp"></span>
            <span id="footNtpLabel">NTP QUERY</span>
          </span>
          <strong id="footNtpVal">—</strong>
        </div>
      </footer>

      <div class="eva-chrome is-on" id="chrome">
        <div class="eva-modes" role="tablist" aria-label="表示モード">
          <button type="button" class="eva-chip is-on" role="tab" aria-selected="true" data-mode="limit">時刻</button>
          <button type="button" class="eva-chip" role="tab" aria-selected="false" data-mode="countdown">限界(タイマー)</button>
        </div>
        <div class="eva-count-tools" id="countTools" hidden>
          <button type="button" class="eva-chip" data-ms="60000">01:00</button>
          <button type="button" class="eva-chip is-on" data-ms="300000">05:00</button>
          <button type="button" class="eva-chip" data-ms="900000">15:00</button>
          <button type="button" class="eva-chip" data-ms="1800000">30:00</button>
          <button type="button" class="eva-chip is-accent" id="btnToggle">開始</button>
          <button type="button" class="eva-chip" id="btnReset">リセット</button>
        </div>
        <span class="eva-count-flag"><?php echo htmlspecialchars($hitsLabel, ENT_QUOTES); ?></span>
        <a class="eva-chip" id="btnShare" href="<?php echo htmlspecialchars($shareHref, ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer">Xで共有</a>
        <button type="button" class="eva-chip" id="btnFull">全画面</button>
      </div>
    </div>
  </div>
  <script src="assets/app.js?v=<?php echo htmlspecialchars($assetV, ENT_QUOTES); ?>" defer></script>
</body>
</html>
