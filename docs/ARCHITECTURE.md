# Architektura

Samostatná aplikace (NE modul U Jabka). Laravel 13, PHP ≥8.3, MariaDB/MySQL,
server-side rendering (Blade), žádný JS build krok (bez Node — vlastní CSS
v `public/css/app.css`, minimum vanilla JS inline pro dynamické řádky
položek).

## Vrstvy

```
routes/web.php
  └─ App\Http (controllery, FormRequesty, middleware, Policies)  ← tenká vrstva
       └─ App\Domain (čistá business logika, bez HTML)
            ├─ Money      – Money VO, výpočty položek a DPH
            ├─ Invoicing  – číslování, životní cykly, výjimky
            ├─ Tenancy    – CurrentOrganization, BelongsToOrganization
            ├─ Payments   – SpdPayload (QR Platba), CzechIban
            ├─ Pdf        – InvoicePdfData DTO, InvoicePdfRenderer
            ├─ Ares       – klient registru ARES (viz ARES_INTEGRATION.md)
            ├─ Contacts   – CzechIco, Supplier/CustomerResolver
            └─ Audit      – AuditLogger
       └─ App\Models (Eloquent, vazby, scopy, enum casty)
```

Zásada: controllery jen validují vstup (FormRequest), volají doménové
služby/modely a vracejí view/redirect. Business pravidla (přechody stavů,
číslování, výpočty, neměnnost) žijí v App\Domain — použitelné i budoucím
REST API bez HTML vrstvy (viz INTEGRATION_CONTRACT.md).

## Multi-tenancy (izolace organizací)

- `App\Domain\Tenancy\CurrentOrganization` — container singleton;
  `set(?Organization)`, `get(): ?Organization`, `id(): ?int`,
  `getOrFail(): Organization`.
- Middleware `SetCurrentOrganization` (alias `org`): vezme
  `session('current_organization_id')`, ověří členství přihlášeného
  uživatele (jinak session smaže a přesměruje na výběr organizace) a naplní
  singleton.
- Trait `App\Domain\Tenancy\BelongsToOrganization` na všech obchodních
  modelech: globální scope `WHERE organization_id = current` (aplikuje se jen
  když je current org nastavena) + auto-fill organization_id při create.
- Route-model binding tak mimo aktuální organizaci vrací 404 (scope zajistí,
  že cizí záznam „neexistuje“). Policies jsou druhá vrstva obrany.
- Testy: záznamy org B nesmí být viditelné s aktivní org A (find → null,
  count, binding 404).

## Peníze — závazná pravidla

- Ukládání: celočíselné haléře (`*_minor` BIGINT). Nikde float.
- `App\Domain\Money\Money` (immutable): `fromMinor(int, string $currency)`,
  `fromDecimalString('1234.56', 'CZK')`, `plus()`, `minus()`,
  `multiplyBy(string $decimalQty)` (bcmath, half-up na haléře),
  `formatCzech()` → `1 234,56 Kč`, `toDecimalString()` → `1234.56`,
  `getMinor()`, `getCurrency()`. Operace nad různými měnami vyhazují výjimku.
- `App\Domain\Money\InvoiceTotalsCalculator`: spočítá řádky
  (viz DATA_MODEL.md) a součty faktury + rekapitulaci DPH dle sazeb.
  Režim neplátce: vat_rate null, DPH 0, na PDF se DPH sekce nezobrazuje.
- Sazby DPH ČR (2026): 21 %, 12 %, 0 % — nabídka v UI; sloupec je obecný
  DECIMAL, výpočet funguje pro libovolnou sazbu.

## QR Platba (kontrakt pro App\Domain\Payments)

`SpdPayload` — sestavení řetězce dle oficiální spec ČBA „QR Platba" (SPD 1.0):

```php
SpdPayload::create(
    iban: 'CZ1801000000000123456789',
    amount: Money::fromMinor(123450, 'CZK'),
    variableSymbol: '20260007',      // ?string, jen číslice, max 10
    message: 'Faktura FV20260007',   // ?string
    dueDate: $carbonImmutable,       // ?CarbonImmutable → DT:YYYYMMDD
)->toString();
// SPD*1.0*ACC:CZ18...*AM:1234.50*CC:CZK*DT:20260815*MSG:FAKTURA...*X-VS:20260007
```

Pravidla: IBAN normalizovat (mezery pryč, uppercase) a validovat (formát CZ
+ mod-97, jinak `InvalidArgumentException`); AM s tečkou a max 2 des. místy
z Money (žádný float); CC z Money; X-VS jen číslice max 10; MSG
transliterace diakritiky do ASCII (iconv //TRANSLIT), odstranit `*`,
oříznout na 60 znaků; klíče v deterministickém pořadí ACC, AM, CC, DT, MSG,
X-VS. Prázdný/chybějící IBAN → výjimka (UI pak QR vynechá a zobrazí
upozornění, PDF se vygeneruje bez QR).

`CzechIban::fromCzechAccount(?string $prefix, string $number, string
$bankCode): string` — výpočet CZ IBAN; `CzechIban::isValid(string): bool`.

`QrPaymentImage::pngDataUri(SpdPayload, int $size = 300): string` — PNG jako
data URI (endroid/qr-code, GD), pro vložení do PDF i do detailu faktury.

## PDF (kontrakt pro App\Domain\Pdf)

- `InvoicePdfData` — readonly DTO se VŠEMI daty pro šablonu (strany jako
  pole, položky, součty v Money, rekapitulace DPH, vatPayer bool, čísla,
  data, poznámka, patička, `?string logoDataUri`, `?string qrDataUri`).
  Šablona NEsahá na Eloquent — mapper `InvoicePdfDataFactory` (App\Domain\Pdf)
  sestaví DTO z IssuedInvoice: draft z živých dat, vystavená faktura ze
  snapshotů.
- `InvoicePdfRenderer::render(InvoicePdfData): string` — PDF binárka přes
  dompdf (view `resources/views/pdf/invoice.blade.php`). Font DejaVu Sans
  (součást dompdf, plná čeština). Vzhled: čistý, konzervativní, černobílý
  s decentní typografií — žádné gradienty a dekorace.
- Uložení: vygenerované PDF se nekešuje na disk, generuje se on-demand
  (download response). `storage/app/private/...` slouží jen pro logo a
  přílohy přijatých faktur.

## Uploads

Logo organizace a přílohy přijatých faktur: disk `local` (privátní,
`storage/app/private`), validace mime (pdf/jpg/png) + velikost ≤ 10 MB,
uložení pod hash názvem, originální název v DB, download přes autorizovaný
controller (`Storage::download`). Nikdy ne public disk.

## Konvence

- Locale cs, timezone Europe/Prague, měna CZK (sloupce currency připraveny
  na budoucí multi-měnu, MVP pracuje s CZK).
- Datumy v UI: `d.m.Y`. Peníze: `1 234,56 Kč`.
- Testy: PHPUnit, SQLite :memory: (rychlost); zámky FOR UPDATE se na SQLite
  chovají jako no-op — korektnost číslování jistí i unikátní index (testováno).
- Kód anglicky, UI texty česky (natvrdo, bez lang souborů — MVP je čistě CZ).
