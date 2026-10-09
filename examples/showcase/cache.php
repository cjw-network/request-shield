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
    'capHit' => $c['capHit'], 'capMiss' => $c['capMiss'], 'capRefused' => $c['capRefused'], 'capNotKept' => $c['capNotKept'], 'capPurge' => $c['capPurge'], 'capClear' => $c['capClear'],
    'auto' => $c['auto'], 'autoStop' => $c['autoStop'], 'story' => $c['story'], 'tech' => $c['tech'], 'anonymous' => $c['anonymous'], 'dockPin' => $c['dockPin'], 'dockUnpin' => $c['dockUnpin']]];
// The picture: four stations a request passes -- drawn for people who never saw a cache.
// Each in two words: plain (a doorkeeper, a shelf, a kitchen) and technical (an option, for developers).
$x = $c['tech'];
$stations = [[80, '🙂', '🌐', $c['stVisitor'], '', $x['stVisitor'], $x['stVisitorSub']], [300, '🛡️', '🛡️', $c['stShield'], $c['stShieldSub'], $x['stShield'], $x['stShieldSub']],
    [520, '🗄️', '💾', $c['stCache'], $c['stCacheSub'], $x['stCache'], $x['stCacheSub']], [740, '🍳', '🐘', $c['stApp'], $c['stAppSub'], $x['stApp'], $x['stAppSub']]];
$both = static fn (string $plain, string $tech): string => ' data-plain="' . $e($plain) . '" data-tech="' . $e($tech) . '"';
?>
<header id="top" class="hero learn-hero cache-hero">
  <div class="container">
    <h1 class="h3 fw-bold mb-1"><?= $e($c['title']) ?></h1>
    <p class="mb-0 small learn-lead"><?= $e($c['lead']) ?></p>
  </div>
</header>

<section id="picture" class="section cache-stage">
  <div class="container">
    <div class="learn-panel cache-picture">
      <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
        <p class="small text-secondary mb-0 flex-grow-1"><?= $e($c['graphLead']) ?></p>
        <div class="btn-group btn-group-sm cache-view" role="group" aria-label="<?= $e($c['view']) ?>">
          <button type="button" class="btn btn-dark" data-view="plain" aria-pressed="true"><?= $e($c['viewPlain']) ?></button>
          <button type="button" class="btn btn-outline-dark" data-view="tech" aria-pressed="false"><i class="bi bi-code-slash" aria-hidden="true"></i> <?= $e($c['viewTech']) ?></button>
        </div>
      </div>
      <p class="cache-story mb-1" aria-live="polite"></p>
      <div class="row g-3 align-items-start">
      <div class="col-xl-8">
      <svg class="cache-flow" viewBox="0 0 820 150" role="img" aria-labelledby="flow-title">
        <title id="flow-title"><?= $e($c['graphLead']) ?></title>
        <line x1="80" y1="56" x2="740" y2="56" class="flow-line"/>
        <?php foreach ($stations as $i => [$cx, $icon, $iconT, $name, $sub, $nameT, $subT]): ?>
        <g class="flow-station" data-station="<?= $i ?>">
          <circle cx="<?= $cx ?>" cy="56" r="34" class="flow-node"/>
          <text x="<?= $cx ?>" y="67" text-anchor="middle" class="flow-icon" aria-hidden="true"<?= $both($icon, $iconT) ?>><?= $icon ?></text>
          <text x="<?= $cx ?>" y="118" text-anchor="middle" class="flow-name"<?= $both($name, $nameT) ?>><?= $e($name) ?></text>
          <text x="<?= $cx ?>" y="138" text-anchor="middle" class="flow-sub"<?= $both($sub, $subT) ?>><?= $e($sub) ?></text>
        </g>
        <?php endforeach ?>
        <circle class="flow-dot" cx="80" cy="56" r="11"/>
      </svg>
      <p class="flow-caption mb-1" aria-live="polite"></p>
      <p class="flow-detail mb-0" hidden></p>
      </div>
      <div class="col-xl-4">
      <h3 class="h6 text-uppercase learn-sub"<?= $both($c['shelf'], $x['shelf']) ?>><?= $e($c['shelf']) ?></h3>
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
    </div>
  </div>
</section>

<section id="actions" class="cache-dock-home">
  <div class="container">
<aside class="cache-dock" aria-label="<?= $e($c['dock']) ?>">
  <div class="container-fluid">
    <div class="cache-dock-actions d-flex flex-wrap gap-2 align-items-center">
      <button type="button" class="btn btn-accent cache-auto" aria-pressed="false"><i class="bi bi-play-fill" aria-hidden="true"></i> <span><?= $e($c['auto']) ?></span></button>
      <label class="visually-hidden" for="cache-article"><?= $e($c['article']) ?></label>
      <select id="cache-article" class="form-select form-select-sm w-auto cache-article cache-manual">
        <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $e($c['article']) ?> <?= $i ?></option><?php endfor ?>
      </select>
      <button type="button" class="btn btn-sm btn-soft cache-load cache-manual"><i class="bi bi-person-walking" aria-hidden="true"></i> <?= $e($c['load']) ?></button>
      <button type="button" class="btn btn-sm btn-soft cache-member cache-manual" data-member="A"><i class="bi bi-person" aria-hidden="true"></i> <?= $e(sprintf($c['member'], 'A')) ?></button>
      <button type="button" class="btn btn-sm btn-soft cache-member cache-manual" data-member="B"><i class="bi bi-person" aria-hidden="true"></i> <?= $e(sprintf($c['member'], 'B')) ?></button>
      <button type="button" class="btn btn-sm btn-soft cache-member cache-manual" data-member="E"><i class="bi bi-pencil-square" aria-hidden="true"></i> <?= $e($c['editor']) ?></button>
      <button type="button" class="btn btn-sm btn-soft cache-campaign cache-manual"><i class="bi bi-megaphone" aria-hidden="true"></i> <?= $e($c['campaign']) ?></button>
      <button type="button" class="btn btn-sm btn-soft cache-list cache-manual"><i class="bi bi-house" aria-hidden="true"></i> <?= $e($c['list']) ?></button>
      <button type="button" class="btn btn-sm btn-soft cache-scan cache-manual"><i class="bi bi-bug" aria-hidden="true"></i> <?= $e($c['scan']) ?></button>
      <button type="button" class="btn btn-sm btn-warning cache-publish cache-manual"><i class="bi bi-send" aria-hidden="true"></i> <?= $e($c['publish']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-secondary cache-clear cache-manual"><i class="bi bi-trash" aria-hidden="true"></i> <?= $e($c['clear']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-secondary ms-auto cache-dock-pin" aria-pressed="false"><i class="bi bi-pin-angle" aria-hidden="true"></i> <span><?= $e($c['dockPin']) ?></span></button>
      <button type="button" class="btn btn-sm btn-link text-secondary cache-dock-toggle" aria-expanded="true" aria-controls="cache-dock-body" title="<?= $e($c['dockLog']) ?>" aria-label="<?= $e($c['dockLog']) ?>"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
    </div>
    <div class="cache-dock-body" id="cache-dock-body">
      <div class="cache-dock-head d-flex flex-wrap align-items-baseline gap-2">
        <h2 class="h6 mb-0"><i class="bi bi-journal-text" aria-hidden="true"></i> <?= $e($c['logTitle']) ?></h2>
        <p class="cache-sum mb-0" role="status" aria-live="polite"></p>
      </div>
      <div class="cache-dock-log">
        <table class="table table-sm mb-0">
          <thead><tr><?php foreach ($c['cols'] as $col): ?><th scope="col"><?= $e($col) ?></th><?php endforeach ?><?php foreach ($c['colsTech'] as $col): ?><th scope="col" class="tech-col"><?= $e($col) ?></th><?php endforeach ?></tr></thead>
          <tbody class="cache-rows"><tr class="cache-empty"><td colspan="11"><?= $e($c['empty']) ?></td></tr></tbody>
        </table>
      </div>
        <div class="cache-dock-grip" role="separator" aria-orientation="horizontal" aria-controls="cache-dock-body" aria-label="<?= $e($c['dockSize']) ?>" title="<?= $e($c['dockSize']) ?>" tabindex="0"></div>
    </div>
  </div>
</aside>
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

<div class="cache-dock-space" aria-hidden="true"></div>

<script id="cache-data" type="application/json"><?= json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/cache.js?v=<?= (int) @filemtime(__DIR__ . '/assets/cache.js') ?>"></script>
</body>
</html>
