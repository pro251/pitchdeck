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
     * How long a remembered miss stands.
     *
     * Long enough that a show never pays twice for the same unillustratable
     * word, short enough that a provider being down for an afternoon does not
     * permanently blacklist half the dictionary.
     */
    private const MISS_TTL = 86400 * 3;

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
    public function find(array $concepts): array
    {
        $found = [];
        $wanted = [];

        foreach (array_unique($concepts) as $concept) {
            $cached = $this->cached($concept);

            if ($cached !== null) {
                // A remembered miss: no provider could illustrate this, and
                // asking all three again costs a second of a performer
                // standing in silence.
                if ($cached !== []) {
                    $found[$concept] = $cached;
                }

                continue;
            }

            $wanted[] = $concept;
        }

        if ($wanted === []) {
            return $found;
        }

        /*
         * Every provider, for every concept, in ONE parallel round.
         *
         * Asking them in sequence -- Wikipedia, then Openverse for whatever
         * it missed, then Commons -- is three round trips plus a download
         * each, and measured 4.8 seconds. The providers are keyless and the
         * volume is a few dozen calls a night, so there is nothing to save by
         * being sparing: ask all three at once, keep whichever answers best,
         * and spend one round trip instead of six.
         */
        $candidates = $this->lookup($wanted);

        // Two download rounds: if the preferred candidate turns out to be a
        // broken file or a 3 KB pronunciation thumbnail, the next provider's
        // answer gets its turn rather than the concept being lost.
        for ($round = 0; $round < 2 && $wanted !== []; $round++) {
            $attempt = [];

            foreach ($wanted as $concept) {
                if (isset($candidates[$concept][$round])) {
                    $attempt[$concept] = $candidates[$concept][$round];
                }
            }

            foreach ($this->download($attempt) as $concept => $image) {
                $found[$concept] = $image;
            }

            $wanted = array_values(array_diff($wanted, array_keys($found)));
        }

        // Remember the failures too, with a timestamp, so the next deck does
        // not pay for them again.
        foreach ($wanted as $concept) {
            file_put_contents($this->metaPath($concept), json_encode([
                'found' => false,
                'at' => time(),
            ]));
        }

        return $found;
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
    private function lookup(array $concepts): array
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
                    ? $this->readOpenverse($concept, $body)
                    : $this->readCommons($concept, $body);

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

    /** @return array{url: string, title: string, credit: string, source: string}|null */
    private function readOpenverse(string $concept, array $body): ?array
    {
        foreach ($body['results'] ?? [] as $result) {
            if (! $this->relevant($concept, (string) ($result['title'] ?? ''))) {
                continue;
            }

            return [
                'url' => (string) $result['url'],
                'title' => (string) ($result['title'] ?? $concept),
                'credit' => trim(($result['creator'] ?? 'Unknown').' / '.strtoupper((string) ($result['license'] ?? 'CC'))),
                'source' => (string) ($result['foreign_landing_url'] ?? ''),
            ];
        }

        return null;
    }

    /** @return array{url: string, title: string, credit: string, source: string}|null */
    private function readCommons(string $concept, array $body): ?array
    {
        foreach ($body['query']['pages'] ?? [] as $page) {
            $title = (string) preg_replace('/^File:|\.\w+$/', '', (string) ($page['title'] ?? ''));
            $url = $page['imageinfo'][0]['thumburl'] ?? null;

            if (! is_string($url) || ! $this->relevant($concept, $title)) {
                continue;
            }

            return [
                'url' => $url,
                'title' => $title,
                'credit' => 'Wikimedia Commons',
                'source' => (string) ($page['imageinfo'][0]['descriptionurl'] ?? ''),
            ];
        }

        return null;
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

            $meta = [
                'file' => $file,
                'title' => $hits[$concept]['title'],
                'credit' => $hits[$concept]['credit'],
                'source' => $hits[$concept]['source'],
                // The colour the picture is mostly made of, so a slide can
                // take its accent from its own photograph.
                'tint' => Palette::fromImage($file),
            ];

            file_put_contents($this->metaPath($concept), json_encode($meta, JSON_PRETTY_PRINT));
            $out[$concept] = $meta;
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

    private function metaPath(string $concept): string
    {
        return $this->cacheDir.'/'.$this->key($concept).'.json';
    }

    /**
     * What we already know about this concept.
     *
     * Three answers, and the third is the point: an array is a cached image,
     * an empty array is a remembered miss, and null means nobody has asked
     * yet. Without the middle one, every deck containing one unillustratable
     * word pays three providers' latency for it again.
     *
     * @return array{file: string, title: string, credit: string, source: string}|array{}|null
     */
    private function cached(string $concept): ?array
    {
        $meta = $this->metaPath($concept);

        if (! is_file($meta)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($meta), true);

        if (! is_array($decoded)) {
            return null;
        }

        if (($decoded['found'] ?? true) === false) {
            return (time() - (int) ($decoded['at'] ?? 0)) < self::MISS_TTL ? [] : null;
        }

        $file = $this->path($concept);

        if (! is_file($file) || filesize($file) === 0) {
            return null;
        }

        // Pictures cached before tints existed. Sampled once and written
        // back, rather than on every read: this is a 16x16 rescale, which is
        // cheap but not free, and a deck does five of them.
        if (! array_key_exists('tint', $decoded)) {
            $decoded['tint'] = Palette::fromImage($file);
            file_put_contents($meta, json_encode([...$decoded, 'file' => $file], JSON_PRETTY_PRINT));
        }

        return [...$decoded, 'file' => $file];
    }
}
