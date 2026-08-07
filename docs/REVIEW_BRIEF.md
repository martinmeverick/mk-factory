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

Stav: **MVP foundation po čtyřech kolech nezávislého review** —
funkční vertikální průřez organizace → odběratel → faktura → vystavení →
PDF → QR platba, plus přijaté faktury, projekty, dashboard a napojení na
registr ARES.

**Aplikace zatím nebyla použita na skutečné faktury.** Review je předstupeň
tohoto rozhodnutí.

> **Pro opakované review:** devět nálezů z kola 2 i nálezy z re-review
> (kolo 3 — lifecycle bypassy, kontrakt updateDraft, přijaté faktury,
> deterministický souběh, email-only párování, snapshot loga, PHP_INT_MIN)
> je opraveno a každý má regresní test, u kterého bylo ověřeno, že bez
> opravy selže. Přehled je v části 7. Nálezy z části 6 (známé slabiny)
> opravené NEJSOU — jsou to vědomá omezení, ne regrese.

> **Stav lifecycle hardeningu:** poslední closure review skončilo
> `LIFECYCLE HARDENING: NOT CLOSED` kvůli třem HIGH nálezům (souběžné
> mazání přílohy, načasování závory hlavní testovací DB, identita
> endpointu souběžné DB). Všechny tři jsou opravené — viz kolo 5
> v části 7. Zbývá je nezávisle ověřit.

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
php artisan serve                  # http://localhost:8000
composer test                      # rychlá sada (SQLite in-memory)
composer test:concurrency          # souběh nad MariaDB (viz níže)
```

Demo přihlášení: `demo@mkfactory.test` / `password`.

Hlavní sada běží proti SQLite in-memory, nepotřebuje přípravu a **nesmí
sahat na síť** — `Tests\TestCase` volá `Http::preventStrayRequests()`.

**Souběh se na SQLite ověřit nedá** — `SELECT … FOR UPDATE` je tam no-op.
Testy zámků proto běží odděleně proti MariaDB (databáze `mk_factory_test`,
konfigurace `phpunit.concurrency.xml`) ve skutečně samostatných procesech
přes `pcntl_fork`, synchronizovaných deterministickou bariérou (workery
vstupují do kritické sekce současně, až když rodič potvrdí připravenost
všech). Bez rozšíření `pcntl`/`posix` se přeskočí — nikdy se netváří
jako splněné. Postup je v README.

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

### 4.0 Souběh a zámky (nejvyšší priorita)

Každá mutace faktury musí jet podle vzoru: transakce → tenant-scoped dotaz →
`lockForUpdate()` → kontrola stavu nad ZAMČENÝM řádkem → změna → audit.
Vzor je popsaný v `docs/INVOICE_LIFECYCLE.md`.

Co prověřit: existuje mutační cesta, která se vzoru vyhne (controller,
observer, příkaz, budoucí API)? Rozhoduje někde ještě stav načtené
instance místo zamčeného řádku? Je zámek držen po celou dobu výpočtu
zaplacené částky?

**Nespoléhejte na zelenou hlavní sadu** — SQLite zámky ignoruje. Souběh
ověřuje `composer test:concurrency` nad MariaDB ve skutečných procesech.

### 4.1 Izolace organizací
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

### 4.3 Číslování faktur
`InvoiceNumberGenerator` (`lockForUpdate` v transakci) + unikátní index
`(organization_id, invoice_number)`. Zámek řady je ale JEN zámek řady —
fakturu zamyká lifecycle zvlášť (viz 4.0). Co ruční změna `next_number`
v nastavení? Co storno — číslo se schválně nevrací do řady. Neplatný doklad
(nulový/záporný součet) číslo spotřebovat nesmí.

### 4.4 Neměnnost vystavené faktury a lifecycle polí
`IssuedInvoice::PROTECTED_ATTRIBUTES` + `updating` hook (čte stav
z DATABÁZE, ne z instance), `deleting` hook, guardy `IssuedInvoiceItem`.
Lifecycle pole (`status`, `invoice_number`, `paid_amount_minor`,
`issued_at`, `paid_at`, `cancelled_at`) nemají ŽÁDNOU veřejnou zápisovou
cestu — jediná je privátní `persistLifecycleState()`, ke které se lifecycle
služba váže přes `Closure::bind` (dřívější veřejné `applyIssued()`/
`applyPaymentState()`/`applyCancelled()` byly odstraněny, `@internal`
v PHPDoc není přístupový modifikátor). Totéž drží `ReceivedInvoice`
(status, paid_at + finalita paid/rejected) a `Payment` je append-only.

Lze to obejít přes `forceFill`, query builder (`items()->delete()`),
`DB::table()` nebo hromadný update? Umí interní zápis přijmout víc, než
má? Pozor: guard je defense-in-depth, proti souběhu chrání zámek; cesty
mimo Eloquent eventy (`DB::table()`, raw SQL, `withoutEvents()`) guard
z principu nevidí — viz známá slabina 6.

### 4.5 Uploady
Logo organizace a přílohy přijatých faktur, privátní disk
`storage/app/private`, whitelist `mimes`, hash názvy, download přes
autorizovaný controller. Path traversal, typ obsahu vs. přípona,
Content-Disposition, autorizace stažení i mazání.

### 4.6 Peníze na hranicích rozsahu
`Money` kontroluje rozsah PŘED castem bcmath řetězce na int
(`assertWithinRange`, `MoneyOverflow`). Existuje cesta, kudy hodnota projde
do databáze bez kontroly? Sedí kontrola i pro záporné hodnoty a pro součty
jednotlivě platných položek?

### 4.7 ARES a zakládání kontaktů (nejnovější část, nejméně „usazená")
`App\Domain\Ares`, `App\Domain\Contacts`. Klíčové:
- Nesmí být závislost — výpadek registru nesmí zablokovat zaevidování faktury.
- `SupplierResolver` find-or-create podle IČO: nevznikají duplicity?
  Nespáruje se kontakt cizí organizace? Sedí ošetření souběhu?
- Ochrana veřejné služby: cache, throttle, předfiltr kontrolní číslice.
- **B2C**: odběratel bývá fyzická osoba **bez IČO** — unikátní index na IČO
  musí povolovat opakované NULL a IČO nesmí být párovacím klíčem.

### 4.8 QR Platba a PDF
`SpdPayload` (formát SPD 1.0), `CzechIban` (mod-97), `InvoicePdfDataFactory`.
Formátování částky bez floatu, escapování zprávy, chování bez bankovního
účtu. U PDF: renderuje se vystavená faktura ze snapshotů, ne z živých dat?
Odpovídá QR stavu dokladu a zbývající částce (tabulka
v `docs/INVOICE_LIFECYCLE.md`)? Používá historický doklad snapshot loga
a respektuje přitom tenant izolaci?

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
6. **Neměnnost faktury vynucuje aplikace, ne databáze.**
7. Aplikace **není certifikovaný účetní software**; formální náležitosti
   dokladů (přenesená daňová povinnost, OSS, zahraniční odběratelé) neřeší.

---

## 7. Historie review

Kód prošel dvěma koly nezávislého review. **Neberte opravené oblasti jako
důkaz, že jsou čisté** — spíš jako signál, že v nich chyby vznikají.

### Kolo 1
Adversariální review našlo kritickou díru v izolaci organizací
(`SubstituteBindings` běžel před tenant middlewarem). Opraveno v `0016c4c`.
Ruční průchod navíc odhalil chybu v mapování snapshotu, kterou testy
nezachytily (PDF vystavené faktury končilo chybou 500, `01a7cec`).

### Kolo 2 — devět nálezů, všechny opravené

| # | Nález | Oprava | Regresní test |
|---|---|---|---|
| 1 | Zastaralá instance mohla změnit i smazat vystavenou fakturu | mutace přes zamčený řádek; guard čte stav z DB | `StaleInvoiceInstanceTest` |
| 2 | Obecný veřejný escape hatch `allowLifecycleTransition()` | odstraněn; úzce vymezené operace s whitelistem | `StaleInvoiceInstanceTest` |
| 3 | Vystavení a platby nebyly serializované | `lockForUpdate()` před kontrolou stavu i výpočtem částky | `tests/Concurrency` (MariaDB) |
| 4 | QR znělo na celkovou částku i u uhrazené faktury | QR podle stavu, na zbývající částku; stavové štítky v PDF | `InvoicePdfStateTest` |
| 5 | Párování zákazníka bralo první kontakt podle e-mailu | striktní pořadí klíčů, ambiguita → výjimka | `CustomerResolverTest` |
| 6 | Šlo vystavit nulovou i zápornou fakturu | kontrola kladného součtu před spotřebováním čísla | `InvoiceTotalGuardTest` |
| 7 | `Money` saturovala na `PHP_INT_MAX` | kontrola rozsahu před castem, `MoneyOverflow` | `MoneyOverflowTest` |
| 8 | Audit bral organizaci z ambientního contextu | odvození ze subjektu, fail closed při neshodě | `AuditTenantTest` |
| 9 | Historické PDF četlo aktuální logo organizace | snapshot loga při vystavení | `InvoicePdfStateTest` |

U každého bylo ověřeno, že test **bez opravy selže** (dočasným vrácením
změny). Nejnázornější doklady: bez zámku skončí souběžné platby 4 000 + 3 000
na `paid_amount_minor` = 3 000 a souběh vystavení a smazání vystavenou
fakturu odstraní; bez kontroly rozsahu se uloží `9223372036854775807`.

### Kolo 3 — re-review oprav (3× PARTIAL, 1× OPEN → uzavřeno)

Nezávislé re-review kola 2 potvrdilo 5 nálezů jako CLOSED a reprodukovalo
zbývající slabiny. Všechny jsou opravené a mají regresní test, u kterého
bylo ověřeno, že bez opravy selže:

| Nález re-review | Oprava | Regresní test |
|---|---|---|
| Veřejné `applyIssued()`/`applyPaymentState()`/`applyCancelled()` obcházely lifecycle | odstraněny; jediná cesta je privátní `persistLifecycleState()` + guard lifecycle polí pro každý veřejný zápis | `LifecycleBypassTest` |
| `updateDraft()` přijímal neomezené pole (šlo změnit organization_id, invoice_number…) | explicitní whitelist hlavičky i položek, neznámý klíč = výjimka před transakcí | `UpdateDraftContractTest` |
| Stale instance mohla změnit i smazat uhrazenou přijatou fakturu | update/delete přes lifecycle (zamčený řádek) + modelové guardy podle stavu v DB; přílohy uhrazené faktury zmrazené; platby append-only | `StaleReceivedInvoiceTest`, `ReceivedInvoiceConcurrencyTest` (MariaDB) |
| Concurrency testy bez deterministické synchronizace | socketpair bariéra READY/GO, rodič se odpojuje před forkem, workery mají prokazatelně vlastní spojení | `ConcurrencyTestCase`, `ForkIsolationTest` |
| Souběžné email-only párování vytvořilo duplicitní kontakty; e-mail nekanonizovaný | trim+lowercase kanonizace při hledání i ukládání, tenant-scoped `GET_LOCK` nad kanonickým e-mailem | `CustomerResolverTest`, `CustomerResolverConcurrencyTest` (MariaDB; bez opravy vzniknou 2 kontakty) |
| Snapshot loga: ignorovaný výsledek `put()`, žádná kompenzace po rollbacku | ověřený zápis (`LogoSnapshotFailed`), kompenzační `discard()` po rollbacku, přiznané failure window | `LogoSnapshotConsistencyTest` |
| `abs(PHP_INT_MIN)` přetékalo do floatu při formátování | `toDecimalString()`/`formatCzech()` čistě přes řetězce | `MoneyBoundaryFormattingTest` |

### Kolo 4 — re-review oprav kola 3 (8 nových nálezů, všechny uzavřené)

Re-review potvrdilo jádro oprav (interní zápis přes `Closure::bind`,
Eloquent guardy, peněžní hranice), ale našlo osm konkrétních děr na jejich
okrajích. Všechny jsou opravené a mají regresní test ověřený dočasným
vrácením opravy:

| # | Nález | Oprava | Regresní test |
|---|---|---|---|
| 1 | `migrate:fresh` mohl dopadnout na databázi podstrčenou proměnnou prostředí | `force="true"` v XML **plus** povinný `ConcurrencyDatabaseGuard` (driver, config vs. `SELECT DATABASE()`, allowlist, denylist, opt-in) | `ConcurrencyDatabaseGuardTest`, `ConcurrencyDatabaseGuardNameRulesTest` |
| 2 | `updateDraft()` přijal tenantově cizí `contact_id`/`project_id`/`bank_account_id`/`number_series_id` | ověření každé reference proti `organization_id` zamčené faktury před jakýmkoli zápisem | `DraftReferenceOwnershipTest` |
| 3 | Změnou parent ID šlo odpojit položku nebo přílohu od finálního dokladu | vlastnická vazba i `organization_id` potomka jsou neměnné; guard finality čte PŮVODNÍHO rodiče | `InvoiceChildOwnershipTest` |
| 4 | Emailový `GET_LOCK` se uvnitř cizí transakce uvolnil před commitem | email-only větev uvnitř transakce fail-closed odmítne (`CustomerResolutionNotTransactional`); timeout má doménový typ | `CustomerResolverConcurrencyTest` (MariaDB) |
| 5 | Stav `rejected` nebyl všude finální (šlo smazat fakturu i měnit přílohy) | jediný zdroj pravdy `FINAL_STATUSES` + `isFinal()` napříč modelem, lifecycle, guardy, controllerem a šablonou | `RejectedReceivedInvoiceTest` |
| 6 | Nakonfigurované, ale chybějící logo se tiše ignorovalo | rozlišení „logo není“ vs. „logo chybí“; druhý případ zastaví vystavení, číslo se nespotřebuje | `LogoSnapshotConsistencyTest` |
| 7 | Odmítnutý upload nechal osiřelý soubor a vrátil HTTP 500 | upload přes lifecycle: zámek → kontrola stavu → soubor → záznam → audit, plus kompenzační úklid a kontrolovaná odpověď | `ReceivedInvoiceAttachmentUploadTest` |
| 8 | Dvojí volání bariéry mohlo způsobit falešně zelený souběžný test | jednorázová bariéra, typované zprávy, striktní base64, odmítnutí neznámého typu | `BarrierContractTest`, `BarrierProtocolDecodingTest` |

Navíc (mimo osm nálezů, stejná třída rizika): **hlavní sada** šla přes
`DB_URL` v prostředí přesměrovat na vývojovou databázi a `composer test`
by ji přes `RefreshDatabase` smazal. Zavřeno `force="true"` v `phpunit.xml`
a runtime kontrolou v `Tests\TestCase`.

> **Poznámka k `force="true"`:** samo o sobě NESTAČÍ. PHPUnit u `<env>`
> zapisuje `putenv()` a `$_ENV`, ale ne `$_SERVER`, a Laravel čte `$_SERVER`
> dřív — exportovaná proměnná se tedy k aplikaci dostane i s `force`.
> Skutečnou brzdou jsou až kontroly v PHP. Ověřeno empiricky.

### Kolo 5 — closure review (3 HIGH nálezy, všechny uzavřené)

Closure review potvrdilo předchozí opravy, ale skončilo verdiktem
`LIFECYCLE HARDENING: NOT CLOSED` kvůli třem novým HIGH nálezům. Všechny
mají regresní test ověřený dočasným vrácením opravy:

| # | Nález | Oprava | Regresní test |
|---|---|---|---|
| 1 | Souběžné mazání přílohy obešlo finalitu přijaté faktury — guard četl stav rodiče bez zámku, mezi čtením a DELETE se vešel cizí `markPaid()` | operace přesunuta do `ReceivedInvoiceLifecycle::deleteAttachment()`: transakce → zámek RODIČE → kontrola stavu → znovunačtení přílohy → DB delete → audit → commit → teprve pak soubor | `ReceivedInvoiceAttachmentDeletionTest`, `ReceivedInvoiceAttachmentConcurrencyTest` (MariaDB) |
| 2 | Runtime závora hlavní testovací DB běžela až po `parent::setUp()`, tedy až po `RefreshDatabase` → `migrate:fresh` (reprodukováno: 22 tabulek v podstrčeném schématu) | `Tests\Support\PrimaryTestDatabaseGuard` volaný z `Tests\TestCase::refreshApplication()`, tedy před `setUpTraits()`; kontroluje výslednou runtime konfiguraci (spojení, driver, `:memory:`, aktivní `DB_URL`, read/write split) | `PrimaryTestDatabaseGuardTest`, `PrimaryDatabaseGuardMysqlProbeTest` (MariaDB) |
| 3 | Závora souběžné DB neověřovala host, port ani jméno spojení — `DB_PORT=1` přepsalo XML i s `force="true"` a Laravel se skutečně pokusil připojit jinam | guard ověřuje celou identitu endpointu z výsledné runtime konfigurace (spojení, driver, host, port, databáze, socket, split) a rozhoduje PŘED spojením; jiný endpoint jen s úplným opt-inem | `ConcurrencyDatabaseGuardEndpointTest`, `ConcurrencyDatabaseGuardNameRulesTest`, `ConcurrencyDatabaseGuardTest` (MariaDB) |

Doklady bez opravy: nález 1 skončil `paid` fakturou bez přílohy odstraněné
až po jejím finalizačním zámku (a cross-tenant mazání přílohy prošlo);
nález 2 vyrobil v podstrčeném MySQL schématu 22 tabulek a v podstrčené
persistentní SQLite spustil `migrate:fresh`; nález 3 nechal Laravel
skutečně navázat spojení na neschválený host/port (30 s timeout,
resp. odmítnutí na portu 1) místo řízeného odmítnutí.

Podrobnosti k testovací infrastruktuře (přesná identita obou povolených
databází a jak funguje opt-in) jsou v README, sekce Testy.

**Kde hledat dál:** oblasti kolem oprav (nové cesty, které vzor obcházejí),
a místa, kde testy ověřují doménu, ale ne skutečný HTTP průchod.

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
| Stav | HEAD větve po opravách z closure review (kolo 5, viz část 7) |
| Testy (SQLite) | `composer test` — 397 testů / 1007 asercí |
| Testy souběhu (MariaDB) | `composer test:concurrency` — 37 testů / 117 asercí, deterministická jednorázová bariéra |
| Migrace | 19 (z toho 3 skeletonové Laravelu) |
