# Agent Context — `wpdev/odata-feed`

This file gives an AI agent enough background to work on this repository without reading the full codebase first.

## What this repo is

**Package 2** in the excel-kit stack: a **framework-agnostic PHP library** that takes a PhpSpreadsheet workbook (or file path) and writes an `.xlsx` with an **embedded Power Query connection** to a remote **OData v4 feed**.

When a user opens the file in Excel and clicks **Refresh**, Excel re-fetches live data from the configured OData URL. The workbook also contains a **snapshot** of data at write time.

- **Composer package:** `wpdev/odata-feed`
- **Namespace:** `WPDev\ODataFeed`
- **GitHub:** `git@github.com:abolfazl-moeini/odata-feed.git`
- **PHP:** **8.1 minimum** (`composer.json`: `"php": ">=8.1"`)
- PSR-4, PSR-12
- **No** Laravel/Symfony dependency

## PHP version requirement (critical)

- **Minimum PHP version: 8.1**
- `composer.json` constraint: `"php": ">=8.1"`
- CI tests PHP 8.1–8.4

## Companion package (Package 1)

This writer **does not serve OData**. It points Excel at a remote feed served by **Package 1**:

| | Package 1 | Package 2 (this repo) |
|---|---|---|
| Repo | `wpdev/phpspreadsheet-odata` | `wpdev/odata-feed` |
| Role | Serves read-only OData v4 from PhpSpreadsheet | Embeds Power Query URL into `.xlsx` |
| Namespace | `WPDev\PhpSpreadsheetOData` | `WPDev\ODataFeed` |

Package 1 exposes endpoints like:

```
GET /odata/{feedId}/$metadata
GET /odata/{feedId}/{EntitySet}
GET /odata/{feedId}/{EntitySet}({key})
```

`{feedId}` is always a **URL path segment** (not a header or query param) so Excel Power Query preserves it on refresh.

## Core behavior

1. Accept input as `\PhpOffice\PhpSpreadsheet\Spreadsheet` or `LiveXlsxWriter::fromFile(string $path)`.
2. Save a base `.xlsx` via PhpSpreadsheet (snapshot data).
3. Re-open the file as a ZIP (`ZipArchive`) and inject Power Query parts.
4. Output a workbook Excel can refresh against the OData URL.

**One connection per file** — only a single Power Query connection is supported for now.

## Feed configuration

`FeedConfig` holds:

| Property | Example | Purpose |
|----------|---------|---------|
| `baseUrl` | `https://api.example.com/odata` | OData service root |
| `feedId` | `abc123` | Tenant/feed id (path segment) |
| `entitySet` | `Sales` | Sheet name = OData entity set name |

Final URL (`ConnectionBuilder`):

```
{rtrim(baseUrl, '/')}/{feedId}/{entitySet}
```

Example: `https://api.example.com/odata/abc123/Sales`

`entitySet` must match the worksheet title used by Package 1's entity-set naming (sanitized sheet titles). The writer ensures a sheet with that name exists or creates one.

## Security (non-negotiable)

- **Never** store auth tokens, passwords, API keys, or Basic credentials inside the `.xlsx`.
- `savePassword="0"` on the connection element is fine; storing actual credentials is not.
- Excel prompts the user for credentials on refresh and stores them in the OS credential manager.

## Architecture

```
src/
├── Contracts/
│   ├── FeedConfigInterface.php
│   ├── XlsxWriterInterface.php
│   └── FeedRepositoryInterface.php
├── Feed/
│   ├── FeedConfig.php              # validates config, throws on empty fields
│   └── ConnectionBuilder.php       # builds OData URL
├── Writer/
│   └── LiveXlsxWriter.php          # main entry: save snapshot + inject PQ parts
├── PowerQuery/
│   └── MashupBuilder.php           # M formula, connections.xml, DataMashup binary
└── Repository/
    └── InMemoryFeedRepository.php  # optional feedId → metadata persistence
```

### Key classes

**`LiveXlsxWriter`** — main API:

```php
$writer = new LiveXlsxWriter($spreadsheet, $repositoryOrNull);
$writer->setFeed(new FeedConfig($baseUrl, $feedId, $entitySet));
$writer->write($path);  // or save($path) — aliases
```

Also: `LiveXlsxWriter::fromFile(string $path): self`

**`FeedRepositoryInterface`** — optional DI for persisting `feedId → meta` on write. If `null`, no persistence. `InMemoryFeedRepository` is the default implementation for tests/simple use.

### Power Query injection (`LiveXlsxWriter` + `MashupBuilder`)

PhpSpreadsheet cannot embed Power Query natively. After the base save, these ZIP parts are added/updated:

| Part | Purpose |
|------|---------|
| `xl/connections.xml` | OData web-query connection (type 4, `webPr` URL) |
| `xl/queryTables/queryTable1.xml` | Query table definition |
| `[Content_Types].xml` | Register new parts |
| `xl/_rels/workbook.xml.rels` | Connection relationship |
| `xl/workbook.xml` | `<connections>` reference |
| `xl/worksheets/sheetN.xml` + `_rels` | Link sheet to query table |

DataMashup (`customXml/item1.xml`) is reserved for a future iteration; the current writer uses connections-only.

Embedded M formula:

```powerquery
section Section1;

shared {EntitySet} = let
    Source = OData.Feed("{FULL_URL}", null, [Implementation="2.0"])
in
    Source;
```

`FeedConfig` normalizes `entitySet` via `EntitySetBuilder::normalizeIdentifier()` (from Package 1) and validates `feedId` against `[A-Za-z0-9_-]+`. `baseUrl` must not contain embedded credentials.

`MashupBuilder::buildDataMashupBinary()` exists for a future MS-QDEFF implementation but is not injected by `LiveXlsxWriter`.

## Tests

TDD with PHPUnit. Run:

```bash
composer install
composer test   # or ./vendor/bin/phpunit
```

Coverage expectations:

1. `ConnectionBuilder` — URL with `feedId` path segment; trailing slash on `baseUrl` handled.
2. `FeedConfig` — empty `baseUrl` / `feedId` / `entitySet` throws `InvalidArgumentException`.
3. `LiveXlsxWriter::fromFile` — loads existing workbook.
4. After `write()` — output ZIP contains `xl/connections.xml`, OData URL with `/{feedId}/`, no stored credentials.
5. `InMemoryFeedRepository` — save/find round-trip.
6. Writer with `repository = null` — no error, no persistence.
7. `MashupBuilder` — M formula and connections XML contain correct URL.

Test namespace: `WPDev\ODataFeed\Tests\`

## Example

```bash
php examples/build.php
```

```php
use WPDev\ODataFeed\Feed\FeedConfig;
use WPDev\ODataFeed\Writer\LiveXlsxWriter;

$writer = new LiveXlsxWriter($spreadsheet);
$writer->setFeed(new FeedConfig('https://api.example.com/odata', 'abc123', 'Sales'));
$writer->save('output.xlsx');
```

## Compatibility

| Client | Support |
|--------|---------|
| Excel for Windows | Yes |
| Excel for Mac | Yes (Power Query available) |
| Apple Numbers | **No** (no Power Query / OData live refresh) |

## Conventions for agents

- **PHP 8.1+** per `composer.json`; run `composer lint` before finishing.
- **Do not** add Laravel/Symfony/framework coupling.
- **Do not** store credentials in generated xlsx files.
- **Keep** `{feedId}` in the URL path segment in all connection approaches.
- **Match** `entitySet` to Package 1 entity set / sheet naming.
- **Use TDD** — write or update tests before changing behavior.
- **No TODO stubs** — implement public methods fully.
- Prefer minimal, focused diffs; match existing code style.
- `README.md` is user-facing docs; this `context.md` is agent-facing.

## Dependencies

```json
"php": ">=8.1",
        "phpoffice/phpspreadsheet": "^2.0|^3.0",
"wpdev/phpspreadsheet-odata": "^1.0",
"ext-zip": "*"
```

Package 1 is a declared dependency for stack alignment; the writer itself only needs the URL shape at runtime (no direct import of Package 1 classes required for core write flow).

## License

MIT (see `LICENSE` in repo root).