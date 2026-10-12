<?php

use PitchDeck\Deck;
use PitchDeck\Drift;
use PitchDeck\Images;

/*
 * These use the real image cache but never the network: everything asked for
 * here has either been warmed or will come back as a remembered miss. The
 * deck must survive both, which is the point.
 */
function deck(): Deck
{
    static $deck;

    return $deck ??= new Deck(
        Drift::open(dirname(__DIR__).'/data/wordnet.sqlite'),
        new Images(dirname(__DIR__).'/storage/images'),
    );
}

test('a deck is always five slides', function () {
    foreach (['lobster', 'zxqwerty', 'loneliness', 'a', 'the bus was late again'] as $input) {
        $built = deck()->build($input, 1234);

        expect($built['slides'])->toHaveCount(Deck::SLIDES, "input: \"$input\"");
    }
});

test('the slides come in the order the game expects', function () {
    $types = array_column(deck()->build('cheese', 42)['slides'], 'type');

    expect($types)->toBe(['title', 'statement', 'chart', 'quote', 'closing']);
});

test('the same seed rebuilds the same deck', function () {
    // This is what makes "previous words" replayable and what makes the
    // downloaded file match what was on the screen: the slides are never
    // stored, only the suggestion and the seed.
    $a = deck()->build('cheese', 9090);
    $b = deck()->build('cheese', 9090);

    expect(json_encode($a))->toBe(json_encode($b));
});

test('every slide has something on it', function () {
    foreach (['lobster', 'zxqwerty', 'divorce'] as $input) {
        foreach (deck()->build($input, 7)['slides'] as $i => $slide) {
            expect(trim((string) $slide['heading']) !== '')->toBeTruthy("blank heading, slide $i of \"$input\"");
        }
    }
});

/*
 * "My landlord keeps the heating off" set in 60pt as a title is a different
 * and worse joke than the one being played, and "Revisit my landlord keeps
 * the heating off in the new year" is not a sentence.
 */
test('a long suggestion is reduced to the word it is about', function () {
    $built = deck()->build('my landlord keeps the heating off because he is cheap', 3);

    expect($built['word'])->toBe('landlord');

    foreach ($built['slides'] as $slide) {
        expect($slide['heading'])->notToContain('keeps the heating off');

        foreach ($slide['bullets'] ?? [] as $bullet) {
            expect($bullet)->notToContain('keeps the heating off');
        }
    }
});

test('a short suggestion is used as it was shouted', function () {
    expect(deck()->build('lobster', 5)['suggestion'])->toBe('lobster');
    expect(deck()->build('  hot   air   balloon ', 5)['suggestion'])->toBe('hot air balloon');
});

test('the chart always rises and is never flat', function () {
    foreach ([1, 2, 3, 99, 12345] as $seed) {
        $bars = deck()->build('cheese', $seed)['slides'][2]['bars'];

        expect($bars)->toHaveCount(5);

        $values = array_column($bars, 'value');

        // A bar of zero renders as nothing at all, which reads as a bug
        // rather than as a joke.
        foreach ($values as $v) {
            expect($v > 0)->toBeTruthy("a zero bar at seed $seed");
        }

        expect(end($values) > $values[0])->toBeTruthy("the chart fell at seed $seed");
    }
});

test('the closing slide asks for exactly three things', function () {
    expect(deck()->build('cheese', 11)['slides'][4]['bullets'])->toHaveCount(3);
});

// A concept with no picture is dropped rather than shown as an empty frame,
// so a slide either has a real image or none.
test('no slide carries a broken image', function () {
    foreach (['lobster', 'cheese', 'loneliness'] as $input) {
        foreach (deck()->build($input, 21)['slides'] as $slide) {
            if (! empty($slide['image'])) {
                expect(is_file($slide['image']['file']))->toBeTruthy(
                    "missing file: {$slide['image']['file']}"
                );
            }
        }
    }
});
