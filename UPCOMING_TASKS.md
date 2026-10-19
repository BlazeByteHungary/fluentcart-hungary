# Upcoming tasks — FluentCart Hungary

**Frissítve:** 2026-10-19 · **Legutóbbi publikus kiadás:** 0.6.1 · **Éles használat:** nincs igazolva (`LIVE_VERIFIED = 0`)

Ez a dokumentum a projekt magas szintű, nyilvános státuszát és a következő feladatokat foglalja össze.
A részletes, bizonyítékokkal ellátott belső mátrix nem része a nyilvános repónak.

## Hol tartunk?

| Terület | Hol tartunk? |
|---|---|
| **Foxpost** | Valódi sandboxban igazolt checkout → COD rendelés → csomag → címke → tracking, valamint visszavonás. |
| **GLS** | Pontlista, widget és teszthitelesítés működik. A tényleges címkekészítés még igazolásra vár. |
| **Barion** | A gateway és refund kódja megvan, teszt POS konfigurálva. Valódi tesztfizetés/refund még kell. |
| **SimplePay** | Implementáció és publikus demó van; saját kereskedői fiókos ellenőrzés hiányzik. |
| **Számlázz.hu / Billingo** | A számla- és korrekciós folyamatok implementálva, jelenleg mockkal igazolva. |
| **MPL / DPD / Packeta** | Jelentős implementáció megvan; hozzáférések, egyes szerződések és valódi szolgáltatói próbák hiányoznak. |
| **Checkout** | Futárválasztás, szűrés, lapozás, snapshot és mentési védelem elkészült. |
| **Legacy migráció** | Terv van, működő importer még nincs. |

## Mit jelent a „kész” ebben a táblázatban?

- **Implementált / elkészült:** a kód létezik, és a rendelkezésre álló unit/mock tesztek zöldek.
- **Igazolt:** valódi provider-sandbox vagy élő környezet bizonyítéka is van. A Foxpost kivételével a
  legtöbb területen ez még hiányzik.
- **Éles (live):** valódi pénz, valódi bizonylat vagy valódi feladás. Jelenleg **egyik** providerre sincs
  `LIVE_VERIFIED` eredmény.

## Következő lépések

1. **Kiadási igazság:** a nyilvános repó utolsó kiadása 0.6.1; a fejlesztés 0.8.0 állapotban van.
   A következő lépés a 0.8.0 release-jelölt, release-notes és verdict tisztázása.
2. **Barion teszt E2E:** a teszt POS már konfigurálva van; egy valódi tesztfizetés + refund hiányzik.
3. **GLS címke:** a pontlista/widget/teszthitelesítés kész; a saját teszt parcel címke-létrehozása,
   pickup-kérése és COD-módosítása van hátra.
4. **Számlázó és további providerek:** Számlázz/Billingo, SimplePay, MPL, DPD és Packeta valódi
   fiókos/sandbox E2E-je a kereskedői hozzáférésekre és szerződésekre vár.
5. **Legacy cutover:** az importer és a dry-run még nem készült el; a régi rendelések átvétele külön
   adatintegritási feladat.

## Nem része ennek a dátumnak

- Éles (production) üzembe helyezés vagy éles provider-hívás.
- Újabb fejlesztési hullám indítása a fenti ellenőrzések lezárása előtt.
- Titkok, fiókazonosítók vagy ügyféladatok publikálása.
