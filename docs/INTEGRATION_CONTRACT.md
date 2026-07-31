# Integrační kontrakt (návrh budoucího API)

MVP veřejné API neimplementuje. Doménová vrstva (App\Domain, App\Models) ale
na HTML nezávisí — budoucí API controllery volají stejné služby jako web
(IssuedInvoiceLifecycle, InvoiceNumberGenerator, InvoicePdfRenderer…).
Tento dokument je závazný návrh, podle kterého se API doimplementuje.

## Zásady

- **Verzování v cestě**: `/api/v1/...`. Breaking změny = nová verze, v1
  se udržuje po dobu deprecation okna.
- **Autentizace**: API tokeny vázané na organizaci (ne na uživatele);
  hlavička `Authorization: Bearer <token>`. Implementace: Laravel Sanctum
  tokeny s abilities (`invoices:write`, `contacts:write`, `invoices:read`).
  Token určuje organizaci → tenant scope se aplikuje stejně jako v session.
- **Idempotence**: mutující endpointy přijímají hlavičku `Idempotency-Key`
  (UUID od klienta). Server ukládá (organization_id, key, request_hash,
  response) po dobu 24 h; opakovaný požadavek se stejným klíčem vrátí
  uloženou odpověď a nic nevytvoří podruhé.
- **Externí identifikátory**: entity Contact, Project a IssuedInvoice
  ponesou `external_id` (unikátní v rámci organizace a typu). Integrační
  systémy (U Jabka, MEX, Cashflow) se odkazují VÝHRADNĚ přes external_id
  nebo interní numerické id — nikdy přes název. Projekt už `external_id`
  má; kontaktům a fakturám se doplní nullable sloupec při implementaci API.
- **Formát**: JSON, částky jako string v desetinném tvaru `"1234.50"` +
  `currency` (nikdy float), datumy `YYYY-MM-DD`, časy ISO 8601 UTC.
- **Chyby**: RFC 9457 problem+json (`type`, `title`, `status`, `detail`,
  `errors` pro validaci).

## Navržené endpointy

| Metoda | Cesta | Účel |
|---|---|---|
| POST | `/api/v1/contacts` | vytvoření odběratele/dodavatele |
| GET | `/api/v1/contacts?external_id=…` | vyhledání kontaktu |
| POST | `/api/v1/invoices` | vytvoření konceptu faktury (hlavička + položky) |
| POST | `/api/v1/invoices/{id}/issue` | vystavení (přidělí číslo, zmrazí) |
| GET | `/api/v1/invoices/{id}` | detail vč. stavu a částek |
| GET | `/api/v1/invoices/{id}/pdf` | stažení PDF (`application/pdf`) |
| POST | `/api/v1/invoices/{id}/payments` | evidence úhrady / označení zaplaceno |
| PATCH | `/api/v1/invoices/{id}/project` | přiřazení k projektu (`project_external_id`) |
| POST | `/api/v1/projects` | vytvoření projektu s external_id |

### Příklad: vytvoření konceptu

```http
POST /api/v1/invoices
Authorization: Bearer <token>
Idempotency-Key: 0d4f7b3e-…

{
  "contact_external_id": "ujabka-cust-42",
  "project_external_id": "ujabka-web",
  "issue_date": "2026-08-01",
  "due_date": "2026-08-15",
  "items": [
    {"description": "Konzultace", "quantity": "2.5", "unit": "hod",
     "unit_price": "1200.00", "vat_rate": "21"}
  ]
}
→ 201 {"id": 123, "status": "draft", "total": "3630.00", "currency": "CZK"}
```

### Stavy v API

Vrací se uložený stav + odvozené `is_overdue` (bool). `overdue` se nikdy
neukládá — klienti nespoléhají na push notifikaci změny stavu.

## Webhooky (výhled, mimo MVP)

`invoice.issued`, `invoice.paid`, `received_invoice.created` — podpis
HMAC-SHA256 hlavičkou `X-MkFactory-Signature`, retry s exponenciálním
backoffem.
