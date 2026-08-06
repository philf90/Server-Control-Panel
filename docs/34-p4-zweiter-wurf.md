# P4, zweiter Wurf: DNS-01, Platzhalter, eigene Zertifikate

Der erste Wurf steht auf dem Zielserver und ist ausgeliefert (`v0.4.0-rc.5`):
ACME über HTTP-01 für Kundendomains, das Zertifikat der Oberfläche über
dieselbe Strecke, HTTPS und HSTS in der Kundenvorlage, Erneuerung am Zeitplan.

Dieser Text ist der Plan für den Rest — **er wird gelesen, bevor etwas gebaut
wird.** Er nennt drei Dinge: was der erste Wurf offengehalten hat und wo er es
*nicht* getan hat, die Reihenfolge der Schritte samt ihrer Wächter, und die
Entscheidungen, die der Betreiber trifft und nicht der Code.

---

## 1. Was dazukommt

Aus dem Plan §9 (P4) ist offen:

- **DNS-01**, gegen externe Anbieter über deren API — und gegen die eigene Zone,
  sobald es sie gibt (P7).
- **Platzhalter** (`*.example.de`). Sie sind der eigentliche Grund für DNS-01:
  Über HTTP-01 gibt es kein Wildcard, die Zertifizierungsstelle lässt es nicht
  zu.
- **Eigenes Zertifikat hochladen**, Kette prüfen, Ablauf anzeigen.

Nicht in diesem Wurf: `http2` und `ssl_stapling`. Die Gründe stehen im
Changelog und haben sich nicht geändert — die eigenständige `http2`-Direktive
gibt es erst ab nginx 1.25.1 und drei der vier Zielplattformen bringen die
ältere Ausgabe mit; OCSP hat Let's Encrypt eingestellt.

---

## 2. Was schon dasteht — und die drei Stellen, an denen es nicht reicht

**Der erste Wurf hat die drei Nähte gelegt, die `docs/32 §6` verlangt hat**, und
zwar tragend:

- `Challenge` ist eine Schnittstelle mit `type()`, `present()`, `ready()`,
  `cleanup()`; `Order::issue()` hat keine Fallunterscheidung. DNS-01 ist eine
  zweite Umsetzung und kein Umbau.
- `ready()` gibt es, obwohl HTTP-01 es nicht braucht — genau für den TXT-Eintrag,
  der noch nicht ausgeliefert wird.
- Ein Zertifikat ist ein eigener Gegenstand (`certificates`) mit einer
  Namensliste, an dem Domains hängen. `Certificate::covers()` versteht
  Platzhalter bereits richtig: genau eine Beschriftung, Punkt bleibt stehen,
  also deckt `*.example.de` nicht `a.b.example.de` und nicht `notexample.de`.
  `CertificateSource::Uploaded` gibt es, `certificates.names` ist JSON. **Für
  Platzhalter und für das Hochladen braucht es keine Migration.**

**Drei Stellen halten der Erweiterung trotzdem nicht stand.** Alle drei sind
beim Schreiben dieses Plans im Quelltext nachgesehen, nicht vermutet.

### 2.1 Der Agent leitet aus dem Domainnamen ab, welches Zertifikat gilt

`SiteTemplate::render()` fragt `Store::existing($site->domain)` — der Pfad
entsteht aus dem Namen der Domain. Für ein Zertifikat, das genau diese eine
Domain deckt, stimmt das. Ein Platzhalter liegt nicht unter
`www.example.de`, und ein hochgeladenes Zertifikat für drei Domains liegt
höchstens unter einer davon.

Damit gäbe es **zwei Wahrheiten zu derselben Frage**: `domains.certificate_id`
in der Datenbank und der Verzeichnisname auf dem Server. Das ist wörtlich das
Muster, an dem dieses Projekt sechsmal verloren hat.

**Die Antwort:** Die Anwendung entscheidet, welches Zertifikat ein Block
ausliefert — sie weiss es, die Spalte gibt es. `Site` bekommt einen
Zertifikatsnamen (den Schlüssel im `Store`, keinen Pfad), `Store` baut daraus
wie bisher den Pfad, und findet der Agent nichts, bleibt der Block auf Port 80
wie heute. Kein Pfad aus der Anwendung, keine zweite Ableitung im Agenten.

### 2.2 Ein Platzhalter hat keinen Ablageort

`Store::directory()` normalisiert über `DomainName::normalize()`, und die weist
`*` ab — die Beschriftung muss `[a-z0-9]` sein. `Store::write('*.example.de', …)`
scheitert also heute mit „ungültiger Name", und zwar erst im Agenten, nach der
erfolgreichen Bestellung. Ein Zertifikat, das ausgestellt ist und sich nicht
ablegen lässt, ist ein verbrauchter Eintrag in der Wochengrenze.

**Die Antwort:** eine eigene Regel für den Ablageort, nicht ein aufgeweichtes
`DomainName::normalize()`. Ein Stern im Dateisystem ist ein Muster für jede
Shell und für `find`, und dieser Pfad landet in einer nginx-Datei. Vorschlag:
`_wildcard.example.de` — eindeutig, ohne Sonderzeichen, und die führende
Unterstrich-Beschriftung kann kein Domainname sein, kollidiert also mit keinem.

### 2.3 `AcmeCertificate` weist Platzhalter ab, und `MAX_NAMES` ist fünf

Beides ist richtig gesetzt und beides muss mit: Die Schranke fällt genau dann,
wenn die Bestellung über DNS-01 läuft — über HTTP-01 bleibt sie, weil die
Zertifizierungsstelle dort mit einer Meldung antwortet, die den Grund nicht
nennt. Fünf Namen bleiben für den Blockfall; ein Platzhalter deckt ohnehin
mit zwei Namen (`example.de` und `*.example.de`) eine ganze Zone.

---

## 3. Platzhalter — was sie lösen und was sie kosten

**Sie lösen die Wochengrenze.** Let's Encrypt zählt Ausstellungen je
registrierbarer Domain und Woche. Ein Abonnement mit vierzig Unterdomains
verbraucht heute vierzig Einträge; mit einem Platzhalter sind es zwei, und die
Erneuerung ist eine Bestellung statt vierzig. Für einen Server, der Kunden
aufnimmt, ist das der Unterschied zwischen „läuft" und „ab Donnerstag keine
neuen Domains mehr".

**Sie kosten die Trennschärfe.** Ein Platzhalter deckt jede Unterdomain der
Zone — auch eine, die einem *anderen* Abonnement gehört. Der Schlüssel liegt
root-eigen auf demselben Server, technisch ist damit nichts gewonnen oder
verloren; die Aussage nach aussen ändert sich aber: Der Block des einen Kunden
weist ein Zertifikat vor, das die Namen des anderen mit deckt.

**Die Regel dazu, und sie gehört durchgesetzt und nicht nur aufgeschrieben:**
Ein Platzhalter gehört dem Abonnement, dem die Basisdomain gehört, und nur
dessen Domains dürfen ihn ausliefern. Das ist dieselbe Klammer wie überall —
`certificates.subscription_id` gibt es, `Tenancy` verweigert im Grundzustand.
Der Wächter prüft die Zuordnung an der Stelle, an der sie zählt: beim Schreiben
des Server-Blocks.

**Und eine Grenze, die ACME selbst zieht:** `*.example.de` deckt keine zweite
Ebene. Wer `a.b.example.de` betreibt, braucht `*.b.example.de` dazu — das ist
kein Fehler des Panels, aber es ist eine Auskunft, die die Oberfläche geben
muss, statt eine Warnung im Browser entstehen zu lassen.

---

## 4. DNS-01: was zu bauen ist

**Der Ablauf steht.** Neu sind drei Teile:

1. **`DnsChallenge implements Challenge`.** `present()` legt
   `_acme-challenge.<domain>` als TXT-Eintrag mit dem Base64url-SHA256 der
   `keyAuthorization` an; `cleanup()` nimmt ihn zurück — auch nach einem
   Fehlschlag, ein liegengebliebener Eintrag ist eine Aussage, die niemand mehr
   zurücknimmt.
2. **`ready()` fragt die autoritativen Nameserver, nicht den Anbieter.** Die API
   sagt „ok", sobald sie den Eintrag entgegengenommen hat; ausgeliefert wird er
   Sekunden bis Minuten später. Wer der Prüfstelle zu früh sagt „prüf jetzt",
   verbrennt einen der fünf Fehlversuche je Konto und Stunde. Gefragt wird
   deshalb per DNS bei den `NS` der Zone selbst, mit Abbruch nach einer festen
   Frist — und die Frist gehört ins Protokoll, weil sie der Grund ist, aus dem
   eine Bestellung „lange nichts tut".
3. **`DnsProvider`** — eine Schnittstelle mit `add()`, `remove()` und einer
   Auflösung der Zone zu einem Namen (`example.de` aus `a.b.example.de`, ohne
   Raterei: die Anbieter-API kennt ihre Zonen, also wird sie gefragt). Die
   Anbieter stehen auf einer **Positivliste mit festen Schlüsseln**, genau wie
   `Directories` — die Anwendung nennt einen Schlüssel, nie eine Adresse.

**Ein `FakeProvider` gehört dazu und ist keine Zugabe.** Sonst prüft nichts von
alledem ohne fremden Dienst, und ein Test, der eine fremde API braucht, wird
beim dritten roten Lauf abgeschaltet.

---

## 5. Wo die Zugangsdaten liegen — die eine Entscheidung mit Folgen

Ein DNS-API-Token darf Einträge in einer Zone schreiben. **Das ist ein grösseres
Geheimnis als das Panel-Passwort:** Wer es hat, kann sich für die Domain jedes
Zertifikat der Welt ausstellen lassen, auch anderswo.

Der Agent macht den API-Aufruf — er fährt die Bestellung. Das Token muss ihn
also erreichen. Zwei Wege:

**a) Der Panel-Weg.** Token verschlüsselt in `Setting` (`encrypted:array`, wie
beim Mailversand), und jede Bestellung trägt es über den Socket mit.
Einfach — aber das Geheimnis liegt dann in der Datenbank *und* geht bei jedem
Vorgang über die Grenze, und jeder Protokolleintrag, jede Ausnahme und jede
Fehlermeldung ist ab sofort eine Stelle, an der es auftauchen kann.

**b) Der Agent-Weg (Empfehlung).** Das Token liegt in
`/etc/srvpanel/dns/<profil>.json`, 0600 root — dort, wo `panel.env` schon liegt,
und aus demselben Grund. Die Anwendung kennt nur den **Profilnamen** und
schickt nur ihn; das Token überquert den Socket **genau einmal**, beim
Einrichten, über eine eigene Operation (`dns.credential.store`), und keine
Leseoperation gibt es je zurück. Die Oberfläche zeigt „hinterlegt" und die
letzten vier Zeichen, nie mehr.

Das ist dieselbe Entscheidung wie in P0 bei Datenbankpasswort und `APP_KEY`:
Geheimnisse entstehen oder wohnen im Agenten, und die Anwendung nennt sie beim
Namen. Der Preis ist eine Operation mehr und ein Ablageort mehr; der Gewinn
ist, dass ein Datenbankauszug keine Zonen öffnet.

**Offen, und der Betreiber entscheidet:** ob die Zugangsdaten am Betreiber
hängen (ein Profil je Anbieter, für alle Kunden) oder am Abonnement (jeder
Kunde hinterlegt sein eigenes). Der Ablageort trägt beides — der Profilname
kann `betrieb` oder `abo-1042` heissen. Die Frage ist nicht technisch, sondern
eine des Geschäftsmodells: Wer die Zone des Kunden verwaltet, braucht kein
Kundentoken; wer sie nicht verwaltet, kann kein Wildcard anbieten, ohne eines
zu verlangen.

---

## 6. Die Anbieter — Vorschlag zur Reihenfolge

Jeder Anbieter ist eine eigene Umsetzung, eigene Fehlerfälle und ein eigener
Wächter. Deshalb nicht fünf auf einmal:

| Anbieter | Warum | Wann |
|---|---|---|
| **RFC 2136** (TSIG) | Der Standard, kein Anbietercode — er bedient BIND, Knot und PowerDNS, und **damit die eigene Zone aus P7** ohne zweite Umsetzung. | zuerst |
| **Hetzner DNS** | Einfaches Token, im deutschen Markt sehr verbreitet. | zuerst |
| **Cloudflare** | Der häufigste Fall überhaupt, feingranulare Token je Zone. | danach |
| **INWX**, **Netcup** | Deutsche Registrare mit API; lohnen sich, wenn Kunden sie mitbringen. | nach Bedarf |
| **deSEC** | Kostenlos und schnell — als **Ziel für die Abnahme**, nicht als Angebot. | mit dem ersten |

Die Empfehlung ist, mit **RFC 2136 und einem Token-Anbieter** zu beginnen: Der
eine ist die Brücke zu P7, der andere deckt echte Kunden ab. Die Liste ist eine
Positivliste im Code — eine dritte Zertifizierungsstelle oder ein vierter
Anbieter ist eine Änderung, die jemand liest, kein Feld in einem Formular.

---

## 7. Eigenes Zertifikat hochladen

Das ist der kleinste Teil und der mit den meisten Abweisungsfällen. Datenmodell
und Ablauf stehen schon (`CertificateSource::Uploaded`, `Store`,
`domains.certificate_id`); zu bauen ist die Prüfung, und sie gehört **in den
Agenten**, weil dort openssl sitzt und weil ein privater Schlüssel nicht durch
die Anwendung wandern sollte, um dann wieder hinauszugehen.

Geprüft wird, bevor irgendetwas abgelegt wird:

- Kette und Schlüssel gehören zusammen (öffentlicher Schlüssel aus beiden,
  verglichen — nicht die Modulus-Ausgabe als Text).
- Die Kette ist eine Kette: Blatt zuerst, jedes folgende Zertifikat
  unterschreibt das vorige. Eine falsch sortierte Kette ist der Fehler, den
  Browser unterschiedlich verzeihen und Mobilgeräte gar nicht.
- Nicht abgelaufen und nicht erst später gültig.
- Die Namen decken die Domain, für die es hinterlegt wird — sonst warnt der
  Browser, und im Panel sieht alles grün aus. Dieselbe Angabe wie `covers_all`
  auf der Domainseite.
- Ein hochgeladenes Zertifikat wird **nicht erneuert**. `CertificateRenewal`
  lässt es liegen und die Seite sagt, dass hier niemand erneuert — mit dem
  Datum, ab dem es zu spät ist.

Der Schlüssel überquert den Socket einmal, wie das DNS-Token, und steht in
keinem Protokoll.

---

## 8. Die Schritte, in dieser Reihenfolge

Jeder Schritt ist für sich abnehmbar, und jeder bringt seinen Wächter samt
Bruch in `tests/waechter-brechen.sh` mit.

1. **Die Zuordnung umdrehen** (§2.1). Die Anwendung nennt das Zertifikat, der
   Agent leitet es nicht mehr ab. *Wächter:* Kein Server-Block liefert ein
   Zertifikat aus, das die Anwendung ihm nicht zugewiesen hat — und keines, das
   einem fremden Abonnement gehört.
2. **Ablageort für Platzhalter** (§2.2). *Wächter:* Der abgeleitete Pfad enthält
   nie einen Stern, und zwei verschiedene Namen ergeben nie dasselbe
   Verzeichnis.
3. **`DnsChallenge` samt `ready()`** gegen `FakeProvider`. *Wächter:* Es wird
   nicht geprüft, bevor die autoritativen Nameserver den Eintrag ausliefern;
   `cleanup()` läuft auch nach einem Fehlschlag.
4. **Zugangsdaten** (§5). *Wächter:* Kein Token verlässt den Agenten — keine
   Leseoperation gibt es zurück, kein Protokolleintrag enthält es.
5. **Der erste echte Anbieter** und die Abnahme gegen deSEC.
6. **Bestellen mit Platzhalter in der Oberfläche.** Wer darf, was wird bestellt
   (`example.de` **und** `*.example.de`), und was die Seite sagt, wenn eine
   zweite Ebene ungedeckt bleibt.
7. **Hochladen** (§7).
8. **Weitere Anbieter**, einer nach dem anderen.

---

## 9. Das Abnahmekriterium

Gemessen auf einem echten Server, nicht geschätzt (Plan §8):

- Ein Abonnement mit einer Domain und mindestens drei Unterdomains bekommt **ein**
  Zertifikat mit `example.de` und `*.example.de`, ausgestellt über DNS-01 gegen
  einen echten Anbieter.
- Alle Server-Blöcke dieses Abonnements liefern es aus; `covers_all` steht auf
  der Domainseite jeder einzelnen auf „ja"; kein Browser warnt.
- Eine Unterdomain eines **anderen** Abonnements in derselben Zone bekommt es
  **nicht** — sie behält ihr eigenes.
- Die Erneuerung läuft über denselben Zeitplan und ist **eine** Bestellung.
- Ein hochgeladenes Zertifikat mit falsch sortierter Kette wird abgewiesen, und
  die Meldung sagt, was falsch ist.
- Das DNS-Token steht in keinem Vorgang, in keinem Protokoll und in keiner
  Antwort der Oberfläche.

---

## 10. Was der Betreiber entscheidet, bevor gebaut wird

1. **Welche Anbieter, und in welcher Reihenfolge?** Vorschlag in §6.
2. **Zugangsdaten am Betreiber oder am Abonnement?** (§5) — die Frage mit den
   grössten Folgen für die Oberfläche.
3. **Darf ein Kunde selbst einen Platzhalter bestellen**, oder ist das eine
   Handlung des Betreibers? Ein Platzhalter deckt die ganze Zone (§3).
4. **Darf ein Kunde ein eigenes Zertifikat hochladen?** Technisch geprüft ist es
   in beiden Fällen; die Frage ist, wer den Schlüssel auf den Server legen darf.
