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

> **Erledigt in Schritt 2.** Die Regel steht in `CertificateName::normalize()`
> und wird von `Store` und `Site` gemeinsam benutzt; sie ist mehrfach
> anwendbar, weil derselbe Schlüssel zweimal durchgeht — beim Ablegen und wenn
> die Anwendung ihn später wieder nennt.
>
> **Dabei ist ein zweiter Zusammenstoss aufgefallen, und er gehört zu Schritt
> 3.** Der Schlüssel entsteht aus dem *ersten Namen*. Zwei verschiedene
> Zertifikate mit demselben ersten Namen ergeben damit denselben Ablageort —
> ein hochgeladenes für `example.de` und ein bestelltes für `example.de`
> überschrieben einander. Solange jede Domain genau ein Zertifikat hat und die
> Erneuerung es an Ort und Stelle ersetzt, ist das richtig so; sobald man
> zwischen zweien wählen kann (§8), ist es das nicht mehr. Die Antwort gehört
> in den Schritt, der das Hochladen bringt, und nicht hierher — sie ist eine
> Frage der Quelle und nicht des Sterns.

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

> **Erledigt in Schritt 5 — die Strecke, noch ohne Anbieter.** `DnsChallenge`
> steht, `ready()` fragt über `Resolver` die autoritativen Nameserver, und das
> Drahtformat liegt als reine Umformung in `Packet` — damit prüfbar ohne Netz,
> ohne Zone und ohne Wartezeit.
>
> **Ein eigener Auflöser und kein `dig`.** Das Programm gehörte auf die
> Positivliste des Agenten und als Abhängigkeit ins Paket, für eine Frage, die
> in hundert Zeilen beantwortet ist.
>
> **Zwei Entscheidungen, die beim Bauen dazukamen.** `cleanup()` bekommt vom
> Ablauf nur Domain und Token, aber der Anbieter muss den *Wert* kennen: Zwei
> Bestellungen für dieselbe Zone laufen sonst einander ins Handwerk, weil die
> eine den `_acme-challenge`-Eintrag der anderen mit abräumt. Die Challenge
> merkt sich deshalb, was sie hingelegt hat. Und `add()` legt an, statt zu
> ersetzen — `example.de` und `*.example.de` in einer Bestellung ergeben zwei
> Werte unter demselben Namen, und beide müssen dastehen.
>
> Der `DnsProvider` selbst hat noch keine Umsetzung; die kommt mit den
> Zugangsdaten (Schritt 6) und RFC 2136 (Schritt 7). Bis dahin steht die
> Schnittstelle und ein Doppel im Testverzeichnis.
>
> **Nachtrag zu Schritt 7:** Sie steht jetzt — `Acme\Dns\Rfc2136`, mit
> `Tsig` für die Unterschrift, `UpdateMessage` für das Drahtformat und
> `Exchange` als Naht zum Netz. Die vier übrigen Anbieter stehen in
> `Providers::PENDING` und werden beim Hinterlegen abgewiesen, damit kein
> Geheimnis auf der Platte liegt, das nichts benutzen kann.

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

> **Erledigt in Schritt 6 — der Agent und die Kommandozeile.** `Credentials`
> legt je Profil eine Datei unter `/etc/srvpanel/dns` ab, 0600 root im
> 0700-Verzeichnis; drei Operationen (`dns.credential.store`, `.list`,
> `.forget`) sind der einzige Weg dorthin, und keine davon gibt ein Token
> zurück — auch nicht die letzten vier Zeichen. `DnsProfile` leitet den Namen
> ab: `abo-<nummer>` mit `dns_edit`, sonst `betrieb`.
>
> **Kein stiller Rückfall auf das Profil des Betreibers.** Ein Abonnement mit
> Freigabe, das noch nichts hinterlegt hat, bekommt nicht ersatzweise dessen
> Token: Das wäre ein Zugriff auf eine fremde Zone mit einem Schlüssel, der sie
> womöglich gar nicht öffnet, und die Fehlermeldung dazu käme vom Anbieter
> statt von hier.
>
> **`Providers` führt die Schlüssel und sonst nichts** (fünf beim Schreiben
> dieses Absatzes, acht seit der Erweiterung vom 7. August). Eine Fabrikmethode,
> die für jeden von ihnen eine Ausnahme wirft, wäre genau die Sorte
> Zeichenkette, die auf nichts zeigt; sie kommt mit der ersten Umsetzung in
> Schritt 7. Die Oberfläche zu den Zugangsdaten steht als 6b aus.

---

## 6. Die Anbieter

Entschieden am 6. August 2026, **erweitert am 7. August auf sieben**. Jeder
Anbieter ist eine eigene Umsetzung, eigene Fehlerfälle und ein eigener Wächter
— deshalb einer nach dem anderen und nicht sieben auf einmal:

| Anbieter | Warum | Wann |
|---|---|---|
| **RFC 2136** (TSIG) | Der Standard, kein Anbietercode — er bedient BIND, Knot und PowerDNS, und **damit die eigene Zone aus P7** ohne zweite Umsetzung. | gebaut |
| **IPv64.net** | Kleiner deutscher Anbieter mit Token-API; deckt den Fall ab, in dem jemand seine Zone dort und nicht beim Registrar führt. **Vorgezogen**, weil sich an ihm die Zonenauflösung beweist (unten). | gebaut |
| **Hetzner DNS** | Einfaches Token, im deutschen Markt sehr verbreitet. | gebaut |
| **Cloudflare** | Der häufigste Fall überhaupt, Token je Zone einschränkbar. | gebaut |
| **netcup** | Deutscher Registrar mit API, viele Kunden bringen ihn mit. | gebaut |
| **IONOS** | Der grösste deutsche Massenhoster; wer dort seine Domain hat, hat sie meist auch dort delegiert. | gebaut |
| **INWX** | Der Registrar, den deutsche Wiederverkäufer benutzen — und der einzige der sieben mit einer Anmeldung statt eines Tokens. | gebaut |
| **deSEC** | Gemeinnütziger deutscher DNS-Betreiber, DNSSEC ab Werk, kostenfrei. Er ist der **Ausweg für alle, deren Anbieter keine API hat** — die Zone zieht um, die Domain bleibt. | gebaut |

### Der zweite Beschluss: sieben statt vier

Vor dem Bauen des zweiten Anbieters wurde die Liste noch einmal gegen die
vollständige gehalten: **222 Anbieter unter `providers/dns/` in lego**,
durchgesehen am 7. August 2026. Aus dem deutschsprachigen Markt kamen drei
dazu, und jeder aus einem eigenen Grund:

- **IONOS** schliesst die grösste Lücke der ursprünglichen vier. Ein Token,
  eine REST-Schnittstelle — von der Bauart her der einfachste der drei.
- **INWX** ist der teuerste und steht deshalb hinten. Er ist der einzige mit
  Benutzername und Passwort statt eines Tokens, er spricht **XML-RPC** statt
  JSON, er führt eine Sitzung über ein Cookie, und wer sein Konto mit einem
  zweiten Faktor gesichert hat, muss das gemeinsame Geheimnis mit hinterlegen —
  der Agent rechnet dann bei jeder Anmeldung einen TOTP-Code aus. Das ist ein
  Passwort in den Zugangsdaten, das ein ganzes Registrarkonto öffnet, und nicht
  nur eine Zone. Es gehört gesagt, bevor jemand es einträgt.
- **deSEC** ist weniger ein Anbieter als eine Antwort. Wessen Anbieter keine
  API hat, kann die Zone dorthin delegieren, ohne die Domain umzuziehen — und
  bekommt Platzhalter, die es sonst für ihn nicht gäbe.

Nicht aufgenommen und ausdrücklich verworfen: die grossen Wolkenanbieter (Route
53, Azure, Google Cloud DNS) — sie haben eine eigene Art von Zugangsdaten mit
eigener Signatur, und wer sie benutzt, betreibt kein Panel dieser Grösse.

### Die Zugangsdaten je Anbieter — nachgesehen, nicht angenommen

Aus lego am 7. August 2026, aus den `*.toml`-Beschreibungen und dem Code
daneben. Was der Betreiber einträgt, steht links; rechts steht, was daran beim
Bauen teuer wird:

| Anbieter | Adresse | Felder | Was daran teuer wird |
|---|---|---|---|
| IPv64.net | `https://ipv64.net/api` | `token` | Die Zone ist oft selbst eine Unterdomain — **gefragt, nicht gerechnet**. Drosselt mit 429. |
| Hetzner | `https://api.hetzner.cloud/v1` (gebaut) — daneben die alte `https://dns.hetzner.com` | `token` | **Zwei Schnittstellen nebeneinander.** lego hält beide vor (`HETZNER_API_KEY` für die alte DNS-Konsole, `HETZNER_API_TOKEN` für die Cloud-API). Ein Token der einen gilt bei der anderen nicht, und die Abweisung sagt das nicht deutlich. |
| Cloudflare | `https://api.cloudflare.com/client/v4` (gebaut) | `token`; `email` + `api_key` wird **abgewiesen** | Der alte Weg über den globalen Schlüssel öffnet **das ganze Konto**. Genommen wird nur das Token, mit `Zone:Read` und `DNS:Edit`. Gelöscht wird über eine Eintragskennung, die erst gesucht werden muss. |
| netcup | `https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON` (gebaut) | `customer_number` + `api_key` + `api_password` + **`zones`** | **Der einzige mit einer Sitzung** und der einzige, dem die Zonen genannt werden müssen — seine Schnittstelle zählt die Domains eines Kontos nicht auf. |
| IONOS | `https://api.hosting.ionos.com/dns/v1` (gebaut) | `api_key` in der Form `<prefix>.<secret>` | Ein Feld, das in Wahrheit zwei ist — geprüft beim Hinterlegen. Und `suffix` im Filter ist ein Suffix, kein Name. |
| INWX | `https://api.domrobot.com/xmlrpc/` | `username` + `password`, wahlweise `shared_secret` | XML-RPC, Sitzungscookie, TOTP. **Und das Passwort öffnet das Registrarkonto**, nicht eine Zone. Der einzige, bei dem ein Kunde besser nicht seine eigenen Zugangsdaten hinterlegt. |
| deSEC | `https://desec.io/api/v1` (gebaut) | `token` | Der geradlinigste, und der **einzige, der die Zonenfrage selbst beantwortet** (`owns_qname`). Führt RRsets statt einzelner Sätze. Sein TTL ist mit 3600 der höchste der sieben — `ready()` darf hier nicht nach zwei Minuten aufgeben. |

Was **keiner** von ihnen bekommt: eine Zonenliste aus dem Formular. Die stand
bei RFC 2136 zu Recht darin — ein TSIG-Schlüssel ist im Nameserver ohnehin auf
Zonen eingegrenzt, und die Liste bildet das ab. Bei einer API-Schnittstelle
kennt der Anbieter seine Zonen selbst; ein zweites, von Hand gepflegtes
Verzeichnis daneben wäre die zweite Fassung derselben Auskunft, und die zweite
ist die, die veraltet.

### Strato hat keine öffentliche DNS-Schnittstelle

Nachgesehen am 7. August 2026: **Strato steht weder in lego noch in acme.sh.**
Das ist kein Versäumnis der beiden — es gibt schlicht keine Schnittstelle, über
die sich ein TXT-Eintrag setzen lässt. Für einen Kunden, der seine Domain bei
Strato hat, heisst das: **kein Platzhalter über DNS-01**, solange die Zone dort
liegt. HTTP-01 für einzelne Namen geht weiter.

Das gehört so gesagt, weil die naheliegende Antwort — „bauen wir eben Strato
dazu" — hier nicht existiert. Der Ausweg ist die Zone, nicht das Panel: Sie
lässt sich zu deSEC delegieren, ohne dass die Domain umzieht. Deshalb steht
deSEC überhaupt auf der Liste.

### INWX im Einzelnen

Nachgesehen am 7. August 2026 in lego und in dessen Client
([`nrdcg/goinwx`](https://github.com/nrdcg/goinwx)). **Die Seiten von INWX sind
aus diesem Container nicht erreichbar.**

- **Adresse:** `https://api.domrobot.com/xmlrpc/` (Testbetrieb:
  `api.ote.domrobot.com`)
- **Bauart:** XML-RPC, ein Aufruf mit genau einem Parameter — einer flachen
  Ablage
- **Anmelden:** `account.login` mit `user`, `pass`, `lang`; die Sitzung kommt als
  Cookie `domrobot=…`
- **Zweiter Faktor:** Antwortet die Anmeldung mit `tfa: GOOGLE-AUTH`, folgt
  `account.unlock` mit einem TAN aus dem gemeinsamen Geheimnis
- **Zonen:** `nameserver.list` → `resData.domains[].domain`, dazu `count`
- **Lesen:** `nameserver.info` mit `domain`, `name`, `type` →
  `resData.record[]` mit `id`, `name`, `content`
- **Schreiben:** `nameserver.createRecord`, **Löschen:**
  `nameserver.deleteRecord` mit `id`
- **Abmelden:** `account.logout`
- **Antwort:** `code` 1000 oder 1500 ist Erfolg, dazu `msg` und `reason` —
  **auch bei HTTP 200**
- Der Wert geht **ohne** Anführungszeichen hinaus

**Der TAN entscheidet den Entwurf.** INWX nimmt denselben kein zweites Mal; lego
wartet notfalls dreissig Sekunden auf den nächsten Zeitschritt. Hier wird einmal
je Bestellung angemeldet — Anlegen und Abräumen benutzen dieselbe Instanz —,
also gibt es genau einen TAN und keinen Schlaf im Agenten.

**Was hinterlegt wird, öffnet ein Registrarkonto.** Das steht als Satz über den
Feldern; die offene Frage dazu ist die aus §11.

**`Object exists` beim Anlegen ist kein Fehlschlag** — der Eintrag steht dann
schon da, etwa weil ein früherer Versuch abgebrochen ist, nachdem er ihn gesetzt
hatte.

**Und XML-RPC steht in `XmlRpc`, nicht hier.** Gebaut ist nur, was gebraucht
wird; beim Lesen sind zwei Vorkehrungen fest: kein Auflösen von Entitäten und
eine gedeckelte Verschachtelung. Die erste ist gemessen — mit `LIBXML_NOENT`
steht der Inhalt von `/etc/hostname` im Wert, mit den Marken der Klasse nicht.

### deSEC im Einzelnen

Nachgesehen am 7. August 2026 in lego und in dessen Client
([`nrdcg/desec`](https://github.com/nrdcg/desec)).

- **Adresse:** `https://desec.io/api/v1`
- **Anmeldung:** Kopfzeile `Authorization: Token <token>`
- **Zuständige Domain:** `GET /domains/?owns_qname=<voller name>` → eine Liste
  mit genau der Domain, die für den Namen zuständig ist
- **Lesen:** `GET /domains/<domain>/rrsets/<subname>/TXT/` → `{"records":[…]}`;
  `404`, wenn es den RRset noch nicht gibt
- **Anlegen:** `POST /domains/<domain>/rrsets/` mit `subname`, `type`, `records`
  und `ttl` → `201`
- **Ändern:** `PATCH /domains/<domain>/rrsets/<subname>/TXT/` mit `records`;
  eine leere Liste löscht den RRset, quittiert mit **`204`**
- Der Name der Domain selbst heisst `@`
- Der Wert steht **in** Anführungszeichen

**Die Zonenfrage stellt sich hier nicht — sie wird gestellt.** `owns_qname` ist
die einzige Auskunft dieser Art unter den sieben; überall sonst nennt der
Anbieter seine Zonen, und die längste passende wird gesucht.

**RRsets statt einzelner Sätze.** Alle TXT-Werte zu einem Namen sind ein
Gegenstand. Beim Anlegen wird deshalb angehängt, beim Abräumen nur der eigene
Wert herausgenommen; beides ist die Grenze zu einer gleichzeitig laufenden
Bestellung.

### IONOS im Einzelnen

Nachgesehen am 7. August 2026 in lego. **Die Seiten von IONOS sind aus diesem
Container nicht erreichbar**; die offene Frage unten gehört beim ersten Zugriff
geklärt.

- **Adresse:** `https://api.hosting.ionos.com/dns/v1`
- **Anmeldung:** Kopfzeile `X-Api-Key: <präfix>.<geheimnis>`
- **Zonen:** `GET /zones` → eine **schlichte Liste** `[{"id":…,"name":…}]`, ohne
  Blätterauskunft
- **Lesen:** `GET /zones/<id>?suffix=<name>&recordType=TXT` → `{"records":[…]}`
- **Schreiben:** `PATCH /zones/<id>` mit einem **Feld** von Sätzen
- **Löschen:** `DELETE /zones/<id>/records/<satz-id>`
- **Fehler:** `{"errors":[{"code":…,"message":…}]}`
- Der Wert geht **ohne** Anführungszeichen hinaus

**Der Schlüssel besteht aus zwei Teilen.** IONOS zeigt sie getrennt an, und der
Präfix steht obenan. Genau einen Punkt und zwei nicht leere Hälften zu verlangen
kostet zwei Zeilen und erspart eine nächtliche Erneuerung, die an einer Meldung
scheitert, die den Grund nicht nennt.

**Die offene Frage: was `PATCH` mit den Sätzen macht.** Fügt es sie hinzu oder
ersetzt es den Bestand zu diesem Namen? legos Code sagt es nicht, und anders als
bei netcup gibt es keinen zweiten Aufruf, der es verrät. Gebaut ist deshalb der
Weg, der unter beiden Lesarten richtig ist: die vorhandenen Sätze **zu diesem
Namen** mitschicken. Gelesen wird dabei nichts ausser dem, was auf denselben
Namen zeigt.

**Und `suffix` ist ein Suffix.** Der Filter liefert auch `x.<name>` mit; was
nicht genau dieser Name ist, gehört weder in die Liste, die zurückgeht, noch in
die Auswahl beim Löschen.

### netcup im Einzelnen

Nachgesehen am 7. August 2026 in lego. **Die Seiten von netcup selbst waren aus
diesem Container nicht abrufbar** — weder das Wiki noch der Endpunkt; was hier
steht, stammt aus legos Umsetzung und gehört beim ersten Zugriff dagegen
gehalten.

- **Adresse:** `https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON`
- **Bauart:** alles `POST` mit JSON `{"action":"…","param":{…}}`; Kundennummer
  und API-Schlüssel stehen in **jedem** Rumpf
- **Anmelden:** `login` mit `apipassword` → `responsedata.apisessionid`
- **Abmelden:** `logout` mit `apisessionid`
- **Lesen:** `infoDnsRecords` mit `domainname`
- **Schreiben:** `updateDnsRecords` mit `dnsrecordset.dnsrecords`; ein Satz ohne
  `id` wird angelegt, einer mit `deleterecord: true` gelöscht
- **Antwort:** `status` ist `success` oder `error`, dazu `statuscode`,
  `shortmessage`, `longmessage` — **auch bei HTTP 200**
- Der TXT-Wert geht **ohne** Anführungszeichen hinaus, anders als bei Hetzner
  und Cloudflare

**Die Zonen stehen in den Zugangsdaten.** Es gibt keine Auskunft, die die
Domains eines Kontos aufzählt; lego fragt dafür die autoritativen Nameserver
nach dem SOA-Satz. Das wäre eine dritte Quelle für dieselbe Frage — stattdessen
gilt dieselbe Antwort wie bei RFC 2136: eine Positivliste, die der Betreiber
aufschreibt. Ein Name ausserhalb kostet damit nicht einmal eine Anmeldung.

**Angemeldet wird je Vorgang, abgemeldet im `finally`, und das Ergebnis des
Abmeldens wird nicht geprüft.** Das erste, damit keine Sitzung liegenbleibt,
wenn der Zugriff dazwischen scheitert; das zweite, damit ein gescheitertes
Abmelden aus einem gesetzten Eintrag keinen Fehlschlag macht.

**Geschrieben wird nur der eine Satz.** lego liest hier die ganze Zone, hängt an
und schickt alles zurück — ein Lesen-Ändern-Schreiben über den Bestand eines
Kunden. Dass `updateDnsRecords` nicht ersetzt, sondern anlegt und löscht, zeigt
legos eigenes `CleanUp`: Es schickt genau einen Satz, und wäre der Aufruf ein
Ersetzen, nähme er jedem netcup-Nutzer beim Abräumen die Zone.

### Hetzner im Einzelnen

Nachgesehen am 7. August 2026 — und zwar **nicht in lego**, sondern in Hetzners
eigenem Go-SDK
([`hetznercloud/hcloud-go`](https://github.com/hetznercloud/hcloud-go)). lego
löst die Zone hier über den SOA-Satz der autoritativen Nameserver auf und
benutzt `GET /zones` gar nicht; das SDK zeigt, dass es den Endpunkt gibt, samt
Blätterauskunft und Feldnamen.

- **Adresse:** `https://api.hetzner.cloud/v1`
- **Anmeldung:** `Authorization: Bearer <Token>`
- **Zonen:** `GET /zones?page=<n>&per_page=50` → `{"zones":[{"name":…}],
  "meta":{"pagination":{"next_page":…}}}`; `next_page` steht auf `null`, sobald
  die letzte Seite erreicht ist
- **Anlegen:** `POST /zones/<zone>/rrsets/<praefix>/TXT/actions/add_records`,
  JSON, `{"ttl":60,"records":[{"value":"\"…\""}]}`
- **Löschen:** dasselbe auf `remove_records`, ohne `ttl`
- **Antwort:** eine *Action* mit `status` aus `running`, `success`, `error` —
  der Schreibvorgang ist beim Antworten also **nicht** zwingend fertig
- **Fehler:** `{"error":{"code":…,"message":…}}`; Drosselung mit HTTP 429

**Der Name der RRSet ist der Präfix, nicht der volle Name.** Wer den vollen
schickt, legt den Eintrag unter `_acme-challenge.example.de.example.de` an —
angenommen wird das, gefunden wird es nie.

**Gewartet wird auf die Action nicht.** Ob der Eintrag ausgeliefert wird,
beantwortet ohnehin nur `DnsChallenge` durch Fragen der autoritativen
Nameserver, und das ist die strengere Frage. Gelesen wird der Zustand einmal,
und zwar wegen `error`: Der steht sofort da und spart eine Prüfung, die zwei
Minuten auf einen Eintrag wartet, den niemand mehr anlegt.

**Und die Blätterschleife zählt Runden, nicht Seitennummern.** Beim Bauen lief
sie endlos, weil sie die Seitennummer mit der Obergrenze verglich und
`next_page` mit `1` zurückkam, während `page` auf `1` stand. Das Ende wird
gemeldet und nicht verschwiegen — wer still abschneidet, sagt gleich darauf
„für diesen Namen keine Zone" und nennt einen Grund, der nicht stimmt.

### Cloudflare im Einzelnen

Nachgesehen am 7. August 2026 in lego und — für die Filter beim Suchen — in
Cloudflares eigenem Go-SDK
([`cloudflare/cloudflare-go`](https://github.com/cloudflare/cloudflare-go)).

- **Adresse:** `https://api.cloudflare.com/client/v4`
- **Anmeldung:** `Authorization: Bearer <Token>`. Der ältere Weg über
  `X-Auth-Email` und `X-Auth-Key` wird **nicht angeboten** — siehe unten.
- **Zonen:** `GET /zones?page=<n>&per_page=50` mit
  `{"success":true,"result":[{"id":…,"name":…}],"result_info":{"total_pages":…}}`
- **Anlegen:** `POST /zones/<zonen-id>/dns_records`, JSON, mit `type`, `name`
  (dem **vollen** Namen), `content` (in Anführungszeichen) und `ttl`
- **Suchen:** `GET /zones/<zonen-id>/dns_records?type=TXT&name.exact=<name>`
- **Löschen:** `DELETE /zones/<zonen-id>/dns_records/<eintrags-id>`
- **Fehler:** `{"success":false,"errors":[{"code":…,"message":…}]}` — und zwar
  **auch mit HTTP 200**

**Der volle Name, nicht der Präfix.** Das ist der Unterschied zu Hetzner: Dort
adressiert der Pfad die RRSet über den Präfix, hier steht der ganze Name im
Rumpf.

**Der globale API-Schlüssel wird abgewiesen, nicht bloss nicht angeboten.** Er
öffnet das ganze Konto. lego nimmt ihn entgegen und rät im Kommentar davon ab;
ein Rat in einem Kommentar ist zu wenig für ein Formularfeld auf einem Server,
auf dem Kunden Websites betreiben. Wer eine Kontoadresse mitschickt, bekommt
einen Satz dazu — sie stillschweigend fallenzulassen hiesse, etwas anderes
entgegenzunehmen, als der Betreiber gemeint hat.

**Gelöscht wird über eine Eintragskennung.** lego merkt sie sich beim Anlegen;
wir suchen sie beim Abräumen, weil `cleanup()` auch nach einem Fehlschlag läuft
und die Ablage dann leer wäre — lego bricht dort mit „unknown record ID" ab und
macht aus einem Fehlschlag zwei. Und der Wert wird nach dem Suchen **noch
einmal** verglichen: Cloudflares Filter sind ausdrücklich nicht auf Gross- und
Kleinschreibung bedacht, ein ACME-Prüfwert ist es sehr wohl.

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

**Ein Fund, der die Bauart bestätigt.** Bei IPv64.net ist die Zone häufig selbst
eine Unterdomain (`meinname.ipv64.de`), also **nicht** die registrierbare
Domain. Wer die Zone aus dem Namen errechnet, liegt hier falsch. Deshalb steht
in §4, dass die Zone **abgefragt** und nicht abgeleitet wird — `get_domains` ist
genau dafür da. Was als Vorsicht in den Plan geschrieben war, ist bei diesem
Anbieter der Normalfall.

> **Berichtigung vom 7. August.** Hier stand, `get_domains` sei „genau dafür da,
> und acme.sh macht es ebenso", und lego „verlange mindestens drei
> Bestandteile". Beim Bauen nachgesehen: lego benutzt `get_domains` **nicht**
> zur Zonenauflösung. Sein `splitDomain` nimmt schlicht die **letzten drei**
> Bestandteile des Namens. Für `meinname.ipv64.de` kommt dabei dasselbe heraus
> wie bei uns; für eine eigene Domain mit zwei Bestandteilen, die jemand zu
> IPv64.net bringt, nicht mehr — `example.de` als Zone ergäbe dort
> `_acme-challenge.example.de`, also den Namen selbst.
>
> Die Abfrage ist damit **unsere** Wahl und nicht die von lego, und sie ist die
> bessere. Das gehört richtiggestellt, weil eine Begründung, die sich auf einen
> anderen beruft, beim nächsten Anbieter wieder herangezogen wird — und dann
> für etwas, das dort nicht stimmt.

Der Feldname `praefix` ist deutsch geschrieben, weil der Anbieter ihn so nennt.
Das ist eine echte Schnittstelle nach aussen — `docs/19 §4a` lässt sie, wie sie
ist; übersetzt wird sie nicht.

Die Liste ist eine Positivliste im Code. Ein achter Anbieter ist eine Änderung,
die jemand liest, kein Feld in einem Formular — dieselbe Haltung wie bei den
Zertifizierungsstellen in `Directories`.

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

> **Erledigt in Schritt 3a — der Agent und die Kommandozeile.** Die Prüfung
> steht in `Bundle`, die Operation heisst `tls.certificate.upload`, und abgelegt
> wird unter `_uploaded.<name>`: Das ist die Antwort auf den Zusammenstoss aus
> §2.2 — unterschieden wird nach der **Quelle**, nicht nach dem Namen.
>
> **Und sie läuft nicht über die Warteschlange, das ist der Fund dieses
> Schritts.** Ein eingereihter Vorgang legt seine Argumente in
> `operations.payload` ab — der private Schlüssel läge damit im Klartext in der
> Datenbank, dauerhaft und für jeden lesbar, der sie liest. Er darf den Socket
> genau einmal überqueren und nirgends sonst stehen. Das Kommando ruft den
> Agenten deshalb unmittelbar und schreibt den Bestand über
> `CertificateRecord` — dieselbe Stelle, die auch eine Bestellung benutzt.
> Für die Oberfläche (Schritt 3b) gilt dasselbe: kein Vorgang, sondern ein
> unmittelbarer Aufruf.
>
> **Erledigt in Schritt 3b — die Oberfläche.** Ein eigener Bereich auf der
> Domainseite mit zwei Textfeldern, sichtbar nur, wenn der Plan
> `certificate_upload` freigibt; gefragt wird dieselbe Policy, die die Route
> später abweist. Zwei Textfelder und keine Dateiauswahl: Wer ein Zertifikat
> gekauft hat, hat es meistens als Text in einer Mail, und eine Dateiauswahl
> auf dem Telefon findet den Anhang einer Mail nicht.
>
> Dabei ist zum dritten Mal derselbe Abstand aufgefallen — eine Knopfreihe
> hinter einem Feld klebt am Text darüber, weil `.button-row` keinen Rand nach
> oben setzt. Die Antwort war bisher jedes Mal eine eigene Klasse auf der Seite;
> jetzt steht sie in `app.css`, wo das Aussehen eines Bausteins hingehört.

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

**Entschieden am 6. August 2026: der laute Rückfall.** Läuft die Wahl ab, wird
sie übergangen — ein hochgeladenes Zertifikat erneuert niemand, und stur daran
festzuhalten nähme die Website vom Netz. **Ist die Wahl abgelaufen und liegt
ein gültiges, deckendes Zertifikat vor, liefert der Block dieses aus**; die
Wahl bleibt eingetragen, greift wieder, sobald sie gilt, und die Domainseite
sagt in einer Meldung, dass sie gerade übergangen wird.

> **Erledigt in Schritt 4.** Die Antwort auf „welches liefert dieser Block
> aus?" steht in `CertificateChoice` und nirgends sonst — `effective()` für den
> Server-Block und die Seite, `satisfied()` für die Frage, ob bestellt werden
> muss.
>
> **Und die beiden sind mit Absicht verschiedene Fragen.** `satisfied()` fragt
> nicht nach der Wahl, sondern ob es überhaupt eines gibt, das gilt und alles
> deckt. Fragte die Bestellbedingung nach dem *zugeordneten* Zertifikat,
> bestellte eine Domain mit abgelaufener Wahl bei jedem Anwenden erneut: Die
> Zuordnung ändert sich ja nicht. Das ist beim Bauen aufgefallen und wäre eine
> Bestellschleife bis in die Wochengrenze gewesen.
>
> **Gibt es gar nichts Gültiges, bleibt das Abgelaufene stehen.** Ohne
> Zertifikat fällt der Block auf Port 80 zurück — eine Adresse, die vorher
> HTTPS war, ist dann nicht mehr erreichbar, sondern still unverschlüsselt. Ein
> abgelaufenes warnt wenigstens.

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
   Zone. *Gebaut.* Die Zonen stehen dabei **in den Zugangsdaten** und werden
   nicht über den SOA-Satz erraten: Ein TSIG-Schlüssel ist im Nameserver
   ohnehin auf eine Zone eingegrenzt, und die Liste ist damit eine
   Positivliste — ein Profil ändert genau die Zonen, die der Betreiber
   hineingeschrieben hat, und nicht die, die aus dem Namen einer Kundendomain
   folgen. *Wächter:* die Unterschrift Byte für Byte gegen RFC 8945 §4.3.3
   nachgerechnet (und nicht mit derselben Klasse gebaut, die sie prüft), die
   Antwort des Nameservers nachgerechnet, ein Name ausserhalb der Zonen wird
   nicht versucht, und jeder Anbieterschlüssel zeigt auf eine Umsetzung oder
   steht als offen. Die Abnahme gegen eine echte Zone steht aus (§10).
8. **Bestellen mit Platzhalter in der Oberfläche.** Wer darf (§3), was bestellt
   wird (`example.de` **und** `*.example.de`), und was die Seite sagt, wenn eine
   zweite Ebene ungedeckt bleibt. *Gebaut.* Bestellt wird **mit dem Stern
   zuerst** — der Ablageort entsteht aus dem ersten Namen, und andersherum
   überschriebe der Platzhalter ein einfaches Zertifikat für dieselbe Domain.
   Erlaubt ist er nur zu einer Haupt- oder Zusatzdomain des eigenen
   Abonnements, geprüft an der Domain; die Berechtigung hängt an
   `Feature::DnsEdit`, weil DNS-01 die Zone ändert. *Wächter:* die Reihenfolge
   der Namen, die fehlenden Zugangsdaten, der Platzhalter zu einer Subdomain,
   die gewöhnliche Bestellung ohne DNS-01, und die Fähigkeit, die im Payload
   ankommt.
9. **Die sieben Anbieter** — einer nach dem anderen, jeder mit seinem Wächter
   und seinen Fehlerfällen. **IPv64.net vorgezogen und gebaut:** Er bringt den
   Fall mit, an dem sich die Zonenauflösung beweist — die Zone ist dort oft
   selbst eine Unterdomain (§6). **Hetzner ebenfalls gebaut**, gegen die
   Cloud-API und nicht gegen die auslaufende DNS-Konsole. **Cloudflare
   ebenfalls gebaut**, nur mit API-Token; der globale Schlüssel wird abgewiesen.
   **netcup ebenfalls gebaut** — der erste mit einer Sitzung und der erste, dem
   die Zonen genannt werden müssen. **IONOS und deSEC ebenfalls gebaut** — deSEC
   als einziger ohne `Zones`, weil er die Zonenfrage selbst beantwortet.
   **INWX zuletzt und ebenfalls gebaut** — XML-RPC, Sitzungscookie, TOTP und ein
   Kontopasswort statt eines Tokens. Damit stehen alle acht, und
   `Providers::PENDING` ist leer.

   Vorweg kam eine Grenze, die es schon gab, aber nur als Prosa: **Der Agent
   spricht an genau einer Stelle nach draussen** (`Acme\Curl`), und ihre vier
   Zusagen — keine Umleitung, Zertifikat geprüft, Name geprüft, zwei Zeitlimits
   — stehen jetzt in `OutboundSourceTest`. Gefunden beim ersten Anbieter, also
   genau in dem Moment, in dem die zweite Stelle mit `curl_setopt` entstanden
   wäre.

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
   IPv64.net (§6). **Am 7. August erweitert:** Nach einer Durchsicht aller 222
   Anbieter in lego kamen IONOS, INWX und deSEC dazu — sieben mit eigener
   Schnittstelle plus RFC 2136. deSEC ist dabei die Antwort auf Strato, das
   überhaupt keine öffentliche DNS-Schnittstelle hat (§6).
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
- ~~Der Endpunktsatz von IPv64.net (§6)~~ — **beantwortet.** Gebaut wie
  beschrieben; die Zonenauflösung über `get_domains` ist unsere Wahl und nicht
  die von lego, siehe die Berichtigung in §6.
- Ob ein Kunde bei INWX überhaupt Zugangsdaten hinterlegen soll (§6). Dort ist
  es sein Registrarkonto und nicht eine Zone; das ist eine andere Grössenordnung
  als ein Token, das eine Zone öffnet.
- Die Frist, nach der `ready()` aufgibt, und wie oft dazwischen gefragt wird.
  Sie hängt an dem, was die Anbieter tatsächlich tun, und gehört gemessen statt
  geraten.
- Die Obergrenze für eine hochgeladene Datei (§7).
