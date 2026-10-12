<?php use PitchDeck\App; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= App::e($deck['suggestion']) ?> — PowerPointless</title>
<link rel="stylesheet" href="/app.css">
</head>
<body>
<div class="show">
  <div class="stage">
<?php foreach ($deck['slides'] as $i => $slide):
    $img = App::imageUrl($slide['image'] ?? null);
    $layout = $slide['layout'] ?? 'plain';
    // The photograph sits behind the words on these, so the text needs a
    // scrim over it; elsewhere it is a figure beside them.
    $behind = $img && ! empty($slide['behind']);
    // Colours come resolved from the Deck, not decided here, so this and the
    // PowerPoint writer cannot disagree about what the deck looked like.
    $vars = sprintf('--bg:#%s;--ink:#%s;--muted:#%s;--accent:#%s', $slide['bg'], $slide['ink'], $slide['muted'], $slide['accent']); ?>
    <section class="slide slide--<?= App::e($slide['type']) ?> is-<?= App::e($layout) ?><?= $behind ? ' has-bg' : '' ?>"
             style="<?= App::e($vars) ?>" data-slide="<?= $i ?>"<?= $i ? ' hidden' : '' ?>>
<?php if ($behind): ?>
      <img class="bg" src="<?= $img ?>" alt="<?= App::e($slide['image']['title']) ?>">
<?php endif ?>

<?php if ($slide['type'] === 'title'): ?>
      <div class="words">
        <h1><?= App::e($slide['heading']) ?></h1>
        <div class="kicker"><?= App::e($slide['support']) ?></div>
      </div>

<?php elseif ($slide['type'] === 'statement'): ?>
      <div class="words">
        <h2><?= App::e($slide['heading']) ?></h2>
        <p><?= App::e($slide['support']) ?></p>
      </div>
<?php if ($img && ! $behind): ?>
      <figure><img src="<?= $img ?>" alt="<?= App::e($slide['image']['title']) ?>"></figure>
<?php endif ?>

<?php elseif ($slide['type'] === 'chart'):
    $max = max(array_column($slide['bars'], 'value')) ?: 1; ?>
      <div class="words">
        <h2><?= App::e($slide['heading']) ?></h2>
      </div>
      <div class="plot">
<?php foreach ($slide['bars'] as $b): ?>
        <div class="bar">
          <span class="v"><?= (int) $b['value'] ?></span>
          <i style="height: <?= round($b['value'] / $max * 100, 1) ?>%"></i>
          <span class="l"><?= App::e($b['label']) ?></span>
        </div>
<?php endforeach ?>
      </div>

<?php elseif ($slide['type'] === 'quote'): ?>
      <div class="words">
        <h2>&ldquo;<?= App::e($slide['heading']) ?>&rdquo;</h2>
        <div class="attrib">&mdash; <?= App::e($slide['support']) ?></div>
      </div>

<?php elseif ($slide['type'] === 'big'): ?>
      <div class="words">
        <div class="kicker"><?= App::e($slide['kicker']) ?></div>
        <div class="stat"><?= App::e($slide['heading']) ?></div>
        <p><?= App::e($slide['support']) ?></p>
      </div>

<?php elseif ($slide['type'] === 'question'): ?>
      <div class="words">
        <h2><?= App::e($slide['heading']) ?></h2>
      </div>

<?php else: ?>
      <div class="words">
        <h2><?= App::e($slide['heading']) ?></h2>
<?php if (! empty($slide['bullets'])): ?>
        <ul>
<?php foreach ($slide['bullets'] as $b): ?>
          <li><?= App::e($b) ?></li>
<?php endforeach ?>
        </ul>
<?php endif ?>
      </div>
<?php endif ?>

<?php if ($img): ?>
      <div class="credit"><?= App::e($slide['image']['credit']) ?></div>
<?php endif ?>
    </section>
<?php endforeach ?>
  </div>

  <div class="bar-controls">
    <div class="nav">
      <button type="button" id="prev" aria-label="Previous slide" disabled>&larr;</button>
      <button type="button" id="next" aria-label="Next slide">&rarr;</button>
    </div>
    <span class="counter" id="counter" aria-live="polite">1 / <?= count($deck['slides']) ?></span>
    <span class="said">They said <b><?= App::e($deck['suggestion']) ?></b>
      <em><?= count($deck['slides']) ?> slides · <?= App::e($deck['difficultyName']) ?></em>
    </span>
    <div class="actions">
      <a class="btn btn--quiet" href="/d/<?= (int) $id ?>.pptx">Download .pptx</a>
      <a class="btn" href="/">New word</a>
    </div>
  </div>
</div>

<script>
/*
 * Arrow keys and on-screen buttons both, because the performer may be holding
 * a microphone in one hand and a borrowed clicker in the other -- and a
 * clicker sends PageUp/PageDown, not arrows.
 */
(function () {
  var slides = Array.prototype.slice.call(document.querySelectorAll('.slide'));
  var prev = document.getElementById('prev');
  var next = document.getElementById('next');
  var counter = document.getElementById('counter');
  var at = 0;

  function go(to) {
    at = Math.max(0, Math.min(slides.length - 1, to));
    slides.forEach(function (s, i) { s.hidden = i !== at; });
    prev.disabled = at === 0;
    next.disabled = at === slides.length - 1;
    counter.textContent = (at + 1) + ' / ' + slides.length;
  }

  prev.addEventListener('click', function () { go(at - 1); });
  next.addEventListener('click', function () { go(at + 1); });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') { go(at + 1); e.preventDefault(); }
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { go(at - 1); e.preventDefault(); }
    else if (e.key === 'Home') { go(0); }
    else if (e.key === 'End') { go(slides.length - 1); }
    else if (e.key === 'f') { document.documentElement.requestFullscreen && document.documentElement.requestFullscreen(); }
  });

  go(0);
})();
</script>
</body>
</html>
