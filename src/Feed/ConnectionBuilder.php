<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Feed;

use WPDev\ODataFeed\Contracts\FeedConfigInterface;

final class ConnectionBuilder
{
    public function buildUrl(FeedConfigInterface $config): string
    {
        $baseUrl = rtrim($config->getBaseUrl(), '/');
        $feedId = $config->getFeedId();
        $entitySet = rawurlencode($config->getEntitySet());

        return $baseUrl
            . '/'
            . $feedId
            . '/'
            . $entitySet;
    }
}
