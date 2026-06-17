<?php

declare(strict_types=1);

namespace WPDev\ODataFeed\Contracts;

interface XlsxWriterInterface
{
    public function setFeed(FeedConfigInterface $feed): self;

    public function write(string $path): void;

    public function save(string $path): void;
}