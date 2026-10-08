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
        'SHOW-ADMIN' => 'access', 'SHOW-LOGIN' => 'access', 'SHOW-DENY' => 'access', 'SHOW-RS' => 'access',
        'SHOW-API-POST' => 'api', 'SHOW-API-QUERY' => 'api', 'SHOW-API-PACE' => 'api', 'SHOW-API-WRITE' => 'api'][$section] ?? null;
};
$groups = array_fill_keys(array_keys($t['groups']), []);
foreach ($tries as $try) {
    $g = $groupOf($try['section']);
    if ($g === 'api' && $try['pass']) {
        continue;       // a message with a pass: the JSON form below shows it, step by step
    }
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
    if (!$on || strncmp(ltrim($line), 'expect ', 7) === 0) {
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
$view = $view ?? 'main';         // site.php: main (/), try (/try), exponential (/exponential)
$x = $t['exp'];
$expGroups = $view === 'exponential' ? exponentialGroups(dirname(__DIR__) . '/exponential') : [];
// A taste on the front page: a scanner, an attack, a parameter -- one card each; everything on /try.
$tasteTries = [];
foreach (['SCAN-HIDDEN', 'ATK-SQL-UNION', 'SHOW-STRICT'] as $by) {
    foreach ($tries as $try) {
        if ($try['by'] === $by && !$try['pass'] && $try['headers'] === [] && $try['times'] === 1) {
            $tasteTries[] = $try;
            continue 2;
        }
    }
}
$badge = static fn (string $o): string => in_array($o, ['answered', 'passes', 'uncached'], true) ? 'pass' : ($o === 'check' ? 'check' : 'stop');
$client = [
    'tries' => $tries,
    'words' => ['api' => $t['api'], 'self' => $t['self'], 'outcome' => $t['outcome'], 'say' => $t['say'], 'details' => $t['details'], 'send' => $t['send'], 'sent' => $t['sent'], 'from' => $t['from'], 'tries' => array_map($tr, array_column($tries, 'text', 'n'))],
];
$here = $view;
$title = $view === 'main' ? $t['title'] : $t['pages'][$view][0] . ' — request-shield';
// One card to try: the front page shows a few of them, /try all (showcase.js finds them either way).
$card = static function (array $try) use ($e, $tr, $t, $badge, $outcomeLabel): void {
    $mode = $try['pass'] ? 'pass' : ($try['headers'] !== [] ? 'server' : ($try['times'] > 1 ? 'repeat' : 'live'));
    ?><div class="col-md-6 col-xl-4"><div class="card-try h-100" data-n="<?= $try['n'] ?>" data-mode="<?= $mode ?>" data-times="<?= (int) $try['times'] ?>">
      <div class="try-text"><?= $e($tr($try['text'])) ?></div>
      <code class="try-request"><span class="m"><?= $e($try['method']) ?></span> <?= $e(rawurldecode($try['url'])) ?><?= $try['times'] > 1 ? ' × ' . (int) $try['times'] : '' ?></code>
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
    </div></div><?php
};
// A page that counts for itself (the search, the sign-in): its card -- on /try both, on the front page the sign-in.
$counterCard = static function (string $w) use ($e, $t): void {
    $x = $t['self'];
    ?><div class="card-try counter h-100" data-kind="<?= $w ?>">
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
          </div><?php
};
require __DIR__ . '/nav.php';
?>
<?php if ($view === 'main'): ?>
<header id="top" class="hero">
  <div class="container">
    <div class="row align-items-center g-4 g-lg-5">
      <div class="col-lg-6">
        <p class="eyebrow"><?= $e($t['heroEyebrow']) ?></p>
        <h1 class="display-3 fw-bold"><?= $t['heroTitle'] ?></h1>
        <p class="lead mt-4 mb-2"><?= $e($t['heroLead']) ?></p>
        <p class="hero-exp mb-4"><i class="bi bi-boxes"></i> <?= $e($t['heroExp'][0]) ?> <a href="#exponential"><?= $e($t['heroExp'][1]) ?> <i class="bi bi-arrow-down-short"></i></a></p>
        <div class="d-flex flex-wrap gap-3">
          <a class="btn btn-accent btn-lg" href="#taste"><i class="bi bi-play-fill"></i> <?= $e($t['heroTry']) ?></a>
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

<section id="guard" class="section guard">
  <div class="container">
    <?php $gd = $t['guard']; ?>
    <div class="section-head"><h2><?= $e($gd['title']) ?></h2><p class="lead"><?= $e($gd['lead']) ?></p></div>
    <div class="row g-4">
      <div class="col-lg-6"><div class="guard-card h-100">
        <h3 class="h4"><i class="bi bi-shield-check"></i> <?= $e($gd['checkTitle']) ?></h3>
        <p><?= $e($gd['checkLead']) ?></p>
        <div class="check-demo" aria-hidden="true">
          <svg viewBox="0 0 120 120" width="120" height="120" focusable="false">
            <circle class="cd-track" cx="60" cy="60" r="52"/><circle class="cd-fill" cx="60" cy="60" r="52" transform="rotate(-90 60 60)"/>
            <g class="cd-ok"><circle cx="60" cy="60" r="27"/><path d="M47 61l9 9 17-19"/></g>
          </svg>
          <div class="cd-text"><span class="cd-wait"><?= $e($gd['checkWait']) ?></span><span class="cd-done"><?= $e($gd['checkDone']) ?></span></div>
        </div>
        <ul class="guard-facts list-unstyled">
          <?php foreach ($gd['checkFacts'] as [$icon, $fact]): ?><li><i class="bi <?= $e($icon) ?>"></i> <?= $e($fact) ?></li><?php endforeach ?>
        </ul>
        <a class="btn btn-accent btn-lg" href="/__login" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> <?= $e($t['checkTry']) ?></a>
        <p class="small text-secondary mt-2 mb-0"><?= $e($gd['checkHint']) ?></p>
      </div></div>
      <div class="col-lg-6"><div class="guard-card h-100">
        <h3 class="h4"><i class="bi bi-hourglass-split"></i> <?= $e($gd['slowTitle']) ?></h3>
        <p><?= $e($gd['slowLead']) ?></p>
        <figure class="stairs" aria-label="<?= $e($gd['stairsLabel']) ?>">
          <?php foreach ($gd['stairs'] as $i => [$try, $wait]): ?>
          <div class="stair" style="--h: <?= (int) [8, 8, 8, 14, 26, 50, 100][$i] ?>%"><span class="stair-bar<?= $i < 3 ? ' stair-free' : '' ?>"></span><b><?= $e($wait) ?></b><small><?= $e($try) ?></small></div>
          <?php endforeach ?>
          <figcaption class="small text-secondary"><?= $e($gd['stairsCaption']) ?></figcaption>
        </figure>
        <div class="row"><div class="col-12"><?php $counterCard('login') ?></div></div>
        <p class="small mt-3 mb-0"><a href="/try?lang=<?= $lang ?>#try"><i class="bi bi-rocket-takeoff"></i> <?= $e($gd['burstLink']) ?></a></p>
      </div></div>
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

<section id="taste" class="section">
  <div class="container">
    <div class="section-head"><h2><?= $e($t['tasteTitle']) ?></h2><p class="lead"><?= $e($t['tasteLead']) ?></p></div>
    <div class="row g-3">
      <?php foreach ($tasteTries as $try) { $card($try); } ?>
    </div>
    <div class="text-center mt-4"><a class="btn btn-accent btn-lg" href="/try?lang=<?= $lang ?>"><i class="bi bi-play-fill"></i> <?= $e($t['tasteAll']) ?></a></div>
  </div>
</section>
<?php endif ?>

<?php if ($view === 'try'): ?>
<section id="try" class="section page-top">
  <div class="container">
    <div class="section-head"><h2><?= $e($t['tryTitle']) ?></h2><p class="lead"><?= $e($t['tryLead']) ?></p>
      <button id="try-all" class="btn btn-accent"><i class="bi bi-lightning-charge-fill"></i> <?= $e($t['tryAll']) ?></button></div>
    <?php foreach ($groups as $g => $list): ?>
    <div class="try-group">
      <h3 class="h4"><?= $e($t['groups'][$g][0]) ?></h3><p class="text-secondary"><?= $e($t['groups'][$g][1]) ?></p>
      <?php if ($g === 'self'): ?>
        <div class="row g-4">
          <?php foreach (['search', 'login'] as $w): ?>
          <div class="col-lg-6"><?php $counterCard($w) ?></div>
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
      <?php if ($g === 'api'): $a = $t['api']; ?>
        <div class="row g-4 mb-4">
          <div class="col-lg-7"><div class="card-try api-form h-100">
            <h4 class="h5 mb-1"><i class="bi bi-braces-asterisk"></i> <?= $e($a['formTitle']) ?></h4>
            <p class="text-secondary mb-2"><?= $e($a['formLead']) ?></p>
            <form class="json-form d-grid gap-2" novalidate>
              <input class="form-control" name="name" value="Ada" aria-label="<?= $e($a['name']) ?>" placeholder="<?= $e($a['name']) ?>">
              <textarea class="form-control" name="message" rows="2" aria-label="<?= $e($a['message']) ?>" placeholder="<?= $e($a['message']) ?>">Hallo request-shield!</textarea>
              <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-accent" type="submit"><i class="bi bi-send"></i> <?= $e($a['send']) ?></button>
                <button class="btn btn-sm btn-soft json-bot" type="button"><i class="bi bi-robot"></i> <?= $e($a['bot']) ?></button>
                <button class="btn btn-sm btn-soft json-forget" type="button"><i class="bi bi-eraser"></i> <?= $e($a['forget']) ?></button>
              </div>
            </form>
            <ol class="json-steps mt-3" aria-live="polite"></ol>
          </div></div>
          <div class="col-lg-5"><div class="card-try why h-100">
            <h4 class="h5 mb-2"><i class="bi bi-question-circle"></i> <?= $e($a['whyTitle']) ?></h4>
            <dl class="why-list mb-0">
              <?php foreach ($a['why'] as [$rule, $because]): ?>
              <dt><code><?= $e($rule) ?></code></dt><dd><?= $e($because) ?></dd>
              <?php endforeach ?>
            </dl>
          </div></div>
        </div>
      <?php endif ?>
      <div class="row g-3">
        <?php foreach ($list as $try) { $card($try); } ?>
      </div>
      <?php endif ?>
    </div>
    <?php endforeach ?>
  </div>
</section>


<?php endif ?>

<?php if ($view === 'main'): ?>
<section id="compliance" class="section">
  <div class="container">
    <?php $c = $t['comp'] ?>
    <div class="section-head"><h2><?= $e($c['title']) ?></h2><p class="lead"><?= $e($c['lead']) ?></p></div>
    <div class="row g-4">
      <?php foreach ($c['cards'] as $one): ?>
      <div class="col-lg-6"><article class="comp-card h-100">
        <h3 class="h4 d-flex align-items-center gap-2"><span class="feature-icon mb-0"><i class="bi <?= $e($one['icon']) ?>"></i></span> <?= $e($one['title']) ?></h3>
        <h4 class="comp-sub"><?= $e($c['altcha']) ?></h4>
        <blockquote class="comp-quote"><?= $e($one['altcha']) ?>
          <a class="d-block mt-2 small" href="<?= $e($one['url']) ?>" rel="noopener" target="_blank"><i class="bi bi-box-arrow-up-right"></i> <?= $e($c['read']) ?></a></blockquote>
        <h4 class="comp-sub"><?= $e($c['shield']) ?></h4>
        <ul class="comp-list comp-yes"><?php foreach ($one['shield'] as $li): ?><li><?= $e($li) ?></li><?php endforeach ?></ul>
        <h4 class="comp-sub"><?= $e($c['differs']) ?></h4>
        <ul class="comp-list comp-diff"><?php foreach ($one['differs'] as $li): ?><li><?= $e($li) ?></li><?php endforeach ?></ul>
      </article></div>
      <?php endforeach ?>
    </div>
    <p class="small text-secondary mt-4 mb-0"><?= $e($c['note']) ?></p>
  </div>
</section>

<section id="exponential" class="section section-alt">
  <div class="container">
    <div class="section-head"><h2><?= $e($t['exp']['title']) ?></h2><p class="lead"><?= $e($t['exp']['lead']) ?></p>
      <a class="btn btn-accent" href="/exponential?lang=<?= $lang ?>"><i class="bi bi-boxes"></i> <?= $e($t['expTeaser']) ?></a></div>
  </div>
</section>
<?php endif ?>

<?php if ($view === 'exponential'): ?>
<section id="exponential-rules" class="section page-top">
  <div class="container">
    <div class="section-head"><h2><?= $e($x['title']) ?></h2><p class="lead"><?= $e($x['lead']) ?></p></div>
    <div class="row g-4 mb-4 align-items-start">
      <div class="col-lg-6"><p class="mb-2"><?= $e($x['how']) ?></p>
        <pre class="card-paper mb-2"><code>// config.php, next to index.php: before the kernel and its cache
Shield::protectFile(__DIR__ . '/settings/request-shield/exponential-admin-uri.rules');</code></pre>
        <a class="small" href="https://github.com/cjw-network/request-shield/blob/main/docs/use-cases/exponential.md" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> <?= $e($x['why']) ?></a></div>
      <div class="col-lg-6"><p class="small text-secondary mb-2"><i class="bi bi-info-circle"></i> <?= $e($x['decided']) ?></p>
        <button class="btn btn-accent exp-all" type="button"><i class="bi bi-lightning-charge-fill"></i> <?= $e($x['checkAll']) ?></button>
        <span class="exp-summary ms-2 fw-bold" data-text="<?= $e($x['summary']) ?>"></span></div>
    </div>
    <?php foreach ($expGroups as $gi => $grp): ?>
    <details class="exp-group card-try mb-3"<?= $gi === 0 ? ' open' : '' ?>>
      <summary class="d-flex align-items-center gap-2"><span class="h5 mb-0"><?= $e($x['groups'][$grp['title']] ?? $grp['title']) ?></span>
        <span class="badge text-bg-light"><?= count($grp['tries']) ?></span><span class="exp-group-sum small text-secondary ms-auto"></span></summary>
      <?php if ($grp['rules'] !== []): ?><p class="small text-secondary mt-3 mb-1"><?= $e($x['rules']) ?></p>
      <pre class="card-paper exp-rules mb-3"><code><?= $e(implode("\n", $grp['rules'])) ?></code></pre><?php endif ?>
      <ol class="exp-rows">
        <?php foreach ($grp['tries'] as $ex): ?>
        <li class="exp-row" data-n="<?= (int) $ex['n'] ?>" data-outcome="<?= $e($ex['outcome']) ?>" data-by="<?= $e((string) $ex['by']) ?>">
          <code class="exp-req"><span class="m"><?= $e($ex['method']) ?></span> <?= $e(rawurldecode($ex['url'])) ?><?= $ex['times'] > 1 ? ' × ' . (int) $ex['times'] : '' ?><?= $ex['pass'] ? ' · pass' : '' ?></code>
          <span class="exp-want"><span class="pill pill-<?= $badge($ex['outcome']) ?>"><?= $e($outcomeLabel($ex['outcome'])) ?></span><?= $ex['by'] !== null ? ' <span class="rule-id">' . $e($ex['by']) . '</span>' : '' ?></span>
          <span class="exp-got"></span>
          <button class="btn btn-sm btn-outline-accent exp-go" type="button"><?= $e($x['check']) ?></button>
          <?php if ($ex['text'] !== ''): ?><small class="exp-note"><?= $e($ex['text']) ?></small><?php endif ?>
        </li>
        <?php endforeach ?>
      </ol>
    </details>
    <?php endforeach ?>
  </div>
</section>

<?php endif ?>

<?php if ($view === 'main'): ?>

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

<?php endif ?>

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

<footer class="footer"><div class="container text-center small"><?= $e($t['footer']) ?>
  <p class="ai-note mb-0 mt-2"><i class="bi bi-stars" aria-hidden="true"></i> <?= $e($t['aiNote']) ?></p></div></footer>

<script id="showcase-data" type="application/json"><?= json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/rs-check/widget.js"></script>
<script src="/assets/showcase.js?v=<?= (int) @filemtime(__DIR__ . '/assets/showcase.js') ?>"></script>
</body>
</html>
