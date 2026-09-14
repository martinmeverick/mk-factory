# Bezpečnostní poznámky

## Autentizace a autorizace

- Session-based přihlášení (Laravel Auth), bcrypt hesla, regenerace session
  po přihlášení, CSRF ochrana na všech formulářích (Blade `@csrf`).
- Registrace neexistuje — uživatele zakládá správce (seeder / tinker).
- Limit pokusů o přihlášení (`throttle:login`, definice v
  `AppServiceProvider`, regrese `tests/Feature/Security/LoginRateLimitTest.php`):
  - 5 POST pokusů/min na dvojici (normalizovaný e-mail, IP),
  - 30 POST pokusů/min na IP napříč e-maily.
  Počítá se každý POST, úspěšný i neúspěšný; GET stránka limit nemá.
  Po překročení HTTP 429 s `Retry-After` a obecnou českou hláškou — bez
  echa údajů, bez informace, zda účet existuje. Klíče jsou hashované
  a vždy obsahují IP: nikdo nemůže zamknout účet ostatním z jiné IP.
  IP je `REMOTE_ADDR` (žádná proxy není důvěryhodná, `X-Forwarded-For`
  se ignoruje). Stav limiteru žije ve výchozí cache, která v produkci
  musí být sdílená a trvalá (viz `docs/VPS_READINESS.md`).
- Aktivní organizace: `current_organization_id` v session. Middleware
  `SetCurrentOrganization` při KAŽDÉM požadavku ověřuje členství uživatele
  v organizaci; nečlenství → session klíč smazán, redirect na výběr.

## Izolace organizací (tenant isolation)

- Trait `BelongsToOrganization`: globální scope `organization_id = current`
  na všech obchodních modelech + auto-fill při create.
- Route-model binding díky scope vrací pro cizí záznamy 404 (záznam
  „neexistuje“) — žádné ID enumeration přes URL.
- **Pořadí middlewaru je bezpečnostně kritické.** `SetCurrentOrganization`
  musí běžet PŘED `SubstituteBindings`, jinak se binding resolvuje bez
  nastavené organizace, scope je no-op a načtou se i cizí záznamy (čtení
  cizích faktur vč. PDF, přepis cizích účtů a číselných řad, mazání cizích
  příloh). Zajišťuje `prependToPriorityList()` v `bootstrap/app.php`;
  regresi hlídá `tests/Feature/Security/AccessControlTest.php`. Při přidání
  dalšího middlewaru pracujícího s tenantem tuto prioritu ověřte.
- Formulářové `exists` validace cizích klíčů (contact_id, project_id,
  bank_account_id, number_series_id) jsou explicitně omezené na aktuální
  organizaci — nelze podstrčit cizí ID.
- Pozor pro vývojáře: scope se aplikuje jen s nastavenou CurrentOrganization.
  Konzolové příkazy a testy bez ní vidí vše — v HTTP kontextu je vždy
  nastavena middlewarem.

## Uploady (logo, přílohy přijatých faktur)

- Uložení na privátní disk `storage/app/private` — NIKDY do `public/`.
- Validace: whitelist typů (PDF/JPG/PNG — `mimes` validuje obsah, ne jen
  příponu), limit 10 MB (logo 2 MB).
- Soubor se ukládá pod náhodným hash názvem; originální název jen v DB
  a v `Content-Disposition` při stažení (Laravel jej sanitizuje).
- Download výhradně přes autorizovaný controller (auth + org scope).
- Přidání i smazání přílohy jde přes `ReceivedInvoiceLifecycle`
  (`attach()` / `deleteAttachment()`) pod zámkem rodičovské faktury —
  controller o finalitě dokladu nerozhoduje a tenant se ověřuje proti
  ULOŽENÉ vazbě přílohy, ne proti instanci volajícího. Soubor se maže až
  po commitu; osiřelý soubor bez odkazu z DB je přijatelný failure window,
  živý záznam bez souboru není.

## Neměnnost a integrita dokladů

- Vystavená faktura: chráněná pole hlídá model (`ImmutableInvoiceViolation`),
  přechody stavů jen přes lifecycle služby v transakcích, audit log
  (kdo, kdy, co) v `audit_logs`.
- Číslo faktury: přiděluje se v transakci se zámkem řádku řady
  (`SELECT … FOR UPDATE`); unikátní index `(organization_id, invoice_number)`
  jako pojistka proti souběhu.
- Storno nevrací číslo do řady (auditní stopa).

## PDF / QR

- dompdf: `isRemoteEnabled=false`, `isPhpEnabled=false` — šablona nemůže
  načítat vzdálené zdroje ani spouštět PHP; logo a QR jdou přes data URI.
- SPD payload: validace IBAN (mod-97), částky z celočíselných haléřů,
  sanitizace zprávy (ASCII, bez `*`).

## Známá omezení MVP (vědomá rozhodnutí)

- Role owner/member zatím nemají odlišná oprávnění (každý člen organizace
  může vše v rámci organizace). Zpřísnění rolí = budoucí task.
- Izolaci organizací drží JEDNA vrstva (globální scope + pořadí middlewaru).
  Laravel Policies jako druhá vrstva obrany zatím nejsou — doporučeno doplnit
  před nasazením pro více organizací s odlišnými vlastníky.
- Modely mají `$guarded = []`. Controllery plní jen validovaná pole, takže
  dnes nelze podstrčit `organization_id` ani `status`, ale jakýkoli budoucí
  `Model::create($request->all())` by to umožnil — při rozšiřování doplňte
  explicitní `$fillable`.
- Limit přihlášení je vázaný na IP. Distribuovaný útok z mnoha zdrojů
  (každý pod 5 pokusů/min na účet) jeden limit na IP nezachytí; na to by
  bylo potřeba zpomalení per účet (s rizikem zamykání cizích účtů),
  2FA nebo detekce na úrovni sítě. Limit je také jen tak trvalý jako
  produkční cache — s cache `array` by neplatil.
- Žádné 2FA, žádný audit přihlášení.
- `.env` v repozitáři není; `.env.example` obsahuje jen lokální dev výchozí
  hodnoty (root bez hesla — pouze lokální XAMPP).
- Produkce běží jako nginx → PHP-FPM na jednom stroji, bez další reverse
  proxy; secure/HttpOnly/SameSite cookie a další produkční parametry
  jsou v `docs/VPS_READINESS.md`. Pokud by se před aplikaci přidala proxy,
  je nutné explicitně nastavit `trustProxies` v `bootstrap/app.php`,
  jinak limit přihlášení uvidí IP proxy.
