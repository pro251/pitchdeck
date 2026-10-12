<?php

use PitchDeck\Rng;

/*
 * The whole point of a seeded generator here is that a deck can be replayed:
 * only the suggestion and the seed are stored, so if the same pair stopped
 * producing the same slides, every "previous word" on the home page would
 * quietly start lying.
 */
test('the same seed replays the same sequence', function () {
    $a = new Rng(4242);
    $b = new Rng(4242);

    foreach (range(1, 50) as $i) {
        expect($a->below(1000))->toBe($b->below(1000), "draw $i");
    }
});

test('different seeds diverge', function () {
    $a = new Rng('lobster');
    $b = new Rng('trombone');

    $same = 0;

    foreach (range(1, 30) as $i) {
        if ($a->below(1000) === $b->below(1000)) {
            $same++;
        }
    }

    expect($same < 5)->toBeTruthy('two seeds produced near-identical sequences');
});

// A zero state in xorshift stays zero forever, which would hand every deck
// built from that seed the same slide five times.
test('a zero seed still varies', function () {
    $rng = new Rng(0);
    $draws = [];

    foreach (range(1, 20) as $i) {
        $draws[] = $rng->below(100);
    }

    expect(count(array_unique($draws)) > 5)->toBeTruthy('zero seed produced a constant');
});

test('below stays inside its bound', function () {
    $rng = new Rng(7);

    foreach (range(1, 200) as $i) {
        $n = $rng->below(5);
        expect($n >= 0 && $n < 5)->toBeTruthy("got $n for bound 5");
    }
});

// Called with an empty strategy list or a bound of zero, this must return
// something rather than divide by zero mid-show.
test('below survives a bound of zero', function () {
    expect((new Rng(1))->below(0))->toBe(0);
});

test('shuffled keeps every item exactly once', function () {
    $items = range('a', 'j');
    $shuffled = (new Rng(99))->shuffled($items);

    expect($shuffled)->toHaveCount(10);
    sort($shuffled);
    expect($shuffled)->toBe($items);
});

test('pickWeighted respects the weights', function () {
    $rng = new Rng(5);
    $counts = ['heavy' => 0, 'light' => 0];

    foreach (range(1, 400) as $i) {
        $counts[$rng->pickWeighted([['heavy', 9], ['light', 1]])]++;
    }

    expect($counts['heavy'] > $counts['light'] * 3)->toBeTruthy(
        "heavy {$counts['heavy']} vs light {$counts['light']}"
    );
    expect($counts['light'] > 0)->toBeTruthy('a weighted option was never picked at all');
});

// Every weight being zero is a real case: the appeal() scorer can return the
// same floor for every edge.
test('pickWeighted still returns something when all weights are zero', function () {
    expect((new Rng(3))->pickWeighted([['a', 0], ['b', 0]]))->toBeTruthy();
});
