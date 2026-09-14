# MK Factory — fakturační systém

Samostatný multi-tenant fakturační systém (Laravel 13, server-side Blade,
MariaDB/MySQL). MVP pokrývá vydané a přijaté faktury, kontakty, projekty,
PDF s QR Platbou (SPD 1.0) a dashboard. Podrobnosti v `docs/`.

## Požadavky

- PHP ≥ 8.4.1 (vyžadují zamčené závislosti v `composer.lock`; vyvíjeno
  na 8.5) s rozšířeními: bcmath, gd, pdo_mysql, mbstring, intl, fileinfo, zip
  - `pdo_mysql` je pro produkční MariaDB/MySQL; `pdo_sqlite` je potřeba jen
    pro izolovanou lokální testovací sadu (`composer test`)
- Composer 2
- MySQL 8 / MariaDB ≥ 10.4 (lokálně XAMPP)
- Node.js NENÍ potřeba (žádný JS build — statické CSS v `public/css/app.css`)
- Produkční nasazení na vlastní VPS: kontrakt v `docs/VPS_READINESS.md`

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

Rychlá hlavní sada (SQLite in-memory, viz `phpunit.xml`) — žádná příprava
databáze není potřeba:

```bash
composer test
```

#### Nad čím hlavní sada smí běžet

Sada jede přes `RefreshDatabase`, tedy spouští `migrate:fresh` —
**destruktivní operaci**. Povolený cíl je proto jediný a jmenovitý:

| | |
|---|---|
| spojení | `sqlite` |
| driver | `sqlite` |
| databáze | `:memory:` |
| `DB_URL` | nesmí být aktivní |
| read/write split | zakázán |

Hlídá to `Tests\Support\PrimaryTestDatabaseGuard`, volaný z
`Tests\TestCase::refreshApplication()`. Laravel tuhle metodu volá
v `setUpTheTestEnvironment()` **před** `setUpTraits()`, tedy před
`RefreshDatabase::refreshDatabase()` → `migrate:fresh`: závora rozhoduje
po bootstrapu konfigurace, ale ještě před první destruktivní operací,
a to bez ohledu na použité traity.

> **Proč ne `beforeRefreshingDatabase()`:** ten hook by byl sémanticky
> přesnější, ale v abstraktním předkovi nefunguje — PHP dává metodě
> z traity přednost před zděděnou metodou předka, takže prázdná
> implementace z `RefreshDatabase` tu naši v potomkovi přebije.

Kontroluje se **výsledná runtime konfigurace spojení**, tedy stav po
aplikaci `DB_URL` (`ConfigurationUrlParser` běží uvnitř
`DatabaseManager::configuration()`). Samotné `getenv()` by nestačilo:
`DB_URL=mysql://…` přepíše driver, host, port i databázi, aniž by se
`DB_CONNECTION` nebo `DB_DATABASE` změnily. Do chybové hlášky se vypisují
`DB_URL`, `DB_CONNECTION`, `DB_DATABASE`, `DB_HOST` a `DB_PORT`, aby bylo
vidět, odkud přesměrování přišlo (`DB_HOST`/`DB_PORT` SQLite ignoruje —
rozhoduje výsledná konfigurace, ne proměnná).

**Alternativní persistentní testovací databáze pro hlavní sadu vědomě
neexistuje** a opt-in na ni nemá být doplněn. Jediná destruktivní sada nad
skutečným serverem je souběžná a ta má vlastní přísný opt-in (níž).

Regrese hlídá `Tests\Feature\Testing\PrimaryTestDatabaseGuardTest`
(a nad MySQL `Tests\Concurrency\PrimaryDatabaseGuardMysqlProbeTest`):
podstrčené `DB_URL` i podstrčená persistentní SQLite musí sadu zastavit
dřív, než framework sáhne na schéma. Dokazuje to sonda
`Tests\Support\DestructiveMigrationProbeTest`, která si přepisuje
`migrateDatabases()` a klade marker — chybějící marker je důkaz pořadí,
ne jen toho, že nakonec vznikla výjimka.

### Testy souběhu nad MariaDB

Zámky (`SELECT … FOR UPDATE`) na SQLite **nefungují** — jsou tam no-op.
Zelená hlavní sada proto sama o sobě není důkazem správného souběhu.
Testy závislé na skutečných zámcích jsou proto oddělené a běží proti
MariaDB ve **skutečně samostatných procesech** (pcntl fork), každý
s vlastním DB spojením:

```bash
composer test:concurrency
```

Souběh je **deterministický**: workery se synchronizují bariérou
(socketpair). Každý dokončí přípravu, ohlásí rodiči `READY` a čeká;
rodič potvrdí připravenost všech a současně je uvolní `GO` do kritické
sekce. Rodič se od DB odpojuje před forkem, potomci tedy nezdědí žádné
PDO a otevírají si vlastní spojení (hlídá `ForkIsolationTest`); worker,
který bariéru nezavolá, test srozumitelně shodí.

Předpoklady:

- běžící MariaDB/MySQL a databáze `mk_factory_test`:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -e "CREATE DATABASE IF NOT EXISTS mk_factory_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

- PHP rozšíření `pcntl` a `posix` (bez nich se testy přeskočí, ne tiše
  „projdou“).

Konfigurace je v `phpunit.concurrency.xml`. Co sada pokrývá, je popsáno
v `docs/INVOICE_LIFECYCLE.md` (sekce Souběh).

#### Který endpoint se smí smazat

Sada volá `migrate:fresh`, tedy **zahodí celé schéma**. Cíl je proto
hlídaný dvakrát:

1. `phpunit.concurrency.xml` má u `DB_*` hodnot `force="true"`,
2. `Tests\Concurrency\ConcurrencyDatabaseGuard` běží v `setUp()` PŘED
   migrací a fail-closed ověří **celou identitu endpointu**.

Povolený endpoint je jmenovitý — odvozený z `phpunit.concurrency.xml`:

| | |
|---|---|
| spojení | `mysql` |
| driver | `mysql` nebo `mariadb` |
| host | `127.0.0.1` |
| port | `3306` |
| databáze | `mk_factory_test` |
| unix socket | zakázán (obchází host i port) |
| read/write split | zakázán |

Navíc musí `SELECT DATABASE()` vracet totéž jméno jako konfigurace (žádné
pozdější `USE jiná_db`). Body z tabulky se rozhodují **výhradně
z konfigurace**, takže se špatný endpoint odmítne ještě **před jakýmkoli
pokusem o spojení** — „špatný port“ tedy skončí rozhodnutím závory, ne
síťovou chybou.

Kontroluje se **výsledná runtime konfigurace** (tedy stav po aplikaci
`DB_URL`), ne jednotlivé `getenv()`. Dřív se ověřoval jen driver a jméno
schématu, což nestačí: schéma `mk_factory_test` může existovat na
libovolném serveru a `DB_PORT=1` prokazatelně přepsalo hodnotu z XML
i přes `force="true"`.

**XML samo o sobě nestačí a nikdy nestačilo**: PHPUnit u `<env>` zapisuje
`putenv()` a `$_ENV`, ale ne `$_SERVER` — a Laravel čte `$_SERVER` dřív.
Exportovaná proměnná prostředí se tedy k aplikaci dostane i s `force`.
Skutečnou brzdou je až kontrola v PHP.

Jiný endpoint jde povolit jen **úplným** opt-inem. Potvrzuje se celý cíl
(spojení, host, port, databáze) plus to, že jde o destruktivní testovací
endpoint; jméno databáze musí KONČIT na `_test` („obsahuje test“ nestačí):

```bash
MKF_CONCURRENCY_CONNECTION=mysql MKF_CONCURRENCY_HOST=127.0.0.1 MKF_CONCURRENCY_PORT=3306 MKF_CONCURRENCY_DATABASE=mkf_ci_test MKF_CONCURRENCY_DATABASE_CONFIRM=ano-smaz-tuto-databazi composer test:concurrency
```

Neúplný opt-in se **nedegraduje** na výchozí endpoint — rovnou se odmítne.
Opt-in povoluje jeden konkrétní endpoint, ne jejich třídu: potvrzený host
nepropustí jiný port ani jinou databázi. Vývojovou (`mk_factory`) ani
systémovou databázi nepovolí ani opt-in.

Regrese hlídají `Tests\Unit\ConcurrencyDatabaseGuardEndpointTest`
(čistý kontrakt, rychlá sada), `Tests\Unit\ConcurrencyDatabaseGuardNameRulesTest`
a `Tests\Concurrency\ConcurrencyDatabaseGuardTest` (běh nad skutečným
serverem, včetně override `DB_HOST`, `DB_PORT`, `DB_DATABASE` a `DB_URL`).

## Generovaná PDF a soubory

- PDF faktur se generují **on-demand** (tlačítko „Stáhnout PDF“ na detailu
  faktury) — na disk se neukládají.
- Logo organizace a přílohy přijatých faktur: `storage/app/private/`
  (privátní disk, mimo webroot).

## Externí služby

Aplikace volá veřejné API registru **ARES** (MF ČR) pro předvyplnění údajů
firem podle IČO — bez klíče a registrace. Je to jen pomůcka: při výpadku
registru lze vše zadat ručně. Vypnout jde přes `ARES_ENABLED=false`.

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
| docs/REVIEW_BRIEF.md | **podklad pro nezávislé review** (samostatný, pro externího recenzenta) |
| docs/PRODUCT_SCOPE.md | rozsah a ohrady produktu |
| docs/ARCHITECTURE.md | vrstvy, tenancy, peníze, kontrakty služeb |
| docs/DATA_MODEL.md | schéma databáze |
| docs/INVOICE_LIFECYCLE.md | stavy faktur, neměnnost, číslování |
| docs/PDF_AND_QR.md | volba knihoven, SPD, fonty |
| docs/ARES_INTEGRATION.md | načítání firem z ARESu, zakládání kontaktů |
| docs/INTEGRATION_CONTRACT.md | návrh budoucího REST API, napojení U Jabka |
| docs/SECURITY_NOTES.md | bezpečnostní model a známá omezení |
| docs/VPS_READINESS.md | kontrakt produkčního nasazení na vlastní VPS (runtime, cookies, cache, webroot, migrace dat) |
| docs/FUTURE_BACKLOG.md | odložené funkce |
