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

    private function injectPowerQueryParts(string $workbookPath, FeedConfigInterface $feed): void
    {
        $zip = new ZipArchive();
        if ($zip->open($workbookPath) !== true) {
            throw new RuntimeException('Unable to open generated workbook for Power Query injection.');
        }

        $zip->addFromString('xl/connections.xml', $this->mashupBuilder->buildConnectionsXml($feed));

        $this->updateContentTypes($zip);
        $this->updateWorkbookRels($zip);

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

        $partMarker = 'PartName="/xl/connections.xml"';
        $override = '<Override PartName="/xl/connections.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.connections+xml"/>';

        if (strpos($content, $partMarker) === false) {
            $content = str_replace('</Types>', $override . '</Types>', $content);
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
        ];

        foreach ($relationships as $relationship) {
            $id = $this->extractRelId($relationship);
            if ($id === null || strpos($content, 'Id="' . $id . '"') === false) {
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

    private function appendRelationship(string $relsXml, string $relationship): string
    {
        // Normalize self-closing empty Relationships tag to open/close form so we can append
        if (preg_match('#<Relationships([^>]*)?/>#', $relsXml)) {
            $relsXml = (string) preg_replace('#<Relationships([^>]*)?/>#', '<Relationships$1></Relationships>', $relsXml);
        }

        if (strpos($relsXml, '</Relationships>') !== false) {
            return str_replace('</Relationships>', $relationship . '</Relationships>', $relsXml);
        }

        // Fallback: append inside or wrap
        if (strpos($relsXml, '<Relationships') !== false) {
            return (string) preg_replace('#(</Relationships>)?$#', $relationship . '</Relationships>', $relsXml, 1);
        }

        return $relsXml;
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
