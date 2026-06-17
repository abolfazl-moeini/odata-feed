<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Tests\Feed;

use PHPUnit\Framework\TestCase;
use WPDev\ODataFeed\Feed\ConnectionBuilder;
use WPDev\ODataFeed\Feed\FeedConfig;

final class ConnectionBuilderTest extends TestCase
{
    public function testBuildsUrlWithFeedIdAsPathSegment(): void
    {
        $config = new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales');
        $builder = new ConnectionBuilder();

        $this->assertSame(
            'https://api.example.com/odata/abc123/Sales',
            $builder->buildUrl($config)
        );
    }

    public function testStripsTrailingSlashFromBaseUrl(): void
    {
        $config = new FeedConfig('https://api.example.com/odata/', 'tenant-1', 'Products');
        $builder = new ConnectionBuilder();

        $this->assertSame(
            'https://api.example.com/odata/tenant-1/Products',
            $builder->buildUrl($config)
        );
    }

    public function testEncodesSpecialCharactersInPathSegments(): void
    {
        $config = new FeedConfig('https://api.example.com/odata', 'tenant 1', 'Sales/Data');
        $builder = new ConnectionBuilder();

        $this->assertSame(
            'https://api.example.com/odata/tenant%201/Sales%2FData',
            $builder->buildUrl($config)
        );
    }
}