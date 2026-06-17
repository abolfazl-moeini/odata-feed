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

    public function testBuildsConnectionsXmlWithWebQueryUrl(): void
    {
        $config = new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales');
        $builder = new MashupBuilder();

        $xml = $builder->buildConnectionsXml($config);

        $this->assertStringContainsString('<connections', $xml);
        $this->assertStringContainsString('type="4"', $xml);
        $this->assertStringContainsString('<webPr', $xml);
        $this->assertStringContainsString('url="https://api.example.com/odata/abc123/Sales"', $xml);
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
        $this->assertStringContainsString('url="https://api.example.com/odata/f1/Sales_Data"', $conn);
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
