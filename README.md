# MK Factory — fakturační systém

Samostatný multi-tenant fakturační systém (Laravel 13, server-side Blade,
MariaDB/MySQL). MVP pokrývá vydané a přijaté faktury, kontakty, projekty,
PDF s QR Platbou (SPD 1.0) a dashboard. Podrobnosti v `docs/`.

## Požadavky

- PHP ≥ 8.3 (vyvíjeno na 8.5) s rozšířeními: pdo_mysql, pdo_sqlite, mbstring,
  bcmath, gd, intl, fileinfo, zip
- Composer 2
- MySQL 8 / MariaDB ≥ 10.4 (lokálně XAMPP)
- Node.js NENÍ potřeba (žádný JS build — statické CSS v `public/css/app.css`)

## Instalace (macOS, XAMPP)

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/mk-factory
composer install
cp .env.example .env        # pokud .env neexistuje
php artisan key:generate
```

## Konfigurace databáze

`.env` (výchozí hodnoty pro XAMPP MariaDB na 127.0.0.1:3306):

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mk_factory
DB_USERNAME=root
DB_PASSWORD=
```

Vytvoření databáze:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -e "CREATE DATABASE IF NOT EXISTS mk_factory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## Migrace a demo data

```bash
php artisan migrate           # schéma
php artisan db:seed           # demo data (organizace „U Jabka Demo“)
# nebo obojí najednou od nuly:
php artisan migrate:fresh --seed
```

Demo přihlášení: **demo@mkfactory.test** / **password**

## Spuštění

```bash
php artisan serve
```

Aplikace poběží na http://localhost:8000 (přihlašovací stránka).

## Testy

```bash
php artisan test
```

Testy běží proti SQLite in-memory (viz `phpunit.xml`), žádná příprava DB
není potřeba.

## Generovaná PDF a soubory

- PDF faktur se generují **on-demand** (tlačítko „Stáhnout PDF“ na detailu
  faktury) — na disk se neukládají.
- Logo organizace a přílohy přijatých faktur: `storage/app/private/`
  (privátní disk, mimo webroot).

## Omezení MVP

- Jen CZK, jedna jazyková verze (čeština).
- Zvláštní režim DPH jen „použité zboží“ (§ 90) s interní sazbou 21 %, ručně
  vystavované faktury plátce DPH; ostatní zvláštní režimy nejsou podporovány.
- Bez e-mailů, bankovního párování, OCR, dobropisů, záloh — viz
  `docs/FUTURE_BACKLOG.md`.
- Role owner/member zatím bez rozdílu oprávnění.
- Aplikace není certifikovaný účetní software (`docs/PRODUCT_SCOPE.md`).

## Dokumentace

| Soubor | Obsah |
|---|---|
| docs/PRODUCT_SCOPE.md | rozsah a ohrady produktu |
| docs/ARCHITECTURE.md | vrstvy, tenancy, peníze, kontrakty služeb |
| docs/DATA_MODEL.md | schéma databáze |
| docs/INVOICE_LIFECYCLE.md | stavy faktur, neměnnost, číslování |
| docs/PDF_AND_QR.md | volba knihoven, SPD, fonty |
| docs/INTEGRATION_CONTRACT.md | návrh budoucího REST API |
| docs/SECURITY_NOTES.md | bezpečnostní model a známá omezení |
| docs/FUTURE_BACKLOG.md | odložené funkce |
