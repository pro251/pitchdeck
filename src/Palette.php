<?php

namespace PitchDeck;

/**
 * The colours a deck is built in.
 *
 * The words drifted and the pictures drifted, and every deck still came out
 * the same dark grey with the same red on it -- which made the whole thing
 * look like one template filled in repeatedly, because it was.
 *
 * Two things vary now. A deck picks a palette, so two decks do not look
 * alike; and within a deck each slide picks a ground from that palette, so
 * slide four does not look like slide two. On top of that, a slide showing a
 * photograph takes its accent FROM that photograph -- the visual drift
 * follows the semantic one, and a deck about langoustines ends up the colour
 * of a langoustine.
 *
 * These are real conference-deck palettes rather than anything clever. The
 * joke needs the deck to look like it means it.
 */
final class Palette
{
    /**
     * bg    the usual ground
     * alt   a second ground, so slides can differ without leaving the scheme
     * ink   text on bg and alt
     * muted secondary text
     * accent the loud one: bars, figures, the title ground
     */
    public const SCHEMES = [
        'boardroom' => ['bg' => '0F1A2B', 'alt' => '16263E', 'ink' => 'F2F4F8', 'muted' => '8FA3BF', 'accent' => 'E12A20'],
        'sodium' => ['bg' => '121010', 'alt' => '1F1A15', 'ink' => 'F8F4EC', 'muted' => 'A2988A', 'accent' => 'F0A202'],
        'blueprint' => ['bg' => '0B2545', 'alt' => '123862', 'ink' => 'E8F1F8', 'muted' => '89A9C4', 'accent' => '4CC9F0'],
        'ledger' => ['bg' => 'F2F5F1', 'alt' => 'E2E8DF', 'ink' => '11261B', 'muted' => '5E7266', 'accent' => '2E7D52'],
        'oxblood' => ['bg' => '1A0E12', 'alt' => '2A161C', 'ink' => 'F6E9EC', 'muted' => 'A98B93', 'accent' => 'C0392F'],
        'concrete' => ['bg' => 'E6E4DF', 'alt' => 'D3D0C8', 'ink' => '1C1C1A', 'muted' => '6A6862', 'accent' => 'D2431E'],
        'memo' => ['bg' => '16122B', 'alt' => '241E42', 'ink' => 'EDE9FB', 'muted' => '9A91C4', 'accent' => '7756FF'],
        'annual' => ['bg' => '06282B', 'alt' => '0D3C40', 'ink' => 'E4F2F1', 'muted' => '7FAAA7', 'accent' => '19A49B'],
        'mustard' => ['bg' => '1B1A14', 'alt' => '2A2819', 'ink' => 'F7F3E3', 'muted' => 'A39C80', 'accent' => 'D9A521'],
        'quarterly' => ['bg' => '190F1C', 'alt' => '2A1830', 'ink' => 'F7E9F6', 'muted' => 'AE8FAC', 'accent' => 'D6248C'],
        'newsprint' => ['bg' => 'EDEAE3', 'alt' => 'DEDAD0', 'ink' => '17171A', 'muted' => '66645E', 'accent' => '1F4FD8'],
        'forest' => ['bg' => '0E1B12', 'alt' => '17291C', 'ink' => 'E9F2E9', 'muted' => '89A58E', 'accent' => 'E07A3F'],
    ];

    /** @return array{name: string, bg: string, alt: string, ink: string, muted: string, accent: string} */
    public static function pick(Rng $rng): array
    {
        $name = $rng->pick(array_keys(self::SCHEMES));

        return ['name' => $name] + self::SCHEMES[$name];
    }

    /**
     * Black or white, whichever can be read on the given colour.
     *
     * Needed because the accent is sometimes the ground -- and a palette
     * whose accent is mustard wants dark text where one whose accent is
     * oxblood wants light. Getting it wrong does not look like a style
     * choice, it looks like the slide failed to load.
     */
    public static function readableOn(string $hex): string
    {
        // Measured both ways rather than compared against a luminance
        // threshold. A threshold calls amber dark and puts white on it at
        // 2.3:1, which on a projector in a half-lit room is not text.
        return self::contrast('17171A', $hex) >= self::contrast('FFFFFF', $hex) ? '17171A' : 'FFFFFF';
    }

    /** Is this a light ground? Photographs and scrims need to know. */
    public static function isLight(string $hex): bool
    {
        return self::readableOn($hex) === '17171A';
    }

    /**
     * Relative luminance, 0 to 1.
     *
     * The sRGB gamma step matters here: averaging the raw channels calls
     * mustard dark and navy light, which is the opposite of what the eye
     * does.
     */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        $channel = function (float $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /**
     * Force a colour to be legible against a given ground.
     *
     * A tint sampled from a photograph is whatever that photograph happened
     * to be -- often a muddy brown, sometimes nearly the ground colour. This
     * pushes it until it reads, keeping its hue, so the slide still takes its
     * character from the picture without becoming unreadable.
     */
    public static function legible(string $tint, string $against): string
    {
        [$h, $s, $l] = self::toHsl($tint);

        // A grey tint has no character to preserve; give it some so the slide
        // does not come out looking like a fault.
        $s = max($s, 0.42);

        $wantLight = ! self::isLight($against);

        // Walk the lightness away from the ground until there is real
        // separation, keeping the hue.
        for ($i = 0; $i < 18; $i++) {
            $candidate = self::fromHsl($h, $s, $l);

            if (self::contrast($candidate, $against) >= 4.0) {
                return $candidate;
            }

            $l += $wantLight ? 0.05 : -0.05;

            if ($l <= 0.04 || $l >= 0.96) {
                break;
            }
        }

        // The hue cannot be made to work against this ground -- which happens
        // on the slides where the ACCENT is the ground, and the sampled
        // colour is a near neighbour of it. Legibility wins over character
        // here: a guess that merely looks nice is how white ends up on amber
        // at 2.3:1, in a bar, with the house lights up.
        return self::readableOn($against);
    }

    /** WCAG contrast ratio, 1 to 21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @return array{int, int, int} */
    public static function rgb(string $hex): array
    {
        $int = ((int) hexdec(ltrim($hex, '#'))) & 0xFFFFFF;

        return [($int >> 16) & 255, ($int >> 8) & 255, $int & 255];
    }

    /** @return array{float, float, float} hue 0-1, saturation 0-1, lightness 0-1 */
    public static function toHsl(string $hex): array
    {
        // Cast: PHP's division returns an INT when it divides evenly, so
        // 0/255 and 255/255 come back as int 0 and int 1, and this function
        // quietly stops honouring its own float return type.
        [$r, $g, $b] = array_map(fn ($c) => (float) $c / 255, self::rgb($hex));

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d === 0.0) {
            return [0.0, 0.0, $l];
        }

        // Pure black and pure white reach here only through floating-point
        // dust, and both make a divisor of zero.
        $divisor = $l > 0.5 ? 2 - $max - $min : $max + $min;

        if ($divisor <= 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $d / $divisor;

        $h = match ($max) {
            $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h / 6, $s, $l];
    }

    public static function fromHsl(float $h, float $s, float $l): string
    {
        if ($s === 0.0) {
            $v = (int) round($l * 255);

            return sprintf('%02X%02X%02X', $v, $v, $v);
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $channel = function (float $t) use ($p, $q): int {
            $t = fmod($t + 1, 1);

            $value = match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };

            return (int) round($value * 255);
        };

        return sprintf('%02X%02X%02X', $channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3));
    }

    /**
     * The colour a photograph is mostly made of.
     *
     * Scaled down to 16x16 and then judged on saturation rather than on how
     * often a pixel occurs: the commonest colour in a photograph is usually
     * sky, wall or table, and the colour a person would say the picture "is"
     * is the one that stands out from those.
     */
    public static function fromImage(string $path): ?string
    {
        $image = @imagecreatefromjpeg($path);

        if ($image === false) {
            return null;
        }

        $small = imagescale($image, 16, 16);
        imagedestroy($image);

        if ($small === false) {
            return null;
        }

        $best = null;
        $bestScore = -1.0;

        for ($x = 0; $x < 16; $x++) {
            for ($y = 0; $y < 16; $y++) {
                // Masked: on a truecolour image imagecolorat packs alpha
                // into the high byte, and 0x7FRRGGBB formats as eight hex
                // digits, which then parses as the wrong three channels.
                $hex = sprintf('%06X', imagecolorat($small, $x, $y) & 0xFFFFFF);
                [, $s, $l] = self::toHsl($hex);

                // Saturated, but not a blown highlight or a black shadow --
                // neither of which is a colour anybody would name.
                $score = $s * (1 - abs($l - 0.5) * 1.4);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $hex;
                }
            }
        }

        imagedestroy($small);

        // Nothing in the picture had a colour worth taking. Returning the
        // best of a grey lot would hand the slide a near-white accent and
        // look like a bug; no tint means the slide keeps the deck's own.
        return $bestScore < 0.08 ? null : $best;
    }
}
