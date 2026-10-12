<?php

namespace PitchDeck;

/**
 * Something that can find a picture for a concept.
 *
 * Exists so the deck can be built without the network. The shape of a deck --
 * which slide types appear, in what order, with which concepts -- has nothing
 * to do with who took the photograph, and testing it against the real
 * providers means a few hundred rounds of HTTP and a suite that depends on
 * what Wikipedia happens to hold today.
 *
 * Implementations must return only the concepts they could actually
 * illustrate. A concept that comes back without a usable file is worse than
 * one that does not come back at all: the deck would put an empty frame on a
 * screen instead of quietly using the next concept along.
 */
interface Pictures
{
    /**
     * @param  array<int, string>  $concepts
     * @param  Rng|null  $rng  seeds which of several candidate photographs is
     *                        chosen, so a replayed deck shows the same ones
     * @param  int|null  $need  stop once this many have been found; the
     *                          lookups are cheap JSON but the downloads are
     *                          not, and a deck uses far fewer than it asks for
     * @return array<string, array{file: string, title: string, credit: string, source: string}>
     */
    public function find(array $concepts, ?Rng $rng = null, ?int $need = null): array;
}
