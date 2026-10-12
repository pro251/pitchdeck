<?php

namespace PitchDeck;

use ZipArchive;

/**
 * The deck as a real PowerPoint file.
 *
 * A .pptx is a zip of OOXML parts, and PowerPoint is strict about which ones
 * have to be present: a presentation with no slide master, no layout or no
 * theme is refused outright rather than opened with the missing piece
 * defaulted. Everything written here is the minimum that satisfies that, with
 * each slide's shapes placed directly rather than inherited from placeholders
 * -- a layout with real placeholders would mean a second set of geometry to
 * keep in step with the browser renderer.
 *
 * No library. The alternative is a composer dependency and a vendor
 * directory to carry around for roughly three hundred lines of XML.
 */
final class Pptx
{
    /** English Metric Units: 914,400 to the inch. A 16:9 slide is 13.333in. */
    private const W = 12192000;

    private const H = 6858000;

    private const MARGIN = 838200;

    public function __construct(private Deck $deck) {}

    /** Write the file and return its path. */
    public function write(array $deck, string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot write $path");
        }

        $slides = $deck['slides'];
        $media = [];

        // Collect pictures first: each slide's relationship file has to name
        // the media it uses, so the numbering must be settled before any
        // slide XML is written.
        foreach ($slides as $i => $slide) {
            if (! empty($slide['image']['file']) && is_file($slide['image']['file'])) {
                $media[$i] = 'image'.($i + 1).'.jpg';
                $zip->addFile($slide['image']['file'], 'ppt/media/'.$media[$i]);
            }
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($slides)));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('ppt/presentation.xml', $this->presentation(count($slides)));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->presentationRels(count($slides)));
        $zip->addFromString('ppt/theme/theme1.xml', $this->theme());
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $this->master());
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->masterRels());
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', $this->layout());
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $this->layoutRels());

        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            $zip->addFromString("ppt/slides/slide$n.xml", $this->slide($slide, isset($media[$i])));
            $zip->addFromString("ppt/slides/_rels/slide$n.xml.rels", $this->slideRels($media[$i] ?? null));
        }

        $zip->close();

        return $path;
    }

    // ---- slides -----------------------------------------------------------

    private function slide(array $slide, bool $hasImage): string
    {
        $layout = $slide['layout'] ?? 'plain';

        // On these the photograph is the background and the words sit over
        // it, so it is drawn first and a scrim goes between.
        $behind = $hasImage && in_array($layout, ['photo', 'overlay'], true);

        $shapes = '';

        if ($behind) {
            $shapes .= $this->picture(4, 0, 0, self::W, self::H).$this->scrim(5);
        }

        $shapes .= match ($slide['type']) {
            'title' => $this->titleShapes($slide),
            'chart' => $this->chartShapes($slide),
            'quote' => $this->quoteShapes($slide),
            'big' => $this->bigShapes($slide),
            'question' => $this->questionShapes($slide),
            'list', 'closing' => $this->listShapes($slide),
            default => $this->statementShapes($slide, $hasImage && ! $behind, $layout),
        };

        return $this->xml('<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:cSld>'.$this->background($slide, $behind).'<p:spTree>'
            .'<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            .'<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .$shapes
            .'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>');
    }

    private function background(array $slide, bool $behind): string
    {
        // A slide with a photograph across it paints no colour -- the picture
        // is the first shape in the tree and covers the whole frame anyway.
        if ($behind) {
            return '';
        }

        // Resolved by the Deck from its palette, so the file matches what was
        // on the screen.
        return '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="'.$slide['bg'].'"/></a:solidFill>'
            .'<a:effectLst/></p:bgPr></p:bg>';
    }

    private function titleShapes(array $slide): string
    {
        return $this->textBox(2, self::MARGIN, 2200000, self::W - self::MARGIN * 2, 2400000,
            $slide['heading'], 5400, $slide['ink'], bold: true)
            .$this->textBox(3, self::MARGIN, 4800000, self::W - self::MARGIN * 2, 700000,
                mb_strtoupper($slide['support']), 1400, $slide['muted'], spacing: 300);
    }

    private function statementShapes(array $slide, bool $beside, string $layout): string
    {
        // Text one side, photograph the other -- or, with no picture beside
        // it, the full width.
        $textWidth = $beside ? 5000000 : self::W - self::MARGIN * 2;
        $left = $beside && $layout === 'left' ? 6400000 : self::MARGIN;

        $shapes = $this->textBox(2, $left, 1700000, $textWidth, 2600000,
            $slide['heading'], 3600, $slide['ink'], bold: true)
            .$this->textBox(3, $left, 4500000, $textWidth, 900000,
                $slide['support'], 1600, $slide['muted']);

        if ($beside) {
            $x = $layout === 'left' ? self::MARGIN : 6400000;
            $shapes .= $this->picture(4, $x, 1100000, 4950000, 4650000);
        }

        return $shapes;
    }

    private function quoteShapes(array $slide): string
    {
        return $this->textBox(2, self::MARGIN * 2, 1900000, self::W - self::MARGIN * 4, 2600000,
            "\u{201C}".$slide['heading']."\u{201D}", 3200, $slide['ink'], bold: true, align: 'ctr')
            .$this->textBox(3, self::MARGIN * 2, 4700000, self::W - self::MARGIN * 4, 700000,
                "\u{2014} ".$slide['support'], 1800, $slide['accent'], align: 'ctr');
    }

    /** One enormous figure, which is how a real deck hides a weak argument. */
    private function bigShapes(array $slide): string
    {
        return $this->textBox(2, self::MARGIN, 1100000, self::W - self::MARGIN * 2, 600000,
            mb_strtoupper($slide['kicker'] ?? ''), 1400, $slide['muted'], align: 'ctr', spacing: 300)
            .$this->textBox(3, self::MARGIN, 1900000, self::W - self::MARGIN * 2, 2900000,
                $slide['heading'], 16000, $slide['accent'], bold: true, align: 'ctr')
            .$this->textBox(4, self::MARGIN * 2, 5100000, self::W - self::MARGIN * 4, 900000,
                $slide['support'], 2000, $slide['ink'], align: 'ctr');
    }

    private function questionShapes(array $slide): string
    {
        return $this->textBox(2, self::MARGIN, 2000000, self::W - self::MARGIN * 2, 3200000,
            $slide['heading'], 5000, $slide['ink'], bold: true, align: 'ctr');
    }

    /** The mid-deck lessons and the closing asks are the same shape. */
    private function listShapes(array $slide): string
    {
        $bullets = $slide['bullets'] ?? [];

        $shapes = $this->textBox(2, self::MARGIN, $bullets === [] ? 2800000 : 1100000,
            self::W - self::MARGIN * 2, 1400000,
            $slide['heading'], $bullets === [] ? 5400 : 3800, $slide['ink'], bold: true,
            align: $bullets === [] ? 'ctr' : 'l');

        $y = 2900000;
        $id = 10;

        foreach ($bullets as $bullet) {
            $shapes .= $this->textBox($id++, self::MARGIN, $y, self::W - self::MARGIN * 2, 700000,
                "\u{2022}   ".$bullet, 2000, $slide['ink']);
            $y += 800000;
        }

        return $shapes;
    }

    private function chartShapes(array $slide): string
    {
        $shapes = $this->textBox(2, self::MARGIN, 700000, self::W - self::MARGIN * 2, 1000000,
            $slide['heading'], 3200, $slide['ink'], bold: true);

        $bars = $slide['bars'];
        $max = max(array_column($bars, 'value')) ?: 1;
        $plotTop = 2100000;
        $plotHeight = 3500000;
        $slot = (self::W - self::MARGIN * 2) / count($bars);
        $barWidth = (int) ($slot * 0.52);
        $id = 10;

        foreach ($bars as $i => $bar) {
            $height = max(60000, (int) ($plotHeight * ($bar['value'] / $max)));
            $x = (int) (self::MARGIN + $slot * $i + ($slot - $barWidth) / 2);
            $y = $plotTop + $plotHeight - $height;

            $shapes .= $this->rect($id++, $x, $y, $barWidth, $height, $slide['accent']);
            $shapes .= $this->textBox($id++, $x - 100000, $y - 500000, $barWidth + 200000, 450000,
                (string) $bar['value'], 1400, $slide['ink'], bold: true, align: 'ctr');
            $shapes .= $this->textBox($id++, $x - 100000, $plotTop + $plotHeight + 90000,
                $barWidth + 200000, 450000, $bar['label'], 1200, $slide['muted'], align: 'ctr');
        }

        return $shapes;
    }

    // ---- shape primitives -------------------------------------------------

    private function textBox(
        int $id, int $x, int $y, int $w, int $h, string $text,
        int $size, string $colour, bool $bold = false, string $align = 'l', int $spacing = 0,
    ): string {
        $runs = '';

        // A blank paragraph still needs a run, or PowerPoint shows an
        // empty box where the text should be rather than nothing at all.
        foreach (explode("\n", $text) as $line) {
            $runs .= '<a:p><a:pPr algn="'.$align.'"/><a:r><a:rPr lang="en-US" sz="'.$size.'" '
                .'b="'.($bold ? 1 : 0).'" dirty="0"'.($spacing ? ' spc="'.$spacing.'"' : '').'>'
                .'<a:solidFill><a:srgbClr val="'.$colour.'"/></a:solidFill>'
                .'<a:latin typeface="Arial"/></a:rPr>'
                .'<a:t>'.$this->esc($line).'</a:t></a:r></a:p>';
        }

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="Text '.$id.'"/>'
            .'<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$w.'" cy="'.$h.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            .'<p:txBody><a:bodyPr wrap="square" rtlCol="0" anchor="t"><a:normAutofit/></a:bodyPr>'
            .'<a:lstStyle/>'.$runs.'</p:txBody></p:sp>';
    }

    private function rect(int $id, int $x, int $y, int $w, int $h, string $colour): string
    {
        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="Bar '.$id.'"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$w.'" cy="'.$h.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            .'<a:solidFill><a:srgbClr val="'.$colour.'"/></a:solidFill></p:spPr>'
            .'<p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody></p:sp>';
    }

    /** A translucent black panel, so white text stays readable over a photo. */
    private function scrim(int $id): string
    {
        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="Scrim"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.self::W.'" cy="'.self::H.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            .'<a:solidFill><a:srgbClr val="000000"><a:alpha val="58000"/></a:srgbClr></a:solidFill>'
            .'</p:spPr><p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody></p:sp>';
    }

    private function picture(int $id, int $x, int $y, int $w, int $h): string
    {
        // srcRect clipping would need the real pixel dimensions; a:stretch
        // fills the frame instead, which can distort. The frames above are
        // close to the images' own aspect for that reason.
        return '<p:pic><p:nvPicPr><p:cNvPr id="'.$id.'" name="Picture"/>'
            .'<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            .'<p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            .'<p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$w.'" cy="'.$h.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    // ---- package parts ----------------------------------------------------

    private function contentTypes(int $slides): string
    {
        $overrides = '';

        for ($n = 1; $n <= $slides; $n++) {
            $overrides .= '<Override PartName="/ppt/slides/slide'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }

        return $this->xml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="jpg" ContentType="image/jpeg"/>'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            .'<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            .'<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            .'<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            .$overrides.'</Types>');
    }

    private function rootRels(): string
    {
        return $this->xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            .'</Relationships>');
    }

    private function presentation(int $slides): string
    {
        $ids = '';

        for ($n = 1; $n <= $slides; $n++) {
            // Slide ids must be 256 or above; anything lower is rejected.
            $ids .= '<p:sldId id="'.(255 + $n).'" r:id="rId'.($n + 1).'"/>';
        }

        return $this->xml('<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            .'<p:sldIdLst>'.$ids.'</p:sldIdLst>'
            .'<p:sldSz cx="'.self::W.'" cy="'.self::H.'"/>'
            .'<p:notesSz cx="'.self::H.'" cy="'.self::W.'"/></p:presentation>');
    }

    private function presentationRels(int $slides): string
    {
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';

        for ($n = 1; $n <= $slides; $n++) {
            $rels .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide'.$n.'.xml"/>';
        }

        $rels .= '<Relationship Id="rId'.($slides + 2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>';

        return $this->xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
    }

    private function slideRels(?string $media): string
    {
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>';

        if ($media !== null) {
            $rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/'.$media.'"/>';
        }

        return $this->xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
    }

    private function masterRels(): string
    {
        return $this->xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
            .'</Relationships>');
    }

    private function layoutRels(): string
    {
        return $this->xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
            .'</Relationships>');
    }

    private function emptyTree(): string
    {
        return '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            .'<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree>';
    }

    private function master(): string
    {
        return $this->xml('<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:cSld>'.$this->emptyTree().'</p:cSld>'
            .'<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" '
            .'accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            .'<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            .'</p:sldMaster>');
    }

    private function layout(): string
    {
        return $this->xml('<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1">'
            .'<p:cSld name="Blank">'.$this->emptyTree().'</p:cSld>'
            .'<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
    }

    /**
     * A theme, which PowerPoint requires even though nothing here uses it.
     *
     * The colour scheme must name all twelve slots and the font scheme both
     * faces; an incomplete theme is a corrupt file, not a defaulted one.
     */
    private function theme(): string
    {
        $slots = ['dk1' => '000000', 'lt1' => 'FFFFFF', 'dk2' => '15161A', 'lt2' => 'EEECE1',
            'accent1' => 'C8452F', 'accent2' => 'D97757', 'accent3' => '8C6A5D', 'accent4' => '5B6472',
            'accent5' => 'A8ACBA', 'accent6' => 'E8C9BE', 'hlink' => 'C8452F', 'folHlink' => '8C6A5D'];
        $scheme = '';

        foreach ($slots as $name => $hex) {
            $inner = '<a:srgbClr val="'.$hex.'"/>';
            // dk1 and lt1 are system colours, not sRGB, and PowerPoint will
            // not accept a plain value for them.
            $scheme .= in_array($name, ['dk1', 'lt1'], true)
                ? '<a:'.$name.'><a:sysClr val="'.($name === 'dk1' ? 'windowText' : 'window').'" lastClr="'.$hex.'"/></a:'.$name.'>'
                : '<a:'.$name.'>'.$inner.'</a:'.$name.'>';
        }

        $fonts = '<a:latin typeface="Arial"/><a:ea typeface=""/><a:cs typeface=""/>';
        $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $line = '<a:ln w="9525" cap="flat"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>';

        return $this->xml('<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="PowerPointless">'
            .'<a:themeElements><a:clrScheme name="PowerPointless">'.$scheme.'</a:clrScheme>'
            .'<a:fontScheme name="PowerPointless"><a:majorFont>'.$fonts.'</a:majorFont>'
            .'<a:minorFont>'.$fonts.'</a:minorFont></a:fontScheme>'
            .'<a:fmtScheme name="PowerPointless">'
            .'<a:fillStyleLst>'.$fill.$fill.$fill.'</a:fillStyleLst>'
            .'<a:lnStyleLst>'.$line.$line.$line.'</a:lnStyleLst>'
            .'<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle>'
            .'<a:effectStyle><a:effectLst/></a:effectStyle>'
            .'<a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            .'<a:bgFillStyleLst>'.$fill.$fill.$fill.'</a:bgFillStyleLst>'
            .'</a:fmtScheme></a:themeElements></a:theme>');
    }

    private function xml(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".$body;
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
