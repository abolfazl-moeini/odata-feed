<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Contracts;

interface FeedRepositoryInterface
{
    /**
     * @param array<string, mixed> $meta
     */
    public function save(string $feedId, array $meta): void;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $feedId): ?array;
}
