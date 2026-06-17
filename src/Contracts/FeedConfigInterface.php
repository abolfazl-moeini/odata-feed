<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Contracts;

interface FeedConfigInterface
{
    public function getBaseUrl(): string;

    public function getFeedId(): string;

    public function getEntitySet(): string;
}
