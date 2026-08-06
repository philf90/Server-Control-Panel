# P4, zweiter Wurf: DNS-01, Platzhalter, eigene Zertifikate

Der erste Wurf steht auf dem Zielserver und ist ausgeliefert (`v0.4.0-rc.5`):
ACME über HTTP-01 für Kundendomains, das Zertifikat der Oberfläche über
dieselbe Strecke, HTTPS und HSTS in der Kundenvorlage, Erneuerung am Zeitplan.

Dieser Text ist der Plan für den Rest — **er wird gelesen, bevor etwas gebaut
wird.** Er nennt drei Dinge: was der erste Wurf offengehalten hat und wo er es
*nicht* getan hat, die Reihenfolge der Schritte samt ihrer Wächter, und die
Entscheidungen des Betreibers. Die vier, die vor dem Bauen zu treffen waren,
sind getroffen und stehen in §11.

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

**Entschieden am 6. August 2026: Der Kunde darf selbst bestellen.** Eine Domain
liegt in aller Regel bei einem Kunden, und wer sie hält, hält auch die Zone —
ihn dafür beim Betreiber anfragen zu lassen, wäre eine Warteschleife ohne
Gewinn. **Damit wird die Regel oben tragend statt vorsorglich:** Solange nur
der Betreiber bestellte, war die Zuordnung eine Sorgfaltsfrage; jetzt ist sie
die Grenze zwischen zwei Kunden. Bestellt werden darf ein Platzhalter deshalb
nur zu einer Basisdomain, die dem eigenen Abonnement gehört — geprüft an der
Domain und nicht am eingetippten Namen, sonst bestellt jemand `*.fremde.de`,
und die Bestellung scheitert erst an der Zertifizierungsstelle, mit einem
verbrauchten Fehlversuch für alle.

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

### Am Betreiber oder am Abonnement? Beides, und die Antwort steht schon da

**Entschieden: am Abonnement — aber nicht als Entweder-oder, sondern über den
Plan.** Die Unterscheidung gibt es in diesem Panel bereits, ausformuliert und
von einer Policy durchgesetzt: `Feature::DnsEdit`, mit dem Hinweistext *„Ohne
diese Freigabe verwaltet der Betreiber die Zone; das Abonnement sieht sie
nur."* Genau diese Frage ist es.

Daraus folgt ohne neue Begriffe:

- **Plan mit `DnsEdit`:** Das Abonnement hinterlegt sein eigenes Profil
  (`abo-1042`) und bestellt damit für seine Zonen. Der Kunde verwaltet die Zone
  ohnehin — er hält den Schlüssel dazu bereits in den Händen.
- **Plan ohne `DnsEdit`:** Es gilt das Profil des Betreibers (`betrieb`). Der
  Kunde sieht, dass über DNS-01 bestellt wird, und hinterlegt nichts; das Token
  gehört dem, der die Zone führt.
- **Beides vorhanden:** Das Abonnement gewinnt für seine eigenen Zonen. Sonst
  entschiede die Reihenfolge im Code darüber, wessen Token benutzt wird — und
  das ist keine Entscheidung, die im Code stehen darf.

Der Ablageort trägt das ohne Änderung: Der Profilname ist ein Schlüssel, kein
Pfad, und `betrieb` und `abo-1042` sind derselbe Mechanismus. Was dazukommt,
ist eine **Auflösung** — welches Profil gilt für diese Domain? — und die gehört
in **eine** Klasse, in der Anwendung, neben `AcmeSettings`. Zwei Stellen, die
diese Frage beantworten, geben irgendwann zwei Antworten.

**Der Wächter dazu:** Ein Abonnement kommt nie an ein fremdes Profil, auch
nicht über einen selbst gewählten Profilnamen. Der Name wird nicht
entgegengenommen, sondern aus dem Abonnement abgeleitet — dieselbe Haltung wie
bei den Verzeichnisnamen der Systembenutzer.

---

## 6. Die Anbieter

Entschieden am 6. August 2026. Jeder Anbieter ist eine eigene Umsetzung, eigene
Fehlerfälle und ein eigener Wächter — deshalb einer nach dem anderen und nicht
fünf auf einmal:

| Anbieter | Warum | Wann |
|---|---|---|
| **RFC 2136** (TSIG) | Der Standard, kein Anbietercode — er bedient BIND, Knot und PowerDNS, und **damit die eigene Zone aus P7** ohne zweite Umsetzung. | 1. |
| **Hetzner DNS** | Einfaches Token, im deutschen Markt sehr verbreitet. | 2. |
| **Cloudflare** | Der häufigste Fall überhaupt, Token je Zone einschränkbar. | 3. |
| **Netcup** | Deutscher Registrar mit API, viele Kunden bringen ihn mit. | 4. |
| **IPv64.net** | Kleiner deutscher Anbieter mit Token-API; deckt den Fall ab, in dem jemand seine Zone dort und nicht beim Registrar führt. | 5. |

### IPv64.net im Einzelnen

Nachgesehen am 6. August 2026. **Die Seiten von IPv64.net selbst waren aus
diesem Container nicht abrufbar (HTTP 403);** was hier steht, stammt aus zwei
unabhängigen, im Einsatz befindlichen ACME-Umsetzungen —
[`dns_ipv64.sh` aus acme.sh](https://github.com/acmesh-official/acme.sh/blob/master/dnsapi/dns_ipv64.sh)
und dem
[IPv64-Anbieter aus lego](https://pkg.go.dev/github.com/go-acme/lego/v5/providers/dns/ipv64).
Beide nennen dieselben Felder. Beim ersten Zugriff wird das gegen die
Dokumentation des Anbieters gehalten, nicht blind übernommen.

- **Adresse:** `https://ipv64.net/api`
- **Anmeldung:** `Authorization: Bearer <Token>`
- **Anlegen:** `POST`, `application/x-www-form-urlencoded`, Felder
  `add_record=<zone>`, `praefix=<name>`, `type=TXT`, `content=<wert>`
- **Löschen:** dasselbe mit `DELETE` und `del_record=<zone>`
- **Zonen:** `get_domains` liefert die Zonen des Kontos
- Erfolg erkennt acme.sh an `"info":"success"`, und es wartet bei **HTTP 429**
  zehn Sekunden — der Anbieter drosselt also.

**Ein Fund, der die Bauart bestätigt.** lego zerlegt den Namen und verlangt
mindestens drei Bestandteile: Bei IPv64.net ist die Zone häufig selbst eine
Unterdomain (`meinname.ipv64.net`), also **nicht** die registrierbare Domain.
Wer die Zone aus dem Namen errechnet, liegt hier falsch. Deshalb steht in §4,
dass die Zone **abgefragt** und nicht abgeleitet wird — `get_domains` ist genau
dafür da, und acme.sh macht es ebenso. Was als Vorsicht in den Plan geschrieben
war, ist bei diesem Anbieter der Normalfall.

Der Feldname `praefix` ist deutsch geschrieben, weil der Anbieter ihn so nennt.
Das ist eine echte Schnittstelle nach aussen — `docs/19 §4a` lässt sie, wie sie
ist; übersetzt wird sie nicht.

Die Liste ist eine Positivliste im Code. Ein sechster Anbieter ist eine
Änderung, die jemand liest, kein Feld in einem Formular — dieselbe Haltung wie
bei den Zertifizierungsstellen in `Directories`.

**Die Abnahme läuft gegen eine Zone, die der Betreiber selbst führt.** Ein
fremder Dienst im Abnahmelauf ist ein Lauf, der irgendwann aus einem Grund rot
wird, der nichts mit diesem Panel zu tun hat. Für alles davor gibt es den
`FakeProvider`.

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

### Der Kunde darf hochladen — und das ist keine neue Entscheidung

`Feature::CertificateUpload` gibt es, mit Beschriftung („Eigene Zertifikate
hochladen"), Kurzform („Zertifikate") und Hinweis („Ohne diese Freigabe bleibt
nur das automatisch ausgestellte Zertifikat"), und sie hängt an
`Permission::Certificates`. Die Pläne tragen die Freigabe also längst; was
fehlt, ist die Funktion dahinter. **Zu bauen ist nichts am Rechtemodell** — zu
bauen ist die Aktion, die dieselbe Freigabe fragt, die sie später abweist, und
ihre `can`-Ablage im Payload (`AbilityReachTest` prüft beide Richtungen).

Was ein Kunde hochladen darf, verschärft die Prüfung aus dem Absatz davor an
zwei Stellen:

- **Die Abweisung muss den Grund nennen.** Ein Betreiber mit einer falsch
  sortierten Kette liest das Protokoll; ein Kunde liest die Meldung auf der
  Seite und sonst nichts. „Ungültig" ist dort keine Auskunft.
- **Grösse und Anzahl sind begrenzt.** Eine Datei, die als root abgelegt wird,
  ist ein Schreibrecht auf dem Server — nur PEM, nur eine feste Obergrenze, und
  nur für Domains des eigenen Abonnements.

### Was das Hochladen an bereits Gebautem berührt

Drei Stellen, und alle drei sind dieselbe Frage: **Wer entscheidet, welches
Zertifikat gilt?**

1. **Die automatische Bestellung darf ein hochgeladenes nicht überholen.**
   Heute bestellt der Lebenslauf, sobald ein Server-Block steht und die Domain
   kein Zertifikat hat. Mit dem Hochladen gibt es einen zweiten Weg, zu einem
   Zertifikat zu kommen — bestellt wird deshalb nur noch, wenn die Domain
   **kein zugewiesenes Zertifikat hat, das ihre Namen deckt**. Das ist
   ohnehin die richtige Bedingung: Sie ist auch die für den Platzhalter.
2. **Die Erneuerung lässt es liegen.** `CertificateRenewal` erneuert, was von
   ACME kommt. Ein `source = uploaded` gehört übersprungen — aber sichtbar, mit
   Datum: Ein Zertifikat, das niemand erneuert und das stillschweigend abläuft,
   nimmt eine Website vom Netz, und das an einem Tag, an dem niemand etwas
   geändert hat.
3. **Der Server-Block liefert aus, was zugewiesen ist** — §2.1, derselbe
   Schritt. Er ist damit nicht nur die Voraussetzung für Platzhalter, sondern
   auch für das Hochladen.

**Die Reihenfolge der Schritte ändert sich dadurch nicht**, sie wird nur
besser begründet: Schritt 1 trägt beide Funktionen.

---

## 8. Mehr als ein Zertifikat — die Auswahl

**Ja, das geht, und die Auswahl gibt es sogar schon:** `domains.certificate_id`
*ist* sie. Was heute eine Zuweisung ist, die nur der Lebenslauf schreibt, wird
mit Schritt 1 die Stelle, an der die Frage „welches liefert diese Domain aus?"
einmal und für alle beantwortet wird. Ein Auswahlfeld ist damit kein neuer
Mechanismus, sondern eine Oberfläche auf einen, der dann dasteht.

**Und die Lage ist nicht selten, sondern der Regelfall.** Sobald es das
Hochladen gibt und Platzhalter dazukommen, hat eine Domain schnell drei
Kandidaten: das bestellte für ihren Namen, den Platzhalter des Abonnements und
ein hochgeladenes. Ohne Auswahl entschiede die Reihenfolge im Code — und das
ist keine Entscheidung, die im Code stehen darf.

**Kandidat ist, was deckt.** Angeboten wird, was dem Abonnement gehört, gültig
ist und **alle** Namen des Server-Blocks deckt. Die Prüfung dafür gibt es:
`Certificate::coversAll($domain->serverNames())`. Was nur den halben Block
deckt, steht nicht zur Wahl — es erzeugte eine Warnung im Browser, die im Panel
grün aussieht.

**Eine Auswahl muss von einer Zuweisung unterscheidbar sein.** Sonst nimmt die
nächste automatische Bestellung sie zurück, und zwar still — genau der
Fehlertyp, der in diesem Projekt am teuersten war. Dafür braucht es ein
Kennzeichen an der Domain (`certificate_pinned_at`, ein Zeitstempel, der
`null` ist, solange niemand gewählt hat). Es ist die einzige Migration dieses
Wurfs.

Daraus folgen drei Regeln:

1. **Ohne Wahl entscheidet die Automatik** wie bisher — sie bestellt, wenn
   nichts Deckendes da ist, und weist zu.
2. **Mit Wahl fasst die Automatik die Zuweisung nicht an.** Sie darf weiter
   *erneuern*, was sie erneuern kann; sie darf nicht umhängen.
3. **Eine Wahl lässt sich zurücknehmen** („wieder automatisch"), und das steht
   als Knopf da. Eine Einstellung, die man nur einmal treffen kann, ist eine
   Falle.

**Offen, und ich empfehle es so:** Was passiert, wenn das gewählte Zertifikat
abläuft? Ein hochgeladenes erneuert niemand. Stur daran festzuhalten nähme die
Website vom Netz; still auf ein anderes zu wechseln wäre die zweite Wahrheit.
Mein Vorschlag ist der laute Rückfall: **Ist die Wahl abgelaufen und liegt ein
gültiges, deckendes Zertifikat vor, liefert der Block dieses aus** — die Wahl
bleibt aber vermerkt, die Domainseite sagt in einer Meldung, dass sie
übergangen wird und warum, und der Vorgang steht im Protokoll. Vorgewarnt wird
davor 30 Tage lang, mit demselben Zeitplan, der auch erneuert. Das ist die
Entscheidung, die beim Bauen von Schritt 3 zu treffen ist.

---

## 9. Die Schritte, in dieser Reihenfolge

Jeder Schritt ist für sich abnehmbar, und jeder bringt seinen Wächter samt
Bruch in `tests/waechter-brechen.sh` mit.

1. **Die Zuordnung umdrehen** (§2.1). Die Anwendung nennt das Zertifikat, der
   Agent leitet es nicht mehr ab; bestellt wird nur noch, wenn kein
   zugewiesenes Zertifikat die Namen der Domain deckt. *Wächter:* Kein
   Server-Block liefert ein Zertifikat aus, das die Anwendung ihm nicht
   zugewiesen hat — und keines, das einem fremden Abonnement gehört.
   **Dieser Schritt trägt Platzhalter und Hochladen gleichermassen** und hängt
   an keiner Anbieterwahl.
2. **Ablageort für Platzhalter** (§2.2). *Wächter:* Der abgeleitete Pfad enthält
   nie einen Stern, und zwei verschiedene Namen ergeben nie dasselbe
   Verzeichnis.
3. **Hochladen** (§7) — es braucht keinen Anbieter und keine Challenge und ist
   nach Schritt 1 die kürzeste Strecke zu etwas, das ein Kunde benutzen kann.
   *Wächter:* Jeder Abweisungsfall einzeln (Schlüssel passt nicht, Kette falsch
   sortiert, abgelaufen, deckt die Domain nicht), die Freigabe aus dem Plan, und
   die Erneuerung, die ein hochgeladenes liegen lässt, ohne es zu verschweigen.
4. **Die Auswahl** (§8) — samt `certificate_pinned_at`, der einzigen Migration
   dieses Wurfs. Sie kommt hier und nicht später: Ab Schritt 3 gibt es zwei
   Zertifikate für dieselbe Domain, und ohne das Kennzeichen nimmt die nächste
   Bestellung die Wahl still zurück. *Wächter:* Eine gewählte Zuweisung
   überlebt einen Lauf des Zeitplans und ein erneutes Schreiben des
   Server-Blocks; angeboten wird nur, was alle Namen deckt und dem Abonnement
   gehört.
5. **`DnsChallenge` samt `ready()`** gegen `FakeProvider`. *Wächter:* Es wird
   nicht geprüft, bevor die autoritativen Nameserver den Eintrag ausliefern;
   `cleanup()` läuft auch nach einem Fehlschlag.
6. **Zugangsdaten und die Auflösung des Profils** (§5). *Wächter:* Kein Token
   verlässt den Agenten — keine Leseoperation gibt es zurück, kein
   Protokolleintrag enthält es; und ein Abonnement kommt nie an ein fremdes
   Profil.
7. **RFC 2136** als erster echter Anbieter, dazu die Abnahme gegen eine eigene
   Zone.
8. **Bestellen mit Platzhalter in der Oberfläche.** Wer darf (§3), was bestellt
   wird (`example.de` **und** `*.example.de`), und was die Seite sagt, wenn eine
   zweite Ebene ungedeckt bleibt.
9. **Hetzner, Cloudflare, Netcup, IPv64.net** — einer nach dem anderen, jeder
   mit seinem Wächter und seinen Fehlerfällen. IPv64.net bringt dabei den Fall
   mit, an dem sich die Zonenauflösung beweist: Die Zone ist dort oft selbst
   eine Unterdomain (§6).

---

## 10. Das Abnahmekriterium

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
- Ein hochgeladenes, gültiges Zertifikat bleibt liegen: Die automatische
  Bestellung überholt es nicht, und die Erneuerung fasst es nicht an — die
  Domainseite nennt aber das Datum, ab dem es zu spät ist.
- Das DNS-Token steht in keinem Vorgang, in keinem Protokoll und in keiner
  Antwort der Oberfläche.

---

## 11. Die Entscheidungen des Betreibers

Getroffen am 6. August 2026, alle vier:

1. **Anbieter und Reihenfolge:** RFC 2136, Hetzner, Cloudflare, Netcup,
   IPv64.net (§6).
2. **Zugangsdaten am Abonnement** — über `Feature::DnsEdit` aus dem Plan, mit
   dem Profil des Betreibers als Grundfall (§5).
3. **Der Kunde darf einen Platzhalter bestellen** — für Basisdomains, die ihm
   gehören (§3).
4. **Der Kunde darf ein eigenes Zertifikat hochladen** — über
   `Feature::CertificateUpload`, die es im Plan bereits gibt (§7).

Dazu kam am selben Tag die fünfte, aus der vierten heraus: **Sind mehrere
Zertifikate da, wird gewählt** (§8) — die Auswahl ist `domains.certificate_id`,
und sie bekommt mit `certificate_pinned_at` ein Kennzeichen, damit die
Automatik sie nicht still zurücknimmt.

**Was daraus offen bleibt und beim Bauen beantwortet wird**, nicht vorher:

- Der Rückfall, wenn die Wahl abläuft (§8). Vorschlag steht dort: laut
  zurückfallen statt die Website vom Netz zu nehmen.
- Der Endpunktsatz von IPv64.net (§6) — beim ersten Zugriff gegen die
  Dokumentation des Anbieters zu halten.
- Die Frist, nach der `ready()` aufgibt, und wie oft dazwischen gefragt wird.
  Sie hängt an dem, was die Anbieter tatsächlich tun, und gehört gemessen statt
  geraten.
- Die Obergrenze für eine hochgeladene Datei (§7).
