<?php

namespace PitchDeck;

/**
 * Five slides a performer has never seen.
 *
 * The deck is pure data -- no HTML, no XML, no file formats. The browser
 * slideshow and the PowerPoint writer are two renderers of the same thing,
 * which is the only way the download can be trusted to match what was on
 * screen during the show.
 */
final class Deck
{
    public const SLIDES = 5;

    /**
     * Ask for more concepts than there are slides.
     *
     * Some drifted words cannot be illustrated -- there is no photograph of
     * "food cache" or "epsilon toxin" -- and which ones is not knowable until
     * the providers have been asked. Over-requesting means the shortfall is
     * absorbed instead of arriving as a blank slide.
     */
    private const OVERSHOOT = 3;

    public function __construct(
        private Drift $drift,
        private Images $images,
    ) {}

    /**
     * Build the deck for a suggestion.
     *
     * @param  string  $suggestion  a word, a phrase or a whole sentence
     * @param  int|null  $seed  pass the stored seed to replay an old deck
     */
    public function build(string $suggestion, ?int $seed = null): array
    {
        $suggestion = trim(preg_replace('/\s+/', ' ', $suggestion) ?? '');
        $seed ??= crc32($suggestion.'|'.random_int(0, PHP_INT_MAX));
        $rng = new Rng($seed);

        $word = $this->drift->seed($suggestion);
        $concepts = $this->drift->drift($word, self::SLIDES + self::OVERSHOOT, $rng);

        // One call, every concept, in parallel. Concepts no provider can
        // illustrate simply do not come back.
        $pictures = $this->images->find($concepts);

        // Keep the drift order, drop what has no picture: the nearest drift
        // is the most arguably-relevant one and belongs early, where the
        // performer is still establishing that this is a real talk.
        $usable = array_values(array_filter($concepts, fn ($c) => isset($pictures[$c])));

        // A long suggestion is reduced to the word the deck is actually
        // about. "My landlord keeps the heating off" set in 60pt as a title
        // is a different and worse joke than the one being played, and
        // "Revisit my landlord keeps the heating off in the new year" is not
        // a sentence. Capitalisation is left to Copy::fill, which knows
        // whether the suggestion landed at the front of the line.
        $inline = $this->inline($suggestion, $word);

        $slides = [];
        $slides[] = $this->titleSlide($inline, $rng);
        $slides[] = $this->statementSlide($inline, $usable, $pictures, 0, $rng);
        $slides[] = $this->chartSlide($usable, 1, $rng);
        $slides[] = $this->quoteSlide($usable, $pictures, 2, $rng);
        $slides[] = $this->closingSlide($inline, $usable, $rng);

        return [
            'suggestion' => $suggestion,
            'seed' => $seed,
            'word' => $word,
            'concepts' => $usable,
            'slides' => $slides,
        ];
    }

    private function titleSlide(string $suggestion, Rng $rng): array
    {
        return [
            'type' => 'title',
            'heading' => Copy::fill($rng->pick(Copy::TITLES), $suggestion),
            'support' => $rng->pick(Copy::SUBTITLES),
        ];
    }

    private function statementSlide(string $suggestion, array $usable, array $pictures, int $n, Rng $rng): array
    {
        $concept = $this->conceptAt($usable, $n, $rng);

        return [
            'type' => 'statement',
            'heading' => Copy::fill($rng->pick(Copy::STATEMENTS), $suggestion, $concept),
            'support' => $rng->pick(Copy::SUPPORTS),
            'image' => $pictures[$concept] ?? null,
        ];
    }

    /**
     * An invented chart.
     *
     * The numbers mean nothing, which is the point: a chart lends a figure
     * the authority of a measurement, and a performer then has to defend a
     * measurement they have never seen. The series always rises -- a deck
     * that shows bad news is a deck nobody has to explain away.
     */
    private function chartSlide(array $usable, int $n, Rng $rng): array
    {
        $concept = $this->conceptAt($usable, $n, $rng);
        $bars = [];
        $value = 8 + $rng->below(20);
        $year = 2021 + $rng->below(3);

        for ($i = 0; $i < 5; $i++) {
            $bars[] = ['label' => (string) ($year + $i), 'value' => $value];
            $value = (int) round($value * (1.2 + $rng->below(90) / 100));
        }

        return [
            'type' => 'chart',
            'heading' => Copy::fill($rng->pick(Copy::CHARTS), '', $concept),
            'support' => $rng->pick(Copy::SUPPORTS),
            'bars' => $bars,
        ];
    }

    /**
     * A dictionary definition, presented as wisdom.
     *
     * WordNet's own gloss read out as a quotation and attributed to Marcus
     * Aurelius is funnier than any invented aphorism, because every word of
     * it is true and none of it is profound. It also needs no quote API --
     * Goodreads, which the original used for this, shut its API in 2020.
     */
    private function quoteSlide(array $usable, array $pictures, int $n, Rng $rng): array
    {
        $concept = $this->conceptAt($usable, $n, $rng);
        $gloss = $this->drift->gloss($concept);

        $quote = $gloss !== null
            ? ucfirst($gloss).'.'
            : 'The '.$concept.' is the beginning of all understanding.';

        return [
            'type' => 'quote',
            'heading' => $quote,
            'support' => $rng->pick(Copy::VOICES),
            'image' => $pictures[$concept] ?? null,
        ];
    }

    private function closingSlide(string $suggestion, array $usable, Rng $rng): array
    {
        $asks = $rng->shuffled(Copy::ASKS);
        $bullets = [];

        foreach (array_slice($asks, 0, 3) as $i => $ask) {
            $bullets[] = Copy::fill($ask, $suggestion, $this->conceptAt($usable, $i, $rng));
        }

        return [
            'type' => 'closing',
            'heading' => $rng->pick(Copy::CLOSINGS),
            'support' => '',
            'bullets' => $bullets,
        ];
    }

    /**
     * The concept for slide n, wrapping if the drift came up short.
     *
     * Wrapping rather than failing: a deck is five slides whatever the
     * dictionary managed to supply, and a repeated concept reads as a motif
     * rather than as a bug.
     */
    private function conceptAt(array $usable, int $n, Rng $rng): string
    {
        if ($usable === []) {
            return 'opportunity';
        }

        return $usable[$n % count($usable)];
    }

    /**
     * What goes on the title slide.
     *
     * A single word is used as shouted. A whole sentence is not -- "my
     * landlord keeps the heating off" set in 60pt as a deck title is a
     * different and worse joke than the one being played -- so a long
     * suggestion is reduced to the word the deck is actually about.
     */
    private function inline(string $suggestion, string $word): string
    {
        $words = preg_split('/\s+/', $suggestion, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtolower(count($words) <= 3 || $word === '' ? $suggestion : $word);
    }
}
