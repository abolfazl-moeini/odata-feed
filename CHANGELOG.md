# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-06-17

### Added

- Live XLSX writer that produces a refreshable Power Query OData workbook by cloning a real, Excel-authored template (`resources/live-template.xlsx`)
- `FeedConfig` value object with validation (baseUrl, feedId, entitySet)
- `ConnectionBuilder` for constructing path-segment OData URLs
- `DataMashupRewriter` for repointing the OData feed URL inside the template's DataMashup (UTF-16 + MS-QDEFF aware)
- `LiveXlsxWriter` with `Spreadsheet` constructor and `fromFile()` static factory
- `FeedRepositoryInterface` with `InMemoryFeedRepository` for optional feed metadata persistence
- PHPUnit test suite covering URL construction, config validation, DataMashup URL substitution, and security
- Example script (`examples/build.php`)

### Security

- No authentication tokens or passwords are stored inside the generated `.xlsx` files
- `savePassword="0"` in all connection definitions
- Excel prompts user for credentials on refresh; credentials stored in OS credential manager
