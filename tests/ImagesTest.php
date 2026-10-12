<?php

use PitchDeck\Images;

/*
 * The relevance check is the only thing standing between the deck and a
 * search provider's confidence. Asked for "female mammal", Openverse returns
 * a wolf and Wikipedia redirects to "Female" and hands back a portrait --
 * neither reports a failure, because from their side nothing failed.
 *
 * It is also how concepts with no photograph get dropped without maintaining
 * a list of them by hand.
 */
function images(): Images
{
    return new Images(sys_get_temp_dir().'/pitchdeck-test-images');
}

test('it accepts a title that names the thing', function () {
    foreach ([['kazoo', 'Kazoo'], ['wheelbarrow', 'Smiling man pushes his love in a wheelbarrow'],
        ['shovel', 'An old shovel, Devon']] as [$concept, $title]) {
        expect(callPrivate(images(), 'relevant', [$concept, $title]))->toBeTruthy("$concept / $title");
    }
});

test('it refuses a title that names something else', function () {
    foreach ([['female mammal', 'Female'], ['female mammal', '050414_black_wolf_odfw'],
        ['penny pincher', 'Coins'], ['food cache', 'Food storage']] as [$concept, $title]) {
        expect(callPrivate(images(), 'relevant', [$concept, $title]))->toBeFalsy("$concept / $title");
    }
});

// Half a phrase is not the phrase. Requiring only the head noun is what let
// "female mammal" through on a photograph of a wolf.
test('a phrase needs all of its words, not just the last one', function () {
    expect(callPrivate(images(), 'relevant', ['fire engine', 'A fire in the hills']))->toBeFalsy();
    expect(callPrivate(images(), 'relevant', ['fire engine', 'Engine room']))->toBeFalsy();
    expect(callPrivate(images(), 'relevant', ['fire engine', 'Vintage fire engine, 1930']))->toBeTruthy();
});

test('a plural in the title still counts', function () {
    expect(callPrivate(images(), 'relevant', ['wheelbarrow', 'Three wheelbarrows']))->toBeTruthy();
    expect(callPrivate(images(), 'relevant', ['lorry', 'Lorries at dawn']))->toBeTruthy();
});

test('it ignores case', function () {
    expect(callPrivate(images(), 'relevant', ['kazoo', 'PLAYING THE KAZOO']))->toBeTruthy();
});
