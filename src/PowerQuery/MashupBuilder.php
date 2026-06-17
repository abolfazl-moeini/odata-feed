<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\PowerQuery;

use RuntimeException;
use WPDev\ODataFeed\Contracts\FeedConfigInterface;
use WPDev\ODataFeed\Feed\ConnectionBuilder;
use ZipArchive;

final class MashupBuilder
{
    private ConnectionBuilder $connectionBuilder;

    public function __construct(?ConnectionBuilder $connectionBuilder = null)
    {
        $this->connectionBuilder = $connectionBuilder ?? new ConnectionBuilder();
    }

    public function buildMFormula(FeedConfigInterface $config): string
    {
        $url = $this->connectionBuilder->buildUrl($config);
        // Escape double quotes for M string literal: " inside "..." becomes ""
        $urlForM = str_replace('"', '""', $url);
        $queryName = $this->sanitizeQueryName($config->getEntitySet());

        return <<<M
section Section1;

shared {$queryName} = let
    Source = OData.Feed("{$urlForM}", null, [Implementation="2.0"])
in
    Source;
M;
    }

    public function buildConnectionsXml(FeedConfigInterface $config): string
    {
        $url = $this->connectionBuilder->buildUrl($config);
        $urlXml = $this->escapeXml($url);
        $queryName = $this->escapeXml($this->sanitizeQueryName($config->getEntitySet()));
        $connectionName = $this->escapeXml('Query - ' . $config->getEntitySet());

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<!-- OData source: {$url} -->
<connections xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <connection id="1" name="{$connectionName}" type="5" refreshedVersion="8" background="1" saveData="1" savePassword="0">
    <dbPr connection="Provider=Microsoft.Mashup.OleDb.1;Data Source=\$Workbook\$;Location={$queryName};Extended Properties=&quot;&quot;" command="{$queryName}" commandType="0"/>
    <parameters/>
    <extLst>
      <ext uri="{AB39BE4F-830E-4394-839B-26F6AB2285C3}" xmlns:x15="http://schemas.microsoft.com/office/spreadsheetml/2010/11/main">
        <x15:connection id="" model="1" excludeFromRefreshAll="0"/>
      </ext>
    </extLst>
  </connection>
</connections>
XML;
    }

    public function buildQueryTableXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<queryTable xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" name="ExternalData_1" connectionId="1" headers="1" rowNumbers="0" fillFormulas="0" disableRefresh="0" backgroundRefresh="1" refreshOnLoad="0" grow="1" removeDataOnSave="0"/>
XML;
    }

    public function buildDataMashupBinary(FeedConfigInterface $config): string
    {
        $queryName = $this->sanitizeQueryName($config->getEntitySet());
        $mFormula = $this->buildMFormula($config);

        $packageParts = $this->createOpcZip([
            'Config/Package.xml' => $this->buildPackageXml(),
            'Formulas/Section1.m' => $mFormula,
        ]);

        $permissions = $this->buildPermissionsXml();
        $metadata = $this->buildMetadataXml($queryName);
        $permissionBindings = '';

        return $this->packSection($packageParts)
            . $this->packSection($permissions)
            . $this->packSection($metadata)
            . $this->packSection($permissionBindings);
    }

    private function buildPackageXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes"?>
<LocalPackageMetadataFile xmlns="http://schemas.microsoft.com/DataMashup">
  <Version>1.0</Version>
  <MinVersion>1.0</MinVersion>
  <Culture>en-US</Culture>
</LocalPackageMetadataFile>
XML;
    }

    private function buildPermissionsXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes"?>
<PermissionList xmlns="http://schemas.microsoft.com/DataMashup">
  <CanEvaluateFuturePackages>false</CanEvaluateFuturePackages>
  <FirewallEnabled>true</FirewallEnabled>
  <WorkbookGroupType>None</WorkbookGroupType>
</PermissionList>
XML;
    }

    private function buildMetadataXml(string $queryName): string
    {
        $formulaName = 'Section1/' . $queryName;

        return <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes"?>
<LocalPackageMetadataFile xmlns="http://schemas.microsoft.com/DataMashup">
  <AllFormulas>
    <FormulaList/>
  </AllFormulas>
  <Formulas>
    <FormulaItem>
      <Name>{$formulaName}</Name>
      <ContentType>x-ms/mdso</ContentType>
      <IsEvaluable>true</IsEvaluable>
      <LoadToReport>true</LoadToReport>
      <IsQuery>true</IsQuery>
    </FormulaItem>
  </Formulas>
</LocalPackageMetadataFile>
XML;
    }

    /**
     * @param array<string, string> $files
     */
    private function createOpcZip(array $files): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'datamashup-');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary file for DataMashup package parts.');
        }

        // Remove the placeholder file tempnam() created so ZipArchive starts a fresh archive.
        // ZipArchive::OVERWRITE requires PHP 8.0+; unlink + CREATE works on PHP 7.4+.
        unlink($tempFile);

        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Unable to create DataMashup package parts archive.');
        }

        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
        }

        $zip->close();

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        if ($content === false) {
            throw new RuntimeException('Unable to read DataMashup package parts archive.');
        }

        return $content;
    }

    private function packSection(string $payload): string
    {
        return pack('V', strlen($payload)) . $payload;
    }

    private function sanitizeQueryName(string $name): string
    {
        $sanitized = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $name);

        if ($sanitized === '' || preg_match('/^[0-9]/', $sanitized)) {
            $sanitized = 'Query_' . $sanitized;
        }

        return $sanitized;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
