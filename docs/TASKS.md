# Pitch Deck — build tasks

A replacement for `talk-generator` (Thomas Winters, Kory Mathewson, 2018), whose
hosted version has gone. An audience shouts a word, the site builds a five-slide
deck, and a performer improvises a pitch having never seen it.

**pitchdeck.251dev.com**

## What makes it funny, and therefore what must not be "improved"

The original's comedy came from the images being **real and wrong**. The drift
engine walks from the suggestion to an adjacent concept, and a genuine
photograph of that adjacent thing appears while the performer is mid-sentence
defending something else.

An image model does not do this. Ask it for a lobster and it returns a
competent lobster, which is obedient and not funny. **No image generation.**
Matching quality is deliberately loose — a near-miss is the product.

## The dependency decision, and why it is not symmetrical

Checked 2026-10-12:

| Dependency | State | Decision |
|---|---|---|
| ConceptNet API | **502** | never call at show time |
| Goodreads API | shut down 2020 | drop entirely, use Wikiquote |
| Openverse | 200, keyless, 0.08s | primary image source |
| Wikimedia Commons | 200, keyless, 0.23s | first fallback |
| Wikipedia page images | 200, keyless, 0.14s | second fallback |

Images are safe: three independent keyless providers, and the show needs about
25 a night. **Fetch those live.**

The drift engine is not safe: one academic-hosted API, no alternative provider,
currently down. **That runs locally or the show dies with it.**

The remaining single point of failure is venue wifi, which is why fetched
images are cached on the way past and a small local pool is the last resort.

---

## Tasks — all done 2026-10-12

Live at **https://pitchdeck.251dev.com**. 52 tests pass (`php tests/run.php`).

### 1. Subdomain
- [x] `sudo scripts/provision-subdomain.sh pitchdeck --type=php` (from the
      ringleader repo), giving nginx + PHP-FPM + a real certificate.

### 2. Drift engine, local
- [x] Download WordNet 3.1 (~20 MB) and parse `data.noun` / `index.noun`.
- [x] Load into SQLite: `word -> related word`, typed by relation.
- [x] Weight the walk toward the odder relations. Straight hypernyms give
      "lobster, crustacean, arthropod", which is a biology lesson rather than a
      joke. Part-of, used-for and has-property drift sideways instead.
- [x] Multi-word input: take the most concrete noun rather than the first word,
      so "my landlord keeps the heating off" starts from *landlord* or
      *heating*, not *my*.
- [x] **Never return empty.** A suggestion with no graph entry falls back to a
      generic concept pool. A show cannot wait for an error.

### 3. Images, live with failover
- [x] Ordered providers: Openverse, Wikimedia Commons, Wikipedia. Fall through
      on any non-200 or timeout.
- [x] Hard timeout per provider (2s). Slow is the same as broken on stage.
- [x] Cache every fetched image on disk keyed by concept. Not for rate limits,
      which are irrelevant at this volume, but so a repeated word is instant
      and works on bad wifi.
- [x] Ship a small local pool as the final fallback so a deck still builds with
      no network at all.

### 4. Deck assembly
- [x] Five slides, the shape the original used:
      title, image + confident caption, fabricated chart, quote, conclusion.
- [x] Captions from templates and the drifted concepts. No model.
- [x] Charts drawn locally — invented data, plausible axes.
- [x] Quotes from a local Wikiquote extract, attributed to someone unrelated.

### 5. The site
- [x] One text input. A word, a phrase or a whole sentence.
- [x] In-browser slideshow: arrow keys **and** on-screen buttons, because the
      performer may be holding a microphone and a clicker is not guaranteed.
- [x] Download the same deck as `.pptx`.
- [x] List of previous suggestions, most recent first, each replayable.

### 6. Fit for a stage
- [x] Deck in under a second, or it dies in the room.
- [x] Legible from the back: large type, high contrast, no thin weights.
- [x] Keyboard-only operation throughout.
- [x] No layout shift when a slide loads — a jump reads as a mistake.

### 7. Checks
- [x] Drift is deterministic for a given seed so a deck can be replayed.
- [x] Every provider failing still produces five slides.
- [x] Empty, nonsense and very long input all produce a deck.
- [x] Downloaded `.pptx` opens.

---

## Not doing, deliberately

- **Image generation.** See above; it removes the joke.
- **An LLM for copy.** The original used templates and n-grams. A model adds
  latency and a failure mode mid-show for prose nobody reads closely.
- **ConceptNet at show time.** The dump is the upgrade path for better
  associations; the API is never called live.
- **Accounts, saving, sharing.** It is a stage tool.

## Credit

Format and approach after `talk-generator` by Thomas Winters and Kory
Mathewson, MIT licensed. This is a fresh implementation, not a fork.
