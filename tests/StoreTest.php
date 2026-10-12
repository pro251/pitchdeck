<?php

use PitchDeck\Store;

function store(): Store
{
    // A fresh file per run: these assert ordering and counts, which a shared
    // database would make depend on whatever ran before.
    $path = sys_get_temp_dir().'/pitchdeck-test-'.getmypid().'.sqlite';
    @unlink($path);

    return Store::open($path);
}

test('it keeps what is needed to replay a deck, and nothing else', function () {
    $store = store();
    $id = $store->record('lobster', 'lobster', 777, 7, 4);
    $row = $store->find($id);

    // A deck is a pure function of these four. Storing the slides as well
    // would be two records of the same fact, and the one nobody writes to is
    // the one that gets believed.
    expect($row['suggestion'])->toBe('lobster');
    expect($row['word'])->toBe('lobster');
    expect((int) $row['seed'])->toBe(777);
    expect((int) $row['slides'])->toBe(7);
    expect((int) $row['difficulty'])->toBe(4);
});

/*
 * The sliders arrived after decks had already been stored. A row written
 * before they existed has to keep replaying as what was actually performed,
 * which is the defaults -- not as whatever the sliders happen to be set to
 * now.
 */
test('decks stored before the sliders existed still replay', function () {
    $path = sys_get_temp_dir().'/pitchdeck-old-'.getmypid().'.sqlite';
    @unlink($path);

    // The schema exactly as it was, then a row in it.
    $old = new PDO("sqlite:$path");
    $old->exec('CREATE TABLE deck (id INTEGER PRIMARY KEY, suggestion TEXT NOT NULL,
                word TEXT NOT NULL, seed INTEGER NOT NULL, created_at INTEGER NOT NULL)');
    $old->exec("INSERT INTO deck (suggestion, word, seed, created_at) VALUES ('lobster', 'lobster', 42, 1)");
    $old = null;

    $row = Store::open($path)->find(1);

    expect((int) $row['slides'])->toBe(5);
    expect((int) $row['difficulty'])->toBe(3);
    expect((int) $row['seed'])->toBe(42);
});

test('an unknown id is absent rather than an error', function () {
    expect(store()->find(99999))->toBe(null);
});

test('previous words come back newest first', function () {
    $store = store();

    foreach (['first', 'second', 'third'] as $word) {
        $store->record($word, $word, 1, 5, 3);
    }

    expect(array_column($store->recent(), 'suggestion'))->toBe(['third', 'second', 'first']);
});

test('the list is capped', function () {
    $store = store();

    foreach (range(1, 15) as $i) {
        $store->record("word $i", 'word', $i, 5, 3);
    }

    expect($store->recent(5))->toHaveCount(5);
    expect($store->count())->toBe(15);
});

// The same word shouted twice is two decks, not one: each gets its own seed
// and so its own slides.
test('the same word can be suggested again', function () {
    $store = store();
    $a = $store->record('lobster', 'lobster', 1, 5, 3);
    $b = $store->record('lobster', 'lobster', 2, 5, 3);

    expect($a === $b)->toBeFalsy();
    expect($store->count())->toBe(2);
});
