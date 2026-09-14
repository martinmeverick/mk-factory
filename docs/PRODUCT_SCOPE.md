# Rozsah produktu — MVP foundation

MK Factory je samostatný, modulární fakturační systém (menší obdoba základních
funkcí iDokladu). První nasazení: projekt **U Jabka**; postupně mk-systems,
Simona Vojtěšková, MEX, Cashflow a další interní/klientské projekty. Proto:

- samostatná aplikace, NE modul jiného projektu,
- všechna obchodní data vázaná na organizaci (multi-tenant),
- doménová logika oddělená od HTML vrstvy → připraveno pro budoucí REST API
  (viz INTEGRATION_CONTRACT.md).

## V rozsahu MVP

- Uživatelé, organizace, členství, nastavení organizace (vč. loga, plátcovství
  DPH, výchozí splatnosti, číselné řady, patičky).
- Kontakty (odběratel / dodavatel / obojí).
- Bankovní účty (s výpočtem CZ IBAN).
- Projekty s externím identifikátorem pro budoucí integrace.
- Vydané faktury: koncept → vystavení (číselná řada, VS, snapshoty) → úhrady
  → storno; režim plátce i neplátce DPH; položky s DPH sazbami 21/12/0.
- Ručně vystavená faktura ve **zvláštním režimu - použité zboží (§ 90
  ZDPH)** pro plátce DPH v CZK (první případ: použité telefony, interní sazba
  21 %): uživatel zadá konečnou prodejní cenu a interní pořizovací cenu,
  aplikace vede DPH z přirážky interně a na doklad tiskne povinný text bez
  vyčíslení DPH. Jen tento jeden zvláštní režim, bez daňového přiznání,
  automatického vystavování či napojení na e-shop.
- PDF faktury (dompdf, DejaVu Sans) s logem a QR Platbou (SPD 1.0).
- Přijaté faktury: evidence, přílohy (PDF/JPG/PNG), schválení / zamítnutí /
  úhrada.
- Dashboard: po splatnosti (vydané i přijaté), nezaplacené vydané, měsíční
  součty.
- Audit log přechodů stavů.
- Demo seed s fiktivními daty (U Jabka Demo).

## Vědomě MIMO rozsah (viz FUTURE_BACKLOG.md)

Bankovní API a párování plateb, OCR, datová schránka, e-mailové odesílání,
upomínky, opakované faktury, dobropisy, zálohové faktury, sklad, účetní
deník, daňová přiznání, exporty pro účetní SW, ISDOC, veřejný SaaS billing,
mobilní aplikace, plné veřejné REST API.

## Právní ohrada

Aplikace NENÍ certifikovaný účetní ani daňový software. Negeneruje daňovou
evidenci ani přiznání. Za formální správnost dokladů odpovídá uživatel;
před ostrým použitím doporučena kontrola účetní/daňovým poradcem.

Zvláštní režim - použité zboží: aplikace neposuzuje, zda konkrétní nákup
a prodej podmínky § 90 ZDPH splňuje (to je rozhodnutí a odpovědnost
uživatele, který režim na faktuře explicitně volí). Interní hodnoty přirážky
a DPH z přirážky jsou podklad pro účetní, nikoli daňové přiznání.
