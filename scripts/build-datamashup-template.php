<?php

declare(strict_types=1);

use WPDev\ODataFeed\Feed\FeedConfig;
use WPDev\ODataFeed\PowerQuery\MashupBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

$builder = new MashupBuilder(null, '/dev/null/force-scratch');
$config = new FeedConfig(
    'https://example.invalid/odata',
    'template',
    'TemplateQuery'
);

$binary = $builder->buildDataMashupBinaryFromScratch($config);
$output = dirname(__DIR__) . '/resources/datamashup.template.bin';

if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0777, true) && !is_dir(dirname($output))) {
    fwrite(STDERR, "Unable to create resources directory.\n");
    exit(1);
}

if (file_put_contents($output, $binary) === false) {
    fwrite(STDERR, "Unable to write {$output}\n");
    exit(1);
}

echo "Wrote {$output} (" . strlen($binary) . " bytes)\n";