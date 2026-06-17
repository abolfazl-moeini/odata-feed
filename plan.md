# odata-feed — Implementation Plan

Framework-agnostic PHP library that embeds a live OData connection into `.xlsx` workbooks for Excel refresh.

## Requirements

| Area | Target |
|------|--------|
| PHP | **>= 8.1** |
| Companion | `wpdev/phpspreadsheet-odata ^1.0` (URL shape alignment) |
| Security | No credentials stored in generated files |
| Style | PSR-4, PSR-12, `declare(strict_types=1)` |

## Architecture

```
src/
├── Contracts/     FeedConfigInterface, XlsxWriterInterface, FeedRepositoryInterface
├── Feed/          FeedConfig, ConnectionBuilder
├── Writer/        LiveXlsxWriter
├── PowerQuery/    MashupBuilder
└── Repository/    InMemoryFeedRepository
```

## Core flow

1. Accept `Spreadsheet` or `LiveXlsxWriter::fromFile($path)`
2. Save snapshot via PhpSpreadsheet
3. Re-open as ZIP and inject Power Query parts
4. Output workbook Excel can refresh against the OData URL

## Feed configuration

Final URL: `{rtrim(baseUrl, '/')}/{feedId}/{entitySet}`

- `feedId` must match `[A-Za-z0-9_-]+` (same as Package 1 router)
- `entitySet` is normalized via `EntitySetBuilder::normalizeIdentifier()`
- `baseUrl` must not contain embedded credentials

## Power Query injection (current)

| Part | Purpose |
|------|---------|
| `xl/connections.xml` | OData web-query connection (type 4, `webPr` URL) |
| `xl/queryTables/queryTable1.xml` | Query table definition |
| `[Content_Types].xml` | Register new parts |
| `xl/_rels/workbook.xml.rels` | Connection relationship |
| `xl/worksheets/sheetN.xml` + `_rels` | Link sheet to query table |

DataMashup (`customXml/item1.xml`) is reserved for a future MS-QDEFF iteration.

## Phases

### Phase 1 — Connections-only writer (done)

- Single Power Query connection per file
- Snapshot data + embedded OData URL
- `FeedRepositoryInterface` for optional metadata persistence

### Phase 2 — Future

- Valid MS-QDEFF DataMashup binary
- Multiple connections per workbook
- Additional auth UX documentation for Excel credential manager

## Quality gates

```bash
composer validate --strict
composer lint
composer phpstan
composer test
```

## Example

```bash
php examples/build.php
```