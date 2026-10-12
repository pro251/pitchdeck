<?php

/*
 * Parse WordNet's noun database into SQLite, once.
 *
 * The show never calls a semantic API. ConceptNet's was returning 502 on the
 * day this was written, there is no alternative provider, and a drift engine
 * that can be down is a show that can be cancelled.
 *
 * Usage: php bin/build-wordnet.php
 */

$root = dirname(__DIR__);
$dict = "$root/data/dict";
$dbPath = "$root/data/wordnet.sqlite";

if (! is_file("$dict/data.noun")) {
    fwrite(STDERR, "No $dict/data.noun. Extract wn3.1.dict.tar.gz into data/ first.\n");
    exit(1);
}

/*
 * How familiar each word is, from OpenSubtitles word counts.
 *
 * This is the filter that decides whether a drift result can go on a screen.
 * WordNet is a dictionary, so the walk happily returns "entoproct" and
 * "callathump" -- real words, genuinely adjacent, and useless on stage twice
 * over: the audience does not recognise them, so there is no joke to get, and
 * no photographer has ever taken a picture of one, so the slide arrives
 * blank.
 *
 * Subtitle counts rather than book counts on purpose: this measures the words
 * people SAY, which is the vocabulary an improv audience actually has.
 * "wombat" scores 192 and "smoothie" 1138, while the four words above are
 * absent from 1.6 million entries.
 */
$freqPath = "$root/data/en_full.txt";

if (! is_file($freqPath)) {
    fwrite(STDERR, "No $freqPath. See docs/TASKS.md for where it comes from.\n");
    exit(1);
}

$freq = [];
$fh = fopen($freqPath, 'r');

while (($line = fgets($fh)) !== false) {
    [$token, $count] = array_pad(explode(' ', trim($line), 2), 2, 0);

    if ($token !== '') {
        $freq[$token] = (int) $count;
    }
}

fclose($fh);
printf("frequency list: %s words\n", number_format(count($freq)));

@unlink($dbPath);
$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec('PRAGMA journal_mode = OFF');
$db->exec('PRAGMA synchronous = OFF');
$db->exec('CREATE TABLE synset (offset TEXT PRIMARY KEY, words TEXT NOT NULL, gloss TEXT, lex INTEGER NOT NULL, blocked INTEGER NOT NULL DEFAULT 0, usable INTEGER NOT NULL DEFAULT 0, kids INTEGER NOT NULL DEFAULT 0)');
$db->exec('CREATE TABLE word (lemma TEXT NOT NULL, offset TEXT NOT NULL, freq INTEGER NOT NULL DEFAULT 0, blocked INTEGER NOT NULL DEFAULT 0)');
$db->exec('CREATE TABLE edge (src TEXT NOT NULL, dst TEXT NOT NULL, rel TEXT NOT NULL)');

/*
 * Which pointer types are worth walking, and how much.
 *
 * Hypernyms (@) climb toward abstraction: lobster, crustacean, arthropod,
 * invertebrate. That is a biology lesson and it is not funny. The sideways
 * relations are where the jokes are -- what a thing is part of, what it is
 * made of, what belongs to it -- because they land on a real object that is
 * plainly not what the performer was talking about.
 */
/*
 * Usage domains that mean "do not put this on a screen in front of a room".
 *
 * WordNet is a dictionary of the language as it is, slurs included, and a
 * drift walk reaches them on its own -- an early run of this returned an
 * archaic slur for a gay man as a slide subject. That is not a joke that
 * misfired, it is the tool failing at its one job.
 *
 * These are the `;u` pointer targets for derogation, ethnic slurs,
 * epithets, smear words, obscenity, profanity and scatology. Ordinary slang
 * (07171981) is deliberately NOT here: slang is most of the good material.
 */
/*
 * The familiarity floor for a word that can appear on a slide.
 *
 * Calibrated against the words a drift walk actually produced: "tomalley"
 * scores 3 and "wombat" 192, and the line belongs between them. Raising it
 * much further starts discarding ordinary objects -- which is the one failure
 * that matters, since a specific object is the whole joke.
 */
const MIN_FREQ = 50;

const OFFENSIVE_DOMAINS = [
    '06730109', // disparagement, derogation
    '06731706', // ethnic slur
    '06733713', // name, epithet -- "a defamatory or abusive word or phrase"
    '06733830', // smear word
    '07139048', // obscenity, smut, vulgarism, filth, dirty word
    '07139636', // scatology
    '07143235', // profanity
];

/*
 * A second pass for what the pointers miss, since only a few hundred synsets
 * carry a usage domain at all. A false positive here costs one word out of
 * 146,000 and is not worth avoiding.
 */
const OFFENSIVE_WORDS = '/\\b(offensive|disparaging|derogatory|slur|vulgar|obscene|insulting|contemptuous|taboo|racist|racial)\\b/i';

const RELATIONS = [
    '~' => ['hyponym', 5],       // kinds of it: specific, concrete, photographable
    '%p' => ['part_of', 6],      // its parts
    '#p' => ['has_part', 6],     // what it is part of
    '%m' => ['member_of', 6],
    '#m' => ['has_member', 6],
    '%s' => ['made_of', 6],
    '#s' => ['substance_of', 6],
    '=' => ['attribute', 4],
    '@' => ['hypernym', 1],      // kept, but weighted down to near-irrelevance
];

$synsets = 0;
$edges = 0;
$blockedCount = 0;

$insSyn = $db->prepare('INSERT OR IGNORE INTO synset (offset, words, gloss, lex, blocked) VALUES (?, ?, ?, ?, ?)');
$insWord = $db->prepare('INSERT INTO word (lemma, offset) VALUES (?, ?)');
$insEdge = $db->prepare('INSERT INTO edge (src, dst, rel) VALUES (?, ?, ?)');

$db->beginTransaction();
$fh = fopen("$dict/data.noun", 'r');

while (($line = fgets($fh)) !== false) {
    // The file opens with licence lines, each beginning with two spaces.
    if ($line[0] === ' ') {
        continue;
    }

    [$body, $gloss] = array_pad(explode('|', $line, 2), 2, '');
    $f = preg_split('/\s+/', trim($body));

    $offset = $f[0];
    // Field 1 is lex_filenum, WordNet's semantic field: 5 is noun.artifact,
    // 13 noun.food, 18 noun.animal. A drift that lands on noun.cognition has
    // nothing to photograph, so the walk needs to know.
    $wordCount = hexdec($f[3]);

    $words = [];
    $i = 4;

    for ($w = 0; $w < $wordCount; $w++) {
        // Underscores are WordNet's word separator, and the lex_id that
        // follows each word is not part of it.
        $words[] = str_replace('_', ' ', $f[$i]);
        $i += 2;
    }

    $pointerCount = (int) $f[$i];
    $i++;

    $pointers = [];

    for ($p = 0; $p < $pointerCount; $p++) {
        $pointers[] = [$f[$i], $f[$i + 1], $f[$i + 2]];
        $i += 4;
    }

    // Decide this before writing the row: a blocked synset is still stored,
    // because an edge may point at it and a missing row breaks the walk. It
    // is excluded when words are READ, not when they are indexed.
    $blocked = preg_match(OFFENSIVE_WORDS, $gloss) === 1;

    foreach ($pointers as [$symbol, $target, $pos]) {
        if ($symbol === ';u' && in_array($target, OFFENSIVE_DOMAINS, true)) {
            $blocked = true;
        }
    }

    $insSyn->execute([$offset, implode('|', $words), trim($gloss), (int) $f[1], (int) $blocked]);
    $synsets++;

    if ($blocked) {
        $blockedCount++;
    }

    foreach ($words as $lemma) {
        $insWord->execute([mb_strtolower($lemma), $offset]);
    }

    foreach ($pointers as [$symbol, $target, $pos]) {
        // Only noun targets: a verb or adjective synset has no photograph.
        if ($pos !== 'n' || ! isset(RELATIONS[$symbol])) {
            continue;
        }

        $insEdge->execute([$offset, $target, RELATIONS[$symbol][0]]);
        $edges++;
    }
}

fclose($fh);
$db->commit();

/*
 * Every index, before any of the derived-column passes below.
 *
 * Each of those passes is a correlated subquery over one of these tables, so
 * an index created afterwards is an index created too late: the frequency
 * pass alone went from four seconds to still-running-after-seven-minutes.
 * This cost three rebuilds to learn. Add new indexes here, and new UPDATE
 * passes below.
 */
$db->exec('CREATE INDEX idx_word_lemma ON word (lemma)');
$db->exec('CREATE INDEX idx_word_offset ON word (offset)');
$db->exec('CREATE INDEX idx_edge_src ON edge (src)');


// Multi-word lemmas score as their rarest part: "filing cabinet" is only as
// familiar as "filing".
$db->beginTransaction();
$setFreq = $db->prepare('UPDATE word SET freq = ? WHERE lemma = ?');

foreach ($db->query('SELECT DISTINCT lemma FROM word')->fetchAll(PDO::FETCH_COLUMN) as $lemma) {
    // A phrase is only as familiar as its least familiar word: "filing
    // cabinet" is common, "entoproct colony" is not, and taking the max would
    // rate the second one on the strength of "colony".
    $scores = array_map(fn ($part) => $freq[$part] ?? 0, explode(' ', $lemma));

    $setFreq->execute([min($scores), $lemma]);
}

$db->commit();

// Both indexes must exist before the UPDATE below. Without idx_word_offset
// each of the 82,000 subqueries scans the whole word table -- the same
// mistake, and the same seven-minute hang, as the frequency pass above.

/*
 * How many words in each synset can actually go on a slide.
 *
 * The descend walk reads this to avoid dead ends. A synset whose only words
 * are Latin binomials is a real node with real edges and nothing to show, and
 * walking into one costs the deck a slide.
 */
$db->exec('UPDATE synset SET usable = (
    SELECT count(*) FROM word w WHERE w.offset = synset.offset AND w.freq >= '.MIN_FREQ.' AND w.blocked = 0
) WHERE blocked = 0');

/*
 * A word is blocked if ANY of its senses is.
 *
 * Blocking the synset alone is not enough, and a test caught it: "skinhead"
 * has a flagged sense and a clean one, the walk used the clean one, and the
 * word went on a slide. The audience reads the word, not the sense that was
 * looked up -- so the lemma is judged by its worst meaning, whichever one the
 * walk happened to arrive through.
 */
$db->exec('UPDATE word SET blocked = 1 WHERE offset IN (SELECT offset FROM synset WHERE blocked = 1)');
$db->exec('UPDATE word SET blocked = 1 WHERE lemma IN (SELECT lemma FROM word WHERE blocked = 1)');

/*
 * How many kinds of thing each synset contains.
 *
 * This is how the walk tells a specific object from a category. "system" and
 * "female mammal" are concrete by WordNet's own classification and are useless
 * on a slide, because they are headings: a photograph of a female mammal is a
 * photograph of some particular animal. A node with a lot of children is a
 * heading, so the descent keeps going until it reaches something with few.
 */
$db->exec("UPDATE synset SET kids = (
    SELECT count(*) FROM edge e WHERE e.src = synset.offset AND e.rel = 'hyponym'
)");

/*
 * A pool of plain concrete objects, numbered contiguously.
 *
 * This is what an abstract suggestion gets illustrated with. "Loneliness" has
 * no photographable cousin anywhere near it in the dictionary -- the walk
 * comes back with "indifference" and "ethos", which are correct and are
 * pictures of nothing. A wheelbarrow is funnier and the slide actually
 * renders.
 *
 * The floor is higher here than MIN_FREQ because these are picked blind, with
 * no connection to the suggestion carrying them. The word has to land on its
 * own.
 *
 * Numbered rather than drawn with RANDOM() so the Rng can address it: a deck
 * has to be replayable, and a random pick inside SQLite is not seedable.
 */
$db->exec('CREATE TABLE pool (id INTEGER PRIMARY KEY, lemma TEXT NOT NULL)');
$db->exec("INSERT INTO pool (lemma) SELECT DISTINCT w.lemma
           FROM word w JOIN synset s ON s.offset = w.offset
           WHERE s.blocked = 0 AND w.blocked = 0 AND w.freq >= ".(MIN_FREQ * 6)."
             AND s.lex IN (5, 6, 13, 17, 20)
             AND w.lemma NOT LIKE '% %'
           ORDER BY w.lemma");

printf("synsets %d\nblocked %d synsets\nwords   %d\nedges   %d\nsize    %s\n",
    $synsets,
    $blockedCount,
    (int) $db->query('SELECT count(*) FROM word')->fetchColumn(),
    $edges,
    number_format(filesize($dbPath) / 1048576, 1).' MB'
);

printf("blocked %d words (any sense flagged)\n", (int) $db->query('SELECT count(DISTINCT lemma) FROM word WHERE blocked = 1')->fetchColumn());
printf("pool    %d plain objects\n", (int) $db->query('SELECT count(*) FROM pool')->fetchColumn());
printf("usable  %d words at freq >= %d\n",
    (int) $db->query('SELECT count(DISTINCT w.lemma) FROM word w JOIN synset s ON s.offset = w.offset
                      WHERE w.freq >= '.MIN_FREQ.' AND s.blocked = 0 AND w.blocked = 0')->fetchColumn(),
    MIN_FREQ
);
