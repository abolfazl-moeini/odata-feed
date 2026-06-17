# Example 2 — Live OData Playground

End-to-end smoke test for `wpdev/odata-feed` and `wpdev/phpspreadsheet-odata`. A single PHP script serves an OData v4 API from in-memory data and generates an Excel workbook wired to that API. Edit the data arrays, save, and refresh Excel — no rebuild needed for row changes.

## Requirements

- PHP 7.4+
- `ext-zip`
- [Composer](https://getcomposer.org/)

## Setup

```bash
cd examples/example-2
composer install
```

Path repositories in `composer.json` link to the local `odata-feed` and `phpspreadsheet-odata` packages in the excel-kit monorepo.

## Quick start

```bash
php playground.php --build
php -S localhost:8080 playground.php
```

1. Open `playground.xlsx` in Excel.
2. **Data → Refresh All** — Excel prompts for **Basic** credentials (`demo` / `demo` by default).
3. Edit the `$feeds` array in `playground.php`, save the file.
4. Refresh Excel again — rows update without rebuilding the workbook.

## Authentication

The OData feed is protected with HTTP Basic auth. Edit credentials at the top of `playground.php`:

```php
$username = 'demo';
$password = 'demo';
```

Excel shows a username/password dialog on refresh and stores credentials in the OS keychain — nothing is saved in the workbook.

On shared hosting, set `ODATA_USER` and `ODATA_PASS` environment variables instead of committing real passwords.

**HTTPS is required in production** — Basic auth sends reversible base64 encoding. `localhost` is fine for testing.

If Excel cached anonymous access for this URL, update via **Data → Get Data → Data Source Settings → Edit Permissions → Credentials → Basic**.

### Verify with curl

```bash
# 401 + WWW-Authenticate challenge
curl -i http://localhost:8080/odata/demo/Employees | grep -iE 'HTTP/|WWW-Authenticate'

# 200 with credentials
curl -i -u demo:demo http://localhost:8080/odata/demo/Employees | head -1
```

## Edit feed data

Change values in the `$feeds` array at the top of `playground.php`:

```php
$feeds = [
    'demo' => [
        'Employees' => [
            ['Id', 'Name', 'Age', 'Department'],
            [1, 'Alice', 30, 'Engineering'],
            // ...
        ],
        'Products' => [
            ['Sku', 'Title', 'Price', 'InStock'],
            // ...
        ],
    ],
];
```

The server reads `$feeds` on every request, so OData and Excel refresh pick up changes immediately.

## When to re-run `--build`

Regenerate `playground.xlsx` only when you change:

- Sheet / entity-set names
- `PLAYGROUND_FEED_ID`
- OData base URL or port

```bash
php playground.php --build
```

## OData base URL

CLI `--build` defaults to `http://localhost:8080/odata`. Override for subdirectory or remote hosting:

```bash
php playground.php --build --base-url=http://localhost:8080
PLAYGROUND_BASE_URL=https://example.com/my-app php playground.php --build
```

When running under a web server, the OData URL is detected automatically from the request (`scheme`, `host`, and script path).

## Endpoints

With the dev server running:

| URL | Description |
|-----|-------------|
| `/` | Home page with links and feed preview |
| `/odata` | OData service document |
| `/odata/demo/$metadata` | CSDL metadata |
| `/odata/demo/Employees` | Employees entity set |
| `/odata/demo/Products` | Products entity set |
| `/playground.xlsx` | Download the workbook |

## Apache deployment

Copy this folder (including `vendor/`, `playground.php`, and `.htaccess`) to your host.

1. Set `RewriteBase` in `.htaccess` if the app is in a subdirectory (e.g. `/my-app/`).
2. Run `php playground.php --build --base-url=https://your-domain.com/my-app`.
3. Upload `playground.xlsx` or download it from `/playground.xlsx`.

Requires PHP 7.4+ and `mod_rewrite`.

## CLI reference

```bash
php playground.php --build    # Generate playground.xlsx
php playground.php --help       # Usage
php -S localhost:8080 playground.php   # Start dev server
```

## Files

| File | Purpose |
|------|---------|
| `playground.php` | Front controller, OData server, xlsx builder |
| `composer.json` | Local path deps for both packages |
| `.htaccess` | Apache rewrite rules |
| `playground.xlsx` | Generated workbook (gitignored) |

For a minimal writer-only demo without a server, see [example-1](../example-1/README.md).