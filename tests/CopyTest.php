<?php

use PitchDeck\Copy;

test('it substitutes both tokens', function () {
    expect(Copy::fill('The {c} changed {s}', 'cheese', 'kazoo'))->toBe('The kazoo changed cheese');
});

/*
 * The suggestion lands mid-sentence as often as it lands at the front, and
 * these are the two ways that went wrong in practice: "The case against
 * Lobster" and "energizer adoption, year on year".
 */
test('it capitalises the line once, wherever the suggestion fell', function () {
    expect(Copy::fill('The case against {s}', 'lobster'))->toBe('The case against lobster');
    expect(Copy::fill('{s}', 'lobster'))->toBe('Lobster');
    expect(Copy::fill('{c} adoption, year on year', '', 'energizer'))->toBe('Energizer adoption, year on year');
});

test('every template has somewhere to put its words', function () {
    foreach ([Copy::TITLES, Copy::ASKS] as $set) {
        foreach ($set as $template) {
            expect(str_contains($template, '{s}') || str_contains($template, '{c}'))
                ->toBeTruthy("no slot in: $template");
        }
    }

    foreach (Copy::STATEMENTS as $template) {
        expect($template)->toContain('{c}', 'a statement with no concept in it');
    }

    foreach (Copy::CHARTS as $template) {
        expect($template)->toContain('{c}', 'a chart title with no concept in it');
    }
});

/*
 * A quote on a screen reads as a claim about the person named. These are
 * attributed to the dead on purpose, and a living name added later would
 * put words in a real person's mouth in front of a paying room.
 */
test('the quote attributions are all historical', function () {
    expect(Copy::VOICES)->notToContain('Elon Musk');
    expect(count(Copy::VOICES) >= 10)->toBeTruthy('too few voices; the same name will repeat within a set');
});

test('no template is left empty', function () {
    $sets = [Copy::TITLES, Copy::SUBTITLES, Copy::STATEMENTS, Copy::SUPPORTS,
        Copy::CHARTS, Copy::VOICES, Copy::CLOSINGS, Copy::ASKS];

    foreach ($sets as $i => $set) {
        expect(count($set) >= 7)->toBeTruthy("set $i is too small to avoid repeats");

        foreach ($set as $line) {
            expect(trim($line) !== '')->toBeTruthy("set $i has a blank line");
        }
    }
});
