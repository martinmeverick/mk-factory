# Napojení na ARES a zakládání kontaktů

ARES (Administrativní registr ekonomických subjektů, MF ČR) slouží
k předvyplnění údajů firem podle IČO. Veřejné REST API, bez registrace
a bez klíče.

## Základní zásada

**ARES je pomůcka, ne závislost.** Výpadek registru nesmí zablokovat
zaevidování faktury. Proto:

- na už známé IČO se ARESu vůbec neptáme (nejdřív lokální hledání),
- při nedostupnosti stačí vyplnit název dodavatele ručně,
- předvyplnění v prohlížeči je jen komfort — dohledání provádí server
  při odeslání formuláře, takže tok funguje i bez JavaScriptu.

## Vrstvy

```
App\Domain\Ares
  AresClient        rozhraní: findByIco(), searchByName()
  HttpAresClient    volání REST API (timeout, User-Agent)
  CachedAresClient  dekorátor s cache (vázán v AppServiceProvider)
  AresSubject       readonly DTO — bez znalosti Eloquentu i HTTP
  AresUnavailable   výpadek registru / vypnutá integrace

App\Domain\Contacts
  CzechIco          normalizace na 8 číslic + kontrola mod 11
  SupplierResolver  find-or-create dodavatele podle IČO
  CustomerResolver  find-or-create odběratele pro napojené systémy (B2C)
```

Klient rozlišuje dva různé stavy, které se nesmí plést:

| Situace | Chování |
|---|---|
| subjekt neexistuje (HTTP 404/400) | vrací `null` — běžný výsledek |
| registr nedostupný (timeout, 5xx) | vyhodí `AresUnavailable` |

## Použité endpointy

| Účel | Volání |
|---|---|
| detail podle IČO | `GET /ekonomicke-subjekty/{ico}` |
| hledání podle názvu | `POST /ekonomicke-subjekty/vyhledat` |

Mapování odpovědi (ověřeno proti živému registru):

| Pole ARESu | Uloží se jako |
|---|---|
| `obchodniJmeno` | název kontaktu |
| `dic` | DIČ (u neplátců `null`) |
| `seznamRegistraci.stavZdrojeDph` | příznak plátce DPH (`AKTIVNI`) |
| `sidlo.nazevUlice` + `cisloDomovni` (+`/cisloOrientacni`) | ulice |
| `sidlo.cisloDomovni` bez ulice | `č.p. 602` / `č.ev. 12` dle `typCisloDomovni` |
| `sidlo.nazevObce` | město |
| `sidlo.psc` (číslo 46362) | PSČ `463 62` |
| `sidlo.kodStatu` | země |

ARES tedy vrací i **plátcovství DPH**. Co v něm NENÍ: příznak
nespolehlivého plátce a zveřejněné bankovní účty — pro ty je potřeba
registr plátců DPH (MFČR/ADIS) nebo VIES. To je zatím mimo rozsah.

## Ochrana veřejné služby

- **Cache** (`CachedAresClient`): nálezy 24 h, „nenalezeno“ 15 minut.
  Registr se mění zřídka, cache zásadně sráží počet dotazů.
- **Throttle** na našem endpointu (`throttle:30,1`), aby uživatel nemohl
  přes aplikaci registr zahltit a nechat zablokovat IP serveru.
- **Předfiltr kontrolní číslicí**: nevalidní IČO se ven vůbec neodešle.
- Timeouty (5 s odpověď, 3 s spojení) a `User-Agent` s názvem aplikace.

Konfigurace je v `config/services.php` pod klíčem `ares`; integraci lze
vypnout `ARES_ENABLED=false` (klient pak hlásí `AresUnavailable` a formuláře
fungují ručně).

## Zakládání dodavatele podle IČO

Formulář přijaté faktury má dva režimy (`supplier_mode`):

1. `existing` — výběr ze seznamu kontaktů,
2. `ico` — zadá se IČO; dodavatel se dohledá nebo založí při uložení.

`SupplierResolver::resolveByIco()`:

1. normalizuje IČO (`177041` → `00177041`),
2. hledá kontakt v aktuální organizaci — pokud existuje, ARES se nevolá,
3. kontakt vedený jen jako odběratel povýší na „odběratel i dodavatel“
   (nevzniká druhý záznam téže firmy),
4. jinak načte údaje z ARESu a založí kontakt typu dodavatel,
5. při souběhu dvou uložení spoléhá na unikátní index a záznam dohledá.

Ručně zadaný název má přednost před registrovým.

## Unikátnost a B2C

Migrace přidávají na `contacts`:

- `unique(organization_id, ico)` — brání duplicitám téže firmy,
- `external_id` + `unique(organization_id, external_id)` — klíč integrací,
- index `(organization_id, email)` — párování objednávek bez external_id.

**Klíčové pro U Jabka:** koncovým zákazníkem bývá fyzická osoba **bez IČO**.
NULL se v unikátním indexu opakovat smí, takže libovolný počet zákazníků
bez IČO je v pořádku a IČO nikdy nesmí být párovacím klíčem odběratele —
tím je `external_id` (viz INTEGRATION_CONTRACT.md).

Kontrola kontrolní číslice IČO se používá jen jako brána před dotazem do
ARESu, **ne** jako tvrdá validace ručně zadaného IČO u kontaktů — jinak by
zpětně znehodnotila už uložené záznamy (demo data mají vymyšlená IČO).

## Testy

- `tests/Unit/CzechIcoTest.php` — normalizace a kontrolní číslice.
- `tests/Unit/AresSubjectTest.php` — mapování odpovědi vč. adres bez ulice.
- `tests/Feature/Ares/AresClientTest.php` — 404/400/5xx/timeout, cache.
- `tests/Feature/Contacts/SupplierResolverTest.php` — find-or-create, izolace
  organizací, výpadek registru.
- `tests/Feature/Contacts/CustomerResolverTest.php` — B2C bez IČO.
- `tests/Feature/Ares/SupplierByIcoHttpTest.php` — celý tok přes formulář.

`Tests\TestCase` volá `Http::preventStrayRequests()` — žádný test nesmí
sáhnout na skutečnou síť.
