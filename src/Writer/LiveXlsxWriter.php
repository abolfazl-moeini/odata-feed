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
            $worksheet = $this->spreadsheet->createSheet();
            $worksheet->setTitle($entitySet);
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

    private function injectPowerQueryParts(string $workbookPath, FeedConfigInterface $feed): void
    {
        $zip = new ZipArchive();
        if ($zip->open($workbookPath) !== true) {
            throw new RuntimeException('Unable to open generated workbook for Power Query injection.');
        }

        $zip->addFromString('xl/connections.xml', $this->mashupBuilder->buildConnectionsXml($feed));
        $zip->addFromString('xl/queryTables/queryTable1.xml', $this->mashupBuilder->buildQueryTableXml());
        $zip->addFromString('customXml/item1.xml', $this->mashupBuilder->buildDataMashupCustomXml($feed));
        // Note: We do not add customXml/_rels/item1.xml.rels here because the DataMashup
        // payload is embedded inline in item1.xml. A rels pointing to a non-existent "bin"
        // would create an invalid package reference.

        $this->updateContentTypes($zip);
        $this->updateWorkbookRels($zip);
        $this->updateWorkbook($zip);
        $this->updateSheetWithQueryTable($zip, $feed->getEntitySet());

        if (!$zip->close()) {
            throw new RuntimeException('Unable to finalize Power Query injection.');
        }
    }

    private function updateContentTypes(ZipArchive $zip): void
    {
        $content = $zip->getFromName('[Content_Types].xml');
        if ($content === false) {
            throw new RuntimeException('Missing [Content_Types].xml in workbook.');
        }

        $newOverrides = [
            'PartName="/xl/connections.xml"' => '<Override PartName="/xl/connections.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.connections+xml"/>',
            'PartName="/xl/queryTables/queryTable1.xml"' => '<Override PartName="/xl/queryTables/queryTable1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.queryTable+xml"/>',
            'PartName="/customXml/item1.xml"' => '<Override PartName="/customXml/item1.xml" ContentType="application/xml"/>',
        ];

        foreach ($newOverrides as $partMarker => $override) {
            if (!str_contains($content, $partMarker)) {
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
            '<Relationship Id="rIdQueryTable1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/queryTable" Target="queryTables/queryTable1.xml"/>',
            '<Relationship Id="rIdCustomXml1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml" Target="../customXml/item1.xml"/>',
        ];

        foreach ($relationships as $relationship) {
            $id = $this->extractRelId($relationship);
            if ($id === null || !str_contains($content, 'Id="' . $id . '"')) {
                $content = $this->appendRelationship($content, $relationship);
            }
        }

        $zip->addFromString('xl/_rels/workbook.xml.rels', $content);
    }

    private function extractRelId(string $relationship): ?string
    {
        if (preg_match('/Id="([^"]+)"/', $relationship, $m)) {
            return $m[1];
        }
        return null;
    }

    private function updateWorkbook(ZipArchive $zip): void
    {
        $content = $zip->getFromName('xl/workbook.xml');
        if ($content === false) {
            throw new RuntimeException('Missing xl/workbook.xml in workbook.');
        }

        if (!str_contains($content, '<connections>')) {
            $content = str_replace(
                '</workbook>',
                '<connections><connection r:id="rIdConnections"/></connections></workbook>',
                $content
            );
        }

        $zip->addFromString('xl/workbook.xml', $content);
    }

    private function updateSheetWithQueryTable(ZipArchive $zip, string $entitySet): void
    {
        $sheetPath = $this->resolveSheetPath($zip, $entitySet);
        if ($sheetPath === null) {
            return;
        }

        $content = $zip->getFromName($sheetPath);
        if ($content === false) {
            return;
        }

        if (!str_contains($content, '<queryTable')) {
            $queryTableRef = '<tableParts count="1"><tablePart r:id="rIdQueryTable1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></tableParts>';
            $content = str_replace('</worksheet>', $queryTableRef . '</worksheet>', $content);
        }

        $zip->addFromString($sheetPath, $content);

        $relsPath = dirname($sheetPath) . '/_rels/' . basename($sheetPath) . '.rels';
        $relsContent = $zip->getFromName($relsPath);
        if ($relsContent === false) {
            $relsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        }

        $relationship = '<Relationship Id="rIdQueryTable1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/queryTable" Target="../queryTables/queryTable1.xml"/>';
        if (!str_contains($relsContent, 'rIdQueryTable1')) {
            $relsContent = $this->appendRelationship($relsContent, $relationship);
        }

        $zip->addFromString($relsPath, $relsContent);
    }

    private function appendRelationship(string $relsXml, string $relationship): string
    {
        // Normalize self-closing empty Relationships tag to open/close form so we can append
        if (preg_match('#<Relationships([^>]*)?/>#', $relsXml)) {
            $relsXml = preg_replace('#<Relationships([^>]*)?/>#', '<Relationships$1></Relationships>', $relsXml);
        }

        if (str_contains($relsXml, '</Relationships>')) {
            return str_replace('</Relationships>', $relationship . '</Relationships>', $relsXml);
        }

        // Fallback: append inside or wrap
        if (str_contains($relsXml, '<Relationships')) {
            return preg_replace('#(</Relationships>)?$#', $relationship . '</Relationships>', $relsXml, 1);
        }

        return $relsXml;
    }

    private function resolveSheetPath(ZipArchive $zip, string $entitySet): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook === false) {
            return null;
        }

        if (!preg_match('/<sheet[^>]+name="' . preg_quote($entitySet, '/') . '"[^>]+r:id="([^"]+)"/', $workbook, $matches)) {
            if (preg_match('/<sheet[^>]+r:id="([^"]+)"[^>]+name="' . preg_quote($entitySet, '/') . '"/', $workbook, $matches)) {
                // matched reversed attribute order
            } else {
                return 'xl/worksheets/sheet1.xml';
            }
        }

        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $rid = $matches[1];
        if (!preg_match('/<Relationship Id="' . preg_quote($rid, '/') . '"[^>]+Target="([^"]+)"/', $rels, $targetMatches)) {
            return 'xl/worksheets/sheet1.xml';
        }

        return 'xl/' . $targetMatches[1];
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