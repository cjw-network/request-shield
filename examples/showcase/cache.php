<?php
/**
 * The showcase's tab "Cache": the HTTP cache at work (RSF04-03) -- the
 * magazine (site.php, /magazin/…) is a CMS that takes half a second a page;
 * every button is a real request, timed in the browser, with the cache's
 * answer (X-RS-Cache). Members, publishing (Shield::purge()) and campaign
 * links (cache-ignore, proposal 0048); the times in the statistics.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 * @var bool $learnHere whether this visitor is on this machine (site.php): the dashboard opens only there
 */

declare(strict_types=1);

$lang = showcaseLang();          // ?lang= first, else the browser's (site.php)
$all = require __DIR__ . '/texts.php';
$t = $all[$lang];
$c = $t['cache'];
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$here = 'cache';
$title = $c['title'];
require __DIR__ . '/nav.php';
$rules = "set http-cache on\n"
    . "set http-cache-hosts www.example.org\n"
    . "set http-cache-session-cookie rs-demo-member   # members: one page for all of them\n"
    . "cache-ignore @tracking                         # campaign links: one page for all of them\n"
    . "set stats requests pages times                 # the times, hits and misses apart\n\n"
    . "// in the application, after publishing:\n"
    . "Shield::active()?->purge(['article-3']);\n"
    . "// for a member, while the page is made:\n"
    . "Shield::active()?->cacheContext('member', shared: true);";
$client = ['words' => ['visitor' => $c['visitor'], 'member' => $c['member'], 'editor' => $c['editor'], 'bot' => $c['bot'], 'hit' => $c['hit'], 'miss' => $c['miss'], 'none' => $c['none'],
    'refused' => $c['refused'], 'notKept' => $c['notKept'], 'empty' => $c['empty'], 'sum' => $c['sum'], 'lang' => $lang,
    'capHit' => $c['capHit'], 'capMiss' => $c['capMiss'], 'capRefused' => $c['capRefused'], 'capNotKept' => $c['capNotKept'], 'capPurge' => $c['capPurge'], 'capClear' => $c['capClear']]];
// The picture: four stations a request passes -- drawn for people who never saw a cache.
$stations = [[80, '🙂', $c['stVisitor'], ''], [300, '🛡️', $c['stShield'], $c['stShieldSub']], [520, '🗄️', $c['stCache'], $c['stCacheSub']], [740, '🍳', $c['stApp'], $c['stAppSub']]];
?>
<header id="top" class="hero learn-hero">
  <div class="container">
    <p class="eyebrow"><?= $e($c['eyebrow']) ?></p>
    <h1 class="display-5 fw-bold"><?= $e($c['title']) ?></h1>
    <p class="lead my-4 learn-lead"><?= $e($c['lead']) ?></p>
  </div>
</header>

<section id="picture" class="section">
  <div class="container">
    <div class="section-head"><h2><?= $e($c['graphTitle']) ?></h2><p class="lead"><?= $e($c['graphLead']) ?></p></div>
    <div class="learn-panel cache-picture">
      <svg class="cache-flow" viewBox="0 0 820 190" role="img" aria-labelledby="flow-title">
        <title id="flow-title"><?= $e($c['graphLead']) ?></title>
        <line x1="80" y1="80" x2="740" y2="80" class="flow-line"/>
        <?php foreach ($stations as $i => [$x, $icon, $name, $sub]): ?>
        <g class="flow-station" data-station="<?= $i ?>">
          <circle cx="<?= $x ?>" cy="80" r="38" class="flow-node"/>
          <text x="<?= $x ?>" y="92" text-anchor="middle" class="flow-icon" aria-hidden="true"><?= $icon ?></text>
          <text x="<?= $x ?>" y="144" text-anchor="middle" class="flow-name"><?= $e($name) ?></text>
          <text x="<?= $x ?>" y="164" text-anchor="middle" class="flow-sub"><?= $e($sub) ?></text>
        </g>
        <?php endforeach ?>
        <circle class="flow-dot" cx="80" cy="80" r="11"/>
      </svg>
      <p class="flow-caption mb-3" aria-live="polite"></p>
      <h3 class="h6 text-uppercase learn-sub"><?= $e($c['shelf']) ?></h3>
      <div class="table-responsive">
        <table class="table table-sm cache-shelf align-middle mb-0">
          <thead><tr><th scope="col"></th><th scope="col"><?= $e($c['overview']) ?></th><?php for ($i = 1; $i <= 5; $i++): ?><th scope="col"><?= $i ?></th><?php endfor ?></tr></thead>
          <tbody>
            <?php foreach ($c['roles'] as $role => $name): ?>
            <tr data-role="<?= $e($role) ?>"><th scope="row"><?= $e($name) ?></th><?php for ($i = 0; $i <= 5; $i++): ?><td><span class="slot" data-slot="<?= $i ?>" aria-label="<?= $e($name) ?> <?= $i === 0 ? $e($c['overview']) : $i ?>"></span></td><?php endfor ?></tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<section id="live" class="section section-alt">
  <div class="container">
    <div class="learn-panel">
      <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <label class="visually-hidden" for="cache-article"><?= $e($c['article']) ?></label>
        <select id="cache-article" class="form-select w-auto cache-article">
          <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $e($c['article']) ?> <?= $i ?></option><?php endfor ?>
        </select>
        <button type="button" class="btn btn-accent cache-load"><i class="bi bi-arrow-down-circle" aria-hidden="true"></i> <?= $e($c['load']) ?></button>
        <button type="button" class="btn btn-soft cache-member" data-member="A"><i class="bi bi-person" aria-hidden="true"></i> <?= $e(sprintf($c['member'], 'A')) ?></button>
        <button type="button" class="btn btn-soft cache-member" data-member="B"><i class="bi bi-person" aria-hidden="true"></i> <?= $e(sprintf($c['member'], 'B')) ?></button>
        <button type="button" class="btn btn-soft cache-member" data-member="E"><i class="bi bi-pencil-square" aria-hidden="true"></i> <?= $e($c['editor']) ?></button>
        <button type="button" class="btn btn-soft cache-campaign"><i class="bi bi-megaphone" aria-hidden="true"></i> <?= $e($c['campaign']) ?></button>
        <button type="button" class="btn btn-soft cache-list"><i class="bi bi-house" aria-hidden="true"></i> <?= $e($c['list']) ?></button>
        <button type="button" class="btn btn-soft cache-scan"><i class="bi bi-bug" aria-hidden="true"></i> <?= $e($c['scan']) ?></button>
        <button type="button" class="btn btn-dark cache-publish ms-lg-auto"><i class="bi bi-send" aria-hidden="true"></i> <?= $e($c['publish']) ?></button>
        <button type="button" class="btn btn-outline-dark cache-clear"><i class="bi bi-trash" aria-hidden="true"></i> <?= $e($c['clear']) ?></button>
      </div>
      <p class="cache-sum fw-bold mb-3" role="status" aria-live="polite"></p>
      <div class="table-responsive">
        <table class="table table-sm learn-table align-middle">
          <thead><tr><?php foreach ($c['cols'] as $col): ?><th scope="col"><?= $e($col) ?></th><?php endforeach ?></tr></thead>
          <tbody class="cache-rows"><tr class="cache-empty"><td colspan="4"><?= $e($c['empty']) ?></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<section id="notes" class="section">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-6">
        <h2 class="h3"><?= $e($c['notesTitle']) ?></h2>
        <ul><?php foreach ($c['notes'] as $note): ?><li class="mb-2"><?= $e($note) ?></li><?php endforeach ?></ul>
      </div>
      <div class="col-lg-6">
        <h2 class="h3"><?= $e($c['rulesTitle']) ?></h2>
        <div class="code-box"><pre class="card-paper mb-0"><code><?= $e($rules) ?></code></pre></div>
      </div>
    </div>
  </div>
</section>

<section id="dashboard" class="section section-alt">
  <div class="container">
    <div class="section-head"><h2><?= $e($c['dashTitle']) ?></h2><p class="lead"><?= $e($c['dashLead']) ?></p></div>
    <?php if ($learnHere): ?>
    <a class="btn btn-dark" href="/rs/stats/overview?lang=<?= $lang ?>" target="_blank" rel="noopener"><i class="bi bi-speedometer2" aria-hidden="true"></i> <?= $e($c['dash']) ?></a>
    <?php endif ?>
  </div>
</section>

<footer class="footer"><div class="container text-center small"><?= $e($t['footer']) ?>
  <p class="ai-note mb-0 mt-2"><i class="bi bi-stars" aria-hidden="true"></i> <?= $e($t['aiNote']) ?></p></div></footer>

<script id="cache-data" type="application/json"><?= json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/cache.js?v=<?= (int) @filemtime(__DIR__ . '/assets/cache.js') ?>"></script>
</body>
</html>
