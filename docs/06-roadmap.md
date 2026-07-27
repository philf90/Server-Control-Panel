# 06 — Roadmap & offene Entscheidungen

## Getroffene Entscheidungen

| Punkt | Entscheidung | Detail |
|---|---|---|
| **Scope** | **A — Server-Administrations-Panel.** Kein Hosting-Panel; vHosts, PHP, Mail und DNS bleiben Nicht-Ziele bzw. spätere optionale Module. | [03-funktionsumfang.md](03-funktionsumfang.md) |
| **Sprache** | Go, statisches Single Binary | [01-sprachwahl.md](01-sprachwahl.md) |
| **Lizenz** | Apache-2.0 (`LICENSE` im Root) | [07-name-lizenz-domain.md](07-name-lizenz-domain.md#lizenz-apache-20) |
| **Name** | **Project Asylum** — CLI `asylum`, Daemon `asylumd` | [07-name-lizenz-domain.md](07-name-lizenz-domain.md#name-project-asylum) |
| **Domain** | `repo.cloudsrv24.de` auf GitHub Pages, ein Host für Installer, Update-Metadaten und APT-Repo | [07-name-lizenz-domain.md](07-name-lizenz-domain.md#domain-repocloudsrv24de) |

## Offene Punkte

Erledigt: DNS samt Domain-Verifizierung und GitHub Pages
([08-runbook-domain.md](08-runbook-domain.md)), Signaturschlüssel als
Repository-Secret `MINISIGN_KEY`.

**Namenskollision gefunden und behoben.** Die offene Prüfung des
Debian-Namensraums hat einen Treffer ergeben: `asylum` ist in Debian und Ubuntu
seit Jahren an ein Spiel vergeben (`universe/games`, „surreal platform shooting
game"), Fassung 0.3.2 — also *höher* als unsere. `apt install asylum` hätte das
Spiel gebracht, auch mit eingebundenem Repository. Das Debian-Paket heißt
deshalb **`asylum-panel`**. Projektname, Daemon (`asylumd`) und Befehl
(`asylum`) bleiben unverändert: `/usr/bin/asylum` steht im `PATH` vor
`/usr/games/asylum`, und da sich die Pfade unterscheiden, streiten sich die
Pakete auch nicht um eine Datei. Geprüft wurde das gegen ein echtes, signiertes
apt-Repository — `apt` löst `asylum-panel` eindeutig auf unser Paket auf.

Ebenfalls erledigt: `main` ist angelegt und als Standardbranch gesetzt, die
Secrets `APT_GPG_KEY` und `APT_GPG_PASSPHRASE` liegen im Repository — das
APT-Repository wird seit 0.1.0-rc.2 signiert veröffentlicht und ist auf einem
echten Server gegen `apt` geprüft.

Offen, keiner davon blockiert die Weiterentwicklung:

- **Marken in Klasse 9/42** noch zu prüfen, dazu Paket-Namensräume und
  GitHub-Organisation — Befehle in
  [07-name-lizenz-domain.md](07-name-lizenz-domain.md#verbleibende-prüfschritte).
- **Copyright-Zeile in `LICENSE`** auf den endgültigen Rechtsträger anpassen.
  Steht derzeit auf „Project Asylum Contributors" — das trägt für ein
  Gemeinschaftsprojekt, wäre für eine Firma aber anzupassen.
- **Externer Sicherheits-Review** der Anmelde- und Updatepfade.
- **Bildschirmfotos auf einem echten Server** neu aufnehmen. Seit rc.2 gibt es
  eine echte Installation, an der das möglich ist.
- **TTL der DNS-Einträge** von 10 auf 3600 anheben, sobald alles steht.
- **Kanal `stable` existiert noch nicht.** Er entsteht mit dem ersten Tag ohne
  Bindestrich. Bis dahin führt `Suites: stable` in ein 404; die Anleitungen
  nennen deshalb `beta`. Siehe [05-updates.md](05-updates.md#apt-repository).

---

## Meilensteine

### M0 — Grundgerüst (1 Woche)
Go-Modul, Repo-Layout, CI (lint/test/build), GoReleaser mit Tarball und `.deb`,
`install.sh` mit Signaturprüfung, systemd-Unit, `asylumd serve` liefert eine leere
Seite über TLS aus. **Ergebnis: der One-Line-Install funktioniert — ohne Features.**

Diese Reihenfolge ist bewusst gewählt: Deployment und Update-Pfad sind die
schwierigsten Teile eines Panels und werden am häufigsten zu spät gebaut. Wer sie
zuerst hat, kann jedes Feature ab Tag eins real ausliefern.

**Stand: umgesetzt.** Gemessen am gebauten Binary:

| Kennzahl | Grenze | Ist |
|---|---|---|
| Binärgröße | < 30 MB | 8,4 MB |
| RSS im Leerlauf | < 40 MB | 10,2 MB |
| Direkte Abhängigkeiten | < 25 | 1 (`gopkg.in/yaml.v3`) |

Verifiziert wurde außerdem: HTTP/2 über TLS 1.3, Erzeugung und Wiederverwendung des
selbstsignierten Zertifikats, Sicherheitsheader, `/healthz` mit Versionsangabe,
geordnetes Herunterfahren auf SIGTERM.

**Signaturkette steht.** Der Public Key liegt in `packaging/minisign.pub` und
eingebettet in `packaging/install.sh`. Geprüft wurde die tatsächlich ausgelieferte
`verify_artifacts`-Funktion gegen ein echtes signiertes Artefakt — sie akzeptiert
die gültige Kette und lehnt manipuliertes Archiv, manipulierte Prüfsummendatei und
fremden Schlüssel jeweils ab. Der Release-Workflow prüft vor dem Bauen, dass der
hinterlegte private Schlüssel zum veröffentlichten Public Key passt; ein
vertauschtes Secret fällt damit im CI auf und nicht erst beim Nutzer.

Bis zum ersten Tag bleibt genau ein Schritt: der private Schlüssel muss als
Repository-Secret `MINISIGN_KEY` hinterlegt werden.

### M1 — Auth & Dashboard (2 Wochen)
SQLite mit Migrationen, Argon2id, TOTP, Sessions, CSRF, RBAC, Audit-Log,
Setup-Token-Flow, Metriken-Sampler mit Ringpuffer, Dashboard mit SSE.

**Stand: umgesetzt.**

| Kennzahl | Grenze | Ist |
|---|---|---|
| Binärgröße | < 30 MB | 13 MB |
| RSS im Leerlauf | < 40 MB | 15,9 MB |
| RSS nach Anmeldungen | < 40 MB | 15,9 MB (siehe [02](02-architektur.md#argon2-und-das-speicherbudget)) |
| Anmeldedauer | — | 39 ms |
| Direkte Abhängigkeiten | < 25 | 5 |

Der vollständige Ablauf wurde gegen die laufende Instanz geprüft: Setup-Token →
Kontoanlage → TOTP-Einrichtung mit QR-Code → Wiederherstellungscodes → Abmelden →
Anmelden mit zweitem Faktor → Live-Metriken über SSE. Der TOTP-Code für die Prüfung
stammte aus einer unabhängigen Python-Implementierung, die Go-Seite hat ihn
akzeptiert; zusätzlich laufen die Testvektoren aus RFC 6238 in der Testsuite.

Zwei Dinge wurden dabei gefunden und behoben:

- **Der Live-Kanal war tot.** Die Logging-Middleware umhüllt den `ResponseWriter`,
  und die Hülle war kein `http.Flusher` — Server-Sent Events liefen deshalb in einen
  Fehler. Behoben über `http.NewResponseController` und eine `Unwrap`-Methode; ein
  Regressionstest läuft nun bewusst durch die vollständige Middleware-Kette, weil
  ein Test direkt gegen den Handler die Lücke nicht gesehen hätte.
- **Die Grundlast lag bei 80 MB** statt der zugesagten 40. Ursache und Lösung stehen
  in [02-architektur.md](02-architektur.md#argon2-und-das-speicherbudget).

**Nachgeliefert (zusammen mit M3):** Der zweite Faktor lässt sich jetzt im
laufenden Betrieb wechseln — mit Rückfrage nach dem aktuellen Passwort, denn
sonst könnte eine übernommene Sitzung den Inhaber mit dessen eigenem
Schutzmechanismus aussperren. Das neue Geheimnis liegt bis zur Bestätigung nur
im Arbeitsspeicher; ein Abbruch ändert nichts. Nach dem Wechsel werden neue
Wiederherstellungscodes ausgegeben und alle anderen Sitzungen beendet.

Ebenfalls nachgeliefert: die Ansicht der eigenen aktiven Sitzungen mit Adresse,
Programm und letzter Aktivität, einzeln oder gesammelt beendbar. Das ist mehr
als Bequemlichkeit — ein entwendetes Sitzungscookie hinterlässt sonst keine
Spur, die dem Betroffenen auffiele.

### M2 — Systemmodule (3 Wochen)
`privops.Executor`, Dienste (systemd/D-Bus), Pakete (apt), Firewall (nftables)
inklusive Lockout-Schutz, Benutzer & SSH-Keys, journald-Logs.

**Stand: umgesetzt.** Der `privops.Executor` ist die einzige Stelle mit
Systemzugriff; alles darüber kennt nur typisierte Operationen. Umgesetzt sind
Dienste, Pakete, Firewall, Systembenutzer samt SSH-Schlüsseln und das Journal.

| Kennzahl | Grenze | Ist |
|---|---|---|
| Binärgröße | < 30 MB | 14 MB |
| Direkte Abhängigkeiten | < 25 | 5 |
| Testabdeckung `privops` | > 72 % (CI-Schwelle) | 75,5 % |

Zwei Abweichungen von der Planung, beide bewusst und in
[02-architektur.md](02-architektur.md#abweichung-von-der-ursprünglichen-planung-systemctl-statt-d-bus)
bzw. [03-funktionsumfang.md](03-funktionsumfang.md) begründet:

- **systemctl statt D-Bus** — keine zusätzliche Abhängigkeit, dieselben Daten,
  und jede Aktion bleibt eine nachvollziehbare Kommandozeile.
- **ufw wird verwaltet, fremde nftables-Regelwerke nur angezeigt** — ein
  automatischer Eingriff in fremde Ketten kann die laufende SSH-Sitzung kappen.

Der Lockout-Schutz ist umgesetzt: Jede Firewall-Änderung gilt zunächst auf
Probe, ohne Bestätigung binnen 60 Sekunden stellt der Server den vorherigen
Stand wieder her — serverseitig, unabhängig davon, ob der Browser noch offen ist.

Gegen ein laufendes System geprüft wurden Paketliste (echtes `apt-get
--simulate`), Systembenutzer (echtes `/etc/passwd`), Firewall-Erkennung und die
Fehlerbehandlung bei fehlendem systemd. Die systemd- und journald-Pfade selbst
konnten in der Entwicklungsumgebung nicht gegen ein laufendes systemd geprüft
werden — dort greifen Parsertests gegen aufgezeichnete Ausgaben und
Operationstests gegen einen einspeisbaren Runner.

### M3 — Update-Mechanik (1 Woche)
Kanäle, Metadatenformat, Selbstupdate mit Healthcheck und Rollback,
`asylum update` / `asylum rollback`, APT-Repository-Job in der Pipeline.

**Stand: umgesetzt.** Einzelheiten in [05-updates.md](05-updates.md).

| Kennzahl | Grenze | Ist |
|---|---|---|
| Binärgröße | < 30 MB | 14 MB |
| Direkte Abhängigkeiten | < 25 | 5 (BLAKE2b kommt aus `x/crypto`, das für Argon2 ohnehin dabei war) |
| Testabdeckung `update` | > 85 % (CI-Schwelle) | 87,8 % |

Die Signaturprüfung ist in Go umgesetzt und ruft kein externes `minisign` auf.
Geprüft wurde sie gegen echtes minisign-Material im Repository
(`internal/update/testdata`), nicht nur gegen die eigene Vorstellung vom Format.

**Der vollständige Weg wurde gegen laufende Prozesse geprüft**, nicht nur im
Test: zwei echte Binaries (0.1.0 und 0.2.0), mit dem echten Projektschlüssel
signiert, über HTTPS mit Zertifikatsprüfung geladen, ausgetauscht, neu
gestartet, per `/healthz` bestätigt — und anschließend wieder zurückgerollt.
Ebenso geprüft wurden vier Angriffe: ausgetauschtes Archiv, manipulierte
Prüfsummenliste, fremd signierte Liste und Metadaten, die eine höhere Fassung
behaupten als die Signatur beglaubigt. Alle vier wurden abgelehnt, ohne dass
etwas auf der Platte angefasst wurde.

Der wichtigste Fall zuletzt: eine **echt signierte, aber kaputte** Fassung, die
sich installieren lässt und dann nicht startet. Nach 60 Sekunden ohne Antwort
auf `/healthz` hat der Server von allein die vorherige Fassung zurückgespielt
und neu gestartet.

Drei Dinge kamen gegenüber der Planung hinzu oder wurden anders entschieden:

- **Der Vorgang läuft in einer eigenen systemd-Unit**, nicht im Panel. systemd
  beendet beim Neustart die gesamte Kontrollgruppe — ein Update darin würde
  genau zwischen Tausch und Bereitschaftsprüfung abgeschnitten. `asylum update`
  weigert sich deshalb, in der Kontrollgruppe des Dienstes zu laufen.
- **Ein Datenbankabzug vor dem Tausch.** Migrationen laufen nur vorwärts; ein
  Rollback des Binaries allein ließe eine ältere Fassung auf ein neueres Schema
  treffen. Eingespielt wird der Abzug nur vom selbsttätigen Rückweg.
- **cosign entfällt.** Begründung in
  [05-updates.md](05-updates.md#release-pipeline).

Nicht geprüft werden konnte, was ohne systemd als PID 1 nicht prüfbar ist: der
Aufruf über `systemd-run` bricht in dieser Umgebung an der fehlenden
Bus-Verbindung ab. Die Argumentliste akzeptiert das echte `systemd-run`; der
Fehler wird sauber bis in die Oberfläche durchgereicht.

### M4 — v0.1.0 Public Beta (1 Woche)
Dokumentation, Screenshots, Landingpage, `SECURITY.md`, Issue-Templates,
Contribution-Guide, externer Sicherheits-Review der Auth- und Update-Pfade.

**Stand: umgesetzt bis auf den externen Review.** Neu im Wurzelverzeichnis:
[`SECURITY.md`](../SECURITY.md), [`CONTRIBUTING.md`](../CONTRIBUTING.md),
[`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md), [`CHANGELOG.md`](../CHANGELOG.md),
[`UPGRADING.md`](../UPGRADING.md); dazu Issue- und PR-Vorlagen und eine
ausgebaute Landingpage mit beiden Signaturschlüsseln.

**Die Bildschirmfotos entstanden durch einen echten Browser**, der die
Ersteinrichtung vollständig durchlaufen hat — Konto anlegen, TOTP einrichten,
anmelden. Sie liegen unter [`docs/bilder/`](bilder/). Einschränkung: aufgenommen
in einem Container ohne systemd, weshalb Dienste, Pakete und Firewall dort ihre
Fehlerbehandlung zeigen statt echter Daten. Vor der Veröffentlichung sollten sie
auf einem echten Server neu entstehen. Für `rc.4` wurden sie erneuert, weil die
Navigation andere Namen trägt und das schmale Layout dazugekommen ist — die
Einschränkung gilt unverändert.

**Zwei Fehler kamen dabei ans Licht**, beide behoben und mit Regressionstests
versehen:

- **Die Übersicht zeigte nach jedem Start eine halbe Minute lang „keine
  Daten".** Sie rendert aus dem Ringpuffer, und der bekommt nur alle 30 Sekunden
  einen Eintrag. Betraf jede frische Installation und jeden Neustart nach einem
  Update — also genau den ersten Eindruck. Jetzt aus der jüngsten Messung.
- **TOTP-Codes galten mehrfach.** Gefunden bei der Durchsicht der Anmeldepfade;
  Einzelheiten in [09-sicherheitsbetrachtung.md](09-sicherheitsbetrachtung.md).

**Der externe Sicherheits-Review steht aus** und lässt sich nicht projektintern
ersetzen. Was es gibt, ist eine Selbstbetrachtung der Anmelde- und Updatepfade
mit Angreifermodell, Abwägungen und offenen Punkten
([09-sicherheitsbetrachtung.md](09-sicherheitsbetrachtung.md)) — vom selben
Kreis geschrieben, der den Code geschrieben hat, und damit kein Ersatz für
fremde Augen. `SECURITY.md` sagt das ausdrücklich, statt es zu verschweigen.

### Freigabekandidaten

Der Weg zu 0.1.0 läuft über Vorabversionen im Kanal `beta`. Jede hat Fehler
zutage gefördert, die kein Test gefunden hatte — die späteren fast alle erst im
Betrieb auf einem echten Server. Genau das ist ihr Zweck.

| Fassung | Gefunden | Behoben in |
|---|---|---|
| `0.1.0-rc.1` | Die Ersetzung der Debian-Version (`0.1.0-rc.1` → `0.1.0~rc.1`) war nie in der Datei angekommen. | `rc.2` |
| `0.1.0-rc.2` | `min_upgradable_from` stand fest auf `0.1.0` und sperrte damit jeden Beta-Tester aus: Nach SemVer ist die Freigabe neuer als ihre Vorabversionen. | `rc.3` |
| `0.1.0-rc.2` | Die apt-Anleitung nannte `Suites: stable` — ein Kanal, den es noch nicht gab. | `rc.3` |
| `0.1.0-rc.2` | Der Link zur Ersteinrichtung nannte den kurzen Rechnernamen ohne Domainendung und war von außen unbrauchbar; der vollqualifizierte Name fehlte zudem im Zertifikat. | `rc.3` |
| `0.1.0-rc.3` | Kein einziger Breakpoint im Stylesheet — auf dem Telefon bricht die Navigation in vier Zeilen um und Tabellen laufen aus dem Rand. | `rc.4` |
| `0.1.0-rc.3` | ufw wird als inaktiv angezeigt, lässt sich aus dem Panel aber weder installieren noch aktivieren. | `rc.4` |
| `0.1.0-rc.3` | Drei fast gleichlautende Menüpunkte (`Konten`, `Benutzer`, `Konto`) — die SSH-Schlüsselverwaltung galt deshalb im eigenen Projekt als fehlend. | `rc.4` |
| `0.1.0-rc.4` | ufw ließ sich nicht einschalten: `ufw status` gibt im ausgeschalteten Zustand keine Regeln aus, das Panel sah seine eigene Portregel nie und verweigerte den Start — der Knopf erschien nie. Ausgeschaltet wird jetzt `ufw show added` gelesen. | `rc.5` |
| `0.1.0-rc.4` | Die Karten der Übersicht waren verschieden breit; eine IPv6-Adresse zog eine `1fr`-Spur auf. Behoben mit `minmax(0, 1fr)`. | `rc.5` |
| `0.1.0-rc.5` | Die Auslastungsbalken funktionierten nie: ihre Breite kam aus einem `style`-Attribut, das die CSP verwirft — die Dateisystembalken standen dauerhaft auf 100 %. Jetzt ein `<progress>` mit dem Wert im Attribut. | `rc.6` |
| `0.1.0-rc.5` | Durchsatzwerte unter 1 KiB standen ungerundet da (`385.76… B/s`): Die Go-Seite schnitt beim Wandeln nach `uint64` ab, die Browserseite nicht. | `rc.6` |
| `0.1.0-rc.6` | Such- und Filterfelder unter „Dienste" und „Logs" waren ungestylt — `input type="search"` fehlte in der CSS-Regel, der Browser zeichnete sie selbst; dazu klebte „Nach Updates suchen" ohne Abstand an der Bezugsquelle. | `rc.7` |

Die ersten beiden Fehler traten erst auf, als ein Tag gesetzt war. Deshalb läuft
der betroffene Schritt seit `rc.3` bei jedem CI-Lauf probeweise gegen eine
Attrappe (`packaging/release-dry-run.sh`).

Die Befunde ab `rc.3` stammen aus dem Betrieb auf einem echten, öffentlich
erreichbaren Server — häufig vom Telefon aus bedient. Fast alle waren in der
Entwicklungsumgebung unsichtbar: Am Schreibtisch fällt ein fehlender Breakpoint
nicht auf, ein Container ohne systemd zeigt statt einer inaktiven Firewall ihre
Fehlerbehandlung, und ein Auslastungsbalken, den `live.js` nachzieht, sieht
richtig aus, obwohl sein Wert nie ankam. Für die Messung solcher Fälle schreibt
`TestDumpSeiten` seit `rc.6` vollständige Seiten mit Beispieldaten heraus.

`rc.7` trägt neben dem Fehler oben einen bewussten Umbau: Die Navigation ist
jetzt eine nach System, Sicherheit und Betrieb gruppierte Seitenleiste statt
einer waagerechten Leiste mit zehn gleichrangigen Punkten. Kein Betriebsbefund,
sondern Vorsorge — zehn Punkte in einer Zeile waren schon knapp, die für v0.2
geplanten Module würden sie sprengen.

**Für 0.1.0 offen:** ein Freigabekandidat, der bei einem unbeteiligten Tester
von der Installation bis zur Anmeldung ohne Eingriff durchläuft. Erst der Tag
ohne Bindestrich legt den Kanal `stable` an.

---

## Umsetzungsplan bis 0.3.0

### rc.4 — Befunde aus der echten Installation

**Stand: umgesetzt.** Kein neues Feature; nur das, was der Betrieb auf einem
echten Server gezeigt hat.

Geprüft wurde gegen einen echten Browser bei 375, 414, 768 und 1280 Pixeln auf
allen zehn Seiten: Der Seitenkörper darf nicht waagerecht scrollen, und die
Navigation muss schmal eingeklappt und breit ausgeklappt sein. Derselbe Prüfer
lief gegen den Stand davor und meldete dort, was zu erwarten war — eine Tabelle,
die im 375-Pixel-Fenster bis 631 Pixel reicht.

Zwei Dinge fielen dabei zusätzlich auf und sind mit behoben:

- **Der Live-Kanal hätte die Beschriftungen wieder weggeworfen.** Übersicht und
  Prozessliste bauen ihre Zellen alle 30 Sekunden neu; ohne Anpassung wären die
  Karten eine halbe Minute nach dem Seitenaufruf wieder namenlos gewesen. Die
  Namen kommen jetzt aus der Kopfzeile derselben Tabelle statt aus einer zweiten
  Liste im Skript.
- **`<details>` trägt hier nicht.** Der naheliegende Weg für ein einklappbares
  Menü scheitert daran, dass ein geschlossenes `<details>` seine Kinder so
  versteckt, dass CSS das nicht wieder aufheben kann — auf breiten Bildschirmen
  wäre das Menü verschwunden. In Chromium nachgemessen, beide Varianten;
  umgesetzt ist die mit einer Checkbox, ohne JavaScript.

**1. Responsives Layout.** `internal/ui/static/app.css` hat 399 Zeilen und genau
einen `@media`-Block — für Dark Mode. Es gibt keinen einzigen Breakpoint. Das
`viewport`-Meta ist korrekt gesetzt, das Layout dahinter nicht.

- Breakpoints bei ~600 px und ~900 px; die Topbar unterhalb davon als
  eingeklapptes Menü statt als umbrechende Liste.
- Tabellen unter 600 px als Karten, eine je Zeile, Spaltenname als Label.
  `overflow-x: auto` allein genügt nicht: Eine seitlich scrollende Tabelle ist
  bedienbar, aber man sieht ihr nicht an, dass rechts noch etwas steht.
- Zahlenspalten (`0,5 %`, `5,5 GiB`) gegen Umbruch schützen.
- Dienste- und Paketlisten mit kompakteren Zeilen; Suche und Filter oben
  festhalten. 159 Units bei zehn sichtbaren Zeilen sind keine Liste, sondern ein
  Scrollband.
- Nachweis über einen echten Browser bei 375, 414, 768 und 1280 px.

**2. Firewall: Zustand erkennen *und* handeln können.** Heute unterscheidet
`privops.FirewallState` drei Fälle, aber zu keinem gibt es eine Handlung.

- Installation über `dpkg-query` feststellen, statt sie aus dem Fehlschlag des
  Aufrufs zu erschließen.
- Fehlt das Paket: Installation über das vorhandene Paketmodul anbieten, statt
  eine Kommandozeile zum Abtippen zu drucken.
- Ist ufw installiert und inaktiv: **Aktivieren.** Bisher lässt das Panel den
  Regelsatz speichern und schreibt daneben, dass er nicht greift — die
  schlechteste der möglichen Antworten.
- **Sicherheitskritisch:** `ufw enable` bei voreingestelltem `deny incoming`
  ohne SSH-Regel sperrt den Bedienenden sofort aus. Die Aktivierung läuft
  deshalb durch dieselbe 60-Sekunden-Probe mit selbsttätigem Rückweg wie jede
  Regeländerung und verweigert sich, solange weder SSH- noch Panel-Port
  freigegeben sind.

**3. Navigation entwirren.** Drei fast gleichlautende Einträge nebeneinander —
`Konten` (Systembenutzer), `Benutzer` (Panel-Zugänge), `Konto` (eigenes Profil).
Wie gut das trägt, zeigt der Umstand, dass die SSH-Schlüsselverwaltung *im
eigenen Projekt* für fehlend gehalten wurde: Sie liegt vollständig unter
„Konten" — Liste mit Fingerprint und Bitlänge, Hinzufügen, Entfernen, je
Systembenutzer.

**4. Instanzname in der Kopfzeile.** Zeigt den kurzen Rechnernamen, weil dort
noch `os.Hostname()` steht. Seit `rc.3` gibt es `netinfo.FQDN()`.

### 0.1.0 — Freigabe

Kein Code, sondern das, was ein erstes öffentliches Release ausmacht:
Bildschirmfotos auf einem echten Server (nach rc.4, damit sie das neue Layout
zeigen), DNS-TTL von 10 auf 3600, Copyright-Zeile in `LICENSE` auf den
endgültigen Rechtsträger, Tag ohne Bindestrich.

### 0.2.0 — Let's Encrypt

Der größte Einzelgewinn an Vertrauenswürdigkeit. Ein Panel, dessen Nutzer bei
jedem Aufruf eine TLS-Warnung wegklicken, gewöhnt ihnen genau das ab, was es
schützen soll.

- ACME über `golang.org/x/crypto/acme` — kein neuer schwerer Baustein,
  `x/crypto` ist wegen Argon2 ohnehin dabei.
- **HTTP-01 braucht Port 80.** Bei einem Panel auf 8443 ist das ein Eingriff:
  ein kurzzeitiger Listener und eine Firewall-Regel. DNS-01 wäre sauberer,
  verlangt aber Zugangsdaten zum DNS-Anbieter — das ist eine zweite
  Ausbaustufe, nicht die erste.
- Erneuerung als Zeitgeber im Daemon, mit **Rückfall auf das selbstsignierte
  Zertifikat**. Ein Panel, das wegen einer gescheiterten ACME-Anfrage nicht mehr
  startet, ist schlimmer als eines mit Warnung.
- Die Rate-Limits von Let's Encrypt beachten: Bei Fehlversuchen nicht in einer
  Schleife anfragen.
- Voraussetzung ist ein auflösender Name. Den ermittelt seit `rc.3`
  `internal/netinfo`.

### 0.3.0 — Passkeys

Zuerst **zusätzlich zu**, nicht anstelle von Passwort und TOTP.

- Ein Passkey ist gerätegebundener Besitz. Fällt das Gerät aus, ist der Zugang
  weg. Es gibt `asylum reset-password` über SSH als Rettungsanker — der setzt
  aber SSH-Zugang voraus, und den soll das Panel gerade entbehrlich machen.
- WebAuthn ist mehr als eine eingebundene Bibliothek: Registrierung, Assertion,
  Speicherung der Credential-IDs, mehrere Schlüssel je Konto, Zusammenspiel mit
  den bestehenden Wiederherstellungscodes, und die Entscheidung, ob ein Passkey
  den zweiten Faktor ersetzt oder Passwort *und* Faktor.
- Reihenfolge: erst als zusätzlicher zweiter Faktor neben TOTP, dann — wenn sich
  das im Betrieb bewährt — als vollständiger Ersatz mit ausdrücklicher
  Zustimmung.

### Laufend, an keine Fassung gebunden

Externer Sicherheits-Review (sinnvollerweise **nach** 0.2.0, weil Let's Encrypt
und Passkeys die Anmeldepfade nochmals anfassen — sonst wird zweimal geprüft),
Marken in Klasse 9/42, Paket-Namensräume, GitHub-Organisation.

### Aufwand

| Phase | Inhalt | Aufwand |
|---|---|---|
| `rc.4` | Responsives Layout, ufw-Handlung, Navigation, Instanzname | ~1,5 Wochen |
| `0.1.0` | Bildschirmfotos, TTL, `LICENSE`, Tag | ~2 Tage |
| `0.2.0` | Let's Encrypt | ~1,5–2 Wochen |
| `0.3.0` | Passkeys | ~2 Wochen |

Zwei Abwägungen, die bewusst so und nicht anders getroffen sind:

**Das responsive Layout gehört vor 0.1.0.** Ein Server-Panel wird vom Telefon
aus bedient — genau dann, wenn etwas kaputt ist und niemand am Schreibtisch
sitzt. Es ist außerdem das Erste, was jeder Besucher sieht.

**Let's Encrypt gehört nicht in 0.1.0.** Es ist der wertvollste nächste Schritt,
aber eine Beta darf mit selbstsigniertem Zertifikat leben, solange
[`SECURITY.md`](../SECURITY.md) den Fingerprint-Abgleich beschreibt. Es
vorzuziehen verschöbe die Freigabe um zwei Wochen und vergrößerte die Fläche,
die noch niemand von außen geprüft hat.

**Summe bis zur nutzbaren Beta: ~8 Wochen** für eine Vollzeit-Person, entsprechend
länger nebenberuflich. Danach v0.2 (Dateimanager, Cron, Terminal,
Benachrichtigungen) und v0.3 (Module).

## Qualitätsziele als harte Grenzen

Diese Werte gehören in die CI, nicht in ein Wiki:

| Metrik | Grenze |
|---|---|
| RSS im Leerlauf | < 40 MB |
| CPU im Leerlauf | < 0,5 % auf 1 vCPU |
| Binärgröße | < 30 MB |
| Kaltstart bis Ready | < 1 s |
| Installationsdauer | < 30 s auf einem 1-vCPU-VPS |
| Direkte Go-Abhängigkeiten | < 25 |
| Testabdeckung `auth` | > 85 % |
| Testabdeckung `update` | > 85 % |
| Testabdeckung `privops`, `store`, `certs`, `config` | > 72–82 %, je Paket |
| Testabdeckung `httpd` | > 63 % |

Ein Benchmark-Job misst RSS und Binärgröße bei jedem Release und lässt den Build
fehlschlagen, wenn eine Grenze gerissen wird. Ohne diesen Zwang wird aus "schlank"
innerhalb eines Jahres ein Marketingbegriff.

Zur Abdeckung: Gemessen wird die **Statement-Abdeckung** aus dem Testlauf, nicht
der Mittelwert über Funktionen — letzterer gewichtet eine dreizeilige Funktion so
stark wie eine dreißigzeilige und ist damit leicht zu schönen. Die Schwellen liegen
bewusst knapp unter dem jeweils erreichten Stand: Sie sind eine Sperrklinke gegen
Rückschritt, keine runden Wunschzahlen. `httpd` liegt niedriger, weil das Paket zu
großen Teilen aus Anzeigelogik besteht; die sicherheitsrelevanten Pfade darin
(Sitzung, CSRF, Rollen) sind eigens getestet.

## Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Aussperrung durch Firewall-/SSH-Änderung | 60-Sekunden-Bestätigung mit automatischem Rollback, `scp` CLI als lokaler Rettungsweg |
| Panel als Angriffsziel im offenen Netz | 2FA-Pflicht, Rate-Limiting, Empfehlung zu Bind auf localhost/WireGuard, signierte Updates, schlanke Angriffsfläche |
| Feature-Creep Richtung Hosting-Panel | Nicht-Ziele dokumentiert, Modulgrenze, harte Ressourcenbudgets in der CI |
| Konflikt mit manueller Serverkonfiguration | Managed-Marker, Drop-in-Dateien, Konflikterkennung per Hash, Backups vor jedem Schreibvorgang |
| Ein-Personen-Projekt versandet | Früh Beta veröffentlichen, kleiner Kern, saubere Modulschnittstelle für Beiträge |
