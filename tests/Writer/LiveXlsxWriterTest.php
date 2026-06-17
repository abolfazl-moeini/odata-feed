<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Tests\Writer;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use WPDev\ODataFeed\Feed\FeedConfig;
use WPDev\ODataFeed\Repository\InMemoryFeedRepository;
use WPDev\ODataFeed\Writer\LiveXlsxWriter;
use ZipArchive;

final class LiveXlsxWriterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/odata-feed-tests-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testFromFileLoadsExistingWorkbook(): void
    {
        $source = $this->createSampleSpreadsheetPath();
        $writer = LiveXlsxWriter::fromFile($source);

        $this->assertInstanceOf(LiveXlsxWriter::class, $writer);
    }

    public function testWriteClonesTemplateAndRepointsFeedUrl(): void
    {
        $spreadsheet = $this->createSampleSpreadsheet();
        $config = new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales');
        $output = $this->tempDir . '/output.xlsx';

        $writer = new LiveXlsxWriter($spreadsheet);
        $writer->setFeed($config);
        $writer->write($output);

        $this->assertFileExists($output);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($output) === true);

        // Power Query structural parts are inherited verbatim from the real template.
        $connections = $zip->getFromName('xl/connections.xml');
        $this->assertNotFalse($connections, 'xl/connections.xml must exist');
        $this->assertStringContainsString('type="5"', $connections);
        $this->assertStringContainsString('Microsoft.Mashup.OleDb.1', $connections);

        $this->assertNotFalse($zip->getFromName('xl/tables/table1.xml'));
        $this->assertNotFalse($zip->getFromName('xl/queryTables/queryTable1.xml'));
        $this->assertNotFalse($zip->getFromName('customXml/itemProps1.xml'));

        // The feed URL is repointed inside the DataMashup M formula.
        $dataMashup = $zip->getFromName('customXml/item1.xml');
        $this->assertNotFalse($dataMashup, 'customXml/item1.xml must exist');
        $formula = $this->extractMFormula((string) $dataMashup);
        $this->assertStringContainsString('OData.Feed(', $formula);
        $this->assertStringContainsString('https://api.example.com/odata/abc123/Sales', $formula);

        // No credentials end up in the workbook.
        $allContents = $this->readAllZipContents($zip);
        $lowerContents = strtolower($allContents);
        $this->assertStringNotContainsString('bearer ', $lowerContents);
        $this->assertStringNotContainsString('authorization:', $lowerContents);
        $this->assertDoesNotMatchRegularExpression('/savepassword="1"/i', $allContents);

        $zip->close();
    }

    public function testWriteProducesAFileThatReloads(): void
    {
        $spreadsheet = $this->createSampleSpreadsheet();
        $config = new FeedConfig('https://api.example.com/odata', 'reload', 'Sales');
        $output = $this->tempDir . '/reload.xlsx';

        $writer = new LiveXlsxWriter($spreadsheet);
        $writer->setFeed($config);
        $writer->write($output);

        $reloaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($output);
        $this->assertNotEmpty($reloaded->getSheetNames());
    }

    public function testRejectsFeedConfigWithCredentialsInBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FeedConfig('https://fake:secret@api.example.com/odata', 'abc123', 'Sales');
    }

    public function testWriteWithoutFeedThrows(): void
    {
        $spreadsheet = $this->createSampleSpreadsheet();
        $writer = new LiveXlsxWriter($spreadsheet);

        $this->expectException(\InvalidArgumentException::class);
        $writer->write($this->tempDir . '/no-feed.xlsx');
    }

    public function testWriteWithNullRepositoryDoesNotPersistAndDoesNotError(): void
    {
        $spreadsheet = $this->createSampleSpreadsheet();
        $config = new FeedConfig('https://api.example.com/odata', 'feed-99', 'Sales');
        $output = $this->tempDir . '/no-repo.xlsx';

        $writer = new LiveXlsxWriter($spreadsheet, null);
        $writer->setFeed($config);
        $writer->write($output);

        $this->assertFileExists($output);
    }

    public function testFromFileAcceptsRepositoryAndPersists(): void
    {
        $source = $this->createSampleSpreadsheetPath();
        $repo = new InMemoryFeedRepository();
        $config = new FeedConfig('https://api.example.com/odata', 'fromfile-repo', 'Sales');
        $output = $this->tempDir . '/fromfile-repo.xlsx';

        $writer = LiveXlsxWriter::fromFile($source, $repo);
        $writer->setFeed($config);
        $writer->write($output);

        $this->assertFileExists($output);
        $stored = $repo->find('fromfile-repo');
        $this->assertIsArray($stored);
        $this->assertSame('https://api.example.com/odata/fromfile-repo/Sales', $stored['url']);
    }

    public function testWritePersistsMetadataWhenRepositoryProvided(): void
    {
        $spreadsheet = $this->createSampleSpreadsheet();
        $repo = new InMemoryFeedRepository();
        $config = new FeedConfig('https://api.example.com/odata', 'persist-me', 'Sales');
        $output = $this->tempDir . '/with-repo.xlsx';

        $writer = new LiveXlsxWriter($spreadsheet, $repo);
        $writer->setFeed($config);
        $writer->write($output);

        $stored = $repo->find('persist-me');
        $this->assertIsArray($stored);
        $this->assertSame('https://api.example.com/odata', $stored['baseUrl']);
        $this->assertSame('Sales', $stored['entitySet']);
        $this->assertSame('https://api.example.com/odata/persist-me/Sales', $stored['url']);
    }

    public function testSaveIsAliasForWrite(): void
    {
        $spreadsheet = $this->createSampleSpreadsheet();
        $config = new FeedConfig('https://api.example.com/odata', 'alias', 'Sales');
        $output = $this->tempDir . '/alias.xlsx';

        $writer = new LiveXlsxWriter($spreadsheet);
        $writer->setFeed($config);
        $writer->save($output);

        $this->assertFileExists($output);
    }

    private function extractMFormula(string $dataMashupXml): string
    {
        $utf8 = $dataMashupXml;
        if (str_starts_with($dataMashupXml, "\xFF\xFE") || str_starts_with($dataMashupXml, "\xFE\xFF")) {
            $converted = mb_convert_encoding($dataMashupXml, 'UTF-8', 'UTF-16');
            $utf8 = is_string($converted) ? $converted : $dataMashupXml;
        }

        if (!preg_match('#<DataMashup\b[^>]*>(.*?)</DataMashup>#s', $utf8, $matches)) {
            return '';
        }

        $binary = base64_decode((string) preg_replace('/\s+/', '', $matches[1]), true);
        if ($binary === false) {
            return '';
        }

        // version + package parts length, then the OPC zip.
        /** @var array{1: int} $lenUnpack */
        $lenUnpack = unpack('V', substr($binary, 4, 4));
        $packageParts = substr($binary, 8, $lenUnpack[1]);

        $tempFile = tempnam(sys_get_temp_dir(), 'qdeff-test-');
        if ($tempFile === false) {
            return '';
        }
        file_put_contents($tempFile, $packageParts);

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            unlink($tempFile);
            return '';
        }

        $formula = $zip->getFromName('Formulas/Section1.m');
        $zip->close();
        unlink($tempFile);

        return $formula === false ? '' : $formula;
    }

    private function createSampleSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sales');
        $sheet->fromArray(['Product', 'Amount'], null, 'A1');
        $sheet->fromArray(['Widget', 10], null, 'A2');

        return $spreadsheet;
    }

    private function createSampleSpreadsheetPath(): string
    {
        $path = $this->tempDir . '/source.xlsx';
        $spreadsheet = $this->createSampleSpreadsheet();
        (new XlsxWriter($spreadsheet))->save($path);

        return $path;
    }

    private function readAllZipContents(ZipArchive $zip): string
    {
        $contents = '';

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                $contents .= $data;
            }
        }

        return $contents;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
