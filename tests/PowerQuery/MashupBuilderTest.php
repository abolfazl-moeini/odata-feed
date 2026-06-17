<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Tests\PowerQuery;

use PHPUnit\Framework\TestCase;
use WPDev\ODataFeed\Feed\FeedConfig;
use WPDev\ODataFeed\PowerQuery\MashupBuilder;

final class MashupBuilderTest extends TestCase
{
    public function testBuildsMFormulaWithODataFeed(): void
    {
        $config = new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales');
        $builder = new MashupBuilder();

        $formula = $builder->buildMFormula($config);

        $this->assertStringContainsString('OData.Feed("https://api.example.com/odata/abc123/Sales"', $formula);
        $this->assertStringContainsString('[Implementation="2.0"]', $formula);
    }

    public function testBuildsConnectionsXmlWithPowerQueryConnection(): void
    {
        $config = new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales');
        $builder = new MashupBuilder();

        $xml = $builder->buildConnectionsXml($config);

        $this->assertStringContainsString('<connections', $xml);
        $this->assertStringContainsString('type="5"', $xml);
        $this->assertStringContainsString('<dbPr', $xml);
        $this->assertStringContainsString('Microsoft.Mashup.OleDb.1', $xml);
        $this->assertStringContainsString('Sales', $xml);
        $this->assertStringContainsString('model="0"', $xml);
        $this->assertStringContainsString('savePassword="0"', $xml);
    }

    public function testUsesNormalizedEntitySetInMFormulaAndConnections(): void
    {
        $config = new FeedConfig('https://api.example.com/odata', 'f1', 'Sales Data!');
        $builder = new MashupBuilder();

        $m = $builder->buildMFormula($config);
        $this->assertStringContainsString('shared Sales_Data =', $m);
        $this->assertStringContainsString('/f1/Sales_Data', $m);

        $conn = $builder->buildConnectionsXml($config);
        $this->assertStringContainsString('Sales_Data', $conn);

        $table = $builder->buildTableXml(['Col1', 'Col2'], 'A1:B3');
        $this->assertStringContainsString('tableType="queryTable"', $table);
        $this->assertStringContainsString('name="Col1"', $table);

        $queryTable = $builder->buildQueryTableXml(['Col1', 'Col2']);
        $this->assertStringContainsString('<queryTableRefresh', $queryTable);
        $this->assertStringContainsString('name="Col2"', $queryTable);

        $dataMashup = $builder->buildDataMashupXml($config);
        $this->assertStringContainsString('<DataMashup', $dataMashup);
    }

    public function testEscapesDoubleQuotesInUrlForMFormula(): void
    {
        // " in baseUrl will reach M unencoded by path logic; must become "" in M string
        $config = new FeedConfig('https://ex.com/"path"', 'id', 'Q');
        $builder = new MashupBuilder();
        $m = $builder->buildMFormula($config);
        $this->assertStringContainsString('""path""', $m);
    }
}
