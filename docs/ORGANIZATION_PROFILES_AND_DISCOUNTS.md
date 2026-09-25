# Fakturační profily a sleva z dokladu

V nabídce **Změnit organizaci → Přidat fakturační profil** může vlastník
existující organizace vytvořit další profil. Interní název (např. U Jabka)
slouží pro přepínač, zatímco právní název se používá na fakturách. Dva profily
mohou mít stejné IČO. Při založení je nutný odlišný prefix číselné řady pro
profily stejného vystavovatele. V nastavení se následně doplní adresa,
bankovní účet a případně logo. Nový profil automaticky patří zakládajícímu
vlastníkovi; cizí profily se nezpřístupňují.

Profily oddělují kontakty, faktury, číslování a nastavení DPH. U samostatného
podnikatele lze vybrat neplátce, u firemního profilu plátce. Existující
organizace se nepřepisují ani nekopírují. Vystavené doklady dál používají
zmrazené údaje dodavatele.

## Sleva

Koncept vydané faktury nabízí **Bez slevy / Procenta / Částka v Kč**.
Sleva se vztahuje na konečnou cenu celého dokladu včetně případné DPH.
Částka 2 000 Kč sníží celkovou cenu přesně o 2 000 Kč. Hodnota nesmí být
záporná, procento nesmí překročit 100 a pevná sleva cenu dokladu.
Fakturu s nulovou konečnou cenou nadále nelze vystavit.

Rozdělení mezi položky je výhradně interní výpočet v haléřích, poměrně
podle původní konečné ceny; zbytkové haléře se přidělí deterministicky podle
největšího zbytku. Žádný mezivýpočet nepoužívá float. U slevy se DPH určí
z nové konečné ceny každé položky. Bez slevy zůstává původní výpočet včetně
jeho zaokrouhlení. Sleva se nenabízí nad zápornými položkami.

V maržovém režimu zůstává pořizovací cena stejná; snížení prodejní ceny
snižuje kladnou přirážku a její interní DPH. Interní částky se netisknou.
PDF a detail ukazují původní ceny položek, slevu za doklad a výsledek;
rekapitulace DPH vychází z částek po slevě. QR a úhrady používají výsledný
zůstatek. Po vystavení nelze změnit ani slevu, ani její rozdělení.

## Nasazení

Dvě nové aditivní migrace přidávají interní název profilu a pole slev.
Stávající doklady dostanou nulovou slevu, bez přepočtu jejich částek.
Produkční upgrade vyžaduje dosavadní koordinovanou zálohu a provozní zámky,
ověření stávajícího MK releasu a aktualizaci identity v zálohovacích pinech.
Lokální testy nad SQLite nejsou důkazem produkční migrace na MariaDB.

Automatický návrat starého kódu po používání slev není bezpečný: starý kód
nezná pravidla editace konceptů se slevou. Při selhání zachovat údržbu,
diagnostikovat stav a použít kontrolovaný návrat kódu/dat pouze před obnovením
provozu nebo schválenou opravu vpřed. Nové sloupce se nesmí automaticky mazat.
