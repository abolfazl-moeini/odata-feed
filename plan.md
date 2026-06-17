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
| `[Content_Types].xml` | Register connections override |
| `xl/_rels/workbook.xml.rels` | Connection relationship |

**Option A (current):** connection-only — file opens cleanly in Excel; snapshot data intact; no live OData refresh yet.

**Option B (future):** full Power Query embed with DataMashup + table → queryTable → connection chain for live refresh.

## Phases

### Phase 1 — Connection-only writer (done)

- Single OData connection part per file (no invalid query-table wiring)
- Snapshot data + embedded OData URL in `connections.xml`
- `FeedRepositoryInterface` for optional metadata persistence
- Opens in Excel for Mac without OOXML repair prompts

### Phase 2 — Power Query DataMashup (future)

- Valid MS-QDEFF DataMashup binary (`customXml/item1.xml`)
- Table Definition + Query Table parts with correct relationship chain
- Live OData refresh in Excel for Windows and Mac

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