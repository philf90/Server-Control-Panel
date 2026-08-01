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

> **Stand 0.5.1.** Dieser Abschnitt sah ursprünglich vier **Reiter** unter einer
> Seite vor. Gebaut wurde bis 0.5.0 eine einzige lange Seite mit sechs
> Abschnitten untereinander; mit 0.5.1 sind es fünf **Flächen mit eigener
> Adresse**, die als eingerückte Punkte unter „Docker" in der Seitenleiste
> stehen. Die Begründung für die dritte Fassung steht im Nachtrag am Ende von
> Abschnitt 8.

| Fläche | Adresse | Inhalt |
|---|---|---|
| **Stacks** (Vorgabe) | `/docker` | Werkbank: Liste (verwaltet/fremd, Dienste, Zustand) + Inspektor mit Diensten, Editor samt Formular, Vorgangsplatte |
| **Container** | `/docker/container` | Werkbank: Liste + Inspektor mit Logs, Statistik, Konfiguration, Mounts, Netzen, Ports — dazu der Ereignisstrom |
| **Ports** | `/docker/ports` | Alle veröffentlichten Ports quer über Container, abgeglichen mit der Firewall |
| **Image-Updates** | `/docker/updates` | Digest-Abgleich gegen die Registries, Zwischenspeicher, Ratengrenze |
| **Bestand** | `/docker/bestand` | Images, Volumes, Netze; `system df` oben; Aufräumen je Art |

Der Zustandskopf (Laufzeit, Daemon, Compose) steht über **allen** Flächen: Er
ist die Voraussetzung für alles darunter, und „der Daemon antwortet nicht" darf
man nicht durch einen Flächenwechsel verpassen.

Die Ereignisansicht bleibt ein aufklappbarer Bereich (Muster: Verfolgen in
`Logs.svelte`) und keine eigene Fläche — sie steht bei den **Containern**. Sie
beantwortet „warum ist der Container um 3 Uhr neu gestartet", und diese Frage
stellt man, während man den Container ansieht.

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
| 7 | **Update-Prüfung**: Digest-Abgleich, Zwischenspeicher, Ratengrenzen, Signal, „Stack aktualisieren" — **umgesetzt**, siehe unten | Auskunft, kein Automat |
| 8 | **Container-Shell**: Schalter in der Konfiguration, Transport, Terminal, Audit je Sitzung — **zurückgestellt**, siehe unten | Siehe unten |
| 9 | **Härtung und Angriffsdurchgang**: Prüfer aushebeln versuchen, Pfadausbruch, Socket-Weitergabe; Messung von Binärgröße und Grundlast; Doku — **umgesetzt**, siehe unten | Wie Phase 7 des Dateimanagers |

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

`privops` wächst um acht Operationen (Images, Volumes, Netze je Liste und
Entfernen, dazu `DockerDiskUsage` und `DockerPrune`), die Seite um den
Bestandsblock: Platzbedarf oben, darunter die Aufräum-Handgriffe und die drei
Listen.

**Vier Entscheidungen, die beim Bauen fielen:**

- **Der Platzbedarf steht oben, „freigebbar" ist die Antwort.** `docker system
  df` nennt je Art, was ein Aufräumen brächte — und dieselbe Zahl steht in der
  Rückfrage: „12 Einträge, davon 5 in Gebrauch · 1.5GB freigebbar" statt
  „alle". Die Begründung ist die aus `docs/14`: „Alle Updates einspielen?"
  befähigt zu keiner Entscheidung, „alle 42" schon.
- **„In Gebrauch" rechnet der Server aus.** Ein Image, das ein Container
  benutzt, und ein Volume, das einer einhängt, bekommen keinen
  Entfernen-Knopf. Docker weigert sich in beiden Fällen, und ein Knopf, der
  zuverlässig in diese Weigerung läuft, ist selbst der Fehler. Dasselbe gilt
  für `bridge`, `host` und `none` — die legt Docker selbst an.
- **`docker system prune` fehlt in der Allowlist.** Es räumt Container, Netze,
  Images und Baucache in einem Zug auf; eine Aktion, deren Umfang der
  Bedienende nicht überblickt, kann keine sinnvolle Rückfrage tragen. Es gibt
  fünf benannte Arten statt einer Sammelaktion.
- **Volumes aufzuräumen ist Stufe 3 mit dem HOSTNAMEN**, nicht mit einem
  Objektnamen: Es trifft jedes ungenutzte Volume des Servers auf einmal, und
  der häufigste Fehler bei einer solchen Aktion ist nicht der falsche Knopf,
  sondern der falsche Server. Ein einzelnes Volume zu entfernen bleibt Stufe 3
  mit seinem Namen; Image und Netz sind Stufe 2, weil sich beides
  wiederherstellen lässt — das eine durch Ziehen, das andere durch den nächsten
  Compose-Start.

**Zwei Befunde aus dem Bau:**

- **Image-Kennungen sind anders gebaut als Containernamen.** Der erste Anlauf
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

### Schritt 7 — Stand: umgesetzt

`internal/privops/dockerupdate.go` (der Vergleich), `api_v1_docker_updates.go`
(Zwischenspeicher, Ratengrenze, Vorgang), ein Signal im Handlungsbedarf und der
Handgriff „Stack aktualisieren" (`compose pull` **und** `up` in einem Vorgang).

**Der wichtigste Befund dieses Schritts steht am Anfang, weil er den ganzen
Entwurf bestimmt hat: Der naheliegende Digest-Vergleich ist falsch.**

Ein gezogenes `nginx:alpine` trägt lokal die Kennung der **Manifestliste**
(`RepoDigests`). `docker manifest inspect --verbose` gibt für eine solche Liste
ein Feld je Plattform zurück, und jedes davon trägt die Kennung des
**Plattform-Manifests** — eine andere Kennung. Wer beide vergleicht, findet
immer einen Unterschied und meldet immer ein Update. Bei fast jedem Image.
Jeden Tag.

Eine Update-Prüfung, die so irrt, ist schlimmer als keine: Nach einer Woche
liest niemand mehr hin, auch nicht, wenn sie einmal recht hat. Die Regel des
Moduls lautet deshalb hier schärfer als sonst: **Ohne belastbaren Vergleich wird
„nicht geprüft" gemeldet, nie „veraltet".**

Daraus folgen zwei Wege:

1. **`docker buildx imagetools inspect`** nennt die Kennung der Manifestliste
   unmittelbar. Damit trägt der Vergleich auch bei Mehrarchitektur-Images.
   buildx ist ein Unterkommando desselben Binaries — kein neuer
   Allowlist-Eintrag, keine neue Abhängigkeit —, aber in Debian ein eigenes
   Paket und nicht überall da.
2. **`docker manifest inspect --verbose`** genügt nur bei einer Architektur.
   Kommt eine Manifestliste zurück, sagt das Panel „nicht geprüft" samt Grund
   und dem Hinweis auf `docker-buildx`.

**Was das praktisch heißt, und es gehört gesagt:** Auf einem Server ohne buildx
bleibt die Prüfung für die meisten Images ohne Ergebnis. Das ist die ehrliche
Fassung des Machbaren mit der Kommandozeile — und sie ist der Grund, warum die
Fläche drei Zahlen zeigt statt zwei. Die dritte, „nicht geprüft", ist die
ehrlichste.

**Vier weitere Entscheidungen:**

- **Die Ratengrenze liegt im Store, nicht im Speicher.** Höchstens ein Lauf je
  Tag; läge der Zeitpunkt im Speicher, setzte jeder Neustart des Panels ihn
  zurück, und ein Dienst, der oft neu startet, fragte dauernd. Der Zeitpunkt
  wird **auch bei einem Abbruch** gespeichert — sonst wäre die Grenze
  wirkungslos, gerade wenn sie zugeschlagen hat.
- **Eine Ratengrenze beendet den Lauf.** Weiterzufragen, nachdem die Registry
  abgewiesen hat, ist genau das Verhalten, gegen das die Grenze gerichtet ist.
- **Gefragt wird nur, was etwas bringt:** die Images laufender Container, je
  Image einmal, ohne die, die über eine Kennung angezogen wurden (`@sha256:…`
  kann sich nicht ändern). Zehn Container mit demselben Image sind eine
  Abfrage, nicht zehn — das ist der Unterschied zwischen innerhalb und
  außerhalb der Grenze.
- **Das Signal im Handlungsbedarf kommt aus dem Zwischenspeicher.** In der
  Drei-Sekunden-Frist von `dashboardSignals` wird nie eine Registry gefragt: Sie
  antwortet, wann sie will, und sie zählt jede Abfrage.

**Der Griff ist der Stack und nicht das Image.** `docker pull` allein ändert
nichts an dem, was läuft. „Stack aktualisieren" ist deshalb `pull` **und** `up`
in einem Vorgang — und in dieser Reihenfolge: Scheitert das Ziehen, wird nicht
hochgefahren. Ein `up` nach einem gescheiterten `pull` startete die alte Fassung
neu und sähe aus wie ein geglücktes Update.

**Zwei Befunde aus dem Bau, beide aus derselben Ecke:**

- **Zwei Überschriften „Images" auf einer Seite.** Der Bestand hatte schon
  eine; die Update-Prüfung brachte eine zweite. Sie heißt jetzt „Aktualität der
  Images".
- **Und dieselbe Doppelung als Testbefund:** Die Spalte „Image" gibt es nun in
  zwei Tabellen, und der Browsertest suchte „die Tabelle mit einer Spalte
  Image" — er nahm die falsche und **bestand weiter**. Ebenso `.hinweis`: Der
  Selektor meinte die Anmerkung unter den Zustandskarten und traf den
  Ratengrenzen-Hinweis der neuen Fläche. Das ist im Modul Docker jetzt der
  dritte Selektor dieser Art (nach `.aktionen` in Schritt 3 und `.werkbank` in
  Schritt 4) — **eine Fläche, die wächst, macht aus jedem allgemeinen Selektor
  eine stille Falschprüfung.**

**Gemessen:** Binärgröße 17,5 MB (< 30), Abdeckung `privops` 78,1 % (> 72),
`httpd` 72,0 % (> 68), direkte Go-Abhängigkeiten unverändert 6.

### Schritt 8 — Stand: zurückgestellt

Die Container-Shell wird vorerst nicht gebaut und gegebenenfalls später
nachgezogen. Die Stufe 0.5 ist damit ohne sie abgeschlossen.

**Was das für §6 bedeutet, gehört hierhin:** Entscheidung E8 hatte eine Folge
angekündigt — mit der Container-Shell entstünde die schwierigere Hälfte des
Web-Terminals (PTY-Anbindung, bidirektionaler Transport, Terminal-Emulation),
und das Argument aus §6, das Terminal hinter 1.0 zu stellen, verlöre danach
seine technische Begründung. **Diese Folge tritt nicht ein.** Das Terminal steht
weiterhin aus beiden Gründen hinter 1.0, dem technischen wie dem
sicherheitspolitischen, und der externe Review vor 1.0 muss diesen Pfad nicht
mitprüfen, weil es ihn nicht gibt.

**Was beim Nachziehen wieder aufgeschlagen werden muss:** die offene
Transportfrage (WebSocket mit `github.com/coder/websocket` und xterm.js gegen
die nicht-interaktive Variante), die Auflagen aus E8 — nur Owner, Schalter in
der Konfigurationsdatei und nicht in der Oberfläche, Audit je Sitzung mit
Container, Dauer und Befehl — und der Nachtrag in §6.

---

### Schritt 9 — Stand: umgesetzt

Der Angriffsdurchgang gegen die eigene Arbeit, nach dem Vorbild von Phase 7 des
Dateimanagers. Er hat **acht** Befunde gebracht, und alle acht sind geschlossen.
Sie stehen hier vollständig, weil ein Angriffsdurchgang ohne seine Funde nur
eine Behauptung ist.

#### Sechs Wege am Compose-Prüfer vorbei

Jeder davon ist beim ersten Anlauf **durchgegangen** — der Prüfer meldete „in
Ordnung".

| Fund | Warum er durchging |
|---|---|
| `- ../../../../var/run/docker.sock:/…` | Der Vergleich mit der Sperrliste traf nicht, weil dort absolute Pfade stehen — und danach galt „nicht absolut" als „liegt im Stack-Verzeichnis". Relative Quellen werden jetzt zuerst gegen das Verzeichnis aufgelöst, so wie Compose es auch tut. |
| **Ein benanntes Volume mit `driver_opts.device: /`** | Der schwerwiegendste Fund. Im Dienst steht nur `- hack:/host` — das sieht aus wie ein harmloses benanntes Volume. Der `local`-Treiber nimmt aber dieselben Angaben wie `mount(8)`, und mit `type: none, o: bind, device: /` hängt es das ganze Wirtsdateisystem ein. Der Prüfer liest jetzt die oberste `volumes:`-Ebene und löst solche Einträge in den Wirtspfad auf, den sie in Wahrheit meinen. |
| `device_cgroup_rules: ["c *:* rwm"]` | „devices" ohne das Wort. Den Geräteknoten legt der Container selbst an — `CAP_MKNOD` hat er von Haus aus —, und damit ist die Platte des Wirts lesbar. |
| `build: {context: /}` | `docker compose up` **baut**, wenn ein Bauabschnitt dasteht. Ein Kontext auf einem hohen Verzeichnis kopiert fremde Dateien in ein Image. Kontext und Dockerfile müssen jetzt im Stack-Verzeichnis liegen. |
| `env_file: [{path: /root/.ssh/id_ed25519}]` | Die neue Langform von `env_file`. Der Leser setzte für eine Abbildung nur einen Platzhalter, und der Pfad ging ungeprüft durch die Vorprüfung. |
| `volumes_from: ["container:xyz"]` | Ein Dienst *aus dieser Datei* ist selbst geprüft; ein fremder Container nicht — auch nicht der Socket, den er vielleicht eingehängt hat. Der Verweis auf einen fremden Container wird jetzt abgelehnt, der auf einen eigenen Dienst bleibt ein Hinweis. |

Dazu eine Härtung ohne vorherigen Fund: Ein Bind-Mount, der im
Stack-Verzeichnis liegt und über einen **symbolischen Verweis** hinausführt,
wird abgelehnt — eingehängt wird das Ziel, nicht der Verweis.

#### Zwei Wege an der Pfadwache vorbei

| Fund | Warum er durchging |
|---|---|
| **Ein fremdes Projekt mit `ConfigFiles: /etc/shadow`** | Bei einem fremden Projekt sagt *Docker*, wo die Datei liegt — eine Angabe, die das Panel nicht gesetzt hat. Der Endpunkt las sie und zeigte sie **jedem angemeldeten Konto**, auch einem mit reinem Leserecht. Damit war der Stack-Inspektor ein allgemeines Leseprogramm. Gelesen wird jetzt nur, was auf `.yaml` oder `.yml` endet: Das kostet nichts — Compose liest ausschließlich YAML — und nimmt dem Endpunkt diese Eigenschaft. |
| **`PUT /api/v1/docker/stacks/{name}` prüfte den Namen nicht** | Im Betrieb hätte ein Pfad als Name nichts bewirkt, weil `privops.StackSchreiben` ihn prüft. Diese Schicht reichte ihn aber ungeprüft weiter und verließ sich vollständig auf die darunter. Sichtbar wurde es überhaupt nur, weil die Attrappe im Test die Prüfung nicht hat — **dieselbe Lehre wie beim `DockerState.Notiz` in Schritt 1: Ein Test, der gegen eine Attrappe prüft, prüft die Zusagen der Attrappe.** Der Name wird jetzt in beiden Schichten geprüft, lesend wie schreibend. |

Zusätzlich gehärtet: Das Stack-Verzeichnis darf kein symbolischer Verweis sein
(`MkdirAll` folgt einem vorhandenen Verweis wortlos), und beim Löschen wurde
nachgewiesen, dass `RemoveAll` das Ziel eines Verweises stehen lässt.

#### Was der Durchgang NICHT gefunden hat

Das gehört genauso dazu, damit die Liste oben nicht wie eine Vollständigkeit
aussieht, die sie nicht ist:

- **Keine Argument-Einschleusung.** Es gibt keine Shell; die Argumente gehen als
  Feld an `exec`. Ein Semikolon ist damit kein Ausbruch. Gefährlich wäre ein
  Wert, der mit `-` beginnt und von docker als Option gelesen wird — davor steht
  überall `--`, und die Namensprüfungen sind der zweite Riegel. Beides ist jetzt
  in einem Test zusammengefasst.
- **Keine Lücke in den Rollen.** Alle zehn schreibenden Routen des Moduls
  verlangen Owner, CSRF-Token und Sitzung; alle sechs lesenden stehen jeder
  Rolle offen. Geprüft wird das jetzt in einer Schleife über eine Liste, die von
  Hand gepflegt wird — eine aus dem Router abgeleitete Liste fände immer genau
  das, was da ist, und könnte nie sagen, dass etwas fehlt.
- **Keine wirkungslose Rückfrage.** Bei allen vier Handgriffen der Stufe 3
  wurde geprüft, dass ein falsches getipptes Wort nicht wirkt — geprüft an der
  Wirkung und nicht am Statuscode.

#### Grenzen, die bleiben, und zwar bewusst

- **Wer den Docker-Socket hat, hat die Maschine.** Der Prüfer ist kein
  Rechtefilter gegen die Owner-Rolle: Wer dieses Modul bedienen darf, darf auch
  Pakete installieren und Dateien als root schreiben. Er ist ein Geländer gegen
  den häufigsten Fall — die aus einem Forum kopierte `compose.yaml`, in der eine
  solche Zeile steht, ohne dass jemand sie liest.
- **Ohne Rendern ist die Prüfung schwächer.** Ist Docker nicht erreichbar, wird
  nur die Rohdatei gelesen; YAML-Anker, `extends` und `.env` können dann an ihr
  vorbei. Die Antwort sagt das (`gerendert: false`), und `up` läuft ohne Docker
  ohnehin nicht.
- **Ein fremdes Compose-Projekt kann auf jede `.yaml` des Servers zeigen.** Wer
  ein solches Projekt anlegt, hat Docker-Zugriff und ist damit ohnehin
  root-nah. Die Einschränkung auf YAML-Endungen begrenzt den Schaden, hebt ihn
  aber nicht auf.
- **`pid: "container:xyz"` wird nicht abgelehnt**, nur `pid: host`. Das ist ein
  Weg in einen fremden *Container*-Namensraum und keiner auf den Wirt.

#### Messung, gegen 0.4.1 auf derselben Maschine

| Größe | 0.4.1 | mit Modul Docker | Grenze |
|---|---|---|---|
| Binär (`-s -w`, trimpath) | 17,2 MiB | 17,6 MiB (+464 KiB, +2 %) | 30 MB (CI) |
| Bündel `index.js` | 298 KiB | 358 KiB | — |
| Bündel CSS | 60 KiB | 67 KiB | — |
| Direkte Go-Abhängigkeiten | 6 | **6** | 25 |
| Allowlist-Einträge für Docker | — | **1** (`/usr/bin/docker`) | — |
| Abdeckung `privops` | — | 78,2 % | 72 % |
| Abdeckung `httpd` | — | 72,1 % | 68 % |

Der Zuwachs von 464 KiB für sieben Schritte ist der Preis dafür, dass alles über
die Kommandozeile läuft: Es kam **keine** Bibliothek dazu. `gopkg.in/yaml.v3`
war schon direkte Abhängigkeit (`internal/config`), und `docker buildx` ist ein
Unterkommando desselben Binaries. Die Grundlast im Leerlauf ist unverändert —
das Modul hält keinen Hintergrundprozess: Der Ereignisstrom läuft nur, solange
jemand zusieht, und die Update-Prüfung nur auf Knopfdruck.

#### Von Hand, auf einem echten Server — weiterhin offen

Der Angriffsdurchgang lief gegen aufgezeichnete Ausgaben, nicht gegen Docker.
**Was ein Test hier nicht leisten kann, bleibt offen** und steht in Abschnitt 10:
die Abnahme aller CLI-Parser auf einer echten Installation. Für den Prüfer heißt
das insbesondere, dass die Gestalt von `docker compose config` bestätigt werden
muss — die ganze Kette hängt daran, dass die gerenderte Fassung so aussieht, wie
der Parser sie erwartet.

---

### Nachtrag 0.5.1 — der Befund, den erst eine Frage gefunden hat

Nach der Freigabe von 0.5.0 kam die Frage, wie man über das Panel einen neuen
Stack anlegt. Die Antwort war: auf einem Server mit mindestens einem
Compose-Projekt so, und auf einem ohne **gar nicht**.

In `Stackliste.svelte` lag der Knopf „Stack anlegen" im `{:else}`-Zweig einer
Kette, deren vorheriger Zweig `daten.zeilen.length === 0` prüfte. Er stand damit
nur bei nicht leerer Liste — also überall außer dort, wo jemand den *ersten*
Stack anlegen will. Einen zweiten Weg in den Editor gibt es nicht: `editor` ist
Zustand der Komponente und steht in keiner Adresse. Daneben stand ein Satz aus
Schritt 4, den Schritt 5 hätte mitnehmen müssen: „Anlegen kommt mit dem nächsten
Schritt."

**Warum keine Prüfung das gefunden hat**, und das ist die eigentliche Lehre:

| Prüfung | Warum sie vorbeisah |
|---|---|
| Go-Tests der Schreibrouten | Sie sprechen die API an. Die Route war in Ordnung — unerreichbar war der Weg dorthin. |
| Browsertest | Der Fake liefert immer mindestens einen Stack. Der leere Zustand kam in keinem Lauf vor. |
| Angriffsdurchgang (Schritt 9) | Er fragte, was jemand tun kann, der etwas erreichen will, das er nicht darf. Nicht, was jemand *nicht* tun kann, der darf. |

Das ist dieselbe Sorte Befund wie „Tests gegen einen Fake beweisen die Zusagen
des Fakes", nur eine Ebene höher: **Ein Zustand, den die Testdaten nie
annehmen, ist ein ungeprüfter Zustand** — und der leere Anfangszustand ist bei
einem Modul für den *frischen* Server der wichtigste von allen.

Die Bewachung sitzt deshalb nicht auf diesem einen Knopf.
`TestAnlegenknopfHaengtNichtAnEinerLeerenListe` (`internal/ui`) sammelt die
`{#if}`-Bedingungen, unter denen ein Anlegen-Knopf steht, und beanstandet jede,
die den Listeninhalt liest. Die erste Fassung des Tests ging noch gegen die
kaputte Datei durch, weil sie ein `{:else}` die Bedingung der Kette vergessen
ließ — ein Test, der die Lücke nicht nachstellt, bevor er sie bewacht, bewacht
nichts.

Mit derselben Fassung heißt „Abbild" durchgehend **Image**. Die Übersetzung war
richtig und im Gebrauch trotzdem falsch: Wer `docker images` tippt, sucht auf
der Seite nach „Images". An der Schnittstelle ändert das nichts — kein
JSON-Feld und kein Pfad hieß je „abbild".

---

### Nachtrag 0.5.1 — das Compose-Formular

Angeregt durch [Dockge](https://github.com/louislam/dockge), das neben dem
Texteditor Felder für die üblichen Compose-Angaben führt und beide Richtungen
synchron hält. Gebaut ist dieselbe Zweiwegsynchronisation, mit drei
Zusätzen, die aus dem Zuschnitt dieses Panels folgen.

#### E9 — Der Text ist die Wahrheit, das Formular ist abgeleitet

`web/src/lib/composeform.ts` hält **keinen Zustand**. Jede Änderung aus dem
Formular ist derselbe Dreischritt: Text parsen, einen Knoten anfassen, Text
zurückgeben. Es gibt kein Dokumentobjekt, das zwischen zwei Klicks veralten
kann, und keine Lage, in der Formular und Editor auseinanderlaufen — sie können
es nicht, weil nur einer von beiden etwas hält.

Der Preis ist ein Parserlauf je Änderung. Bei einer Compose-Datei von dreißig
bis dreihundert Zeilen ist das nicht messbar; die Alternative wäre ein zweiter
Zustand mit eigener Lebensdauer gewesen, und den hat dieses Projekt an anderer
Stelle schon teuer bezahlt.

#### E10 — Chirurgisch ändern, nie neu ausgeben

Der naheliegende Weg — YAML nach JavaScript-Objekt, Objekt ändern, Objekt nach
YAML — ist der falsche. Er verliert Kommentare, Einrückung, Anführungszeichen
und die Reihenfolge der Felder. Für dieses Panel ist das kein Schönheitsfehler:
Entscheidung **E7** sagt über die mitgelieferten Vorlagen, die Kommentare seien
„der eigentliche Inhalt". Ein Formular, das sie beim ersten Klick auffrisst,
hätte die Vorlagen entwertet.

Geschrieben wird deshalb über die Document-API von `yaml`, und zwar am
vorhandenen Knoten:

| Fall | Weg | Was dadurch bleibt |
|---|---|---|
| Skalar ändern | vorhandenen `Scalar` weiterbenutzen, nur `value` setzen | Kommentar am Feld, Anführungszeichenstil, Stellung in der Abbildung |
| Liste ändern | zeilenweise abgleichen, nur geänderte Zeilen anfassen | Kommentare an den Zeilen, die niemand angerührt hat |
| Ausgabe | `lineWidth: 0`, `indent` aus der Quelldatei geraten | keine umgebrochenen langen Zeilen, keine umformatierte Einrückung |

Zwei Kleinigkeiten mit Folgen, beide beim Schreiben der Prüfungen gefunden:
`yaml` faltet ohne `lineWidth: 0` lange Zeichenketten bei 80 Zeichen um — ein
`command`, das niemand angefasst hat, stünde nach dem ersten Klick woanders. Und
es schreibt ohne `indent` immer zwei Leerzeichen, formatierte eine mit vier
eingerückte Datei also vollständig um.

#### E11 — Der zweite Leser sagt, was er nicht kann

Das Formular ist der **zweite** Leser der Datei. Der erste ist der Prüfer auf
dem Server, und nur er entscheidet, was gespeichert und gestartet wird. Das
Formular kann irren; es kann nichts durchlassen.

Genau deshalb darf es nichts verstecken, was es nicht versteht:

| Fund | Verhalten |
|---|---|
| YAML-Anker, Aliasse | ganzes Dokument nur Anzeige — was ein Anker hineinzieht, sieht der Leser nicht |
| Mehrere Dokumente in einer Datei | ganzes Dokument nur Anzeige |
| `extends`, Merge-Key `<<` | dieser Dienst nur Anzeige — er erbt, und das Formular sieht nur die eine Hälfte |
| `command` als Liste, `depends_on` mit Bedingungen, Port in der langen Form | Feld wird als *unbedienbar* benannt und bleibt gesperrt |
| Felder, die das Formular gar nicht kennt (`deploy`, `healthcheck`, `labels` …) | werden aufgezählt und bleiben unangetastet |
| Text ist gerade kein gültiges YAML | Felder eingefroren, mit Grund |

**Der wichtigste dieser Fälle ist der vierte, und er ist beim Testen
aufgefallen.** Ein `depends_on` in der Abbildungsform mit `condition:
service_healthy` lieferte dem Formular eine leere Liste. Angezeigt hätte es ein
leeres Feld — und die erste Änderung an irgendetwas anderem im Dienst hätte die
Bedingungen weggeschrieben. Das ist der Grund, warum `unbedienbar` getrennt von
`weitereFelder` steht: „kenne ich nicht" und „kenne ich, aber nicht in dieser
Gestalt" sind zwei verschiedene Aussagen, und nur die zweite ist gefährlich.

#### Zur Bedienung

Beim Tippen wird nur Nichtleeres übernommen; das Leeren wirkt beim Verlassen des
Feldes. Ein leerer Wert heißt „Feld weg" — würde das schon beim Tippen gelten,
verschwände `image` in dem Augenblick, in dem man es zum Ändern leert, und
stünde danach an anderer Stelle in der Datei wieder da. „Zeile hinzufügen"
schreibt zunächst gar nichts: Eine leere Portzeile wäre in der Datei ein Fehler.

#### Prüfung

Ein JavaScript-Testrahmen kam dafür nicht ins Haus. Geprüft wird in Node,
angestoßen aus Go (`internal/httpd/composeform_test.go`): rolldown — der
Bündler, der ohnehin die Oberfläche baut — bündelt das Modell, ein Skript stellt
rund dreißig Behauptungen über den Rückweg. Der Test überspringt sich, wo
`node_modules` fehlt, und läuft in der CI im Job „UI-Bundle reproduzierbar".

Dazu ein Abschnitt im Browsertest, der beide Richtungen an einer Datei prüft,
die der Test selbst setzt: Feld ändern → steht in der Datei und der Kommentar
lebt noch; Datei ändern → steht in den Feldern, samt `127.0.0.1:8080:80`, an dem
ein Zerlegen von links scheitert; Anker → gesperrt mit Grund; kaputtes YAML →
eingefroren.

**Ein Befund aus dem Testbau, der die Fläche nicht betrifft und trotzdem
hierher gehört:** Der erste Anlauf legte die Datei mit
`keyboard.insertText` in den Editor. Chromium hängt beim Schreiben in ein
`contenteditable` ein `style`-Attribut an die Editorzeile — ein Verstoß gegen
die Content-Security-Policy, erzeugt vom Test und nicht von der Anwendung. Der
Wächter im Browsertest unterscheidet das zu Recht nicht. Der Test schreibt
seither über ein `paste`-Ereignis, das CodeMirror selbst abfängt und über seine
eigene Schnittstelle einfügt. Der Browsertest sagt seit diesem Fall auch, **wer**
einen Verstoß ausgelöst hat — vorher stand nur da, dass einer vorlag.

#### Messung gegen 0.5.0

| | 0.5.0 | 0.5.1 | Bemerkung |
|---|---|---|---|
| Binary | 18 032 KiB | 18 152 KiB | +120 KiB, das eingebettete Bündel |
| Erstladung (`index.js` + CSS) | 433,8 KiB | 452,8 KiB | +19,0 KiB |
| davon nachgeladen | — | 103,1 KiB | `composeform`-Brocken samt `yaml`, nur beim Öffnen des Editors |
| direkte Go-Abhängigkeiten | 6 | 6 | unverändert |
| direkte npm-Abhängigkeiten | 8 | 9 | `yaml` 2.9.0, ISC |

---

### Nachtrag 0.5.1 — die Seite wird ein Modul mit fünf Flächen

Die Frage kam aus dem Betrieb: Bei vielen Containern wird die Seite lang.

**Sie wird nicht nur lang, sie wird teuer.** Gemessen mit der Attrappe des
Browsertests (2 Stacks, 4 Container, 2 Images) sind es 2 337 px. Hochgerechnet
auf einen betriebsüblichen Server — 12 Stacks, 40 Container, 30 veröffentlichte
Ports, 45 Image-Referenzen, 60 Images, 25 Volumes, 8 Netze — sind das rund 204
zusätzliche Tabellenzeilen und **etwa 9 300 px, also dreizehn Bildschirme**.

Das schwerere Argument steht daneben: Jeder Abschnitt holt beim Einhängen seine
eigenen Daten, und die meisten davon sind `docker`-Prozesse.

| Abschnitt | Prozesse beim Öffnen (40 Container) |
|---|---|
| Zustandskopf | 4 |
| Stacks | 2 |
| Container | 1 |
| Ports | 2 |
| Image-Updates | 0 (nur Zwischenspeicher) |
| **Bestand** | **45** — `ps`, je Container ein `docker inspect`, dazu `system df`, `image ls`, `volume ls`, `network ls` |

Rund **54 Prozessaufrufe je Seitenaufruf**, davon 45 aus dem Abschnitt mit dem
seltensten Anlass. Wer einen Container neu starten wollte, bezahlte den ganzen
Bestand mit — samt `system df`, das den Image- und Volume-Speicher durchläuft.

#### E12 — Unterpunkte in der Seitenleiste, keine Reiter

Erwogen und verworfen wurde eine Reiterleiste im Inhalt. Der Unterschied ist
nicht Geschmack:

| | Reiter | Unterpunkt in der Leiste |
|---|---|---|
| eigene Adresse | ginge auch | ja |
| von einer anderen Seite aus erreichbar | nein, erst über das Modul | ja |
| in der Befehlspalette | nein | ja |
| zweite Navigation im Inhalt | ja | nein |

Der dritte Punkt gibt den Ausschlag. `lib/ziele.ts` ist ausdrücklich die *eine*
Liste des Menüs, weil die Palette sonst eine zweite, unvollständige Fassung
wäre. Eine Reiterleiste hätte genau das wieder eingeführt — fünf Flächen, die
das Menü nicht kennt.

**Aufgeklappt heißt „ich bin drin".** Es gibt keinen Umschalter: Die Punkte
erscheinen, solange man im Modul steht, und verschwinden beim Verlassen. Ein
Umschalter wäre ein zweiter Zustand, und sein schlechter Fall wäre ein
zugeklapptes Modul, in dem man gerade steht — nichts hervorgehoben, nichts zu
sehen. Die Hervorhebung trägt in diesem Fall das Kind und nicht der Elternteil;
zwei markierte Punkte sagten nicht mehr, sondern weniger.

#### Der Fall, an dem der Entwurf beinahe gescheitert wäre

Unter 900 px ist die Seitenleiste eine **Symbolschiene**: Beschriftungen sind
für das Auge ausgeblendet (`clip-path`), Gruppenüberschriften weg, Symbole
zentriert. Ein eingerückter Unterpunkt hat dort keine sichtbare Einrückung — er
wäre ein weiteres Symbol in derselben Spalte, nicht von einem Modul zu
unterscheiden, und für fünf Flächen gibt es keine fünf sinnvollen Symbole.

Die Antwort ist ein **Umschaltstreifen auf der Seite, der nur schmal
erscheint**. Damit gibt es zwei Navigationen für dieselbe Sache — aber nie
beide gleichzeitig sichtbar, und jede an der Breite, an der sie funktioniert.
Der Browsertest prüft beides: dass schmal keine Kinder in der Schiene stehen und
dass der Streifen da ist. Ohne die zweite Hälfte wären die Flächen auf einem
Telefon unerreichbar.

#### Was das an Prüfungen gekostet hat

- Der Browsertest wechselt jetzt über die Punkte der Leiste und nicht über die
  Adresszeile — nur so ist geprüft, dass der Weg dorthin existiert. Je Wechsel
  wird Pfad, Hervorhebung und „ohne Neuladen" festgehalten.
- Der Vergleich Palette gegen Leiste war eine Gleichheit von **Anzahlen**. Das
  ging nicht mehr: Die Palette kennt 23 Ziele, die Leiste zeigt 18 plus die
  Flächen des Moduls, in dem man gerade steht. Er vergleicht jetzt
  **Benennungen** und sagt damit genauer, was er meint: Was in der Leiste steht,
  findet die Palette.
- Neu ist `TestJedeFlaecheEinesModulsWirdGerendert` (`internal/ui`). Es gibt
  jetzt drei Leser der Flächenliste: die Leiste (allgemein), die Palette
  (allgemein) und die Seite selbst (eine Kette von Vergleichen). Nur die dritte
  kann vergessen werden — der Punkt stünde dann im Menü und führte auf „Diese
  Fläche gibt es nicht".

#### Offen geblieben

**Warnpunkte an den Menüpunkten gibt es nicht.** Der README behauptet sie seit
0.4.0 („jedes Ziel mit einem Warnpunkt, wenn dort etwas offen ist"); gebaut sind
sie nie worden — `Seitenleiste.svelte` rendert Symbol und Beschriftung, `Ziel`
hat kein Signalfeld, und die Signale erreichen nur die Übersicht. Mit den
Flächen wären sie deutlich nützlicher als vorher: ein Punkt an
„Image-Updates" statt einer an „Docker", der sechs Abschnitte meint. Das ist ein
eigenes Stück Arbeit und steht aus; der falsche Satz im README ist mit dieser
Fassung berichtigt.

Ebenfalls offen: Der Bestand holt je Container ein `docker inspect`
(`api_v1_docker.go`), nur um „Volume in Gebrauch" zu markieren. `docker system
df -v` liefert dasselbe in einem Aufruf. Durch die eigene Fläche wird das
seltener ausgelöst, aber nicht besser.

---

#### Was bleibt

Das Formular deckt die Felder ab, die eine gewöhnliche Compose-Datei ausmachen.
Es deckt **nicht** ab: `healthcheck`, `deploy`, `labels`, `configs`, `secrets`,
`build` und alles Weitere — sie bleiben dem Texteditor vorbehalten und werden
namentlich genannt, statt stillschweigend zu fehlen. Ob das zu wenig ist, sagt
der Betrieb; die Liste zu verlängern ist Arbeit an einer Stelle
(`dargestellteFelder` in `composeform.ts`) und kein Umbau.

---

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
