<?php

namespace PitchDeck;

/**
 * Real photographs of the wrong thing.
 *
 * This is the half of the joke the drift engine cannot do on its own. An
 * image model would answer "lobster" with a competent lobster, which is
 * obedient and not funny; a photo library answers a drifted concept with
 * somebody's actual holiday snap of a wheelbarrow, and the performer has to
 * explain why that was always the plan.
 *
 * Three keyless providers, tried in order of precision. All were verified
 * reachable on 2026-10-12; any one of them being down costs nothing because
 * the next is tried, and a concept that defeats all three is dropped rather
 * than shown blank.
 */
final class Images implements Pictures
{
    /** Searching is pointless for words this generic. */
    private const SKIP_IN_TITLE = ['the', 'of', 'a', 'an', 'and'];

    /** Below this on either edge it is an icon, not a photograph. */
    private const MIN_EDGE = 320;

    /**
     * How long a written picture survives on disk.
     *
     * Nothing is reused -- every deck fetches afresh -- but the bytes still
     * have to exist long enough for the browser to load them and for a .pptx
     * download to embed them. This is a sweep, not a cache.
     */
    private const KEEP_SECONDS = 3600;

    public function __construct(private string $cacheDir)
    {
        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * Find a picture for each concept.
     *
     * Concepts that no provider can illustrate are simply absent from the
     * result. That is deliberate and it is how the deck filters out words
     * like "female mammal" -- real, correctly drifted, and a photograph of
     * nothing in particular. Rather than maintain a list of such words by
     * hand, the question is put to the only authority that can answer it:
     * whether a photograph of the thing exists.
     *
     * @param  array<int, string>  $concepts
     * @return array<string, array{file: string, title: string, credit: string, source: string}>
     */
    public function find(array $concepts, ?Rng $rng = null, ?int $need = null): array
    {
        $this->sweep();

        $wanted = array_values(array_unique($concepts));

        if ($wanted === []) {
            return [];
        }

        // Look everything up at once: these are small JSON replies and the
        // answers decide which concepts are worth the expensive part.
        $candidates = $this->lookup($wanted, $rng);
        $found = [];

        // Downloading is where the seconds go -- a photograph is a few
        // hundred kilobytes and a lookup is a few hundred bytes. A deck asks
        // for more concepts than it can use, so stop as soon as enough have
        // arrived rather than fetching every picture and discarding half.
        $need ??= count($wanted);

        // Two rounds: if the preferred candidate turns out to be a broken
        // file or a 3 KB pronunciation thumbnail, the next provider's answer
        // gets its turn rather than the concept being lost.
        for ($round = 0; $round < 2 && count($found) < $need; $round++) {
            $attempt = [];

            foreach ($wanted as $concept) {
                if (count($found) + count($attempt) >= $need + 1) {
                    break;
                }

                if (! isset($found[$concept]) && isset($candidates[$concept][$round])) {
                    $attempt[$concept] = $candidates[$concept][$round];
                }
            }

            if ($attempt === []) {
                break;
            }

            foreach ($this->download($attempt) as $concept => $image) {
                $found[$concept] = $image;
            }
        }

        // Returned in the order they were asked for, so the nearest drift is
        // still the one the deck reaches for first.
        $ordered = [];

        foreach ($concepts as $concept) {
            if (isset($found[$concept])) {
                $ordered[$concept] = $found[$concept];
            }
        }

        return $ordered;
    }

    /**
     * Delete pictures older than the keep window.
     *
     * Without this the directory grows forever, because nothing ever reads a
     * file back and so nothing ever decides one is current.
     */
    private function sweep(): void
    {
        $cutoff = time() - self::KEEP_SECONDS;

        foreach (glob($this->cacheDir.'/*.jpg') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Look the concepts up and return ranked candidates for each.
     *
     * Two stages, not three and not one. Asking every provider for every
     * concept is three times the requests for no gain, because Wikipedia
     * answers most concrete nouns on its own -- and this box cannot hold more
     * than about eighteen connections open at once, so the extra requests buy
     * another round trip rather than saving one. Asking them strictly one at
     * a time is the opposite mistake, six round trips where two will do.
     *
     * @param  array<int, string>  $concepts
     * @return array<string, array<int, array>>
     */
    private function lookup(array $concepts, ?Rng $rng): array
    {
        $providers = $this->providers();
        $candidates = [];

        // Wikipedia first and alone: the article called "Kazoo" contains a
        // kazoo, so there is no relevance problem to work around. The search
        // providers answer a phrase they have nothing for confidently, with
        // something else.
        $urls = [];

        foreach ($concepts as $concept) {
            $urls[$concept] = $providers['wikipedia']($concept);
        }

        foreach (Http::json($urls) as $concept => $body) {
            $hit = $this->readWikipedia($concept, $body);

            if ($hit !== null) {
                $candidates[$concept][] = $hit;
            }
        }

        $missing = array_values(array_diff($concepts, array_keys($candidates)));

        if ($missing === []) {
            return $candidates;
        }

        // Whatever Wikipedia had no article for, asked of both search
        // providers together so their answers compete in one round trip.
        $urls = [];

        foreach (['openverse', 'commons'] as $provider) {
            foreach ($missing as $concept) {
                // The key carries both halves so one flat batch of responses
                // can be sorted back out afterwards.
                $urls[$provider."\n".$concept] = $providers[$provider]($concept);
            }
        }

        $bodies = Http::json($urls);

        foreach (['openverse', 'commons'] as $provider) {
            foreach ($missing as $concept) {
                $body = $bodies[$provider."\n".$concept] ?? null;

                if ($body === null) {
                    continue;
                }

                $hit = $provider === 'openverse'
                    ? $this->readOpenverse($concept, $body, $rng)
                    : $this->readCommons($concept, $body, $rng);

                if ($hit !== null) {
                    $candidates[$concept][] = $hit;
                }
            }
        }

        return $candidates;
    }

    /** @return array<string, callable(string): string> */
    private function providers(): array
    {
        return [
            'wikipedia' => fn (string $c) => 'https://en.wikipedia.org/w/api.php?'.http_build_query([
                'action' => 'query',
                'titles' => ucfirst($c),
                'prop' => 'pageimages',
                'piprop' => 'thumbnail',
                'pithumbsize' => 1600,
                'redirects' => 1,
                'format' => 'json',
            ]),
            'openverse' => fn (string $c) => 'https://api.openverse.org/v1/images/?'.http_build_query([
                'q' => $c,
                'page_size' => 8,
                'license_type' => 'all-cc',
            ]),
            'commons' => fn (string $c) => 'https://commons.wikimedia.org/w/api.php?'.http_build_query([
                'action' => 'query',
                'generator' => 'search',
                'gsrsearch' => $c,
                // Namespace 6 is File. Without it the search returns category
                // and gallery pages, which carry no image at all.
                'gsrnamespace' => 6,
                'gsrlimit' => 8,
                'prop' => 'imageinfo',
                'iiprop' => 'url|extmetadata',
                'iiurlwidth' => 1600,
                'format' => 'json',
            ]),
        ];
    }

    /** @return array{url: string, title: string, credit: string, source: string}|null */
    private function readWikipedia(string $concept, array $body): ?array
    {
        foreach ($body['query']['pages'] ?? [] as $page) {
            $url = $page['thumbnail']['source'] ?? null;
            $title = (string) ($page['title'] ?? '');

            // The RESOLVED title, after redirects. Wikipedia answers "female
            // mammal" by redirecting to "Female" and handing back a portrait
            // of a woman -- the article title is only trustworthy when it is
            // still the thing that was asked for.
            if (! is_string($url) || ! $this->relevant($concept, $title)) {
                continue;
            }

            return [
                'url' => $url,
                'title' => $title,
                'credit' => 'Wikipedia',
                'source' => 'https://en.wikipedia.org/wiki/'.rawurlencode($title),
            ];
        }

        return null;
    }

    /**
     * @return array{url: string, title: string, credit: string, source: string}|null
     */
    private function readOpenverse(string $concept, array $body, ?Rng $rng): ?array
    {
        $usable = [];

        foreach ($body['results'] ?? [] as $result) {
            if ($this->relevant($concept, (string) ($result['title'] ?? ''))) {
                $usable[] = [
                    'url' => (string) $result['url'],
                    'title' => (string) ($result['title'] ?? $concept),
                    'credit' => trim(($result['creator'] ?? 'Unknown').' / '.strtoupper((string) ($result['license'] ?? 'CC'))),
                    'source' => (string) ($result['foreign_landing_url'] ?? ''),
                ];
            }
        }

        return $this->choose($usable, $rng);
    }

    /**
     * @return array{url: string, title: string, credit: string, source: string}|null
     */
    private function readCommons(string $concept, array $body, ?Rng $rng): ?array
    {
        $usable = [];

        foreach ($body['query']['pages'] ?? [] as $page) {
            $title = (string) preg_replace('/^File:|\.\w+$/', '', (string) ($page['title'] ?? ''));
            $url = $page['imageinfo'][0]['thumburl'] ?? null;

            if (is_string($url) && $this->relevant($concept, $title)) {
                $usable[] = [
                    'url' => $url,
                    'title' => $title,
                    'credit' => 'Wikimedia Commons',
                    'source' => (string) ($page['imageinfo'][0]['descriptionurl'] ?? ''),
                ];
            }
        }

        return $this->choose($usable, $rng);
    }

    /**
     * Pick one of several usable photographs.
     *
     * Seeded rather than always-the-first, so asking for the same word twice
     * gives a different picture while replaying a stored deck gives the one
     * that was actually performed. Without this, removing the cache would
     * have made regeneration slower and identical.
     *
     * @param  array<int, array>  $usable
     */
    private function choose(array $usable, ?Rng $rng): ?array
    {
        if ($usable === []) {
            return null;
        }

        return $rng === null ? $usable[0] : $rng->pick($usable);
    }

    /**
     * Does this picture actually show the thing?
     *
     * Every word of the concept has to appear in the title. Requiring only
     * the head noun lets "female mammal" through on a photograph of a wolf,
     * which is how a search provider answers a phrase it has nothing for: not
     * with an error, but confidently, with something else.
     *
     * Matching is on whole words rather than substrings, so "cat" does not
     * match "catalogue", and both sides are reduced to a stem, so "lorry"
     * matches "Lorries at dawn".
     */
    private function relevant(string $concept, string $title): bool
    {
        $titleStems = array_map(
            $this->stem(...),
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: []
        );

        if ($titleStems === []) {
            return false;
        }

        $titleStems = array_flip($titleStems);

        foreach (explode(' ', mb_strtolower($concept)) as $word) {
            if (in_array($word, self::SKIP_IN_TITLE, true)) {
                continue;
            }

            if (! isset($titleStems[$this->stem($word)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Crude singularising, applied to both sides so they meet in the middle.
     *
     * Not a real stemmer: a real one would also fold "mouse" to "mice" and is
     * a dependency. This exists to make a plural title match a singular
     * concept, and over-stemming costs at worst one rejected photograph out
     * of eight candidates.
     */
    private function stem(string $word): string
    {
        if (mb_strlen($word) > 4 && str_ends_with($word, 'ies')) {
            return mb_substr($word, 0, -3).'y';
        }

        if (mb_strlen($word) > 3 && str_ends_with($word, 'es')) {
            return mb_substr($word, 0, -2);
        }

        if (mb_strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
            return mb_substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * Pull the bytes down and keep them.
     *
     * Caching is not about rate limits -- nobody plays this often enough to
     * hit one. It is about the venue: wifi in the back room of a bar fails
     * exactly when a show is running, and a word used in the warm-up should
     * still work in the second half.
     *
     * @param  array<string, array>  $hits
     * @return array<string, array{file: string, title: string, credit: string, source: string}>
     */
    private function download(array $hits): array
    {
        $urls = array_map(fn ($hit) => $hit['url'], $hits);
        $out = [];

        foreach (Http::all($urls, timeout: 6.0) as $concept => $bytes) {
            $image = @imagecreatefromstring($bytes);

            // Not an image, a truncated one, or an SVG -- all of which arrive
            // looking like a successful download.
            if ($image === false) {
                continue;
            }

            // Commons carries pronunciation recordings and icons alongside
            // photographs, and their thumbnails come back as valid 3 KB
            // images. On a projector that is a smudge, so it is rejected
            // here and the next provider's answer gets its turn.
            if (imagesx($image) < self::MIN_EDGE || imagesy($image) < self::MIN_EDGE) {
                imagedestroy($image);

                continue;
            }

            $file = $this->path($concept);
            imagejpeg($this->fit($image), $file, 86);
            imagedestroy($image);

            $out[$concept] = [
                'file' => $file,
                'title' => $hits[$concept]['title'],
                'credit' => $hits[$concept]['credit'],
                'source' => $hits[$concept]['source'],
                // The colour the picture is mostly made of, so a slide can
                // take its accent from its own photograph.
                'tint' => Palette::fromImage($file),
            ];
        }

        return $out;
    }

    /**
     * Scale down to something a slide can hold.
     *
     * A 6000px original is 12 MB in the pptx and makes the download the
     * slowest part of the game.
     */
    private function fit(\GdImage $image, int $max = 1600): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);

        if ($w <= $max && $h <= $max) {
            return $image;
        }

        $scale = $max / max($w, $h);

        return imagescale($image, (int) ($w * $scale), (int) ($h * $scale)) ?: $image;
    }

    private function key(string $concept): string
    {
        return sha1(mb_strtolower(trim($concept)));
    }

    private function path(string $concept): string
    {
        return $this->cacheDir.'/'.$this->key($concept).'.jpg';
    }

}
