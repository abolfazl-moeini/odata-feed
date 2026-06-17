<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Writer;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;
use WPDev\ODataFeed\Contracts\FeedConfigInterface;
use WPDev\ODataFeed\Contracts\FeedRepositoryInterface;
use WPDev\ODataFeed\Contracts\XlsxWriterInterface;
use WPDev\ODataFeed\Feed\ConnectionBuilder;
use WPDev\ODataFeed\PowerQuery\MashupBuilder;
use ZipArchive;

final class LiveXlsxWriter implements XlsxWriterInterface
{
    private Spreadsheet $spreadsheet;

    private ?FeedRepositoryInterface $repository;

    private ?FeedConfigInterface $feed = null;

    private MashupBuilder $mashupBuilder;

    private ConnectionBuilder $connectionBuilder;

    public function __construct(Spreadsheet $spreadsheet, ?FeedRepositoryInterface $repository = null)
    {
        $this->spreadsheet = $spreadsheet;
        $this->repository = $repository;
        $this->mashupBuilder = new MashupBuilder();
        $this->connectionBuilder = new ConnectionBuilder();
    }

    public static function fromFile(string $path, ?FeedRepositoryInterface $repository = null): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Workbook file not found: %s', $path));
        }

        $spreadsheet = IOFactory::load($path);

        return new self($spreadsheet, $repository);
    }

    public function setFeed(FeedConfigInterface $feed): self
    {
        $this->feed = $feed;

        return $this;
    }

    public function write(string $path): void
    {
        $feed = $this->requireFeed();
        $this->prepareSpreadsheet($feed);

        $tempFile = tempnam(sys_get_temp_dir(), 'live-xlsx-');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary workbook file.');
        }

        try {
            $writer = new XlsxWriter($this->spreadsheet);
            $writer->save($tempFile);

            $this->injectPowerQueryParts($tempFile, $feed);
            $this->copyFile($tempFile, $path);
            $this->persistFeedMetadata($feed);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function save(string $path): void
    {
        $this->write($path);
    }

    private function requireFeed(): FeedConfigInterface
    {
        if ($this->feed === null) {
            throw new InvalidArgumentException('Feed configuration must be set before writing.');
        }

        return $this->feed;
    }

    private function prepareSpreadsheet(FeedConfigInterface $feed): void
    {
        $entitySet = $feed->getEntitySet();
        $worksheet = $this->findWorksheet($entitySet);

        if ($worksheet === null) {
            if ($this->spreadsheet->getSheetCount() === 1) {
                $worksheet = $this->spreadsheet->getActiveSheet();
                $worksheet->setTitle($entitySet);
            } else {
                $worksheet = $this->spreadsheet->createSheet();
                $worksheet->setTitle($entitySet);
            }
        }

        $this->spreadsheet->setActiveSheetIndex(
            $this->spreadsheet->getIndex($worksheet)
        );
    }

    private function findWorksheet(string $title): ?Worksheet
    {
        foreach ($this->spreadsheet->getWorksheetIterator() as $worksheet) {
            if ($worksheet->getTitle() === $title) {
                return $worksheet;
            }
        }

        return null;
    }

    /**
     * @return array{columns: list<string>, ref: string}
     */
    private function extractSheetTableData(FeedConfigInterface $feed): array
    {
        $worksheet = $this->findWorksheet($feed->getEntitySet());
        if ($worksheet === null) {
            throw new RuntimeException('Unable to locate worksheet for Power Query injection.');
        }

        $highestColumn = $worksheet->getHighestDataColumn();
        $highestRow = max(1, $worksheet->getHighestDataRow());

        if ($highestColumn === 'A' && $worksheet->getCell('A1')->getValue() === null) {
            throw new RuntimeException('Worksheet must contain at least one header row for Power Query injection.');
        }

        $columns = [];
        $columnIndex = 1;

        while (true) {
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            if ($columnLetter > $highestColumn) {
                break;
            }

            $value = $worksheet->getCell($columnLetter . '1')->getValue();
            if ($value === null || $value === '') {
                break;
            }

            $columns[] = (string) $value;
            ++$columnIndex;
        }

        if ($columns === []) {
            throw new RuntimeException('Worksheet must contain at least one header column for Power Query injection.');
        }

        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));

        return [
            'columns' => $columns,
            'ref' => 'A1:' . $lastColumn . $highestRow,
        ];
    }

    private function injectPowerQueryParts(string $workbookPath, FeedConfigInterface $feed): void
    {
        $tableData = $this->extractSheetTableData($feed);

        $zip = new ZipArchive();
        if ($zip->open($workbookPath) !== true) {
            throw new RuntimeException('Unable to open generated workbook for Power Query injection.');
        }

        $zip->addFromString('xl/connections.xml', $this->mashupBuilder->buildConnectionsXml($feed));
        $zip->addFromString('xl/tables/table1.xml', $this->mashupBuilder->buildTableXml($tableData['columns'], $tableData['ref']));
        $zip->addFromString('xl/tables/_rels/table1.xml.rels', $this->buildTableRelsXml());
        $zip->addFromString('xl/queryTables/queryTable1.xml', $this->mashupBuilder->buildQueryTableXml($tableData['columns']));
        $zip->addFromString('customXml/item1.xml', $this->mashupBuilder->buildDataMashupXml($feed));
        $zip->addFromString('customXml/itemProps1.xml', $this->mashupBuilder->buildItemPropsXml());
        $zip->addFromString('customXml/_rels/item1.xml.rels', $this->buildCustomXmlRelsXml());

        $this->updateContentTypes($zip);
        $this->updateWorkbookRels($zip);
        $this->updateSheetWithTable($zip, $feed->getEntitySet());

        if (!$zip->close()) {
            throw new RuntimeException('Unable to finalize Power Query injection.');
        }
    }

    private function buildTableRelsXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rIdQueryTable1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/queryTable" Target="../queryTables/queryTable1.xml"/>
</Relationships>
XML;
    }

    private function buildCustomXmlRelsXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rIdProps1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps" Target="itemProps1.xml"/>
</Relationships>
XML;
    }

    private function updateContentTypes(ZipArchive $zip): void
    {
        $content = $zip->getFromName('[Content_Types].xml');
        if ($content === false) {
            throw new RuntimeException('Missing [Content_Types].xml in workbook.');
        }

        $newOverrides = [
            'PartName="/xl/connections.xml"' => '<Override PartName="/xl/connections.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.connections+xml"/>',
            'PartName="/xl/tables/table1.xml"' => '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>',
            'PartName="/xl/queryTables/queryTable1.xml"' => '<Override PartName="/xl/queryTables/queryTable1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.queryTable+xml"/>',
            'PartName="/customXml/itemProps1.xml"' => '<Override PartName="/customXml/itemProps1.xml" ContentType="application/vnd.openxmlformats-officedocument.customXmlProperties+xml"/>',
        ];

        foreach ($newOverrides as $partMarker => $override) {
            if (strpos($content, $partMarker) === false) {
                $content = str_replace('</Types>', $override . '</Types>', $content);
            }
        }

        $zip->addFromString('[Content_Types].xml', $content);
    }

    private function updateWorkbookRels(ZipArchive $zip): void
    {
        $content = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($content === false) {
            throw new RuntimeException('Missing xl/_rels/workbook.xml.rels in workbook.');
        }

        $relationships = [
            '<Relationship Id="rIdConnections" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/connections" Target="connections.xml"/>',
            '<Relationship Id="rIdCustomXml1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml" Target="../customXml/item1.xml"/>',
        ];

        foreach ($relationships as $relationship) {
            $id = $this->extractRelId($relationship);
            if ($id === null || strpos($content, 'Id="' . $id . '"') === false) {
                $content = $this->appendRelationship($content, $relationship);
            }
        }

        $zip->addFromString('xl/_rels/workbook.xml.rels', $content);
    }

    private function updateSheetWithTable(ZipArchive $zip, string $entitySet): void
    {
        $sheetPath = $this->resolveSheetPath($zip, $entitySet);
        if ($sheetPath === null) {
            return;
        }

        $content = $zip->getFromName($sheetPath);
        if ($content === false) {
            return;
        }

        if (strpos($content, '<tableParts') === false) {
            $tablePartRef = '<tableParts count="1"><tablePart r:id="rIdTable1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></tableParts>';
            $content = str_replace('</worksheet>', $tablePartRef . '</worksheet>', $content);
        }

        $zip->addFromString($sheetPath, $content);

        $relsPath = dirname($sheetPath) . '/_rels/' . basename($sheetPath) . '.rels';
        $relsContent = $zip->getFromName($relsPath);
        if ($relsContent === false) {
            $relsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        }

        $relationship = '<Relationship Id="rIdTable1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>';
        if (strpos($relsContent, 'rIdTable1') === false) {
            $relsContent = $this->appendRelationship($relsContent, $relationship);
        }

        $zip->addFromString($relsPath, $relsContent);
    }

    private function extractRelId(string $relationship): ?string
    {
        if (preg_match('/Id="([^"]+)"/', $relationship, $m)) {
            return $m[1];
        }

        return null;
    }

    private function appendRelationship(string $relsXml, string $relationship): string
    {
        if (preg_match('#<Relationships([^>]*)?/>#', $relsXml)) {
            $relsXml = (string) preg_replace('#<Relationships([^>]*)?/>#', '<Relationships$1></Relationships>', $relsXml);
        }

        if (strpos($relsXml, '</Relationships>') !== false) {
            return str_replace('</Relationships>', $relationship . '</Relationships>', $relsXml);
        }

        if (strpos($relsXml, '<Relationships') !== false) {
            return (string) preg_replace('#(</Relationships>)?$#', $relationship . '</Relationships>', $relsXml, 1);
        }

        return $relsXml;
    }

    private function resolveSheetPath(ZipArchive $zip, string $entitySet): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook === false) {
            return null;
        }

        $rid = $this->extractSheetRid($workbook, $entitySet);
        if ($rid === null) {
            return 'xl/worksheets/sheet1.xml';
        }

        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        if (!preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($rid, '/') . '"[^>]*\bTarget="([^"]+)"/', $rels, $targetMatches)) {
            return 'xl/worksheets/sheet1.xml';
        }

        $target = $targetMatches[1];

        return strpos($target, '/') === 0 ? ltrim($target, '/') : 'xl/' . $target;
    }

    private function extractSheetRid(string $workbookXml, string $entitySet): ?string
    {
        $name = preg_quote(htmlspecialchars($entitySet, ENT_QUOTES | ENT_XML1, 'UTF-8'), '/');

        foreach ([
            '/<sheet\b[^>]*\bname="' . $name . '"[^>]*\br:id="([^"]+)"/',
            '/<sheet\b[^>]*\br:id="([^"]+)"[^>]*\bname="' . $name . '"/',
        ] as $pattern) {
            if (preg_match($pattern, $workbookXml, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function persistFeedMetadata(FeedConfigInterface $feed): void
    {
        if ($this->repository === null) {
            return;
        }

        $this->repository->save($feed->getFeedId(), [
            'baseUrl' => $feed->getBaseUrl(),
            'entitySet' => $feed->getEntitySet(),
            'url' => $this->connectionBuilder->buildUrl($feed),
        ]);
    }

    private function copyFile(string $source, string $destination): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create output directory: %s', $directory));
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException(sprintf('Unable to write workbook to %s', $destination));
        }
    }
}