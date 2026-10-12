<?php

namespace PitchDeck;

/**
 * The words on the slides.
 *
 * The comedy is not in the copy. It is in a performer having to justify a
 * photograph of a wheelbarrow to a room that was promised a talk about
 * lobsters. So the copy's job is to be perfectly straight: the confident,
 * slightly empty register of a real conference deck, asserting things it does
 * not explain and leaving the explaining to whoever is holding the clicker.
 *
 * Every line here is a fill-in-the-blank. There is no language model behind
 * this, the same as the original -- a model would write something cleverer
 * and get in the performer's way, and it would be one more thing to be down
 * in the middle of a show.
 */
final class Copy
{
    /** Deck titles. {s} is the audience's suggestion. */
    public const TITLES = [
        '{s}',
        'Rethinking {s}',
        'The future of {s}',
        '{s}: a candid assessment',
        'Why {s} matters now',
        'Beyond {s}',
        '{s}, revisited',
        'The {s} opportunity',
        'What we learned about {s}',
        'The case against {s}',
    ];

    public const SUBTITLES = [
        'Internal use only',
        'Q3 strategy review',
        'Prepared for the board',
        'Draft — please do not circulate',
        'Findings and recommendations',
        'Year one',
        'A working document',
        'Confidential',
        'Second edition, revised',
    ];

    /**
     * Headings for an image slide. {c} is the drifted concept.
     *
     * Each one asserts a relationship between the suggestion and something
     * that is plainly not it, without ever saying what the relationship is.
     * That gap is the performer's to fill, and filling it is the game.
     */
    public const STATEMENTS = [
        'It starts with the {c}',
        'Nobody was talking about the {c}',
        'The {c} problem',
        'We kept coming back to the {c}',
        'Consider the {c}',
        'This is a {c}',
        'Every great idea begins with a {c}',
        'The {c} changed everything',
        'What the data said about the {c}',
        'You already own a {c}',
        'The competition ignored the {c}',
        'Our first prototype was a {c}',
        'Ask yourself: where is your {c}?',
        'The {c} is not the enemy',
        'Six months on the {c}',
    ];

    /** Smaller text under a statement, in the register of a real deck. */
    public const SUPPORTS = [
        'The implications are significant.',
        'We were as surprised as you are.',
        'This is the part people push back on.',
        'Industry-wide, this is still unsolved.',
        'It is early, but the signal is there.',
        'We have been saying this for years.',
        'The numbers bear this out.',
        'Happy to go deeper on this one.',
        'This is where it gets interesting.',
        'No one else is doing this.',
    ];

    /** Chart titles. {c} is a concept. */
    public const CHARTS = [
        '{c} adoption, year on year',
        'Where the {c} spend goes',
        '{c}: projected growth',
        'Why {c} outperforms',
        'Time spent per {c}',
        '{c} satisfaction by segment',
        'The {c} gap',
    ];

    /**
     * Attributions for the quote slide.
     *
     * Dead and famous on purpose. A living person has not said this, and a
     * room reads a quote on a screen as a claim about them.
     */
    public const VOICES = [
        'Marcus Aurelius', 'Florence Nightingale', 'Sun Tzu', 'Ada Lovelace',
        'Leonardo da Vinci', 'Harriet Tubman', 'Niccolò Machiavelli',
        'Marie Curie', 'Confucius', 'Grace Hopper', 'Socrates',
        'Mary Shelley', 'Benjamin Franklin', 'Ching Shih', 'Hypatia',
    ];

    /** Closing headings. */
    public const CLOSINGS = [
        'Where we go from here',
        'In conclusion',
        'What we are asking for',
        'Next steps',
        'Thank you',
        'Questions?',
        'The ask',
    ];

    /** Closing bullets. {c} is a concept, {s} the suggestion. */
    public const ASKS = [
        'Commit to the {c}',
        'Revisit {s} in the new year',
        'Double the {c} budget',
        'Stop pretending the {c} is optional',
        'Hire somebody who understands {c}',
        'Begin the {c} pilot in March',
        'Take the {c} seriously',
        'One more look at {s}',
    ];

    /**
     * Fill a template.
     *
     * Both the suggestion and the concept arrive lowercase, because they land
     * mid-sentence as often as they land at the front -- "The case against
     * Lobster" and "energizer adoption, year on year" are the two ways of
     * getting this wrong, and they are the same mistake. The line is
     * capitalised once, at the end, whatever happened to start it.
     */
    public static function fill(string $template, string $suggestion, string $concept = ''): string
    {
        $filled = str_replace(['{s}', '{c}'], [$suggestion, $concept], $template);

        return mb_strtoupper(mb_substr($filled, 0, 1)).mb_substr($filled, 1);
    }
}
