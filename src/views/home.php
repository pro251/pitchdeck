<?php

use PitchDeck\App;
use PitchDeck\Deck;
use PitchDeck\Drift;

$levels = Drift::LEVELS;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>PowerPointless</title>
<link rel="stylesheet" href="/app.css">
</head>
<body>
<main class="wrap">
  <h1 class="brand">Power<span>Pointless</span></h1>
  <div class="synopsis">
    <p>Someone in the audience shouts a word.</p>
    <p>A few seconds later there are slides on the screen behind you &mdash; a title, a chart that rises
       for no stated reason, a quotation from somebody long dead, photographs of things that are almost
       but not quite what was shouted. You have not seen them. Nobody has.</p>
    <p>Then you present them, straight-faced, as though you wrote them.</p>
    <p class="synopsis__how">The slides are made by walking the dictionary sideways from the word you
       were given. Ask for a lobster and the deck may be about a wheelbarrow, and explaining why is
       the act. Set how many slides you want and how far it is allowed to wander.</p>
  </div>

  <form method="post" action="/" id="make">
    <div class="ask">
      <input type="text" name="suggestion" placeholder="lobster" autofocus autocomplete="off"
             aria-label="A word, a phrase or a whole sentence" maxlength="300">
      <button type="submit">Make the deck</button>
    </div>

    <div class="dials">
      <label class="dial">
        <span class="dial__head">
          Slides
          <output id="slides-out" for="slides"><?= Deck::SLIDES ?></output>
        </span>
        <input type="range" name="slides" id="slides"
               min="<?= Deck::MIN_SLIDES ?>" max="<?= Deck::MAX_SLIDES ?>" step="1"
               value="<?= Deck::SLIDES ?>">
      </label>

      <label class="dial">
        <span class="dial__head">
          Difficulty
          <output id="difficulty-out" for="difficulty"><?= App::e($levels[Drift::DEFAULT_LEVEL]['name']) ?></output>
        </span>
        <input type="range" name="difficulty" id="difficulty"
               min="1" max="<?= count($levels) ?>" step="1" value="<?= Drift::DEFAULT_LEVEL ?>">
        <span class="dial__note" id="difficulty-note"><?= App::e($levels[Drift::DEFAULT_LEVEL]['blurb']) ?></span>
      </label>
    </div>
  </form>
  <?php if (! empty($error)): ?>
    <p class="error"><?= App::e($error) ?></p>
  <?php endif ?>

  <section class="past">
    <h2><?= empty($recent) ? 'No suggestions yet' : 'Previous words' ?><?= ! empty($total) ? ' · '.$total : '' ?></h2>
    <?php if (empty($recent)): ?>
      <p class="empty">Whatever the audience says goes in the box.</p>
    <?php else: ?>
      <ul class="chips">
        <?php foreach ($recent as $row): ?>
          <li><a href="/d/<?= (int) $row['id'] ?>" title="<?= App::e($row['suggestion']) ?>"><?= App::e($row['suggestion']) ?></a></li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>
</main>

<!--
  Building a deck means a drift walk and, for any word not already cached, a
  round of image lookups over somebody's wifi. That is a quarter of a second
  warm and several seconds cold, and in between the page just sits there --
  so the person at the laptop presses the button again, and the audience
  watches them press it again.

  The bar fills by slide rather than spinning, because that is the thing being
  waited for.
-->
<div class="building" id="building" hidden aria-live="polite">
  <div class="building__inner">
    <div class="building__slides" aria-hidden="true">
      <i></i><i></i><i></i><i></i><i></i>
    </div>
    <p class="building__word" id="building-word"></p>
    <p class="building__step" id="building-step">Looking it up</p>
  </div>
</div>

<script>
/*
 * Difficulty is distance. A concept one hop from the suggestion is obviously
 * about it and the performer can just agree with the slide; one reached by
 * climbing to where lobsters are merely animals has to be argued for, and the
 * arguing is the act. The blurbs come from the engine so the two cannot drift
 * apart.
 */
var LEVELS = <?= json_encode(array_map(fn ($l) => ['name' => $l['name'], 'blurb' => $l['blurb']], $levels)) ?>;

(function () {
  var slides = document.getElementById('slides');
  var difficulty = document.getElementById('difficulty');
  var slidesOut = document.getElementById('slides-out');
  var diffOut = document.getElementById('difficulty-out');
  var diffNote = document.getElementById('difficulty-note');

  function draw() {
    // No stage-time estimate under this one. It was a guess at seconds per
    // slide dressed up as a fact, and how long a deck runs is entirely down
    // to how long the performer wants to argue with each slide.
    slidesOut.textContent = slides.value;

    var level = LEVELS[difficulty.value];
    diffOut.textContent = level.name;
    diffNote.textContent = level.blurb;

    // Fill the track up to the thumb, which range inputs cannot do themselves.
    [slides, difficulty].forEach(function (el) {
      var pct = (el.value - el.min) / (el.max - el.min) * 100;
      el.style.setProperty('--pct', pct + '%');
    });

    // Remembered per browser so the operator is not re-dialling between
    // rounds. Wrapped because a private window throws on access.
    try {
      localStorage.setItem('powerpointless.slides', slides.value);
      localStorage.setItem('powerpointless.difficulty', difficulty.value);
    } catch (e) {}
  }

  try {
    var savedSlides = localStorage.getItem('powerpointless.slides');
    var savedDifficulty = localStorage.getItem('powerpointless.difficulty');
    if (savedSlides) slides.value = savedSlides;
    if (savedDifficulty) difficulty.value = savedDifficulty;
  } catch (e) {}

  slides.addEventListener('input', draw);
  difficulty.addEventListener('input', draw);
  draw();
})();

(function () {
  var form = document.getElementById('make');
  var panel = document.getElementById('building');
  var stepEl = document.getElementById('building-step');
  var wordEl = document.getElementById('building-word');
  var input = form.querySelector('input');

  // The real stages, in the order they actually happen, so the line on screen
  // is a description rather than decoration.
  var steps = [
    'Looking it up',
    'Wandering off the point',
    'Looking for photographs',
    'Writing something confident',
    'Setting the slides'
  ];
  var timer;

  function start(label) {
    if (panel.hidden === false) return;

    wordEl.textContent = label ? '“' + label + '”' : '';
    panel.hidden = false;
    document.body.classList.add('is-building');

    // Disabling the control is the part that stops a second submit; the
    // panel on top of it is what stops the person wondering whether the
    // first one worked.
    input.readOnly = true;
    form.querySelector('button').disabled = true;

    var at = 0;
    timer = setInterval(function () {
      at = Math.min(at + 1, steps.length - 1);
      stepEl.textContent = steps[at];
    }, 1100);
  }

  form.addEventListener('submit', function (e) {
    if (input.value.trim() === '') return;
    start(input.value.trim());
  });

  // A previous word rebuilds its deck from the seed, which is the same work.
  document.querySelectorAll('.chips a').forEach(function (link) {
    link.addEventListener('click', function () { start(link.textContent.trim()); });
  });

  // Coming back via the back button must not leave the panel up over a page
  // that is finished with it.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      clearInterval(timer);
      panel.hidden = true;
      document.body.classList.remove('is-building');
      input.readOnly = false;
      form.querySelector('button').disabled = false;
    }
  });
})();
</script>
</body>
</html>
