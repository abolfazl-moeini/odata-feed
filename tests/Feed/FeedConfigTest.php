<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Tests\Feed;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WPDev\ODataFeed\Feed\FeedConfig;

final class FeedConfigTest extends TestCase
{
    public function testEmptyBaseUrlThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeedConfig('', 'abc', 'Sales');
    }

    public function testEmptyFeedIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeedConfig('https://api.example.com/odata', '', 'Sales');
    }

    public function testEmptyEntitySetThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeedConfig('https://api.example.com/odata', 'abc', '');
    }

    public function testExposesConfigurationValues(): void
    {
        $config = new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales');

        $this->assertSame('https://api.example.com/odata', $config->getBaseUrl());
        $this->assertSame('abc123', $config->getFeedId());
        $this->assertSame('Sales', $config->getEntitySet());
    }

    public function testTrimsWhitespaceFromValues(): void
    {
        $config = new FeedConfig('  https://ex.com/odata  ', '  id1  ', '  Ent  ');

        $this->assertSame('https://ex.com/odata', $config->getBaseUrl());
        $this->assertSame('id1', $config->getFeedId());
        $this->assertSame('Ent', $config->getEntitySet());
    }

    public function testRejectsInvalidFeedIdPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeedConfig('https://api.example.com/odata', 'abc/def', 'Sales');
    }

    public function testRejectsBaseUrlWithCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeedConfig('https://user:pass@api.example.com/odata', 'abc123', 'Sales');
    }

    public function testNormalizesEntitySetToMatchServerIdentifiers(): void
    {
        $config = new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales Data');

        $this->assertSame('Sales_Data', $config->getEntitySet());
    }
}
