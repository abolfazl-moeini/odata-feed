<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Tests\PowerQuery;

use PHPUnit\Framework\TestCase;
use WPDev\ODataFeed\PowerQuery\DataMashupRewriter;
use WPDev\ODataFeed\Writer\LiveXlsxWriter;
use ZipArchive;

final class DataMashupRewriterTest extends TestCase
{
    public function testRewritesFeedUrlInTemplateDataMashup(): void
    {
        $item = $this->templateItemXml();
        $rewriter = new DataMashupRewriter();

        $newUrl = 'https://api.example.com/odata/tenant-7/Orders';
        $result = $rewriter->rewriteFeedUrl($item, $newUrl);

        $formula = $this->extractFormula($result);
        $this->assertStringContainsString('OData.Feed("' . $newUrl . '"', $formula);
    }

    public function testKeepsStreamWellFramedAndBindingsNonEmpty(): void
    {
        $rewriter = new DataMashupRewriter();
        $result = $rewriter->rewriteFeedUrl($this->templateItemXml(), 'https://api.example.com/odata/a/B');

        $binary = $this->decodeBinary($result);
        $offset = 0;
        $length = strlen($binary);

        $version = $this->readUInt32($binary, $offset);
        $this->assertSame(0, $version);

        $this->readSection($binary, $offset, $length); // package parts
        $this->readSection($binary, $offset, $length); // permissions
        $this->readSection($binary, $offset, $length); // metadata
        $bindings = $this->readSection($binary, $offset, $length);

        $this->assertSame($length, $offset, 'stream must be fully consumed');
        $this->assertGreaterThanOrEqual(1, strlen($bindings));
    }

    public function testRejectsNonDataMashupInput(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DataMashupRewriter())->rewriteFeedUrl('<not-a-mashup/>', 'https://x/y/z');
    }

    private function templateItemXml(): string
    {
        $template = LiveXlsxWriter::defaultTemplatePath();
        $this->assertFileExists($template, 'live-template.xlsx fixture must be present');

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($template) === true);
        $item = $zip->getFromName('customXml/item1.xml');
        $zip->close();

        $this->assertNotFalse($item);

        return (string) $item;
    }

    private function decodeBinary(string $dataMashupXml): string
    {
        $utf8 = $dataMashupXml;
        if (str_starts_with($dataMashupXml, "\xFF\xFE") || str_starts_with($dataMashupXml, "\xFE\xFF")) {
            $converted = mb_convert_encoding($dataMashupXml, 'UTF-8', 'UTF-16');
            $utf8 = is_string($converted) ? $converted : $dataMashupXml;
        }

        $this->assertSame(1, preg_match('#<DataMashup\b[^>]*>(.*?)</DataMashup>#s', $utf8, $matches));
        $binary = base64_decode((string) preg_replace('/\s+/', '', $matches[1]), true);
        $this->assertNotFalse($binary);

        return $binary;
    }

    private function extractFormula(string $dataMashupXml): string
    {
        $binary = $this->decodeBinary($dataMashupXml);
        /** @var array{1: int} $len */
        $len = unpack('V', substr($binary, 4, 4));
        $packageParts = substr($binary, 8, $len[1]);

        $tempFile = (string) tempnam(sys_get_temp_dir(), 'qdeff-rwt-');
        file_put_contents($tempFile, $packageParts);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tempFile) === true);
        $formula = $zip->getFromName('Formulas/Section1.m');
        $zip->close();
        unlink($tempFile);

        return $formula === false ? '' : $formula;
    }

    private function readUInt32(string $binary, int &$offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('V', substr($binary, $offset, 4));
        $offset += 4;

        return $value[1];
    }

    private function readSection(string $binary, int &$offset, int $length): string
    {
        $sectionLength = $this->readUInt32($binary, $offset);
        $this->assertLessThanOrEqual($length, $offset + $sectionLength);
        $section = substr($binary, $offset, $sectionLength);
        $offset += $sectionLength;

        return $section;
    }
}
