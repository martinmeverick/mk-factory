# PDF a QR Platba — posouzení knihoven a implementační rozhodnutí

Dokumentace vrstvy `App\Domain\Payments` (QR Platba) a `App\Domain\Pdf`
(generování PDF faktur). Kontrakt viz ARCHITECTURE.md, významy polí viz
DATA_MODEL.md.

## 1. Posouzení PDF knihoven

| Kritérium | dompdf | mPDF | TCPDF |
|---|---|---|---|
| Vstup | HTML + CSS (subset 2.1 + části 3) | HTML + CSS | vlastní API + omezené HTML |
| Licence | **LGPL-2.1** | **GPL-2.0** | LGPL-3.0 |
| Údržba (2026) | aktivní (v3.x) | aktivní | aktivní, ale letité API |
| Čeština out-of-box | ano (bundlovaný DejaVu Sans) | ano (DejaVu) | ano (dejavusans) |
| Binární závislosti | žádné (pure PHP + GD/mbstring) | žádné | žádné |
| Laravel integrace | barryvdh/laravel-dompdf (MIT) | jen komunitní wrappery | jen komunitní wrappery |

**Volba: dompdf + barryvdh/laravel-dompdf.**

Důvody:

- **Licence.** mPDF je GPL-2.0 — pro proprietární/komerční nasazení
  problematická (virální licence), proto vyřazen i přes kvalitnější CSS
  podporu. dompdf je LGPL-2.1 (užití jako knihovna bez omezení vlastního
  kódu), barryvdh wrapper MIT.
- **Blade workflow.** Faktura je šablona `resources/views/pdf/invoice.blade.php`
  — stejný způsob práce jako zbytek aplikace (SSR Blade), žádné skládání
  PDF přes imperativní API (TCPDF).
- **Pure PHP.** Žádné binární závislosti (wkhtmltopdf, Chromium/Browsershot)
  — funguje na běžném sdíleném Linux hostingu jen s PHP + GD + mbstring.
- **Čeština.** DejaVu Sans je bundlovaný přímo v dompdf, plné pokrytí
  české diakritiky bez instalace fontů (viz §4).

## 2. Posouzení QR knihoven

| Kritérium | endroid/qr-code | chillerlan/php-qrcode |
|---|---|---|
| Licence | **MIT** (jádro bacon/bacon-qr-code: BSD-2-Clause) | MIT |
| Údržba (2026) | aktivní (v6.x) | aktivní |
| API | Builder (named arguments), PNG/SVG/… | vlastní options objekt |
| Výstup data URI | vestavěné `getDataUri()` | ano |
| Závislosti | GD (PngWriter) | GD/Imagick volitelně |

**Volba: endroid/qr-code.** Obě knihovny jsou rovnocenné kvalitou; endroid má
čistší moderní API (readonly Builder, výčtové typy pro error correction),
přímou podporu data URI výstupu a je de-facto standard v Laravel ekosystému.
Licenčně bez problémů (MIT + BSD-2-Clause).

### Souhrn licencí použitých balíčků

| Balíček | Licence |
|---|---|
| dompdf/dompdf | LGPL-2.1 |
| barryvdh/laravel-dompdf | MIT |
| endroid/qr-code | MIT |
| bacon/bacon-qr-code | BSD-2-Clause |

Žádná GPL závislost — vhodné i pro proprietární nasazení.

## 3. QR Platba — formát SPD

Implementace `App\Domain\Payments\SpdPayload` sestavuje řetězec dle oficiální
specifikace ČBA **Short Payment Descriptor (SPD) 1.0**:
<https://qr-platba.cz/pro-vyvojare/specifikace-formatu/>

Použité klíče (deterministické pořadí, volitelné se vynechávají):

| Klíč | Význam | Pravidla implementace |
|---|---|---|
| `SPD*1.0` | hlavička | vždy |
| `ACC` | IBAN příjemce | normalizace (mezery pryč, uppercase), validace CZ formát 24 znaků + mod-97; prázdný/nevalidní → `InvalidArgumentException` (česká hláška) |
| `AM` | částka | z `Money::toDecimalString()` — tečka, 2 des. místa, **nikdy float**; nekladná částka → výjimka |
| `CC` | měna | z `Money::getCurrency()` |
| `DT` | splatnost | `YYYYMMDD` z `CarbonImmutable`; při `null` vynechán |
| `MSG` | zpráva pro příjemce | transliterace do ASCII + uppercase (doporučení ČBA pro alfanumerický režim, viz níže), odstranění `*`, ořez na 60 znaků; `null`/prázdná → vynechán |
| `X-VS` | variabilní symbol | jen číslice, max 10; jinak výjimka; `null` → vynechán |

Poznámky:

- **Transliterace MSG:** iconv `//TRANSLIT` je závislý na locale (na macOS
  bez `setlocale` selhává, na různých hostinzích se chová různě), proto se
  česká diakritika převádí napřed **vlastní převodní tabulkou** a iconv
  slouží jen jako fallback pro ostatní znaky; zbylé ne-ASCII znaky se
  zahazují. Výsledek je deterministický napříč platformami.
- **Uppercase MSG:** dle příkladu v ARCHITECTURE.md a doporučení ČBA
  (alfanumerický režim QR kóduje úsporněji → menší/čitelnější kód).
- Znak `*` je oddělovač SPD polí — ze zprávy se odstraňuje.
- Chybějící bankovní účet: `SpdPayload` vyhazuje výjimku; volající vrstva
  (UI/PDF mapper) QR vynechá a PDF se vygeneruje bez něj (kontrakt
  ARCHITECTURE.md).

`App\Domain\Payments\CzechIban` počítá CZ IBAN z tuzemského čísla účtu
(BBAN = kód banky 4 + předčíslí doplněné na 6 + číslo na 10, kontrolní
číslice ISO 13616 mod-97; mod-97 počítán po 7znakových blocích v celých
číslech — bez bcmath a bez přetečení). Podporuje i zápis s předčíslím
(`19-2000145399` přes `fromAccountNumber()`). Ověřeno proti referenčnímu
příkladu ČNB: `19-2000145399/0800` → `CZ6508000000192000145399`.

`App\Domain\Payments\QrPaymentImage::pngDataUri()` — endroid Builder,
error correction **M** (doporučení pro platební QR), malý margin
(`max(4, size/30)` px), PNG přes GD, výstup `data:image/png;base64,…`.

## 4. České fonty v PDF

dompdf bundluje rodinu **DejaVu Sans** (regular/bold/italic) s plným pokrytím
střední Evropy — šablona používá `font-family: 'DejaVu Sans'` a renderer
nastavuje `defaultFont => 'DejaVu Sans'`. Žádné doinstalace fontů, žádné
`load_font` skripty, funguje beze změn na libovolném hostingu.

## 5. Vkládání QR a loga — bezpečnost

- QR i logo se do šablony vkládají **výhradně jako data URI**
  (`data:image/png;base64,…`).
- `isRemoteEnabled` zůstává **false** a `isPhpEnabled` false — dompdf tak
  nesmí stahovat vzdálené zdroje (obrana proti SSRF přes uživatelský obsah
  v šabloně) ani spouštět inline PHP.
- Logo se čte z privátního disku (`storage/app/private`) a do data URI ho
  převádí mapper — šablona s filesystémem nepracuje.
- Vystavená faktura čte **snapshot loga** (`logo_snapshot_path`), nikdy
  aktuální logo organizace. Snapshot vzniká při vystavení ověřeným zápisem
  (`InvoiceLogoSnapshotStore::capture()` — chyba zápisu = `LogoSnapshotFailed`
  a vystavení se zastaví) a po rollbacku transakce se kompenzačně maže.
- **Nenakonfigurované logo ≠ chybějící logo.** `logo_path = NULL` je
  legitimní a faktura se vystaví bez loga; vyplněná cesta na chybějící,
  nečitelný nebo nepovolený soubor vystavení ZASTAVÍ (číslo se nespotřebuje).
  Zdrojová cesta musí být relativní, bez `..` a uvnitř `logos/org-{id}/`
  vlastní organizace; kontrola je řetězcová a běží před dotykem disku,
  protože Flysystem `..` normalizuje místo odmítnutí.
  Filesystem a DB nemají společnou transakci — přesný protokol, záruky
  a přiznané failure window popisuje `INVOICE_LIFECYCLE.md` (sekce Logo
  historického dokladu). Snapshot se vykreslí jen pro organizaci, jejíž id
  nese v cestě (`belongsToOrganization`).

## 6. Známá omezení dompdf

- **CSS subset:** bez flexboxu a gridu — layout šablony je postaven na
  tabulkách (`<table>`), což je pro dompdf spolehlivé. Podpora
  `position: fixed` funguje pro patičku na každé stránce; negativní
  `bottom` hodnoty jsou nespolehlivé (patička používá `bottom: 0`).
- **Tabulky:** dlouhá tabulka položek se stránkuje automaticky; `thead` se
  opakuje na další straně. Je třeba se vyhnout `border-collapse: separate`
  a složitým `colspan/rowspan` kombinacím.
- **Výkon:** render je synchronní a paměťově náročnější u velkých obrázků —
  logo se doporučuje rozumně zmenšit před uložením; QR 300 px je bezproblémové.
- PDF se negeneruje na disk, ale **on-demand** (download response) — viz
  ARCHITECTURE.md.
