## Live Connection XLSX Writer

=== TASK ===
Build a PHP package that takes a PhpSpreadsheet object (or a file path)
and produces an .xlsx file containing an EMBEDDED Power Query (DataMashup)
connection to a remote OData v4 feed. When the user opens the file in
Excel and clicks "Refresh", Excel re-fetches live data from the OData URL.

=== REQUIREMENTS ===
- PHP 7.4+
- Namespace: WPDev\odata-feed
- PSR-4 autoloading
- PSR-12 code style
- Framework-agnostic, database-agnostic
- NO Laravel, NO Symfony
- This package DEPENDS ON Package 1 (the OData endpoint package) and on
  phpoffice/phpspreadsheet. Do NOT build XLSX from scratch.
- Use TDD with PHPUnit. Write tests FIRST, then code.

=== INPUT ===
The writer must accept EITHER:
  1. A \PhpOffice\PhpSpreadsheet\Spreadsheet object, OR
  2. A file path via static method fromFile(string $path)

=== CORE BEHAVIOR ===
1. Take the spreadsheet (snapshot data is already in it).
2. Inject a Power Query / DataMashup connection that points to an
   OData v4 feed URL.
3. Save as .xlsx.

The feed URL MUST use a PATH SEGMENT for the feed id:
   https://host/odata/{feedId}/{SheetName}
Example: https://api.example.com/odata/abc123/Sales

=== CONFIGURATION (FeedConfig) ===
A FeedConfig object holds:
- string $baseUrl        // e.g. "https://api.example.com/odata"
- string $feedId         // e.g. "abc123"
- string $entitySet      // e.g. "Sales" (the sheet/entity to connect)

Build the final URL as:
   rtrim(baseUrl,'/') . '/' . feedId . '/' . entitySet

=== SECURITY (DEFAULT) ===
- DO NOT store any auth token/password inside the .xlsx file.
- Excel must prompt the user for credentials and store them in the OS
  Credential Manager. The file only contains the URL + query definition.

=== ONLY ONE CONNECTION ===
For now support exactly ONE Power Query connection per file.

=== SHEET-TO-ENTITY MAPPING ===
Use this rule (you choose, keep it simple):
- The FeedConfig.entitySet value IS the OData entity set name.
- It must match the worksheet title used by Package 1.
- The visible sheet in the .xlsx that displays the live table should be
  named the same as entitySet.

=== MAPPING STORAGE (interface) ===
Define an interface so consumers can persist feedId -> meaning mapping
if they want, but the package itself does NOT require a database.

interface FeedRepositoryInterface {
    public function save(string $feedId, array $meta): void;
    public function find(string $feedId): ?array;
}

Provide a default InMemoryFeedRepository implementing it.
The writer takes this repo as an OPTIONAL constructor dependency
(Dependency Injection). If null, no persistence happens.

=== FILE STRUCTURE ===
src/
├── Contracts/
│   ├── FeedConfigInterface.php
│   ├── XlsxWriterInterface.php
│   └── FeedRepositoryInterface.php
├── Feed/
│   ├── FeedConfig.php
│   └── ConnectionBuilder.php       // builds the final OData URL
├── Writer/
│   └── LiveXlsxWriter.php          // main class
├── PowerQuery/
│   └── MashupBuilder.php           // builds DataMashup / connection XML
└── Repository/
    └── InMemoryFeedRepository.php

=== POWER QUERY / DATAMASHUP (CRITICAL) ===
PhpSpreadsheet cannot natively embed Power Query. So LiveXlsxWriter must:
1. Use PhpSpreadsheet to save a base .xlsx (the snapshot).
2. Re-open that .xlsx as a ZIP archive (use PHP's ZipArchive).
3. Inject/modify these internal parts so Excel sees a live connection:

   xl/connections.xml          // <connection> describing OData/Mashup
   xl/queryTables/queryTable1.xml
   customXml/item1.xml         // DataMashup blob (M formula)
   xl/worksheets/sheetN.xml    // link the table to the queryTable
   [Content_Types].xml         // register new parts
   xl/_rels/workbook.xml.rels  // add relationships

4. The M (Power Query) formula inside the mashup must be:
   let
       Source = OData.Feed("{FULL_URL}", null, [Implementation="2.0"])
   in
       Source

   where {FULL_URL} = the path-segment URL built by ConnectionBuilder.

NOTE FOR THE AGENT: The DataMashup customXml part is a base64-wrapped
binary container. If producing a fully valid binary mashup is too hard,
FIRST implement the simpler WEB QUERY / connections.xml approach using
an OData connection string, and clearly mark the DataMashup path as a
second iteration. Connection string form:

   OLEDBConnection / odc style is acceptable as a fallback as long as
   Excel can refresh from the OData URL.

Keep the URL with the {feedId} path segment in ALL approaches.

=== TESTS (write first) ===
1. ConnectionBuilder builds correct URL with feedId as path segment.
   - baseUrl with/without trailing slash both work.
2. FeedConfig validation: empty baseUrl/feedId/entitySet throws.
3. LiveXlsxWriter::fromFile loads an existing .xlsx.
4. After write(), the output .xlsx (as ZIP) CONTAINS:
   - xl/connections.xml
   - the OData URL with /{feedId}/ in it
   - NO auth token/password anywhere in any file (assert absence).
5. InMemoryFeedRepository save/find round-trip.
6. Writer works with repo = null (no persistence, no error).

=== DELIVERABLES ===
- composer.json (requires php >=8.1, phpoffice/phpspreadsheet,
  and package 1; require-dev phpunit)
- All src/ files above
- tests/ for every test case
- README.md including a "Compatibility" section that explicitly states:
  * Excel for Windows: supported
  * Excel for Mac: supported (Power Query available)
  * Apple Numbers: NOT supported (no Power Query / OData live refresh)
- An example script examples/build.php showing usage:
    $writer = new LiveXlsxWriter($spreadsheet);
    $writer->setFeed(new FeedConfig('https://api.example.com/odata','abc123','Sales'));
    $writer->save('output.xlsx');
