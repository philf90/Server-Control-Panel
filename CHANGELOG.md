# Changelog

Alle nennenswerten Änderungen. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung nach
[SemVer](https://semver.org/lang/de/).

Die Einträge unter **Unveröffentlicht** stehen im Repository, sind aber noch
nicht als Release getaggt.

## [Unveröffentlicht]

## [0.4.0-rc.1] — 2026-07-30

### Hinzugefügt

- **Die neue Oberfläche „Leitstand" beginnt — vorerst neben der alten.** Sie ist
  unter `/v2/` erreichbar, die bestehende bleibt unter `/` unverändert. Damit
  entsteht der Umbau am laufenden Panel, ohne dass ein Handgriff verloren geht,
  und der Weg zurück ist bis zum Umschalten immer da. Konzept und Begründungen
  stehen in [docs/16-neukonzeption.md](docs/16-neukonzeption.md), die Bildschirme
  in [docs/entwuerfe/neukonzept.html](docs/entwuerfe/neukonzept.html).

  Gebaut ist sie mit Svelte 5 und Vite; die Quellen liegen unter `web/`, das
  Ergebnis eingecheckt unter `internal/ui/dist/` und von dort im Binary. Ein
  Go-Build braucht deshalb weiterhin **keine Node-Kette** — dieselbe
  Entscheidung wie beim Editor-Bundle, und ein CI-Job baut das Ergebnis nach und
  vergleicht byteweise. Nachgewiesen ist die Reproduzierbarkeit über drei Fälle:
  zwei Läufe hintereinander, ein Lauf aus einem anderen Verzeichnispfad und ein
  Lauf nach frischem `npm ci`.

  Die Telemetrie-Kacheln sind der einzige Bestandteil der alten Oberfläche, der
  bleibt — sie sind der Ausgangspunkt des neuen Gestaltungssystems. Gerechnet
  werden die Verläufe weiter auf dem Server (dasselbe `buildSpark`), gezeichnet
  werden sie in einer eigenen Komponente aus inline-SVG. **Keine
  Diagramm-Bibliothek:** Die Feinheiten dieser Kachel sind in 0.2.0 teuer
  gelernt und stecken in wenigen Zeilen.

  **Zur Reihenfolge, weil dieselbe Fassung beides enthält:** Die Kommandobrücke
  weiter unten in diesem Abschnitt ist der Umbau der *alten* Oberfläche — er ist
  gebaut und ausgeliefert worden, und die Rückmeldung darauf war, dass er nicht
  trägt. Aus ihr entstand die Neukonzeption. Die alte Oberfläche ist damit
  **eingefroren**: keine Gestaltung, keine Funktion mehr, weil jede Stunde dort
  in Arbeit ginge, die mit dem Umschalten gelöscht wird. Sie bleibt lauffähig,
  bis die neue Parität erreicht hat. Ein sicherheitsrelevanter Fehler wird auch
  dort behoben, solange sie ausgeliefert wird — eingefroren heißt nicht
  abgeschaltet.

- **Die Übersicht des Leitstands ist vollständig.** Über den Kacheln stehen
  Urteilszeile und Handlungsbedarf, darunter Dateisysteme und die größten
  Prozesse — dieselben Daten wie bisher, in der Reihenfolge von Grundsatz V:
  erst das Urteil, dann die Zahlen.

  Der Handlungsbedarf kommt aus einem **eigenen Aufruf** (`/api/v1/signals`) und
  hat einen **eigenen Fehlerweg**. Beides aus demselben Grund: Seine Erhebung
  ruft `systemctl` und prüft die Neustartmarkierung, sie kostet also echte Zeit
  und kann scheitern. Die Kacheln stehen längst, während er läuft; scheitert er,
  sagt die Urteilszeile das ausdrücklich — eine gescheiterte Erhebung ist nicht
  dasselbe wie „alles in Ordnung", und wer das verwechselt, baut ein Panel, das
  schweigt, wenn es klemmt.

  Ein Dateisystem, das an mehreren Stellen hängt, bleibt ein Eintrag zum
  Aufklappen; die weiteren Stellen tragen die Zahlen der Platte, an der sie
  hängen. Unter 600 Pixeln wird jede Tabellenzeile zu einer Karte mit
  Spaltenbeschriftung — die Lektion aus `rc.4`, jetzt durch einen Test an den
  Quellen und eine Messung im Browser abgesichert.

- **`packaging/dev-deploy.sh`** tauscht das Binary einer laufenden Installation
  gegen einen Eigenbau — für Stände, die man auf einem echten Server sehen will,
  bevor es ein Release gibt. Der reguläre Weg trägt sie nicht: `install.sh` lädt
  immer aus dem Release und prüft die Signatur, `asylum update` braucht
  signierte Metadaten. Beides wird nicht umgangen, sondern beiseitegelassen.

  Das Skript liest den Zielpfad **aus der laufenden Unit** statt zu raten (die
  curl-Installation legt das Binary unter `/usr/local/lib/asylum`, das `.deb`
  unter `/usr/lib/asylum`), sichert das alte, tauscht, prüft die Bereitschaft
  und rollt bei jedem Fehlschlag von allein zurück. Es steht in der
  shellcheck-Liste der CI.

- **JSON-Schnittstelle `/api/v1`** mit `session`, `overview`, `signals` und
  `metrics/history` als einzige Datenquelle der neuen Oberfläche. Der
  Live-Kanal bleibt der bestehende SSE-Hub, den beide Oberflächen gemeinsam
  lesen. Neu ist `session`: Das CSRF-Token liegt in der Sitzungszeile und ging
  bisher in jede gerenderte Seite — eine Einzelseiten-Anwendung bekommt kein
  gerendertes HTML und braucht es über die Schnittstelle.

### Behoben

- **Drei Fehler in der neuen Übersicht, alle von einem Bildschirmfoto gefunden**
  und nicht von einem Test — die Tests waren grün, weil im DOM alles vorhanden
  war. Für jeden gibt es jetzt eine Messung im Browser:

  Die Tabellenkomponenten gaben je **zwei Wurzelelemente** aus (Titel und
  Rahmen). Im Gitter der Übersicht ist jedes Wurzelelement eine eigene Zelle —
  der Titel stand links, die Tabelle rechts. Gemessen wird jetzt, ob jeder Titel
  die linke Kante seiner Tabelle hat und über ihr sitzt.

  Der Tabellenrahmen hatte **`overflow: hidden`** und beschnitt die letzte
  Spalte, ohne einen Balken zu zeigen — die Seite sah heil aus, während die
  Inode-Werte fehlten. Jetzt `overflow-x: auto`, und ein Test schlägt an, wenn
  Inhalt in einem Rahmen mit `hidden` breiter ist als der Rahmen.

  Der **Live-Kanal überschrieb vollständige Listen mit dünneren.** Sein erstes
  Ereignis ist der letzte Ringpuffer-Eintrag, und der Ring hält den Verlauf,
  nicht zwingend jede Liste. Wer ihn bedingungslos bevorzugt, zeigt „keine
  Dateisysteme gefunden", während der Server längst geantwortet hat. Entschieden
  wird jetzt je Liste: Ein Linux-Rechner hat immer Dateisysteme und Prozesse,
  eine leere Liste heißt also nicht „keine", sondern „nicht in dieser Nachricht".

- **Eine abgelaufene Sitzung antwortete der Schnittstelle mit HTML.**
  `redirectToLogin` beantwortete nur den SSE-Fall mit einem Statuscode; jede
  andere Hintergrund-Anfrage bekam eine Weiterleitung auf die Anmeldeseite. Für
  ein `fetch` heißt das HTML statt JSON, und die Oberfläche meldet dann einen
  Parserfehler statt der eigentlichen Ursache. Unter `/api/` steht jetzt ein
  401 mit JSON-Rumpf. Erkannt wird der Fall am Pfad und nicht am Accept-Kopf:
  Den setzt jede Kundin selbst, den Pfad bestimmt die Anwendung.

### Geändert

- **Die Oberfläche wird eine Kommandobrücke.** Die Übersicht zeigte zuverlässig,
  wie es dem Server geht — nur verschwand sie, sobald man handelte. Wer auf
  „Dienste" wechselte, um einen Ausfall zu beheben, sah CPU, Speicher und Platte
  in genau dem Moment nicht mehr, in dem sie interessant wurden. Aus der
  gruppierten Seitenleiste wird deshalb eine Schale aus vier Teilen, die auf
  jeder Seite gleich ist:

  - Eine **Statusleiste** über allem mit Wirt, Laufzeit, CPU, Speicher, Platte,
    Last und Netz. Jede Anzeige darin ist ein Link — eine auffällige Zahl soll
    ein Griff sein, kein Text. Die Werte schreibt der Live-Kanal jetzt auf allen
    Seiten fort, nicht mehr nur auf der Übersicht.
  - Eine **Symbolschiene** statt der Menüspalte: elf Ziele auf gut vier
    Zeichenbreiten, mit einem Warnpunkt je Bereich. Damit verrät das Menü, wo
    etwas offen ist, ohne dass man jede Seite einzeln besuchen muss.
  - Eine **Konsole** am unteren Rand — siehe unten.

  Der Akzent wechselt von Grün auf Signalbernstein; Grün, Gelb und Rot bleiben
  damit dem Zustand vorbehalten und bedeuten nichts anderes mehr. Schiene,
  Statusleiste und Konsole sind auch im hellen Modus dunkel: Eine
  Instrumententafel hat eine Blende, und sie trennt das Gerät vom Inhalt
  deutlicher als jede Linie.

- **Schmal wird aus der Schiene eine Leiste am unteren Rand** — in
  Daumenreichweite, was die Spalte links nie war. Vier Ziele bleiben stehen
  (Lage, Dienste, Firewall, Journal), der Rest klappt über „Mehr" auf. Die
  Kennzahlen werden ein seitlich schiebbares Band unter der Kopfzeile.

### Hinzugefügt

- **Konsolen-Echo: Das Panel zeigt, was es ausführt.** Am unteren Rand jeder
  Seite steht der zuletzt auf der Maschine ausgeführte Befehl mit Rückgabewert
  und Laufzeit; aufgeklappt die letzten vierundzwanzig. Wer auf „neu starten"
  klickt, sieht `systemctl restart ssh.service` — und wer per SSH weiterarbeitet,
  findet dieselben Befehle vor. Fehlschläge stehen mit der ersten Meldung dabei,
  nicht nur mit einem Kreuz.

  Aufgezeichnet wird am Runner, nicht an jeder einzelnen Operation, damit keine
  Stelle vergessen werden kann. Das Journal liegt nur im Speicher und in einem
  Ring fester Größe — ein Nebenprodukt der Oberfläche darf weder wachsen noch
  einen Neustart überleben; dauerhaft protokolliert das Audit-Log. Stdin wird
  nie aufgezeichnet (dort stehen die Passwörter, die `passwd` entgegennimmt),
  und Argumente nach einer Option, die nach einem Geheimnis klingt, werden
  verdeckt. Die Konsole nimmt keine Eingabe entgegen: Ein Terminal wäre ein
  eigenes Modul mit eigener Sicherheitsbetrachtung.

- **Warnpunkte an der Schiene.** Sie folgen denselben Signalen wie die
  Übersicht, damit sich Menü und Seite nicht widersprechen. Erhoben wird der
  Stand im Messtakt und bei jedem Aufruf der Übersicht — ein Seitenaufbau löst
  bewusst kein `systemctl` aus, sonst hinge jede Seite an einem Systemaufruf.
  Ist nichts Frisches da, bleiben die Punkte weg; das ist die ehrlichere
  Aussage als ein geratener.

- Drei Entwürfe für die Neuordnung der Oberfläche als Mappe mit Mockups, dazu
  eine zweite Mappe, die Entwurf 1 über alle 23 Seiten durchzieht — am
  Bildschirm und auf dem Telefon. Siehe
  [docs/15-neuordnung.md](docs/15-neuordnung.md).

### Sicherheit

- **Die Rückfragen vor zerstörenden Aktionen haben nie gefragt.** Dreizehn
  Formulare trugen ein `onsubmit="return confirm(…)"`: Panel-Zugang löschen,
  Systemkonto löschen, SSH-Schlüssel entfernen, Passkey entfernen, Dateien
  löschen, ufw ein- und ausschalten, Server neu starten, alle Updates
  einspielen, Dienst stoppen, Panel-Update, Rollback, alle anderen Sitzungen
  beenden. Die eigene Content-Security-Policy (`script-src 'self'` ohne
  `'unsafe-inline'`) lässt keinen Inline-Handler zu — der Browser verwirft ihn
  still. Im Browser nachgemessen: `form.onsubmit` war keine Funktion, kein
  Dialog erschien, und das Konto war nach einem Klick weg. Jede dieser Stellen
  sah im Code abgesichert aus, keine war es.

  Die Rückfrage steht jetzt im Handler: Ohne das Feld `bestaetigt` führt keine
  dieser Aktionen etwas aus, und ohne Skript kommt eine Zwischenseite, die sagt,
  was passieren wird. Bei unumkehrbaren oder aussperrenden Aktionen muss
  zusätzlich der Name des Ziels getippt werden — bei systemweiten (Neustart, ufw
  ausschalten) der **Hostname**, gegen den Fehler, den kein Klick abfängt: die
  richtige Aktion auf dem falschen Server. Dazu ein Dialog im Browser
  (`<dialog>`, kein `window.confirm`), der dieselbe Frage ohne Seitenwechsel
  stellt. Einzelheiten in
  [docs/14-bestaetigungen.md](docs/14-bestaetigungen.md).

  Ohne Rückfrage bleibt, was umkehrbar ist: sperren, entsperren, starten, neu
  starten, ein einzelnes Paket einspielen, eine einzelne Sitzung beenden. Ein
  Dialog vor jeder Kleinigkeit erzieht zum Wegklicken.
- **Zwei Aktionen hatten überhaupt keine Rückfrage:** „Passkeys entfernen" auf
  Panel-Zugänge und das Erzeugen neuer Wiederherstellungscodes — letzteres macht
  eine ausgedruckte Liste wertlos, und bemerkt wird das erst, wenn man sie
  braucht. Beide fragen jetzt.
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

- **Die Passwortrichtlinie steht dort, wo ein Passwort gewählt wird.** Bisher
  stand unter dem Feld ein Satz („Mindestens 12 Zeichen"); welche Regeln es sonst
  gibt, erfuhr man erst durch eine Ablehnung. Jetzt zeigen alle vier Seiten mit
  einem neuen Passwort — Ersteinrichtung, Kontoseite, erzwungener Wechsel und der
  Weg über einen Passkey — jede Bedingung mit Haken oder Kreuz, dazu eine
  Stärkeschätzung als Balken mit einem Wort daneben (schwach, mittel, gut, stark).
  Verletzt eine Eingabe eine Regel, sagt die Anzeige „nicht zulässig" statt eine
  Stärke zu loben, die der Server nicht annimmt.

  Die Zahlen der Richtlinie stehen genau einmal (`auth.Policy()`) und werden ins
  Markup gerendert; das Skript für die Anzeige schreibt keine davon fest.
  Verbindlich bleibt die Prüfung auf dem Server. Dass beide Fassungen dasselbe
  sagen, hält ein Browsertest fest, der dieselbe Tabelle durch Go und durch die
  Anzeige schickt und Regel für Regel vergleicht.
- **Die Richtlinie prüft zwei Fälle mehr**, die jede Längenregel bestehen und
  trotzdem in Sekunden geraten sind: den eigenen Anmeldenamen (auch als Teil des
  Passworts, unabhängig von der Schreibweise) und eine bloße Wiederholung oder
  durchgehende Zeichenfolge (`aaaaaaaaaaaa`, `abcdefghijkl`). Weiterhin **keine**
  Vorschriften zu Groß-, Klein- und Sonderzeichen: Die führen zu `Passwort1!`,
  und NIST 800-63B rät seit 2017 davon ab. Bestehende Passwörter sind unberührt —
  geprüft wird, was neu gesetzt wird. Einzelheiten und was bewusst offen bleibt:
  [docs/09-sicherheitsbetrachtung.md](docs/09-sicherheitsbetrachtung.md).

- **Dateimanager über das gesamte Dateisystem.** Browsen mit klickbarem Pfad und
  sortierbarer Liste, Namenssuche unterhalb eines Verzeichnisses, Download
  einzelner Dateien, ganze Ordner als `tar.gz`, Upload mit Fortschrittsbalken
  und Ablagefläche, Anlegen, Umbenennen, Verschieben, Kopieren, Löschen sowie
  Rechte und Eigentümer. Dazu ein Editor mit Zeilennummern und Hervorhebung für
  YAML, JSON, INI, Shell, nginx, Dockerfile und TOML.

  Lesen darf jede angemeldete Rolle, ändern nur Admin und Owner. **Manche Pfade
  sind für das Panel tabu — auch für Owner:** die Passwort-Hashes des Systems,
  SSH-Host-Schlüssel, der private TLS-Schlüssel und die Datenbank des Panels.
  Sie stehen mit Begründung in der Liste, ihr Inhalt wird nie ausgeliefert; wer
  sie braucht, hat SSH. Rekursive Eingriffe werden vorher gezählt und
  abgelehnt, wenn Gesperrtes darunter liegt oder eine Dateisystemgrenze
  überschritten würde — ein Löschen von `/etc` darf `/etc/shadow` nicht
  mitnehmen. Jede Änderung **und jeder Download** steht im Audit-Log.

  Der Editor erhält Zeilenenden, erkennt eine von außen geänderte Datei am Hash
  und rollt zurück, wenn `sshd -t` oder `nft -c -f` die neue Fassung ablehnen.
  Vor jedem Überschreiben entsteht eine Sicherung unter
  `/var/lib/asylum/backups/`.

  Abschaltbar über `files.enabled: false` — das entfernt Routen und Rechte, nicht
  nur den Menüpunkt. Einstellbar sind außerdem sichtbare und beschreibbare
  Bereiche, eigene Sperrmuster und die Größengrenzen.
  Einzelheiten in [docs/13-dateimanager.md](docs/13-dateimanager.md).
- **Passkeys als zusätzlicher zweiter Faktor (WebAuthn).** Neben der
  Authenticator-App lässt sich jetzt ein Passkey hinterlegen — Fingerabdruck,
  Gesicht oder ein Sicherheitsschlüssel. Im Konto: hinzufügen (verlangt das
  aktuelle Passwort), umbenennen, entfernen. Bei der Anmeldung ein Knopf neben
  „Anmelden"; der Weg mit Passwort und Code bleibt unverändert der Rückfall
  ohne JavaScript und für Konten ohne Passkey. Über SSH gibt es
  `asylum passkey list|remove` als Rettungsweg. Ohne Konfiguration verfügbar,
  sobald ein auflösbarer Name als RP-ID feststeht (aus Zertifikat, ACME-Domain
  oder FQDN) — spätestens mit einem Zertifikat auf einen echten Namen erscheint
  der Abschnitt von selbst. `auth.webauthn.enabled: false` schaltet aus.
  Einzelheiten in [docs/11-passkeys.md](docs/11-passkeys.md).
- **Ein Zugang lässt sich im Panel zurücksetzen.** Auf *Panel-Zugänge* steht
  unter der Tabelle „Zugang zurücksetzen": Passwort, zweiter Faktor oder
  Passkeys, einzeln auslösbar; je Tabellenzeile führt ein Link dorthin und wählt
  das Konto vor. Das Passwort wird als **Einmalpasswort** vergeben und genau
  einmal angezeigt — das Konto muss es bei der nächsten Anmeldung ersetzen und
  kommt vorher auf keine andere Seite. Jede Aktion verlangt das eigene Passwort
  des Owners, beendet die Sitzungen des Zielkontos und steht im Audit-Log; das
  eigene Konto ist ausgenommen. Bis hierher ging das nur über
  `sudo asylum reset-password` auf dem Server.
- **Ein vergessenes Passwort lässt sich per Passkey selbst zurücksetzen.** Unter
  dem Anmeldeformular steht „Passwort vergessen?". Die Zeremonie nennt **kein
  Konto** — der Browser bietet an, was er für dieses Panel hat — und verlangt
  zwingend die Prüfung am Gerät (PIN, Fingerabdruck, Gesicht). Damit besteht der
  Nachweis aus zwei Teilen, und der Weg verrät keine Anmeldenamen. Ein
  Wiederherstellungscode half hier nie: Er wird nur eingelöst, wenn das Passwort
  stimmt. Damit neue Passkeys dafür taugen, verlangt die Registrierung jetzt
  `residentKey: "preferred"`. Bewusst **ohne Mailversand** — die Abwägung steht
  in [docs/12-zugang-zuruecksetzen.md](docs/12-zugang-zuruecksetzen.md).
- **Ein Neustart lässt sich aus dem Panel anstoßen.** Steht ein Neustart aus
  (etwa nach einem Kernel-Update), führt die Übersicht ihn im Handlungsbedarf
  auf und verlinkt auf die Pakete-Seite; dort steht neben dem Hinweis der Knopf
  »Jetzt neu starten«. Er ist Owner-Konten vorbehalten und fragt vor dem
  Auslösen nach — ein Neustart beendet alle Sitzungen und Dienste. Umgesetzt
  über eine neue, typisierte `Reboot`-Operation im `privops.Executor`
  (`systemctl reboot`), nicht über ein freies Kommando.
- **Hell und Dunkel lassen sich von Hand umschalten.** Bisher folgte das Panel
  nur der Systemeinstellung. Unten in der Seitenleiste steht jetzt ein
  Umschalter; die Wahl liegt in einem Cookie und wird serverseitig gerendert,
  sodass die Seite ohne Aufblitzen im gewählten Modus ankommt. Ohne getroffene
  Wahl gilt weiter die Systemeinstellung.
- **Der Zertifikatsbezug zeigt live, was er tut.** Unter den Einstellungen steht
  jetzt ein Verlauf, der sich von selbst fortschreibt — Anmeldung, Auftrag,
  gesetzter TXT-Record, das Warten auf die DNS-Ausbreitung samt gebrauchter
  Zeit, die Bestätigung, das Abholen und das Einsetzen mit Ablaufdatum.
  Vorher war das der stummste Teil des Panels: Ein DNS-01-Durchlauf wartet bis
  zu zwei Minuten auf die Sichtbarkeit des Records und danach unbestimmt lange
  auf die CA, ohne dass irgendetwas davon zu sehen war — und ein Fehlschlag kam
  als ein einziger Satz zurück, aus dem nicht hervorging, ob der DNS-Anbieter,
  die Ausbreitung oder Let's Encrypt das Problem war. Der Vorgang läuft weiter,
  wenn die Seite geschlossen wird; wer zurückkommt, bekommt den ganzen Ablauf.
  Auch die Erneuerung, die vor Ablauf von allein läuft, schreibt mit. Geheimnisse
  stehen nie darin — weder der Challenge-Wert noch die Zugangsdaten des
  Anbieters; ein Test wacht darüber.

- **TLS und Let's Encrypt lassen sich im Panel einstellen** — unter
  *Sicherheit → Zertifikat*. Betriebsart, Domains, Kontaktadresse,
  Prüfverfahren, DNS-Anbieter samt Hook-Pfaden oder Cloudflare-Token und das
  Testverzeichnis. Eine Konfigurationsdatei muss dafür niemand mehr anfassen.
  Eine Änderung greift sofort: Der Bezug wird mit den neuen Werten neu
  angestoßen, ohne Neustart des Dienstes. Dazu ein Knopf **Jetzt beziehen**,
  der eine geänderte Einstellung sofort prüft.
- **Ergänzungsdateien unter `/etc/asylum/conf.d/`.** Sie werden nach der
  Hauptdatei in Namensreihenfolge gelesen. Das Panel schreibt dort genau eine
  Datei (`10-tls.yaml`) und fasst `config.yaml` nicht an — Kommentare und
  eigene Anmerkungen des Betreibers bleiben erhalten. Wer eine Einstellung
  festhalten will, legt sie in eine Datei mit höherem Namen.
- **Zertifikate von Let's Encrypt (ACME).** Mit `server.tls.mode: acme` holt
  das Panel ein von Browsern anerkanntes Zertifikat und erneuert es rund 30 Tage
  vor Ablauf — im Hintergrund, ohne Neustart. Zwei Prüfverfahren:
  **HTTP-01** über einen kurzlebigen Listener auf Port 80 und **DNS-01** über
  einen TXT-Record, der ohne Port 80 auskommt (wichtig, wenn dort schon ein
  Webserver läuft). DNS-01 gibt es über einen **Hook** (Betreiber-Skript, kein
  Anbieter im Binary) oder eingebaut über **Cloudflare** (reines HTTP, Token aus
  einer Datei). Ist ein DNS-Anbieter gesetzt, wählt das Panel automatisch
  DNS-01, sonst HTTP-01. Scheitert der Bezug, bleibt das selbstsignierte
  Zertifikat — das Panel bleibt erreichbar. Einzelheiten in
  [docs/10-tls-acme.md](docs/10-tls-acme.md).
- **Seite „Zertifikat"** (unter Sicherheit) und **`asylum cert status`** zeigen
  Herkunft, Namen, Aussteller, Restlaufzeit und Fingerprint des ausgelieferten
  Zertifikats.
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

- **Rechte im Dateimanager stehen als Raster da, nicht als Ziffer.** Drei Rollen,
  drei Rechte, je Zeile ein Satz: „Eigentümer darf Inhalt auflisten, Einträge
  anlegen und löschen und hineinwechseln". Bei einem Verzeichnis heißt `x` nicht
  ausführen, sondern hineinwechseln, und `r` nicht lesen, sondern auflisten — die
  häufigste Verwechslung überhaupt, jetzt steht sie in den Worten. Die
  Oktalzahl bleibt daneben und läuft mit den Kästchen im Gleichschritt: Kästchen
  ändern die Ziffer, eine getippte Ziffer setzt die Kästchen. Die Sonderbits
  (setuid, setgid, Sticky) stehen mit ihrer Bedeutung dabei und erklären die
  erste Stelle.

  Beschrieben wird serverseitig (`privops.DescribeMode`), damit die Angabe auch
  ohne Skript stimmt; ohne Skript sind die Kästchen gesperrt und beschreiben den
  Ist-Zustand. Der Abschnitt erscheint jetzt auch für die nur lesende Rolle — als
  Beschreibung ohne Formular.
- **Verschieben und Kopieren wählen ihr Ziel aus, statt es tippen zu lassen.**
  Das Ziel war ein freies Textfeld: Ein Tippfehler wurde erst beim Absenden zu
  einer Fehlermeldung, und `/srv/date` statt `/srv/daten` benennt beim
  Verschieben um, statt zu verschieben. Zur Wahl steht jetzt nur, was es gibt —
  eine durchsuchbare Auswahl über den neuen Endpunkt `/files/dirs`, mit den
  Schreibbereichen als Sprungmarken; ein Ordner ohne Schreibrecht ist sichtbar,
  aber nicht wählbar. Ohne Skript bleibt eine serverseitig gefüllte Auswahlliste
  (Schreibbereiche und der Weg zum Eintrag) — auch die ist nicht frei.

  Verbindlich bleibt die Prüfung beim Ausführen: Die Auswahl ist eine
  Bedienhilfe, keine Sicherheitsgrenze. Ein selbstgebauter POST kommt an ihr
  vorbei und an der Pfadwache nicht.
- **Verschieben und Kopieren teilen sich ein Formular.** Sie unterscheiden sich
  nur im Knopf — dasselbe Ziel, dieselbe Prüfung. Aus drei Formularen mit
  eigenem Feld und eigenem Knopf sind zwei Zeilen geworden; welcher Knopf
  gedrückt wurde, entscheidet über `formaction`.
- **Der Dateimanager ist deutlich kürzer geworden — ohne eine Funktion zu
  verlieren.** Fünf Eingriffe: Rechte und Eigentümer stehen in einer Spalte
  (`root:root · 0644`, aus sechs Spalten werden fünf); die bis zu drei Knöpfe je
  Zeile sind ein Menü je Zeile (bei zwanzig Einträgen waren das sechzig Knöpfe) —
  darin weiter öffnen und `tar.gz` bei Ordnern, bearbeiten und herunterladen bei
  Dateien, immer die Detailseite, zu der jetzt auch der Name selbst führt;
  Anlegen und Hochladen sitzen in einer Karte statt in zwei; die Angaben der
  Detailseite stehen in einer Zeile statt als Definitionsliste mit fünf; und
  Löschen steht bei den Aktionen oben rechts statt als eigener Abschnitt am Fuß.
  Die Rückfrage nennt weiter die Zahlen — sie ist die einzige Bremse, denn einen
  Papierkorb gibt es nicht.

  Das Menü ist ein `<details>` und braucht kein JavaScript; ein offenes Menü
  schließt dafür nicht von selbst, wenn man ein zweites öffnet. Die Karte zum
  Hochladen bleibt sichtbar statt eingeklappt: Sie ist die Ablagefläche für
  Ziehen und Ablegen, und ein zugeklapptes Element nimmt keine Datei an.
- **Das Rechteraster ist unter 900 Pixeln Fensterbreite ein Block je Rolle.**
  Als Tabelle braucht es gut 980 Pixel: Bei 700 war der Satz mitten im Wort
  abgeschnitten („hineinwechse"), bei 390 fehlte er ganz — und er ist der Grund,
  warum es das Raster gibt. Ursache war ein `overflow-x: visible`, das für die
  Kartentabellen gedacht war und dem Raster seinen Scrollbehälter nahm; es gilt
  jetzt nur noch für Tabellen, die schmal zu Karten werden.
- **Ein Panel-Zugang wird mit Anmeldename und Rolle angelegt — nichts weiter.**
  Das Feld für ein Startpasswort ist entfallen: Das Panel erzeugt es selbst
  (zufällig, der Richtlinie entsprechend), zeigt es genau einmal an und verlangt
  bei der ersten Anmeldung den Wechsel. Dieselbe Mechanik wie beim Zurücksetzen
  eines Zugangs, dieselbe Seite. Ein selbst getipptes Startpasswort war so gut,
  wie es dem Owner an diesem Tag einfiel, stand als Klartext in seinem Formular
  und blieb gültig, bis das neue Konto von selbst auf den Wechsel kam. Im
  Audit-Log steht, dass ein Einmalpasswort vergeben wurde — nie das Passwort.
- **Die Firewall-Seite sieht aus wie der Rest des Panels.** Hinweistexte, Knöpfe
  und die Regelblöcke lagen frei auf dem Seitenhintergrund, während Übersicht,
  Pakete und Dienste ihre Inhalte in Karten führen — die Seite wirkte wie ein
  Entwurf. Jetzt zwei benannte Abschnitte in Karten: **Zustand** (greifen die
  Regeln? was bleibt erreichbar? welcher Knopf ist fällig?) und **Regelsatz für
  eingehenden Verkehr** mit den Regelblöcken darin. Das gesperrte Einschalten ist
  eine Meldung und kein loser Absatz mehr.
- **Die Zeilenformulare füllen ihre Karte.** `.row-form` verteilte gleich große
  Rasterspuren (`repeat(auto-fit, minmax(12rem, 1fr))`) — auch an die Spalte des
  Knopfes, der davon rund 95 Pixel braucht. Der Rest der Spur blieb leer: bei
  „Konto anlegen" gut 130 Pixel am rechten Rand, während die vier Felder davor
  schmaler waren als nötig. Die Karte sah aus, als sei sie zu breit für ihren
  Inhalt. Jetzt nimmt der Knopf seine eigene Breite, und die Felder teilen sich
  den Rest; beim Umbruch trägt ein Zeilenabstand, wo vorher die Beschriftung der
  zweiten Zeile am Feld der ersten klebte. Betrifft alle Zeilenformulare —
  Dateien, Dateidetails, Systembenutzer, Panel-Zugänge, Konto.
- **Auf „Systembenutzer" führt ein Knopf oben zum Anlegen.** Bei 33 Konten steht
  das Formular hinter einer langen Liste; ohne diesen Weg scrollt man sie jedes
  Mal ab. Ein Anker, kein Skript.
- **„Paketlisten aktualisieren" zeigt, was `apt-get update` gemeldet hat.**
  Bisher lief der Aufruf im Seitenaufruf, und seine Ausgabe wurde gesammelt und
  verworfen: Übrig blieb im Fehlerfall die erste `stderr`-Zeile. Wer wissen
  wollte, welche Quelle geantwortet hat und welche nicht, brauchte SSH. Der Lauf
  ist jetzt ein Vorgang mit Live-Ausgabe — dieselbe Mechanik wie beim Einspielen,
  mit eigenem Kontext, damit ein geschlossener Tab kein laufendes `apt-get`
  abbricht. Der Auszug steht immer da, nicht nur bei Fehlern.

  **Ein Teilerfolg ist keine Fehlermeldung mehr.** apt beendet sich mit 100,
  sobald eine einzige Quelle klemmt — auch dann, wenn alle übrigen aktualisiert
  wurden. Auf einem Server mit einer aufgegebenen PPA meldete das Panel dafür
  „Paketlisten konnten nicht aktualisiert werden", obwohl die Listen von Ubuntu
  und Docker frisch waren. Jetzt wird die Ausgabe ausgewertet: Gibt es Antworten
  *und* gescheiterte Quellen, ist es eine Warnung, die die betroffenen Quellen
  mit Grund nennt („403 Forbidden") und dazusagt, dass die Aufstellung
  unvollständig sein kann. Scheitert alles, bleibt es ein Fehler. Der Ausgang
  steht mit den betroffenen Quellen im Audit-Log.
- **Die weiteren Einhängepunkte einer Platte klappen in der Übersicht auf.** Ein
  Dateisystem, das an mehreren Stellen hängt — die Härtung der eigenen Unit tut
  das mit Teilen von `/` —, stand als eine Zeile mit dem Hinweis „auch an 6
  weiteren Stellen" da; die Stellen selbst gab es nur als `title`-Attribut: ein
  Kasten, der nach einer Sekunde erscheint, keine Zahlen tragen kann und auf
  einem Telefon gar nicht. Sie sind jetzt eigene Zeilen der Liste mit den Zahlen
  des Dateisystems, an dem sie hängen. Voreingestellt eingeklappt; der
  Umschalter ist eine Checkbox und kein Knopf mit Skript, damit die Liste ohne
  JavaScript aufklappbar bleibt — dieselbe Entscheidung wie beim Menü.
- **Die Verläufe der Telemetriekacheln lassen sich ablesen.** Der Zeiger zeigt
  Wert und Uhrzeit der Messung unter ihm. Die Messpunkte stehen fertig
  formatiert in einem `data`-Attribut, das Skript sucht nur den nächsten:
  Gerechnet und gerundet wird weiter auf dem Server, und ohne das Skript bleibt
  der Verlauf zu sehen.
- **Die systemd-Unit erlaubt dem Dienst Schreibzugriff auf `/etc`, `/home` und
  `/root`** (`ProtectSystem=true` statt `full`, `ProtectHome=false` statt
  `read-only`). Ohne diese Lockerung könnte der Dateimanager keine
  Konfigurationsdatei speichern: Der Schreibversuch scheitert dann mit `EROFS`,
  und das ist an den Rechtebits des Verzeichnisses nicht zu erkennen. `/usr`,
  `/boot` und `/efi` bleiben schreibgeschützt — dort hat ein Panel nichts von
  Hand zu ändern, und der Schutz gegen ein untergeschobenes Binary bleibt damit
  erhalten.

  **Das Selbstupdate tauscht das Programm, nie die Unit.** Bestehende
  Installationen behalten die alte Härtung; das Panel erkennt das mit einem
  echten Schreibversuch und zeigt auf der Dateiseite, wie es behoben wird. Weg
  und Begründung in [UPGRADING.md](UPGRADING.md).
- **Die Content-Security-Policy der Editor-Seite erlaubt ein nonce-gebundenes
  Stil-Element.** CodeMirror trägt seine Regeln zur Laufzeit ein, und
  `style-src 'self'` verwirft das — im Browser nachgemessen, der Editor blieb
  ungestylt. Statt `'unsafe-inline'` für die Seite trägt die Antwort einen je
  Antwort neu gezogenen Nonce; erlaubt ist damit genau das eine Element, das
  ihn kennt. Alle anderen Seiten behalten die unveränderte Richtlinie.
- **Alle Seiten tragen dieselbe Handschrift wie der Leitstand.** Jede Modulseite
  beginnt jetzt mit einem Seitenkopf (Titel als Überschrift, eine Unterzeile mit
  der Kennzahl, rechts die Aktionen der Seite) statt mit einer Überschrift in
  einer Karte. Die Tabellen sind ruhiger — Kapitälchen-Kopf, leise Trennlinien,
  eine Hervorhebung der Zeile unter dem Zeiger —, Badges sind Pillen mit einem
  farbigen Punkt für Zustände, und die Karten sind stärker abgerundet. Das ist
  eine durchgängige Überarbeitung des Stylesheets; die Seiten wirkten zuvor
  neben der neuen Übersicht veraltet.
- **Die Übersicht ist ein Leitstand.** Statt eines Gitters gleichrangiger
  Kacheln, aus dem der Betrachter selbst herauslesen musste, ob dem Server
  etwas fehlt, führt jetzt ein Urteil in einem Satz: Läuft alles normal, oder
  brauchen einige Dinge Aufmerksamkeit? Darunter erscheint — nur wenn es etwas
  zu tun gibt — ein Handlungsbedarf-Block mit ausgefallenen Diensten, knappem
  Plattenplatz (ab 85 %, kritisch ab 95 %) und ausstehendem Neustart, jeweils
  mit dem Weg zur zuständigen Seite. Erst dann folgt die Telemetrie: CPU,
  Arbeitsspeicher, Last und Netz je als Kachel mit dem Verlauf der letzten
  Stunden, dazu Dateisysteme und die größten Prozesse. Die Verläufe zeichnet
  der Server als SVG-Pfad (die CSP verbietet Inline-Skripte); die großen Zahlen
  tragen weiter `data-live` und werden vom Live-Kanal nachgezogen. Der
  Handlungsbedarf kommt ohne Schreibpfad und ohne CSRF aus, weil seine Aktionen
  bloße Links sind, und wird mit kurzem Timeout gesammelt — ein hängendes
  `systemctl` darf die meistbesuchte Seite nicht blockieren.
- **Die Navigation ist eine Seitenleiste.** Statt zehn Punkten in einer Zeile
  stehen sie senkrecht und nach **System**, **Sicherheit** und **Betrieb**
  gruppiert; der eigene Zugang und das Abmelden sitzen unten fest. Der Menüpunkt
  „Mein Konto" entfällt — der Benutzername in der Leiste ist der Weg aufs Profil,
  der Projektname der Weg zur Übersicht. Schmal klappt die Leiste zu einer
  Kopfzeile ein. Der Grund ist Platz: Zehn Punkte in einer Zeile waren schon
  knapp, die geplanten Module würden sie sprengen.
- **Such- und Filterfelder unter „Dienste" und „Logs" sind gestaltet.** Sie sind
  `<input type="search">`; die Regel für Eingabefelder kannte diesen Typ nicht,
  sodass der Browser sie in seinem eigenen Stil zeichnete.
- **Der Knopf „Nach Updates suchen" hat wieder Abstand** zur Bezugsquelle
  darüber; eine Definitionsliste trägt keinen Außenabstand nach unten.
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

- **Ein Systembenutzer bekam kein Home-Verzeichnis, obwohl das Formular es
  versprach.** `CreateHome` hing an einem Feld `create_home`, das es im Formular
  nie gab — `useradd` lief also immer mit `--no-create-home`, während darunter
  „Das Home-Verzeichnis wird angelegt" stand. Ohne Home gibt es kein `~/.ssh`,
  das dem Konto gehört, und damit keine Anmeldung per Schlüssel: den einzigen
  Weg, den diese Konten haben (sshd besteht auf einem Home, das nur dem Konto
  selbst zugänglich ist). Es wird jetzt immer angelegt.
- **Das Feld für den SSH-Schlüssel beim Anlegen eines Systembenutzers fehlte.**
  Vorhanden war nur seine Beschriftung für Screenreader — hinter dem `</form>`,
  also selbst dann ohne Wirkung, wenn es ein Feld gegeben hätte. Der Handler
  nimmt `ssh_key` seit dem ersten Tag an und legt den Schlüssel beim Anlegen ab;
  erreichbar war die Angabe nie, und der Hinweistext („Ohne Schlüssel …")
  beschrieb eine Eingabe, die niemand machen konnte. Das Feld steht jetzt im
  Formular, über die ganze Breite. Zwei neue Tests halten die Gattung fest: Jede
  Beschriftung braucht ihr Feld, jeder Anker sein Ziel.
- **Die Netzwerkkachel der Übersicht zeigte `docker0`.** Sie nahm die erste
  Schnittstelle der alphabetisch sortierten Liste, und auf jedem Server mit
  Docker ist das die Brücke, über die nach draußen kein Byte geht: Die Kachel
  stand dauerhaft auf 0 B/s, während die echte Karte Last hatte — und der Name
  darunter machte die falsche Angabe glaubwürdig. Gewählt wird jetzt die
  Schnittstelle mit der Standardroute (`/proc/net/route`,
  `/proc/net/ipv6_route`), nachrangig eine mit einem Gerät hinter sich
  (`/sys/class/net/<name>/device`). Eine Brücke oder ein Bündel darf gewinnen,
  wenn der Verkehr dort hinausgeht — auf einem Hypervisor ist `br0` die richtige
  Antwort, nicht ihr Anschluss. Auch der Netzverlauf zählt nur noch diese
  Schnittstelle statt der Summe über alle; sein Wert gehörte vorher zu keiner
  Zahl auf der Kachel. Die vollständige Liste bleibt unberührt.
- **Die Sparklines der Telemetriekacheln liefen aus.** Ihr viewBox ist 100
  Einheiten breit und wird mit `preserveAspectRatio="none"` auf die Kachelbreite
  von rund 270 Pixeln gezogen — waagerecht mit Faktor 2,7, senkrecht mit 1. Die
  Strichstärke wurde mitgezogen: Steile Stücke waren über 4 Pixel breit, flache
  blieben bei 1,6, und der Endpunkt kam als liegende Ellipse heraus. Jetzt gilt
  sie in Bildschirmpixeln (`vector-effect: non-scaling-stroke`), und der
  Endpunkt ist ein Segment der Länge null mit runder Kappe statt eines
  `<circle>`. Zwei weitere Gründe für den unruhigen Eindruck sind mit behoben:
  Die bis zu 2880 Messungen des Ringpuffers werden auf 60 Stützstellen gemittelt
  — zehn Punkte je Pixel ergeben kein Bild, sondern ein Band —, und die
  Skalierung hat eine Mindestspanne. Eine CPU, die zwischen 0,1 und 0,3 Prozent
  pendelt, sah vorher aus wie ein Gebirge.

  Gemessen im echten Browser: `TestUebersichtBrowser` vermisst den gemalten
  Endpunkt aus einem Bildschirmfoto (4 × 4 Pixel rund, vorher 16 × 10), führt
  den Zeiger über die Kachel und klappt die Dateisystemliste auf. Ob ein Segment
  der Länge null einen Punkt malt und ob `:has()` greift, sagt kein Go-Test.
- **Ein Zeilenumbruch in einem Zielpfad landete unverändert im Audit-Log.**
  Gefunden beim Angriffsdurchgang des Dateimanagers, betrifft aber jeden
  Aufrufer: `store.AppendAudit` macht Steuerzeichen und
  Schreibrichtungs-Umschalter jetzt als Escape-Folge sichtbar und begrenzt die
  Feldlänge auf 1024 Zeichen. Heute liegt das Log in SQLite, wo eine Spalte
  einen Zeilenumbruch verträgt — für das geplante zeilenweise Protokoll unter
  `/var/log/asylum/audit.log` wären aus einem Eintrag zwei geworden, und der
  zweite wäre frei erfunden.
- **Die Filterleiste ragte im schmalen Modus vier Pixel über den Rand.** Ihr
  negativer Randausgleich stand auf `-1rem`, der Innenabstand von `main`
  unterhalb von 900 Pixeln aber auf `0,75rem`. Betroffen waren Dienste, Logs und
  die neue Dateiseite; der Seitenkörper ließ sich dadurch waagerecht scrollen.
- **Die Passkey-Zeile im Konto schob die Seite bei 375 Pixeln um 48 Pixel nach
  rechts.** Textfeld und zwei Knöpfe in einer Aktionszelle mit
  `flex-wrap: nowrap`. Im Kartenmodus darf sie jetzt umbrechen; das `nowrap`
  gilt der Tabellenansicht, in der ein Umbruch die Zeilenhöhen springen lässt.
- Beides gefunden, weil die neue Seite mit einem echten Browser über alle elf
  Seiten bei 375, 414, 768 und 1280 Pixeln gemessen wurde. Keine Seite scrollt
  jetzt bei einer dieser Breiten waagerecht.
- **Zwei Zertifikatsbezüge konnten sich überlagern.** Der Knopf „Jetzt beziehen"
  und die Erneuerung im Hintergrund laufen in verschiedenen Goroutinen und
  schrieben ohne Absprache in dasselbe Verzeichnis. Wahrscheinlich war das nicht
  — zwischen zwei selbsttätigen Erneuerungen liegen rund 60 Tage —, aber ein
  halb überschriebenes Schlüsselpaar wäre ein Fehler gewesen, den niemand
  reproduzieren kann. Beide teilen sich jetzt eine Sperre; nachgewiesen mit
  einem Test, der ohne sie zuverlässig scheitert.
- **Eine Domainänderung wirkte bis zu 60 Tage nicht.** Der ACME-Manager sah nur
  auf die Restlaufzeit des abgelegten Zertifikats, nicht darauf, ob es die
  eingestellten Namen abdeckt. Wer die Domain änderte — seit der
  Zertifikatsseite ein Klick — bekam weiter das alte Zertifikat ausgeliefert,
  und der Browser warnte zu Recht. Die Ursache wäre für niemanden erkennbar
  gewesen: Die Oberfläche zeigte den neuen Namen, ausgeliefert wurde der alte.
- **Eine leere `config.yaml` verhinderte den Start** mit der Meldung `EOF`.
  Eine leere Datei bedeutet jetzt: bei den Vorgaben bleiben.
- **Das Kästchen „Port 80 öffnen" ist entfallen.** `http01.open_firewall` ist
  vorgesehen, wird aber von nichts ausgewertet — eine Bedienmöglichkeit ohne
  Wirkung sieht aus wie eine Zusage.

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
