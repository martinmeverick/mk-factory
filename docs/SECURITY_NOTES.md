# Bezpečnostní poznámky

## Autentizace a autorizace

- Session-based přihlášení (Laravel Auth), bcrypt hesla, regenerace session
  po přihlášení, CSRF ochrana na všech formulářích (Blade `@csrf`).
- Registrace neexistuje — uživatele zakládá správce (seeder / tinker).
- Aktivní organizace: `current_organization_id` v session. Middleware
  `SetCurrentOrganization` při KAŽDÉM požadavku ověřuje členství uživatele
  v organizaci; nečlenství → session klíč smazán, redirect na výběr.

## Izolace organizací (tenant isolation)

- Trait `BelongsToOrganization`: globální scope `organization_id = current`
  na všech obchodních modelech + auto-fill při create.
- Route-model binding díky scope vrací pro cizí záznamy 404 (záznam
  „neexistuje“) — žádné ID enumeration přes URL.
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
- Chybí rate limiting na login (Laravel throttle middleware — doplnit před
  veřejným nasazením).
- Žádné 2FA, žádný audit přihlášení.
- `.env` v repozitáři není; `.env.example` obsahuje jen lokální dev výchozí
  hodnoty (root bez hesla — pouze lokální XAMPP).
- Aplikace se předpokládá za HTTPS reverse proxy v produkci (secure cookie
  nastavit při nasazení).
