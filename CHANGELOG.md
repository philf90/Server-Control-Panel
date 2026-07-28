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
