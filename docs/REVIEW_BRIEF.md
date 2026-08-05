# Zadání pro nezávislé review — MK Factory

Tento dokument je podklad pro **nezávislého recenzenta** (člověka nebo agenta),
který kód nepsal. Cílem je najít chyby, ne potvrdit, že je vše v pořádku.
Autorem kódu je jiný agent; nálezy se neberou osobně a **nesouhlas
s rozhodnutími níže je vítaný**, pokud je podložený.

---

## 1. Co aplikace je

MK Factory je samostatný **multi-tenant fakturační systém** (menší obdoba
základních funkcí iDokladu) pro firmu, která ho bude používat napříč několika
projekty. První reálné nasazení: projekt **U Jabka**; dále mk-systems,
Simona Vojtěšková, MEX, Cashflow.

Stav: **MVP foundation** — funkční vertikální průřez
organizace → odběratel → faktura → vystavení → PDF → QR platba,
plus přijaté faktury, projekty, dashboard a napojení na registr ARES.

**Aplikace zatím nebyla použita na skutečné faktury.** Review je předstupeň
tohoto rozhodnutí.

### Vědomě mimo rozsah
Bankovní API a párování plateb, OCR, datová schránka, odesílání e-mailem,
upomínky, opakované faktury, dobropisy, zálohové faktury, sklad, účetní
deník, daňová přiznání, exporty pro účetní SW, ISDOC, veřejný SaaS billing,
mobilní aplikace, **veřejné REST API** (zatím jen navržené).

Nálezy typu „chybí dobropisy" tedy nejsou nálezy. Nálezy typu „doménový model
neumožní dobropisy doplnit bez přepsání" naopak ano.

---

## 2. Technologie a spuštění

| | |
|---|---|
| PHP | ≥ 8.3 (vyvíjeno na 8.5) |
| Framework | Laravel 13 |
| DB | MariaDB 10.4 / MySQL 8 (testy: SQLite in-memory) |
| UI | server-side Blade, vlastní CSS, **žádný Node build** |
| PDF | dompdf + barryvdh/laravel-dompdf |
| QR | endroid/qr-code + bacon/bacon-qr-code |

```bash
composer install
cp .env.example .env && php artisan key:generate
# nastavit DB_* v .env, vytvořit databázi mk_factory
php artisan migrate:fresh --seed
php artisan serve            # http://localhost:8000
php artisan test             # 202 testů
```

Demo přihlášení: `demo@mkfactory.test` / `password`.

Testy běží proti SQLite in-memory, nepotřebují přípravu a **nesmí sahat na
síť** — `Tests\TestCase` volá `Http::preventStrayRequests()`.

---

## 3. Architektura v kostce

```
routes/web.php
  └─ App\Http (controllery, FormRequesty, middleware)   ← tenká vrstva
       └─ App\Domain (business logika, bez HTML)
            ├─ Money      Money VO, výpočty položek a DPH
            ├─ Invoicing  číslování, životní cykly, neměnnost
            ├─ Tenancy    CurrentOrganization, BelongsToOrganization
            ├─ Payments   SpdPayload (QR Platba), CzechIban
            ├─ Pdf        DTO + renderer + mapper z Eloquentu
            ├─ Ares       klient registru ARES
            ├─ Contacts   CzechIco, Supplier/CustomerResolver
            └─ Audit      AuditLogger
       └─ App\Models (Eloquent)
```

Podrobnosti v `docs/ARCHITECTURE.md`, `docs/DATA_MODEL.md`,
`docs/INVOICE_LIFECYCLE.md`, `docs/PDF_AND_QR.md`,
`docs/ARES_INTEGRATION.md`, `docs/INTEGRATION_CONTRACT.md`,
`docs/SECURITY_NOTES.md`.

### Čtyři invarianty, na kterých systém stojí

1. **Izolace organizací** — obchodní záznam jedné organizace nesmí být
   viditelný ani zapisovatelný z jiné.
2. **Peníze bez floatu** — vše v celočíselných haléřích (`*_minor`),
   aritmetika přes `App\Domain\Money\Money` (bcmath).
3. **Unikátní číslo faktury** v rámci organizace, přidělené při vystavení.
4. **Neměnnost vystavené faktury** — po vystavení nelze měnit kritické údaje;
   strany a bankovní spojení jsou zmrazené ve snapshotech.

Pokud kterýkoli z nich prolomíte, je to nález nejvyšší závažnosti.

---

## 4. Na co se soustředit

Seřazeno podle toho, kde je nejvyšší riziko.

### 4.1 Izolace organizací (nejvyšší priorita)
Drží ji **globální scope** traitu `BelongsToOrganization` plus **pořadí
middlewaru**: `SetCurrentOrganization` musí běžet **před**
`SubstituteBindings` (nastaveno v `bootstrap/app.php` přes
`prependToPriorityList`).

> Přesně tady už jedna kritická díra byla — binding se resolvoval dřív, než
> byla nastavena organizace, scope byl no-op a šlo číst cizí faktury i PDF,
> přepisovat cizí bankovní účty a číselné řady a mazat cizí přílohy.
> Opraveno v commitu `0016c4c`, regresi hlídá
> `tests/Feature/Security/AccessControlTest.php`.

Co prověřit: existuje jiná cesta, jak scope obejít? Hromadné
`update`/`delete`, `withoutGlobalScope`, raw dotazy, vztahy načtené z modelu
druhé organizace, `exists` validace bez `organization_id`, artisan příkazy
a fronty (tam organizace nastavená není).

### 4.2 Peněžní výpočty a DPH
`Money`, `InvoiceTotalsCalculator`. Zaokrouhlování half-up po řádcích, součty
jako suma řádků, rekapitulace DPH po sazbách. Sedí součet rekapitulace
s celkem vždy? Chování u záporných částek, velkých čísel, desetinného
množství, sazby 0 %, režimu neplátce (`vat_rate = null`).

### 4.3 Číslování faktur a souběh
`InvoiceNumberGenerator` (`lockForUpdate` v transakci) + unikátní index
`(organization_id, invoice_number)`. Je zámek účinný i při `issue()` volaném
paralelně? Co ruční změna `next_number` v nastavení? Co storno — číslo se
schválně nevrací do řady.

Pozor: na SQLite (testy) se `FOR UPDATE` chová jako no-op, takže testy
souběh neprokazují — správnost je potřeba posoudit z kódu.

### 4.4 Neměnnost vystavené faktury
`IssuedInvoice::PROTECTED_ATTRIBUTES` + `updating` hook,
`IssuedInvoiceItem` guardy, escape hatch `allowLifecycleTransition()`.
Lze obejít přes `forceFill`, query builder (`items()->delete()`),
`DB::table()`, hromadný update? Je escape hatch bezpečně omezená?

### 4.5 Uploady
Logo organizace a přílohy přijatých faktur, privátní disk
`storage/app/private`, whitelist `mimes`, hash názvy, download přes
autorizovaný controller. Path traversal, typ obsahu vs. přípona,
Content-Disposition, autorizace stažení i mazání.

### 4.6 ARES a zakládání kontaktů (nejnovější část, nejméně „usazená")
`App\Domain\Ares`, `App\Domain\Contacts`. Klíčové:
- Nesmí být závislost — výpadek registru nesmí zablokovat zaevidování faktury.
- `SupplierResolver` find-or-create podle IČO: nevznikají duplicity?
  Nespáruje se kontakt cizí organizace? Sedí ošetření souběhu?
- Ochrana veřejné služby: cache, throttle, předfiltr kontrolní číslice.
- **B2C**: odběratel bývá fyzická osoba **bez IČO** — unikátní index na IČO
  musí povolovat opakované NULL a IČO nesmí být párovacím klíčem.

### 4.7 QR Platba a PDF
`SpdPayload` (formát SPD 1.0), `CzechIban` (mod-97), `InvoicePdfDataFactory`.
Formátování částky bez floatu, escapování zprávy, chování bez bankovního
účtu. U PDF: renderuje se vystavená faktura ze snapshotů, ne z živých dat?

---

## 5. Rozhodnutí a jejich důvody

Nejsou nedotknutelná, ale ať je review nezdržuje jejich opakovaným objevováním.

| Rozhodnutí | Důvod |
|---|---|
| `overdue` se **neukládá**, odvozuje se z `due_date` a stavu | uložený stav vyžaduje plánovač a rozchází se s realitou |
| Vystavená faktura drží **snapshoty** stran a účtu | pozdější změna kontaktu nesmí změnit vystavený doklad |
| Storno **nevrací** číslo do řady | auditní stopa |
| Peníze v haléřích, `Money` VO | vyloučení floatu |
| dompdf, ne mPDF | mPDF je GPL-2.0, problematická pro proprietární nasazení |
| Bez Node/JS buildu | běžný Linux hosting, jednoduché nasazení |
| Kontrolní číslice IČO **není** tvrdá validace kontaktů | demo i historická data mají vymyšlená IČO; kontrola slouží jen jako brána před dotazem do ARESu |
| E-mail kontaktu **není** unikátní | jednu adresu sdílí domácnosti; tvrdá unikátnost by rozbila legitimní objednávky |

---

## 6. Známé slabiny (přiznané, ne skryté)

Nálezy v těchto bodech jsou platné a užitečné — zajímá nás hlavně
**závažnost a dopad**, protože o jejich existenci víme.

1. **Izolace organizací stojí na jedné vrstvě** (globální scope + pořadí
   middlewaru). `app/Policies` neexistuje, žádné `Gate`/`authorize()`.
2. **Všech 13 modelů má `$guarded = []`.** Dnes bez dopadu (controllery plní
   jen validovaná pole), ale jediné budoucí `Model::create($request->all())`
   by umožnilo podstrčit `organization_id` nebo `status`.
3. **Role `owner`/`member` nemají odlišná oprávnění** — každý člen může
   v rámci organizace vše.
4. **Na přihlášení není rate limiting** ani 2FA, chybí audit přihlášení.
5. **Demo data mají neplatná IČO** (12345678 neprojde mod-11) — proto je
   kontrola jen brána před ARESem, viz výše.
6. **Souběh není otestovaný reálně** — SQLite v testech zámky ignoruje.
7. **Neměnnost faktury vynucuje aplikace, ne databáze.**
8. Aplikace **není certifikovaný účetní software**; formální náležitosti
   dokladů (přenesená daňová povinnost, OSS, zahraniční odběratelé) neřeší.

---

## 7. Historie review

Kód už prošel jedním adversariálním review (commit `0016c4c`), které našlo
kritickou díru v izolaci organizací popsanou v 4.1. **Neberte to jako důkaz,
že oblast je čistá** — spíš jako signál, že v ní chyby vznikají.

Během ručního průchodu aplikací se navíc ukázalo, že testy nezachytily chybu
v mapování snapshotu (PDF vystavené faktury končilo chybou 500, commit
`01a7cec`). Stojí za to hledat další místa, kde testy testují doménu, ale
ne skutečný HTTP průchod.

---

## 8. Jak nálezy reportovat

U každého nálezu prosím:

1. **závažnost** — kritická / vysoká / střední / nízká,
2. **soubor:řádek**,
3. **konkrétní scénář zneužití nebo selhání** (vstupy → co se stane),
4. **doporučená oprava**, stručně,
5. je-li to možné, **test, který nález prokazuje** (do
   `tests/Feature/Security/` nebo odpovídající složky).

Prosím o **ověřené nálezy**, ne teoretické úvahy. Když si nejste jistý,
napište krátký test a spusťte ho — je to rychlejší než spekulace.

Zvlášť vítané: nález, který prolomí některý ze čtyř invariantů z části 3,
a upozornění na **chybějící test kritického chování**.

### Co nedělat
- Nepřepisovat architekturu ani nepřidávat produktové funkce.
- Neřešit formátování a styl, pokud nemá funkční dopad.
- Nepovažovat položky z části 1 („mimo rozsah") za chybějící funkce.

---

## 9. Fakta k datu předání

| | |
|---|---|
| Umístění | `/Applications/XAMPP/xamppfiles/htdocs/mk-factory` |
| Repozitář | `github.com/martinmeverick/mk-factory` (privátní) |
| Větev | `feature/invoicing-mvp-foundation` (do `main` nic nemergováno) |
| Poslední commit | `b006b6a` |
| Verzovaných souborů | 199 (bez `vendor/`) |
| Testy | 202 testů / 446 asercí, všechny procházejí |
| Migrace | 18 (z toho 3 skeletonové Laravelu) |
