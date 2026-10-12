<?php

use PitchDeck\Drift;
use PitchDeck\Rng;

function drift(): Drift
{
    static $drift;

    return $drift ??= Drift::open(dirname(__DIR__).'/data/wordnet.sqlite');
}

test('a single word is its own seed', function () {
    expect(drift()->seed('lobster'))->toBe('lobster');
    expect(drift()->seed('Trombone'))->toBe('trombone');
});

/*
 * The deck is about the most photographable noun in the sentence, not the
 * first word in it. A deck about "my" is a deck about nothing.
 */
test('a sentence yields its concrete noun, not its first word', function () {
    expect(drift()->seed('my landlord keeps the heating off'))->toBe('landlord');
    expect(drift()->seed('the bus was late again'))->toBe('bus');
});

test('plurals find their singular', function () {
    expect(drift()->seed('taxes'))->toBe('tax');
    expect(drift()->seed('lobsters'))->toBe('lobster');
});

test('a known phrase beats its separate words', function () {
    expect(drift()->seed('artificial intelligence'))->toBe('artificial intelligence');
});

test('nonsense yields no seed rather than an error', function () {
    expect(drift()->seed('zxqwerty'))->toBe('');
    expect(drift()->seed(''))->toBe('');
    expect(drift()->seed('!!! ???'))->toBe('');
});

/*
 * "No results" is not an option with an audience watching. Every one of these
 * has to come back with a full set, including the ones the dictionary has
 * never heard of and the ones with almost no edges.
 */
test('it always fills the deck, whatever it is given', function () {
    foreach (['lobster', 'zxqwerty', '', 'loneliness', 'cheese', 'a', 'the the the'] as $input) {
        $seed = drift()->seed($input);
        $concepts = drift()->drift($seed, 8, new Rng($input));

        expect($concepts)->toHaveCount(8, "input: \"$input\"");

        foreach ($concepts as $c) {
            expect(trim($c) !== '')->toBeTruthy("blank concept for \"$input\"");
        }
    }
});

test('it never repeats a concept within one deck', function () {
    foreach (['lobster', 'cheese', 'divorce', 'trombone'] as $input) {
        $concepts = drift()->drift(drift()->seed($input), 8, new Rng($input));

        expect(array_unique($concepts))->toHaveCount(count($concepts), "repeat in \"$input\"");
    }
});

test('it never hands back the word it was given', function () {
    foreach (['lobster', 'cheese', 'trombone'] as $word) {
        expect(drift()->drift($word, 8, new Rng($word)))->notToContain($word);
    }
});

test('the same seed drifts the same way', function () {
    $a = drift()->drift('lobster', 6, new Rng(555));
    $b = drift()->drift('lobster', 6, new Rng(555));

    expect($a)->toBe($b);
});

/*
 * WordNet is a dictionary of the language as it is, slurs included, and the
 * walk reaches them on its own -- an early run returned an archaic slur for a
 * gay man as a slide subject. That is not a joke that misfired, it is the
 * tool failing at its one job in front of a room.
 */
test('it never surfaces a word WordNet marks offensive', function () {
    $db = new PDO('sqlite:'.dirname(__DIR__).'/data/wordnet.sqlite');
    $blocked = $db->query(
        'SELECT DISTINCT w.lemma FROM word w JOIN synset s ON s.offset = w.offset WHERE s.blocked = 1'
    )->fetchAll(PDO::FETCH_COLUMN);

    expect(count($blocked) > 50)->toBeTruthy('the block list is suspiciously empty; did the build run?');
    expect($blocked)->toContain('nance', 'a known slur is no longer being blocked');

    $lookup = array_flip($blocked);

    // A wide sweep rather than one word: the walk is random, so a single
    // drift proves very little.
    foreach (['landlord', 'man', 'woman', 'person', 'foreigner', 'immigrant', 'cheese'] as $word) {
        foreach (range(1, 12) as $run) {
            foreach (drift()->drift($word, 8, new Rng("$word-$run")) as $concept) {
                expect(isset($lookup[mb_strtolower($concept)]))->toBeFalsy(
                    "\"$word\" drifted to the blocked word \"$concept\""
                );
            }
        }
    }
});

/*
 * Below the familiarity floor a word is real, correctly adjacent and unusable
 * twice over: the audience does not recognise it, and no photograph of it
 * exists. "tomalley" scores 3 against "wombat" at 192.
 */
test('every concept clears the familiarity floor', function () {
    $db = new PDO('sqlite:'.dirname(__DIR__).'/data/wordnet.sqlite');
    $freq = $db->prepare('SELECT max(freq) FROM word WHERE lemma = ?');

    foreach (['lobster', 'cheese', 'divorce', 'trombone', 'beach'] as $word) {
        foreach (drift()->drift(drift()->seed($word), 8, new Rng($word)) as $concept) {
            $freq->execute([mb_strtolower($concept)]);
            $score = (int) $freq->fetchColumn();

            expect($score >= 50)->toBeTruthy("\"$concept\" (from $word) scores only $score");
        }
    }
});

test('a gloss comes back as a sentence, not as dictionary apparatus', function () {
    $gloss = drift()->gloss('kazoo');

    expect($gloss)->toBeTruthy();
    expect($gloss)->notToContain(';', 'the usage examples were not trimmed');
    expect($gloss)->notToContain('(', 'the parenthetical notes were not trimmed');
});

test('an unknown word has no gloss rather than a broken one', function () {
    expect(drift()->gloss('zxqwerty'))->toBe(null);
});
