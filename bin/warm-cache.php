<?php

/*
 * Fetch pictures ahead of the show.
 *
 * Brad's constraint, and the reason this exists: "I don't want it going down
 * because of that dependency." Images are the one thing fetched over the
 * network at generation time, and the network in question is the wifi in the
 * back room of a bar.
 *
 * So the pool of plain concrete objects -- what every abstract suggestion
 * gets illustrated with -- is filled in overnight, a few dozen at a time. A
 * word already in the cache costs nothing on the night and works with no
 * network at all. Familiar words first, because those are the ones the drift
 * engine reaches for most.
 *
 * Usage: php bin/warm-cache.php [count]
 */

$root = dirname(__DIR__);

foreach (['Http', 'Images'] as $class) {
    require $root.'/src/'.$class.'.php';
}

use PitchDeck\Images;

$count = max(1, (int) ($argv[1] ?? 60));
$images = new Images($root.'/storage/images');

$db = new PDO("sqlite:$root/data/wordnet.sqlite", options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pool = $db->query('SELECT lemma FROM pool ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

// Skip anything already known, hit or miss: a remembered miss is a result
// too, and re-asking three providers about "epsilon toxin" every night is
// exactly the traffic this is meant to avoid.
$todo = [];

foreach ($pool as $lemma) {
    if (count($todo) >= $count) {
        break;
    }

    if (! is_file($root.'/storage/images/'.sha1(mb_strtolower($lemma)).'.json')) {
        $todo[] = $lemma;
    }
}

if ($todo === []) {
    echo "Nothing to warm: the whole pool is cached.\n";
    exit(0);
}

$started = microtime(true);
$found = 0;

// Small batches with a pause. There is no rate limit at this volume and no
// reason to find out where one is -- the job has all night.
foreach (array_chunk($todo, 10) as $i => $batch) {
    if ($i > 0) {
        sleep(2);
    }

    $found += count($images->find($batch));
}

printf(
    "%d words, %d illustrated, %d with no photograph, %.0fs\n",
    count($todo),
    $found,
    count($todo) - $found,
    microtime(true) - $started
);

$cached = count(glob($root.'/storage/images/*.jpg'));
printf("cache now holds %d pictures (%s)\n", $cached, number_format(array_sum(array_map('filesize', glob($root.'/storage/images/*.jpg'))) / 1048576, 1).' MB');
