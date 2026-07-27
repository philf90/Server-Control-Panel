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
auf einem echten Server neu entstehen.

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

Der Weg zu 0.1.0 läuft über Vorabversionen im Kanal `beta`. Jede hat bisher
genau einen Fehler zutage gefördert, den kein Test gefunden hatte — das ist ihr
Zweck.

| Fassung | Gefunden | Behoben in |
|---|---|---|
| `0.1.0-rc.1` | Die Ersetzung der Debian-Version (`0.1.0-rc.1` → `0.1.0~rc.1`) war nie in der Datei angekommen. | `rc.2` |
| `0.1.0-rc.2` | `min_upgradable_from` stand fest auf `0.1.0` und sperrte damit jeden Beta-Tester aus: Nach SemVer ist die Freigabe neuer als ihre Vorabversionen. | `rc.3` |
| `0.1.0-rc.2` | Die apt-Anleitung nannte `Suites: stable` — ein Kanal, den es noch nicht gab. | `rc.3` |
| `0.1.0-rc.2` | Der Link zur Ersteinrichtung nannte den kurzen Rechnernamen ohne Domainendung und war von außen unbrauchbar; der vollqualifizierte Name fehlte zudem im Zertifikat. | `rc.3` |

Die ersten beiden Fehler traten erst auf, als ein Tag gesetzt war. Deshalb läuft
der betroffene Schritt seit `rc.3` bei jedem CI-Lauf probeweise gegen eine
Attrappe (`packaging/release-dry-run.sh`).

**Für 0.1.0 offen:** ein Freigabekandidat, der bei einem unbeteiligten Tester
von der Installation bis zur Anmeldung ohne Eingriff durchläuft. Erst der Tag
ohne Bindestrich legt den Kanal `stable` an.

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
