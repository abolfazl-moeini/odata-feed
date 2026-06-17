<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Tests\Writer;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use WPDev\ODataFeed\Feed\FeedConfig;
use WPDev\ODataFeed\PowerQuery\DataMashupTemplate;
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
        $this->assertStringContainsString('type="5"', $connections);
        $this->assertStringContainsString('Microsoft.Mashup.OleDb.1', $connections);
        $this->assertStringContainsString('Sales', $connections);
        $this->assertStringContainsString('model="0"', $connections);

        $allContents = $this->readAllZipContents($zip);
        $lowerContents = strtolower($allContents);
        $this->assertStringNotContainsString('bearer ', $lowerContents);
        $this->assertStringNotContainsString('authorization:', $lowerContents);
        $this->assertStringNotContainsString('api_key', $lowerContents);
        $this->assertStringNotContainsString('basic auth', $lowerContents);
        $this->assertDoesNotMatchRegularExpression('/savepassword="1"/i', $allContents);

        $queryTable = $zip->getFromName('xl/queryTables/queryTable1.xml');
        $this->assertNotFalse($queryTable);
        $this->assertStringContainsString('<queryTableRefresh', $queryTable);
        $this->assertStringContainsString('name="Product"', $queryTable);

        $table = $zip->getFromName('xl/tables/table1.xml');
        $this->assertNotFalse($table);
        $this->assertStringContainsString('tableType="queryTable"', $table);
        $this->assertStringContainsString('name="Product"', $table);

        $tableRels = $zip->getFromName('xl/tables/_rels/table1.xml.rels');
        $this->assertNotFalse($tableRels);
        $this->assertStringContainsString('relationships/queryTable', $tableRels);

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        $this->assertNotFalse($contentTypes);
        $this->assertStringContainsString('/xl/connections.xml', $contentTypes);
        $this->assertStringContainsString('/xl/tables/table1.xml', $contentTypes);
        $this->assertStringContainsString('/xl/queryTables/queryTable1.xml', $contentTypes);

        $workbookRels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $this->assertNotFalse($workbookRels, 'workbook rels should exist');
        $this->assertStringContainsString('customXml', $workbookRels);
        $this->assertStringNotContainsString('queryTable', $workbookRels);

        $dataMashup = $zip->getFromName('customXml/item1.xml');
        $this->assertNotFalse($dataMashup, 'customXml/item1.xml must exist');
        $this->assertStringContainsString('<DataMashup', $dataMashup);
        $binary = $this->extractDataMashupBinary((string) $dataMashup);
        $this->assertNotEmpty($binary);
        $this->assertDataMashupBinaryIsStructurallyValid($binary, 'https://api.example.com/odata/abc123/Sales');

        $itemProps = $zip->getFromName('customXml/itemProps1.xml');
        $this->assertNotFalse($itemProps);
        $this->assertStringContainsString('schemas.microsoft.com/DataMashup', $itemProps);

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertNotFalse($sheet);
        $this->assertStringContainsString('<tableParts count="1">', $sheet);
        $this->assertStringContainsString('r:id="rIdTable1"', $sheet);
        $this->assertStringNotContainsString('<tableParts count="0"', $sheet);

        $sheetRels = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
        $this->assertNotFalse($sheetRels, 'sheet rels should exist');
        $this->assertStringContainsString('rIdTable1', $sheetRels);
        $this->assertStringContainsString('relationships/table', $sheetRels);
        $this->assertStringNotContainsString('relationships/queryTable', $sheetRels);

        $this->assertOoxmlRelationshipsAreConsistent($zip);
        $this->assertSheetTableRelationshipChain($zip);

        $zip->close();
    }

    public function testRejectsFeedConfigWithCredentialsInBaseUrl(): void
    {
        $spreadsheet = $this->createSampleSpreadsheet();
        $output = $this->tempDir . '/cred-reject.xlsx';

        $writer = new LiveXlsxWriter($spreadsheet);

        $this->expectException(\InvalidArgumentException::class);
        $writer->setFeed(new FeedConfig('https://fake:secret@api.example.com/odata', 'abc123', 'Sales'));
        $writer->write($output);
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

    public function testSingleWorksheetIsRenamed(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');
        $sheet->fromArray(['Col1', 'Col2'], null, 'A1');

        $config = new FeedConfig('https://api.example.com/odata', 'tenant-1', 'Sales');
        $output = $this->tempDir . '/rename-single.xlsx';

        $writer = new LiveXlsxWriter($spreadsheet);
        $writer->setFeed($config);
        $writer->write($output);

        $this->assertFileExists($output);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($output) === true);

        $workbook = $zip->getFromName('xl/workbook.xml');
        $this->assertNotFalse($workbook);
        $this->assertStringContainsString('name="Sales"', $workbook);
        $this->assertStringNotContainsString('name="Sheet1"', $workbook);

        $connections = $zip->getFromName('xl/connections.xml');
        $this->assertNotFalse($connections);
        $this->assertStringContainsString('Sales', $connections);
        $this->assertStringNotContainsString('<connections>', $workbook);

        $zip->close();
    }

    public function testNormalizesSheetTitleForSpecialCharacters(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');
        $sheet->fromArray(['Col1', 'Col2'], null, 'A1');

        $config = new FeedConfig('https://api.example.com/odata', 'tenant-1', 'Sales & Marketing');
        $output = $this->tempDir . '/xml-special-chars.xlsx';

        $writer = new LiveXlsxWriter($spreadsheet);
        $writer->setFeed($config);
        $writer->write($output);

        $this->assertFileExists($output);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($output) === true);

        $workbook = $zip->getFromName('xl/workbook.xml');
        $this->assertNotFalse($workbook);
        $this->assertStringContainsString('name="Sales_Marketing"', $workbook);

        $connections = $zip->getFromName('xl/connections.xml');
        $this->assertNotFalse($connections);
        $this->assertStringContainsString('Sales_Marketing', $connections);

        $dataMashup = $zip->getFromName('customXml/item1.xml');
        $this->assertNotFalse($dataMashup);
        $this->assertStringContainsString('<DataMashup', $dataMashup);

        $zip->close();
    }

    private function extractDataMashupBinary(string $dataMashupXml): string
    {
        if (!preg_match('/<DataMashup[^>]*>(.*)<\/DataMashup>/s', $dataMashupXml, $matches)) {
            return '';
        }

        $decoded = base64_decode(trim($matches[1]), true);

        return $decoded === false ? '' : $decoded;
    }

    private function assertDataMashupBinaryIsStructurallyValid(string $binary, string $expectedUrl): void
    {
        $sections = DataMashupTemplate::parseTopLevel($binary);
        $this->assertGreaterThanOrEqual(1, strlen($sections['permissionBindings']));

        $metadata = DataMashupTemplate::parseMetadataField($sections['metadata']);
        $this->assertSame(0, $metadata['version']);
        $this->assertStringContainsString('LocalPackageMetadataFile', $metadata['metadataXml']);

        $extracted = DataMashupTemplate::extractPackageFormula($sections['packageParts']);
        $this->assertContains('[Content_Types].xml', $extracted['names']);
        $this->assertStringContainsString('OData.Feed(', $extracted['formula']);
        $this->assertStringContainsString($expectedUrl, $extracted['formula']);
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

    private function assertOoxmlRelationshipsAreConsistent(ZipArchive $zip): void
    {
        $relsFiles = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match('#\.rels$#', $name)) {
                $relsFiles[] = $name;
            }
        }

        $this->assertNotEmpty($relsFiles, 'workbook should contain relationship parts');

        foreach ($relsFiles as $relsPath) {
            $relsXml = $zip->getFromName($relsPath);
            $this->assertNotFalse($relsXml, $relsPath . ' must be readable');

            if (preg_match_all('/Type="([^"]+)"/', (string) $relsXml, $typeMatches)
                && preg_match_all('/Target="([^"]+)"/', (string) $relsXml, $targetMatches)) {
                foreach ($typeMatches[1] as $index => $type) {
                    if (strpos($type, '/table') !== false && strpos($relsPath, 'worksheets/_rels/') !== false) {
                        $this->assertStringEndsWith('/table', $type, $relsPath . ' tablePart must use table relationship type');
                    }
                }
            }

            if (!preg_match_all('/Target="([^"]+)"/', (string) $relsXml, $matches)) {
                continue;
            }

            foreach ($matches[1] as $target) {
                if (strpos($target, 'http') === 0) {
                    continue;
                }

                $resolved = $this->resolveRelationshipTarget($relsPath, $target);
                $this->assertNotFalse(
                    $zip->getFromName($resolved),
                    sprintf('Relationship target %s (from %s) must exist in the package', $resolved, $relsPath)
                );
            }
        }
    }

    private function assertSheetTableRelationshipChain(ZipArchive $zip): void
    {
        $sheetRels = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
        $this->assertNotFalse($sheetRels);
        $this->assertMatchesRegularExpression(
            '/Type="[^"]*\/table"[^>]*Target="\.\.\/tables\/table1\.xml"/',
            (string) $sheetRels
        );

        $tableRels = $zip->getFromName('xl/tables/_rels/table1.xml.rels');
        $this->assertNotFalse($tableRels);
        $this->assertMatchesRegularExpression(
            '/Type="[^"]*\/queryTable"[^>]*Target="\.\.\/queryTables\/queryTable1\.xml"/',
            (string) $tableRels
        );
    }

    private function resolveRelationshipTarget(string $relsPath, string $target): string
    {
        if (strpos($target, '/') === 0) {
            return ltrim($target, '/');
        }

        if ($relsPath === '_rels/.rels') {
            return $target;
        }

        $baseDir = (string) preg_replace('#/_rels/[^/]+\.rels$#', '', $relsPath);

        $parts = explode('/', ($baseDir !== '' ? $baseDir . '/' : '') . $target);
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($resolved);
                continue;
            }

            $resolved[] = $part;
        }

        return implode('/', $resolved);
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
