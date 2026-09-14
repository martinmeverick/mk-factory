# Backlog (mimo rozsah MVP)

Seřazeno zhruba dle očekávané priority pro U Jabka a další projekty.

## Hotovo po sepsání backlogu

- **Načítání firem z ARESu** podle IČO vč. zakládání dodavatele bez
  předchozího kontaktu (docs/ARES_INTEGRATION.md).
- **external_id na kontaktech** + párování odběratelů pro napojené systémy.

## Vysoká priorita (další iterace)

1. **Veřejné REST API v1** dle INTEGRATION_CONTRACT.md (Sanctum tokeny,
   idempotence, external_id na fakturách). První konzument: automatické
   fakturování objednávek z U Jabka — převážně B2C bez IČO.
2. **Role a oprávnění** — rozlišit owner/member (mazání, nastavení, členové).
3. **Odesílání faktur e-mailem** (PDF příloha, šablona, log odeslání).
4. **Rate limiting + audit přihlášení** (throttle, poslední přihlášení).
5. **Číselné řady — UX** — automatické založení řady nového roku,
   upozornění na kolizi po ruční změně next_number.

## Střední priorita

6. **Upomínky** — ruční/automatické upomínky po splatnosti.
7. **Opakované (šablonové) faktury** — měsíční paušály.
8. **Dobropisy** (opravné daňové doklady) — záporné položky, vazba na
   původní fakturu.
9. **Zálohové faktury** a vyúčtování.
10. **Bankovní API + párování plateb** (Fio/ČSOB/RB API, párování dle VS
    a částky, návrh na částečné úhrady).
11. **OCR / vytěžování přijatých faktur** (ISDOC import jako první krok).
12. **Multi-měna** — sloupce currency existují, chybí kurzy a přepočty.
13. **Export pro účetní** (CSV/XLSX přehledy, případně Pohoda XML).

## Nízká priorita / výhled

14. Datová schránka, ISDOC export.
15. Webhooky pro integrace (viz INTEGRATION_CONTRACT.md).
16. Veřejný SaaS billing (registrace, platby za používání).
17. Mobilní aplikace / PWA.
18. Sklad a skladové položky.
19. Účetní deník, daňová evidence, přiznání DPH — **vědomě nikdy v MVP**;
    vyžaduje certifikaci a odborný dohled.
