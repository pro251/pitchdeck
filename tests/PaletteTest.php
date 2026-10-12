<?php

use PitchDeck\Palette;
use PitchDeck\Rng;

test('every scheme is legible in its own right', function () {
    foreach (Palette::SCHEMES as $name => $scheme) {
        expect(Palette::contrast($scheme['ink'], $scheme['bg']) >= 7)
            ->toBeTruthy("$name: ink on bg");
        expect(Palette::contrast($scheme['ink'], $scheme['alt']) >= 6)
            ->toBeTruthy("$name: ink on the second ground");
        expect(Palette::contrast($scheme['muted'], $scheme['bg']) >= 3)
            ->toBeTruthy("$name: muted text on bg");
        expect(Palette::contrast($scheme['accent'], $scheme['bg']) >= 3)
            ->toBeTruthy("$name: accent on bg");
    }
});

/*
 * The accent is sometimes the ground -- the title and closing slides wear it.
 * A palette whose accent is mustard wants dark text where one whose accent is
 * oxblood wants light, and guessing from a luminance threshold put white on
 * amber at 2.3:1.
 */
test('it picks text that can be read on any accent', function () {
    foreach (Palette::SCHEMES as $name => $scheme) {
        $on = Palette::readableOn($scheme['accent']);

        expect(Palette::contrast($on, $scheme['accent']) >= 4.2)->toBeTruthy(
            sprintf('%s: #%s on accent #%s is %.1f:1', $name, $on, $scheme['accent'],
                Palette::contrast($on, $scheme['accent']))
        );
    }
});

test('a sampled colour is pushed until it reads', function () {
    // Real tints that came back muddy: a dark sand brown and a near-black
    // navy, both of which vanish against a dark ground untouched.
    foreach (['763E0F', '245367', '1A1A1A', 'FFFFFF', '888888'] as $tint) {
        foreach (['15161A', 'F2F5F1', '0B2545'] as $ground) {
            $fixed = Palette::legible($tint, $ground);

            expect(Palette::contrast($fixed, $ground) >= 3.4)->toBeTruthy(sprintf(
                '#%s on #%s came back #%s at %.1f:1', $tint, $ground, $fixed,
                Palette::contrast($fixed, $ground)
            ));
        }
    }
});

test('it keeps the hue it was given', function () {
    // The point of sampling the photograph is that the slide takes its
    // character from it. A correction that discards the hue has thrown away
    // the only thing it was for.
    $tint = '763E0F';
    [$hue] = Palette::toHsl($tint);
    [$fixedHue] = Palette::toHsl(Palette::legible($tint, '15161A'));

    expect(abs($hue - $fixedHue) < 0.05)->toBeTruthy("hue moved from $hue to $fixedHue");
});

test('hsl survives a round trip', function () {
    foreach (['E2342B', '0F1A2B', 'FFFFFF', '000000', '4CC9F0'] as $hex) {
        [$h, $s, $l] = Palette::toHsl($hex);

        expect(Palette::fromHsl($h, $s, $l))->toBe($hex);
    }
});

// Pure black and pure white have no hue, and the naive formula divides by
// zero on both.
test('the greys do not blow up', function () {
    foreach (['000000', 'FFFFFF', '808080'] as $hex) {
        [$h, $s, $l] = Palette::toHsl($hex);

        expect(is_float($l))->toBeTruthy();
        expect(Palette::legible($hex, '15161A'))->toBeTruthy();
    }
});

test('an unreadable picture yields no tint rather than a bad one', function () {
    expect(Palette::fromImage('/nonexistent.jpg'))->toBe(null);
});

test('it picks a scheme that exists', function () {
    foreach (range(1, 40) as $i) {
        $picked = Palette::pick(new Rng($i * 31));

        expect(array_keys(Palette::SCHEMES))->toContain($picked['name']);
        expect($picked['bg'])->toBe(Palette::SCHEMES[$picked['name']]['bg']);
    }
});
