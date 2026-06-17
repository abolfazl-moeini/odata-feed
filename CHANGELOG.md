# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-06-17

### Added

- Live XLSX writer that embeds Power Query / DataMashup OData connections
- `FeedConfig` value object with validation (baseUrl, feedId, entitySet)
- `ConnectionBuilder` for constructing path-segment OData URLs
- `MashupBuilder` for generating M formulas, connections XML, query tables, and DataMashup binary
- `LiveXlsxWriter` with `Spreadsheet` constructor and `fromFile()` static factory
- `FeedRepositoryInterface` with `InMemoryFeedRepository` for optional feed metadata persistence
- PHPUnit test suite covering URL construction, config validation, ZIP injection, and security
- Example script (`examples/build.php`)

### Security

- No authentication tokens or passwords are stored inside the generated `.xlsx` files
- `savePassword="0"` in all connection definitions
- Excel prompts user for credentials on refresh; credentials stored in OS credential manager
