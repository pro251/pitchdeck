<?php

use PitchDeck\Pptx;

/*
 * PowerPoint does not open a package with a missing part and report which one
 * -- it refuses the file as corrupt. There is no PowerPoint on this machine
 * to try it against, so these assert the structural rules that file format
 * enforces: every part declared, every relationship resolving, every
 * reference pointing at something real.
 */
function pptxOf(string $suggestion, int $seed = 4242): ZipArchive
{
    $built = deck()->build($suggestion, $seed);
    $path = tempnam(sys_get_temp_dir(), 'pdtest').'.pptx';
    (new Pptx(deck()))->write($built, $path);

    $zip = new ZipArchive;
    $zip->open($path);

    return $zip;
}

test('it writes one slide part per slide, plus the scaffolding', function () {
    $zip = pptxOf('cheese');
    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    foreach ([
        '[Content_Types].xml',
        '_rels/.rels',
        'ppt/presentation.xml',
        'ppt/_rels/presentation.xml.rels',
        // Each of these is required: a presentation with no master, no layout
        // or no theme is rejected rather than defaulted.
        'ppt/slideMasters/slideMaster1.xml',
        'ppt/slideLayouts/slideLayout1.xml',
        'ppt/theme/theme1.xml',
    ] as $required) {
        expect($names)->toContain($required);
    }

    for ($n = 1; $n <= 5; $n++) {
        expect($names)->toContain("ppt/slides/slide$n.xml");
        expect($names)->toContain("ppt/slides/_rels/slide$n.xml.rels");
    }
});

test('every part is well-formed XML', function () {
    $zip = pptxOf('lobster');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (! str_ends_with($name, '.xml') && ! str_ends_with($name, '.rels')) {
            continue;
        }

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($zip->getFromName($name));
        libxml_use_internal_errors($previous);

        expect($parsed !== false)->toBeTruthy("malformed XML in $name");
    }
});

test('every relationship a part uses actually exists', function () {
    $zip = pptxOf('trombone');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (! str_ends_with($name, '.xml') || str_contains($name, '_rels/')) {
            continue;
        }

        $dir = str_contains($name, '/') ? dirname($name).'/' : '';
        $relsPath = $dir.'_rels/'.basename($name).'.rels';
        $declared = [];

        if (($relsXml = $zip->getFromName($relsPath)) !== false) {
            foreach (simplexml_load_string($relsXml) as $rel) {
                $declared[] = (string) $rel['Id'];
            }
        }

        preg_match_all('/r:(?:id|embed)="(rId\d+)"/', $zip->getFromName($name), $used);

        foreach (array_unique($used[1]) as $id) {
            expect($declared)->toContain($id, "$name uses $id which $relsPath does not declare");
        }
    }
});

test('the content types cover every part in the package', function () {
    $zip = pptxOf('cheese');
    $types = simplexml_load_string($zip->getFromName('[Content_Types].xml'));

    $defaults = [];
    $overrides = [];

    foreach ($types as $entry) {
        if ($entry->getName() === 'Default') {
            $defaults[] = strtolower((string) $entry['Extension']);
        } else {
            $overrides[] = ltrim((string) $entry['PartName'], '/');
        }
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if ($name === '[Content_Types].xml' || in_array($name, $overrides, true)) {
            continue;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        expect($defaults)->toContain($extension, "$name has no Override and no Default");
    }
});

// Slide ids below 256 are rejected by the format.
test('the slide ids are in the legal range', function () {
    $zip = pptxOf('cheese');
    $xml = $zip->getFromName('ppt/presentation.xml');

    preg_match_all('/<p:sldId id="(\d+)"/', $xml, $ids);

    expect($ids[1])->toHaveCount(5);

    foreach ($ids[1] as $id) {
        expect((int) $id >= 256)->toBeTruthy("slide id $id is below the 256 minimum");
    }
});

test('every embedded picture is a real JPEG', function () {
    $zip = pptxOf('cheese');
    $found = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (str_starts_with($name, 'ppt/media/')) {
            expect(substr($zip->getFromName($name), 0, 3))->toBe("\xff\xd8\xff", "$name is not a JPEG");
            $found++;
        }
    }

    expect($found > 0)->toBeTruthy('no pictures made it into the file at all');
});

// Somebody will type a quote mark or an ampersand, and an unescaped one turns
// the slide into a parse error rather than a joke.
test('punctuation in the suggestion does not break the XML', function () {
    $zip = pptxOf('Dave & "Steve\'s" <lobster>');

    $xml = $zip->getFromName('ppt/slides/slide1.xml');

    expect(simplexml_load_string($xml) !== false)->toBeTruthy('the title slide is malformed');
    expect($xml)->notToContain('<lobster>', 'the angle brackets were not escaped');
});
