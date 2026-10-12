<?php

namespace PitchDeck;

/**
 * Five slides a performer has never seen.
 *
 * The deck is pure data -- no HTML, no XML, no file formats. The browser
 * slideshow and the PowerPoint writer are two renderers of the same thing,
 * which is the only way the download can be trusted to match what was on
 * screen during the show.
 *
 * The SHAPE of the deck is sampled, not fixed. An early version always ran
 * title, statement, chart, quote, closing in that order, and the room learns
 * that after two rounds: once the audience knows slide three is a graph, the
 * performer has lost the only advantage they had, which is that nobody --
 * including them -- knows what is coming.
 */
final class Deck
{
    public const SLIDES = 5;

    /**
     * What the sliders allow.
     *
     * Three is the fewest that still has a beginning, a middle and an end;
     * past ten the performer is not improvising a pitch any more, they are
     * reading out a document.
     */
    public const MIN_SLIDES = 3;

    public const MAX_SLIDES = 10;

    /**
     * Ask for more concepts than there are slides.
     *
     * Some drifted words cannot be illustrated -- there is no photograph of
     * "food cache" or "epsilon toxin" -- and which ones is not knowable until
     * the providers have been asked. Over-requesting means the shortfall is
     * absorbed instead of arriving as a blank slide. About six of eight
     * survive on average.
     */
    private const OVERSHOOT = 3;

    /**
     * What can appear in the middle of a deck, and how often.
     *
     * Statements carry the most weight because a photograph with a confident
     * caption is the core of the game; everything else is here so that the
     * core is not the only thing. Nothing is guaranteed to appear -- a deck
     * with no chart at all is a good deck.
     */
    private const MIDDLE = [
        ['statement', 6],
        ['chart', 3],
        ['quote', 3],
        ['big', 3],
        ['list', 2],
        ['question', 2],
    ];

    /** Where each concept goes, so one slide does not reuse the last one's. */
    private int $cursor = 0;

    public function __construct(
        private Drift $drift,
        private Pictures $images,
    ) {}

    /**
     * Build the deck for a suggestion.
     *
     * @param  string  $suggestion  a word, a phrase or a whole sentence
     * @param  int|null  $seed  pass the stored seed to replay an old deck
     */
    public function build(
        string $suggestion,
        ?int $seed = null,
        int $slides = self::SLIDES,
        int $difficulty = Drift::DEFAULT_LEVEL,
    ): array {
        $suggestion = trim(preg_replace('/\s+/', ' ', $suggestion) ?? '');
        $slides = max(self::MIN_SLIDES, min(self::MAX_SLIDES, $slides));
        $difficulty = max(1, min(count(Drift::LEVELS), $difficulty));
        $seed ??= crc32($suggestion.'|'.random_int(0, PHP_INT_MAX));
        $rng = new Rng($seed);

        $word = $this->drift->seed($suggestion);
        $concepts = $this->drift->drift($word, $slides + self::OVERSHOOT, $rng, $difficulty);

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

        // A palette per deck, so two decks do not look alike. Everything
        // drifted except the colours, which made the whole thing look like
        // one template filled in repeatedly.
        $palette = Palette::pick($rng);

        $this->cursor = 0;
        $built = [$this->title($inline, $usable, $pictures, $rng)];

        foreach ($this->middleTypes($slides - 2, $rng) as $type) {
            $built[] = $this->slide($type, $inline, $usable, $pictures, $rng);
        }

        $built[] = $this->closing($inline, $usable, $pictures, $rng);

        // Colours are resolved HERE rather than in each renderer, so the
        // browser and the PowerPoint file cannot disagree about what the deck
        // looked like.
        foreach ($built as $i => $slide) {
            $built[$i] = $this->paint($slide, $palette, $rng);
        }

        return [
            'suggestion' => $suggestion,
            'seed' => $seed,
            'word' => $word,
            'palette' => $palette['name'],
            'difficulty' => $difficulty,
            'difficultyName' => Drift::LEVELS[$difficulty]['name'],
            'concepts' => $usable,
            'slides' => $built,
        ];
    }

    /**
     * Give a slide its colours.
     *
     * The ground varies within the deck as well as between decks -- slide
     * four should not be the same colour as slide two -- and a slide showing
     * a photograph takes its accent FROM that photograph, so the visual drift
     * follows the semantic one and a deck about langoustines comes out the
     * colour of a langoustine.
     */
    private function paint(array $slide, array $palette, Rng $rng): array
    {
        $behind = ! empty($slide['image']) && in_array($slide['layout'], ['photo', 'overlay'], true);

        // Title and closing wear the accent most of the time: they are the
        // two slides that are all type, and a flat colour is what makes them
        // read as the bookends of a real deck.
        $ground = in_array($slide['type'], ['title', 'closing'], true)
            ? $rng->pickWeighted([['accent', 5], ['bg', 2], ['alt', 2]])
            : $rng->pickWeighted([['bg', 5], ['alt', 4], ['accent', 1]]);

        $bg = $palette[$ground];
        $ink = $ground === 'accent' ? Palette::readableOn($bg) : $palette['ink'];

        // Over a photograph the scrim decides the text colour, not the
        // palette: the picture is what is actually behind the words.
        if ($behind) {
            $ink = 'FFFFFF';
        }

        $tint = $slide['image']['tint'] ?? null;
        $source = $tint ?: $palette['accent'];

        return [
            ...$slide,
            'bg' => $bg,
            'ink' => $ink,
            'ground' => $ground,
            'behind' => $behind,
            'muted' => $behind || $ground === 'accent'
                ? Palette::fromHsl(...[...array_slice(Palette::toHsl($ink), 0, 2), $ink === 'FFFFFF' ? 0.76 : 0.34])
                : $palette['muted'],
            // Pushed until it reads against whatever it ended up on. A tint
            // sampled from a photograph is whatever that photograph happened
            // to be, and is often nearly the ground colour.
            'accent' => Palette::legible($source, $behind ? '000000' : $bg),
        ];
    }

    /**
     * Choose the middle of the deck.
     *
     * Sampled WITHOUT replacement, so the three middle slides are three
     * different kinds of thing. Merely banning adjacent repeats still allowed
     * question-big-question, and a five-slide deck that asks the room two
     * rhetorical questions has spent its one surprise twice.
     *
     * @return array<int, string>
     */
    private function middleTypes(int $count, Rng $rng): array
    {
        $available = self::MIDDLE;
        $chosen = [];

        for ($i = 0; $i < $count; $i++) {
            // There are only six kinds of middle slide, so a long deck has to
            // start reusing them. Refilling beats widening the pool with
            // filler: what it must never do is run out and return fewer
            // slides than were asked for.
            if ($available === []) {
                $last = end($chosen);
                $available = array_values(array_filter(self::MIDDLE, fn ($t) => $t[0] !== $last));
            }

            $type = $rng->pickWeighted($available);
            $chosen[] = $type;
            $available = array_values(array_filter($available, fn ($t) => $t[0] !== $type));
        }

        return $chosen;
    }

    private function slide(string $type, string $suggestion, array $usable, array $pictures, Rng $rng): array
    {
        return match ($type) {
            'chart' => $this->chart($usable, $pictures, $rng),
            'quote' => $this->quote($usable, $pictures, $rng),
            'big' => $this->big($usable, $pictures, $rng),
            'list' => $this->lessons($usable, $pictures, $rng),
            'question' => $this->question($usable, $pictures, $rng),
            default => $this->statement($suggestion, $usable, $pictures, $rng),
        };
    }

    // ---- slide builders ---------------------------------------------------

    private function title(string $suggestion, array $usable, array $pictures, Rng $rng): array
    {
        // Even the title varies: a flat accent ground most of the time, and
        // occasionally the photograph behind the words, which sets the room
        // up to think the deck is about that instead.
        $layout = $rng->pickWeighted([['flat', 3], ['photo', 4]]);
        $concept = $layout === 'photo' ? $this->next($usable) : null;

        return [
            'type' => 'title',
            'layout' => $concept === null ? 'flat' : 'photo',
            'heading' => Copy::fill($rng->pick(Copy::TITLES), $suggestion),
            'support' => $rng->pick(Copy::SUBTITLES),
            'image' => $concept === null ? null : ($pictures[$concept] ?? null),
        ];
    }

    private function statement(string $suggestion, array $usable, array $pictures, Rng $rng): array
    {
        $concept = $this->next($usable);
        $image = $pictures[$concept] ?? null;

        return [
            'type' => 'statement',
            // Which side the photograph sits on, or the whole slide with the
            // words over it. Without this the game has one picture layout and
            // the deck reads as a template.
            'layout' => $image === null
                ? 'plain'
                : $rng->pickWeighted([['right', 4], ['left', 3], ['overlay', 4]]),
            'heading' => Copy::fill($rng->pick(Copy::STATEMENTS), $suggestion, $concept),
            'support' => $rng->pick(Copy::SUPPORTS),
            'image' => $image,
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
    private function chart(array $usable, array $pictures, Rng $rng): array
    {
        $concept = $this->next($usable);
        $bars = [];
        $value = 8 + $rng->below(20);
        $year = 2021 + $rng->below(3);

        for ($i = 0; $i < 5; $i++) {
            $bars[] = ['label' => (string) ($year + $i), 'value' => $value];
            $value = (int) round($value * (1.2 + $rng->below(90) / 100));
        }

        // A chart over a photograph of the thing being charted is the same
        // joke twice, which is the right number of times.
        $image = $pictures[$concept] ?? null;

        return [
            'type' => 'chart',
            'layout' => $image === null ? 'bars' : $rng->pickWeighted([['bars', 3], ['photo', 2]]),
            'heading' => Copy::fill($rng->pick(Copy::CHARTS), '', $concept),
            'support' => $rng->pick(Copy::SUPPORTS),
            'bars' => $bars,
            'image' => $image,
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
    private function quote(array $usable, array $pictures, Rng $rng): array
    {
        $concept = $this->next($usable);
        $gloss = $this->drift->gloss($concept);
        $image = $pictures[$concept] ?? null;

        return [
            'type' => 'quote',
            'layout' => $image === null ? 'plain' : $rng->pickWeighted([['photo', 6], ['plain', 1]]),
            'heading' => $gloss !== null
                ? ucfirst($gloss).'.'
                : 'The '.$concept.' is the beginning of all understanding.',
            'support' => $rng->pick(Copy::VOICES),
            'image' => $image,
        ];
    }

    /** One enormous figure, which is how a real deck hides a weak argument. */
    private function big(array $usable, array $pictures, Rng $rng): array
    {
        $concept = $this->next($usable);

        $stat = $rng->pickWeighted([
            [($rng->below(80) + 15).'%', 4],
            [number_format(($rng->below(90) + 11) / 10, 1).'x', 3],
            ['$'.number_format(($rng->below(90) + 11) / 10, 1).'M', 2],
            [number_format(($rng->below(900) + 100) * 1000), 2],
        ]);

        return [
            'type' => 'big',
            'layout' => ($pictures[$concept] ?? null) === null ? 'plain' : $rng->pickWeighted([['plain', 2], ['photo', 3]]),
            'heading' => $stat,
            'support' => Copy::fill($rng->pick(Copy::STATS), '', $concept),
            'kicker' => $rng->pick(Copy::STAT_HEADS),
            'image' => $pictures[$concept] ?? null,
        ];
    }

    private function lessons(array $usable, array $pictures, Rng $rng): array
    {
        $lines = $rng->shuffled(Copy::LESSONS);
        $bullets = [];

        foreach (array_slice($lines, 0, 3) as $line) {
            $bullets[] = Copy::fill($line, '', $this->next($usable));
        }

        $image = $pictures[$this->peek($usable)] ?? null;

        return [
            'type' => 'list',
            'layout' => $image === null ? 'bullets' : $rng->pickWeighted([['bullets', 3], ['photo', 2]]),
            'image' => $image,
            'heading' => Copy::fill($rng->pick(Copy::LESSON_HEADS), '', $this->peek($usable)),
            'support' => '',
            'bullets' => $bullets,
        ];
    }

    private function question(array $usable, array $pictures, Rng $rng): array
    {
        $concept = $this->next($usable);

        return [
            'type' => 'question',
            'layout' => ($pictures[$concept] ?? null) === null ? 'plain' : $rng->pickWeighted([['photo', 4], ['plain', 1]]),
            'heading' => Copy::fill($rng->pick(Copy::QUESTIONS), '', $concept),
            'support' => '',
            'image' => $pictures[$concept] ?? null,
        ];
    }

    private function closing(string $suggestion, array $usable, array $pictures, Rng $rng): array
    {
        $image = $pictures[$this->peek($usable)] ?? null;
        $layout = $rng->pickWeighted([['bullets', 4], ['plain', 2], ...($image ? [['photo', 3]] : [])]);

        if ($layout === 'plain') {
            // Sometimes the deck just stops, which is its own kind of
            // difficult to stand next to.
            return [
                'type' => 'closing',
                'layout' => 'plain',
                'heading' => $rng->pick(Copy::CLOSINGS),
                'support' => '',
                'bullets' => [],
                'image' => null,
            ];
        }

        $asks = $rng->shuffled(Copy::ASKS);
        $bullets = [];

        foreach (array_slice($asks, 0, 3) as $ask) {
            $bullets[] = Copy::fill($ask, $suggestion, $this->next($usable));
        }

        return [
            'type' => 'closing',
            'layout' => $layout,
            'heading' => $rng->pick(Copy::CLOSINGS),
            'support' => '',
            'bullets' => $bullets,
            'image' => $layout === 'photo' ? $image : null,
        ];
    }

    // ---- concepts ---------------------------------------------------------

    /**
     * The next concept, wrapping if the drift came up short.
     *
     * Wrapping rather than failing: a deck is five slides whatever the
     * dictionary managed to supply, and a repeated concept reads as a motif
     * rather than as a bug.
     */
    private function next(array $usable): string
    {
        if ($usable === []) {
            return 'opportunity';
        }

        return $usable[$this->cursor++ % count($usable)];
    }

    /** The next concept without consuming it. */
    private function peek(array $usable): string
    {
        return $usable === [] ? 'opportunity' : $usable[$this->cursor % count($usable)];
    }

    private function inline(string $suggestion, string $word): string
    {
        $words = preg_split('/\s+/', $suggestion, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtolower(count($words) <= 3 || $word === '' ? $suggestion : $word);
    }
}
