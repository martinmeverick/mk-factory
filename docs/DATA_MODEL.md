# Datový model

Závazná specifikace schématu. Všechny obchodní tabulky nesou `organization_id`
(multi-tenant izolace, viz ARCHITECTURE.md). Peněžní částky se ukládají
v **celočíselných haléřích** (`*_minor`, BIGINT) — nikdy float. Sazby DPH jako
DECIMAL(5,2). Množství jako DECIMAL(12,3). Stavy jako string sloupce s PHP
backed enumy (přenositelnost MariaDB/SQLite).

Pořadí migrací: `organizations` → `organization_members` → `contacts` →
`bank_accounts` → `invoice_number_series` → `organization_settings` →
`projects` → `issued_invoices` → `issued_invoice_items` → `received_invoices`
→ `received_invoice_attachments` → `payments` → `audit_logs`.

## organizations
| sloupec | typ | pozn. |
|---|---|---|
| id | bigint PK | |
| name | string | obchodní název |
| ico | string(20) null | |
| dic | string(20) null | |
| street, city, zip | string null | sídlo |
| country | string(2) default 'CZ' | |
| email, phone, website | string null | |
| logo_path | string null | cesta na disku `local` (privátní) |
| timestamps | | |

## organization_settings (1:1 s organizací)
| sloupec | typ |
|---|---|
| organization_id | FK unique, cascadeOnDelete |
| vat_payer | bool default false |
| default_due_days | unsignedSmallInteger default 14 |
| default_bank_account_id | FK bank_accounts null, nullOnDelete |
| default_number_series_id | FK invoice_number_series null, nullOnDelete |
| invoice_footer_text | text null |
| invoice_default_note | text null |

## organization_members
`organization_id` FK cascade, `user_id` FK cascade, `role` string
('owner'|'member', enum `MemberRole`), unique(organization_id, user_id).

## contacts
`organization_id`, `type` string ('customer'|'supplier'|'both', enum
`ContactType`), `name`, `ico` null, `dic` null, `street/city/zip` null,
`country` default 'CZ', `email` null, `phone` null, `note` text null.
Index (organization_id, type).

## bank_accounts
`organization_id`, `name`, `account_number` string(30) (český formát, může
obsahovat předčíslí `19-123456789`), `bank_code` string(4), `iban` string(34),
`bic` string(11) null, `is_default` bool default false.

## projects
`organization_id`, `name`, `code` string null (interní kód), `contact_id` FK
null nullOnDelete (klient), `status` string ('active'|'completed'|'archived',
enum `ProjectStatus`, default active), `external_id` string null (pro budoucí
napojení U Jabka / MEX / Cashflow — nikdy nepoužívat název jako klíč),
`note` text null. Unique(organization_id, external_id).

## invoice_number_series
`organization_id`, `name`, `prefix` string(10) default '', `year`
unsignedSmallInteger, `next_number` unsignedInteger default 1,
`number_format` string default `'{PREFIX}{YEAR}{NUMBER:4}'`.
Unique(organization_id, prefix, year).

Tokeny formátu: `{PREFIX}`, `{YEAR}` (4 číslice), `{YY}` (2 číslice),
`{NUMBER:n}` (pořadové číslo doplněné nulami na n míst).
Př.: prefix `FV`, rok 2026, číslo 7, formát `{PREFIX}{YEAR}{NUMBER:4}` →
`FV20260007`.

## issued_invoices
| sloupec | typ | pozn. |
|---|---|---|
| organization_id | FK | |
| number_series_id | FK null, restrictOnDelete | |
| contact_id | FK, restrictOnDelete | odběratel |
| project_id | FK null, nullOnDelete | |
| bank_account_id | FK null, nullOnDelete | účet pro platbu |
| status | string default 'draft' | enum `IssuedInvoiceStatus`: draft, issued, partially_paid, paid, cancelled (overdue je ODVOZENÝ, neukládá se — viz INVOICE_LIFECYCLE.md) |
| invoice_number | string null | přiděleno při vystavení; **unique(organization_id, invoice_number)** |
| variable_symbol | string(10) null | |
| issue_date | date | |
| due_date | date | |
| tax_date | date null | DUZP, volitelné |
| currency | char(3) default 'CZK' | |
| subtotal_minor | bigint default 0 | součet základů |
| vat_total_minor | bigint default 0 | |
| total_minor | bigint default 0 | |
| paid_amount_minor | bigint default 0 | denormalizace ze payments |
| note | text null | tisková poznámka |
| internal_note | text null | netiskne se |
| footer_text | text null | snapshot patičky při vystavení |
| supplier_snapshot | json null | snapshot dodavatele při vystavení |
| customer_snapshot | json null | snapshot odběratele při vystavení |
| bank_account_snapshot | json null | {account_number, bank_code, iban, bic} |
| issued_at, paid_at, cancelled_at | datetime null | |

Indexy: (organization_id, status), (organization_id, due_date).

Snapshoty (JSON) fixují údaje stran k okamžiku vystavení — pozdější změna
organizace/kontaktu nesmí změnit vystavenou fakturu. Draft se renderuje
z živých dat, vystavená faktura VŽDY ze snapshotů.

## issued_invoice_items
`issued_invoice_id` FK cascade, `organization_id`, `position` unsignedSmallInt,
`description` string, `quantity` decimal(12,3), `unit` string(20) default 'ks',
`unit_price_minor` bigint, `vat_rate` decimal(5,2) null (null = režim
neplátce), `line_subtotal_minor`, `line_vat_minor`, `line_total_minor` bigint.

Výpočet (bcmath, zaokrouhlení half-up na celé haléře, po řádcích):
- `line_subtotal_minor = round(quantity × unit_price_minor)`
- `line_vat_minor = round(line_subtotal_minor × vat_rate / 100)` (0 pro null)
- `line_total_minor = line_subtotal_minor + line_vat_minor`
Součty faktury = suma řádků. Rekapitulace DPH se skupinuje dle sazby.

## received_invoices
`organization_id`, `contact_id` FK restrictOnDelete (dodavatel), `project_id`
FK null nullOnDelete, `supplier_invoice_number` string null, `variable_symbol`
string(10) null, `issue_date` date null, `received_date` date,
`due_date` date null, `currency` char(3) default 'CZK', `total_minor` bigint,
`vat_minor` bigint null, `status` string default 'received' (enum
`ReceivedInvoiceStatus`: received, approved, paid, rejected; overdue odvozený),
`note` text null, `paid_at` datetime null.
Indexy: (organization_id, status), (organization_id, due_date).

## received_invoice_attachments
`received_invoice_id` FK cascade, `organization_id`, `original_filename`
string, `stored_path` string, `mime_type` string(100), `size_bytes`
unsignedBigInteger.

## payments
`organization_id`, morph `payable` (payable_type, payable_id — IssuedInvoice
nebo ReceivedInvoice), `amount_minor` bigint, `currency` char(3) default
'CZK', `paid_on` date, `note` string null.

## audit_logs
`organization_id` FK null, `user_id` FK null nullOnDelete, morph `subject`
(nullable), `action` string (např. `invoice.issued`,
`invoice.status_changed`, `invoice.payment_registered`), `changes` json null,
`created_at` (bez updated_at). Indexy: (organization_id, created_at),
(subject_type, subject_id).

## users
Standardní Laravel tabulka (name, email unique, password). Vazba na organizace
přes organization_members.

## Demo seed (DemoSeeder)
Pouze fiktivní údaje:
- uživatel `demo@mkfactory.test`, heslo `password`, jméno „Demo Uživatel";
- organizace **U Jabka Demo** (IČO 12345678, DIČ CZ12345678, plátce DPH,
  Jablečná 1, 110 00 Praha, splatnost 14 dní);
- bankovní účet `123456789/0100`, IBAN `CZ1801000000000123456789` (validní
  fiktivní), výchozí;
- číselná řada: název „Faktury", prefix `FV`, rok 2026, formát
  `{PREFIX}{YEAR}{NUMBER:4}`;
- 2 odběratelé, 2 dodavatelé (fiktivní firmy, faker cs_CZ);
- 2 projekty (jeden s external_id `ujabka-web`);
- vydané faktury: min. 1 draft, 1 issued, 1 issued po splatnosti, 1 paid;
- přijaté faktury: min. 1 received, 1 approved po splatnosti, 1 paid.
  (Druhý fiktivní dodavatelský IBAN: `CZ1403000000000987654321`.)
