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
    $id = $store->record('lobster', 'lobster', 777);
    $row = $store->find($id);

    expect($row['suggestion'])->toBe('lobster');
    expect($row['word'])->toBe('lobster');
    expect((int) $row['seed'])->toBe(777);
});

test('an unknown id is absent rather than an error', function () {
    expect(store()->find(99999))->toBe(null);
});

test('previous words come back newest first', function () {
    $store = store();

    foreach (['first', 'second', 'third'] as $word) {
        $store->record($word, $word, 1);
    }

    expect(array_column($store->recent(), 'suggestion'))->toBe(['third', 'second', 'first']);
});

test('the list is capped', function () {
    $store = store();

    foreach (range(1, 15) as $i) {
        $store->record("word $i", 'word', $i);
    }

    expect($store->recent(5))->toHaveCount(5);
    expect($store->count())->toBe(15);
});

// The same word shouted twice is two decks, not one: each gets its own seed
// and so its own slides.
test('the same word can be suggested again', function () {
    $store = store();
    $a = $store->record('lobster', 'lobster', 1);
    $b = $store->record('lobster', 'lobster', 2);

    expect($a === $b)->toBeFalsy();
    expect($store->count())->toBe(2);
});
