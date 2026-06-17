<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Writer;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use WPDev\ODataFeed\Contracts\FeedConfigInterface;
use WPDev\ODataFeed\Contracts\FeedRepositoryInterface;
use WPDev\ODataFeed\Contracts\XlsxWriterInterface;
use WPDev\ODataFeed\Feed\ConnectionBuilder;
use WPDev\ODataFeed\PowerQuery\DataMashupRewriter;
use ZipArchive;

/**
 * Produces a live-refresh .xlsx by cloning a known-good, Excel-authored Power
 * Query template and repointing its embedded OData feed URL.
 *
 * Hand-synthesizing the Power Query (MS-QDEFF) parts proved too fragile for
 * Excel (especially Excel for Mac), so the writer instead reuses a real
 * template workbook that Excel itself generated and substitutes only the feed
 * URL inside the DataMashup. Every structural part (connections, table,
 * queryTable, content types, relationships, the DataMashup framing) stays
 * exactly as Excel wrote it.
 */
final class LiveXlsxWriter implements XlsxWriterInterface
{
    private Spreadsheet $spreadsheet;

    private ?FeedRepositoryInterface $repository;

    private ?FeedConfigInterface $feed = null;

    private ConnectionBuilder $connectionBuilder;

    private DataMashupRewriter $dataMashupRewriter;

    private string $templatePath;

    public function __construct(
        Spreadsheet $spreadsheet,
        ?FeedRepositoryInterface $repository = null,
        ?string $templatePath = null
    ) {
        $this->spreadsheet = $spreadsheet;
        $this->repository = $repository;
        $this->connectionBuilder = new ConnectionBuilder();
        $this->dataMashupRewriter = new DataMashupRewriter();
        $this->templatePath = $templatePath ?? self::defaultTemplatePath();
    }

    public static function fromFile(string $path, ?FeedRepositoryInterface $repository = null): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Workbook file not found: %s', $path));
        }

        $spreadsheet = IOFactory::load($path);

        return new self($spreadsheet, $repository);
    }

    public static function defaultTemplatePath(): string
    {
        return dirname(__DIR__, 2) . '/resources/live-template.xlsx';
    }

    public function setFeed(FeedConfigInterface $feed): self
    {
        $this->feed = $feed;

        return $this;
    }

    public function write(string $path): void
    {
        $feed = $this->requireFeed();

        if (!is_file($this->templatePath)) {
            throw new RuntimeException(sprintf('Live Power Query template not found: %s', $this->templatePath));
        }

        $url = $this->connectionBuilder->buildUrl($feed);

        $tempFile = tempnam(sys_get_temp_dir(), 'live-xlsx-');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary workbook file.');
        }

        try {
            if (!copy($this->templatePath, $tempFile)) {
                throw new RuntimeException('Unable to stage Power Query template.');
            }

            $this->repointFeedUrl($tempFile, $url);
            $this->copyFile($tempFile, $path);
            $this->persistFeedMetadata($feed, $url);
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

    /**
     * Exposes the snapshot spreadsheet supplied at construction. The current
     * template-clone strategy refreshes its data live from the OData feed, so
     * the snapshot is not embedded; this accessor keeps the value available for
     * callers and future snapshot-fidelity work.
     */
    public function getSpreadsheet(): Spreadsheet
    {
        return $this->spreadsheet;
    }

    private function requireFeed(): FeedConfigInterface
    {
        if ($this->feed === null) {
            throw new InvalidArgumentException('Feed configuration must be set before writing.');
        }

        return $this->feed;
    }

    private function repointFeedUrl(string $workbookPath, string $url): void
    {
        $zip = new ZipArchive();
        if ($zip->open($workbookPath) !== true) {
            throw new RuntimeException('Unable to open Power Query template for URL substitution.');
        }

        $item = $zip->getFromName('customXml/item1.xml');
        if ($item === false) {
            $zip->close();
            throw new RuntimeException('Power Query template is missing customXml/item1.xml.');
        }

        $rewritten = $this->dataMashupRewriter->rewriteFeedUrl($item, $url);
        $zip->addFromString('customXml/item1.xml', $rewritten);

        if (!$zip->close()) {
            throw new RuntimeException('Unable to finalize Power Query URL substitution.');
        }
    }

    private function persistFeedMetadata(FeedConfigInterface $feed, string $url): void
    {
        if ($this->repository === null) {
            return;
        }

        $this->repository->save($feed->getFeedId(), [
            'baseUrl' => $feed->getBaseUrl(),
            'entitySet' => $feed->getEntitySet(),
            'url' => $url,
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
