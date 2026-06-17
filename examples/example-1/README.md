# Example 1 — Minimal LiveXlsxWriter

Creates a workbook with an embedded Power Query OData connection using a static feed URL. No OData server is required.

## Requirements

- PHP 7.4+
- `ext-zip`
- Dependencies installed in the parent package:

```bash
cd ../..   # odata-feed root
composer install
```

## Run

From the `odata-feed` package root:

```bash
php examples/example-1/build.php
```

Or from this directory:

```bash
php build.php
```

Output is written to `output.xlsx` in this folder.

## What it does

1. Builds a small spreadsheet with a `Sales` sheet (Product, Amount columns).
2. Embeds a Power Query connection pointing at `https://api.example.com/odata/abc123/Sales`.
3. Saves the result as `output.xlsx`.

Open the file in Excel. **Data → Refresh All** will attempt to fetch from that URL — you need a real OData endpoint at that address for refresh to succeed.

## Customize

Edit `build.php` to change sheet data or the feed config:

```php
$writer->setFeed(new FeedConfig(
    'https://api.example.com/odata',  // OData base URL
    'abc123',                          // feed id
    'Sales'                            // entity set / sheet name
));
```

For a full local stack with a live OData server and Excel refresh loop, see [example-2](../example-2/README.md).