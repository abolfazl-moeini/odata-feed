<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\PowerQuery;

use RuntimeException;
use ZipArchive;

/**
 * Loads a known-good MS-QDEFF binary fixture and swaps only the M formula
 * (plus metadata formula name) while preserving valid framing.
 */
final class DataMashupTemplate
{
    private string $permissions;

    public static function defaultPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/datamashup.template.bin';
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('DataMashup template not found: %s', $path));
        }

        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            throw new RuntimeException(sprintf('DataMashup template is unreadable: %s', $path));
        }

        return new self($binary);
    }

    public function __construct(private string $templateBinary)
    {
        $sections = self::parseTopLevel($this->templateBinary);
        $this->permissions = $sections['permissions'];
    }

    public function build(string $mFormula, string $metadataXml): string
    {
        $sections = self::parseTopLevel($this->templateBinary);
        $packageParts = $this->replaceFormulaInPackageParts($sections['packageParts'], $mFormula);
        $metadata = self::buildMetadataField($metadataXml);
        $permissionBindings = "\x00";

        return pack('V', 0)
            . pack('V', strlen($packageParts)) . $packageParts
            . pack('V', strlen($this->permissions)) . $this->permissions
            . pack('V', strlen($metadata)) . $metadata
            . pack('V', strlen($permissionBindings)) . $permissionBindings;
    }

    /**
     * @return array{
     *     version: int,
     *     packageParts: string,
     *     permissions: string,
     *     metadata: string,
     *     permissionBindings: string
     * }
     */
    public static function parseTopLevel(string $binary): array
    {
        $offset = 0;
        $length = strlen($binary);

        $version = self::readUInt32($binary, $offset, $length, 'version');
        if ($version !== 0) {
            throw new RuntimeException('DataMashup version must be 0.');
        }

        $packageParts = self::readSection($binary, $offset, $length, 'package parts');
        $permissions = self::readSection($binary, $offset, $length, 'permissions');
        $metadata = self::readSection($binary, $offset, $length, 'metadata');
        $permissionBindings = self::readSection($binary, $offset, $length, 'permission bindings');

        if ($offset !== $length) {
            throw new RuntimeException(sprintf(
                'DataMashup stream length mismatch: consumed %d of %d bytes.',
                $offset,
                $length
            ));
        }

        if ($permissionBindings === '') {
            throw new RuntimeException('DataMashup permission bindings must not be empty.');
        }

        return [
            'version' => $version,
            'packageParts' => $packageParts,
            'permissions' => $permissions,
            'metadata' => $metadata,
            'permissionBindings' => $permissionBindings,
        ];
    }

    public static function buildMetadataField(string $metadataXml): string
    {
        $metadataContent = '';

        return pack('V', 0)
            . pack('V', strlen($metadataXml)) . $metadataXml
            . pack('V', strlen($metadataContent)) . $metadataContent;
    }

    /**
     * @return array{
     *     version: int,
     *     metadataXml: string,
     *     content: string
     * }
     */
    public static function parseMetadataField(string $metadata): array
    {
        $offset = 0;
        $length = strlen($metadata);

        $version = self::readUInt32($metadata, $offset, $length, 'metadata version');
        if ($version !== 0) {
            throw new RuntimeException('DataMashup metadata version must be 0.');
        }

        $metadataXml = self::readSection($metadata, $offset, $length, 'metadata XML');
        $content = self::readSection($metadata, $offset, $length, 'metadata content');

        if ($offset !== $length) {
            throw new RuntimeException(sprintf(
                'DataMashup metadata length mismatch: consumed %d of %d bytes.',
                $offset,
                $length
            ));
        }

        return [
            'version' => $version,
            'metadataXml' => $metadataXml,
            'content' => $content,
        ];
    }

    /**
     * @return list<string>
     */
    public static function listPackagePartNames(string $packageParts): array
    {
        return self::extractPackageFormula($packageParts)['names'];
    }

    /**
     * @return array{formula: string, names: list<string>}
     */
    public static function extractPackageFormula(string $packageParts): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'qdeff-formula-');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary file for package formula.');
        }

        unlink($tempFile);
        file_put_contents($tempFile, $packageParts);

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            unlink($tempFile);
            throw new RuntimeException('Package parts bytes are not a valid ZIP archive.');
        }

        $formula = $zip->getFromName('Formulas/Section1.m');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $names[] = $name;
            }
        }

        $zip->close();
        unlink($tempFile);

        if ($formula === false) {
            throw new RuntimeException('Package parts archive is missing Formulas/Section1.m.');
        }

        return [
            'formula' => $formula,
            'names' => $names,
        ];
    }

    private function replaceFormulaInPackageParts(string $packageParts, string $mFormula): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'qdeff-rebuild-');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary file for package rebuild.');
        }

        unlink($tempFile);
        file_put_contents($tempFile, $packageParts);

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            throw new RuntimeException('Unable to open package parts archive for rebuild.');
        }

        if ($zip->locateName('Formulas/Section1.m') === false) {
            $zip->close();
            unlink($tempFile);
            throw new RuntimeException('Package parts archive is missing Formulas/Section1.m.');
        }

        $zip->addFromString('Formulas/Section1.m', $mFormula);
        $zip->close();

        $rebuilt = file_get_contents($tempFile);
        unlink($tempFile);

        if ($rebuilt === false) {
            throw new RuntimeException('Unable to read rebuilt package parts archive.');
        }

        return $rebuilt;
    }

    private static function readUInt32(string $binary, int &$offset, int $length, string $label): int
    {
        if ($offset + 4 > $length) {
            throw new RuntimeException(sprintf('DataMashup stream truncated while reading %s.', $label));
        }

        $value = unpack('V', substr($binary, $offset, 4));
        $offset += 4;

        return (int) ($value[1] ?? 0);
    }

    private static function readSection(string $binary, int &$offset, int $length, string $label): string
    {
        $sectionLength = self::readUInt32($binary, $offset, $length, $label . ' length');

        if ($offset + $sectionLength > $length) {
            throw new RuntimeException(sprintf('DataMashup stream truncated while reading %s.', $label));
        }

        $section = substr($binary, $offset, $sectionLength);
        $offset += $sectionLength;

        return $section;
    }
}