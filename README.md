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

### Examples

**Example 1** — minimal writer demo (static OData URL):

```bash
php examples/example-1/build.php
```

**Example 2** — full live refresh playground (OData server + Excel workbook):

```bash
cd examples/example-2
composer install
php playground.php --build
php -S localhost:8080 playground.php
```

Edit `$feeds` in `playground.php`, save, then refresh the workbook in Excel. The playground enables HTTP Basic auth (`$username` / `$password`) so Excel prompts for credentials on refresh — nothing is stored in the `.xlsx`. Re-run `--build` only when sheet names, `feedId`, or the OData base URL change. For subdirectory hosting, pass `--base-url` or set `PLAYGROUND_BASE_URL` when building.

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

Hand-synthesizing the Power Query (MS-QDEFF) parts proved too fragile for Excel (it kept triggering the "We found a problem with some content" repair prompt, especially on Excel for Mac). Instead the writer reuses a real, Excel-authored Power Query workbook as a template:

1. `resources/live-template.xlsx` is a known-good workbook that Excel itself generated from an OData feed (working `connections.xml`, `xl/tables`, `xl/queryTables`, and the `customXml/item1.xml` DataMashup).
2. `LiveXlsxWriter` clones that template and rewrites **only** the OData feed URL inside the DataMashup's `Formulas/Section1.m` (rebuilding the inner OPC package and recomputing the MS-QDEFF section lengths). Changing the package invalidates the DPAPI permission bindings, so they are replaced with the spec's single null-byte fallback and Excel applies default permissions on open.

Every other part stays byte-for-byte as Excel wrote it, so the output opens cleanly and refreshes live.

`entitySet` is normalized to match Package 1 entity-set identifiers (e.g. `Sales Data` → `Sales_Data`). `feedId` must match `[A-Za-z0-9_-]+`. Credentials are never stored in the file.

To regenerate `resources/live-template.xlsx`, create a new OData Power Query connection in Excel (Data → Get Data → From OData Feed), load it to a table, save the `.xlsx`, and drop it in as the template.

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