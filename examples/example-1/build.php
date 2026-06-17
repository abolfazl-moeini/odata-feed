<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use WPDev\ODataFeed\Feed\FeedConfig;
use WPDev\ODataFeed\Writer\LiveXlsxWriter;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Sales');
$sheet->fromArray(['Product', 'Amount'], null, 'A1');
$sheet->fromArray(['Widget', 10], null, 'A2');
$sheet->fromArray(['Gadget', 25], null, 'A3');

$writer = new LiveXlsxWriter($spreadsheet);
$writer->setFeed(new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales'));
$writer->save(__DIR__ . '/output.xlsx');

echo "Wrote " . __DIR__ . "/output.xlsx\n";