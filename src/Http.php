<?php

namespace PitchDeck;

/**
 * Parallel GETs with a hard deadline.
 *
 * Everything here is in service of one number: the deck has to arrive before
 * the room notices it is waiting. Five concepts need a lookup and a download
 * each, and done one after another at 300ms a piece that is three seconds of
 * a performer standing in silence. Run in parallel it is closer to half a
 * second.
 *
 * The deadline is equally load-bearing. A provider that hangs is worse than
 * one that refuses, because a refusal falls through to the next provider
 * immediately and a hang spends the whole budget before failing anyway.
 */
final class Http
{
    private const AGENT = 'PitchDeck/1.0 (https://pitchdeck.251dev.com; improv slide generator)';

    /**
     * How many requests may be in flight at once.
     *
     * Measured on this box: 20 simultaneous handles all succeed, 27 all fail
     * together with no response and no curl error -- not a provider refusing,
     * but something local running out of room. The failure mode is the
     * dangerous kind, because it looks exactly like every provider being down
     * at once. Sixteen leaves margin and fits a whole deck's lookups in one
     * round.
     */
    private const MAX_PARALLEL = 16;

    /**
     * @param  array<string|int, string>  $urls  keyed however the caller likes
     * @return array<string|int, string>  bodies, keyed the same; failures absent
     */
    public static function all(array $urls, float $timeout = 4.0, int $maxBytes = 8_000_000): array
    {
        if ($urls === []) {
            return [];
        }

        if (count($urls) > self::MAX_PARALLEL) {
            $bodies = [];

            foreach (array_chunk($urls, self::MAX_PARALLEL, preserve_keys: true) as $chunk) {
                $bodies += self::all($chunk, $timeout, $maxBytes);
            }

            return $bodies;
        }

        $multi = curl_multi_init();
        $handles = [];

        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => 2000,
                CURLOPT_USERAGENT => self::AGENT,
                CURLOPT_ENCODING => '',
                // Wikimedia answers an unidentified client with a 403, and a
                // referrer-less hotlink with the same.
                CURLOPT_HTTPHEADER => ['Accept: application/json, image/*;q=0.9, */*;q=0.8'],
                // A provider serving something enormous must not be allowed
                // to eat the request's memory limit.
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => fn ($c, $expected, $got) => $got > $maxBytes ? 1 : 0,
            ]);

            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running) {
                curl_multi_select($multi, 0.2);
            }
        } while ($running && $status === CURLM_OK);

        $bodies = [];

        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            // A 200 with an empty body is a failure dressed as a success, and
            // treating it as a hit puts a blank image on a slide.
            if ($code === 200 && is_string($body) && $body !== '') {
                $bodies[$key] = $body;
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $bodies;
    }

    /** @return array<string|int, array> decoded JSON bodies, failures absent */
    public static function json(array $urls, float $timeout = 4.0): array
    {
        $out = [];

        foreach (self::all($urls, $timeout) as $key => $body) {
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                $out[$key] = $decoded;
            }
        }

        return $out;
    }
}
