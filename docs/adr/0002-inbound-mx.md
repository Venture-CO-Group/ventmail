# ADR 0002 — Inbound MX-fogadás és route-motor (A4)

**Állapot:** elfogadva · 2026-09-05
**Kontextus:** A4 packet, 30–38. elfogadási kritérium

## Kontextus

Az A4 MX-fogadást ír elő: a tenant domainjének MX-rekordja hozzánk mutat, a beérkező levél
átmegy a spam/vírus-szűrésen, majd a route-motoron. A specifikáció a fogadót nyitva hagyta:
„Postal inbound vagy Haraka/Postfix ⚠️".

Közben az A2-ben megírtuk a SAJÁT SMTP-implementációnkat: egy I/O-mentes protokoll-állapotgép
(`SmtpSession`) és egy natív stream-alapú szerver (`StreamSubmissionServer`), időkorlátokkal,
per-IP kerettel, tarpittal, PROXY protokoll-támogatással és négy független nyílt-relay zárral.
Ez a kód ma a SUBMISSION oldalt (587/465) szolgálja ki.

## 1. döntés — nem hozunk be harmadik SMTP-implementációt, de KÜLÖN session-osztályt igen

Az MX-fogadó a MI kódunk: ugyanaz a transzport (`StreamSubmissionServer`), ugyanazok a
protokoll-primitívek (`SmtpReply`, `SessionState`, `SmtpEnvelope`) — de SAJÁT session-osztály
(`MxSession`), saját invariánssal.

**Miért nem Postal inbound / Haraka:** két idegen SMTP-felület két külön támadási felület, két
külön konfigurációs hibaforrás, és két külön hely, ahol a nyílt relay tiltását bizonyítani
kell. A golden rule 3 nem arról szól, hogy az EGYIK felületünk nem nyílt relay.

**Miért nem „mode" a meglévő `SmtpSession`-ön:** a submission-session kulcs-invariánsa az,
hogy a sink hívása HITELESÍTÉS NÉLKÜL MEG SEM FOGALMAZHATÓ — a `SubmissionSink::accept()`
első paramétere nem-nullable `SubmissionPrincipal`, és ezt reflection-teszt őrzi (12.
kritérium). Egy `mx` mód ehhez nullable principalt vagy második sink-utat követelne, vagyis
pont azt a típus-szintű zárat gyengítené, ami a nyílt relay ellen a legerősebb védelmünk.

A két oldal invariánsa KÜLÖNBÖZŐ, tehát külön is kell bizonyítani:
- **submission (587/465):** „nincs `MAIL FROM` principal nélkül";
- **MX (25):** „nincs elfogadás olyan címzettre, aki nem a MI verifikált inbound-domainünk" —
  és `AUTH` egyáltalán nem létezik ezen a porton.

A Postal marad a KIMENŐ MTA. A `SendingDriver`-határ változatlan.

## 2. döntés — a MIME-parse NEM a fogadó folyamatban fut

A fogadó a nyers bájtokat leteszi (Wasabi + `inbound_messages` sor), és ott a munkája véget
ér. A parse külön, SANDBOXOLT job (36. kritérium: időkorlát, rekurzió-mélység, malformált
MIME nem dönti el a workert).

**Miért:** a bejövő MIME ISMERETLEN EREDETŰ, és a parser-sérülékenységek osztálya (mélységi
rekurzió, zip-bomba, kvadratikus visszalépés) pont az a fajta hiba, ami egy hosszan élő
SMTP-fogadóban az ÖSSZES párhuzamos kapcsolatot elviszi. A fogadó dolga a bájtok átvétele —
ha a parse elhasal, az egy üzenet hibája, nem a szolgáltatásé.

## 3. döntés — a `forward(email)` a PreSendGate-en megy

A továbbítás KIMENŐ küldés, tehát ugyanaz a kapu érvényes rá, mint bármi másra (golden
rule 2). Nincs „belső" út a gate mellett.

**Következmény:** a továbbított levél feladója MI vagyunk (SRS-szerű átírás), nem az eredeti
feladó — különben a mi IP-nkről menne ki egy idegen domain nevében írt levél, és az SPF
azonnal megbukna.

## 4. döntés — a route-motor determinisztikus és megállítható

Prioritás szerint rendezett szabálylista; az első `stop()` lezárja a láncot. A szabályok
KIFEJEZÉSEI ügyfél által megadott reguláris kifejezések — ezért a motor időkorláttal és
hossz-limittel futtatja őket (ReDoS: az A1-ben már megégettük magunkat egy saját regexen).

## Eldöntendő (tulajdonosi döntés kell — a packet ezek NÉLKÜL is szállítható)

1. **A `store()` retenciója.** A spec 3 napot javasol ⚠️. Fix, vagy planenként állítható
   (ventPay `log_retention_tier`)? *Amíg nincs döntés: 3 nap, konfigból.*
2. **Spam-szűrő.** rspamd (gyorsabb, jobban skálázódik, +1 üzemeltetendő szolgáltatás) vagy
   SpamAssassin? *Amíg nincs döntés: interfész mögött (`SpamScanner`), fake implementációval;
   a valódi bekötés az A6 kézbesíthetőségi packetbe fér.*
3. **Csatolmány-méret plafon.** A planből jön, vagy legyen fölötte fix platform-limit is?
   *Amíg nincs döntés: fix 25 MB platform-plafon, alatta plan-limit.*
