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

    private ?string $templatePath;

    public function __construct(?ConnectionBuilder $connectionBuilder = null, ?string $templatePath = null)
    {
        $this->connectionBuilder = $connectionBuilder ?? new ConnectionBuilder();
        $this->templatePath = $templatePath;
    }

    public function buildMFormula(FeedConfigInterface $config): string
    {
        $url = $this->connectionBuilder->buildUrl($config);
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
        $queryName = $this->escapeXml($this->sanitizeQueryName($config->getEntitySet()));
        $connectionName = $this->escapeXml('Query - ' . $config->getEntitySet());

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<connections xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <connection id="1" name="{$connectionName}" type="5" refreshedVersion="8" background="1" saveData="1" savePassword="0">
    <dbPr connection="Provider=Microsoft.Mashup.OleDb.1;Data Source=\$Workbook\$;Location={$queryName};Extended Properties=&quot;&quot;" command="SELECT * FROM [{$queryName}]" commandType="0"/>
    <parameters/>
    <extLst>
      <ext uri="{AB39BE4F-830E-4394-839B-26F6AB2285C3}" xmlns:x15="http://schemas.microsoft.com/office/spreadsheetml/2010/11/main">
        <x15:connection id="" model="0" excludeFromRefreshAll="0"/>
      </ext>
    </extLst>
  </connection>
</connections>
XML;
    }

    /**
     * @param list<string> $columnNames
     */
    public function buildTableXml(array $columnNames, string $ref): string
    {
        $count = count($columnNames);
        $columns = '';

        foreach ($columnNames as $index => $name) {
            $id = $index + 1;
            $columns .= '<tableColumn id="' . $id . '" name="' . $this->escapeXml($name) . '"/>';
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="ExternalData_1" displayName="ExternalData_1" ref="{$ref}" headerRowCount="1" tableType="queryTable" totalsRowShown="0">
  <autoFilter ref="{$ref}"/>
  <tableColumns count="{$count}">
    {$columns}
  </tableColumns>
  <tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>
</table>
XML;
    }

    /**
     * @param list<string> $columnNames
     */
    public function buildQueryTableXml(array $columnNames): string
    {
        $fieldCount = count($columnNames);
        $nextId = $fieldCount + 1;
        $fields = '';

        foreach ($columnNames as $index => $name) {
            $id = $index + 1;
            $fields .= '<queryTableField id="' . $id . '" name="' . $this->escapeXml($name) . '" tableColumnId="' . $id . '"/>';
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<queryTable xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" name="ExternalData_1" connectionId="1" headers="1" rowNumbers="0" fillFormulas="0" disableRefresh="0" backgroundRefresh="1" refreshOnLoad="0" grow="1" removeDataOnSave="0" autoFormatId="16" applyNumberFormats="0" applyBorderFormats="0" applyFontFormats="0" applyPatternFormats="0" applyAlignmentFormats="0" applyWidthHeightFormats="0">
  <queryTableRefresh nextId="{$nextId}">
    <queryTableFields count="{$fieldCount}">
      {$fields}
    </queryTableFields>
  </queryTableRefresh>
</queryTable>
XML;
    }

    public function buildDataMashupXml(FeedConfigInterface $config): string
    {
        $base64 = base64_encode($this->buildDataMashupBinary($config));
        $sqmid = $this->generateGuid();

        return <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes"?>
<DataMashup sqmid="{$sqmid}" xmlns="http://schemas.microsoft.com/DataMashup">{$base64}</DataMashup>
XML;
    }

    public function buildItemPropsXml(): string
    {
        $itemId = $this->generateGuid();

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<ds:datastoreItem ds:itemID="{$itemId}" xmlns:ds="http://schemas.openxmlformats.org/officeDocument/2006/customXml">
  <ds:schemaRefs>
    <ds:schemaRef ds:uri="http://schemas.microsoft.com/DataMashup"/>
  </ds:schemaRefs>
</ds:datastoreItem>
XML;
    }

    public function buildDataMashupBinary(FeedConfigInterface $config): string
    {
        $templatePath = $this->templatePath ?? DataMashupTemplate::defaultPath();
        if (is_file($templatePath)) {
            return $this->buildDataMashupBinaryFromTemplate($config, $templatePath);
        }

        return $this->buildDataMashupBinaryFromScratch($config);
    }

    public function buildDataMashupBinaryFromScratch(FeedConfigInterface $config): string
    {
        $queryName = $this->sanitizeQueryName($config->getEntitySet());

        $packageParts = $this->createOpcZip([
            '[Content_Types].xml' => $this->buildPackageContentTypesXml(),
            'Config/Package.xml' => $this->buildPackageXml(),
            'Formulas/Section1.m' => $this->buildMFormula($config),
        ]);

        $permissions = $this->buildPermissionsXml();
        $metadata = DataMashupTemplate::buildMetadataField($this->buildMetadataXml($queryName));
        $permissionBindings = "\x00";

        return pack('V', 0)
            . pack('V', strlen($packageParts)) . $packageParts
            . pack('V', strlen($permissions)) . $permissions
            . pack('V', strlen($metadata)) . $metadata
            . pack('V', strlen($permissionBindings)) . $permissionBindings;
    }

    public function buildDataMashupBinaryFromTemplate(FeedConfigInterface $config, string $templatePath): string
    {
        $queryName = $this->sanitizeQueryName($config->getEntitySet());
        $template = DataMashupTemplate::fromFile($templatePath);

        return $template->build(
            $this->buildMFormula($config),
            $this->buildMetadataXml($queryName)
        );
    }

    private function buildPackageContentTypesXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="xml" ContentType="text/xml"/>
  <Default Extension="m" ContentType="text/plain"/>
  <Override PartName="/Config/Package.xml" ContentType="text/xml"/>
</Types>
XML;
    }

    private function buildPackageXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes"?>
<Package xmlns="http://schemas.microsoft.com/DataMashup">
  <Version>1.0</Version>
  <MinVersion>1.0</MinVersion>
  <Culture>en-us</Culture>
</Package>
XML;
    }

    private function buildPermissionsXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes"?>
<PermissionList xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://schemas.microsoft.com/DataMashup">
  <CanEvaluateFuturePackages>false</CanEvaluateFuturePackages>
  <FirewallEnabled>true</FirewallEnabled>
  <WorkbookGroupType xsi:nil="true"/>
</PermissionList>
XML;
    }

    private function buildMetadataXml(string $queryName): string
    {
        $formulaName = 'Section1/' . $queryName;

        return <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes"?>
<LocalPackageMetadataFile xmlns="http://schemas.microsoft.com/DataMashup">
  <Items>
    <Item>
      <ItemLocation>
        <ItemType>Formula</ItemType>
        <ItemPath>{$formulaName}</ItemPath>
      </ItemLocation>
    </Item>
  </Items>
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

    private function generateGuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}