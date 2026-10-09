<?php
/**
 * The showcase's head and menu, for the front page and the page on building
 * rules ($here: 'main' or 'learn'). Expects $t, $lang, $e.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 * @var array<string, mixed> $t
 * @var string $lang
 * @var callable(string): string $e
 * @var string $here
 * @var string $title
 */

declare(strict_types=1);

$home = $here === 'main' ? '' : '/?lang=' . $lang;
$self = $here === 'main' ? '/' : '/' . $here;
?><!doctype html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($title) ?></title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/showcase.css?v=<?= (int) @filemtime(__DIR__ . '/assets/showcase.css') ?>">
</head>
<body<?= $here === 'main' ? ' data-bs-spy="scroll" data-bs-target="#nav"' : '' ?>>

<nav id="nav" class="navbar navbar-expand-lg navbar-light fixed-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $here === 'main' ? '#top' : $e($home) ?>"><span class="logo"><i class="bi bi-shield-check"></i></span> request-shield</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu" aria-controls="menu" aria-expanded="false" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="menu">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
        <?php foreach (['what', 'rules', 'try', 'compliance', 'exponential', 'install'] as $k): ?>
        <?php $page = in_array($k, ['try', 'exponential'], true); /* a page of its own; the others are parts of the front page */ ?>
        <li class="nav-item"><a class="nav-link<?= $here === $k ? ' active' : '' ?>" href="<?= $page ? '/' . $k . '?lang=' . $lang : $e($home) . '#' . $k ?>"<?= $here === $k ? ' aria-current="page"' : '' ?>><?= $e($t['nav'][$k]) ?></a></li>
        <?php endforeach ?>
        <li class="nav-item"><a class="nav-link<?= $here === 'learn' ? ' active' : '' ?>" href="/learn?lang=<?= $lang ?>"<?= $here === 'learn' ? ' aria-current="page"' : '' ?>><i class="bi bi-magic"></i> <?= $e($t['nav']['learn']) ?></a></li>
        <li class="nav-item"><a class="nav-link<?= $here === 'cache' ? ' active' : '' ?>" href="/cache?lang=<?= $lang ?>"<?= $here === 'cache' ? ' aria-current="page"' : '' ?>><i class="bi bi-lightning-charge"></i> <?= $e($t['nav']['cache']) ?></a></li>
        <li class="nav-item ms-lg-3"><div class="btn-group btn-group-sm" role="group" aria-label="Language">
          <a class="btn <?= $lang === 'de' ? 'btn-dark' : 'btn-outline-dark' ?>" href="<?= $self ?>?lang=de" hreflang="de">DE</a>
          <a class="btn <?= $lang === 'en' ? 'btn-dark' : 'btn-outline-dark' ?>" href="<?= $self ?>?lang=en" hreflang="en">EN</a>
        </div></li>
      </ul>
    </div>
  </div>
</nav>
