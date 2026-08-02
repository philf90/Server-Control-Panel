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

Diese sieben Punkte sind entschieden und der Rahmen für alles Weitere.

| Entscheidung | Gewählt | Folge |
|---|---|---|
| Bruch mit dem Bestand | vollständig, inklusive Technologiewechsel | Neue Codebasis, neues Repo-Layout, keine Übernahme von Go-Code |
| Stack | PHP 8.3+/Laravel, Vue 3 + Inertia, Vite, Tailwind | Schnelle Feature-Entwicklung, viel Vorbild im Hosting-Umfeld; dafür eine PHP-Laufzeit auf dem Host und eine strikt getrennte Rechteebene (§4) |
| Zielplattform | Debian 12/13, Ubuntu 22.04/24.04 | Eine Paketwelt (apt), ein Pfadschema, vier CI-Zweige |
| Isolation | ein Systembenutzer je Abonnement | Plesk-Modell: eigener PHP-FPM-Pool, `open_basedir`, Chroot-SFTP, Dateisystem-Quota |
| Rollenmodell | Admin → Kunde → Zusatzbenutzer | Keine Reseller-Ebene in der 1.0, aber im Modell vorbereitet (§5.4) |
| Funktionsumfang 1.0 | Web + PHP + Datenbanken, DNS autoritativ, FTP/SFTP, Cron, Backups | Mail ist **nicht** in der 1.0 |
| Mail | spätere Ausbaustufe | Datenmodell, DNS-Zonen, Backups und Rechte werden so geschnitten, dass Postfix/Dovecot ohne Umbau andocken (§5.5) |

**Arbeitsname.** Das Produkt heißt in diesem Dokument „das Panel", die
Systembezeichner lauten `panel` (Benutzer, Pfade, Unit-Namen). Der endgültige
Name ist offen (§12).

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
                      ├─▶ php-fpm Pool „panel"        (Benutzer panel, kein root)
                      │      Laravel: HTTP, Inertia, API v1, Policies
                      │
                      ├─▶ panel-worker (Benutzer panel)
                      │      Warteschlange, lang laufende Vorgänge
                      │
                      └──unix socket──▶ panel-agentd   (root, minimal)
                                          /run/panel/agent.sock  0660 root:panel
```

**`panel-agentd`** ist der einzige Prozess mit Systemrechten. Er ist ein
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
- **Aufruferprüfung.** `SO_PEERCRED`: nur die UID des Panel-Benutzers wird
  bedient.
- **Ausführung.** Absolute Pfade aus einer Programm-Positivliste, kein `$PATH`,
  keine Shell, Argumente als Array, feste Umgebung (`LC_ALL=C`), Zeitlimit je
  Operation, Ausgabe bei 4 MiB gekappt.
- **Doppeltes Protokoll.** Jeder Auftrag wird auf beiden Seiten protokolliert —
  in der Panel-Datenbank (mit Person) und in `/var/log/panel/agent.log` (mit
  ausgeführter Kommandozeile). Wer beides vergleicht, sieht, ob am Panel vorbei
  gearbeitet wurde.

**Was diese Trennung leistet und was nicht.** Sie verhindert, dass ein Fehler
in der Web-Anwendung — ein Pfad, der nicht geprüft wurde, eine Bibliothek mit
Lücke — unmittelbar root bedeutet, und sie hält die Menge des als root
laufenden Codes prüfbar klein. Sie ist **kein** Schutz gegen eine vollständig
übernommene Panel-Anwendung: Wer die Anwendung kontrolliert, kann gültige
Aufträge stellen. Das ist die Grenze, und sie wird in der Sicherheitsbetrachtung
so benannt, nicht kaschiert.

### 4.3 Die PHP-Frage

Das Panel braucht eine feste PHP-Version, die Kunden brauchen mehrere.
Distributionspakete liefern je Release genau eine (Debian 12: 8.2, Ubuntu
24.04: 8.3, Debian 13: 8.4). Deshalb:

- Das Panel bringt **seine eigene PHP-Version** mit, aus einer vom Projekt
  betriebenen apt-Quelle, installiert nach `/opt/panel/php/` und nur vom
  Panel benutzt. Es hängt nicht an der PHP-Version des Systems und ändert sie
  nicht.
- Für Kundenwebseiten werden **mehrere PHP-Versionen** als eigene Pakete
  angeboten (7.4 bis 8.4, je mit `-fpm`), installierbar aus dem Panel.
  Basis ist `deb.sury.org`; das Panel spiegelt die benötigten Pakete in der
  eigenen, signierten Quelle, damit die Lieferkette einen Betreiber hat und
  Versionen einfrierbar sind.

Das ist eine laufende Betriebslast (Spiegel pflegen, Sicherheitsupdates
nachziehen) und der Preis dafür, dass ein Hosting-Panel nicht an dem hängt, was
die Distribution gerade mitbringt.

### 4.4 Datenhaltung

| Ort | Inhalt |
|---|---|
| `/opt/panel/releases/<version>/` | Anwendung, `root:root`, für `panel` nur lesbar |
| `/opt/panel/current` | Symlink auf die aktive Version |
| `/etc/panel/panel.conf` | Konfiguration, Schlüsselmaterial (`root:panel 0640`) |
| `/var/lib/panel/` | Zustand, Zertifikate, Vorlagen-Overrides, Sicherungen |
| `/var/log/panel/` | `panel.log`, `agent.log`, `audit.log` (append-only, logrotate) |
| `/var/www/vhosts/<abo>/` | Abo-Wurzel = Home des Systembenutzers |
| MariaDB `panel` | Panel-Datenbank, eigener DB-Benutzer, nur über Socket |

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

## 8. Querschnittsregeln für jede Ausbaustufe

Eine Stufe ist **nicht fertig**, solange nicht alles davon steht:

1. Datenmodell samt Migration und Rückmigration
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
  mit `/opt/panel/releases/<version>` und Symlink-Umschaltung
- Installer `install.sh`: Vorbedingungen prüfen (Distribution, Speicher,
  Quota-fähiger Mount, belegte Ports), Pakete installieren, Panel-PHP,
  MariaDB, nginx, Datenbank anlegen, systemd-Units, Einmal-Link für die
  Ersteinrichtung
- Eigene apt-Quelle, signiert; Update aus dem Panel: prüfen, entpacken,
  migrieren, umschalten, Bereitschaftsprüfung, Rollback bei Fehlschlag
- CI: statische Prüfung (PHPStan max, Pint, ESLint, TS), Unit- und
  Feature-Tests, Integrationslauf in systemd-Containern für Debian 12/13 und
  Ubuntu 22.04/24.04
- Testumgebung: ein Skript, das eine Wegwerf-VM/Container aufsetzt und das
  gebaute Paket installiert

**Fertig, wenn** auf allen vier Zielplattformen aus dem Nichts installiert,
angemeldet, aktualisiert und zurückgerollt werden kann.

### P1 — Kern: Konten, Mandanten, Rechte, Vorgänge, Agent · 4–6 Wochen · (0.2)

- Anmeldung, 2FA, Passkeys, Wiederherstellung, Ratenbegrenzung, Sitzungen
- Accounts/Customers/Subscriptions/Plans als Modell — noch ohne Systemwirkung
- Policies, Mandantenklammer, mechanische Vollständigkeitsprüfung der Routen
- Protokoll mit Filter und Export
- Vorgangssystem: Warteschlange, Zustände, Live-Ausgabe über SSE
- **Agent-Protokoll und `panel-agentd`**, zunächst mit drei echten Operationen
  (Dienstzustand lesen, Datei prüfen, Reload) und einer vollständigen Attrappe
  für die Tests
- Oberflächengerüst: Navigation, Gestaltung, Bestätigungsstufen, Sprachen,
  Fehlerbilder, „Anmelden als"

**Fertig, wenn** ein Admin einen Kunden anlegt, dieser sich anmeldet, seine
(leere) Übersicht sieht, und kein Kunde durch Manipulation von IDs an fremde
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

- Statistik: Traffic je Domain, Zugriffe, Speicher, Datenbankgrößen, Verlauf
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

## 12. Offene Punkte

Diese Fragen blockieren P0 nicht, brauchen aber eine Antwort, bevor die
jeweilige Stufe beginnt:

| # | Frage | Spätestens vor |
|---|---|---|
| 1 | **Name und Marke** des Produkts; Domain, Repo-Name, Paketnamen, Systembezeichner | P0 |
| 2 | **Lizenz** — Apache-2.0 wie beim Vorgänger, oder für ein kundenfähiges Produkt etwas anderes (AGPL, Quelle offen mit kommerzieller Lizenz, geschlossen)? | P0 |
| 3 | **Wer betreibt die apt-Quelle und den PHP-Spiegel**, und mit welcher Zusage zu Sicherheitsupdates? | P0 |
| 4 | **Apache** zusätzlich unterstützen oder dauerhaft nur nginx? | P3 |
| 5 | **PostgreSQL** wirklich in der 1.0 oder nach hinten? | P5 |
| 6 | **FTP** (unverschlüsselt/FTPS über vsftpd oder ProFTPD) neben SFTP wirklich nötig, oder reicht SFTP? | P6 |
| 7 | **Testserver**: gibt es Hardware/VM für Integrationsläufe und Lasttests, oder muss die CI das leisten? | P1 |
| 8 | **Datenschutz**: Auftragsverarbeitung, Aufbewahrungsfristen für Protokoll und Zugriffs-Logs, Löschkonzept für Kundendaten | P9 |
