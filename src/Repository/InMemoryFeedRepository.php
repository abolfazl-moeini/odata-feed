<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Repository;

use WPDev\ODataFeed\Contracts\FeedRepositoryInterface;

final class InMemoryFeedRepository implements FeedRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $feeds = [];

    public function save(string $feedId, array $meta): void
    {
        $this->feeds[$feedId] = $meta;
    }

    public function find(string $feedId): ?array
    {
        return $this->feeds[$feedId] ?? null;
    }
}
