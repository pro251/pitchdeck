# Pitch Deck

An improv game. The audience shouts a word, the site builds five slides, and
the performer has to sell them having never seen them.

A replacement for [talk-generator][tg] by Thomas Winters and Kory Mathewson,
whose hosted version has gone. This is a fresh implementation, not a fork.

**https://pitchdeck.251dev.com**

[tg]: https://github.com/korymath/talk-generator

## Running it

From a fresh clone:

    bin/fetch-data.sh              # WordNet 3.1 + the OpenSubtitles word counts
    php bin/build-wordnet.php      # builds data/wordnet.sqlite, about 7 seconds
    php -S localhost:8000 -t public

Then:

    php tests/run.php              # 92 tests, about 8 seconds
    php tests/run.php drift        # filter by name

No composer, no node, no framework. PHP 8.4 with `pdo_sqlite`, `curl`, `gd`
and `zip`, all of which were already on the box.

Neither corpus is in git: together they are 36 MB of somebody else's data, and
the SQLite file built from them is reproducible in seconds. What the
repository carries is the code that turns one into the other.

## The one thing to understand before changing anything

**The joke is that the pictures are real and wrong.** The drift engine walks
from the suggestion to something adjacent, and a genuine photograph of that
adjacent thing appears while the performer is mid-sentence defending
something else.

An image model cannot do this. Ask one for a lobster and it returns a
competent lobster, which is obedient and not funny. **Do not add image
generation**, and do not "improve" the matching: a near miss is the product.

## Why the dependencies are not treated alike

Checked 2026-10-12, which is why the two halves are built differently:

| | state | decision |
|---|---|---|
| ConceptNet API | **502** | never called; WordNet is loaded locally instead |
| Goodreads API | shut down 2020 | dropped; the quote is a WordNet gloss |
| Openverse, Wikimedia Commons, Wikipedia | all 200, keyless | fetched live, in that preference order |

Images are safe to fetch because there are three independent providers and a
cache. The semantic graph is not: it had one provider and that provider was
down. So `data/wordnet.sqlite` (31 MB, built from WordNet 3.1) ships with the
app and nothing semantic goes over the wire.

## How a deck gets made

1. **Seed** — `Drift::seed()` picks the most photographable noun in the input,
   not the first word. "My landlord keeps the heating off" is a deck about a
   landlord.
2. **Drift** — climb the taxonomy to where lobsters are merely animals, then
   descend someone else's branch and come back with a wombat. Four strategies
   (cousin, part, substance, near) mixed so the five slides are not all wrong
   in the same direction.
3. **Pictures** — every concept looked up in parallel, Wikipedia first.
4. **Assemble** — five slides from templates in `Copy.php`. No language model,
   same as the original.

## The two dials

**Slides, 3 to 10, default 5.** Three is the fewest that still has a
beginning, a middle and an end; past ten the performer is reading out a
document rather than improvising. There are only six kinds of middle slide, so
a long deck reuses them -- never two of a kind in a row.

**Difficulty, five levels, default 3 (Standard).** Difficulty is distance. A
concept one hop from the suggestion is obviously about it and the performer
can simply agree with the slide; one reached by climbing to where lobsters are
merely animals has to be argued for, and the arguing is the act. The lever is
therefore the CLIMB in `Drift::LEVELS`, plus -- at Hostile only -- a fraction
of the deck drawn from the unrelated-object pool.

From "lobster":

| | |
|---|---|
| 1 Gentle | true lobster, norway lobster, lobster tail, rock lobster |
| 2 Fair | periwinkle, northern lobster, squid, crayfish |
| 3 Standard | rock barnacle, ribbon worm, flint glass, sea gooseberry |
| 4 Hard | leviathan, sea cradle, woodworm, icicle |
| 5 Hostile | mirror, banquet, mascot, growler |

**Level 3 is exactly what the engine did before the sliders existed**, which
is why it is the default, and there is a test asserting its weights so that
moving it cannot happen quietly. The hard levels also reject anything still
wearing the suggestion's name -- a long climb can descend back into the family
it came from, and "cheese rind" on a Hostile deck about cheese is the one
slide nobody has to work for.

Both are stored per deck, so a previous word replays as it was performed. Rows
written before the sliders existed take the defaults.

## The design drifts too

Everything drifted except the colours, and every deck came out the same dark
grey with the same red on it -- which made it look like one template filled in
repeatedly, because it was.

- **A palette per deck** (`Palette::SCHEMES`), so two decks do not look alike.
- **A ground per slide** within that palette, so slide four is not slide two.
- **A slide showing a photograph takes its accent FROM that photograph.** The
  tint is sampled at cache time and pushed until it reads against whatever
  ground it landed on. A deck about langoustines comes out the colour of a
  langoustine.
- **The shape of the deck is sampled too.** Slide one is a title and slide
  five a closing; the middle three are drawn without replacement from six
  types, so nothing is guaranteed to appear and the chart turns up in about
  three decks in ten. It used to be slide three of everything, which a room
  learns after two rounds.

Colours are resolved in `Deck::paint()`, not in the renderers, so the browser
and the `.pptx` cannot disagree about what the deck looked like. Every
combination is asserted readable at 4.5:1 -- this is projected in a bar with
the house lights half up, and text that fails there does not read as a style
choice, it reads as a slide that failed to load.

## Things that cost a session to learn

- **The drift must land on a specific object, not a category.** "System",
  "change" and "female mammal" are concrete by WordNet's own classification
  and are photographs of nothing. `Drift::CATEGORY` and the concreteness gate
  exist for this.
- **Familiarity is a hard requirement, not a nicety.** "Entoproct" and
  "callathump" are real words, correctly adjacent, and useless twice over: the
  audience does not recognise them and no photograph of them exists. The floor
  comes from OpenSubtitles word counts (`data/en_full.txt`) — spoken English,
  because that is the vocabulary a room actually has.
- **WordNet contains slurs, and the walk finds them.** An early run returned
  an archaic slur for a gay man as a slide subject. Synsets are blocked via
  WordNet's own `;u` usage-domain pointers, and **a word is blocked if any of
  its senses is** — "skinhead" has one flagged sense and one clean one, and
  the room reads the word, not the sense. There is a test asserting this and
  it must not be relaxed.
- **A search provider never reports failure.** Asked for "female mammal",
  Wikipedia redirects to "Female" and Openverse returns a wolf. Hence the
  relevance check on the returned title, which is also how concepts with no
  photograph get dropped without maintaining a list of them by hand.
- **In `bin/build-wordnet.php`, create every index before the UPDATE passes.**
  Each pass is a correlated subquery; an index made afterwards turns a
  seven-second build into one still running after seven minutes. This cost
  three rebuilds.
- **`readableOn` must measure, not guess.** Choosing black or white from a
  luminance threshold put white on amber at 2.3:1. Compare the contrast of
  both candidates and take the winner.
- **PHP's `/` returns an int when it divides evenly.** `0/255` is int 0, which
  made `toHsl` quietly stop honouring its own float return type.
- **`continue 3` left the wrong loop.** In `Drift::candidates()` it exited the
  phrase-length loop instead of the word loop, so a multi-word suggestion only
  ever considered its FIRST matching word. "Ford motors" became a deck about a
  shallow river crossing. It looked right for a long time because the first
  match is usually the right one, and the test that should have caught it was
  asserting the first word too.
- **The lemma column is lowercased, which killed the capital-letter filter.**
  It was comparing a lowercased string to itself and had never rejected
  anything, so WordNet's six men called Ford were eligible for slides. Proper
  nouns are flagged per SENSE at build time now -- per sense, because "ford" is
  both a river crossing and those six men.
- **A word is judged by all its senses, not the one the walk used.** "Change"
  reached a slide through its concrete sense -- coins in a pocket -- and on
  screen it is the abstract noun. Same shape as the slur fix: the room reads
  the word. `word.concreteness` is the share of a lemma's senses that are
  photographable, and must exceed 50%.
- **Low difficulty must degrade, not fall over.** A word with few near
  neighbours used to drop straight through to the unrelated pool, so asking
  for Gentle returned the most random thing in the dictionary. The walk now
  widens its climb in stages first, turning Gentle into Fair rather than into
  noise, and reaches the pool only when every distance is exhausted.
- **This box drops every connection past about 18 in flight.** Not a provider
  refusing — all of them fail together with no response, which looks exactly
  like the whole internet being down. `Http::MAX_PARALLEL` is 12.

## What is stored

Only the suggestion and the seed (`data/decks.sqlite`). The slides are a pure
function of those two, so storing them as well would be two records of the
same fact, and the one nobody writes to is the one that gets believed.
Replaying a previous word rebuilds it identically, which is also what makes
the downloaded `.pptx` match what was on screen.

Pictures are cached in `storage/images/`, keyed by concept, **misses
included** — a remembered miss is a result too, and without it every deck
containing one unillustratable word pays three providers' latency again.

## The PowerPoint file

`Pptx.php` writes the OOXML by hand. PowerPoint rejects a package with a
missing slide master, layout or theme rather than defaulting it, so all three
are written even though nothing uses them. `PptxTest.php` asserts the
structural rules the format enforces.

**Not verified against PowerPoint itself** — there is none on this machine,
and no LibreOffice either. The package validates structurally; opening it
once on a real machine would settle it.

## Credit

Format and approach after talk-generator by Thomas Winters and Kory
Mathewson, MIT licensed.
