# 03 — Funktionsumfang

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

---

## v0.1 — MVP ("das Panel ersetzt meine häufigsten SSH-Handgriffe")

Alles, was hier steht, ist bewusst nur das, was ein Admin auf einem frischen VPS in
der ersten Stunde tut.

### Setup & Auth
- Geführtes Erst-Setup: Admin-Account, TOTP-2FA, Hostname, Zeitzone.
- Login mit Argon2id + TOTP, serverseitige Sessions, Rate-Limiting.
- Rollen: Owner, Admin, ReadOnly.
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

## v0.2 — Alltagstauglichkeit

- **Dateimanager — umgesetzt in 0.3.0.** Browsen, Umbenennen, Rechte/Owner,
  Upload/Download, Editor mit Syntax-Highlighting für Configs. Dazu Anlegen,
  Verschieben, Kopieren, Löschen mit gezählter Rückfrage, Namenssuche und
  Verzeichnis-Download als `tar.gz`. Bewusst schlicht — **keine
  Archiv-Extraktion** (Zip-Slip, Speicher, Rechte; `tar -x` über SSH ist der
  bessere Weg), kein Cloud-Sync, kein Papierkorb. Manche Pfade sind für das
  Panel tabu, auch für die Rolle Owner: Passwort-Hashes, private Schlüssel, die
  eigene Datenbank. Einzelheiten in [13-dateimanager.md](13-dateimanager.md).
- **Cron & systemd-Timer:** Anzeigen, anlegen, bearbeiten, letzte Ausführung mit
  Exit-Code und Ausgabe.
- **Web-Terminal:** PTY über WebSocket. Nur für Owner-Rolle, per Default
  deaktiviert, jede Sitzung im Audit-Log. Sehr nützlich und gleichzeitig die
  gefährlichste Funktion des Panels — deshalb explizit opt-in.
- **Benachrichtigungen:** E-Mail/Webhook/ntfy bei Failed Units, Speicherplatz-,
  Zertifikats- oder Reboot-Warnungen.
- **Storage-Details:** SMART-Status, Mounts, Swap-Verwaltung.

---

## v0.3 — Module

Ab hier gilt: Kern bleibt klein, Funktionen kommen als abschaltbare Module.

- **Docker/Podman:** Container, Images, Volumes, Compose-Stacks, Logs.
- **Reverse Proxy:** Caddy oder nginx, vHost anlegen mit automatischem TLS —
  der Einstieg in Hosting-Funktionen mit dem besten Aufwand-Nutzen-Verhältnis.
- **Backups:** restic/borg-Integration, Ziele, Zeitpläne, Restore-Test.
- **WireGuard:** Peers verwalten, Konfiguration und QR-Code ausgeben.
- **Multi-Server:** ein Panel, mehrere Agents (erfordert die Prozesstrennung aus
  [02-architektur.md](02-architektur.md)).

---

## Bewusste Nicht-Ziele

Diese Liste ist genauso wichtig wie die Feature-Liste:

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
