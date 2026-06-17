# OData Feed — Live Connection XLSX Writer

PHP package that takes a PhpSpreadsheet workbook (or file path) and produces an `.xlsx` with an embedded Power Query connection to a remote OData v4 feed. When opened in Excel and refreshed, Excel re-fetches live data from the configured OData URL.

This package is **Package 2** in the excel-kit stack. It pairs with the OData endpoint package (`wpdev/phpspreadsheet-odata`) which serves the live data.

## Requirements

- **PHP 8.1 or greater**
- `ext-zip`
- [phpoffice/phpspreadsheet](https://github.com/PHPOffice/PhpSpreadsheet)

## Installation

```bash
composer require wpdev/odata-feed
```

## Quick start

```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use WPDev\ODataFeed\Feed\FeedConfig;
use WPDev\ODataFeed\Writer\LiveXlsxWriter;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Sales');
$sheet->fromArray(['Product', 'Amount'], null, 'A1');
$sheet->fromArray(['Widget', 10], null, 'A2');

$writer = new LiveXlsxWriter($spreadsheet);
$writer->setFeed(new FeedConfig(
    'https://api.example.com/odata',  // OData base URL
    'abc123',                          // feed id (path segment)
    'Sales'                            // entity set / sheet name
));
$writer->save('output.xlsx');
```

Load an existing workbook:

```php
$writer = LiveXlsxWriter::fromFile('/path/to/workbook.xlsx');
$writer->setFeed(new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales'));
$writer->write('output.xlsx');
```

Run the included example:

```bash
php examples/build.php
```

## Feed configuration

`FeedConfig` holds three values:

| Property | Description |
|----------|-------------|
| `baseUrl` | OData service root, e.g. `https://api.example.com/odata` |
| `feedId` | Tenant/feed identifier used as a **path segment** |
| `entitySet` | Sheet/entity name, e.g. `Sales` |

The final OData URL is built as:

```
{rtrim(baseUrl, '/')}/{feedId}/{entitySet}
```

Example: `https://api.example.com/odata/abc123/Sales`

The visible worksheet in the output file is named to match `entitySet`.

## Security

No authentication token or password is stored inside the generated `.xlsx`. Excel prompts the user for credentials on refresh and stores them in the OS credential manager. The file contains only the OData URL and Power Query definition.

## Optional feed metadata persistence

Inject `FeedRepositoryInterface` to persist feed metadata when writing:

```php
use WPDev\ODataFeed\Repository\InMemoryFeedRepository;

$repo = new InMemoryFeedRepository();
$writer = new LiveXlsxWriter($spreadsheet, $repo);
$writer->setFeed($config);
$writer->write('output.xlsx');

$meta = $repo->find('abc123');
```

Pass `null` (default) to skip persistence.

## How it works

1. PhpSpreadsheet saves a snapshot workbook.
2. The writer re-opens the file as a ZIP archive.
3. Power Query parts are injected:
   - `xl/connections.xml` — OData web-query connection (`webPr` URL, `savePassword="0"`)
   - `xl/queryTables/queryTable1.xml`
   - `[Content_Types].xml`, `xl/_rels/workbook.xml.rels`, worksheet links

`entitySet` is normalized to match Package 1 entity-set identifiers (e.g. `Sales Data` → `Sales_Data`). `feedId` must match `[A-Za-z0-9_-]+`. Credentials are never stored in the file.

DataMashup (`customXml/item1.xml`) is reserved for a future iteration.

## Compatibility

| Client | Support |
|--------|---------|
| Excel for Windows | Supported |
| Excel for Mac | Supported (Power Query available) |
| Apple Numbers | **NOT supported** (no Power Query / OData live refresh) |

## Development

```bash
composer install
./vendor/bin/phpunit
```

## License

MIT