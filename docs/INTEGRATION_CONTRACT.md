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
- **Externí identifikátory**: integrační systémy (U Jabka, MEX, Cashflow) se
  na entity odkazují VÝHRADNĚ přes `external_id` nebo interní numerické id —
  nikdy přes název. `external_id` už mají Project i Contact (unikátní v rámci
  organizace); IssuedInvoice ho dostane při implementaci API.
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

## Automatické fakturování z e-shopu (U Jabka)

První reálná integrace: U Jabka vystavuje fakturu automaticky při objednávce.
Odběratelem je **převážně fyzická osoba bez IČO**, což určuje způsob párování.

### Párování zákazníka — `App\Domain\Contacts\CustomerResolver`

Pořadí klíčů (první nález vyhrává):

1. **`external_id`** — id zákazníka v e-shopu. Jediný spolehlivý klíč,
   unikátní v rámci organizace. Integrace ho má posílat vždy.
2. **IČO** — jen u firemních objednávek, když ho zákazník uvedl.
3. **e-mail** — záchrana pro objednávky bez `external_id`.
4. jinak se založí nový kontakt.

Pravidla, na kterých integrace stojí:

- **IČO NENÍ povinné ani párovací.** Libovolný počet zákazníků bez IČO je
  v pořádku (v unikátním indexu se NULL opakovat smí).
- **E-mail se nevynucuje jako unikátní** — jednu adresu může sdílet víc
  osob (domácnost, firma) a tvrdá unikátnost by legitimní objednávky rozbila.
- Zná-li se zákazník už jako dodavatel, povýší se na „odběratel i dodavatel“
  místo vzniku druhého záznamu; chybějící `external_id` se doplní.

### Navržený tok objednávky

```http
POST /api/v1/invoices
Authorization: Bearer <token organizace>
Idempotency-Key: <uuid objednávky>     ← opakované doručení nevytvoří 2. fakturu

{
  "customer": {
    "external_id": "ujabka-cust-1042",
    "name": "Jana Nováková",
    "email": "jana@example.test",
    "street": "Krátká 5", "city": "Brno", "zip": "602 00"
  },
  "issue": true,
  "items": [ … ]
}
```

Server: vyřeší zákazníka resolverem → založí koncept → volitelně rovnou
vystaví (`issue: true`) → vrátí číslo faktury a odkaz na PDF. `Idempotency-Key`
odvozený od id objednávky zajistí, že opakované doručení webhooku nevytvoří
druhou fakturu.

### Otevřené otázky pro B2C (před ostrým nasazením)

- **GDPR**: fakturační údaje spotřebitelů jsou osobní údaje. Je potřeba lhůta
  archivace, výmaz a zpracovatelská smlouva mezi U Jabka a MK Factory.
- **Objem**: e-shop generuje výrazně víc dokladů než ruční fakturace —
  ověřit chování číselných řad a zámků při špičkách.
- **Zaokrouhlování a doprava** jako položka faktury musí odpovídat tomu, co
  ukazuje košík; jinak nesedí haléře.

### Stavy v API

Vrací se uložený stav + odvozené `is_overdue` (bool). `overdue` se nikdy
neukládá — klienti nespoléhají na push notifikaci změny stavu.

## Webhooky (výhled, mimo MVP)

`invoice.issued`, `invoice.paid`, `received_invoice.created` — podpis
HMAC-SHA256 hlavičkou `X-MkFactory-Signature`, retry s exponenciálním
backoffem.
