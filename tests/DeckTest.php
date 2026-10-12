<?php

use PitchDeck\Deck;
use PitchDeck\Drift;
use PitchDeck\Palette;
use PitchDeck\Pictures;
use PitchDeck\Rng;

/*
 * A stand-in for the picture providers.
 *
 * The deck tests build a few hundred decks between them, and against the real
 * Images that is a few hundred rounds of network lookups -- slow enough to
 * time the suite out, and dependent on what Wikipedia happens to hold today.
 * What is being tested here is the SHAPE of a deck, which has nothing to do
 * with who took the photograph.
 *
 * It deliberately fails to illustrate some concepts, because that is the real
 * behaviour: there is no photograph of "food cache", and the deck has to
 * absorb the shortfall rather than show a blank slide.
 */
final class StubImages implements Pictures
{
    public function __construct(private string $fixture) {}

    public function find(array $concepts, ?Rng $rng = null, ?int $need = null): array
    {
        $out = [];

        foreach ($concepts as $concept) {
            // Deterministic, so a seeded deck stays reproducible, and roughly
            // three in four, which is about the real hit rate.
            if (crc32($concept) % 4 === 0) {
                continue;
            }

            $out[$concept] = [
                'file' => $this->fixture,
                'title' => ucfirst($concept),
                'credit' => 'Test fixture',
                'source' => '',
                // A colour sampled from the picture, which is what a slide
                // takes its accent from. Varied, and sometimes absent, as
                // real ones are.
                'tint' => crc32($concept) % 5 === 0 ? null : sprintf('%06X', crc32($concept) & 0xFFFFFF),
            ];
        }

        return $out;
    }
}

function deck(): Deck
{
    static $deck;

    if ($deck === null) {
        $fixture = sys_get_temp_dir().'/pitchdeck-fixture.jpg';

        if (! is_file($fixture)) {
            $canvas = imagecreatetruecolor(640, 360);
            imagefilledrectangle($canvas, 0, 0, 640, 360, imagecolorallocate($canvas, 40, 40, 48));
            imagejpeg($canvas, $fixture, 70);
            imagedestroy($canvas);
        }

        $deck = new Deck(
            Drift::open(dirname(__DIR__).'/data/wordnet.sqlite'),
            new StubImages($fixture),
        );
    }

    return $deck;
}

test('a deck is always five slides', function () {
    foreach (['lobster', 'zxqwerty', 'loneliness', 'a', 'the bus was late again'] as $input) {
        $built = deck()->build($input, 1234);

        expect($built['slides'])->toHaveCount(Deck::SLIDES, "input: \"$input\"");
    }
});

test('a deck opens on a title and ends on a closing', function () {
    foreach ([1, 42, 777, 9090, 31337] as $seed) {
        $types = array_column(deck()->build('cheese', $seed)['slides'], 'type');

        expect($types[0])->toBe('title', "seed $seed");
        expect(end($types))->toBe('closing', "seed $seed");
    }
});

/*
 * The complaint that caused this: every deck ran title, statement, chart,
 * quote, closing, so slide three was a graph every time. A room learns that
 * after two rounds, and once the audience knows what is coming the performer
 * has lost the only advantage they had.
 */
test('the shape of the deck changes between decks', function () {
    $shapes = [];

    foreach (range(1, 20) as $i) {
        $slides = deck()->build('cheese', $i * 977)['slides'];
        $shapes[] = implode(',', array_map(fn ($s) => $s['type'].'/'.$s['layout'], $slides));
    }

    expect(count(array_unique($shapes)) >= 12)->toBeTruthy(
        'only '.count(array_unique($shapes)).' distinct shapes in 20 decks'
    );
});

test('no slide type is guaranteed to appear', function () {
    // Specifically the chart, which used to be slide three of everything.
    $withChart = 0;

    foreach (range(1, 20) as $i) {
        if (in_array('chart', array_column(deck()->build('cheese', $i * 977)['slides'], 'type'), true)) {
            $withChart++;
        }
    }

    expect($withChart > 0)->toBeTruthy('the chart never appears at all');
    expect($withChart < 20)->toBeTruthy('every deck still has a chart in it');
});

// Three slides of the same kind is not variety, and the performer gets
// nothing new to work with on the second one.
test('the middle three slides are three different kinds of thing', function () {
    foreach (range(1, 25) as $i) {
        $middle = array_slice(array_column(deck()->build('lobster', $i * 331)['slides'], 'type'), 1, 3);

        expect(array_unique($middle))->toHaveCount(3, 'repeat in: '.implode(',', $middle));
    }
});

test('every slide declares a layout the renderers know', function () {
    $known = [
        'title' => ['flat', 'photo'],
        'statement' => ['right', 'left', 'overlay', 'plain'],
        'chart' => ['bars', 'photo'],
        'quote' => ['photo', 'plain'],
        'big' => ['plain', 'photo'],
        'list' => ['bullets', 'photo'],
        'question' => ['photo', 'plain'],
        'closing' => ['bullets', 'plain', 'photo'],
    ];

    foreach (range(1, 30) as $i) {
        foreach (deck()->build('lobster', $i * 101)['slides'] as $slide) {
            expect($known[$slide['type']] ?? [])->toContain(
                $slide['layout'],
                "{$slide['type']} has unknown layout {$slide['layout']}"
            );
        }
    }
});

// The figure IS the slide; without it there is a caption floating alone.
test('a big-number slide carries a figure and a caption', function () {
    $found = 0;

    foreach (range(1, 30) as $i) {
        foreach (deck()->build('cheese', $i * 59)['slides'] as $slide) {
            if ($slide['type'] !== 'big') {
                continue;
            }

            $found++;
            expect(preg_match('/\d/', $slide['heading']) === 1)->toBeTruthy("no digits in: {$slide['heading']}");
            expect(trim($slide['support']) !== '')->toBeTruthy('a stat with no caption');
            expect(trim((string) ($slide['kicker'] ?? '')) !== '')->toBeTruthy('a stat with no heading');
        }
    }

    expect($found > 0)->toBeTruthy('no big-number slide appeared in 30 decks');
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

    // One content noun from the sentence, not the whole sentence. Which one
    // is the scorer's business, and is asserted in DriftTest.
    expect(['landlord', 'heating'])->toContain($built['word']);

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

test('a chart always rises and is never flat', function () {
    $found = 0;

    // The chart is no longer at a fixed position, and is not in every deck,
    // so this looks for it rather than assuming slide three.
    foreach (range(1, 30) as $i) {
        foreach (deck()->build('cheese', $i * 977)['slides'] as $slide) {
            if ($slide['type'] !== 'chart') {
                continue;
            }

            $found++;
            expect($slide['bars'])->toHaveCount(5);

            $values = array_column($slide['bars'], 'value');

            // A bar of zero renders as nothing at all, which reads as a bug
            // rather than as a joke.
            foreach ($values as $v) {
                expect($v > 0)->toBeTruthy("a zero bar at seed {$i}");
            }

            // A deck that shows bad news is a deck nobody has to explain away.
            expect(end($values) > $values[0])->toBeTruthy("the chart fell at seed {$i}");
        }
    }

    expect($found > 0)->toBeTruthy('no chart appeared in 30 decks');
});

test('a closing either asks for three things or for nothing', function () {
    foreach (range(1, 25) as $i) {
        $closing = end(deck()->build('cheese', $i * 13)['slides']);

        // Sometimes the deck just stops, which is its own kind of difficult
        // to stand next to. What it must never be is one or two bullets.
        expect(in_array(count($closing['bullets']), [0, 3], true))->toBeTruthy(
            'closing had '.count($closing['bullets']).' bullets'
        );
    }
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

/*
 * The complaint that caused all of this: the words drifted, the pictures
 * drifted, and every deck still came out the same dark grey with the same red
 * on it, which made the whole thing look like one template filled in
 * repeatedly.
 */
test('two decks do not look alike', function () {
    $palettes = [];

    foreach (range(1, 30) as $i) {
        $palettes[] = deck()->build('cheese', $i * 977)['palette'];
    }

    expect(count(array_unique($palettes)) >= 6)->toBeTruthy(
        'only '.count(array_unique($palettes)).' palettes across 30 decks'
    );
});

test('slides vary within one deck as well as between decks', function () {
    $varied = 0;

    foreach (range(1, 20) as $i) {
        $grounds = array_column(deck()->build('lobster', $i * 331)['slides'], 'bg');

        if (count(array_unique($grounds)) > 1) {
            $varied++;
        }
    }

    expect($varied >= 18)->toBeTruthy("only $varied of 20 decks used more than one ground");
});

test('every slide carries a resolved set of colours', function () {
    foreach (range(1, 20) as $i) {
        foreach (deck()->build('lobster', $i * 101)['slides'] as $slide) {
            foreach (['bg', 'ink', 'muted', 'accent'] as $role) {
                expect(preg_match('/^[0-9A-F]{6}$/i', (string) ($slide[$role] ?? '')) === 1)
                    ->toBeTruthy("{$slide['type']} has no usable $role: ".var_export($slide[$role] ?? null, true));
            }
        }
    }
});

/*
 * This is projected in a bar with the house lights half up. Text that fails
 * here does not read as a style choice, it reads as a slide that failed to
 * load -- and the performer has to stand next to it either way.
 */
test('text is readable on whatever ground the slide landed on', function () {
    foreach (range(1, 40) as $i) {
        foreach (deck()->build('lobster', $i * 53)['slides'] as $slide) {
            // Over a photograph the scrim decides, not the palette.
            $ground = $slide['behind'] ? '000000' : $slide['bg'];

            expect(Palette::contrast($slide['ink'], $ground) >= 4.5)->toBeTruthy(sprintf(
                '%s: ink #%s on #%s is only %.1f:1',
                $slide['type'], $slide['ink'], $ground, Palette::contrast($slide['ink'], $ground)
            ));

            // The accent is a sampled photograph colour, pushed until it
            // reads. This is the one most likely to come out muddy.
            expect(Palette::contrast($slide['accent'], $ground) >= 3.0)->toBeTruthy(sprintf(
                '%s: accent #%s on #%s is only %.1f:1',
                $slide['type'], $slide['accent'], $ground, Palette::contrast($slide['accent'], $ground)
            ));
        }
    }
});

test('most slides carry a picture', function () {
    $withPicture = 0;
    $total = 0;

    foreach (range(1, 20) as $i) {
        foreach (deck()->build('lobster', $i * 977)['slides'] as $slide) {
            $total++;

            if (! empty($slide['image'])) {
                $withPicture++;
            }
        }
    }

    // The stub refuses to illustrate one concept in four, so this cannot
    // reach 100%. It used to sit near 45%.
    expect($withPicture / $total > 0.6)->toBeTruthy(
        round($withPicture / $total * 100).'% of slides had a picture'
    );
});

// ---- the two sliders ----------------------------------------------------

test('it builds however many slides were asked for', function () {
    foreach (range(Deck::MIN_SLIDES, Deck::MAX_SLIDES) as $n) {
        $built = deck()->build('lobster', 4242, $n);

        expect($built['slides'])->toHaveCount($n, "asked for $n");

        $types = array_column($built['slides'], 'type');
        expect($types[0])->toBe('title', "a $n-slide deck must still open on a title");
        expect(end($types))->toBe('closing', "a $n-slide deck must still end on a closing");
    }
});

/*
 * There are only six kinds of middle slide, so a ten-slide deck has to start
 * reusing them. What it must never do is put two of a kind side by side, or
 * run out and return fewer slides than were asked for.
 */
test('a long deck repeats a slide type but never two in a row', function () {
    foreach (range(1, 20) as $i) {
        $types = array_column(deck()->build('lobster', $i * 977, Deck::MAX_SLIDES)['slides'], 'type');

        expect($types)->toHaveCount(Deck::MAX_SLIDES);

        for ($n = 1; $n < count($types); $n++) {
            expect($types[$n] === $types[$n - 1])->toBeFalsy(
                'two '.$types[$n].' slides in a row at '.$n
            );
        }
    }
});

test('a slide count outside the slider is clamped, not an error', function () {
    expect(deck()->build('lobster', 1, 0)['slides'])->toHaveCount(Deck::MIN_SLIDES);
    expect(deck()->build('lobster', 1, 999)['slides'])->toHaveCount(Deck::MAX_SLIDES);
    expect(deck()->build('lobster', 1, -5)['slides'])->toHaveCount(Deck::MIN_SLIDES);
});

test('the deck reports the difficulty it was built at', function () {
    foreach (array_keys(Drift::LEVELS) as $level) {
        $built = deck()->build('lobster', 7, 5, $level);

        expect($built['difficulty'])->toBe($level);
        expect($built['difficultyName'])->toBe(Drift::LEVELS[$level]['name']);
    }
});

test('a difficulty outside the slider is clamped, not an error', function () {
    expect(deck()->build('lobster', 7, 5, 0)['difficulty'])->toBe(1);
    expect(deck()->build('lobster', 7, 5, 99)['difficulty'])->toBe(count(Drift::LEVELS));
});

// The defaults are what a deck gets when nobody touches a slider, and they
// have to stay what the game was before the sliders existed.
test('the defaults are five slides at the standard drift', function () {
    $built = deck()->build('lobster', 4242);

    expect($built['slides'])->toHaveCount(5);
    expect($built['difficulty'])->toBe(Drift::DEFAULT_LEVEL);
    expect(Deck::SLIDES)->toBe(5);
});

test('the same settings and seed rebuild the same deck', function () {
    // This is what makes a previous word replay as it was performed, now that
    // length and difficulty are part of what a deck is.
    $a = deck()->build('cheese', 909, 7, 4);
    $b = deck()->build('cheese', 909, 7, 4);

    expect(json_encode($a))->toBe(json_encode($b));

    // And that changing either one actually changes the deck.
    expect(json_encode(deck()->build('cheese', 909, 7, 2)))->notToContain('"difficulty":4');
});

/*
 * The deck opens, and very nearly closes, on something plainly about the
 * suggestion, and wanders in between.
 *
 * Brad asked for this and the reason is sound: the room has to believe the
 * deck is about the word before the performer gets any credit for defending
 * the parts that are not. Without the bookends a Hostile deck is five
 * unrelated slides, which is noise rather than a joke.
 */
test('the first slide and the last but one stay near the word', function () {
    foreach ([1, 3, 5] as $level) {
        foreach ([3, 5, 8] as $count) {
            $built = deck()->build('lobster', 4242, $count, $level);
            $slides = $built['slides'];

            expect($slides)->toHaveCount($count);
            expect($built['anchors'])->toBeTruthy("no anchor set at level $level");

            foreach ([0, $count - 2] as $at) {
                $concept = $slides[$at]['concept'] ?? null;

                if ($concept === null) {
                    // The title is sometimes a flat colour with no picture,
                    // and carries no concept at all.
                    continue;
                }

                expect($built['anchors'])->toContain($concept, sprintf(
                    'slide %d of a %d-slide deck at level %d used "%s", which is not an anchor',
                    $at + 1, $count, $level, $concept
                ));
            }
        }
    }
});

// The anchors must not be the same concepts the middle is using, or the
// callback lands on something the room has already watched wander off.
test('the anchor concepts are held back from the middle', function () {
    foreach ([1, 3, 5] as $level) {
        $built = deck()->build('cheese', 909, 7, $level);
        $slides = $built['slides'];
        $last = count($slides) - 1;

        foreach ($slides as $i => $slide) {
            $concept = $slide['concept'] ?? null;

            if ($concept === null || $i === 0 || $i === $last - 1 || $i === $last) {
                continue;
            }

            expect($built['anchors'])->notToContain($concept, sprintf(
                'level %d used the anchor "%s" on middle slide %d', $level, $concept, $i + 1
            ));
        }
    }
});

/*
 * At the hardest setting the middle should genuinely wander. If the anchors
 * were simply applied everywhere, the slider would stop meaning anything.
 */
test('the middle still drifts at hostile even with the anchors in place', function () {
    $built = deck()->build('lobster', 4242, 8, 5);
    $outside = 0;

    foreach (array_slice($built['slides'], 1, -2) as $slide) {
        if (($slide['concept'] ?? null) !== null && ! in_array($slide['concept'], $built['anchors'], true)) {
            $outside++;
        }
    }

    expect($outside >= 3)->toBeTruthy("only $outside middle slides drifted away from the anchors");
});
