# 20 — Neuplan: Hosting-Panel

> **Dieses Dokument steht für sich.** Die Dokumente 01–19 beschreiben den
> Vorgänger („Asylum", ein Server-Verwaltungspanel für einen einzelnen
> Betreiber, Go + Svelte, Stand 0.6.2). Sie bleiben als Beleg stehen und sind
> **nicht mehr maßgeblich**. Was hier steht, ersetzt sie vollständig: anderes
> Produkt, anderes Datenmodell, anderer Stack, andere Zielgruppe. Es gibt
> keinen Migrationspfad von 0.6.2 und keine Rücksicht auf bestehende
> Implementierungen, Fixes oder Provisorien.

## 0. Der Auftrag in einem Absatz

Ein Hosting-Panel für einen einzelnen Linux-Server, vergleichbar mit Plesk:
Der Betreiber verwaltet Kunden, Kunden verwalten ihre Abonnements —
Domains, Webseiten, PHP, Datenbanken, DNS, Dateien, Zugänge, Cronjobs und
Sicherungen — ohne SSH und ohne Zugriff auf fremde Daten. Das Panel ist
kundenfähig: es kann an Dritte ausgegeben werden, weil die Trennung zwischen
Mandanten sowohl in der Anwendung als auch im Betriebssystem durchgesetzt wird.

## 1. Getroffene Entscheidungen

Diese neun Punkte sind entschieden und der Rahmen für alles Weitere.

| Entscheidung | Gewählt | Folge |
|---|---|---|
| Bruch mit dem Bestand | vollständig, inklusive Technologiewechsel | Neue Codebasis, neues Repo-Layout, keine Übernahme von Go-Code |
| Stack | PHP 8.3+/Laravel, Vue 3 + Inertia, Vite, Tailwind | Schnelle Feature-Entwicklung, viel Vorbild im Hosting-Umfeld; dafür eine PHP-Laufzeit auf dem Host und eine strikt getrennte Rechteebene (§4) |
| Zielplattform | Debian 12/13, Ubuntu 22.04/24.04 | Eine Paketwelt (apt), ein Pfadschema, vier CI-Zweige |
| Isolation | ein Systembenutzer je Abonnement | Plesk-Modell: eigener PHP-FPM-Pool, `open_basedir`, Chroot-SFTP, Dateisystem-Quota |
| Rollenmodell | Admin → Kunde → Zusatzbenutzer; **innerhalb Admin zwei Rollen** (§6.1) | Keine Reseller-Ebene in der 1.0, aber im Modell vorbereitet (§5.4) |
| Funktionsumfang 1.0 | Web + PHP + Datenbanken, DNS autoritativ, FTP/SFTP, Cron, Backups | Mail ist **nicht** in der 1.0 |
| Mail | spätere Ausbaustufe | Datenmodell, DNS-Zonen, Backups und Rechte werden so geschnitten, dass Postfix/Dovecot ohne Umbau andocken (§5.5) |
| Name | **SrvPanel** | Beschreibend, kurz, kollisionsarm. Die beiden verworfenen Vorschläge und die Markenrecherche in §12.1 |
| Lizenz | **AGPL-3.0-only**, Copyright beim Projektinhaber | Quelle offen, Zweitlizenz jederzeit möglich; eine Auflage schlägt in die Oberfläche durch (§12.2) |

**Bezeichner.** Systembenutzer `srvpanel`, Pfade `/etc/srvpanel`,
`/var/lib/srvpanel`, `/opt/srvpanel`, `/var/log/srvpanel`, Units
`srvpanel-web`, `srvpanel-worker`, `srvpanel-agentd`, `srvpanel-metrics`,
Paket `srvpanel`, Kommandozeile `srvpanel`. Im Fließtext dieses
Dokuments steht weiter „das Panel".

## 2. Leitbild

1. **Der Bestand ist Gesetz.** Das Panel schreibt ausschließlich in
   Verzeichnisse, die ihm gehören. Distributionsdateien werden nie verändert,
   sondern über Include-Punkte erweitert. Eine bestehende, von Hand gepflegte
   Konfiguration überlebt die Installation des Panels.
2. **Nichts verstecken.** Jede Aktion ist im Protokoll nachvollziehbar, mit
   Person, Zeit, Ziel, Ergebnis. Fehlermeldungen zeigen die tatsächliche
   Ausgabe des Systems, nicht eine Umschreibung davon.
3. **Erst prüfen, dann übernehmen.** Jede erzeugte Konfiguration wird vor dem
   Reload validiert (`nginx -t`, `named-checkzone`, `php-fpm -t`, `sshd -t`).
   Schlägt die Prüfung fehl, wird die vorherige Fassung wiederhergestellt und
   der Fehler angezeigt.
4. **Jede Funktion hat einen Löschpfad.** Was ein Modul anlegt, muss es beim
   Löschen eines Abonnements auch wieder vollständig entfernen — Dateien,
   Systemobjekte, Konfigurationsabschnitte, Zeitpläne, Zertifikate.
5. **Zwei Oberflächen, eine Wahrheit.** Admin- und Kundenfläche greifen auf
   dieselben Dienste und dieselbe Rechteprüfung zu. Es gibt keine Aktion, die
   nur in einer der beiden korrekt abgesichert ist.
6. **Technische Sprache.** Die Vorgabe aus
   [19-sprache-der-oberflaeche.md](19-sprache-der-oberflaeche.md) gilt weiter;
   sie ist das einzige Dokument des Vorgängers, das übernommen wird. Neu
   dazu: die Oberfläche ist von Anfang an mehrsprachig angelegt (DE/EN), weil
   Kundenflächen es sein müssen.
7. **Bezeichner sind englisch, Erklärungen deutsch.** Dateinamen, Klassen,
   Methoden, Variablen, Konfigurations- und Protokollschlüssel, CSS-Marken,
   Datenattribute, Job-Namen in der CI — alles englisch und damit neutral.
   Kommentare, Dokumentation und die Texte der Oberfläche bleiben deutsch.

   Der Grund ist nicht Geschmack. Eine Schnittstelle mit Feldern wie
   `pruefbare_wurzeln` schließt jeden aus, der kein Deutsch kann — und ein
   Panel unter AGPL rechnet mit Beiträgen von außen. Dazu kommt das
   Praktische: Wer `$vorhalt` liest, muss raten, ob Kapazität oder Aufbewahrung
   gemeint ist; `$capacity` sagt es. Umgekehrt trägt eine deutsche Begründung
   im Kommentar mehr als eine englische, die niemand mit dieser Genauigkeit
   schreiben würde.

   Ausgenommen ist genau eines: Produkt- und Systembezeichner, die außen
   sichtbar sind (`srvpanel`, `srvpanel-agentd`, `repo.cloudsrv24.de`).

## 3. Nicht-Ziele der 1.0

- **Kein Mail-Server.** Konten, Filter, DKIM, Webmail, Zustellbarkeit — ein
  eigener Block, der die 1.0 um Monate verschiebt. Vorbereitet, nicht gebaut.
- **Keine Reseller-Ebene.** Vorbereitet im Modell, nicht in der Oberfläche.
- **Kein Cluster, kein Multi-Node.** Ein Panel verwaltet genau den Server, auf
  dem es läuft. Ausnahme: DNS-Slaves (§9.7).
- **Keine Abrechnung.** Kein Billing, keine Zahlungsanbindung, kein
  WHMCS-Modul. Statt dessen eine API, an die sich so etwas anschließen lässt.
- **Kein RHEL/AlmaLinux/Rocky**, kein Windows, keine ARM-Erstunterstützung
  (arm64 wird gebaut, aber nicht als Zielplattform zugesagt).
- **Keine Migration vom Vorgänger.** Ein Import aus Plesk-/cPanel-Sicherungen
  ist eine mögliche Nacharbeit (§11), kein Bestandteil der 1.0.
- **Kein Marktplatz, kein App-Installer** außer WordPress als mögliche
  Nacharbeit.

## 4. Systemarchitektur

### 4.1 Prozessmodell

Der gewählte Stack hat eine Eigenschaft, die die Architektur bestimmt: eine
PHP-Anwendung mit großer Angriffsfläche darf niemals als `root` laufen, muss
aber Systembenutzer anlegen, nginx neu laden und Zertifikate schreiben. Die
Lösung ist eine Trennlinie, die es beim Vorgänger nur als Absichtserklärung
gab:

```
   Browser ──TLS──▶ nginx (Panel-vhost, Port 8443)
                      │
                      ├─▶ php-fpm Pool „srvpanel"        (Benutzer srvpanel, kein root)
                      │      Laravel: HTTP, Inertia, API v1, Policies
                      │
                      ├─▶ srvpanel-worker (Benutzer srvpanel)
                      │      Warteschlange, lang laufende Vorgänge
                      │
                      └──unix socket──▶ srvpanel-agentd   (root, minimal)
                                          /run/srvpanel/agent.sock  0660 root:srvpanel
```

**`srvpanel-agentd`** ist der einzige Prozess mit Systemrechten. Er ist ein
eigenständiges PHP-CLI-Programm **ohne Framework und ohne Composer-Abhängigkeiten**
(nur `json`, `posix`, `sockets`, `pcntl`) — die Menge Code, die als root läuft,
soll klein genug bleiben, um sie ganz zu lesen. Das Protokoll ist bewusst so
geschnitten, dass der Agent später in einer anderen Sprache neu geschrieben
werden kann, ohne dass sich für die Anwendung etwas ändert.

### 4.2 Das Agent-Protokoll

- **Eine Verbindung, ein Auftrag.** Anfrage: eine JSON-Zeile
  `{"op":"webserver.site.apply","actor":{"user_id":7,"ip":"…"},"args":{…}}`.
  Antwort: NDJSON-Zeilen vom Typ `progress`, `stdout`, `stderr`, `result`.
- **Typisierte Operationen, keine Kommandozeilen.** Die Anwendung schickt nie
  einen Befehl und nie einen Dateipfad, sondern eine Absicht mit Parametern.
  `op` kommt aus einer festen Liste; unbekannte Werte werden abgewiesen.
- **Der Agent besitzt die Vorlagen.** nginx-Server-Blöcke, FPM-Pools,
  sshd-Drop-ins und Zonendateien werden **im Agent** aus geprüften Vorlagen
  gerendert. Die Anwendung liefert Struktur (Domain, DocumentRoot,
  PHP-Version, Zertifikatspfade), nicht Text. Freitextfelder („eigene
  Direktiven") sind auf wenige Stellen begrenzt und werden gegen eine
  Positivliste erlaubter Direktiven geprüft.
- **Aufruferprüfung.** `SO_PASSCRED` und `SCM_CREDENTIALS`: Der Kernel legt
  pid, uid und gid des Senders an die erste Nachricht; bedient wird nur die UID
  des Panel-Benutzers (und root, für Bereitschaftsprüfung und Rauchtest).
  *Nachgezogen in P0:* Hier stand `SO_PEERCRED`. Die Konstante gibt es in PHPs
  Socket-Extension nicht (geprüft mit 8.4). Die Auskunft ist dieselbe und
  kommt aus derselben Quelle — ausgefüllt wird sie beim Senden vom Kernel und
  nicht vom Programm, also vom Absender nicht zu fälschen.
- **Ausführung.** Absolute Pfade aus einer Programm-Positivliste, kein `$PATH`,
  keine Shell, Argumente als Array, feste Umgebung (`LC_ALL=C`), Zeitlimit je
  Operation, Ausgabe bei 4 MiB gekappt.
- **Doppeltes Protokoll.** Jeder Auftrag wird auf beiden Seiten protokolliert —
  in der Panel-Datenbank (mit Person) und in `/var/log/srvpanel/agent.log` (mit
  ausgeführter Kommandozeile). Wer beides vergleicht, sieht, ob am Panel vorbei
  gearbeitet wurde.

**Was diese Trennung leistet und was nicht.** Sie verhindert, dass ein Fehler
in der Web-Anwendung — ein Pfad, der nicht geprüft wurde, eine Bibliothek mit
Lücke — unmittelbar root bedeutet, und sie hält die Menge des als root
laufenden Codes prüfbar klein. Sie ist **kein** Schutz gegen eine vollständig
übernommene Panel-Anwendung: Wer die Anwendung kontrolliert, kann gültige
Aufträge stellen. Das ist die Grenze, und sie wird in der Sicherheitsbetrachtung
so benannt, nicht kaschiert.

### 4.3 Die PHP-Frage und die Grenzen von GitHub Pages

Das Panel braucht eine feste PHP-Version, die Kunden brauchen mehrere.
Distributionspakete liefern je Release genau eine (Debian 12: 8.2, Ubuntu
24.04: 8.3, Debian 13: 8.4).

- Das Panel bringt **seine eigene PHP-Version** mit, gebaut ins eigene Paket
  und installiert nach `/opt/srvpanel/php/`. Es hängt nicht an der
  PHP-Version des Systems und ändert sie nicht.
- Für Kundenwebseiten kommen **mehrere PHP-Versionen** (7.4 bis 8.4, je mit
  `-fpm`) aus **`deb.sury.org`**, eingebunden als zusätzliche apt-Quelle mit
  fest hinterlegtem Schlüssel und Pinning auf die Pakete, die das Panel
  wirklich braucht. Das Panel installiert sie auf Anforderung; es ändert die
  Quelle nicht und zieht nichts anderes daraus.

**Warum kein eigener Spiegel — Korrektur einer früheren Annahme.** Der Gedanke,
die PHP-Pakete in die eigene signierte Quelle zu spiegeln, scheitert an der
Infrastruktur: Das apt-Repository liegt auf GitHub Pages (Branch `gh-pages`,
ausgeliefert als `repo.cloudsrv24.de`), und Pages ist auf rund 1 GB je Site und
100 GB Datenverkehr im Monat ausgelegt. Sechs PHP-Versionen mal vier
Distributionen mal Extensions sind ein Mehrfaches davon. Den Pool auszulagern
geht nicht: Im `Packages`-Index stehen relative Pfade, apt folgt keiner
absoluten URL — GitHub Releases als Pool scheidet damit aus.

Daraus folgt für die eigene Quelle: **im Pool nur die letzten Freigaben je
Kanal**, ältere ausschließlich unter GitHub Releases. Ein `.deb` mit
Abhängigkeiten und gebauten Assets liegt bei 30–80 MB; ohne diese Regel ist die
Grenze nach etwa einem Jahr erreicht.

**Der Ausbau, wenn zahlende Kunden dranhängen:** eigener Spiegel auf
Objektspeicher (Hetzner Storage, S3, R2) unter einer Subdomain, Pages behält
Landingpage und Schlüssel. Größenordnung zehn bis zwanzig Euro im Monat, dafür
volle Kontrolle über die Lieferkette. Eigene PHP-Pakete zu bauen — sechs
Versionen, vier Distributionen, Extensions, Sicherheitsupdates — ist für eine
Person keine tragfähige Dauerlast und bleibt draußen.

Die Abhängigkeit von `deb.sury.org` ist damit eine bewusste und wird in der
Sicherheitsbetrachtung als solche benannt, nicht verschwiegen.

### 4.4 Datenhaltung

| Ort | Inhalt |
|---|---|
| `/opt/srvpanel/releases/<version>/` | Anwendung, `root:root`, für `srvpanel` nur lesbar |
| `/opt/srvpanel/current` | Symlink auf die aktive Version |
| `/etc/srvpanel/srvpanel.conf` | Konfiguration, Schlüsselmaterial (`root:srvpanel 0640`) |
| `/var/lib/srvpanel/` | Zustand, Zertifikate, Vorlagen-Overrides, Sicherungen |
| `/var/log/srvpanel/` | `srvpanel.log`, `agent.log`, `audit.log` (append-only, logrotate) |
| `/var/www/vhosts/<abo>/` | Abo-Wurzel = Home des Systembenutzers |
| MariaDB `srvpanel` | Panel-Datenbank, eigener DB-Benutzer, nur über Socket |

**Panel-Datenbank ist MariaDB**, nicht SQLite: Das Panel verwaltet ohnehin
einen MariaDB-Server, mehrere Prozesse (Web, Worker, Zeitpläne) schreiben
gleichzeitig, und die Datenmengen (Statistik, Protokoll) wachsen.
**Warteschlange und Cache laufen über die Datenbank** — kein Redis als
Pflichtabhängigkeit; Redis bleibt eine spätere Option für Installationen mit
vielen Abonnements.

### 4.5 Verzeichnisschema eines Abonnements

```
/var/www/vhosts/beispiel.de/          root:root 0755   ← Chroot-Wurzel für SFTP
    httpdocs/                         p1001:www-data 0750
    <weitere-domain>/                 p1001:www-data 0750
    logs/<domain>/{access,error}.log   p1001:adm 0750
    tmp/                              p1001:p1001 0700  ← upload_tmp_dir, session.save_path
    conf/                             root:root 0755    ← generierte Includes
    .ssh/                             p1001:p1001 0700
```

Die Chroot-Wurzel muss `root` gehören und darf für andere nicht schreibbar sein
— das ist eine Vorgabe von OpenSSH und der Grund für die Zweiteilung: Wurzel
root, Inhalt Kunde. `www-data` erhält Lesezugriff über die Gruppe, andere
Abonnements haben keinen: `0750` schließt sie aus.

### 4.6 Kennzahlen und Spikelines

Die Telemetrie-Kachel mit Verlaufslinie ist der eine Bestandteil des
Vorgängers, der als **Gestaltungsprinzip** übernommen wird — der Code nicht,
der ist Svelte und Go. Die Regeln, die ihren Wert ausmachen, gelten weiter und
stehen hier, damit sie nicht beim Neubau verlorengehen:

1. **Keine Diagramm-Bibliothek.** Ein SVG mit fester `viewBox`, ein Pfad, eine
   Ablesung. Eine Bibliothek würde das nachbauen und dabei schwerer machen.
2. **Der Server liefert fertige Stützstellen** samt Beschriftung und Einheit.
   Das Skript im Browser rechnet nicht, es sucht nur den Punkt unter dem
   Zeiger.
3. **Kein Diagramm ohne Ablesung.** Wer auf die Linie zeigt, bekommt Zeitpunkt
   und Wert; sonst ist die Linie Dekoration.

**Die Erfassung muss neu gebaut werden**, denn PHP-FPM hält zwischen zwei
Anfragen nichts im Speicher: `srvpanel-metrics` ist ein Dauerlauf unter
systemd, der alle 10 Sekunden aus `/proc` liest und in **Ringpuffer-Dateien
fester Größe** unter `/var/lib/srvpanel/metrics/` schreibt (24 Stunden je
Kennzahl, wenige MB, kein Wachstum). Kein Redis, keine Zeitreihendatenbank,
keine wachsende Tabelle. Für die Langzeitsicht verdichtet ein Tageslauf die
abo-bezogenen Werte in eine schmale Tabelle.

Wo Spikelines auftauchen:

| Fläche | Kennzahlen | Auflösung | Stufe |
|---|---|---|---|
| Adminübersicht | CPU, RAM, Load, Netz, IO, Datenträger | 10 s, 24 h | P1 |
| Adminübersicht | dazu Prozessliste, Dateisysteme, Uptime, Dienstzustände | live | P1 |
| Abo-Übersicht (Admin und Kunde) | Speicherplatz, Traffic, Datenbankgröße, FPM-Prozesse | 1 Tag, 30 Tage | P9 |
| Domain | Zugriffe, Traffic, Fehlerraten | 1 Tag, 30 Tage | P9 |

Die Adminübersicht kommt bewusst schon in P1: Sie ist das Erste, was nach der
Anmeldung zu sehen ist, und sie prüft den Live-Weg (SSE, Vorgänge, Ringpuffer)
an einem Gegenstand, der noch nichts kaputtmachen kann.

## 5. Datenmodell

### 5.1 Der Kern

```
Account ──gehört zu──▶ Customer ──hat──▶ Subscription ──basiert auf──▶ Plan
                                              │
    ┌──────────────┬──────────────┬───────────┼───────────┬─────────────┐
    ▼              ▼              ▼           ▼           ▼             ▼
  Domain      Database      DnsZone     FtpAccount   CronJob    BackupSchedule
    │            │             │
    ├ Subdomain  └ DbUser      └ DnsRecord
    ├ Alias
    ├ Certificate
    └ PhpSetting
```

- **Account** — ein Anmeldekonto. Typ `admin`, `customer` oder `additional`.
  Trägt Passwort, 2FA, Passkeys, Sitzungen, Spracheinstellung.
- **Customer** — der Vertragspartner: Name, Firma, Anschrift, Kontakt,
  Zustand (aktiv/gesperrt). Besitzt Abonnements.
- **Subscription (Abonnement)** — die tragende Einheit. Genau ein
  Systembenutzer, genau eine Hauptdomain, ein Plan, ein Satz Kontingente, ein
  Zustand (aktiv, gesperrt, gekündigt). **Alles Weitere hängt hier dran** —
  das ist der Anker der Mandantentrennung.
- **Plan (Service-Paket)** — Kontingente und Funktionsfreigaben als Vorlage.
  Änderungen wirken auf alle daran gebundenen Abonnements; ein Abonnement kann
  einzelne Werte übersteuern („abweichend vom Plan", sichtbar markiert).
- **Domain** — Typ `main`, `addon`, `subdomain`, `alias`. Hat DocumentRoot,
  PHP-Handler, Zertifikat, Weiterleitungsart, Zustand.

### 5.2 Kontingente

Je Plan und je Abonnement: Speicherplatz (über Dateisystem-Quota erzwungen),
Traffic je Monat (gemessen, nicht erzwungen; Warnschwellen), Anzahl Domains,
Subdomains, Datenbanken, FTP-Konten, Cronjobs, erlaubte PHP-Versionen,
maximale FPM-Prozesse, erlaubte Funktionen (DNS bearbeiten, eigene Zertifikate
hochladen, Sicherungen anlegen, PHP-Einstellungen ändern). Jede Prüfung
passiert serverseitig beim Anlegen — die Oberfläche zeigt den Stand nur an.

### 5.3 Vorgänge und Protokoll

- **Job** — jede Systemänderung, die länger als eine Sekunde dauern kann, ist
  ein Vorgang mit Zustand, Fortschritt, Ausgabe und Ergebnis. Die Oberfläche
  zeigt ihn live (SSE), auch nach Seitenwechsel.
- **AuditEvent** — Person, Rolle, IP, Ziel (Abo/Domain/Objekt), Aktion,
  Ergebnis, Zeit. Für Kunden auf die eigenen Ereignisse gefiltert sichtbar.

### 5.4 Vorbereitung Reseller

`Customer` erhält von Anfang an ein optionales Feld `parent_customer_id` und
`Plan` ein `owner_customer_id`. Beides bleibt in der 1.0 leer und ist in der
Oberfläche unsichtbar. Die Rechteprüfung arbeitet aber schon über eine
Zugehörigkeitskette statt über einen festen Vergleich — das ist der Unterschied
zwischen „später erweiterbar" und „später Umbau".

### 5.5 Vorbereitung Mail

Drei Vorkehrungen, mehr nicht: die DNS-Zonenvorlage kennt MX-, SPF-, DKIM- und
DMARC-Einträge und lässt sie leer; das Abo-Verzeichnisschema hält
`/var/www/vhosts/<abo>/mail/` frei; das Sicherungsformat hat einen
Objekttyp-Platz für Mailkonten. Keine Tabellen, keine Attrappen-Oberfläche.

## 6. Rechte- und Mandantenmodell

### 6.1 Die drei Ebenen

| Ebene | Sieht | Darf |
|---|---|---|
| **Admin** | den ganzen Server | alles: Kunden, Pläne, Dienste, Pakete, Firewall, Panel-Einstellungen, Updates |
| **Kunde** | seine Abonnements | alles innerhalb seiner Abonnements, im Rahmen von Plan und Kontingent |
| **Zusatzbenutzer** | ausgewählte Abonnements/Domains | genau die Rechte, die der Kunde ihm zuweist |

#### Zwei Rollen innerhalb der Admin-Ebene

> **Entschieden vom Betreiber am 24. August 2026**, beim Planen von A9
> (`docs/81 §11`). Gebaut wird es dort — hier steht es, weil §6 die Quelle für
> das Rechtemodell ist und eine Entscheidung, die nur im Planungsdokument
> stünde, beim nächsten Nachschlagen nicht gefunden wird.

Die Admin-**Ebene** bleibt eine: Beide Rollen sehen den ganzen Server, und für
die Mandantenklammer sind sie ununterscheidbar. Verschieden ist nur, was sie
dürfen:

| Rolle | Darf |
|---|---|
| **Betreiber** | alles. Dem `root` dieses Servers nahe |
| **Administrator** | Kunden, Abonnements, Domains, Datenbanken, Dateien, Cron, Protokoll. **Kritisches weder sehen noch bedienen** |

Kritisch ist, was eines dieser drei Merkmale trägt: es verleiht root auf Dauer
(Paketquellen, unbeaufsichtigte Updates) · es nimmt alle Kunden mit (Dienste
stoppen, Firewall, Neustart, Systemupdates einspielen) · es zeigt ein Geheimnis
(DNS-Zugangsdaten, SMTP-Kennwort, private Schlüssel des Panels).

**Das ist kein Rechte-Baukasten**, sondern eine feste zweite Rolle — dieselbe
Begründung wie bei den drei Ebenen. Und es ist **keine vierte Ebene**: Ebene und
Rolle sind zwei Achsen, und wer sie in ein Feld legt, macht „ist Admin"
zweideutig. Die Folgen dieser Verwechslung stehen in `docs/81 §11`, der
Stolperdraht dagegen ist `AccountTypeAxisTest`.

Der Rechtekatalog für Zusatzbenutzer (Plesk-nah): Dateien lesen, Dateien
schreiben, Datenbanken, DNS, Cron, Sicherungen, PHP-Einstellungen, Zertifikate,
FTP-Konten, Statistik, später Mail. Zusätzlich eine Einschränkung auf einzelne
Domains eines Abonnements.

### 6.2 Wie die Trennung durchgesetzt wird

Vier Schichten, absichtlich redundant:

1. **Mandantenklammer im Datenzugriff.** Jedes mandantengebundene Modell trägt
   `subscription_id` und einen globalen Filter, der ohne aktiven Mandanten
   nichts liefert. Eine vergessene `where`-Bedingung führt so zu „nicht
   gefunden", nicht zu fremden Daten.
2. **Policies je Aktion.** Autorisierung passiert an der Aktion, nicht am
   Menüpunkt. Jede Route ist einer Policy zugeordnet; eine Route ohne Policy
   fällt im Test durch (mechanische Prüfung, keine Disziplinfrage).
3. **Kontingent- und Planprüfung** im Dienst, nicht im Formular.
4. **Betriebssystemebene.** Selbst bei einem Fehler in 1–3 endet der Zugriff am
   Systembenutzer: eigener FPM-Pool, `open_basedir`, `0750`, keine Login-Shell,
   Chroot beim SFTP, Quota.

### 6.3 Anmelden als Kunde

Der Admin kann in die Sicht eines Kunden wechseln. Sichtbares Band in der
Oberfläche, eigener Sitzungszustand, jede Aktion im Protokoll doppelt vermerkt
(handelnde Person und Kontext). Rückweg jederzeit. Kein Passwortzugriff, kein
stiller Wechsel.

### 6.4 Anmeldung und Sitzungen

Argon2id, TOTP als zweiter Faktor (für Admins verpflichtend, für Kunden je Plan
erzwingbar), Passkeys als zweiter Weg, Wiederherstellungscodes, Ratenbegrenzung
je IP und je Konto mit ansteigender Sperre, Sitzungen serverseitig mit
absoluter und gleitender Laufzeit, CSRF-Schutz, optionale IP-Beschränkung für
die Adminfläche, API-Tokens mit Bereichen und Ablauf.

## 7. Oberfläche

### 7.1 Grundlagen

- **Zwei Flächen, ein Bau.** Adminfläche (`/admin`) und Kundenfläche (`/`)
  teilen Komponenten und Gestaltung, unterscheiden sich in Navigation und
  Rechten.
- **Vue 3 + Inertia** für die Oberfläche (kein getrenntes API-Frontend, keine
  doppelte Zustandshaltung), **Vite**, **Tailwind**, TypeScript.
- **Die REST-API v1 ist trotzdem eigenständig** und nicht das, was die
  Oberfläche benutzt: versioniert, mit Tokens, dokumentiert (OpenAPI). So kann
  eine Abrechnungslösung Abonnements anlegen, ohne dass die Oberfläche zur
  Schnittstelle wird.
- **Live-Vorgänge über SSE**, Fortschritt und Ausgabe sichtbar, Neuladen
  unschädlich.
- **Mehrsprachig von Anfang an** (DE/EN), Sprache je Konto.
- **Bestätigungsstufen** für riskante Aktionen: lesen → tippen → Sicherung
  anbieten. Löschen eines Abonnements verlangt den Namen.

### 7.2 Gestaltungssystem „Kontor"

**Zwei Systeme, und beide sind hier festgehalten.** Bis August 2026 hiess dieses
System „Leitstand" — dunkel, dicht, instrumentenhaft, entschieden aus drei
Vorschlägen in
[entwuerfe/20-stilvorschlaege.html](entwuerfe/20-stilvorschlaege.html). Der
Betreiber hat es abgelöst, und die Begründung steht in seinen Worten: „zu
schmal, zu kleinlich, zu beschränkt und macht Dinge kompliziert". Die neuen
Richtungen samt der verworfenen Alternative „Werkbank" stehen in
[entwuerfe/30-neue-richtungen.md](entwuerfe/30-neue-richtungen.md), das
bedienbare Muster in
[entwuerfe/31-kontor-mockup.html](entwuerfe/31-kontor-mockup.html). Nichts
davon ist gelöscht: Eine Entscheidung ohne ihre Alternativen ist in einem
halben Jahr nicht mehr nachvollziehbar, und das gilt für die abgelöste
genauso.

**Die Richtung in einem Satz:** hell entworfen, offen, technisch informativ —
die Fläche wird ausgenutzt, Farbe bedeutet etwas oder wird nicht benutzt.

Vier Grundannahmen haben sich gegenüber „Leitstand" geändert. Nicht der
Maßstab — die vier:

1. **Hell entworfen, dunkel mitgeführt** statt umgekehrt. Der Charakter
   entsteht im Hellen; das dunkle Theme ist eine eigene Rechnung und keine
   Umkehrung. Vorher war das helle Theme die Fassung, die seltener jemand
   ansah — und entsprechend sah sie aus.
2. **Keine Karten.** Ein Bereich ist eine Überschrift, eine 2px-Linie und
   Inhalt. Eine Karte kostet je Bereich rund 40 px Innenabstand, und die
   fehlen am Ende als Zeilen. Genau das war der Vorwurf.
3. **Monospace nur für Kennungen** — Pfad, Unit, Systembenutzer, Befehl,
   Vorgangsnummer. Nicht für jede Zahl: Das war „Leitstand", und es liess jede
   Tabelle wie ein Terminal aussehen. Ziffern stehen trotzdem spaltengenau,
   das leistet `font-variant-numeric: tabular-nums` am `body` in jeder
   Grotesk.
4. **Die Form der Bausteine steht in `app.css`**, nicht je Seite. Tabelle,
   Formularfeld, Zustandsmarke, Balken, Meldung, Schalter — vorher gab es
   davon vier bis elf Fassungen über 32 Dateien.

#### Marken (Token)

| Rolle | Marke | Hell | Dunkel |
|---|---|---|---|
| Grund | `--bg` | `#ffffff` | `#0f1116` |
| Ruhige Fläche | `--surface` | `#fafafb` | `#14171d` |
| Trennung | `--line` | `#dcdfe6` | `#2c313d` — 1 px, keine Schatten |
| Bedienelement | `--control-bg`, `--control-line` | `#ffffff`, `#757d8a` | `#191d25`, `#7b8393` |
| Text | `--text-strong` … `--text-faint` | `#0f1115` … `#6b7280` | `#edeff5` … `#858c9a` |
| Akzent | `--accent`, `--accent-on`, `--accent-surface` | `#3730a3` (Indigo) | `#a3aaff` |
| Zustände | `--ok`, `--warn`, `--critical` (je mit `-surface`) | `#076e54` · `#845306` · `#ab2b19` | `#57c99c` · `#e2a94a` · `#f08a72` |
| Radius | `--radius` | 5 px | |

Umgeschaltet wird über `data-theme` (`dark`/`light`) und `data-density`
(`admin`/`customer`) am Wurzelelement. Die CI prüft, dass außerhalb von
`resources/css/app.css` kein Farbwert steht.

**Indigo statt Amber, und der Grund ist kein Geschmack.** Unter „Leitstand"
trugen der Akzent und der Zustand „Warnung" denselben Farbwert `#E0A340` und
mussten sich die Bedeutung teilen — der aktive Menüpunkt sah aus wie eine
Warnung, und wo beides in einer Zeile stand, musste eine Regel entscheiden,
welches gewinnt. Eine tragende Farbe, die nicht nach Warnung aussieht, macht
diese Regel überflüssig.

**Eine Marke für jedes Bedienelement.** Vorher hiessen sie `--button-bg` und
`--button-line` und galten nur für Knöpfe. Das Eingabefeld stand auf `--line`,
einer Haarlinie zum Trennen, und erreichte damit **1,09:1** gegen den
Seitengrund im hellen und **1,13:1** im dunklen Theme — ein Feld ohne sichtbare
Grenze, auf elf Seiten dieselbe abgeschriebene Zeile. Derselbe Fehler wie beim
Knopf mit 1,04:1, nur an einem anderen Element und neun Monate später bemerkt.
Jetzt tragen beide dieselbe Marke, damit es kein drittes Mal gibt.

**`--surface-border` und `--padding` gibt es nicht mehr.** Sie sind mit den
Karten weggefallen — und sieben Seiten nannten sie danach weiter, an elf
Stellen. Der Browser wirft eine Deklaration mit unbekannter Marke still weg:
Der Spaltenkopf der Übersicht hatte keine Linie, die Balkenspur keinen Grund,
die Karte der Anmeldemaske keinen Rand. Monatelang grün getestet. Seitdem
prüft `DesignTokensTest::test_every_token_a_component_uses_exists` **jede**
Marke, die eine Komponente nennt, und nicht mehr nur die der Schriftskala.

#### Bereiche statt Karten

Ein Bereich ist der Baustein, aus dem jede Seite besteht:

```
Überschrift                                    [eine Handlung]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Inhalt
```

Er steht in `resources/js/Components/Bereich.vue`. **Die Breite ist eine
Eigenschaft des Inhalts und nicht der Seite:** `weit` für eine Tabelle mit
mehr als drei Spalten, `voll` für die lange Liste am Ende. Deshalb stehen sie
an der Komponente und nicht als Rasterangabe auf der Seite — wer die Tabelle
um eine Spalte erweitert, ändert die Breite an derselben Stelle mit.

**Flex und nicht Grid**, und das ist im Browser entschieden worden. Der erste
Entwurf hatte `grid-template-columns: repeat(auto-fit, minmax(…, 1fr))`. Ein
Raster hält seine Spaltenzahl über alle Zeilen: Sobald eine Reihe nur einen
Bereich trägt, steht er in *einer* Spalte und die übrigen zwei Drittel bleiben
leer. „Dateisysteme" stand so allein mit 900 px Leerraum daneben — der Vorwurf
„nutzt die Fläche nicht aus", nur in neuer Gestalt.

Die einzige Karte im ganzen Panel ist die Anmeldemaske (`.sheet`). Auf einer
leeren Seite mit einem einzigen Formular kehrt sich die Rechnung um: Dort gibt
der Rahmen dem Formular überhaupt erst seine Gestalt, und es gibt keinen
zweiten Bereich, dem der Platz fehlt.

#### Schrift

- **Kennungen: Monospace** — Pfad, Unit, Systembenutzer, Befehl,
  Vorgangsnummer, Version, IP. Über die Klasse `.ident` und nicht je Seite.
- **Alles andere: Grotesk** (System-Stack, keine nachgeladene Schrift — eine
  Schrift, die nicht ankommt, ist eine Oberfläche, die anders aussieht als
  geplant). `font-variant-numeric: tabular-nums` steht am `body` und lässt
  sich damit nirgends mehr vergessen.
- Kleine Beschriftungen in Versalien mit Sperrung (`.09em`), sonst keine
  Versalien.

**Sieben Rollen, je eine Marke.** Sie stehen in `resources/css/app.css` und
sonst nirgends — dieselbe Regel wie für Farben:

| Marke | Wert | Rolle |
|---|---|---|
| `--text-label` | 11,5 px | Versalien: Spaltenkopf, Kachelbeschriftung |
| `--text-small` | 13 px | Feldbeschriftung, Hinweis, Fehlertext, Beizeile |
| `--text-table` | 14 px | Tabellenzelle, Knopf, Kennung |
| `--text-body` | 15 px | Fließtext |
| `--text-section` | 17 px | Bereichsüberschrift |
| `--text-heading` | 26 px | Seitenüberschrift |
| `--text-metric` | 34 px | die große Zahl auf einer Kachel |
| `--text-input` | 15 px, auf schmal 16 px | die Schrift in einem Eingabefeld |

**Die Skala ist gewachsen, und die alte Vorgabe ist gefallen.** „Fünf Größen,
und keine sechste" widersprach ihrer eigenen Tabelle, die sechs Zeilen hatte,
und der Datei, die sieben Marken führte. Sie drängte ausserdem drei Rollen in
2,5 px — 10,5 px, 11 px und 12 px für Beschriftung, Hinweis und
Tabellenzelle. Eine Rangfolge, die man messen muss, ist keine; auf dem
Bildschirm sah das nach einer Größe mit Messfehlern aus.

**`--text-input` ist eine eigene Marke und keine Wiederholung.** Auf schmaler
Fläche steht sie auf 16 px, weil Safari sonst beim Fokus in die Seite zoomt
(docs/24 §3). Geprüft von
`MobileLayoutTest::test_input_fields_use_the_zoom_safe_size`.

**In px und nicht in rem.** `rem` rechnet gegen das Wurzelelement, und das
steht auf der Browservorgabe von 16 px — die Grundgröße des Panels ist eine
andere. In den Komponenten standen zehn rem-Werte für fünf Rollen, jeder davon
23 % größer als gemeint.

**Nicht nach Dichte gestaffelt.** Die Dichtetabelle unten staffelt Zeilenhöhe,
Abstände und Kacheln. Schriftgrößen nicht: Die Kundenfläche wird ruhiger durch
Luft, nicht durch größere Schrift. Hier stand einmal `--block-heading-size`
mit 13 px auf der Admin- und 15 px auf der Kundenfläche, also genau das, was
die Regel verbietet; `--text-section` tritt an seine Stelle und staffelt
nicht.

Geprüft von `tests/Feature/DesignTokensTest.php` — kein `rem` in einer
Komponente, keine Schriftgröße außerhalb der Skala, keine Marke ohne Wert, und
**keine Stufe ohne Nutzer.**

#### Tabellen — eine Form, drei Muster

Die Form einer Tabelle steht in `app.css` und sonst nirgends: Zeilenhöhe aus
`--row-height`, Innenabstand, Zeilentrenner, Spaltenkopf.

**Der Befund, der diese Regel nötig machte.** Die Dichtestufe verspricht als
erste Zeile ihrer Tabelle die Zeilenhöhe. Die Marke dafür heisst
`--row-height` und wurde von **zwei der 26 Seiten** benutzt. Auf den übrigen
24 entstand die Zeilenhöhe aus `padding: 6px 8px`, je Seite neu geschrieben —
die Kundenfläche war dort also nicht ruhiger als die Adminfläche, und niemand
hat es gemerkt, weil kein Lauf danach fragte. Zehn Seiten definierten `table`,
und es gab zwei unvereinbare Fassungen davon.

Die drei Muster stehen in [24-mobile.md](24-mobile.md) §5 und sind dort
bindend:

| Klasse | Was sie tut | Wofür |
|---|---|---|
| `.scrolls` | rollt waagerecht | die Umschliessung jeder breiten Tabelle |
| `.stacks` | Zeile wird auf schmal zum Kärtchen | Verzeichnisse und Listen |
| `.pairs` | Beschriftung links, Wert rechts | Stammdaten einer Detailseite |

Geprüft von `tests/Feature/TableStyleTest.php` und `MobileLayoutTest`.

#### Die Verlaufskachel und ihre Schwelle

Die Kurve wechselt die Farbe, wenn der **letzte** Wert über einer Schwelle
liegt — sonst Akzent, darüber Warnung. Das bediente Muster nennt es „den
Unterschied zwischen bunt und bedeutend": Fünf Kurven in fünf Farben wären
Dekoration; eine, die als einzige warnt, ist eine Meldung.

**Der letzte Wert und nicht der höchste.** Eine Kurve, die vor einer Stunde
einmal ausgeschlagen ist und seitdem ruhig läuft, warnte sonst für immer — und
eine Warnung, die nicht mehr weggeht, liest nach dem dritten Mal niemand.

**Die Schwellen stehen im Controller**, aus demselben Grund wie das `tight` der
Dateisysteme: Ab wann eine Auslastung eng ist, ist eine Aussage über den
Betrieb und keine über die Darstellung.

| Kachel | Schwelle | woher |
|---|---|---|
| CPU | 85 % | Muster |
| RAM | 85 % | Muster |
| Load | Zahl der Kerne | der Agent zählt sie (`SystemInfo::cpu()['cores']`) |
| Netz | 900 Mbit/s, in Byte gerechnet — **je Richtung** | Muster |
| Schreibdurchsatz | **keine** | siehe unten |

**Warum der Schreibdurchsatz keine bekommt.** Es gibt für ihn keine Zahl, die
auf zwei Servern dasselbe bedeutet: Eine NVMe schreibt zwei Gigabyte je
Sekunde, ein Netzlaufwerk hundert Megabyte. Eine Schwelle, die überall gilt,
warnt entweder ständig oder nie — und das ist schlechter als keine. `null`
heisst hier „es gibt keine" und ist eine Angabe, keine vergessene Zeile.

**Und die Load rechnet mit der wirklichen Kernzahl.** Eine feste Vier wäre hier
besonders falsch: Load 4 heisst auf vier Kernen „ausgelastet" und auf
zweiunddreissig „langweilt sich". Der Agent zählt die Kerne seit P0 und benutzt
hat sie bis dahin niemand.

**Das Merkmal hat ein Jahr lang gefehlt**, und aufgefallen ist es erst, als
zum ersten Mal jemand Messwerte in den Ringpuffer schrieb: In der
Entwicklungsumgebung läuft kein Agent, und auf jeder Kachel stand „noch keine
Messwerte". Ein Merkmal, das man nur mit Daten sieht, braucht einen Test, der
Daten mitbringt — `SeriesThresholdTest` für die Rechnung und
`PanelWalkthroughTest::test_a_tile_over_its_threshold_says_so` über die ganze
Kette, weil ein weggekürztes Argument im Controller sonst still jede Kurve
wieder gleich färbt.

#### Zwei Richtungen in einer Kachel

Das Netz zeigt beides: eingehend als durchgezogene Linie im Akzent, ausgehend
gestrichelt in `--accent-second`. Der Sammler schreibt beide Spalten seit P0;
gezeigt wurde bis August 2026 nur die eingehende — auf einem Webserver
ausgerechnet die ruhigere, denn eine Seite auszuliefern kostet ein Vielfaches
dessen, was ihre Anforderung kostet.

**Die beiden Kurven teilen sich die Achse. Das ist die eigentliche Regel.**
`Store::series()` normiert jede Reihe auf ihr eigenes Kleinstes und Grösstes —
für eine Kurve richtig, für zwei in einem Feld eine Lüge: Der eingehende
Verkehr, tausendfach kleiner, läge gleich hoch und schlüge gleich weit aus.
Deshalb `Store::pair()` mit einer gemeinsamen Spanne; die kleinere Richtung
liegt dann flach unten, und **genau das ist die Auskunft.** Auf einem
Bildschirmfoto sieht der Fehler richtig aus — er hat deshalb einen eigenen
Wächter (`PairedSeriesTest`), der die Geometrie prüft und nicht die
Anwesenheit.

**Die Zahlen teilen sich die Vorsilbe nicht.** Die gemeinsame Achse ist eine
Aussage über die Darstellung; „0,0 MB/s" für 12,9 kB/s wäre eine über den
Messwert und wäre falsch. Jede Richtung bekommt ihre eigene Grössenordnung —
eine je Reihe, gewählt nach ihrem höchsten Wert, damit die Ablesung beim
Wandern über die Kurve nicht zwischen kB/s und MB/s springt.

**Drei Unterschiede und nicht einer.** Farbe, Strichart und die Fläche, die nur
die erste Kurve hat. Rechnerisch liegen Akzent und zweite Farbe im
Helligkeitsverhältnis bei 1,85:1 (hell) und 1,49:1 (dunkel): Wer Farbtöne
schlecht unterscheidet, sähe zwei gleich helle Linien. Der Strich löst das ohne
Farbe (WCAG 1.4.1) — und er steht in **Bildpunkten**, weil die Linie
`vector-effect: non-scaling-stroke` trägt und das Muster damit im
Bildschirmraum rechnet. Zweimal danebengegriffen, bis das im Bild zu sehen war.

**Die Beizeile hält zwei Zeilen frei** (`.tile-sub.paired`), und die Kurven
sitzen am unteren Rand der Kachel statt unter der Beizeile. Gemessen: Eine
Kachel ist auf 1440 px 228 px breit, ihre Beizeile 179 px — darin passen rund
25 Zeichen. Beide Zustände sind dort zweizeilig; ohne die Reserve wuchs die
Kachel beim Zeigen, und ohne die Ausrichtung an der Unterkante lagen fünf
Sparklines auf zwei Höhen.

**Die Ablesung nennt eine Richtung — die, auf die man zeigt.** Beide zusammen
brauchten drei Zeilen. Wer auf eine Kurve zeigt, will diese ablesen; die andere
Zahl steht im Ruhezustand daneben.

#### Blättern

Ein Verzeichnis endet nach 50 Zeilen, und darunter steht der Weg zur nächsten
Seite: `.pager` mit „Zurück", „Seite N von M" und „Weiter".

**Warum Vor und Zurück und keine Seitenzahlenleiste.** Eine Leiste
„1 2 … 7 8 9 … 42" bricht auf 390 px entweder um oder braucht Abkürzungslogik,
und die ist eine klassische Quelle für Zählfehler. Bei fünfzig Zeilen je Seite
hat eine reale Installation ein bis drei Seiten; wer eine bestimmte Zeile
sucht, filtert.

**Der Befund, der das nötig machte, ist ein Jahr alt.** Vier Controller riefen
`paginate()` auf — und **keine einzige Seite zeigte einen Weg zur zweiten.**
Vom Protokoll waren 76 Einträge da und 50 zu sehen; von den Vorgängen, der
Liste, die man ansieht, wenn etwas nicht stimmt, ebenso. Drei der vier
schickten die Seitenzahlen nicht einmal mit; beim vierten kamen sie an und die
Seite warf sie weg. Zwei weitere Verzeichnisse — Abonnements und Pläne —
paginierten gar nicht und wuchsen ohne Grenze.

Kein Fehler, keine Meldung, nur eine Liste, die aufhört. Wieder eine Zusage auf
der einen Seite, der auf der anderen nichts entspricht.

| Wo | Was |
|---|---|
| `App\Support\Web\Page::SIZE` | 50 Zeilen je Seite, für **alle** Verzeichnisse |
| `Page::from($paginator, $row)` | erzeugt die Nutzlast: `data`, `current_page`, `last_page`, `total` |
| `->withQueryString()` | trägt die eingestellten Filter in den Verweis auf Seite 2 |
| `Components/Pager.vue` | zeigt sich nur, wenn es mehr als eine Seite gibt |

Geprüft von `tests/Feature/PaginationTest.php`: Jede Paginierung geht durch den
Helfer, behält ihre Abfrage — und **jede Seite, die eine paginierte Nutzlast
bekommt, zeigt den Pager.** Die dritte Prüfung springt über die Sprachgrenze
und ist die, die gefehlt hat: Der Controller war richtig, die Seite ignorierte
ihn, und beide für sich sahen in Ordnung aus.

#### Die Farben, die der Browser mitbringt

Drei Stellen färbt der Browser von sich aus, und an allen dreien setzt er ein
Blau, das in keiner Marke steht. Sie gehören in `resources/css/app.css` und
nicht in die Komponente, an der sie zuerst auffallen:

- **Selbst ausgefüllte Felder.** Chrome, Edge und Safari malen eine eigene
  Fläche über den Hintergrund — auf hellem Grund ein blasses Gelb, auf dunklem
  ein kräftiges Blau, das das Feld verschluckt. `background` erreicht sie
  nicht; der einzige Weg ist ein Schatten nach innen (`-webkit-box-shadow …
  inset`) samt `-webkit-text-fill-color`.
- **Ankreuzfelder.** `accent-color: var(--accent)`. Ein gesetztes Häkchen ist
  ein Zustand — in einer Gestaltung, in der Farbe etwas bedeutet, behauptet ein
  blaues Häkchen eine zweite Bedeutungsebene, die es nicht gibt.
- **Der Fokusrahmen an Eingabefeldern.** Die Regel für `:focus-visible` gilt
  auch für `input`, `select` und `textarea` — sonst hängt der sichtbare Fokus
  davon ab, welchen Browser jemand benutzt.

#### Das Zeichen

Drei gestapelte Balken, der oberste blau. Bis August 2026 gab es keines: In
der Seitenleiste stand ein Buchstabe in einem amberfarbenen Quadrat — erst
„C" von CloudSrv, dem verworfenen Namen, dann „S" —, und `public/favicon.ico`
lag mit **null Byte** da, dem Platzhalter aus dem Laravel-Gerüst. Im Reiter
des Browsers stand damit das leere Blatt. **Das ist ein Fehler, der sich als
Vorgabe tarnt:** Ein leeres Zeichen sieht aus wie gar keines, deshalb meldet
es niemand.

| Wo | Was | Warum |
|---|---|---|
| Reiter, Lesezeichen | `public/favicon.svg`, `favicon.ico` (16/32/48) | die SVG gewinnt, wo sie verstanden wird; die .ico ist der Rückfall |
| iOS-Startbildschirm | `public/apple-touch-icon.png` (180) | eigenes Bild: iOS legt nichts unter durchsichtige Ecken |
| Android, Manifest | `public/icon-512.png`, `site.webmanifest` | nur Name und Zeichen — kein `display`, also keine App-Installation |
| Oberfläche | `resources/js/Components/MarkIcon.vue` | erbt die Farbe, siehe unten |
| ausserhalb | `resources/images/srvpanel-mark*.svg` | drei Fassungen für fremde Zusammenhänge |

**In der Oberfläche steht es als Quelltext und nicht als Bild.** Ein `<img>`
wäre ein zweiter Aufruf für drei Rechtecke — und vor allem könnte es seine
Farbe nicht erben. Das Panel hat zwei Themes; ein Zeichen mit eingebauten
Farben wäre in einem der beiden falsch. Die zwei unteren Balken nehmen
`currentColor`, der obere `--mark-accent`, und die Marke führt je Theme den
passenden Blauton: auf Dunkel den helleren, auf Weiss den kräftigeren.

**Einfarbig ging nicht.** Der Versuch, das Zeichen in einer Farbe zu zeichnen,
machte aus dem untersten Balken — er steht auf halber Deckung — ein
schmutziges Braun. Das sah nach Fehler aus und nicht nach Gestaltung; gesehen
im Browser bei 22 px, nicht im Entwurf.

**Reiter und Seitenleiste zeigen dasselbe.** Das ist der eigentliche Zweck:
Wer mehrere Panels offen hat, unterscheidet sie am Reiter — und nur dann,
wenn dort steht, was auch in der Anwendung steht.

Geprüft von `tests/Feature/IconTest.php`: Jeder Verweis im Kopf der Seite und
im Manifest zeigt auf eine Datei, die es gibt **und die nicht leer ist**; die
.ico ist wirklich eine und trägt mehr als eine Grösse; die SVG bringt ihre
eigene Fläche mit; und in `MarkIcon.vue` steht kein Hexwert.

#### Knöpfe — eine Form, drei Ränge

Ein Knopf ist keine Sache der Seite, auf der er steht. Bis August 2026 war er
genau das: `padding: 8px 16px` auf der einen Seite, `6px 12px` auf der nächsten,
mal mit Rahmen, mal ohne — und „Kunde anlegen" in der Kundenliste war überhaupt
kein Knopf, sondern ein amberfarbener Link. Auf dem Bildschirm sah das aus wie
eine Beschriftung, die zufällig anklickbar ist. Dasselbe Muster wie bei den
Schriftgrößen: keine Vorgabe, kein Werkzeug, das sie prüft, und nach einem
halben Jahr elf Fassungen desselben Elements.

Die Form steht in `resources/css/app.css` und sonst nirgends:

| Klasse | Aussehen | Wofür |
|---|---|---|
| `.button` | Rahmen und Fläche aus eigenen Marken | die gewöhnliche Aktion |
| `.button.primary` | Akzentfläche | die eine Aktion, für die man die Seite geöffnet hat |
| `.button.danger` | roter Rand, keine Fläche | was sich nicht zurücknehmen lässt |
| `.button.small` | flacher, kleinere Schrift | eine Aktion in einer Tabellenzeile |
| `.button-row` | Reihe, unter 480 px gestapelt | mehrere Knöpfe nebeneinander |

Fünf Regeln dazu:

- **Höchstens ein `.primary` je Formular** — nicht je Seite. „Mein Konto" hat
  zwei unabhängige Formulare untereinander, Stammdaten und Passwortwechsel, und
  jedes hat seine eigene Hauptsache. Wer dort einen der beiden abstuft,
  behauptet eine Rangfolge zwischen zwei Dingen, die nichts miteinander zu tun
  haben.
- **`.danger` bekommt keine Fläche.** Eine rote Fläche neben einer
  Akzentfläche macht aus zwei Rängen einen Wettstreit.
- **Es gilt für `<button>` und für `<Link>`.** Ob hinter einer Aktion ein
  Formular oder eine Adresse steckt, ist eine Frage der Umsetzung und keine der
  Bedienung.
- **Auch `.small` ist auf dem Telefon ein Fingerziel.** Es gibt `min-height`
  auf, um die Tabellenzeile nicht aufzublasen — unter 720 px gibt es diese
  Zeile aber nicht mehr, die Tabelle ist dort ein Kärtchen (docs/24), und der
  Wert kommt auf `--tap` zurück.
- **Den Rand muss man sehen — 3:1, gerechnet.** Der Knopf stand auf `--surface`
  mit einem Rand aus `--line`; im dunklen Theme sind das #111922 und #141d26 und
  damit ein Kontrast von **1,04:1**. Auf dem Bildschirm war „Bearbeiten" kein
  Bedienelement, sondern ein etwas hellerer Fleck, den man für Text hält.
  Aufgefallen ist das erst auf einem echten Monitor: Im Quelltext hat jeder Wert
  einen Namen und sieht deshalb richtig aus. Der Knopf hat seitdem eigene
  Marken — inzwischen `--control-bg` und `--control-line`, die sich Knopf, Feld
  und Auswahl teilen —, und sie sind gerechnet und nicht gewählt: WCAG 1.4.11 verlangt für die Grenze eines Bedienelements 3:1 gegen
  alles, was daneben liegt, und ein Knopf liegt auf dreierlei Grund — auf sich
  selbst, auf einer Karte und auf der Seite.

Nicht jedes anklickbare Element ist ein Knopf. Der Menüknopf der Schublade, das
Augensymbol am Passwortfeld, das Abmelden in der Seitenleiste tragen kein
`.button` und sollen es nicht: Ein Knopf auf einer Seite ist eine Aktion, die
jemand auslöst; das Auge am Passwortfeld zeigt einen Zustand.

Geprüft von `tests/Feature/ButtonStyleTest.php`: Keine Seite unter
`resources/js/Pages` setzt an einem Knopf Innenabstand, Grund, Rahmen, Radius
oder Schriftschnitt; jeder `<button>` trägt die Klasse; kein Formular hat zwei
Hauptsachen; und der Rand erreicht in beiden Themes 3:1 gegen Fläche, Karte und
Seitengrund. Geprüft wird dabei die Rechnung und nicht der Wert — ein Test, der
`#647486` verlangt, hielte die Farbe fest; dieser hält die Eigenschaft fest.

**Ein „Bearbeiten" steht in jeder Liste.** Bei den Abonnements war der Name die
einzige Verbindung zur Bearbeitung, während Kunden und Pläne je Zeile einen
Knopf hatten. Wer eine Liste überfliegt, sucht einen Knopf und keinen Link; und
wenn drei Listen dieselbe Aufgabe verschieden lösen, ist die vierte wieder
anders. Der Name bleibt trotzdem ein Link — er führt auf die Abo-Seite mit
Speicher, Kontingenten und Vorgängen, und das ist etwas anderes als das
Formular.

**Die offene Abweichung ist entschieden — zugunsten des Codes.** „Leitstand"
verlangte 3 px Radius, „kein größerer Wert, nirgends", und der gebaute Panel
hielt das an keiner einzigen Stelle ein: Die Werte lagen zwischen 5 und 8 px.
Eine Vorgabe, die in neun Monaten kein einziges Mal befolgt wurde und die kein
Werkzeug prüft, ist keine Vorgabe, sondern ein Satz im Dokument. „Kontor" führt
`--radius` mit 5 px, und der Wert steht an einer Stelle statt an vierzig.

#### Dichte in zwei Stufen

Die Kritik am dunkelen, dichten Zuschnitt trifft die Kundenfläche, nicht die
Adminfläche. Sie wird nicht durch eine zweite Gestaltung aufgefangen, sondern
durch eine zweite Dichtestufe im selben System:

| Marke | Adminfläche | Kundenfläche |
|---|---|---|
| `--row-height` (Zeilenhöhe) | 40 px | 48 px |
| `--bereich-gap` (Abstand der Bereiche) | 30/44 px | 38/52 px |
| `--bereich-min` (kleinster Grundriss) | 400 px | 460 px |
| `--kachel-min` (kleinste Kachel) | 200 px | 250 px |
| `--block-gap`, `--gap` | 26 px, 14 px | 34 px, 18 px |
| Erklärsatz unter der Überschrift | nur wo nötig | immer |

Gleiche Marken, gleiche Bausteine, gleicher Code — ein Attribut am
Wurzelelement schaltet um. **Keine Kachelzahl mehr, sondern eine Mindestbreite:**
`--tile-columns: 4` hiess auf einem 1920er Bildschirm vier Riesenkacheln und auf
einem 1280er vier gequetschte. Wie viele in eine Reihe passen, rechnet der Fluss
aus der Fläche aus.

#### Wer das Theme wählt

Beide Themes standen ab P1 fertig da — und **schalten konnte sie niemand.**
`data-theme` kam aus `SRVPANEL_THEME` in der `.env`, also serverweit für alle
und nur für jemanden mit Zugriff auf die Datei. Dieser Abschnitt beschrieb
durchgehend das Gestaltungssystem und nie seine Bedienung; in keiner Stufe
P0–P10 stand ein Umschalter. Dasselbe Muster wie beim Zurückziehen eines
Kunden und bei `CustomerStatus::Suspended`: Die Mechanik war vollständig
gebaut, es fehlte der Weg dorthin.

Seit August 2026 steht die Wahl unter „Mein Konto", für **Admins und
Kundenkonten**. Der Kunde am hellen Bürobildschirm ist der Fall, für den das
helle Theme überhaupt verlangt wird; ein Umschalter nur für Betreiber
verfehlte ihn.

| Wert | Bedeutung |
|---|---|
| `accounts.theme = null` | dem Betriebssystem folgen — die Vorgabe |
| `'light'` / `'dark'` | ausdrücklich gewählt |
| `SRVPANEL_THEME` | die Seiten **ohne** Konto: Anmeldung und zweiter Faktor |

**Am Konto und nicht im Browser.** Ein Betreiber arbeitet an zwei Rechnern;
eine Einstellung, die er zweimal treffen muss, ist keine. Der `localStorage`
wäre die bequemere Stelle und die falsche.

**Zwei Fallen, beide erst im Browser aufgefallen:**

1. **Das falsche Theme blitzt auf.** Ein Konto, das dem Betriebssystem folgt,
   kann der Server nicht auflösen — ob dort hell oder dunkel gilt, weiss nur
   der Browser. Trägt die Anwendung das nach, sieht man bei *jedem*
   Seitenaufruf kurz die dunkle Fläche, bevor sie hell wird: Vite lädt sein
   Bündel mit `defer`, und das läuft erst nach dem ersten Zeichnen. Die
   Abfrage steht deshalb als kleines Skript im Kopf, ohne `defer`, vor dem
   Bündel.
2. **Der Klick tut nichts.** `data-theme` steht am `<html>`, und dieses Gerüst
   rendert Inertia bei einer Navigation nie neu: Die Seite wechselt, der
   Rahmen bleibt. Gespeichert wurde richtig, die Bestätigung kam — und zu
   sehen war nichts bis zum nächsten Neuladen. Das Skript im Kopf reicht die
   Umschaltung deshalb als `window.srvpanelTheme` nach aussen.

Geprüft von `tests/Feature/ThemeTest.php`, einschliesslich beider Fallen: Die
Abfrage steht vor dem Bündel und trägt weder `defer` noch `async`, und die
Verbindung zwischen Kopf und Seite besteht. Beides kann reissen, ohne dass
etwas bricht — und das ist die schlimmste Sorte Fehler, weil ihn nichts meldet.

#### Das helle Theme ist Pflicht, nicht Nacharbeit

Die dunkle Fassung ist die, in der diese Richtung ihren Charakter hat. Sie ist
trotzdem nicht die einzige: Ein Kunde, der sein Abonnement am hellen
Bürobildschirm ansieht, hat ein Recht auf eine Fläche, die dort lesbar ist.
Beide Themes entstehen in **P1 zusammen** über dieselben Marken; das helle
nachträglich aufzusetzen, hieße jede Farbentscheidung zweimal zu treffen.

#### Bausteine, die in P1 fertig werden

Kachel mit Verlauf (§4.6) · Tabelle mit Zustandsmarke und Balken ·
Vorgangszeile mit Fortschritt und Ausgabe · Rückfrage in drei Stufen ·
Bereichsrahmen · Navigation mit Gruppen · Meldungsband · Formularzeile mit
Erklärsatz und Fehlerbild · leerer Zustand · Ladezustand.

#### Zwei Prüfungen, die mitlaufen

1. **Kontrast.** Jede Text-auf-Grund-Kombination erreicht mindestens AA
   (4,5:1 für Fließtext, 3:1 für große Schrift). Bei dunklen Oberflächen
   scheitert das regelmäßig an den ruhigen Grautönen — deshalb geprüft und
   nicht geschätzt.
2. **Farbe ist nie der einzige Träger.** Jeder Zustand hat neben der Farbe ein
   Wort oder eine Form. Ein rotes Feld ohne Beschriftung ist für rund acht
   Prozent der männlichen Nutzer kein Signal.

## 8. Querschnittsregeln für jede Ausbaustufe

Eine Stufe ist **nicht fertig**, solange nicht alles davon steht:

1. Datenmodell samt Migration und Rückmigration. Eine Migration muss mit der
   **vorigen** Fassung verträglich bleiben: Der Rückweg beim Update legt den
   Symlink um, nimmt aber keine Migration zurück — eine durchgelaufene
   Migration gilt.
2. Dienstschicht mit Kontingent- und Planprüfung
3. Agent-Operationen, typisiert, mit Vorlagen im Agent
4. Policies für alle drei Ebenen, mechanisch geprüft auf Vollständigkeit
5. Adminfläche **und** Kundenfläche
6. Protokollereignisse für jede Änderung
7. **Löschpfad**: Abo löschen entfernt alles restlos, geprüft durch einen Test,
   der hinterher das Dateisystem und die Systemobjekte absucht
8. **Angriffsdurchgang** als Test: Mandantenübergriff (fremde IDs in jeder
   Route), Pfadausbruch, Befehls- und Vorlageneinschleusung, Kontingentumgehung
9. Berücksichtigung in Sicherung und Wiederherstellung
10. Dokumentation: Betreiber-Runbook und Kundenhilfe

## 9. Umsetzungsphasen

Aufwände: eine Person, KI-gestützt, in Wochen. Die Versionsnummern sind
Freigaben mit eigenem Paket und eigener Ankündigung.

### P0 — Fundament und Werkzeug · 2–3 Wochen · (0.1)

**Ziel:** Ein leeres, aber vollständig lieferbares Panel. Alles Spätere ist nur
noch Fachfunktion.

- Repo neu geschnitten: `app/` (Laravel), `agent/` (PHP-CLI, framework-frei),
  `resources/js/` (Vue), `packaging/`, `docs/`, `tests/`
- Bauweg: Composer ohne Dev-Abhängigkeiten, Vite-Build, alles in ein `.deb`
  mit `/opt/srvpanel/releases/<version>` und Symlink-Umschaltung
- Installer `install.sh`: Vorbedingungen prüfen (Distribution, Speicher,
  Quota-fähiger Mount, belegte Ports), Pakete installieren, Panel-PHP,
  MariaDB, nginx, Datenbank anlegen, systemd-Units, Einmal-Link für die
  Ersteinrichtung
- Bestehende apt-Quelle weiterverwendet (Branch `gh-pages`,
  `repo.cloudsrv24.de`), neues Paket neben dem eingefrorenen alten (§13)
- **Ersteinrichtung `srvpanel setup`**: Datenbank, Anwendungsschlüssel,
  selbstsigniertes Zertifikat, nginx-Server-Block, Dienste — wiederholbar,
  ohne dass ein Schlüssel wechselt
- **Update mit Rückweg**: angestoßen über `srvpanel update` als transiente
  systemd-Unit (der Lauf überlebt den Neustart des Panels), Migrationen und
  Umschalten im Paketskript, danach Bereitschaftsprüfung über HTTP — antwortet
  sie nicht, zeigt der Symlink wieder auf die vorige Fassung
- CI umgebaut (§14): statische Prüfung (PHPStan max, Pint, ESLint, `vue-tsc`),
  Unit- und Feature-Tests, **neu:** Integrationslauf in systemd-Containern für
  Debian 12/13 und Ubuntu 22.04/24.04
- Repo-Übergang vollzogen: `legacy/asylum`, Neuanfang auf `main`, Lizenzwechsel
  auf AGPL-3.0-only samt Kopfzeilen und Quellenlink-Auflage (§12.2)
- Testumgebung: ein Skript, das eine Wegwerf-VM/Container aufsetzt und das
  gebaute Paket installiert

**Fertig, wenn** auf allen vier Zielplattformen aus dem Nichts installiert,
eingerichtet, aufgerufen, aktualisiert und zurückgerollt werden kann.

*Berichtigt beim Bauen:* Hier stand „angemeldet". Anmeldung gibt es erst mit
den Konten, und die kommen in P1 — das Kriterium war von Anfang an nicht
erfüllbar. An seine Stelle tritt, dass die Oberfläche nach der Einrichtung
über HTTPS antwortet. Aus demselben Grund gibt es in P0 **keinen Einmal-Link**:
Er führte zu einem Raum ohne Inhalt. Er entsteht in P1 zusammen mit dem
Administratorkonto.

### P1 — Kern: Konten, Mandanten, Rechte, Vorgänge, Agent · 4–6 Wochen · (0.2)

- Anmeldung, 2FA, ~~Passkeys~~, Wiederherstellung, Ratenbegrenzung, Sitzungen
  (*beim Bauen verschoben:* Passkeys nach P2, Begründung in §15 Punkt 9)
- Accounts/Customers/Subscriptions/Plans als Modell — noch ohne Systemwirkung
- Policies, Mandantenklammer, mechanische Vollständigkeitsprüfung der Routen
- Protokoll mit Filter und Export
- Vorgangssystem: Warteschlange, Zustände, Live-Ausgabe über SSE
- **Agent-Protokoll und `srvpanel-agentd`**, zunächst mit drei echten Operationen
  (Dienstzustand lesen, Datei prüfen, Reload) und einer vollständigen Attrappe
  für die Tests
- Oberflächengerüst: Navigation, Gestaltung, Bestätigungsstufen, Sprachen,
  Fehlerbilder, „Anmelden als", Quellenlink in der Fußzeile (Auflage der AGPL, Abschnitt 13)
- Gestaltungssystem (§7.2) in beiden Dichtestufen und beiden Themes — damals
  „Leitstand", seit August 2026 „Kontor"
- **`srvpanel-metrics` und die Adminübersicht mit Spikelines** (§4.6): CPU,
  RAM, Load, Netz, IO, Datenträger, dazu Prozessliste, Dateisysteme, Uptime
  (*beim Bauen entschieden:* Die Prozessliste ordnet nach Speicher und nicht
  nach CPU. Eine CPU-Angabe je Prozess bräuchte zwei Messungen und damit
  Zustand im Agenten, der bewusst keinen führt; die Gesamtrechenzeit seit dem
  Start wäre ohne Zustand zu haben, sagt aber nur, dass ein alter Prozess viel
  gerechnet hat — nicht, dass er es gerade tut)

**Fertig, wenn** ein Admin einen Kunden anlegt, dieser sich anmeldet, seine
(leere) Übersicht sieht, der Admin auf seiner Übersicht die Verläufe des
Servers ablesen kann, und kein Kunde durch Manipulation von IDs an fremde
Objekte kommt — belegt durch den Angriffsdurchgang.

### P2 — Abonnements und Systembenutzer · 3–4 Wochen · (0.3)

- Abo anlegen: Systembenutzer, Gruppe, Verzeichnisschema, Quota,
  Standard-DocumentRoot, Willkommensseite
- Abo sperren (Webseiten aus, Zugänge aus, Daten bleiben), entsperren,
  löschen (mit Sicherung davor und vollständigem Rückbau)
- Pläne: anlegen, ändern, auf Abonnements anwenden, Abweichungen sichtbar
- Speicherverbrauch je Abo messen und anzeigen
- Adminübersicht: Kunden, Abonnements, Zustand, Verbrauch

**Fertig, wenn** hundert Abonnements angelegt und wieder gelöscht werden
können, ohne dass ein Systembenutzer, ein Verzeichnis oder ein Quota-Eintrag
zurückbleibt.

### P3 — Web und PHP · 4–5 Wochen · (0.4)

- nginx als verwalteter Webserver; ein bestehender Apache wird erkannt und
  nicht angefasst (Betrieb dann verweigert, mit Erklärung)
- Domains: Hauptdomain, Zusatzdomains, Subdomains, Aliase, Weiterleitungen
- DocumentRoot je Domain innerhalb des Abos, Verzeichniswechsel geprüft
- PHP-FPM-Pool je Abo und Version; PHP-Version je Domain umstellbar
- PHP-Einstellungen je Domain (memory_limit, upload_max_filesize,
  max_execution_time, display_errors …) mit vom Plan gedeckelten Grenzen
- `open_basedir`, `disable_functions`, eigenes `tmp`, eigene Session-Ablage
- Logs je Domain, im Panel lesbar, mit Rotation
- Eigene nginx-Direktiven je Domain, gegen Positivliste geprüft
- Standardschutz: `.git`, `.env`, Dotfiles, PHP in Upload-Verzeichnissen

**Fertig, wenn** zwei Abonnements mit je drei Domains und unterschiedlichen
PHP-Versionen parallel laufen und ein Skript im einen Abo nachweislich nicht
auf Dateien des anderen zugreifen kann.

### P4 — TLS · 2–3 Wochen · (0.5)

- ACME: HTTP-01 für alle Domains, DNS-01 für Wildcards
- DNS-01 gegen die eigene Zone (nach P7 automatisch) und gegen externe
  Anbieter über API
- Erneuerung als Zeitplan, mit Warnung und Protokoll
- Eigenes Zertifikat hochladen, Kette prüfen, Ablauf anzeigen
- Zertifikat für die Panel-Fläche selbst
- HSTS, Weiterleitung auf HTTPS, moderne Chiffren, OCSP-Stapling

**Fertig, wenn** ein Kunde ohne Zutun des Admins für seine Domain ein
Zertifikat erhält, die Erneuerung ohne Ausfall läuft und ein Fehlschlag den
laufenden Betrieb nicht unterbricht.

### P5 — Datenbanken · 2–3 Wochen · (0.6)

Der Plan dazu ist [36](36-datenbanken.md).

- MariaDB: Datenbanken und Benutzer je Abo, Namenspräfix, Rechte begrenzt
- Zugriff nur lokal, optional Fernzugriff je Benutzer mit IP-Beschränkung —
  aber erst, nachdem der **Betreiber** `bind-address` freigegeben hat, nie
  durch ein Kundenhäkchen (Leitbild 1)
- Kontingente: Anzahl, Größe (gemessen)
- Import/Export über die Oberfläche, mit Größenbegrenzung und als Vorgang
- ~~Adminer als eingebettetes Werkzeug~~ — **aufgeschoben**, siehe §15 Punkt 5a
- ~~PostgreSQL im selben Zuschnitt, als zweiter Schritt der Stufe~~ — **eigene
  Stufe P5b**, siehe unten und §15 Punkt 5

**Fertig, wenn** ein Kunde eine Datenbank anlegt, benutzt, sichert und
zurückspielt, und ein Datenbankbenutzer nachweislich keine fremde Datenbank
sieht.

**Und ein siebtes Kriterium ist beim Planen dazugekommen:** Ein hochgeladener
Dump, der `GRANT ALL PRIVILEGES ON *.* …` enthält, muss **scheitern**. Ohne
diesen Punkt wäre „zurückspielen" bewiesen und die Isolation dabei aufgehoben —
ein Dump ist beliebiges SQL, und wer ihn als Datenbank-`root` einspielt, hat dem
Kunden die Rechtevergabe geschenkt ([36 §10.2](36-datenbanken.md)).

### P5b — PostgreSQL · 2–3 Wochen · (0.6.x)

Aus P5 herausgelöst (§15 Punkt 5). Der Plan dazu ist [38](38-postgresql.md), die
Übergabe [37](37-uebergabe-an-p5b.md).

- PostgreSQL im Zuschnitt von P5, mit **eigener Abnahme**
- PostgreSQL wird erkannt und auf Verlangen installiert, nie als
  Paketabhängigkeit ([38 §7](38-postgresql.md))
- Fernzugriff wie in P5, nach einem Schalter des Betreibers — aber über einen
  **verwalteten Block in `pg_hba.conf`**, weil die Wirtsbeschränkung dort steht
  und nicht am Benutzer. Und mit einem Rückweg, der nicht meldet, sondern die
  Datei zurückschreibt: Eine kaputte `pg_hba.conf` verhindert nicht den
  laufenden Betrieb, sondern den **nächsten Start**
  ([38 §14](38-postgresql.md))

**Fertig, wenn** ein Kunde eine PostgreSQL-Datenbank anlegt, benutzt, sichert
und zurückspielt — und die sieben Punkte aus [38 §3](38-postgresql.md) belegt
sind.

*Berichtigt am 9. August 2026, nach der Messung.* Hier stand: *„und ein
Datenbankbenutzer die **Namen** fremder Datenbanken nachweislich nicht aufzählen
kann."* Das ist auf einem echten PostgreSQL gemessen worden und in dieser Form
**nicht erfüllbar**, aus zwei unabhängigen Gründen: Der Verbindungsaufbau
unterscheidet „keine Berechtigung" von „gibt es nicht" und verrät damit die
Existenz — das lässt sich durch keine Rechtevergabe schliessen. Und das
Aufzählen liesse sich zwar schliessen, aber der Entzug von `pg_database` nimmt
dem **Kunden** `pg_dump`, auch für seine eigene Datenbank. An die Stelle tritt
das Kriterium aus [38 §3](38-postgresql.md): Namen, die nichts verraten, elf
gesperrte Statistiksichten, kein Zugriff, eine Absperrung, die der Kunde nicht
aufheben kann — und der verbleibende Ratekanal wird im Abnahmelauf ausdrücklich
gefahren und protokolliert, statt verschwiegen zu werden.

### P5c — Datenbankmanagement · 2–3 Wochen · (0.6.x)

Der Plan dazu ist [46](46-datenbankmanagement.md). Er beantwortet §15 Punkt 5a
(Adminer) — **selbst gebaut statt eingebunden**, und damit bleibt die Begründung
von damals stehen, während die Sache anders entschieden ist.

- Tabellen und Struktur durchsehen, Zeilen ansehen, filtern, sortieren,
  blättern, eine Zeile anlegen, ändern und löschen — für **beide**
  Datenbanksysteme
- **Kein freies SQL.** Der Agent bekommt typisierte Fragen und keine Anweisung;
  damit gilt §4.2 wörtlich statt dem Sinne nach, und der Kunde kann weder eine
  zweite Anweisung anhängen noch das Zeitlimit zurücknehmen ([46 §3](46-datenbankmanagement.md))
- Jede Handlung läuft unter einem **befristeten Zugang**, der nach der Anfrage
  fort ist — derselbe Mechanismus, unter dem seit P5 mitgebrachte Dumps laufen.
  Die Trennung durchsetzt damit die Datenbank und nicht unsere Prüfung
- Nur für den Kunden, nicht für den Betreiber. Protokolliert wird, was ändert —
  **ohne die Werte**

**Fertig, wenn** ein Kunde in seiner Datenbank durchsieht, blättert und eine
Zeile ändert — und die sieben Punkte aus [46 §4](46-datenbankmanagement.md)
belegt sind. Der zweite davon ist der, den ein Test nicht findet: Ein Wert mit
Tabulator, einer mit Zeilenumbruch, ein `NULL` und eine leere Zeichenkette
kommen **unterscheidbar** an. Die Textausgabe, mit der der Agent seit P5
antwortet, kann das gemessen nicht — sie macht aus einem Tabulator eine Spalte
und aus einem Umbruch eine Zeile.

### P6 — Dateien, Zugänge, Cron · 3–4 Wochen · (0.7)

**Der Plan dazu ist `docs/51`, die Messrunde davor `docs/50`.**

- Dateimanager: Baum, Editor mit Syntaxhervorhebung, Hochladen, Entpacken,
  Rechte, Suche — alles hart auf die Abo-Wurzel begrenzt
- SFTP mit Chroot je Abo, Schlüsselverwaltung
- Cronjobs je Abo: laufen als Systembenutzer des Abos, Ausgabe wird
  aufgezeichnet, Zeitplan mit lesbarer Übersetzung, Ausführungsverlauf

**Fertig, wenn** der Angriffsdurchgang für Pfadausbruch, Symlink-Tricks und
Cron-Befehlseinschleusung durchläuft.

**Drei Punkte dieser Liste sind am 14. August 2026 gestrichen worden**, damit
die Streichung nicht später als Vergessen gelesen wird (`docs/51 §3`):

- **„Prüfung nach Auflösung von Symlinks" trägt nicht.** Gemessen in
  `docs/50 §3`: Gegen einen Prozess des Abonnements, der
  `renameat2(RENAME_EXCHANGE)` fährt, liess dieses Muster **11 081 von 36 056
  bestandenen Prüfungen** ausserhalb der Grenze lesen. Die Grenze ist statt
  dessen ein Prozess ohne Rechte in einem Chroot — sie wird nicht geprüft,
  sondern vom Kernel gehalten.
- **„Zusätzliche FTP-Konten" entfallen.** Entscheidung des Betreibers: FTP ist
  das unsicherste Protokoll dieses Plans, SFTP deckt denselben Bedarf, und
  `proftpd-basic` gibt es in Ubuntu 24.04 ohnehin nicht mehr.
- **„SSH-Zugang optional freischaltbar" entfällt.** Gemessen in `docs/50 §6`:
  `internal-sftp` läuft im leeren Chroot, eine Shell scheitert mit
  `/bin/bash: No such file or directory`. Ein Shell-Zugang verlangte ein
  **bewohnbares** Chroot je Abonnement — Shell, Bibliotheken, `/dev/null`,
  `/etc/passwd` — und damit ein anderes Verzeichnisschema als §4.5.

### P7 — DNS-Abgleich · 1–2 Wochen · (0.8)

> **Umgeschrieben am 21. August 2026.** Diese Stufe plante bis dahin einen
> eigenen autoritativen Nameserver — PowerDNS über die HTTP-API, Zonenvorlage,
> Eintragseditor, DNSSEC, AXFR. Der Betreiber hat vor dem Bauen gefragt, ob das
> Sinn ergibt, bevor es zu erheblichen Problemen kommen kann, und die Antwort
> war nein. **Die Begründung steht in `docs/72 §1`**, die Messungen dahinter in
> `docs/71`. Von der ursprünglichen Liste bleibt der vorletzte Punkt — und der
> wird die ganze Stufe.

- Das Panel **führt keine Zone**. Es kennt den Sollzustand einer Domain und
  gleicht ihn gegen die **autoritativen** Nameserver ab — nicht gegen den
  Systemauflöser, dessen Zwischenspeicher aus einer Anleitung eine Irreführung
  macht.
- Sollzustand: `A`/`AAAA` für die Domain und `www`. Kein Mail, kein PTR.
- Drei Zustände je Eintrag: zeigt hierher · zeigt woandershin (**mit dem
  gefundenen Wert**) · fehlt. „Zeigt woandershin" ist kein Fehler — ein Kunde
  hinter einem CDN hat genau diesen Zustand.
- `CAA` wird gelesen und nicht gesetzt: Ein Satz, der die eigene
  Zertifizierungsstelle nicht nennt, wird gemeldet, **bevor** eine Bestellung
  daran scheitert.
- Jedes Ergebnis trägt den Zeitpunkt seiner Messung.
- Wildcard-Zertifikate über DNS-01 bleiben unberührt — acht Anbieter plus
  RFC 2136 stehen seit P4 (`docs/34 §6`), und dafür braucht es keine eigene
  Zone.

**Fertig, wenn** eine Domain, deren Einträge woandershin zeigen, mit dem
gefundenen Wert angezeigt wird, ein fehlender Eintrag von einem falschen
unterschieden wird, der Abgleich die autoritativen Server fragt — nachweisbar —
und ein fremdes `CAA` gemeldet wird, bevor es eine Bestellung kostet.

**Was ausdrücklich nicht mehr dazugehört:** eigener Nameserver, Zone,
Eintragseditor, DNSSEC, AXFR, NOTIFY — und das Schreiben in fremde Zonen über
die vorhandenen Anbieter-Zugangsdaten. Das letzte ist der naheliegende nächste
Schritt und eine eigene Entscheidung (`docs/72 §4`).

### P7b — Serververwaltung · ~9 Wochen · (0.8.x)

> **Dazugekommen am 24. August 2026, entschieden vom Betreiber.** Diese Stufe
> stand bis dahin als ein Aufzählungspunkt in P9 („Serververwaltung für den
> Admin: Dienste, Pakete und Systemupdates, Firewall (nftables),
> Fail2ban-Jails, Logs") — und allein dieser Punkt ist nach der Aufstellung in
> `docs/80` **acht bis zehn Wochen**, während P9 insgesamt mit drei bis vier
> angesetzt war. Die Bestandsaufnahme ist `docs/80`, der Plan `docs/81`, die
> Übergabe `docs/79`.
>
> Geplant wurde sie unter dem Namen „P9a", weil sie in P9 stand. Sie hängt aber
> zwischen P7 und P8, und damit war der Name falsch:
>
> > **Ein Name, der eine Reihenfolge behauptet, wird falsch, wenn die
> > Reihenfolge sich ändert — und er wird trotzdem weiterbenutzt, weil er in
> > Überschriften steht.**

Sieben Vorschläge aus `docs/80 §6.1`, in dieser Reihenfolge:

- **A5 — Logs an einer Stelle** · 3–5 Tage · **zuerst**. Positivliste von
  Quellen im Agenten, dazu `/var/log/apt/history.log`. Kein Dateipfad aus dem
  Formular. Zuerst, weil jede folgende Stufe damit billiger wird: A1 erzeugt
  ein Protokoll, das jemand lesen können muss.
- **A9 — Zwei Verwaltungsrollen und die Kontenverwaltung** · 1,5–2 Wochen ·
  **vorgezogen am 24. August 2026**. Der Plan ist `docs/82`. Vorgezogen, weil
  jede Adminfunktion, die vorher entsteht, ihre Rolle beim Bauen entscheiden
  muss — und weil Adminkonten bis dahin **ausschliesslich** über
  `srvpanel admin` auf der Kommandozeile entstehen.
- **A2 — Dienste und Timer** · 1 Woche. Alle Units, Neustartzähler, und **der
  nächste Termin je Timer** — ein Timer ohne Termin meldet „active" und ist
  abgeschaltet (`docs/64`). Braucht die drei feineren Fähigkeiten aus
  `docs/82 §2.3` und kommt deshalb nach A9.
- **A10 — Diagnose des Bestands** · 1 Woche. Knopf und Nachtlauf.
- **A1 — Paketquellen und Systemupdates über apt** · 2–3 Wochen ·
  **abgenommen am 28. August 2026**. Ausgeplant in `docs/81` §2 bis §10, mit
  Abnahmekriterium (§4) und Schrittfolge (§9); der Lauf ist `docs/85`, das
  Protokoll `docs/86`, die Abnahme dessen §6. Die zwei Ausfälle sind die von
  `docs/85 §6` zugelassenen; sechs Reste stehen in `docs/86 §5` benannt offen,
  keiner davon ein Kriterienausfall.
- **A11 — Neustart, Zeitzone des Servers, NTP** · 2–3 Tage.
- **A6 — Zeitpläne des Servers, nur lesen** · 2–3 Tage.
- **A8 — IP-Adressen des Servers** · 2–3 Tage · **eingeordnet am 28. August
  2026**. Welche Adressen der Server hat, welche der DNS-Abgleich als Soll
  nimmt, welche die Vorgabe für neue Domains ist. Sie stand als „eigenständig"
  da und damit nirgends; sie beantwortet die erste Rückfrage, wenn P7s Abgleich
  „zeigt woandershin" meldet — gegen **was** hat er verglichen?
- **A3, erster Wurf — welche Ports lauschen** · 4 Tage · **eingeordnet am
  28. August 2026**, und nur dieser erste Wurf. Er ist **reine Anzeige**:
  `ss -ltnp`, welches Regelwerk läuft, und ob die Ports, die das Panel geöffnet
  hat, von aussen erreichbar sind.

  **Der Grund, dass er hierher gehört und nicht in die Absicherung:** Seit P5b
  liefert das Panel den Fernzugriff auf MariaDB und PostgreSQL aus und öffnet
  damit 3306 und 5432 — und sagt an genau dieser Stelle in der Oberfläche, die
  Firewall sei nicht seine Sache (`docs/36:838`). Das ist ehrlich und trotzdem
  eine halbe Auskunft: **Das Panel öffnet einen Port und kann nicht sagen, ob
  er erreichbar ist.**

  Er schreibt nichts und kann deshalb niemanden aussperren. Die Falle ist keine
  technische, sondern eine der Ehrlichkeit — steht eine Cloud-Firewall davor,
  die das Panel nicht sieht, ist „Port offen" falsch. Was vorher zu messen ist,
  steht in `docs/80` A3.

**Fertig, wenn** ein Betreiber Systemupdates einspielen, Dienste ansehen und
Logs lesen kann, ohne sich anzumelden — und das Abnahmekriterium von A1
(`docs/81 §4`, acht Punkte) auf einem echten Server erfüllt ist.

**Stand am 28. August 2026: A5, A9 und A1 sind abgenommen**, die Rollenteilung
der Updates-Seite ist gebaut. Offen sind A2, A10, A11, A6, A8 und der erste
Wurf von A3.

**Was hier ausdrücklich nicht dazugehört:** der **zweite** Wurf von **A3** —
der, der schreibt — und **A4** (Anmeldeschutz über fail2ban). Beide stehen seit
dem 28. August 2026 in **P9b**, unten.

> **Berichtigt am 28. August 2026.** Hier stand, **drei** Vorschläge hätten
> keine Stufe, und **A7** war der dritte. Das war falsch, und der Widerspruch
> stand sechzig Zeilen weiter im selben Dokument: P9 führt „Ressourcen­über­
> wachung des Servers, Schwellen, Benachrichtigungen (E-Mail, Webhook)" in
> seiner Aufzählung — das **ist** A7 — und nennt in seiner eigenen Notiz nur
> „Firewall und Fail2ban" als ohne Stufe. `CLAUDE.md` und `docs/81 §12.1`
> hatten die Drei-Version übernommen.
>
> **Zwei Zeilen desselben Dokuments über dieselbe Frage laufen auseinander, und
> keine von beiden ist der Ort, an dem man nachsieht.**

> **A9 stand bis zum 24. August in dieser Gruppe und ist herausgelöst worden.**
> Sie war die folgenreichste darin: Sie teilt den Admin in Betreiber und
> Administrator (`§6.1`), und wer eine Adminfunktion baut, entscheidet **beim
> Bauen**, auf welcher Seite sie liegt. Jede Woche, die sie später kommt, ist
> eine Woche Adminfunktionen, die diese Entscheidung nachtragen müssten.

### P8 — Sicherungen und Wiederherstellung · 3–4 Wochen · (0.9)

- Sicherung je Abonnement: Dateien, Datenbanken, Konfiguration (Domains, DNS,
  Cron, FTP, Zertifikate) als beschriebenes, portables Format mit Manifest
- Zeitpläne, Aufbewahrungsregeln, Ziele: lokal, S3-kompatibel, SFTP/FTP
- Wiederherstellung: vollständig oder einzeln (eine Domain, eine Datenbank,
  ein Verzeichnisstand) — **durch den Kunden selbst**
- Automatische Sicherung vor riskanten Aktionen (Löschen, PHP-Wechsel,
  Wiederherstellung)
- Prüflauf, der eine Sicherung regelmäßig testweise zurückspielt

**Fertig, wenn** ein vollständig gelöschtes Abonnement aus einer Sicherung
wiederhergestellt wird und danach Webseiten, Datenbanken, DNS und Cron
funktionieren — geprüft durch einen automatisierten Lauf, nicht von Hand.

### P9 — Kundenfähigkeit und Betrieb · 3–4 Wochen · (0.10)

- Statistik mit Spikelines auf Abo- und Domain-Ebene (§4.6): Speicherplatz,
  Traffic, Zugriffe, Datenbankgrößen, FPM-Prozesse — Tagesauflösung über
  30 Tage, aus der verdichteten Tabelle statt aus dem Ringpuffer
- Auswertung der Zugriffs-Logs je Domain als Nachtlauf, mit Aufbewahrungsfrist
- Ressourcenüberwachung des Servers, Schwellen, Benachrichtigungen (E-Mail,
  Webhook) — **das ist A7**, und ausgeschrieben steht er in `docs/80`. Diese
  Zeile war dünner als er: Es fehlten die **Entprellung** (eine Platte, die um
  die Schwelle pendelt, sind 400 Mails), die Begründung des zweiten Kanals —
  *eine Meldung über einen Ausfall, die über den ausgefallenen Weg geht, kommt
  nicht an*, und der Mailversand läuft über diesen Server — samt der Anzeige
  „zuletzt erfolgreich zugestellt", weil ein Kanal, der schweigt, von einem,
  der nichts zu melden hat, sonst nicht zu unterscheiden ist.

  **Und seine Auslöserliste ist seit P7b gewachsen:** Dienst tot und Timer ohne
  nächsten Termin kommen aus A2, offene Sicherheitsupdates und der ablaufende
  Signaturschlüssel aus A1. Wer A7 baut, liest die Liste in `docs/80` und nicht
  diese Zeile.
- Benachrichtigungen an Kunden: Kontingent erreicht, Zertifikat läuft ab,
  Sicherung fehlgeschlagen
- Branding: Logo, Farben, Fußzeile, Absenderadresse, eigene Panel-Domain
- ~~Serververwaltung für den Admin~~ — **seit dem 24. August 2026 eine eigene
  Stufe, P7b**, und damit vor P8 statt hier. Der Punkt bleibt als Zeile stehen
  und nicht als stille Streichung: Wer P9 plant, soll sehen, wohin er gegangen
  ist. Firewall und Fail2ban hatten bis zum 28. August 2026 keine Stufe; sie
  stehen jetzt in **P9b**, unten.
- API v1 mit OpenAPI-Beschreibung und Tokens; darüber Abonnement anlegen,
  ändern, sperren, löschen
- Dokumentation: Betreiberhandbuch, Kundenhilfe in der Oberfläche

**Fertig, wenn** ein fremder Kunde das Panel benutzen kann, ohne zu fragen —
gemessen an einem Durchlauf mit einer Person, die das Projekt nicht kennt.

### P9b — Absicherung des Servers · ~3 Wochen · (0.10.x)

> **Entschieden am 28. August 2026 vom Betreiber.** `docs/81 §12.1` schlug
> „eine eigene Stufe nach P9" vor und liess sie offen; `docs/80 §6.2` nannte
> sie „P9c" und rechnete A7 und A9 mit. Beide sind inzwischen anderswo — A9 in
> P7b, A7 in P9 —, es bleiben zwei.
>
> **Der Name folgt P7b:** eine Stufe, die zwischen zwei bestehende gehört, trägt
> den Buchstaben der davor. Nicht „P10a", denn sie kommt **vor** P10, und der
> Unterschied ist hier der ganze Grund.

- **A3, zweiter Wurf — die Firewall schreiben** · 1,5 Wochen. In eine **eigene**
  nftables-Tabelle, die den Bestand nicht anfasst. Der erste Wurf (Anzeige) ist
  in P7b.
- **A4 — Anmeldeschutz über fail2ban** · 1 Woche. Jails lesen, Sperren zählen,
  entsperren, eine eigene Jail für das Panel-Log. Der Entsperren-Knopf ohne
  Prüfung wäre „beliebige Zeichenkette an fail2ban": Die Adresse gehört
  validiert, der Jailname kommt aus der gelesenen Liste.

**Warum vor P10 und nicht darin.** P10 enthält den vollständigen
Angriffsdurchgang und den **externen Sicherheits-Review**. Eine Firewall, die in
P10 entsteht, wird von genau dem Durchgang begutachtet, der sie hätte prüfen
sollen.

> **Eine Härtungsstufe, die selbst noch baut, prüft ihr eigenes Werk.**

**Die Falle dieser Stufe ist das Aussperren.** Eine Firewall über das Panel zu
verwalten heisst, sich über das Panel aussperren zu können. Jede Änderung
braucht eine **Rücknahme nach Zeit** — der neue Regelsatz gilt, und wenn niemand
innerhalb von zwei Minuten bestätigt, stellt eine transiente Unit den alten
wieder her.

> **Ein Rückweg, der voraussetzt, dass man noch drankommt, ist keiner für den
> Fall, dass genau dieser Vorgang einen aussperrt.**

Derselbe Satz wie beim `sshd` in P6 und bei der Netzbeschränkung aus A9, an
einer dritten Sache. Und die transiente Unit ist dieselbe Bauart wie in A1 —
mitsamt der Familie aus `docs/86 §5`, die dort noch offen ist: Ein Vorgang, der
nur absetzt, sagt über den Ausgang nichts. Hier wäre das teurer als bei einem
Upgrade.

**Fertig, wenn** ein Regelsatz über das Panel gesetzt wird, die Bestätigung
ausbleibt und der alte Zustand von selbst zurückkommt — gemessen auf einem
echten Server, mit einer Verbindung, die dabei wirklich abreisst.

**Sie darf auch nach P10 kommen** (`docs/80 §6.2`), wenn der Server ohnehin
hinter einer Cloud-Firewall steht — **aber dann als Entscheidung und nicht als
Versäumnis**, und P10s Angriffsdurchgang läuft dann gegen ein Panel ohne
eigene Firewall. Das ist die vorsichtigere Annahme und deshalb kein Fehler.

### P10 — Härtung und Freigabe · 3–4 Wochen · (1.0)

- Vollständiger Angriffsdurchgang über alle Module, dokumentiert
- Lasttest: 200 Abonnements, 1000 Domains — Antwortzeiten, Speicher,
  Reload-Dauer von nginx, Größe der Konfiguration
- Externer Sicherheits-Review, Befunde abgearbeitet
- Upgrade-Pfad zwischen allen 0.x-Fassungen geprüft
- Sicherheitsbetrachtung als eigenes Dokument, mit den benannten Grenzen
  (§4.2)
- Freigabe 1.0

**Summe: 36–48 Wochen** bis 1.0 — am 28. August 2026 um vier Wochen
fortgeschrieben: **P9b** (rund drei) sowie A8 und der erste Wurf von A3 in P7b
(zusammen gut eine). Die Zahl wächst, weil Arbeit einen Ort bekommen hat, die
vorher keinen hatte — nicht, weil neue dazugekommen wäre.

> **Eine Summe, aus der das Heimatlose fehlt, ist nicht kleiner — sie ist
> unvollständig.** Die Zahl ist ehrlich gemeint, nicht
verhandelt: Ein Hosting-Panel ist kein Feature, sondern ein Betriebssystem-
Aufsatz mit einer Fläche für Fremde.

## 10. Reihenfolgebegründung

Zwei Abweichungen von der naheliegenden Reihenfolge, beide bewusst:

**Der Agent kommt in P1, nicht wenn er gebraucht wird.** Wenn die Trennlinie
erst dann entsteht, wenn die erste privilegierte Operation ansteht, ist sie
schon durchlöchert — die Anwendung hätte dann bereits gelernt, Dinge selbst zu
tun. Sie kommt zuerst und mit einer Attrappe, damit alles Weitere gar keinen
anderen Weg kennt.

**DNS kommt spät (P7), obwohl Domains früh kommen.** Eine Domain ist ohne
eigene Zone benutzbar (externer DNS), eine Zone ohne Domain nicht. Und DNS ist
die Stufe mit der höchsten Außenwirkung eines Fehlers: eine falsche Zone nimmt
Kunden vom Netz, ein falscher Vhost nur eine Seite.

> **Nachgetragen am 21. August 2026.** Dieser Absatz hat die Entscheidung von
> P7 vorweggenommen, und niemand hat ihn so gelesen — auch ich nicht, als ich
> die Stufe geplant habe. Er nennt beide Hälften: dass eine Domain ohne eigene
> Zone benutzbar ist, und dass ein Fehler hier Kunden trifft und nicht eine
> Seite. Zusammengenommen ist das die Begründung dafür, die Zone gar nicht erst
> zu führen — nicht nur dafür, es spät zu tun. Gefragt hat danach der Betreiber
> (`docs/72 §1`).
>
> **Eine Begründung für die Reihenfolge kann eine gegen die Sache sein, und man
> merkt es erst, wenn jemand die Frage stellt.**

## 11. Nach der 1.0

In dieser Reihenfolge, ohne Zusage:

1. **Mail** (Postfix/Dovecot, Rspamd, DKIM, Webmail, Konten und Filter im
   Kundenbereich) — die größte Einzelstufe, eigenes Konzept nötig
2. **Reseller-Ebene** — Kontingentweitergabe, eigenes Branding
3. **Import aus Plesk-/cPanel-Sicherungen** — der Weg zu echten Kunden
4. **WordPress-Toolkit** — Installation, Updates, Absicherung, Klone
5. **Node.js-/Python-Anwendungen** je Abo (systemd-Units, Reverse Proxy)
6. **RHEL-Familie**
7. **Mehrere Server aus einem Panel**

## 12. Name und Lizenz

### 12.1 SrvPanel

Zwei Vorschläge sind vorher gescheitert, und beide Male am selben Prüfschritt:
Vor dem ersten Tag geht der Name in ein öffentliches apt-Repository und danach
in die `sources.list` fremder Server. Bis dahin kostet ein Wechsel Stunden,
danach eine Migration.

**CloudPanel** — verworfen, weil es unter diesem Namen bereits ein
verbreitetes kostenloses Hosting-Panel gibt (cloudpanel.io, MGT-COMMERCE
GmbH): dieselbe Produktkategorie, derselbe Markt, derselbe Sprachraum.
Markenrechtlich angreifbar, sobald das Produkt an Dritte geht, und praktisch
unauffindbar, weil jede Suche beim anderen landet.

**CloudSrv** — verworfen nach der Recherche über offene Quellen. Gefunden
wurden: ein aktiv benutzter Firmenname unter cloudsrv.org (IT-Dienstleistung,
Softwareentwicklung); die britische CLOUDSRV LIMITED (Companies House
10085676, SIC 63110 „data processing, hosting and related activities",
inzwischen aufgelöst) — also genau dieses Feld; ein belegter
GitHub-Namensraum; und keine einzige freie Domain unter `.de/.com/.org/.net/`
`.io/.eu`. Eine identische eingetragene Marke war nicht auffindbar, wohl aber
im Umfeld CLOUDSURF (US #6872202) und CLOUDSERVE (US #4157170), beide
eingetragen und in derselben Warenklasse. Die Erweiterung auf **CloudSrv24**
— die Domain steht zur Verfügung — löst das nicht: Der prägende Bestandteil
bliebe „cloudsrv", eine angehängte Zahl ist kennzeichnungsschwach.

**SrvPanel** ist eine Zusammensetzung aus zwei beschreibenden Bestandteilen.
Als Marke ist das schwach — und genau das ist hier der Vorteil, weil es
umgekehrt auch von niemandem gut monopolisierbar ist. `srv` ist die
kanonische Abkürzung im Unix-Umfeld (`/srv` steht so im FHS), der Name ist
acht Zeichen lang und liest sich ohne Erklärung. Frei sind zum Zeitpunkt der
Prüfung: Packagist-Vendor, npm, Docker Hub, GitHub-Namensraum sowie
`srvpanel.com/.io/.net/.org/.dev` (`.de` ist belegt). Kein Hosting-Panel
dieses Namens ist auffindbar.

**Der Name der Software und die Adresse des Projekts bleiben getrennt.**
`cloudsrv24.de` gehört dem Projektinhaber und bedient weiter Landingpage und
apt-Repository (`repo.cloudsrv24.de`); das Paket heißt `srvpanel`. Das ist
üblich und hier zusätzlich sinnvoll: Unter AGPL darf jeder forken und
weiterverbreiten — trüge das Projekt den Namen der Vertriebsmarke, täten das
die Forks auch.

**Was diese Recherche nicht ist.** DPMAregister, EUIPO eSearch und TMview
waren aus der Arbeitsumgebung nicht abfragbar (403). Es ist eine
Plausibilitätsprüfung über offene Quellen, keine Identitäts- oder
Ähnlichkeitsrecherche. Für ein AGPL-Projekt unter eigener Domain ist das
vertretbar; **soll das Produkt verkauft werden, bleibt eine Recherche in den
Klassen 9 und 42 nachzuholen** — und eine Wortmarke ist dann die einzige
Handhabe gegen Weiterverkauf unter demselben Namen. Die Lizenz leistet das
nicht.

### 12.2 AGPL-3.0-only

Der Wechsel weg von Apache-2.0 ist jetzt kostenlos: neue Codebasis, ein
Rechteinhaber. Später wäre er es nicht mehr.

**Warum Copyleft.** Apache-2.0 erlaubt jedem — auch einem konkurrierenden
Hoster —, das Panel zu nehmen, zu schließen und als eigenes Produkt zu
verkaufen. Das ist genau das eine Szenario, das wehtut. Die AGPL schließt es
aus, ohne die Quelle zu verstecken, und offene Quelle ist bei Software, die
als root auf fremden Servern läuft, ein Argument und kein Verlust.

**Warum das die Kommerzialisierung nicht verbaut.** Solange der Copyright
beim Projektinhaber bleibt — Beiträge Dritter nur mit DCO, bei größeren
Beiträgen mit CLA —, ist eine kommerzielle Zweitlizenz für alle möglich, die
nicht unter AGPL arbeiten wollen. Das Modell ist erprobt (Grafana, Nextcloud,
Mattermost).

**Drei Folgen für die Umsetzung**, alle in P0:

1. **Quellenlink in der Oberfläche.** Abschnitt 13 der AGPL verlangt, dass wer die Software
   über das Netz benutzt, an den Quelltext kommt. Kunden benutzen das Panel
   über das Netz. Also: ein Link in der Fußzeile beider Flächen, der auf die
   **exakt laufende Fassung** zeigt (Version und Commit), nicht bloß auf das
   Repository. Der Link bleibt auch bei eingeschaltetem Branding stehen.
2. **Lizenzprüfung der Abhängigkeiten in der CI.** Laravel und Vue sind MIT
   und damit unproblematisch; eine Abhängigkeit unter einer mit AGPL
   unvereinbaren Lizenz muss auffallen, bevor sie im Paket landet.
3. **Der Vorgänger bleibt Apache-2.0.** Das gilt für alles, was unter dem Tag
   `v0.6.2` steht, und ist nicht rückwirkend zu ändern — es ist auch nicht
   nötig.

## 13. Repo-Übergang

**Kein Wipe und kein neues Repository.** Der Grund liegt nicht in der
Historie, sondern im Branch `gh-pages`: Dort liegt das apt-Repository, das
`repo.cloudsrv24.de` ausliefert, samt CNAME, Archiv-Keyring, minisign-Schlüssel
und Landingpage. Ein neues Repository hieße, das komplett neu aufzusetzen; ein
Wipe der Historie brächte dafür nichts — der alte Code stört nicht mehr, sobald
er aus dem Arbeitsbaum verschwunden ist, und eine ausradierte Vorgeschichte ist
schlechter belegt als eine sichtbar abgebrochene.

Der Übergang:

1. **Der Vorgänger bleibt erreichbar.** Der Tag `v0.6.2` zeigt bereits auf den
   aktuellen `main`. Dazu ein Branch `legacy/asylum` als lesbarer Einstieg.
2. **Ein Commit auf `main`** entfernt alles bis auf `.github/` (wird umgebaut,
   nicht neu geschrieben — §14), `docs/19-sprache-der-oberflaeche.md` und
   dieses Dokument, und legt den neuen Baum an. `LICENSE` wird gegen die
   AGPL-3.0 getauscht. `CHANGELOG.md` beginnt neu; die 157 kB Vorgeschichte
   bleiben im Tag.
3. **Das alte Paket bleibt im apt-Repository stehen**, eingefroren unter
   seinem Namen. Bestehende Installationen liefen sonst beim nächsten
   `apt update` ins Leere. Das neue Paket `srvpanel` kommt daneben —
   dieselbe Site, zwei Pakete, getrennte Kanäle.

   Eingelöst wurde das nur zur Hälfte, und das gehört hier hin statt in eine
   Fußnote: Der erste Freigabelauf unter dem neuen Namen hat 92 ältere
   Fassungen des Vorgängers aus dem Pool geräumt (404 MiB), weil die Regel
   „je Paket nur die letzten fünf" über alle Paketverzeichnisse lief statt
   nur über die eigenen. Die Ursache ist behoben. Die Dateien sind bewusst
   **nicht** zurückgeholt worden: `asylum-panel` 0.6.1 und 0.6.2 liegen noch
   im Pool, ein bestehender Server kann also weiter aktualisieren — ältere
   Fassungen sind nicht mehr installierbar, und der `stable`-Index verweist
   für sie ins Leere.
4. **`main` bleibt geschützt**, Entwicklung auf Branches, Freigaben über Tags —
   wie bisher.

Die Versionszählung beginnt neu bei 0.1. Dass es schon einmal eine 0.6.2 gab,
steht in der Vorgeschichte und ist kein Grund, bei 0.7 anzufangen: Es ist ein
anderes Produkt.

## 14. Was aus der CI wird

Die drei Workflows umfassen 890 Zeilen und sind fast durchgehend Go-spezifisch.
Umgebaut, nicht weggeworfen:

**Bleibt nahezu unverändert** — und ist das Wertvollste am Bestand:

- der Pages-/apt-Job aus `release.yml`: Pool, Kanäle, `Release`-Datei,
  Keyring, Landingpage. Nur Namen ändern sich.
- `secrets.yml`, die Prüfung der Signaturschlüssel.
- die Signatur der Artefakte mit minisign und die OpenPGP-Signatur des
  Repositories. **Die Schlüssel bleiben, wie sie sind.**
- der Shellcheck-Job für `install.sh` und die Paketierungsskripte.

**Wird ersetzt:** `setup-go`, golangci-lint, goreleaser und der
Reproduzierbarkeitsnachweis des Svelte-Bundles gegen `embed.FS` weichen
`setup-php` mit Composer, PHPStan, Pint, Pest, Node/Vite, ESLint, `vue-tsc`
und Playwright für die Kundenfläche. Das `.deb` baut nfpm statt goreleaser.
Die Schwachstellen- und Lizenzprüfung wechselt von `govulncheck` auf
`composer audit`, `npm audit` und eine Lizenzprüfung gegen die AGPL-Auflage
(§12.2).

**Kommt neu dazu, und das ist der eigentliche Gewinn:** eine Integrationsmatrix
über Debian 12/13 und Ubuntu 22.04/24.04 in systemd-fähigen Containern, die
das gebaute Paket installiert, den Dienst startet, den Agenten anspricht und
einen Rauchtest fährt. So etwas gibt es heute nicht — und genau diese Lücke hat
die `ProtectSystem`-Härtung durchgehen lassen, die in 0.6.2 jede
Paketinstallation über das Panel unmöglich machte. Gefunden hat sie kein Test,
sondern der erste Lauf auf einem echten Server. Ein Panel, das fremde Kunden
bedient, darf sich das nicht zweimal leisten.

Der Aufwand steckt in P0 und ist dort eingerechnet.

## 15. Offene Punkte

Diese Fragen blockieren P0 nicht, brauchen aber eine Antwort, bevor die
jeweilige Stufe beginnt:

Name (§12.1), Lizenz (§12.2), Repo-Übergang (§13), apt-Quelle und PHP-Bezug
(§4.3) und der Umbau der CI (§14) sind entschieden und stehen nicht mehr hier.
Offen bleibt:

| # | Frage | Spätestens vor |
|---|---|---|
| 1 | **Markenanmeldung** für SrvPanel — nötig, sobald das Panel an Dritte verkauft wird; Recherche DPMA/EUIPO vorher | vor dem ersten Verkauf |
| 2 | **Beitragsregelung**: DCO reicht, oder CLA, damit die Zweitlizenz belastbar bleibt? | erster Fremdbeitrag |
| 2a | **Archiv-Keyring im Paket ausliefern?** Heute kommt er nur über `install.sh` auf den Server und wird danach von nichts aktualisiert — ein Schlüsseltausch wäre Handarbeit bei jedem Nutzer. Ihn ins Paket zu legen (wie `debian-archive-keyring`) löst das, nimmt ihn aber bei `apt remove` mit und hinterlässt eine unprüfbare Paketquelle. Braucht einen entfernungsfesten Entwurf (§ [21](21-signaturschluessel.md)) | vor 1.0 |
| ~~3~~ | **Beantwortet in P3: `deb.sury.org` bleibt.** Die Abhängigkeit ist auf eine Stelle zusammengezogen — `srvpanel-php-source` trägt die Quelle ein, `PhpVersions::CATALOG` nennt die Versionen, `PhpVersions::EXTENSIONS` die Pakete. Ein Wechsel auf einen eigenen Spiegel ist damit eine neue Fassung eines Pakets und keine Umbauaktion. Die Schwelle bleibt die aus §4.3: zahlende Kunden, zehn bis zwanzig Euro im Monat für Objektspeicher. Bis dahin steht die Abhängigkeit in der Sicherheitsbetrachtung — und neu: **kein Freitext erreicht apt.** Der Paketname entsteht aus zwei Positivlisten im Agenten | erledigt |
| 3a | **Den Schlüssel von sury im Paket mitliefern statt beim Einrichten zu holen?** `srvpanel-php-source` lädt ihn heute im `postinst` über das Netz — das braucht Netz zur Installationszeit und ist nicht reproduzierbar. Ihn einzubetten wäre besser, bindet aber einen fremden Schlüssel an unsere Fassung: Rotiert sury, ist jede ausgelieferte Fassung falsch, bis eine neue erscheint. Dieselbe Abwägung wie Punkt 2a, nur mit einem Schlüssel, der uns nicht gehört | vor 1.0 |
| ~~4~~ | **Beantwortet in P3: dauerhaft nur nginx.** Zwei Webserver-Vorlagen zu pflegen verdoppelte genau die Fläche, die klein bleiben soll — und die Vorlagen sind die Stelle, an der ein Fehler `root` auf ein fremdes Verzeichnis zeigen lässt. Ein *laufender* fremder Webserver verweigert den Betrieb (`webserver.detect`), ein bloss installierter nicht: Auf manchen Systemen liegt Apache als Abhängigkeit herum, ohne je zu starten, und wer deswegen den Dienst verweigerte, verweigerte ihn auf einem Server, auf dem nichts im Weg ist | erledigt |
| ~~5~~ | **Beantwortet vor P5: in der 1.0, aber als eigene Stufe P5b.** Nicht „nach hinten" und nicht „als zweiter Schritt der Stufe", wie §9 P5 es formuliert hatte — sondern getrennt abnehmbar, mit eigener Übergabe ([37](37-uebergabe-an-p5b.md)), eigenem Plan ([38](38-postgresql.md)) und eigener Abnahme. Der Grund ist eine Überprüfbarkeit: [36 §14](36-datenbanken.md) behauptet, ein zweites Datenbanksystem sei eine Erweiterung und kein Umbau. Eine eigene Stufe ist die Bauform, in der sich das nachweisen lässt — muss P5b `agent/src/Db/` aufreissen, war die Trennung falsch, und es fällt auf, bevor MariaDB darunter leidet. **P5 baut dafür nichts vor:** keine `engine`-Spalte auf Verdacht, keine Schnittstelle mit einer einzigen Umsetzung. Die ganze Vorleistung ist eine Unterlassung — `Db\Session` bleibt die einzige Stelle, die `mysql` aufruft, und kein Modell, keine Tabelle und keine Spalte trägt `mysql` im Namen. Zu klären hat P5b vor allem eines: „sieht keine fremde Datenbank" heisst dort etwas anderes, weil `pg_database` für jeden lesbar ist und `REVOKE CONNECT` die Verbindung nimmt und nicht den **Namen** | erledigt |
| ~~5a~~ | **Beantwortet am 12. August 2026: die Alternative, nicht Adminer.** Aufgeschoben war er vor P5, aus demselben Grund, aus dem `certbot` mit P4 wieder von der Positivliste verschwunden ist: fremder Code auf dem Panel-Host, den wir ab dann mit ausliefern und aktualisieren — hier mit Datenbankzugangsdaten. **Diese Begründung bleibt vollständig stehen; entschieden ist nur die Sache anders.** Gebaut wird die „schmale eigene Tabellenansicht", die hier schon als Alternative stand — als eigene Stufe P5c mit eigenem Plan ([46](46-datenbankmanagement.md)) und eigener Abnahme. Der dritte Einwand aus [36 §13](36-datenbanken.md) — „Anmeldung ohne Passwortweitergabe ist baubar und ist nicht wenig" — ist inzwischen keiner mehr: Der befristete Zugang läuft seit P5 unter jedem Zurückspielen, gemessen 11,2 ms je Anfrage. Und der Einwand „ein Werkzeug für zwei Systeme ist etwas anderes als eines für eines" ist der Grund, warum die Stufe nach P5b liegt und nicht davor. **Die volle SQL-Fläche entfällt ausdrücklich** (Entscheidung 2 in [46 §3](46-datenbankmanagement.md)): kein Eingabefeld für Anweisungen, sondern typisierte Fragen an den Agenten | erledigt |
| 5b | **PostgreSQL-Erweiterungen**: welche gehören auf die Positivliste? In P5b gehört die Datenbank dem Panel und nicht dem Kunden — das ist die einzige Bauform, in der der Kunde die Absperrung nicht selbst wieder aufheben kann ([38 §5](38-postgresql.md), gemessen). Der Preis ist, dass er kein `CREATE EXTENSION` bekommt, auch kein `pgcrypto`, das PostgreSQL selbst als vertraut einstuft. Der Ausweg ist eine Positivliste im Agenten und eine Operation, die daraus installiert — wörtlich die Form von `PhpVersions::EXTENSIONS`. **Welche Erweiterungen daraufgehören, ist eine Frage an den Betrieb und nicht an einen Plan**, und sie lässt sich erst beantworten, wenn jemand PostgreSQL im Panel benutzt hat | nach P5b |
| ~~5c~~ | **Beantwortet am 9. August 2026: Der Fernzugriff auf PostgreSQL wird in P5b gebaut.** Er stand einen halben Tag lang als offener Punkt hier, weil [38 §14](38-postgresql.md) abgeraten hatte: Ein Include-Punkt für `pg_hba.conf` existiert erst ab PG 16 und ist bei Debian auch dort nicht eingeschaltet, ein Fernzugriff wäre also auf der Hälfte der Zielplattformen anders gebaut. **Die Nachmessung hat die Begründung umgeworfen** — ein verwalteter Block zwischen Marken braucht keinen Include-Punkt und ist auf PG 14 bis 17 derselbe Bau. Die Prämisse stimmte, die Folgerung nicht; es ist derselbe Fehler, den [38 §3](38-postgresql.md) für `pg_database` beschreibt, im selben Dokument ein zweites Mal. Was aus der Messung wirklich bleibt, ist ein anderes Risiko: Eine kaputte `pg_hba.conf` stört den laufenden Betrieb nicht und verhindert den **nächsten Start** — deshalb rollt die Operation die Datei zurück, statt den Fehler zu melden | erledigt |
| 6 | **FTP** (unverschlüsselt/FTPS über vsftpd oder ProFTPD) neben SFTP wirklich nötig, oder reicht SFTP? | P6 |
| 7 | **Testserver**: gibt es Hardware/VM für Integrationsläufe und Lasttests, oder muss die CI das leisten? | P1 |
| 8 | **Datenschutz**: Auftragsverarbeitung, Aufbewahrungsfristen für Protokoll und Zugriffs-Logs, Löschkonzept für Kundendaten | P9 |
| 9 | **Passkeys** (§6.4) sind in P1 **nicht** gebaut worden. Gebaut sind TOTP und Wiederherstellungscodes. Passkeys brauchen WebAuthn — eine echte Abhängigkeit, einen Ablauf im Browser und eine eigene Verwaltung registrierter Schlüssel; das ist ein eigenes Stück Arbeit und nicht der Rest eines anderen. Sie bleiben geplant, aber als zweiter Weg **neben** TOTP, nicht als Ersatz | P2 |
| 11 | **Zwei Maßstäbe in der Oberfläche.** Die Grundgröße steht mit 13px am `body`, `rem` rechnet aber gegen das Wurzelelement — und das steht auf der Browservorgabe von 16px. Jeder `rem`-Wert ist damit 23 % größer als der Maßstab, den §7.2 festlegt, und in derselben Komponente stehen px-Werte (12,5px im Menü, 34px Zeilenhöhe) neben rem-Werten (13,6px). Gemessen an der Anmeldemaske: Überschrift 18,4px statt 16px, Feldhöhe 37px statt 34px. Dort ist es behoben (px), in **zehn weiteren Dateien mit 77 rem-Werten nicht**. Zu entscheiden ist, welche Einheit gilt — und die Umstellung gehört gemessen, nicht geschätzt | vor 1.0 |
| 10 | **Reste der Umbenennung auf englische Bezeichner** (Commit `22e8bc0`) tauchen weiter auf: `app.ts` suchte die Seiten im alten Verzeichnis (die ganze Oberfläche war unbenutzbar), `srvpanel-metrics.service` rief `artisan srvpanel:kennzahlen` auf, `srvpanel-worker.service` horchte auf die alte Warteschlange (kein Vorgang wäre je gelaufen), dazu tote CSS-Klassen. Gemeinsam ist allen: eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft. `InertiaPagesTest` und `PackagingTest` decken jetzt die drei gefundenen Sorten ab — **offen ist, ob es eine vierte gibt** | vor 1.0 |
