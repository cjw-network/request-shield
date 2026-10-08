<?php
/**
 * The showcase's page on building rules (proposal 0016): a learning run --
 * started here, live, for this browser -- and the rules checked against it
 * with replay; the commands for a real server, and what comes next.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 * @var bool $learnHere whether this visitor may start a run here (site.php)
 */

declare(strict_types=1);

$lang = showcaseLang();          // ?lang= first, else the browser's (site.php)
$all = require __DIR__ . '/texts.php';
$t = $all[$lang];
$l = $t['learn'];
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$here = 'learn';
$title = $l['title'];
require __DIR__ . '/nav.php';
$sample = '{"t":1791446553,"method":"GET","host":"www.example.org","path":"/contact","query":{},"form":{},"type":null,"decided":"allow","status":200,'
    . "\n" . ' "found":{"forms":[{"action":"/contact/send","method":"POST","fields":{"email":"email","message":"textarea","csrf":"hidden"}}],'
    . "\n" . '          "links":["/news/?page&sort","/about"],"scripts":["/api/v1/messages"],"hosts":["api.example.org"]}}';
$cli = "# record: two bookmarks switch it on and off in your browser\n"
    . "php request-shield.php learn site.rules start --for=2h\n"
    . "php request-shield.php learn site.rules status\n"
    . "php request-shield.php learn site.rules stop\n\n"
    . "# keep the recording as a test (it holds no values)\n"
    . "cp .request-shield/store/learned.jsonl tests/learned.jsonl\n\n"
    . "# in CI, before every deployment: exit 1 when a rule would refuse one of your clicks\n"
    . "php request-shield.php replay site.rules tests/learned.jsonl --junit=build/rules.xml";
$advise = "# advice.rules -- from 1,284 recorded requests\n"
    . "[ADV-PARAMS]  query page int  sort word  q text          # the parameters your pages use, by type\n"
    . "[ADV-STRICT]  monitor query strict                        # anything else: watched first\n"
    . "[ADV-FORMS]   allow POST /contact/send /account/login     # the only forms\n"
    . "[ADV-ORIGIN]  post-origin same                            # sent from your own pages\n"
    . "[ADV-API]     api-path /api/**                            # called by your scripts: answers as JSON";
$client = ['words' => ['on' => $l['on'], 'off' => $l['off'], 'empty' => $l['empty'], 'foundSum' => $l['foundSum'], 'replaySum' => $l['replaySum'], 'lang' => $lang]];
?>
<header id="top" class="hero learn-hero">
  <div class="container">
    <p class="eyebrow"><?= $e($l['eyebrow']) ?></p>
    <h1 class="display-5 fw-bold"><?= $e($l['title']) ?></h1>
    <p class="lead my-4 learn-lead"><?= $e($l['lead']) ?></p>
    <div class="row g-3 mt-2">
      <?php foreach ($l['steps'] as $i => [$icon, $name, $text]): ?>
      <div class="col-sm-6 col-lg-3"><div class="learn-step h-100<?= $i === 3 ? ' learn-soon' : '' ?>">
        <div class="feature-icon"><i class="bi <?= $e($icon) ?>"></i></div>
        <h2 class="h5 mt-2"><span class="learn-n"><?= $i + 1 ?></span> <?= $e($name) ?><?= $i === 3 ? ' <span class="badge text-bg-light">' . $e($l['soon']) . '</span>' : '' ?></h2>
        <p class="mb-0"><?= $e($text) ?></p>
      </div></div>
      <?php endforeach ?>
    </div>
  </div>
</header>

<section id="live" class="section">
  <div class="container">
    <div class="section-head"><h2><?= $e($l['liveTitle']) ?></h2><p class="lead"><?= $e($l['liveLead']) ?></p></div>
    <div class="learn-panel">
      <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <?php if ($learnHere): ?>
        <button type="button" class="btn btn-accent learn-start"><i class="bi bi-record-circle"></i> <?= $e($l['start']) ?></button>
        <button type="button" class="btn btn-soft learn-stop" disabled><i class="bi bi-stop-circle"></i> <?= $e($l['stop']) ?></button>
        <?php endif ?>
        <a class="btn btn-soft" href="/?lang=<?= $lang ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> <?= $e($l['open']) ?></a>
        <button type="button" class="btn btn-dark learn-check ms-lg-auto"><i class="bi bi-check2-circle"></i> <?= $e($l['check']) ?></button>
      </div>
      <p class="learn-state small mb-3" role="status"><?= $e($learnHere ? $l['off'] : $l['only']) ?></p>
      <h3 class="h6 text-uppercase learn-sub"><?= $e($l['recorded']) ?> <span class="learn-count badge text-bg-light"></span></h3>
      <div class="table-responsive">
        <table class="table table-sm learn-table align-middle">
          <thead><tr><?php foreach ($l['cols'] as $c): ?><th scope="col"><?= $e($c) ?></th><?php endforeach ?></tr></thead>
          <tbody class="learn-rows"><tr class="learn-empty"><td colspan="5"><?= $e($l['empty']) ?></td></tr></tbody>
        </table>
      </div>
      <div class="learn-replay mt-4" hidden>
        <h3 class="h5"><?= $e($l['replayTitle']) ?></h3>
        <p class="small text-secondary"><?= $e($l['replayLead']) ?></p>
        <p class="learn-replay-sum fw-bold"></p>
        <ul class="learn-replay-list list-unstyled mb-0"></ul>
      </div>
    </div>
  </div>
</section>

<section id="cli" class="section section-alt">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-6">
        <h2 class="h3"><?= $e($l['cliTitle']) ?></h2>
        <p><?= $e($l['cliLead']) ?></p>
        <div class="code-box"><pre class="card-paper mb-0"><code><?= $e($cli) ?></code></pre></div>
      </div>
      <div class="col-lg-6">
        <h2 class="h3"><?= $e($l['lineTitle']) ?></h2>
        <p><?= $e($l['lineLead']) ?></p>
        <div class="code-box"><pre class="card-paper mb-0"><code><?= $e($sample) ?></code></pre></div>
      </div>
    </div>
  </div>
</section>

<section id="advise" class="section">
  <div class="container">
    <div class="section-head"><h2><?= $e($l['adviseTitle']) ?> <span class="badge text-bg-light align-middle"><?= $e($l['soon']) ?></span></h2><p class="lead"><?= $e($l['adviseLead']) ?></p></div>
    <div class="code-box learn-advise"><pre class="card-paper mb-0"><code><?= $e($advise) ?></code></pre></div>
  </div>
</section>

<footer class="footer"><div class="container text-center small"><?= $e($t['footer']) ?>
  <p class="ai-note mb-0 mt-2"><i class="bi bi-stars" aria-hidden="true"></i> <?= $e($t['aiNote']) ?></p></div></footer>

<script id="learn-data" type="application/json"><?= json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/learn.js?v=<?= (int) @filemtime(__DIR__ . '/assets/learn.js') ?>"></script>
</body>
</html>
