<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\PowerQuery;

use RuntimeException;
use ZipArchive;

/**
 * Rewrites the OData feed URL inside a real, Excel-authored DataMashup part
 * (customXml/item1.xml) without disturbing the rest of the MS-QDEFF stream.
 *
 * The URL lives only in the Power Query M document (Formulas/Section1.m) inside
 * the Package Parts OPC zip. Swapping it changes the Package Parts bytes, which
 * invalidates the DPAPI permission bindings, so the bindings are replaced with
 * the spec-sanctioned single null-byte fallback (Excel then applies default
 * permissions on open). See [MS-QDEFF] sections 2.2-2.6.
 */
final class DataMashupRewriter
{
    public function rewriteFeedUrl(string $itemXmlBytes, string $newUrl): string
    {
        $utf8 = $this->decodeToUtf8($itemXmlBytes);

        if (!preg_match('#<DataMashup\b[^>]*>(.*?)</DataMashup>#s', $utf8, $matches)) {
            throw new RuntimeException('Template customXml/item1.xml is not a DataMashup part.');
        }

        $base64 = preg_replace('/\s+/', '', $matches[1]) ?? '';
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            throw new RuntimeException('DataMashup payload is not valid base64.');
        }

        $newBinary = $this->rewriteBinary($binary, $newUrl);
        $newElement = '<DataMashup' . $this->extractDataMashupAttributes($matches[0])
            . '>' . base64_encode($newBinary) . '</DataMashup>';

        $newUtf8 = str_replace($matches[0], $newElement, $utf8);

        return $this->encodeFromUtf8($newUtf8);
    }

    private function extractDataMashupAttributes(string $element): string
    {
        if (preg_match('#<DataMashup\b([^>]*)>#s', $element, $m)) {
            return $m[1];
        }

        return ' xmlns="http://schemas.microsoft.com/DataMashup"';
    }

    private function rewriteBinary(string $binary, string $newUrl): string
    {
        $offset = 0;
        $length = strlen($binary);

        $version = $this->readUInt32($binary, $offset, $length, 'version');
        if ($version !== 0) {
            throw new RuntimeException('DataMashup version must be 0.');
        }

        $packageParts = $this->readSection($binary, $offset, $length, 'package parts');
        $permissions = $this->readSection($binary, $offset, $length, 'permissions');
        $metadata = $this->readSection($binary, $offset, $length, 'metadata');
        $this->readSection($binary, $offset, $length, 'permission bindings');

        if ($offset !== $length) {
            throw new RuntimeException('DataMashup stream length mismatch.');
        }

        $newPackageParts = $this->replaceUrlInPackageParts($packageParts, $newUrl);
        $permissionBindings = "\x00";

        return pack('V', 0)
            . pack('V', strlen($newPackageParts)) . $newPackageParts
            . pack('V', strlen($permissions)) . $permissions
            . pack('V', strlen($metadata)) . $metadata
            . pack('V', strlen($permissionBindings)) . $permissionBindings;
    }

    private function replaceUrlInPackageParts(string $packageParts, string $newUrl): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'qdeff-pkg-');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary file for package rewrite.');
        }

        if (file_put_contents($tempFile, $packageParts) === false) {
            unlink($tempFile);
            throw new RuntimeException('Unable to stage package parts for rewrite.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            unlink($tempFile);
            throw new RuntimeException('Package parts are not a valid OPC package.');
        }

        $formula = $zip->getFromName('Formulas/Section1.m');
        if ($formula === false) {
            $zip->close();
            unlink($tempFile);
            throw new RuntimeException('Package parts are missing Formulas/Section1.m.');
        }

        $escapedUrl = str_replace('"', '""', $newUrl);
        $newFormula = preg_replace_callback(
            '/OData\.Feed\(\s*"[^"]*"/',
            static fn (): string => 'OData.Feed("' . $escapedUrl . '"',
            $formula,
            1,
            $count
        );

        if ($newFormula === null || $count === 0) {
            $zip->close();
            unlink($tempFile);
            throw new RuntimeException('Template M formula does not contain an OData.Feed(...) source.');
        }

        $zip->addFromString('Formulas/Section1.m', $newFormula);
        $zip->close();

        $rebuilt = file_get_contents($tempFile);
        unlink($tempFile);

        if ($rebuilt === false) {
            throw new RuntimeException('Unable to read rewritten package parts.');
        }

        return $rebuilt;
    }

    private function decodeToUtf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16');
        }

        return $bytes;
    }

    private function encodeFromUtf8(string $utf8): string
    {
        return "\xFF\xFE" . mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
    }

    private function readUInt32(string $binary, int &$offset, int $length, string $label): int
    {
        if ($offset + 4 > $length) {
            throw new RuntimeException(sprintf('DataMashup stream truncated reading %s.', $label));
        }

        /** @var array{1: int} $value */
        $value = unpack('V', substr($binary, $offset, 4));
        $offset += 4;

        return $value[1];
    }

    private function readSection(string $binary, int &$offset, int $length, string $label): string
    {
        $sectionLength = $this->readUInt32($binary, $offset, $length, $label . ' length');

        if ($offset + $sectionLength > $length) {
            throw new RuntimeException(sprintf('DataMashup stream truncated reading %s.', $label));
        }

        $section = substr($binary, $offset, $sectionLength);
        $offset += $sectionLength;

        return $section;
    }
}
