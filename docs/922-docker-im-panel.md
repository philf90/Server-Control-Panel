# 922 — Docker im Panel: Bestand, Vorschläge und die Messrunde davor

Geschrieben am **20. September 2026** auf Auftrag des Betreibers: Das Panel soll
eine vollwertige Docker-Integration mit vollumfänglichem Management bekommen.
Als Vorlagen genannt sind **Dockge** und **Arcane**. Getrennt werden soll die
Nutzung durch den Betreiber und — eventuell — durch den Kunden.

**Dies ist eine vollständig neue Planung.** Sie leitet alles aus zwei Quellen
ab und aus keiner dritten: aus dem **gemessenen Bestand dieses Repositorys** und
aus der **Compose-Spezifikation**. Die beiden Vorlagen sind an ihrem Quelltext
vermessen und nicht aus der Erinnerung beschrieben.

**Dieses Dokument ist noch kein Plan.** Es ist die Grundlage davor: was gemessen
ist, welche Nähte Docker in diesem Panel still bricht, welche Zuschnitte zur
Wahl stehen und was vor dem Plan zu messen ist. Der Plan entsteht **nach** der
Messrunde in §12 — so wie bei jeder Stufe seit P5b, und jede davon hat den
Entwurf umgeworfen.

Die Nummer kommt aus dem 900er-Block, weil Docker eine **eigene Anforderung**
ist und keines der acht Merkmale von P9.

---

## §0 · Die Entscheidungen des Betreibers

Sie stehen hier und nicht verstreut im Text, weil ein Plan, der seine
Entscheidungen nicht an einer Stelle führt, sie beim nächsten Umbau verliert.

| # | Frage | Entschieden am | Entscheidung |
|---|---|---|---|
| **1** | Bekommt der Kunde Docker? | 20. September 2026 | **K0 — Docker gehört dem Betreiber allein.** K2 (rootless je Abonnement) ist **vorgemerkt für nach der vollständigen Umsetzung** und nicht Teil dieser Stufe. |
| **2** | Zuschnitt und Reihenfolge | 20. September 2026 | **Eigene Stufe `P9a`, nach P9 und vor P9b.** Zwei Abnahmeläufe: erst die lesenden Bereiche, dann Stacks, Prüfer und Angriffsdurchgang. |
| **3** | `docker.io` oder `docker-ce` | offen | |
| **4** | Container-Shell | offen | |

**Was Entscheidung 1 festlegt.** Die Stufe baut ein Betreibermodul und keine
Kundenschnittstelle. Damit gilt für alles Weitere:

- Die Fähigkeit ist **`operate-server`**, und es kommt **kein** `Permission`-Fall
  für Abonnements dazu.
- Von den fünf Nähten aus §7 sind **zwei** in dieser Stufe zu schliessen — das
  Regelwerk (§7.3) und die Diagnose (§7.5). Die drei anderen — Quota, Sicherung,
  Proxy-Form — betreffen ausschliesslich Kundencontainer und bleiben **benannt
  offen** statt still zu fehlen.
- Von den neun Messungen aus §11 tragen **M1, M3, M4, M5 und M6** diese Stufe.
  **M2, M8 und M9 entfallen vorerst**, **M7 gehört zum vorgemerkten K2.**

**Was Entscheidung 2 festlegt.** Die Stufe steht seit dem 20. September als
`P9a` in `docs/20 §9`, zwischen P9 und P9b, und die Summe dort ist auf
**40–53 Wochen** fortgeschrieben. Drei Dinge folgen daraus:

- **Der Name folgt der Regel von P7b** — eine Stufe zwischen zwei bestehenden
  trägt den Buchstaben der davor: P9, P9a, P9b.
- **Vor P9b, und das ist der tragende Grund:** A3s zweiter Wurf schreibt die
  Firewall in eine eigene nftables-Tabelle, „die den Bestand nicht anfasst" —
  und Docker **ist** dann Bestand. Danach gebaut, wird A3 zweimal gebaut.
- **Nach P9**, weil P9 das Verkäufliche bringt und A7 dann schon steht: Dockers
  vier Diagnosebefunde aus §7.5 kommen aus `FindingCheck`, und A7 liest daraus
  — das sind Zeilen in einer Aufzählung und kein Umbau.

**Die zwei Abnahmeläufe** sind ein Vorschlag des Plans und keine eigene
Entscheidung des Betreibers; sie folgen aus dem Zuschnitt. Lauf 1 nimmt die
lesenden Bereiche ab — Container, Bestand, Ports, Image-Updates, Zustandskopf,
Diagnose, Regelwerk. Lauf 2 nimmt die schreibenden ab — Stacks, den
Compose-Prüfer und den Angriffsdurchgang gegen die sechs Mechanismen aus §6.

> **Der gefährlichste Schritt kommt zuletzt, und er kommt erst dran, wenn alles
> Lesende steht und gemessen ist.** Dieselbe Logik wie bei A3s erstem und
> zweitem Wurf, nur innerhalb einer Stufe.

**Was Entscheidung 1 nicht sagt.** Sie sagt nicht, dass ein Kunde nie Container
bekommt — sie sagt, wann darüber entschieden wird. Und sie legt die Richtung
fest: Der Weg dorthin führt über **K2 und nicht über K1**, also über eine
Grenze, die der Kernel hält, und nicht über eine, die in unserem Code liegt.

> **Ein Merkmal, das kein Plan bestellt hat, trägt den Namen nicht, unter dem
> es geplant war — und die Planzeile bleibt offen stehen.** Deshalb steht K2
> hier als Zeile und nicht als Absicht: K1 ist damit **nicht Teil dieses
> Vorhabens**, und wer später einen Kundenweg baut, fängt bei §5 an und nicht
> bei null.

---

## §1 · Der Bestand — gemessen am 20. September 2026

Alles hier ist gegen `main @ fae85f8` gemessen, nicht erinnert.

| Frage | Befund | Gemessen an |
|---|---|---|
| Gibt es Docker im Panel? | **Nein.** Der einzige Treffer im ganzen Baum ist `packaging/testbed.sh` — dort ist Docker das *Prüfmittel* und nicht der Gegenstand. | `grep -rni docker --include=*.php --include=*.vue` |
| Operationen im Agenten | **117**, keine davon Docker | `ls agent/src/Ops/` |
| Programme auf der Positivliste | `docker` steht **nicht** darauf | `agent/src/Runner.php` |
| Vhost-Formen | **Vier**: `suspended`, `redirect`, `php`, `static`. **Keine Proxy-Form.** | `agent/src/SiteTemplate.php:85–91` |
| Kontingente | **14**, keines für Container, Speicher oder Volumes | `app/Support/Plans/Quota.php` |
| Kundenrechte | **10** (`Permission`), keines für Container | `app/Enums/Permission.php` |
| Adminfähigkeiten | **2**: `operate-server`, `manage-settings` | `app/Support/Authorization/AdminAbility.php` |
| Transportgrenze | `CONTENT_MAX` = 1 MiB − 64 KiB ≈ **983 KB** je Antwort | `agent/src/Connection.php:22,50` |
| Muster für lange Läufe | `systemd-run` absetzen, Urteil aus einer Datei nachlesen — `apt-run`, `cron-run` | `packaging/bin/`, `Ops/SystemRunOutcome.php` |
| Bestandsdiagnose | **18** Befundarten, keine für Container | `app/Enums/FindingCheck.php` |
| Dauerdienste und Timer | **4** und **8**, alle unter `srvpanel.target` — `docker.service` gehört zu keinem | `packaging/systemd/` |

**Die Sprache ist zum Teil schon gebunden.** `docs/19 §3` führt eine Liste
verbrauchter Wörter, die `WordChoiceTest` über jede `.vue` und jedes
Zeichenkettenliteral in `app/` durchsetzt. Für ein Docker-Modul bindend sind
darunter: **Version** statt „Fassung", **installieren** statt „einspielen",
**Host-Pfad** statt „Wirtspfad", **Build-Cache** statt „Baucache",
**entfernen** statt „wegräumen", **Datenträger** statt „Platte". Dazu aus dem
Abschnitt darunter: **Logs** für Container-Ausgaben — „Protokoll" bleibt dem
Audit, wo es ein Nachweis ist und kein Log.

---

## §2 · Die zwei Vorlagen, gemessen an ihrem Quelltext

Beide am 20. September geklont und ausgezählt — nicht aus Beschreibungen
übernommen.

| | **Dockge 1.5.0** | **Arcane** |
|---|---|---|
| Bauart | TypeScript, Socket.IO | Go-Backend, SvelteKit |
| Anbindung an Docker | **CLI** — `spawn("docker", ["compose", …])`, Argumente als Feld | **Engine-API** über `DOCKER_HOST` / Socket |
| Führendes Objekt | Stack (`compose.yaml`) | Projekt (`compose.yaml`) |
| Umfang | `up`, `down`, `restart`, `stop`, `pull`, `logs`, `ps`, `exec` | Container, Images, Volumes, Netze, Ports, Projekte, Registries, Templates, Builds, GitOps, Schwachstellen, Swarm, Webhooks, S3, OIDC, Passkeys |
| Rechtemodell | ein Benutzer, ein Passwort | **Rechte je Verb**: `containers:start`, `projects:deploy`, `images:pull`, … |
| Mehrere Hosts | Agenten über Socket-Tunnel | Environments |

**Was ich davon für tragfähig halte**, und warum:

| Übernehmen | Begründung aus *diesem* Repo |
|---|---|
| **Compose als führendes Objekt** | Beide Vorlagen kommen unabhängig dorthin. Ein einzelner Container ohne Datei ist ein Zustand, den niemand reproduzieren kann — dasselbe Argument, mit dem `CronApply` eine Datei schreibt statt einen Befehl abzusetzen. |
| **Die CLI statt des Sockets** | `Runner` tut bereits genau das, was Grenze 1 verlangt (§8). Der Socket wäre ein zweiter Transport. |
| **Eine Ports-Ansicht über alle Container** | Hier stärker als bei Arcane, weil A3 seit dem 9. September das Regelwerk kennt: Die Seite kann sagen „auf `0.0.0.0:8080` veröffentlicht, und das Regelwerk lässt es durch". |
| **Rechte je Verb** | Arcanes Modell passt zur `Permission`-Aufzählung dieses Panels, falls der Kunde Container bekommt (§5). |

| Nicht übernehmen | Begründung |
|---|---|
| **Swarm** | Dieses Panel verwaltet *einen* Server. |
| **Mehrere Hosts / Agenten** | Ein eigenes Vertrauensmodell, das es hier nicht gibt. |
| **GitOps, Image-Builds, Schwachstellen-Scans** | Drei eigene Produkte mit je eigener Vertrauensfrage. Ein Scanner, der privilegiert läuft, ist eine zweite Angriffsfläche neben Docker. |
| **Automatisches Anwenden von Image-Updates** | Ein Panel, das nachts von allein Images tauscht, ist ein Panel, das nachts von allein etwas kaputt macht. Update-**Prüfung** ja, Anwenden durch den Menschen. |

Arcanes Rechteliste ist dabei selbst ein Beleg für die Hausregel dieses
Projekts: Neben `containers:start` steht dort `containrs:start` — eine
Zeichenkette, die auf nichts zeigt, und nichts prüft den Bezug.

> **Eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder
> ein Werkzeug den Bezug prüft.** Der Fehler, der in diesem Repo mindestens
> sechsmal aufgetreten ist — hier im Quelltext der Vorlage, ungeprüft.

---

## §3 · Die Tatsache, an der der ganze Zuschnitt hängt

> **Wer den Docker-Socket erreicht, hat die Maschine.**

Das ist keine Vorsicht, sondern eine Eigenschaft des Produkts: `docker run
-v /:/host` ist root auf dem Wirt, in einer Zeile. Derselbe Weg steht in einer
`compose.yaml` an mindestens sechs verschiedenen Stellen (§9), und mehrere
davon sehen harmlos aus.

Daraus folgen zwei Sätze, und sie tragen alles Weitere:

**Für den Betreiber ändert Docker nichts.** Er trägt `operate-server`, und
`AdminAbility` nennt als erstes Merkmal von „kritisch": *„Es verleiht root auf
Dauer."* Wer Paketquellen schaltet, Dienste stoppt und den Server neu startet,
kommt ohnehin an root. Ein Compose-Prüfer ist für ihn **ein Geländer und kein
Rechtefilter** — er schützt vor der aus einem Forum kopierten Datei, nicht vor
dem Betreiber.

**Für den Kunden ändert Docker alles.** Ein Kunde, der eine `compose.yaml` frei
schreiben darf, ist root auf dem Server aller anderen Kunden. Damit fallen die
drei Grenzen aus `CLAUDE.md` zusammen: Mandantenklammer, Sandbox und
Positivliste wären **umgangen und nicht überwunden**.

**Die Antwort darauf hat dieses Projekt schon einmal gegeben**, in P5c für
dieselbe Frage in anderer Gestalt:

> **Kein freies SQL.** Damit bekommt der Agent typisierte Fragen und keine
> Anweisung, und die erste Grenze gilt wörtlich statt dem Sinne nach.

Wörtlich übertragen: **Kein freies Compose für den Kunden.** Alles andere wäre
eine Prüfung über einer Sprache, die mächtiger ist als die Prüfung.

---

## §4 · Der Betreiberteil — fünf Bereiche

In allen Zuschnitten aus §5 derselbe. Fünf Bereiche unter `/docker`, jeder mit
eigener Adresse, darüber ein Zustandskopf (Laufzeit, Daemon, Compose) — weil
„der Daemon antwortet nicht" man nicht durch einen Bereichswechsel verpassen
darf.

| Bereich | Adresse | Inhalt |
|---|---|---|
| **Stacks** | `/docker` | Liste (verwaltet / fremd), Dienste, Zustand; Editor mit Compose-Prüfer |
| **Container** | `/docker/container` | Liste und Inspektor: Logs, Statistik, Konfiguration, Mounts, Netze, Ports; Ereignisstrom |
| **Ports** | `/docker/ports` | alle veröffentlichten Ports, abgeglichen mit dem Regelwerk aus A3 |
| **Image-Updates** | `/docker/updates` | Digest-Abgleich, zwischengespeichert, mit Ratengrenze |
| **Bestand** | `/docker/bestand` | Images, Volumes, Netze, `system df`, Aufräumen je Art |

**Verwaltet und fremd werden getrennt**, und zwar nach dem Muster, das dieses
Panel für Crontabs und für nftables schon hat: Geschrieben wird ausschliesslich
in eigene Verzeichnisse unter einem Ablageort, den der Agent **baut und nicht
entgegennimmt** — so wie `SubscriptionProvision` den Pfad eines Abonnements
baut. Fremde Compose-Projekte, die `docker compose ls --all` kennt, erscheinen
**lesbar und bedienbar, aber ihre Datei wird nie geschrieben**.

> **Eine Operation, die einen Pfad annimmt und ihn danach prüft, ist eine
> Operation, deren Prüfung irgendwann eine Lücke hat.**

**Fehlt Docker**, zeigt die Seite eine Karte mit dem Zustand und einen Knopf
„Docker installieren" — dasselbe Muster, das `/updates` und die Datenbankseite
schon haben. Drei Zustände sind dabei zu unterscheiden und **nicht zwei**:
nicht installiert, installiert aber Daemon tot, Daemon läuft aber Compose
fehlt. Bei totem Daemon hilft kein apt-Lauf, sondern ein Dienststart — dort
gehört ein Verweis auf die Diensteseite und kein Knopf.

> **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen lässt,
> behauptet etwas, das sie nicht weiss.** Dieser Satz hat dieses Repo im
> September dreimal etwas gekostet — `/schedules`, der Ports-Bereich und die
> Dienste-Tabelle.

**Und „auffällig" wird an genau einer Stelle entschieden.** Die Übersicht, die
Containerliste und die Bestandsdiagnose speisen ihr Urteil aus **derselben**
Funktion. Zwei Fassungen liefen in diesem Repo schon einmal auseinander, und
dann meldete die Übersicht einen Befund, den die Dienste-Seite nicht kannte
(`docs/91 §20`, `dienstRang()`).

---

## §5 · Drei Zuschnitte für die Kundenseite

Der Betreiberteil ist in allen drei derselbe. Was sich unterscheidet, ist
allein, was ein Kunde bekommt.

### K0 — Docker gehört dem Betreiber allein

Ein Modul unter `/docker`, Fähigkeit `operate-server`. Der Kunde sieht nichts,
und in seinem Abonnement ändert sich kein Feld.

| | |
|---|---|
| **Neue Sicherheitsgrenze** | keine — der Betreiber ist root-nah |
| **Nähte, die brechen** | Regelwerk (§7.3), Diagnose (§7.5) |
| **Was es nicht ist** | ein Hosting-Merkmal. Der Betreiber verwaltet seine eigenen Container; der Kunde hat nichts davon |

### K1 — Der Kunde bekommt Container aus einem Katalog des Betreibers

Der Betreiber pflegt **Vorlagen** mit typisierten Feldern. Der Kunde wählt eine,
füllt aus — Domain, Grösse, Adminadresse — und bekommt einen Stack. **Die
`compose.yaml` erzeugt das Panel und nicht der Kunde**; er sieht sie höchstens
lesend.

Das Erzeugte läuft in einem Profil, das nicht verhandelbar ist:

| Zusage | Wie |
|---|---|
| kein `privileged`, keine geteilten Namensräume, keine `devices`, keine `cap_add` | die Vorlage kennt die Felder nicht — **und der Prüfer läuft trotzdem** |
| Volumes nur unter `/var/www/vhosts/<name>/docker/` | damit greift die Quota — **sofern M2 das bestätigt** (§12) |
| Ports nur an `127.0.0.1` | nginx proxyt; nichts wird auf `0.0.0.0` veröffentlicht (§7.3) |
| Speicher und CPU aus dem Kontingent | neue `Quota`-Fälle (§7.1) |
| Kennung des Abonnements | `user: <uid>:<gid>`, kein root im Container |

**Der Kunde erreicht seinen Container über seine Domain**, und dafür braucht es
die fünfte Vhost-Form (§7.4). Das ist der Punkt, der aus einem Adminwerkzeug ein
Hosting-Merkmal macht.

| | |
|---|---|
| **Neue Sicherheitsgrenze** | die Vorlage — und sie liegt in *unserem* Code, nicht im Kernel |
| **Nähte, die brechen** | alle fünf aus §7 |
| **Was es nicht sagt** | dass ein Ausbruch unmöglich ist. Ein Container ohne eigenen Benutzernamensraum teilt sich den Kernel mit allen anderen Kunden |

### K2 — Jeder Kunde bekommt sein eigenes rootless Docker

Je Abonnement ein `dockerd` als `p<N>`, über `systemd --user` mit `loginctl
enable-linger`, eigene `subuid`/`subgid`-Bereiche, Netz über `slirp4netns`. Der
Kunde darf dann **freies Compose** — er ist root nur in seinem eigenen
Benutzernamensraum, und das ist genau die Art Grenze, die `Sandbox` seit P6 für
Dateien hält: *nicht geprüft, sondern eingesperrt, und die Grenze hält der
Kernel.*

| | |
|---|---|
| **Neue Sicherheitsgrenze** | der Kernel — die einzige, die gegen freies Compose trägt |
| **Offen** | Speicher je Daemon, Start nach einem Neustart, Quota über `fuse-overlayfs`, Zusammenspiel mit der Sandbox |
| **Was es nicht sagt** | ob es auf einem Server mit fünfzig Abonnements noch trägt. Das ist M7, und **ohne M7 ist jede Zahl zu K2 geraten** |

### Entschieden: K0 — und K2 als vorgemerkter offener Punkt

**Der Betreiber hat am 20. September K0 gewählt** (§0). K2 ist vorgemerkt für
nach der vollständigen Umsetzung; **K1 ist nicht Teil dieses Vorhabens.**

Die drei Zuschnitte bleiben hier stehen und werden nicht auf den gewählten
zusammengestrichen. Der Grund ist derselbe, aus dem `docs/20 §9` eine
verschobene Stufe als durchgestrichene Zeile stehen lässt statt sie zu
entfernen:

> **Wer später einen Kundenweg plant, soll sehen, welche Wege es gab und warum
> einer davon gewählt wurde — eine stille Streichung lässt ihn von vorn
> anfangen.**

**Was den Ausschlag gab**, und es steht hier als Begründung und nicht als
Empfehlung, weil die Entscheidung gefallen ist:

1. **K0 hat eine neue Angriffsfläche und keine neue Grenze.** Der Betreiber
   trägt `operate-server` und kommt ohnehin an root; die Stufe lässt sich
   deshalb allein durch einen Angriffsdurchgang abnehmen — wie P6 Schritt 11.
2. **Die Kundenseite hängt an drei ungemessenen Nähten** (§7.1, §7.2, §7.4).
   Eine davon entscheidet, ob das Merkmal überhaupt verkäuflich wäre: Zählt ein
   Container nicht gegen `setquota`, zeigt die Abonnementseite ein Kontingent
   an, das es nicht gibt.
3. **Es ist das Muster, das bei A3 getragen hat** — erster Wurf lesend, zweiter
   Wurf schreibend, jeder mit eigenem Abnahmelauf.

**Und die Richtung für später ist mitentschieden.** Führt der Kundenweg über
**K2** statt über K1, liegt die Grenze im **Kernel** und nicht in einer Vorlage
und einem Prüfer, die wir selbst pflegen. Das ist die härtere Grenze — und die
teurere, weil sie an M7 hängt.

> **Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke.** Eine
> Vorlage, die nur deshalb hält, weil niemand an ihr vorbeischreibt, ist eine
> Voreinstellung; ein Benutzernamensraum ist eine Schranke.

---

## §6 · Der Compose-Prüfer — die Angriffsfläche, hergeleitet

Das ist der sicherheitskritische Kern. Die folgende Liste ist **aus der
Compose-Spezifikation hergeleitet und nicht gemessen**; sie zu messen ist M4,
und die Messung ist erzeugend: Zu jedem Mechanismus gehört ein Prüfkörper, der
den Wirt **tatsächlich erreicht**, und erst dann bedeutet eine Ablehnung etwas.

> **Ein Eingriff, der einen Zustand herstellt, den der Prüfling ohnehin gleich
> beantwortet, misst die Regel nicht — er misst, dass sie unempfindlich ist.**

### Sechs Mechanismen, nicht eine Liste von Feldern

| # | Mechanismus | Felder |
|---|---|---|
| **1** | **Direkte Rechteabgabe** | `privileged`, `cap_add` (`SYS_ADMIN`, `SYS_PTRACE`, `SYS_MODULE`, `DAC_READ_SEARCH`, `MKNOD`, `ALL`), `security_opt` (`apparmor:unconfined`, `seccomp:unconfined`, `label:disable`, `no-new-privileges:false`), `user: 0`, `group_add` mit der Docker-Gruppe |
| **2** | **Geteilte Namensräume** | `pid: host`, `ipc: host`, `uts: host`, `userns_mode: host`, `network_mode: host`, `cgroup: host`, `cgroup_parent` |
| **3** | **Geräte** | `devices`, `device_cgroup_rules` — das zweite ist „devices" ohne das Wort |
| **4** | **Einhängungen** | `volumes` als Bind-Mount, `volumes_from`, `tmpfs` mit `exec`, und **die oberste `volumes:`-Ebene mit `driver_opts`** |
| **5** | **Pfade, die Compose selbst liest** | `build.context`, `build.dockerfile`, `env_file` (Kurz- **und** Langform), `extends.file`, `secrets.file`, `configs.file` |
| **6** | **Verdeckung** | YAML-Anker und Merge-Schlüssel, `x-`-Felder, `.env`-Interpolation, `extends` |

**Drei davon verdienen einen eigenen Satz**, weil sie nicht aussehen, wonach
sie aussehen:

**Ein benanntes Volume ist nicht harmlos.** Im Dienst steht `- daten:/x`, und
das liest sich wie ein gewöhnliches Volume. Der `local`-Treiber nimmt aber
dieselben Angaben wie `mount(8)`: Mit `driver_opts: {type: none, o: bind,
device: /}` hängt er das ganze Wirtsdateisystem ein. Ein Prüfer, der nur die
`volumes:`-Liste **im Dienst** liest, sieht davon nichts.

**`compose up` baut.** Steht ein `build`-Abschnitt da, wird ein Image gebaut —
und ein `context` auf einem hohen Verzeichnis kopiert fremde Dateien hinein.
Bauen ist kein getrennter Vorgang, den man separat verbieten könnte.

**Ein Pfad ist nicht dasselbe wie ein absoluter Pfad.** Eine relative Quelle
(`../../../var/run/docker.sock`) muss **zuerst gegen das Stack-Verzeichnis
aufgelöst** werden, so wie Compose es auch tut — sonst prüft man eine
Zeichenkette gegen eine Liste absoluter Pfade und trifft nie. Und ein
symbolischer Verweis, der aus dem Stack-Verzeichnis hinausführt, wird
eingehängt als sein **Ziel** und nicht als Verweis.

### Der Prüfer läuft zweistufig, und die Reihenfolge ist der ganze Punkt

Mechanismus 6 erzwingt es. Ein `privileged: true` hinter einem YAML-Anker steht
in der Rohdatei unter **keinem** Dienst:

```yaml
x-basis: &basis
  privileged: true
services:
  web:
    <<: *basis
```

Geprüft gehört deshalb die **gerenderte** Konfiguration — die Ausgabe von
`docker compose config`, die Anker, `extends`, `env_file` und `.env` auflöst.

**Aber rendern liest fremde Dateien.** `extends: {file: …}` zieht eine beliebige
YAML in die Ausgabe, und die Ausgabe zeigt das Panel an. Ein Prüfer, der
ungeprüft rendert, ist damit **selbst der Weg**, jede Datei des Servers zu
lesen.

> **Ein Prüfer, der zum Prüfen rendert, liest damit, was er prüfen soll — und
> die Reihenfolge ist der ganze Unterschied.**

Also: **erst die Rohdatei auf Verweise nach draussen prüfen, dann rendern, dann
die gerenderte Fassung auf die sechs Mechanismen.**

### Zwei Haltungen, die dazugehören

**Unbekanntes ist kein Freibrief.** Ein Feld oder eine Compose-Version, die der
Prüfer nicht kennt, meldet er als **„nicht geprüft"** und nicht als „in
Ordnung" — dieselbe Haltung, mit der A10 eine Datei behandelt, für die es kein
Prüfprogramm gibt.

**Eine Ablehnung nennt Dienst, Feld und Grund.** „Der Stack wurde abgelehnt"
schickt jemanden auf die Suche; „`web`: `privileged`" zeigt auf die Zeile. Der
Prüfer ist Grenze und Bedienhilfe zugleich, und er läuft **serverseitig vor
jedem `up`** — nicht im Formular. Im Editor läuft **dieselbe Funktion**
zusätzlich als Auskunft, damit es nicht zwei Auslegungen gibt.

> **Zwei Fassungen derselben Regel sind zwei Fassungen, und die zweite ist die,
> die veraltet.**

---

## §7 · Die fünf Nähte, die Docker still bricht

Das ist der Teil, den weder Dockge noch Arcane beantworten: **Sie kennen keine
Kunden.** Jede dieser fünf Nähte ist eine Zusage, die dieses Panel heute gibt
und die ein Container unterläuft, ohne dass irgendetwas rot wird.

### 7.1 Die Quota ist für Container keine

`setquota` setzt eine ext4-Quota auf die **Kennung** des Abonnements. Dockers
Speicher liegt unter `/var/lib/docker` und gehört root; ein benanntes Volume
ebenso. **Hergeleitet, nicht gemessen** (M2): Ein Kunde mit Containern hätte
damit einen unbegrenzten Datenträger, während seine Abonnementseite ein
Kontingent anzeigt.

> **Eine Anzeige, die eine Angabe erfindet, ist teurer als eine, die fehlt.**
> Genau dieser Satz hat am 18. September `Quotas::format()` getroffen.

Dazu fehlen Kontingente, die es noch nicht gibt: Container je Abonnement,
Arbeitsspeicher, und der Platz der Volumes. `Quota` ist dafür die einzige
Quelle — wer eines dazunimmt, bekommt Beschriftung, Einheit, Prüfregel und
Formularfeld daraus.

### 7.2 Die Sicherung sieht den Container nicht

P8 sichert `/var/www/vhosts/<name>`, die Datenbanken und die Cron-Datei.
**Benannte Volumes stehen in keiner dieser drei Quellen.** Ein Abonnement mit
Containern wäre also „gesichert" und nicht gesichert.

Und `docs/116` hat dazu schon gemessen, was die Wiederherstellung kostet:
`ZipArchive` erhält **1 von 5** Eigenschaften (Rechte, Kennung, Verweis,
setgid, leeres Verzeichnis), `tar(1)` **5 von 5** und steht nicht auf der
Positivliste. Ein Volume ohne Eigentümer zurückzuspielen ergibt einen
Container, der nicht startet.

### 7.3 Docker schreibt am Regelwerk vorbei

Docker legt eigene Ketten an (`DOCKER`, `DOCKER-USER`) und veröffentlicht Ports
an der Firewall vorbei — ein `ports: ["8080:80"]` ist von aussen erreichbar,
auch wenn `ufw` es verbietet. Das ist **hergeleitet** und gehört gemessen (M3).

Gemessen ist dagegen schon, **womit** man hinsieht: A3s erster Wurf hat auf
`cloudsrv24` belegt, dass eine Regel über `iptables-legacy` in
`nft list ruleset` unsichtbar bleibt — 2307 Bytes, byteweise dieselben.

> **Ein leeres Regelwerk und ein Regelwerk, das man mit dem falschen Werkzeug
> abfragt, sehen gleich aus — und beide sagen `rc=0`.**

Das trifft **P9b** unmittelbar: A3s zweiter Wurf will „eine eigene
nftables-Tabelle, die den Bestand nicht anfasst". Docker *ist* dann Bestand, und
`DOCKER-USER` ist die dokumentierte Stelle zum Einhängen. **Wer Docker nach P9b
baut, baut A3 zweimal.**

### 7.4 Es gibt keine Proxy-Form für einen Vhost

`SiteTemplate` kennt vier Formen, und keine zeigt auf einen Container. Für K1
ist das die tragende Lücke.

Eine fünfte Form ist kein Einzeiler — sie muss durch vier bestehende Wächter:

- **A10**: ein Eintrag in `PROMISED_BY_FORM`. `docs/100` hat gemessen, dass die
  Zusage die **Schnittmenge** aller Formen ist: zu gross meldet jede Nacht jede
  heile Domain, zu klein meldet nichts.
- **A12**: die Wartungswache gehört in jeden Server-Block;
  `MaintenanceVerdictTest` zählt sie gegen die Zahl der Blöcke.
- **`docs/102`**: eine PHP-Domain durchläuft die Rewrite-Phase bei jeder
  **inneren Umleitung** ein zweites Mal. Ob eine Proxy-Form dieselbe Falle hat,
  ist ungemessen.
- **`Statements::nginx()`** zerlegt an `;{}` und **kennt keine
  Anführungszeichen** (`docs/102`, weiterhin offen). Eine
  `proxy_set_header`-Zeile mit Anführungszeichen läuft da hinein.

### 7.5 Die Diagnose und das Ziel kennen Docker nicht

`srvpanel.target` führt vier Dauerdienste und acht Timer; `docker.service`
gehört zu keinem davon. Die Bestandsdiagnose hat 18 Befundarten und keine für
Container. Was dazugehörte:

| Befundart | Wofür |
|---|---|
| `container.state` | beendet mit Code ≠ 0, Neustartschleife, `unhealthy` |
| `container.port` | auf `0.0.0.0` veröffentlicht |
| `compose.drift` | die Datei ist neuer als das, was läuft |
| `image.update` | neuer Digest in der Registry — **aus dem Zwischenspeicher**, nie ein Registry-Aufruf im Nachtlauf |

---

## §8 · Die Anbindung: CLI, abgesetzt, typisiert

**Die CLI und nicht der Socket.** Drei Gründe, und alle drei sind Eigenschaften
dieses Panels:

1. **`Runner` tut schon genau das, was Grenze 1 verlangt**: Positivliste mit
   absoluten Pfaden, kein `$PATH`, keine Shell, Argumente als Feld an
   `proc_open`, feste Umgebung mit `LC_ALL=C`, gedeckelte Ausgabe, Zeitlimit.
   Ein Eintrag `'docker' => '/usr/bin/docker'` — und Compose v2 ist ein
   Unterkommando desselben Programms, also **kein zweiter Eintrag**.
2. **Der Socket wäre ein zweiter Transport.** Der Agent spricht HTTP mit
   niemandem; ihn das zu lehren heisst, Anfragen aus Zeichenketten zu bauen —
   der Vorgang, den Grenze 1 gerade verhindert.
3. **Compose ist viel Logik**, die man sonst nachbaut: Abhängigkeitsreihenfolge,
   Healthchecks, Profile, `.env`. Arcane geht den Socketweg und hat deshalb
   Swarm, Builds und GitOps — Umfang, den dieses Panel nicht will.

**Ein Wert, der mit `-` beginnt, ist der einzige Rest.** Ohne Shell gibt es
keine Argument-Einschleusung; gefährlich wäre allein ein Wert, den `docker` als
Option liest. Davor gehört überall ein `--`, und die Namensprüfungen sind der
zweite Riegel.

**Lange Läufe gehen über `systemd-run` und nicht über die Warteschlange.** Ein
`compose pull` dauert Minuten. `docs/116` führt als offene Frage, ob
`retry_after` (90 s) einem Lauf von 1800 s in die Quere kommt — für Docker ist
das keine offene Frage, sondern die Voraussetzung. Der Weg steht seit A1:
absetzen, Urteil in eine Datei schreiben, nachlesen.
`packaging/bin/docker-run` ist das dritte Geschwister von `apt-run` und
`cron-run`.

> **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt über den Ausgang
> dessen, was er abgesetzt hat, nichts — und `fertig` liest sich wie das
> Gegenteil.**

**Die Operationen**, benannt nach der bestehenden Ordnung (`db.user.create`,
`web.site.apply`):

| Lesend | Schreibend | Abgesetzt |
|---|---|---|
| `docker.state` | `docker.container.action` | `docker.install` |
| `docker.container.list` · `.inspect` · `.logs` · `.stats` | `docker.container.remove` | `docker.image.pull` |
| `docker.image.list` · `.digest` | `docker.image.remove` | `docker.stack.up` · `.down` · `.pull` · `.restart` |
| `docker.volume.list` · `docker.network.list` | `docker.volume.remove` · `docker.network.remove` | |
| `docker.disk.usage` · `docker.events` | `docker.prune` | |
| `docker.stack.list` · `.read` · `.config` | `docker.stack.write` · `.remove` | |

**Die Logs sind eine Grenzfrage und keine Nebensache.** `docker logs` einer
lebhaften Anwendung sind Megabytes, `CONTENT_MAX` ist 983 KB. Der Weg ist
gebaut: `WebLogsTail` und `SystemLogsTail` liefern ein Ende mit Zeilennummern —
und `docs/914` hat dort den Befund gefunden, der hier genauso droht:

> **Zwei Gründe, die dasselbe Ergebnis erzeugen, sind nicht derselbe Grund —
> und die Abhilfe für den einen lässt den anderen stehen.** Ein Abbruch am
> Dateiende und einer am Bytedeckel sahen von aussen gleich aus.

---

## §9 · Sprache, Rechte, Wege, Rückfragen

**Die Sprache** ist zum Teil gebunden (§1). Offen ist das Wort für das führende
Objekt: Dockge sagt *Stack*, Arcane sagt *Projekt*. `docs/19 §5` regelt, wie ein
Wort dazukommt.

**Die Rechte.** Für K0 genügt `operate-server` — Merkmal 1 aus `AdminAbility`
(„verleiht root auf Dauer") trifft wörtlich zu. Für K1 käme eine `Permission`
je Abonnement dazu, und `AbilityReachTest` verlangt dann, dass ein Knopf, den
der Betrachter nicht drücken darf, gar nicht gezeigt wird.

**Der Weg dorthin.** Für K1 liegen die Kundenseiten unter
`/subscriptions/{id}/…`, und `SubscriptionReachTest` verlangt von jeder solchen
Route einen Verweis auf der Abonnementseite. Das ist die geprüfte Hälfte. Die
ungeprüfte steht als Frage und nicht als Zusage, und sie ist in diesem Repo
**fünfmal** aufgetreten:

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?**

**Die Rückfragestufen.** Zwei sind schärfer, als man beim Entwerfen denkt:
**Volume entfernen** gehört auf die höchste Stufe mit getipptem Volumenamen —
Daten weg, kein Rückweg, die schärfste Einzelaktion des Moduls. Und
**`volume prune`** gehört auf die höchste Stufe mit *Hostname*, weil es
systemweit wirkt und nicht auf ein Objekt. `ConfirmationVerbTest` hält dazu
heute schon, dass auf dem Knopf ein Verb steht und kein Satz — und `docs/123`
hat am 18. September gemessen, was passiert, wenn der Satz doch dort landet:
281 px Überlauf und die Auskunft abgeschnitten.

---

## §10 · Der Aufwand — geeicht an diesem Repo

Es gibt keine belastbare Zahl für ein Modul, das es nicht gibt. Was es gibt,
ist der **Massstab dieses Repositorys**, ausgezählt am 20. September:

| Stufe | Umfang | Dauer laut `docs/20 §9` |
|---|---|---|
| **P5c** — Datenbankmanagement | 12 Agent-Operationen | 2–3 Wochen |
| **P8** — Sicherungen | 5 Operationen, 4 Agent-Klassen, 6 Support-Klassen, 4 Seiten, 2 Kommandos, 2 Units, 34 Wächter, 5544 Zeilen PHP | 3–4 Wochen |
| **P6** — Dateien, Zugänge, Cron (drei Teilsysteme) | | 3–4 Wochen |
| **P7b** — Serververwaltung, elf Merkmale | | ~9 Wochen |

**K0** umfasst nach §8 rund **20 Operationen** auf **fünf Bereichen**, dazu den
Compose-Prüfer, ein Kommando, eine Diagnoseprüfung und einen
Angriffsdurchgang. Das liegt über P5c und in der Gegend von P6:

| | Schätzung | Basis |
|---|---|---|
| **K0** — der gewählte Zuschnitt | **4–5 Wochen** | mehr Operationen als P5c, drei Teilsysteme wie P6, dazu ein sicherheitskritischer Prüfer |
| ~~K1~~ — nicht Teil dieses Vorhabens | — | (3–4 Wochen wären es gewesen, vergleichbar mit P8) |
| **K2** — vorgemerkt für danach | **nicht schätzbar** | hängt vollständig an M7, und M7 ist nicht gefahren |

> **Eine Erwartung, die man aus den Zahlen ausrechnet statt sie zu schätzen,
> macht aus dem Ergebnis einen Beleg.** Diese Zahlen sind **geschätzt**, und
> zwar an vergleichbarem Umfang in diesem Repo. Sie sind keine Zusage.

---

## §11 · Was vor dem Plan zu messen ist

Jede Stufe seit P5b hat ihre Messrunde vor dem Plan gehabt, und jede hat den
Entwurf umgeworfen. **Jede Frage bekommt eine Gegenprobe und einen Satz
darüber, was sie nicht sagt.**

Die neun stehen vollständig da; **welche davon diese Stufe trägt, sagt §0** und
nicht diese Liste — sonst stünde der Umfang an zwei Stellen, und die zweite
veraltet.

**M1 · Welches Docker liegt auf den vier Zielplattformen, und was gibt
`--format json` wirklich aus?** Zu messen auf Debian 12/13 und Ubuntu
22.04/24.04: Version von `docker.io`, ob `docker compose` dabei ist, und die
tatsächliche Form von `ps`, `image ls`, `volume ls`, `network ls`, `compose ls`,
`system df`. **Die Prüfkörper der Parser kommen aus diesen Ausgaben und nicht
aus der Dokumentation.** **Was es nicht sagt:** ob die Form über Versionen
stabil bleibt.

**M2 · Zählt ein Container gegen die Quota des Abonnements?** Drei Fälle, jeder
einzeln: Bind-Mount unter `/var/www/vhosts/<name>/`, benanntes Volume, und die
Schreibschicht des Containers selbst. **Gegenprobe:** dieselbe Datei durch den
Kunden geschrieben. **Was es nicht sagt:** was man tut, wenn die Antwort „nein"
lautet — das ist eine Entscheidung und keine Messung.

**M3 · Was tut Docker am Regelwerk, und ist ein veröffentlichter Port trotz
Firewall erreichbar?** Zu messen mit **beiden** Werkzeugen (`nft list ruleset`
**und** `iptables-legacy -S`), weil A3 gemessen hat, dass eines davon blind sein
kann. **Gegenprobe:** von aussen anklopfen, nicht die Regel lesen. **Was es
nicht sagt:** wie A3s zweiter Wurf damit umgeht — das ist eine Planfrage für
P9b.

**M4 · Erreicht jeder der sechs Mechanismen aus §6 den Wirt wirklich?** Je
Mechanismus ein Prüfkörper, der nachweislich **ausbricht**, bevor der Prüfer
existiert — sonst misst der Prüfer später seine eigene Unempfindlichkeit.
**Gegenprobe:** derselbe Prüfkörper nach dem Prüfer, abgewiesen mit Nennung von
Dienst und Feld. **Was es nicht sagt:** dass es keinen siebten Mechanismus gibt
— deshalb die Haltung „unbekannt heisst nicht geprüft".

**M5 · Was kostet ein `compose up` mit Pull, und überlebt es die
Warteschlange?** An einem echten Image gemessen, mit `retry_after = 90`
daneben. **Was es nicht sagt:** wie lange es bei einem langsamen Anschluss
dauert.

**M6 · Wie gross sind `docker logs` und `compose logs` wirklich?** Gegen
`CONTENT_MAX` (983 KB) gehalten, an einem Container, der viel schreibt. **Was
es nicht sagt:** wie viele Zeilen ein Mensch sehen will.

**M7 · Trägt rootless Docker je Abonnement?** Speicher je Daemon, Startzeit,
Verhalten nach einem Neustart, `subuid`/`subgid`, Quota über `fuse-overlayfs`.
**Diese Messung entscheidet K2 allein.** **Was es nicht sagt:** ob es sicher
ist — nur, ob es bezahlbar ist.

**M8 · Besteht eine Proxy-Form die vier bestehenden Wächter?** `nginx -t`,
`PROMISED_BY_FORM`, die Wartungswache, und `Statements::nginx()` mit
Anführungszeichen. **Gegenprobe:** eine Zeile entfernen und sehen, ob die
Diagnose sie nennt — das ist der Beleg, für den es A10 gibt.

**M9 · Was sieht die Sicherung von einem Container?** Ein Abonnement mit
Container sichern und zurückspielen. **Gegenprobe:** startet der Container
danach? **Was es nicht sagt:** wie ein Volume zu sichern wäre — das hängt an
`docs/116` (Eigentümer, `tar` nicht auf der Positivliste).

**Was im Container messbar ist:** M1 teilweise (`apt-get install docker.io` ist
derselbe Handgriff wie bei MariaDB, `sshd`, PowerDNS und nginx), M4, M5 und M6
vollständig. **M2, M3, M7 und M9 brauchen `cloudsrv24`.**

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
> braucht einen Versuch.** In diesem Repo steht er zum achten Mal.

---

## §12 · Was dieser Vorschlag ausdrücklich nicht ist

Damit die Abschnitte oben nicht wie eine Vollständigkeit aussehen, die sie
nicht haben:

- **Kein Plan.** Der entsteht nach §11, und die Messrunde wird ihn umwerfen —
  bei P8 waren es vier gekippte Annahmen, darunter die Form der
  Wiederherstellung.
- **Keine Aufwandszusage.** §10 schätzt an vergleichbarem Umfang in diesem
  Repo. `docs/913` hat am 13. September festgehalten, was eine geschätzte
  Erwartung anrichtet, wenn man sie für gerechnet hält.
- **Kein Swarm, keine Multi-Host-Verwaltung, kein GitOps, keine Image-Builds,
  keine Schwachstellenprüfung.** Arcane hat alle fünf.
- **Keine automatische Aktualisierung von Images.**
- **Keine Aussage darüber, ob K1 sicher genug ist.** Ein Container ohne eigenen
  Benutzernamensraum teilt sich den Kernel mit allen anderen Kunden. Das ist
  eine Grenze und kein Mangel — sie gehört benannt und nicht wegargumentiert.
- **Keine Messung.** Von den Aussagen dieses Dokuments ist der **Bestand** (§1,
  §2) gemessen; die Angriffsfläche (§6) ist **hergeleitet**, die fünf Nähte
  (§7) sind es zum Teil. Was hergeleitet ist, steht als solches da.

---

## §13 · Die Fragen an den Betreiber

Vier Fragen ändern die Arbeit, der Rest folgt aus ihnen.

1. ~~Bekommt der Kunde Docker?~~ — **beantwortet am 20. September, siehe §0:
   K0, mit K2 als vorgemerktem offenem Punkt.**

2. **Eigene Stufe oder Merkmal in P9 — und wo in der Reihenfolge?** P9 trägt
   acht Merkmale. Ein Zuschnitt als eigene Stufe mit eigenen Abnahmeläufen je
   Bereich — wie P7b — ist der wahrscheinlichere. **Die Reihenfolge gegen P9b
   ist dabei keine Geschmacksfrage**: A3s zweiter Wurf muss wissen, ob Docker am
   Regelwerk mitschreibt, sonst wird er zweimal gebaut (§7.3).

3. **`docker.io` aus der Distribution oder `docker-ce` aus Dockers Quelle?**
   `docker.io` bedeutet keine fremde Paketquelle, dafür auf Debian 12 eine
   deutlich ältere Version — und die Ausgabeform der CLI hängt an der Version.
   `docker-ce` bedeutet eine Version über alle vier Zielplattformen, dafür eine
   fremde Quelle mit eigenem Signaturschlüssel, den A1 mitüberwachen müsste.
   **M1 liefert die Zahlen dazu.**

4. **Container-Shell: ja oder nein?** Sie baut die schwierigste Hälfte eines
   Web-Terminals — PTY-Anbindung, bidirektionaler Transport, Terminal-Emulation
   im Browser. Wer sie will, hat danach das Terminal fast, und das Argument, es
   hinter 1.0 zu stellen, verliert seine technische Begründung und behält nur
   die sicherheitspolitische.

**Drei kleinere Fragen**, die der Plan sonst rät:

- **Fremde Stacks: nur anzeigen oder auch bedienen?** Mein Vorschlag: anzeigen,
  starten und stoppen — aber nie in ihre Datei schreiben.
- **Das führende Objekt: „Stack" oder „Projekt"?**
- **Wenn K1: Wer pflegt den Vorlagenkatalog?** Eine Vorlage ist Inhalt mit
  eigener Vertrauens- und Pflegefrage. Für den Betreiber allein genügte ein
  kommentiertes Gerüst; für Kunden **ist** der Katalog die Grenze, und dann
  braucht er einen Pfleger.
