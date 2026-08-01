# 18 — Webserver & Domains (Stufe 0.6)

> **Stand:** Schritt 1 von 8 ist gebaut (Fundament: Zustand, Portbelegung,
> Installation). Alles Weitere ist Plan.
> Die Vorgaben kommen aus [16-neukonzeption.md](16-neukonzeption.md) §5 (0.6),
> §7.4 und der Meilensteintabelle in [06-roadmap.md](06-roadmap.md).

## Anlass

`docs/16` §2 nennt den Grund in einem Satz:

> Der häufigste Handgriff nach dem Aufsetzen eines Dienstes ist „mach ihn unter
> einem Namen mit TLS erreichbar". Das ACME-Modul existiert bereits; der Schritt
> von „Zertifikat fürs Panel" zu „Zertifikat je Domain" ist der kleinste im
> ganzen Vorhaben.

Der zweite Halbsatz stimmt nicht mehr, seit man nachsieht. Er ist der Grund für
Abschnitt 3 dieses Plans.

Heute verweist die Seite `/webserver` auf den Dateimanager: „Konfigurationsdateien
lassen sich heute über die Dateien bearbeiten, und der Dienst darüber neu laden."
Das ist die Latte, über die dieses Modul springen muss — wer am Ende nur einen
zweiten Texteditor bekommt, hat nichts gewonnen.

---

## 1. Was §5 vorgibt

| Vorgabe | Bemerkung |
|---|---|
| nginx **oder** Caddy, erkannt wird was läuft; fehlt beides, Installation über das Paketmodul (Vorgabe nginx) | **Abweichung, siehe E1:** verwaltet wird nginx; jeder andere Webserver — Caddy, Apache, Traefik — gilt als fremd. In `docs/16` §5 nachgetragen |
| Eine Site ist **Domain → Ziel → TLS**. Ziele: Reverse-Proxy auf Container oder Port, statisches Verzeichnis, PHP-FPM (optional) | |
| Geschrieben wird ausschließlich als verwaltetes Drop-in `/etc/nginx/conf.d/asylum-<site>.conf` mit Marker; fremde vHosts werden **angezeigt, nie verändert** | dieselbe Trennung wie bei nftables und bei fremden Compose-Projekten |
| Jeder Schreibvorgang als Kette: Backup → schreiben → `nginx -t` → neu laden → **Probe** mit selbsttätigem Rückweg | §7.4 |
| TLS je Domain über das vorhandene ACME-Modul; der Zertifikatshalter wird mehrfähig | siehe Abschnitt 3 |
| `privops` wächst um `WebServerState`, `SiteList`, `SiteApply`, `SiteRemove` | |
| Aufwand | ~3 Wochen laut Roadmap; siehe Abschnitt 9 |

---

## 2. Was der Bestand schon hergibt

Vor dem ersten Handgriff nachgesehen, weil es den Zuschnitt ändert:

| Vorhanden | Wo | Passt wie |
|---|---|---|
| **Probe mit Frist und Rückweg** | `firewallGuard` in `internal/httpd/jobs.go` | `arm(subject, revert)`, `confirm()`, `state()` — generisch geschrieben, heute an ein Feld `s.fwGuard` gebunden |
| **Konfigurationsprüfung** | `privops.ConfigCheck` (`sshd -t`, `nft -c -f`) | `nginx -t` fügt sich als dritter Fall ein, `ConfigCheckResult` passt unverändert |
| **Verwaltetes Drop-in mit Marker** | `internal/config/dropin.go`, `managedHeader` | Vorbild für `asylum-<site>.conf` |
| **ACME mit HTTP-01 und DNS-01** | `internal/acme` | vollständig — aber einfach, siehe Abschnitt 3 |
| **Vorgänge mit Strom, Rückfragen, Audit, Signale** | `internal/httpd` | unverändert nutzbar |
| **Warnpunkte im Menü** | seit 0.5.1 | ein neues Signal ergibt automatisch einen Punkt |

Und was **fehlte**: ein Eintrag `nginx` in der Allowlist
(`internal/privops/exec.go`). Beim Bau von Schritt 1 kam ein zweiter dazu, den
dieser Plan nicht vorgesehen hatte: **`ss`**. Er beantwortet die Frage, an der
der Installationsknopf hängt — wer hört auf 80 und 443. Ohne ihn müsste das
Modul nach Paketnamen fragen, und eine Liste bekannter Konkurrenten ist immer
unvollständig: Sie hätte Caddy gekannt und den Apache übersehen, und ein Traefik
im Container heißt auf dem Wirt `docker-proxy` und in keiner Paketliste
irgendwas.

---

## 3. Der Befund, der den Plan prägt: Port 80 gehört danach nginx

**Das Panel bindet Port 80 selbst.** `internal/acme/http01.go`:

```go
func newHTTP01Solver(ctx context.Context, addr string) (*http01Solver, error) {
	if addr == "" {
		addr = ":80"
	}
	ln, err := lc.Listen(ctx, "tcp", addr)
	if err != nil {
		return nil, fmt.Errorf("HTTP-01 braucht Port %s, das Binden schlug fehl "+
			"(läuft dort ein Webserver?): %w", addr, err)
	}
```

Die Fehlermeldung nimmt den Fall vorweg, den dieses Modul erzeugt: **Sobald das
Panel nginx installiert, gehört Port 80 nginx**, und das Panel kann sein eigenes
Zertifikat nicht mehr über HTTP-01 erneuern. Nicht irgendwann — beim nächsten
Erneuerungslauf, still, sechzig Tage später.

Das ist kein Randfall, sondern die Voreinstellung: `tlsctl.go` setzt
`HTTP01Addr: ":80"`.

**Der Ausweg gehört in denselben Schritt wie die Installation** und nicht
hinterher:

Läuft ein Webserver, löst das Panel HTTP-01 **durch ihn hindurch** statt daneben.
Es schreibt ein verwaltetes Drop-in, das `/.well-known/acme-challenge/` aus einem
Verzeichnis ausliefert, und der Solver legt die Token dort als Dateien ab, statt
zu lauschen. Das ist der Weg, den certbot seit jeher geht; er ist unauffällig,
braucht keinen Port und funktioniert für das Panel und für jede Site gleich.

Zwei Folgen, die benannt gehören:

1. **Der Solver bekommt eine zweite Bauart.** `challengeSolver` ist schon ein
   Interface (`present`/`cleanup`/`challengeType`) — ein `webrootSolver` daneben
   ist wenig Code. Die Wahl trifft nicht der Betreiber, sondern der Zustand:
   Webserver da → Webroot, sonst → eigener Listener.
2. **Das Drop-in für die Challenge ist die erste Site, die das Panel schreibt** —
   und es schreibt sie für sich selbst. Damit ist der Schreibpfad samt Probe
   schon im Fundamentschritt in Betrieb, bevor die erste Benutzersite entsteht.

---

## 4. Der zweite Befund: der Zertifikatshalter kennt genau ein Zertifikat

`internal/certs/holder.go`:

```go
func (h *Holder) GetCertificate(*tls.ClientHelloInfo) (*tls.Certificate, error)
```

Der ClientHello wird **ignoriert**. Es gibt ein Zertifikat, und jede Verbindung
bekommt es. Ebenso im Manager: ein `m.domains`, ein `covers(cert, domains)`, ein
Speicherort `acme.CertPath(dataDir)` — ein Pfad, keine Karte.

„Der Zertifikatshalter wird mehrfähig" ist damit **kein Nebensatz, sondern ein
eigener Schritt an Bestandscode im TLS-Pfad des Panels**. Wer ihn falsch macht,
nimmt sich die Oberfläche selbst.

Zwei Wege:

| | Ein Zertifikat mit allen Namen (SAN) | Ein Zertifikat je Site |
|---|---|---|
| Halter | bleibt wie er ist | Karte Name → Zertifikat, Auswahl über SNI |
| Manager | Domainliste erweitern | Erneuerungsschleife je Zertifikat |
| Fehlschlag bei einer Domain | kostet **alle** — das Zertifikat wird als Ganzes ausgestellt | kostet diese eine |
| Domainliste | steht in der Panel-Konfiguration | steht bei der Site |
| Rate Limits von Let's Encrypt | ein Zertifikat je Änderung an der Liste | eines je Site, seltener geändert |

**Empfehlung: ein Zertifikat je Site.** Der Ausschlag ist die dritte Zeile: Eine
Site mit falsch gesetztem DNS darf nicht das Zertifikat des Panels mitreißen.

Der Halter bekommt dafür eine Karte und einen Rückfall: Kein Eintrag für den
angefragten Namen → das Zertifikat des Panels. Ein Browser, der eine unbekannte
Domain anfragt, bekommt dann eine Warnung statt eines Verbindungsabbruchs, und
das ist die bessere der beiden Antworten.

---

## 5. Grundentscheidungen

**E1 — nginx wird verwaltet, alles andere ist ein fremder Webserver.**
§5 sagt „nginx oder Caddy — erkannt wird, was läuft". Zwei Backends schreibend
heißt: zwei Syntaxen, zwei Prüfprogramme, zwei Schreibpfade, zwei
Angriffsdurchgänge, doppelte Tests. Aber auch „Caddy nur lesend" ist teurer als
es klingt: Caddy-Sites *anzeigen* heißt Caddyfile parsen (eigene Grammatik) oder
die Admin-API auf `localhost:2019` befragen, und `caddy adapt` steht zwischen
beiden Fassungen — fünf Tage für eine Liste, mit der man nichts tun darf.

Für 0.6 gilt deshalb nicht „nginx oder Caddy", sondern:

| Zustand | Antwort des Panels |
|---|---|
| nginx läuft | **verwaltet** — Sites, Schreibpfad, Probe, TLS |
| ein anderer Webserver hält 80/443 | Name und Fassung nennen, **kein Installationsknopf**, Verweis auf den Dateimanager |
| nichts hält 80/443 | Knopf „nginx installieren" |

Caddy ist damit kein zweites Backend, sondern **dieselbe Kategorie wie Apache,
lighttpd oder ein Traefik im Container** — und das deckt mehr ab als ein
eigens modellierter Caddy-Zweig, der Apache übersehen hätte.

Die mittlere Zeile ist keine Höflichkeit, sondern eine Sicherung: Bietet das
Panel „nginx installieren" an, während schon etwas auf 80 hört, macht ein Klick
den Server kaputt — `apt-get install nginx` startet nginx, nginx bindet 80, der
laufende Server ist weg. **Das Modul muss deshalb den Port prüfen, nicht den
Paketnamen.**

Ein Nebenbefund, der dazugehört: **Caddy macht TLS selbst.** Auf einem
Caddy-Host wäre §5s Zusage „TLS je Domain über das ACME-Modul des Panels"
ohnehin gegenstandslos — die Zertifikate gehören dort Caddy. Ein halb
unterstütztes Caddy wäre auch ein halb eingelöstes Versprechen.

**Die Vorsorge für später** kostet fast nichts und gehört von Anfang an rein:
Die Site bleibt **Daten** (Domain, Ziel, TLS, Optionen); nur die *Erzeugung des
Textes* ist nginx-spezifisch — eine Funktion, kein Interface. Und weil der
Prüfer die Felder prüft und nicht den erzeugten Text (Abschnitt 6), ist er
backend-neutral und bliebe bei einem zweiten Backend unverändert.

**E2 — Sites sind Felder, kein Text.**
Der Compose-Editor bekam mit 0.5.1 ein Formular *neben* der Datei. Hier gibt es
die Datei nicht: **In 0.6 gibt es keinen Rohtext-Editor für nginx-Direktiven.**
Der Grund ist der Prüfer. Ein Freitextblock in einer verwalteten Site hebt jede
Prüfung auf — eine einzige Zeile `root /;` veröffentlicht den Server. Bei
Compose war das anders: Die Datei gehört dem Betreiber und existiert schon, und
der Prüfer läuft gegen die gerenderte Fassung.

Wer Handarbeit braucht, hat sie: fremde vHosts bleiben unangetastet, und der
Dateimanager kann sie bearbeiten. Das Modul nimmt nichts weg.

**E3 — Ein Drop-in je Site, mit Marker und Hash.**
`/etc/nginx/conf.d/asylum-<site>.conf`, erste Zeile Marker (Vorbild
`internal/config/dropin.go`). Eine Datei ohne Marker wird nie überschrieben,
auch nicht am eigenen Platz — dieselbe Regel wie bei Compose-Stacks. Dazu ein
Hash des zuletzt geschriebenen Standes: Wurde die Datei von Hand geändert, sagt
die Fläche das, statt die Änderung stillschweigend zu überfahren.

**E4 — Die Probe ist die Sicherung, nicht der Dialog.**
§7.4 wörtlich: „Ein Dialog schützt vor Versehen; die Probe schützt auch dann,
wenn man nicht mehr klicken kann." Jeder Schreibvorgang an einer Site läuft
deshalb durch dieselbe Kette wie ein Firewall-Regelsatz. **Der `firewallGuard`
wird dafür verallgemeinert**, nicht kopiert: Ein zweiter Wächter mit derselben
Mechanik wäre die zweite Stelle, an der die wichtigste Sicherung des Panels
steht.

Konkret: aus `firewallGuard` wird `probenWaechter` mit einer Kennung je Bereich
(`firewall`, `webserver`). Der Warnpunkt aus 0.5.1 liest heute
`s.fwGuard.state()` — er muss beide sehen.

**E5 — Das Panel prüft, ob es sich selbst noch erreicht.**
Die Firewall-Probe fragt nicht nach, ob das Panel erreichbar ist; sie zählt nur
herunter. Für den Webserver reicht das nicht, weil das Panel **hinter** dem
Proxy liegen kann: Ein kaputtes Drop-in nimmt dann nicht den Port, sondern den
Weg. Der Rückweg wird deshalb zusätzlich von einer Bereitschaftsprüfung
ausgelöst — dieselbe Mechanik, die das Selbstupdate seit 0.3 hat
(`cmd/asylumd/update.go`).

**E6 — Zertifikate gehören zur Site, nicht zu einer Liste.**
Siehe Abschnitt 4. Eine Site trägt ihre Domains; das Zertifikat entsteht daraus.
Die Panel-Zertifikatsseite bleibt, was sie ist: die Fläche für das Zertifikat
*des Panels*.

---

## 6. Der sicherheitskritische Kern: der Site-Prüfer

Analog zum Compose-Prüfer, und aus demselben Grund: Was das Panel schreibt, läuft
als root und ist aus dem Netz erreichbar.

**Abgelehnt (400, mit Nennung von Feld und Grund):**

| Fund | Warum |
|---|---|
| `root`/`alias` auf einen Pfad der **Sperrliste** (`/etc`, `/root`, `/opt/asylum`, private Schlüssel) | Eine Site mit `root: /` veröffentlicht den ganzen Server im Netz — der Fall, der dem Bind-Mount auf `/` beim Compose-Prüfer entspricht |
| `listen` auf dem **Panel-Port** | Selbstausschluss |
| `proxy_pass` auf das Panel selbst | Schleife; und ein Proxy davor umgeht die Herkunftsprüfung des Panels |
| `server_name`, das eine **fremde** Site schon führt | Zwei vHosts für denselben Namen: nginx nimmt den ersten, und welcher das ist, hängt an der Lesereihenfolge |
| Ein Domainname, der kein Domainname ist | vor ACME, nicht danach |

**Stufe 3 (getippter Domainname), nicht abgelehnt:**

- `root` auf ein Verzeichnis **außerhalb** der üblichen Wurzeln (`/var/www`,
  `/srv`) — legitim und zugleich der Weg, über den eine Site fremde Daten
  ausliefert. Dasselbe Muster wie der Bind-Mount nach draußen.

**Zwei Eigenschaften, die der Prüfer haben muss** (wörtlich aus `docs/17` §4,
und sie gelten unverändert):

1. Er läuft **serverseitig vor jedem Schreiben**, nicht im Formular.
2. Was er nicht kennt, meldet er als „nicht geprüft" — nicht als „in Ordnung".

Dazu kommt hier eine dritte, die es bei Compose nicht gab: **Der Prüfer prüft
sein eigenes Erzeugnis.** Die Site entsteht aus Feldern, also erzeugt das Panel
den Text. Ein Prüfer, der nur den erzeugten Text liest, prüft die eigene
Vorlage. Geprüft werden deshalb **die Felder** — und `nginx -t` prüft danach,
ob daraus gültige Syntax wurde.

---

## 7. Bestätigungsstufen

Nach `docs/14-bestaetigungen.md`; Abweichungen dort eintragen.

| Aktion | Stufe | Begründung |
|---|---|---|
| Webserver installieren | 2 | Job, apt, umkehrbar |
| Site anlegen oder ändern | 2 **plus Probe** | Der Dialog nennt Domain und Ziel; die Probe fängt den Fehler, den niemand vorher sieht |
| Site mit `root` außerhalb der üblichen Wurzeln | **3, Domainname** | siehe Prüfer |
| Site abschalten | 2 | die Domain ist danach nicht mehr erreichbar |
| Site löschen | **3, Domainname** | Drop-in weg, Zertifikat verwaist |
| Webserver neu laden | 1 | umkehrbar, und die Probe steht ohnehin |
| Zertifikat einer Site erneuern | 1 | ändert nichts an der Konfiguration |

---

## 8. Die Oberfläche

Ein Modul mit Flächen, wie Docker seit 0.5.1 — die Machart steht und muss nicht
erfunden werden (`kinder` in `lib/ziele.ts`, Unterpfad in der Adresse,
Umschaltstreifen unter 900 px).

| Fläche | Adresse | Inhalt |
|---|---|---|
| **Sites** (Vorgabe) | `/webserver` | Werkbank: Liste (verwaltet/fremd, Domain, Ziel, TLS-Zustand) + Inspektor mit Feldern, Vorgangsplatte |
| **Zertifikate** | `/webserver/zertifikate` | je Site: Aussteller, Restlaufzeit, letzter Bezugsversuch, Knopf „jetzt erneuern" |
| **Zustand** | `/webserver/zustand` | welcher Server läuft, `nginx -t` auf Knopfdruck, die letzten Zeilen des Fehlerprotokolls |

Der Zustandskopf (Server, Fassung, läuft/läuft nicht) steht über allen Flächen —
wie bei Docker, und aus demselben Grund.

> **Abweichung aus Schritt 1:** Gebaut ist zunächst **eine** Fläche, nicht drei.
> Die Unterpunkte entstehen mit ihrem Inhalt (Schritt 4 und 7) und nicht vorher.
> Der Grund ist derselbe, aus dem Docker in seinem ersten Schritt eine Seite
> bekam: Zwei Menüeinträge, die auf „kommt noch" führen, sind schlechter als
> keine — sie versprechen eine Fläche und liefern eine Vertröstung. Was fehlt,
> sagt stattdessen ein Absatz auf der einen Fläche.

**Fehlt ein Webserver**, zeigt die Seite genau eine Karte mit dem Zustand und dem
Knopf „nginx installieren" — die Antwort, die ufw seit `rc.4` und Docker seit
0.5.0 geben.

**Läuft ein fremder Webserver**, zeigt dieselbe Karte, welcher es ist und auf
welchen Ports er hört — und **keinen Installationsknopf** (E1). Dazu der Satz,
der ihn ersetzt: dass seine Konfiguration über den Dateimanager erreichbar
bleibt und das Panel sie nicht anfasst.

---

## 9. Umsetzung in acht Schritten

Jeder Schritt endet mit etwas, das läuft, und mit Tests.

| # | Schritt | Ergebnis |
|---|---|---|
| 1 ✅ | **Fundament**: Allowlist-Einträge `nginx` und `ss`, `WebServerState` (nginx verwaltet / fremd mit Name / keiner, dazu **wer 80 und 443 hält**), Installation als Job und nur bei freiem Port, `GET /api/v1/webserver`, eine Fläche | Das Modul existiert, kann nginx installieren — und tut es nicht, wenn dort schon etwas läuft |
| 2 | **Der Challenge-Weg** (Abschnitt 3): `webrootSolver`, verwaltetes Drop-in für `/.well-known/acme-challenge/`, Auswahl nach Zustand | Das Panel erneuert sein eigenes Zertifikat weiter, **nachdem** nginx da ist. Der Schreibpfad samt Probe ist damit in Betrieb, bevor die erste Benutzersite existiert |
| 3 | **Der Halter wird mehrfähig** (Abschnitt 4): Karte Name → Zertifikat, Auswahl über SNI, Rückfall auf das Panel-Zertifikat; Manager je Zertifikat | Bestandscode im TLS-Pfad, eigener Schritt, eigener Test |
| 4 | **Sites lesend**: `SiteList` (verwaltete Drop-ins + fremde vHosts aus `sites-enabled`), Detail, Werkbank | Auf einem Bestandsserver ist die Seite ab hier nicht leer |
| 5 | **Sites schreibend**: Felder → Drop-in, **Site-Prüfer**, `nginx -t`, reload, **Probe mit Rückweg**, Hash-Konflikt | Der gefährlichste Schritt — deshalb erst, wenn alles Lesende steht |
| 6 | **Ziele**: Reverse-Proxy (Container aus dem Docker-Modul zur Auswahl!), statisches Verzeichnis, PHP-FPM über das Paketmodul | Der Alltagsfall |
| 7 | **TLS je Site**: Bezug über ACME, Erneuerung, Zustand je Site, Signale + Warnpunkte | Der Satz aus §2 wird eingelöst |
| 8 | **Härtung und Angriffsdurchgang**: Prüfer aushebeln versuchen, Pfadausbruch über `alias`, Selbstausschluss provozieren; Messung; Doku | Wie Schritt 9 des Docker-Moduls |

Schritt 6 hat eine Zugabe, die es ohne 0.5 nicht gäbe: **Die Zielauswahl kennt
die laufenden Container.** „Reverse-Proxy auf" wird damit eine Liste statt eines
Textfeldes — und die Portübersicht aus 0.5 weiß bereits, welcher Container auf
welchem Port hört.

**Aufwand:** Die Roadmap sagt ~3 Wochen. Mit den Schritten 2 und 3 — beides
Bestandscode im TLS-Pfad, beides nicht in der ursprünglichen Schätzung —
rechne ich mit **4 bis 5 Wochen**. Die Schätzung von 0.5 lag bei 3 und wurde 5;
der Grund war derselbe: Was im Plan ein Halbsatz ist, ist im Bestand ein Schritt.

---

## 10. Rollen, Audit, Signale

**Rollen.** Lesen: jede Rolle. Schreiben: **Owner**. Begründung wie bei Docker
und Cron — eine Site ist eine Konfiguration, die als root gelesen wird und aus
dem Netz erreichbar macht. `tokenFamilien` um `"webserver"` ergänzen.

**Audit.** `webserver.install`, `webserver.site.write`, `webserver.site.remove`,
`webserver.reload`, `webserver.cert.obtain`. Bei Jobs zweimal — „gestartet" und
das Ende über `auditNachtraeglich`.

**Signale** — und hier zahlt sich 0.5.1 aus, weil jedes Signal ohne Zutun einen
Warnpunkt im Menü ergibt:

| Signal | Stufe | Ziel |
|---|---|---|
| Zertifikat einer Site abgelaufen | crit | `/webserver/zertifikate` |
| Zertifikat einer Site < 14 Tage | warn | `/webserver/zertifikate` |
| Konfiguration geschrieben, aber nicht neu geladen | warn | `/webserver` |
| Site zeigt auf einen Container, den es nicht mehr gibt | warn | `/webserver` |
| Webserver installiert, aber nicht gestartet | crit | `/webserver/zustand` |

Alle fünf lesen Zustand, den das Modul ohnehin hält — kein zusätzlicher
Prozessaufruf im Minutentakt. Die Regel aus `docs/17`: Was einen Prozess startet,
gehört hinter einen Zwischenspeicher.

---

## 11. Verifikation

**Go-Tests** (CI-Sperrklinken: `privops ≥ 72 %`, `httpd ≥ 68 %`):

- `internal/privops/webserver_test.go` — Parser gegen **aufgezeichnete echte
  Ausgaben** (`nginx -v`, `nginx -T`, `nginx -t` im Fehlerfall). Und diesmal von
  Anfang an die Frage stellen, die bei Docker offen blieb: **Die Aufzeichnungen
  müssen von einer echten Installation abgenommen werden.**
- `internal/privops/sitepruef_test.go` — je ein Fall pro Ablehnungsgrund, dazu
  die Umgehungsversuche: `alias` statt `root`, relative Pfade, Symlink im
  Zielverzeichnis, Groß-/Kleinschreibung im Domainnamen, doppelter
  `server_name`.
- Für jede Ablehnung: `len(f.calls) != 0` prüfen — es darf kein Kommando
  gelaufen sein.
- `internal/certs/holder_test.go` — SNI-Auswahl, Rückfall, und der Fall, den man
  vergisst: **ein Name, für den es kein Zertifikat gibt.**
- `internal/httpd/api_webserver_test.go` — Rückfrage kommt *und* führt nichts
  aus, falsches getipptes Wort wirkt nicht, Admin bekommt 403 auf
  Schreibrouten, Probe läuft ab und nimmt zurück. Dazu der Fall aus E1:
  **hält ein fremder Prozess Port 80, führt die Installationsroute nichts aus**
  — geprüft über `len(f.calls) != 0`, nicht über den Statuscode.

**Browsertest**: neuer Abschnitt in `leitstand_e2e.js`. Geprüft: Flächenwechsel
über die Unterpunkte, Site anlegen mit allen drei Zieltypen, Prüferbefund steht
mit Feld und Grund da, die Probe zählt herunter und der Rückweg greift, kein
waagerechtes Scrollen bei 375 px.

**Von Hand, auf einem echten Server** — der Teil, den kein Test ersetzt:

```bash
make check && make ui && make build
sudo ./packaging/dev-deploy.sh
```

Durchzuspielen: nginx über das Panel installieren und danach prüfen, **ob die
Erneuerung des Panel-Zertifikats weiter läuft** (Schritt 2 — der Befund aus
Abschnitt 3); eine Site auf einen Container legen; eine Site mit `root: /`
anlegen und ablehnen lassen; eine mit `root: /srv/daten` und die
Stufe-3-Rückfrage sehen; ein kaputtes Drop-in von Hand hinlegen und den
Hash-Konflikt sehen; das Panel hinter den Proxy legen, eine kaputte Site
schreiben und **den Rückweg zusehen lassen**; einen fremden vHost danebenlegen
und prüfen, dass er lesbar, aber nicht schreibbar ist; und auf einer zweiten
Maschine **Caddy oder Apache laufen lassen** und prüfen, dass die Seite den
Server benennt und keinen Installationsknopf zeigt (E1).

**Messung** wie bei jeder Stufe: Binärgröße, RSS im Leerlauf, Abdeckung, direkte
Abhängigkeiten. Erwartung: **keine neue direkte Abhängigkeit** — nginx spricht
man über die Kommandozeile an, und der Rest ist Textbau.

---

## 12. Risiken und offene Punkte

| Risiko | Gegenmaßnahme |
|---|---|
| **Port 80 kollidiert mit nginx**, das Panel-Zertifikat läuft still ab | Abschnitt 3, und zwar als **Schritt 2** — vor der ersten Benutzersite |
| **Der mehrfähige Halter nimmt dem Panel die eigene Oberfläche** | Eigener Schritt, eigene Tests, Rückfall auf das Panel-Zertifikat bei unbekanntem Namen |
| **Ein Schreibpfad sperrt das Panel aus** (Panel hinter dem Proxy) | Probe mit Frist **und** Bereitschaftsprüfung (E4, E5); `asylum` über SSH bleibt der Rettungsanker |
| **Der Site-Prüfer lässt sich umgehen** | Kein Rohtext (E2), geprüft werden die Felder, Angriffsdurchgang in Schritt 8 |
| **Zwei Backends verdoppeln alles** | Nur nginx wird verwaltet; alles andere ist fremd (E1) |
| **Der Installationsknopf killt einen laufenden Webserver** — die einzige Aktion dieses Moduls, die einen Server im Betrieb umbringen kann | `WebServerState` prüft die Portbelegung, nicht den Paketnamen; kein Knopf, solange 80 oder 443 belegt sind (E1, Schritt 1) |
| **Rate Limits von Let's Encrypt** beim Ausprobieren | Das Staging-Verzeichnis ist in der Zertifikatskonfiguration schon vorgesehen (`stagingDirectory`) — für Sites sichtbar machen |
| **Die Stufe wird zur Dauerbaustelle** | Acht Schritte, jeder auslieferbar; nach Schritt 5 ist das Modul inhaltlich vollständig, 6 bis 8 sind Ausbau |

**Vor dem Bau zu entscheiden:**

1. **Ein Zertifikat je Site oder eines mit allen Namen** (Abschnitt 4).
   Empfehlung: je Site.
2. ~~**Caddy schreibend in 0.6 oder später**~~ — **entschieden:** gar nicht.
   Verwaltet wird nginx, jeder andere Webserver gilt als fremd (E1). Der
   Entwurf ist damit nicht schmaler, sondern breiter: Apache und Traefik sind
   mit abgedeckt, und der Installationsknopf hängt an der Portbelegung statt an
   einer Liste bekannter Namen.
3. **Rohtext-Editor für Sites** (E2). Empfehlung: nicht in 0.6 — und wenn doch,
   dann nur für *nicht* verwaltete Dateien, also im Dateimanager, wo er schon
   ist.
4. **Wohin die Panel-Zertifikatsseite gehört**, wenn es Zertifikate je Site gibt.
   Zwei Flächen für dieselbe Sache sind zwei Auslegungen. Empfehlung: Die
   Seite `/zertifikate` bleibt für das Panel; die Sites führen ihre eigenen und
   verweisen aufeinander.
