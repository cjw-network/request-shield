<?php
/**
 * The showcase's front page. Everything it says about a try comes from the
 * rules (site.php: showcaseTries()); the words from texts.php.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 * @var list<array{n: int, method: string, url: string, headers: array<string, string>, from: string, pass: bool, times: int, outcome: string, by: ?string, text: string, section: string}> $tries
 */

declare(strict_types=1);

$lang = ($_GET['lang'] ?? '') === 'en' ? 'en' : (($_GET['lang'] ?? '') === 'de' ? 'de'
    : (stripos((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'de') === 0 ? 'de' : 'en'));
$all = require __DIR__ . '/texts.php';
$t = $all[$lang];
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$tr = static fn (string $en): string => $t['tries'][$en] ?? $en;
$note = static fn (string $en): string => $t['notes'][$en] ?? $en;

// The tries by group, as the page shows them; the page's own address is no card.
$groupOf = static function (string $section): ?string {
    if ($section === 'built-in' || $section === '@attacks') {
        return 'scan';
    }
    return ['@tracking' => 'params', 'SHOW-PARAMS' => 'params', 'SHOW-STRICT' => 'params', 'SHOW-PACE' => 'pace', 'SHOW-FORMS' => 'forms', 'SHOW-ORIGIN' => 'forms',
        'SHOW-ADMIN' => 'access', 'SHOW-LOGIN' => 'access', 'SHOW-DENY' => 'access', 'SHOW-RS' => 'access'][$section] ?? null;
};
$groups = array_fill_keys(array_keys($t['groups']), []);
foreach ($tries as $try) {
    $g = $groupOf($try['section']);
    if ($g !== null) {
        $groups[$g][] = $try;
    }
}

// The rule file as the page shows it: from "ids" to the showcase's own settings, without the examples.
$ruleLines = [];
$on = false;
foreach (file(__DIR__ . '/showcase.rules', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $on = $on || strncmp($line, 'ids ', 4) === 0;
    if (strncmp($line, "# The showcase's own settings", 29) === 0) {
        break;
    }
    if (!$on || strncmp($line, 'expect ', 7) === 0) {
        continue;
    }
    if (trim($line) === '') {
        if ($ruleLines !== [] && end($ruleLines)['code'] !== '') {
            $ruleLines[] = ['code' => '', 'note' => ''];
        }
        continue;
    }
    if (strncmp(ltrim($line), '#', 1) === 0) {
        $ruleLines[] = ['code' => '', 'note' => $note(trim(ltrim(trim($line), '#')))];
        continue;
    }
    $hash = strpos($line, ' # ');
    $ruleLines[] = ['code' => rtrim($hash !== false ? substr($line, 0, $hash) : $line), 'note' => $hash !== false ? $note(trim(substr($line, $hash + 3))) : ''];
}

$outcomeLabel = static fn (string $o): string => $t['outcome'][$o] ?? $o;
$badge = static fn (string $o): string => $o === 'answered' ? 'pass' : ($o === 'check' ? 'check' : 'stop');
$client = [
    'tries' => $tries,
    'words' => ['outcome' => $t['outcome'], 'send' => $t['send'], 'sent' => $t['sent'], 'from' => $t['from'], 'tries' => array_map($tr, array_column($tries, 'text', 'n'))],
];
?><!doctype html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($t['title']) ?></title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/showcase.css">
</head>
<body data-bs-spy="scroll" data-bs-target="#nav">

<nav id="nav" class="navbar navbar-expand-lg navbar-dark fixed-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="#top"><span class="logo"><i class="bi bi-shield-check"></i></span> request-shield</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu" aria-controls="menu" aria-expanded="false" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="menu">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
        <?php foreach (['what', 'rules', 'try', 'install'] as $k): ?>
        <li class="nav-item"><a class="nav-link" href="#<?= $k ?>"><?= $e($t['nav'][$k]) ?></a></li>
        <?php endforeach ?>
        <li class="nav-item ms-lg-3"><div class="btn-group btn-group-sm" role="group" aria-label="Language">
          <a class="btn <?= $lang === 'de' ? 'btn-light' : 'btn-outline-light' ?>" href="/?lang=de" hreflang="de">DE</a>
          <a class="btn <?= $lang === 'en' ? 'btn-light' : 'btn-outline-light' ?>" href="/?lang=en" hreflang="en">EN</a>
        </div></li>
      </ul>
    </div>
  </div>
</nav>

<header id="top" class="hero">
  <div class="container">
    <div class="row align-items-center g-4 g-lg-5">
      <div class="col-lg-6">
        <p class="eyebrow"><?= $e($t['heroEyebrow']) ?></p>
        <h1 class="display-3 fw-bold"><?= $t['heroTitle'] ?></h1>
        <p class="lead my-4"><?= $e($t['heroLead']) ?></p>
        <div class="d-flex flex-wrap gap-3">
          <a class="btn btn-accent btn-lg" href="#try"><i class="bi bi-play-fill"></i> <?= $e($t['heroTry']) ?></a>
          <a class="btn btn-outline-light btn-lg" href="#rules"><?= $e($t['heroRules']) ?></a>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="stream card-glass" aria-live="polite">
          <div class="stream-head"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="ms-2"><?= $e($t['streamTitle']) ?></span></div>
          <ul id="stream" class="stream-list"></ul>
        </div>
      </div>
    </div>
    <div class="row stats text-center g-3 mt-5">
      <?php foreach ($t['stats'] as [$big, $small]): ?>
      <div class="col-6 col-md-3"><div class="stat"><div class="stat-big"><?= $e($big) ?></div><div class="stat-small"><?= $e($small) ?></div></div></div>
      <?php endforeach ?>
    </div>
  </div>
</header>

<section id="what" class="section">
  <div class="container">
    <div class="section-head"><h2><?= $e($t['whatTitle']) ?></h2><p class="lead"><?= $e($t['whatLead']) ?></p></div>
    <div class="row g-4">
      <?php foreach ($t['features'] as [$icon, $title, $text]): ?>
      <div class="col-md-6 col-lg-4"><div class="feature h-100">
        <div class="feature-icon"><i class="bi <?= $e($icon) ?>"></i></div>
        <h3 class="h5"><?= $e($title) ?></h3><p class="mb-0"><?= $e($text) ?></p>
      </div></div>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section id="rules" class="section section-alt">
  <div class="container">
    <div class="section-head"><h2><?= $e($t['rulesTitle']) ?></h2><p class="lead"><?= $e($t['rulesLead']) ?></p></div>
    <div class="rules card-code">
      <div class="rules-head"><i class="bi bi-file-earmark-text"></i> showcase.rules</div>
      <?php foreach ($ruleLines as $r): ?>
        <?php if ($r['code'] === '' && $r['note'] === ''): ?><div class="rule-gap"></div>
        <?php elseif ($r['code'] === ''): ?><div class="rule-line rule-comment"><div class="rule-code"># <?= $e($r['note']) ?></div></div>
        <?php else: ?><div class="rule-line"><code class="rule-code"><?= $e($r['code']) ?></code><div class="rule-note"><?= $e($r['note']) ?></div></div>
        <?php endif ?>
      <?php endforeach ?>
    </div>
    <p class="small text-secondary mt-3"><?= $t['rulesNote'] ?></p>
  </div>
</section>

<section id="try" class="section">
  <div class="container">
    <div class="section-head"><h2><?= $e($t['tryTitle']) ?></h2><p class="lead"><?= $e($t['tryLead']) ?></p>
      <button id="try-all" class="btn btn-accent"><i class="bi bi-lightning-charge-fill"></i> <?= $e($t['tryAll']) ?></button></div>
    <?php foreach ($groups as $g => $list): ?>
    <div class="try-group">
      <h3 class="h4"><?= $e($t['groups'][$g][0]) ?></h3><p class="text-secondary"><?= $e($t['groups'][$g][1]) ?></p>
      <?php if ($g === 'pace'): ?>
        <?php $limits = array_column($list, 'times'); sort($limits); ?>
        <div class="burst card-try" data-check="<?= (int) $limits[0] ?>" data-pause="<?= (int) end($limits) ?>">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div><h4 class="h5 mb-1"><?= $e($t['burstTitle']) ?></h4><p class="mb-0 text-secondary"><?= $e($t['burstLead']) ?></p></div>
            <button class="btn btn-accent burst-go"><i class="bi bi-rocket-takeoff"></i> <?= $e($t['burstGo']) ?></button>
          </div>
          <div class="burst-bar mt-3" aria-hidden="true"></div>
          <div class="burst-legend mt-2 small">
            <span><i class="sq sq-pass"></i> <?= $e($t['burstLegend'][0]) ?> <b class="n-pass">0</b></span>
            <span><i class="sq sq-check"></i> <?= $e($t['burstLegend'][1]) ?> <b class="n-check">0</b></span>
            <span><i class="sq sq-stop"></i> <?= $e($t['burstLegend'][2]) ?> <b class="n-stop">0</b></span>
          </div>
        </div>
      <?php else: ?>
      <div class="row g-3">
        <?php foreach ($list as $try): ?>
        <?php $mode = $try['pass'] ? 'pass' : ($try['headers'] !== [] ? 'server' : 'live'); ?>
        <div class="col-md-6 col-xl-4"><div class="card-try h-100" data-n="<?= $try['n'] ?>" data-mode="<?= $mode ?>">
          <div class="try-text"><?= $e($tr($try['text'])) ?></div>
          <code class="try-request"><span class="m"><?= $e($try['method']) ?></span> <?= $e(rawurldecode($try['url'])) ?></code>
          <div class="try-meta small"><?= $e($t['from']) ?> <?= $e($try['from']) ?> · <?= $e($t['expected']) ?>:
            <span class="pill pill-<?= $badge($try['outcome']) ?>"><?= $e($outcomeLabel($try['outcome'])) ?></span><?= $try['by'] !== null ? ' <span class="rule-id">' . $e($try['by']) . '</span>' : '' ?></div>
          <?php if ($mode === 'pass'): ?>
            <p class="small text-secondary mt-2 mb-2"><?= $e($t['passCard']) ?></p>
            <a class="btn btn-sm btn-outline-accent mt-auto" href="/__login" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> <?= $e($t['openLogin']) ?></a>
          <?php else: ?>
            <?php if ($mode === 'server'): ?><p class="small text-secondary mt-2 mb-2"><i class="bi bi-info-circle"></i> <?= $e($t['simulated']) ?></p><?php endif ?>
            <div class="try-result" aria-live="polite"></div>
            <button class="btn btn-sm btn-outline-accent mt-auto try-go"><i class="bi bi-send"></i> <span><?= $e($t['send']) ?></span></button>
          <?php endif ?>
        </div></div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div>
    <?php endforeach ?>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <div class="section-head"><h2><?= $e($t['checkTitle']) ?></h2></div>
    <div class="row g-4">
      <?php foreach ($t['checkSteps'] as $i => [$icon, $title, $text]): ?>
      <div class="col-md-4"><div class="step h-100"><div class="step-n"><?= $i + 1 ?></div><i class="bi <?= $e($icon) ?> step-icon"></i>
        <h3 class="h5"><?= $e($title) ?></h3><p class="mb-0"><?= $e($text) ?></p></div></div>
      <?php endforeach ?>
    </div>
    <div class="text-center mt-4">
      <a class="btn btn-accent btn-lg" href="/__login" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> <?= $e($t['checkTry']) ?></a>
      <p class="small text-secondary mt-3"><?= $e($t['checkNote']) ?></p>
    </div>
  </div>
</section>

<section id="install" class="section">
  <div class="container">
    <div class="section-head"><h2><?= $e($t['installTitle']) ?></h2></div>
    <div class="row g-4">
      <?php $code = ["request-shield.php\nmy-site.rules", "auto_prepend_file = /var/www/request-shield.php", "include @tracking\ninclude @attacks\nlimit requests 120/min challenge-at 60\nrestrict /admin/** to 192.0.2.0/24"]; ?>
      <?php foreach ($t['install'] as $i => [$title, $text]): ?>
      <div class="col-lg-4"><div class="install h-100"><div class="step-n"><?= $i + 1 ?></div><h3 class="h5"><?= $e($title) ?></h3><p><?= $e($text) ?></p>
        <pre class="card-code mb-0"><code><?= $e($code[$i]) ?></code></pre></div></div>
      <?php endforeach ?>
    </div>
    <p class="text-center mt-4"><i class="bi bi-eye"></i> <?= $t['installMonitor'] ?></p>
  </div>
</section>

<footer class="footer"><div class="container text-center small"><?= $e($t['footer']) ?></div></footer>

<script id="showcase-data" type="application/json"><?= json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/showcase.js"></script>
</body>
</html>
