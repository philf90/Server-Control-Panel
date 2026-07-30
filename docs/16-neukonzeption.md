# 16 — Neukonzeption: vom Server-Panel zum Server-und-Apps-Panel

Dieses Dokument ist der Bauplan für die zweite Ausbaustufe des Projekts. Es
revidiert zwei Grundsatzentscheidungen aus [03-funktionsumfang.md](03-funktionsumfang.md)
— den Scope und die Vorgabe „schlank als hartes Budget" —, legt den
Funktionsschnitt der Fassungen 0.4 bis 1.0 fest und beschreibt die neue
Oberfläche. Die zugehörige Entwurfsmappe mit allen Bildschirmen:

**[docs/entwuerfe/neukonzept.html](entwuerfe/neukonzept.html)** — im Browser öffnen.

Wie die bisherigen Mappen ist sie statisches HTML ohne Schriftdateien und ohne
externe Aufrufe. Die Seitenleisten in den Rahmen sind echte Verweise — man
bedient die Mappe wie das Panel und springt von Bildschirm zu Bildschirm.

---

## 1. Anlass und Auftrag

Drei Dinge ändern sich, drei bleiben.

**Was sich ändert:**

1. **Der Scope wächst von A nach A+.** Das Panel bleibt Serververwaltung, nimmt
   aber die Betriebsthemen dazu, für die heute doch wieder SSH nötig ist:
   Container, Webserver mit Domains und Zertifikaten, Datenbanken, Zeitpläne,
   Backups. Nicht als Hosting-Panel mit Kunden und Mail — als Werkzeug für den
   einen eigenen Server, auf dem Anwendungen laufen.
2. **Die Oberfläche wird neu gebaut.** Die Kommandobrücke aus
   [15-neuordnung.md](15-neuordnung.md) hat sich im Betrieb nicht bewährt; was
   sich bewährt hat, sind die Telemetrie-Kacheln der Übersicht — dunkle Karte,
   große Zahl, bernsteinfarbener Verlauf. Sie werden zum Ausgangspunkt des neuen
   Gestaltungssystems, alles andere entsteht neu.
3. **„Schlank" fällt als harte Vorgabe.** Die CI-Grenzen für Binärgröße und
   Grundlast bleiben als Messwerte erhalten, aber sie entscheiden nicht mehr
   gegen Funktionen. Konkret heißt das: ein Frontend mit Build-Schritt und
   Node in der Werkzeugkette ist jetzt zulässig — auf dem Zielserver ändert
   sich nichts, dort landet weiterhin genau ein Binary.

**Was bleibt:**

1. **Ein Binary, ein Server, apt.** Keine Laufzeitabhängigkeiten, kein Docker
   als Voraussetzung, kein Node auf dem Zielsystem.
2. **Nicht-besitzergreifend.** Das Panel schreibt nur in verwaltete Blöcke und
   eigene Drop-in-Dateien, sichert vor jedem Schreiben, validiert vor jedem
   Neuladen und erkennt fremde Änderungen am Hash. Der Server bleibt „normal"
   — wer das Panel deinstalliert, behält eine funktionierende Maschine.
3. **Das Go-Fundament.** `privops`, Anmeldung samt Passkeys, Metrik-Sammler,
   Store, signiertes Selbstupdate und ACME sind erprobt, getestet und von
   Freigabekandidaten gehärtet. Sie werden weitergebaut, nicht ersetzt.

## 2. Revision der Nicht-Ziele

Die Nicht-Ziel-Liste aus [03-funktionsumfang.md](03-funktionsumfang.md) war
richtig für Scope A. Zwei Einträge fallen, der Rest gilt verschärft weiter.

| Bisher Nicht-Ziel | Jetzt | Begründung |
|---|---|---|
| vHosts / Reverse Proxy | **Ziel (0.6)** | Der häufigste Handgriff nach dem Aufsetzen eines Dienstes ist „mach ihn unter einem Namen mit TLS erreichbar". Das ACME-Modul existiert bereits; der Schritt von „Zertifikat fürs Panel" zu „Zertifikat je Domain" ist der kleinste im ganzen Vorhaben. |
| Datenbanken (MySQL/PostgreSQL) | **Ziel (0.7)** | Jede zweite Anwendung braucht eine. Datenbank und Benutzer anlegen ist typisierbar und klein — verwaltet wird die Instanz, nicht der Inhalt. |

**Unverändert Nicht-Ziele, jetzt mit größerem Gewicht:**

- **Kein Mailserver-Stack.** Die Erfahrung aller Hosting-Panels: Mail erzeugt
  80 % des Supports. Daran ändert der neue Scope nichts.
- **Kein autoritativer DNS-Server.**
- **Keine Kunden-, Reseller- oder Abrechnungsverwaltung.** Das Panel verwaltet
  einen Server für seinen Betreiber, nicht ein Geschäft.
- **Kein eigenes Paketformat**, keine Software-Stacks an apt vorbei. Auch der
  Webserver und die Datenbank kommen aus den Distributionsquellen.
- **Kein Ersatz für Konfigurationsmanagement.** Wer deklarativ über Flotten
  arbeitet, bleibt bei Ansible besser aufgehoben.
- **Keine Windows-Unterstützung.**

Die Positionierungstabelle in 03 verschiebt sich damit: Der Vergleichspunkt ist
nicht mehr nur Cockpit/Webmin, sondern auch CloudPanel und Coolify — mit dem
Unterschied, dass Asylum nicht-besitzergreifend bleibt und keinen eigenen Stack
neben das System stellt.

## 3. Leitbild und Positionierung

**Ein modernes Verwaltungs- und Betriebs-Panel für einen einzelnen Ubuntu- oder
Debian-Server, auf dem Anwendungen laufen.** Die Zielperson betreibt einen VPS
oder eine dedizierte Maschine, allein oder im kleinen Team, und will drei Dinge
ohne SSH erledigen können: den Zustand sehen, den Server pflegen, Anwendungen
betreiben.

| Produkt | Positionierung | Abgrenzung |
|---|---|---|
| Cockpit | Serververwaltung, Red-Hat-zentriert | Debian/Ubuntu-first, ein Binary, App-Betrieb eingeschlossen |
| Webmin | alles, Perl, gealtert | fokussiert, sicher voreingestellt, moderne Oberfläche |
| CloudPanel / HestiaCP | Hosting, schreibt das System weitreichend um | nicht-besitzergreifend, der Server bleibt normal |
| Coolify | App-Deployment, Docker-zentriert | der Server selbst ist Bürger erster Klasse; Docker ist Option, nicht Voraussetzung |
| Portainer | nur Container | Container sind ein Modul unter mehreren |

Die fünf Leitplanken aus dem README gelten fort, eine wird umformuliert:
„Schlank und ressourcenschonend" wird zu **„sparsam auf dem Server, großzügig
in der Werkstatt"** — auf dem Zielsystem bleiben die Budgets, in der
Entwicklung ist eine Frontend-Werkzeugkette zulässig.

## 4. Basisfunktionen — das Fundament

Was ein Panel für Ubuntu/Debian-Server auf jeden Fall können muss. Der Bestand
deckt fast alles davon ab — das ist das Ergebnis der Fassungen 0.1 bis 0.3 und
der Grund, das Fundament zu behalten.

### 4.1 Vorhanden und bewährt

| Basisfunktion | Umsetzung |
|---|---|
| Geführtes Erst-Setup, Anmeldung (Argon2id + TOTP), Passkeys, Rollen Owner/Admin/ReadOnly, Sitzungsverwaltung, Zugang zurücksetzen ohne Mail | `internal/auth`, `internal/passkeys`, [12-zugang-zuruecksetzen.md](12-zugang-zuruecksetzen.md) |
| Übersicht mit Urteil, Handlungsbedarf und 24-h-Telemetrie (CPU, Speicher, Last, Netz, Dateisysteme, Prozesse), live über SSE | `internal/metrics`, `internal/httpd` |
| Dienste: systemd-Units auflisten, starten, stoppen, neu laden, aktivieren | `internal/privops/services.go` |
| Pakete & Updates: apt mit Live-Ausgabe, Sicherheitsupdates markiert, Reboot-Hinweis | `internal/privops/packages.go` |
| Firewall: ufw mit 60-Sekunden-Probe und selbsttätigem Rückweg; fremde nftables-Regelwerke nur anzeigen | `internal/privops/firewall.go` |
| Systembenutzer und `authorized_keys`, SSH-Härtung mit `sshd -t`-Validierung | `internal/privops/users.go`, `sshd.go` |
| Dateimanager mit Pfadwache, Sperrliste auch für Owner, streamendem Upload und Editor | [13-dateimanager.md](13-dateimanager.md) |
| Logs: journald mit Filter und Live-Follow | `internal/privops/journal.go` |
| Audit-Log, TLS (selbstsigniert + Let's Encrypt), signiertes Selbstupdate mit Rollback | `internal/store/audit.go`, [10-tls-acme.md](10-tls-acme.md), [05-updates.md](05-updates.md) |
| Rückfragen in drei Stufen, serverseitig erzwungen | [14-bestaetigungen.md](14-bestaetigungen.md) |

### 4.2 Neu als Basis

Drei Dinge fehlen dem Fundament, und alle drei sind Voraussetzung für die
Ausbaustufen — deshalb gehören sie in die erste neue Fassung und nicht in ein
späteres Modul.

**Cron & systemd-Timer.** Anzeigen, anlegen, bearbeiten, letzte Ausführung mit
Exit-Code und Ausgabe. War schon in 03 für v0.2 vorgesehen; mit Backups (0.8)
werden Timer außerdem zur internen Infrastruktur. privops wächst um
`CronList`, `CronWrite`, `TimerList`, `TimerRuns` — Cron-Einträge entstehen als
eigene Datei unter `/etc/cron.d/` mit verwaltetem Marker, nie durch Editieren
fremder Crontabs.

**Ein einheitliches Job-Modell.** Heute löst jedes Modul lange Vorgänge selbst:
Pakete streamen über einen eigenen Kanal, die Firewall-Probe hält ihren eigenen
Zustand. Mit Docker, Webserver und Backups kommen drei weitere Vorgangs-Typen
dazu — ab dann ist die Einzellösung ein Fehler. Ein Job hat eine Nummer, einen
Auslöser (wer, wann, was), einen Stream aus dem Konsolen-Echo, einen Exit-Code
und bleibt nach Abschluss abrufbar. Die Oberfläche zeigt jeden Job gleich an,
egal aus welchem Modul er stammt.

> **Stand nach dem Modul Pakete:** Der *sichtbare* Teil davon steht — eine
> Ressource `/api/v1/jobs/{art}` mit Zustand, Auslöser, Laufzeit und Auszug, ein
> Ereignisstrom daneben, und eine Platte in der Oberfläche, die jeder Vorgang
> gleich benutzt. Der *innere* Teil ist noch der alte: Die Registry liegt in
> `internal/httpd` und hält je Art den letzten Vorgang im Speicher. Was noch
> fehlt, ist die Nummer und der Verlauf über einen Neustart des Panels hinaus —
> beides verlangt eine Tabelle im Store. Das geschieht beim Umschalten, wenn die
> alte Oberfläche gelöscht ist und nur noch ein Leser übrig ist: Solange beide
> laufen, wäre eine zweite Registry der schlimmere Fehler — ein Vorgang, der
> doppelt startet, weil zwei Verwaltungen nichts voneinander wissen.

**Eine JSON-Schnittstelle `/api/v1/*`.** Die neue Oberfläche ist die einzige
Kundin, aber die Schnittstelle wird von Anfang an so geschnitten, als käme
später eine zweite (CLI, Automatisierung): Ressourcen statt Seiten, Fehler als
strukturierte Antworten, Berechtigungen serverseitig am Endpunkt. Dazu
**API-Tokens** als zweiter Anmeldeweg neben der Sitzung: serverseitig nur der
Hash gespeichert (dasselbe Muster wie Sitzungen), an das Rollenmodell gebunden,
mit Ablaufdatum, im Audit-Log sichtbar. Öffentlich dokumentiert und stabil wird
die API erst später (siehe Roadmap) — aber falsch geschnitten wird sie sonst
jetzt.

## 5. Erweiterte Funktionen — die Ausbaustufen 0.4 bis 1.0

Der Stand vor der Neukonzeption ist `v0.3.0-rc.6`; eine Fassung ohne Bindestrich
gab es nie. Die Neukonzeption beginnt deshalb mit **`0.4.0-rc.1`** und endet mit
der Freigabe 1.0.

**Die bestehende Oberfläche ist eingefroren.** Sie bekommt keine Gestaltung und
keine Funktion mehr — jede Stunde dort wäre in Arbeit investiert, die mit dem
Umschalten gelöscht wird. Sie bleibt lauffähig, bis die Parität steht, und wird
dann in einem Zug entfernt. Eine Ausnahme, die keine Aufweichung ist: Ein
sicherheitsrelevanter Fehler wird auch dort behoben, solange sie ausgeliefert
wird — sie ist eingefroren, nicht abgeschaltet.

Jede Stufe nennt, was `privops` dazulernen muss — das ist der eigentliche
Kostentreiber, nicht die Oberfläche: Eine neue Seite ist ein Nachmittag, eine
neue Systemfläche ist eine Sicherheitsbetrachtung.

### 0.4 — Neues Fundament

Die neue Oberfläche mit vollständiger Funktionsparität zum Bestand, dazu die
drei Basis-Neuerungen aus 4.2. Kein neues Systemrisiko: privops wächst nur um
die Cron/Timer-Familie, alles andere ist Umbau über dem Bestand.

- Neue Oberfläche (Svelte, siehe Abschnitt 8) für alle vorhandenen Module.
- `/api/v1` für alles, was die Oberfläche braucht; die alten HTML-Routen
  fallen mit dem Umstieg, nicht vorher.
- Job-Modell; Pakete und Firewall-Probe ziehen als erste um.
- Cron & systemd-Timer als neues Modul.
- API-Tokens (intern, für die eigene Oberfläche und `asylum`-CLI).

*Größter Einzelschritt des Vorhabens. Er ist bewusst die erste Stufe: Jede
weitere baut auf API, Job-Modell und neuer Oberfläche auf — in umgekehrter
Reihenfolge würde jedes Modul zweimal gebaut.*

#### Stand der Parität

Die Parität ist als Liste der heutigen Seiten definiert und abzuhaken — das ist
die Gegenmaßnahme gegen das Risiko „die 0.4 wird zur Dauerbaustelle" (siehe
Abschnitt 11). Zur Fassung 0.4.0-rc.4 steht sie vollständig:

| Modul der alten Fläche | Neue Fläche | Anmerkung |
|---|---|---|
| Lage (`/`) | `/v2/` | Telemetrie-Kacheln, Urteil, Handlungsbedarf, Dateisysteme, Prozesse |
| Dienste | `/v2/dienste` | Werkbank mit Inspektor |
| Pakete | `/v2/pakete` | erstes Modul im Job-Modell |
| Konten (System) | `/v2/benutzer` | Systemkonten und SSH-Schlüssel |
| Dateien | `/v2/dateien` | einschließlich Editor (CodeMirror im Bundle) |
| Firewall | `/v2/firewall` | mit Probe und Rückweg |
| TLS | `/v2/zertifikate` | Bezug über das Job-Modell statt eigenem Strom |
| Zugänge (Panel) | `/v2/zugaenge` | nur Owner-Rolle, Menüpunkt entsprechend gefiltert |
| Audit | `/v2/audit` | Filter auf dem Server, Blätterung über die Kennung |
| Journal | `/v2/logs` | mit Verfolgen |
| Updates | `/v2/updates` | Poller statt Strom — der Vorgang startet den Dienst neu |
| Konto (eigenes) | `/v2/konto` | Passwort, zweiter Faktor, Passkeys, Sitzungen |

**Nicht übertragen, und zwar absichtlich:** Anmeldung, Erstinstallation, der
erzwungene Passwortwechsel und der Weg für ein vergessenes Passwort bleiben
server-gerenderte Vorlagen. Sie liegen vor der Anmeldung oder an ihrer Stelle,
müssen ohne JavaScript laufen und sind der Grund für das Hybrid-Routing aus
Abschnitt 8.1.

Offen für die 0.4 bleiben damit die zwei Basis-Neuerungen, die keine Parität
sind, sondern Zuwachs: **Cron & systemd-Timer** (neue privops-Familie) und
**API-Tokens** (erste Store-Erweiterung). Danach folgt das Umschalten: `/v2` wird
`/`, die alten Vorlagen fallen in einem Zug, und die Sitzungen werden verworfen.

### 0.5 — Docker

Container, Images, Volumes, Netzwerke, Compose-Stacks, Container-Logs und
-Statistiken. Podman bleibt Roadmap — erst eine Laufzeit richtig.

- **Stacks sind das führende Objekt.** Ein Stack ist ein Verzeichnis
  `/opt/asylum/stacks/<name>/` mit einer `compose.yaml`, die durch die
  Pfadwache und einen Compose-Prüfer läuft. Container entstehen ausschließlich
  aus Compose-Dateien — ein freies `docker run` mit beliebigen Flags gibt es
  nicht, aus demselben Grund, aus dem privops keine freie Shell hat.
- privops wächst um die `Docker*`-Familie: typisierte Operationen über die
  `docker`-CLI (Allowlist-Pfad, `--format json`), **nicht** über eine
  Socket-Bibliothek. Zwei Gründe: kein neuer schwerer Baustein im
  Abhängigkeitsbudget, und jede Aktion bleibt eine nachvollziehbare
  Kommandozeile im Konsolen-Echo — Grundsatz IV überlebt nur so.
- Fehlt Docker, bietet das Panel die Installation über das Paketmodul an —
  dieselbe Antwort wie bei ufw in rc.4, nicht eine Kommandozeile zum Abtippen.

### 0.6 — Webserver & Domains

nginx oder Caddy — erkannt wird, was läuft; fehlt beides, wird eines über das
Paketmodul installiert (Voreinstellung nginx, weil auf Bestandsservern häufiger
vorhanden). Sites sind der Gegenstand, nicht Konfigurationsdateien:

- Eine Site ist Domain → Ziel → TLS. Ziele: Reverse-Proxy auf einen Container
  oder Port, statisches Verzeichnis, PHP-FPM (optional, über das Paketmodul).
- Geschrieben wird ausschließlich als verwaltetes Drop-in
  (`/etc/nginx/conf.d/asylum-<site>.conf` mit Marker und Hash-Konflikterkennung
  wie bei `internal/config`); fremde vHosts werden angezeigt, nie verändert —
  dieselbe Trennung wie bei nftables.
- Jeder Schreibvorgang läuft als Kette: Backup → schreiben → `nginx -t` bzw.
  `caddy validate` → neu laden → **Probe**. Antwortet der Server nach dem
  Neuladen nicht mehr (das Panel kann selbst hinter dem Proxy liegen), stellt
  der Rückweg den vorigen Stand wieder her — dieselbe 60-Sekunden-Mechanik wie
  bei der Firewall, und aus demselben Grund.
- TLS je Domain über das vorhandene ACME-Modul; der Zertifikatshalter wird
  mehrfähig (heute hält er genau ein Zertifikat, das des Panels).
- privops wächst um `WebServerState`, `SiteList`, `SiteApply`, `SiteRemove`.

### 0.7 — Datenbanken

MariaDB/MySQL und PostgreSQL: Instanz-Status, Datenbanken und Benutzer
anlegen und entfernen, Zeichensatz/Locale, Dumps erzeugen und einspielen.

- Admin-Zugriff über **Unix-Socket-Peer-Authentifizierung als root** — das
  Panel speichert kein Datenbank-Admin-Passwort, weil es keines braucht. Auf
  Debian/Ubuntu ist Socket-Auth für beide Systeme die Voreinstellung.
- Passwörter angelegter Datenbank-Benutzer werden genau einmal angezeigt —
  dasselbe Muster wie bei Panel-Zugängen — und tauchen weder im Konsolen-Echo
  noch im Audit-Log auf (die bestehende Geheimnis-Verdeckung des
  privops-Journals wird um die betreffenden Argumentformen erweitert).
- Dumps laufen als Jobs mit Stream und landen in einem Verzeichnis, das der
  Dateimanager kennt; Einspielen ist Stufe 3 (getippter Datenbankname).
- privops wächst um `DbState`, `DbList`, `DbCreate`, `DbDrop`, `DbUserCreate`,
  `DbUserDrop`, `DbDump`, `DbRestore`.

### 0.8 — Backups

restic-Integration: Ziele (lokal, SFTP, S3-kompatibel), Zeitpläne über
systemd-Timer (das Modul aus 0.4), Aufbewahrungsregeln, Wiederherstellung.

- **Der Restore-Test ist der Kern des Moduls**, nicht die Sicherung. Ein Backup,
  das es gibt, aber nicht zurückspielbar ist, ist schlimmer als keines, weil es
  Sorglosigkeit erzeugt. Jeder Zeitplan enthält deshalb einen wiederkehrenden
  Prüflauf (`restic check` plus Probe-Restore einer Stichprobe), dessen
  Ergebnis auf der Übersicht als Handlungsbedarf erscheint, wenn er scheitert
  oder ausbleibt.
- Das Repository-Passwort ist das erste echte Betriebsgeheimnis, das das Panel
  dauerhaft halten muss (siehe 7.4).
- Bewusst die letzte Stufe: Sie braucht Timer (0.4), sichert sinnvollerweise
  Stacks (0.5) und Datenbanken (0.7) — und sie ist die Funktion, bei der ein
  Konstruktionsfehler am teuersten ist.
- privops wächst um `BackupTargetCheck`, `BackupRun`, `BackupList`,
  `BackupRestore`, `BackupPrune`.

### 1.0 — Freigabe

Kein neues Feature. Externer Sicherheits-Review der neuen Flächen (Docker,
Webserver-Schreibpfad, Datenbanken, API-Tokens) — der ausstehende Review aus
[06-roadmap.md](06-roadmap.md) wird damit auf den Endstand gezogen statt
zweimal beauftragt. Dazu Dokumentation, Screenshots vom echten Server,
Migrationspfad von 0.3.

## 6. Roadmap — was bewusst später kommt

Jeder Eintrag nennt den Grund für das Warten und das Design-Risiko, das vor dem
Bau geklärt sein muss. Die Liste ersetzt die v0.2/v0.3-Abschnitte in 03.

| Feature | Warum später | Was vorher geklärt sein muss |
|---|---|---|
| **Multi-Server** | Erfordert die Prozesstrennung aus [02-architektur.md](02-architektur.md) und ein Panel/Agent-Protokoll; prägt jede Architekturentscheidung | Trust-Modell: Ein kompromittiertes Panel darf nicht n Server kompromittieren. Update-Pfad je Agent. Wo liegt die Wahrheit — Panel oder Agent? |
| **Web-Terminal** | Die gefährlichste Funktion des Panels: ein PTY über WebSocket umgeht die gesamte privops-Typisierung, das Konsolen-Echo und die Rückfragen | Opt-in per Konfigurationsdatei (nicht per Oberfläche), nur Owner, Sitzungsaufzeichnung ins Audit, eigene Sicherheitsbetrachtung wie beim Dateimanager |
| **Plugins / Module Dritter** | Braucht eine stabile API (frühestens nach 1.0); ein Plugin im Prozess erbt alle Rechte des Panels | Go-Plugins sind praktisch unbrauchbar (Versionskopplung) — realistisch ist ein Unterprozess mit IPC und eigenem Rechteschnitt; das ist ein eigenes Projekt |
| **Benachrichtigungen** (Mail/Webhook/ntfy) | Braucht Zustell-Semantik (Wiederholung, Entprellung) und Geheimnisverwaltung für SMTP/Token | Schwellenmodell gegen Alarm-Müdigkeit: Was meldet wann, und wie schweigt man es gezielt? Die Signalquelle existiert schon (Handlungsbedarf) |
| **Metriken-Langzeitspeicher** | Der 24-h-Ring deckt „was ist gerade los" ab; Langzeit ist eine Speicher- und Kompaktierungsfrage | Downsampling-Politik, SQLite-Wachstum vs. eigenes Format; wer echte Langzeitmetriken will, exportiert besser nach Prometheus |
| **Öffentliche API + CLI-Fernzugriff** | Die API entsteht in 0.4, aber Versionsstabilität verspricht man nur einmal | Token-Scopes feiner als Rollen? Versionierungs- und Abkündigungsregeln, Rate-Limits |
| **WireGuard** | Nützlich, aber unabhängig von allem anderen; verliert gegen die App-Stufen | Schlüsselverwaltung (private Keys der Peers nie speichern), QR-Ausgabe |
| **Podman** | Erst eine Container-Laufzeit richtig | Abbildung auf dieselbe typisierte Operationsfamilie wie Docker |
| **PWA / Mobile** | Das responsive Web kommt zuerst; ein Manifest ist der billige Zwischenschritt | Push-Benachrichtigungen hängen am Notifications-Feature |

Das **Web-Terminal** stand in 03 noch unter v0.2. Es wandert bewusst hinter
1.0: Der neue Scope vergrößert die Angriffsfläche bereits um Docker und den
Webserver-Schreibpfad, und ein Terminal zusätzlich vor dem externen Review wäre
die falsche Reihenfolge.

## 7. Sicherheitsfundament ab Tag eins

### 7.1 Der Bestand bleibt Gesetz

Nichts an der Neukonzeption lockert eine bestehende Regel:

- **privops als einzige Systemgrenze** — typisierte Operationen, Allowlist mit
  absoluten Pfaden, keine Shell, festes Environment, Ausgabe- und Zeitbudget.
- **CSP `default-src 'none'`** ohne `unsafe-inline`/`unsafe-eval`; der
  Nonce-Mechanismus bleibt die dokumentierte Ausnahme.
- Argon2id, TOTP mit Zähler, Passkeys, serverseitige Sitzungen (nur Hashes in
  der Datenbank), CSRF auf jeder schreibenden Route, Rate-Limits.
- Signierte Releases, Schlüssel im Binary, Selbstupdate mit Probe und Rückweg.
- Pfadwache mit Sperrliste, die auch für Owner gilt.
- Rückfragen in drei Stufen, serverseitig erzwungen.
- Audit-Log und Konsolen-Echo — das Panel verschweigt nichts.

### 7.2 Neu: Docker ist root-Äquivalenz

Wer den Docker-Socket hat, hat die Maschine (`-v /:/host` genügt). Daraus
folgen die Regeln des Moduls:

- Der Socket wird **nie** an die Oberfläche oder API durchgereicht; es gibt
  ausschließlich typisierte privops-Operationen.
- Container entstehen nur aus Compose-Dateien unter der Pfadwache. Vor jedem
  `up` läuft ein Prüfer: `privileged`, Host-PID/-Netz, Device-Mounts und
  Bind-Mounts auf Pfade der Sperrliste werden abgelehnt; Bind-Mounts außerhalb
  des Stack-Verzeichnisses sind Stufe 3 (getippter Stack-Name). Der Prüfer ist
  eine Bedienhilfe **und** eine Grenze — er läuft serverseitig, nicht im
  Formular.
- Images nur per Digest oder Tag aus konfigurierbaren Registries; `docker login`
  -Geheimnisse gehören in die Geheimnisverwaltung (7.4), nicht in die Compose-Datei.

### 7.3 Neu: Schreibpfade, die das Panel selbst treffen können

Der Webserver-Schreibpfad hat dieselbe Eigenschaft wie die Firewall: Ein
Fehler kann die Erreichbarkeit des Panels selbst kosten (Panel hinter dem
Proxy, Port-Kollision auf 443). Deshalb gilt für Sites dieselbe Mechanik wie
für Regeln — validieren, neu laden, Probe, selbsttätiger Rückweg — und nicht
nur ein Bestätigungsdialog. Ein Dialog schützt vor Versehen; die Probe schützt
auch dann, wenn man nicht mehr klicken kann.

### 7.4 Neu: Geheimnisse, die das Panel halten muss

Bis 0.3 speichert das Panel keine fremden Geheimnisse (das Cloudflare-Token
liegt als Datei beim Betreiber). Mit den Ausbaustufen kommen drei dazu:
Registry-Zugänge (0.5), restic-Repository-Passwörter (0.8), später
SMTP/Webhook-Token. Grundsätze:

- **Vermeiden vor verwalten:** Datenbanken laufen über Socket-Auth — kein
  gespeichertes Admin-Passwort. Erzeugte DB-Passwörter werden einmal angezeigt
  und nicht aufgehoben.
- Was bleibt, liegt verschlüsselt im Store; der Schlüssel kommt aus
  `systemd`-Credentials (`LoadCredential`) oder einer Root-Datei, nie aus der
  Datenbank selbst. Anzeigen gibt es nicht, nur Ersetzen.
- Das privops-Journal maskiert die zugehörigen Argumentformen; die Liste
  maskierter Muster wächst mit jedem Modul und ist getestet.

### 7.5 Neu: API-Tokens

- Nur der Hash liegt im Store (Muster der Sitzungen), Anzeige genau einmal.
- Tokens sind an eine Rolle gebunden und können sie nur unterschreiten, nie
  überschreiten; Ablaufdatum verpflichtend, Widerruf einzeln, jede Nutzung im
  Audit-Log mit Token-Kennung.
- Kein Token im Query-String (Logs), nur als Header.

### 7.6 Neu: die Node-Lieferkette

Mit dem Frontend-Build betritt npm die Werkzeugkette — nicht den Server. Die
Regeln folgen dem Muster des Editor-Bundles, das dieses Problem bereits gelöst
hat (`.github/workflows/ci.yml`, Job „Editor-Bundle reproduzierbar"):

- Lockfile eingecheckt, Installation nur `npm ci`, Node-Version gepinnt.
- Das gebaute Bundle ist eingecheckt; die CI baut es nach und vergleicht
  byteweise. Ein Go-Build braucht kein Node — der eingecheckte Stand trägt.
- Lizenz-Prüfung über `node_modules` (Allowlist wie bisher), `npm audit` als
  Warnstufe im CI.
- **Kein CDN, keine externen Schriftarten, keine Laufzeit-Nachladung** — die
  CSP beweist das strukturell weiter: `default-src 'none'` lässt gar keinen
  fremden Host zu.

### 7.7 Sitzungen und Zugangsdauer

Die Mechanik bleibt unverändert — sie ist erprobt und wird übernommen. Was
fehlte, ist ihre Beschreibung: Die Laufzeiten standen bisher **nirgends in der
Dokumentation**, nur als Konstanten in `internal/httpd/session.go`.
[02-architektur.md](02-architektur.md) nennt lediglich „absolute + idle
Expiry". Das wird hier nachgetragen, weil eine Zusage, die niemand nachlesen
kann, keine ist.

**Speicherung.** Das Cookie `asylum_session` trägt einen 32-Byte-Zufallswert,
die Datenbank speichert nur dessen SHA-256. Ein Datenbankabzug erlaubt damit
keine Übernahme laufender Sitzungen. Attribute: `HttpOnly`, `Secure`,
`SameSite=Strict`. Je Zeile stehen daneben CSRF-Token, IP, User-Agent (auf 200
Zeichen gekürzt), Anlage-, Aktivitäts- und Ablaufzeit.

**Laufzeiten.**

| Größe | Wert |
|---|---|
| Absolute Lebensdauer | **12 Stunden** ab Anmeldung |
| Leerlauf-Fenster | **2 Stunden**, gleitend |
| Cookie `Max-Age` | 12 Stunden, wird nie erneuert |
| Aufräumtakt für abgelaufene Zeilen | 10 Minuten |
| Zwischenschritt der Passkey-Anmeldung | 2 Minuten |
| Setup-Token | 60 Minuten |
| Ticket „Passwort vergessen" | 10 Minuten, einmalig |
| TOTP-Wechsel in Arbeit | 15 Minuten |
| Einmalpasswort nach Zurücksetzung | **kein Zeitlimit** — verfällt durch Gebrauch |

Bei jeder Anfrage wandert das Ablaufdatum auf „jetzt + 2 h", hart begrenzt auf
„Anmeldung + 12 h". Geschrieben wird nur, wenn sich dadurch mehr als eine
Minute ändert — die Datenbank sieht also höchstens einen Schreibvorgang pro
Minute und Sitzung, nicht einen pro Anfrage.

Zwei Nebenwirkungen dieser Konstruktion, beide bekannt und beide hingenommen:
Das Cookie lebt zwölf Stunden, die Datenbankzeile im Leerlauf zwei — nach einer
Pause liegt ein technisch gültiges Cookie im Browser, dem serverseitig nichts
mehr entspricht (harmlos, aber die Werte sind nur durch Absicht gekoppelt).
Und etwa zehn Stunden nach der Anmeldung greift die absolute Grenze; ab dann
ändert sich das Ablaufdatum nicht mehr, die Ein-Minuten-Schwelle schlägt nie
wieder zu, und die Spalte „letzte Aktivität" auf der Kontoseite friert für die
letzten zwei Stunden ein.

**Was eine Sitzung beendet.** Vollständig, weil eine unvollständige Liste an
dieser Stelle gefährlich ist:

| Umfang | Anlass |
|---|---|
| Die eine | Abmelden · Widerruf einer eigenen Sitzung · Ablauf beim Lesen · Konto beim nächsten Aufruf gesperrt oder gelöscht |
| Alle des Kontos | eigene Passwortänderung (danach sofort eine frische) · erzwungener Wechsel eines Einmalpassworts · Passwort-Zurücksetzung durch den Owner · 2FA-Zurücksetzung durch den Owner · Konto sperren · Konto löschen (per `ON DELETE CASCADE`) · „Passwort vergessen" · `asylum reset-password` über SSH |
| Alle anderen | Wechsel des zweiten Faktors · „alle anderen beenden" (Stufe 2) |
| Global | nur der Aufräumlauf, und nur schon abgelaufene Zeilen |

Jeder dieser Wege schreibt einen Audit-Eintrag mit dem Vermerk, dass Sitzungen
beendet wurden.

**Was Sitzungen nicht beendet** — und was davon Absicht ist:

- **Ein Passkey-Wechsel beendet nichts.** Weder das Hinzufügen oder Entfernen
  eines eigenen Passkeys noch das Löschen aller Passkeys eines fremden Kontos
  durch den Owner. Beim TOTP-Wechsel wird es gemacht, und die Begründung dort
  („meist eine Reaktion auf ein verlorenes Gerät") gilt für einen verlorenen
  Passkey genauso. **Das ist eine Inkonsistenz, keine Abwägung** — sie gehört
  behoben.
- **Eine Kontosperre durch das Rate-Limiting beendet nichts.** Die Sperre liegt
  nur im Arbeitsspeicher und wird ausschließlich an den Anmeldewegen geprüft;
  wer schon angemeldet ist, bleibt es. Das ist richtig — die Sperre schützt
  gegen Erraten, nicht gegen eine bereits übernommene Sitzung —, war aber
  nirgends festgehalten.
- **Sitzungen überleben Neustart und Selbstupdate**, weil sie ausschließlich in
  SQLite liegen. Nicht überleben die flüchtigen Zustände: Rate-Limit-Zähler,
  halbfertige TOTP-Einrichtungen, WebAuthn-Challenges, Reset-Tickets.
- **Ein Datenbank-Rollback kann widerrufene Sitzungen wiederbeleben**, weil der
  Rückweg die ganze Datei zurückspielt, `sessions` eingeschlossen. Begrenzt
  durch den Zuschnitt (selbsttätig nur wenige Sekunden nach einem
  fehlgeschlagenen Update, von Hand nur mit `--restore-db`), im Code aber nur
  als Datenverlust-Risiko vermerkt, nicht als dieses.
- **Einen Rollenwechsel gibt es nicht** — es existiert keine Route dafür. Käme
  eine, bräuchte sie keine Invalidierung: Der Nutzerdatensatz wird bei jeder
  Anfrage neu gelesen, eine geänderte Rolle greift also sofort.

**Vier Entscheidungen für die 0.4**, die aus der neuen Oberfläche folgen:

1. **Die Umschalt-Migration beendet alle Sitzungen** (`DELETE FROM sessions`).
   Sitzungen überleben das Update, Assets liegen im Browser-Zwischenspeicher —
   ohne diesen Schnitt sitzt nach dem Umschalten jemand mit gültiger Sitzung vor
   einer halb alten Oberfläche. Eine Zeile SQL gegen eine ganze Klasse von
   Resten; der Preis ist eine Neuanmeldung für alle.
2. **Der SSE-Strom wird neu geprüft.** Heute prüft die Middleware die Sitzung
   beim Verbindungsaufbau, danach läuft die Schleife bis zum Abbruch weiter. In
   der neuen Oberfläche hält jeder offene Reiter dauerhaft einen `EventSource` —
   ein Dashboard auf einem Wandmonitor zeichnet also weiter Live-Zahlen,
   während die Sitzung serverseitig längst abgelaufen ist, und der erste Klick
   wirft auf die Anmeldung. Der Strom muss den Ablauf selbst erkennen und
   schließen.
3. **Passkey-Wechsel beendet die übrigen Sitzungen** — die Inkonsistenz oben
   wird mit dem Umbau der Kontoseite behoben, nicht davor: Es ist derselbe
   Handgriff.
4. **API-Tokens folgen den Invalidierungspfaden.** Heute heißt „alle Sitzungen
   des Kontos beenden" wirklich alles. Sobald Tokens existieren, sind sie ein
   zweiter Zugangsweg — eine Passwort-Zurücksetzung durch den Owner muss auch
   sie widerrufen, sonst ist ein zurückgesetztes Konto nicht zurückgesetzt.

*Erledigt mit der ersten Stufe:* Eine abgelaufene Sitzung antwortete jeder
Hintergrund-Anfrage außer SSE mit einer Weiterleitung auf HTML — für ein `fetch`
ein Parserfehler statt der Ursache. Unter `/api/` steht jetzt ein 401 mit
JSON-Rumpf, erkannt am Pfad und nicht am Accept-Kopf.

## 8. Neue Oberfläche

### 8.1 Technikentscheidung

**Svelte 5 + Vite als reines SPA-Build, TypeScript, keine Chart-Bibliothek für
die Telemetrie.** Quelle unter `web/`, Build-Ergebnis eingecheckt unter
`internal/ui/dist/`, eingebettet über das vorhandene `embed.FS` — auf dem
Server bleibt es ein Binary.

Gegen die Alternativen, kurz:

- **React** bringt ein Laufzeit-Framework und den größten transitiven
  Abhängigkeitsbaum mit — genau die Lieferketten-Fläche, die 7.6 klein halten
  will — und bezahlt das mit nichts, was dieses Projekt braucht.
- **Vue** wäre gleichwertig möglich; Svelte compiliert die Reaktivität weg,
  erzeugt kleinere Bundles und arbeitet ohne eval-artige Konstrukte — die
  strikte CSP ist ein hartes Auswahlkriterium, kein Stilpunkt.
- **SvelteKit** wird bewusst nicht genommen: kein Node-Server, kein SSR —
  Vite mit dem Svelte-Plugin genügt und hält die Build-Kette klein.
- **htmx/Alpine über den Go-Templates** wäre der kleinste Bruch, kann aber
  Befehlspalette, Live-Job-Streams und flüssige Inspektoren nur mit genau dem
  Inline-Skript-Stil, den die CSP verbietet — die Fassung wäre eine Sackgasse
  vor der nächsten.

**Routing als Hybrid.** Alles vor der Anmeldung — Login, Setup,
Passwort-Zwangswechsel, Zugang zurücksetzen, Fehlerseiten — bleibt
server-gerendertes Go-Template: kleinste Angriffsfläche vor Auth, funktioniert
ohne JavaScript, trägt den bestehenden CSRF- und Sitzungsfluss. Alles hinter
der Anmeldung ist eine SPA mit History-Routing; unbekannte Pfade liefern für
angemeldete Sitzungen das `index.html`-Fallback.

**Ehrlich benannt, weil es eine Abkehr ist:** Die Kernoberfläche verliert die
Ohne-JavaScript-Fähigkeit. Bisher galt „jede Seite trägt ohne Skript"; künftig
gilt das nur noch für die Seiten vor der Anmeldung. Das ist der Preis für
Befehlspalette, Live-Streams und Inspektoren — und er wird bewusst bezahlt,
nicht übersehen. Die Rückfragen bleiben trotzdem serverseitig erzwungen
(`bestaetigt`-Feld im Handler); der Dialog im Browser bleibt Bedienhilfe.

**Live-Daten.** Der bestehende SSE-Hub bleibt unverändert; im Frontend hängt
ein dünner Store um `EventSource` (Reconnect macht der Browser), den die
Komponenten abonnieren. Jobs (0.4) senden über denselben Kanal mit eigenen
Event-Typen. Kein Zustands-Framework — ein Fetch-Wrapper mit CSRF-Header und
Svelte-Stores genügen.

**Telemetrie-Karten.** Die Sparkline-Berechnung bleibt auf dem Server:
`buildSpark` (Verdichtung auf 60 Stützstellen, Mindestspanne, vorformatierte
Messpunkt-Texte) liefert künftig JSON über `/api/v1/metrics/history` statt
fertiger SVG-Pfade im Template. Gezeichnet wird in einer eigenen
`StatCard`-Komponente — inline-SVG, dieselbe Geometrie (viewBox 100 × 34,
`vector-effect: non-scaling-stroke`, Endpunkt als Null-Segment mit runder
Kappe). **Keine Chart-Bibliothek:** Die Feinheiten dieser Karten sind in 0.2
teuer gelernt und fertig gelöst; eine Bibliothek würde sie schlechter
nachbauen und die einzige Stelle ersetzen, die dem Nutzer gefällt. Für eine
spätere Metrik-Detailseite (Zoom, mehrere Reihen) ist uPlot vorgemerkt —
klein, abhängigkeitsfrei, Canvas — aber nicht in 0.4.

**Zahlen und Sprache kommen weiter vom Server.** Einheiten, Rundung und
deutsche Beschriftung stehen an einer Stelle; das Frontend formatiert nicht
selbst. Alle Oberflächentexte liegen in einem Modul (`web/src/lib/texte.ts`),
keine i18n-Bibliothek — Deutsch ist die Sprache des Projekts, Englisch käme
als zweite Ausbaustufe desselben Moduls.

**Build und CI.** Makefile-Ziel `ui` nach dem Muster von `editor` (ohne npm
bleibt der eingecheckte Stand); CI-Job „UI-Bundle reproduzierbar" exakt nach
dem Editor-Muster. Vite muss dafür deterministisch bauen: feste Chunk- und
Hash-Namen, keine Zeitstempel im Output — das ist ein Prüfpunkt der ersten
Woche, nicht eine Annahme. Content-gehashte Dateinamen erlauben
`Cache-Control: immutable` statt der heutigen 300 Sekunden. Der
CSP-Verstoß-Mitleser aus den E2E-Tests läuft gegen jede neue Seite.

### 8.2 Gestaltungssystem „Leitstand"

Der Name ist geerbt: Entwurf 3 aus [15-neuordnung.md](15-neuordnung.md) hieß
Leitstand und hatte den Grundsatz, der jetzt das ganze System trägt — **Farbe
trägt ausschließlich Zustand.** Umgesetzt wurde damals Entwurf 1; die
Neukonzeption dreht das um: Leitstand gewinnt und erbt von der Kommandobrücke
die Schale (Statusband, Konsolen-Echo).

**Der Keim.** Die Telemetrie-Kachel — dunkle Karte, Beschriftung in
Kapitälchen, große Zahl mit Tabellenziffern, bernsteinfarbener Verlauf,
Unterzeile in Mono — ist der einzige Bestandteil, der bleibt. Ihre Machart
wird zur Regel für alles: dunkle Flächen in drei Stufen, eine warme
Akzentfarbe, Werte in Mono, Zustand in Grün/Rot/Blau mit Text oder Symbol
daneben, nie Farbe allein.

**Farbwerte** (dark-first; das helle Schema ist eine Ableitung mit denselben
Rollen, kein zweites Design):

| Rolle | Wert |
|---|---|
| Hintergrund | `#0c0e11` |
| Fläche (Karte, Leiste) | `#13161b` |
| Fläche erhöht (Kopfzeilen, Eingaben) | `#1a1e25` |
| Linien | `#262b33` |
| Text / gedämpft / schwach | `#e8eaed` / `#9aa1ab` / `#6b7280` |
| **Akzent Bernstein** — Verläufe, aktive Navigation, primäre Knöpfe, Fokusring, Warnstufe | `#e8a33d` |
| Zustand läuft | `#4cc38a` |
| Zustand Fehler / zerstörend | `#e5484d` |
| Information | `#5eb1ef` |

Die Zustandsfarben sind gegen die dunklen Flächen auf Kontrast ≥ 3:1 und
CVD-Unterscheidbarkeit geprüft; sie treten nie als Serienfarben in einem
Diagramm auf — die Verläufe sind immer einreihig und immer bernsteinfarben.

**Typografie.** System-Sans für Text (keine Schriftdateien — dieselbe Regel
wie „keine externen Aufrufe"), Mono für Werte, Pfade, Befehle und Logs, überall
`font-variant-numeric: tabular-nums`. Skala 13/14/16/20/28/40 px; die 40er
gehört der Kachelzahl.

**Grundriss.** Vier Teile, auf jeder Seite gleich:

1. **Statusband** oben: Host und Laufzeit, drei bis vier Mini-Verläufe
   (CPU/RAM/Netz) mit aktuellem Wert — jeder ein Verweis auf die Übersicht —,
   die Befehlspalette (⌘K), ein Live-Punkt für den SSE-Kanal. Grundsatz I:
   der Zustand geht nie weg.
2. **Seitenleiste** links, 240 px, einklappbar auf eine 64-px-Symbolschiene.
   Vier Gruppen: **System** (Übersicht, Dienste, Pakete, Cron & Timer),
   **Apps** (Docker, Webserver, Datenbanken, Backups), **Sicherheit**
   (Firewall, Benutzer & SSH, Panel-Zugänge, Zertifikate), **Betrieb**
   (Dateien, Logs, Audit, Eigenes Konto, Updates). Warnpunkt je Eintrag wie
   bisher.

   Der Punkt hieß im Entwurf „Einstellungen" und zeigte bis 0.4.0-rc.3 auf
   `/users` — die Kontenliste. Der Name versprach etwas anderes, als dahinter
   stand. Aufgeteilt in „Panel-Zugänge" (Konten dieser Oberfläche, der
   Owner-Rolle vorbehalten) und „Eigenes Konto" (jede Rolle, weil jeder sein
   eigenes Passwort und seinen zweiten Faktor wechseln können muss —
   `apiEigenerZugriff` prüft dort nur das Sitzungstoken, die Schranke ist das
   aktuelle Passwort); „Panel-Zugänge" steht
   absichtlich direkt unter „Benutzer & SSH", weil die zwei Kontenarten die
   häufigste Verwechslung im Panel sind und zwei Menüpunkte nebeneinander mehr
   über den Unterschied sagen als jeder Erklärsatz auf einer der beiden Seiten.

   Was die Rolle nicht erreicht, steht nicht in der Leiste und nicht in der
   Befehlspalette. Gefiltert wird in `web/src/lib/ziele.ts` und nicht an beiden
   Stellen: Zwei Filter derselben Regel laufen auseinander, und der übersehene
   wäre die Palette — dort fällt ein Ziel zu viel niemandem auf, bis es
   angeklickt wird. Verbindlich bleibt die Route (`apiOwner`), die Leiste ist
   Bedienhilfe.
3. **Inhalt** mit Brotkrume, Seitentitel und den Mustern aus dem
   Komponentenvorrat.
4. **Protokollzeile** unten: der zuletzt ausgeführte Befehl mit Exit-Code und
   Dauer, aufziehbar zur Vollansicht — das Konsolen-Echo aus dem
   privops-Journal, unverändert. Grundsätze III und IV.

**Komponentenvorrat.**

| Komponente | Zweck |
|---|---|
| `StatCard` | die Telemetrie-Kachel; einzige Diagrammform der Übersicht |
| `DataTable` | Sortierung, Filter oben fixiert, unter 600 px eine Karte je Zeile (die rc.4-Lektion) |
| `Inspector` | Drawer rechts für das ausgewählte Objekt — Details, Aktionen, letzte Logzeilen ohne Seitenwechsel; die Auswahl steht in der URL, damit Verweise teilbar bleiben (das Werkbank-Erbe aus Entwurf 2) |
| `JobStream` | Live-Ausgabe eines Jobs mit Kopfzeile (Nummer, Auslöser, Dauer) und Exit-Banner |
| `ConfirmDialog` | die drei Stufen aus [14-bestaetigungen.md](14-bestaetigungen.md); serverseitig erzwungen, im Browser als Dialog mit Zahlen und getipptem Wort |
| `CommandPalette` | ⌘K: Navigation, Aktionen, Dienste- und Dateisuche — der offene Punkt aus 15, mit der SPA erstmals sauber baubar |
| `Toast`, `Badge`, `StatusDot`, `EmptyState`, `Tabs`, `KeyValue` | die üblichen Kleinteile; `EmptyState` kennt den Zustand „ohne systemd" als gültigen, gestalteten Fall |

**Barrierefreiheit und Responsivität.** Sichtbarer Bernstein-Fokusring auf
allem Bedienbaren; Kontraste gegen die dunklen Flächen AA-geprüft;
`prefers-reduced-motion` schaltet Übergänge und Verlaufs-Animationen ab;
Zahlen umbruchgeschützt; unter 900 px klappt die Seitenleiste zur Schiene,
unter 600 px wird sie zur unteren Reiterleiste (fünf Ziele + „Mehr") und
Tabellen werden Karten — die Belegung übernimmt die erprobte Antwort aus der
Kommandobrücke.

### 8.3 Interaktionsgrundsätze

Die fünf Grundsätze aus [15-neuordnung.md](15-neuordnung.md) gelten
unverändert — sie waren nie das Problem der Kommandobrücke, sondern ihr
richtiger Kern:

| | |
|---|---|
| I | Der Zustand geht nie weg — Statusband auf jeder Seite |
| II | Jede Zahl ist ein Griff — Kacheln, Kennzahlen und Warnpunkte sind Verweise |
| III | Handlungen sind quittiert — Jobs mit Stream, Exit und Verlauf |
| IV | Das Panel verschweigt nichts — Protokollzeile mit jedem Befehl im Klartext |
| V | Erst das Urteil, dann die Zahlen — die Übersicht beginnt mit einem Satz |

Dazu kommt ein sechster, der die neuen Module bindet:

| | |
|---|---|
| VI | Was schiefgehen kann, hat einen Rückweg — Probe mit Frist statt bloßer Rückfrage, wo ein Fehler das Panel selbst aussperren kann |

### 8.4 Das Muster eines Moduls

Festgelegt am Modul **Dienste**, das als erstes gebaut wurde — nicht weil es
das wichtigste ist, sondern weil sieben weitere dieselbe Form brauchen. Wer den
Inspektor falsch baut, baut ihn achtmal falsch.

**Grundriss: Werkbank.** Liste links, Inspektor rechts, kein Seitenwechsel. Wer
einen Dienst neustartet, will danach die Liste sehen — mit der neuen Zeile darin
und nicht als frisch geladene Seite, auf der er die Stelle wiederfinden muss.
Ohne Auswahl nimmt die Liste die ganze Breite; eine leere Spalte danebenzustellen
wäre ein Versprechen auf etwas, das nicht da ist. Unter 1100 px stapelt es, und
der Inspektor steht **oben**: Wer eine Zeile angeklickt hat, will die Einzelheiten
sehen und nicht erst scrollen.

**Die Auswahl steht in der Adresse** (`?unit=nginx.service`). Damit ist ein
Verweis auf einen bestimmten Eintrag teilbar, ein Neuladen zeigt denselben
Zustand, und der Zurück-Knopf schließt den Inspektor. Der Verlauf ist dabei
überlegt und nicht beiläufig: Die **erste** Auswahl auf einer Seite ist ein
Schritt (`pushState`), der Wechsel von einer Auswahl zur nächsten **ersetzt**
(`replaceState`). Sonst müsste man nach zehn angesehenen Einträgen zehnmal
zurück, um die Seite zu verlassen.

**Zwei Aufrufe, nicht einer.** Die Liste kommt vollständig und wird im Browser
gefiltert — beim Tippen ist das Ergebnis sofort da, statt einmal pro Buchstabe
über `systemctl` zu gehen. Die Einzelheiten kommen erst mit der Auswahl, weil
`systemctl show` je Unit Zeit kostet. Was der Server ausrechnet, rechnet der
Browser nicht nach: Zustand („läuft/fehlgeschlagen/aus"), Zähler, Sortierung
(Gescheitertes zuerst) und die sinnvollen Aktionen zum Zustand stehen in der
Antwort. Zählte der Browser selbst, zählte jedes Modul nach eigener Regel — und
die Übersicht nach einer dritten.

**Aktionen antworten mit dem neu gelesenen Zustand.** `POST` auf die Ressource,
Aktion im JSON-Körper, und die Antwort trägt das frische Detail. Ohne das müsste
die Oberfläche eine zweite Anfrage stellen und zeigte in der Lücke den alten
Zustand — was nach einem Neustart genauso aussieht wie ein Neustart, der nicht
geklappt hat.

**Rückfragen kommen vom Server.** [14-bestaetigungen.md](14-bestaetigungen.md)
wortgleich übersetzt: Der Handler führt nichts aus, solange `bestaetigt` fehlt,
und antwortet stattdessen mit **409** und dem *Text* der Rückfrage — Titel,
Frage, Folgen, Knopfbeschriftung, und bei Stufe 3 das zu tippende Wort. Die
Zwischenseite von damals wird ein Objekt. Drei Eigenschaften fallen dadurch
wieder von selbst an: Ein selbstgebautes `POST` ohne das Feld tut nichts, der
Text der Frage steht an genau einer Stelle — dort, wo sie auch erzwungen wird —,
und der Dialog im Browser darf sich irren, ohne dass es gefährlich wird. Der
Dialog selbst ist ein echtes `<dialog>` mit `showModal()`: Fokusfang, oberste
Ebene und Escape kommen vom Browser. Ein `<div>` mit Schleier nachzubauen ist die
Stelle, an der Tastaturbedienung still verloren geht.

**Lange Handlungen sind Vorgänge.** Festgelegt am Modul **Pakete**, dem ersten
mit einer Aktion, die Minuten dauert. Der POST startet und ist sofort zurück
(**202**) — er wartet nicht auf apt; eine Anfrage, die zwanzig Minuten offen
bleibt, überlebt keinen Zwischenserver und kein WLAN. Zugesehen wird über
`/api/v1/jobs/{art}`: die Ressource für den Zustand, der Ereignisstrom daneben
für die Zeilen, während sie entstehen. Der Strom sagt nur „vorbei" — ob es
geglückt ist, wie lange es dauerte und ob eine Anmerkung dazugehört, steht in der
Ressource, und die wird am Ende noch einmal gefragt. Zwei Fassungen dieser
Auskunft liefen auseinander, und dann sagte die Zeile über dem Auszug etwas
anderes als der Auszug.

Drei Einzelheiten, die keine Wahl sind:

- **Der Vorgang läuft auf dem Server weiter.** Wer die Seite verlässt und
  zurückkommt, findet ihn vor, mit Auszug und Laufzeit. Ein abgebrochenes
  `apt-get` mitten im dpkg-Lauf hinterlässt ein halb konfiguriertes System; das
  darf nicht davon abhängen, ob ein Tab offen bleibt.
- **Angehängt wird an die Antwort, nicht an einen späteren Abruf.** Der Server
  hat den Vorgang gerade angelegt, er läuft also — der Strom kann sofort auf.
  Erst abzufragen wäre eine Runde später, und bei einem Vorgang, der in der
  Zwischenzeit fertig wird, käme „läuft nicht" zurück und der Strom ginge nie
  auf. Bei `apt-get update` über einen schnellen Spiegel ist das der Normalfall.
- **Höchstens einer je Art.** Zwei apt-Läufe blockieren sich an der dpkg-Sperre.
  Das soll die Oberfläche verhindern (**409**) und nicht ausprobieren.

**Ein Strom ist nicht wie der andere.** Festgelegt am Modul **Logs**, der zweiten
Seite mit einem Ereignisstrom — und dem Punkt, an dem sich zeigte, dass die
Vorgangsplatte dafür *nicht* taugt. Der Unterschied ist nicht die Technik, beide
hängen an Server-Sent Events; er liegt in der Bedeutung:

| | Vorgang (Pakete) | Journal (Logs) |
|---|---|---|
| Ende | bestimmt der Server: apt ist fertig | keines — es endet, wenn niemand zusieht |
| Warum man zusieht | um zu erfahren, wie es ausgeht | um zu erfahren, was gerade passiert |
| Beim Verlassen der Seite | läuft weiter (ein Abbruch schadet) | wird beendet (ein Weiterlaufen kostet nur) |
| Gehalten wird | jede Zeile, bis zum Ende | die letzten 2000 |
| Zuschauer | teilen sich einen Vorgang | jeder hat einen eigenen Prozess |
| Anfangen | mit der Aktion, ungefragt | auf Knopfdruck, nie ungefragt |

Die letzten zwei Zeilen sind die wichtigen. Weil jeder Zuschauer seinen eigenen
Filter hat, braucht er einen eigenen `journalctl --follow` — vier offene Tabs sind
vier Prozesse. Deshalb gibt es eine Obergrenze (`maxLogFolger`, mit **429** und
einer Angabe in der Abfrage, damit die Oberfläche den Knopf gleich richtig zeigt),
und deshalb ist Verfolgen ein Schalter und keine Vorgabe: Wer die Seite öffnet,
will meist lesen, was war.

Zwei Einzelheiten, die im Betrieb wehtun, wenn sie fehlen:

- **Ein Herzschlag.** Ein Reverse-Proxy schließt eine stille Verbindung nach einer
  Minute, und ein ruhiges Journal ist genau das: still. Ein Kommentar im
  Ereignisstrom (`: still`) hält sie offen, ohne beim Client ein Ereignis
  auszulösen.
- **Verworfene Zeilen werden gemeldet.** Schreibt das Journal schneller als die
  Leitung überträgt, verwirft der Server — und sagt, wie viele. Eine Lücke, die
  niemand sieht, ist schlimmer als eine, die dasteht.

Für privops heißt das eine neue Operation: `LogsFollow`. Sie ist die **einzige
ohne eigene Frist** — der Kontext des Betrachters ist die Frist, und
`CommandContext` tötet den Prozess, wenn er abbricht. Die Argumente baut sie aus
derselben Funktion wie die Abfrage: Hätte der Strom eigene, könnte er mehr zeigen
als die Abfrage vorher hergab, und eine Stufenbeschränkung wäre beim Umschalten
ein Leck durch die Hintertür.

**Ein Rückweg ist Zustand, keine Meldung.** Festgelegt am Modul **Firewall**, dem
einzigen, bei dem ein Fehler den Zugang zum Panel kostet — und zwar aus der Seite
heraus, auf der man ihn zurücknehmen könnte. Grundsatz VI wird dort konkret: Jede
Änderung gilt zunächst auf Probe, und ohne Bestätigung binnen 60 Sekunden stellt
der Server den vorherigen Stand wieder her.

Drei Entscheidungen, die daran hängen:

- **Die Probe steht in `GET`, nicht in einem Ereignis.** Ob eine aussteht, was auf
  Probe steht und wie viele Sekunden übrig sind, ist ein Feld des Zustands. Wer
  die Seite neu lädt, während die Frist läuft, muss den Countdown vorfinden —
  sonst bestätigt er nicht, und die Änderung fällt weg, ohne dass er weiß, warum.
  Genau das ist der Fall, in dem es zählt: Man lädt neu, *weil* etwas hakt.
- **Die Frist ist die des Servers.** Der Browser zählt nur herunter, damit man sie
  sieht, und rechnet dabei aus einem festen Ablaufzeitpunkt statt sekundenweise
  abzuziehen — ein Zähler, der bei jedem Takt eins abzieht, geht falsch, sobald
  der Tab in den Hintergrund kommt oder der Rechner schläft. Bei null wird der
  Zustand *einmal* geholt: Was dann gilt, sagt der Server.
- **Die Probe steht über allem anderen auf der Seite.** Vor dem Zustand, vor der
  Liste. Es ist der einzige Ort im Panel, an dem Untätigkeit etwas rückgängig
  macht — wer hereinkommt, muss zuerst den Knopf sehen, der sie beendet.

Die Stufen folgen daraus, und sie sind hier nicht nach Gefühl gewählt:

| Aktion | Stufe | Warum |
|---|---|---|
| Regeln übernehmen | 2 | Die Probe nimmt einen Fehler von selbst zurück |
| ufw einschalten | 2 | ebenso — und die Frage nennt, was erreichbar bleibt |
| ufw **ausschalten** | **3**, Hostname | Die einzige der drei ohne Probe: Sie *öffnet* den Server, und dieser Zustand bleibt, bis jemand ihn ändert |

Dazu zwei Sicherungen, die keine Rückfrage ersetzen kann: Ohne Regel von überall
her für den Panel-Port wird das Einschalten **verweigert** (vor der Rückfrage,
nicht danach — danach wäre sie eine Einladung in eine Minute Ausfall), und die
Regel für diesen Port wird der Anfrage nicht überlassen, sondern ergänzt. In der
Oberfläche steht sie unveränderlich da; ein gesperrtes Feld ist aber eine Bitte,
keine Sperre.

**Was hier fehlt und bewusst offen bleibt:** Verlässt jemand die Seite, während
eine Frist läuft, warnt nichts. Der Rückbau findet trotzdem statt — das sichere
Ergebnis also auch —, aber niemand erfährt davon, bis er zurückkommt. Es zu
schließen hieße, den Probezustand in die Schale zu heben und auf jeder Seite
abzufragen; das ist eine Abfrage auf jeder Seite für einen Zustand, den es
selten gibt. Vertagt, nicht vergessen.

**Die Oberfläche bietet nur an, was gehen kann.** Festgelegt am Modul
**Dateien** — dem einzigen, dessen Ziel aus der Anfrage kommt und nicht aus einer
Allowlist, und dem einzigen, in dem ein Eintrag sichtbar sein kann, ohne
anfassbar zu sein.

Der Server rechnet je Eintrag aus, welche Handgriffe zu ihm passen
(`dateiAktionen`), und die Oberfläche zeigt nur diese. Das ist eine
**Bedienhilfe und keine Rechteprüfung** — verbindlich bleibt die Pfadwache in
privops, und ein selbstgebautes POST kommt an der Liste vorbei und an der Wache
nicht. Der Grund, sie überhaupt zu berechnen, ist ein anderer: Ein Knopf, der
zuverlässig in ein 403 oder 413 läuft, nennt den Fehler erst nach dem Klick, und
dann ist der Knopf schon der Fehler. Konkret heißt das:

- Ein **gesperrter** Eintrag steht in der Liste und ist als gesperrt benannt —
  ihn zu verstecken hieße, jemanden über den Inhalt seines Servers zu belügen.
  Sein Inhalt wird nie angefasst: kein Download, kein Editor, kein Kopieren.
- **Kopieren hängt am Ziel, nicht an der Quelle.** Aus `/usr/share` nach `/srv`
  zu kopieren ist erlaubt, obwohl die Quelle nicht beschreibbar ist.
- Der **Editor** erscheint nur unter seiner Größengrenze. Eine Logdatei von
  800 MiB im Browser zu öffnen ist kein Handgriff, sondern ein Absturz. Damit
  privops die Grenze nennen kann, ohne sie zu prüfen, gibt es `Files.Limits()`.
- **Anlegen und Hochladen** beziehen sich auf den stehenden Ordner und stehen
  deshalb über der Liste, nicht im Inspektor. Sie fehlen ganz, wo nicht
  geschrieben werden darf — und während einer Suche, weil die Trefferliste quer
  über Ordner steht und „hier anlegen" dann kein eindeutiges *Hier* hat.

**Der Ort ist ein Schritt im Verlauf.** Bei den Diensten ersetzt der Wechsel der
Auswahl den Verlaufseintrag; im Dateimanager wäre das falsch. Wer drei Ebenen
tief steht, will mit dem Zurück-Knopf eine Ebene höher und nicht aus der Seite
heraus. Dafür setzt der Router mehrere Parameter in *einem* Eintrag
(`weg.setzeAlle`): Ein Ordnerwechsel ändert Ort, Auswahl und Suchbegriff
gleichzeitig, und drei einzelne Schritte hätten den Zurück-Knopf in
Zwischenzustände geführt, die nie jemand gesehen hat.

**Gefiltert wird hier nicht im Browser.** Die Liste eines Verzeichnisses ist bei
zweitausend Einträgen gekürzt, und ein Browserfilter darüber behauptete „kein
Treffer" für eine Datei, die es gibt. Die Suche geht an den Server und findet
auch in Unterordnern — mit dem Ort am Treffer, weil ein Ergebnis quer über
Unterordner ohne ihn eine Sammlung von Namen ist, von denen keiner auffindbar
ist.

**Zwei Statuscodes des Editors, und warum sie nicht 409 sind.** Der Hash-Konflikt
antwortet **412**: In dieser Schnittstelle trägt 409 schon die Bedeutung
„unbestätigt, hier ist der Text der Rückfrage". Zwei Bedeutungen an einem Code
hätte die Oberfläche an einem Feld im Rumpf auseinanderhalten müssen, und die
Stelle, an der jemand das vergisst, wäre die, an der ein Konflikt als Rückfrage
erscheint und ein zweiter Klick die fremde Änderung überschreibt. Der Konflikt
hat außerdem **zwei** Auswege — die eigene Fassung durchsetzen oder die fremde
übernehmen —, und ein Dialog mit einem Knopf hätte den zweiten verschluckt.

Die Ablehnung durch das Prüfprogramm antwortet **400 mit eigenem Rumpf**: Ausgabe
des Programms wörtlich, dazu der Satz, was mit dem Vorzustand geschehen ist.
„Fehler beim Speichern" wäre hier die schädlichste Auskunft — der Bediener würde
erneut speichern.

**CodeMirror im Vite-Bundle, und der Nonce, der dazugehört.** Der Editor liegt
als eigener Brocken (`web/src/lib/editorkern.ts`, ~356 KiB) und wird über ein
dynamisches `import()` nachgeladen; das Hauptbündel bleibt bei ~166 KiB. Beim
ersten Anlauf stand hier die Annahme, CodeMirror trage seine Stilregeln über
CSSOM in ein vorhandenes Stylesheet ein und sei von `style-src 'self'` deshalb
nicht betroffen. **Das war falsch:** style-mod legt ein eigenes `<style>`-Element
an, Chromium verwirft es, und der Editor bleibt ungestylt. Gefunden hat es der
Browsertest, keine Überlegung — an genau dieser Stelle ist das Projekt schon
zweimal gescheitert. Der Ausweg ist derselbe wie auf der Editorseite der alten
Oberfläche: ein Nonce für dieses eine Element, je Antwort neu gezogen, in der
Richtlinie genannt und über `EditorView.cspNonce` weitergereicht. Er steht in
einem `<meta>` der Hülle und wird immer gesetzt, nicht nur bei offenem Editor —
die Hülle kommt einmal, der Editor öffnet später ohne neue Antwort, und ein
Nonce, der erst mit ihm käme, käme nie.

**Rechte am Endpunkt, sichtbar in der Oberfläche.** Schreibrecht und
Sitzungstoken werden vor jeder verändernden Anfrage geprüft (`X-CSRF-Token` als
Kopfzeile, nicht als Feld — eine Kopfzeile kann ein Formular von einer fremden
Seite nicht setzen). Wer nur Leserecht hat, bekommt die Schaltknöpfe gar nicht
angeboten und dazu den Satz, warum. Sie zu verstecken, ohne es zu sagen, sieht
wie ein halb gebautes Modul aus.

## 9. Architekturfolgen im Backend

- **`internal/httpd` teilt sich** in die API-Schicht (`/api/v1`, JSON, Tokens)
  und die schmale Restmenge server-gerenderter Seiten (vor Auth). Die
  Handler-Logik zieht in service-artige Funktionen, die API und — solange die
  Umstellung läuft — die alten Seiten gemeinsam nutzen.
- **Job-Modell** als eigenes Paket (`internal/jobs`): Registry, Verlauf im
  Store, Stream über den SSE-Hub. Pakete/Firewall ziehen um, alles Neue
  beginnt dort. **Beim Umschalten und nicht davor:** Die Schnittstelle nach
  außen steht seit dem Modul Pakete (siehe 4.2 und 8.4), die Verwaltung bleibt
  vorerst in `internal/httpd`, weil die alte Oberfläche sie mitbenutzt. Eine
  zweite Registry neben der ersten wäre ein Vorgang, der doppelt startet.
- **privops wächst um vier Familien** (`Docker*`, `Site*`, `Db*`, `Backup*`
  plus `Cron*/Timer*`) und bleibt die einzige Systemgrenze. Die
  Allowlist wächst um `docker`, `nginx`/`caddy`, `mysql`/`psql`, `restic`,
  `crontab` — jeweils absolute Pfade, `--format json` wo möglich.
- **Die Prozesstrennung** (unprivilegierter Webprozess, root-Agent über
  Unix-Socket), im privops-Interface seit jeher als Fluchtlinie vermerkt, wird
  mit dem Wachstum wichtiger, bleibt aber nach 1.0 — sie ändert nichts an der
  Operationsschnittstelle und kann deshalb warten, ohne dass etwas zweimal
  gebaut wird.
- **Store**: neue Tabellen für Jobs, API-Tokens, Backup-Ziele/-Läufe,
  Site-Metadaten; Migrationen wie gehabt.
- **Messwerte statt Budgets:** Binärgröße und RSS werden weiter je Release
  gemessen und im CHANGELOG genannt; die CI-Grenze wird auf das neue Maß
  gehoben (Richtwert: Binary < 40 MB, RSS-Leerlauf < 64 MB) statt gestrichen —
  eine Sperrklinke gegen Wildwuchs bleibt, nur die Klinke sitzt höher.

## 10. Meilensteine und Aufwand

Schätzung für eine Vollzeit-Person, nebenberuflich entsprechend länger. Die
0.4 ist bewusst der dickste Brocken — sie kauft allen späteren Stufen die
doppelte Arbeit ab.

| Stufe | Inhalt | Aufwand |
|---|---|---|
| Vorbau | Entwurfsmappe verabschieden, `web/`-Gerüst, Vite-Reproduzierbarkeit nachweisen, API-Skelett | ~1 Woche |
| 0.4 | Neue Oberfläche mit Parität, `/api/v1`, Job-Modell, Cron & Timer, API-Tokens | ~6–8 Wochen |
| 0.5 | Docker | ~3 Wochen |
| 0.6 | Webserver & Domains | ~3 Wochen |
| 0.7 | Datenbanken | ~2 Wochen |
| 0.8 | Backups | ~3 Wochen |
| 1.0 | externer Review, Befundbehebung, Doku, Screenshots | ~2 Wochen + Wartezeit |

**Summe: rund ein halbes Jahr Vollzeit.** Nach jeder Stufe ist etwas
Auslieferbares da; der Update-Kanal `beta` trägt die Zwischenstände wie bisher.

## 11. Risiken und Gegenmaßnahmen

| Risiko | Gegenmaßnahme |
|---|---|
| Die 0.4 wird zur Dauerbaustelle (Parität unterschätzt) | Parität ist als Liste der heutigen Seiten definiert und abzuhaken; kein neues Feature in 0.4 außer den drei Basis-Neuerungen |
| Docker-Modul wird zur Sicherheitslücke | Kein Socket-Durchgriff, Compose-Prüfer serverseitig, Stufe-3-Rückfragen, externer Review vor 1.0 |
| Webserver-Schreibpfad sperrt das Panel aus | Probe mit Frist und selbsttätigem Rückweg (Grundsatz VI); zusätzlich bleibt `asylum` über SSH der Rettungsanker |
| Vite baut nicht reproduzierbar | Nachweis in der ersten Woche (Vorbau), bevor irgendetwas darauf aufsetzt; notfalls Rollup-Konfiguration härten oder Hashes aus dem Dateinamen nehmen und über Manifest auflösen |
| npm-Lieferkette | Lockfile + `npm ci` + byteweiser Nachbau in CI + Lizenz-Allowlist; kein Laufzeit-CDN, strukturell durch CSP verhindert |
| Ohne-JS-Abkehr verprellt Bestandsnutzer | Vor Auth bleibt alles ohne JS; die Abkehr steht im CHANGELOG und in diesem Dokument, nicht im Kleingedruckten |
| Ein-Personen-Projekt versandet auf halber Strecke | Stufenschnitt so, dass jede Fassung für sich nützlich ist; 0.4 ersetzt den Bestand vollständig, bevor Neues beginnt |

## 12. Folgearbeiten an bestehenden Dokumenten

Dieses Dokument ändert Entscheidungen, die anderswo dokumentiert sind. Damit
die Doku nicht lügt, sind anzupassen:

- **[03-funktionsumfang.md](03-funktionsumfang.md):** Verweis auf die Revision
  der Nicht-Ziele (Abschnitt 2) und den neuen Stufenschnitt (Abschnitt 5);
  v0.2/v0.3-Abschnitte als überholt markieren.
- **[06-roadmap.md](06-roadmap.md):** Meilensteine ab 0.4 aus Abschnitt 10
  übernehmen; Qualitätsziele-Tabelle an Abschnitt 9 angleichen; festhalten,
  dass die bestehende Oberfläche eingefroren ist.
- **[15-neuordnung.md](15-neuordnung.md):** Vermerk, dass die Kommandobrücke
  durch den Leitstand abgelöst wird; die fünf Grundsätze bleiben in Kraft.
- **README:** Leitplanke „schlank und ressourcenschonend" umformulieren
  (Abschnitt 3), Scope-Beschreibung erweitern.
- **[02-architektur.md](02-architektur.md):** Die Sitzungszeile der
  Sicherheitstabelle nennt „absolute + idle Expiry" ohne Zahlen — Verweis auf
  Abschnitt 7.7 ergänzen. Dort steht außerdem `internal/auth` als Heimat der
  Sitzungen; tatsächlich liegen sie in `internal/store` und
  `internal/httpd`.

**Erledigt:** CONTRIBUTING trägt den Abschnitt zur Frontend-Werkzeugkette
(Node 22, `make ui`, Reproduzierbarkeit, die drei Regeln für `web/`) seit der
ersten Stufe.

Der Rest geschieht mit der Umsetzung der jeweiligen Stufe und nicht vorab —
sonst beschreibt die Doku einen Zustand, den es noch nicht gibt.
