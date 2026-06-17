<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Tests\PowerQuery;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use WPDev\ODataFeed\Feed\FeedConfig;
use WPDev\ODataFeed\PowerQuery\DataMashupTemplate;
use WPDev\ODataFeed\PowerQuery\MashupBuilder;
use WPDev\ODataFeed\Writer\LiveXlsxWriter;
use ZipArchive;

final class DataMashupStructureTest extends TestCase
{
    public function testTemplateFixtureIsStructurallyValid(): void
    {
        $path = DataMashupTemplate::defaultPath();
        $this->assertFileExists($path);

        $sections = DataMashupTemplate::parseTopLevel((string) file_get_contents($path));
        $this->assertPackagePartsAreValidZip($sections['packageParts']);
        $this->assertGreaterThanOrEqual(1, strlen($sections['permissionBindings']));
    }

    public function testBuildDataMashupBinaryMatchesQdeffStructure(): void
    {
        $config = new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales');
        $builder = new MashupBuilder();
        $binary = $builder->buildDataMashupBinary($config);

        $this->assertQdeffStreamIsValid($binary, 'https://api.example.com/odata/abc123/Sales');
    }

    public function testWriterEmbedsStructurallyValidDataMashup(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sales');
        $sheet->fromArray(['Product', 'Amount'], null, 'A1');
        $sheet->fromArray(['Widget', 10], null, 'A2');

        $output = sys_get_temp_dir() . '/odata-feed-qdeff-' . uniqid('', true) . '.xlsx';
        $config = new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales');

        $writer = new LiveXlsxWriter($spreadsheet);
        $writer->setFeed($config);
        $writer->write($output);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($output) === true);
        $dataMashupXml = $zip->getFromName('customXml/item1.xml');
        $zip->close();
        unlink($output);

        $this->assertNotFalse($dataMashupXml);
        $this->assertMatchesRegularExpression('/<DataMashup[^>]*>(.*)<\/DataMashup>/s', (string) $dataMashupXml);

        preg_match('/<DataMashup[^>]*>(.*)<\/DataMashup>/s', (string) $dataMashupXml, $matches);
        $binary = base64_decode(trim($matches[1]), true);
        $this->assertNotFalse($binary);

        $this->assertQdeffStreamIsValid($binary, 'https://api.example.com/odata/abc123/Sales');
    }

    public function testConnectionsXmlUsesWorksheetModelFlag(): void
    {
        $builder = new MashupBuilder();
        $xml = $builder->buildConnectionsXml(new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales'));

        $this->assertStringContainsString('model="0"', $xml);
    }

    private function assertQdeffStreamIsValid(string $binary, string $expectedUrl): void
    {
        $sections = DataMashupTemplate::parseTopLevel($binary);
        $this->assertPackagePartsAreValidZip($sections['packageParts'], $expectedUrl);

        $metadata = DataMashupTemplate::parseMetadataField($sections['metadata']);
        $this->assertSame(0, $metadata['version']);
        $this->assertNotSame('', $metadata['metadataXml']);
        $this->assertStringContainsString('LocalPackageMetadataFile', $metadata['metadataXml']);
        $this->assertGreaterThanOrEqual(1, strlen($sections['permissionBindings']));
    }

    private function assertPackagePartsAreValidZip(string $packageParts, ?string $expectedUrl = null): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'qdeff-test-');
        $this->assertNotFalse($tempFile);
        unlink($tempFile);
        file_put_contents($tempFile, $packageParts);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tempFile) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $names[] = $name;
            }
        }

        $this->assertContains('[Content_Types].xml', $names);
        $this->assertContains('Config/Package.xml', $names);
        $this->assertContains('Formulas/Section1.m', $names);

        $formula = $zip->getFromName('Formulas/Section1.m');
        $this->assertNotFalse($formula);
        $this->assertStringContainsString('OData.Feed(', (string) $formula);
        if ($expectedUrl !== null) {
            $this->assertStringContainsString($expectedUrl, (string) $formula);
        }

        $zip->close();
        unlink($tempFile);
    }
}