# 03 — Funktionsumfang

> **Teilweise überholt (Stand 0.4.1).** Der Abschnitt **v0.1** beschreibt den
> gebauten Bestand und gilt. Die Abschnitte **v0.2** und **v0.3** sind durch
> [16-neukonzeption.md](16-neukonzeption.md) §5 (Stufen 0.4 bis 1.0) und §6
> (bewusst Zurückgestelltes) ersetzt; sie stehen hier nur noch als Herkunft der
> Entscheidungen. Auch die Scope-Wahl unten und zwei Einträge der
> Nicht-Ziel-Liste sind revidiert — Einzelheiten jeweils an Ort und Stelle.

## Scope-Entscheidung zuerst

"Control Panel" bedeutet je nach Publikum zwei sehr verschiedene Produkte:

| | A: Server-Administration | B: Hosting-Panel |
|---|---|---|
| Zielgruppe | Entwickler, Selfhoster, kleine Teams | Webhoster, Agenturen, Endkunden |
| Umfang | Dienste, Pakete, Firewall, Nutzer, Logs | zusätzlich vHosts, PHP-FPM, MySQL, Mail, DNS, Kundenverwaltung |
| Wettbewerb | Cockpit, Webmin | Plesk, cPanel, CloudPanel, HestiaCP, CyberPanel |
| Aufwand MVP | ~4–8 Wochen | ~6–12 Monate |
| Risiko | überschaubar | Mail und DNS sind je ein eigenes Produkt |

**Empfehlung: mit A starten**, Modul-Schnittstelle von Anfang an vorsehen und
Hosting-Funktionen (B) später als optionale Module nachziehen. Der Anspruch
"schlank und ressourcenschonend" ist mit B im Kern nicht vereinbar — und der
Mailserver-Teil von B ist erfahrungsgemäß die Quelle von 80 % des Supportaufwands.

Die folgenden Stufen gehen von dieser Empfehlung aus.

**Revidiert mit der Neukonzeption: der Scope ist jetzt A+.** Das Panel bleibt
Serververwaltung, nimmt aber die Betriebsthemen dazu, für die heute doch wieder
SSH nötig ist — Container, Webserver mit Domains und Zertifikaten, Datenbanken,
Zeitpläne, Backups. Nicht als Hosting-Panel mit Kunden und Mail, sondern als
Werkzeug für den einen eigenen Server, auf dem Anwendungen laufen. Mit der
Erweiterung fiel auch „schlank" als **harte** Vorgabe: Die Grenzen für
Binärgröße und Grundlast bleiben als Messwerte, entscheiden aber nicht mehr
gegen eine Funktion. Begründung in
[16-neukonzeption.md](16-neukonzeption.md) §1 und §2.

---

## v0.1 — MVP ("das Panel ersetzt meine häufigsten SSH-Handgriffe")

Alles, was hier steht, ist bewusst nur das, was ein Admin auf einem frischen VPS in
der ersten Stunde tut.

### Setup & Auth
- Geführtes Erst-Setup: Admin-Account, TOTP-2FA, Hostname, Zeitzone.
- Login mit Argon2id + TOTP, serverseitige Sessions, Rate-Limiting.
- Rollen: Owner, Admin, ReadOnly.
- Panel-Zugang anlegen: nur Anmeldename und Rolle. Das Startpasswort erzeugt das
  Panel, zeigt es genau einmal und verlangt bei der ersten Anmeldung den Wechsel.
- Passwortrichtlinie: mindestens 12 Zeichen, nicht der Anmeldename, keine bloße
  Wiederholung oder Zeichenfolge — keine Vorschriften zu Zeichenklassen. Wo ein
  neues Passwort gewählt wird, stehen die Bedingungen mit Haken oder Kreuz
  daneben, dazu eine Stärkeschätzung.
- `asylum reset-password` als lokaler Rettungsanker über SSH.
- Zugang zurücksetzen im Panel: Einmalpasswort mit Wechselzwang, zweiter Faktor
  und Passkeys — durch den Owner; ein vergessenes Passwort per Passkey durch den
  Inhaber selbst. Kein Mailversand, siehe
  [12-zugang-zuruecksetzen.md](12-zugang-zuruecksetzen.md).

### Dashboard
- CPU (gesamt + je Kern), RAM/Swap, Load, Uptime, Kernel- und Distributionsversion.
- Verlauf der letzten 24 Stunden je Kachel; per Mouseover Wert und Uhrzeit der
  einzelnen Messung.
- Belegung aller gemounteten Dateisysteme, Inode-Warnung. Ein Dateisystem, das an
  mehreren Stellen hängt, ist ein Eintrag zum Aufklappen — die weiteren Stellen
  stehen darunter.
- Netzwerk-Durchsatz je Interface, IPv4/IPv6-Adressen. Die Kachel zeigt die
  Schnittstelle mit der Standardroute, nicht eine Docker-Brücke.
- Top-Prozesse nach CPU und RAM.
- Live-Aktualisierung über SSE (1–2 s Intervall, nur bei geöffnetem Tab).

### Dienste (systemd)
- Units auflisten, filtern, Status inkl. letzter Log-Zeilen.
- Start / Stop / Restart / Reload / Enable / Disable.
- Failed Units prominent auf dem Dashboard.

### Pakete & Updates (apt)
- Verfügbare Updates anzeigen, Sicherheitsupdates markiert.
- Einzeln oder gesamt einspielen, Live-Ausgabe im Browser.
- Paketlisten aktualisieren mit dem Auszug von `apt-get update`: welche Quelle
  geantwortet hat, welche nicht und warum. Klemmt eine Quelle und laufen die
  übrigen durch, ist das eine Warnung mit Nennung der Quelle — kein Fehlschlag.
- Reboot-Required-Hinweis (`/var/run/reboot-required`).
- Unattended-Upgrades ein-/ausschalten und Konfiguration anzeigen.

### Firewall
- `ufw` wird verwaltet, wo es aktiv ist — es ist auf Ubuntu und Debian der
  Regelfall und selbst ein Frontend für nftables.
- Ein eigenes nftables-Regelwerk wird **angezeigt, aber nicht verändert.**
  Ein automatischer Eingriff in fremde Ketten kann bestehende Regeln unwirksam
  machen oder die laufende SSH-Sitzung kappen. Ein Panel, das den Server
  aussperrt, hat schlimmer versagt als eines, das eine Funktion nicht anbietet.
- Regeln für eingehende Ports (Port, Protokoll, Quelle, Kommentar).
- Presets: SSH, HTTP/HTTPS, Panel-Port.
- **Lockout-Schutz:** Regeländerungen greifen zunächst probeweise; ohne Bestätigung
  innerhalb von 60 Sekunden wird automatisch zurückgerollt.

### Benutzer & SSH
- Systembenutzer anlegen/sperren/löschen, Shell und Gruppen setzen.
- `authorized_keys` verwalten (Key hinzufügen, entfernen, Fingerprint anzeigen).
- SSH-Härtung per Klick: PasswordAuthentication aus, PermitRootLogin aus, Port
  ändern — jeweils mit `sshd -t`-Validierung und derselben 60-Sekunden-Bestätigung.

### Logs
- journald-Ansicht mit Filter nach Unit, Priorität, Zeitraum, Freitext.
- Live-Follow, Download des gefilterten Ausschnitts.

### Betrieb des Panels selbst
- Audit-Log-Ansicht.
- Panel-Einstellungen: Port, Bind-Adresse, TLS (self-signed / ACME).
- Update-Status und Update-Auslösung (siehe [05-updates.md](05-updates.md)).

---

## v0.2 — Alltagstauglichkeit *(überholt)*

*Diese Stufe gibt es als Stufe nicht mehr. Zwei ihrer Punkte sind gebaut, die
übrigen drei neu eingeordnet:*

| Punkt | Wo er gelandet ist |
|---|---|
| Dateimanager | gebaut in **0.3.0** |
| Cron & systemd-Timer | gebaut in **0.4.0** |
| Web-Terminal | **hinter 1.0** verschoben ([16](16-neukonzeption.md) §6) |
| Benachrichtigungen | **zurückgestellt** ([16](16-neukonzeption.md) §6) |
| Storage-Details (SMART, Swap) | **nicht eingeplant**; die Dateisystem-Anzeige der Lage deckt den Alltagsfall ab |

- **Dateimanager — umgesetzt in 0.3.0.** Browsen, Umbenennen, Rechte/Owner,
  Upload/Download, Editor mit Syntax-Highlighting für Configs. Dazu Anlegen,
  Verschieben, Kopieren, Löschen mit gezählter Rückfrage, Namenssuche und
  Verzeichnis-Download als `tar.gz`. Bewusst schlicht — **keine
  Archiv-Extraktion** (Zip-Slip, Speicher, Rechte; `tar -x` über SSH ist der
  bessere Weg), kein Cloud-Sync, kein Papierkorb. Manche Pfade sind für das
  Panel tabu, auch für die Rolle Owner: Passwort-Hashes, private Schlüssel, die
  eigene Datenbank. Einzelheiten in [13-dateimanager.md](13-dateimanager.md).
- **Cron & systemd-Timer — umgesetzt in 0.4.0 (neue Oberfläche).** Anzeigen,
  anlegen, bearbeiten, abschalten, löschen; Zeitplan zusätzlich in Worten. Timer
  nur lesend, mit dem Ergebnis des letzten Laufs; geschaltet werden sie über die
  Dienste, denn ein Timer ist eine Unit. Verwaltete Einträge sind eigene Dateien
  unter `/etc/cron.d/` mit Marker — fremde Crontabs werden gelesen und nie
  geschrieben. Das **Anlegen eines Timers** fehlt bewusst: Eine `.service`-Datei
  trägt `ExecStart` samt der Härtungsoptionen von systemd, und ein halbes Formular
  dafür sähe aus, als könnte man damit alles einstellen. Die
  Sicherheitsbetrachtung — ein Cron-Eintrag *ist* eine Shell-Zeile — steht in
  [16-neukonzeption.md](16-neukonzeption.md) unter 4.2 und 7.2.
- **Web-Terminal:** PTY über WebSocket. Nur für Owner-Rolle, per Default
  deaktiviert, jede Sitzung im Audit-Log. Sehr nützlich und gleichzeitig die
  gefährlichste Funktion des Panels — deshalb explizit opt-in.
- **Benachrichtigungen:** E-Mail/Webhook/ntfy bei Failed Units, Speicherplatz-,
  Zertifikats- oder Reboot-Warnungen.
- **Storage-Details:** SMART-Status, Mounts, Swap-Verwaltung.

---

## v0.3 — Module *(überholt)*

*Der Modulgedanke — „Kern bleibt klein, Funktionen kommen als abschaltbare
Module" — trägt weiter, aber die Liste ist neu geschnitten und auf eigene
Fassungen verteilt. Maßgeblich ist [16-neukonzeption.md](16-neukonzeption.md)
§5 und §6:*

| Punkt von damals | Jetzt |
|---|---|
| Docker | **Stufe 0.5**, Stacks als führendes Objekt; Podman bleibt zurückgestellt — erst eine Laufzeit richtig |
| Reverse Proxy | **Stufe 0.6** als „Webserver & Domains", Sites statt Konfigurationsdateien |
| Backups | **Stufe 0.8** (restic; borg entfällt), Restore-Test als Kern des Moduls |
| WireGuard | **zurückgestellt** — nützlich, aber unabhängig von allem anderen |
| Multi-Server | **zurückgestellt** — braucht die Prozesstrennung und ein Trust-Modell |

*Neu hinzugekommen und in der alten Liste nicht enthalten:*
**Datenbanken** (Stufe 0.7) sowie das Fundament aus **0.4** — neue Oberfläche,
`/api/v1`, Job-Modell, Cron & Timer, API-Tokens.

Die ursprüngliche Liste, zur Herkunft:

- **Docker/Podman:** Container, Images, Volumes, Compose-Stacks, Logs.
- **Reverse Proxy:** Caddy oder nginx, vHost anlegen mit automatischem TLS —
  der Einstieg in Hosting-Funktionen mit dem besten Aufwand-Nutzen-Verhältnis.
- **Backups:** restic/borg-Integration, Ziele, Zeitpläne, Restore-Test.
- **WireGuard:** Peers verwalten, Konfiguration und QR-Code ausgeben.
- **Multi-Server:** ein Panel, mehrere Agents (erfordert die Prozesstrennung aus
  [02-architektur.md](02-architektur.md)).

---

## Bewusste Nicht-Ziele

Diese Liste ist genauso wichtig wie die Feature-Liste.

**Zwei Einträge sind gefallen** ([16-neukonzeption.md](16-neukonzeption.md) §2):

| Bisher Nicht-Ziel | Jetzt | Begründung |
|---|---|---|
| vHosts / Reverse Proxy | **Ziel (0.6)** | Der häufigste Handgriff nach dem Aufsetzen eines Dienstes ist „mach ihn unter einem Namen mit TLS erreichbar". Das ACME-Modul existiert bereits. |
| Datenbanken (MySQL/PostgreSQL) | **Ziel (0.7)** | Jede zweite Anwendung braucht eine. Datenbank und Benutzer anlegen ist typisierbar und klein — verwaltet wird die Instanz, nicht der Inhalt. |

**Der Rest gilt weiter, und mit größerem Gewicht** — seit „schlank" nicht mehr
gegen Funktionen entscheidet, ist diese Liste die einzige Bremse gegen den Weg
zum Hosting-Panel:

- **Kein eigener Mailserver-Stack** (Postfix/Dovecot/Rspamd/DKIM/DMARC-Verwaltung).
  Zu groß, zu supportintensiv, zu risikoreich.
- **Kein DNS-Autoritativserver.**
- **Keine Kunden-/Reseller-/Abrechnungsverwaltung.**
- **Kein eigenes Paketformat** und keine eigenen Software-Stacks parallel zu apt.
- **Kein Ersatz für Konfigurationsmanagement** (Ansible, NixOS). Wer flottenweit
  deklarativ arbeitet, ist dort besser aufgehoben.
- **Keine Windows-Unterstützung.**

## Abgrenzung zum Wettbewerb

| Produkt | Positionierung | Lücke, die wir füllen |
|---|---|---|
| Cockpit | Solide, aber Red-Hat-zentriert, Python/JS-Stack, träges UI auf kleinen VPS | Schlanker, ein Binary, Debian/Ubuntu-first |
| Webmin | Riesiger Funktionsumfang, Perl, gealtertes UI | Fokussiert, modern, sicherer Default |
| CloudPanel / HestiaCP | Hosting-Fokus, schreibt Systemkonfiguration weitreichend um | Nicht-besitzergreifend, Server bleibt "normal" |
| Portainer | Nur Container | Der Server selbst, Container optional |

Mit dem Scope A+ verschiebt sich der Vergleichspunkt: Er ist nicht mehr nur
Cockpit und Webmin, sondern auch **CloudPanel und Coolify**. Der Unterschied
bleibt derselbe wie oben — Asylum bleibt nicht-besitzergreifend und stellt
keinen eigenen Stack neben das System; auch Webserver und Datenbank kommen aus
den Distributionsquellen.
