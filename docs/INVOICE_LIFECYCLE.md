# Životní cyklus faktur

Veškeré přechody stavů provádí VÝHRADNĚ doménové služby
`App\Domain\Invoicing\IssuedInvoiceLifecycle` a
`App\Domain\Invoicing\ReceivedInvoiceLifecycle`. Controllery stavy nikdy
nemění přímo. Každý přechod běží v DB transakci a zapisuje AuditLog.

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
bank_account_id (pokud organizace účet má). V transakci:
1. přepočítá součty položek (`InvoiceTotalsCalculator`),
2. přidělí číslo z řady (`InvoiceNumberGenerator`, viz níže),
3. doplní variable_symbol, pokud je prázdný (číslice z čísla faktury, max 10),
4. vytvoří snapshoty supplier/customer/bank_account + footer_text ze settings,
5. status → issued, issued_at = now, audit `invoice.issued`.

### registerPayment(IssuedInvoice, int $amountMinor, CarbonImmutable $paidOn, ?string $note)
Jen pro issued/partially_paid. Vytvoří Payment, přičte paid_amount_minor;
paid_amount ≥ total → status paid + paid_at, jinak partially_paid.
Audit `invoice.payment_registered`.

### markPaid(IssuedInvoice, CarbonImmutable $paidOn)
Zkratka: zaregistruje platbu zbývající částky.

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
bank_account_snapshot, footer_text.

Povolené i po vystavení: status, paid_amount_minor, paid_at, cancelled_at,
issued_at, internal_note, project_id (interní evidence, netiskne se).
Lifecycle služba interně používá `$invoice->allowLifecycleTransition()`
(příznak na modelu), aby mohla nastavit chráněná pole při vystavení.

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
