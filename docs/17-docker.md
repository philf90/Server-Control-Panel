# 17 — Docker: Bauplan der Stufe 0.5

Dieses Dokument ist der Bauplan des Moduls Docker. Es beginnt als Plan und
wächst mit der Umsetzung in das Kapitel des Moduls hinein — Angriffsmodell,
Prüferregeln und Grenzen stehen am Ende hier und nicht in einer zweiten Datei.
Solange ein Abschnitt Vorhaben beschreibt und keinen Bestand, ist er als solcher
zu lesen.

## Anlass

`docs/16-neukonzeption.md` §5 nennt Docker als nächste Ausbaustufe nach dem
Fundament 0.4: „Container, Images, Volumes, Netzwerke, Compose-Stacks,
Container-Logs und -Statistiken", mit drei Vorgaben — Stacks sind das führende
Objekt, `privops` wächst um eine `Docker*`-Familie über die **CLI** statt über
eine Socket-Bibliothek, und fehlt Docker, bietet das Panel die Installation über
das Paketmodul an. §7.3 setzt den Rahmen: **wer den Socket hat, hat die
Maschine.**

Der Stand ist 0.4.1 — Parität erreicht, alte Oberfläche abgebaut, `/docker`
steht im Menü und führt auf die Seite „bald" mit der Zusage „ab 0.5".

Dieser Plan füllt §5 aus und vergleicht ihn mit **Arcane**
([getarcaneapp/arcane](https://github.com/getarcaneapp/arcane), SvelteKit + Go,
BSD-3), dem derzeit meistgenannten modernen Docker-Panel. Der Zuschnitt ist
gegenüber §5 **erweitert** — Ports, Events, Update-Prüfung und eine
Container-Shell kommen dazu. Das ist eine bewusste Entscheidung und verschiebt
den Aufwand von ~3 auf **~5–6 Wochen**.

---

## 1. Vergleich mit Arcane

Arcanes Oberfläche hat diese Seiten (`frontend/src/routes/(app)/`):
`dashboard, containers, images, volumes, networks, ports, projects, events,
updates, environments, swarm, settings, account, customize`. Das Backend
(`backend/internal/services/`, 94 Dateien) zeigt den Rest: `template_service`,
`image_update_service`, `container_registry_service`, `vulnerability_service`
(Trivy), `gitops_sync_service`, `build_service`, `webhook_service`,
`volume_backup`, `oidc_service`, `rbac`.

### Übernommen

| Aus Arcane | Warum es hier trägt |
|---|---|
| **Compose-first**: Projekte sind das führende Objekt, nicht einzelne Container | Bestätigt §5. Arcane setzt sich damit ausdrücklich von Portainer ab, und die Rückmeldungen geben ihm recht |
| **Ports-Seite** über alle Container | Hier sogar stärker als bei Arcane: Asylum kennt die Firewall. Die Seite kann sagen „auf 0.0.0.0:8080 veröffentlicht, ufw lässt es durch" — genau die Frage, die man sich auf einem VPS stellt |
| **Events-Ansicht** (Docker-Ereignisstrom) | Beantwortet „warum ist der Container um 3 Uhr neu gestartet". Die Mechanik ist fertig: dasselbe Muster wie `LogsFollow` |
| **Update-Prüfung** über Registry-Digests | Arcanes stärkste Idee. Hier **nur als Auskunft**, siehe Entscheidung E5 |
| **Prune mit freigegebenem Platz** und `system df` | Der häufigste Wartungshandgriff. „12,4 GB freigegeben" ist die Antwort, die zählt |
| **Container-Detail als Inspektor** mit Logs, Statistik, Konfiguration, Mounts, Netzen | Deckt sich mit dem Werkbank-Muster aus §8.4 |
| **Minimalvorlagen** beim Anlegen eines Stacks | Kein Katalog — ein Gerüst mit Kommentaren, siehe E7 |
| **Container-Shell** | Auf ausdrücklichen Wunsch, mit den Auflagen aus E8 |

### Bewusst nicht übernommen

| Aus Arcane | Warum nicht |
|---|---|
| **Docker-Socket / Docker-SDK** (`DOCKER_HOST`, Socket-Proxy) | §5 legt die CLI fest: kein schwerer Baustein im Abhängigkeitsbudget, und **jede Aktion bleibt eine nachvollziehbare Kommandozeile im Konsolen-Echo**. Grundsatz IV überlebt nur so |
| **Swarm** (eigener Arbeitsbereich, Services, Configs, Secrets) | Asylum verwaltet *einen* Server. Swarm ist ein Cluster-Produkt |
| **Environments / Agents** (mehrere Hosts über Tunnel) | Das ist Multi-Server, in §6 mit Begründung hinter 1.0 gestellt: das Trust-Modell fehlt |
| **GitOps** (`gitops_sync_service`, `git_repository_service`) | Ein eigenes Produkt mit eigenem Vertrauensmodell. Wer deklarativ arbeitet, ist bei Ansible besser aufgehoben (Nicht-Ziel in `03`) |
| **Vulnerability-Scans** (Trivy in einem privilegierten Container) | Ein Scanner, der `TRIVY_PRIVILEGED` kennt, ist eine zweite Angriffsfläche neben Docker. Kandidat für nach 1.0 |
| **Image-Build** (`build_service`, `build_workspace_service`) | Ein Panel, das baut, braucht einen Arbeitsbereich, eine Registry und eine Signaturfrage. Nicht in dieser Stufe |
| **Registry-Verwaltung** samt ECR-Token | Verschoben: 0.5 hält kein Betriebsgeheimnis, siehe E6 |
| **Automatisches Anwenden** von Updates | Ein Panel, das nachts von allein Images tauscht, ist ein Panel, das nachts von allein etwas kaputt macht |
| **OIDC, eigenes RBAC, Benachrichtigungen, Webhooks** | Asylum hat Rollen, Passkeys und API-Tokens; Benachrichtigungen stehen in §6 mit eigener Begründung |
| **Volume-Backups** | Gehört zu 0.8 (restic), nicht hierher |

**Der Unterschied im Betriebsmodell** gehört dazu: Arcane läuft selbst als
Container mit gemountetem Socket. Asylum läuft als systemd-Dienst als root und
ruft `docker` als Kommando. Damit fällt der ganze Themenkreis „Socket-Proxy,
Container-Ausbruch, PUID/PGID" weg — und dafür gilt: jede Docker-Operation
läuft mit vollen Rechten, und die einzige Grenze ist die Typisierung in
`privops`.

---

## 2. Grundentscheidungen

**E1 — `docker.io` aus den Distributionsquellen, nicht `docker-ce`.**
`docker-ce` verlangt das Einbinden von Dockers eigenem apt-Repository — das
wäre „ein Stack neben apt" und widerspricht §2. `DockerInstall` installiert
deshalb `docker.io` und, falls `docker compose` danach fehlt,
`docker-compose-v2`. Beide Namen stehen im Quelltext, nicht im Formular
(Vorbild: `FirewallInstall`, `internal/privops/firewall.go:128`).

**E2 — Ein Allowlist-Eintrag, kein zweiter für Compose.**
`"docker": {"/usr/bin/docker"}` in `internal/privops/exec.go:34`. Compose v2 ist
ein Unterkommando desselben Binaries. Ein `docker-compose`-v1-Eintrag wird
**nicht** aufgenommen; fehlt v2, sagt `DockerState` das und bietet die
Installation an.

**E3 — Verwaltet wird unter `/opt/asylum/stacks/`, Fremdes wird angezeigt.**
Geschrieben wird ausschließlich in eigene Verzeichnisse mit Marker in der
`compose.yaml` (Vorbild `cronMarker`, `internal/privops/cron.go:62`). Compose-
Projekte, die Docker sonst kennt (`docker compose ls --all`), erscheinen in der
Liste als **fremd**: lesbar, startbar/stoppbar, aber die Datei wird nie
geschrieben. Dieselbe Trennung wie bei nftables und fremden Crontabs. Ein
Adoptionsweg für beliebige Pfade ist **nicht** vorgesehen — er hieße, die
Pfadwache für beliebige Pfade zu öffnen.

**E4 — Der Compose-Prüfer läuft gegen die *gerenderte* Konfiguration.**
Das ist die wichtigste Einzelentscheidung dieser Stufe. `extends`, YAML-Anker,
`env_file` und `.env` können ein `privileged: true` an einer Prüfung der
Rohdatei vorbeischmuggeln. Geprüft wird deshalb die Ausgabe von
`docker compose config` (die alles auflöst), und die Rohdatei nur zusätzlich auf
Formfehler. Einzelheiten in Abschnitt 4.

**E5 — Update-Prüfung ist eine Auskunft, kein Vorgang.**
Verglichen wird der lokale `RepoDigest` mit dem entfernten
(`docker manifest inspect`). Ergebnis ist ein Signal im Handlungsbedarf und ein
Knopf „Stack aktualisieren" (`compose pull` + `up -d`) — der Mensch drückt ihn.
**Ratengrenzen sind Teil des Entwurfs**, nicht ein Nachgedanke: Docker Hub
zählt anonyme Abfragen, deshalb höchstens ein Lauf je Tag, Ergebnis im Store
zwischengespeichert, Fehler („rate limit") wird angezeigt statt wiederholt.

**E6 — 0.5 hält kein Betriebsgeheimnis.** Gezogen wird aus öffentlichen
Registries. Ein vorhandenes `docker login` des Betreibers wird *gelesen*
(`~/.docker/config.json` — nur, ob ein Eintrag existiert, nie der Inhalt) und
als Auskunft angezeigt. Die verschlüsselte Geheimnisverwaltung aus §7.5 entsteht
mit 0.8, wo sie unvermeidbar ist.

**E7 — Vorlagen sind ein Gerüst, kein Katalog.** Beim Anlegen eines Stacks
steht eine kommentierte `compose.yaml` im Editor, dazu zwei, drei Beispiele im
Binary (ein einzelner Dienst mit Volume, ein Dienst hinter einem Port, ein
Dienst mit `depends_on`). Kein gepflegter Katalog: der wäre ein Inhaltsprojekt
mit eigener Vertrauens- und Pflegefrage.

**E8 — Die Container-Shell ist opt-in und hat eine Folge, die benannt gehört.**
Auf ausdrücklichen Wunsch gebaut, mit den Auflagen, die §6 dem Web-Terminal
gesetzt hat: **nur Owner**, **Schalter in der Konfigurationsdatei** (nicht in
der Oberfläche), jede Sitzung im Audit mit Container, Dauer und Befehl.

> Die Folge, die dazugehört: Damit entsteht die schwierigste Hälfte des
> Web-Terminals — PTY-Anbindung, bidirektionaler Transport, Terminal-Emulation
> im Browser. Das Argument aus §6, das Terminal hinter 1.0 zu stellen, verliert
> danach seine technische Begründung und behält nur die
> sicherheitspolitische. Der externe Review vor 1.0 muss diesen Pfad
> mitprüfen — und das gehört in §6 nachgetragen, statt es stillschweigend
> stehenzulassen.

---

## 3. Die privops-Familie

Neue Datei `internal/privops/docker.go` (Zustand, Container, Images, Volumes,
Netze), `internal/privops/compose.go` (Stacks), `internal/privops/composepruef.go`
(der Prüfer). Methoden am `Executor`-Interface in `internal/privops/privops.go:21`
ergänzen — der Compile-Time-Nachweis `var _ Executor = (*System)(nil)`
(`system.go:22`) erzwingt danach, dass `fakeOps` in
`internal/httpd/system_test.go:14` mitwächst.

| Operation | Kommando | Anmerkung |
|---|---|---|
| `DockerState` | `dpkg-query`, `systemctl is-active docker`, `docker version --format json`, `docker compose version` | Rückgabetyp mit `Installed`, `DaemonLaeuft`, `ComposeVerfuegbar`, `Notice` — drei unterscheidbare Zustände wie `FirewallState` (`privops.go:235`) |
| `DockerInstall` | `apt-get install --yes -- docker.io [docker-compose-v2]` | Paketnamen hartkodiert, Job, `longTimeout` |
| `DockerContainers` | `docker ps --all --format json` | NDJSON, nicht Array |
| `DockerContainer` | `docker inspect --format {{json .}} -- <id>` | |
| `DockerContainerAction` | `docker <start\|stop\|restart\|pause\|unpause> -- <id>` | Eigener Typ `ContainerAction` + `ValidContainerAction`, Vorbild `ServiceAction` (`privops.go:113`) |
| `DockerContainerRemove` | `docker rm [--volumes] -- <id>` | |
| `DockerContainerLogs` | `docker logs --timestamps --tail N -- <id>` | |
| `DockerLogsFollow` | `docker logs --follow …` | `OhneFrist: true`, Muster `LogsFollow`, Folgerbegrenzung + Herzschlag |
| `DockerStats` | `docker stats --no-stream --format json` | Ausdrücklich **ohne** Dauerstrom — die Seite pollt |
| `DockerImages` / `…Remove` / `…Pull` | `docker image ls --all --format json`, `image rm`, `image pull` | Pull ist ein Job |
| `DockerVolumes` / `…Remove` | `docker volume ls --format json`, `volume rm` | |
| `DockerNetworks` / `…Remove` | `docker network ls --format json`, `network rm` | |
| `DockerPrune` | `docker <art> prune --force [-a]` | Art als Allowlist-Typ; freigegebener Platz aus der Ausgabe |
| `DockerDiskUsage` | `docker system df --format json` | |
| `DockerEventsFollow` | `docker events --format json` | wie `LogsFollow` |
| `DockerImageDigest` | `docker manifest inspect <ref>` | für E5 |
| `StackList` | `docker compose ls --all --format json` + eigenes Verzeichnis | Verschmolzen, Feld `Verwaltet bool` |
| `StackRead` / `StackWrite` | eigene Pfadwache | Marker, Sicherung vor dem Schreiben, atomarer Tausch |
| `StackConfig` | `docker compose -f … config` | liefert die gerenderte Fassung für E4 |
| `StackUp/Down/Pull/Restart` | `docker compose -p <name> -f <datei> …` | Jobs mit Strom |
| `DockerExec` | `docker exec -it -- <id> <shell>` | siehe Schritt 8 |

**Was aus dem Bestand wiederverwendet wird, nicht neu gebaut:**

- `Runner`/`ExecRunner` mit fester Umgebung (`LC_ALL=C` ist die Voraussetzung
  für jeden Parser) — `internal/privops/exec.go:85`
- `LineWriter` für Ströme in die Vorgangsplatte — `privops.go:100`
- Das Journal als Dekorator um den *Runner* — `internal/privops/journal.go:121`.
  Jede Docker-Zeile landet damit ohne Zutun im Konsolen-Echo. Registry-
  Zugangsdaten dürfen nur über `Stdin` gehen, dann greift die Verdeckung von
  selbst (`journal.go:158`)
- `firewallGuard` als Vorbild, falls ein Stack den Panel-Port belegt —
  `internal/httpd/jobs.go:219`
- `ConfigCheckResult{Checked, OK, Tool, Output}` — `internal/privops/configcheck.go:22`
- `PruefeName` für Stack-Namen — `internal/privops/pfadwache.go:493`
- `os.Root`-Auflösung statt Zeichenkettenarithmetik — `pfadwache.go:173`

---

## 4. Der Compose-Prüfer — der sicherheitskritische Kern

Eigene Datei `internal/privops/composepruef.go`, geprüft wird die **gerenderte**
Konfiguration (E4), geparst mit `gopkg.in/yaml.v3` — schon direkte
Abhängigkeit, keine neue.

**Abgelehnt (400, mit Nennung von Dienst und Feld):**

| Fund | Warum |
|---|---|
| `privileged: true` | root auf dem Host, ohne Umweg |
| `pid: host`, `ipc: host`, `userns_mode: host` | Namensraum-Ausbruch |
| `network_mode: host` | umgeht jede Portveröffentlichung und die Firewall-Sicht |
| `devices:` | Rohzugriff auf Geräte |
| `cap_add` mit `SYS_ADMIN`, `SYS_PTRACE`, `SYS_MODULE`, `DAC_READ_SEARCH`, `ALL` | Ausbruchsprimitiven |
| `security_opt` mit `apparmor:unconfined`, `seccomp:unconfined` | schaltet die Absicherung ab |
| Bind-Mount auf `/var/run/docker.sock` | reicht die Maschine an den Container weiter — der Fall aus §7.3 |
| Bind-Mount auf einen Pfad der Sperrliste (`/etc/shadow`, private Schlüssel, die Panel-Datenbank) | derselbe Grund wie im Dateimanager |

**Stufe 3 (getippter Stack-Name), nicht abgelehnt:**
Bind-Mount auf einen Pfad **außerhalb** des Stack-Verzeichnisses. Das ist ein
legitimer, häufiger Fall (`/srv/daten:/data`) und zugleich der Weg, über den ein
Container an fremde Daten kommt.

**Zwei Eigenschaften, die der Prüfer haben muss:**

1. **Er ist Grenze und Bedienhilfe zugleich.** Er läuft serverseitig vor jedem
   `up`, nicht im Formular. Im Editor läuft er zusätzlich beim Speichern als
   Auskunft — dieselbe Funktion, damit es nicht zwei Auslegungen gibt.
2. **Unbekannte Felder sind kein Freibrief.** Findet der Prüfer eine
   Compose-Version oder ein Feld, das er nicht kennt, sagt er das („nicht
   geprüft"), statt „in Ordnung" zu melden — dieselbe Haltung wie
   `configcheck.go:17`.

---

## 5. Bestätigungsstufen

Nach `docs/14-bestaetigungen.md`; Abweichungen sind dort einzutragen.

| Aktion | Stufe | Begründung |
|---|---|---|
| Container starten, neu starten, pausieren | 1 | umkehrbar |
| Container stoppen | 2 | nennt, was nicht mehr erreichbar ist |
| Container entfernen (gestoppt) | 2 | |
| Container entfernen (läuft) | 3, Containername | |
| Image entfernen | 2 | erneut ziehbar |
| `image prune -a` | 2 mit Zahl **und** Größe | „alle 34 Images, 12,4 GB" statt „alle" |
| Volume entfernen | **3, Volumename** | Daten weg, kein Rückweg — die schärfste Einzelaktion des Moduls |
| `volume prune` | **3, Hostname** | löscht alle ungenutzten Volumes des Servers; systemweit, deshalb Hostname (Muster: Neustart, `api_v1_pakete.go:321`) |
| Netz entfernen | 2 | |
| Stack starten | 1 — **außer** der Prüfer meldet einen Bind-Mount nach außen, dann 3 mit Stack-Name | |
| Stack stoppen | 2 | |
| Stack stoppen **mit Volumes** | 3, Stack-Name | dasselbe Argument wie beim Volume |
| Stack löschen (Verzeichnis) | 3, Stack-Name | |
| Shell öffnen | 2, plus Audit-Eintrag beim Öffnen und Schließen | |

---

## 6. Die Oberfläche

Eine Seite `/docker` mit vier Reitern, statt fünf Menüpunkten — die
Seitenleiste hat schon achtzehn Ziele:

| Reiter | Inhalt |
|---|---|
| **Stacks** (Vorgabe) | Werkbank: Liste (verwaltet/fremd, Dienste, Zustand) + Inspektor mit Diensten, Editor, Vorgangsplatte |
| **Container** | Werkbank: Liste + Inspektor mit Logs, Statistik, Konfiguration, Mounts, Netzen, Ports |
| **Bestand** | Images, Volumes, Netze; `system df` oben; Aufräumen je Art |
| **Ports** | Alle veröffentlichten Ports quer über Container, abgeglichen mit der Firewall |

Dazu die Ereignisansicht als aufklappbarer Bereich (Muster: Verfolgen in
`Logs.svelte`), nicht als eigener Reiter.

**Neue Dateien:** `web/src/seiten/Docker.svelte` und
`web/src/komponenten/Composeeditor.svelte`. Wiederverwendet werden
`Inspektor.svelte`, `Rueckfrage.svelte`, `Vorgangsplatte.svelte`,
`StatCard.svelte`, `lib/vorgang.svelte.ts`, `lib/editorkern.ts` (CodeMirror ist
schon im Bündel).

**Fehlt Docker**, zeigt die Seite genau eine Karte mit dem Zustand und dem Knopf
„Docker installieren" — die Antwort, die ufw seit `rc.4` gibt, statt einer
Kommandozeile zum Abtippen.

**Navigation umstellen** (die fünf Stellen, die der Cron-Umstieg auch anfasste):
`web/src/lib/ziele.ts` → `neu: true`; `web/src/lib/weg.svelte.ts` → `docker` von
`angekuendigt` nach `gebauteSeiten`, `type Seite` erweitern;
`internal/httpd/handlers_v2.go:155` → `"docker"` in den Block „Gebaute Seiten";
`web/src/seiten/Bald.svelte` → `docker`-Ersatzweg entfernen;
`web/src/App.svelte` → Import und Zweig.

---

## 7. Rollen, Audit, Signale

**Rollen.** Lesen: jede Rolle. Schreiben: **Owner** — nicht Admin. Die
Begründung ist dieselbe, mit der Cron-Schreiben Owner verlangt
(`internal/httpd/routes.go:225`): ein Compose-Stack ist Codeausführung als root.
Verdrahtung `s.protected(s.apiOwner(s.apiSchreibend(…)))`. `tokenFamilien`
(`internal/httpd/tokenauth.go:71`) um `"docker"` ergänzen, sonst ist das Modul
für API-Tokens zu.

**Audit.** Aktionsnamen punktiert: `docker.stack.up`, `docker.stack.down`,
`docker.container.stop`, `docker.image.prune`, `docker.volume.remove`,
`docker.exec.open`, `docker.install`. Bei Jobs zweimal — „gestartet" über
`s.audit`, das Ende über `s.auditNachtraeglich` (`api_v1_pakete.go:360`).

**Signale** in `dashboardSignals` (`internal/httpd/handlers_app.go:19`), mit
`ActionHref: "/docker"`:

- `crit`: Container mit Exit-Code ≠ 0 beendet; Container in einer Neustartschleife
- `warn`: Container `unhealthy`; Stack nur teilweise oben; neues Image verfügbar (aus dem Zwischenspeicher, **nie** ein Registry-Aufruf in der 3-Sekunden-Frist)

Der Test `TestAPISignaleVerweisenAufDieNeueOberflaeche`
(`internal/httpd/api_dienste_test.go:464`) prüft, dass jeder Verweis in die neue
Fläche zeigt — `/docker` muss dort bestehen.

---

## 8. Umsetzung in neun Schritten

Jeder Schritt endet mit etwas, das läuft, und mit Tests.

| # | Schritt | Ergebnis |
|---|---|---|
| 1 | **Fundament**: Allowlist, `DockerState`, `DockerInstall`, Executor-Methoden, `fakeOps`, `GET /api/v1/docker`, Seitengerüst — **umgesetzt**, siehe unten | Das Modul existiert und kann Docker installieren |
| 2 | **Container**: Liste, Inspektor, Aktionen, Entfernen, Logs (Auszug und Verfolgen), Statistik — **umgesetzt**, siehe unten | Der Alltagsfall steht |
| 3 | **Bestand**: Images, Volumes, Netze, `system df`, Aufräumen je Art mit freigegebenem Platz — **umgesetzt**, siehe unten | Die häufigste Wartung |
| 4 | **Stacks lesend**: `compose ls`, eigenes Verzeichnis, Verschmelzung, Detail — **umgesetzt**, siehe unten | Auf einem Bestandsserver ist die Seite ab hier nicht leer |
| 5 | **Stacks schreibend**: Pfadwache, Marker, Editor, **Compose-Prüfer**, `up/down/pull/restart` als Jobs, Gerüstvorlagen — **umgesetzt**, siehe unten | Der gefährlichste Schritt — deshalb erst, wenn alles Lesende steht |
| 6 | **Ports & Events**: Portübersicht mit Firewall-Abgleich, Ereignisstrom — **umgesetzt**, siehe unten | Die zwei Adaptionen aus Arcane |
| 7 | **Update-Prüfung**: Digest-Abgleich, Zwischenspeicher, Ratengrenzen, Signal, „Stack aktualisieren" | Auskunft, kein Automat |
| 8 | **Container-Shell**: Schalter in der Konfiguration, Transport, Terminal, Audit je Sitzung | Siehe unten |
| 9 | **Härtung und Angriffsdurchgang**: Prüfer aushebeln versuchen, Pfadausbruch, Socket-Weitergabe; Messung von Binärgröße und Grundlast; Doku | Wie Phase 7 des Dateimanagers |

### Schritt 1 — Stand: umgesetzt

`privops` wächst um `DockerState` und `DockerInstall`, die Allowlist um genau
einen Eintrag (`docker`), und `/docker` ist ein gebautes Ziel statt eines
angekündigten. Die Seite zeigt drei Karten — Laufzeit, Daemon, Compose —, dazu
den Satz, was zu tun ist, und den Knopf, der es tut.

**Drei Entscheidungen, die beim Bauen fielen:**

- **Der Rat steht in `httpd`, nicht in `privops`.** Ursprünglich trug
  `DockerState` ein Feld `Notiz` mit dem Satz „Docker fehlt, das Panel kann es
  einspielen" — dem Vorbild `FirewallState.Notice` folgend. Der Browsertest hat
  gezeigt, warum das falsch war: Die Attrappe füllte das Feld nicht, und die
  Seite zeigte im Zustand „nicht installiert" drei Karten ohne ein einziges Wort
  dazu. Ein Go-Test konnte das nicht finden — er prüfte das Feld, das er selbst
  gesetzt hatte. Jetzt leitet `dockerAnmerkung` den Satz aus dem Zustand ab,
  in derselben Schicht, die auch über den Knopf entscheidet. Die Trennung
  dahinter: **privops meldet Tatsachen, httpd macht daraus eine Empfehlung.**
- **Einspielbar ist nicht dasselbe wie „fehlt".** Bei totem Daemon hilft kein
  apt-Lauf, sondern ein Dienststart — dort steht deshalb kein Knopf, sondern
  ein Verweis auf die Dienstseite. Ein Knopf, der zuverlässig nichts bewirkt,
  verschiebt die Suche nach der Ursache um einen Fehlversuch.
- **Ohne Docker stehen Daemon und Compose auf „—".** Der erste Entwurf meldete
  dort „antwortet nicht" und „fehlt", beides farbig. Aus einem Befund wurden
  drei, zwei davon erfunden: Zu einem Programm, das es nicht gibt, ist keine der
  beiden Fragen gestellt.

**Gemessen** (gegen die CI-Grenzen): Binärgröße 17,2 MB (< 30), Abdeckung
`privops` 78,9 % (> 72), `httpd` 70,3 % (> 68), direkte Go-Abhängigkeiten
unverändert 6. Der Schritt kostet keine neue Abhängigkeit — `encoding/json` und
`strings` reichen.

**Noch nicht geprüft, und das ist die wichtigste offene Zeile:** Die Parser
laufen gegen aufgezeichnete Ausgaben, die aus der Dokumentation und nicht von
einem echten Docker stammen. `docker version --format {{json .}}`,
`docker compose version --short` und `dpkg-query` müssen auf einem Server mit
Docker gegengelesen und die `const`-Blöcke in
`internal/privops/docker_test.go` durch echte Ausgaben ersetzt werden. Solange
das aussteht, ist die Fassungserkennung eine begründete Annahme.

### Schritt 2 — Stand: umgesetzt

`privops` wächst um sieben Operationen (`DockerContainers`, `DockerContainer`,
`DockerContainerAction`, `DockerContainerRemove`, `DockerContainerLogs`,
`DockerContainerLogsFollow`, `DockerStats`), die Seite um die Werkbank nach
§8.4: Liste links, Inspektor rechts, Auswahl in der Adresse.

**Vier Entscheidungen, die beim Bauen fielen:**

- **Umgebungsvariablen werden gezählt, nicht ausgeliefert.** Sie tragen auf
  jedem zweiten Server ein Datenbankpasswort. Der Detailtyp nennt ihre Anzahl,
  und es gibt keinen Endpunkt, der die Werte hergäbe — dasselbe Argument wie
  bei der Sperrliste des Dateimanagers: Wer sie braucht, hat SSH. Ein Test
  prüft, dass der Wert aus der aufgezeichneten Beispielausgabe nirgends in der
  Antwort auftaucht.
- **„Auffällig" wird an einer Stelle entschieden** (`containerStufe`), und die
  Übersicht speist ihren Handlungsbedarf aus derselben Funktion. Zwei Fassungen
  liefen auseinander, und dann meldete die Übersicht einen Befund, den die
  Containerliste nicht kennt. Ein laufender, aber **ungesunder** Container ist
  dabei der schärfste Fall — er steht auf „läuft" und tut trotzdem nicht, wofür
  er da ist. Mit Code 0 beendet ist dagegen kein Befund: Ein einmaliger Auftrag
  darf nicht dauerhaft einen roten Punkt erzeugen.
- **Zustandswort und Statussatz bleiben roh.** „exited" und „dead" sind nicht
  dasselbe, und Dockers Satz („Exited (137) 2 days ago") trägt die Angabe, für
  die es kein Zustandswort gibt. Die Gesundheit steht bei `docker ps` nur in
  Klammern im Statussatz und wird von dort gelesen; fehlt sie, ist der Container
  **nicht gesund, sondern ungeprüft** — das Image bringt keine Prüfung mit.
- **Der Protokollauszug kommt mit dem Detail**, nicht als zweiter Aufruf. Wer
  einen Container anklickt, will wissen, was er sagt. Das Verfolgen ist ein
  eigener Strom nach dem Muster des Journals, mit Herzschlag, Folgerbegrenzung
  (vier) und gemeldeten verworfenen Zeilen — und mit eigener Zählung neben dem
  Journal, damit ein offenes Containerprotokoll nicht den Blick ins Journal
  versperrt.

**Zwei Fehler, die der Bau zutage gebracht hat**, beide in Testcode und beide
lehrreich genug für einen Vermerk:

- **`mussJSON` verlangt Status 200.** Auf eine 409-Antwort angewendet meldete es
  „erwartet 200" — die Fehlermeldung zeigte damit auf den Handler, während der
  Fehler im Test stand. Jetzt liest `rueckfrageAus` die Rückfrage, und der
  Statuscode gehört zur Erwartung.
- **Ein Deadlock in der Attrappe.** `record` nimmt dieselbe Sperre wie die
  Methode, in der es steht; ein zweiter Vermerk im gesperrten Abschnitt ließ den
  ganzen Testlauf hängen, bis `go test` ihn nach zehn Minuten abbrach.

**Gemessen:** Binärgröße 17,3 MB (< 30), Abdeckung `privops` 78,3 % (> 72),
`httpd` 69,8 % (> 68), direkte Go-Abhängigkeiten unverändert 6.

### Schritt 3 — Stand: umgesetzt

`privops` wächst um acht Operationen (Abbilder, Volumes, Netze je Liste und
Entfernen, dazu `DockerDiskUsage` und `DockerPrune`), die Seite um den
Bestandsblock: Platzbedarf oben, darunter die Aufräum-Handgriffe und die drei
Listen.

**Vier Entscheidungen, die beim Bauen fielen:**

- **Der Platzbedarf steht oben, „freigebbar" ist die Antwort.** `docker system
  df` nennt je Art, was ein Aufräumen brächte — und dieselbe Zahl steht in der
  Rückfrage: „12 Einträge, davon 5 in Gebrauch · 1.5GB freigebbar" statt
  „alle". Die Begründung ist die aus `docs/14`: „Alle Updates einspielen?"
  befähigt zu keiner Entscheidung, „alle 42" schon.
- **„In Gebrauch" rechnet der Server aus.** Ein Abbild, das ein Container
  benutzt, und ein Volume, das einer einhängt, bekommen keinen
  Entfernen-Knopf. Docker weigert sich in beiden Fällen, und ein Knopf, der
  zuverlässig in diese Weigerung läuft, ist selbst der Fehler. Dasselbe gilt
  für `bridge`, `host` und `none` — die legt Docker selbst an.
- **`docker system prune` fehlt in der Allowlist.** Es räumt Container, Netze,
  Abbilder und Baucache in einem Zug auf; eine Aktion, deren Umfang der
  Bedienende nicht überblickt, kann keine sinnvolle Rückfrage tragen. Es gibt
  fünf benannte Arten statt einer Sammelaktion.
- **Volumes aufzuräumen ist Stufe 3 mit dem HOSTNAMEN**, nicht mit einem
  Objektnamen: Es trifft jedes ungenutzte Volume des Servers auf einmal, und
  der häufigste Fehler bei einer solchen Aktion ist nicht der falsche Knopf,
  sondern der falsche Server. Ein einzelnes Volume zu entfernen bleibt Stufe 3
  mit seinem Namen; Abbild und Netz sind Stufe 2, weil sich beides
  wiederherstellen lässt — das eine durch Ziehen, das andere durch den nächsten
  Compose-Start.

**Zwei Befunde aus dem Bau:**

- **Abbild-Kennungen sind anders gebaut als Containernamen.** Der erste Anlauf
  hat für beides `ValidateContainerID` benutzt — und die lehnte `sha256:aaa`,
  `nginx:alpine` und `ghcr.io/o/n:1.2` ab, weil Doppelpunkt und Schrägstrich
  fehlten. Jetzt gibt es `ValidateImageRef` daneben; Leerzeichen, Semikolon und
  ein führender Bindestrich bleiben in beiden draußen.
- **Ein Quellentest nahm an, jede Datei habe genau eine Tabelle.**
  `TestKolspanDecktAlleSpaltenDerNeuenOberflaeche` maß alle `colspan` einer
  Datei an der Kopfzeile der ERSTEN Tabelle. Das ging, solange es so war — eine
  Eigenschaft, die sich ergeben hatte und keine Regel war. Der Bestand hat vier
  Tabellen, und der Test meldete drei Fehler, von denen keiner einer war.
  Geprüft wird jetzt je Tabelle; die Absicht ist dieselbe geblieben.

**Gemessen:** Binärgröße 17,3 MB (< 30), Abdeckung `privops` 77,4 % (> 72),
`httpd` 70,0 % (> 68), direkte Go-Abhängigkeiten unverändert 6.

### Schritt 4 — Stand: umgesetzt

`internal/privops/compose.go` mit `StackList` und `StackDatei`, zwei lesende
Routen (`GET /api/v1/docker/stacks`, `GET /api/v1/docker/stacks/{name}`) und die
Stackwerkbank über der Containerwerkbank. Rein lesend: Es gibt keinen Knopf, der
etwas ändert, und der Browsertest prüft ausdrücklich, dass keiner dasteht — ein
Editor ohne Compose-Prüfer wäre genau die Reihenfolge, die dieses Modul sich in
E4 verboten hat.

**Die Entscheidung, die alles andere trägt: Kein Pfad kommt je aus der
Anfrage.** Die Oberfläche nennt einen *Namen*; wo dessen Compose-Datei liegt,
sagt entweder Docker (`compose ls --all --format json`) oder das verwaltete
Verzeichnis. `StackDatei` schlägt den Namen deshalb erst in `StackList` nach und
liest dann den Pfad, den die Liste nennt. Käme der Pfad aus der Anfrage, wäre
dieser Endpunkt ein Weg, jede Datei des Servers zu lesen — und die Pfadwache des
Dateimanagers stünde daneben, ohne dass ihn jemand fragt. Die Frage stellt sich
so gar nicht erst.

**Vier weitere Entscheidungen, die beim Bauen fielen:**

- **Der Marker entscheidet über „verwaltet", nicht der Ort.** Wer von Hand ein
  Verzeichnis unter `/opt/asylum/stacks/` anlegt, hat es damit nicht dem Panel
  überschrieben — es fehlt die erste Zeile `# Vom Panel verwaltet — Modul
  Docker.` Dasselbe Muster wie bei den Crontabs (`cron.go`), und aus demselben
  Grund: Der Ort allein ist eine Vermutung, der Marker eine Aussage.
- **Zwei Quellen, eine Liste, und ein Ausfall an einer Quelle beendet die
  Auskunft nicht.** Ohne Compose gibt es keine Projekte von Docker, aber
  vielleicht Verzeichnisse; umgekehrt kennt Docker Projekte, die nirgends
  liegen. Ein Stack, den das Panel angelegt hat und der noch nie lief, ist ein
  Zustand („nicht gestartet") und kein Fehler.
- **Die Dienstnamen kommen aus den Compose-Labels der Container, nicht aus der
  Datei.** Ein YAML zu zerlegen, nur um Namen anzuzeigen, hieße einen zweiten
  Compose-Parser neben dem Prüfer aus Schritt 5 zu halten — und zwei Parser
  desselben Formats laufen auseinander. Ein nie gestarteter Stack hat deshalb
  keine Dienstnamen; seine Datei steht im Inspektor daneben.
- **Der auffällige Fall ist der HALBE Stack.** `stackStufe` meldet nur „warn"
  für ein Projekt, von dem ein Teil läuft: Das ist der Zustand, der aussieht wie
  „läuft" und keiner ist. Ein ganz gestoppter Stack ist meistens Absicht — wer
  ihn heruntergefahren hat, weiß das, und ein Ausrufezeichen dafür wäre Lärm.

**Ein Befund aus dem Bau:** Der Browsertest hatte die Containerwerkbank über
`.werkbank .tabelle` angesteuert — „die erste Werkbank der Seite". Das stimmte,
solange es nur eine gab. Mit der Stackwerkbank darüber hätte derselbe Selektor
weiterhin *bestanden* und dabei etwas anderes geprüft als gemeint, weil eine
falsche Tabelle immer noch eine Tabelle ist. Es gibt jetzt `klickeInTabelle`,
das die Tabelle über eine ihrer Spalten heraussucht; dieselbe Falle war in
Schritt 3 schon einmal zugeschnappt.

**Was in diesem Schritt bewusst offen bleibt:** Der Zustand eines fremden
Projekts lässt sich nicht bedienen — auch Starten und Stoppen nicht, obwohl E3
das vorsieht. Es kommt mit Schritt 5, zusammen mit den Vorgängen: `up` und
`down` sind Jobs mit Strom, und die Vorgangsplatte für Stacks gehört in
denselben Schritt wie der Prüfer, der vor jedem `up` läuft.

**Gemessen:** Binärgröße 17,3 MB (< 30), Abdeckung `privops` 77,6 % (> 72),
`httpd` 70,0 % (> 68), direkte Go-Abhängigkeiten unverändert 6.

### Schritt 5 — Stand: umgesetzt

Der gefährlichste Schritt, und damit ist die Stufe inhaltlich vollständig:
`internal/privops/composepruef.go` (der Prüfer), `internal/privops/composeschreib.go`
(Pfadwache, Marker, Vorgänge, Vorlagen), drei schreibende Routen und der
Compose-Editor über der Werkbank.

**Der Prüfer ist die Grenze, und er läuft davor.** Jeder Weg, der eine Datei
schreibt oder einen Container startet, geht durch `StackSchreiben`
beziehungsweise `StackAusfuehren` — und beide rufen dieselbe Funktion. Ein
abgelehnter Stack landet nie auf der Platte, auch nicht kurz und auch nicht als
Sicherung: Der Text liegt während der Prüfung als temporäre Datei im
Stack-Verzeichnis (dort lösen sich `.env` und relative Pfade wie im Betrieb auf)
und wird bei einer Ablehnung entfernt, statt umbenannt zu werden.

**Sieben Entscheidungen, die beim Bauen fielen:**

- **Geprüft wird die gerenderte Fassung** — Entscheidung E4, und der Grund dafür
  steht jetzt als Testfall da: Ein YAML-Anker mit `<<: *basis` bringt ein
  `privileged: true` an jeder Prüfung der Rohdatei vorbei, weil das Wort unter
  keinem Dienst steht. Compose löst das beim Rendern auf; `yaml.v3` tut beim
  Einlesen dasselbe.
- **Die Rohprüfung bleibt trotzdem, und zwar davor.** Rendern liest fremde
  Dateien: `extends: {file: …}` und `env_file:` ziehen eine beliebige YAML in die
  Ausgabe, und die Ausgabe zeigt das Panel an. Ohne Vorprüfung wäre der Prüfer
  selbst der Weg, `/etc/asylum/config.yaml` zu lesen. Beide Felder sind deshalb
  auf das Stack-Verzeichnis beschränkt. **Das ist der Befund, den dieser Schritt
  gebracht hat** — er stand in keiner Planungszeile.
- **Ein Befund erklärt sich.** Eine Ablehnung antwortet mit 400 UND mit Dienst,
  Feld, Wert und Grund je Fund. „Der Stack wurde abgelehnt" schickte jemanden auf
  die Suche in einer Datei, die er gerade geschrieben hat.
- **Ein Bind-Mount nach draußen ist kein Fehler, sondern eine Frage** — Stufe 3
  mit dem getippten Stack-Namen, und die Frage nennt jeden Pfad einzeln. Ein
  Stack ohne solchen Mount startet ohne jede Rückfrage: Eine Frage, die immer
  kommt, wird weggeklickt, und dann wird auch die weggeklickt, die zählt.
- **`uts: host` wird NICHT abgelehnt**, obwohl es ein geteilter Namensraum ist.
  Es teilt den Hostnamen und öffnet keinen Weg nach draußen. Eine Prüfung, die
  auch Harmloses ablehnt, wird umgangen statt gelesen. `pid`, `ipc`,
  `userns_mode` und `cgroup` bleiben drin — über die Cgroup-Hierarchie lässt sich
  auf dem Wirt Code ausführen.
- **Für fremde Projekte gilt derselbe Prüfer.** Das ist eine bewusste Härte: Ein
  Bestandsprojekt mit `privileged: true` lässt sich über das Panel nicht starten,
  auch wenn es gerade läuft. Die Alternative wäre schlimmer — ein Prüfer, der bei
  fremden Dateien nachgibt, prüft genau die nicht, die niemand geschrieben hat,
  der die Regeln kennt. Was das Panel nicht tut, sagt es: Der Befund steht mit
  Dienst, Feld und Grund im Vorgangsauszug.
- **`down` läuft ohne Prüfung.** Etwas anzuhalten war nie das Problem, und ein
  Stack, den man wegen eines Befundes nicht mehr stoppen könnte, wäre die Falle,
  in die eine zu eifrige Prüfung führt.

**Ein Vorgang, der abgelehnt wird, endet gescheitert.** `StackAusfuehren` gibt
eine Ablehnung als *Ergebnis* zurück und nicht als Go-Fehler — die Schicht
darüber macht daraus einen Fehler am Vorgang und schreibt die Befunde in den
Auszug. Ohne diesen Schritt endete der Vorgang als „erfolgreich", während der
Stack nicht läuft; das ist die schlechteste Auskunft von allen.

**Fünf Befunde aus dem Bau:**

- **Die Attrappe schloss ihren Kanal zweimal — und der erste Anlauf zur Reparatur
  war schlimmer als der Fehler.** `stackDone` wurde bei jedem Aufruf geschlossen;
  ein Test mit zwei Vorgängen brachte den Lauf mit „close of closed channel" zum
  Absturz. Der Kanal wurde daraufhin beim Schließen auf nil gesetzt — womit der
  Test in einen Empfang auf einem **nil-Kanal** lief, und der blockiert für
  immer. Im gewöhnlichen Lauf fiel das nicht auf, weil das Zeitfenster klein war;
  gefunden hat es `go test -race`, das die Reihenfolge verschiebt. Jetzt schließt
  ein `sync.Once`, und der Kanal bleibt stehen.
- **Der Browsertest blieb beim Schließen des Editors stehen — ohne Meldung.**
  Der Effekt, der CodeMirror aufbaut, las `griff` und setzte ihn, und seine
  Aufräumfunktion setzte ihn zurück auf null: eine Schleife, die sich selbst
  nährt. Weil das Setzen aus einem asynchronen Rückruf kam, griff Sveltes
  Tiefenerkennung nicht — es gab keinen Fehler, die Seite drehte sich einfach
  weiter, bis die Testuhr ablief. Der Editor hat jetzt zwei Effekte statt einem,
  wie der des Dateimanagers.
- **Ein Bildschirmfoto konnte den ganzen Lauf anhalten.** Die Aufnahme der
  Docker-Seite mit offenem Editor kehrte nie zurück: Playwright versteckt vor
  jeder Aufnahme den Textcursor und wartet dafür auf ein ruhiges Bild, und ein
  blinkender Cursor ist eine endlose CSS-Animation. Alle 31 Aufnahmen des
  Browsertests laufen jetzt über eine Hilfe mit `animations: "disabled"`, einer
  Frist und einem Fang — ein Diagnosebild darf keine Prüfung kippen können.
- **Ein Test änderte den Text zwischen Frage und Antwort.** Die Prüfung „ein
  falsches getipptes Wort wirkt nicht" schickte beim zweiten Anlauf eine Datei
  ohne den Bind-Mount — dann kam die Frage zu Recht nicht mehr, und der Test
  bestand, ohne etwas zu prüfen. Er schickt jetzt denselben Text.
- **Ein Zustandsname verdeckte einen Parameter.** Im Editor hieß sowohl das
  „schon getippt"-Kennzeichen als auch das bestätigende Wort einer Rückfrage
  `getippt`. In `speichern()` hätte das eine still verschluckte Bestätigung
  ergeben. Das Kennzeichen heißt jetzt `bearbeitet`.

**Was in diesem Schritt bewusst NICHT entstanden ist:** ein Vorlagenkatalog
(E7 — drei kommentierte Gerüste im Binary, kein gepflegtes Inhaltsprojekt),
Registry-Zugangsdaten (E6 — 0.5 hält kein Betriebsgeheimnis) und ein Adoptionsweg
für fremde Verzeichnisse (E3 — er hieße, die Pfadwache für beliebige Pfade zu
öffnen).

### Schritt 6 — Stand: umgesetzt

Zwei Flächen, zwei Adaptionen aus Arcane: die Portübersicht mit
Firewall-Abgleich und der Ereignisstrom. `internal/privops/dockerevents.go`
(Parser für beide), `internal/httpd/api_v1_docker_ports.go` (Urteil und Strom),
`Ports.svelte` und `Ereignisse.svelte`.

**Die Portseite sagt etwas Unbequemes, und das ist ihr ganzer Zweck: Docker geht
an ufw vorbei.** Wer einen Container mit `-p 8080:80` veröffentlicht, ist auf
8080 aus dem Internet erreichbar — auch wenn ufw läuft und diesen Port nicht
kennt. Der Grund ist die Reihenfolge der iptables-Ketten: Docker trägt seine
Weiterleitung in FORWARD ein, bevor die Kette von ufw drankommt. Auf einem VPS
ist das die häufigste Fehlvorstellung überhaupt — „ich habe eine Firewall" und
„der Port ist zu" sind zwei verschiedene Aussagen, und nur die erste stimmt.

Ein Panel, das hier einen grünen Haken zeigte, weil ufw läuft, wäre schlimmer
als eines ohne diese Seite. Es gibt deshalb vier Urteile und nicht zwei:

| Urteil | Bedeutung |
|---|---|
| `lokal` | auf 127.0.0.1 gebunden — von außen kommt niemand heran, unabhängig von jeder Firewall |
| `offen` | von überall erreichbar, und ufw hat eine Regel dafür: bewusst geöffnet |
| `unbemerkt` | von überall erreichbar, OHNE dass ufw ihn kennt — **der Befund dieser Seite** |
| `ohnewache` | von überall erreichbar, und es läuft keine Firewall: nichts zu vergleichen |

Der Unterschied zwischen den letzten beiden ist kein Detail: Im einen Fall irrt
sich jemand über seine Firewall, im anderen hat er keine.

**Vier weitere Entscheidungen, die beim Bauen fielen:**

- **Nur laufende Container.** Die Ports-Spalte von `docker ps --all` trägt bei
  einem gestoppten Container noch die alte Angabe. Sie als offenen Port zu
  zeigen wäre eine Unwahrheit — und zwar eine beunruhigende.
- **Ein Eintrag ohne `->` ist nicht veröffentlicht.** `80/tcp` sagt nur, worauf
  der Container selbst hört; vom Wirt aus ist er nicht erreichbar. Eine
  Portübersicht, in der Ports stehen, die keiner erreicht, ist keine.
- **IPv4 und IPv6 sind EINE Veröffentlichung.** Docker schreibt
  `0.0.0.0:8080->80/tcp, :::8080->80/tcp` — zwei Zeilen dafür wären eine
  Verdopplung, die niemand erklären kann. Zusammengefasst gewinnt die offenere
  Bindung: Ist eine der beiden Familien von überall erreichbar, ist der Port von
  überall erreichbar.
- **Der Ereignisstrom beginnt zugeklappt.** Er hält einen `docker`-Prozess auf
  dem Server, und dafür soll niemand zahlen, der die Seite nur geöffnet hat.
  Gefiltert wird auf dem Wirt und nicht im Browser: Ein ungefilterter Strom
  schreibt auf einem Server mit vierzig Containern bei jedem Gesundheitscheck
  eine Zeile.

**Der Befund aus dem Bau — und er kam wieder aus dem Bildschirmfoto:** Die
Begründung des Urteils stand als ganzer Satz in der Tabellenzelle und wurde am
rechten Rand **abgeschnitten** — ausgerechnet bei dem Befund, wegen dessen es
die Seite gibt. Dazu wiederholte sie sich in jeder Zeile derselben Art. Es gibt
jetzt zwei Felder: ein kurzes Urteil für die Spalte („aus dem Netz, ohne Regel")
und die Begründung, die einmal über der Tabelle steht und am Feld als Titel
hängt. Kein Go-Test hätte das gefunden — sie prüfen den Text, nicht seine
Breite.

**Was hier bewusst NICHT entstanden ist:** ein Signal im Handlungsbedarf für
offene Ports ohne Regel. Es wäre naheliegend und billig — beide Aufrufe macht
`dashboardSignals` ohnehin —, steht aber nicht in der Signalliste aus §7. Es
gehört in dieselbe Runde wie das Update-Signal aus Schritt 7, damit die Liste
einmal und begründet wächst statt nebenbei.

**Zu Schritt 8, offen und vor dem Bau zu entscheiden:** Der Transport. Das
Panel hat heute nur SSE. Ein PTY braucht bidirektional. Empfehlung:
`github.com/coder/websocket` (klein, ohne eigene Abhängigkeiten) — damit stiege
das direkte Abhängigkeitsbudget von 6 auf 7, weit unter der Grenze von 25. Dazu
im Frontend ein Terminal-Emulator (xterm.js, ~250 KiB als eigener Brocken wie
`editorkern.ts`, dynamisch nachgeladen, mit demselben Nonce-Weg wie CodeMirror).
Die billigere Alternative — ein nicht-interaktives „Befehl absetzen, Ausgabe
lesen" ohne PTY und ohne WebSocket — steht in Abschnitt 11.

---

## 9. Dokumentation

Ein Modul dieser Größe bekommt im Repo ein eigenes Kapitel (Vorbild
`13-dateimanager.md`, `11-passkeys.md`):

- **Dieses Dokument** wächst mit jedem Schritt: Angriffsmodell, der
  Compose-Prüfer und seine endgültigen Regeln, warum CLI statt Socket, die
  Bestätigungsstufen, die Grenzen des Moduls, die Ratengrenzen der
  Update-Prüfung. Was am Ende hier steht, beschreibt Gebautes — nicht
  Vorhaben.
- `docs/16-neukonzeption.md`: §5 den 0.5-Block auf den Ist-Stand ziehen; §6 den
  Web-Terminal-Eintrag um die Folge aus E8 ergänzen; §7.3 um die endgültige
  Prüferliste
- `docs/14-bestaetigungen.md`: die Stufentabelle aus Abschnitt 5, Abweichungen
  begründet
- `docs/06-roadmap.md`: 0.5 als umgesetzt, Aufwand gegen die Schätzung stellen
- `web/src/lib/weg.svelte.ts` und `docs/16` §5 halten dieselben Fassungszahlen —
  beim Herausnehmen von `docker` bleiben `webserver`/`datenbanken`/`backups` auf
  0.6/0.7/0.8
- `CHANGELOG.md`, `README.md` (Modulliste im Statusblock)

---

## 10. Verifikation

**Go-Tests** (CI-Sperrklinken: `privops ≥ 72 %`, `httpd ≥ 68 %`):

- `internal/privops/docker_test.go` — Parser gegen **aufgezeichnete echte
  Ausgaben** als `const`-Blöcke mit Herkunftsangabe (Repo-Gewohnheit, siehe
  `modules_test.go:151`). Die genauen Formen von `docker ps --format json` &
  Co. **müssen von einer echten Docker-Installation abgenommen werden** — sie
  aus dem Kopf zu schreiben ist der sichere Weg zu einem Parser, der gegen die
  eigene Vorstellung passt und gegen Docker nicht.
- `internal/privops/composepruef_test.go` — je ein Fall pro Ablehnungsgrund,
  **und** die Umgehungsversuche: `extends`, YAML-Anker, `env_file`,
  Groß-/Kleinschreibung, Listenform vs. Kurzform bei `volumes`.
- Für jede Ablehnung: `len(f.calls) != 0` prüfen — es darf kein Kommando
  gelaufen sein.
- `internal/httpd/api_docker_test.go` — Sortierung, Zähler, Rückfrage kommt
  *und* führt nichts aus (Wirkung prüfen, nicht den Statuscode), falsches
  getipptes Wort wirkt nicht, Admin-Konto bekommt 403 auf Schreibrouten,
  unbekanntes JSON-Feld → 400.
- `internal/ui/leitstand_quellen_test.go` läuft mit: `data-spalte` an jeder
  Zelle, keine Inline-Stile, kein `confirm(`, SPA-Pfade stimmen zusammen.

**Browsertest**: neuer Abschnitt in
`internal/httpd/testdata/leitstand_e2e.js` + Feld in `ergebnisLeitstand`
(`internal/httpd/leitstand_e2e_test.go:22`). Geprüft: Reiterwechsel ohne
Neuladen, Auswahl in der Adresse, Zurück schließt den Inspektor,
Rückfragedialog erscheint und bleibt gesperrt bis zum richtigen Wort, kein
waagerechtes Scrollen bei 375 px, Bildschirmfoto bei `ASYLUM_E2E_SHOTS`.

**Von Hand, auf einem echten Server mit Docker** — das ist der Teil, den kein
Test ersetzt und der bei jeder bisherigen Stufe die eigentlichen Befunde
gebracht hat:

```bash
make check && make ui && make build
ASYLUM_LEITSTAND_E2E=1 ASYLUM_CHROMIUM=… go test ./internal/httpd -run Leitstand
sudo ./packaging/dev-deploy.sh    # auf die Testinstallation
```

Durchzuspielen: Docker über das Panel installieren; einen Stack anlegen,
starten, stoppen, löschen; einen Stack mit `privileged: true` anlegen und
ablehnen lassen; einen mit `-v /var/run/docker.sock` ebenso; einen mit
`/srv:/data` und die Stufe-3-Rückfrage sehen; ein fremdes Compose-Projekt
danebenlegen und prüfen, dass es lesbar, aber nicht schreibbar ist;
`volume prune` mit Hostname bestätigen; Container-Logs verfolgen; die
Portübersicht gegen `ufw status` halten.

**Messung** wie bei jeder Stufe, gegen den Stand ohne diesen Zweig auf
derselben Maschine: Binärgröße, RSS im Leerlauf, Abdeckung `privops`/`httpd`,
direkte Abhängigkeiten. Grenze heute: 30 MB Binary (CI), Richtwert neu 40 MB.

---

## 11. Risiken und offene Punkte

| Risiko | Gegenmaßnahme |
|---|---|
| **Der Compose-Prüfer lässt sich umgehen** — das teuerste Versagen dieser Stufe | Prüfung gegen die gerenderte Konfiguration (E4), eigener Angriffsdurchgang in Schritt 9, unbekannte Felder gelten als „nicht geprüft" |
| **Die CLI-Ausgaben ändern sich zwischen Docker-Fassungen** | `--format json` überall, Parser tolerant gegen unbekannte Felder, Mindestfassung in `DockerState` prüfen und benennen |
| **Die Container-Shell zieht das Web-Terminal vor** | Ausdrücklich in §6 nachtragen, opt-in per Konfigurationsdatei, Owner-Rolle, vollständiges Audit — und im externen Review vor 1.0 mitprüfen lassen |
| **Ratengrenzen der Registries** bei der Update-Prüfung | Höchstens ein Lauf je Tag, Zwischenspeicher, Fehler anzeigen statt wiederholen |
| **Die Stufe wird zur Dauerbaustelle** (5–6 statt 3 Wochen) | Neun Schritte, jeder für sich auslieferbar; nach Schritt 5 ist die Stufe inhaltlich vollständig — 6 bis 8 sind Zugaben und dürfen rutschen |
| **Ein Stack belegt den Panel-Port** | Der Prüfer warnt vor einer Kollision mit dem Panel-Port; bei Bedarf dieselbe Probe mit Frist wie bei der Firewall (`internal/httpd/jobs.go:219`) |

**Vor dem Bau zu entscheiden:**

1. **Transport der Shell** — WebSocket mit neuer Abhängigkeit und xterm.js,
   oder die nicht-interaktive Variante („Befehl absetzen, Ausgabe lesen", ohne
   PTY, ohne WebSocket, ohne neue Abhängigkeit). Letztere ist etwa ein Fünftel
   des Aufwands und deckt den Großteil der Fälle (`ls`, `cat`, `env`,
   `curl localhost`), taugt aber nicht für `top` oder einen Editor.
2. **Reiter oder eigene Menüpunkte** — vier Reiter unter `/docker` (Vorschlag)
   oder Docker/Container/Bestand als drei Ziele in der Gruppe „Apps".
3. **Ob `docker.io` reicht.** Auf Bestandsservern läuft häufig `docker-ce` aus
   Dockers Repo. Das Panel muss beides *erkennen* (das tut `DockerState` über
   das Binary, nicht über den Paketnamen) — installieren tut es nur `docker.io`.
