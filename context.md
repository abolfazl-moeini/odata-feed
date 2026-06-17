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
│   └── LiveXlsxWriter.php          # main entry: clone template + repoint feed URL
├── PowerQuery/
│   └── DataMashupRewriter.php      # swaps OData URL inside a real DataMashup (UTF-16 + MS-QDEFF)
└── Repository/
    └── InMemoryFeedRepository.php  # optional feedId → metadata persistence

resources/
└── live-template.xlsx              # real Excel-authored Power Query workbook used as the base
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

### Power Query embed (`LiveXlsxWriter` + `DataMashupRewriter`) — template-clone

Hand-synthesizing the Power Query / MS-QDEFF parts repeatedly triggered Excel's "We found a problem with some content" repair prompt (Excel for Mac is especially strict). The writer therefore clones a real, Excel-authored template instead of building parts:

1. `resources/live-template.xlsx` is a workbook Excel produced from an OData feed. It already has valid `xl/connections.xml`, `xl/tables/table1.xml`, `xl/queryTables/queryTable1.xml`, `customXml/item1.xml` (DataMashup), content types, relationships, and the hidden `ExternalData_1` defined name.
2. `LiveXlsxWriter::write()` copies the template and calls `DataMashupRewriter::rewriteFeedUrl()` to repoint the OData URL. Only `customXml/item1.xml` changes; every other part stays byte-identical to a file Excel accepts.

`DataMashupRewriter` essentials:
- `customXml/item1.xml` is **UTF-16** (with BOM) in real Excel files — decode/re-encode accordingly.
- The feed URL lives only in `Formulas/Section1.m` inside the Package Parts OPC zip. Swap it, rebuild the inner zip, recompute the four [MS-QDEFF] top-level section lengths.
- Changing the package invalidates the DPAPI permission bindings, so they are replaced with a single `0x00` byte (the spec fallback); Excel applies default permissions on open.

Embedded M formula (query name kept from the template, e.g. `Query1`):

```powerquery
section Section1;

shared Query1 = let
    Source = OData.Feed("{FULL_URL}", null, [Implementation="2.0"])
in
    Source;
```

`FeedConfig` normalizes `entitySet` via `EntitySetBuilder::normalizeIdentifier()` (from Package 1) and validates `feedId` against `[A-Za-z0-9_-]+`. `baseUrl` must not contain embedded credentials.

Limitation: the current writer ships the template's snapshot data (data refreshes live on open/Refresh). Embedding the caller's own snapshot rows/columns is a follow-up. Regenerate the template by authoring a fresh OData Power Query connection in Excel and saving over `resources/live-template.xlsx`.

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
7. `DataMashupRewriter` — repoints the OData URL inside the template DataMashup and keeps the MS-QDEFF stream well-framed.

Test namespace: `WPDev\ODataFeed\Tests\`

## Examples

```bash
# Minimal writer demo
php examples/example-1/build.php

# Live OData + Excel refresh playground
cd examples/example-2 && composer install
php playground.php --build
php -S localhost:8080 playground.php
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