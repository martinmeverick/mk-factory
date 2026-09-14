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

Pořadí klíčů:

1. **`external_id`** — id zákazníka v e-shopu. Jediný spolehlivý klíč,
   unikátní v rámci organizace. **U Jabka (a každá další integrace) ho MUSÍ
   posílat vždy** — e-mail je slabý fallback pro ruční/nedokonalé vstupy,
   ne párovací mechanismus, se kterým smí integrace počítat.
2. **IČO** — jen u firemních objednávek bez `external_id`.
3. **e-mail** — jen bez `external_id` i IČA a jen při právě jedné shodě.
4. jinak se založí nový kontakt.

**E-mail NENÍ stabilní primární identita.** Jednu adresu legitimně sdílí
víc osob (domácnost, firma), osoba může adresu změnit a tatáž adresa se
může vyskytovat v různých velikostech písmen. Proto e-mail nemá unikátní
index, párování jím platí jen při právě jedné shodě a při více shodách
resolver vyhodí `AmbiguousCustomerMatch` — kontrolovaná ambiguita je
lepší než náhodné sloučení dvou osob.

#### Kanonizace e-mailu

Před hledáním i uložením se e-mail kanonizuje: **trim + lowercase**
(UTF-8 bezpečně, `mb_strtolower`). Porovnává se kanonicky na obou stranách
(`LOWER(email)`), takže `User@Example.com` a `user@example.com` jsou táž
hodnota i proti ručně založeným kontaktům. Nic víc se NEDĚLÁ — žádné
odstraňování gmailových teček, `+tagů` ani přepisy domén: takové úpravy by
slepily odlišné adresy.

#### Transakční kontrakt: resolve PŘED transakcí

**Email-only párování NESMÍ běžet uvnitř transakce, kterou resolver
neřídí.** Aplikační zámek `GET_LOCK` je vázaný na SESSION, ne na transakci:
uvnitř nadřazené `DB::transaction()` by se uvolnil ve chvíli, kdy resolver
skončí — tedy PŘED commitem volajícího. Druhý souběžný požadavek by zámek
získal, nově založený kontakt ale ještě neviděl a založil by druhý.
(Ověřeno proti MariaDB 10.4: `GET_LOCK` přežije COMMIT i ROLLBACK a z jiného
spojení ho uvolnit nelze.)

Resolver proto v takové situaci **fail closed** vyhodí
`CustomerResolutionNotTransactional`. Integrace má vyřešit zákazníka
a teprve pak otevřít transakci, která zakládá doklad:

```php
$customer = $resolver->resolve($payload['customer']);   // mimo transakci
DB::transaction(fn () => /* založení konceptu, položky, vystavení */);
```

Omezení platí JEN pro email-only větev. Silnější klíče (`external_id`, IČO)
zámek nepotřebují — kryje je unikátní index — a uvnitř transakce běžet smí.

Nedostupný zámek končí doménovou `CustomerLockUnavailable` (retry-able),
ne obecnou `RuntimeException`, aby ji budoucí API error handler odlišil od
interní chyby. Timeout je 10 s a je konfigurovatelný konstruktorem.

#### Souběh email-only požadavků

Dva souběžné požadavky bez `external_id` a IČO se stejným e-mailem dřív
mohly založit dva kontakty (lookup obou proběhl před insertem toho
druhého; unikátní index tu z principu není). Email-only větev proto drží
**tenant-scoped aplikační zámek** nad kanonickým e-mailem (MariaDB
`GET_LOCK`, název = hash databáze + organizace + e-mailu, timeout 10 s;
jmenný prostor zámků je serverový, ne per-database, proto je v hashi i jméno
databáze): lookup
a případné založení jsou serializované a druhý požadavek po získání zámku
najde kontakt založený prvním. Stejný e-mail v jiné organizaci se
neblokuje (zámek nese tenant). Nedostupnost zámku končí výjimkou, ne
duplicitou. Ověřeno deterministickým MariaDB testem
(`CustomerResolverConcurrencyTest`). Silnější klíče zámek nepotřebují —
kryjí je unikátní indexy `(organization_id, external_id)` /
`(organization_id, ico)` a ošetřený `UniqueConstraintViolationException`
retry. Zámek serializuje resolver vůči resolveru; souběh s RUČNÍM
založením kontaktu v UI pod ním nespadá (přiznané omezení — vyřeší ho
až případná kanonizace na úrovni celé aplikace).

Pořadí je STRIKTNÍ — slabší klíč nikdy nezachraňuje neúspěch silnějšího:

- Je-li dodán `external_id`, hledá se **výhradně** podle kombinace
  (organizace, external_id). Když nic nenajde, založí se nový kontakt.
  Párování se v tom případě NESMÍ „zachránit“ e-mailem ani IČEM — jinak by
  nová identita tiše splynula se starým kontaktem.
- IČO se použije jen tehdy, když `external_id` dodán není.
- E-mail se použije jen bez `external_id` i bez použitelného IČA.
- **Párování e-mailem platí jen při právě jedné shodě.** Při více shodách
  resolver vyhodí `AmbiguousCustomerMatch`; nikdy nevybere první řádek podle
  pořadí v databázi. Fakturu není přípustné přiřadit odhadem.
- Všechny dotazy jsou omezené aktuální organizací.

Další pravidla:

- **IČO NENÍ povinné ani párovací.** Libovolný počet zákazníků bez IČO je
  v pořádku (v unikátním indexu se NULL opakovat smí).
- **E-mail se nevynucuje jako unikátní** — jednu adresu může sdílet víc
  osob (domácnost, firma) a tvrdá unikátnost by legitimní objednávky rozbila.
- Zná-li se zákazník už jako dodavatel, povýší se na „odběratel i dodavatel“
  místo vzniku druhého záznamu. `external_id` se na kontakt nalezený slabším
  klíčem **nedopisuje**.
- Konflikt „nové `external_id` + IČO už patří jinému kontaktu“ končí
  `AmbiguousCustomerMatch` — sloučení identit je rozhodnutí pro člověka.

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

### Chování při chybě

Integrace musí počítat s tím, že server mutaci **odmítne**, nikoli tiše
uhodne. Kromě validačních chyb jsou to zejména:

| Situace | Výsledek |
|---|---|
| dvojznačný e-mail bez silnějšího klíče | `AmbiguousCustomerMatch` |
| nové `external_id` s obsazeným IČEM | `AmbiguousCustomerMatch` |
| email-only párování uvnitř cizí transakce | `CustomerResolutionNotTransactional` |
| zámek e-mailu nedostupný v limitu | `CustomerLockUnavailable` (opakovat) |
| reference konceptu patří jiné organizaci | `InvalidInvoiceReference` |
| příloha k uhrazené či zamítnuté faktuře | `InvalidStateTransition` |
| nastavené logo organizace na disku chybí | `LogoSnapshotFailed` |
| nulový nebo záporný součet faktury | `InvoiceNotIssuable` |
| částka mimo podporovaný rozsah | `MoneyOverflow` |
| faktura patří jiné organizaci | `InvoiceNotFound` (fail closed) |
| souběžný protichůdný přechod | `InvalidStateTransition` |

Mutace faktur jsou serializované zámkem na řádku, takže opakované doručení
webhooku ani souběžné požadavky nezpůsobí ztracenou platbu ani dvojí
spotřebování čísla (viz INVOICE_LIFECYCLE.md). `Idempotency-Key` zůstává
doporučený jako ochrana proti opakovanému vytvoření dokladu.

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
