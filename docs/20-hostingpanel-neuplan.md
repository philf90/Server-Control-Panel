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
| Rollenmodell | Admin → Kunde → Zusatzbenutzer | Keine Reseller-Ebene in der 1.0, aber im Modell vorbereitet (§5.4) |
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

### 7.2 Gestaltungssystem „Leitstand"

Entschieden aus drei Vorschlägen; die verworfenen sind mitsamt ihren Vor- und
Nachteilen in [entwuerfe/20-stilvorschlaege.html](entwuerfe/20-stilvorschlaege.html)
festgehalten und nicht gelöscht — eine Entscheidung ohne ihre Alternativen ist
in einem halben Jahr nicht mehr nachvollziehbar.

**Die Richtung in einem Satz:** dunkel, dicht, instrumentenhaft — Zahlen stehen
in Monospace und damit spaltengenau untereinander, und Farbe bedeutet etwas
oder wird nicht benutzt.

#### Marken (Token)

| Rolle | Marke | Wert |
|---|---|---|
| Grund | `--bg` | `#0B0F13` |
| Bereich | `--surface`, `--surface-border` | `#111922`, Rand `#1C2733` |
| Navigation | `--nav-bg`, `--nav-border` | `#080C10`, Rand `#1A232D` |
| Text | `--text-strong`, `--text`, `--text-muted`, `--text-faint` | `#EAF1F8` … `#7B8C9B` |
| Akzent | `--accent`, `--accent-on`, `--accent-surface` | `#E0A340` (Amber) — Signal, Zustand, aktive Navigation, primäre Aktion |
| Zustände | `--ok`, `--warn`, `--critical` (je mit `-surface`) | `#5CC294` · `#E0A340` · `#E87761` |
| Radius | — | 3 px. Kein größerer Wert, nirgends |
| Trennung | `--line` | 1 px Rand, keine Schatten |

Umgeschaltet wird über `data-theme` (`dark`/`light`) und `data-density`
(`admin`/`customer`) am Wurzelelement. Die CI prüft, dass außerhalb von
`resources/css/app.css` kein Farbwert steht.

Die Zustandsfarben sind vom Akzent getrennt zu denken, auch wo sie denselben
Farbwert haben: Amber als Warnung heißt etwas anderes als Amber am aktiven
Menüpunkt. Wo beides in einer Zeile stünde, gewinnt der Zustand.

#### Schrift

- **Ziffern, Kennungen, Pfade, Zeitstempel: Monospace.** Das ist keine Zierde —
  es ist der Grund, warum sich Werte in einer Tabelle vergleichen lassen, ohne
  sie zu lesen. Überall `font-variant-numeric: tabular-nums`.
- **Fließtext, Beschriftungen, Überschriften: Grotesk** (System-Stack, keine
  nachgeladene Schrift — eine Schrift, die nicht ankommt, ist eine Oberfläche,
  die anders aussieht als geplant).
- Kleine Beschriftungen in Versalien mit Sperrung (`.09em`), sonst keine
  Versalien.

**Fünf Größen, und keine sechste.** Sie stehen als Marken in
`resources/css/app.css` und sonst nirgends — dieselbe Regel wie für Farben:

| Marke | Wert | Rolle |
|---|---|---|
| `--text-label` | 10,5 px | Versalien-Beschriftungen: Spaltenköpfe, Kachel-Beschriftung |
| `--text-small` | 11 px | Feldbeschriftungen, Hinweise, Fehlertexte, kleine Knöpfe |
| `--text-table` | 12 px | Tabellenzellen und Fließtext in Listen |
| `--text-body` | 13 px | Eingaben, Knöpfe, Fließtext |
| `--text-heading` | 16 px | Seitenüberschrift |
| `--text-metric` | 22 px | die große Zahl auf einer Kachel |

**In px und nicht in rem.** `rem` rechnet gegen das Wurzelelement, und das steht
auf der Browservorgabe von 16 px — die Grundgröße des Panels sind aber 13 px am
`body`. In den Komponenten standen zehn rem-Werte für fünf Rollen, jeder davon
23 % größer als gemeint: `.85rem` für Tabellentext ergab 13,6 px und war damit
*größer* als der Fließtext, den er unterschreiten sollte. Aufgefallen ist es an
der Anmeldemaske, deren Überschrift größer war als die Seitenüberschrift im
angemeldeten Panel.

**Nicht nach Dichte gestaffelt.** Die Dichtetabelle unten staffelt Zeilenhöhe,
Abstände und Kacheln je Reihe. Schriftgrößen nicht: Die Kundenfläche wird
ruhiger durch Luft, nicht durch größere Schrift.

Geprüft von `tests/Feature/DesignTokensTest.php` — kein `rem` in einer
Komponente, keine Schriftgröße außerhalb der Skala, keine Marke ohne Wert.

#### Dichte in zwei Stufen

Die Kritik am dunkelen, dichten Zuschnitt trifft die Kundenfläche, nicht die
Adminfläche. Sie wird nicht durch eine zweite Gestaltung aufgefangen, sondern
durch eine zweite Dichtestufe im selben System:

| | Adminfläche | Kundenfläche |
|---|---|---|
| Zeilenhöhe Tabelle | 34 px | 42 px |
| Abstand der Bereiche | 10 px | 16 px |
| Erklärsatz unter der Überschrift | nur wo nötig | immer |
| Kacheln je Reihe | 4 | 3 |

Gleiche Marken, gleiche Bausteine, gleicher Code — ein Attribut am
Wurzelelement schaltet um.

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
- Gestaltungssystem „Leitstand" (§7.2) in beiden Dichtestufen und beiden Themes
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

- MariaDB: Datenbanken und Benutzer je Abo, Namenspräfix, Rechte begrenzt
- Zugriff nur lokal, optional Fernzugriff je Benutzer mit IP-Beschränkung
- Kontingente: Anzahl, Größe (gemessen)
- Import/Export über die Oberfläche, mit Größenbegrenzung und als Vorgang
- Adminer als eingebettetes Werkzeug, Anmeldung ohne Passwortweitergabe
- PostgreSQL im selben Zuschnitt, als zweiter Schritt der Stufe

**Fertig, wenn** ein Kunde eine Datenbank anlegt, benutzt, sichert und
zurückspielt, und ein Datenbankbenutzer nachweislich keine fremde Datenbank
sieht.

### P6 — Dateien, Zugänge, Cron · 3–4 Wochen · (0.7)

- Dateimanager: Baum, Editor mit Syntaxhervorhebung, Hochladen, Entpacken,
  Rechte, Suche — alles hart auf die Abo-Wurzel begrenzt (Prüfung nach
  Auflösung von Symlinks)
- SFTP mit Chroot je Abo, Schlüsselverwaltung
- Zusätzliche FTP-Konten mit eigenem Startverzeichnis
- Cronjobs je Abo: laufen als Systembenutzer des Abos, Ausgabe wird
  aufgezeichnet, Zeitplan mit lesbarer Übersetzung, Ausführungsverlauf
- SSH-Zugang je Abo optional freischaltbar (Plan-gesteuert, standardmäßig aus)

**Fertig, wenn** der Angriffsdurchgang für Pfadausbruch, Symlink-Tricks und
Cron-Befehlseinschleusung durchläuft.

### P7 — DNS · 3–4 Wochen · (0.8)

- PowerDNS autoritativ, angesteuert über die HTTP-API (nicht über die
  Datenbank)
- Zonenvorlage mit Platzhaltern; beim Anlegen einer Domain entsteht die Zone
  automatisch
- Einträge: A, AAAA, CNAME, MX, TXT, SRV, CAA, NS, PTR-Hinweis; Prüfung vor
  dem Übernehmen
- DNSSEC ein-/ausschaltbar, Schlüsselwechsel, DS-Angaben zum Weitergeben
- AXFR an Slave-Server, Benachrichtigungen
- Betriebsart „externer DNS": das Panel verwaltet nichts, zeigt aber die
  nötigen Einträge zum Abgleich
- Kundensicht: Einträge bearbeiten, im Rahmen des Plans

**Fertig, wenn** eine neu angelegte Domain ohne weiteres Zutun auflösbar ist,
ein Zonenfehler nicht übernommen wird und DNSSEC nachweislich validiert.

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
  Webhook)
- Benachrichtigungen an Kunden: Kontingent erreicht, Zertifikat läuft ab,
  Sicherung fehlgeschlagen
- Branding: Logo, Farben, Fußzeile, Absenderadresse, eigene Panel-Domain
- Serververwaltung für den Admin: Dienste, Pakete und Systemupdates,
  Firewall (nftables), Fail2ban-Jails, Logs
- API v1 mit OpenAPI-Beschreibung und Tokens; darüber Abonnement anlegen,
  ändern, sperren, löschen
- Dokumentation: Betreiberhandbuch, Kundenhilfe in der Oberfläche

**Fertig, wenn** ein fremder Kunde das Panel benutzen kann, ohne zu fragen —
gemessen an einem Durchlauf mit einer Person, die das Projekt nicht kennt.

### P10 — Härtung und Freigabe · 3–4 Wochen · (1.0)

- Vollständiger Angriffsdurchgang über alle Module, dokumentiert
- Lasttest: 200 Abonnements, 1000 Domains — Antwortzeiten, Speicher,
  Reload-Dauer von nginx, Größe der Konfiguration
- Externer Sicherheits-Review, Befunde abgearbeitet
- Upgrade-Pfad zwischen allen 0.x-Fassungen geprüft
- Sicherheitsbetrachtung als eigenes Dokument, mit den benannten Grenzen
  (§4.2)
- Freigabe 1.0

**Summe: 32–44 Wochen** bis 1.0. Die Zahl ist ehrlich gemeint, nicht
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
| 3 | **Wie lange wird `deb.sury.org` als PHP-Bezug getragen**, und ab welcher Kundenzahl kommt der eigene Spiegel auf Objektspeicher (§4.3)? Seit `srvpanel-php-source` gibt es dafür genau eine Stelle: das Paket trägt die Quelle ein, ein Wechsel wäre eine neue Fassung davon | P3 |
| 3a | **Den Schlüssel von sury im Paket mitliefern statt beim Einrichten zu holen?** `srvpanel-php-source` lädt ihn heute im `postinst` über das Netz — das braucht Netz zur Installationszeit und ist nicht reproduzierbar. Ihn einzubetten wäre besser, bindet aber einen fremden Schlüssel an unsere Fassung: Rotiert sury, ist jede ausgelieferte Fassung falsch, bis eine neue erscheint. Dieselbe Abwägung wie Punkt 2a, nur mit einem Schlüssel, der uns nicht gehört | vor 1.0 |
| 4 | **Apache** zusätzlich unterstützen oder dauerhaft nur nginx? | P3 |
| 5 | **PostgreSQL** wirklich in der 1.0 oder nach hinten? | P5 |
| 6 | **FTP** (unverschlüsselt/FTPS über vsftpd oder ProFTPD) neben SFTP wirklich nötig, oder reicht SFTP? | P6 |
| 7 | **Testserver**: gibt es Hardware/VM für Integrationsläufe und Lasttests, oder muss die CI das leisten? | P1 |
| 8 | **Datenschutz**: Auftragsverarbeitung, Aufbewahrungsfristen für Protokoll und Zugriffs-Logs, Löschkonzept für Kundendaten | P9 |
| 9 | **Passkeys** (§6.4) sind in P1 **nicht** gebaut worden. Gebaut sind TOTP und Wiederherstellungscodes. Passkeys brauchen WebAuthn — eine echte Abhängigkeit, einen Ablauf im Browser und eine eigene Verwaltung registrierter Schlüssel; das ist ein eigenes Stück Arbeit und nicht der Rest eines anderen. Sie bleiben geplant, aber als zweiter Weg **neben** TOTP, nicht als Ersatz | P2 |
| 11 | **Zwei Maßstäbe in der Oberfläche.** Die Grundgröße steht mit 13px am `body`, `rem` rechnet aber gegen das Wurzelelement — und das steht auf der Browservorgabe von 16px. Jeder `rem`-Wert ist damit 23 % größer als der Maßstab, den §7.2 festlegt, und in derselben Komponente stehen px-Werte (12,5px im Menü, 34px Zeilenhöhe) neben rem-Werten (13,6px). Gemessen an der Anmeldemaske: Überschrift 18,4px statt 16px, Feldhöhe 37px statt 34px. Dort ist es behoben (px), in **zehn weiteren Dateien mit 77 rem-Werten nicht**. Zu entscheiden ist, welche Einheit gilt — und die Umstellung gehört gemessen, nicht geschätzt | vor 1.0 |
| 10 | **Reste der Umbenennung auf englische Bezeichner** (Commit `22e8bc0`) tauchen weiter auf: `app.ts` suchte die Seiten im alten Verzeichnis (die ganze Oberfläche war unbenutzbar), `srvpanel-metrics.service` rief `artisan srvpanel:kennzahlen` auf, `srvpanel-worker.service` horchte auf die alte Warteschlange (kein Vorgang wäre je gelaufen), dazu tote CSS-Klassen. Gemeinsam ist allen: eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft. `InertiaPagesTest` und `PackagingTest` decken jetzt die drei gefundenen Sorten ab — **offen ist, ob es eine vierte gibt** | vor 1.0 |
