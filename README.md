# FluentCart Hungary

Moduláris WordPress-plugin a FluentCart magyarországi e-kereskedelmi integrációihoz: online fizetés,
magyar számlázók, futárszolgálatok és csomagpontválasztás — közös technikai alapon, egy pluginként.

**Szerző:** UNBND · **Legutóbbi publikus kiadás:** 0.6.1 · **Állapot:** `READY_FOR_SANDBOX` (`LIVE_VERIFIED = 0`)

A fejlesztés jelenlegi, 2026-10-19-i állapotát és a következő feladatokat a
[UPCOMING_TASKS.md](UPCOMING_TASKS.md) foglalja össze.

*English summary: modular Hungarian payment (Barion, SimplePay), invoicing (Számlázz, Billingo),
shipping (MPL, GLS, DPD, Packeta, Foxpost) and pickup-point integrations for FluentCart. Developed
by UNBND. Sandbox-ready; live provider verification is still pending (no live credentials).*

## Mi ez, kinek való

A FluentCart Hungary egyetlen, moduláris plugin azoknak a magyar webáruházaknak, amelyek FluentCart
alatt futnak, és magyar fizetési, számlázási és szállítási szolgáltatókat akarnak használni. Natív
FluentCart hookokat és modelleket használ; a FluentCart Core/Pro forrását nem módosítja, és egy
provider modul hibája vagy kikapcsolása nem állítja le a többit.

## Funkciók (kivonat)

- **Fizetés**: Barion és SimplePay checkout-gateway; fizetési kísérletek/azonosítók, visszatérési és
  callback/státusz-feldolgozás, egyeztetés; teljes és részletes refund-kezelés (refund-egyenleg,
  bizonytalan kimenetel kezelése). Natív FC payment/refund binding, szerveres végállapot-kezelés,
  replay/dedup.
- **Számlázás**: Számlázz.hu/Számla Agent és Billingo; számla/díjbekérő/draft, partner-/adóadat-,
  státusz- és PDF-kezelés; egy rendelési eseményre a konfigurált egyetlen számlázó; sztornó/
  helyesbítő dokumentumlánc az eredeti bizonylat kötésével; privát bizonylattár; refundhoz kötött
  számlakorrekciós policy; COD számlázási időzítés.
- **Szállítás és logisztika**: MPL, GLS, DPD, Packeta és Foxpost címkekészítés, privát címletár,
  tracking/státusz, utánvét; natív shipping method → carrier/service binding; rendeléskori kötés
  megőrzése és carrier-routing legacy esetekre; queue/recovery; provider szerint támogatott
  shipment-cancel és Packeta claim.
- **Checkout és csomagpontok**: natív FC díjakkal megjelenő home-futár ajánlatok; közös
  csomagpontválasztó lista carrier/ponttípus szűrővel, kereséssel, szerveroldali lapozással;
  cross-carrier választás a helyes natív ajánlattal (díj előre látható); címtár-verzió és
  freshness policy (last-good, stale/expired, backoff, ütemezett refresh); hivatalos Foxpost
  apt-finder widget és listás fallback; Packeta/GLS adapter a hozzáférési korlátokkal; szerveresen
  hitelesített, egységes snapshot a draftban és a rendelésen.
- **Admin és üzemeltetés**: „Magyar integrációk” workspace (Áttekintés, Beállítások, Számlázás,
  Szállítás, Egyeztetés, Rendelések); modulkapcsolók, provider mód/account, maszkos credential-kezelés;
  külső műveleti ledger auditált állapotokkal; Action Scheduler alapú queue fallback-kel;
  integer pénzkezelés; capability/nonce védelem és privát artifact-tár.

A részletes, funkciónkénti státusz és bizonyítékszint a fejlesztői mátrixban él; a publikusan
elérhető, igazolt rész a fenti kivonat.

## Telepítés

**Követelmények (minimum):** WordPress 6.5+, PHP 8.1+, FluentCart Core 1.7.0+.
**Tesztelt kombináció:** WordPress 7.1.2 + FluentCart 1.7.0 + PHP 8.2/8.3 (izolált lab).

1. Töltsd le a legfrissebb **plugin-ZIP-et** a GitHub **Releases** oldalról
   (`fluentcart-hungary-<verzió>.zip`) és a hozzá tartozó `.sha256` ellenőrző fájlt.
2. WordPress admin → *Bővítmények → Új hozzáadása → Bővítmény feltöltése*, majd aktiválás.
   (Vagy `wp plugin install <zip-url> --activate`.)
3. Győződj meg róla, hogy a FluentCart telepítve és aktív — a plugin a `plugins_loaded` után bootol,
   és FluentCart nélkül nem tölti be a provider modulokat.

> **Fontos:** a GitHub „Source code (zip)” automatikus csomagja **nem** telepíthető WordPress
> plugin-ZIP. Mindig a Releases alatti `fluentcart-hungary-*.zip` fájlt használd; a release-hez
> tartozó `.sha256` a letöltés épségét ellenőrzi.

## Első beállítás

1. FluentCart → **Magyar integrációk → Áttekintés**: kapcsold be a szükséges modulokat (fizetés,
   számlázó, futárok).
2. **Beállítások**: provider mód (test/live), account/API adatok megadása (maszkolt tárolás).
3. **Számlázás**: válaszd ki az egyetlen aktív számlázót és a számlázási politikát (pl. COD
   `before_dispatch`).
4. **Szállítás**: minden szállítási módszernél állítsd be a futárt/szolgáltatást (home/pickup/locker)
   és csomagpontnál a ponttípusokat; a csomagpont-könyvtár diagnosztika és a kézi frissítés ugyanitt
   érhető el.
5. **Rendelések**: a rendelés panelen látod a rögzített futár-döntést és a csomagpont-snapshotot;
   hiányzó kötésnél kézi futár-hozzárendelés lehetséges.

## Fejlesztés

A repó a plugin forrása (PHP, WordPress-plugin). Build lépés nincs; a kiadási ZIP a tagelt forrásból
készül.

- **Unit tesztek** (külső függőség nélkül): `php tests/unit/run.php` a repó gyökeréből.
- **Integrációs tesztek**: izolált WordPress + FluentCart lab szükséges (a `tests/integration/`
  harness a `FCH_LAB` környezeti változóval állítható a saját labra; alapérték egy helyi lab-útvonal).
  A próbák hálózat és éles provider nélkül futnak.
- **Fejlesztői követelmény:** PHP 8.1+ CLI a unit tesztekhez.

## Fejlesztési állapot és következő feladatok

A területenkénti aktuális státuszt, a „kész” jelentését és a következő lépéseket a
[UPCOMING_TASKS.md](UPCOMING_TASKS.md) tartalmazza. A nyilvános repó utolsó kiadása 0.6.1; a
fejlesztői állapot ennél előrébb tart, de éles provider-igazolás továbbra sincs.

## Ismert korlátok

- `LIVE_VERIFIED = 0`: éles provider fiókok/credentials hiányában a fizetés/számlázás/feladás éles
  útja nincs igazolva; a sandbox/lab és mock bizonyítékok ettől függetlenül zöldek.
- Packeta widget élő kiválasztás merchant apiKey-hez kötött (kulcs nélkül a driver letiltva).
- GLS widget csak dokumentált asset-konfigurációval aktív; éles account hiányában nem igazolt.
- DPD PUDO végpont nem dokumentált — nincs kitalált feed.
- Modal/embedded checkout ebben a kiadásban nem támogatott.
- A csomagpont-adatállapot (pl. aktuális Foxpost/MPL pontszám) pillanatkép, nem garancia.

## Licenc és kapcsolat

- **Licenc:** GPL-3.0-or-later (lásd `LICENSE`).
- **Szerző:** UNBND.
- **Hibabejelentés / támogatás:** GitHub Issues ebben a repóban.

*A FluentCart Hungary független add-on; a FluentCart és a felsorolt szolgáltatók saját védjegyeik
tulajdonosai.*
