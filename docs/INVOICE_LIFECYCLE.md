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

Zaručené chování:
- dvě souběžné platby 4 000 a 3 000 → dvě platby, `paid_amount_minor` 7 000,
- dvojitý `markPaid()` → právě jeden doplatek, uhrazeno nepřekročí celkem,
- dvojité vystavení → jedno spotřebované číslo, jeden přechod,
- souběh vystavení a smazání → vystavenou fakturu nelze odstranit,
- protichůdné přechody se serializují, druhý je odmítnut podle aktuálního
  stavu.

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

**Obecný escape hatch neexistuje.** Dřívější `allowLifecycleTransition()`
byl odstraněn — jakýkoli kód jím mohl vypnout ochranu. Chráněné atributy
zapisují jen úzce vymezené operace s whitelistem: `applyIssued()` (jen
atributy vystavení), `applyPaymentState()` (jen stav úhrady) a
`applyCancelled()`. Nelze jimi změnit organization_id, položky ani
libovolný atribut.

Guard navíc čte stav z DATABÁZE, ne z instance — zastaralá draft instance
tak po souběžném vystavení chráněné údaje nezmění ani doklad nesmaže.
Guard je ale **defense-in-depth**; proti souběhu chrání zámek, ne on.

Položky (`IssuedInvoiceItem`): creating/updating/deleting hook vyhodí
výjimku, pokud rodičovská faktura není draft.

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

Platí stejný vzor jako u vydaných faktur — transakce, tenant-scoped dotaz,
`lockForUpdate()`, kontrola stavu nad zamčeným řádkem. Souběžné protichůdné
přechody se tím serializují a druhý je odmítnut podle aktuálního stavu;
dvojitý `markPaid` nevytvoří druhou platbu.

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
