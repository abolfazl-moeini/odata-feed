<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Feed;

use InvalidArgumentException;
use WPDev\ODataFeed\Contracts\FeedConfigInterface;

final class FeedConfig implements FeedConfigInterface
{
    private string $baseUrl;

    private string $feedId;

    private string $entitySet;

    public function __construct(string $baseUrl, string $feedId, string $entitySet)
    {
        $baseUrl = trim($baseUrl);
        $feedId = trim($feedId);
        $entitySet = trim($entitySet);

        if ($baseUrl === '') {
            throw new InvalidArgumentException('baseUrl must not be empty.');
        }

        if ($feedId === '') {
            throw new InvalidArgumentException('feedId must not be empty.');
        }

        if ($entitySet === '') {
            throw new InvalidArgumentException('entitySet must not be empty.');
        }

        $this->baseUrl = $baseUrl;
        $this->feedId = $feedId;
        $this->entitySet = $entitySet;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getFeedId(): string
    {
        return $this->feedId;
    }

    public function getEntitySet(): string
    {
        return $this->entitySet;
    }
}
