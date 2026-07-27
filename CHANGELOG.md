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

- **Der schmale Modus beginnt bei 900 statt 600 Pixeln** — dieselbe Grenze wie
  für die Navigation. Dazwischen stand sonst eine fünfspaltige Tabelle mit
  umbrechendem Text; bei 768 Pixeln schwankten die Zeilenhöhen um 75 Pixel.
- **Festgesetzte Felder sehen aus wie alle anderen.** Was fest ist, sagt die
  Beschriftung, nicht ein abweichender Hintergrund. Der Speichern-Knopf ist so
  breit wie sein Text und nicht mehr wie die Seite.
- **Aktionen sind Schaltflächen, keine unterstrichenen Wörter.** „sperren",
  „löschen", „einspielen" waren als Links gestaltet — auf dem Telefon ein
  Tippziel von wenigen Millimetern, und „löschen" sah aus wie „mehr lesen".
- **Die Firewall-Maske ist ein Block je Regel** statt einer Tabelle mit vier
  Eingabefeldern pro Zeile. Schmal ergab die Tabelle vier verschieden breite
  Felder untereinander, deren Beschriftungen unterschiedlich weit einrückten.
- **Die Regel für den Panel-Port ist gesetzt und nicht entfernbar.** Sie steht
  vorausgefüllt an erster Stelle; erzwungen wird sie serverseitig, denn ein
  schreibgeschütztes Feld ist eine Bitte, keine Sperre. Für SSH schlägt das
  Panel eine Regel vor — mit dem Port aus `sshd_config`, nicht mit der Annahme
  22.
- **Lange Listen auf der Übersicht sind kompakter.** Zellen tragen jetzt eine
  Rolle: Der Name eines Eintrags wird zur Überschrift der Karte,
  Begleitangaben laufen in einer gemeinsamen Zeile. Aus sieben Zeilen je
  Dateisystem werden drei. Ausgeblendet wird nichts.
- **Die Navigation hatte drei fast gleichlautende Einträge** — `Konten`
  (Systembenutzer), `Benutzer` (Panel-Zugänge) und `Konto` (eigenes Profil).
  Sie heißen jetzt **Systembenutzer**, **Panel-Zugänge** und **Mein Konto**.
  Wie gut die alten Namen trugen, zeigt der Umstand, dass die
  SSH-Schlüsselverwaltung im eigenen Projekt für fehlend gehalten wurde: Sie
  liegt vollständig unter „Konten".
- **Die Kopfzeile zeigt den vollqualifizierten Rechnernamen**, nicht mehr den
  kurzen aus `os.Hostname()`. So lässt er sich mit der Adresse im Browser
  vergleichen.
- **Das Debian-Paket heißt `asylum-panel`**, nicht `asylum`. Letzteres ist in
  Debian und Ubuntu an ein Spiel vergeben, dessen Fassung über unserer liegt —
  `apt install asylum` hätte das Spiel gebracht. Der Befehl heißt weiterhin
  `asylum`.
- **`updates.channel` lässt nur noch `stable` und `beta` zu.** `nightly` stand
  in der Konfiguration, wurde von der Freigabepipeline aber nie bedient.
- **Neu: `updates.base_url`** in der Konfiguration, für einen eigenen Spiegel.
  Die Signaturprüfung bleibt davon unberührt.

### Behoben

- **Die Auslastungsbalken standen immer auf 100 %.** Ihre Breite kam aus
  einem `style`-Attribut, und die Content-Security-Policy des Panels erlaubt
  keine Inline-Styles — der Browser verwarf die Angabe stillschweigend. Bei
  CPU und Arbeitsspeicher fiel es nicht auf, weil `live.js` die Breite kurz
  darauf über das CSSOM nachzog; die Balken der Dateisysteme zog niemand nach.
  Der Balken ist jetzt ein `<progress>` und trägt seinen Wert in einem
  Attribut. Ein Test wacht darüber, dass kein `style`-Attribut zurückkehrt.
- **Durchsatzwerte unter 1 KiB standen ungerundet da** — „385.76365553133 B/s"
  in der Netzwerktabelle. Die Go-Seite schnitt ab, die Browserseite nicht.
- **Die Dienstliste sprang zeilenweise auf und ab.** Unter 1200 Pixeln
  Fensterbreite brachen die Aktionsknöpfe um, wodurch aus einer 54 Pixel hohen
  Zeile eine 99 Pixel hohe wurde. Die Knöpfe bleiben jetzt nebeneinander, und
  Zustand samt Unterzustand stehen in einer Zeile.
- **Die Felder der Firewall-Maske waren verschieden breit.** Ein `<fieldset>`
  rendert seinen Inhalt in einer anonymen Box und verhält sich als
  Rastercontainer uneinheitlich: Blöcke mit Hinweistext bekamen 214 Pixel
  breite Spalten, Blöcke ohne 271. Das Raster sitzt jetzt in einem eigenen
  Behälter.
- **Die Firewall ließ sich nicht einschalten, solange sie aus war.** `ufw
  status` gibt im ausgeschalteten Zustand nur `Status: inactive` aus und keine
  einzige Regel — auch dann nicht, wenn längst welche angelegt sind. Das Panel
  verweigert das Einschalten aber, solange es keine Regel für seinen eigenen
  Port sieht. Der Regelsatz ließ sich speichern, der Knopf erschien nie, und
  der Grund war nirgends zu erkennen. Im ausgeschalteten Zustand wird jetzt
  `ufw show added` gelesen.
- **Auf der Übersicht stand dieselbe Platte bis zu sieben Mal.** Die
  systemd-Härtung der eigenen Unit hängt Teile von `/` an weiteren Stellen ein;
  in `/proc/mounts` sind das eigene Zeilen mit denselben Zahlen. Gleiche
  Dateisysteme werden jetzt zusammengefasst, die weiteren Einhängepunkte
  stehen am Eintrag.
- **Karten auf der Übersicht waren unterschiedlich breit.** Eine IPv6-Adresse
  in der Netzwerktabelle zog die Rasterspur auf 414 Pixel, während die
  Nachbarkarte bei 332 blieb — nebeneinander sah das nach zwei Layouts aus.
  Ursache war `1fr`: Ein Grid-Element hat von sich aus `min-width: auto`.
- **Geschützte Konten boten „sperren" und „löschen" an.** `root` lässt sich
  über das Panel nicht verändern — die Prüfung greift serverseitig und lehnt
  ab. Angeboten wurde es trotzdem, und ein Knopf, der zuverlässig scheitert,
  ist schlimmer als keiner.
- **Auf dem Telefon war das Panel kaum bedienbar.** Das Stylesheet hatte keinen
  einzigen Breakpoint — der einzige `@media`-Block galt dem Dunkelmodus. Die
  Navigation brach in vier ausgefranste Zeilen um, und Tabellen liefen aus dem
  Rand: In der Dateisystemliste endete die Anzeige mitten in der
  Spaltenüberschrift. Neu sind zwei Breakpoints, eine einklappbare Navigation
  ohne JavaScript und Tabellen, die schmal zu Karten werden. Geprüft mit einem
  echten Browser bei 375, 414, 768 und 1280 Pixeln auf allen zehn Seiten.
- **ufw ließ sich nur betrachten, nicht bedienen.** Die Firewall-Seite meldete
  „installiert, aber nicht aktiv" und bot keinen Weg, das zu ändern; ein
  Regelsatz ließ sich speichern, obwohl daneben stand, dass er nicht greift.
  ufw lässt sich jetzt aus dem Panel installieren und einschalten. Die
  Aktivierung wird verweigert, solange der Panel-Port nicht freigegeben ist,
  und gilt danach auf Probe mit selbsttätigem Rückweg.
- **Der Zustand von ufw wurde am Fehlschlag des Aufrufs festgestellt.** Damit
  sahen „nicht installiert" und „installiert, aber kaputt" gleich aus, und
  beide bekamen den Rat, ufw zu installieren — im zweiten Fall ein falscher.
  Gefragt wird jetzt die Paketverwaltung.
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
