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
| 3 | **Bestand**: Images, Volumes, Netze, `system df`, Aufräumen je Art mit freigegebenem Platz | Die häufigste Wartung |
| 4 | **Stacks lesend**: `compose ls`, eigenes Verzeichnis, Verschmelzung, Detail | Auf einem Bestandsserver ist die Seite ab hier nicht leer |
| 5 | **Stacks schreibend**: Pfadwache, Marker, Editor, **Compose-Prüfer**, `up/down/pull/restart` als Jobs, Gerüstvorlagen | Der gefährlichste Schritt — deshalb erst, wenn alles Lesende steht |
| 6 | **Ports & Events**: Portübersicht mit Firewall-Abgleich, Ereignisstrom | Die zwei Adaptionen aus Arcane |
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
