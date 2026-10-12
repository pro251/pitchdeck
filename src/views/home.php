<?php use PitchDeck\App; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pitch Deck</title>
<link rel="stylesheet" href="/app.css">
</head>
<body>
<main class="wrap">
  <h1 class="brand">Pitch<span>Deck</span></h1>
  <p class="lede">Someone shouts a word. You get five slides you have never seen, and you have to sell them.</p>

  <form method="post" action="/">
    <input type="text" name="suggestion" placeholder="lobster" autofocus autocomplete="off"
           aria-label="A word, a phrase or a whole sentence" maxlength="300">
    <button type="submit">Make the deck</button>
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
</body>
</html>
