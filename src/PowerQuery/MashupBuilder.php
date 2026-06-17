<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\PowerQuery;

use WPDev\ODataFeed\Contracts\FeedConfigInterface;
use WPDev\ODataFeed\Feed\ConnectionBuilder;

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
        $connectionName = $this->escapeXml('OData - ' . $config->getEntitySet());

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<connections xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <connection id="1" name="{$connectionName}" type="4" refreshedVersion="8" background="1" saveData="1" savePassword="0">
    <webPr SourceData="0" parsePre="0" consecutive="0" url="{$urlXml}" htmlTables="0"/>
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
