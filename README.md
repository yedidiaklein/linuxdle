# Linuxdle

Linuxdle is a Wordle-like game for Linux distro nerds.

You get 6 guesses to find the daily Linux distro name. The puzzle is date-based and uses data from a local MySQL database.

## Features

- Daily puzzle seeded by date (`YYYY-MM-DD`)
- Wordle-style tile feedback (`correct`, `present`, `absent`)
- Distro list loaded from MySQL (`distros` table)
- Only distros with valid image files in `static/` are used
- Result card with distro logo and website link
- Share result:
  - Mobile: share to WhatsApp
  - Desktop: copy result to clipboard
- Custom Tux favicon
- Footer attribution and PayPal donation button

## Project Structure

```text
index.php
config.php
linuxdle.sql
favicon.ico
static/
  linuxdle.css
```

## Requirements

- PHP 8+
- MySQL or MariaDB
- Web server (Apache/Nginx) serving this directory

## Database Setup

1. Create a database named `linuxdle`.
2. Import the SQL dump:

```bash
mysql -u <user> -p linuxdle < linuxdle.sql
```

## Configuration

Database config is stored in `config.php`.

Current format:

```php
return [
    'db' => [
        'host' => 'localhost',
        'user' => 'linuxdle',
        'pass' => 'linuxdle',
        'name' => 'linuxdle',
        'port' => 3306,
    ],
];
```

You can also override values with environment variables:

- `LINUXDLE_DB_HOST`
- `LINUXDLE_DB_USER`
- `LINUXDLE_DB_PASS`
- `LINUXDLE_DB_NAME`
- `LINUXDLE_DB_PORT`

## Run Locally

If you want a quick local run with PHP built-in server:

```bash
php -S 0.0.0.0:8000
```

Then open:

- `http://localhost:8000`


## Gameplay Rules

- You have 6 attempts.
- Every guess must match the daily answer length.
- Tile colors:
  - Green: correct letter, correct position
  - Yellow: letter exists, wrong position
  - Gray: letter not in answer

## Notes

- `config.php` is in `.gitignore` so credentials stay local.

## License

GNU General Public License (GPL).

Copyright (C) 2026 Yedidia Klein
