<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Tests\Repository;

use PHPUnit\Framework\TestCase;
use WPDev\ODataFeed\Repository\InMemoryFeedRepository;

final class InMemoryFeedRepositoryTest extends TestCase
{
    public function testSaveAndFindRoundTrip(): void
    {
        $repo = new InMemoryFeedRepository();
        $meta = [
            'baseUrl' => 'https://api.example.com/odata',
            'entitySet' => 'Sales',
        ];

        $repo->save('abc123', $meta);

        $this->assertSame($meta, $repo->find('abc123'));
        $this->assertNull($repo->find('missing'));
    }
}
