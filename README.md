# Pitch Deck

An improv game. The audience shouts a word, the site builds five slides, and
the performer has to sell them having never seen them.

A replacement for [talk-generator][tg] by Thomas Winters and Kory Mathewson,
whose hosted version has gone. This is a fresh implementation, not a fork.

**https://pitchdeck.251dev.com**

[tg]: https://github.com/korymath/talk-generator

## Running it

    php tests/run.php              # 52 tests, about 20 seconds
    php tests/run.php drift        # filter by name
    php bin/warm-cache.php 60      # fetch 60 more pictures (also on cron, 08:00 UTC)
    php bin/build-wordnet.php      # rebuild the dictionary from data/dict + data/en_full.txt

No composer, no node, no framework. PHP 8.4 with `pdo_sqlite`, `curl`, `gd`
and `zip`, all of which were already on the box.

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
