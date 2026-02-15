<?php
declare(strict_types=1);

/**
 * Minimal PDF helper focused on rendering the handover HTML into a readable A4 document.
 * This is intentionally lightweight to keep the project file-based and dependency-free.
 */
class SimplePdfDocument
{
    private float $pageWidth;
    private float $pageHeight;
    private float $margin;
    private float $cursorY;
    private array $pages = [];
    private array $fonts;
    private array $images = [];

    public function __construct(float $pageWidth = 595.28, float $pageHeight = 841.89, float $margin = 56.7)
    {
        $this->pageWidth = $pageWidth;
        $this->pageHeight = $pageHeight;
        $this->margin = $margin;
        $this->fonts = [
            'regular' => ['id' => null, 'name' => 'Helvetica'],
            'bold' => ['id' => null, 'name' => 'Helvetica-Bold'],
        ];

        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = [
            'contents' => [],
            'resources' => [],
        ];
        $this->cursorY = $this->margin;
    }

    private function currentPageIndex(): int
    {
        return max(count($this->pages) - 1, 0);
    }

    private function addContent(string $content): void
    {
        $index = $this->currentPageIndex();
        $this->pages[$index]['contents'][] = $content;
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private function availableWidth(): float
    {
        return $this->pageWidth - ($this->margin * 2);
    }

    private function requireSpace(float $height): void
    {
        if (($this->cursorY + $height) > ($this->pageHeight - $this->margin)) {
            $this->addPage();
        }
    }

    private function yPosition(float $offset = 0.0): float
    {
        $yFromBottom = $this->pageHeight - ($this->cursorY + $offset);
        return $yFromBottom;
    }

    public function addParagraph(string $text, float $fontSize = 12.0, bool $bold = false, float $spacingBefore = 4.0, float $spacingAfter = 8.0): void
    {
        $clean = trim($text);
        if ($clean === '') {
            $this->cursorY += $spacingBefore + $spacingAfter + $fontSize * 0.6;
            return;
        }

        $this->cursorY += $spacingBefore;
        $lineHeight = $fontSize * 1.35;
        $lines = $this->wrapText($clean, $fontSize);
        foreach ($lines as $line) {
            $this->requireSpace($lineHeight);
            $this->addContent(sprintf("BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET", $bold ? 'F2' : 'F1', $fontSize, $this->margin, $this->yPosition(), $this->escape($line)));
            $this->cursorY += $lineHeight;
        }

        $this->cursorY += $spacingAfter;
    }

    public function addHr(float $spacingBefore = 8.0, float $spacingAfter = 8.0): void
    {
        $this->cursorY += $spacingBefore;
        $this->requireSpace(2.0);
        $y = $this->yPosition(1.0);
        $x1 = $this->margin;
        $x2 = $this->pageWidth - $this->margin;
        $this->addContent(sprintf("%.2f %.2f m %.2f %.2f l S", $x1, $y, $x2, $y));
        $this->cursorY += $spacingAfter;
    }

    public function addImage(string $path, float $maxWidth = 160.0): void
    {
        if (!is_file($path)) {
            return;
        }

        $imageData = @file_get_contents($path);
        if ($imageData === false) {
            return;
        }

        $resource = @imagecreatefromstring($imageData);
        if ($resource === false) {
            return;
        }

        $widthPx = imagesx($resource);
        $heightPx = imagesy($resource);
        if ($widthPx === 0 || $heightPx === 0) {
            imagedestroy($resource);
            return;
        }

        $scale = min(1.0, $maxWidth / $widthPx);
        $widthPt = $widthPx * $scale;
        $heightPt = $heightPx * $scale;
        $this->requireSpace($heightPt + 6.0);

        ob_start();
        imagejpeg($resource, null, 92);
        $jpegData = (string) ob_get_clean();
        imagedestroy($resource);

        $imageId = $this->registerImage($jpegData, (int) ceil($widthPx * $scale), (int) ceil($heightPx * $scale));
        $x = $this->margin;
        $y = $this->yPosition($heightPt);
        $this->addContent(sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im%d Do Q", $widthPt, $heightPt, $x, $y, $imageId));
        $this->cursorY += $heightPt + 6.0;
    }

    private function registerImage(string $data, int $width, int $height): int
    {
        $hash = md5($data);
        if (!isset($this->images[$hash])) {
            $this->images[$hash] = [
                'data' => $data,
                'width' => $width,
                'height' => $height,
                'objectId' => null,
            ];
        }

        return array_search($hash, array_keys($this->images), true) + 1;
    }

    private function wrapText(string $text, float $fontSize): array
    {
        $maxWidth = $this->availableWidth();
        $avgCharWidth = $fontSize * 0.52;
        $maxChars = max(1, (int) floor($maxWidth / $avgCharWidth));

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $words = preg_split('/\s+/', trim($text));
        if (!is_array($words)) {
            return [$text];
        }

        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($candidate) > $maxChars) {
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    public function output(): string
    {
        $objects = [];
        $objectId = 0;
        $pageIds = [];
        $contentIds = [];

        // Fonts
        $this->fonts['regular']['id'] = ++$objectId;
        $objects[$objectId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $this->fonts['bold']['id'] = ++$objectId;
        $objects[$objectId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        // Images
        foreach ($this->images as $key => $image) {
            $imageObjectId = ++$objectId;
            $this->images[$key]['objectId'] = $imageObjectId;
            $objects[$imageObjectId] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream', $image['width'], $image['height'], strlen($image['data']), $image['data']);
        }

        $pagesRootId = ++$objectId;
        $catalogId = ++$objectId;

        foreach ($this->pages as $pageIndex => $page) {
            $contentId = ++$objectId;
            $pageId = ++$objectId;

            $contentStream = implode("\n", $page['contents']);
            $objects[$contentId] = "<< /Length " . strlen($contentStream) . " >>\nstream\n" . $contentStream . "\nendstream";

            $fontResources = ['/F1 ' . $this->fonts['regular']['id'] . ' 0 R', '/F2 ' . $this->fonts['bold']['id'] . ' 0 R'];
            $imageResources = [];
            $imageIndex = 1;
            foreach ($this->images as $image) {
                if ($image['objectId'] !== null) {
                    $imageResources[] = '/Im' . $imageIndex . ' ' . $image['objectId'] . ' 0 R';
                }
                $imageIndex++;
            }

            $resources = '<< /Font << ' . implode(' ', $fontResources) . ' >>';
            if ($imageResources !== []) {
                $resources .= ' /XObject << ' . implode(' ', $imageResources) . ' >>';
            }
            $resources .= ' >>';

            $objects[$pageId] = '<< /Type /Page /Parent ' . $pagesRootId . ' 0 R /MediaBox [0 0 ' . $this->pageWidth . ' ' . $this->pageHeight . '] /Contents ' . $contentId . ' 0 R /Resources ' . $resources . ' >>';

            $pageIds[] = $pageId . ' 0 R';
            $contentIds[] = $contentId;
        }

        $objects[$pagesRootId] = '<< /Type /Pages /Kids [' . implode(' ', $pageIds) . '] /Count ' . count($pageIds) . ' >>';
        $objects[$catalogId] = '<< /Type /Catalog /Pages ' . $pagesRootId . ' 0 R >>';

        $buffer = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($buffer);
            $buffer .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefPosition = strlen($buffer);
        $buffer .= "xref\n0 " . (max(array_keys($objects)) + 1) . "\n";
        $buffer .= "0000000000 65535 f \n";
        for ($i = 1; $i <= max(array_keys($objects)); $i++) {
            $offset = $offsets[$i] ?? strlen($buffer);
            $buffer .= sprintf('%010d 00000 n %s', $offset, "\n");
        }

        $buffer .= "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root $catalogId 0 R >>\nstartxref\n" . $xrefPosition . "\n%%EOF";

        return $buffer;
    }
}
