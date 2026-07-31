# 06 — Roadmap & offene Entscheidungen

## Stand: 0.4.1

Dieses Dokument hat zwei Teile, und sie sind verschieden alt.

**Rückblick (M0–M4, die Fassungen bis 0.3).** Der untere Teil ist das
Bau- und Befundprotokoll des ersten Vorhabens — was gebaut wurde, was der
Betrieb auf einem echten Server gefunden hat, welche Grenze wo gemessen wurde.
Er wird nicht fortgeschrieben, sondern bleibt als Beleg stehen.

**Ausblick (ab 0.4).** Die Planung ab 0.4 kommt aus
[16-neukonzeption.md](16-neukonzeption.md) — sie revidiert Scope und
Fassungsschnitt und ist dort maßgeblich. Der Abschnitt
[Meilensteine ab 0.4](#meilensteine-ab-04) unten gibt sie wieder, damit dieses
Dokument nicht auf einem Stand stehenbleibt, den es nicht mehr gibt.

Zwei Dinge, die den Rückblick lesbar halten:

- **Die Fassungen 0.1.0, 0.2.0 und 0.3.0 sind nie erschienen.** Sie existieren
  ausschließlich als Vorabversionen (`v0.1.0-rc.1` bis `v0.3.0-rc.6`). Der erste
  Tag ohne Bindestrich war **`v0.4.0`**; damit ist auch der Kanal `stable`
  entstanden. Wo unten „für 0.1.0 offen" steht, ist das Wortlaut von damals —
  erledigt hat es sich dadurch, dass die Neukonzeption den Freigabepunkt auf 1.0
  verschoben hat.
- **Die alte, server-gerenderte Oberfläche gibt es nicht mehr.** Sie war ab dem
  Beschluss der Neukonzeption eingefroren (keine Gestaltung, keine Funktion, nur
  Sicherheitsfehler), lag mit 0.4.0 eine Fassung lang unter `/alt/` als Rückweg
  und ist mit **0.4.1** entfernt. Bildschirmfotos, Seitenzahlen und
  Vorlagennamen im Rückblick beziehen sich auf sie.

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

Erledigt seit `v0.4.0`: **Der Kanal `stable` existiert.** Er entsteht mit dem
ersten Tag ohne Bindestrich, und das war 0.4.0 — `Suites: stable` führt seither
nicht mehr in ein 404. Die Anleitungen in
[05-updates.md](05-updates.md#apt-repository) beschreiben stellenweise noch die
Beta-Phase, in der es nur `beta` gab.

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
richtig aus, obwohl sein Wert nie ankam. Für die Messung solcher Fälle schrieb
`TestDumpSeiten` ab `rc.6` vollständige Seiten mit Beispieldaten heraus; er hing
an den Vorlagen der alten Oberfläche und ist mit ihr entfallen (0.4.1). Was er
leistete, leisten jetzt die Browsersuiten: Sie fahren die echte Fläche und legen
auf Wunsch Bildschirmfotos ab (`ASYLUM_E2E_SHOTS`).

`rc.7` trägt neben dem Fehler oben einen bewussten Umbau: Die Navigation ist
jetzt eine nach System, Sicherheit und Betrieb gruppierte Seitenleiste statt
einer waagerechten Leiste mit zehn gleichrangigen Punkten. Kein Betriebsbefund,
sondern Vorsorge — zehn Punkte in einer Zeile waren schon knapp, die für v0.2
geplanten Module würden sie sprengen.

**Wie es weiterging.** Die Freigabe 0.1.0 kam nie: Die Reihe lief über 0.2.0
und 0.3.0 als Vorabversionen weiter, und mit der Neukonzeption wurde der
Freigabepunkt auf **1.0** verschoben. Der erste Tag ohne Bindestrich war
`v0.4.0` — die Fassung, mit der die neue Oberfläche an die Wurzel kam. Was hier
als offen stand, gilt unverändert, nur für eine andere Zahl: ein
Freigabekandidat, der bei einem unbeteiligten Tester von der Installation bis
zur Anmeldung ohne Eingriff durchläuft.

---

## Umsetzungsplan bis 0.3.0

*Abgeschlossen. Alles unter dieser Überschrift ist gebaut und ausgeliefert; die
Planung ab 0.4 steht weiter unten unter [Meilensteine ab 0.4](#meilensteine-ab-04).*

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

**Stand: umgesetzt bis auf die Prüfung gegen einen echten CA.** Einzelheiten in
[10-tls-acme.md](10-tls-acme.md). Gebaut wurde:

- ACME über `golang.org/x/crypto/acme` — kein neuer schwerer Baustein,
  `x/crypto` ist wegen Argon2 ohnehin dabei.
- Ein Zertifikatshalter, der das Zertifikat zur Laufzeit tauscht: Erneuerung
  ohne Neustart.
- Erneuerung als Zeitgeber im Daemon (~30 Tage vor Ablauf), mit **Rückfall auf
  das selbstsignierte Zertifikat** — ein Panel, das wegen einer gescheiterten
  ACME-Anfrage nicht mehr startet, ist schlimmer als eines mit Warnung. Nach
  einem Fehlversuch wird nicht in einer Schleife angefragt (Rate-Limits).
- **HTTP-01 und DNS-01.** Anders als zunächst geplant kam DNS-01 gleich dazu:
  Läuft auf Port 80 schon ein Webserver, ist HTTP-01 nicht nutzbar. DNS-01 gibt
  es über einen **Hook** (Betreiber-Skript, kein Anbieter im Binary) und
  eingebaut über **Cloudflare** (reines HTTP, Token aus einer Datei). Ist ein
  DNS-Anbieter gesetzt, wird automatisch DNS-01 gewählt.
- Voraussetzung ist ein auflösender Name. Den ermittelt seit `rc.3`
  `internal/netinfo`.
- Anzeige über die Seite **Zertifikat** und `asylum cert status`.

Offen: die Prüfung des Live-Wegs gegen das Let's-Encrypt-**Staging** auf einem
echten Server (ohne öffentlichen Namen in der Entwicklungsumgebung nicht
möglich) und das gefahrlose kurzzeitige Öffnen von Port 80 für HTTP-01, das eine
eigene Firewall-Primitive braucht.

### 0.2.0 — Übersicht als Leitstand

**Stand: umgesetzt.** Kein neues Systemmodul, sondern eine Neufassung der
meistbesuchten Seite. Der Anlass war eine Rückmeldung, kein Testbefund: Die
Übersicht wirkte „fad und wenig aufschlussreich" — ein Gitter gleichrangiger
Kacheln (CPU, Speicher, Last, Laufzeit), aus dem der Betrachter selbst
herauslesen musste, ob dem Server etwas fehlt.

Jetzt führt die Seite ein Urteil in einem Satz: Läuft alles normal, oder
brauchen *n* Dinge Aufmerksamkeit? Darunter steht ein Handlungsbedarf-Block, der
nur erscheint, wenn es etwas zu tun gibt — fehlgeschlagene Dienste, knapper
Plattenplatz (ab 85 %, kritisch ab 95 %), ein ausstehender Neustart —, jeweils
mit einem Link auf die zuständige Seite. Erst danach folgt die Telemetrie: CPU,
Arbeitsspeicher, Last und Netz je als Kachel mit dem Verlauf der letzten
Stunden, dazu Dateisysteme und die größten Prozesse.

Zwei Entscheidungen dabei, beide der Architektur des Panels geschuldet:

- **Die Verläufe zeichnet der Server**, nicht der Browser. Die CSP verbietet
  Inline-Skripte; die Sparklines sind fertige SVG-Pfade aus dem Ringpuffer. Die
  großen Zahlen tragen weiter `data-live` und werden vom SSE-Kanal nachgezogen.
- **Der Handlungsbedarf kommt ohne Schreibpfad aus.** Seine Aktionen sind bloße
  Links — von der Übersicht aus wird nichts geändert, deshalb braucht die Seite
  weder CSRF noch einen Schreibendpunkt. Die Signale werden mit kurzem Timeout
  (3 s) aus günstigen Quellen gesammelt; ein hängendes `systemctl` darf die
  meistbesuchte Seite nicht blockieren, dann fehlt eben ein Signal.

Die Bildschirmfotos unter [`docs/bilder/`](bilder/) sind für die Übersicht neu
entstanden; die Verläufe stammen dort aus einem gestellten Ringpuffer, wie der
übrige Snapshot.

**Nachgezogen nach dem Betrieb.** Drei Befunde, alle von der Seite selbst:

- **Die Verläufe liefen aus.** Der viewBox ist 100 Einheiten breit und wird mit
  `preserveAspectRatio="none"` auf die Kachelbreite gezogen — waagerecht mit
  Faktor 2,7, senkrecht mit 1. Die Strichstärke wurde mitgezogen: Steile Stücke
  waren über 4 Pixel breit, flache blieben bei 1,6, und der Endpunkt kam als
  liegende Ellipse heraus. Jetzt `vector-effect: non-scaling-stroke`, und der
  Endpunkt ist ein Segment der Länge null mit runder Kappe. Dazu werden die bis
  zu 2880 Messungen des Ringpuffers auf 60 Stützstellen gemittelt — zehn Punkte
  je Pixel ergeben keinen Verlauf, sondern ein Band — und die Skalierung hat
  eine Mindestspanne, damit ein ruhiger Server ruhig aussieht statt wie ein
  Gebirge.
- **Die Netzwerkkachel zeigte `docker0`.** Sie nahm die erste Schnittstelle der
  alphabetisch sortierten Liste; auf jedem Server mit Docker ist das die Brücke,
  über die nach draußen nichts geht. Die Kachel stand dauerhaft auf 0 B/s, und
  der Name daneben machte die falsche Angabe glaubwürdig. Gewählt wird jetzt die
  Schnittstelle mit der Standardroute (`/proc/net/route`,
  `/proc/net/ipv6_route`), nachrangig eine mit einem Gerät hinter sich
  (`/sys/class/net/<name>/device`). Eine Brücke oder ein Bündel darf gewinnen,
  wenn der Verkehr dort hinausgeht — auf einem Hypervisor ist `br0` die richtige
  Antwort. Der Netzverlauf zählt dieselbe Schnittstelle statt der Summe über
  alle.
- **Die weiteren Einhängepunkte einer Platte gab es nur als `title`-Attribut.**
  Ein Kasten, der nach einer Sekunde erscheint, keine Zahlen tragen kann und auf
  einem Telefon gar nicht. Sie sind jetzt eigene Zeilen der Liste, eingeklappt,
  mit den Zahlen des Dateisystems, an dem sie hängen. Der Umschalter ist eine
  Checkbox und kein Knopf mit Skript — dieselbe Entscheidung wie beim Menü.

Neu ist außerdem, dass sich die Verläufe ablesen lassen: Der Zeiger zeigt Wert
und Uhrzeit der Messung unter ihm. Die Messpunkte stehen fertig formatiert in
einem `data`-Attribut, das Skript sucht nur den nächsten — gerechnet wird
weiterhin auf dem Server, und ohne das Skript bleibt der Verlauf zu sehen.

Nachgewiesen ist das im echten Browser (`TestUebersichtBrowser`, hinter
`ASYLUM_UEBERSICHT_E2E`): Der Treiber vermisst den gemalten Endpunkt aus einem
Bildschirmfoto — 4 × 4 Pixel rund, vorher 16 × 10 —, führt den Zeiger über die
Kachel, klappt die Dateisystemliste auf und liest mit, ob die Richtlinie etwas
verworfen hat. Zwei der drei Punkte kann kein Go-Test beantworten: Ob ein
Segment der Länge null überhaupt einen Punkt malt und ob `:has()` greift, sagt
nur der Browser.

### 0.3.0 — Passkeys

Zuerst **zusätzlich zu**, nicht anstelle von Passwort und TOTP.

**Stand: umgesetzt (additiv), bis auf den externen Review.** Einzelheiten in
[11-passkeys.md](11-passkeys.md). Gebaut wurde in vier Schritten:

- **Fundament** — Tabelle `webauthn_credentials`, Store-Methoden und ein Adapter
  um `github.com/go-webauthn/webauthn` (die einzige neue direkte Abhängigkeit,
  6 statt 5). Die Krypto ist bewusst nicht selbst geschrieben; das Panel liefert
  Benutzertyp, Persistenz und den kurzlebigen Challenge-Speicher.
- **Registrierung** im Konto: hinzufügen, umbenennen, entfernen. Das Anlegen
  verlangt das aktuelle Passwort, damit eine übernommene Sitzung nicht unbemerkt
  einen dauerhaften Schlüssel hinterlegt.
- **Anmeldung** in zwei Schritten (Passwort → Assertion), mit einem
  kurzlebigen Vorab-Cookie für den Zwischenstand. Der bisherige Login mit
  Passwort und Code bleibt unangetastet — der Rückweg ohne JavaScript und für
  Konten ohne Passkey.
- **Grenzfälle und Rettung** — `asylum passkey list|remove` über SSH,
  Klon-Erkennung (vermerkt, sperrt aber nicht), Audit-Einträge, Dokumentation.

Der vollständige Durchlauf ist mit einem echten Browser und virtuellem
Authenticator geprüft (`TestPasskeyBrowserKonto`): registrieren, umbenennen,
abmelden, mit Passkey anmelden, entfernen. Voraussetzung im Betrieb ist ein auflösbarer Hostname als
RP-ID — über eine IP funktioniert WebAuthn nicht.

- Reihenfolge: erst als zusätzlicher zweiter Faktor neben TOTP (jetzt), dann —
  wenn sich das im Betrieb bewährt — als vollständiger Ersatz mit ausdrücklicher
  Zustimmung. Der Ersatz ist noch offen: Ein Passkey ist gerätegebundener
  Besitz; fällt das Gerät aus, muss ein Rückweg bleiben. Heute ist das TOTP,
  dazu `asylum reset-password` über SSH.

### 0.4.0 — Zugang zurücksetzen

Der Umkehrschluss aus dem Passkey-Kapitel: Wenn ein Passkey ein Nachweis ist, der
Phishing widersteht, taugt er auch dort, wo bisher nur SSH half. Zwei Wege, beide
eng gefasst, Einzelheiten in
[12-zugang-zuruecksetzen.md](12-zugang-zuruecksetzen.md):

- **Der Owner setzt einen fremden Zugang zurück** — Passwort (als Einmalpasswort
  mit Wechselzwang), zweiter Faktor oder Passkeys, einzeln auslösbar. Verlangt
  das eigene Passwort des Owners; das eigene Konto ist ausgenommen.
- **Vergessenes Passwort per Passkey**, ohne genanntes Konto und mit
  verpflichtender Prüfung am Gerät. Damit verrät der Weg keine Anmeldenamen und
  besteht aus zwei Faktoren, nicht einem.

Bewusst **ohne E-Mail**: Ein Reset per Mail würde das Postfach zum Hauptschlüssel
des Servers machen und auf einer frischen Maschine still im Spam versagen. Sollte
später ein Mailkanal kommen, dann zuerst für Benachrichtigungen.

`asylum reset-password` bleibt als Anker — der Fall „weder Passkey noch zweiter
Owner" muss irgendwo endlich sein.

### 0.3.0 — Dateimanager

**Stand: umgesetzt.** Einzelheiten in [13-dateimanager.md](13-dateimanager.md).
Gebaut in sieben Schritten: Pfadwache, Leseansicht, Schreiboperationen, Upload,
Editor, Härtung und Angriffsdurchgang.

| Kennzahl | Grenze | Ist | Ohne den Dateimanager |
|---|---|---|---|
| Binärgröße | < 30 MB | 16,6 MB | 15,8 MB |
| RSS im Leerlauf | < 40 MB | 22,0 MB | 19,7 MB |
| Direkte Go-Abhängigkeiten | < 25 | 6 | 6 |
| Testabdeckung `privops` | > 72 % (CI-Schwelle) | 76 % | 75 % |
| Testabdeckung `httpd` | > 63 % (CI-Schwelle) | 69 % | 67 % |
| Haufenwachstum bei 40-MiB-Upload | — | 0 B (gestreamt) | — |

Die rechte Spalte ist derselbe Stand ohne diesen Zweig (`a4e07c4`), auf derselben
Maschine mit derselben Methode gemessen — sonst wäre der Vergleich keiner. Der
Dateimanager kostet also 0,8 MB im Binary (davon 351 KiB das Editor-Bundle) und
2,3 MB Grundlast. Keine neue Go-Abhängigkeit: Der gesamte Dateizugriff läuft über
die Standardbibliothek (`os.Root`, `archive/tar`, `compress/gzip`).

Der Dateimanager ist das erste Modul, dessen Ziel aus der Anfrage kommt und
nicht aus einer Allowlist: Bei den Diensten steht ein Unit-Name zur Wahl, hier
jeder Pfad des Servers. Die gesamte Prüfung liegt deshalb an einer Stelle
(`internal/privops/pfadwache.go`), aufgelöst wird über `os.Root` statt über
Zeichenketten, und kein Handler baut je selbst einen Pfad zusammen.

Fünf Entscheidungen, die den Zuschnitt bestimmen:

- **Eine eingebaute Sperrliste, die für jede Rolle gilt** — auch für Owner:
  Passwort-Hashes, SSH-Host-Schlüssel, der private TLS-Schlüssel, die Datenbank
  des Panels. Eine übernommene Sitzung soll nicht mit zwei Klicks das Material
  holen können, mit dem sich jede weitere Schutzschicht umgehen lässt. Der
  Eintrag bleibt sichtbar und nennt den Grund; wer die Datei braucht, hat SSH.
- **Inhalt nur bei regulären Dateien.** Ein `open()` auf eine FIFO blockiert
  unbegrenzt, `/dev/zero` liefert unendlich viel, `/proc/kcore` behauptet
  128 TiB. `/proc`, `/sys` und `/dev` werden gar nicht betreten.
- **Rekursive Eingriffe werden vorher gezählt** und abgelehnt, wenn Gesperrtes
  darunter liegt oder eine Dateisystemgrenze überschritten würde: Ein Löschen
  von `/etc` darf `/etc/shadow` nicht mitnehmen, eines von `/mnt` nicht die
  eingehängte Platte leeren.
- **Der Upload streamt.** `r.ParseMultipartForm` zöge zwei Gigabyte in Speicher
  und Temp-Dateien; bei `MemoryMax=256M` ist das kein Weg. Der CSRF-Token wird
  deshalb aus dem ersten Multipart-Teil geprüft, vor dem ersten Byte Inhalt.
- **Die Härtung der Unit geht auf**, aber nur so weit wie nötig:
  `ProtectSystem=true` statt `full`, `ProtectHome=false`. `/usr` und `/boot`
  bleiben schreibgeschützt.

**Was der Bau zutage gebracht hat**, jenseits des geplanten Umfangs:

- **CodeMirror lief nicht unter der Content-Security-Policy.** Chromium verwarf
  das Stil-Element, das der Editor zur Laufzeit anlegt; er blieb ungestylt.
  Statt `'unsafe-inline'` für die Seite trägt die Antwort jetzt einen Nonce, den
  CodeMirror mitbekommt — erlaubt ist damit genau das eine Element, das den Wert
  kennt. Gemessen im Browser, nicht vermutet.
- **Zwei Layoutfehler, beide älter als der Dateimanager.** Die Filterleiste ragte
  im schmalen Modus auf jeder Seite vier Pixel über den Rand (negativer
  Randausgleich `-1rem` gegen `0,75rem` Innenabstand von `main`), und die
  Passkey-Zeile im Konto schob die Seite bei 375 Pixeln um 48 Pixel nach rechts.
  Gefunden, weil die neue Seite über alle elf Seiten × vier Breiten gemessen
  wurde; behoben für alle.

### Laufend, an keine Fassung gebunden

Externer Sicherheits-Review (sinnvollerweise **nach** 0.2.0, weil Let's Encrypt
und Passkeys die Anmeldepfade nochmals anfassen — sonst wird zweimal geprüft),
Marken in Klasse 9/42, Paket-Namensräume, GitHub-Organisation.

*Nachtrag zum Review: Dieselbe Überlegung, eine Stufe weiter gedacht, hat ihn
inzwischen auf **1.0** geschoben. Docker, der Webserver-Schreibpfad, die
Datenbanken und die API-Tokens fassen die Angriffsfläche noch einmal an; ein
Review davor wäre genau der zweite Durchgang, den dieser Absatz vermeiden
wollte.*

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
länger nebenberuflich.

*Nachtrag: Der Satz „danach v0.2 (Dateimanager, Cron, Terminal,
Benachrichtigungen) und v0.3 (Module)" stand hier bis zur Neukonzeption. Er gilt
nicht mehr — der Dateimanager kam in 0.3.0, Cron in 0.4.0, das Web-Terminal ist
hinter 1.0 verschoben, und der Modulschnitt ist neu gefasst. Maßgeblich sind
[16-neukonzeption.md](16-neukonzeption.md) §5 und §6, wiedergegeben im nächsten
Abschnitt.*

---

## Meilensteine ab 0.4

Übernommen aus [16-neukonzeption.md](16-neukonzeption.md) §10; der
Funktionsschnitt jeder Stufe steht dort in §5, die bewusst zurückgestellten
Themen in §6. Die Aufwände sind für eine Vollzeit-Person geschätzt.

| Stufe | Inhalt | Aufwand | Stand |
|---|---|---|---|
| Vorbau | Entwurfsmappe, `web/`-Gerüst, Vite-Reproduzierbarkeit, API-Skelett | ~1 Woche | **umgesetzt** |
| **0.4** | Neue Oberfläche mit Parität, `/api/v1`, Job-Modell, Cron & Timer, API-Tokens | ~6–8 Wochen | **umgesetzt** (0.4.0), alte Fläche abgebaut (0.4.1) |
| **0.5** | Docker — Stacks, Container, Images, Volumes, Netze, Logs; dazu Ports, Events, Update-Prüfung | ~5–6 Wochen | **umgesetzt** (Schritte 1–7 und 9); die Container-Shell ist zurückgestellt — [17-docker.md](17-docker.md) |
| **0.6** | Webserver & Domains — nginx/Caddy, Sites als Domain → Ziel → TLS | ~3 Wochen | offen |
| **0.7** | Datenbanken — MariaDB/MySQL und PostgreSQL | ~2 Wochen | offen |
| **0.8** | Backups — restic, Ziele, Zeitpläne, Restore-Test | ~3 Wochen | offen |
| **1.0** | Externer Review, Befundbehebung, Doku, Screenshots | ~2 Wochen + Wartezeit | offen |

**Summe: gut ein halbes Jahr Vollzeit**, davon 0.4 und 0.5 erledigt —
verbleibend rund zweieinhalb Monate. Nach jeder Stufe ist etwas Auslieferbares da; der Kanal `beta`
trägt die Zwischenstände wie bisher.

Die 0.5 stand mit ~5–6 statt der in §10 geschätzten 3 Wochen, weil ihr Zuschnitt
erweitert wurde: Ports, Ereignisstrom, Update-Prüfung und eine Container-Shell
kamen über §5 hinaus dazu. Die Begründung je Zugabe — und was aus dem
Vergleichsprodukt Arcane bewusst *nicht* übernommen wurde — steht in
[17-docker.md](17-docker.md).

**Der Aufwand gegen die Schätzung**, wie es §10 verlangt: Umgesetzt sind acht
der neun Schritte; die Container-Shell (Schritt 8) ist zurückgestellt und war
mit Abstand der teuerste — sie hätte einen bidirektionalen Transport, einen
Terminal-Emulator im Bündel und eine siebte direkte Abhängigkeit gebracht. Ohne
sie liegt die Stufe näher an der ursprünglichen Schätzung als am erweiterten
Ansatz. **Gemessen** ist das Ergebnis in [17-docker.md](17-docker.md) unter
Schritt 9: +464 KiB Binärgröße (2 %), **keine** neue Go-Abhängigkeit, ein
einziger neuer Allowlist-Eintrag, unveränderte Grundlast im Leerlauf.

Der Kostentreiber jeder Stufe ist nicht die Oberfläche, sondern das, was
`privops` dazulernt: Eine neue Seite ist ein Nachmittag, eine neue Systemfläche
eine Sicherheitsbetrachtung. Es kommen vier Familien hinzu — `Docker*`, `Site*`,
`Db*`, `Backup*` — zu der in 0.4 gebauten `Cron*`/`Timer*`-Familie.

**Zurückgestellt und mit Begründung versehen** (§6): Multi-Server, Web-Terminal,
Plugins Dritter, Benachrichtigungen, Metriken-Langzeitspeicher, öffentliche API
mit Fernzugriff, WireGuard, Podman, PWA. Das **Web-Terminal** stand früher unter
v0.2 und wandert bewusst hinter 1.0 — der neue Scope vergrößert die
Angriffsfläche bereits um Docker und den Webserver-Schreibpfad.

**Der externe Sicherheits-Review** ist damit auf 1.0 gezogen. Er wird einmal
beauftragt und deckt dann den Endstand ab (Docker, Webserver-Schreibpfad,
Datenbanken, API-Tokens) statt zweimal einen Zwischenstand.

## Qualitätsziele als harte Grenzen

Diese Werte gehören in die CI, nicht in ein Wiki. Mit der Neukonzeption hat sich
ihr Rang geändert: **„schlank" ist Messwert, nicht Vetorecht** — die Grenzen
entscheiden nicht mehr gegen eine Funktion, bleiben aber als Sperrklinke gegen
Wildwuchs stehen ([16](16-neukonzeption.md) §9).

| Metrik | Grenze | Wo geprüft |
|---|---|---|
| Binärgröße | < 30 MB (Richtwert neu: < 40 MB) | CI, jeder Lauf |
| RSS im Leerlauf | < 40 MB (Richtwert neu: < 64 MB) | von Hand je Release, im CHANGELOG genannt |
| CPU im Leerlauf | < 0,5 % auf 1 vCPU | von Hand |
| Kaltstart bis Ready | < 1 s | von Hand |
| Installationsdauer | < 30 s auf einem 1-vCPU-VPS | von Hand |
| Direkte Go-Abhängigkeiten | < 25 | Durchsicht von `go.mod` |
| Testabdeckung `auth` | > 85 % | CI, jeder Lauf |
| Testabdeckung `update` | > 85 % | CI, jeder Lauf |
| Testabdeckung `config` | > 88 % | CI, jeder Lauf |
| Testabdeckung `store`, `certs` | > 78 % | CI, jeder Lauf |
| Testabdeckung `privops` | > 72 % | CI, jeder Lauf |
| Testabdeckung `httpd` | > 68 % | CI, jeder Lauf |
| Testabdeckung `acme` | > 55 % | CI, jeder Lauf |

Zwei Dinge, die diese Tabelle ehrlich halten sollen:

- **Die neuen Richtwerte sind noch nicht in der CI.** `.github/workflows/ci.yml`
  prüft weiterhin gegen 30 MB. Die Anhebung auf 40 MB gehört in die Stufe, in
  der sie zum ersten Mal gebraucht wird — vorher wäre sie ein Vorschuss auf
  Wachstum, das noch niemand gebaut hat.
- **RSS misst kein Job.** Der Wert wird je Release von Hand erhoben und steht im
  CHANGELOG; automatisiert ist allein die Binärgröße. Der frühere Satz, ein
  Benchmark-Job messe beides, war eine Absicht und keine Beschreibung.

Ohne diesen Zwang wird aus „schlank" innerhalb eines Jahres ein Marketingbegriff.

Zur Abdeckung: Gemessen wird die **Statement-Abdeckung** aus dem Testlauf, nicht
der Mittelwert über Funktionen — letzterer gewichtet eine dreizeilige Funktion so
stark wie eine dreißigzeilige und ist damit leicht zu schönen. Die Schwellen liegen
bewusst knapp unter dem jeweils erreichten Stand: Sie sind eine Sperrklinke gegen
Rückschritt, keine runden Wunschzahlen — und sie steigen mit, wenn der gemessene
Stand steigt. Die `httpd`-Grenze ging mit 0.4.1 von 63 auf 68, weil der Abbau der
alten Oberfläche rund 4.500 Zeilen überwiegend ungetestete Anzeigelogik
mitgenommen hat; eine Grenze sieben Punkte unter dem Stand hält nichts. `httpd`
liegt trotzdem niedriger als die übrigen, weil das Paket zu großen Teilen aus
Anzeigelogik besteht; die sicherheitsrelevanten Pfade darin (Sitzung, CSRF,
Rollen) sind eigens getestet.

## Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Aussperrung durch Firewall-/SSH-Änderung | 60-Sekunden-Bestätigung mit automatischem Rollback, `scp` CLI als lokaler Rettungsweg |
| Panel als Angriffsziel im offenen Netz | 2FA-Pflicht, Rate-Limiting, Empfehlung zu Bind auf localhost/WireGuard, signierte Updates, schlanke Angriffsfläche |
| Feature-Creep Richtung Hosting-Panel | Nicht-Ziele dokumentiert, Modulgrenze, harte Ressourcenbudgets in der CI |
| Konflikt mit manueller Serverkonfiguration | Managed-Marker, Drop-in-Dateien, Konflikterkennung per Hash, Backups vor jedem Schreibvorgang |
| Ein-Personen-Projekt versandet | Früh Beta veröffentlichen, kleiner Kern, saubere Modulschnittstelle für Beiträge |

Zwei Zeilen dieser Tabelle sind mit der Neukonzeption nachzuschärfen. **Der
Feature-Creep** ist nicht mehr durch das Ressourcenbudget begrenzt — es
entscheidet nicht mehr gegen Funktionen —, sondern allein durch die
Nicht-Ziel-Liste: kein Mailserver, kein autoritatives DNS, keine Kunden- oder
Abrechnungsverwaltung, kein Stack neben apt. Sie gilt verschärft weiter, weil
sie jetzt die einzige Bremse ist. Und **das Versanden** wird nicht mehr über
„früh Beta veröffentlichen" abgefangen, sondern über den Stufenschnitt: Jede
Fassung ab 0.4 ist für sich nützlich und auslieferbar.

Die Risiken der Stufen ab 0.5 — Docker als Sicherheitslücke, ein
Webserver-Schreibpfad, der das Panel aussperrt, die npm-Lieferkette,
Vite-Reproduzierbarkeit — stehen mit ihren Gegenmaßnahmen in
[16-neukonzeption.md](16-neukonzeption.md) §11.
