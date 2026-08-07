# Životní cyklus faktur

Veškeré přechody stavů provádí VÝHRADNĚ doménové služby
`App\Domain\Invoicing\IssuedInvoiceLifecycle` a
`App\Domain\Invoicing\ReceivedInvoiceLifecycle`. Controllery stavy nikdy
nemění přímo. Každý přechod běží v DB transakci a zapisuje AuditLog.

## Závazný vzor každé mutace

Každá metoda lifecycle, která mění fakturu, dodržuje toto pořadí:

1. `DB::transaction(...)`
2. tenant-scoped dotaz (organizace faktury; nastavený `CurrentOrganization`
   se musí shodovat, jinak `InvoiceNotFound` — fail closed)
3. `lockForUpdate()`
4. načtení AKTUÁLNÍ faktury ze zamčeného řádku
5. kontrola stavu **nad zamčeným záznamem**, nikdy nad instancí volajícího
6. doménová validace
7. změna
8. audit
9. commit

**Stav se nikdy neposuzuje podle instance předané volajícím.** Ta může být
zastaralá: dvě PHP instance téhož konceptu, první ho vystaví, druhá by
jinak prošla guardem a přepsala vystavený doklad. Controller proto
o editovatelnosti nerozhoduje — dělá to až zamčený řádek.

Při chybě rolluje zpět všechno: číslo faktury, řádek platby, změna stavu,
auditní záznam i snapshoty.

### Souběh

Zámky jsou ověřené skutečnými procesy nad MariaDB, ne domněnkou:
`composer test:concurrency` (viz README). SQLite `SELECT … FOR UPDATE`
ignoruje, takže hlavní sada souběh **neprokazuje**.

Souběžné testy jsou **deterministické**: workery se synchronizují bariérou
(socketpair mezi rodičem a potomky). Každý worker dokončí přípravu — vlastní
DB spojení, načtení modelů — ohlásí `READY` a blokuje; rodič potvrdí
připravenost VŠECH a teprve pak je současně uvolní `GO` do kritické sekce.
Rodič se od DB odpojuje PŘED forkem, takže potomci žádné PDO nezdědí
a každý si otevírá vlastní spojení (hlídá `ForkIsolationTest`). Worker,
který bariéru nezavolá, test shodí — překryv nikdy nestojí na náhodě
plánovače.

Bariéra je **jednorázová** a protokol **typovaný** (`READY`, `GO`,
`RESULT:<base64>`, `ERROR:<base64>`). Dřív byl poziční: druhé (chybné)
volání bariéry poslalo další `READY`, rodič ho přečetl místo výsledku,
`base64_decode('READY', true)` vrátilo `false`, přetypování na string
udělalo prázdný řetězec — a test falešně prošel jako úspěch. Teď druhé
volání vyhodí výjimku ještě PŘED zápisem do kanálu, poškozený base64 se
odmítá striktně a neznámý typ zprávy test shodí. Chybějící bariéra,
bariéra navíc i porušený protokol se hlásí jako `ProtocolViolation`,
nikdy jako „worker skončil s chybou“. Hlídá to `BarrierContractTest`.

Cíl destruktivního `migrate:fresh` prověřuje `ConcurrencyDatabaseGuard`
(viz README, sekce „Která databáze se smí smazat“).

Zaručené chování:
- dvě souběžné platby 4 000 a 3 000 → dvě platby, `paid_amount_minor` 7 000,
- dvojitý `markPaid()` → právě jeden doplatek, uhrazeno nepřekročí celkem,
- dvojité vystavení → jedno spotřebované číslo, jeden přechod,
- souběh vystavení a smazání → vystavenou fakturu nelze odstranit,
- protichůdné přechody se serializují, druhý je odmítnut podle aktuálního
  stavu,
- přijaté faktury: dvojitý `markPaid()` → jedna platba; souběh
  `markPaid()` s update/delete skončí konzistentně (uhrazenou fakturu už
  stale operace nezmění ani nesmaže),
- email-only párování zákazníka nevytvoří duplicitní kontakt
  (viz INTEGRATION_CONTRACT.md).

Číselná řada má vlastní zámek, ale **není zámkem faktury** — obojí je
potřeba.

## Vydaná faktura — stavy a přechody

Uložené stavy: `draft`, `issued`, `partially_paid`, `paid`, `cancelled`.

```
draft ──issue()──▶ issued ──payment──▶ partially_paid ──payment──▶ paid
                     │                      │
                     ├──payment (plná)──▶ paid
                     └──cancel()──▶ cancelled   (jen bez evidované platby)
```

Mapa povolených přechodů (jediné místo pravdy v `IssuedInvoiceLifecycle`):
- draft → issued
- issued → partially_paid | paid | cancelled
- partially_paid → paid
- paid → (nic), cancelled → (nic)

`overdue` se NEUKLÁDÁ — je odvozený: stav ∈ {issued, partially_paid}
∧ due_date < dnešek. Model poskytuje `scopeOverdue()` a accessor
`display_status`, který vrací `overdue` místo uloženého stavu.
Důvod: uložený stav by vyžadoval plánovač a vznikala by nekonzistence;
odvození je vždy pravdivé. Totéž platí pro přijaté faktury.

### issue(IssuedInvoice $invoice): void
Předpoklady: status draft, ≥1 položka, vyplněný contact_id, number_series_id,
bank_account_id (pokud organizace účet má). V transakci nad zamčeným řádkem:
1. přepočítá součty položek (`InvoiceTotalsCalculator`),
2. **ověří kladný součet** (viz níže) — neplatný doklad nesmí spotřebovat číslo,
3. přidělí číslo z řady (`InvoiceNumberGenerator`, viz níže),
4. doplní variable_symbol, pokud je prázdný (číslice z čísla faktury, max 10),
5. vytvoří snapshoty supplier/customer/bank_account + footer_text ze settings,
6. zmrazí logo organizace kopií (`InvoiceLogoSnapshotStore` → logo_snapshot_path),
7. status → issued, issued_at = now, audit `invoice.issued`.

**Kladný součet je podmínkou vystavení.** Nulový i záporný výsledek skončí
`InvoiceNotIssuable` — validovanou doménovou chybou, ne neodchycenou výjimkou
později v `markPaid()`. Kontrola běží PO finálním přepočtu položek, uvnitř
transakce a PŘED spotřebováním čísla řady. Záporné položky (slevy) zůstávají
povolené, dokud je výsledek kladný. Dobropisy a nulové doklady zatím nejsou
v rozsahu (viz FUTURE_BACKLOG.md).

### registerPayment(IssuedInvoice, int $amountMinor, CarbonImmutable $paidOn, ?string $note)
Jen pro issued/partially_paid — stav i dosud uhrazená částka se čtou ze
ZAMČENÉHO řádku. Vytvoří Payment, přičte paid_amount_minor přes `Money`
(přetečení → `MoneyOverflow`, žádná saturace); paid_amount ≥ total → status
paid + paid_at, jinak partially_paid. Úhrada nesmí překročit celkovou částku.
Audit `invoice.payment_registered`.

### markPaid(IssuedInvoice, CarbonImmutable $paidOn)
Doplatí zbývající částku. Zbytek se počítá až nad zamčeným řádkem, takže
souběžné volání nevytvoří druhý doplatek; už uhrazená faktura skončí
`InvalidStateTransition`.

### updateDraft() a deleteDraft()
Úprava a smazání konceptu. Editovatelnost se rozhoduje až nad zamčeným
řádkem — controller ji podle dřív načtené instance neposuzuje. Souběh
vystavení a smazání se tím serializuje a vystavenou fakturu nelze odstranit.

`updateDraft(header, items)` má **uzavřený kontrakt**: hlavička přijímá
POUZE editovatelná pole formuláře (contact_id, project_id, bank_account_id,
number_series_id, issue_date, due_date, tax_date, variable_symbol, note,
internal_note, currency), položky pouze description, quantity, unit,
unit_price_minor, vat_rate a line_* součty (ty stejně přepočítá
kalkulačka). Klíč mimo whitelist — `organization_id`, `status`,
`invoice_number`, `paid_amount_minor`, snapshoty, timestampy, cokoli
neznámého — končí `InvalidArgumentException` PŘED transakcí: nezapíše se
nic, ani legitimní část změny. Tiché ignorování by skrylo programátorskou
chybu volajícího. `position` a `organization_id` položek doplňuje výhradně
lifecycle služba z hodnot zamčené faktury.

**Whitelist klíčů nestačí — kontroluje se i KAM reference ukazují.**
Povolený `contact_id` může nést cizí id, takže se každá nenulová reference
(contact_id, project_id, bank_account_id, number_series_id) ověřuje proti
`organization_id` ZAMČENÉ faktury, nikdy proti ambientnímu tenant contextu
(ten může být nastavený jinak nebo vůbec — konzole, fronta). Kontrola běží
uvnitř transakce, ale PŘED změnou hlavičky, nahrazením položek, přepočtem
i auditem, takže selhání (`InvalidInvoiceReference`) odvalí úplně všechno.
HTTP validace v `IssuedInvoiceRequest` zůstává jako první vrstva, ale
doménová služba si kontrakt hlídá sama.

### cancel(IssuedInvoice)
Jen z issued a jen bez evidovaných plateb. cancelled_at = now, audit
`invoice.cancelled`. Číslo faktury zůstává spotřebované (řada se nevrací) —
auditní stopa.

## Neměnnost vystavené faktury

Jakmile status != draft, model `IssuedInvoice` v `updating` hooku vyhodí
`App\Domain\Invoicing\ImmutableInvoiceViolation`, pokud se mění chráněný
atribut: invoice_number, variable_symbol, issue_date, due_date, tax_date,
contact_id, number_series_id, bank_account_id, currency, subtotal_minor,
vat_total_minor, total_minor, supplier_snapshot, customer_snapshot,
bank_account_snapshot, footer_text, note, logo_snapshot_path.

Povolené i po vystavení: status, paid_amount_minor, paid_at, cancelled_at,
issued_at, internal_note, project_id (interní evidence, netiskne se).

**Obecný escape hatch neexistuje a lifecycle pole nemají ŽÁDNOU veřejnou
zápisovou cestu.** Dřívější `allowLifecycleTransition()` byl odstraněn už
v minulém kole; veřejné metody `applyIssued()`/`applyPaymentState()`/
`applyCancelled()` v tomto — `@internal` v PHPDoc není přístupový
modifikátor a šly volat odkudkoli bez validace, zámku i auditu.

Platí dvě vrstvy:

1. **Lifecycle pole** (`status`, `invoice_number`, `paid_amount_minor`,
   `issued_at`, `paid_at`, `cancelled_at`) odmítne `updating` guard při
   KAŽDÉM veřejném zápisu (`update()`, `save()`, `forceFill()->save()`)
   bez ohledu na stav dokladu — koncept tedy nelze „vystavit“ přímým
   přepsáním stavu, nelze podvrhnout částku úhrady ani stornovat mimo
   lifecycle. Jediná zápisová cesta je PRIVATE metoda
   `persistLifecycleState()` s vlastním whitelistem (organization_id ani
   položky jí neprojdou); `IssuedInvoiceLifecycle` se k ní váže přes
   `Closure::bind` do scope modelu (obdoba friend třídy). Stejný vzor drží
   `ReceivedInvoice` pro `status` + `paid_at`.
2. **Chráněné atributy dokladu** (PROTECTED_ATTRIBUTES) guard odmítá po
   vystavení, `organization_id` — tenant identitu — v každém stavu.

Guard čte stav z DATABÁZE, ne z instance — zastaralá draft instance tak po
souběžném vystavení chráněné údaje nezmění ani doklad nesmaže. Guard je ale
**defense-in-depth**; proti souběhu chrání zámek, ne on. Framework cesty
mimo Eloquent eventy (`DB::table()`, raw SQL, `Model::withoutEvents()`)
guard z principu nevidí — neměnnost na úrovni databáze vynucená není
(známá slabina č. 6 v REVIEW_BRIEF.md) a aplikační kód je nesmí používat
k zápisu faktur.

Položky (`IssuedInvoiceItem`): creating/updating/deleting hook vyhodí
výjimku, pokud rodičovská faktura není draft.

**Vlastnická vazba potomka je po vytvoření NEMĚNNÁ.** `issued_invoice_id`
(u příloh `received_invoice_id`) ani `organization_id` nelze update()em
změnit — reparenting by byl zadní vrátka: guard se ptal na stav rodiče
podle NOVÉ hodnoty, takže položku vystavené faktury šlo „přestěhovat“ na
koncept, guard se zeptal konceptu, změnu povolil a historický doklad
o položku přišel. Guard finality proto navíc vychází z PŮVODNÍHO rodiče
(`getOriginal()`), u `creating` z aktuální hodnoty. Totéž platí pro mazání:
`delete()` maže podle primárního klíče, takže podvržené parent ID v paměti
by jinak smazalo řádek pod původním (finálním) dokladem. Přepis položek
konceptu NENÍ reparenting — `updateDraft()` je maže a zakládá znovu.

Platby (`Payment`): **append-only**. Vznikají výhradně v lifecycle
službách; updating/deleting hook je odmítne vždy — jinak by se historie
plateb rozešla s `paid_amount_minor` a stavem dokladu. Storno platby jako
operace zatím neexistuje (FUTURE_BACKLOG.md).

## Číslování faktur (InvoiceNumberGenerator)

`nextNumber(InvoiceNumberSeries $series): string` — v transakci:
1. znovu načte řadu `lockForUpdate()` (SELECT … FOR UPDATE),
2. sestaví číslo z number_format tokenů,
3. inkrementuje next_number, uloží.

Souběh řeší zámek řádku řady; unikátní index
issued_invoices(organization_id, invoice_number) je pojistka. Volá se
výhradně uvnitř `issue()` (sdílená transakce).

## Přijatá faktura — stavy a přechody

Uložené stavy: `received`, `approved`, `paid`, `rejected` (+ odvozený overdue).

- received → approved | rejected | paid
- approved → paid | rejected
- paid → (nic), rejected → (nic)

`markPaid` nastaví paid_at (+ Payment záznam), `approve`/`reject` jen mění
stav; vše přes `ReceivedInvoiceLifecycle` s auditem.

**Také úprava a smazání jdou výhradně přes lifecycle** — controller nic
nerozhoduje podle dřív načtené instance:

- `updateDetails(invoice, attributes)` — whitelist editovatelných polí
  (contact_id, project_id, supplier_invoice_number, variable_symbol,
  issue_date, received_date, due_date, total_minor, vat_minor, currency,
  note); neznámý klíč, tenant i stavová pole končí
  `InvalidArgumentException` před transakcí. Editovatelné jsou jen stavy
  received/approved — rozhoduje ZAMČENÝ řádek, takže stale instance po
  cizím `markPaid()` doklad nezmění. Audit `invoice.updated` s diffem.
- `delete(invoice)` — uhrazenou fakturu nesmaže (kontrola nad zamčeným
  řádkem); přílohy maže v téže transakci, jejich soubory až PO commitu
  (rollback nesmí nechat záznamy bez souborů). Audit `invoice.deleted`.
- `deleteAttachment(attachment)` — smazání JEDNÉ přílohy. Finalita je
  vlastnost RODIČE, takže operace zamyká rodičovskou fakturu, ne přílohu:
  transakce → tenant-scoped zamčení rodiče → kontrola aktuálního stavu →
  znovunačtení přílohy pod zamčeným rodičem → DB delete → audit
  `invoice.attachment_removed` → commit → teprve pak soubor.

### Finální stavy — jediný zdroj pravdy

`ReceivedInvoice::FINAL_STATUSES` = **paid, rejected**. Tenhle seznam
(a predikáty `isFinal()` / `isFinalStatus()`) používá VŠECHNO, co o finalitě
rozhoduje: `updating` i `deleting` guard modelu, `updateDetails()`,
`delete()`, `attach()` i `deleteAttachment()` v lifecycle, guardy příloh,
controller i šablona.
Dřív se na několika místech testoval jen `=== Paid` samostatně, takže
zamítnutou fakturu šlo smazat a její přílohy měnit i mazat. Ručně opsané
seznamy stavů se proto v této doméně nepoužívají — rozejdou se.

Model má stejné guardy jako vydaná faktura: `status` a `paid_at` mají
jedinou (privátní) zápisovou cestu lifecycle služby, `organization_id` je
neměnné vždy a fakturu ve finálním stavu `updating` guard odmítne změnit
celou — podle stavu v DATABÁZI, ne podle instance. `deleting` guard odmítne
smazat fakturu v jakémkoli finálním stavu. Finální fakturu tedy nelze ani
vrátit do předchozího stavu, ani přesunout mezi organizacemi. Její přílohy
jsou zmrazené (creating/updating/deleting guard na
`ReceivedInvoiceAttachment`) a UI k ní upload ani mazání příloh nenabízí.

### Přílohy: pořadí zápisu a kompenzace

`attach()` jede: transakce → tenant-scoped zamčení → kontrola AKTUÁLNÍHO
stavu → **teprve pak** zápis souboru → DB záznam → audit → commit. Dřív se
soubor ukládal jako první a guard finální faktury insert odmítl až potom,
takže na disku zůstal osiřelý soubor a HTTP vrátilo neošetřenou 500.
Filesystem není součástí DB transakce, proto navíc kompenzace: selže-li
cokoli po zápisu souboru (DB, audit, guard), soubor se smaže. Controller
doménové chyby překládá na redirect s flash zprávou, ne na 500.

Mazání jde opačně (nejdřív DB záznam, pak soubor) — odmítnuté mazání by
jinak nechalo záznam bez souboru. Zbývající failure window: pád procesu
mezi commitem a smazáním souboru nechá osiřelý soubor bez odkazu z DB,
což je bezpečný směr (stejně jako u snapshotu loga).

#### Mazání jedné přílohy pod zámkem rodiče

Individuální mazání dřív dělal přímo controller (`$attachment->delete()`)
a o finalitě rozhodoval `deleting` guard modelu. Ten sice četl stav
z DATABÁZE, ale **bez zámku rodiče**, takže mezi jeho čtením a samotným
DELETE se vešel cizí `markPaid()`. Deterministická reprodukce nad MariaDB
skončila `phase=checked result=deleted final_status=paid attachment_count=0`
— sekvenční stale guard fungoval, skutečný souběh ne.

Operace proto sedí v `ReceivedInvoiceLifecycle::deleteAttachment()` a jede
stejným vzorem jako ostatní mutace přijaté faktury. **Pořadí zámků je
shodné s `delete()` i `attach()`: nejdřív rodič, pak potomek** — žádný nový
deadlock pattern nevzniká. Rodič i tenant se určují z ULOŽENÝCH hodnot
přílohy (`getOriginal()`), takže podvržené `received_invoice_id` ani
`organization_id` v paměti guard nepřesměruje.

Controller (`AttachmentController::destroy()`) o finalitě nerozhoduje
vůbec: zavolá lifecycle operaci a doménovou chybu přeloží na redirect
s flash zprávou.

Filesystem se řeší až PO commitu a jeho selhání se **nevrací do DB** —
`deleteAttachment()` vrací `false` a zapíše `Log::warning`. Provozní
kompenzací je úklid osiřelých souborů (soubor bez odkazu z DB); opačný
směr, tedy živý záznam ukazující na neexistující soubor, přijatelný není.

Souběžné protichůdné operace se serializují zámkem řádku a druhá je
odmítnuta podle aktuálního stavu; dvojitý `markPaid` nevytvoří druhou
platbu, `markPaid` vs. update/delete končí konzistentně (ověřeno MariaDB
sadou `ReceivedInvoiceConcurrencyTest`). Obě serializované varianty
mazání přílohy ověřuje `ReceivedInvoiceAttachmentConcurrencyTest`:

| Kdo získá zámek rodiče první | Výsledek |
|---|---|
| `markPaid` | faktura je `paid`, mazání přílohy odmítnuto (`InvalidStateTransition`), příloha i soubor zůstávají |
| `deleteAttachment` | příloha i soubor zmizí, následný `markPaid` pracuje nad konzistentním stavem |

Zakázaný výsledek je jediný: `paid` faktura bez přílohy, která byla
odstraněna až PO jejím finalizačním zámku.

## QR platba podle stavu (PDF)

| Stav | QR |
|---|---|
| draft | žádné |
| issued, overdue | na zbývající částku (= celkem, není-li uhrazeno) |
| partially_paid | jen na `total_minor − paid_amount_minor` |
| paid | žádné |
| cancelled | žádné |

Zbývající částka nikdy není záporná. PDF navíc zobrazuje stavový štítek
(ČÁSTEČNĚ UHRAZENO / UHRAZENO / STORNOVÁNO) a u uhrazené či stornované
faktury nepoužívá popisek „Celkem k úhradě“.

## Logo historického dokladu

Při vystavení se logo organizace zmrazí KOPIÍ do `invoice-logos/org-{id}/`
(relativní cesta na disku `local`, viz `InvoiceLogoSnapshotStore`) a uloží
do `logo_snapshot_path`. Pozdější změna ani smazání firemního loga proto
historický doklad nezmění. Koncept se renderuje z aktuálního loga
organizace; snapshot se vykreslí jen pro vlastní organizaci.

### Nakonfigurované vs. chybějící logo

Rozlišují se dva různé stavy, které se dřív slévaly do jednoho `return null`:

| Stav | Chování |
|---|---|
| `logo_path` je NULL — organizace logo nemá | legitimní; faktura se vystaví bez snapshotu |
| `logo_path` vyplněná, ale soubor chybí, nejde přečíst nebo cesta není použitelná | `LogoSnapshotFailed`; vystavení se odvalí, faktura zůstane konceptem, číslo se NESPOTŘEBUJE a audit nevznikne |

Druhý případ dřív tiše vrátil null — doklad se vystavil bez loga, které si
organizace nastavila, a spotřeboval číslo. Náprava pro uživatele je nahrát
logo znovu nebo ho v nastavení odebrat.

Použitelná cesta = relativní, bez `..` a bez řídicích znaků, uvnitř
`logos/org-{id}/` vlastní organizace. Kontrola je čistě řetězcová a běží
PŘED jakýmkoli dotykem filesystemu: Flysystem `..` normalizuje (nezakazuje),
takže `logos/org-2/../org-3/logo.png` by prefixem prošlo a přečetlo cizí
adresář, a cesta s řídicími znaky by z adaptéru vyhodila negenerickou
výjimku.

### Konzistence souboru a databáze — kompenzace, ne společná transakce

Filesystem a MariaDB **společný commit nemají** a nic to nepředstírá.
Drží se kompenzační protokol:

1. soubor snapshotu vzniká UVNITŘ zamčené transakce vystavení, ale PŘED
   spotřebováním čísla řady a PŘED zápisem lifecycle polí; cesta je
   unikátní pro (organizaci, fakturu, otisk obsahu),
2. výsledek zápisu se OVĚŘUJE — `put()` vracející false, výjimka adaptéru
   i nečitelný zdroj končí `LogoSnapshotFailed` a vystavení se zastaví
   (faktura s logem organizace se bez snapshotu nevystaví; cesta se nikdy
   neuloží bez existujícího souboru),
3. selže-li COKOLI dalšího v transakci, DB se odvalí a `issue()`
   kompenzačně smaže soubor vytvořený tímto pokusem (`discard()`).

**Zbývající failure window** (přiznané): spadne-li PROCES mezi zápisem
souboru a DB commitem, kompenzace se nespustí a zůstane **osiřelý soubor**
— bez odkazu z DB, faktura zůstává konceptem. Je to bezpečný směr: opačný
stav (vystavený doklad odkazující na chybějící soubor) vzniknout nemůže,
protože cesta se ukládá jen v transakci, která existenci souboru ověřila.
Osiřelý soubor další pokus o vystavení přepíše stejným obsahem (stejná
cesta z otisku); ruční úklid = smazat soubory `invoice-logos/…`, na které
neukazuje žádný `logo_snapshot_path`.
