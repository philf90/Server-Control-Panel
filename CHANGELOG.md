# Changelog

Alle nennenswerten Änderungen. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung nach
[SemVer](https://semver.org/lang/de/).

Die Einträge unter **Unveröffentlicht** stehen im Repository, sind aber noch
nicht als Release getaggt.

## [Unveröffentlicht]

### Sicherheit

- **TOTP-Codes gelten nur noch einmal.** Bis hierher war die Prüfung
  zustandslos: Ein Code blieb sein ganzes Zeitfenster über gültig — bei einer
  Toleranz von einem Fenster bis zu anderthalb Minuten — und beliebig oft. Wer
  ihn mitlas und das Passwort kannte, konnte ihn in dieser Zeit erneut
  einlösen. RFC 6238 §5.2 verlangt das Gegenteil. Das Konto merkt sich jetzt das
  zuletzt angenommene Zeitfenster; verbraucht wird erst nach einer geglückten
  Anmeldung, damit ein Vertippen beim Passwort nicht eine halbe Minute Wartezeit
  kostet. Eine Wiederverwendung wird im Audit-Log als solche vermerkt.
  Gefunden bei der Betrachtung in
  [docs/09-sicherheitsbetrachtung.md](docs/09-sicherheitsbetrachtung.md).
- **Ein falsches Passwort verbraucht keinen Wiederherstellungscode mehr.**
  Dessen Prüfung löst ihn unwiderruflich ein; wer die Codeliste hatte, aber
  nicht das Passwort, konnte damit die Vorräte eines Kontos aufbrauchen.

### Hinzugefügt

- **Selbstupdate** mit Signaturprüfung, Bereitschaftsprüfung und selbsttätigem
  Rückweg: `asylum update`, `asylum rollback` und eine Update-Seite im Panel.
  Die minisign-Prüfung ist in Go umgesetzt und braucht kein externes Programm.
- **Datenbankabzug vor jedem Austausch** (`VACUUM INTO`). Migrationen laufen nur
  vorwärts; ohne Abzug träfe eine zurückgespielte ältere Fassung auf ein Schema,
  das sie nicht kennt.
- **APT-Repository** unter `https://repo.cloudsrv24.de/apt`, signiert mit einem
  eigenen OpenPGP-Schlüssel. Der Schlüsselbund kommt als eigenes Paket
  `asylum-archive-keyring`.
- **Wechsel des zweiten Faktors im laufenden Betrieb**, mit Rückfrage nach dem
  aktuellen Passwort. Bis dahin ging das nur über `asylum reset-password` auf
  der Kommandozeile des Servers.
- **Ansicht der eigenen aktiven Sitzungen** mit Adresse, Programm und letzter
  Aktivität, einzeln oder gesammelt beendbar.
- Workflow **Signatur-Secrets prüfen**, von Hand auslösbar: prüft beide
  Signaturschlüssel vollständig, ohne etwas zu veröffentlichen.

### Geändert

- **Das Debian-Paket heißt `asylum-panel`**, nicht `asylum`. Letzteres ist in
  Debian und Ubuntu an ein Spiel vergeben, dessen Fassung über unserer liegt —
  `apt install asylum` hätte das Spiel gebracht. Der Befehl heißt weiterhin
  `asylum`.
- **`updates.channel` lässt nur noch `stable` und `beta` zu.** `nightly` stand
  in der Konfiguration, wurde von der Freigabepipeline aber nie bedient.
- **Neu: `updates.base_url`** in der Konfiguration, für einen eigenen Spiegel.
  Die Signaturprüfung bleibt davon unberührt.

### Behoben

- **Die Übersicht zeigte nach jedem Start eine halbe Minute lang „keine
  Daten".** Sie rendert aus dem Ringpuffer, und der bekommt nur alle 30 Sekunden
  einen Eintrag. Jetzt aus der jüngsten Messung. Betraf jede frische
  Installation und jeden Neustart nach einem Update.
- **Der Link zur Ersteinrichtung nannte den Rechner ohne Domainendung.**
  `asylum setup-token` gab `https://cloudsrv24:8443/setup?token=…` aus, weil
  `os.Hostname()` auf Debian und Ubuntu den kurzen Namen liefert. Auf dem Server
  selbst löst der auf, im Browser eines anderen Rechners nicht — der Link führte
  ins Leere, und die fehlende Endung sieht man nur, wenn man weiß, dass sie
  fehlen kann. Ermittelt wird jetzt der vollqualifizierte Name wie bei
  `hostname -f`. Findet sich keiner, nennt die Ausgabe zusätzlich die
  IP-Adressen des Servers als Ausweg.
- **Das selbstsignierte Zertifikat enthielt den vollqualifizierten Namen
  nicht.** Wer die fehlende Domainendung von Hand ergänzte, bekam deshalb zur
  Warnung vor dem unbekannten Aussteller noch eine vor dem falschen Namen. Beide
  zusammen sehen aus wie ein Angriff. Der Name steht jetzt im SAN.
- **Die apt-Anleitung nannte einen Kanal, den es noch nicht gab.**
  Dokumentation und Landingpage zeigten `Suites: stable`, während bislang nur
  Vorabversionen veröffentlicht sind — die landen im Kanal `beta`. Ein
  `apt update` endete damit in `404 Not Found` und
  „enthält keine Release-Datei". Die Anleitungen nennen jetzt `beta` und
  erklären, dass ein Kanal erst mit der ersten passenden Veröffentlichung
  entsteht; die Landingpage bestimmt die Empfehlung aus dem tatsächlichen
  Bestand des Repositories.
- **Die Freigabepipeline brach beim Lesen der Datei
  `packaging/min-upgradable-from` ab**, sobald diese wie vorgesehen nur aus
  Kommentarzeilen bestand: `grep` endet ohne Treffer mit Code 1, und unter
  `set -e` riss das den ganzen Schritt mit. Neu ist ein Probelauf
  (`packaging/release-dry-run.sh`), der diesen Schritt bei jedem CI-Lauf gegen
  eine Attrappe ausführt — bisher lief er erstmals, wenn schon ein Tag gesetzt
  war.

## [0.1.0] — noch nicht veröffentlicht

Erste öffentliche Beta. Der Stand davor ist in
[docs/06-roadmap.md](docs/06-roadmap.md) nach Meilensteinen aufgeschrieben:

- **M0** — Installer mit Signaturprüfung, systemd-Unit, TLS, Release-Pipeline
- **M1** — SQLite mit Migrationen, Argon2id, TOTP, Sitzungen, CSRF, Rollen,
  Audit-Log, Live-Übersicht
- **M2** — `privops` als einzige Stelle mit Systemzugriff; Dienste, Pakete,
  Firewall mit Aussperrschutz, Systembenutzer samt SSH-Schlüsseln, Journal
- **M3** — Update-Mechanik (siehe oben)
