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

    public function testWriteEmbedsConnectionsXmlWithFeedIdUrlAndNoAuth(): void
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

        $connections = $zip->getFromName('xl/connections.xml');
        $this->assertNotFalse($connections, 'xl/connections.xml must exist');
        $this->assertStringContainsString('/abc123/', $connections);
        $this->assertStringContainsString('https://api.example.com/odata/abc123/Sales', $connections);

        $allContents = $this->readAllZipContents($zip);
        $lowerContents = strtolower($allContents);
        $this->assertStringNotContainsString('bearer ', $lowerContents);
        $this->assertStringNotContainsString('authorization:', $lowerContents);
        $this->assertStringNotContainsString('api_key', $lowerContents);
        $this->assertStringNotContainsString('basic auth', $lowerContents);
        $this->assertDoesNotMatchRegularExpression('/savepassword="1"/i', $allContents);

        // Ensure sheet rels has the queryTable relationship (critical for refresh to target the sheet)
        $sheetRels = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
        $this->assertNotFalse($sheetRels, 'sheet rels should exist');
        $this->assertStringContainsString('rIdQueryTable1', $sheetRels);
        $this->assertStringContainsString('queryTable', $sheetRels);

        // Ensure we do not emit a broken rels pointing to non-existent bin for DataMashup
        $this->assertFalse($zip->getFromName('customXml/_rels/item1.xml.rels'), 'should not create customXml rels to non-existent bin');

        $zip->close();
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