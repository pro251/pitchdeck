<?php

/**
 * Front controller. nginx sends everything that is not a real file here.
 */
$root = dirname(__DIR__);

foreach (['Rng', 'Http', 'Drift', 'Images', 'Copy', 'Deck', 'Pptx', 'Store', 'App'] as $class) {
    require $root.'/src/'.$class.'.php';
}

use PitchDeck\{App, Deck, Drift, Images, Store};

$app = new App(
    new Deck(
        Drift::open($root.'/data/wordnet.sqlite'),
        new Images($root.'/storage/images'),
    ),
    Store::open($root.'/data/decks.sqlite'),
    $root,
);

$app->handle($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ?? '/');
