<?php

namespace PitchDeck;

/**
 * A seeded generator, so a deck can be replayed.
 *
 * PHP's global mt_rand would do the job, except that seeding it is a global
 * side effect: anything else in the request that wants a random number then
 * silently changes what the deck looks like. A performer who reruns yesterday's
 * word expects yesterday's slides.
 */
final class Rng
{
    private int $state;

    public function __construct(int|string $seed)
    {
        if (is_string($seed)) {
            // crc32 rather than a hash: this needs to be stable across PHP
            // versions, not unpredictable.
            $seed = crc32($seed);
        }

        // A zero state in xorshift stays zero forever.
        $this->state = $seed === 0 ? 0x9e3779b9 : abs($seed);
    }

    /** 0 .. $bound-1 */
    public function below(int $bound): int
    {
        if ($bound < 1) {
            return 0;
        }

        $this->state ^= ($this->state << 13) & 0x7fffffffffffffff;
        $this->state ^= $this->state >> 7;
        $this->state ^= ($this->state << 17) & 0x7fffffffffffffff;

        return $this->state % $bound;
    }

    /** @template T @param array<T> $items @return T */
    public function pick(array $items): mixed
    {
        $values = array_values($items);

        return $values[$this->below(count($values))];
    }

    /**
     * Weighted pick.
     *
     * @param  array<array{0: mixed, 1: int}>  $weighted  [item, weight] pairs
     */
    public function pickWeighted(array $weighted): mixed
    {
        $total = array_sum(array_column($weighted, 1));

        if ($total < 1) {
            return $this->pick(array_column($weighted, 0));
        }

        $roll = $this->below($total);

        foreach ($weighted as [$item, $weight]) {
            $roll -= $weight;

            if ($roll < 0) {
                return $item;
            }
        }

        return $weighted[array_key_last($weighted)][0];
    }

    public function shuffled(array $items): array
    {
        $values = array_values($items);

        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = $this->below($i + 1);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return $values;
    }
}
