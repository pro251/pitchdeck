<?php

namespace PitchDeck;

use PDO;

/**
 * Semantic drift, locally.
 *
 * Give it a word and it walks off sideways through WordNet until it reaches
 * something adjacent enough to be arguably relevant and wrong enough to be
 * funny. This is the whole engine of the game: the performer is defending a
 * lobster and the slide shows a claw hammer.
 *
 * It never calls a network service. ConceptNet's API -- what the original
 * talk-generator used -- was answering 502 when this was written, there is no
 * second provider for it, and a drift engine that can be down is a show that
 * can be cancelled.
 */
final class Drift
{
    /**
     * WordNet's semantic fields that contain things you can photograph.
     *
     * animal, artifact, body, food, location, object, person, plant,
     * substance. A drift that lands in noun.cognition or noun.state is
     * correct and useless: there is no photograph of "ambivalence", so the
     * image pass falls through every provider and the slide arrives blank.
     */
    private const CONCRETE = [5, 6, 8, 13, 15, 17, 18, 20, 27];

    /**
     * The familiarity floor, matching bin/build-wordnet.php.
     *
     * Below this a word is real, correctly adjacent, and unusable twice over:
     * the audience does not recognise it, so there is no joke to get, and no
     * photographer has ever taken a picture of one, so the slide arrives
     * blank. "tomalley" scores 3; "wombat" scores 192.
     */
    private const MIN_FREQ = 50;

    /**
     * Words to ignore when picking a seed out of a sentence.
     *
     * Deliberately short. The scoring below handles most of the work -- a
     * stopword list long enough to matter is a list long enough to throw away
     * the one concrete noun in the sentence.
     */
    private const SKIP = [
        'a', 'an', 'the', 'and', 'or', 'but', 'if', 'of', 'at', 'by', 'for',
        'with', 'about', 'into', 'to', 'from', 'in', 'on', 'off', 'out', 'up',
        'down', 'over', 'under', 'is', 'are', 'was', 'were', 'be', 'been',
        'being', 'am', 'do', 'does', 'did', 'have', 'has', 'had', 'will',
        'would', 'can', 'could', 'should', 'my', 'your', 'his', 'her', 'its',
        'our', 'their', 'this', 'that', 'these', 'those', 'i', 'you', 'he',
        'she', 'it', 'we', 'they', 'me', 'him', 'us', 'them', 'not', 'no',
        'so', 'than', 'then', 'too', 'very', 'just', 'keeps', 'keep', 'get',
        'got', 'make', 'made', 'thing', 'things', 'stuff', 'way',
    ];

    /**
     * How far past the concrete requirement to keep trying.
     *
     * A multiple of the slide count rather than a flat number, because a
     * word in a sparse corner of the graph fails most of its attempts and
     * still has to fill the same deck.
     */
    private const BUDGET = 12;

    /**
     * Above this many kinds, a synset is a heading rather than a thing.
     *
     * "mammal" has hundreds; "kazoo" has none. Twelve lets through a thing
     * that happens to come in varieties -- a whistle, a hammer -- while
     * stopping the walk handing back a chapter title.
     */
    private const CATEGORY = 12;

    /** How far past the chosen depth the descent may go hunting specificity. */
    private const EXTRA_HOPS = 6;

    /**
     * How hard the deck is to defend.
     *
     * Difficulty here is distance. A concept one hop from the suggestion is
     * obviously about it and the performer can simply agree with the slide; a
     * concept reached by climbing to where lobsters are merely animals and
     * descending somebody else's branch has to be argued for, and the arguing
     * is the act.
     *
     * So the lever is the CLIMB. Each level says which strategies to use, how
     * far up to go before coming back down, and -- at the top level only --
     * what fraction of the deck is drawn from the unrelated-object pool, which
     * is the farthest anything can be from anything.
     *
     * Level 3 is the behaviour this had before the slider existed, and is the
     * default for that reason.
     */
    public const LEVELS = [
        1 => [
            'name' => 'Gentle',
            'blurb' => 'The slides are plainly about the word',
            'strategies' => [['near', 6], ['part', 4], ['substance', 2], ['cousin', 1]],
            'climb' => [0, 2],
            'pool' => 0.0,
        ],
        2 => [
            'name' => 'Fair',
            'blurb' => 'Close relatives, nothing wild',
            'strategies' => [['near', 4], ['part', 4], ['substance', 3], ['cousin', 3]],
            'climb' => [1, 2],
            'pool' => 0.0,
        ],
        3 => [
            'name' => 'Standard',
            'blurb' => 'Arguably related. You will have to do some work',
            'strategies' => [['cousin', 6], ['part', 3], ['substance', 2], ['near', 1]],
            'climb' => [2, 3],
            'pool' => 0.0,
        ],
        4 => [
            'name' => 'Hard',
            'blurb' => 'Distant cousins. Good luck',
            'strategies' => [['cousin', 9], ['part', 1], ['substance', 1]],
            'climb' => [4, 3],
            'pool' => 0.0,
        ],
        5 => [
            'name' => 'Hostile',
            'blurb' => 'Some slides have nothing to do with it at all',
            'strategies' => [['cousin', 8], ['part', 1]],
            'climb' => [6, 4],
            'pool' => 0.4,
        ],
    ];

    public const DEFAULT_LEVEL = 3;

    public function __construct(private PDO $db) {}

    public static function open(string $path): self
    {
        $db = new PDO("sqlite:$path", options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return new self($db);
    }

    /**
     * The word a deck is actually about.
     *
     * For a single word this is that word. For a sentence it is the most
     * photographable noun in it, which is not the first word: "my landlord
     * keeps the heating off" is a deck about a landlord, and a deck about
     * "my" is a deck about nothing.
     */
    public function seed(string $input): string
    {
        $candidates = $this->candidates($input);

        if ($candidates === []) {
            return '';
        }

        // Score, then take the best. Position breaks a tie toward the later
        // word, which in English is more often the subject of the complaint.
        $best = null;
        $bestScore = -1;

        foreach ($candidates as $position => $lemma) {
            $score = $this->scoreSeed($lemma) + $position;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $lemma;
            }
        }

        return $best ?? '';
    }

    /**
     * Phrases and words from the input that WordNet actually knows.
     *
     * Longest match first, so "ice cream" is one candidate rather than two
     * unrelated ones.
     *
     * @return array<int, string> keyed by position in the input
     */
    private function candidates(string $input): array
    {
        $words = preg_split('/[^\p{L}\p{N}\'-]+/u', mb_strtolower(trim($input)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return [];
        }

        $found = [];
        $taken = [];

        // Three words down to one. A longer phrase, where it exists, is
        // always the better seed.
        for ($length = min(3, count($words)); $length >= 1; $length--) {
            for ($i = 0; $i + $length <= count($words); $i++) {
                $span = range($i, $i + $length - 1);

                if (array_intersect($span, $taken) !== []) {
                    continue;
                }

                $phrase = implode(' ', array_slice($words, $i, $length));

                if ($length === 1 && in_array($phrase, self::SKIP, true)) {
                    continue;
                }

                foreach ($this->forms($phrase) as $form) {
                    if ($this->offsets($form) !== []) {
                        $found[$i] = $form;
                        $taken = [...$taken, ...$span];

                        continue 3;
                    }
                }
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * Spellings to try for one token.
     *
     * WordNet stores singulars, and an audience shouts plurals. Crude
     * de-pluralising beats the real morphology files here: a wrong guess just
     * misses and the next form is tried, while loading noun.exc costs a file
     * read on every suggestion.
     */
    private function forms(string $phrase): array
    {
        $forms = [$phrase];

        if (str_ends_with($phrase, 'ies') && strlen($phrase) > 4) {
            $forms[] = substr($phrase, 0, -3).'y';
        }

        if (str_ends_with($phrase, 'es') && strlen($phrase) > 3) {
            $forms[] = substr($phrase, 0, -2);
        }

        if (str_ends_with($phrase, 's') && ! str_ends_with($phrase, 'ss')) {
            $forms[] = substr($phrase, 0, -1);
        }

        return array_unique($forms);
    }

    /**
     * How good a seed a word is.
     *
     * Concrete beats abstract, and something with a lot of edges beats
     * something with two, because a dead end sends the walk back to the
     * fallback pool and the deck stops being about the suggestion.
     */
    private function scoreSeed(string $lemma): int
    {
        $row = $this->db->prepare(
            'SELECT count(*) AS senses,
                    sum(CASE WHEN s.lex IN ('.implode(',', self::CONCRETE).') THEN 1 ELSE 0 END) AS concrete,
                    (SELECT count(*) FROM edge e JOIN word w2 ON w2.offset = e.src WHERE w2.lemma = w.lemma) AS edges
             FROM word w JOIN synset s ON s.offset = w.offset
             WHERE w.lemma = ?'
        );
        $row->execute([$lemma]);
        $r = $row->fetch();

        return ((int) $r['concrete'] * 20)
            + min(30, (int) $r['edges'])
            + min(5, (int) $r['senses']);
    }

    /** @return array<int, array{offset: string, lex: int}> */
    private function offsets(string $lemma): array
    {
        $q = $this->db->prepare(
            'SELECT s.offset, s.lex FROM word w JOIN synset s ON s.offset = w.offset
             WHERE w.lemma = ? AND s.blocked = 0 AND w.blocked = 0'
        );
        $q->execute([$lemma]);

        return $q->fetchAll();
    }

    /**
     * Drift away from a word and collect what you land on.
     *
     * WordNet is a taxonomy, so a short walk from "lobster" reaches
     * "langoustine" -- correct, adjacent, and not funny, because the audience
     * can see it is still a lobster. The laugh needs a DISTANT COUSIN: climb
     * to where lobsters are merely animals, then descend somebody else's
     * branch and come back with a wombat. Arguably the same category,
     * obviously not the thing, and a real photograph of it exists.
     *
     * Four strategies, mixed across the deck so the five slides are not all
     * wrong in the same direction.
     *
     * @return array<int, string> distinct concepts
     */
    public function drift(string $word, int $count, Rng $rng, int $level = self::DEFAULT_LEVEL): array
    {
        $level = self::LEVELS[$level] ?? self::LEVELS[self::DEFAULT_LEVEL];
        $start = $this->offsets($word);

        if ($start === []) {
            // A suggestion the dictionary has never heard of -- a brand, a
            // bandmate's surname, pure nonsense. It still has to produce a
            // deck: "no results" is not an option with an audience watching.
            return $this->fromPool($count, [], $rng);
        }

        $found = [];
        $seen = [mb_strtolower($word) => true];

        // At the hardest level part of the deck is simply unrelated. Taken
        // first so the walk below does not have to fill those places.
        $fromPool = (int) round($count * $level['pool']);

        foreach ($this->fromPool($fromPool, $seen, $rng) as $unrelated) {
            $seen[mb_strtolower($unrelated)] = true;
            $found[] = $unrelated;
        }

        // Weighted toward cousins at the usual setting, because those carry
        // the joke. Parts and substances are funny differently -- a slide
        // about what a lobster is made of -- and a near miss is the easiest
        // thing in the deck to defend, which is why the gentle levels lean on
        // it.
        $strategies = $level['strategies'];
        $attempts = 0;

        while (count($found) < $count && $attempts < $count * self::BUDGET) {
            $attempts++;

            $at = $rng->pick($start)['offset'];
            $landed = match ($rng->pickWeighted($strategies)) {
                'cousin' => $this->cousin($at, $rng, $level['climb']),
                'part' => $this->along($at, ['part_of', 'has_part', 'member_of', 'has_member'], $rng),
                'substance' => $this->along($at, ['made_of', 'substance_of'], $rng),
                default => $this->along($at, ['hyponym'], $rng),
            };

            if ($landed === null) {
                continue;
            }

            // The landing must be something a camera could point at, and a
            // particular one. "system", "change" and "realm" are common
            // enough to win the familiarity weighting and are pictures of
            // nothing; "female mammal" is a caption, not a subject.
            if (! $this->isConcrete($landed) || $this->kids($landed) > self::CATEGORY) {
                continue;
            }

            foreach ($this->wordsAt($landed, $rng) as $candidate) {
                $key = mb_strtolower($candidate);

                // At the hard settings, reject anything that still has the
                // suggestion written on it. A long climb can descend back
                // into the family it came from, and "cheese rind" on a
                // Hostile deck about cheese is the one slide the performer
                // does not have to work for.
                if ($level['climb'][0] >= 4 && $this->related($key, $word)) {
                    continue;
                }

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $found[] = $candidate;

                    break;
                }
            }
        }

        // Short, which happens two ways: a word with almost no edges, and an
        // abstract suggestion whose whole neighbourhood is abstract. Both get
        // padded with plain objects rather than with abstractions -- a slide
        // showing a wheelbarrow under the heading "loneliness" is the joke
        // working, and one showing the word "indifference" is the joke
        // failing.
        $concepts = [...$found, ...$this->fromPool($count - count($found), $seen, $rng)];

        // At the hardest level the unrelated ones were taken first and so
        // landed on the opening slides, which gives the game away: the deck
        // starts nowhere near the word and then settles down. Spread them.
        return $level['pool'] > 0 ? $rng->shuffled($concepts) : $concepts;
    }

    /**
     * Plain concrete objects, drawn from the pool built by bin/build-wordnet.
     *
     * @param  array<string, bool>  $seen  lowercased words already used
     * @return array<int, string>
     */
    private function fromPool(int $count, array $seen, Rng $rng): array
    {
        if ($count < 1) {
            return [];
        }

        $size = (int) $this->db->query('SELECT max(id) FROM pool')->fetchColumn();
        $one = $this->db->prepare('SELECT lemma FROM pool WHERE id = ?');
        $picked = [];

        // Bounded: with a small pool and a lot already seen, asking for more
        // than exists must not spin.
        for ($i = 0; count($picked) < $count && $i < $count * 20; $i++) {
            $one->execute([1 + $rng->below($size)]);
            $lemma = $one->fetchColumn();

            if (is_string($lemma) && ! isset($seen[mb_strtolower($lemma)])) {
                $seen[mb_strtolower($lemma)] = true;
                $picked[] = $lemma;
            }
        }

        return $picked;
    }

    /**
     * Up to the abstraction, then down somebody else's branch.
     *
     * It descends further than it climbed on purpose. Stopping level with the
     * start leaves you on a category -- "a kind of mollusc" -- and a category
     * has no photograph. Going deeper lands on one specific object.
     */
    private function cousin(string $offset, Rng $rng, array $climb): ?string
    {
        $at = $offset;

        // How far up before coming back down. This is the difficulty dial:
        // one level up lands on a sibling the room can see the link to, five
        // lands on something the performer has to talk their way into.
        for ($i = 0, $levels = $climb[0] + $rng->below($climb[1]); $i < $levels; $i++) {
            $up = $this->neighbours($at, ['hypernym']);

            if ($up === []) {
                break;
            }

            $at = $rng->pick($up)['dst'];
        }

        $climbed = $at;

        // Descend further than the climb, so the walk ends on one specific
        // object rather than on the category it passed through.
        $levels = $climbed === $offset ? 1 + $rng->below(2) : 3 + $rng->below(4);

        // Descend the chosen number of levels, then keep going while the node
        // is still a heading. Without the second condition the walk stops
        // wherever its dice ran out and hands back "system" or "female
        // mammal" -- true, concrete, and a photograph of nothing in
        // particular. The extra hops are capped so a deep branch cannot run
        // away.
        for ($i = 0; $i < $levels + self::EXTRA_HOPS; $i++) {
            if ($i >= $levels && $this->kids($at) <= self::CATEGORY) {
                break;
            }

            $down = $this->neighbours($at, ['hyponym']);

            if ($down === []) {
                break;
            }

            // Prefer a concrete landing with something sayable in it. A walk
            // that ends in noun.cognition is semantically respectable and has
            // nothing to show; one that ends on a synset of Latin binomials
            // has edges and no words.
            $at = $rng->pickWeighted(array_map(fn ($e) => [$e['dst'], $this->appeal($e)], $down));
        }

        // Never came back down -- that is a category, not a cousin. A climb
        // of zero is allowed to stay put, which is what makes the gentle
        // levels gentle: it becomes an ordinary step sideways.
        return $at === $climbed && $climbed !== $offset ? null : $at;
    }

    /** One hop along specific relations. */
    private function along(string $offset, array $relations, Rng $rng): ?string
    {
        $edges = $this->neighbours($offset, $relations);

        if ($edges === []) {
            return null;
        }

        return $rng->pickWeighted(array_map(fn ($e) => [$e['dst'], $this->appeal($e)], $edges));
    }

    private function kids(string $offset): int
    {
        $q = $this->db->prepare('SELECT kids FROM synset WHERE offset = ?');
        $q->execute([$offset]);

        return (int) $q->fetchColumn();
    }

    /** Does this concept still wear the suggestion's name? */
    private function related(string $candidate, string $word): bool
    {
        $word = mb_strtolower($word);

        return $word !== '' && (str_contains($candidate, $word) || str_contains($word, $candidate));
    }

    /** Is there anything here a camera could point at? */
    private function isConcrete(string $offset): bool
    {
        $q = $this->db->prepare('SELECT lex FROM synset WHERE offset = ?');
        $q->execute([$offset]);

        return in_array((int) $q->fetchColumn(), self::CONCRETE, true);
    }

    /** How much the walk should want to land here. */
    private function appeal(array $edge): int
    {
        $weight = in_array((int) $edge['lex'], self::CONCRETE, true) ? 4 : 1;

        return (int) $edge['usable'] > 0 ? $weight * 3 : 1;
    }

    /** @return array<int, array{dst: string, rel: string, lex: int, usable: int, kids: int}> */
    private function neighbours(string $offset, array $relations): array
    {
        $in = implode(',', array_fill(0, count($relations), '?'));

        // Blocked synsets are excluded as destinations, not merely filtered
        // when read: the walk should not pass THROUGH the slur corner of the
        // dictionary on its way somewhere innocent.
        $q = $this->db->prepare(
            "SELECT e.dst, e.rel, s.lex, s.usable, s.kids FROM edge e JOIN synset s ON s.offset = e.dst
             WHERE e.src = ? AND e.rel IN ($in) AND s.blocked = 0"
        );
        $q->execute([$offset, ...$relations]);

        return $q->fetchAll();
    }

    /**
     * Readable words from a synset, best first.
     *
     * @return array<int, string>
     */
    private function wordsAt(string $offset, Rng $rng): array
    {
        $q = $this->db->prepare(
            // w.blocked, not only s.blocked: a lemma with one flagged sense
            // is refused whichever sense the walk arrived through, because
            // the room reads the word.
            'SELECT w.lemma, w.freq FROM word w JOIN synset s ON s.offset = w.offset
             WHERE w.offset = ? AND s.blocked = 0 AND w.blocked = 0 AND w.freq >= ?'
        );
        $q->execute([$offset, self::MIN_FREQ]);

        $words = array_filter(
            $q->fetchAll(),
            // Any capital rules a word out. WordNet carries genus names and
            // abbreviations beside ordinary words -- Decapoda, Homaridae, MT,
            // Si -- and a slide captioned "Si" reads as a bug on stage rather
            // than a joke.
            fn ($w) => $w['lemma'] === mb_strtolower($w['lemma']) && ! preg_match('/\d/', $w['lemma'])
        );

        if ($words === []) {
            return [];
        }

        // Weighted rather than simply the most common, because the second or
        // third word in a synset is often the funnier one. Single words are
        // tripled: "common spotted orchid" is a real phrase and a terrible
        // image search.
        $picked = [];

        while ($words !== []) {
            $chosen = $rng->pickWeighted(array_map(
                fn ($w) => [$w['lemma'], min(2000, (int) $w['freq']) * (str_contains($w['lemma'], ' ') ? 1 : 3)],
                $words
            ));

            $picked[] = $chosen;
            $words = array_filter($words, fn ($w) => $w['lemma'] !== $chosen);
        }

        return $picked;
    }

    /** The dictionary definition, used as straight-faced slide copy. */
    public function gloss(string $word): ?string
    {
        $q = $this->db->prepare(
            'SELECT s.gloss FROM word w JOIN synset s ON s.offset = w.offset
             WHERE w.lemma = ? AND s.gloss != "" LIMIT 1'
        );
        $q->execute([mb_strtolower($word)]);

        $gloss = $q->fetchColumn();

        if (! is_string($gloss) || $gloss === '') {
            return null;
        }

        // Glosses carry parenthetical notes and quoted examples. The first
        // clause is the definition and the rest is apparatus.
        $gloss = preg_replace('/\s*[;(].*$/s', '', $gloss) ?? $gloss;

        return trim($gloss) ?: null;
    }
}
