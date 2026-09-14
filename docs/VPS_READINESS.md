# Připravenost na vlastní VPS (faktury.ujabka.cz)

Tento dokument je **kontrakt** mezi aplikací a produkčním prostředím: co
aplikace vyžaduje a co předpokládá. Není to postup instalace, neobsahuje
příkazy s oprávněními a **netvrdí, že server je připraven**. Ověření
každého bodu je součástí nasazení, ne tohoto repozitáře.

## Runtime

| | Požadavek |
|---|---|
| PHP | ≥ 8.4.1 (`composer.json`: `^8.4.1`; zamčené závislosti to vyžadují) |
| Rozšíření (aplikace) | `bcmath` (peníze), `gd` (logo, QR, PDF), `pdo_mysql` (MariaDB) |
| Rozšíření (framework/knihovny) | `mbstring`, `intl`, `fileinfo`, `zip`, `ctype`, `openssl`, `tokenizer`, `dom`, `xml`, `curl` (ARES) |
| Databáze | MariaDB ≥ 10.4 / MySQL 8, `utf8mb4_unicode_ci` |
| Composer | 2, instalace `--no-dev --optimize-autoloader` z `composer.lock` |
| Node.js | **není potřeba** — žádný JS build, CSS je statické v `public/css/app.css` |

`pdo_sqlite` produkce nepotřebuje; slouží jen izolované lokální testovací
sadě (`composer test`, viz README).

## Aplikační prostředí (`.env`)

| Klíč | Produkční hodnota | Poznámka |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | nikdy `true` na veřejném hostu |
| `APP_URL` | `https://faktury.ujabka.cz` | základ pro generování URL |
| `APP_KEY` | **zachovat** stávající | viz Migrace dat níže |
| `SESSION_DRIVER` | `database` nebo `file` | nikoli `array` |
| `SESSION_COOKIE` | vlastní název, např. `mk_factory_session` | odlišit od jiných aplikací na doméně `ujabka.cz` |
| `SESSION_DOMAIN` | **nenastavovat** (host-only) | cookie platí jen pro `faktury.ujabka.cz`, ne pro poddomény |
| `SESSION_SECURE_COOKIE` | `true` | jen přes HTTPS |
| `SESSION_HTTP_ONLY` | `true` (výchozí) | |
| `SESSION_SAME_SITE` | `lax` | |
| `CACHE_STORE` | `database`, `file` nebo `redis` | **sdílená a trvalá** cache — viz níže |
| `DB_*` | produkční MariaDB, vlastní uživatel s heslem | ne `root` bez hesla |
| `ARES_ENABLED` | `true` | odchozí HTTPS na ARES MF ČR |

### Sdílená cache a limit přihlášení

Limit pokusů o přihlášení (`throttle:login`, `docs/SECURITY_NOTES.md`) drží
počítadla ve **výchozí cache**. PHP-FPM obsluhuje požadavky ve více
procesech, takže cache musí být sdílená mezi nimi a přežít restart
workeru: `database`, `file` (jeden stroj) nebo `redis`. S `array` cache
by limit fakticky neexistoval. Totéž platí pro cache ARES odpovědí.

## Webový server

- nginx s **webrootem výhradně `public/`**. Kořen repozitáře, `.env`,
  `storage/`, `vendor/`, `database/` nesmí být dostupné přes HTTP.
- PHP-FPM přímo za nginx na témže stroji, **bez další reverse proxy**.
  Aplikace nedůvěřuje `X-Forwarded-*` hlavičkám (`bootstrap/app.php`
  nenastavuje `trustProxies`); klientská IP je `REMOTE_ADDR`, jak ji
  předá nginx přes FastCGI. Pokud by proxy někdy přibyla, je nutné
  ji explicitně zapsat do `trustProxies` — jinak limit přihlášení uvidí
  jen IP proxy.
- HTTPS povinné (viz secure cookie); HTTP jen přesměrování.
- `php artisan config:cache`, `route:cache`, `view:cache` po nasazení;
  po každé změně `.env` znovu `config:cache`.

## Privátní úložiště

- `storage/app/private/` — loga organizací a přílohy přijatých faktur.
  Mimo webroot, zapisovatelné pro uživatele PHP-FPM, součást záloh.
- `storage/logs/`, `storage/framework/` — zapisovatelné pro PHP-FPM.
- `bootstrap/cache/` — připravená oprávnění pro generování a čtení
  aplikačních cache; ověřit pro nasazovacího uživatele i PHP-FPM.
- PDF faktur se generují on-demand a na disk se neukládají.

## Migrace dat a číslování

- Data se přenášejí **dumpem existující databáze** (schéma + data, včetně
  tabulek číselných řad a jejich čítačů), poté `php artisan migrate`
  pro případné nové migrace. Nikdy `migrate:fresh` ani seedery.
- `APP_KEY` musí zůstat stejný jako u prostředí, odkud data pocházejí —
  jinak přestanou platit podepsané/šifrované hodnoty (cookies, sessions,
  vše šifrované aplikačním klíčem).
- Po importu ověřit kontinuitu číslování: čítač každé řady má stejnou
  hodnotu jako ve zdrojové databázi a příští číslo neopakuje již
  přidělené číslo (viz `docs/INVOICE_LIFECYCLE.md`).
- **Skript `composer setup` je lokální scaffold** (kopie `.env.example`,
  `key:generate`, `migrate`, npm). Na existující data ani na produkci se
  **nesmí** použít — vygeneroval by nový `APP_KEY` v `.env`.

## Přihlášení a budoucí integrace

- V první fázi má MK Factory **vlastní, samostatné přihlášení**
  (session, uživatele zakládá správce). Limity přihlášení jsou
  v `docs/SECURITY_NOTES.md`.
- Vložení do administrace U Jabka, sdílené přihlášení (SSO) nebo REST API
  jsou **mimo tento krok** — návrh je v `docs/INTEGRATION_CONTRACT.md`.
  Host-only cookie a `SameSite=lax` výše jsou volené pro samostatný běh;
  případný embed (iframe, cross-site) bude vyžadovat vlastní rozhodnutí
  o cookies a CSRF.
