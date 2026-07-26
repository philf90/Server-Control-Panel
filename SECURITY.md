# Sicherheit

Project Asylum verwaltet einen Server mit root-Rechten. Ein Fehler hier ist kein
Anzeigefehler, sondern ein übernommener Server. Meldungen zu Schwachstellen sind
deshalb ausdrücklich willkommen.

## Eine Schwachstelle melden

**Bitte kein öffentliches Issue.** Nutzen Sie stattdessen
[GitHub Security Advisories](https://github.com/philf90/Server-Control-Panel/security/advisories/new)
— das ist ein privater Kanal zwischen Ihnen und den Betreuern.

Hilfreich für eine Meldung:

- betroffene Fassung (`asylum version`) und Distribution
- was ein Angreifer damit erreichen kann, und was er dafür vorher braucht
  (unangemeldet? angemeldet mit welcher Rolle? Zugriff auf das Netz dazwischen?)
- eine Schrittfolge zum Nachvollziehen, gern als Skript

Sie bekommen binnen **72 Stunden** eine Eingangsbestätigung und binnen **7 Tagen**
eine erste Einschätzung. Dieses Projekt wird nebenberuflich betreut; ein
Rund-um-die-Uhr-Bereitschaftsdienst wäre eine Zusage, die niemand einlöst.

## Offenlegung

Wir halten es mit **koordinierter Offenlegung**: Nach der Korrektur wird ein
Advisory mit CVE veröffentlicht, in dem Sie namentlich genannt werden, wenn Sie
das möchten. Als Frist gelten 90 Tage ab Eingang — wenn wir bis dahin nichts
geliefert haben, veröffentlichen Sie bitte. Eine Frist, die der Hersteller
beliebig verlängern kann, ist keine.

Ein Bug-Bounty-Programm gibt es nicht. Das Projekt hat kein Budget dafür, und
eine Belohnung anzukündigen, die dann nicht kommt, wäre schlechter als gar keine.

## Unterstützte Fassungen

| Fassung | Sicherheitsupdates |
|---|---|
| aktuelle Minor-Version | ja |
| deren Vorgänger | ja |
| ältere | nein |

Vor v1.0 heißt das in der Praxis: aktualisieren Sie. Die Updatefunktion ist
genau dafür gebaut ([docs/05-updates.md](docs/05-updates.md)).

## Was in den Geltungsbereich fällt

Der Code in diesem Repository, die Release-Artefakte und der Installer.
Insbesondere interessant:

- **Anmeldung und Sitzungen** — Passwort, zweiter Faktor,
  Wiederherstellungscodes, Sitzungsverwaltung, CSRF
- **Rollentrennung** — jede Stelle, an der eine Rolle mehr darf, als
  [docs/03-funktionsumfang.md](docs/03-funktionsumfang.md) beschreibt
- **`internal/privops`** — die einzige Stelle mit Systemzugriff. Alles, was
  dort ein Argument einschleust oder die Allowlist umgeht, ist ein Fund
- **Der Updateweg** — Signaturprüfung, Metadaten, Austausch des Binaries
- **Der Installer** — er läuft als root aus einer Pipe

## Was nicht in den Geltungsbereich fällt

- Ein Panel, das absichtlich unverschlüsselt oder ohne zweiten Faktor betrieben
  wird. Beides lässt sich nicht abschalten, aber wer die Konfiguration von Hand
  verbiegt, hat den Schutz selbst entfernt.
- Das selbstsignierte Zertifikat beim ersten Start. Das ist bekannt und
  beabsichtigt; der Fingerprint wird beim Setup ausgegeben, damit er sich prüfen
  lässt.
- Angriffe, die bereits root auf dem Server voraussetzen. Wer root hat, braucht
  keine Lücke im Panel.
- Ergebnisse automatischer Scanner ohne nachvollziehbaren Angriffsweg.

## Wie das Projekt sich selbst absichert

Damit klar ist, wogegen Sie antreten — und wo es sich lohnt, genauer
hinzusehen:

| Bereich | Umsetzung |
|---|---|
| Passwörter | Argon2id (m=32 MiB, t=3, p=2), serialisiert |
| Zweiter Faktor | TOTP, beim ersten Login erzwungen, nicht abschaltbar |
| Sitzungen | serverseitig in SQLite, nur der Hash des Cookies wird gespeichert |
| CSRF | Double-Submit-Token bei jedem verändernden Aufruf |
| Rollen | serverseitig geprüft, nicht nur im Menü ausgeblendet |
| Systemzugriff | ausschließlich über `privops`, Allowlist mit absoluten Pfaden, niemals eine Shell |
| Updates | minisign-Signatur gegen einen im Binary eingebauten Schlüssel |
| Audit | jede verändernde Aktion mit Konto, IP, Ziel, Ergebnis |

Details und die Begründungen dahinter:
[docs/02-architektur.md](docs/02-architektur.md) und
[docs/09-sicherheitsbetrachtung.md](docs/09-sicherheitsbetrachtung.md).

## Stand der Prüfung

**Ein externer Sicherheitsreview hat bisher nicht stattgefunden.** Was es gibt,
ist eine Selbstbetrachtung der Anmelde- und Updatepfade
([docs/09-sicherheitsbetrachtung.md](docs/09-sicherheitsbetrachtung.md)) — vom
selben Kreis geschrieben, der den Code geschrieben hat, und damit kein Ersatz
für fremde Augen. Wer das Panel in einer Umgebung einsetzt, in der ein
übernommener Server teuer wäre, sollte das bei der Entscheidung berücksichtigen.
