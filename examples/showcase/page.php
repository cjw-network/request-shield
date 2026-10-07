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
    'words' => ['self' => $t['self'], 'outcome' => $t['outcome'], 'say' => $t['say'], 'details' => $t['details'], 'send' => $t['send'], 'sent' => $t['sent'], 'from' => $t['from'], 'tries' => array_map($tr, array_column($tries, 'text', 'n'))],
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

<nav id="nav" class="navbar navbar-expand-lg navbar-light fixed-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="#top"><span class="logo"><i class="bi bi-shield-check"></i></span> request-shield</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu" aria-controls="menu" aria-expanded="false" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="menu">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
        <?php foreach (['what', 'rules', 'try', 'install'] as $k): ?>
        <li class="nav-item"><a class="nav-link" href="#<?= $k ?>"><?= $e($t['nav'][$k]) ?></a></li>
        <?php endforeach ?>
        <li class="nav-item ms-lg-3"><div class="btn-group btn-group-sm" role="group" aria-label="Language">
          <a class="btn <?= $lang === 'de' ? 'btn-dark' : 'btn-outline-dark' ?>" href="/?lang=de" hreflang="de">DE</a>
          <a class="btn <?= $lang === 'en' ? 'btn-dark' : 'btn-outline-dark' ?>" href="/?lang=en" hreflang="en">EN</a>
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
          <a class="btn btn-soft btn-lg" href="#rules"><?= $e($t['heroRules']) ?></a>
        </div>
        <ul class="promise-badges list-unstyled d-flex flex-wrap gap-2 mt-4 mb-0">
          <?php foreach ($t['promises'] as [$icon, $title]): ?>
          <li><a href="#promise"><i class="bi <?= $e($icon) ?>"></i> <?= $e($title) ?></a></li>
          <?php endforeach ?>
        </ul>
      </div>
      <div class="col-lg-6">
        <div class="mascot-row">
          <svg class="mascot" viewBox="0 0 120 130" width="104" height="112" aria-hidden="true" focusable="false">
            <path d="M60 6 L108 24 V62 C108 94 86 116 60 124 C34 116 12 94 12 62 V24 Z" fill="#34d399" stroke="#059669" stroke-width="4" stroke-linejoin="round"/>
            <path d="M60 16 L98 30 V62 C98 88 80 106 60 113 Z" fill="#6ee7b7" opacity=".55"/>
            <circle cx="44" cy="58" r="6" fill="#064e3b"/><circle cx="76" cy="58" r="6" fill="#064e3b"/>
            <circle cx="46" cy="56" r="2" fill="#fff"/><circle cx="78" cy="56" r="2" fill="#fff"/>
            <circle cx="34" cy="74" r="6" fill="#fda4af" opacity=".8"/><circle cx="86" cy="74" r="6" fill="#fda4af" opacity=".8"/>
            <path d="M44 78 Q60 94 76 78" fill="none" stroke="#064e3b" stroke-width="5" stroke-linecap="round"/>
          </svg>
          <div class="bubble"><?= $e($t['mascot']) ?></div>
        </div>
        <div class="stream card-soft" aria-live="polite">
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

<section id="promise" class="promise">
  <div class="container">
    <h2 class="promise-title"><?= $e($t['promiseTitle']) ?></h2>
    <div class="row g-4">
      <?php foreach ($t['promises'] as [$icon, $title, $text]): ?>
      <div class="col-sm-6 col-lg-3"><div class="promise-card h-100">
        <div class="promise-icon"><i class="bi <?= $e($icon) ?>"></i></div>
        <h3 class="h5"><?= $e($title) ?></h3><p class="mb-0"><?= $e($text) ?></p>
      </div></div>
      <?php endforeach ?>
    </div>
  </div>
</section>

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
    <div class="rules card-paper">
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
      <?php if ($g === 'self'): ?>
        <div class="row g-4">
          <?php foreach (['search', 'login'] as $w): $x = $t['self']; ?>
          <div class="col-lg-6"><div class="card-try counter h-100" data-kind="<?= $w ?>">
            <h4 class="h5 mb-1"><i class="bi <?= $w === 'search' ? 'bi-search' : 'bi-key' ?>"></i> <?= $e($x[$w . 'Title']) ?></h4>
            <p class="text-secondary mb-2"><?= $e($x[$w . 'Lead']) ?></p>
            <form class="counter-form d-flex flex-wrap gap-2" novalidate>
              <?php if ($w === 'search'): ?>
                <input class="form-control flex-grow-1" name="q" value="php" aria-label="<?= $e($x['searchPlaceholder']) ?>" placeholder="<?= $e($x['searchPlaceholder']) ?>" style="min-width: 10rem; flex-basis: 10rem">
                <button class="btn btn-accent" type="submit"><i class="bi bi-search"></i> <?= $e($x['searchGo']) ?></button>
              <?php else: ?>
                <input class="form-control" name="user" value="demo" aria-label="<?= $e($x['user']) ?>" style="max-width: 8rem" readonly>
                <input class="form-control flex-grow-1" name="password" type="password" value="falsch" aria-label="<?= $e($x['password']) ?>" style="min-width: 8rem; flex-basis: 8rem">
                <button class="btn btn-accent" type="submit"><i class="bi bi-box-arrow-in-right"></i> <?= $e($x['loginGo']) ?></button>
              <?php endif ?>
            </form>
            <?php if ($w === 'login'): ?><p class="small text-secondary mb-0 mt-1"><i class="bi bi-lightbulb"></i> <?= $e($x['pwHint']) ?></p><?php endif ?>
            <div class="counter-answer mt-2" aria-live="polite"></div>
            <div class="d-flex flex-wrap gap-2 mt-2">
              <button class="btn btn-sm btn-outline-accent bot-go"><i class="bi bi-robot"></i> <span><?= $e($x['botGo']) ?></span></button>
              <button class="btn btn-sm btn-soft new-visitor"><i class="bi bi-person-plus"></i> <?= $e($x['newVisitor']) ?></button>
            </div>
            <div class="timeline mt-3" aria-hidden="true"></div>
            <div class="timeline-legend small mt-1"><span><i class="sq sq-pass"></i> <?= $e($x['legendReached']) ?></span> <span><i class="sq sq-check"></i> <?= $e($x['legendLimit']) ?></span> <span><i class="sq sq-stop"></i> <?= $e($x['legendRefused']) ?></span>
              <span class="visitor text-secondary"></span></div>
            <p class="bot-summary small mt-1 mb-0" aria-live="polite"></p>
          </div></div>
          <?php endforeach ?>
        </div>
      <?php elseif ($g === 'pace'): ?>
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
            <span class="burst-ip text-secondary" data-label="<?= $e($t['burstFrom']) ?>"></span>
          </div>
          <details class="burst-log mt-2"><summary><?= $e($t['burstLog']) ?></summary><ol class="burst-list"></ol></details>
        </div>
      <?php else: ?>
      <div class="row g-3">
        <?php foreach ($list as $try): ?>
        <?php $mode = $try['pass'] ? 'pass' : ($try['headers'] !== [] ? 'server' : 'live'); ?>
        <div class="col-md-6 col-xl-4"><div class="card-try h-100" data-n="<?= $try['n'] ?>" data-mode="<?= $mode ?>">
          <div class="try-text"><?= $e($tr($try['text'])) ?></div>
          <code class="try-request"><span class="m"><?= $e($try['method']) ?></span> <?= $e(rawurldecode($try['url'])) ?></code>
          <div class="try-meta small"><?= $e($t['expected']) ?>:
            <span class="pill pill-<?= $badge($try['outcome']) ?>"><?= $e($outcomeLabel($try['outcome'])) ?></span><?= $try['by'] !== null ? ' <span class="rule-id">' . $e($try['by']) . '</span>' : '' ?></div>
          <?php if ($mode === 'pass'): ?>
            <p class="small text-secondary mt-2 mb-2"><?= $e($t['passCard']) ?></p>
            <a class="btn btn-sm btn-outline-accent mt-auto" href="/__login" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> <?= $e($t['openLogin']) ?></a>
          <?php else: ?>
            <?php if ($mode === 'server'): ?><p class="small text-secondary mt-2 mb-2"><i class="bi bi-info-circle"></i> <?= $e($t['simulated']) ?></p><?php endif ?>
            <div class="try-result" aria-live="polite" data-from="<?= $e($try['from']) ?>"></div>
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
    <?php $code = static fn (string $c): string => '<div class="code-box"><button type="button" class="btn btn-sm btn-soft copy" data-copied="' . $e($t['copied']) . '"><i class="bi bi-clipboard"></i> ' . $e($t['copy']) . '</button><pre class="card-paper mb-0"><code>' . $e($c) . '</code></pre></div>'; ?>
    <div class="install-steps">
      <?php foreach ($t['install'] as $i => [$title, $text, $snippet]): ?>
      <div class="install mb-4"><div class="step-n"><?= $i + 1 ?></div>
        <h3 class="h5"><?= $e($title) ?></h3><p><?= $e($text) ?></p>
        <?php if ($i === 1): ?>
          <ul class="nav nav-pills install-tabs mb-3" role="tablist">
            <?php foreach ($t['installWays'] as $k => [$label]): ?>
            <li class="nav-item" role="presentation"><button class="nav-link<?= $k === 0 ? ' active' : '' ?>" id="way-<?= $k ?>-tab" data-bs-toggle="pill" data-bs-target="#way-<?= $k ?>" type="button" role="tab" aria-controls="way-<?= $k ?>" aria-selected="<?= $k === 0 ? 'true' : 'false' ?>"><?= $e($label) ?></button></li>
            <?php endforeach ?>
          </ul>
          <div class="tab-content">
            <?php foreach ($t['installWays'] as $k => [, $snip, $hint]): ?>
            <div class="tab-pane fade<?= $k === 0 ? ' show active' : '' ?>" id="way-<?= $k ?>" role="tabpanel" aria-labelledby="way-<?= $k ?>-tab" tabindex="0">
              <?= $code($snip) ?><p class="small text-secondary mt-2 mb-0"><i class="bi bi-info-circle"></i> <?= $e($hint) ?></p>
            </div>
            <?php endforeach ?>
          </div>
        <?php else: ?>
          <?= $code($snippet) ?>
          <?php if ($i === 2): ?><div class="mt-3"><?= $code($t['installCheck']) ?></div>
            <p class="mt-4 mb-2 fw-bold"><i class="bi bi-journal-text"></i> <?= $e($t['installLogTitle']) ?></p>
            <pre class="log-box mb-1"><code><?= $e($t['installLog']) ?></code></pre>
            <p class="small text-secondary mb-0"><?= $e($t['installLogLegend']) ?></p>
          <?php endif ?>
        <?php endif ?>
      </div>
      <?php endforeach ?>
    </div>
    <p class="text-center mt-4"><i class="bi bi-eye"></i> <?= $t['installMonitor'] ?></p>
  </div>
</section>

<aside class="log-dock" id="log-dock" aria-label="<?= $e($t['liveLogTitle']) ?>" data-empty="<?= $e($t['liveLogEmpty']) ?>">
  <div class="log-dock-top">
    <button type="button" class="log-dock-head" aria-expanded="true" aria-controls="log-dock-body">
      <span class="live-dot" aria-hidden="true"></span> <i class="bi bi-journal-text"></i> <?= $e($t['liveLogTitle']) ?>
      <span class="log-new" hidden></span><i class="bi bi-chevron-down ms-auto log-toggle" aria-hidden="true"></i>
    </button>
    <button type="button" class="log-dock-wide" aria-pressed="false" title="<?= $e($t['liveLogWide']) ?>" aria-label="<?= $e($t['liveLogWide']) ?>"><i class="bi bi-arrows-angle-expand"></i></button>
  </div>
  <div class="log-dock-body" id="log-dock-body">
    <div class="log-dock-bar">
      <p class="log-dock-lead"><?= $e($t['liveLogLead']) ?> <?= $e($t['liveLogMasked']) ?>.</p>
      <div class="btn-group btn-group-sm log-mode" role="group">
        <button type="button" class="btn btn-light active" data-mode="nice" aria-pressed="true"><?= $e($t['liveLogNice']) ?></button>
        <button type="button" class="btn btn-outline-light" data-mode="raw" aria-pressed="false"><?= $e($t['liveLogRaw']) ?></button>
      </div>
    </div>
    <ol class="log-rows" aria-live="off"><li class="log-empty"><?= $e($t['liveLogEmpty']) ?></li></ol>
  </div>
</aside>

<footer class="footer"><div class="container text-center small"><?= $e($t['footer']) ?></div></footer>

<script id="showcase-data" type="application/json"><?= json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/showcase.js"></script>
</body>
</html>
