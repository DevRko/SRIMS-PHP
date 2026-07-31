<?php
/**
 * SRIMS - Minimal PDF writer
 * -----------------------------------------------------------------------
 * A tiny, self-contained PDF generator with no external dependencies
 * (no Composer, no FPDF, nothing to install) — just plain PHP writing a
 * valid PDF file byte-for-byte. Supports what a receipt/voucher needs:
 * positioned text (regular/bold Helvetica), straight lines, and rectangle
 * outlines, on a single A4 page.
 *
 * Coordinates for text()/line()/rect() are given as (x, y) in points from
 * the TOP-LEFT of the page (like screen/CSS coordinates) — this class
 * converts internally to PDF's bottom-left origin.
 *
 * Only plain ASCII / Latin-1 text is supported (non-Latin characters are
 * transliterated where possible, otherwise dropped) since embedding a
 * full Unicode font is out of scope for a dependency-free helper.
 * -----------------------------------------------------------------------
 */
class SimplePdf
{
    private float $pageWidth = 595.28;  // A4 portrait, points
    private float $pageHeight = 841.89;
    private string $buffer = '';
    private string $currentFont = 'F1';
    private float $currentSize = 10;
    /** @var array<int, array{name:string,data:string,w:int,h:int}> */
    private array $images = [];

    public function setFont(string $style = '', float $size = 10): void
    {
        $this->currentFont = ($style === 'B') ? 'F2' : 'F1';
        $this->currentSize = $size;
    }

    /** Draw a line of text. $style: '' = regular, 'B' = bold. */
    public function text(float $x, float $yTop, string $txt, string $style = '', ?float $size = null): void
    {
        $font = ($style === 'B') ? 'F2' : $this->currentFont;
        $sz = $size ?? $this->currentSize;
        $yBottom = $this->pageHeight - $yTop;
        $this->buffer .= 'BT /' . $font . ' ' . $this->num($sz) . ' Tf 1 0 0 1 '
            . $this->num($x) . ' ' . $this->num($yBottom) . ' Tm (' . $this->escape($txt) . ") Tj ET\n";
    }

    /** Straight line between two points. */
    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.6): void
    {
        $this->buffer .= $this->num($width) . " w\n"
            . $this->num($x1) . ' ' . $this->num($this->pageHeight - $y1) . ' m '
            . $this->num($x2) . ' ' . $this->num($this->pageHeight - $y2) . " l S\n";
    }

    /** Rectangle outline. $x,$yTop = top-left corner. */
    public function rect(float $x, float $yTop, float $w, float $h, float $lineWidth = 0.6): void
    {
        $yBottom = $this->pageHeight - $yTop - $h;
        $this->buffer .= $this->num($lineWidth) . " w\n"
            . $this->num($x) . ' ' . $this->num($yBottom) . ' ' . $this->num($w) . ' ' . $this->num($h) . " re S\n";
    }

    /** Solid light-gray filled rectangle (e.g. table header band). */
    public function filledRect(float $x, float $yTop, float $w, float $h, float $gray = 0.93): void
    {
        $yBottom = $this->pageHeight - $yTop - $h;
        $this->buffer .= $this->num($gray) . ' g '
            . $this->num($x) . ' ' . $this->num($yBottom) . ' ' . $this->num($w) . ' ' . $this->num($h) . " re f 0 g\n";
    }

    /** Solid rectangle filled with an RGB color given as a "#RRGGBB" hex string. */
    public function filledRectColor(float $x, float $yTop, float $w, float $h, string $hexColor): void
    {
        $hex = ltrim($hexColor, '#');
        if (strlen($hex) !== 6) { $this->filledRect($x, $yTop, $w, $h); return; }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $yBottom = $this->pageHeight - $yTop - $h;
        $this->buffer .= $this->num($r) . ' ' . $this->num($g) . ' ' . $this->num($b) . ' rg '
            . $this->num($x) . ' ' . $this->num($yBottom) . ' ' . $this->num($w) . ' ' . $this->num($h) . " re f 0 g\n";
    }

    /**
     * Solid circle filled with an RGB color given as a "#RRGGBB" hex
     * string — standard 4-Bezier-curve circle approximation, since PDF
     * has no native circle/ellipse operator. $cx/$cyTop is the center,
     * in the same top-left-origin coordinates as text()/rect().
     */
    public function filledCircleColor(float $cx, float $cyTop, float $radius, string $hexColor): void
    {
        $hex = ltrim($hexColor, '#');
        if (strlen($hex) !== 6) { $hex = '9CA3AF'; }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $cy = $this->pageHeight - $cyTop;
        $k = 0.5522847498 * $radius; // kappa: standard cubic-Bezier circle constant

        $this->buffer .= $this->num($r) . ' ' . $this->num($g) . ' ' . $this->num($b) . " rg\n"
            . $this->num($cx + $radius) . ' ' . $this->num($cy) . " m\n"
            . $this->num($cx + $radius) . ' ' . $this->num($cy + $k) . ' ' . $this->num($cx + $k) . ' ' . $this->num($cy + $radius) . ' ' . $this->num($cx) . ' ' . $this->num($cy + $radius) . " c\n"
            . $this->num($cx - $k) . ' ' . $this->num($cy + $radius) . ' ' . $this->num($cx - $radius) . ' ' . $this->num($cy + $k) . ' ' . $this->num($cx - $radius) . ' ' . $this->num($cy) . " c\n"
            . $this->num($cx - $radius) . ' ' . $this->num($cy - $k) . ' ' . $this->num($cx - $k) . ' ' . $this->num($cy - $radius) . ' ' . $this->num($cx) . ' ' . $this->num($cy - $radius) . " c\n"
            . $this->num($cx + $k) . ' ' . $this->num($cy - $radius) . ' ' . $this->num($cx + $radius) . ' ' . $this->num($cy - $k) . ' ' . $this->num($cx + $radius) . ' ' . $this->num($cy) . " c\n"
            . "f 0 g\n";
    }

    /**
     * Draw an image. $jpegData must be raw JPEG bytes (not base64, not a
     * data: URL) — convert other formats to JPEG first (e.g. with GD)
     * before calling this, since embedding JPEG directly via DCTDecode is
     * what keeps this dependency-free. Position/size in points, top-left
     * origin like text()/rect(). Silently does nothing if $jpegData isn't
     * a decodable image, so a bad/missing logo never breaks the receipt.
     */
    public function image(float $x, float $yTop, float $w, float $h, string $jpegData): void
    {
        $size = @getimagesizefromstring($jpegData);
        if (!$size) return;
        $name = 'Im' . (count($this->images) + 1);
        $this->images[] = ['name' => $name, 'data' => $jpegData, 'w' => $size[0], 'h' => $size[1]];
        $yBottom = $this->pageHeight - $yTop - $h;
        $this->buffer .= 'q ' . $this->num($w) . ' 0 0 ' . $this->num($h) . ' ' . $this->num($x) . ' ' . $this->num($yBottom) . ' cm /' . $name . " Do Q\n";
    }

    private function num(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private function escape(string $s): string
    {
        // The 14 standard PDF fonts only support Latin-1-ish text — no
        // Unicode font is embedded (that's what keeps this dependency-free).
        // Common characters used elsewhere in the app (₹ currency, en/em
        // dashes, arrows, curly quotes) fall outside that range, so swap
        // them for readable ASCII first. Anything left over that's still
        // outside Latin-1 becomes "?" via the encoding conversion below —
        // rare in practice once the common cases are handled here.
        $replacements = [
            '₹' => 'Rs. ', '—' => '-', '–' => '-', '→' => '->', '←' => '<-',
            '“' => '"', '”' => '"', '‘' => "'", '’' => "'", '…' => '...', '•' => '-',
        ];
        $s = strtr($s, $replacements);

        $converted = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        if ($converted !== false) $s = $converted;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    /** Build the PDF and stream it to the browser as a download. */
    public function output(string $filename): void
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

        // Image XObjects (if any) get object numbers starting right after
        // the fixed objects 1-6, and get referenced from the Page's
        // /Resources dict below.
        $imageObjStart = 7;
        $xobjectEntries = '';
        foreach ($this->images as $idx => $img) {
            $xobjectEntries .= '/' . $img['name'] . ' ' . ($imageObjStart + $idx) . ' 0 R ';
        }
        $resources = '/Font << /F1 4 0 R /F2 5 0 R >>' . ($xobjectEntries ? ' /XObject << ' . $xobjectEntries . '>>' : '');

        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->num($this->pageWidth) . ' ' . $this->num($this->pageHeight) . ']'
            . ' /Resources << ' . $resources . ' >> /Contents 6 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[6] = '<< /Length ' . strlen($this->buffer) . " >>\nstream\n" . $this->buffer . 'endstream';

        $nextObjNum = $imageObjStart;
        foreach ($this->images as $img) {
            $objects[$nextObjNum] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $img['w'] . ' /Height ' . (int) $img['h']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($img['data']) . " >>\nstream\n" . $img['data'] . "\nendstream";
            $nextObjNum++;
        }
        $totalObjects = $nextObjNum - 1;

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        for ($i = 1; $i <= $totalObjects; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($totalObjects + 1) . "\n";
        $pdf .= sprintf("%010d %05d f \n", 0, 65535);
        for ($i = 1; $i <= $totalObjects; $i++) {
            $pdf .= sprintf("%010d %05d n \n", $offsets[$i], 0);
        }
        $pdf .= "trailer\n<< /Size " . ($totalObjects + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $pdf;
        exit;
    }
}
