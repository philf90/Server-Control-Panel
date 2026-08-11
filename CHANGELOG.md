# Changelog

Die Vorgeschichte dieses Repositorys — das Server-Panel „Asylum" bis 0.6.2 —
steht unter dem Tag `v0.6.2` und im Branch `legacy/asylum`. SrvPanel ist ein
anderes Produkt und zählt neu.

## [Unbereinigt]

### P0 — Fundament und Werkzeug

- **Repo-Übergang.** Go-Code, die Svelte-Oberfläche und die Dokumente 01–18 des
  Vorgängers sind entfernt; erhalten bleiben die Sprachvorgabe (`docs/19`), der
  Plan (`docs/20`) und das Signaturmaterial. Die Lizenz ist von Apache-2.0 auf
  **AGPL-3.0-only** gewechselt.
- **Agent (`srvpanel-agentd`).** Framework- und abhängigkeitsfreies PHP-CLI als
  einziger Prozess mit Systemrechten. Unix-Socket mit 0660, Aufruferprüfung
  über SCM_CREDENTIALS, NDJSON-Protokoll, typisierte Operationen,
  Programm-Positivliste mit absoluten Pfaden, feste Umgebung, Zeitlimit,
  gedeckelte Ausgabe, eigenes Protokoll mit der tatsächlich ausgeführten
  Kommandozeile. Operationen: `agent.ping`, `system.info`, `service.status`,
  `config.validate`.
- **Anwendung.** Laravel mit Inertia und Vue 3, Gestaltungssystem „Leitstand"
  in beiden Themes und beiden Dichtestufen, Adminübersicht mit
  Verlaufskacheln, Gesundheitsendpunkt für die Bereitschaftsprüfung.
- **Kennzahlen.** Ringpuffer fester Größe je Kennzahl, Sammler im
  Zehnsekundentakt als eigene Unit.
- **Paketierung.** `.deb` über nfpm mit `/opt/srvpanel/releases/<version>` und
  Symlink-Umschaltung, vier systemd-Units, Installer mit
  Vorbedingungsprüfung, Instandhaltungsskripte.
- **CI.** Statische Prüfung, Tests, Oberfläche, Shellcheck, Lieferkette — und
  neu ein Integrationslauf, der das gebaute Paket unter echtem systemd auf
  Debian 12/13 und Ubuntu 22.04/24.04 installiert.

### P0 abgeschlossen

- **Ersteinrichtung `srvpanel setup`** — legt über den Agenten Datenbank,
  Datenbankbenutzer, Anwendungsschlüssel, selbstsigniertes Zertifikat und den
  nginx-Server-Block an und startet die Dienste. Wiederholbar: Ein zweiter Lauf
  wechselt keinen Schlüssel und wirft keine Datenbank weg.
- **Geheimnisse überqueren den Socket nie.** Datenbankpasswort und `APP_KEY`
  entstehen im Agenten und werden von dort nach `/etc/srvpanel/panel.env`
  geschrieben (0640 root:srvpanel). Die Datei liegt bewusst außerhalb des
  Auslieferungsverzeichnisses — dieses wird bei jedem Update ersetzt, und mit
  einer `.env` darin wäre nach dem ersten Update der Schlüssel weg, mit dem
  alle verschlüsselten Werte in der Datenbank lesbar sind.
- **Update mit Rückweg.** `srvpanel update` setzt den Lauf als transiente
  systemd-Unit ab, damit er den Neustart des Panels überlebt. Das
  postinstall-Skript prüft danach über HTTPS, ob das Panel antwortet — sonst
  zeigt der Symlink wieder auf die vorige Fassung und die Dienste laufen mit
  ihr weiter. Bis dahin war das ein Kommentar, der eine Zusage machte, die der
  Code nicht einlöste.
- **Der Schreibbereich liegt außerhalb der Fassung.** `storage` ist ein Verweis
  nach `/var/lib/srvpanel/storage`. Solange das Fassungsverzeichnis wörtlich
  `${VERSION}` hieß und sich nie änderte, war das ohne Folgen; mit dem richtigen
  Namen wäre der Schreibbereich bei jedem Update neu gewesen — das Protokoll des
  Panels finge von vorn an, und alles, was ab P2 dort landet, wäre fort. Das
  postinstall-Skript räumt außerdem Fassungen ab, die nicht mehr in Gebrauch
  sind: dpkg entfernt beim Update nur seine eigenen Dateien, und was zur
  Laufzeit entstand, hielt das alte Verzeichnis am Leben.
- **Fünf neue Operationen im Agenten**: `service.action` (mit eigener, enger
  Unit-Liste — Zustand lesen ist harmlos, eine beliebige Unit stoppen nicht),
  `panel.provision`, `panel.tls.ensure`, `panel.vhost.apply` (Vorlage im
  Agenten, `nginx -t` vor der Übernahme, Rückweg bei Ablehnung) und
  `panel.update`.
- **`/etc/srvpanel/fpm.conf`** — eigener PHP-FPM-Pool für die Oberfläche, mit
  `open_basedir` auf die Verzeichnisse des Panels und ohne `exec`, `system`
  und Verwandte: Die Anwendung kann keinen Prozess starten, sie hat dafür den
  Agenten.
- **`packaging/testbed.sh`** — dieselbe Wegwerf-Maschine wie in der CI, aber
  von Hand in zwei Minuten statt in Zwanzig-Minuten-Schritten.

### P1 — Mandanten, Anmeldung, Vorgänge

Sechs Schritte, und am Ende ein Test, der das Abnahmekriterium der Stufe am
Stück durchläuft: Ein Admin legt einen Kunden an, dieser meldet sich an, sieht
seine leere Übersicht, der Admin liest auf seiner die Serverwerte — und kein
Kunde kommt an ein fremdes Objekt.

- **Datenmodell und Mandantenklammer.** Kunde → Abonnement → Objekte, dazu die
  Konten. **Der Grundzustand der Klammer ist „nichts"** — nicht „alles" und
  nicht „das erste Abonnement". Ein Kommando, ein Job, ein neuer Controller:
  Solange niemand einen Mandanten gesetzt hat, liefert jede mandantengebundene
  Abfrage eine leere Menge. Der umgekehrte Grundzustand hätte die Eigenschaft,
  dass ein vergessener Aufruf erst auffällt, wenn der zweite Kunde dazukommt.
  Die Rechteprüfung läuft über eine Zugehörigkeitskette statt über einen festen
  Vergleich; heute ist sie einen Schritt lang, mit der Reseller-Ebene aus §5.4
  ändert sich eine Methode statt dreißig `where`-Bedingungen. Vorgänge heißen
  `operations` und nicht `jobs` — `jobs` ist bereits die Warteschlangentabelle
  von Laravel.
- **Anmeldung, Sitzungen, Ratenbegrenzung.** Argon2id statt bcrypt (§6.4); der
  Integrationslauf prüft das Verfahren auf allen vier Zielplattformen, weil es
  eine Eigenschaft des übersetzten PHP ist und nicht der Konfiguration. Eine
  einzige Fehlermeldung für unbekannte Adresse, falsches Passwort und
  deaktiviertes Konto — wer unterscheidet, macht aus dem Formular ein Werkzeug
  zum Sammeln von Kontonamen; auch für unbekannte Adressen wird gerechnet,
  sonst verrät die Antwortzeit, was die Meldung verschweigt. **Die
  Ratenbegrenzung hat eine Falle**, und deshalb zwei Zähler: Die IP-Sperre
  steigt bis zu einer Stunde, die Kontosperre ist bei fünf Minuten gedeckelt —
  sonst könnte jemand, der die Adresse des Betreibers kennt, ihn von beliebigen
  IPs aus dauerhaft aussperren, ohne je ein Passwort zu erraten. Dazu die
  absolute Sitzungsdauer, die Laravel nicht mitbringt: zwölf Stunden,
  abschaltbar. `srvpanel:admin` nimmt das Passwort nicht als Argument
  entgegen — es stünde in der Shell-Historie und in `ps`.
- **Zweiter Faktor mit TOTP und Wiederherstellungscodes.** Zwischen Passwort
  und zweitem Faktor ist niemand angemeldet: Das wartende Konto steht in der
  Sitzung, nicht im Anmeldezustand. Ein angenommener Zeitschritt wird am Konto
  vermerkt — das Fenster ist neunzig Sekunden breit, weil es ungenaue Uhren
  abfangen muss, und ohne diesen Vermerk hätte jemand, der einen Code mitliest,
  anderthalb Minuten Zeit. TOTP ohne Bibliothek, weil der RFC Testvektoren
  liefert und sich die Umsetzung damit gegen den Standard belegen lässt statt
  nur gegen sich selbst. Wiederherstellungscodes als SHA-256-Hashes: Argon2id
  wäre hier das falsche Verfahren — die Codes tragen rund fünfzig Bit Entropie,
  und acht Vergleiche zu je 64 MiB je Anmeldeversuch wären ein bequemer Weg,
  den Server lahmzulegen. Für Administratoren ist der zweite Faktor
  verpflichtend (§6.4). Passkeys bleiben offen (§15 Punkt 9).
- **Policies und die mechanische Routenprüfung.** **Kein `Gate::before` für
  Admins:** Die eine Zeile im Provider beantwortet auch Fragen, die es gar nicht
  gibt — ein vertippter Fähigkeitsname liefert weiterhin `true`, und der Fehler
  zeigt sich ausschließlich bei Kunden. Die Adminzeile steht deshalb in jeder
  Policy einzeln. Die Routenprüfung hat drei Richtungen: Jede Route trägt eine
  Policy oder steht mit Begründung in der Registratur; jede Eintragung muss zu
  einer Route gehören, die es noch gibt; und die Eintragung muss stimmen — was
  als „nur mit Anmeldung" deklariert ist, muss `auth` tragen. Alle drei
  Richtungen wurden absichtlich gebrochen und melden sich; eine mechanische
  Kontrolle, die noch nie fehlgeschlagen ist, ist eine Behauptung. Dabei fielen
  zwei Routen des Frameworks auf, an die niemand gedacht hatte — `GET` und
  `PUT` auf `storage/{path}`, geschützt über signierte URLs. Genau dafür ist
  die Registratur da.
- **Vorgänge: Warteschlange, Zustände, Live-Ausgabe über SSE.** **Die
  Modellbindung lief bisher vor der Mandantenklammer** — ein Kunde mit einer
  fremden ID in der Adresse bekäme das Objekt gebunden, und aus „nicht
  gefunden" würde „verboten"; damit ließe sich abzählen, welche IDs es gibt.
  Sie sitzt jetzt hinter `ApplyTenancy`, ein Test hält die Reihenfolge fest.
  SSE statt WebSocket: eine Richtung, kein zweiter Dienst, kein eigener Port.
  Der Preis ist ein belegter FPM-Arbeiter je offener Verbindung, deshalb endet
  der Strom nach fünf Minuten von selbst und kündigt vorher `reconnect` an —
  ohne diese Grenze könnten ein paar vergessene Browserreiter das Panel für
  alle unerreichbar machen. Wiederaufnahme über `Last-Event-ID`, damit „auch
  nach Seitenwechsel" nicht heißt, dass die Ausgabe von vorn beginnt. Kein
  automatischer zweiter Anlauf im Arbeiter: Ein Wiederholungslauf nach einem
  Abbruch mitten in einer Paketinstallation täte dasselbe noch einmal auf einem
  halb veränderten Zustand.
- **Aufgabenkatalog und Vorgangsfläche.** **Der Browser schickt einen
  Schlüssel, keine Anweisung.** Nähme der Steuerungscode `type` und `payload`
  aus dem Formular, wäre das Panel eine Fernsteuerung für beliebige Operationen
  und die Positivliste im Agenten die einzige verbliebene Schranke. Sie ist
  gut, aber sie darf nicht die einzige sein: `App\Support\Operations\Task` ist
  ein Katalog im Quelltext, die Argumente entstehen erst dort, kein Wert aus
  der Anfrage erreicht den Agenten.
- **Abbrechen, das den Prozess auch wirklich beendet.** Ein Abbruch, der nur
  die Ausgabe abschaltet, sähe in der Oberfläche genauso aus wie einer, der
  wirkt. Dieser geht durch alle drei Prozesse: Der Knopf setzt
  `cancel_requested_at` und nicht den Zustand — wer sofort „abgebrochen"
  schriebe, schriebe eine Behauptung über einen fremden Prozess. Der Arbeiter
  fragt beim Warten auf die nächste Antwort nach und schließt die Verbindung;
  `Client::call` wartet dafür in Sekundenschritten statt am Stück, sonst wäre
  ein Programm, das schweigend zehn Minuten läuft, zehn Minuten lang nicht zu
  stoppen. Der Agent bemerkt das geschlossene Ende mit `MSG_PEEK` und beendet
  das Kind wie beim Zeitlimit, erst SIGTERM, dann SIGKILL.
- **Protokoll: Ansicht, Filter und Export.** **Beide gehen durch dieselbe
  Sichtbarkeit.** Der naheliegende Fehler bei einem Export ist eine sorgfältig
  gefilterte Liste und eine Datei, die „schnell noch" mit einer eigenen Abfrage
  gebaut wurde — sie fällt niemandem auf, weil beide für den Betreiber gleich
  aussehen, und liefert einem Kunden alles. Die Sichtbarkeit ist zweimal
  formuliert, als Abfrage und als Policy; ein Test vergleicht sie Ereignis für
  Ereignis. Der Export händigt keiner Tabellenkalkulation eine Formel aus
  (führendes `=`, `+`, `-`, `@`, Tabulator) und ist gestreamt und gedeckelt,
  aber nicht stillschweigend: Wird die Grenze erreicht, sagt die letzte Zeile
  der Datei das.
- **Kunden, Navigation, „Anmelden als".** Die Kundenübersicht bekommt die
  Serverwerte nicht ausgeblendet, sondern gar nicht erst — eine gemeinsame
  Seite mit `v-if` schickte Rechnername, Kernel und Dienstzustände an jeden
  Browser und zeigte sie dort nur nicht an. Kunde und Anmeldekonto entstehen
  zusammen in einer Transaktion. „Anmelden als" (§6.3) mit vier Zusagen: kein
  Passwortzugriff, kein stiller Wechsel (beide Richtungen und alles dazwischen
  im Protokoll, mit dem Admin als handelnder Person und dem Kundenkonto als
  Kontext), ein nicht wegklickbares Band mit dem Rückweg bei sich, und ein
  Rückweg, der auch dann funktioniert, wenn das Adminkonto inzwischen gesperrt
  wurde. Kein Wechsel aus einem Wechsel heraus.
- **Kennzahlen: Netz, IO, Dateisysteme, Prozesse** (§4.6). Gerechnet wird mit
  der tatsächlich vergangenen Zeit und nicht mit dem eingestellten Takt — ein
  Dienst, der ins Stocken gerät, zeigte sonst das Doppelte. Ein rückwärts
  gelaufener Zähler ergibt keine Rate statt einer erfundenen, und die erste
  Messung ebenfalls nicht: Sonst stünde der gesamte Verkehr seit dem
  Systemstart als eine Sekunde in der Kurve. Die Prozessliste ordnet nach
  Speicher und nicht nach CPU — eine CPU-Angabe je Prozess bräuchte zwei
  Messungen und damit Zustand im Agenten, der bewusst keinen führt.
  `SystemInfo` wird gegen ein erfundenes `/proc` geprüft: Ein Test gegen das
  echte kennt die Antwort nur von derselben Stelle wie der Code.
- **Statische Prüfung wieder mit Aussage.** Mit dem Datenmodell meldete PHPStan
  auf einen Schlag 100 Fehler, alle vom selben Muster — `$model->id` als
  „undefined property". Larastan erledigt 55 davon; die übrigen 45 waren eine
  Lücke in dem, was der Code über sich selbst aussagt: `@property`-Blöcke für
  sechs Modelle, Generics an jeder Beziehung, Wertetypen in Arrays. Kein
  einziger war ein Laufzeitfehler — das ist kein Argument dafür, sie zu
  unterdrücken: Eine Prüfung, die man mit Ausnahmen ruhigstellt, sagt beim
  nächsten Mal nichts mehr. Dabei fand sie in
  `Customer::descendantIdsIncludingSelf` eine Abbruchbedingung, die nie
  zutreffen konnte — sie sah nach Sorgfalt aus und war toter Code.

### P2 — die Systemseite eines Abonnements

- **Abonnements im Panel** (`docs/26`). Die Bedienung zu den vier Operationen:
  anlegen, sperren, entsperren, zurückbauen — jede als Vorgang mit sichtbarem
  Verlauf. **Der Zustand folgt dem System und nicht der Absicht:** Er wird
  gesetzt, nachdem der Agent geantwortet hat, nicht beim Klick. Sonst stünde in
  der Liste „gesperrt", während das Abonnement weiter ausliefert — und genau in
  die Liste schaut man ja. Daraus folgt der Zustand „wird angelegt" für die
  Zeit, in der es die Zeile schon gibt und den Systembenutzer noch nicht. Der
  Name wird mit **der Funktion des Agenten selbst** geprüft und nicht mit einer
  zweiten Formulierung derselben Regel. Der Systembenutzer wird vergeben, nicht
  gewählt, und bleibt nach einem Rückbau verbraucht — sonst erbte ein neuer
  Kunde alles, was auf dem Dateisystem noch der alten UID gehört.
- **Die Mandantenklammer am Abonnement selbst.** Im Modell stand, die
  Sichtbarkeit regele die Policy. Das war zu wenig, und es fiel mit der ersten
  Liste auf: Eine Policy entscheidet über *ein* Objekt, `Subscription::query()`
  fragt sie nie — ein Kunde sah jedes Abonnement des Servers. Es trägt jetzt
  dieselbe Klammer wie alles andere, nur auf den eigenen Schlüssel. Dabei kam
  die Gegenrichtung mit heraus: `PlanPolicy::view()` filterte danach doppelt und
  hing damit davon ab, was vor ihr lief. Eine Policy muss aus sich heraus
  antworten.
- **`subscription.provision`** legt Gruppe, Systembenutzer, das
  Verzeichnisschema aus §4.5 und die Dateisystem-Quota an. **Die Operation
  nimmt keinen Pfad entgegen — sie baut ihn.** Übergeben wird der Name des
  Abonnements, geprüft gegen eine Positivliste; der Pfad entsteht im Agenten.
  Damit gibt es kein `..`, keinen Symlink und keinen absoluten Pfad, den ein
  Aufrufer unterschieben könnte. Der Benutzername muss `p` und vier bis neun
  Ziffern sein — sonst wäre er ein Weg, über `useradd`/`usermod` ein
  bestehendes Konto zu berühren und über `setquota` ein fremdes Kontingent zu
  setzen. Wurzel `root`, Inhalt Kunde: OpenSSH verweigert ein Chroot, dessen
  Wurzel dem eingesperrten Benutzer gehört, und zwar wortlos beim
  Verbindungsaufbau. `useradd --no-user-group` mit eigener Gruppe zuvor, sonst
  landet der Benutzer je nach Distribution in einer gleichnamigen Gruppe oder
  in `users` — und dann sähe jedes Abonnement die Dateien jedes anderen.
- **`subscription.remove`** — die gefährlichste Operation im Projekt: Sie
  löscht als root einen Verzeichnisbaum. Vier Schranken: Der Pfad wird gebaut;
  er muss nach `realpath` derselbe sein; die Wurzel aller Abonnements ist
  ausdrücklich ausgeschlossen; und beim Absteigen wird keinem Symlink gefolgt
  — der Kunde besitzt `httpdocs` und kann darin `ausbruch -> /etc` anlegen.
  Die Reihenfolge ist der Rest der Arbeit: Prozesse beenden (sonst scheitert
  `userdel`), dann Quota (danach gibt es den Namen nicht mehr, und der Eintrag
  bliebe unter der nackten UID stehen — ein späteres Abonnement mit derselben
  UID erbte das Kontingent), dann der Baum, dann das Konto samt `groupdel`.
  **Keine Sicherung in dieser Operation:** Eine, die sichert *und* löscht,
  sichert im Fehlerfall vielleicht nicht und löscht trotzdem. Die Tests laufen
  gegen ein echtes Dateisystem und nicht gegen einen Doppelgänger — der
  beantwortet nur, ob man sich den Ablauf richtig vorstellt.
- **`subscription.suspend` und `.resume`** — **ein Schalter, nicht viele.** Der
  naheliegende Weg wäre, jede Domain einzeln abzuschalten; ein
  Sperrmechanismus, der bei jeder Ausbaustufe eine Stelle mehr anfassen muss,
  vergisst irgendwann eine, und dann ist ein Abonnement gesperrt und liefert
  trotzdem aus. Der Schalter sitzt am x-Bit für „andere" an der Chroot-Wurzel:
  Fällt es weg, kommt kein Webserver-Prozess mehr hinein, unabhängig davon,
  wie viele Domains darunter hängen. Dazu das Konto — `--lock` allein hindert
  niemanden, der sich mit einem Schlüssel anmeldet, `--expiredate 1` ist die
  Schranke, die SSH und SFTP prüfen; beim Freigeben ein leeres `--expiredate`
  und nicht `0`, denn null ist der 1. Januar 1970 und damit weiterhin
  abgelaufen. Und die laufenden Prozesse, weil der Kernel das Zugriffsbit beim
  Öffnen prüft und nicht bei jedem Lesen.
- **Pläne und Kontingente** (`docs/23`). Die Kontingente liegen weiter als
  JSON — ein Abonnement übersteuert einzelne Werte und muss „nicht gesetzt"
  von „auf 0 gesetzt" unterscheiden können. Die **Schlüssel** stehen jetzt in
  `App\Support\Plans\Quota` und `Feature`, mit Beschriftung, Hinweis, Einheit,
  Grenzen und Vorgabewert; vorher waren sie Literale an vier Stellen, und ein
  Tippfehler darin liefert kein Kontingent — was aussieht wie unbegrenzt. Die
  Zuordnung Freigabe → Recht ist aus der `SubscriptionPolicy` in
  `Feature::permission()` gewandert, in die Richtung, in der ein Tippfehler
  beim Übersetzen scheitert. Das Formular kennt kein Kontingent beim Namen; es
  rendert den Katalog. **Speicherplatz und FPM-Prozesse dürfen nicht
  unbegrenzt sein** — beide teilen eine Ressource, die der ganze Server teilt.
  Es gibt genau einen Standardplan: Das Setzen nimmt ihn dem bisherigen, das
  Abwählen tut nichts, beim Löschen rückt der älteste nach, und `srvpanel
  setup` legt einen an, wenn keiner da ist. Eine Planänderung wirkt sofort auf
  alle gebundenen Abonnements — die Zahl steht in der Liste und über dem
  Formular —, senkt aber nichts rückwirkend weg: Gesenkte Grenzen verbieten
  das nächste Objekt, sie löschen keines.
- **Der belegte Speicher wird gemessen** (`docs/26 §8`). `subscription.usage`
  liest die Dateisystem-Quota — **ein Aufruf für alle Abonnements und nicht
  einer je Abonnement**: `repquota` liest die Quota-Datei einmal und kennt
  danach jeden Benutzer darin. Herausgegeben wird nur, was der Form `p` plus
  vier bis neun Ziffern entspricht; `root` und `www-data` stehen in derselben
  Ausgabe, und eine Operation, die die Benutzerliste des Servers ausliefert,
  wäre eine Auskunft, die niemand bestellt hat. **Messen ist kein Vorgang** —
  niemand löst es aus, es ändert nichts, und es liefe alle fünfzehn Minuten
  durch die Vorgangsliste jedes Kunden; der Aufruf geht direkt an den Agenten,
  gestartet von `srvpanel-usage.timer`. Abgelegt werden **zwei** Werte: die
  Zahl und der Zeitpunkt. Ohne den zweiten sähe eine Messung von vor drei Tagen
  aus wie eine von vorhin. Ohne `usrquota` auf dem Mount wird nichts
  zurückgesetzt — „nichts Neues" ist kein Grund, die Messung von gestern zu
  verwerfen.
- **Kontingente am Abonnement übersteuern** (`docs/26 §9`). Das Datenmodell
  konnte es längst, ein Formular gab es nicht. Ein Kontingent hat dort zwei
  Zustände und nicht einen Wert: „gilt der Plan" ist etwas anderes als „gilt
  zufällig derselbe Wert". **Was fehlt, bleibt weg** — die Felder mit
  Vorgabewerten aufzufüllen wäre eine stille Loslösung vom Plan, und ein
  Abonnement, das jedes Kontingent übersteuert, erreicht keine Planänderung
  mehr. Nur `disk_mb` erreicht das System, und nur wenn sich der *wirksame*
  Wert ändert. Dafür gibt es **`subscription.quota`** statt eines zweiten
  `subscription.provision`: Provision rückt die Rechte der Chroot-Wurzel auf
  `0755` zurecht, und genau dieses Bit trägt die Sperre — ein gesperrtes
  Abonnement wäre nach einer Kontingentänderung wieder erreichbar gewesen,
  während im Panel weiter „gesperrt" stand. Ein gesperrtes Abonnement bekommt
  seinen Vorgang trotzdem: `usable()` wäre die falsche Frage gewesen, denn das
  Entsperren setzt keine Quota, und die neue Grenze käme sonst nie an.
- **Der Abnahmelauf für P2** (`docs/26 §10`): `srvpanel acceptance --count=100`
  legt an, baut zurück und sucht danach nach drei Sorten Rückstand —
  Systembenutzer **und Gruppe getrennt** (`userdel` entfernt eine
  nicht-primäre Gruppe nicht mit, und beim Anlegen steht `--no-user-group`),
  Verzeichnis, Quota-Eintrag. Der letzte ist der, den man ohne Werkzeug
  übersieht: kein Ort im Dateisystem, keine Zeile in /etc/passwd — und das
  nächste Abonnement mit derselben UID erbt eine fremde Grenze. Ein Kommando
  und kein Test, weil das Kriterium nach echten `useradd`-Aufrufen und der
  ganzen Kette bis unter systemd fragt; was ohne Server prüfbar ist — dass ein
  Rückstand jeder Art den Lauf durchfallen lässt —, steht als Test daneben.

### P2 abgeschlossen

Das Abnahmekriterium lautete: hundert Abonnements anlegen und wieder löschen,
ohne dass ein Systembenutzer, ein Verzeichnis oder ein Quota-Eintrag
zurückbleibt.

`srvpanel acceptance --count=100` ist am 4. August 2026 auf dem Server des
Betreibers gelaufen, aus dem Paket `0.2.0~rc.13`, und meldet: kein
Systembenutzer, keine Gruppe, kein Verzeichnis, kein Quota-Eintrag geblieben.
Geprüft ist damit nicht nur der Rückbau, sondern die ganze Kette Panel →
Warteschlange → Arbeiter → Agent unter echtem systemd — und zwar
zweihundertmal hintereinander.

**Offen bleibt aus P2 die Sicherung vor dem Rückbau.** Sie steht im Plan und
ist bewusst nach P8 verschoben: Eine Operation, die sichert *und* löscht,
sichert im Fehlerfall vielleicht nicht und löscht trotzdem — und ohne
Sicherungsziele, Aufbewahrung und einen Weg zurück wäre „Sicherung" nur ein
Verzeichnis daneben. Bis dahin ist der Rückbau endgültig, und die Rückfrage in
der Oberfläche sagt das.

### Hell und dunkel — jetzt wählbar

- **Das Theme lässt sich umschalten** (`docs/20 §7.2`). Beide Fassungen standen
  seit P1 fertig da und wurden von den Kontrastprüfungen abgedeckt — schalten
  konnte sie **niemand**: `data-theme` kam aus `SRVPANEL_THEME` in der `.env`,
  also serverweit für alle und nur für jemanden mit Zugriff auf die Datei. Der
  Plan beschrieb durchgehend das Gestaltungssystem und nie seine Bedienung; in
  keiner Stufe stand ein Umschalter. Dasselbe Muster wie beim Zurückziehen
  eines Kunden und bei `CustomerStatus::Suspended`. Jetzt steht die Wahl unter
  „Mein Konto" — **für Admins und Kundenkonten**, denn der Kunde am hellen
  Bürobildschirm ist der Fall, für den das helle Theme überhaupt verlangt wird.
  Drei Zustände: System (die Vorgabe, folgt dem Betriebssystem und wechselt
  mit), Hell, Dunkel. Gespeichert am Konto und nicht im Browser: Ein Betreiber
  arbeitet an zwei Rechnern. `SRVPANEL_THEME` behält seine Aufgabe für die
  Seiten ohne Konto.
- **Ohne Passwortbestätigung, anders als der Rest der Seite.** Die Schranke
  dort schützt die Sitzung; eine Farbe ist kein Übernahmeweg, und eine
  Rückfrage nach dem Passwort für einen Umschalter erzieht dazu, es beiläufig
  einzutippen — genau das, was die Schranke verhindern soll. **Während
  „Anmelden als" gesperrt:** Die Wahl landete sonst am Konto des Kunden und
  stellte dessen Oberfläche um.
- **Zwei Fallen, beide erst im Browser aufgefallen.** Das falsche Theme blitzte
  auf, weil der Server „folge dem Betriebssystem" nicht auflösen kann und Vite
  sein Bündel mit `defer` lädt — die Abfrage steht jetzt als Skript im Kopf,
  vor dem Bündel. Und der Klick tat sichtbar **gar nichts**: `data-theme` steht
  am `<html>`, und dieses Gerüst rendert Inertia bei einer Navigation nie neu.
  Gespeichert wurde richtig, die Bestätigung kam, zu sehen war nichts. Beides
  hält `ThemeTest` fest, weil beides reissen kann, ohne dass etwas bricht.
- **„Mein Konto" zeigte jede Erfolgsmeldung doppelt.** Als das Meldungsband ins
  Gerüst zog, blieb dort eine eigene Fassung stehen. Aufgefallen ist es, weil
  mit der Themewahl eine dritte Meldung dazukam — zwei richtige Meldungen sehen
  nicht falsch aus, nur doppelt. `InertiaPagesTest` prüft jetzt, dass keine
  Seite `flash.success` selbst anfasst.

### Das Zeichen des Panels

- **SrvPanel hat ein Icon** (`docs/20 §7.2`). Es hatte keines: In der
  Seitenleiste stand ein Buchstabe in einem amberfarbenen Quadrat, und
  `public/favicon.ico` lag mit **null Byte** da — der Platzhalter aus dem
  Laravel-Gerüst. Im Kopf der Seite stand kein einziges `<link rel="icon">`.
  Der Reiter im Browser trug damit das leere Blatt, und **das ist ein Fehler,
  der sich als Vorgabe tarnt:** Ein leeres Zeichen sieht aus wie gar keines,
  deshalb meldet es niemand. Jetzt liegen `.ico` (16/32/48), SVG,
  Apple-Touch-Icon und ein Manifest in `public/`; das Manifest führt nur Name
  und Zeichen und kein `display`, damit daraus keine App-Installation wird.
- **In der Oberfläche steht das Zeichen als Quelltext.** Ein `<img>` wäre ein
  zweiter Aufruf für drei Rechtecke — und könnte seine Farbe nicht erben. Das
  Panel hat zwei Themes; geliefert wurde das Zeichen deshalb in drei Fassungen,
  und drei Dateien in der Oberfläche hiessen, an jeder Stelle die richtige
  auszuwählen und irgendwann die falsche. `MarkIcon.vue` löst das einmal: die
  unteren Balken über `currentColor`, der obere über die neue Marke
  `--mark-accent`, die je Theme den passenden Blauton führt. **Einfarbig ging
  nicht** — das ganze Zeichen in Amber machte aus dem untersten Balken, der auf
  halber Deckung steht, ein schmutziges Braun. Gesehen im Browser bei 22 px,
  nicht im Entwurf. Reiter und Seitenleiste zeigen jetzt dasselbe, und darum
  geht es: Wer mehrere Panels offen hat, unterscheidet sie am Reiter.

### Gefunden auf dem Server

- **Der vollständige Rechnername fehlte im Zertifikat.** Der subjectAltName
  bekam den Knotennamen aus `php_uname('n')` — und der ist auf den meisten
  Servern der kurze: `cloudsrv24` statt `cloudsrv24.de`. Aus ihm wurde
  ausserdem noch eine Kurzform *abgeleitet*, also die falsche Richtung. Wer
  das Panel unter seinem vollen Namen aufruft, bekam eine Warnung über einen
  Namen, der nicht passt. **Dieselbe Lektion gab es schon einmal:** Bei der
  Ersteinrichtung zeigte der Link am Ende auf den kurzen Namen, und dort steht
  seit dem ersten Lauf auf einem echten Server ein Kommentar mit genau diesem
  Beispiel — eine Regel, die an einer Stelle gelernt und an der nächsten neu
  erfunden wurde. Sie steht jetzt in `Names::fqdn()`: Knotenname mit Punkt,
  sonst `/etc/hosts`, sonst die Rückwärtsauflösung — und ein gefundener Name
  zählt nur, wenn er den Knotennamen fortsetzt, damit weder eine fremde Zeile
  noch ein Namensdienst einen beliebigen Namen in dieses Zertifikat schreibt.
  Die Ersteinrichtung fragt dieselbe Funktion, statt ihre eigene Fassung zu
  behalten.
- **Der Rand eines Knopfes war nicht zu sehen.** `.knopf` stand auf `--surface`
  mit einem Rand aus `--line`; im dunklen Theme sind das #111922 und #141d26
  und damit ein Kontrast von **1,04:1**. „Bearbeiten" und „Anmelden als" waren
  auf dem Bildschirm keine Bedienelemente, sondern etwas hellere Flecken, die
  man für Text hält. Im Quelltext fiel das nicht auf, weil dort jeder Wert
  einen Namen hat und deshalb richtig aussieht. Der Knopf hat jetzt eigene
  Marken, `--button-bg` und `--button-line`, und sie sind gerechnet: WCAG
  1.4.11 verlangt für die Grenze eines Bedienelements 3:1 gegen alles, was
  daneben liegt — erreicht werden 3,3:1 gegen die eigene Fläche, 3,7:1 gegen
  die Karte und 4,0:1 gegen den Seitengrund, im hellen Theme 3,6:1 und 3,3:1.
  `ButtonStyleTest` rechnet das nach, in beiden Themes und gegen alle drei
  Gründe; geprüft wird die Rechnung und nicht der Wert, damit die Reihe
  umstimmbar bleibt.
- **HSTS sperrte den Betreiber aus** (`docs/27 §7`). Der Server-Block setzte
  `Strict-Transport-Security: max-age=31536000` bedingungslos, und die
  Begründung dafür war, Browser verwürfen den Header über eine nicht vertraute
  Verbindung. Das stimmt genau so lange, wie niemand das selbstsignierte
  Zertifikat in seinen Speicher aufnimmt — und dazu ist es ja da. Danach ist
  die Verbindung vertraut, der Header wird gespeichert, und ab da lässt sich
  auf diesem Host **kein Zertifikatsfehler mehr wegklicken**: kein „trotzdem
  fortfahren", keine Ausnahme. Das nächste neu ausgestellte Zertifikat sperrte
  den Betreiber aus seinem eigenen Panel; der Ausweg war ein Inkognitofenster.
  Ein Jahr Erzwingung zu versprechen, während sich das Zertifikat jederzeit
  ändern darf, ist kein Härtungsgewinn, sondern eine Zusage, die das Panel
  nicht halten kann. `panel.vhost.apply` liest jetzt das Zertifikat, bevor es
  den Block schreibt: selbstsigniert heisst kein HSTS, und im Block steht als
  Kommentar, warum die Zeile fehlt. **Unlesbar zählt als selbstsigniert** — wer
  aus einem Zertifikat, das er nicht lesen kann, auf eine Zertifizierungsstelle
  schliesst, verspricht das Jahr auf Verdacht. Mit dem ersten Zertifikat von
  Let's Encrypt kommt der Header von selbst zurück. **Einen bereits
  gespeicherten Eintrag löscht das nicht:** Der Header ist eine Anweisung an
  den Browser, und die muss dort gelöscht werden (Chrome:
  `chrome://net-internals/#hsts`).
- **Der Versionsstand steht auf der Anmeldemaske.** Sie ist die einzige Seite
  ohne Sitzung und damit nach einem Update der erste Beleg dafür, dass das neue
  Paket auch ausgeliefert wird. Das ist eine **bewusste Ausnahme** von „hier
  erfährt niemand etwas über diesen Server": Man gibt damit preis, welche
  bekannten Lücken auf diese Fassung zutreffen. Vertretbar, weil das Panel
  nicht im offenen Netz auf 443 steht, sondern auf einem eigenen Port hinter
  einer Anmeldung mit zweitem Faktor. Der Test grenzt die Ausnahme ein und
  hält fest, was ausdrücklich nicht dazugehört — sonst findet der nächste hier
  einen Präzedenzfall. Die Marke `.version` steht seitdem in `app.css` statt
  zweimal im Quelltext.
- **Bei den Abonnements fehlte der „Bearbeiten"-Knopf.** Dort war der Name die
  einzige Verbindung zur Bearbeitung, während Kunden und Pläne je Zeile einen
  Knopf haben — man musste wissen, dass der Name klickbar ist und was dahinter
  liegt. Wer eine Liste überfliegt, sucht einen Knopf und keinen Link. Der Name
  bleibt trotzdem ein Link: Er führt auf die Abo-Seite mit Speicher,
  Kontingenten und Vorgängen, und das ist etwas anderes als das Formular.

### Quer zu den Stufen

Nach der Abnahme von P1 nachgezogen; keines dieser Themen gehört einer
einzelnen Ausbaustufe.

- **Passwortrichtlinie an einer Stelle** (`docs/22`). Die Regel stand dreifach
  da: als `min:12` im Controller, als Längenprüfung im Kommando `srvpanel:admin`
  und als Satz unter dem Feld. Jetzt kommt alles aus
  `App\Support\Passwords\Policy` — Validierung, Kommandozeile und, über
  Inertia, die Prüfliste im Browser. `PasswordFields.vue` zeigt je Anforderung
  Haken oder Kreuz, dazu eine Stärkeschätzung, einen Knopf zum Erzeugen (im
  Browser, über `crypto.getRandomValues`) und ein Augensymbol. Bis dahin war
  „mindestens zwölf Zeichen" die ganze Richtlinie; `passwortpasswort` erfüllte
  sie.
- **Die Kundennummer vergibt der Server.** Sie ist der Bezeichner, unter dem der
  Kunde in Rechnungen und Verzeichnisnamen auftaucht — als freies Feld konnte
  sie doppelt vergeben werden oder einen Schrägstrich enthalten. Im Formular
  steht sie schreibgeschützt als Vorschau. Das Firmenfeld ist entfernt.
- **Kunden werden zurückgezogen, nicht gelöscht.** Ein `DELETE` gäbe die
  Kundennummer wieder frei, und der nächste Kunde bekäme sie — danach trügen
  zwei Vertragspartner in zwei Rechnungen dieselbe. Die Zeile bleibt mit
  `deleted_at` stehen, der eindeutige Index gilt weiter für sie, und die Vergabe
  fragt als einzige Stelle im Panel `withTrashed()`. Die Anmeldung weist Konten
  eines zurückgezogenen Kunden ab: Ohne das käme ein gekündigter Kunde weiter
  herein und sähe nichts — was wie ein Fehler aussieht und keine Kündigung ist.
  **Erreichbar wurde das erst im August 2026:** Die Mechanik war gebaut, die
  Policy stand da — es gab nur keine Route, keine Controllermethode und keinen
  Knopf. Ein Kunde liess sich ausschliesslich über die Datenbank zurückziehen.
  Jetzt steht „Zurückziehen" auf der Kundenseite, und der Versuch wird
  **abgewiesen, solange Abonnements laufen**: Sie mit zurückzubauen hiesse, aus
  diesem Knopf einen zu machen, der als Nebenwirkung Verzeichnisbäume als root
  löscht, während die Rückfrage davor von einem Kunden spricht. Zurückgebaute
  Abonnements zählen nicht mit — sonst liesse sich ein Kunde, der einmal eines
  hatte, nie wieder zurückziehen.
- **Einen Kunden sperren heisst, seine Abonnements zu sperren** (`docs/26 §11`).
  `CustomerStatus::Suspended` gab es von Anfang an und bedeutete nichts — es
  liess sich nirgends setzen. Jetzt gibt es „Sperren" und „Freigeben" auf der
  Kundenseite, und die Sperre nimmt mit, was der Kunde hat: **je Abonnement ein
  Vorgang**, nicht ein Sammelvorgang, weil „teilweise erfolgreich" bei zehn
  Abonnements keine Auskunft ist. **Die Freigabe ist die schwierigere Hälfte:**
  „alle gesperrten wieder an" wäre die naheliegende Umkehrung und wäre falsch —
  ein Abonnement, das der Betreiber vorher einzeln gesperrt hat, war nie Teil
  der Kundensperre, und am Zustand ist das nicht zu erkennen. Das Abonnement
  merkt sich deshalb in `suspended_with_customer`, zu welcher Sperre es gehört;
  wer eines einzeln sperrt, löscht die Kennzeichnung damit. Ein einzelnes
  Abonnement lässt sich nicht entsperren, solange der Kunde gesperrt ist —
  sonst liesse sich die Kundensperre von unten aushebeln. **Und für einen
  gesperrten Kunden lässt sich keines anlegen:** Es käme aktiv aus dem Anlegen
  heraus — die Kaskade sperrt, was es beim Klick gab —, und danach stünde beim
  Kunden „gesperrt" und darunter eine laufende Webseite. Im Formular steht ein
  gesperrter Kunde trotzdem in der Liste, abgeblendet und mit dem Grund
  daneben; wer ihn herausfiltert, lässt jemanden nach einem Kunden suchen, den
  er gestern angelegt hat. Die Anmeldung bleibt offen: Ein gesperrter Kunde
  soll sehen, warum nichts mehr geht.
- **Eine Willkommensseite im DocumentRoot** (`docs/26 §7`). Das
  Verzeichnisschema legte `httpdocs` an und schrieb nichts hinein — ein Kunde
  bekam ein leeres Verzeichnis. Sie entsteht **nur, solange das Verzeichnis
  leer ist**: Das ist die Bedingung dafür, dass `subscription.provision`
  wiederholbar bleiben darf, denn ein zweiter Lauf träfe sonst auf eine fertige
  Webseite und legte eine `index.html` daneben, die vor `index.php` gefunden
  wird. Sie nennt weder Abonnementnamen noch Systembenutzer noch das Panel —
  sobald eine Domain hierher zeigt, ist sie öffentlich —, lädt nichts von
  aussen und trägt `noindex`.
- **Die Übersicht zeigt den Bestand** (`docs/26 §12`). Sie zeigte
  ausschliesslich die Maschine: Auslastung, Dienste, Dateisysteme, Prozesse.
  Ein Betreiber öffnet sein Panel aber nicht, um zu erfahren, wie viel RAM
  belegt ist, sondern um zu sehen, ob mit dem, was er hostet, etwas nicht
  stimmt. Jetzt stehen dort Kunden und Abonnements nach Zustand — und die fünf
  Abonnements, die ihrer Speichergrenze am nächsten sind. **Die vollsten und
  nicht die grössten:** Eines mit 40 GB von 200 ist unauffällig, eines mit 4,8
  GB von 5 ist der Anruf von morgen. Ein Kunde bekommt diese Zahlen nicht
  ausgeblendet, sondern gar nicht erst erhoben.
- **Das Zertifikat der Oberfläche** (`docs/27`) — vorgezogen aus P4, ohne
  Let's Encrypt. Das selbstsignierte Zertifikat gab es seit P0; beim Nachsehen
  fielen zwei Mängel auf, die beide erst im Betrieb weh getan hätten. **Es
  trug keinen subjectAltName:** Der Name stand nur im CommonName, und den liest
  Chrome seit 2017 nicht mehr, Firefox und Safari ebenso wenig — der Browser
  meldete nicht „unbekannter Aussteller", sondern „der Name passt nicht", und
  auch die Aufnahme in den eigenen Zertifikatsspeicher half nicht. Dazu ruft
  man das Panel nach der Einrichtung über die **IP** auf, und die stand nirgends
  darin. Jetzt stehen Hostname, Kurzform, `localhost` und jede Adresse aller
  Schnittstellen darin — ohne die link-lokalen, die sich ändern und unter denen
  niemand ein Panel aufruft. **Und nichts erneuerte es:** `panel.tls.ensure`
  rief ausschliesslich `srvpanel setup` auf, die Prüfung auf Restlaufzeit lief
  also nie. `srvpanel-tls.timer` prüft jetzt täglich; erneuert wird ab 30 Tagen
  Restlaufzeit oder wenn der Rechner nicht mehr so heisst wie damals. Eine
  geänderte IP erneuert **nicht** — auf einem Server mit Docker gäbe das jede
  Woche ein neues Zertifikat samt neuer Warnung; die Seite im Panel zeigt statt
  dessen an, welche Adresse fehlt.
  Dazu drei kleinere Korrekturen am Zertifikat selbst: `CA:FALSE` statt einer
  Zertifizierungsstelle (ein selbstsigniertes Zertifikat, das eine CA sein
  darf, ist ein Generalschlüssel für jeden, der den privaten Schlüssel des
  Servers erbeutet), eine zufällige Seriennummer statt `0`, und 397 statt 825
  Tage Laufzeit. Nach einem Tausch wird nginx geprüft und neu geladen — vorher
  hätte der Webserver das alte Zertifikat weiter aus dem Speicher ausgeliefert,
  und eine Erneuerung, die nicht ankommt, ist schlimmer als keine.
  `/settings/tls` zeigt Name, Aussteller, Laufzeit und Namen und stellt auf
  Wunsch neu aus; das Nachsehen ist ausdrücklich **kein** Vorgang.
- **Das Gerüst der schmalen Fläche ist eine Spalte** (`docs/24 §4`). Es war
  unter 720px weiterhin ein Raster mit `auto 1fr` — Kopfzeile oben, Inhalt
  darunter —, und das ging, solange es zwei Kinder im Fluss gab. Beim Wechsel
  in die Sicht eines Kunden kommt das Band dazu: Dann rutscht die **Kopfzeile**
  in die `1fr`-Zeile und nimmt sich allen übrigen Platz. Auf einem Telefon mit
  844px Höhe war sie 591px hoch, zwischen Band und Seitentitel stand eine leere
  schwarze Fläche, und der Inhalt landete in einer Zeile, die es im Raster gar
  nicht gab. Eine dritte Zeile wäre die falsche Antwort gewesen — dann zählt
  man Kinder. Unter 720px gibt es eine Spalte, und eine Spalte hat nichts zu
  zählen. `MobileLayoutTest` lässt dort kein `grid-template-rows` mehr durch.
- **Die Erfolgsmeldung steht im Gerüst und nicht auf jeder Seite.** Sie kam
  bisher von drei Seiten selbst, der Rest warf sie weg — wer einen Kunden
  sperrte, bekam als einzige Rückmeldung einen anders beschrifteten Knopf,
  während der Controller „Ein Abonnement wird gesperrt — der Vorgang läuft"
  schickte. Dasselbe Muster wie bei den Knöpfen: eine Sache, die jede Seite
  einzeln richtig machen musste, und die meisten machten sie gar nicht.
- **Führt zu jeder Fähigkeit auch ein Weg?** (`PolicyReachTest`) Die
  Gegenrichtung zur Routenprüfung, und der Grund, aus dem die Lücke oben so
  lange stand: `RouteAuthorizationTest` prüft, dass jede Route eine Policy
  trägt — das ist die Richtung, in der ein Fehler gefährlich ist. Dass eine
  Policy-Fähigkeit von nirgendwo aus erreichbar war, prüfte nichts. Eine
  Fähigkeit ohne Weg ist kein Sicherheitsproblem, sondern eine Zusage im
  Quelltext, die es in der Anwendung nicht gibt: Wer sie liest, hält eine
  Funktion für vorhanden. Fünf Ausnahmen stehen mit Begründung in der Liste —
  darunter `AuditEventPolicy::update` und `::delete`, die grundsätzlich
  verweigern und für die eine Route gerade der Fehler wäre.
- **Mein Konto** (`/settings/profile`). Name, Anmeldeadresse und Passwort des
  eigenen Kontos — bis dahin liess sich das Adminkonto ausschliesslich über
  `srvpanel:admin` auf der Kommandozeile ändern, also nur von jemandem mit root
  auf dem Server. Jede Änderung verlangt das aktuelle Passwort, auch die des
  Namens; ein Passwortwechsel meldet alle anderen Sitzungen ab. **Während
  „Anmelden als" ist die Seite gesperrt** — ein Admin in fremder Sicht könnte
  sonst das Passwort eines Kunden setzen und sich einen dauerhaften Zugang
  verschaffen. Der abgewiesene Versuch steht im Protokoll.
- **Die Version steht in der Navigation**, als Marke unter dem Schriftzug. Die
  Fusszeile behält den Quelltextlink samt Version (Abschnitt 13 der AGPL); die
  Marke beantwortet etwas anderes, nämlich die erste Frage jedes Fehlerberichts.
- **Wortwahl wieder mechanisch geprüft** (`WordChoiceTest`). Die Vorgabe aus
  `docs/19` hatte einen Test, und der ist beim Repo-Übergang mit dem Go-Code
  verschwunden. Neun Monate später stand im Aufgabenkatalog „Fragt den Agenten
  nach seiner Fassung". Die Beschreibungen der Vorgänge nennen jetzt Operation,
  Unit und Wirkung.
- **Abstände zwischen den Bereichen** der Übersicht als Dichte-Marken. Die
  Abschnittsüberschriften standen ohne Abstand nach oben unter der vorigen
  Tabelle — jede war damit näher an dem, wozu sie nicht gehörte. Sie tragen
  jetzt außerdem die Behandlung, die §7.2 für Überschriften vorsieht, statt der
  für kleine Beschriftungen: Sie sahen sonst aus wie der Spaltenkopf zwölf
  Pixel darunter.
- **Keine Emoji in der Oberfläche** (`docs/19 §3a`). Das Augensymbol der
  Passwortfelder war 👁 beziehungsweise 🙈 — gezeichnet von der Schriftart des
  Betriebssystems, ohne Textfarbe, auf manchen Servern ein leeres Rechteck.
  Ersetzt durch `EyeIcon.vue`: ein SVG mit `currentColor`, eigene Geometrie,
  keine Icon-Bibliothek. Geprüft von `test_no_vue_template_uses_an_emoji`.
- **Schriftgrößen als Marken** (`docs/20 §7.2`). In den Komponenten standen zehn
  `rem`-Werte für fünf Rollen, dazu neun Literale in px. `rem` rechnet gegen das
  Wurzelelement (16px), die Grundgröße des Panels sind aber 13px — jeder Wert
  war 23 % größer als gemeint, `.85rem` für Tabellentext ergab 13,6px und war
  damit größer als der Fließtext, den er unterschreiten sollte. 150 Werte sind
  auf eine Skala aus fünf Stufen umgestellt; `DesignTokensTest` lässt weder
  `rem` noch ein Literal noch eine Marke ohne Wert durch.
- **Die Eingabe eines Bestätigungscodes** ist eine eigene Komponente. Sechs
  Ziffern sind kein Fließtext: Man liest sie von einem Telefon ab und
  vergleicht sie Ziffer für Ziffer mit dem, was im Feld steht — dafür müssen
  sie gleich breit sein und auseinander stehen. Dieselbe Komponente an allen
  drei Stellen, an denen ein Code abgefragt wird.
- **Drei Fremdfarben des Browsers abgestellt** (`docs/20 §7.2`), alle in
  `app.css` und nicht dort, wo sie zuerst auffielen: die Einfärbung selbst
  ausgefüllter Felder (ein kräftiges Blau, das auf dunklem Grund das ganze Feld
  verschluckt — `background` erreicht sie nicht, nur ein Schatten nach innen),
  das Blau der Ankreuzfelder und der Fokusrahmen an Eingabefeldern, den bis
  dahin nur die Anmeldemaske selbst gesetzt hatte.
- **Knöpfe kommen aus `app.css`** (`docs/20 §7.2`). Jede Seite brachte ihre
  eigenen mit — `8px 16px` hier, `6px 12px` dort, mal mit Rahmen, mal ohne —,
  und „Kunde anlegen" in der Kundenliste war überhaupt kein Knopf, sondern ein
  amberfarbener Link: auf dem Bildschirm eine Beschriftung, die zufällig
  anklickbar ist. Es gibt jetzt eine Form und drei Ränge (`.knopf`,
  `.wichtig`, `.gefahr`), dazu `.klein` für die Tabellenzeile und
  `.knopfreihe`, die unter 480 px stapelt. Sechzehn Seiten sind umgestellt und
  ihre eigenen Knopfregeln gelöscht. `ButtonStyleTest` lässt keine Seite mehr
  ihr eigenes Aussehen erfinden und kein Formular mit zwei Hauptsachen durch.
  Beim Nachmessen im Browser fiel auf, dass `.klein` auf 390 px zwei 23 px hohe
  Ziele nebeneinander ergab — unter 720 px bekommt es `--tap` zurück (`docs/24
  §2`), und ein Test hält das fest.
- **Kunden lassen sich bearbeiten.** In der Kundenübersicht führte kein Weg zu
  den Stammdaten eines angelegten Kunden: Sie liessen sich anlegen und ansehen,
  danach nur noch über die Datenbank ändern. „Bearbeiten" steht jetzt in der
  Zeile und auf der Kundenseite. Nicht änderbar bleiben die Kundennummer (sie
  steht in Rechnungen und Verzeichnisnamen), der Zustand (er bekommt eine eigene
  Aktion) und die Anmeldeadresse (sie gehört zum Konto, nicht zum
  Vertragspartner) — ein `login_email` im Formular fasst kein Konto an. Das
  Protokoll hält fest, **welche Felder** sich geändert haben, nicht ihren
  Inhalt.

### Berichtigt

Das Abnahmekriterium für P0 verlangte, dass man sich anmelden kann — Konten
gibt es aber erst in P1. Es war von Anfang an nicht erfüllbar. An seine Stelle
tritt, dass die Oberfläche nach der Einrichtung über HTTPS antwortet. Aus
demselben Grund gibt es in P0 keinen Einmal-Link: Er führte zu einem Raum ohne
Inhalt.

### Bezeichner auf Englisch

Dateien, Klassen, Methoden, Variablen, Konfigurations- und
Protokollschlüssel, CSS-Marken, Datenattribute und Job-Namen in der CI sind
englisch; Kommentare, Dokumentation und die Texte der Oberfläche bleiben
deutsch. Die Vorgabe steht in §2 des Plans.

Betroffen war die Schnittstelle mit: `Ergebnis`→`Result`, `Kontext`→`Context`,
`Verbindung`→`Connection`, `Ringpuffer`→`RingBuffer`, `Sammler`→`Collector`,
`Speicher`→`Store`; die Nutzdaten des Agenten (`vorhanden`→`present`,
`speicher`→`memory`, `pfad`→`path`, `art`→`kind`); die Schlüssel in
`/etc/srvpanel/agent.json` (`benutzer`→`user`, `pruefbare_wurzeln`→
`config_roots`); die Konfiguration (`srvpanel.kennzahlen.*`→`srvpanel.metrics.*`);
das Kommando `srvpanel:kennzahlen`→`srvpanel:metrics`; die CSS-Marken
(`--grund`→`--bg`, `--akzent`→`--accent`, …) und die Werte von `data-theme`
(`dunkel`/`hell`→`dark`/`light`) und `data-density` (`kunde`→`customer`).

Da noch nichts ausgeliefert ist, gibt es dafür keinen Migrationspfad — und
genau deshalb war jetzt der Zeitpunkt.

### Gefunden und behoben

- `SO_PEERCRED` gibt es in PHPs Socket-Extension nicht (geprüft mit 8.4). Die
  Aufruferprüfung läuft statt dessen über `SO_PASSCRED` und `SCM_CREDENTIALS` —
  dieselbe Auskunft aus derselben Quelle, vom Kernel ausgefüllt und vom
  Absender nicht zu fälschen. Der Plan sagte SO_PEERCRED zu; §4.2 ist
  nachgezogen.
- Der Agent reagierte nicht auf SIGTERM. `pcntl_signal` setzt `SA_RESTART`,
  wenn man nicht widerspricht: Der Kernel nahm den unterbrochenen
  `accept()`-Aufruf danach wieder auf, das Beenden-Flag stand auf true und der
  Prozess hing weiter. systemd hätte ihn nach der Frist mit SIGKILL beendet —
  mitten in einem laufenden Auftrag. Die Schleife wartet jetzt mit `select`
  und Frist.
- Die Bereitschaftsprüfung antwortete mit 500 statt 503: Beim Umbenennen blieb
  eine Konstante stehen, die kein Test berührte. Die Lücke ist geschlossen —
  `HealthTest` prüft jetzt beides, den Gesundheitsendpunkt ohne Agenten und
  die Übersicht ohne Agenten.
- Ein Unix-Socket-Pfad ist im Kernel auf 108 Zeichen begrenzt. Darüber warf PHP
  eine `ValueError` mitten im Start; jetzt steht dort eine Meldung, aus der
  hervorgeht, was zu ändern ist.
- **Zwei Units riefen ins Leere**, beide Reste der Umbenennung auf englische
  Bezeichner, beide erst auf einem echten Server aufgefallen:
  `srvpanel-metrics.service` rief `artisan srvpanel:kennzahlen` auf und wäre in
  eine Neustartschleife gelaufen; `srvpanel-worker.service` horchte auf
  `vorgaenge,standard`, während Aufträge nach `operations` gehen — kein
  einziger Vorgang wäre je ausgeführt worden. `PackagingTest` prüft jetzt
  beides.
- **`app.ts` suchte die Seiten in `./Seiten`**, seit der Umbenennung heißen sie
  `./Pages`. `import.meta.glob` auf ein Verzeichnis ohne Treffer ist kein
  Fehler, sondern ein leeres Ergebnis: Der Build lief durch, war um jede Seite
  leichter (545 statt 586 Module) und im Browser endete jede Seite in
  „Seite … gibt es nicht". Weder `vue-tsc` noch Vite noch die Tests haben es
  gemeldet. `InertiaPagesTest` tut es.
- **`||` verkettet in SQLite, in MariaDB ist es ein logisches Oder.** Die
  gesammelte Ausgabe jedes Vorgangs wäre im Betrieb nach dem ersten Anhängen
  eine Ziffer gewesen — und die Tests gegen SQLite hätten das nie bemerkt. Der
  Ausdruck hängt jetzt am Treiber, ein Test belegt beide Zweige.
- **Die Ausgabengrenze prüfte nur, was schon dastand.** Ein einzelnes großes
  Stück — und genau so kommt Ausgabe aus einem Programm — lief daran vorbei,
  weil die Zeile davor noch leer war. Geprüft wird jetzt gegen das, was
  ankommt.
- **Die Karte des zweiten Faktors hatte keinen Innenabstand.** Dort stand
  `padding: calc(var(--padding) * 1.5)`; `--padding` ist eine Kurzform mit drei
  Werten, `calc()` rechnet mit einem. Die Deklaration war ungültig und fiel
  still auf null zurück — Überschrift und Knopf klebten an der Kante.

### P3 — Web und PHP: das Datenmodell der Domains

- **`App\Models\Domain`** (`docs/20 §5.1`) — der erste Gegenstand unter dem
  Abonnement. Typ (Haupt, Zusatz, Subdomain, Alias), Zustand, DocumentRoot,
  PHP-Version, PHP-Einstellungen, eigene nginx-Direktiven, Weiterleitung. Was
  daran Regel ist, steht am Typ und nicht als Bedingung in einem Dienst:
  `App\Enums\DomainType` beantwortet, ob eine Sorte eigene Dateien ausliefert,
  eine Elterndomain braucht, sich einzeln entfernen lässt und auf welches
  Kontingent sie zählt. Vier gleichlautende `if` an vier Stellen wären vier
  Gelegenheiten, das fünfte zu vergessen.
- **Der Name ist serverweit einmalig, und das ist eine Sicherheitsbedingung.**
  Zwei Server-Blöcke mit demselben `server_name` sind für nginx kein Fehler —
  es nimmt wortlos den ersten. Wäre die Eindeutigkeit nur je Abonnement
  erzwungen, könnte ein Kunde die Domain eines anderen eintragen und je nach
  Reihenfolge der Konfigurationsdateien dessen Besucher bekommen: ein
  Mandantenübergriff, der keine einzige Rechteprüfung berührt.
- **Was ein Domainname ist, entscheidet der Agent** — `SrvPanel\Agent\DomainName`,
  und das Panel fragt dieselbe Regel, statt sie ein zweites Mal zu formulieren.
  Sie bringt den Namen zugleich in seine Normalform: klein, ohne den
  abschliessenden Punkt der DNS-Schreibweise. Ohne das wären `Beispiel.DE` und
  `beispiel.de.` zwei Zeilen in der Datenbank und zwei `server_name` mit
  demselben Inhalt. `DomainNameTest` führt den Angriffsdurchgang: Pfade,
  Kommandozeilen, Platzhalter, Adressen, Nicht-ASCII, überlange Bestandteile.
- **Die Domain-Einschränkung für Zusatzbenutzer wirkt jetzt** (`docs/20 §6.1`).
  Die Spalte `domain_ids` lag seit P1 in der Verknüpfungstabelle, das
  Rechtemodell versprach sie — **gelesen hat sie nichts.** Solange es keine
  Domains gab, war das folgenlos; mit P3 wäre daraus ein Feld im Formular
  geworden, das nichts bewirkt, und aufgefallen wäre es niemandem, weil alles
  funktioniert. `App\Support\Tenancy\Tenancy` trägt die Einschränkung deshalb
  **je Abonnement** und nicht als flache Liste erlaubter IDs: Derselbe Mensch
  kann in einem Abonnement auf zwei Domains beschränkt sein und im nächsten an
  allem arbeiten. Und sie wird bei jeder Abfrage ausgewertet statt einmal in
  eine Liste aufgelöst — sonst wäre eine später angelegte Domain für ihn
  unsichtbar geblieben, bis sich jemand ans Neubauen erinnert. Beide Fehler
  haben einen eigenen Test in `DomainTenancyTest`, und beide wurden absichtlich
  herbeigeführt, um zu sehen, dass er zubeißt.
- **`main_domain` wurde von nichts beschrieben.** Die Spalte gibt es seit P1,
  die Kundenübersicht liest sie — und zeigte deshalb bei jedem Abonnement „noch
  keine Domain". Sie ist jetzt die Abschrift der Hauptdomain, nachgezogen vom
  Modell an dem einen Ereignis, nach dem sie falsch sein könnte, und aus
  `$fillable` entfernt: Bliebe sie füllbar, gäbe es einen zweiten Weg, sie zu
  setzen, und der käme ohne Domain aus.
- **Ein DocumentRoot kann kein Verzeichnis des Schemas treffen** (`docs/20
  §4.5`). `logs` als DocumentRoot liefert die Zugriffsprotokolle des Kunden
  über HTTP aus, `.ssh` seine Schlüssel. Die Liste der reservierten
  Verzeichnisse kommt aus dem Agenten und wird dort aus dem Schema selbst
  abgeleitet — wächst das Schema, wächst die Prüfung mit. `DomainTest` fragt
  sie ab, statt sie abzuschreiben.
- **Domains werden hart gelöscht, Abonnements nicht.** Das Abonnement wird
  zurückgezogen, weil sein Systembenutzer verbraucht bleiben muss — die UID
  darf nicht neu vergeben werden, solange auf dem Dateisystem etwas liegt, das
  ihr gehört. Bei einer Domain gibt es diesen Grund nicht: Mit ihr geht ihr
  Verzeichnis. Den Namen trotzdem für immer zu sperren hiesse, dass ein
  versehentlich gelöschter Eintrag nie wieder anlegbar wäre — auch nicht für
  den Kunden, dem die Domain gehört.

### P3 — der Agent kann Web und PHP

- **Zehn neue Operationen** (`docs/20 §4.2`): `webserver.detect`,
  `web.site.apply`, `web.site.remove`, `web.logs.tail`, `web.logrotate.apply`,
  `php.versions`, `php.version.install`, `php.version.remove`,
  `php.pool.apply`, `php.pool.remove`. Die Vorlagen für Server-Block und
  FPM-Pool liegen im Agenten; das Panel schickt Struktur und keinen Text.
- **Eine Klasse baut alle Pfade.** Zu einer Domain gehören sechs: Server-Block,
  Include, DocumentRoot, Protokollverzeichnis, FPM-Sockel und die Wurzel des
  Abonnements. Stünden sie in `apply`, `remove` und `state` je neu
  zusammengesetzt, wäre die Operation, die **entfernt**, die schlechteste
  Stelle für eine Abweichung. Übergeben wird ein *relatives* DocumentRoot;
  alles andere entsteht im Agenten — dieselbe Regel wie bei
  `subscription.provision`.
- **`web.site.state` gibt es nicht.** Der Plan sah eine eigene Operation fürs
  Sperren vor; sie hätte denselben Server-Block geschrieben, nur mit anderem
  Rumpf. Zwei Wege zu einer Datei sind zwei Gelegenheiten, sie unterschiedlich
  zu bauen — und die Sperre wäre der Weg, der seltener läuft und deshalb später
  auffällt. Das Panel schickt den gewünschten Zustand, `suspended` ist ein Feld
  darin. Eine gesperrte Website antwortet mit **503** und nicht mit dem nackten
  403, den die Rechteänderung aus P2 erzeugte: „nicht in Betrieb" ist etwas
  anderes als „du darfst nicht", und Suchmaschinen nehmen die Seite bei 503
  nicht aus dem Bestand.
- **Ohne PHP-Version wird `.php` verweigert, nicht ausgeliefert.** Der Fehler,
  der bei jeder statischen Website teuer wird: Ohne Handler liefert nginx die
  Datei als Text aus, mit allem, was an Zugangsdaten darin steht.
- **Der Standardschutz steht in der Vorlage und in keinem Häkchen** (§9 P3):
  Punktdateien (`.git`, `.env`, `.htaccess`) in einem Ausdruck — mit Ausnahme
  von `.well-known`, ohne die ab P4 keine Domain je ein Zertifikat bekäme —,
  kein PHP in Verzeichnissen, in die hochgeladen wird, und `try_files` **vor**
  dem Handler, damit `/bild.jpg/schad.php` nicht in PHP endet.
- **Eigene nginx-Direktiven gegen eine Positivliste** (`docs/20 §4.2`). Die
  einzige Stelle in P3, an der Text eines Kunden in einer Datei landet, die als
  root gelesen wird. Fünfzehn Namen sind erlaubt, keine Blöcke, ein Semikolon
  am Ende und sonst keines. Was einen Pfad oder einen Empfänger bestimmt —
  `root`, `alias`, `include`, `fastcgi_pass` — steht nicht darauf und wird
  nicht dazukommen; `DirectiveAllowlistTest` prüft **die Liste** und nicht nur
  die Prüfung.
- **Die Abschottung liegt im Pool.** `open_basedir` auf die Wurzel des
  Abonnements, `disable_functions` für alles, was einen Prozess startet, eigenes
  `tmp` und eigene Sitzungsablage (§4.5), `security.limit_extensions = .php`
  gegen den Umweg über `.phar`. Alles als `php_admin_value` — als `php_value`
  wäre es eine Empfehlung, die ein `ini_set()` im Skript aufhebt. Die
  Einstellungen **je Domain** gehen dagegen als `PHP_VALUE` über FastCGI mit;
  ein Pool bedient drei Domains und kann nicht drei `memory_limit` haben.
  `PhpIsolationTest` prüft beide Seiten, auch die Gegenrichtung: Keine
  Domaineinstellung darf einen Schlüssel tragen, der im Pool `php_admin_value`
  ist.
- **`Quota::PHP_VERSIONS` zeigt jetzt auf den Katalog des Agenten.** Die Liste
  stand im Panel, und das war die falsche Richtung: Der Agent glaubt dem Panel
  nichts und hätte die Angabe ohnehin gegen eine eigene Liste prüfen müssen —
  zwei Listen, und die gepflegte wäre die falsche gewesen.
  `PhpVersionCatalogTest` löst `docs/23 §7` ein: Zu jeder Version im Katalog
  gehören Vorlage, Paketname und Handler — samt einer Zeile in der
  Programm-Positivliste des Agenten, ohne die sich der Pool nie prüfen liesse.
- **`$` ist kein Ende — neun Muster waren betroffen, vier davon aus P0 bis P2.**
  Aufgefallen im Angriffsdurchgang: Eine Zeitzone mit angehängtem
  Zeilenumbruch ging durch. PCRE lässt `$` auch vor einem abschliessenden
  Umbruch passen, und in einem `fastcgi_param PHP_VALUE` ist
  `memory_limit=256M\n` eine Einstellung und der Anfang der nächsten. Die
  Prüfungen des Abonnementnamens und des Systembenutzers hatten denselben
  Fehler. Alle tragen jetzt den Modifikator `D`, und weil die Einzelkorrektur
  nur den Fehler von heute behoben hätte, prüft `AnchoredPatternTest` die
  ganze Klasse: Jedes Muster im Agenten, das auf `$` endet, trägt `D`.
- **Der Standard-Pool der Distribution wird abgeschaltet.** `phpX.Y-fpm` bringt
  `www.conf` mit — ein geteilter Pool als `www-data`, ohne `open_basedir`, also
  genau das Loch, das P3 zumacht. `php.version.install` benennt ihn um; die
  Unit bleibt danach stehen, solange kein Abonnement einen Pool hat, weil ein
  PHP-FPM ohne Pool nicht startet.
- **Zwei Abschriften weniger.** Der Ablauf „schreiben, `nginx -t`, neu laden,
  im Fehlerfall zurück" stand in `panel.vhost.apply` und wird jetzt von jeder
  Kundendomain gebraucht; er steht in einer Klasse, und die Panel-Vorlage
  benutzt sie. Der Baumlauf, der als root löscht, stand in
  `subscription.remove` und wird beim Entfernen einer Domain gebraucht — auch
  er steht jetzt an einer Stelle. Beim Abschreiben wäre nach aller Erfahrung
  die Zeile mit `is_link` verlorengegangen.
- **Ein laufender Apache verweigert den Betrieb, ein installierter nicht**
  (§9 P3). Auf manchen Systemen liegt Apache als Abhängigkeit herum, ohne je
  zu starten; wer deswegen den Dienst verweigerte, verweigerte ihn auf einem
  Server, auf dem nichts im Weg ist.

### P3 — die Dienstschicht: Kontingent, Plan, Lebenslauf

- **`App\Support\Web\Domains`** legt Domains an, ändert und entfernt sie — und
  ist die Schranke aus `docs/20 §6.2`: die Prüfung sitzt im Dienst, nicht im
  Formular. Wer das Formular umgeht, trifft auf dieselbe Grenze. Die *Regeln*
  formuliert sie nicht neu: Was ein Domainname ist, entscheidet der Agent, was
  ein DocumentRoot sein darf ebenfalls, und welche Direktive zulässig ist auch.
  Das Panel fragt und übersetzt die Ablehnung in eine Meldung am Feld.
- **Die Zählregeln sind die, die im Formular des Betreibers stehen.** Haupt-
  und Zusatzdomains auf ein Kontingent, Subdomains auf ein eigenes, Aliasse auf
  keines — genau das verspricht `App\Support\Plans\Quota` als Hinweis unter dem
  Eingabefeld, und `DomainServiceTest` hält beide aneinander. Gezählt wird
  einschliesslich der Domains, die gerade entstehen: Zwei gleichzeitige Anlagen
  kämen sonst beide durch, weil jede die andere noch nicht sieht.
- **Drei neue Kontingente decken die PHP-Einstellungen** (`docs/23`):
  `php_memory_mb`, `php_upload_mb`, `php_execution_seconds`. §9 P3 verlangt
  „vom Plan gedeckelte Grenzen", und das braucht einen Ort — feste Serverwerte
  wären kein Unterschied zwischen zwei Paketen, und dafür gibt es Pläne. Keines
  der drei darf unbegrenzt sein: `memory_limit = -1` lässt eine einzige
  Anfrage den Arbeitsspeicher belegen. `QuotaCatalogTest` hat beim Hinzunehmen
  rot geschlagen und den Grund eingefordert — das war seine Aufgabe. Eine
  Migration trägt die drei in bestehende Pläne nach; ohne sie hiesse ein
  fehlender Schlüssel „unbegrenzt", also die genaue Umkehrung.
- **Die drei Mengen der PHP-Versionen** stehen in
  `App\Support\Web\PhpSelection`: Katalog, installiert, vom Plan erlaubt.
  Wählbar ist der Schnitt, und diese Rechnung steht an einer Stelle. Der Kunde
  sieht zusätzlich die Versionen, die sein Plan hergibt und die auf dem Server
  fehlen — abgeblendet, mit dem Grund; er sieht damit, dass die Lücke am Server
  liegt und nicht an seinem Vertrag. **Ein leerer Zwischenspeicher heisst
  „nichts installiert" und nicht „alles erlaubt"**: Das ist die sichere
  Richtung, solange `php.versions` noch nie gelaufen ist.
- **Zwei Vorgänge je Domain, in dieser Reihenfolge**: erst der FPM-Pool, dann
  der Server-Block. `web.site.apply` weist einen Block zurück, dessen Pool
  fehlt — sonst zeigte `fastcgi_pass` auf einen Sockel, den niemand bedient,
  und die Website antwortete mit „502 Bad Gateway", während im Panel alles
  grün aussieht.
- **Ein Vorgang sagt jetzt, wovon er handelt.** `subject_type` und
  `subject_id` statt einer Spalte je Ausbaustufe — und statt eines
  Klassennamens in der Datenbank steht dort der Wert einer Aufzählung
  (`App\Enums\OperationSubject`). Ein Klassenname wäre wieder eine
  Zeichenkette, die auf etwas zeigt, ohne dass jemand den Bezug prüft;
  nach einer Umbenennung stünden in der Datenbank Zeilen, die ins Leere weisen.
- **`App\Support\Operations\Lifecycles` hängt die Lebensläufe an den
  Arbeiter.** Bis P2 gab es einen, und der Arbeiter rief ihn direkt auf; ab
  zweien ist „man gibt dem Arbeiter die Klasse" der Weg, auf dem der dritte
  vergessen wird — der Vorgang liefe durch, der Agent täte seine Arbeit, und im
  Panel änderte sich nichts. Ohne Fehler, ohne Meldung. `LifecycleReachTest`
  prüft beide Richtungen.
- **Der Rückbau eines Abonnements gibt die Domainnamen frei.** Das Abonnement
  wird weich gelöscht, damit sein Systembenutzer verbraucht bleibt — der
  Fremdschlüssel der Domains hat `cascadeOnDelete`, und das greift dabei
  **nicht**. Die Zeilen wären stehen geblieben und hätten ihre Namen belegt
  gehalten, auf einem Server, auf dem von ihnen nichts mehr liegt. Aufgefallen
  ist das beim Nachfragen und nicht im Test; der Test steht jetzt daneben.
- **Zwei Tests waren zu schwach und sind es nicht mehr.** Die Prüfung auf einen
  schon vergebenen Domainnamen lief nur als Admin — mit offener
  Mandantenklammer sieht die Abfrage die fremde Domain ohnehin, und ihr
  Entfernen blieb in der Gegenprobe grün. Der Test läuft jetzt zusätzlich aus
  der Sicht eines Kunden, in der die Klammer zu ist. Und die Argumente für den
  Agenten hingen daran, wer gerade angemeldet ist: Im Grundzustand der Klammer
  stand im Namensfeld eine leere Zeichenkette.

### P3 — Vorgänge mit Argument, und was ein Abonnementvorgang nach sich zieht

- **Vier neue Aufgaben im Katalog**: Webserver erkennen, PHP-Versionen
  nachsehen, installieren, entfernen. Die letzten beiden sind die ersten
  Aufgaben mit einem Argument — der Kommentar über der Aufzählung hatte sie
  seit P1 angekündigt („sobald es Websites gibt, brauchen Aufgaben Argumente …
  und dann muss dieser Katalog auch beschreiben, welche Werte zulässig sind und
  woher sie stammen dürfen"). Die Antwort auf „woher" ist dieselbe wie überall:
  aus einer festen Liste im Quelltext. Der Browser schickt „8.2", der
  Steuerungscode prüft gegen dieselbe Liste, aus der die Oberfläche ihr
  Auswahlfeld baut, und `apt-get` bekommt einen Paketnamen, den der Agent
  zusammensetzt.
- **Installieren und Entfernen bleiben Betreiberhandlungen.** Ein Kunde sieht
  im Domainformular, welche Versionen er wählen kann und welche sein Plan
  hergibt, ohne dass es sie auf dem Server gibt — anfordern kann er nichts. Ein
  Knopf ohne Empfänger ist schlechter als keiner: Der Kunde drückt, sichtbar
  passiert nichts, und niemand ist zuständig.
- **Ein Abonnementvorgang zieht die Websites nach.** Nach
  `subscription.provision` entsteht die Hauptdomain — der Name des Abonnements
  *ist* sie (§5.1), ein zweites Eingabefeld wäre eine Gelegenheit, zwei
  verschiedene Namen einzutragen — und ihr Server-Block wird geschrieben.
  Sperren und Entsperren schreiben jeden Server-Block neu: Bis hierher setzte
  `subscription.suspend` nur die Rechte des Verzeichnisses, und ein Besucher
  bekam einen nackten „403 Forbidden" zu sehen.
- **Die Reihenfolge in `App\Support\Operations\Lifecycles` ist die
  Voraussetzung dafür.** Der Lebenslauf des Abonnements läuft zuerst und hat
  den Zustand gesetzt, bevor die Argumente für den Server-Block entstehen.
  Umgekehrt trüge jeder Block noch den Zustand von vorher — die Sperre stünde
  im Panel und die Website antwortete weiter. Ein Test hält die Reihenfolge
  fest und steht neben dem, der sie braucht.
- **Ein Folgevorgang trägt das Konto dessen, der ihn ausgelöst hat.** Im
  Arbeiter gibt es keine Anfrage; ohne diese Weitergabe stünde in der Liste
  „—" neben einer Sperre, die jemand angeordnet hat.
- **Ein Test führte eine abgeschriebene Liste der Agent-Operationen** und war
  damit beim ersten Zuwachs falsch: Er kannte `webserver.detect` nicht, obwohl
  der Agent sie kennt, und hätte einen Fehler gemeldet, den es nicht gibt.
  Gefragt wird jetzt die Registratur des Agenten — dieselbe Sorte Korrektur wie
  beim Changelog-Test, der auf Dateien zeigt, die es geben muss.

### P3 — die Oberfläche für Domains und PHP

- **Vier neue Seiten**: die serverweite Domainliste, das Formular zum Anlegen,
  die Domainseite mit Verzeichnis, Handler, PHP-Einstellungen, eigenen
  Direktiven und Weiterleitung, dazu die Protokollansicht. Am Abonnement steht
  die Liste seiner Domains — ein Kunde kommt über sein Abonnement zu seinen
  Websites, und ein zweiter Menüpunkt wäre ein zweiter Weg zum selben Ort.
- **`/settings/php`** zeigt, welche Versionen auf dem Server liegen, ob ihr FPM
  läuft, wie viele Pools daran hängen und wie viele Domains sie benutzen.
  Installiert und entfernt wird von dort über den Aufgabenkatalog. Antwortet
  der Agent nicht, steht der letzte bekannte Stand da — mit dem Zeitpunkt,
  damit niemand ihn für den heutigen hält.
- **`App\Policies\DomainPolicy`** — und `viewLogs` als eigene Fähigkeit. Ein
  Fehlerprotokoll enthält Pfade, Dateinamen und Bruchstücke aus dem Quelltext;
  wer Dateien nicht lesen darf, soll sie nicht über diesen Umweg sehen. `create`
  fragt am Abonnement, in dem die Domain entstehen soll: Ohne es liesse sich
  nur fragen, ob ein Konto *irgendwo* Domains anlegen darf.
- **Der Angriffsdurchgang** (`DomainRouteTest`) geht jede Route mit einer
  fremden ID durch — und fremd heisst „nicht gefunden", nicht „verboten": Ein
  403 verriete, dass es die Domain gibt. Dazu die Domain-Einschränkung eines
  Zusatzbenutzers am direkten Aufruf einer Adresse, nicht nur an der Liste.
- **Drei Fehler hat erst der Browser gezeigt**, und alle drei waren grün
  getestet:
  - `class="knopf betont"` — eine Klasse, die es in `app.css` nicht gibt. Der
    Knopf sah aus wie ein gewöhnlicher, der ausgewählte Umschalter der
    Protokollansicht war von dem daneben nicht zu unterscheiden.
    `ButtonStyleTest` prüfte, dass keine Seite ihr *eigenes* Aussehen erfindet
    — nicht, dass sie ein vorhandenes trifft. Jetzt prüft er beides.
  - „höchstens 64" als Platzhalter, ohne die Einheit. Sekunden oder MB, das
    stand nirgends.
  - Rot am Installieren statt am Entfernen. Eine Version dazuzunehmen kostet
    Platz, eine wegzunehmen kann Websites stilllegen.
- **`PolicyReachTest` kannte eine Form des Aufrufs nicht.**
  `$request->user()->can('updatePhp', $domain)` ist der Weg für eine Fähigkeit,
  die keine eigene Route trägt, sondern in der Ansicht entscheidet, ob ein
  Abschnitt überhaupt erscheint. Ohne diese Ergänzung hätte der Test verlangt,
  eine erreichbare Fähigkeit als unerreichbar einzutragen.
- **Und eine Gegenprobe blieb grün**, was einen fehlenden Fall zeigte: Die
  Protokollroute auf `can:view` umzustellen fiel niemandem auf, weil `viewLogs`
  weiterhin aus der Ansicht heraus aufgerufen wird und damit als erreichbar
  galt. Erreichbarkeit ist nicht dasselbe wie „die richtige Fähigkeit an der
  richtigen Route" — dafür steht jetzt ein Test, der ein Konto mit dem Recht
  „Statistik" die Domain sehen und die Protokolle nicht lesen lässt.

### P3 — der Wächter über die Operationsnamen

- **`AgentOperationReachTest`** hält drei Listen zusammen: was das Panel an den
  Agenten schickt, was der Agent kennt, und was danach ein Lebenslauf
  beantwortet. Mit P3 standen die Namen der Operationen als Zeichenketten in
  zehn Dateien — `web.site.apply` im Lebenslauf, `php.versions` im
  Steuerungscode, `panel.tls.info` in den Einstellungen — und geprüft hat sie
  nichts. Wortwörtlich das Muster aus CLAUDE.md, diesmal an einer besonders
  unangenehmen Stelle: Ein Tippfehler in `web.site.aply` fällt weder beim
  Übersetzen noch in der Oberfläche auf, sondern erst, wenn ein Kunde eine
  Domain anlegt und der Vorgang mit „Unbekannte Operation" scheitert.
- **Ein Lebenslauf sagt jetzt, welche Aufgaben er beantwortet.** Vorher stand
  das als `str_starts_with` und `match` im Rumpf — lesbar, aber für nichts
  prüfbar. Und genau das ist die Frage, sobald jemand eine Aufgabe dazunimmt:
  Beantwortet sie überhaupt jemand? Eine Aufgabe ohne Lebenslauf läuft durch,
  der Agent tut seine Arbeit, und im Panel ändert sich nichts — ohne Fehler,
  ohne Meldung. Was nichts ändert, steht mit Begründung in einer Liste.
- **Zwei Operationen waren gebaut und wurden von nichts aufgerufen** — beide
  vom neuen Test gefunden:
  - `web.logrotate.apply`. Ohne sie füllt das Zugriffsprotokoll die Quota des
    Kunden mit Dateien, die er nie angelegt hat. Sie entsteht jetzt mit dem
    Abonnement; der Ausdruck darin deckt jede Domain ab, auch die von morgen.
  - `php.pool.remove`. Der Pool einer entfernten Domain wäre stehen geblieben —
    und `php.version.remove` weist ab, solange ein Abonnement einen Pool in
    dieser Version hat. Die Version liesse sich nie wieder entfernen, und der
    Betreiber suchte nach einem Abonnement, das es nicht mehr gibt.
- **Und der Test selbst hat zweimal dazugelernt.** Der erste Entwurf suchte die
  Namen an den Aufrufstellen — dabei sah `subscription.provision` unbenutzt
  aus, weil der Steuerungscode sie über eine eigene Methode durchreicht. Ein
  Ausdruck, der jede Schreibweise eines Aufrufs erraten muss, ist kein Wächter,
  sondern eine zweite Fehlerquelle; die Vollständigkeit trägt seitdem die
  erklärte Liste, und die Suche im Quelltext ist nur noch das Netz daneben.
  Beim Gegenprüfen fiel dann auf, dass dieses Netz `dispatchForSubscription()`
  nicht sah — ein Tippfehler in dem Namen, den sie abschickt, blieb unbemerkt.

### P3 — Rückbau, Abnahme, Dokumente

- **Der Rückbau reicht seit P3 über das Abo-Verzeichnis hinaus.** Bis P2 lag
  alles zu einem Abonnement unter `/var/www/vhosts/<abo>`, und der Baumlauf nahm
  es mit. Mit den Websites liegen drei Dinge ausserhalb: der Server-Block in
  `/etc/nginx/srvpanel.d`, der FPM-Pool in `/etc/php/<version>/fpm/pool.d`, die
  Rotation in `/etc/logrotate.d`. `subscription.remove` räumt sie mit ab —
  **vor** dem Verzeichnis, weil der Server-Block darauf zeigt: Ein nginx, das
  zwischen beiden Schritten neu lädt, fände sonst ein `root`, das es nicht mehr
  gibt.
- **Die Server-Blöcke werden gesucht und nicht übergeben.** Das Panel wüsste,
  welche Domains es gab — nur ist genau das die Liste, die nach einem
  abgebrochenen Lauf unvollständig ist. Gesucht wird in einem Verzeichnis, das
  ausschliesslich srvpanel gehört, nach dem Pfad des Abonnements; jeder erzeugte
  Block trägt ihn in `access_log`. `SubscriptionCleanupTest` beantwortet die
  Frage aus §8.7 über das Dateisystem — einschliesslich der beiden Fälle, an
  denen ein Aufräumen scheitert, ohne dass es auffällt: Es räumt zu viel
  (`beispiel.de` nähme die Blöcke von `beispiel.de.alt` mit) oder es findet die
  verwaiste Datei nicht.
- **`srvpanel acceptance-web`** misst das Abnahmekriterium von P3, statt es zu
  behaupten: zwei Abonnements, je drei Domains, zwei PHP-Versionen. Gefragt wird
  über HTTP — durch nginx, durch den Pool, als der Systembenutzer des
  Abonnements. Geprüft werden vier Dinge: dass jede Domain antwortet, mit ihrer
  Version, unter ihrem Benutzer, und dass sie **nicht** an die Dateien des
  anderen Abonnements kommt. In die Pool-Vorlage zu sehen zeigt nur, dass
  `open_basedir` dasteht — nicht, dass PHP es anwendet, nginx den richtigen
  Sockel trifft und die Rechte stimmen.
- **`web.isolation.probe`** legt die Selbstprobe ab und entfernt sie wieder.
  Ihr Inhalt steht im Agenten und kommt nicht als Argument — dieselbe Regel wie
  bei der Willkommensseite; käme er von aussen, wäre das eine Fernsteuerung zum
  Ablegen beliebigen PHP-Codes unter fremdem Namen. Und sie antwortet mit
  „lesbar: ja/nein" und niemals mit dem Inhalt einer Datei: Ein Selbsttest, der
  bei einem Fehlschlag die Datei ausgibt, an die er nicht hätte kommen dürfen,
  hat aus einem Beleg ein Leck gemacht. Die Domains des Laufs enden auf
  `.invalid` (RFC 2606) und stehen in keinem DNS — ein Abnahmelauf trifft
  niemals eine echte Domain.
- **`docs/28`** hält fest, was beim Bauen entschieden wurde und was dabei
  schiefging. Und §15 des Plans hat zwei Antworten weniger offen: `deb.sury.org`
  bleibt (die Abhängigkeit ist auf eine Stelle zusammengezogen), und es bleibt
  dauerhaft bei nginx — zwei Webserver-Vorlagen verdoppelten genau die Fläche,
  die klein bleiben soll.
- **Der Wächter aus dem Paket davor hat gleich wieder zugebissen:** Die neue
  Operation `web.isolation.probe` war registriert und von nichts aufgerufen, und
  `AgentOperationReachTest` meldete das, bevor der Abnahmelauf geschrieben war.
- **Und zwei Gegenproben blieben grün — beide zeigten eine fehlende Prüfung.**
  Aus `subscription.remove` liess sich der Aufruf des Aufräumens entfernen,
  ohne dass ein Test es merkte: `SubscriptionCleanupTest` prüft die Methode
  über Reflexion und damit ihre Wirkung, nicht ihren Anschluss. Und in der
  Selbstprobe liess sich `is_readable()` durch `file_get_contents()` ersetzen —
  aus dem Beleg wäre ein Leck geworden, das bei einem Fehlschlag genau die
  Datei ausgibt, an die niemand hätte kommen dürfen. `WebIsolationProbeTest`
  deckt beides ab, samt der Reihenfolge: Die Konfiguration fällt vor dem
  Verzeichnis.

### Freigaben bleiben in der Beta-Phase

- **Vorgabe: Solange die Entwicklung läuft, erscheint jede Fassung als
  `X.Y.Z-rc.N` im Kanal `beta`.** Das war vorher schon so gemeint und an
  nichts geprüft. Der Freigabelauf leitete den Kanal aus einem Bindestrich in
  der Fassung ab — als Regel richtig, als Wächter nichts wert: Ein Tag `v0.3.0`
  statt `v0.3.0-rc.1` bricht nichts ab. Der Lauf wird grün, das Paket wird
  gebaut, signiert und veröffentlicht, nur eben im falschen Kanal. **Beide
  Hälften des Fehlers sind still:** Die Server im Beta-Kanal sehen das Paket
  nie und `srvpanel update` meldet „nichts Neues"; die Server im Stable-Kanal
  bekommen ein Panel angeboten, dessen Abnahmelauf nie gelaufen ist.
- **`packaging/version-channel.sh`** beantwortet die Frage jetzt an einer
  Stelle und weist ab, was nicht der Vorgabe entspricht — als erster Schritt
  des Freigabelaufs, vor dem Bauen und vor jeder Signatur. Geprüft wird auch
  die Form: Aus dem Tag `v.0.3.0-rc.1` würde die Fassung `.0.3.0-rc.1`, dpkg
  verlangt vorn eine Ziffer, und der Lauf bräche sonst erst beim Paketbau ab —
  nach dem Tag, den man ungern zurücknimmt.
- **Der Kanal wird nur noch einmal bestimmt.** Vorher stand die Ableitung
  zweimal da: einmal für das `--prerelease` des GitHub-Release, einmal für die
  Paketquelle. Zwei Stellen, die dieselbe Frage beantworten, geben irgendwann
  zwei Antworten — und dann liegt das Paket im einen Kanal und ist im anderen
  als Vorabfassung ausgewiesen.
- **Der Ausgang aus der Beta-Phase ist `packaging/stable-release`** und nennt
  eine Fassung namentlich, nicht einen Schalter „stabil erlaubt". Ein Schalter
  wäre einmal umgelegt und danach ein Freibrief für jeden weiteren Tag. Ohne
  diesen Weg hiesse die erste stabile Freigabe, dass jemand den Wächter
  entfernt — und ein entfernter Wächter nimmt seinen Test mit.
- **`ReleaseChannelTest` fährt das Skript mit einer Tabelle durch**, und das
  ist der Grund für den Umweg über ein Skript: Ein Wächter, der nur in
  `release.yml` stünde, liesse sich nicht gegenprüfen — man müsste einen Tag
  pushen, um ihn brechen zu sehen. Alle acht Gegenproben haben zugebissen,
  darunter die entfernte rc-Pflicht, die zum Freibrief gewordene Marke und der
  aus `release.yml` entfernte Aufruf.
- **Dabei aufgefallen:** Die shellcheck-Liste im CI-Lauf ist von Hand geführt.
  `packaging/version-channel.sh` wäre still ungeprüft mitgelaufen — dieselbe
  Sorte Lücke wie eine Policy ohne Route. Der Test prüft jetzt, dass jedes
  Skript unter `packaging/` in der Liste steht.

### Der erste Abnahmelauf auf dem Zielserver — drei Fehler

Er ist sofort gescheitert, mit einer Meldung, die von etwas ganz anderem
sprach: „Das Abonnement ist wird angelegt — daran lässt sich nichts ändern."

- **Das Warten auf die Vorgänge brachte die Modelle nicht auf Stand.** Es hielt
  nur die Vorgangszeilen im Blick; die übergebenen Abonnements trugen weiter
  `Provisioning` aus ihrem `create()`. `Domains::create()` bekommt das
  Abonnement als Objekt gereicht und prüft daran — anders als
  `Domains::update()`, das die Beziehung frisch aus der Datenbank holt und
  deshalb glatt durchlief. Der Lauf konnte damit **nie** gelingen: kein
  Wettlauf, sondern jedes Mal.
- **Ein blosses Auffrischen wäre zu früh gewesen.** `RunAgentOperation`
  schreibt erst den Vorgang auf „erledigt" und ruft **danach** `afterSuccess()`,
  das den Zustand des Abonnements setzt. Dazwischen liegt ein Fenster, in dem
  kein Vorgang mehr offen ist und das Abonnement trotzdem noch angelegt wird.
  Deshalb wird jetzt gewartet, bis der Zustand da ist, und nicht einmal
  nachgesehen.
- **Der Rückbau stand hinter der Probe, in gerader Linie.** Die Ausnahme sprang
  darüber hinweg, und auf dem Server blieben zwei Abonnements samt `useradd`,
  Verzeichnisbaum, Server-Blöcken und FPM-Pools liegen — nach einem Kommando,
  dessen ganze Zusage lautet, hinterher aufzuräumen. Er steht jetzt in einem
  `finally`.
- **Und das Deutsch.** `SubscriptionStatus::label()` liefert „wird angelegt" —
  richtig für eine Spalte, falsch hinter „ist". Drei der vier Zustände passten
  in den Satzrahmen, der vierte nicht, und weil in diesem Zustand sonst niemand
  eine Domain anlegt, hat es kein Test und kein Blick in die Oberfläche
  gezeigt. Für Sätze gibt es jetzt `sentence()`, und `WordChoiceTest` meldet
  eine Beschriftung hinter einem Verb.
- **Warum nichts davon vorher auffiel:** Den Abnahmelauf konnte kein Test
  fahren — er braucht nginx, PHP-FPM und einen Agenten. Was sich **ohne** all
  das prüfen lässt, steht jetzt in `AcceptanceWebCommandTest`, und das ist
  mehr, als es aussah: das Auffrischen, das Fenster, der Rückbau im `finally`
  und der Satzbau.
- **Beim Schreiben des Tests fiel gleich der nächste auf:** Ohne
  `withoutRestriction()` sah die Abfrage keine einzige Vorgangszeile, das
  Warten fand nichts Offenes und meldete Erfolg — der Test hätte bestanden,
  ohne etwas geprüft zu haben. Dieselbe Falle wie in P3 bei der
  Namenseindeutigkeit.

### Der zweite Abnahmelauf — und was er über den ersten Fix sagte

`Die Abonnements sind nicht fertig geworden.` Mehr stand nicht da.

- **Das `finally` aus dem Fix davor stand eine Stufe zu tief** — hinter dem
  Warten. Ein Abonnement, das nicht fertig wird, ist aber gerade der Fall, in
  dem etwas halb dasteht: `subscription.provision` kann den Systembenutzer
  angelegt und danach abgebrochen haben. Der Lauf ist genau hier ausgestiegen
  und hat wieder zwei Abonnements hinterlassen. Der Block beginnt jetzt dort,
  wo das erste Abonnement entsteht.
- **Und der Satz war keine Diagnose.** Ein gescheiterter Vorgang trägt seine
  Begründung in der Datenbank, ein hängender trägt seinen Zustand, und beides
  sagt etwas völlig anderes über die Ursache. Der Lauf nennt jetzt den
  gescheiterten Vorgang samt Meldung des Agenten, die Zahl der noch offenen
  Vorgänge mit dem Hinweis auf `srvpanel-worker`, und den Zustand jedes
  Abonnements. Ist nichts davon auffällig, sagt er auch das — dann wurde der
  Zustand nicht nachgezogen, und das ist eine andere Spur.
- **Ein Abnahmelauf, der nur „nein" sagt, verschiebt die Arbeit auf jemanden,
  der weniger sieht als er.** Das ist der Grund für den ganzen Abschnitt.

### `subscriptions.main_domain` war eine zweite Wahrheit

Der dritte Abnahmelauf brach ab, und diesmal stand die Ursache im Protokoll des
Panels:

```
Duplicate entry 'abnahme-web-2.invalid' for key 'subscriptions_main_domain_unique'
```

Zwei Fehler, die einzeln harmlos aussehen und zusammen den Lauf unmöglich
machten. Beide entstanden in P3, als die Spalte zum ersten Mal beschrieben
wurde — vorher stand dort nie ein Wert, und deshalb konnte auch nie etwas
kollidieren.

- **Der Rückbau löschte die Domains mit einem Massenlöschen über den Erbauer,
  und das feuert keine Modellereignisse.** An einem davon hängt die Abschrift:
  Das Modell setzt `main_domain` beim `deleted` auf null. Übersprungen hielt
  ein gekündigtes Abonnement seine Hauptdomain für immer fest. Im Modell steht
  seit P3 der Kommentar, die Abschrift werde „nicht von einem Dienst gepflegt,
  der daran denken muss, sondern vom Modell selbst" — und der eine Löschweg
  ging am Modell vorbei. Er geht die Domains jetzt einzeln durch.
- **Und die Spalte trug einen eindeutigen Index.** In P1 wurden `system_user`
  und `main_domain` nebeneinander als eindeutig angelegt, mit derselben
  Begründung. Für den Systembenutzer ist das richtig und bleibt: Ein weich
  gelöschtes Abonnement **verbraucht** seinen `p1000`, weil die UID sonst
  wiederverwendet würde und Dateien plötzlich jemand anderem gehörten. Für die
  Hauptdomain gilt das Gegenteil, und zwar ausdrücklich — Domains haben seit P3
  keine weiche Löschung, **damit** ein Name wieder frei wird. Die Abschrift
  muss derselben Regel folgen wie das, was sie abschreibt.
- **Es war ohnehin eine zweite Wahrheit.** Die Zuständigkeit für „diesen Namen
  gibt es auf diesem Server einmal" liegt bei `domains.name`. Was dort
  eindeutig ist, ist es in der Abschrift von selbst; ein zweiter Index fügt
  keine Regel hinzu, sondern eine Stelle, an der dieselbe Regel anders
  ausfällt. Er ist gefallen, ein gewöhnlicher Index bleibt.
- **Warum es kein Test fand:** `test_the_main_domain_is_copied_to_the_subscription`
  prüfte drei Ereignisse — anlegen, umbenennen, entfernen — alle über das
  Modell. Der vierte Weg, der Rückbau, geht nicht über das Modell und war
  deshalb nicht dabei. Er steht jetzt daneben, zusammen mit der Frage, an der
  der Lauf hing: Lässt sich derselbe Name danach wieder vergeben? Und ein
  Wächter über das Schema hält beide Uniques auseinander — `main_domain` darf
  keinen tragen, `system_user` muss.
- Die Migration räumt die Altlast mit auf: Jedes weich gelöschte Abonnement
  verliert seine Abschrift. Ohne das bliebe der Name auf einem laufenden Server
  belegt, obwohl der Index fällt.

### Ein Warten in der CI, das ablaufen konnte, ohne zu scheitern

Aufgefallen an zwei verlorenen Läufen auf Ubuntu, und die gemeldete Ursache war
jedes Mal eine andere als die wirkliche: eine dpkg-Sperre und ein fehlendes
`systemctl`, beides drei Schritte hinter der Stelle, an der es schiefging.

- **Der Integrationslauf wartet darauf, dass systemd im Container hochkommt.**
  Lief die Schleife ab, passierte nichts: Im einen Schritt fing ein `| head -1`
  den Rückgabewert von `docker exec` ab — die Pipe gibt den von `head` zurück,
  und der ist immer 0 —, im anderen stand hinter der Schleife überhaupt keine
  Prüfung. Der Schritt galt als geglückt, und der Lauf ging in einen Container
  weiter, in dem gerade noch apt arbeitete.
- Das Zeitfenster ist von 120 auf 180 Sekunden gewachsen — die Ubuntu-Abbilder
  bringen systemd nicht mit und müssen es erst installieren. Wichtiger ist
  aber, dass ein Ablaufen jetzt **abbricht und sagt, warum**, samt der letzten
  Zeilen aus `docker logs`.
- **`PackagingTest` hält die Regel fest:** Wer auf `is-system-running` wartet,
  muss im selben Schritt sagen, was gilt, wenn es nicht kommt. Geprüft wird die
  Sache und nicht der eine Schritt — es waren zwei, und der zweite war der
  stillere.

### Und die Frage, die niemand beantworten konnte

Der schärfere Wächter aus dem Absatz davor hat beim ersten Lauf sofort
zugebissen — und diesmal stand die Ursache in der Meldung statt drei Schritte
weiter: „systemd im Container ist in 180 s nicht hochgekommen."

- **Jede Installationszeile der Arbeitsabläufe trug
  `DEBIAN_FRONTEND=noninteractive` — bis auf die beiden, die den Container
  überhaupt erst hochfahren.** Dort fehlte sie. Solange die Abbilder es nicht
  verlangten, fiel das nicht auf; als sich das Ubuntu-Abbild änderte, blieb
  `apt-get install systemd` an einer debconf-Frage stehen, die in einem
  Container ohne Terminal niemand beantwortet. Der Container schwieg, und
  sichtbar war nur, dass systemd nicht kam.
- Das ist die teure Sorte Ausnahme: eine Regel, die überall gilt, ausser an der
  einen Stelle, an der niemand hinsieht, weil sie schon immer funktioniert hat.
- **Die Ausgabe des Hochfahrens wird nicht mehr weggeworfen.** `-qq` und
  `>/dev/null` sorgten dafür, dass `docker logs` beim Fehlschlag leer blieb —
  ein Fehlschlag ohne jede Spur.
- **Der Wächter sieht alle Arbeitsabläufe an, nicht nur die CI**, und hat dabei
  drei weitere Aufrufe gefunden: in `release.yml` und in `secrets.yml`. Ein
  hängendes apt in einem Freigabelauf wäre teurer als eines in der CI.

### Und die eigentliche Ursache: ein langsamer Spiegel

Nach dem Diagnoseschritt stand sie im Protokoll, gemessen statt vermutet:

```
Fetched 31.9 MB in 2min 38s (202 kB/s)
```

- **`apt-get update` allein verbrauchte 158 der 180 Sekunden.** Danach kam
  `apt-get install` gerade noch bis zur Paketliste, dann war das Fenster zu.
  Kein Hänger, kein Dialog, keine Flakiness: `archive.ubuntu.com` ist von den
  Läufern aus langsam, und die Paketlisten sind gross — allein `noble/universe`
  misst 19,3 MB.
- **Debian war nie betroffen**, weil `deb.debian.org` ein CDN ist. Genau
  deshalb war das Muster über drei Läufe hinweg identisch: viermal Debian grün,
  zweimal Ubuntu rot.
- **Das Zeitfenster wächst auf 300 Sekunden, und das ist der Fix.** Danach
  waren alle elf Jobs grün.
- **Der Spiegel bei Azure war es nicht — anders als beim Einbau behauptet.**
  Die Läufer stehen bei Azure, `azure.archive.ubuntu.com` ist die Quelle, die
  das Läufer-Abbild für sich selbst benutzt, und das klang nach der Kur. Die
  Messung danach gibt es nicht her: Der Startschritt brauchte 138 s auf 24.04
  und 255 s auf 22.04, gegenüber 158 s allein fürs Auffrischen davor. Der
  Spiegel bleibt, weil er nichts kostet und nur Ubuntu trifft — aber als
  Vermutung gekennzeichnet und nicht als Beleg. Wer hier später Zeit sparen
  will, fängt bei der Grösse der Paketlisten an.
- **Zwei Vermutungen davor lagen ebenfalls daneben** — „zu knapp bemessen" und
  „debconf fragt". Beide Beiträge bleiben trotzdem richtig: Ohne den ersten
  hätte der Lauf weiter geschwiegen statt zu scheitern, ohne den zweiten wäre
  die Ausgabe nie sichtbar geworden. Sie waren die Voraussetzung dafür, dass
  die dritte Vermutung überhaupt überprüfbar wurde.
- **Kein neuer Wächter dazu, und das ist Absicht.** Was hier zu prüfen wäre —
  „der Spiegel ist schnell genug" — lässt sich nicht als Test schreiben, ohne
  ihn zu befragen. Die beiden Wächter aus den Absätzen davor sind die
  dauerhaften: Ein Warten muss scheitern können, und kein `apt-get` darf eine
  Frage stellen. Ein schwacher dritter wäre schlechter als keiner.

### Die Selbstprobe lag für vier von sechs Domains am falschen Ort

Der Abnahmelauf kam zum ersten Mal bis zur eigentlichen Prüfung — und meldete:

```
  · eins-abnahme-web-1.invalid antwortet nicht.
  · zwei-abnahme-web-1.invalid antwortet nicht.
  · eins-abnahme-web-2.invalid antwortet nicht.
  · zwei-abnahme-web-2.invalid antwortet nicht.
```

Beide **Haupt**domains bestanden, alle vier **Zusatz**domains fielen durch. Das
ist kein Befund über die Abschottung, sondern über den Lauf selbst.

- **`web.isolation.probe` schrieb fest nach `httpdocs`.** Das ist das
  DocumentRoot der Hauptdomain; jede Zusatzdomain liefert nach §4.5 aus einem
  Verzeichnis mit ihrem eigenen Namen aus. Dort lag die Probe nicht, nginx
  antwortete mit 404. Die Operation nimmt das Verzeichnis jetzt als Argument —
  und prüft es mit `DocumentRoot::valid()`, demselben Wächter wie das Panel:
  Was als Pfad in einer Operation ankommt, prüft der Agent selbst, auch wenn
  der Wert aus der eigenen Datenbank stammt.
- **„Antwortet nicht" war keine Diagnose.** Der Satz warf vier Lagen in einen
  Topf: keine Verbindung, ein HTTP-Fehler, eine fremde Seite, ungültiges JSON.
  Jede hat eine andere Ursache und einen anderen nächsten Schritt; hier war es
  der zweite, und die Meldung las sich wie ein Urteil über `open_basedir`. Der
  Lauf nennt jetzt den Status, bei 404 den erwarteten Pfad, und bei fremdem
  Inhalt einen Ausschnitt — eine nginx-Fehlerseite sieht anders aus als ein
  PHP-Skript, das im Klartext ausgeliefert wird.
- **Auch die übrigen Meldungen sagen mehr:** die falsche PHP-Version nennt die
  Pool-Datei, der falsche Benutzer den Grund, warum das zählt, und der
  durchlässige Zugriff das, was `open_basedir` tatsächlich meldet.
- **Die Regel „je DocumentRoot eine Probe" steht in einer eigenen Methode**,
  damit sie prüfbar ist: Ein Alias hat kein Verzeichnis, zwei Domains können
  sich eines teilen, und genau diese Unterscheidung ist untergegangen.

### P3 ist abgenommen

`srvpanel acceptance-web --force` auf dem Server des Betreibers, aus dem Paket
`0.3.0~rc.5`:

```
Das Abnahmekriterium von P3 ist erfüllt.
Sechs Domains, zwei PHP-Versionen, zwei Systembenutzer — und kein Zugriff über die Grenze.
```

- **Damit ist das Kriterium aus §9 P3 keine Zusage mehr, sondern eine
  Feststellung.** Geprüft ist nicht die Pool-Vorlage, sondern die Kette: nginx
  nimmt die Anfrage an, trifft den richtigen Sockel, der Pool läuft unter dem
  Systembenutzer des Abonnements, PHP wendet `open_basedir` an, und die Rechte
  auf dem Dateisystem stimmen. Ein Skript im einen Abonnement kommt an die
  Dateien des anderen nicht heran — versucht, nicht gelesen.
- **Der Rückbau danach war restlos**: acht Pool-Dateien über vier
  Katalogversionen, keine geblieben. Das ist §8.7 am selben Lauf.
- **Vier Anläufe hat es gebraucht, und keiner scheiterte an der Abschottung.**
  Ein Abonnement, dessen Zustand im Speicher veraltet war; ein Rückbau, der zu
  spät begann; eine Abschrift, die einen Domainnamen festhielt; und die
  Selbstprobe im falschen Verzeichnis. **Drei davon waren Fehler im
  Prüfwerkzeug, nicht im Geprüften.** Das macht die Sache nicht besser: Ein
  Werkzeug, das falsch misst, kostet dasselbe wie ein Fehler im Gemessenen —
  und zusätzlich das Vertrauen in sein Ergebnis.
- Was daraus bleibt, steht in `AcceptanceWebCommandTest`: Am Abnahmelauf lässt
  sich **ohne** Server mehr prüfen, als es aussieht — das Auffrischen der
  Modelle, das Fenster zwischen Vorgang und Zustand, der Rückbau im `finally`,
  die Ableitung der Verzeichnisse und jede einzelne Fehlermeldung. Beim
  Schreiben des Laufs gab es davon keinen einzigen Test, weil er „einen Server
  braucht". Das stimmte nur für das Ergebnis, nicht für den Weg dorthin.

Als Nächstes P4 (TLS).

### Rework der Optik, Schritt 1: die Wächter kommen vor dem Umbau

Die Oberfläche wird neu gestaltet — die Richtung „Kontor" aus
`docs/entwuerfe/30-neue-richtungen.md`, bedienbar in
`docs/entwuerfe/31-kontor-mockup.html`. Das Gestaltungssystem „Leitstand"
fällt: nicht seine Umsetzung, sondern seine Grundannahme — dunkel als
Ausgangsfassung, Amber als einzige Farbe, jede Zahl in Monospace, ein schmales
dunkles Rail neben dem Inhalt.

**Zuerst die Wächter, und noch keine Zeile Gestaltung.** Wer den Umbau zuerst
macht, prüft danach den Umbau statt den Bestand — und erfährt nie, was vorher
falsch war. Deshalb steht die Messung am Anfang, und sie ist unbequem: Der
Lauf ist nach diesem Schritt **rot an sechs Stellen**, und jede davon ist ein
Befund und kein Versehen.

- **`ButtonStyleTest::test_every_control_border_stands_out`** — der
  Kontrasttest liest keine Namen mehr, sondern Regeln. Vorher las er genau eine
  Marke, `--button-line`, weil genau ein Fund ihn ausgelöst hatte: ein Knopf
  auf `--surface` mit einem Rand aus `--line`, 1,04:1, auf dem Bildschirm kein
  Bedienelement, sondern ein hellerer Fleck.

  Die Regel dahinter steht in WCAG 1.4.11 und redet nicht von Knöpfen, sondern
  von der Grenze eines Bedienelements. Der Test tat es doch — und hat deshalb
  neun Monate lang nicht gemeldet, dass **jedes Eingabefeld des Panels**
  denselben Fehler trägt: `border: 1px solid var(--line)` auf elf Seiten,
  **1,13:1 im dunklen Theme und 1,09:1 im hellen**. Er sucht jetzt jede Regel —
  in `app.css` *und* in jeder Komponente —, deren Selektor ein Bedienelement
  nennt und die ihm einen Rand aus einer Marke gibt, und rechnet diese Marke
  gegen jede Fläche, auf der das Element liegen kann. Ein künftiges `.schalter`
  fällt darunter, ohne dass jemand den Test anfasst.
- **`ButtonStyleTest::test_no_page_styles_a_field_itself`** — dieselbe Regel wie
  für Knöpfe, nur für `input`, `select` und `textarea`. Elf Seiten trugen
  dieselbe abgeschriebene Zeile; beim zwölften Mal wäre sie anders gewesen.
- **`ClassReachTest`** (neu) — jede Klasse in einem Template zeigt auf eine
  Regel, die es gibt. Das ist die Verallgemeinerung einer Prüfung, die es schon
  gab: `ButtonStyleTest` verlangt seit P3, dass jede *Knopfklasse* existiert —
  Anlass war `class="knopf betont"`, eine Klasse, die niemand kennt. Für jede
  andere Klasse fragte danach niemand. Der erste Lauf fand drei tote, zwei
  davon vorher unbemerkt:
  - `Tile.vue` schreibt `class="series empty"`, das CSS kennt `.series.leer`;
  - `Tile.vue` schreibt `class="value num"` — **`.num` gibt es nirgends.** Die
    Marke sollte der Kachelzahl Tabellenziffern geben; `app.css` setzt sie über
    `.zahl`, die Klasse heisst anders. Die eine grosse Zahl, für die die Kachel
    da ist, stand also in Proportionalziffern, und zwei Kacheln nebeneinander
    hatten ihre Ziffern nicht auf derselben Linie. Sichtbar, messbar, und
    trotzdem ein Jahr lang von niemandem gemeldet;
  - `PanelLayout.vue` schreibt `class="name"` im Kontoblock, ohne Regel dazu.
- **`TableStyleTest`** (neu) — Tabellen kommen aus `app.css`, und ihre
  Zeilenhöhe aus `--row-height`. §7.2 verspricht zwei Dichtestufen und nennt
  als erste Zeile ihrer Tabelle die Zeilenhöhe. Die Marke dafür wurde von
  **zwei der 26 Seiten** benutzt; auf den übrigen 24 entstand die Zeilenhöhe
  aus `padding: 6px 8px`, je Seite neu geschrieben. Die Kundenfläche war dort
  also nicht ruhiger als die Adminfläche, und gemerkt hat es niemand, weil kein
  Lauf danach fragt. Dazu, was daraus folgt: zehn Seiten definieren `table`,
  und es gibt **zwei unvereinbare Fassungen** davon.
- **`DesignTokensTest`** — die Ausnahme im Ausdruck fällt. Dort stand
  `|block-heading-size`, damit eine Marke durchkommt, die §7.2 gerade verbietet:
  „Schriftgrößen nicht nach Dichte gestaffelt", und `--block-heading-size` ist
  13px auf der Admin- und 15px auf der Kundenfläche. Ein Test, der die Ausnahme
  einbaut, hält nicht mehr die Regel fest, sondern ihre Verletzung. Neu dazu:
  **jede Stufe der Skala wird auch benutzt** — eine Rolle ohne Nutzer ist keine
  Rolle, sondern ein Rest, an dem sich beim nächsten Umbau jemand festhält.
- **`MobileLayoutTest`** — die Regel bleibt, der Ort ändert sich: Er sucht
  Feldregeln jetzt auch in `app.css` und nicht mehr nur unter `resources/js`.
  Ohne das hätte er in dem Augenblick nichts mehr gefunden, in dem die Felder
  dorthin umziehen — und wäre an seiner eigenen Untergrenze durchgefallen statt
  an der Sache. **Ein Wächter, der beim Aufräumen zubeisst, wird beim Aufräumen
  abgeschaltet.**

**Und jeder Wächter wurde absichtlich gebrochen.** Das steht als
`tests/waechter-brechen.sh` im Repo und nicht als Notiz, weil es sonst beim
nächsten Wächter nicht mehr geschieht. Es hat sich beim ersten Lauf sofort
gelohnt: `TableStyleTest::test_the_density_token_exists_in_both_steps` blieb
**grün**, obwohl `--row-height` aus der Kundendichte entfernt war. In `app.css`
steht `[data-density='customer']` ein zweites Mal, nämlich im
`@media (max-width: 720px)`-Block, wo beide Stufen auf 44px zusammenlaufen —
und der Ausdruck fand diese Fundstelle. Der Wächter sah richtig aus und war es
nicht; gemerkt hat es nur der Bruch. Er zählt jetzt Klammern und sucht
ausserhalb der Haltepunkte.

### Rework der Optik, Schritte 2 bis 5: „Kontor" steht

Alle 26 Seiten, ein Gerüst, acht Komponenten und `resources/css/app.css`
folgen jetzt der Richtung aus `docs/entwuerfe/30-neue-richtungen.md`. Was sich
gegenüber „Leitstand" geändert hat, steht ausführlich in Plan §7.2; hier steht,
was dabei gefunden wurde.

**Vier Grundannahmen sind gefallen, nicht der Maßstab.** Hell ist die
Ausgangsfassung und nicht die nachträgliche zweite Fassung. Es gibt keine
Karten mehr — ein Bereich ist eine Überschrift, eine Linie und Inhalt, und die
rund 40px Innenabstand, die eine Karte je Bereich kostet, sind jetzt Zeilen.
Monospace trägt nur noch Kennungen; Ziffern stehen trotzdem spaltengenau, weil
`tabular-nums` am `body` steht statt in vierzig Regeln. Und die Form jedes
Bausteins steht in `app.css` — vorher gab es davon vier bis elf Fassungen über
32 Dateien.

Drei Vorgaben sind dabei ausdrücklich gefallen, jede mit ihrer Begründung im
Plan: der 3px-Radius (in neun Monaten an keiner einzigen Stelle eingehalten —
eine Vorgabe, die kein Werkzeug prüft, ist ein Satz im Dokument), „fünf
Größen, und keine sechste" (widersprach ihrer eigenen sechszeiligen Tabelle
und der Datei mit sieben Marken), und Amber als tragende Farbe (Akzent und
Zustand „Warnung" waren derselbe Farbwert und mussten sich die Bedeutung
teilen). `docs/24` bekommt mit `.paare` ein drittes Tabellenmuster: Es füllt
eine Lücke, die bis dahin jede Detailseite selbst gefüllt hat.

#### Was im Browser gefunden wurde und kein Test gemeldet hat

- **Zwölf Zahlenfelder ohne jedes Aussehen.** Im Formular eines Plans stehen
  die Kontingente in `.mit-einheit` und nicht in `.feld`, und nur `.feld input`
  trug Rand, Fläche und Innenabstand. Auf dem Bildschirm standen die Werte als
  nackter Text. Kein Wächter fragt, ob ein Feld überhaupt *ein* Aussehen
  bekommt — sie fragen nur, ob das, was es bekommt, aus `app.css` stammt.
- **Ein Eingabefeld von 1570px.** Ein Bereich, der allein in seiner Zeile
  steht, nimmt die volle Breite — und nahm sie ohne Grenze mit ins Feld. „Die
  Fläche ausnutzen" heisst mehr Zeilen zu zeigen und nicht, ein Wort über den
  halben Bildschirm zu ziehen. `.feld` hört bei 540px auf, das Codefeld bei
  280px.
- **Ein Überlauf von 81px auf `/domains/1` bei 390px**, und der erste Versuch
  half nicht: `white-space: normal` statt `nowrap` liess ihn auf exakt
  denselben 81px stehen. In einer Flexzeile hält `flex: none` die
  Inhaltsbreite, ganz gleich wie umgebrochen werden darf. Erst `flex: 1 1 auto`
  mit `min-width: 0` gibt der Zelle das Recht, schmaler zu werden als ihr
  Inhalt.
- **1400px Lauflänge in einem halben Grundriss.** Zwölf Kontingente
  untereinander, daneben die halbe Seite leer — derselbe Vorwurf wie vorher,
  nur in neuer Gestalt. Als Raster über die volle Breite passt das Formular
  eines Abonnements auf einen Bildschirm.
- **Ein Satz in Monospace.** „noch keine Domain" stand mit `.kennung` da und
  las sich wie ein Wert, den man irgendwo eintippen soll.

#### Der Fund, der einem Wächter seine Reichweite gegeben hat

**Sieben Seiten nannten `--surface-border` und `--padding` — Marken, die es
seit dem Umbau nicht mehr gibt.** An elf Stellen. Der Browser wirft eine
Deklaration mit unbekannter Marke still weg: Der Spaltenkopf der Übersicht
hatte keine Linie, die Balkenspur keinen Grund, die Karte der Anmeldemaske
keinen Rand.

`DesignTokensTest` prüfte genau das schon — aber nur für `--text-…` und
`--block-…`, also für ein Zehntel der Marken. Der Wächter stand daneben und
sah weg. Er heisst jetzt `test_every_token_a_component_uses_exists` und liest
jede Marke, die eine Komponente nennt.

#### Drei Wächter mussten ihre Untergrenze weiten — und einer log

`test_no_page_styles_a_button_itself`, `..._a_field_itself` und
`test_no_component_styles_a_table_itself` zählen ihre Treffer, damit sie
merken, wenn ihr Ausdruck ins Leere läuft. Gezählt haben sie nur in den
Seiten. Als die letzte Seite ihr eigenes CSS abgab, stand der Zähler auf null,
und alle drei meldeten Rot für genau die Ordnung, die sie durchsetzen sollen.
Gezählt wird jetzt in `app.css` mit; der Befund kommt weiter allein aus den
Seiten.

Dazu ein vierter, der **falschen Alarm schlug**:
`MobileLayoutTest::test_input_fields_use_the_zoom_safe_size` liest als Selektor
alles, was vor der geschweiften Klammer steht — also auch den Kommentar
darüber. In `app.css` steht über `.schalter` die Begründung, warum ein
Ankreuzfeld dort seine eigene Größe bekommt; das Wort „input" darin genügte,
damit die Regel als Feldregel galt und an `--text-table` durchfiel. Ein
Ankreuzfeld zoomt Safari nie hinein. Jeder andere Wächter mit demselben
Ausdruck schneidet Kommentare weg; hier war es vergessen worden.

Das ist inzwischen **dreimal dieselbe Ursache** — bei `MobileLayoutTest`, bei
`DesignTokensTest::test_every_step_of_the_scale_is_used` und jetzt bei diesen
dreien: *Ein Wächter, der eine Regel prüft, darf nicht davon ausgehen, **wo**
sie gerade eingehalten wird.* Alle sechs Änderungen sind über
`tests/waechter-brechen.sh` gegengeprüft — Regel gebrochen, Wächter beisst.

#### Und das Werkzeug, das die Wächter prüft, hatte selbst keinen

`tests/waechter-brechen.sh` meldete nach dem Umbau **fünf Wächter als „hält
seine Regel nicht"** — und keiner davon war schuld. Die Eingriffe nennen
wörtliche Werte: `--row-height: 42px`, `--button-line`, `--text: #b9c7d4`,
`--text-metric: 22px`. Nach „Kontor" hiessen alle anders, und `sed` schweigt,
wenn sein Muster nicht passt. Das Skript patchte also nichts, liess den Test
laufen, sah ihn grün und schrieb das dem Wächter zu.

Das ist wieder dieselbe Sache: eine Zeichenkette, die auf etwas verweist, ohne
dass etwas den Bezug prüft — diesmal im Werkzeug, das genau dafür gebaut wurde.
Jeder Eingriff vergleicht die Datei jetzt vorher und nachher; ändert er nichts,
meldet das Skript **„Eingriff hat nichts geändert"** statt den Wächter
anzuschwärzen.

**Ein Bruch war ausserdem gar keiner.** Der Test zur Zeilenhöhe wurde
gebrochen, indem eine *zusätzliche* Regel `td { height: 40px }` angehängt
wurde. Der Test fragt aber, ob *irgendeine* Tabellenregel die Marke liest — und
die echte tat es weiter. Ein Bruch, der neben die Regel greift statt auf sie,
kann nie zubeissen; er hat den Wächter zwei Ausbaustufen lang bestätigt, ohne
ihn je zu prüfen. Er bricht jetzt die eine Stelle, die es wirklich gibt.

Dazu drei neue Eingriffe für die drei neuen Wächter. Elf Brüche, elf Bisse.

### Klassennamen sind englisch

Aufgefallen ist es dem Betreiber beim Lesen: `.knopf`, `.marke`, `.bereich`,
`.kennung`, `.stapelt`, `data-spalte` — rund 110 Namen, dazu Komponenten
namens `Bereich`, `Marke` und `Balken` mit Eigenschaften wie `titel`,
`erklaerung` und `prozent`.

**Das war nie die Vorgabe.** `CLAUDE.md` sagt seit dem ersten Tag: „Kommentare,
Dokumentation und alle Texte der Oberfläche: deutsch. Bezeichner: englisch."
Eine CSS-Klasse ist ein Bezeichner. Es gab dafür nur kein Werkzeug — dieselbe
Geschichte wie bei den Knöpfen, den Schriftgrößen und den Tabellen: eine Regel
im Dokument, keine im Lauf.

**Und `docs/19` §4 hielt das Gegenteil fest.** Dort stand „Die Bezeichner im
Quelltext sind von alledem unberührt", mit `api.einspielen` und `ufwEinspielen`
als Beispielen. Der Satz stammt aus dem Vorgängerprojekt und meinte etwas
Enges und Richtiges: eine **Schnittstelle**, die man nicht für einen
Wortgeschmack umbenennt, weil auf der anderen Seite jemand mitliest. Auf die
Klassennamen der eigenen Gestaltung angewandt hat er neun Monate lang eine
zweite Sprache gerechtfertigt. §4a zieht die Grenze jetzt dort, wo sie
hingehört: Was eine Migration oder einen Bruch kosten würde, bleibt; was
ausschliesslich zwischen `app.css` und einem Template dieses Repositorys
steht, wird englisch.

Umgestellt sind alle CSS-Klassen, die Datenattribute (`data-spalte` →
`data-column`, `data-stufe`, `data-ablesung`), die drei deutschen Komponenten
samt Eigenschaften und Slots (`#pfad` → `#breadcrumb`, `#aktion` →
`#actions`). Seitenlokale Hilfsfunktionen bleiben deutsch — sie sind
niemandes Schnittstelle, und ein eigener Durchgang nur dafür lohnt nicht.

**Eine Übersetzung war keine.** `.bereich.voll` hiess „über die ganze Breite",
`.balken.voll` hiess „nahe an der Grenze" — wörtlich übersetzt hätten beide
`full` geheissen, und dieselbe Klasse hätte an zwei Bausteinen zwei Dinge
bedeutet. Der Balken heisst jetzt `.bar.tight` und `.bar.over` und sagt damit,
was er meint. Das ist im Deutschen neun Monate lang niemandem aufgefallen.

**Der neue Wächter fand drei tote Regeln.** `ClassNameTest` prüft zwei Dinge:
dass jeder Klassenname aus einer Wortliste stammt, und — als Gegenrichtung zu
`ClassReachTest` — dass jede Regel in `app.css` von einem Template erreicht
wird. Die zweite Prüfung meldete `.pair-list`, `.output .time` und
`.output .fehl`: eine Beschreibungsliste, die seit „Kontor" niemand mehr baut,
und zwei Regeln für Markup, das die Vorgangsausgabe längst nicht mehr
erzeugt — sie ist reiner Text in einem `<pre>`. `.fehl` war ausserdem die
letzte deutsche Klasse und wäre beim Umbenennen fast durchgerutscht, weil sie
in keinem Template steht.

Wer eine Klasse hinzufügt, trägt ihr Wort in `VOCABULARY` ein. Die Zeile steht
im Diff — genau dort, wo ein deutsches Wort auffällt.

### Blättern — vier Verzeichnisse hörten nach fünfzig Zeilen auf

Aufgefallen beim Ansehen einer Aufnahme des Protokolls: Oben stand „76
Einträge", darunter fünfzig, und darunter nichts.

**Der Befund war schlimmer als der erste Blick.** Vier Controller riefen
`paginate()` auf — Protokoll, Kunden, Domains, Vorgänge — und **keine einzige
Seite zeigte einen Weg zur zweiten.** Drei von ihnen schickten die
Seitenzahlen nicht einmal mit; beim vierten kamen sie an und die Seite warf
sie weg. Alles ab Zeile 51 war unerreichbar: kein Fehler, keine Meldung, nur
eine Liste, die aufhört. Am schlimmsten trifft es die **Vorgänge** — die
Liste wächst am schnellsten, eine Zeile je Operation, und man sieht sie an,
wenn etwas nicht stimmt.

Zwei weitere Verzeichnisse — **Abonnements und Pläne** — paginierten gar
nicht und wuchsen ohne Grenze. Auf der schmalen Fläche wird daraus ein
Kärtchen je Zeile; das Protokoll war bei 76 Einträgen bereits 21 000 px hoch.

Wieder derselbe Fehler: eine Zusage auf der einen Seite (`paginate`), der auf
der anderen nichts entspricht. Er ist ein Jahr alt.

#### Was jetzt gilt

- **Alle sechs Verzeichnisse paginieren**, 50 Zeilen je Seite, aus einer
  Konstante. Auch Pläne — ein Katalog von über 50 ist unwahrscheinlich, aber
  die Kosten sind ungleich verteilt: Blättern, das nie erscheint, kostet
  nichts; zehn unsichtbare Pläne sind derselbe stille Verlust.
- **Domains von 100 auf 50.** Ein abweichender Wert ohne Grund ist einer, den
  beim nächsten Verzeichnis jemand anders setzt.
- **Die Nutzlast entsteht in `App\Support\Web\Page`** und nicht je Controller.
  Vorher schickte das Protokoll vier Felder und die übrigen drei zwei.
- **`->withQueryString()` überall.** Ohne den Aufruf trägt der Verweis auf
  Seite 2 die eingestellten Filter nicht mit: Man filtert auf
  „fehlgeschlagen", blättert weiter und steht wieder in der ungefilterten
  Liste — mit einem Formular, das weiter den Filter anzeigt.
- **`Pager.vue`: Zurück · Seite N von M · Weiter.** Keine Seitenzahlenleiste:
  „1 2 … 7 8 9 … 42" bricht auf 390 px um oder braucht Abkürzungslogik, und
  die ist eine klassische Quelle für Zählfehler. Er zeigt sich nur, wenn es
  mehr als eine Seite gibt, und auf Seite 1 steht kein `?page=1` in der
  Adresse.

#### Zwei Dinge, die erst der Browser zeigte

Der Pager landete zunächst als Geschwister von `<Section>` und damit in
`.sections` — einer Flexverteilung. Dort schrumpfte er auf seine
Inhaltsbreite, und seine Trennlinie reichte über ein Fünftel der Seite. Er
gehört unter die Tabelle und damit *in* den Bereich.

Die äusseren Spalten halten ihre Breite auch dann, wenn kein Knopf darin
steht. Ohne das rutschte „Seite 2 von 5" um eine Knopfbreite, sobald man von
Seite 1 weiterblättert — auf einer Seite, die sonst gleich bleibt, sieht das
nach einem Fehler aus.

#### Der Wächter, der gefehlt hat

`PaginationTest` prüft drei Dinge, und die dritte ist die entscheidende: Sie
liest, welche Inertia-Seite ein `Page::from()` in ihrer Nutzlast hat, und
sieht in der zugehörigen `.vue` nach, ob dort ein `<Pager>` steht. **Genau an
dieser Naht ist der Fehler entstanden** — der Controller war richtig, die
Seite ignorierte ihn, und beide für sich sahen in Ordnung aus.

Dazu zwei funktionale Prüfungen in `AuditLogTest`, die der Wächter nicht
leisten kann: dass Seite 2 andere Zeilen zeigt als Seite 1, und dass ein
Filter das Blättern überlebt.

### Die Kurve warnt wieder — das Merkmal, wegen dem „Kontor" gewählt wurde

Beim Zuschlag hiess es: „Die Gestaltung mit unterschiedlichen farbigen Badges
und den farbigen Sparklines gefällt mir sehr gut." Die Badges kamen mit dem
Rework. **Die farbigen Sparklines nicht.**

Im bedienten Muster wechselt die Kurve die Farbe, sobald der letzte Wert über
einer Schwelle liegt; der Kommentar dort nennt es „den Unterschied zwischen
bunt und bedeutend: ‚Datenträger' ist bei 91 % nicht dieselbe Auskunft wie
‚CPU' bei 23 %". `Tile.vue` hatte davon nichts — die Linie stand fest auf
`var(--accent)`.

**Warum es ein Jahr lang niemandem auffiel.** In dieser Entwicklungsumgebung
läuft kein Agent. Auf jeder Kachel stand „noch keine Messwerte", und eine
Kurve, die es nicht gibt, hat auch keine falsche Farbe. Bemerkt wurde es erst,
als zum Abschluss des Reworks zum ersten Mal jemand Messwerte in den
Ringpuffer schrieb, um die Kacheln überhaupt einmal gefüllt zu sehen.

Die Schwellen sind die des Musters — mit zwei Abweichungen, die begründet
sind:

- **Die Load rechnet mit der wirklichen Kernzahl.** Das Muster nannte 4, weil
  sein erfundener Server vier Kerne hatte. Eine feste Zahl wäre hier besonders
  falsch: Load 4 heisst auf vier Kernen „ausgelastet" und auf zweiunddreissig
  „langweilt sich". Der Agent zählt die Kerne seit P0 —
  `SystemInfo::cpu()['cores']` — und **benutzt hat sie bis heute niemand.**
- **Der Schreibdurchsatz bekommt keine.** Das Muster hatte an fünfter Stelle
  den Füllstand des Datenträgers (90 %); das Panel zeigt dort den
  Schreibdurchsatz, und für den gibt es keine Zahl, die auf zwei Servern
  dasselbe bedeutet — eine NVMe schreibt zwei Gigabyte je Sekunde, ein
  Netzlaufwerk hundert Megabyte. Eine Schwelle, die überall gilt, warnt
  entweder ständig oder nie. `null` steht dort als Angabe und mit Begründung.

**Verglichen wird der letzte Wert und nicht der höchste.** Eine Kurve, die vor
einer Stunde einmal ausgeschlagen ist und seitdem ruhig läuft, warnte sonst
für immer — und eine Warnung, die nicht mehr weggeht, liest nach dem dritten
Mal niemand.

Im Browser nachgesehen, beide Themes: CPU auf 90 % und Load auf 8,96 tragen
die Warnfarbe, RAM auf 61 %, Netz und IO bleiben im Akzent.

#### Zwei Wächter, und der zweite war nötig

`SeriesThresholdTest` prüft die Rechnung: unter der Schwelle ruhig, ab der
Schwelle Warnung, ein alter Ausschlag warnt nicht weiter, ohne Schwelle warnt
nichts, und das Feld steht auch in einer leeren Reihe.

Beim Gegenprüfen zeigte sich, dass das nicht genügt: **Kürzt jemand im
Controller das letzte Argument weg, bleibt der Unit-Test grün** und die
Übersicht zeichnet wieder jede Kurve gleich — also genau der Zustand, der
gerade behoben wurde. `PanelWalkthroughTest::test_a_tile_over_its_threshold
_says_so` schreibt deshalb echte Messwerte in den Ringpuffer und prüft die
ganze Kette: CPU über ihrer Schwelle warnt, RAM darunter nicht.

Dieser Test ist beim ersten Anlauf selbst hereingefallen: Er legte einen
eigenen `Store` mit Kapazität 100 an, während die Anwendung mit 8640 liest. Ein
RingBuffer legt seine Datei nach der Kapazität aus — dieselbe Datei, anders
gelesen, und die CPU stand auf „warnt nicht", obwohl 96 % darin standen.

### „Darstellung gespeichert" — und man stand auf der Übersicht

Wer im Konto hell auf dunkel stellte, wurde auf die Übersicht geworfen.
Gespeichert war richtig; man stand nur woanders. Dasselbe galt für „Konto
gespeichert", „Passwort geändert" und alle drei Antworten der Mailprüfung —
**sechs Stellen, ein Fehler.**

Die Ursache sind drei Dinge, die einzeln jedes für sich vernünftig sind:

1. Der Vhost des Panels schickt `Referrer-Policy: no-referrer`. Das Panel gibt
   nicht preis, von welcher Adresse jemand kam — der Browser sendet damit kein
   `Referer`.
2. `back()` fragt zuerst genau dieses `Referer` und nimmt sonst die zuletzt in
   der Sitzung vermerkte Adresse.
3. Vermerkt wird sie nur bei einem GET, das **kein** XHR ist
   (`StartSession::storeCurrentUrl`). Jede Navigation über Inertia ist eines.
   In der Sitzung steht deshalb der letzte vollständige Seitenaufruf — nach der
   Anmeldung die Übersicht.

`back()` konnte also gar nicht wissen, wohin zurück ist, und fiel auf `/`
durch. Wieder eine Zusage ohne Gegenprüfung: „zurück" verweist auf eine
Adresse, die niemand kennt.

**Beim Entwickeln war davon nichts zu sehen.** Ohne nginx gibt es die
Kopfzeile aus (1) nicht, der Browser schickt ein `Referer`, und alles stimmt.
Der Fehler entsteht erst auf dem Zielserver — dieselbe Sorte Lücke wie bei den
nginx-Vorlagen, die deshalb als Text geprüft werden.

`RedirectTargetTest` lässt `back()` in keinem Controller mehr zu und prüft
jedes Ziel ausgeschrieben. Der alte `ThemeTest` hatte den Fehler nicht
gemerkt, weil er `assertRedirect()` **ohne Ziel** aufrief — eine Zusicherung,
die nur sagt, dass überhaupt weitergeleitet wird. Beim Gegenprüfen meldete der
neue Test genau den gemeldeten Effekt: erwartet `/settings/profile`, bekommen
`http://localhost`.

### Die Netzkachel zeigt beide Richtungen

Der Sammler schreibt seit P0 **zwei** Spalten — eingehend und ausgehend. Die
Kachel zeigte eine, und die Beizeile „eingehend" war die einzige Stelle, an
der stand, dass die andere fehlt. Auf einem Webserver ist ausgehend ausserdem
die Richtung, die zuerst an die Grenze stösst: Eine Seite auszuliefern kostet
ein Vielfaches dessen, was ihre Anforderung kostet. Gezeigt wurde also die
ruhigere der beiden.

Ausgehend steht jetzt als zweite Kurve daneben: gestrichelt, in
`--accent-second` und ohne Fläche darunter.

**Der Fehler, der dabei fast entstanden wäre, sieht auf einem Bildschirmfoto
richtig aus.** `Store::series()` normiert jede Reihe auf ihr eigenes Kleinstes
und Grösstes — für **eine** Kurve richtig, für zwei in einem Feld eine Lüge:
Der eingehende Verkehr, tausendfach kleiner, läge gleich hoch und schlüge
gleich weit aus. Wer hinsieht, liest „etwa gleich viel in beide Richtungen".
Kein Testlauf hätte etwas gemeldet — beide Reihen wären da, beide mit
richtigen Zahlen daneben. Deshalb `Store::pair()` mit einer gemeinsamen
Spanne, und deshalb prüft `PairedSeriesTest` die **Geometrie**: Die kleinere
Richtung muss flach am Boden liegen, die grössere darf ihn nicht berühren.

**Die Zahlen teilen sich die Vorsilbe trotzdem nicht.** Die gemeinsame Achse
ist eine Aussage über die Darstellung; „0,0 MB/s" für 12,9 kB/s wäre eine über
den Messwert und wäre falsch. Jede Richtung bekommt ihre eigene
Grössenordnung — eine je Reihe, damit die Ablesung beim Wandern über die Kurve
nicht zwischen kB/s und MB/s springt.

#### Was die Messung im Browser erzwungen hat

Drei Entscheidungen stehen so, weil nachgemessen wurde und nicht, weil sie
schöner aussehen:

- **Byte bekommen Grössenordnungen.** Eine Kachel ist auf 1440 px 228 px
  breit, ihre Beizeile 179 px — darin passen rund 25 Zeichen.
  „65.981.645 B/s" sind vierzehn davon, und mit einem Wort davor bricht die
  Zeile um. Netz und Schreibdurchsatz zeigen deshalb `9,0 kB/s` und
  `0,7 MB/s`. Lesbar war die rohe Zahl ohnehin nie: Wer sieht einem
  neunstelligen Bytewert an, dass er 63 Megabyte bedeutet?
- **Die Kurven sitzen an der Unterkante der Kachel.** Die Netzkachel braucht
  zwei Zeilen Beizeile, die anderen eine — und damit begann ihre Kurve 20 px
  tiefer als die vier daneben. Fünf Sparklines auf zwei Höhen sehen nicht nach
  zwei Richtungen aus, sondern nach einem Fehler.
- **Der Strich rechnet in Bildpunkten.** Zweimal danebengegriffen: Die Linie
  trägt `vector-effect: non-scaling-stroke`, damit sie überall gleich dick
  ist — damit rechnet aber auch das Strichmuster im Bildschirmraum. Ein
  `stroke-dasharray: 2 1.6` sind zwei Bildpunkte, und im Bild sah das nicht
  nach einer gestrichelten Linie aus, sondern nach einer unsauber
  gezeichneten. Der Umweg über `pathLength` half aus demselben Grund nicht.

**Die Ablesung nennt eine Richtung — die, auf die man zeigt.** Beide zusammen
brauchten drei Zeilen (gemessen). Die Beizeile hält zwei frei, damit die
Kachel beim Zeigen nicht springt; in jedem Zustand und bei 1440, 1100 und
390 px sind es genau 40 px.

**Drei Unterschiede tragen die zweite Kurve und nicht einer.** Farbe,
Strichart und die Fläche, die nur die erste hat. Akzent und zweite Farbe
liegen im Helligkeitsverhältnis bei 1,85:1 (hell) und 1,49:1 (dunkel) — wer
Farbtöne schlecht unterscheidet, sähe zwei gleich helle Linien. Der Strich
löst das ohne Farbe (WCAG 1.4.1). Gegen den Grund erreicht `--accent-second`
6,18:1 hell und 11,10:1 dunkel, über den 3:1 aus WCAG 1.4.11.

### Auf dem Telefon: gestapelte Kacheln mit einem Strich an der falschen Seite

Unter 720px legt `--kachel-min: 100%` die Kacheln untereinander — der Trenner
aus `.tile + .tile` blieb aber der **linke** Rand. Auf 390px stand damit ein
senkrechter Strich neben allen Kacheln ausser der ersten, und ihr Inhalt war um
24px eingerückt: Die erste begann am Seitenrand, die vier darunter nicht. Das
sieht aus wie eine Einrückung mit Bedeutung und ist ein Trenner, der sich nicht
gedreht hat.

**Auf der eigenen 390px-Aufnahme war es zu sehen.** Gemeldet hat es der
Betreiber vom Telefon. Genau davor warnt CLAUDE.md — „im Browser nachsehen,
nicht nur bauen" —, und der Satz reicht offenbar nicht: Eine Aufnahme zu machen
genügt nicht, wenn man sie nur auf das ansieht, was man gerade geändert hat.
`MobileLayoutTest` prüft jetzt, dass ein Trenner sich mit der Richtung dreht.

Dazu ein zweiter Fund aus demselben Bild: Steht die Kachelreihe unmittelbar
unter dem Seitenkopf, standen dort **zwei Haarlinien** mit einer leeren Fläche
dazwischen. Hier ist das nie zu sehen — zwischen beiden steht in dieser
Umgebung immer die Meldung „Der Agent antwortet nicht", und die gibt es nur,
weil der Agent fehlt.

### Die Sitzung überlebte auf dem iPhone keinen Seitenaufruf

`SESSION_SAME_SITE` stand auf `strict`, mit der Begründung, es gebe keine
Anmeldung über eine fremde Seite. Das ist richtig und war trotzdem der falsche
Schluss: `strict` heisst nicht „keine fremde Anmeldung", sondern **„das Cookie
geht bei keinem Seitenaufruf mit, den der Browser nicht als von dieser Seite
ausgehend ansieht"**. Dazu gehört ein Verweis aus einem Mailprogramm, eine
Verknüpfung vom Startbildschirm — und auf iOS das Wiederaufnehmen eines Tabs,
den Safari zwischendurch aus dem Speicher geworfen hat. Der Betreiber landete
dadurch immer wieder am Anmeldeformular, obwohl seine Sitzung gültig war.

Jetzt `lax`, Laravels Vorgabe: Das Cookie geht beim Aufrufen der Seite mit und
weiter **nicht** bei einem fremden POST — und gegen genau den steht ohnehin die
CSRF-Prüfung an jeder ändernden Route.

**Und die gleitende Sitzungsdauer hatte nie jemand entschieden.** §6.4 legt die
absolute fest (12 Stunden); die gleitende stand nirgends und war damit Laravels
Vorgabe von 120 Minuten. Wer sein Panel ein paarmal am Tag vom Telefon ansieht,
meldet sich damit jedes Mal neu an. Sie steht jetzt ausgeschrieben auf acht
Stunden — ein Arbeitstag, und die absolute Grenze zieht die Sitzung ohnehin
spätestens nach zwölf ein.

### `APP_URL` erfand den Rechnernamen zum dritten Mal

`php_uname('n')` liefert den Knotennamen des Kernels, und der ist auf den
meisten Servern der kurze: „cloudsrv24" statt „cloudsrv24.de". `APP_URL` ist
die Adresse, unter der sich das Panel selbst nennt — in Mails, in erzeugten
Verweisen, im EHLO-Namen des Mailversands. Ein kurzer Name löst ausserhalb des
Servers nicht auf.

Dreimal derselbe Fehler: in `srvpanel setup` fürs Zertifikat, in
`Names::forThisHost()` für den subjectAltName, jetzt in `PanelProvision` für
`APP_URL`. Zweimal wurde er einzeln behoben, und beide Male stand danach ein
Kommentar da, der die Regel erklärt — CLAUDE.md sagt es sogar wörtlich: „sie ist
die *einzige* Stelle, die diese Frage beantworten darf. Sie ist schon zweimal
neu erfunden worden." **Ein Kommentar ist kein Wächter.**

`HostnameSourceTest` lässt `php_uname('n')` und `gethostname()` jetzt nur noch
in `Names` zu und in `SystemInfo`, das den Knotennamen bewusst als solchen
anzeigt. Beim ersten Lauf hat er eine **vierte** Stelle gefunden:
`Setup::reachableHost()` holte den Namen selbst, nur um ihn an `Names::fqdn()`
weiterzureichen — dieselbe Frage, die die Funktion sich selbst stellt.
`Names::host()` gibt es neu für alle, die eine Adresse zusammensetzen und mit
„kein Name" nichts anfangen können.

#### Der Wächter hatte selbst ein Loch, und nur der Bruch hat es gezeigt

Drei Wächter teilten sich eine abgeschriebene Zeile, um Kommentare aus PHP zu
entfernen — ein `preg_replace`, dessen zweites Muster alles ab `//` bis zum
Zeilenende strich. `//` beginnt aber nicht nur einen Kommentar, es steht auch in
jeder URL. Aus

    'APP_URL' => 'https://'.php_uname('n').':'.$port,

wurde `'APP_URL' => 'https:` — und der Aufruf, den der Wächter suchte, war für
ihn nicht mehr da. **Beim Gegenprüfen blieb er deshalb grün, während die Regel
gebrochen war.**

Das ist dasselbe Muster wie beim Bruchskript, dessen `sed` ins Leere lief:
*Ein Werkzeug, das die Wächter trägt, braucht selbst einen.* Die Antwort steht
jetzt einmal in `Tests\Support\WithoutPhpComments` und kommt von
`token_get_all()` — der Parser weiss, was Zeichenkette ist und was Kommentar.
Ein regulärer Ausdruck weiss es nie.

### Ein Aufräumen, das die Einrichtung zerbrochen hat — und was daran fehlte

`Setup::reachableHost()` holte den Rechnernamen selbst, nur um ihn an
`Names::fqdn()` weiterzureichen. Die Zeile fiel weg; die beiden Stellen weiter
unten, die dieselbe Variable benutzten, blieben stehen. Ergebnis: „Undefined
variable $name" mitten in `srvpanel setup` — nachdem Datenbank, Zertifikat,
Webserver, Migrationen und Dienste schon standen.

**Gemeldet hat es der Installationslauf der CI, nicht der Testlauf.** Und das
ist der eigentliche Befund: Kein Test ging durch diesen Zweig, PHPStan ist in
der Entwicklungsumgebung nicht lauffähig — der Fehler war lokal auf keinem Weg
zu sehen.

Zwei Dinge daraus:

- `NamesTest` ruft `reachableHost()` jetzt auf und prüft, dass die Einrichtung
  eine Adresse aus Zeichen nennt. `Names::host()` gibt notfalls `localhost`
  statt einer leeren Zeichenkette: Wer sie aufruft, baut eine Adresse, und aus
  einem leeren Namen würde `https://:8443`.
- **Ein Versuch, Warnungen rot zu machen — wieder zurückgenommen.** Der neue
  Test lief grün *mit* einer Warnung: genau der Warnung, die auf dem Server zum
  Abbruch führte. `failOnWarning` in `phpunit.xml` schien die Antwort, war hier
  grün und machte in der CI 40 Prüfungen rot — unterdrückte Warnungen aus
  `@unlink` in Aufräumcode, die **nur dort** gemeldet werden. Warum sie hier
  nicht auftauchen, ist nicht geklärt; damit lässt sich die Verschärfung vor dem
  Push nicht prüfen, und eine Regel, die man nicht prüfen kann, wird nicht
  eingeführt. Sie bleibt offen.

  Der Aufräumcode ist trotzdem geradegezogen: `ReleaseChannelTest` löscht seine
  Marke jetzt mit `is_file()` statt mit `@unlink`. Der Stille-Operator macht aus
  einem Aufruf, der scheitern darf, einen, bei dem niemand mehr sieht, dass er
  scheitert.

  **Und die ehrliche Zuordnung:** Den `$name` hat PHPStan gefunden — das ist
  das Werkzeug für undefinierte Variablen, und es hat funktioniert. Die Lücke
  war nicht der fehlende Testlauf, sondern dass hier ohne PHPStan gepusht
  wurde.

### Ein Knopf, den der Kunde nicht drücken darf, stand trotzdem da

In der Sicht eines Kunden zeigte `/subscriptions` den Knopf „Abonnement
anlegen" und in jeder Zeile „Bearbeiten". Beides ist einem Kunden verwehrt,
beides endete mit einem nackten **„403 This action is unauthorized"**.

**Die Autorisierung war dabei richtig.** Die Routen tragen `can:create` und
`can:update`, und sie haben abgewiesen — genau so, wie es sein soll. Falsch war
die Auskunft davor: Ein Knopf ist ein Angebot, und einer, der nur ablehnen kann,
ist eine Falle.

CLAUDE.md sagt „Autorisierung sitzt an der Aktion, nicht im Menü". Das ist die
Regel fürs **Durchsetzen** und war nie eine Erlaubnis, jedem alles anzubieten.
Die Kehrseite fehlte, und sie steht jetzt daneben: Wer eine Aktion zeigt, fragt
vorher dieselbe Policy, die sie später abweist.

**Die Vorlage stand schon im selben Verzeichnis.** `Subscriptions/Show`
gatterte „Domain anlegen" über `mayAddDomain` — richtig gedacht und für genau
eine der sechs Aktionen dieser Seite gemacht. Bearbeiten, Sperren, Entsperren
und Zurückbauen standen ungefragt da. Wieder eine Seite, die es an einer Stelle
richtig macht und an fünf nicht; wieder kein Werkzeug, das danach fragt.

Die Antwort kommt jetzt als `can`-Ablage im Payload — eine Form für dieselbe
Sache, auch je Zeile (`row.can.update`). **Nicht** als `v-if` auf den Kontotyp:
Das wäre eine zweite Fassung der Policy, und die zweite Fassung ist die, die
veraltet. Je Zeile und nicht je Seite, weil `SubscriptionPolicy::update()` heute
nur nach dem Kontotyp fragt — das ist eine Eigenschaft von heute und keine
Zusage.

`AbilityReachTest` prüft beide Richtungen: Jede Fähigkeit, die ein Template
unter `can.` abfragt, wird auch geschickt, und jede geschickte wird abgefragt.
Eine Fahne, die nie ankommt, ist in Vue `undefined` — der Knopf verschwindet
dann für **alle**, lautlos. Dazu zwei Läufe am Panel: Ein Kunde bekommt überall
`false`, ein Betreiber überall `true`.

**Und der Wächter hat gleich eine falsche Annahme von mir widerlegt.** Der erste
Anlauf erwartete, dass ein Kunde auch den Verweis auf „seinen" Kunden nicht
sehen darf. `CustomerPolicy::view` lässt ihn für den eigenen Datensatz und
dessen Untergeordnete zu — die Policy hat recht, die Erwartung war falsch. Der
Verweis bleibt, und ein Zusatzbenutzer ohne eigenen Kunden sieht dort nur noch
den Namen.

Im Browser gegengeprüft: Als Kunde steht auf `/subscriptions` **kein** Knopf und
am Abonnement nur „Domain anlegen" (das darf er). Als Betreiber steht alles da,
was vorher da war.

### Der Weg zu einer neuen Domain war drei Klicks lang und der letzte versteckt

Ein Kunde erreichte „Domain anlegen" nur so: Menüpunkt **Abonnements** → auf den
**Namen** des Abonnements klicken → im Bereich „Domains" rechts einen kleinen
Knopf finden. Drei Klicks für die Sache, wegen der er das Panel überhaupt
öffnet, und der letzte davon in einer Zeile, die man kennen muss.

**Die Liste `/domains` gab es für ihn längst.** `DomainPolicy::viewAny` lässt
jedes Konto durch, und was darauf steht, entscheidet die Mandantenklammer — ein
Kunde sieht seine Domains, der Betreiber alle. Gefehlt haben zwei Dinge: der
Menüpunkt und ein Knopf an der Stelle, an der man ihn sucht.

Beides ist jetzt da. Der Menüpunkt steht bei einem Kunden erst, **wenn es ein
aktives Abonnement gibt** — ohne eines gibt es keinen Ort, an dem eine Domain
entstehen könnte, und der Eintrag führte auf eine leere Liste ohne Knopf. Das
ist eine Sackgasse mit Einladung.

**Die Abkürzung bekommt nur der Kunde**, und das ist Absicht: Sie führt in ein
bestimmtes Abonnement, und der Betreiber hat davon Hunderte — eine Auswahlliste
über alle wäre kein kurzer Weg, sondern ein langer mit Suchfeld. Seine Wege
bleiben unverändert. Bei genau einem Abonnement führt der Knopf direkt hin, bei
mehreren steht eine Auswahl davor; die Auswahl immer zu zeigen wäre die
einfachere Fassung und die schlechtere, denn wer ein Abonnement hat, müsste erst
das einzige auswählen, das es gibt.

`SubscriptionStatus::usableValues()` gibt es dafür neu — abgeleitet aus
`usable()` und nicht daneben geschrieben. Ein `whereIn('status', ['active'])` im
Controller wäre eine zweite Fassung derselben Regel, und beim nächsten
benutzbaren Zustand zöge nur eine von beiden mit.

### Zeichen im Menü, und Trennüberschriften, die trennen

Die Menüpunkte trugen kein Symbol, und die Überschriften „Verwaltung",
„Server" und „Konto" hoben sich zu wenig von ihnen ab: Sie waren kleiner und
blasser, sonst nichts. In einer Spalte aus lauter kurzen Wörtern reicht ein
Größenunterschied nicht, um „Überschrift" von „Menüpunkt" zu trennen.

Jeder Eintrag hat jetzt ein Zeichen — zwölf Pfade in `NavIcon.vue`, ein Raster,
eine Strichstärke, kein Füllen. **Keine Symbolbibliothek:** Zwölf Zeichen sind
zwölf Zeilen Pfad; ein Paket dafür wäre eine Abhängigkeit, ein Bündel und eine
Auswahl von tausend Symbolen, aus der beim nächsten Menüpunkt jemand ein anderes
Stilmittel greift. Sie erben ihre Farbe über `currentColor`, laufen also mit dem
aktiven Eintrag in den Akzent, und tragen `aria-hidden`: Neben jedem steht sein
Wort, das Zeichen ist Wiedererkennung und kein Ersatz (WCAG 1.1.1).

Die Überschriften bekommen eine Haarlinie darüber und werden **blasser** statt
lauter. Eine Überschrift, die lauter wird, zieht den Blick von dem weg, worum es
geht; die Linie leistet das Abheben, die Farbe nimmt sich zurück. Gerechnet
gegen `--nav-bg`: 4,63:1 hell, 5,31:1 dunkel — über den 4,5:1 aus WCAG 1.4.3 und
im hellen Theme knapp, weshalb die Linie den Unterschied trägt und nicht die
Farbe. Den dritten Teil des Unterschieds leisten die Zeichen: Die Einträge haben
eines, die Überschriften nicht, und das trägt schon im Umriss.

`NavIconTest` hält die Kette zusammen: Jeder Menüpunkt trägt ein `icon:`, jeder
Name zeigt auf eine Zeichnung, jede Zeichnung wird benutzt, und im Zeichensatz
steht kein Farbwert. Ohne ihn ist `<NavIcon name="domain" />` eine Zeichenkette,
die auf nichts zeigt — die Komponente zeichnet dann nichts, kein Fehler, keine
Meldung, nur ein Eintrag ohne Punkt davor.

### Der Punkt am Ende jeder Kurve war eine Ellipse — und eine halbe dazu

Gemeldet vom Betreiber mit einem Bild: „Die Punkte der einzelnen Messwerte sind
extrem groß und platt gedrückt." Genau so war es, und zwar aus zwei Gründen
gleichzeitig.

**Erstens die Form.** Das Feld der Kachel ist 100 × 32 Einheiten und wird mit
`preserveAspectRatio="none"` auf rund 204 × 46 Bildpunkte gezogen — waagerecht
gut zweieinhalbmal so stark wie senkrecht. Ein `<circle r="2">` wird darin
**4,6px breit und 2,9px hoch**. Das ist keine Ungenauigkeit, das ist die
Rechnung.

**Zweitens die Kante.** Die letzte Stützstelle liegt bei x = 100, also genau auf
dem rechten Rand des Feldes, und ein `<svg>` schneidet dort ab. Vom Punkt blieb
die linke Hälfte — und eine halbe liegende Ellipse liest sich als Klotz.

Der Punkt entsteht jetzt aus der **Kappe eines Strichs**: ein kurzer Pfad mit
`stroke-linecap: round` und `vector-effect="non-scaling-stroke"`. Damit ist sein
Durchmesser die Strichstärke in Bildpunkten — unabhängig davon, wie das Feld
gezogen wird. Die Länge des Pfades ist nicht null, sondern ein Tausendstel: Ein
Teilpfad ohne Ausdehnung wird zwar laut Norm mit runder Kappe gezeichnet, aber
nicht jeder Renderer hält sich daran. Und `overflow: visible` lässt die zwei
Bildpunkte über die Kante ragen; sie landen im Innenabstand der Kachel, gemessen
ohne waagerechten Überlauf bei 1440 und 390 px.

**Das ist der zweite Fehler dieser Art in derselben Kachel.** Der erste war das
Strichmuster der zweiten Netzkurve: `stroke-dasharray` in Nutzerkoordinaten wurde
auf flachen Stücken lang und auf steilen gestaucht. Zweimal dieselbe Ursache,
zweimal erst auf einem fremden Bildschirm gesehen — deshalb jetzt
`SparklineShapeTest`: Wer ein Feld ungleich zieht, zeichnet darin nichts Rundes
in Nutzerkoordinaten, und jede Zeichnung mit Strich sagt, dass ihre Stärke in
Bildpunkten gilt.

### Eine Übergabe, die das Chatfenster überlebt

`docs/32-uebergabe-p4.md` beantwortet die Fragen, die am Anfang von P4 ohnehin
gestellt werden: was für TLS schon dasteht, was fehlt, welche Entscheidungen
der Betreiber treffen muss — und die zwei Dinge, die man dabei falsch machen
kann, ohne es zu merken.

**Warum das ein Dokument ist und keine Notiz im Chat.** Eine Übergabe, die im
Sitzungsfenster steht, verschwindet mit dem Fenster. Die nächste Session sieht
in `docs/` nach; dort gehört sie hin.

Zwei Dinge stehen darin, die man aus dem Plan allein nicht sieht, weil sie aus
dem Quelltext gelesen sind:

- **Die Kundenvorlage trägt HTTP-01 schon halb.** Sie hört auf Port 80, und
  `.well-known` ist vom Punktdatei-Schutz ausgenommen — mit einem Kommentar,
  der P4 namentlich nennt. Was dort fehlt, ist `ssl_certificate` und die
  Weiterleitung auf HTTPS: Eine Kundendomain spricht heute Klartext.
- **HSTS kommt mit dem ersten vertrauten Zertifikat von selbst — aber nur,
  wenn der Server-Block danach neu geschrieben wird.** Wer ein ACME-Zertifikat
  einspielt, ohne `panel.vhost.apply` zu rufen, bekommt ein vertrautes
  Zertifikat ohne den Header. Der harmlosere der beiden Ausgänge, und genau
  deshalb bemerkt ihn niemand.

Dazu die Erinnerung, die sonst zwischen zwei Freigaben verlorengeht: Die
Sitzungswerte aus `rc.2` stehen erst nach `srvpanel setup` in `panel.env` —
`srvpanel update` schreibt diese Datei nicht.

### Ein Abnahmelauf, der nicht jedes Mal neu erfunden wird

`docs/33-abnahme-0.3.1.md` ist der Prüfweg für `0.3.1-rc.3` auf einem echten
Server — Schritt für Schritt, mit dem, was dastehen muss, und einem
Ergebnisblock zum Ausfüllen.

**Warum er vor P4 steht und nicht danach.** P4 fasst das Zertifikat der
Oberfläche, den Server-Block des Panels und die Vorlage der Kundendomains an.
Das sind genau die Stellen, an denen der Optik-Rework nie unter echten
Bedingungen gelaufen ist; abgenommen wurde P3 aus `0.3.0~rc.5`. Ohne den
Nachweis hätte jeder Fehlschlag in P4 zwei mögliche Ursachen, und die
Unterscheidung kostet mehr als der Lauf.

Drei Dinge stehen darin, die man beim Fahren sonst falsch macht:

- **Zuerst `panel.env` ansehen.** `srvpanel update` schreibt die Datei nicht
  neu. Wer von `0.3.0` nur aktualisiert hat, prüft die Sitzung auf dem Telefon
  gegen `SESSION_SAME_SITE=strict` — also gegen den Stand vor `rc.2` und nicht
  gegen den, der abgenommen werden soll.
- **Erst das Maschinelle, dann das Sichtbare.** Wer die Kacheln ansieht,
  während `acceptance-web` läuft, misst die Last des Abnahmelaufs und weiß
  hinterher nicht, was er gesehen hat.
- **RAM nicht mit Gewalt auf 85 % treiben.** Der naheliegende Weg dorthin endet
  beim OOM-Killer, und der sucht sich sein Opfer selbst. CPU und Load lassen
  sich gefahrlos auslösen (`yes` je Kern, danach `pkill -x yes`) und laufen
  durch denselben Code — was dort warnt, warnt bei RAM auch.

### Der Abnahmelauf für 0.3.1 ist durch — grün

Gefahren am 5. August auf dem Zielserver, nach `docs/33`: beide
Abnahmekommandos, die Schwellen der Verlaufskacheln unter echter Last (CPU und
Load wechseln in die Warnfarbe und gehen danach zurück), Übersicht und
Zertifikatsseite mit laufenden Diensten, die Sitzung auf dem Telefon nach fünf
Minuten Bildschirmsperre, die Kundensicht, das Blättern mit gesetztem Filter.

**Was das für P4 wert ist.** Der Rework hat `app/` angefasst, abgenommen war P3
aus `0.3.0~rc.5`. Ohne diesen Lauf hätte jeder Fehlschlag in P4 zwei mögliche
Ursachen gehabt, und die Unterscheidung wäre teurer gewesen als der Lauf. Ab
hier kommt, was bricht, aus P4.

### P4 — TLS

Erster Wurf: ACME über HTTP-01, das Zertifikat der Oberfläche über dieselbe
Strecke, die Kundenvorlage mit HTTPS. DNS-01 mit mehreren Anbietern und das
Hochladen eigener Zertifikate sind der zweite Wurf. Die Entscheidungen und ihre
Begründung stehen in `docs/32 §6`.

#### Schritt 1: der ACME-Kern im Agenten

Ein eigener Client statt `certbot` — reines PHP über die openssl-Erweiterung,
unter `agent/src/Acme/`. **Der Ausschlag gab nicht die Codemenge, sondern die
zweite Wahrheit:** certbot führt Zustand in `/etc/letsencrypt` und einen eigenen
Timer, das Panel führt beides ebenfalls, und verbunden wären sie durch einen
Pfad in einer nginx-Datei, den niemand nachprüft. Das ist wörtlich das Muster,
an dem dieses Projekt sechsmal verloren hat.

Drei Punkte kamen beim Nachsehen dazu, und jeder allein hätte gereicht:
`php8.4-curl`, `ca-certificates` und `openssl` sind längst Abhängigkeiten des
Pakets — ein eigener Client braucht **kein** neues. certbot liefe als
Kindprozess des Agenten unter dessen Härtung, also mit
`MemoryDenyWriteExecute=yes`, und an genau dieser Sorte Einschränkung ist hier
schon einmal `dpkg` zerbrochen; gefunden hat das kein Test, sondern der erste
Lauf auf einem echten Server. Und die certbot-Fassungen der vier Zielplattformen
spreizen von 1.21 auf Ubuntu 22.04 bis zu deutlich neueren auf Debian 13 —
weiter als nginx 1.18 gegen 1.25, woran die Einrichtung schon einmal fast
gescheitert wäre.

**`RS256` und nicht `ES256`.** Bei einem EC-Schlüssel liefert `openssl_sign` die
Signatur in DER-Kodierung, JWS verlangt die beiden Zahlen roh hintereinander —
dazwischen liegt ein kleiner ASN.1-Parser an der heikelsten Stelle des Clients,
wo ein Fehler nicht abbricht, sondern als „unauthorized" von der Gegenseite
zurückkommt. Mit RSA gibt `openssl_sign` genau das Format aus, das hineingehört.
Das ausgestellte Zertifikat ist davon unberührt.

**Die Naht für den zweiten Wurf ist von Anfang an da.** Der Ablauf einer
Bestellung enthält keine Fallunterscheidung nach der Art der Prüfung; das
erledigt die Schnittstelle `Challenge` mit `present`, `ready` und `cleanup`.
`ready()` gibt es, obwohl HTTP-01 es nicht braucht: Ein TXT-Eintrag ist nicht
da, weil die API des Anbieters „ok" gesagt hat, sondern erst, wenn die
autoritativen Nameserver ihn ausliefern — und wer zu früh prüfen lässt,
verbrennt einen der fünf Fehlversuche, die eine Stunde halten. Nachträglich
eingezogen hätte dieser Schritt die Form jeder Operation geändert, die eine
Bestellung fährt.

**Ein gemeinsames Prüfverzeichnis für alle Domains**, nicht eines je Abonnement.
Damit braucht kein Kunde irgendwo Schreibrechte, und eine Domain **ohne
DocumentRoot** bekommt trotzdem ein Zertifikat — eine Weiterleitung beantwortet
jede Anfrage selbst, ein gesperrtes Abonnement antwortet mit 503, und beide
wären sonst dauerhaft von TLS ausgeschlossen. Dass die Vorlage das heute nicht
hergibt, ist Schritt 3.

`certbot` ist aus der Positivliste des Runners entfernt. Ein Programm, das der
Agent als root starten darf und nie startet, ist Angriffsfläche mit
Erlaubnisschein — und seine Erneuerungsdateien dürfen Hooks nennen, die bei
jedem Lauf als root laufen.

**`AcmeProtocolTest` prüft auf zwei Arten**, und die zweite ist die, die zählt.
Der Fingerabdruck des Kontoschlüssels wird gegen den Testvektor aus RFC 7638
gemessen — dieselbe Begründung wie bei TOTP: gegen den Standard belegen und
nicht gegen sich selbst. Alles, was Ablauf ist, läuft gegen ein Drehbuch
(`ScriptedTransport`): die Reihenfolge der Anfragen, der verbrauchte
Einmalwert, der leere Rumpf, das Abräumen nach einem Fehlschlag. Gegen einen
echten Server geprüft hiesse Netz in der CI, eine Ratenbegrenzung, die den Lauf
sperrt — und keine Möglichkeit, den seltenen Fall herzustellen.

Zwei Funde stammen aus dem Brechen selbst und nicht aus dem Bauen:

- **Der leere Rumpf ist `{}` und nicht `[]`.** `json_encode([])` schreibt `[]`,
  und ACME antwortet darauf mit „malformed" — an der einen Stelle, an der ein
  leerer Rumpf vorkommt, beim Anstossen einer Prüfung. Aufgefallen wäre das erst
  auf dem echten Server, denn ein Drehbuch antwortet ja trotzdem. Die
  Sonderbehandlung steht bewusst in `sign()` und nicht in `json()`: Dort träfe
  sie auch verschachtelte leere Listen, und aus `"contact": []` würde
  `"contact": {}` — wieder „malformed", nur an einer Stelle, an der niemand
  danach sucht.
- **Der Testvektor allein bewacht die Feldreihenfolge nicht.** Er prüft
  `thumbprintOf()` mit einem JWK aus dem RFC, bringt die Ordnung also selbst
  mit. Wer sie in `jwk()` umstellt, kommt daran vorbei: Der Fingerabdruck wäre
  falsch, der Vektor bliebe grün. Gemerkt hat das erst der Bruch — die zweite
  Prüfung an der Stelle, an der die Reihenfolge entsteht, gibt es seitdem.

Die sechs Brüche stehen in `tests/waechter-brechen.sh`. Das Skript sichert
jetzt auch `agent/`; ausserdem trägt es einen Hinweis, den es brauchte: `git
checkout` stellt nur wieder her, was git kennt — ein Bruch an noch nicht
eingechecktem Code löscht ihn, statt ihn zu brechen.

#### Nachtrag aus der ersten CI-Runde

Pint meldete eine Datei mit zwei Regelverstössen, und der zweite hat mehr
verändert als eine Formatierung.

Der erste war schlicht: `{@see \SrvPanel\Agent\Runner}` im Klassenkommentar von
`CurlTransport` — `fully_qualified_strict_types` fasst auch `@see` an und will
den Namen importiert und kurz.

Der zweite war `unary_operator_spaces`, und der Weg dorthin ist der Teil, der
sich zu erzählen lohnt. Pint kürzt seine Meldung ab, PHPStan lief wegen des
Abbruchs gar nicht erst, und hier gibt es kein `vendor/`, mit dem sich das
nachstellen liesse. Statt zu raten: der Tokenizer von PHP, auf dem der Fixer
selbst arbeitet. Er entlastete beide `!` und das binäre `+` und liess drei
`&`-Referenzen übrig — die Closures, die Kopfzeilen und Rumpf über
`use (&$…)` einsammelten.

**Die Antwort war nicht, ein Leerzeichen zu verschieben.** Die drei Referenzen
waren das Symptom: Der Deckel auf der Antwortgrösse stand als Bedingung mitten
in der Konfigurationsablage von curl, zusammen mit zwei weiteren Zuständen. Er
liess sich damit nur befragen, indem man eine Gegenstelle baut, die zuviel
schickt — also gar nicht. Er war eine Zusage ohne Wächter, drei Tage nachdem
das Projekt sich vorgenommen hat, keine mehr zu bauen.

`ResponseBuffer` nimmt jetzt Kopfzeilen und Rumpf auf und trägt die Regel als
Methode mit Rückgabewert. Die Closures kommen ohne `&` aus, der Deckel hat einen
Test, und der Bruch dazu steht im Skript. Dass Pint den Anstoss gab, ändert
nichts daran, dass die Stelle vorher schlechter war.

#### Schritt 2: das Zertifikat als eigener Gegenstand

Ein Zertifikat ist eine Zeile mit einer **Namensliste**, an der Domains hängen
— nicht eine Spalte an der Domain. Das ist die Entscheidung, die nachträglich
am teuersten wäre: Ein Wildcard `*.example.de` gehört zu keiner einzelnen
Domainzeile, und mit der umgekehrten Modellierung bräche der zweite Wurf das
Datenmodell auf, samt Mandantenklammer und Migration.

**Zwei getrennte Angaben, und das ist Absicht.** `certificates.names` sagt, was
das Zertifikat *behauptet*; `domains.certificate_id` sagt, was nginx für diese
Domain *ausliefert*. Aus dem einen auf das andere zu schliessen ist genau der
Fehler, der eine Namenswarnung im Browser erzeugt — und die bemerkt niemand,
weil die Seite lädt.

**`subscription_id` darf null sein**, denn das Zertifikat der Oberfläche gehört
keinem Kunden. Für einen Kunden ist es damit unsichtbar: `null` ist kein
Treffer in einem `where subscription_id in (…)`. Wer eines anlegt, muss die
Null ausdrücklich hinschreiben — sonst trägt `BelongsToSubscription` den gerade
aktiven Mandanten ein, dieselbe Falle wie bei einem Vorgang des Betreibers, der
aus einer Kundenanfrage heraus entsteht.

**`nullOnDelete` und nicht `cascadeOnDelete`.** Verschwindet ein Zertifikat,
verliert die Domain ihren Verweis — mitgehen darf sie nicht. Ein `cascade`
hätte eine Domain gelöscht, weil ihr Zertifikat abgelaufen ist.

`CertificateCoverageTest` prüft die Regel, an der man sich verrechnet: Ein
Platzhalter deckt **genau eine Beschriftung**. `*.example.de` gilt für
`www.example.de`, aber weder für `example.de` noch für `a.b.example.de` — und
ohne den Punkt im Vergleich deckte er auch `notexample.de`. Elf Fälle als
Tabelle.

**Die Regel steht im Modell und nicht beim Aufrufer.** `Domain` lässt eine
Zuordnung ohne Deckung gar nicht erst zu; es wird davon drei Aufrufer geben
(Einspielen, Erneuern, Hochladen), und drei Stellen mit derselben Prüfung sind
zwei Gelegenheiten, sie zu vergessen. Geprüft wird nur beim Setzen — ein
Speichern, das den Verweis nicht anfasst, kostet keine Abfrage.

**Und die Prüfung liest ohne Mandantenklammer.** Sie steht im Grundzustand auf
„nichts", und in einem Kommando oder einem Job ist das der Normalfall; ein
gewöhnliches `find()` lieferte dort `null`, und der Wächter hätte eine richtige
Zuordnung abgewiesen — *ein Wächter, der beim Arbeiten zubeisst, wird beim
Arbeiten abgeschaltet.* Dasselbe Muster hat drei Wächter des Optik-Reworks
getroffen; es hat hier einen eigenen Testdurchgang.

**Was in Schritt 2 bewusst fehlt: die Policy.** `PolicyReachTest` verlangt zu
jeder Fähigkeit einen Weg — eine Route, ein `authorize`, ein `can`. Die Routen
entstehen mit der Oberfläche; eine `CertificatePolicy` jetzt hiesse, vier
Einträge in die Ausnahmeliste zu schreiben, deren Begründung „kommt noch"
wäre. Genau davor warnt der Kommentar an dieser Liste: Sie wächst über Jahre,
und irgendwann steht darin, was jemand nicht nachziehen wollte.

#### Schritt 3: die Prüfadresse kommt überall an

**`docs/32` hat sich an dieser Stelle geirrt, und der Irrtum wäre teuer
geworden.** Dort stand, die Kundenvorlage trage HTTP-01 schon halb: Sie hört
auf Port 80, und `.well-known` ist vom Punktdatei-Schutz ausgenommen. Diese
Ausnahme steht aber nur im **ausliefernden** Zweig. Eine Weiterleitung
beantwortet jede Anfrage mit `return 302` und sucht nie eine Datei; ein
gesperrtes Abonnement antwortet mit 503. **Beide hätten nie ein Zertifikat
bekommen** — dauerhaft, und ohne dass irgendwo etwas gemeldet hätte.

Der `location`-Block entsteht deshalb **einmal, oberhalb der
Fallunterscheidung**. Was strukturell nicht vergessen werden kann, muss später
niemand nachtragen.

**Und die Oberfläche bekommt einen eigenen Block auf Port 80.** Sie hört auf
8443 und sonst nirgends; die Prüfung fragt immer über Port 80. Ohne diesen
Block bekäme ausgerechnet das Panel nie ein Zertifikat, während jede
Kundendomain eines bekommt. Er trägt den **Rechnernamen** und nicht `_`: Ein
`server_name _;` trifft keinen echten Host-Header, er wirkte nur als
Vorgabeserver — und der ist auf Port 80 längst vergeben, weil nginx
`conf.d/srvpanel-sites.conf` vor `conf.d/srvpanel.conf` liest. Der Name kommt
aus `Names::host()`, der einzigen Stelle, die diese Frage beantworten darf.

**Der Schnipsel steht in `HttpChallenge` und nicht in den beiden Vorlagen.**
Ablageort und ausliefernde Zeile sind eine Zusage; wer das Verzeichnis ändert
und eine Vorlage vergisst, bekommt keine Fehlermeldung, sondern eine Prüfung,
die nichts findet. Zwei Formulierungen derselben Regel sind der Fehler, der
dieses Projekt sechsmal getroffen hat.

**`root` und nicht `alias`** — die Stelle, die still danebengeht. `root` hängt
den *ganzen* Pfad aus der Adresse an das Verzeichnis an, und genau dorthin
schreibt `HttpChallenge::present()`. Mit `alias` läge der gesuchte Pfad zwei
Ebenen höher als der geschriebene, und die Prüfung scheiterte mit
„unauthorized" — einer Meldung, in der von Pfaden nichts steht.
`ChallengeLocationTest` hält beide Hälften zusammen: Es schreibt eine echte
Prüfdatei und misst, dass der Teil unterhalb des Verzeichnisses genau der Pfad
aus der Adresse ist.

Dazu `^~`, damit der Präfix jede Regex-Regel schlägt — sonst entschiede
`location ~ /\.` über die Prüfadresse und verweigerte sie. Der
Punktdatei-Schutz bleibt trotzdem stehen: Im DocumentRoot liegen weitere
`.well-known`-Dateien, die abgerufen werden sollen.

Drei Brüche im Wächterskript, alle gegengeprüft: `alias` statt `root`, die
Prüfadresse aus der Kundenvorlage entfernt, der Panel-Block wieder mit `_`.

#### Nachtrag: zwei Pint-Befunde aus Schritt 2

`fully_qualified_strict_types` an zwei Stellen, dazu je eine Folgeregel. Die
Ursache waren voll ausgeschriebene Klassennamen in `{@see …}`; sie stehen jetzt
als Import da und im Text nur noch kurz. Dieselbe Stelle in `HttpChallenge` ist
gleich mitgegangen, bevor sie in der nächsten Runde auffällt.

**Und die Längenrechnung in `Certificate::covers()` ist ausgeschrieben.** Sie
stand als `substr($wanted, 0, -strlen($suffix))` da — ein unäres Minus, und
`unary_operator_spaces` hatte etwas daran auszusetzen. Was jetzt dasteht,
`strlen($wanted) - strlen($suffix)`, sagt dasselbe und liest sich als das, was
es ist: die Länge ohne den Platzhalterteil. Die elf Deckungsfälle sind
unverändert grün.

#### Schritt 4: die Operationen — und die Regel, die niemand bemerkt

Drei Operationen im Agenten: `acme.account.ensure`, `acme.certificate.issue`
und `acme.certificate.info`. Der Kontoschlüssel und der Schlüssel des
Zertifikats entstehen dort und überqueren den Socket nie; zurück geht, was auch
jeder Browser sieht.

**Das Panel nennt einen Schlüssel, keine Adresse.** `Directories` ist eine
Positivliste mit zwei Einträgen — Testbetrieb und Produktion. Nähme der Agent
eine URL entgegen, wäre die Anwendung eine Fernsteuerung dafür, wohin ein
Prozess als root eine TLS-Verbindung aufbaut und wem er den Fingerabdruck
seines Kontoschlüssels zeigt; die Prüfung „fängt mit https an" wäre dabei keine
Schranke, sondern eine Formalie. Dieselbe Entscheidung wie im Aufgabenkatalog
des Panels und bei der Programm-Positivliste des Runners. **Ohne Angabe gilt
der Testbetrieb** — ein vertippter Wert, der still produktiv landet, ist der
Weg in eine Sperre, die Stunden hält.

**Wer ein Zertifikat einspielt, schreibt danach den Server-Block neu.** Das ist
die Falle aus `docs/32 §8`, und sie ist die unangenehme Sorte: Es bricht nichts
ab. Der Block entsteht bei `web.site.apply`, und ob `Strict-Transport-Security`
darin steht, entscheidet sich an dem Zertifikat, das dabei gelesen wird. Wer
ein vertrautes Zertifikat ablegt und die Operation nicht ruft, bekommt ein
vertrautes Zertifikat **ohne** den Header — die Seite läuft, das Protokoll ist
leer, und niemand sucht danach.

**Bestellt wird von selbst, und der Auslöser ist kein Knopf.** Das
Abnahmekriterium der Stufe verlangt ein Zertifikat „ohne Zutun des Admins";
der Auslöser ist deshalb der fertige Server-Block. Vorher ist die Domain über
Port 80 nicht erreichbar, und die Prüfung könnte gar nicht gelingen.

**Und die beiden zusammen wären eine Schleife** — Bestellung, Zuordnung, Block
neu, Bestellung. Dass sie aufhört, ist keine Beobachtung, sondern eine Zusage:
Bestellt wird nur, wenn die Domain noch kein Zertifikat hat, und die Zuordnung
passiert **vor** dem neuen Block. `CertificateReapplyTest` hat dafür einen
eigenen Durchgang; ohne ihn liefe eine Warteschlange, bis die Ratenbegrenzung
sie anhält.

**Ohne Kontaktadresse bestellt das Panel nichts.** Der naheliegende Weg wäre,
die Adresse des ersten Adminkontos zu nehmen — und das wäre geraten. Sie ist
die Stelle, an die Let's Encrypt schreibt, wenn ein Zertifikat abzulaufen
droht; sie gehört gesetzt und nicht abgeleitet.

Dazu, aus dem Bauen: **Platzhalter werden abgewiesen** (über HTTP-01 gibt es
kein Wildcard, und die Zertifizierungsstelle antwortet darauf mit einer
Meldung, die das nicht sagt), **höchstens fünf Namen je Bestellung** (eine über
hundert scheitert an einem einzigen nicht auflösbaren Namen und nimmt
neunundneunzig mit), und `Domain::serverNames()` als die eine Liste der Namen,
unter denen ein Block antwortet — ein Zertifikat, das nur den ersten deckt,
warnt bei jedem Alias.

#### Schritt 4b: die beiden Lücken, ohne die auf dem Server nichts passiert

Nach Schritt 4 stand die ganze Strecke — und ein Server hätte trotzdem kein
einziges Zertifikat ausgeliefert. Zwei Enden fehlten, jedes für sich still.

**Die Kontaktadresse liess sich nicht setzen.** Ohne sie bestellt das Panel
nichts, das Formular dafür kommt mit der Oberfläche in Schritt 6, und dazwischen
sähe TLS aus wie kaputt: Es passiert nichts, und nichts meldet sich.
`srvpanel tls --contact=… --directory=staging|production` schliesst die Lücke an
dem Kommando, das ohnehin so heisst. Beides wird **dort** geprüft und nicht erst
beim Bestellen — eine Adresse, die keine ist, fiele sonst erst auf, wenn ein
Kunde eine Domain anlegt, und dann als Vorgang, der ohne Zutun scheitert. Der
Schlüssel der Zertifizierungsstelle geht durch dieselbe Positivliste, die auch
der Agent befragt. Ein Lauf mit Optionen fragt den Agenten nicht: Wer etwas
einträgt, will nichts erneuern.

**Zusammengelegt und nicht ersetzt.** Beide Angaben liegen unter demselben
Schlüssel, und dort wird künftig der Zugang eines DNS-Anbieters dazukommen. Ein
`updateOrCreate` mit der halben Ablage löschte die andere Hälfte — lautlos, und
danach steht die Zertifizierungsstelle richtig da, während die Kontaktadresse
fehlt und nichts mehr bestellt wird.

**Und die Kundenvorlage kannte `ssl_certificate` nicht.** Ein ausgestelltes
Zertifikat lag damit im Ablageort und wurde von niemandem ausgeliefert.
Sie hat jetzt einen zweiten Server-Block auf 443, und Port 80 beantwortet nur
noch die Prüfadresse und leitet dauerhaft weiter (`301`, nicht `302` — die
Umstellung ist nicht vorläufig).

**Ob es einen gibt, sieht der Agent selbst nach.** Der Pfad kommt nicht aus der
Anwendung: Bei `ssl_certificate` wäre das dieselbe Freiheit wie bei `root` — die
Erlaubnis, eine beliebige Datei des Servers zu benennen. Und daran hängt der
dritte Teil des Abnahmekriteriums: **Ohne Zertifikat keine Weiterleitung.** Eine
Domain, die auf HTTPS umleitet, während auf 443 niemand hört, ist nicht „noch
nicht gesichert" — sie ist weg, und genau das passierte bei jeder gescheiterten
Bestellung.

**Ein halbes Zertifikat ist keines.** Bricht ein Lauf zwischen den beiden
Schreibvorgängen ab, liegt die Kette da und der Schlüssel nicht. Ein
`ssl_certificate` ohne `ssl_certificate_key` lässt nginx **nicht starten** —
dann steht nicht eine Domain still, sondern der Webserver mit allen. `Store`
antwortet deshalb mit beiden Pfaden oder mit nichts.

**Kein `http2` und kein `ssl_stapling`, beides mit Absicht.** Die eigenständige
`http2`-Direktive gibt es erst seit nginx 1.25.1; davor ist HTTP/2 ein Parameter
an `listen`. Drei der vier Zielplattformen bringen die ältere Fassung mit, und
die falsche Schreibweise macht die Einrichtung unmöglich — dieselbe Wette, an
der P0 schon einmal fast gescheitert wäre. Sie kommt, wenn die Abfrage aus
`PanelVhost` an einer Stelle steht, die beide Vorlagen fragen. Und Let's Encrypt
hat OCSP eingestellt: Eine Direktive, die auf eine Adresse zeigt, die im
Zertifikat nicht mehr steht, ist eine Zeile ohne Wirkung.

Der Reihenfolgetest ist dabei der eigentliche. Beide Blöcke tragen
`server_name`, `access_log` und `error_log`; ein Ausdruck, der nur nach dem
Vorkommen von `root` fragt, wäre auch dann grün, wenn der Inhalt im
**unverschlüsselten** Block stünde — auf beiden Adressen antwortet ja etwas.
Geprüft wird deshalb, dass er genau einmal dasteht und hinter `listen 443`.

Drei weitere Brüche im Wächterskript: die Weiterleitung ohne Zertifikat, das
halbe Zertifikat, die ersetzte statt zusammengelegte Einstellung.

#### Nachtrag: der Zähler stand auf dem Bestand

`CertificateReapplyTest::test_the_two_rules_do_not_chase_each_other` war rot,
während die Regel hielt. Der Durchgang spielt zuerst ein Zertifikat ein — und
der Vorgang, mit dem er das tut, heisst selbst `acme.certificate.issue` und
steht in derselben Tabelle wie das, wonach danach gesucht wird. Erwartet waren
null Bestellungen, dagestanden hat die eigene aus dem Aufbau.

Das ist wörtlich die Falle, die in CLAUDE.md unter „drei Wächter des
Optik-Reworks" steht, nur andersherum: Wer zählt, muss zählen, was
**dazukommt**. Gemessen wird jetzt der Zuwachs. Der Bruch dazu beisst
unverändert — ohne die Bedingung im Lebenslauf kommt eine zweite Bestellung
hinzu, und genau das ist der Unterschied, den der Zähler sehen soll.

#### Nachtrag: zwei Namen, die der Basisklasse gehören

An einem Tag zweimal dieselbe Sorte Fehler, und beide brechen beim **Laden** der
Klasse statt beim Ausführen: `count()` in einem PHPUnit-Testfall — dort ist der
Name `final`, die Datei liess sich nicht einmal einlesen — und `configure()` als
private Hilfsmethode in einem Artisan-Kommando, wo Symfony ihn `protected`
belegt. Der zweite hielt nicht ein Kommando an, sondern `artisan` mit allen: Die
Klasse wird beim Einlesen der Kommandos geladen, also schon bei
`package:discover`.

`php -l` sieht davon nichts, und hier gibt es kein `vendor/`, mit dem sich das
nachstellen liesse — beide haben je eine Runde CI gekostet. Die Regel steht
jetzt in CLAUDE.md: Wer in einer abgeleiteten Klasse eine private Hilfsmethode
einzieht, sieht vorher in der Basisklasse nach.

#### Schritt 5: Erneuerung, HSTS — und der Fund vom Zielserver

Der Abnahmelauf für P4 ist auf `cloudlab24.de` durchgelaufen: Server-Block →
Bestellung → Einspielen → Server-Block, und danach hört die Kette auf. Das
Zertifikat ist echt (`(STAGING) Dastardly Durum YR1`, 90 Tage), Port 80 leitet
dauerhaft weiter, und über 443 kam eine Antwort — aus dem 443er Block, der
damit beide Zertifikatsdateien liest.

**Die Antwort war „403 Forbidden", und das ist der Fund.** Das DocumentRoot war
leer, nginx meldete „directory index is forbidden". Die Willkommensseite
entstand in `subscription.provision` und nur für das *erste* DocumentRoot eines
Abonnements; jede weitere Domain bekam ein leeres Verzeichnis. Das ist wörtlich
dieselbe falsche Auskunft wie bei der Sperre, die zuerst 403 statt 503 gab: „du
darfst nicht" statt „hier ist noch nichts". Gesehen hat es kein Test — der
Abnahmelauf legt seine eigene Prüfdatei in jedes DocumentRoot und ist damit an
genau diesem Fall vorbeigelaufen.

Die Seite steht jetzt in `WelcomePage` und wird von beiden Operationen
geschrieben, die ein DocumentRoot anlegen. Sie nennt das Verzeichnis, in dem
die Dateien liegen sollen, als **Angabe** und nicht mehr als Wort im Text: Es
hiess `httpdocs`, solange nur das erste eine Seite bekam, und ein Hinweis auf
ein Verzeichnis, das es für diese Domain nicht gibt, schickt den Kunden ins
Leere. Die Bedingung von früher gilt unverändert — geschrieben wird nur in ein
leeres Verzeichnis, sonst legte ein zweiter Lauf eine `index.html` neben die
Seite des Kunden, die vor `index.php` gefunden wird.

**Erneuert wird am selben Timer.** `srvpanel:tls` läuft täglich für das
Zertifikat der Oberfläche und nimmt jetzt die Kundenzertifikate mit: Was
weniger als 30 Tage Restlaufzeit hat, wird neu bestellt. Der Takt hängt nicht
an der Laufzeit, sondern an dieser Frist — in diesen 30 Tagen muss ein Server
einmal gelaufen sein.

Vier Zeilen entscheiden, ob dieser Lauf trägt:

- **Ein Zertifikat, an dem keine Domain hängt, wird nicht erneuert.** Beim
  Erneuern entsteht ein *neues* Zertifikat, die Domain zeigt danach darauf, und
  die alte Zeile bleibt als Beleg stehen. Ohne diese Bedingung wäre sie in alle
  Ewigkeit fällig — jeder Lauf bestellte sie neu, bis die Ratenbegrenzung
  zuschlägt. Das fiele nicht am ersten Tag auf, sondern am dreissigsten.
- **Erst nachsehen, dann bestellen.** Bricht ein Lauf zwischen dem Ablegen der
  Dateien und dem Eintrag im Bestand ab, liegt ein erneuertes Zertifikat da,
  von dem das Panel nichts weiss. `acme.certificate.info` beantwortet das — die
  Operation war seit Schritt 4 gebaut und wurde von nichts gerufen.
- **Nach einem Versuch wird gewartet.** Sechs Stunden. Produktiv sind fünf
  Fehlversuche je Konto und Stunde die Grenze; wer sofort wieder anklopft,
  sperrt sich selbst aus — samt aller Domains, die in dieser Stunde neu
  angelegt werden.
- **Und ein Lauf bestellt höchstens zehn.** Ein Server, auf dem hundert Domains
  am selben Tag fällig werden, holt das über mehrere Tage auf, statt an der
  Wochengrenze hängenzubleiben, hinter der dann auch die neuen stehen. Was
  liegen bleibt, steht in der Ausgabe: **eine Grenze, die niemand meldet, sieht
  aus wie „alles erledigt".**

**Wie bestellt wird, steht jetzt an einer Stelle** (`CertificateOrder`). Es gibt
zwei Anlässe und ab dem zweiten Wurf drei — eine Domain ohne Zertifikat, eine
Erneuerung, später ein Knopf. Drei Stellen, die eine Bestellung zusammenbauen,
sind zwei Gelegenheiten, die Kontaktadresse zu vergessen oder die Namen aus der
Anfrage statt aus dem Bestand zu nehmen.

**HSTS für Kundendomains — mit einer Bedingung auf jeder Seite.** Ob ein Jahr
erzwungenes HTTPS gewollt ist, weiss nur das Panel: Es kennt den Testbetrieb,
dessen Wurzel kein Browser kennt, und an der Datei ist ein Staging-Zertifikat
von einem echten nicht zu unterscheiden. Ob das Zertifikat es hergibt, weiss
nur der Agent, denn nur er liest die Datei. Die Erlaubnis reist deshalb in den
Vorgangsdaten, und der Agent prüft trotzdem noch einmal — `docs/27 §7` nennt
das die Falle, die aussperrt, und bei einer Kundendomain trifft sie jeden
Besucher statt nur den Betreiber. **Kein `includeSubDomains`:** Eine Subdomain
ist hier eine eigene Domain mit eigenem Zertifikat, und die Erzwingung träfe
sie, bevor sie eines hat.

Die Frage „darf ein Browser dem trauen?" steht dabei nicht mehr in
`panel.vhost.apply`, sondern in `Acme\Trust` — sie wird jetzt von zwei Vorlagen
gestellt, und die zweite Formulierung wäre die gewesen, die HSTS auf ein
selbstsigniertes Zertifikat schreibt.

Sieben weitere Brüche im Wächterskript — und hier gehört eine Einschränkung
hin, die dieses Projekt sonst nicht macht: **Sie sind nicht gelaufen.** In
dieser Umgebung fehlt `vendor/`, es gibt also kein PHPUnit (siehe CLAUDE.md).
Geprüft ist mechanisch, dass jeder der sieben Eingriffe greift, die Datei
verändert und gültiges PHP hinterlässt; der Rot-Grün-Durchgang steht aus und
gehört auf eine Maschine mit `vendor/`. Ein Wächter, der nie rot war, ist kein
Wächter — diese sieben sind bis dahin Zusagen.

**Was P4 noch fehlt:** Das Panel selbst läuft weiter mit einem selbstsignierten
Zertifikat. Die Prüfadresse auf Port 80 steht seit Schritt 3, bestellt wird für
den Rechnernamen aber nichts — `docs/32` führt das im ersten Wurf, und es ist
der nächste Schritt vor der Oberfläche.

#### Schritt 5b: das Zertifikat der Oberfläche kommt von Let's Encrypt

Der letzte Punkt des ersten Wurfs, der noch offen war: Das Panel lief mit dem
selbstsignierten Zertifikat aus P0, und jeder Aufruf begann mit einer
Browserwarnung.

**Bestellt wird über dieselbe Strecke wie für jede Kundendomain** —
`acme.certificate.issue` für den vollständigen Rechnernamen, HTTP-01 über den
Block auf Port 80, den Schritt 3 der Oberfläche gegeben hat. Kein zweiter
ACME-Weg, keine zweite Ablage.

**Ohne Vorgang und ohne Zeile im Bestand.** Das Zertifikat der Oberfläche
gehört keinem Kunden, hängt an keiner Domain und wird von keiner Seite
verwaltet; was gilt, steht im Ablageort. Eine Zeile in `certificates` bräuchte
einen zweiten Erneuerungsweg — und der zweite Weg ist immer der, der veraltet.
`srvpanel:tls` fragt deshalb täglich `acme.certificate.info` und bestellt ab 30
Tagen Restlaufzeit neu, mit derselben Frist wie bei den Kundenzertifikaten.

**Aus dem Testbetrieb wird für die Oberfläche nie bestellt, und das ist die
wichtigste Zeile dieses Schritts.** Ein Staging-Zertifikat ist von einer
Zertifizierungsstelle ausgestellt — der Agent hält es damit für
vertrauenswürdig und schreibt `Strict-Transport-Security` in den Server-Block.
Kein Browser kennt die Wurzel dahinter: Die Warnung bleibt, **und sie lässt
sich nicht mehr wegklicken**, weil HSTS genau das verbietet. Der Betreiber wäre
aus seinem eigenen Panel ausgesperrt — und die Einstellung, mit der er sich
ausgesperrt hat, liegt hinter der Anmeldung. Bei einer Kundendomain ist
derselbe Fall unschön, hier ist er teuer.

**Das selbstsignierte bleibt liegen und bleibt gültig.** Es ist der Rückweg,
wenn unter dem Namen dieses Servers nichts mehr steht; `panel.tls.ensure` hält
es weiter aktuell, auch wenn es gerade niemand ausliefert. Ein Rückweg, den man
erst wieder herstellen muss, ist keiner.

**Welche der beiden Dateien ausgeliefert wird, entscheidet eine Stelle**
(`Acme\PanelCertificate`). Der Server-Block wählt sie, die Zertifikatsseite
zeigt sie an, die Erneuerung fragt danach — drei Stellen, die je selbst
nachsähen, sind zwei Gelegenheiten für eine Seite, die ein anderes Zertifikat
anzeigt als das, was der Browser bekommt. Und ein **kurzer Rechnername** ist
dabei kein Fehler: `Store` nimmt nur Domainnamen an, auf einem Server namens
`cloudsrv24` fliegt die Frage nach dem Ablageort. Sie heisst hier „es gibt
keines" und nicht „der Server-Block lässt sich nicht schreiben" — sonst nähme
ein kurzer Hostname das ganze Panel mit.

**Ein Aufruf ohne Portangabe verschiebt das Panel nicht mehr.** Nach dem
Ausstellen wird der Block neu geschrieben, und niemand nennt dabei einen Port;
die Vorgabe 8443 wäre für jeden Betreiber, der 9443 gewählt hat, ein Panel an
einer anderen Stelle — mit „Verbindung abgelehnt" als einziger Meldung.
`panel.vhost.apply` liest den Port jetzt aus dem Block, der dasteht.

**Ein Fehlschlag hält den Lauf nicht an.** Ohne DNS-Eintrag auf diesen Server
kann die Prüfung nicht gelingen; das ist beim Einrichten der Normalfall und
kein Grund, eine systemd-Unit rot zu färben. Die Oberfläche antwortet weiter,
nur eben mit der Warnung.

Drei weitere Brüche im Wächterskript — auch sie sind geschrieben und nicht
gelaufen, aus demselben Grund wie in Schritt 5.

**Was dabei bewusst liegen bleibt:** Die Seite `/settings/tls` zeigt weiter nur
„selbstsigniert oder nicht" und trägt einen Knopf, der das selbstsignierte neu
ausstellt — mit einem ACME-Zertifikat davor sieht man davon nichts. Die Auskunft
dafür steht seit diesem Schritt im Rückgabewert (`acme`); die Seite bekommt sie
in Schritt 6, zusammen mit den Screenshots in beiden Themes und bei 390 px.

#### Schritt 5c: die ausgelieferte nginx-Konfiguration wird nachgezogen

**Der erste Lauf gegen Let's Encrypt für die Oberfläche scheiterte — und der
Grund lag nicht bei ACME.** Die Prüfung fragte
`http://cloudsrv24.de/.well-known/acme-challenge/…` und bekam **404**. Also hat
ein Server-Block auf Port 80 geantwortet, nur nicht der der Oberfläche.

**Die Vorlage lebt im Agenten, die Datei unter `/etc/nginx` ist eine Kopie —
und die zog bis hierher niemand nach.** `panel.vhost.apply` ruft ausschliesslich
`srvpanel setup`; `srvpanel update` stösst `panel.update` an, und das fasst
nginx nicht an. Auf einem einmal eingerichteten Server steht deshalb der Block
von damals, beliebig alt. Der Port-80-Block aus Schritt 3 stand im Code und
nicht auf dem Server; die Anfrage fand keinen passenden `server_name`, landete
beim Vorgabeserver auf Port 80 — einem Kundenblock aus P3, der die Prüfadresse
nicht kennt — und bekam 404. Kein Fehler, keine Meldung, nur eine Zahl.

Das ist genau das Muster, an dem dieses Projekt sechsmal verloren hat: eine
Kopie, die nichts nachzieht. Neu ist daran nur, dass sie diesmal auf einem
echten Server aufgefallen ist und nicht beim Lesen.

- **`srvpanel vhost`** schreibt den Server-Block der Oberfläche neu — ohne
  Portangabe, denn den liest `panel.vhost.apply` seit 5b aus dem Block, der
  dasteht. Für den Betreiber ist es damit auch der Hebel, den es vorher nicht
  gab: Bis jetzt half nur ein zweiter `srvpanel setup`.
- **Das postinstall-Skript ruft es nach jedem Umschalten**, vor der
  Bereitschaftsprüfung: Der Agent nimmt nur an, was `nginx -t` besteht, und
  käme trotzdem etwas Unbrauchbares heraus, meldet die Prüfung das Panel als
  nicht erreichbar und der Rückweg greift. Ein Fehlschlag bricht das Update
  **nicht** ab — der alte Block liefert weiter aus, und ein Update wegen einer
  Konfigurationsdatei zurückzunehmen wäre die teurere Antwort.
- **`srvpanel vhost --sites`** nimmt die Kundendomains mit. Das ist eine
  ausdrückliche Option und läuft nicht mit: Jeder neu geschriebene Block löst
  den Lebenslauf aus, und der bestellt für jede Domain ohne Zertifikat eines.
  Bei zwanzig Domains ist das erwünscht, bei tausend ist es eine Bestellwelle
  in die Wochengrenze der Zertifizierungsstelle — die dann auch die *neuen*
  Domains aussperrt. Gesagt wird die Zahl trotzdem: Wieviele Bestellungen
  daraus werden, steht in der Ausgabe.
- **Ein Alias bekommt keinen.** Er steht im `server_name` seiner Elterndomain;
  für ihn etwas anzuwenden hiesse, denselben Block ein zweites Mal zu
  schreiben, und der Agent suchte ein DocumentRoot, das es nicht gibt.

**Der Wächter dazu prüft die Zeigerichtung**, wie überall hier: Das
Installationsskript muss den Aufruf enthalten, und zwar vor der
Bereitschaftsprüfung — danach wäre der Rückweg verspielt. Gesucht wird dabei
der *Aufruf* (`/usr/local/bin/srvpanel vhost`) und nicht das Wort: Der Hinweis
„Nachholen mit: sudo srvpanel vhost" steht eine Zeile darunter, und ein
Ausdruck, der ihn findet, bliebe grün, wenn der Aufruf verschwindet.

`tests/waechter-brechen.sh` sichert dafür jetzt auch `packaging/`. Ein Bruch in
einem Verzeichnis, das `wiederherstellen` nicht kennt, ist keine Probe, sondern
eine Änderung.

**Was auf dem Zielserver zu tun ist**, sobald diese Fassung dort läuft: Das
Update schreibt den Block der Oberfläche selbst neu. Die sechs Domains aus dem
P3-Abnahmelauf kennen die Prüfadresse weiterhin nicht — für sie einmal
`srvpanel vhost --sites`.

#### Schritt 6: die Oberfläche zu TLS

**Die Zertifikatsseite hat drei Jahre lang die Wahrheit gesagt und tat es seit
Schritt 5b nicht mehr.** Sie zeigte „selbstsigniert oder nicht" und einen
Knopf, der neu ausstellt — mit einem Zertifikat von Let's Encrypt davor sagte
sie damit das Falsche über das, was der Browser bekommt, und der Knopf erneuerte
sichtbar nichts. Genau diese Sorte Fehler steht in CLAUDE.md als eine der drei,
die grün getestet und trotzdem falsch waren.

- **Sie zeigt jetzt, was ausgeliefert wird.** `panel.tls.info` gibt seit
  Schritt 5b `acme` zurück; die Seite liest es und nennt die Art als Marke.
- **Der Knopf heisst, was er tut.** Mit einem ACME-Zertifikat davor steht dort
  „Rückweg erneuern", und die Rückfrage sagt, dass sich im Browser nichts
  ändert: Erneuert wird das selbstsignierte, und das ist der Rückweg für den
  Fall, dass unter dem Namen dieses Servers nichts mehr steht.
- **Und die beiden Angaben stehen endlich in einem Formular.** Kontaktadresse
  und Zertifizierungsstelle gab es seit Schritt 4b nur auf der Kommandozeile.
  Wer das Panel benutzt und nicht die Konsole, sah TLS als etwas, das still
  nichts tut — und „still nichts" erkennt von aussen niemand. Ganz oben steht
  deshalb die Meldung, dass ohne Kontaktadresse **für keine Domain** etwas
  bestellt wird, und im Testbetrieb, dass für die Oberfläche selbst gar nichts
  bestellt wird.

**Die Domainseite sagt, ob die Domain gesichert ist.** Art, Aussteller,
Laufzeit, gedeckte Namen — und `covers_all`, die Angabe, die man übersieht: Ein
Alias, der nach der Ausstellung dazukam, steht im `server_name` und nicht im
Zertifikat. Der Browser warnt dann bei ihm, und im Panel sieht alles grün aus.

**Ein Knopf zum Bestellen, obwohl es von selbst passiert.** Der Lebenslauf
bestellt, sobald der Server-Block steht; scheitert die Prüfung — falscher
DNS-Eintrag, Port 80 zu —, wartet die Domain auf den nächsten Anlass, und den
gibt es womöglich nicht. Wer den Eintrag gerade berichtigt hat, will es jetzt
versuchen. Er trägt dieselbe Fähigkeit wie das Ändern der Domain, und ohne
Kontaktadresse ist er abgeschaltet statt wirkungslos: Ein Knopf, der eine leere
Vorgangsliste hinterlässt, sieht aus, als hätte er gewirkt.

**Gelesen wird der Bestand und nicht der Ablageort.** Eine Domainseite, die bei
jedem Aufruf über den Socket geht, ist eine Seite, die bei einem stehenden
Agenten nicht mehr aufgeht — und die Frage „habe ich eines?" beantwortet die
Datenbank.

**Was hier fehlt, und es ist der Teil, auf dem dieses Projekt sonst besteht:
die Screenshots.** CLAUDE.md verlangt bei allem Sichtbaren einen Blick in den
Browser, in beiden Themes und bei 390 px. In dieser Umgebung liess sich das
nicht einlösen: `npm ci` ging durch, `npm run types` und `npm run build` laufen
also — aber `composer install` scheitert weiterhin an „Could not authenticate
against github.com", und ohne `vendor/` startet `artisan serve` nicht. Geprüft
ist damit, dass die Vorlagen übersetzen und die Typen stimmen; **wie die Seite
aussieht, hat niemand gesehen.** Das gehört nachgeholt, bevor die Stufe als
abgenommen gilt — auf dem Zielserver oder in einer Sitzung mit `vendor/`.

#### Schritt 6a: der Blick in den Browser, den Schritt 6 schuldig geblieben ist

Nachgeholt auf dem Zielserver, und er hat drei Dinge gefunden — alle drei grün
getestet und trotzdem falsch. Genau der Grund, aus dem CLAUDE.md bei allem
Sichtbaren einen Screenshot verlangt.

**Eine Kennung im Fliesstext brach nicht, und die Seite lief aus dem
Bildschirm.** In der Warnung der Zertifikatsseite steht die Liste der Namen,
unter denen der Rechner sonst noch erreichbar ist — als `.ident`, und `.ident`
stand auf `white-space: nowrap`. Damit war die ganze Liste eine einzige
unteilbare Zeile: 99px über den Rand der Meldung hinaus, 83px über den der
Seite. Gemessen, nicht geschätzt.

Behoben ist es diesmal an der Klasse und nicht am Fundort. **Denn genau so
wurde es beim ersten Mal behoben:** `table.pairs td.right.ident` löst denselben
Überlauf für die eine Tabelle, an der er auffiel — und der zweite Ort kam
trotzdem, elf Monate später. `nowrap` gehört jetzt der Tabelle (`td .ident`),
wo man schieben kann; die Klasse selbst bricht. Dazu
`MobileLayoutTest::test_an_identifier_may_break_outside_a_table`: Keine Regel
in irgendeinem Stylesheet — auch nicht im `<style>`-Block einer Seite — hält
eine Kennung ausserhalb einer Tabelle vom Umbruch ab.

**Ein Abstand, der aus der Reihenfolge der Seite abgeleitet war.** Das
Nachwort unter den Angaben trug `margin-bottom: 0` mit der Begründung „steht am
Fuss der Seite". Seit Schritt 6 steht das ACME-Formular darunter, und die
Überschrift klebte an seiner Unterkante. Dieselbe Sorte Annahme wie überall
hier: eine Aussage über den Nachbarn, die niemand nachprüft, wenn der Nachbar
wechselt.

**Und zwei Stellen, an denen Text abgeschnitten wurde statt umzubrechen.** Die
Knopfreihe in `Tls.vue` stand im Bereich statt daneben und bekam damit keinen
Abstand nach oben — „Speichern" klebte am Hinweis darüber. Und ein `<select>`
bricht seine Einträge nicht um, es schneidet ab: „Produktiv — gültige
Zertifikate von Let's Encrypt" endete bei 390px als „… von Let's Encry". Die
beiden Beschriftungen sind jetzt kurz genug; was darüber hinaus zu sagen ist,
steht im Hinweis unter dem Feld, und der bricht um.

Gegengeprüft wurde diesmal ohne `artisan serve`: Das gebaute Stylesheet aus
`public/build` mit dem Markup der Meldung in einer eigenen Datei, gerendert im
vorinstallierten Chromium bei 390px, in beiden Themes, mit `scrollWidth -
clientWidth` als Zahl auf der Seite. Vorher 99px, nachher 0px. Das ersetzt den
Blick auf die echte Seite nicht — aber es beantwortet die Frage, um die es
geht, ohne auf eine Umgebung mit `vendor/` zu warten.

### P4, zweiter Wurf — DNS-01, Platzhalter, eigene Zertifikate

Der Plan dazu steht in `docs/34`: die drei Stellen, an denen der erste Wurf der
Erweiterung nicht standhält, die Reihenfolge der Schritte, und die
Entscheidungen des Betreibers. Anbieter sind RFC 2136, Hetzner, Cloudflare,
Netcup und IPv64.net; die Zugangsdaten hängen am Abonnement, sobald der Plan
`dns_edit` freigibt, sonst am Betreiber. Kunden dürfen Platzhalter bestellen
und eigene Zertifikate hochladen — beides gibt es als Freigabe im Plan längst.

#### Schritt 1: welches Zertifikat gilt, sagt das Panel

**Bis hierher leitete der Agent es aus dem Domainnamen ab.**
`SiteTemplate::render()` sah unter dem Namen der Domain nach und nahm, was dort
lag. Für ein Zertifikat, das genau diese eine Domain deckt, stimmt das — und
für nichts sonst: Ein Platzhalter liegt unter keinem der Namen, die er deckt,
und ein hochgeladenes Zertifikat für drei Domains höchstens unter einer davon.

Damit gäbe es zwei Wahrheiten zu einer Frage: die Zuordnung in der Datenbank
und der Verzeichnisname auf dem Server. Das ist wörtlich das Muster, an dem
dieses Projekt sechsmal verloren hat — und diesmal wäre es aufgefallen, indem
eine Website ohne Fehlermeldung auf Port 80 zurückfällt.

**Jetzt nennt das Panel den Namen, und der Agent baut den Pfad.** Ein Name und
kein Pfad: Ein Pfad aus der Anwendung wäre bei `ssl_certificate` dasselbe wie
bei `root` — die Erlaubnis, eine beliebige Datei des Servers zu benennen.
Nennt das Panel keines, liefert der Block keines aus, auch wenn unter dem Namen
der Domain eines läge.

**Wo es liegt, berichtet der Agent, statt dass es jemand ausrechnet.**
`Store::write()` gibt den Schlüssel zurück, unter dem abgelegt wurde;
`certificates.storage_name` merkt ihn sich. Die Regel „Verzeichnis = erster
Name" stünde sonst an zwei Stellen — und sie ändert sich schon im nächsten
Schritt, weil `*.example.de` kein Verzeichnisname sein kann. Bestehende Zeilen
trägt die Migration nach genau dieser Regel nach; ohne den Nachtrag verlöre
jede bestehende Domain beim nächsten Anwenden ihr HTTPS.

**Bestellt wird jetzt nach der Deckung und nicht mehr nach dem Verweis.** Ein
zugeordnetes Zertifikat genügte, solange jedes für genau eine Domain bestellt
wurde. Kommt ein Alias nachträglich dazu, steht er im `server_name` und nicht
im Zertifikat: Der Browser warnt bei ihm, und im Panel sieht alles grün aus —
`covers_all` zeigt das seit Schritt 6 an, behoben hat es bis hierher nichts.
Dieselbe Bedingung trägt später den Platzhalter und das hochgeladene
Zertifikat.

**Und läuft schon eine Bestellung, kommt keine zweite dazu.** Ohne diese Frage
bestellte jedes erneute Anwenden noch einmal — bei `srvpanel vhost --sites`
über viele Domains wären das ebenso viele Prüfungen, und fünf Fehlversuche je
Stunde sind die Grenze der Zertifizierungsstelle.

**Die Zuordnung fragt jetzt auch nach dem Eigentum, und diesen Fall hat der
Quelltext vorhergesagt, bevor es ihn gab.** Am Verweis in `App\Models\Domain`
stand seit P4, die Deckungsprüfung fange eine fremde Nummer „meistens" ab —
„aber nicht bei einem Wildcard, das den Namen zufällig deckt". Genau das kommt
mit diesem Wurf: `*.example.de` deckt jede Unterdomain der Zone, auch die eines
anderen Kunden. Ab da ist die Zuordnung keine Sorgfaltsfrage mehr, sondern die
Grenze zwischen zwei Abonnements.

Vier neue Brüche im Wächterskript: der ableitende Agent, das schweigende Panel,
das fremde Zertifikat, der Verweis statt der Deckung. Geprüft wird in
`SiteTemplateTest`, `WebLifecycleTest`, `CertificateCoverageTest` und
`CertificateReapplyTest`.

**Gegengeprüft wurde diesmal ohne PHPUnit.** `vendor/` ist in dieser Umgebung
unvollständig — 39 Pakete, aber weder `vendor/bin` noch Autoloader. Der Teil
unterhalb von `agent/` liess sich trotzdem fahren: `agent/src/autoload.php` ist
abhängigkeitsfrei, und ein Wegwerfskript im Scratchpad hat die elf Behauptungen
zur Vorlage geprüft — ohne Nennung kein `listen 443`, mit fremdem Ablageort
genau dieser Pfad, und ein `../../etc/passwd` als Zertifikatsname wird
abgewiesen. Die Tests der Anwendung prüft die CI.

#### Schritt 2: ein Platzhalter heisst nicht so, wie er heisst

`*.example.de` als Verzeichnisname wäre ein Stern in einem Pfad, der als
`ssl_certificate` in einer nginx-Datei steht und von einem Prozess als root
gelesen wird. Für jede Shell, für `find` und für `rm` ist das ein Muster — ein
Name, der unterwegs expandiert, bezeichnet dann etwas anderes als das, was
gemeint war.

**Und gescheitert wäre es an der teuersten Stelle:** `Store::directory()` prüft
über `DomainName::normalize()`, und die weist den Stern ab. Die Ablage wäre
also erst *nach* der erfolgreichen Bestellung gescheitert — ein Zertifikat, das
ausgestellt ist und sich nicht ablegen lässt, ist ein verbrauchter Eintrag in
der Wochengrenze der Zertifizierungsstelle.

Der Schlüssel heisst deshalb `_wildcard.example.de`. Die führende
Unterstrich-Beschriftung kann kein Domainname sein — `DomainName` weist sie ab
—, also kollidiert der Schlüssel mit keinem echten Namen. Die Umformung steht
in `CertificateName` und ist **mehrfach anwendbar**, weil derselbe Schlüssel
zweimal durchgeht: einmal beim Ablegen und einmal, wenn die Anwendung ihn
später wieder nennt. Wäre sie es nicht, ginge genau dieser zweite Weg schief —
bei jeder gesicherten Website gleichzeitig.

`CertificateStorageTest` prüft die drei Zusagen: kein Stern im Pfad, zwei
verschiedene Namen nie im selben Verzeichnis (mit `example.de` und
`*.example.de` als dem teuren Paar), und zehn Formen, die weder Name noch
Schlüssel sind, werden abgewiesen — `*.*.example.de`, `a.*.example.de`,
`*./../../etc` und Verwandte. Dazu der Durchgang über die ganze Strecke: Das
Panel nennt `*.example.de`, und im Server-Block steht der Pfad ohne Stern.

Zwei neue Brüche im Wächterskript — der Stern im Ablageort und der Platzhalter
ohne eigenes Verzeichnis —, und beide sind hier **rot-grün gefahren**, nicht nur
geschrieben: `agent/src/autoload.php` ist abhängigkeitsfrei, das Wegwerfskript
im Scratchpad kommt ohne PHPUnit aus. Bruch 1 nimmt zwei Behauptungen mit,
Bruch 2 die Eindeutigkeit, und zurückgesetzt ist wieder alles grün.

**Ein Zusammenstoss, der dabei aufgefallen ist und zu Schritt 3 gehört:** Der
Schlüssel entsteht aus dem ersten Namen — zwei verschiedene Zertifikate mit
demselben ersten Namen ergeben also denselben Ablageort. Solange jede Domain
genau eines hat und die Erneuerung es an Ort und Stelle ersetzt, ist das
richtig. Sobald ein hochgeladenes neben einem bestellten liegen kann, ist es
das nicht mehr; die Antwort ist eine Frage der Quelle und nicht des Sterns und
steht deshalb in `docs/34 §2.2` für den nächsten Schritt vorgemerkt.

#### Schritt 3a: ein eigenes Zertifikat ablegen — der Agent und die Kommandozeile

Geprüft wird im Agenten, und zwar vollständig, **bevor** irgendetwas abgelegt
wird. Dort sitzt openssl, dort läuft nginx, und dort bleibt der private
Schlüssel — er soll nicht durch das Panel wandern, um von dort wieder
hinauszugehen. Eine halb abgelegte Kette wäre schlimmer als eine abgewiesene:
Ein Zertifikat, das nginx nicht laden kann, nimmt beim nächsten Reload *alle*
Websites dieses Servers mit, auch die, mit denen es nichts zu tun hat.

`Bundle` weist ab, und jeder Fall nennt seinen Grund: Schlüssel und Kette
gehören nicht zusammen, die Kette ist falsch sortiert, der Schlüssel trägt ein
Passwort, es ist abgelaufen oder gilt erst später, es nennt keinen Namen im
subjectAltName. **Die falsch sortierte Kette ist der teure Fall** — Firefox holt
das fehlende Glied nach, ein Mobilgerät nicht; der Betreiber sieht eine Seite,
die bei ihm aufgeht, und der Kunde eine Warnung. Geprüft wird die Kette
gliedweise, nicht als Ganzes: `openssl_x509_parse` liest von einer Kette das
erste Zertifikat und schweigt über den Rest.

**Keine Prüfung gegen die Wurzelspeicher des Systems.** Ob die ausstellende
Stelle bekannt ist, entscheidet der Browser des Besuchers und nicht dieser
Server; ein internes Zertifikat einer Firmen-CA abzuweisen wäre eine Anmassung.

**Abgelegt wird unter `_uploaded.<name>`** — die Antwort auf den Zusammenstoss
aus Schritt 2: Der Ablageort entsteht aus dem ersten Namen, ein hochgeladenes
Zertifikat für `example.de` hätte also denselben wie ein bestelltes.
Unterschieden wird nach der Quelle und nicht nach dem Namen.

**Und der Fund dieses Schritts: das Hochladen darf nicht über die Warteschlange
laufen.** Ein eingereihter Vorgang legt seine Argumente in `operations.payload`
ab — der private Schlüssel läge damit im Klartext in der Datenbank, dauerhaft
und für jeden lesbar, der sie liest. `srvpanel tls --upload` ruft den Agenten
deshalb unmittelbar auf und schreibt den Bestand über
`App\Support\Tls\CertificateRecord`, die neue gemeinsame Stelle für „ein
abgelegtes Zertifikat in den Bestand nehmen" — dieselbe Zeile wie nach einer
Bestellung, nur mit anderer Quelle. `AgentOperationReachTest` führt den Grund
in seiner Ausnahmeliste; die Liste hat dafür eine zweite Sorte Begründung
bekommen.

Dabei ist noch etwas aus Schritt 1 nachgezogen worden: Der Erneuerungslauf
fragte `acme.certificate.info` mit dem **Domainnamen** nach dem abgelegten
Zertifikat. Seit ein Platzhalter unter `_wildcard.example.de` liegt, wäre die
Antwort „liegt nichts da" — und der Lauf bestellte jeden Tag neu. Gefragt wird
jetzt nach dem Ablageort.

`CertificateUploadTest` fährt achtzehn Behauptungen gegen **im Test erzeugte**
Zertifikate: eine eigene CA, ein Blatt mit subjectAltName, eines ohne, ein
Schlüssel mit Passwort. Eingecheckt wird davon nichts — ein privater Schlüssel
im Repo, und sei es ein erfundener, ist ein Fundstück für jeden Scanner und
eine Erklärung, die man immer wieder abgeben muss. Erzeugt wird mit den
openssl-Funktionen von PHP, das `openssl`-Programm braucht es dafür nicht.

Zwei neue Brüche, beide **rot-grün gefahren**: ohne Reihenfolgeprüfung geht die
verkehrte Kette durch, ohne die Kennzeichnung der Quelle teilen sich
hochgeladen und bestellt denselben Ort.

Offen bleibt Schritt 3b — die Oberfläche dazu, mit `Feature::CertificateUpload`
und den Screenshots, die zu allem Sichtbaren gehören.

#### Schritt 3b: die Oberfläche zum eigenen Zertifikat

Ein eigener Bereich auf der Domainseite, sichtbar nur, wenn der Plan
`certificate_upload` freigibt. **Am Rechtemodell war dafür nichts zu bauen:**
`Feature::CertificateUpload` steht seit P2 in den Plänen, mit Beschriftung,
Hinweis und Recht — gefehlt hat die Funktion dahinter. Die Fähigkeit heisst
`uploadCertificate` und hängt nicht an `update`: Wer hochlädt, legt einen
privaten Schlüssel auf den Server, und was danach ausgeliefert wird, sieht
jeder Besucher. Das ist eine andere Grössenordnung als ein DocumentRoot zu
ändern, und der Betreiber entscheidet über den Plan, wer sie bekommt.

**Zwei Textfelder und keine Dateiauswahl.** Wer ein Zertifikat gekauft hat, hat
es meistens als Text in einer Mail — und wer es als Datei hat, kann sie öffnen
und den Inhalt einfügen. Umgekehrt gilt das nicht: Eine Dateiauswahl auf dem
Telefon findet den Anhang einer Mail nicht. Der Schlüssel wird nach dem
Absenden geleert, auch bei Erfolg.

**Die Meldung des Agenten wird wörtlich durchgereicht.** „Die Kette ist nicht
in der richtigen Reihenfolge" ist eine Auskunft, mit der jemand weiterkommt;
„ungültig" ist keine. Ein Betreiber liest sonst das Protokoll — ein Kunde liest
diese Seite und sonst nichts.

**Und auch hier kein Vorgang, sondern ein unmittelbarer Aufruf** — aus dem
Grund aus Schritt 3a: Ein eingereihter Vorgang legt seine Argumente in
`operations.payload` ab, und ein privater Schlüssel gehört dort nicht hin.

**Zum dritten Mal derselbe Abstand.** Eine Knopfreihe hinter einem Feld klebt
am Text darüber, weil `.button-row` keinen Rand nach oben setzt; in einem
`.form` fällt das nicht auf, weil sie dort ein Flexkind ist und den `gap` erbt.
Die Antwort war bisher jedes Mal eine eigene Klasse auf der Seite — `.spaced`
im Profil, ein Umbau in der Zertifikatsseite. Jetzt steht sie in `app.css`, wo
das Aussehen eines Bausteins hingehört, und trifft über einen
Nachbarschaftsausdruck genau den Fall: eine Reihe *nach* Formularinhalt.

**Im Browser nachgesehen**, auf dem Weg aus CLAUDE.md, der ohne `artisan serve`
auskommt: das gebaute Stylesheet mit dem Markup des Bereichs in einer eigenen
Datei, gerendert im vorinstallierten Chromium bei 390px, in beiden Themes.
Kein waagerechter Überlauf (0px), das Feld 358px breit, und der Knopf steht
nach der Ergänzung nicht mehr am Hinweis. Das ersetzt den Blick auf die echte
Seite nicht — der gehört auf dem Zielserver nachgeholt, wie in P4 Schritt 6.

`DomainRouteTest` um drei Durchgänge erweitert: ohne Freigabe abgewiesen, mit
Freigabe kommt die Meldung des Agenten zurück, und ein halbes Formular reicht
nicht. Dazu ein neuer Bruch — die Policy ohne Planfreigabe.

#### Schritt 4: welches Zertifikat gilt, wenn zwei dastehen

Seit Schritt 3 kann eine Domain mehrere haben — das für ihren Namen bestellte,
ein hochgeladenes, später den Platzhalter ihres Abonnements. Damit wird aus
einer Zuweisung eine Frage, und die hat ab jetzt **genau eine Antwort**:
`App\Support\Tls\CertificateChoice`. Wer sie an drei Stellen zusammensetzt —
beim Schreiben des Server-Blocks, beim Entscheiden über eine Bestellung, in der
Oberfläche —, bekommt drei Antworten, und die beiden falschen fallen erst im
Browser auf.

**Wahl und Zuweisung sind zweierlei.** `domains.certificate_id` trägt beides;
die neue Spalte `certificate_pinned_at` sagt, welches von beiden es gerade ist.
Ohne diesen Unterschied nähme die nächste Erneuerung die Wahl still zurück — der
Fehlertyp, der in diesem Projekt am teuersten war. **Ein Hochladen ist dagegen
selbst eine Wahl:** Wer die Datei einfügt und absendet, hat entschieden, und ein
Formular, das nichts tut, weil eine ältere Wahl dasteht, wäre schlimmer als
keines.

**Der laute Rückfall — die Entscheidung des Betreibers vom 6. August.** Läuft
die Wahl ab, wird sie übergangen und nicht befolgt: Ein hochgeladenes
Zertifikat erneuert niemand, und stur daran festzuhalten nähme die Website vom
Netz. Übergangen heisst aber nicht verschwiegen — die Wahl bleibt eingetragen,
greift wieder, sobald sie gilt, und die Domainseite sagt in einer Meldung, dass
sie gerade nicht gilt.

**Ein Fund beim Bauen, und er wäre eine Bestellschleife gewesen.** Die
Bedingung, ob bestellt wird, darf nicht nach dem *zugeordneten* Zertifikat
fragen. Bei einer abgelaufenen Wahl ändert sich die Zuordnung ja nicht — die
Domain hätte bei jedem Anwenden erneut bestellt, bis die Wochengrenze der
Zertifizierungsstelle sie anhält. `satisfied()` fragt deshalb etwas anderes als
`effective()`: nicht „was liefern wir aus", sondern „gibt es überhaupt eines,
das gilt und alles deckt".

**Gibt es gar nichts Gültiges, bleibt das Abgelaufene stehen.** Ohne Zertifikat
fällt der Block auf Port 80 zurück, und eine Adresse, die vorher HTTPS war, ist
dann nicht mehr erreichbar, sondern still unverschlüsselt. Ein abgelaufenes
warnt wenigstens — und `satisfied()` sorgt dafür, dass parallel ein neues
bestellt wird.

Die Oberfläche zeigt das Auswahlfeld erst ab zwei Kandidaten: Ein Feld mit
einem Eintrag ist eine Frage ohne Antwortmöglichkeit. Die Einträge nennen
Herkunft und Laufzeit und **nicht die gedeckten Namen** — jeder Kandidat deckt
alle, sonst stünde er nicht zur Wahl, und ein `<select>` bricht nicht um,
sondern schneidet ab (`docs/24 §8`).

`CertificateChoiceTest` prüft die sieben Regeln einzeln; `DomainRouteTest` dazu,
dass nur gewählt werden kann, was zur Wahl steht, und dass sich eine Wahl
zurücknehmen lässt. Zwei neue Brüche: die Bestellung, die die Wahl
überschreibt, und die abgelaufene Wahl, die befolgt wird.

**Im Browser nachgesehen** wie in Schritt 3b — gebautes Stylesheet, Markup in
einer eigenen Datei, Chromium bei 390px: kein Überlauf, und der Text des
Auswahlfelds passt in seine Breite.

##### Nachtrag: ein fester Zeitstempel als Zeitbombe

Die CI hat einen einzigen Durchgang gemeldet, und er hatte recht: „die beiden
Regeln jagen einander nicht" schlug fehl, weil das Testzertifikat abgelaufen
war. In `CertificateReapplyTest` standen zwei feste Zeitstempel aus dem August
2025 — solange die Bestellbedingung nur nach der *Deckung* fragte, war das
folgenlos. Seit Schritt 4 fragt sie auch nach dem Ablauf, und damit bestellte
der Durchgang ein zweites Mal.

**Der Test hatte unrecht, nicht der Code.** Ein fester Zeitstempel in einem
Datensatz ist eine Zeitbombe mit unbekanntem Zünder: Er wartet, bis irgendwann
jemand nach der Zeit fragt. Die Laufzeit ist jetzt relativ — neunzig Tage, so
wie Let's Encrypt sie ausstellt.

#### Schritt 5: DNS-01 — die Strecke, noch ohne Anbieter

`DnsChallenge` ist die zweite Umsetzung derselben Schnittstelle; der Ablauf in
`Order` bleibt ohne Fallunterscheidung. Verschieden sind hinlegen, abräumen —
und vor allem `ready()`, der Grund, aus dem es die Schnittstelle überhaupt gibt.

**Gefragt werden die autoritativen Nameserver und nicht der Anbieter.** Dessen
API sagt „ok", sobald sie den Eintrag entgegengenommen hat; ausgeliefert wird er
Sekunden bis Minuten später. Wer der Zertifizierungsstelle zu früh sagt „prüf
jetzt", verbrennt einen der fünf Fehlversuche je Stunde — und die gelten für das
ganze Konto, also für jeden Kunden dieses Servers. **Und auch nicht der
Systemauflöser:** Der antwortet aus seinem Zwischenspeicher, und der kann den
Namen von vorhin noch als „gibt es nicht" führen.

**Erst wenn alle Server den Wert ausliefern.** Welchen die Zertifizierungsstelle
fragt, weiss niemand; sie fragt sogar aus mehreren Netzen zugleich. Ein Wert,
den nur die Hälfte kennt, ist eine Prüfung, die manchmal gelingt — die
unangenehmste Sorte Fehler.

**Ein eigener Auflöser und kein `dig`.** Das Programm gehörte auf die
Positivliste des Agenten und als Abhängigkeit ins Paket, für eine Frage, die in
hundert Zeilen beantwortet ist; unterhalb von `agent/` gibt es keine
Abhängigkeiten, und das bleibt so. Das Drahtformat steht als reine Umformung in
`Packet` — getrennt von der Steckdose und damit gegen **gebaute** Pakete
prüfbar: ein Name als Zeiger, ein Name ausgeschrieben, ein Satz anderer Art
dazwischen, zwei Werte unter einem Namen, ein Wert in Stücken, eine Antwort auf
eine fremde Frage, ein gesetztes Abschneidebit, ein Satz über das Paketende
hinaus. **Der Zeiger ist der Fall, für den dieser Durchgang existiert:** Wer die
Zusammenfassung aus RFC 1035 §4.1.4 nicht erkennt, liest die folgenden Felder
verschoben und bekommt Werte, die fast stimmen.

**Zwei Entscheidungen kamen beim Bauen dazu.** `cleanup()` bekommt vom Ablauf
nur Domain und Token — der Anbieter muss aber den *Wert* kennen: Zwei
Bestellungen für dieselbe Zone laufen sonst einander ins Handwerk, weil die eine
den `_acme-challenge`-Eintrag der anderen mit abräumt. Die Challenge merkt sich
deshalb, was sie hingelegt hat. Und `add()` legt an, statt zu ersetzen:
`example.de` und `*.example.de` in einer Bestellung ergeben zwei Werte unter
demselben Namen, und beide müssen dastehen.

**Der Stern fällt weg.** Für `*.example.de` heisst der Eintrag
`_acme-challenge.example.de` — derselbe wie für `example.de`. Das ist keine
Eigenheit dieses Panels, sondern RFC 8555: Der Platzhalter steht in der
Bestellung und nicht im Namen der Prüfung.

Der `DnsProvider` hat noch keine Umsetzung — sie kommt mit den Zugangsdaten
(Schritt 6) und RFC 2136 (Schritt 7). Bis dahin steht die Schnittstelle, und
die Doppel liegen unter `tests/Support/`.

Zwei neue Brüche, beide **rot-grün gefahren**: der nicht erkannte Namenszeiger
und der eine Nameserver, der genügt.

#### Schritt 6: wo ein DNS-Token liegt — und was den Agenten davon verlässt

**Ein DNS-Token ist ein grösseres Geheimnis als das Panel-Passwort.** Wer es
hat, kann sich für die Domain jedes Zertifikat der Welt ausstellen lassen, auch
anderswo. Es liegt deshalb dort, wo `panel.env` schon liegt: in einer Datei, die
root allein lesen darf (0600 im 0700-Verzeichnis `/etc/srvpanel/dns`) — und
nicht in der Datenbank des Panels, aus der es bei jedem Vorgang über den Socket
ginge und in jeder Fehlermeldung auftauchen könnte.

**Drei Operationen sind der einzige Weg dorthin**, und keine gibt ein Token
zurück: `dns.credential.store` überquert den Socket genau einmal beim
Einrichten, `.list` nennt Profil, Anbieter und Zeitpunkt, `.forget` räumt wieder
weg. **Auch nicht die letzten vier Zeichen** — bei einem kurzen Token ist das
ein spürbarer Teil davon, und der Gewinn wäre eine Bequemlichkeit beim
Wiedererkennen.

**Der Profilname wird geprüft und nicht geglaubt.** Er wird zu einem Dateinamen
in einem Prozess mit Systemrechten; was durchginge, läge als
`../../etc/irgendwas` auf der Platte — mit 0600 root, also genau da, wo es
niemandem auffällt. Zugelassen sind Kleinbuchstaben, Ziffern und Bindestriche:
genug für `betrieb` und `abo-1042`, und sonst nichts.

**Und er wird abgeleitet, nicht entgegengenommen.** `App\Support\Tls\DnsProfile`
fragt die Freigabe, die es dafür längst gibt: `Feature::DnsEdit` trägt seit den
Plänen den Hinweistext „Ohne diese Freigabe verwaltet der Betreiber die Zone;
das Abonnement sieht sie nur." Mit Freigabe gilt `abo-<nummer>`, ohne sie
`betrieb`. **Keinen stillen Rückfall:** Ein Abonnement mit Freigabe, das noch
nichts hinterlegt hat, bekommt nicht ersatzweise das Token des Betreibers — das
wäre ein Zugriff auf eine fremde Zone mit einem Schlüssel, der sie womöglich gar
nicht öffnet.

`Providers` führt die fünf Schlüssel aus der Entscheidung vom 6. August und
sonst nichts. Eine Fabrikmethode, die für jeden von ihnen eine Ausnahme wirft,
wäre genau die Sorte Zeichenkette, die auf nichts zeigt; sie kommt mit der
ersten Umsetzung.

`srvpanel dns` ist der Weg von der Kommandozeile — hinterlegen, ansehen,
entfernen. Wer einen Server einrichtet, hat das Token gerade im Terminal; die
Oberfläche dazu kommt danach und ersetzt dieses Kommando nicht.

Drei neue Brüche, zwei davon **rot-grün gefahren**: der ungeprüfte Profilname,
das Token in der Antwort, und die Planfreigabe, die nicht mehr entscheidet.

#### Schritt 7: RFC 2136 — der erste Anbieter, der wirklich etwas ändert

**Der Standard und kein Anbietercode.** Eine TSIG-unterschriebene
Zonenaktualisierung bedient BIND, Knot und PowerDNS gleichermassen — und damit
auch die eigene Zone aus P7, ohne dass es dafür eine zweite Umsetzung bräuchte.
Deshalb steht er in der Reihenfolge vorn.

**Die Zonen stehen in den Zugangsdaten und werden nicht erraten.** Man könnte
sie über den SOA-Satz suchen, und die meisten Umsetzungen tun das; hier nicht.
Ein TSIG-Schlüssel ist im Nameserver ohnehin auf eine Zone eingegrenzt — wer sie
errät, bekommt ein stummes `REFUSED` —, und vor allem ist die Liste damit eine
**Positivliste**: Ein Profil ändert genau die Zonen, die der Betreiber
hineingeschrieben hat. Ohne sie wäre der Zonenname eine Grösse, die aus dem
Namen einer Domain folgt, und den bestimmt ein Kunde. Zusammengesetzt wird von
unten, die längste passende Zone gewinnt: Wer `example.de` und
`intern.example.de` mit demselben Schlüssel führt, will für einen Namen in der
zweiten auch die zweite genannt haben.

**Verglichen wird beschriftungsweise.** `boesexample.de` endet auf
`example.de` — ein Vergleich als Zeichenkette liesse hier eine fremde Domain in
eine Zone hinein, die jemand anderem gehört.

**Die Antwort des Nameservers wird nachgerechnet.** Über TCP ist eine
untergeschobene Antwort nicht leicht, aber „nicht leicht" ist bei einem Prozess
mit Systemrechten kein Argument: Ein gefälschtes `NOERROR` hiesse, wir sagen der
Zertifizierungsstelle „prüf jetzt", während nichts in der Zone steht — und
verbrennen einen der fünf Fehlversuche je Konto und Stunde, die für jeden Kunden
dieses Servers gelten. Geprüft werden Kennung, Unterschrift und Rückgabewert,
und jeder Rückgabewert hat einen deutschen Satz: Ohne ihn stünde im Protokoll
„Rückgabewert 9" und der Betreiber suchte an der falschen Stelle.

**Der Rechenweg der Unterschrift ist zweimal geschrieben, und das mit Absicht.**
Die Durchgänge rechnen ihn Byte für Byte aus RFC 8945 §4.3.3 nach, statt mit
derselben Klasse zu unterschreiben, die gleich prüfen soll — sonst bestünden sie
auch dann, wenn beide Seiten denselben Fehler machen. Die Stelle, um die es dabei
geht: Gerechnet wird über die Nachricht **vor** dem TSIG-Satz, also mit dem alten
`ARCOUNT`. Wer es andersherum macht, bekommt eine Unterschrift, die in sich
stimmig ist, und einen Nameserver, der `NOTAUTH` antwortet, ohne zu sagen, an
welcher der acht Grössen es lag.

**Ohne `hmac-md5` und ohne `hmac-sha1`.** Beide stehen in RFC 8945 noch drin,
und `hmac-md5` ist dort sogar der alte Standardwert; für einen Schlüssel, der
heute neu eingerichtet wird, gibt es keinen Grund dazu.

**Was noch nicht gebaut ist, lässt sich jetzt auch nicht mehr hinterlegen.**
Hetzner, Cloudflare, Netcup und IPv64.net stehen in `Providers::PENDING` und
werden beim Ablegen abgewiesen. Ein Formular, das ein Cloudflare-Token annimmt,
sagt dem Betreiber, dass es geht — und die Wahrheit erfährt er beim ersten
Zertifikat, mit einem Geheimnis, das längst auf der Platte liegt. Aus demselben
Grund prüft `srvpanel dns` die Angaben jetzt beim Hinterlegen und nicht erst
beim Bestellen: Was durchgeht, fällt sonst auf, wenn eine Erneuerung um drei Uhr
morgens an einem vertippten Schlüsselnamen scheitert. Und das Geheimnis wird
gefragt, wenn es nicht in der Kommandozeile steht — was dort steht, steht danach
in der Verlaufsdatei der Shell und in `ps`.

**Das Drahtformat eines Namens hat jetzt eine Stelle.** `Acme\Dns\Name` schreibt
und überliest ihn; die Frage nach einem TXT-Satz, die Aktualisierung und die
Unterschrift benutzen sie. Der zusammengefasste Name — zwei Bytes, die auf eine
frühere Stelle zeigen (RFC 1035 §4.1.4) — ist die Stelle, an der
handgeschriebener DNS-Code danebengeht, und drei Fassungen davon wären der
Fehler, der dieses Projekt am häufigsten kostet. `DnsNameSourceTest` besteht
darauf, mit denselben zwei Mustern, die auch `HostnameSourceTest` benutzt: eng
genug, dass `explode('.', …)` in `DomainName` und `Resolver` nicht mitgemeldet
wird — ein Wächter, der beim Aufräumen zubeisst, wird beim Aufräumen
abgeschaltet.

**Und ein Wächter für einen Fehler, der drei CI-Runden gekostet hat.** Ein Name,
der der Basisklasse gehört, bricht beim *Laden* der Klasse und nicht beim
Ausführen: `count()` in einem Testfall, `configure()` in einem Artisan-Kommando
— dort stand danach nicht ein Kommando still, sondern `artisan` mit allen —, und
zuletzt `name()` in `DnsPacketTest`. Nach dem zweiten Mal stand die Regel in
CLAUDE.md; beim dritten Mal habe ich sie gelesen und bin trotzdem hineingelaufen.
**Ein Satz in einer Datei ist kein Wächter.** `InheritedNameTest` liest den Text
der Datei und lädt nur die Basisklasse — denn ein Wächter, der die fragliche
Klasse zur Prüfung lädt, stürzt selbst mit demselben fatalen Fehler ab.

Fünf neue Brüche im Bruchskript: die Unterschrift über den erhöhten Zähler, die
Zone als Zeichenkette verglichen, ein Anbieter ohne Umsetzung, der nicht als
offen geführt wird, eine zweite Fassung des Drahtformats, und ein Name der
Basisklasse.

Was aussteht: die Abnahme gegen eine echte Zone auf dem Zielserver — sie ist das
Kriterium aus `docs/34 §10` und lässt sich hier nicht messen; dieser Container
hat keinen Nameserver, der Aktualisierungen annimmt.

#### Schritt 8: einen Platzhalter bestellen

**Ein Kästchen und keine Automatik.** Ein Platzhalter deckt jede Unterdomain der
Zone — auch eine, die einem *anderen* Abonnement gehört. Technisch ist damit
nichts gewonnen oder verloren, der Schlüssel liegt ohnehin root-eigen auf
demselben Server; die Aussage nach aussen ändert sich aber, und etwas mit dieser
Folge passiert nicht als Nebenwirkung eines Knopfdrucks. Was er einbringt, steht
daneben: Ein Abonnement mit vierzig Unterdomains verbraucht sonst vierzig
Einträge je Woche statt zwei.

**Der Stern steht zuerst, und das ist keine Kosmetik.** Bestellt wird
`['*.example.de', 'example.de']`. Der Ablageort entsteht aus dem ersten Namen;
stünde die Basisdomain vorn, läge der Platzhalter unter `example.de` und
überschriebe ein einfaches Zertifikat für denselben Namen. Das sieht man einer
Bestellung nicht an — gemerkt hätte man es, wenn eine Domain plötzlich das
falsche Zertifikat ausliefert. Und die Basisdomain gehört mit in die Bestellung:
`*.example.de` deckt `example.de` nicht.

**Geprüft wird an der Domain und nicht am eingetippten Namen** (`docs/34 §3`).
Ein Platzhalter geht nur zu einer Haupt- oder Zusatzdomain des eigenen
Abonnements. Käme der Name aus der Anfrage, bestellte jemand `*.fremde.de` —
und das scheitert erst an der Zertifizierungsstelle, mit einem verbrauchten
Fehlversuch, der für das ganze Konto zählt und damit für jeden Kunden dieses
Servers.

**„Darfst du nicht" und „geht gerade nicht" sind zwei verschiedene Neins.** Die
Berechtigung beantwortet `DomainPolicy::orderWildcard()` — an `Feature::DnsEdit`
und nicht an `Certificates`, denn ein Platzhalter geht nur über DNS-01, und
DNS-01 heisst: In der Zone wird ein Eintrag angelegt. Fehlende Zugangsdaten sind
dagegen keine Frage der Berechtigung, sondern eine Auskunft, und sie steht in
`WildcardOrder::obstacle()`. Wer beides vermischt, sagt dem Betreiber „keine
Berechtigung", wo „kein Token hinterlegt" gemeint ist.

**Die Sternschranke im Agenten hängt jetzt an der Art der Prüfung statt am
Namen.** `AcmeCertificate` nimmt `challenge` und `profile` entgegen — ein Wort
aus einer Positivliste und einen Profilnamen, keine Adresse und kein Token.
Über HTTP-01 bleibt die Abweisung, weil die Zertifizierungsstelle dort mit einer
Meldung antwortet, die den Grund nicht nennt. **Ohne Angabe bleibt es HTTP-01:**
Jede Bestellung aus der Fassung davor kommt ohne diese Felder an, und ein
Zeitplan, der noch die alte fährt, soll nicht stehenbleiben.

**Der Stern gehört nicht in `DomainName::normalize()`.** Dort ist eine
Beschriftung `[a-z0-9]`, und das bleibt sie — sonst wäre `*` überall im Agenten
ein zulässiges Zeichen, auch dort, wo ein Name zu einem Pfad wird. Geprüft wird
der Rest des Namens, und der Stern kommt danach wieder davor.

**Und die Domainseite heisst jetzt `can` statt `may`.** Sie war die einzige mit
dieser Schreibweise und damit von `AbilityReachTest` **in beide Richtungen nicht
erfasst** — weder „kommt an" noch „wird benutzt". Eine neue Fahne dort
einzuhängen, ohne das zu beheben, wäre wieder eine Zeichenkette, die auf nichts
zeigt: Eine Fahne, die nie ankommt, ist in Vue `undefined`, und der Knopf
verschwindet dann für **alle**, ohne dass etwas meldet.

**Eine Naht mehr, und aus einem konkreten Grund:** `SrvPanel\Agent\Client` ist
`final`. Die Frage „welche DNS-Profile sind hinterlegt?" hat deshalb eine eigene
Schnittstelle bekommen (`App\Support\Tls\DnsCredentials`), sonst hinge jede
Prüfung an einem Unix-Socket, den dieser Container nicht hat — und die drei
Fälle, auf die es ankommt, liessen sich gar nicht herstellen: Profil da, Profil
fehlt, Agent schweigt.

Dazu die Auskunft, die ACME erzwingt: **`*.example.de` deckt `a.b.example.de`
nicht.** Hat das Abonnement solche Namen, nennt die Seite sie — als Hinweis,
nicht als Fehler.

Fünf neue Brüche im Bruchskript: die Basisdomain vor dem Stern, ein Platzhalter
ohne Zugangsdaten, einer zu einer Subdomain, DNS-01 auch ohne Platzhalter, und
eine Fähigkeit, die nicht im Payload ankommt.

**Der Blick bei 390 px ist gefahren, über den Ersatzweg** — das gebaute
Stylesheet mit dem Markup des Bausteins in einer eigenen Datei, gerendert im
vorinstallierten Chromium: kein Überlauf, in beiden Themes, und die langen
Kennungen brechen. **Dabei hat der Messweg selbst zuerst gelogen:** Chromium
klemmt das Fenster in Headless auf mindestens 500 px, `--window-size=390` wird
stillschweigend ignoriert. Gerendert wurde also bei 500 px und der Screenshot
bei 390 abgeschnitten — das Bild zeigte abgeschnittenen Text, und die Messung
sagte trotzdem null. Gemessen wird seitdem ein Rahmen im Dokument und nicht das
Fenster. *Ein Werkzeug, das die Wächter trägt, braucht selbst einen* — derselbe
Fall wie beim Bruchskript, dem sein `sed` ins Leere lief.

**Und ein Fehler, der eine Runde gekostet hat, mit seiner Lehre.** Eine
Textersetzung im Skript hat den halben Block stehen lassen: Der Konstruktor
nahm die neue Naht entgegen, die alte Methode daneben griff weiter auf eine
Eigenschaft zu, die es nicht mehr gab. `php -l` sieht davon nichts — eine
undefinierte Eigenschaft ist ein Laufzeitfehler —, und PHPStan läuft hier nur
über `agent/`. Acht Tests waren rot. Es gibt dafür jetzt eine Probe im
Scratchpad, die den Text nach `$this->x` ohne zugehörige Erklärung durchsucht;
sie hätte den Fall in einer Sekunde gemeldet. **Wer eine Ersetzung schreibt,
sieht danach nach, dass das Alte weg ist** — nicht nur, dass das Neue da ist.

Dazu die vierte Runde derselben Sorte an der lokalen Fixer-Einstellung: Pint
meldete `braces_position` und `phpdoc_separation`, und beide wollten in der
Voreinstellung des Fixers hundert bzw. fünfunddreissig Dateien anfassen, die
die CI heute annimmt. Sie stehen deshalb **nicht** in der lokalen Fassung, und
der Grund steht als Kommentar daneben: *Eine Regel gehört nur dorthin, wenn sie
genau das meldet, was die CI meldet.*

Was aussteht: der Blick auf die **echte** Seite (dafür fehlt `vendor/`) und die
Abnahme gegen eine echte Zone (`docs/34 §10`).

#### Die Anmeldeadresse eines zurückgezogenen Kunden wird frei

**Der Fund kommt aus dem Betrieb, und er sah aus wie ein kaputter Knopf.** Ein
Kunde liess sich nicht anlegen: ausfüllen, „Anlegen", und scheinbar passierte
nichts — kein Eintrag im Prüfprotokoll, keine Zeile im Fehlerprotokoll, kein
Kunde. Es waren zwei Fehler übereinander.

**Der erste: Eine weiche Löschung sperrte mehr, als sie darf.** Die
Kundennummer *soll* vergeben bleiben — sie steht in Rechnungen, und zwei
Vertragspartner mit derselben wären ein Buchhaltungsproblem. Die Anmeldeadresse
soll es nicht: Wer einen Kunden zurückzieht und ihn neu anlegt, hat denselben
Menschen vor sich. Sie blieb trotzdem belegt, und zwar nicht aus Versehen in
der Validierung, sondern durch den Unique-Index auf `accounts.email`. Nur die
Regel zu lockern hätte aus der stummen Abweisung einen Serverfehler gemacht.

**Freigegeben wird jetzt beim Zurückziehen, und `null` heisst frei.** Die
Alternative wäre eine Adresse gewesen, die niemand tippen kann —
`zurueckgezogen-12@invalid` oder dergleichen. Das ist genau die Sorte
Zeichenkette, an der dieses Projekt wiederholt verloren hat: Sie sieht aus wie
eine Adresse, ist keine, und jede Stelle, die sie anzeigt oder anschreibt,
müsste das wissen. Ein Unique-Index verträgt beliebig viele `null`.

**Das Konto selbst bleibt stehen** — das Prüfprotokoll zeigt per `account_id`
darauf, und ein Eintrag ohne Urheber ist als Protokoll wertlos. Was verloren
ginge, hält der Eintrag `customer.withdrawn` fest: Er nennt die freigegebenen
Adressen. Und weil `email` damit `null` sein kann, gibt es
`Account::signInAddress()` für die Stellen, die eine echte brauchen. Sie wirft,
statt eine leere Zeichenkette weiterzureichen — die stünde als Schlüssel der
Ratenbegrenzung für **alle** Konten zugleich, und ein `clear()` darauf setzte
die Sperre für jeden zurück.

**Nachgeprüft und nicht geglaubt:** Ein zurückgezogenes Konto kam schon vorher
nicht mehr herein (`LoginController` weist es ab), und es gibt keinen Weg,
einen Kunden wiederherzustellen — die freigegebene Adresse kann also nie zu
zwei aktiven Konten mit derselben führen.

**Der zweite Fehler: Es gab keine Meldung, die man findet.** Die Abweisung war
die ganze Zeit da — als kleine rote Zeile unter dem Feld, mitten in einem
langen Formular. Inertia setzt die Scrollposition nach einer Antwort zurück;
oben angekommen sieht man dieselben ausgefüllten Felder wie vorher und schliesst
auf einen kaputten Knopf. **Eine Meldung, die man suchen muss, ist keine.**

`FormErrors` fasst deshalb zusammen, was schiefging, und steht über dem ersten
Formular jeder Seite — dort, wo der Blick nach dem Sprung landet. Sie liest den
Fehlersatz der **Seite** und nicht den eines Formulars: `useForm().errors`
füllt sich nur bei der Anfrage dieser einen Instanz, und die Domainseite hat
drei Formulare. `preserveScroll` braucht es dafür ausdrücklich *nicht* — steht
die Zusammenfassung oben, führt der Sprung genau zu ihr.

**Es waren dreizehn Seiten mit Formularen, und bei zwölf davon wäre derselbe
Fehlschlag genauso unsichtbar gewesen.** Deshalb ist es ein Wächter geworden
und keine Notiz: `FormErrorTest` verlangt die Zusammenfassung auf jeder Seite,
die `useForm` benutzt, und prüft, dass sie am Fehlersatz der Seite hängt.

Zwei neue Brüche: die Adresse, die belegt bleibt, und eine Seite ohne
Zusammenfassung.

#### Der Stable-Kanal, der nie unserer war

**`install.sh` bot als Vorgabe `stable` an, und dorthin ist nie etwas
freigegeben worden.** Aufgefallen ist es beim Nachsehen, warum die
Pages-Auslieferung von `v0.4.0-rc.7` scheiterte — nicht durch einen Test, der
zubiss.

Unter `apt/dists/stable/` lag auf der Seite weiterhin der Index des
Vorgängerprojekts: 68 Fassungen von `asylum-panel` und
`asylum-archive-keyring`, `Origin: Project Asylum`, Stand 2. August, signiert
mit `FC79D6FB…EEB9AA1A` statt mit unserem `58EE4644…C4393E86`. Die
`pool`-Dateien dazu sind im August entfernt worden, der Index nicht: Der
Freigabelauf schreibt nur `dists/<kanal>` des **gerade freigegebenen** Kanals
neu, und ein Kanal ohne Freigabe enthält, was immer dort lag.

Wer dem dokumentierten Weg folgte — `curl … install.sh | sudo sh` —, bekam
eine Paketquelle mit `Suites: stable`, verwiesen auf unseren Schlüsselbund.
Das erste `apt-get update` endete im
`NO_PUBKEY`, `apt-get install srvpanel` fand nichts. **Die Erstinstallation war
kaputt, und beide Hälften waren still** — derselbe Fehlschlag wie beim
fehlenden `php-source.sh`, gefunden wieder nicht von einem Test.

Die Vorgabe heisst jetzt `beta`. Sie ist damit aber nur zufällig richtig, und
das ist der eigentliche Punkt: **eine Zeichenkette, die auf einen Kanal zeigt,
und nichts prüfte den Bezug.** `PackagingTest` prüft ihn seitdem gegen
`packaging/stable-release` — dieselbe Marke, die auch `version-channel.sh`
steuert, statt einer zweiten Fassung derselben Entscheidung. Solange dort keine
Fassung steht, muss die Vorgabe `beta` heissen; steht eine drin, muss sie
`stable` heissen.

**Beide Richtungen, und das mit Absicht.** Ein Wächter, der nur beim Betreten
der Beta-Phase zubeisst, verschwindet beim Verlassen: Nach der ersten stabilen
Freigabe bekäme sonst jeder Neuzugang weiter eine Vorabfassung angeboten, und
niemand meldete es. Zwei neue Brüche entsprechend — die alte Vorgabe zurück,
und die Marke gesetzt ohne nachgezogene Vorgabe.

Was auf `gh-pages` unter `dists/stable/` und als `apt/asylum-archive-keyring.gpg`
liegt, gehört gelöscht; ein Kanal, den es nicht gibt, meldet sich klar, einer
mit fremdem Index nicht.

#### Schritt 6b: die Oberfläche zu den Zugangsdaten

**Bis hierher ging DNS-01 nur über `srvpanel dns`.** Für den Betreiber ist die
Kommandozeile oft der bequemere Weg — wer einen Server einrichtet, hat den
Schlüssel gerade im Terminal. Für einen Kunden ist sie kein Weg: Gibt sein Plan
`dns_edit` frei, führt er seine Zone selbst und hält den Schlüssel dazu ohnehin
in den Händen, hätte ihn aber nur loswerden können, indem er ihn dem Betreiber
schickt. Damit wäre die Freigabe im Plan ein Etikett gewesen.

Es sind zwei Orte für dieselbe Sache (`docs/34 §5`): `/settings/dns` für das
Profil `betrieb`, und ein Bereich am Abonnement für `abo-<nummer>`. Beide
benutzen dieselbe Komponente und denselben Zugang (`DnsCredentialAccess`,
`DnsCredentialInput`) — zwei Fassungen davon wären das Muster, an dem dieses
Projekt am häufigsten verloren hat.

**Der Profilname kommt nie aus dem Formular.** Er wird aus dem Abonnement
abgeleitet; käme er aus der Anfrage, könnte ein Kunde `abo-7` eintragen und die
Zone eines anderen bearbeiten lassen. Dieselbe Haltung wie bei den
Verzeichnisnamen der Systembenutzer. Die Berechtigung hängt an
`SubscriptionPolicy::manageDns` — `Feature::DnsEdit` **und**
`Permission::Dns`, und ausdrücklich nicht am Kontotyp: Auch ein Admin bekommt
das Formular nicht an einem Abonnement ohne die Freigabe, denn dort gäbe es
kein Profil, das je gefragt würde.

**Ohne Warteschlange, mit Absicht.** Ein eingereihter Vorgang legt seine
Argumente in `operations.payload` ab; ein DNS-Token läge damit dauerhaft im
Klartext in der Datenbank. Es überquert den Socket genau einmal, beim
Hinterlegen.

**Der Agent gibt jetzt die Zonen heraus — und nur die.** Sie sind kein
Geheimnis, und sie beantworten die Frage, die man vor dem Bestellen hat: Deckt
dieses Profil die Zone dieser Domain überhaupt? Ohne sie sagt der Agent das
erst beim Bestellen, und dann ist ein Fehlversuch verbraucht, der bei
Let's Encrypt für **jeden** Kunden dieses Servers zählt.

Gelesen wird über eine **Positivliste** und nicht über eine Sperrliste: Das
Geheimnis heisst je Anbieter anders — `secret` bei RFC 2136, bei den vier aus
Schritt 9 wird es `token` oder `api_key` heissen. Eine Liste dessen, was nicht
hinaus darf, ist beim fünften Anbieter unvollständig, und niemandem fällt es
auf. Der Wächter prüft deshalb den **Schlüsselsatz** von `describe()` und nicht
die Abwesenheit eines Wortes; der Bruch dazu reicht die ganze Konfiguration
durch.

**Und dieser Schritt hat eine Lücke im eigenen Wächterwerk aufgedeckt.**
`FormErrorTest` suchte in den Seiten nach `useForm`. Das Formular für die
Zugangsdaten steht als Komponente unter `Components/`; die Abonnementseite
bindet es ein und enthält das Wort nirgends — sie galt damit als formularlos
und wäre ohne die Zusammenfassung durchgegangen. Genau der Fehler, den
`FormErrors` beheben sollte, hätte sich an der ersten Seite wiederholt, die
danach entstand. Der Wächter sammelt jetzt zuerst die Komponenten, die selbst
ein Formular abschicken, und rechnet eine Seite dazu, die eine davon einbindet.
Die Gegenprobe zeigt beides: Der Bruch wird von der neuen Fassung gemeldet und
von der alten nicht.

Zwei neue Brüche: eine Seite, die ihr Formular aus einer Komponente holt, ohne
Zusammenfassung — und eine Auskunft, die die Konfiguration durchreicht.

**Und der Blick bei 390px hat wieder etwas gefunden, das grün getestet war:**
305px Überlauf an der Zonenliste. Zwei Regeln greifen dabei ineinander —
`td .ident` steht auf `nowrap` (in einer Tabelle richtig, man kann sie
schieben), und `table.pairs td.right` bekommt auf schmaler Fläche `flex: none`,
behält also seine Inhaltsbreite. Die Ausnahme, die es dafür längst gibt, hängt
an `table.pairs td.right.ident` — an der **Zelle**. Eine Liste in einem Span
*innerhalb* einer gewöhnlichen Zelle fällt durch beide Maschen. Behoben im
Markup und nicht in der Regel: Die Zelle trägt die Kennung, dann bricht die
Liste an ihren Leerzeichen um.

**Dabei ist die Messung selbst zuerst falsch gewesen.** Playwrights
`colorScheme: 'dark'` sah aus wie eine Umschaltung und tat nichts — das Theme
dieses Panels hängt an `[data-theme]` und nicht an `prefers-color-scheme`.
Beide Aufnahmen waren hell, und die dunkle Fassung wäre ungeprüft
durchgegangen. Gesetzt wird das Attribut jetzt ausdrücklich, und die Probe
liest zur Kontrolle die Hintergrundfarbe zurück: `#fff` gegen `#0f1116`.

#### Ein Freigabelauf, der ein zweites Mal laufen darf

**Der 6. August hat es vorgeführt.** GitHubs Warteschlange liess fünf Anläufe
des Freigabelaufs ohne Runner verhungern — `runner_id: 0`, kein einziger
Schritt, nach fünfzehn Minuten abgeräumt. Der sechste kam durch: gebaut,
signiert, `v0.4.0-rc.8` als Release angelegt. Der siebte brach an
`gh release create` ab, mit „a release with the same tag name already exists".

Diese Abweisung ist für sich richtig; ein fertiges Release soll niemand
versehentlich überschreiben. **Der Schaden lag daneben:** Der Job `package`
galt als gescheitert, und damit wurde `repository` übersprungen. Die
Paketquelle blieb ohne die Fassung, die als GitHub-Release längst dastand — ein
halber Zustand, den an einer Meldung über das Release niemand erkennt.

Der Fall wird jetzt unterschieden statt verboten: Gibt es das Release schon,
werden die Dateien ersetzt. Das ist gewollt und nicht bloss geduldet — der Lauf
baut aus einem Tag, der Inhalt ist derselbe, und Prüfsumme wie Signatur werden
im selben Zug mit ausgetauscht. Ein Release, das zur Hälfte aus dem einen und
zur Hälfte aus dem anderen Lauf stammt, wäre schlimmer.

**Warum daraus ein Wächter wurde.** Ein Freigabelauf läuft selten und wird
genau dann wiederholt, wenn ohnehin etwas schiefging. Ein Schritt, der beim
zweiten Mal abbricht, ist deshalb nicht selten, sondern verlässlich im
ungünstigsten Moment im Weg. `PackagingTest` verlangt, dass jeder Ablauf, der
ein Release anlegt, vorher fragt, ob es schon eines gibt.

**Und `tests/waechter-brechen.sh` klammert jetzt auch `.github/`.** Der Bruch
dazu ändert eine Datei dort; ein Bruch in einem Verzeichnis, das
`wiederherstellen` nicht kennt, ist keine Probe, sondern eine Änderung. Genau
aus diesem Grund war `packaging/` in P4 dazugekommen.

#### Schritt 9 beginnt mit einer Grenze, die es schon gab

**Vor dem ersten DNS-Anbieter kam ein Fund.** Der Agent läuft als root; alles,
was er tut, geht über einen Unix-Socket mit Aufruferprüfung oder über Programme
von einer Positivliste. Eine Verbindung zu einem fremden Rechner ist eine
eigene Art von Oberfläche, und ihre vier Zusagen — nur https, keine
Umleitungen, gedeckelte Antwort, Zeitlimit — standen seit P4 **nur als
Kommentar** in `CurlTransport`. Kein Test nannte sie.

Aufgefallen ist das im ungünstigsten denkbaren Moment und deshalb gerade
rechtzeitig: Mit den Anbietern aus Schritt 9 kommt eine zweite Gegenstelle
dazu, und eine zweite Stelle, die dieselben vier Optionen setzt, ist genau das
Muster, an dem dieses Projekt am häufigsten verloren hat — die zweite ist die,
in der eine davon irgendwann fehlt.

Die Grenze steht deshalb jetzt an einer Stelle (`Acme\Curl`), und darüber
liegen zwei Formen: die ACME-förmige (`CurlTransport`, zwei Verben und
`application/jose+json`) und die der Anbieter. `Acme\Outbound` ist die Naht, an
der beide gegen ein Drehbuch geprüft werden können — dieselbe Machart wie
`Transport` für ACME, nur eine Ebene tiefer gezogen.

**`CUSTOMREQUEST` statt `POST`.** Beim Zusammenziehen fiel eine Falle auf, die
vorher nicht bestand: `CURLOPT_POST` und `CURLOPT_CUSTOMREQUEST` zusammen
gesetzt schreiben die Methode zweimal, und welche gewinnt, hängt an der
Reihenfolge im Array. Da die Anbieter `DELETE` brauchen, hängt die Methode
jetzt allein an `CUSTOMREQUEST` und der Rumpf allein an `POSTFIELDS`.

`OutboundSourceTest` prüft beides: dass keine andere Datei unter `agent/` curl
anfasst, und dass die vier Zusagen dort wörtlich stehen. Zwei neue Brüche — eine
Zusage fällt weg, und eine zweite Stelle spricht nach draussen.

#### Schritt 9, erster Anbieter: IPv64.net

**Er ist der erste der fünf, obwohl der Plan ihn zuletzt nennt** — und das aus
einem Grund, der ihn zum besten Prüfstein macht: Bei IPv64.net ist die Zone
häufig selbst eine Unterdomain. `meinname.ipv64.de` ist eine ganze Zone, nicht
ein Name darin. Jede Regel, die sie aus dem Namen errechnet, liegt hier
irgendwann falsch — und zwar **still**, denn ein TXT-Eintrag in der falschen
Zone ist kein Fehler, er wird nur nie gefunden.

**Deshalb wird gefragt und nicht gerechnet.** `get_domains` nennt die Zonen des
Kontos; die längste, die auf den Namen passt, gewinnt. Das ist bewusst eine
andere Wahl als bei lego, dessen `splitDomain` die **letzten drei** Bestandteile
nimmt — nachgesehen im geklonten Quelltext, nicht aus der Erinnerung. Für
`meinname.ipv64.de` kommt dasselbe heraus; für eine eigene Domain, die jemand
zu IPv64.net bringt, nicht mehr: `example.de` hat zwei Bestandteile, und die
Regel gäbe `_acme-challenge.example.de` als Zone aus.

Damit ist auch eine Angabe in `docs/34 §6` genauer geworden: Dort stand,
`get_domains` sei „genau dafür da, und acme.sh macht es ebenso". Die
Schnittstelle stimmt, die Zonenauflösung über sie ist aber **unsere** Wahl und
nicht die von lego.

**Die Zonen werden einmal je Vorgang geholt.** Der Anbieter drosselt und sagt
es mit HTTP 429; das steht als eigene Meldung da, weil ein Vorgang, der ohne
Grund scheitert, wiederholt wird — und einer, der „zu schnell" sagt, abgewartet.

**`null` ist ein Fehlschlag und kein leeres Ergebnis.** Der Anbieter antwortet
in diesem Fall mit dem vier Zeichen langen Rumpf `null`, und `json_decode` macht
daraus brav ein PHP-`null`. Wer das als „nichts gefunden" liest, hält einen
Fehlschlag für einen Normalfall. Ebenso steht die Begründung je nach Aufruf in
`add_record` oder `del_record`, während `info` ein allgemeines Wort trägt — wer
nur `info` liest, bekommt „Nope" und weiss nichts.

**`praefix` ist deutsch geschrieben, weil der Anbieter es so nennt** — eine
echte Schnittstelle nach aussen, die `docs/19 §4a` ausdrücklich lässt, wie sie
ist.

**Und das Formular folgt jetzt dem Anbieter.** RFC 2136 braucht Nameserver,
Zonen, Schlüsselnamen und ein Base64-Geheimnis; IPv64.net ein Token und sonst
nichts. Ein gemeinsames Formular mit lauter freiwilligen Feldern hiesse, dass
beide alles annehmen und die Hälfte ignorieren. `DnsCredentialInput` verzweigt
an derselben Stelle wie das Markup, und `DnsProviderReachTest` prüft beide
Richtungen: Jeder Schlüssel im Markup ist ein Anbieter, den es gibt, und jeder
benutzbare Anbieter hat ein Formular.

Drei neue Brüche: ein Anbieterschlüssel, der ins Leere zeigt · ein benutzbarer
Anbieter ohne Formular · eine Zone, die gerechnet statt gefragt wird.

#### Ein anbieterübergreifender Wächter, der im Test eines Anbieters wohnte

`test_every_provider_key_points_at_something` — der Wächter, der prüft, dass
jeder Anbieterschlüssel entweder gebaut ist oder als offen dasteht — stand in
`Rfc2136Test` und hat sich von dort seine Annahmen geliehen: Er hielt **jedem**
Anbieter denselben Satz Zugangsdaten hin, und das war der eines TSIG-Schlüssels.
Solange RFC 2136 der einzige gebaute war, ging das auf. Mit IPv64.net fiel er —
„Für IPv64.net fehlt das Token." —, und zwar an der Stelle, an der er eigentlich
grün werden sollte.

Er steht jetzt als `ProvidersTest` für sich, mit einem Satz Zugangsdaten **je**
Anbieter. Wer einen baut und die Zeile vergisst, liest einen Satz dazu statt
eines Zugriffs auf einen fehlenden Schlüssel — sonst bliebe der neue Anbieter
einfach ungeprüft, und das fiele erst beim ersten Zertifikat auf. Für die sechs,
die noch kommen, hätte die alte Form sechsmal dasselbe gekostet.

**Und `league/commonmark` steht auf 2.9.0.** Am 6. August kamen zwei Meldungen
für 2.8.3 — eine quadratische Laufzeit beim Zerlegen von Markdown (hoch) und ein
Weg, den Linkfilter der `AttributesExtension` mit Steuerzeichen zu umgehen
(mittel). Das Paket ist eine mittelbare Abhängigkeit und nichts, was dieses
Projekt selbst aufruft; die CI meldet es trotzdem, und genau dafür gibt es den
Lauf „Schwachstellen und Lizenzen". Nur die Sperrdatei wandert, der Rest bleibt.

#### Sieben Anbieter statt vier — und ein achter, den es nicht geben kann

Vor dem zweiten Anbieter wurde die Liste noch einmal gegen die vollständige
gehalten: **222 Anbieter unter `providers/dns/` in lego**, durchgesehen am
7. August. Aus dem deutschsprachigen Markt kamen drei dazu, jeder aus einem
eigenen Grund — **IONOS** (der grösste deutsche Massenhoster, ein Token, eine
REST-Schnittstelle), **INWX** (der Registrar der Wiederverkäufer) und **deSEC**
(gemeinnützig, DNSSEC ab Werk, kostenfrei).

**Der Fund, der deSEC auf die Liste gebracht hat: Strato hat keine öffentliche
DNS-Schnittstelle.** Weder lego noch acme.sh können ihn, und das ist kein
Versäumnis der beiden — es gibt schlicht nichts, worüber sich ein TXT-Eintrag
setzen liesse. Für einen Kunden mit Domain bei Strato heisst das: kein
Platzhalter über DNS-01, solange die Zone dort liegt. Der Ausweg ist die Zone
und nicht das Panel; sie lässt sich zu deSEC delegieren, ohne dass die Domain
umzieht. Das gehört gesagt, weil die naheliegende Antwort — „bauen wir eben
Strato dazu" — hier nicht existiert.

**INWX steht zuletzt, und das ist keine Reihenfolge nach Bequemlichkeit.** Er
ist der einzige der sieben mit Benutzername und Passwort statt eines Tokens, er
spricht XML-RPC statt JSON, er führt eine Sitzung über ein Cookie, und bei
gesichertem Konto rechnet der Agent bei jeder Anmeldung einen TOTP-Code aus.
Vor allem aber öffnet das, was dort hinterlegt wird, ein ganzes Registrarkonto
und nicht eine Zone. Ob ein **Kunde** dort überhaupt etwas hinterlegen soll,
steht als offene Frage in `docs/34 §11`.

Die drei stehen jetzt als offen in `Providers::PENDING` — die Entscheidung ist
damit im Code und nicht nur im Plan, und `ProvidersTest` hält sie fest: Jeder
Schlüssel ist entweder gebaut oder offen, und wer offen ist, lässt sich nicht
hinterlegen. Die Zugangsdatenformen aller sieben sind in `docs/34 §6`
tabelliert — nachgesehen und nicht angenommen. Drei Stellen darin sind teurer,
als sie aussehen: Hetzner führt **zwei** Schnittstellen nebeneinander (alte
DNS-Konsole und Cloud-API, und ein Token der einen gilt bei der anderen nicht),
netcup ist der einzige mit einer Sitzung aus `login`/`logout`, und der eine
IONOS-Schlüssel ist in Wahrheit zwei Felder in der Form `<prefix>.<secret>`.

**Und eine Berichtigung.** `docs/34 §6` schrieb, `get_domains` sei „genau dafür
da, und acme.sh macht es ebenso", lego „verlange mindestens drei Bestandteile".
Beim Bauen nachgesehen: lego benutzt `get_domains` zur Zonenauflösung **nicht**
— sein `splitDomain` nimmt die letzten drei Bestandteile des Namens. Die
Abfrage ist damit unsere Wahl und nicht die von lego, und sie ist die bessere.
Das gehört richtiggestellt, weil eine Begründung, die sich auf einen anderen
beruft, beim nächsten Anbieter wieder herangezogen wird — und dann für etwas,
das dort nicht stimmt.

#### Schritt 9, dritter Anbieter: Hetzner — gegen die Cloud-API

Hetzner führt **zwei** Schnittstellen für dasselbe: die ältere der DNS-Konsole
(`dns.hetzner.com/api/v1`, Kopfzeile `Auth-API-Token`) und die neuere als Teil
der Cloud-API (`api.hetzner.cloud/v1`, `Authorization: Bearer`). Gebaut ist die
**Cloud-API**, weil Hetzner die DNS-Verwaltung dorthin überführt hat und ein
Panel, das gegen die auslaufende Strecke baut, sie zweimal bauen muss. Belegt
ist der Endpunktsatz nicht aus lego, sondern aus Hetzners eigenem Go-SDK
(`hetznercloud/hcloud-go`) — `GET /zones` mit Blätterauskunft,
`POST /zones/{zone}/rrsets/{name}/TXT/actions/add_records` und `remove_records`.

**Ein Token der einen gilt bei der anderen nicht, und an der Form lassen sie
sich nicht unterscheiden.** Nachgesehen und nicht gefunden — also steht der
Hinweis dort, wo er ankommt: im Formular vor der Eingabe, und in der Meldung zu
401 und 403. Eine Abweisung, die nur „unauthorized" sagt, führt dazu, dass
jemand dasselbe Token ein zweites Mal einträgt.

**Der Schreibvorgang ist beim Antworten nicht fertig.** Die Cloud-API antwortet
mit einer *Action*, die auf `running` stehen kann. Gewartet wird trotzdem nicht:
Ob der Eintrag ausgeliefert wird, beantwortet ohnehin nur `DnsChallenge` durch
Fragen der autoritativen Nameserver, und das ist die strengere Frage. Gelesen
wird der Zustand **einmal**, und zwar wegen `error` — der steht sofort da und
spart eine Prüfung, die zwei Minuten auf einen Eintrag wartet, den niemand mehr
anlegt.

**Der TXT-Wert geht in Anführungszeichen hinaus**, wie eine Zonendatei ihn
schreibt. Ein Wert mit einem Anführungszeichen oder Rückstrich darin bräuchte
eine Fluchtregel — eine eigene kleine Sprache mit eigenen Fehlern. Ein
ACME-Prüfwert ist Base64 ohne Polster und enthält beides nie; was es doch
enthält, wird abgewiesen statt halb richtig verpackt.

**Der Fund, den kein Test gemacht hat.** Die Blätterschleife lief endlos. Sie
verglich die *Seitennummer* mit der Obergrenze — kommt `next_page` mit `1`
zurück, während `page` auf `1` steht, ist „Seite kleiner als die Obergrenze"
für immer erfüllt. Gefunden hat es eine Wegwerfprobe, die nicht zurückkam;
ein Test an dieser Stelle hätte die CI blockiert statt rot zu werden. Gezählt
werden jetzt die **Runden**, und das Ende wird gemeldet statt still
abgeschnitten: Wer hier einfach aufhört, sagt gleich darauf „für diesen Namen
keine Zone" und nennt damit einen Grund, der nicht stimmt.

Der Bruch dazu bricht deshalb bewusst nur die Hälfte, die still zurückfallen
kann — das Melden. Ein Bruch des Deckels selbst hinge, und ein hängender Lauf
ist schlimmer als ein fehlender; das steht als Absatz im Bruchskript.

#### Die Zonenauflösung stand zweimal da und wäre dreimal geworden

Die Regel ist bei jedem Anbieter dieselbe: Von allen Zonen, in denen ein Name
liegt, gewinnt die **längste**. Sie stand als eigene Schleife bei RFC 2136 und
noch einmal bei IPv64.net. Mit Hetzner wäre sie zum dritten Mal geschrieben
worden — dasselbe Muster wie beim Rechnernamen, den dieses Projekt viermal neu
erfunden hat, bis `HostnameSourceTest` dazwischenging.

Sie steht jetzt in `Zones`, und `ZoneSourceTest` besteht darauf: `Name::within()`
ruft nur diese eine Stelle. Der Fehler wäre still gewesen — eine Schleife, die
die erste statt der längsten passenden Zone nimmt, legt den Eintrag eine Ebene
zu hoch an. Der Anbieter nimmt das an, es gibt keine Meldung; die Prüfung findet
ihn nur nie. Die zwei Richtungen haben zwei Tests: `erste gewinnt` bricht den
einen, `letzte gewinnt` den anderen, und beide wurden gegengeprüft.

#### Ein Satz in einer Zelle, die nicht umbricht — 128px, ausgeliefert im PR

Die Zonenzeile der Auskunft trug im Leerfall einen Satz: „aus dem Konto bei
IPv64.net — dieses Profil ändert, was dort geführt wird". Bei 390px sind das
**128px Überlauf**. `table.pairs td.right` steht auf `nowrap` und `flex: none`;
ein Satz darin wird weder umbrochen noch zusammengedrückt, und die Ausnahme
dafür hängt an `ident` — also an einer Kennung, die ein Satz nicht ist.

**Er war grün getestet und gemessen.** Gemessen wurde die Seite *ohne*
hinterlegte Zugangsdaten, und in diesem Zustand gibt es die Zeile nicht. Das ist
dieselbe Lücke wie bei den drei Fehlern aus `v0.4.0-rc.4`: Eine Aufnahme prüft
den Zustand, den sie zeigt, und keinen anderen.

In der Zelle steht jetzt nur noch der Wert — `vom Anbieter`, `keine` oder die
Zonen —, die Sätze stehen als `hint` unter der Tabelle. Nachgemessen bei 390px
in beiden Themes: 0px, auch mit einer dreigliedrigen Zonenliste.

#### Schritt 9, vierter Anbieter: Cloudflare — und die Anmeldung, die fehlt

Cloudflare kennt zwei Arten von Zugangsdaten. Die ältere ist der globale
API-Schlüssel samt Kontoadresse (`X-Auth-Email`, `X-Auth-Key`) und öffnet **das
ganze Konto** — alle Zonen, alle Einstellungen, den Zugriffsschutz. Die neuere
ist ein API-Token, eingrenzbar auf einzelne Zonen und auf zwei Rechte:
`Zone:Read`, um die Zonen zu finden, und `DNS:Edit`, um den Prüfeintrag zu
setzen.

**Angeboten wird nur das Token.** lego nimmt beide entgegen und rät im
Kommentar vom globalen Schlüssel ab. Ein Rat in einem Kommentar ist hier zu
wenig: Was in einem Formular steht, wird ausgefüllt, und was ausgefüllt wird,
liegt danach als Geheimnis auf einem Server, auf dem Kunden Websites
betreiben. Eine mitgeschickte Kontoadresse wird deshalb **abgewiesen** und
nicht stillschweigend fallengelassen — sonst käme die Abweisung von Cloudflare,
mit einem Satz, der den Grund nicht nennt.

**Gelöscht wird über eine Eintragskennung, nicht über den Wert** — der
Unterschied zu Hetzner und IPv64.net. lego merkt sich die Kennung beim Anlegen
in einer Ablage; wir suchen sie beim Abräumen. Der Grund steht in
`DnsProvider::remove()`: Der Aufruf läuft auch nach einem Fehlschlag, und dann
ist die Ablage leer. lego bricht an dieser Stelle mit „unknown record ID" ab
und macht aus einem Fehlschlag zwei.

**Gefiltert wird zweimal, und das ist Absicht.** Cloudflare grenzt serverseitig
ein (`type`, `name.exact`), aber seine Filter sind ausdrücklich *nicht* auf
Gross- und Kleinschreibung bedacht — nachgesehen in Cloudflares eigenem Go-SDK.
Ein ACME-Prüfwert ist Base64 und damit sehr wohl. Deshalb wird der Wert nach
dem Suchen noch einmal Zeichen für Zeichen verglichen: Ohne das löschte eine
Bestellung den Eintrag einer anderen, die sich nur in der Schreibweise
unterscheidet.

**Und `success` zählt, nicht nur der HTTP-Code.** Cloudflare antwortet auf einen
abgelehnten Vorgang durchaus mit 200 und `"success": false`. Wer nur den Code
liest, hält das für erledigt und wartet danach zwei Minuten auf einen Eintrag,
den es nicht gibt.

Fünf neue Brüche, alle gegengeprüft.

#### Der TXT-Wert stand zweimal da — diesmal beim zweiten Mal bemerkt

Ein TXT-Eintrag besteht aus „character-strings" in Anführungszeichen
(RFC 1035 §3.3.14), und Hetzner wie Cloudflare nehmen den Wert genau so
entgegen. Bei Hetzner stand die Regel als private Methode; mit Cloudflare wäre
sie zum zweiten Mal geschrieben worden.

Sie steht jetzt in `TxtValue`, und `TxtValueSourceTest` besteht darauf. Das ist
dieselbe Sorte Fund wie bei der Zonenauflösung eine Runde vorher — nur früher:
Dort stand die Regel dreimal, bevor jemand hinsah.

**Dabei ist eine Abweisung dazugekommen, die vorher fehlte: die Länge.** Ein
Anbieter teilt einen Wert über 255 Zeichen stillschweigend in zwei
character-strings, und ein TXT-Satz aus zwei Teilen ist für die
Zertifizierungsstelle ein anderer Wert — der Vorgang scheitert dann an einer
Ursache, die nirgends steht. Ein ACME-Prüfwert ist 43 Zeichen lang; was länger
ankommt, ist keiner.

`TxtValue::matches()` ist die Gegenrichtung und ebenfalls neu: Beim Abräumen
zählen beide Formen, weil Cloudflare die Anführungszeichen ablegt und
zurückgibt, andere aber den nackten Wert liefern. Wer nur die eine Form
vergleicht, findet seinen eigenen Eintrag nicht — und lässt eine Aussage über
die Zone stehen, die niemand mehr zurücknimmt.

#### Schritt 9, fünfter Anbieter: netcup — der erste mit einer Sitzung

Drei Dinge sind bei netcup anders als bei allen bisherigen.

**Erstens: eine Sitzung.** Vor jedem Zugriff steht ein `login`, danach ein
`logout`. An- und abgemeldet wird **je Vorgang** und nicht einmal für die
Lebensdauer des Objekts: Ein Abmelden im Destruktor wäre Netzverkehr zu einem
Zeitpunkt, den niemand bestimmt, und eine Ausnahme darin ist in PHP ein fataler
Fehler. Zwei Regeln hängen daran, und beide haben ihren Bruch:

- Das Abmelden steht im `finally` — sonst bliebe eine Sitzung bei einem fremden
  Anbieter genau dann liegen, wenn der Zugriff dazwischen scheitert. Das ist
  der Fall, der sich häuft.
- Sein Ergebnis wird **nicht** geprüft. Ein gescheitertes Abmelden machte sonst
  aus einem gesetzten Eintrag einen Fehlschlag, und der Vorgang würde
  wiederholt, obwohl er durchgelaufen ist — bei Let's Encrypt zählt jeder
  Fehlversuch für alle Kunden dieses Servers.

**Zweitens: die Zonen stehen in den Zugangsdaten.** Die DNS-Schnittstelle von
netcup kennt keine Auskunft, die die Domains eines Kontos aufzählt; lego fragt
dafür die autoritativen Nameserver nach dem SOA-Satz. Das wäre eine dritte
Quelle für dieselbe Frage. Stattdessen gilt dieselbe Antwort wie bei RFC 2136
und aus demselben Grund: eine Positivliste, die der Betreiber aufschreibt. Ein
Name ausserhalb kostet damit nicht einmal eine Anmeldung.

Dabei ist eine Stelle in der Oberfläche aufgefallen, die still falsch geworden
wäre: Die Auskunft entschied mit „alles ausser RFC 2136", ob die Zonen vom
Anbieter kommen. netcup wäre auf der falschen Seite gelandet — die Seite hätte
„vom Anbieter" gesagt, wo der Betreiber sie selbst eingetragen hat. Die beiden
stehen jetzt als Liste da, und wer einen Anbieter baut, muss ihn einordnen.

**Drittens: geschrieben wird nur der eine Satz.** lego liest an dieser Stelle
**die ganze Zone**, hängt den neuen Eintrag an und schickt alles zurück. Das ist
ein Lesen-Ändern-Schreiben über den Bestand eines Kunden. Es ist auch unnötig:
`updateDnsRecords` legt an, was keine Kennung hat, und löscht, was
`deleterecord` trägt — es ersetzt den Bestand nicht. Belegt ist das aus legos
eigenem `CleanUp`, das genau einen Satz schickt; wäre der Aufruf ein Ersetzen,
nähme er jedem netcup-Nutzer beim Abräumen die Zone. Beim ersten echten Zugriff
gehört das gegen netcups Dokumentation gehalten, so wie beim Endpunktsatz von
IPv64.net — die Seiten von netcup sind aus diesem Container nicht erreichbar.

**Und beim Löschen zählt der Name mit.** lego vergleicht nur Wert und Art;
stehen zwei Prüfeinträge mit demselben Wert unter verschiedenen Namen, ist das
der falsche Satz.

**Der Fund, den hier sonst nichts findet.** Der Abschluss in `add()` nahm
`$record` nicht mit, und die Fehlermeldung hätte den Namen der Domain verloren.
Gefunden hat es die Wegwerfprobe über `agent/src/autoload.php` — PHP warnt zur
Laufzeit, wo `php -l` nichts sieht und PHPStan erst in der CI läuft.

Vier neue Brüche, alle rot und grün gegengeprüft.

#### Schritt 9, sechster Anbieter: IONOS — ein Feld, das in Wahrheit zwei ist

Der Schlüssel hat die Form `<präfix>.<geheimnis>`, und IONOS zeigt beide Teile
**getrennt** an — der Präfix steht dort obenan und sieht aus wie der Schlüssel.
Wer nur ihn einträgt, bekommt ohne Prüfung erst nachts bei einer Erneuerung eine
Abweisung, die von einem ungültigen Schlüssel spricht. `configure()` verlangt
deshalb genau einen Punkt und zwei nicht leere Hälften, das Formular sagt es
vorher, und die Meldung zu 401 und 403 nennt es noch einmal.

**`suffix` ist ein Suffix und kein Name.** Der Filter von IONOS
(`?suffix=<name>&recordType=TXT`) liefert auch `x.<name>` mit. Ohne einen
zweiten Abgleich wanderte ein fremder Name in die Liste, die zurückgeschickt
wird — und beim Löschen in die Auswahl.

**Beim Anlegen folgen wir lego, und das ist hier die Entscheidung.** `PATCH
/zones/<id>` bekommt eine Liste von Sätzen; ob der Aufruf sie *hinzufügt* oder
den Bestand zu diesem Namen *ersetzt*, geht aus legos Code nicht hervor, und die
Seiten von IONOS sind aus diesem Container nicht erreichbar. Bei netcup liess
sich dieselbe Frage aus legos eigenem `CleanUp` beantworten — hier nicht.
Solange das offen ist, gilt der Weg, der unter **beiden** Lesarten richtig ist:
erst die vorhandenen Sätze zu diesem Namen holen, den neuen anhängen, alles
schicken. Gelesen wird dabei nur, was auf denselben Namen zeigt, und nicht die
ganze Zone — der Einwand von netcup trifft hier also nicht mit derselben Wucht.

**Und eine Unstimmigkeit in lego, die uns nichts kostet:** Sein `Present`
schickt den Wert nackt, sein `CleanUp` sucht ihn in Anführungszeichen. Eines von
beidem stimmt nicht, und welches, hängt daran, ob IONOS beim Ablegen umschreibt.
`TxtValue::matches()` nimmt beide Formen — genau dafür ist es eine Runde vorher
entstanden.

Nebenbei zwei kleinere Unterschiede zu lego: Dessen `findZone` vergleicht mit
`strings.HasSuffix`, also als Zeichenkette — `boesexample.de` endet auf
`example.de` und gehört trotzdem jemand anderem; `Zones` vergleicht
beschriftungsweise. Und sein `CleanUp` wirft, wenn es nichts findet, obwohl es
auch nach einer gescheiterten Bestellung läuft — aus einem Fehlschlag würden
zwei.

`ScriptedOutbound::json()` nimmt jetzt auch eine schlichte Liste: IONOS
antwortet auf `GET /zones` mit einem Feld und nicht mit einer Ablage, und ein
Drehbuch, das nur Ablagen kennt, könnte diesen Anbieter gar nicht nachstellen.

Drei neue Brüche, alle rot und grün gegengeprüft.

#### Schritt 9, siebter Anbieter: deSEC — der einzige, der die Zonenfrage beantwortet

**`owns_qname` ist die beste Auskunft der sieben.** Alle anderen nennen ihre
Zonen, und dieses Panel sucht sich die längste passende heraus (`Zones`); deSEC
nimmt den vollen Namen entgegen und antwortet mit genau der Domain, die für ihn
zuständig ist. Eine Anfrage statt einer Liste, kein Blättern — und die Regel
„die längste gewinnt" steht hier gar nicht zur Debatte, weil sie beim Anbieter
liegt. Das ist der einzige Anbieter, der ohne `Zones` auskommt, und
`ZoneSourceTest` bleibt davon unberührt: Es wird nicht verglichen, sondern
gefragt.

**deSEC führt RRsets, keine einzelnen Sätze.** Alle TXT-Werte zu einem Namen
sind ein Gegenstand mit einer Liste. Einen Prüfwert hinzuzufügen heisst deshalb
lesen, anhängen, zurückschreiben — anders als bei netcup ist das
Lesen-Ändern-Schreiben hier keine Bequemlichkeit, sondern die Form der
Schnittstelle. Zwei Grenzen hängen daran, jede mit ihrem Bruch: Beim Anlegen
wird **angehängt** und nicht ersetzt, beim Abräumen fällt **nur der eigene
Wert** heraus. Wer eines von beidem übersieht, nimmt einer gleichzeitig
laufenden Bestellung ihre Prüfung weg.

**Und `204` ist Erfolg.** Nimmt man den letzten Wert heraus, verschwindet der
RRset, und deSEC quittiert das mit `204` — der Normalfall am Ende jeder
Bestellung. Der Bruch dazu geht bewusst an `Response::successful()` und nicht an
den Anbieter: Die Regel „2xx ist Erfolg" steht dort für alle, und deSEC ist der
erste, bei dem sie einen anderen Code als 200 tragen muss.

Ein RRset, den es noch nicht gibt, antwortet mit `404`. Das ist kein Fehler,
sondern die Auskunft, dass angelegt statt geändert werden muss — und beim
Abräumen, dass nichts zu tun ist.

Der TTL ist mit 3600 der höchste der sieben; deSEC nimmt für ein gewöhnliches
Konto nichts Kürzeres. Das steht als Kommentar an der Konstante, weil daran
hängt, dass `ready()` hier nicht nach zwei Minuten aufgeben darf.

Drei neue Brüche, alle rot und grün gegengeprüft. **Damit ist deSEC der
Anbieter, wegen dem Strato auf der Liste fehlen darf:** Wessen Anbieter keine
Schnittstelle hat, delegiert die Zone hierher, ohne die Domain umzuziehen.

#### `Totp` zieht in den Agenten — damit es ihn nur einmal gibt

Die Klasse für zeitbasierte Einmalkennwörter stand in `app/Support/Auth`, für
den zweiten Faktor der Anmeldung. Mit INWX kommt ein zweiter Aufrufer: Dessen
Schnittstelle verlangt bei einem gesicherten Konto einen TAN, und der entsteht
aus einem Geheimnis, das der Agent hält und die Anwendung nach dem Speichern
nie wiedersieht.

**Der Agent kann nicht auf `app/` zugreifen** — die andere Richtung geht,
`SrvPanel\Agent\` ist von dort autoladbar. Die naheliegende Antwort wäre also
eine zweite Umsetzung im Agenten gewesen, und damit genau das Muster, an dem
dieses Projekt am häufigsten verloren hat. Sie im Agenten zu haben ist der
einzige Weg, sie **einmal** zu haben.

**Der Fehler wäre teuer und still zugleich.** Eine zweite Fassung, die die
Abschneideregel aus RFC 4226 §5.3 um ein Byte danebenlegt, erzeugt Codes, die
*manchmal* stimmen — immer dann, wenn das Versatz-Halbbyte klein genug ist. Ein
Anbieter, der sich alle paar Stunden nicht anmelden lässt, ist schwerer zu
finden als einer, der es nie tut. `TotpSourceTest` besteht deshalb darauf, dass
`hash_hmac` mit SHA1 nur an dieser einen Stelle vorkommt — und prüft die Stelle
gegen den Testvektor aus RFC 6238 Anhang B.

Die Klasse ist unverändert; es wandert nur der Namensraum, und mit ihm fünf
Verweise.

#### Schritt 9, achter Anbieter: INWX — und damit sind alle acht gebaut

INWX ist der teuerste der Liste, und zwar aus vier Gründen auf einmal.

**Erstens: XML-RPC.** Er ist der einzige, der kein JSON spricht, und PHPs eigene
`xmlrpc`-Erweiterung gibt es seit PHP 8 nicht mehr. Der Umgang damit steht in
`XmlRpc` und nicht im Anbieter — gebaut ist nur, was gebraucht wird: ein Aufruf
mit einem flachen Parametersatz. **Beim Lesen ist es die gefährliche Richtung**,
denn was hereinkommt, bestimmt die Gegenstelle, und dieser Prozess läuft als
root. Zwei Vorkehrungen, jede mit ihrem Bruch: kein Auflösen von Entitäten
(`LIBXML_NONET`, keine `NOENT`) und eine gedeckelte Verschachtelung.

Die erste ist **gemessen und nicht angenommen**: Mit `LIBXML_NOENT` steht der
Inhalt von `/etc/hostname` im Wert, mit den Marken dieser Klasse ist er leer.

**Zweitens: eine Sitzung über ein Cookie**, und die Entscheidung dazu hängt am
dritten Punkt.

**Drittens: der zweite Faktor — und INWX nimmt denselben TAN kein zweites Mal.**
lego wartet deshalb notfalls dreissig Sekunden auf den nächsten Zeitschritt.
Hier wird stattdessen **einmal je Bestellung** angemeldet: Anlegen und Abräumen
benutzen dieselbe Instanz des Anbieters, also gibt es genau eine Anmeldung und
genau einen TAN. Ein Schlaf im Agenten wäre eine halbe Minute, in der ein
Prozess mit Systemrechten nichts tut und sein Zeitlimit näherkommt.

**Viertens: was hier hinterlegt wird, öffnet ein Registrarkonto und nicht eine
Zone.** Bei allen anderen ist es ein Token, das sich einschränken lässt. Das
steht als Satz **über** den Feldern und nicht in einer Fussnote; ob ein Kunde
das überhaupt hinterlegen soll, bleibt die offene Frage aus `docs/34 §11`.

**Der Fund, den die Wegwerfprobe gemacht hat und kein Test:** Der Kommentar an
`recordsOf()` versprach, dass beim Suchen der Name mitzählt — der Code machte es
nicht und verliess sich auf den Filter, den INWX bekommt. Dieselbe Lehre wie bei
IONOS: Was ein Anbieter als Filter versteht, ist seine Sache; was gelöscht wird,
ist unsere.

Fünf neue Brüche, alle rot und grün gegengeprüft.

#### `Providers::PENDING` ist leer — und was das an zwei Stellen ändert

Mit dem achten Anbieter hat die Regel „was noch nicht gebaut ist, lässt sich
nicht hinterlegen" keinen Gegenstand mehr. Zwei Stellen mussten das beantworten
statt es zu überdecken:

**`usable()` fragt jetzt gegen `available()` und nicht gegen `PENDING`.** Beides
ist dieselbe Aussage, aber ein `in_array` gegen eine leere Konstante ist ein
Zweig, den nichts erreichen kann — PHPStan sagt das auch so, und ihn stehen zu
lassen hiesse, die Meldung wegzudrücken statt sie zu beantworten. Dieselbe
Umstellung in `ProvidersTest`; der Bruch dazu legt jetzt einen neunten Schlüssel
an, den es nur als Etikett gibt, statt einen aus `PENDING` zu nehmen.

**Und `DnsCredentialsTest` prüft die Regel, die immer einen Gegenstand hat.**
Der Test hatte drei Fassungen: erst `Providers::CLOUDFLARE` wörtlich — der fiel,
als Cloudflare gebaut wurde; dann `PENDING[0]` ohne Auffangzweig, damit er
auffällt, wenn der letzte Anbieter kommt. Genau das ist passiert. Geprüft wird
jetzt, dass ein Schlüssel, den es gar nicht gibt, abgewiesen wird; die Variante
für offene Anbieter steht in `ProvidersTest` und bekommt ihren Gegenstand mit
dem neunten Anbieter von selbst zurück.

#### Die Frist für `ready()` kommt vom Anbieter und nicht aus einer Konstante

Bis zum 7. August 2026 wartete jede Bestellung dieselben 120 Sekunden darauf,
dass der TXT-Eintrag von aussen sichtbar wird, und fragte alle zwei. **Das ist
kürzer, als lego für drei der acht Anbieter für nötig hält** — netcup und IONOS
bekommen dort 900 Sekunden, INWX 360. Eine Bestellung, die zu früh aufgibt,
verbrennt einen der fünf Fehlversuche je Konto und Stunde, **und die gelten für
jeden Kunden dieses Servers**, nicht nur für den, dessen Domain gerade dran war.

Umgekehrt wäre eine Viertelstunde für alle genauso falsch: Eine hängende
Bestellung hielte dann eine Operation des Agenten fünfzehn Minuten fest, statt
nach einer Minute mit einer brauchbaren Meldung zurückzukommen. Jeder Anbieter
nennt deshalb seine eigene Frist und seinen eigenen Abstand (`Patience`),
`DnsChallenge` reicht sie durch, und `Order::awaitReady()` nimmt sie. Die Zahlen
sind die von lego, weil sie aus dem Einsatz stammen und nicht aus einer
Schätzung; sie stehen in `docs/34 §11` und werden von `PatienceTest` einzeln
dagegen geprüft.

**Das Warten auf die Zertifizierungsstelle bleibt unberührt.** Wie schnell ein
DNS-Anbieter ausliefert und wie schnell Let's Encrypt eine Autorisierung
abschliesst, sind zwei verschiedene Fragen — `awaitAuthorization()` behält seine
120 Sekunden. `Challenge::patience()` und `DnsProvider::patience()` stehen ohne
Vorgabe in den Schnittstellen: Eine geerbte Zahl wäre die, die beim nächsten
Anbieter zu kurz ist.

**Und ein Nachtrag, den die CI gefunden hat:** `RecordingDnsProvider`, das
Testdoppel, bekam die neue Methode nicht. Das ist kein Fehlschlag, sondern ein
Abbruch — eine Klasse, die sich nicht laden lässt, beendet den Lauf mit
„Premature end of PHP process", und alles danach bleibt ungeprüft. Lokal
auffindbar wäre es gewesen: PHPStan läuft in diesem Container über `agent/src`
**und** `tests/Support` sauber durch, weil die Doppel dort am Agenten hängen und
nicht am Framework. Steht jetzt so in `CLAUDE.md`.

**Ein Bruch fehlt im Skript, und zwar mit Begründung dort.** Der naheliegende —
`Order::awaitReady()` die Frist des Anbieters wegnehmen — zeigt auf
`AcmeProtocolTest`, und der fährt HTTP-01, wo die Prüfdatei sofort liegt. Ein
Bruch, der nicht rot werden kann, ist kein Wächter; geprüft wird stattdessen,
dass `DnsChallenge` die Zahl durchreicht.

#### INWX wird nicht angeboten — gebaut, und trotzdem nicht in der Liste

Die Entscheidung des Betreibers vom 7. August 2026 zur vierten offenen Frage aus
`docs/34 §11`: **Was hier hinterlegt würde, sind Benutzername und Passwort des
Registrarkontos** und nicht ein Token für eine Zone. Bei den übrigen sieben
lässt sich der Zugang auf die Zonen einschränken, für die er gebraucht wird;
bei INWX öffnet er alles, was dem Kunden dort gehört — Domainübertragungen
eingeschlossen. Ein Panel, das das entgegennimmt, verwahrt danach ein Geheimnis
auf der Platte eines Servers, auf dem Kunden Websites betreiben.

**Die Liste hiess `PENDING` und meint jetzt `WITHHELD` — mit dem Grund als
Wert.** Das ist der eigentliche Punkt dieser Runde: Bis heute gab es genau einen
Grund zu fehlen, „noch nicht gebaut", und die Oberfläche konnte ihn als festen
Satz schreiben. Jetzt gibt es zwei, und **„Noch nicht verfügbar" wäre bei INWX
eine Zusage, die niemand einlöst.** Der Grund steht deshalb im Agenten neben dem
Schlüssel, geht als `reason` durch `DnsCredentialAccess::providers()` bis ins
Formular und steht dort neben dem Namen; das Kommando schreibt ihn ebenso. Zwei
Sätze in der Oberfläche wären eine zweite Fassung dessen, was in `WITHHELD`
steht — und die zweite ist die, die veraltet.

Aus demselben Grund heisst die Abweisung am Feld nicht mehr „Diesen Anbieter
gibt es noch nicht", sondern „hier nicht": Sie gilt für jeden abgewiesenen Wert
und kann den Einzelfall nicht erklären.

**Der Code bleibt stehen, an allen drei Stellen** — `Providers::make()`,
`DnsCredentialInput::config()` und der Zweig im Formular. Er ist gebaut,
gegengeprüft und von sechs Wächtern gedeckt; ihn zu löschen hiesse, ihn beim
nächsten Sinneswandel neu zu schreiben. Erreichbar ist er nicht mehr: `usable()`
weist ab, bevor gebaut wird, und die Auswahl im Formular kennt ihn nicht.
`PatienceTest` baut ihn deshalb an der Werkstatt vorbei, damit seine Zahl
weiter geprüft wird und nicht erst am Tag seiner Rückkehr auffällt.

**Ein neuer Bruch:** ein Zurückgehaltener ohne Grund. Er war beim ersten Anlauf
grün — die Wegwerfprobe fragte nur, *ob* abgewiesen wird, nicht *ob der Grund
dabeisteht*. Genau die Lücke, die der Wächter schliessen soll, hatte er selbst.

#### Der laute Rückfall wird laut — der Eintrag im Prüfprotokoll fehlte

Die erste offene Entscheidung aus `docs/34 §11`, beschlossen am 6. August 2026:
Läuft die gewählte Zuweisung ab und deckt ein anderes gültiges Zertifikat alle
Namen, liefert der Block dieses aus — „aber mit einem Eintrag im Prüfprotokoll
und einem Hinweis auf der Domainseite". **Der Hinweis stand seit Schritt 4, der
Eintrag nicht.** Und die beiden beantworten verschiedene Fragen: Die Seite sagt
dem, der gerade hinsieht, dass seine Wahl nicht gilt; seit wann sie nicht mehr
gilt, sagt nur ein Eintrag mit Zeitstempel.

**Er entsteht nach dem Vorgang und nicht beim Einreihen.** Der Zustand folgt
dem Agenten (CLAUDE.md, zweite Grenze) — erst wenn `web.site.apply` durch ist,
liefert dieser Block wirklich etwas anderes aus als das Eingestellte. Ein
Eintrag beim Absenden behauptete es früher, als es stimmt, und bliebe stehen,
wenn der Vorgang scheitert.

**Und das löst nebenbei ein Problem, das ein Haken am Einreihen gehabt hätte.**
`web.site.apply` wird an zwei Stellen eingereiht: von `WebLifecycle::apply()`
und von `CertificateLifecycle::install()` nach jeder Erneuerung. Die zweite ist
genau der Weg, auf dem eine abgelaufene Wahl auffällt — und die, die man beim
Verhaken vergisst. In `afterSuccess()` laufen beide zusammen, weil jeder
abgeschlossene Vorgang durch `Lifecycles::afterSuccess()` geht.

Drei Entscheidungen am Eintrag selbst, jede mit einem Grund:

- **Als Fehlschlag, nicht als Erfolg.** Jemand durfte wählen, und die Wahl
  liess sich nicht einlösen — genau der Fall, für den es diesen Ausgang gibt.
  „Erfolgreich" stünde neben einem Ereignis, das für den, der es eingestellt
  hat, das Gegenteil bedeutet, und niemand fände es beim Filtern nach dem, was
  Aufmerksamkeit braucht.
- **Mit dem Abonnement.** `AuditQuery::visibleTo()` zeigt einem Kunden sein
  eigenes Konto und seine Abonnements; geschrieben hat den Eintrag der
  Arbeiter. Ohne die Angabe stünde er nur dem Betreiber offen — und es ist die
  Wahl des Kunden, die übergangen wird.
- **Er wiederholt sich, und das ist Absicht.** Jeder geschriebene Block, der
  die Wahl übergeht, ist ein eigener Vorgang. Wer nur den ersten protokolliert,
  braucht einen Vermerk „schon gesagt" — also einen zweiten Zustand neben der
  Wahl, und der veraltet. So steht im Protokoll die Spanne und nicht ein Punkt.

**Zwei Brüche, und der zweite ist der wichtigere.** Der naheliegende Fehler ist
nicht der fehlende Eintrag, sondern der bei jedem angewandten Block: Die
Automatik hängt eine Domain regelmässig um — auf das mit der längsten Laufzeit
—, und das ist kein Übergehen, sondern ihre Aufgabe. Ein Protokoll, das sie
meldet, meldet nichts. Deshalb prüft `CertificateChoiceTest` beide Richtungen
und dazu ausdrücklich den Fall ohne Wahl.

**Rot-Grün steht aus.** Dieser Container hat kein `vendor/` — `composer
install` scheitert am Proxy —, und beide Brüche hängen an PHPUnit mit
Datenbank. Geprüft ist hier nur, dass die Eingriffe greifen (beide Muster
finden ihre Stelle) und dass das Ergebnis lädt; der Biss selbst gehört
nachgeholt, sobald `vendor/` da ist. Das steht hier, weil „nachher noch" in
diesem Projekt schon einmal eine ausgelieferte Fassung gekostet hat.

#### Der Platzhalter war über die Oberfläche nicht erreichbar

**Auf dem Zielserver aufgefallen, und nur dort.** Die Domainseite von
`cloudlab24.ipv64.de` zeigte ein gültiges Let's-Encrypt-Zertifikat — und weder
das Kästchen „Als Platzhalter bestellen" noch den Bestellknopf. Beide hingen an
`!props.certificate`.

**Das ist kein Randfall, sondern der Normalfall.** Die Automatik bestellt,
sobald der Server-Block steht, und der Arbeiter ist schneller als jeder Mensch.
Wer eine Domain anlegt, wartet und dann die Seite öffnet, findet dort immer
schon ein Zertifikat — das Kästchen war damit praktisch nie zu sehen. Und weil
der Bestellknopf an derselben Bedingung hing, gab es auch keinen Weg zurück:
**Der Umstieg von Einzelzertifikaten auf einen Platzhalter existierte über die
Oberfläche gar nicht**, obwohl die Route ihn annimmt und die Policy ihn erlaubt.

Gefragt wird jetzt nach der **Deckung** statt nach dem Vorhandensein:
`WildcardOrder::covered()` beantwortet, ob schon ein gültiges, dem Abonnement
gehörendes Zertifikat `*.example.de` **und** `example.de` deckt. Das ist
dieselbe Unterscheidung wie bei `CertificateChoice::satisfied()` — ein
Zertifikat für `example.de` ist keines für `*.example.de`, und wer beide gleich
behandelt, verwechselt „da ist etwas" mit „da ist das Richtige".

**Die Frage steht in `CertificateChoice`**, wo alle Deckungsfragen stehen, als
`covers()`. Sie teilt sich die Abfrage mit `candidates()` — wem ein Zertifikat
gehört und ob es in Gebrauch ist, wird an einer Stelle beantwortet.

**Der Bestellknopf hat jetzt zwei Anlässe statt einem.** Ohne Zertifikat ist er
der Nachschlag zu einer gescheiterten Bestellung — falscher DNS-Eintrag, Port 80
zu. Mit Zertifikat erscheint er erst, wenn das Kästchen gesetzt ist: Der einzige
sinnvolle Anlass ist dann der Umstieg. Ein Knopf, der ohne Kästchen dasselbe
noch einmal bestellt, verbrennt einen der fünf Fehlversuche je Konto und Stunde
für nichts — und die gelten für jeden Kunden dieses Servers.

Dazu ein Satz, der vorher fehlte und den man sonst durch zweimaliges Klicken
lernt: Das bestehende Zertifikat wird nicht ersetzt. Der Platzhalter tritt als
Kandidat daneben und übernimmt von selbst, weil er alles deckt und länger läuft.

**Zwei Brüche.** Deckung durch Vorhandensein ersetzt — das ist wörtlich der
ausgelieferte Fehler. Und die Laufzeit bei der Deckung nicht gefragt, denn ein
abgelaufener Platzhalter darf das Angebot nicht wegnehmen; genau dann braucht
die Domain eines. Rot-Grün steht aus, solange dieser Container kein `vendor/`
hat; geprüft ist, dass beide Eingriffe ihre Stelle finden.

**Und ein Nachtrag zur Prüfung selbst:** `WildcardOrder` hat einen dritten
Bestandteil bekommen, und `WildcardOrderTest` baut die Klasse von Hand. Ohne die
mitgezogene Zeile wäre das kein Fehlschlag gewesen, sondern ein Abbruch beim
Laden — dieselbe Falle wie beim Testdoppel des DNS-Anbieters zwei Tage zuvor.

#### Eine Kennung überschrieb den Nachbarbereich — auf dem Schreibtisch

**Im Screenshot des Betreibers gesehen, nicht im Test.** Auf der Domainseite von
`cloudlab24.ipv64.de` lief `/var/www/vhosts/cloudlab24.ipv64.de/logs/…` aus dem
Bereich „Stammdaten" heraus und legte sich über „Gilt für" im Bereich
„Zertifikat". Nachgemessen: **173px bei 1440px Fensterbreite, 134px bei 1024px,
6px bei 1280px.**

**Und der Seitenüberlauf war dabei die ganze Zeit 0.** Das ist der Grund, warum
nichts es gemeldet hat — und warum meine eigene Messung einen Tag zuvor „kein
Überlauf" ergab: Sie lief bei 390px, wo die Bereiche untereinander stehen. Die
Seite rollt nicht, sie überlappt. Ein Wächter, der `scrollWidth - clientWidth`
misst, sieht davon nichts; gemessen wird deshalb die Zelle gegen ihren Bereich.

**Die Begründung für `nowrap` war richtig gedacht und hier falsch.** Sie steht
seit dem Optik-Rework in app.css: Ein Pfad, der mitten im Verzeichnisnamen
umbricht, sei schwerer zu lesen als einer, „für den man die Tabelle schiebt —
und schieben kann man dort". In einer Bezeichnungstabelle kann man das nicht:
Sie steht in einem Bereich mit `min-width: 0` neben zwei weiteren, und es gibt
keinen Rollbehälter. Die Wahl ist nicht „umbrechen oder schieben", sondern
**umbrechen oder den Nachbarn überschreiben** — und das Zweite ist eindeutig
schlechter.

**Es ist derselbe Fund zum dritten Mal, und zum zweiten Mal am selben Ort
halb behoben.** Die Ausnahme gab es schon: `table.pairs td.right.ident` im
`@media`-Block für 390px — für den einen Ort, an dem der Überlauf auffiel,
statt für die Regel. Genau diese Lehre steht als Kommentar zwei Zeilen darüber.
Sie gilt jetzt unbedingt.

**Der Wächter sitzt in `TableStyleTest` und nicht in `MobileLayoutTest`.** Dort
steht bereits einer zu `.ident`, und der lässt `nowrap` an einer Zellenauswahl
ausdrücklich zu — aus genau der Annahme, die dieser Fund widerlegt. Er soll das
weiter tun: Eine Tabelle mit `.scrolls` rollt wirklich. Geprüft wird deshalb
gezielt die Bezeichnungstabelle, und ausserhalb der Haltepunkte.

**Zwei Brüche, und beide sind gegengeprüft** — hier ausnahmsweise vollständig,
weil dieser Wächter reine Textprüfung ist und ohne Datenbank auskommt: `nowrap`
zurückgeholt (rot) und die Ausnahme in den `@media`-Block zurückgeschoben (rot);
ohne Bruch grün. Nachgemessen im Browser bei 1440, 1280, 1024 und 390px: kein
Zellenüberhang mehr, in beiden Themes.

#### `srvpanel dns` kannte nur RFC 2136 — vier Anbieter waren aus dem Skript nicht zu setzen

**Beim Zusammenstellen des Abnahmelaufs aufgefallen**, und zwar an der Frage, mit
welchem Befehl der Betreiber sein IPv64-Token hinterlegt. Antwort: mit gar
keinem. Das Kommando baute die Angaben selbst zusammen — `server`, `zones`,
`key_name`, `secret` —, und in seiner Hilfe stand seit P4 unverändert „heute
geht nur rfc2136". Ein `--token` gab es nicht.

Schritt 9 hat sieben Anbieter gebaut, das Formular verzweigt seither an ihnen.
**Das Kommando ist die zweite Fassung derselben Regel gewesen, und die zweite
ist die, die veraltet** — genau das Muster, an dem dieses Projekt am häufigsten
verliert. Gemerkt hat es niemand, weil nichts danach fragte.

Die Angaben baut jetzt `DnsCredentialInput::config()`, dieselbe Stelle, an der
auch das Formular prüft; im Kommando steht nur noch, wie eine Angabe von der
Kommandozeile dorthin kommt. Dazu `--token`, `--customer-number`, `--api-key`
und `--api-password`.

**Zwei Dinge, die dabei mit falsch waren.** Die Zone stand in der Bedingung, ob
überhaupt etwas hinterlegt werden soll — wer für IPv64 kein `--zone` mitgab,
landete in der Anzeige statt in der Eingabe, obwohl fünf der sieben Anbieter
ihre Zonen aus ihrer eigenen Auskunft mitbringen. Und die Erfolgsmeldung
schrieb die Zonen immer; sie steht jetzt nur noch, wenn das Profil wirklich eine
Liste trägt. „Zonen: —" behauptete eine Einschränkung, die es nicht gibt.

**Gefragt wird weiter nach dem, was fehlt**, aber nur nach dem, was der Anbieter
braucht: das TSIG-Geheimnis bei RFC 2136, das API-Passwort bei netcup, das Token
bei den vier mit Token. Ein Prompt für ein Geheimnis, das der Agent gar nicht
annimmt, liesse den Betreiber etwas eintippen, das verworfen wird. IONOS trägt
sein Geheimnis im Schlüssel selbst und bekommt deshalb keinen zweiten.

**Der Wächter steht in `DnsProviderReachTest`**, wo schon „jeder benutzbare
Anbieter hat ein Formular" steht — die Kommandozeile ist dieselbe Frage. Für
jeden Anbieter läuft ein Satz Angaben durch `DnsCredentialInput::config()`, und
für jeden Schlüssel der geprüften Fassung muss das Kommando eine Angabe
anbieten. Ein achter Anbieter mit einem neuen Feld fällt damit dort auf und
nicht beim ersten Einrichtungsskript.

Der Bruch nimmt `--token` wieder weg. Und eine Meldung aus der Gegenprobe steht
als Kommentar an der Sammlung im Test: PHPStan liest die Form einer Konstanten
genauer, als eine `@var`-Angabe sie beschreiben kann, und weist jede zurück, die
weiter ist.

#### Der Platzhalter erreichte nur den Block, der ihn bestellt hat

**Im Abnahmelauf auf `cloudlab24.ipv64.de` gefunden — dem Lauf, für den das
alles gebaut wurde.** Der Platzhalter war ausgestellt und trug beide Namen; die
Hauptdomain lieferte ihn aus. Die drei Unterdomains behielten ihre einzelnen
Zertifikate, vier verschiedene Seriennummern. Der Betreiber musste auf jeder
Unterdomain den Platzhalter von Hand auswählen oder „Übernehmen" drücken.

**Das ist Abnahmekriterium 2** (`docs/34 §10`): „Alle Server-Blöcke dieses
Abonnements liefern es aus." Es war nicht erfüllt.

`CertificateChoice` antwortete für die Unterdomains die ganze Zeit richtig — der
Platzhalter deckt sie, läuft am längsten und gewinnt. **Nur fragte niemand.**
`CertificateLifecycle::install()` reihte genau einen `web.site.apply` ein: für
die Domain, die bestellt hat. Bis dahin trägt nginx, was beim letzten Anwenden
dastand, und deshalb wirkte „Übernehmen" — der Knopf schreibt den Block neu.

**Die Annahme dahinter war: ein Zertifikat betrifft die Domain, die es bestellt
hat.** Das gilt, solange jedes für einen Namen ausgestellt wird. Ein Platzhalter
ändert die Antwort für **jede** Domain der Zone. Es ist derselbe Bruch derselben
Annahme wie zweimal am selben Tag: erst beim Kästchen, das an „gibt es schon
eines" hing, dann beim Kommando, das nur RFC 2136 kannte.

**Verglichen wird der Ablageort und nicht die Kennung** — das ist die
Entscheidung, an der die Kosten hängen. Vor dem Ablegen wird gemerkt, was jeder
Block ausliefert; danach wird nur der neu geschrieben, für den jetzt ein anderer
Ablageort gilt. Eine Erneuerung legt eine neue Zeile an mit **derselben** Datei
`_wildcard.example.de`: Der Vergleich über die Kennung hielte jeden Nachbarblock
für veraltet und reihte bei einem Abonnement mit vierzig Domains alle sechzig
Tage vierzig Vorgänge ein, für eine Datei, die genauso heisst wie vorher.
Entschieden vom Betreiber am 7. August 2026.

**Eine Wahl wird dabei nicht angefasst.** Die Zuordnung bleibt stehen; der Block
wird trotzdem geschrieben, wenn sich der Ablageort geändert hat — genau dann ist
die Wahl abgelaufen und der laute Rückfall greift, und der greift nur, wenn ihn
jemand aufschreibt.

#### Und der Platzhalter ist in der Auswahl als solcher zu erkennen

Zweiter Fund desselben Laufs, vom Betreiber gemeldet. In der Liste „Ausgeliefert
wird" stand die Herkunft und das Datum — **bei zwei Zertifikaten von Let's
Encrypt also zweimal dasselbe Wort.** Ob ein Eintrag eine Domain deckt oder jede
Unterdomain der Zone, musste man am Datum erraten; und das ist genau die Frage,
wegen der man dort überhaupt wählt.

`Certificate::isWildcard()` beantwortet sie, der Eintrag heisst jetzt
„Let's Encrypt · Platzhalter — bis 5.11.2026". Die gedeckten Namen stehen
weiter nicht dabei: Ein `<select>` bricht nicht um, es schneidet ab (`docs/24
§8`). Bei 390px gemessen, beide Themes, kein Überlauf.

**Zwei Brüche:** die Nachbarblöcke gar nicht angefasst — wörtlich der Zustand
aus dem Abnahmelauf — und die Kennung statt des Ablageorts verglichen, was jede
Erneuerung zu einem Rundumschlag machte.

#### Zwei Befunde aus dem laufenden Abnahmelauf

**Das Auswahlfeld für das Abonnement war unbeschriftet.** Auf der Domainliste
steht neben „Domain anlegen" eine Auswahl, in *welches* Abonnement die neue
Domain kommt. Sie trug ein `aria-label` und sonst nichts — für einen sehenden
Betrachter also ein Feld mit einem Domainnamen darin, neben einem Knopf. Der
Betreiber am 7. August 2026: „geht unter und wird nicht wirklich wahrgenommen."

**Der Schaden ist nicht kosmetisch.** Wer die Auswahl übersieht, legt die Domain
im falschen Abonnement an — mit eigenem Verzeichnisbaum, eigenem Systembenutzer
und eigenem Server-Block. Zurück geht es nur über Entfernen und neu Anlegen.

Daraus ist eine Regel geworden, die weiter reicht als der eine Ort:
**`FormLabelTest` besteht darauf, dass jedes `<select>` in einem `<label>`
steht.** Alle 17 im Panel tun das jetzt; `aria-label` beschriftet für die
Vorlesehilfe und für nichts sonst. Ein `<select>` zeigt immer einen gültigen
Wert an — es sieht nie leer aus und lädt deshalb dazu ein, überlesen zu werden.
`input` steht bewusst nicht in der Regel: Ein Suchfeld trägt seinen Zweck im
`placeholder`, ein Kästchen seinen Text daneben.

**Und der Satz zu den ungedeckten Namen fehlte genau dann, wenn er zählt.**
„Eine Ebene tiefer deckt ein Platzhalter nicht" hing allein am Kästchen „Als
Platzhalter bestellen" — also an der **Absicht**, einen zu bestellen. Sobald er
ausgestellt war, verschwand das Kästchen (es gibt nichts mehr zu bestellen) und
mit ihm die Auskunft. Im Abnahmelauf war `tief.a.cloudlab24.ipv64.de` angelegt,
und die Seite schwieg dazu.

Gefragt wird jetzt zusätzlich, ob der Platzhalter schon liegt. Der Unterschied
ist der zwischen einer Vorhersage und einer Tatsache, und die Tatsache ist die,
die jemand braucht.

**Der Wächter prüft die Bedingung im Markup und nicht das Bild** — gerendert
wird in den Tests nichts. Das ist die schwächere Prüfung, hält aber genau den
Rückschritt auf, der hier passiert ist: die Bedingung wieder auf die Absicht
allein zu verkürzen.

Beide Brüche gegengeprüft; Screenshots bei 1280 und 390px in beiden Themes,
kein Überlauf.

#### Ein Platzhalter wurde als gewöhnliches Zertifikat erneuert

**Der teuerste Fund des Abnahmelaufs, und er sah aus wie ein Erfolg.** Der Lauf
meldete `1 fällig, 1 bestellt, 0 nachgetragen` — genau die Zahl, die
Abnahmekriterium 4 verlangt. Die Zahl stimmte auch. Das Bestellte nicht:

```
cloudlab24.ipv64.de     serial=057551B2…  notBefore=Aug 7 10:34:53
a.cloudlab24.ipv64.de   serial=059229C9…  notBefore=Aug 7 09:41:20
b.cloudlab24.ipv64.de   serial=059229C9…  notBefore=Aug 7 09:41:20
c.cloudlab24.ipv64.de   serial=059229C9…  notBefore=Aug 7 09:41:20
```

Das erneuerte Zertifikat trug nur `cloudlab24.ipv64.de`. Die drei Unterdomains
lieferten weiter das alte aus — richtig, denn das neue deckt sie nicht.

**Die Ursache ist eine fehlende Angabe.** `CertificateRenewal::sweep()` rief
`$this->order->place($domain)` auf, und `place()` hat `bool $wildcard = false`.
Die Erneuerung wusste nichts von Platzhaltern; sie bestellte, was
`$domain->serverNames()` sagt.

**Der Fehler wäre still und käme mit neunzig Tagen Verzögerung.** Im Panel sieht
ein erneuertes Zertifikat aus wie ein erneuertes; dass es eine Zone weniger
deckt als vorher, steht nirgends. Aufgefallen wäre es, wenn das alte abläuft und
der Browser bei jeder Unterdomain warnt — an einem Tag, an dem niemand etwas
geändert hat.

**Und es wäre in diesem Abnahmelauf beinahe durchgegangen**, weil das Kriterium
nach der *Anzahl* fragt und die stimmte. Gefunden hat es der Betreiber, weil er
danach die Seriennummern verglichen hat.

**Die Basisdomain kommt jetzt aus dem Namen und nicht aus der Zuordnung.**
`*.example.de` gehört zu `example.de`, auch wenn an dem Zertifikat gerade nur
Unterdomains hängen — nach einer Wahl etwa. Über die Zuordnung zu gehen (bisher
`domains()->orderBy('id')->first()`) ergäbe `*.a.example.de`, weil
`a.example.de` die kleinste Kennung hat: ein Platzhalter eine Ebene tiefer, für
etwas ganz anderes.

**Ohne DNS-Zugangsdaten wird gar nicht erneuert**, und das ist die zweite
Entscheidung. Der naheliegende Ausweg wäre, den Platzhalter dann als
gewöhnliches Zertifikat nachzuholen — genau der stille Rückschritt, um den es
hier geht. `RenewalReport::$blocked` zählt diese Fälle, und `srvpanel tls` meldet
sie als **Fehler** und nicht als Auskunft: Wer den Lauf aus einem Skript fährt,
sieht sonst nichts.

Drei Wächter, zwei Brüche — die fehlende Angabe und der stille Rückschritt.

#### Zwei Meldungen für eine Ursache, und die falsche stand unten

**Aus dem Abnahmelauf, Schritt „verkehrte Kette".** `srvpanel tls --upload` mit
einem frisch angelegten privaten Schlüssel:

```
--key: Diese Datei gibt es nicht oder sie ist nicht lesbar: /tmp/pk.pem
Es fehlt eine Angabe: --domain, --certificate und --key gehören zusammen.
```

Der erste Satz war richtig, der zweite falsch — die Angabe war da. **Und der
zweite ist der, den man glaubt:** Er steht zuletzt und klingt allgemeiner, also
sucht man den Fehler in der Kommandozeile, wo alles stimmt.

Ursache war eine Hilfsmethode, die `null` für zwei verschiedene Dinge zurückgab
— „nicht angegeben" und „angegeben, aber nicht lesbar" —, und ein Aufrufer, der
beides gleich behandelte. Geprüft werden jetzt erst die Angaben, dann die
Dateien, und jede Ursache meldet sich einmal.

**Der eigentliche Stolperstein steht jetzt in der Meldung.** `srvpanel` wechselt
per `setpriv` auf den Dienstbenutzer, bevor artisan startet — gelesen wird also
**nicht** als root. Ein privater Schlüssel, den ein Betreiber gerade mit
`openssl req -keyout` angelegt hat, gehört root und steht auf 0600; dieses
Kommando kommt nicht heran, und zwar immer. Ohne diesen Hinweis ist der
naheliegende nächste Griff `chmod 644` auf einen privaten Schlüssel. Die Meldung
nennt deshalb den Benutzer und sagt dazu, dass das Kommando nicht als root
läuft.

Der Name kommt aus `posix_geteuid()` und nicht aus `get_current_user()` — das
zweite nennt den Eigentümer der Skriptdatei, und auf einem Panel, das die
Rechte wechselt, sind das zwei verschiedene Antworten.

**Was der Wächter nicht prüft:** den unlesbaren Fall selbst. Die Tests laufen in
der CI als root, und root liest auch 0600. Geprüft werden die beiden Ausgänge,
die sich herstellen lassen — fehlende Angabe und fehlende Datei —, und dass
keiner die Meldung des anderen mitbringt.

#### Die verkehrte Kette meldete den Schlüssel

**Abnahmekriterium 5 zur Hälfte.** „Ein hochgeladenes Zertifikat mit falsch
sortierter Kette wird abgewiesen, und die Meldung sagt, was falsch ist." Es
wurde abgewiesen. Die Meldung sagte:

```
Zertifikat abgewiesen: Der Schlüssel gehört nicht zu diesem Zertifikat.
```

**Der Satz ist buchstäblich wahr und die falsche Auskunft.** Steht das
ausstellende Zertifikat vorn, ist es „dieses Zertifikat", und der Schlüssel des
Blattes passt nicht dazu. Ein Betreiber, der seine Kette verkehrt herum
eingefügt hat, geht danach seinen Schlüssel suchen — holt ihn neu, leitet ihn
neu aus, fügt ihn neu ein —, während die Ursache zwei Zeilen weiter oben in
derselben Datei steht.

**Die Prüfung, die es genau weiss, gab es die ganze Zeit.** `Bundle::ordered()`
sagt, welches Glied welches nicht unterschrieben hat, und nennt die richtige
Reihenfolge dazu. Sie lief nur **nach** `keyBelongs()` und kam deshalb nie zum
Zug. Beide Zeilen stehen jetzt umgekehrt.

**Und der bestehende Wächter hat das nicht gefunden**, obwohl es ihn gibt:
`test_a_chain_in_the_wrong_order_is_refused` reicht den Schlüssel der
**Zertifizierungsstelle** ein. Den hat niemand, der ein gekauftes Zertifikat
hochlädt — der Durchgang umging damit ausgerechnet die Prüfung, die im Weg
stand. Der neue nimmt den Schlüssel, den ein Mensch wirklich hat.

Das ist dieselbe Lehre wie beim `$`-Anker in P3: Ein Wächter, der den Weg prüft,
der gerade durchkommt, statt den, den jemand geht, ist grün und wertlos.

Der Bruch ist vollständig rot-grün gegengeprüft — `agent/` läuft ohne Framework,
und eine Wegwerfprobe über `agent/src/autoload.php` baut sich ihre CA selbst.
Mit der alten Reihenfolge meldet sie den Schlüssel, mit der neuen die Kette; die
richtige Kette mit fremdem Schlüssel meldet weiter den Schlüssel, und die
richtige Kette mit dem richtigen geht durch.

### P4 zweiter Wurf abgenommen

**Gemessen am 7. August 2026 auf `cloudlab24.ipv64.de` gegen IPv64.net, nicht
geschätzt.** Alle sieben Kriterien aus `docs/34 §10` sind erfüllt: vier sofort
auf `v0.4.0-rc.10`, drei auf `v0.4.0-rc.11`, nachdem der Lauf die Fehler zutage
gefördert hatte, für die es ihn gibt.

```
subjectAltName:  DNS:*.cloudlab24.ipv64.de, DNS:cloudlab24.ipv64.de
alle vier:       serial=06832C1F89711756C037F68969E0631EC641
                 HTTP/1.1 200 OK   (ohne curl -k)
Erneuerung:      Kundenzertifikate: 1 fällig, 1 bestellt, 0 nachgetragen.
verkehrte Kette: „…nicht in der richtigen Reihenfolge: Das 2. Zertifikat hat
                 das 1. nicht unterschrieben."                          → 1
richtige Kette:  „Abgelegt für b.cloudlab24.ipv64.de."                  → 0
```

**Der Lauf hat sechs Fehler gefunden, und keinen davon ein Test.** Drei
betrafen ein Kriterium, drei die Bedienung; sie stehen einzeln weiter oben in
diesem Dokument. Was sie zusammen zeigen, steht hier:

**Vier der sechs hätte kein Kriterium erwischt.** Sie sind aufgefallen, weil ein
Mensch die Oberfläche benutzt hat, statt eine Liste abzuhaken — das
unbeschriftete Auswahlfeld, der fehlende Hinweis auf die Namen eine Ebene
tiefer, die zwei Meldungen für eine Ursache, das Kommando, das nur RFC 2136
kannte. **Zwei davon standen dem Lauf sogar im Weg:** Ohne das
Platzhalter-Kästchen und ohne `srvpanel dns --token` wäre er auf dem Weg, den
ein Kunde geht, gar nicht zustande gekommen.

**Der teuerste kam aus dem Vergleich nach einer grünen Meldung.** Die Erneuerung
schrieb `1 fällig, 1 bestellt` — genau die Zahl, die Kriterium 4 verlangt — und
bestellte ein gewöhnliches Zertifikat statt eines Platzhalters. Wer nur die
Zeile gelesen hätte, hätte abgehakt, und der Fehler wäre in neunzig Tagen als
Browserwarnung wiedergekommen, an einem Tag, an dem niemand etwas geändert hat.

> **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.**

**Eine Einschränkung gehört zum Nachweis.** Kriterium 2 ist nach seinem Wortlaut
erfüllt — alle Blöcke liefern den Platzhalter aus, `covers_all` steht überall
auf ja, kein Browser warnt. Der behobene Fehler ist damit aber **nicht auf dem
Server gemessen**: Er trat beim Wechsel von Einzelzertifikaten auf einen
Platzhalter auf, wo sich der Ablageort ändert; eine Erneuerung schreibt dagegen
in dieselbe Datei, und die Blöcke zeigen längst dorthin.
`CertificateLifecycle::spread()` ist deshalb von `CertificateReapplyTest`
gedeckt und nicht von einer Messung. Das steht so auch in `docs/34 §10`, samt
dem Weg, es nachzuholen — eine zweite Zone.

Und zwei Fallen, die den Lauf zweimal **stumm** haben scheitern lassen, stehen
jetzt als Kasten in `docs/34 §10`: `tinker` braucht `HOME=/tmp` (sonst darf
psysh seine Einrichtung nicht schreiben und führt den Code gar nicht aus) und
`allowAll()` als erste Zeile (sonst klammert die Mandantenklammer jede Abfrage
auf nichts). Beide Male ohne eine einzige Fehlermeldung.

### Zwei Bereiche ohne Abstand — und der Wächter dafür

Auf **DNS-Zugang** berührte der letzte Hinweis des Bereichs „Hinterlegt" die
Überschrift „Neu hinterlegen": 0px dazwischen, im Browser gemessen. Gemeldet hat
es der Betreiber, kein Lauf.

Die Ursache ist keine fehlende Regel in app.css, sondern eine fehlende Klammer
im Template. In „Kontor" hat ein Bereich **keinen eigenen Aussenabstand**, und
das ist Absicht: Bereiche stehen in einem Flexfluss, der sie nebeneinander
stellt, solange sie nebeneinander passen. Ein Abstand am Bereich selbst wäre
waagerecht wie senkrecht derselbe — die Spaltenlücke ist aber eine andere als
die Zeilenlücke (`--bereich-gap: 30px 44px`). Deshalb kommt er aus dem `gap` des
Behälters: `.sections` um eine Gruppe von Bereichen, `.form` um ein Formular.

**Ein Bereich ohne diesen Behälter bekommt damit gar keinen Abstand** — nicht zu
wenig, sondern keinen. Jede einzelne Regel stimmt weiter, und nichts sagt etwas.

Die Falle lag eine Ebene höher. `DnsCredentials` bringt zwei Bereiche mit und
keinen Behälter; wer die Komponente einsetzt, stellt ihn. Am Abonnement stand er
von Anfang an, auf der Seite des Betreibers fehlte er: **dieselbe Komponente,
zwei Orte, ein Ort falsch.** Eine Komponente, deren Gestalt vom Ort abhängt,
sieht an ihrem ersten Ort richtig aus.

`SectionSpacingTest` prüft das jetzt in drei Richtungen: jeder `<Section>` steht
in einem Behälter, jede Komponente, die Bereiche mitbringt, wird an **jeder**
Einsatzstelle eingeklammert, und die beiden Behälterklassen setzen in app.css
wirklich ein `gap` aus `--bereich-gap`. Die Trägerkomponenten zählt der Test
nicht auf, sondern sucht sie selbst zusammen — als Fixpunkt, damit auch eine
Komponente auffällt, die ihrerseits eine Trägerkomponente ohne Klammer einsetzt.
Wer morgen eine zweite baut, wird ohne Zutun mitgeprüft.

Gemessen statt geschätzt: 0px vorher, 44px Spaltenlücke bei 1440px (die beiden
Bereiche stehen dort jetzt nebeneinander wie überall sonst im Panel) und 26px
Zeilenlücke bei 390px, kein waagerechter Überlauf. Alle drei Brüche stehen in
`tests/waechter-brechen.sh` und beissen.

### Ein Plan ohne Abonnements liess sich nicht löschen — 500 statt Meldung

Der Betreiber wollte einen Plan entfernen, an dem „keine Abos mehr" hingen. Der
Knopf antwortete mit **Error 500**.

Die Ursache ist eine Asymmetrie, die man beim Lesen nicht sieht:
`$plan->subscriptions()->count()` sieht **weniger** als der Fremdschlüssel.
`Subscription` trägt zwei Filter, die die Datenbank nicht kennt, und beide sind
für sich richtig — `SoftDeletes`, damit ein zurückgebautes Abonnement aus dem
Panel verschwindet, und die Mandantenklammer, die zeigt, was das anfragende
Konto sehen darf. `ON DELETE RESTRICT` kennt weder das eine noch das andere; es
zählt Zeilen.

Ein zurückgebautes Abonnement ist genau so eine Zeile. Sie bleibt liegen, weil
sie liegen bleiben **muss**: Ihr Systembenutzer darf nicht ein zweites Mal
vergeben werden, sonst bekäme ein neuer Kunde `p1000` samt allem, was auf dem
Dateisystem noch der alten UID gehört (`Lifecycle::nextSystemUser()`). Und diese
Zeile zeigt weiter auf ihren Plan.

Das Panel zählte also null, die Datenbank zählte eins, `DELETE` endete als
SQLSTATE 23000 — und daraus wurde eine weisse Seite statt einer Auskunft.

Gezählt wird jetzt mit denselben Augen wie der Fremdschlüssel: ohne
Mandantenklammer und mit den Grabsteinen. Daraus folgt eine zweite, eigene
Abweisung — „es hängt kein Abonnement daran" und „der Plan lässt sich löschen"
sind seitdem zwei verschiedene Aussagen, und die Meldung sagt, welche gilt. Das
Formular fragt dieselbe Frage: Der Löschknopf erscheint gar nicht erst, und an
seiner Stelle steht, was ihn festhält — ein Knopf, der wortlos verschwindet, ist
für den Betreiber dasselbe wie einer, der nicht funktioniert.

`RestrictedDeleteTest` prüft die Regel und nicht den Einzelfall: Zu jedem
`restrictOnDelete()`, dessen Kindmodell weich löscht oder eine Mandantenklammer
trägt, gehört ein `destroy()`, das beide Filter ausdrücklich abschaltet. Heute
gibt es genau einen solchen Fremdschlüssel; der nächste wird nicht daran denken.

**Zwei Funde aus dem Bauen selbst.** Der erste Ausdruck des Wächters las über
das Semikolon hinweg und meldete `customers` statt `plans` — er hätte den
`destroy()` eines unbeteiligten Controllers geprüft und wäre grün geblieben.
Gefunden hat ihn der Probelauf, nicht das Lesen. Und im Hinweis auf der Seite
stand „1 zurückgebaute Abonnements"; gefunden hat das der Screenshot bei 390px,
den es für diesen Fall sonst nicht gegeben hätte.

### Und der Plan geht doch — die Grabsteine werden übertragen

Die offene Frage aus dem Eintrag darüber ist entschieden: Ein Plan mit
Grabsteinen lässt sich löschen, und beim Löschen wird gefragt, wohin sie gehen.

**Warum gefragt und nicht angenommen.** Der Plan eines zurückgebauten
Abonnements wird nirgends im Panel angezeigt. Man könnte die Zeilen also still
auf den Standardplan schieben, und niemand sähe einen Unterschied — genau
deshalb nicht: Eine Änderung, die niemand sieht, ist eine, die niemand prüft.
Der Betreiber nennt das Ziel, es steht in der Rückfrage, in der Erfolgsmeldung
und im Protokoll (`transferred_to`).

**Warum übertragen und nicht auflösen.** Drei Auswege wurden geprüft und
verworfen. Den Plan weich zu löschen scheitert am `unique`-Index auf dem Namen:
Er wäre für immer verbraucht, und ihn zu lockern nähme eine echte Zusage
zurück. `plan_id` nullbar zu machen hiesse, dass ein Abonnement ohne Plan
möglich wird — und ein fehlender Plan bedeutet in diesem Panel „unbegrenzt",
also ausgerechnet beim Speicherplatz das Gegenteil dessen, was gemeint wäre.
Und die Zeile mitzulöschen gäbe ihren Systembenutzer wieder frei, womit ein
neuer Kunde `p1000` samt fremder UID bekäme.

Bleibt ein Fall, der auch mit Rückfrage nicht aufgeht: Es gibt keinen zweiten
Plan. Dann wird abgewiesen, und die Meldung sagt, was fehlt.

`onlyTrashed()` beim Übertragen und nicht `withTrashed()`: Lebende Abonnements
sind an dieser Stelle längst ausgeschlossen, und ein Aufruf, der sie trotzdem
mitnähme, würde bei einem Fehler weiter oben stillschweigend Kunden umhängen.
Die engere Abfrage ist hier die Sicherung.

**Und wieder hat das Bild etwas gefunden, das der Fliesstext nicht zeigte.** Im
Hinweis stand „Beim Löschen gehen sie an den Plan über" hinter einem Satz in der
Einzahl — ein Numerusfehler, sichtbar erst im Screenshot bei 390px. Dort ist
auch aufgefallen, dass die Beschriftung neben dem Löschknopf zweizeilig stand
und die Knopfreihe auseinanderschob; `.field.inline > span` bricht seitdem
nicht mehr.

### Beschlossen: die Grabsteine der Abonnements werden abgeschafft (docs/35)

Beim Nachsehen, warum ein Plan sich nicht löschen liess, ist ein Befund
herausgefallen, der grösser ist als der Fehler: **`withTrashed()` auf
`Subscription` kommt in `app/` genau einmal vor** — in
`Lifecycle::nextSystemUser()`. Sonst liest nichts im Panel ein zurückgebautes
Abonnement. Das `status = cancelled`, das der Rückbau vorher noch setzt, wird
von keiner Stelle je wieder gelesen.

121 Zeilen auf dem Zielserver existieren damit für eine einzige `MAX()`-Abfrage
— und halten dabei einen Fremdschlüssel auf `plans` fest, zwingen jede Zählung,
zwei Filter abzuziehen, die die Datenbank nicht kennt, und machen die
Reservierung eines Systembenutzers zu einem Nebeneffekt von `deleted_at`.

`docs/35-systembenutzer-verzeichnis.md` ist der Plan dagegen: eine eigene
Tabelle für verbrauchte Namen, harte Löschung der Abonnements, der Zähler
bleibt lückenlos und monoton. Er nennt jede Datei, die Reihenfolge der
Migrationen, die Wächter samt ihren Brüchen und elf Abnahmekriterien, die auf
dem Server messbar sind — das wichtigste davon: `nextSystemUser()` muss vor und
nach der Migration dieselbe Zahl liefern.

**Kunden behalten ihre weiche Löschung**, und das ist keine Inkonsequenz: Ihr
Grabstein wird von zwei Stellen gelesen, an ihm hängen die Konten, und ihre
Nummer steht in Rechnungen. Der Abonnementgrabstein trägt eine Zahl. Nur der
zweite lässt sich ersetzen, ohne etwas zu verlieren.

**Und der Plan hat einen Fehler in einem frischen Wächter gefunden.**
`RestrictedDeleteTest` verlangt heute beide Filterabschaltungen, sobald ein
Kindmodell *irgendwie* gefiltert ist. Verliert `Subscription` seine weiche
Löschung, verlangt er weiter ein `withTrashed()`, das es nicht mehr geben kann
— ein Wächter, der beim Aufräumen zubeisst, genau die Falle, in die dieses
Vorgehen schon dreimal gelaufen ist. Die Aufteilung steht als Schritt 8 im Plan.

### Das Verzeichnis der Systembenutzer — die Grabsteine sind weg (docs/35)

Ein zurückgebautes Abonnement blieb als Zeile mit `deleted_at` liegen, damit
sein Systembenutzer `p1000` nicht ein zweites Mal vergeben wird: `userdel` gibt
die UID frei, das nächste `useradd` vergibt sie wieder, und dann erbt ein neuer
Kunde alles, was auf dem Dateisystem noch der alten UID gehört.

**Der Grund war richtig. Das Mittel war zu grob**, und der Befund, der das
zeigte, stand in einer einzigen Zeile: `withTrashed()` auf `Subscription` kam in
`app/` genau einmal vor. Sonst las nichts im ganzen Panel einen dieser
Grabsteine. 121 Zeilen auf dem Zielserver existierten für ein einzelnes `MAX()`
— und hielten dabei einen Fremdschlüssel auf `plans` fest, was der 500er vom
7. August 2026 war.

Die Reservierung steht jetzt in `system_users`: eine Nummer, eine Abschrift des
Abonnementnamens, ein Zeitpunkt. `Lifecycle::claim()` verbraucht einen Namen und
schreibt die Zeile, `nextSystemUser()` zeigt nur, was der nächste wäre. Der
eindeutige Index auf `number` ist die Sicherung gegen zwei gleichzeitige
Anlagen; bis dahin scheiterte die zweite an einem Index mit einer Meldung, die
niemand deuten konnte. Abonnements werden hart gelöscht, und `subscriptions`
enthält damit nur noch, was es gibt.

**Kunden behalten ihre weiche Löschung**, und das ist keine Inkonsequenz: Ihr
Grabstein wird von zwei Stellen gelesen, an ihm hängen ihre Konten, und ihre
Nummer steht in Rechnungen. Der Abonnementgrabstein trug eine Zahl.

**Eine Verhaltensänderung, die man kennen muss.** `operations.subscription_id`
steht nicht mehr auf `cascadeOnDelete`, sondern fällt beim Rückbau auf `NULL`;
der Name des Abonnements steht daneben in `subscription_name`. Die Vorgänge
überleben damit das Abonnement — sie fallen aber aus der Mandantenklammer
(`subscription_id in (…)`, und `NULL` ist in keiner Liste) und sind **nur noch
für den Admin sichtbar**. Das ist richtig, der Kunde hat das Abonnement nicht
mehr, aber es ist eine Änderung und keine Nebensache.

**`PlanController` ist um die Hälfte kleiner.** Die Übertragung der Grabsteine
samt Rückfrage nach einem Zielplan, die am 7. August entstand, hatte keinen
Anlass mehr: Am Plan hängt nichts Unsichtbares. Was bleibt, ist
`withoutRestriction()` um die Zählung — die Mandantenklammer liegt weiter auf
`Subscription`, und ein Kommando ohne gesetzten Mandanten zählte sonst null.

**Und `SubscriptionStatus::Cancelled` ist gefallen**, in einem eigenen Commit
danach. Der Zustand wurde gesetzt und nie wieder gelesen; er stand auf einer
Zeile, die im selben Atemzug unsichtbar wurde.

### Drei Fehler im Plan, und alle drei hätten erst auf dem Server gefehlt

`docs/35` war vollständig genug, um ihn abzuarbeiten — und drei Stellen trugen
trotzdem nicht. Sie folgen demselben Muster wie die sechs Funde aus dem
TLS-Abnahmelauf: **eine Regel, die an einem Ort steht und an einem zweiten
gebraucht wird, ohne dass etwas den Bezug prüft.**

**SQLite kann einen Fremdschlüssel überhaupt nicht ändern.** Der Plan nennt für
diesen Schritt zwei Stolpersteine — `UPDATE … JOIN` und den Indexnamen — und
diesen dritten nicht. Es gibt dort kein `ALTER TABLE … DROP FOREIGN KEY`; die
Umstellung auf `nullOnDelete` läuft deshalb nur auf MariaDB. Damit im Test nicht
etwas anderes geschieht als auf dem Server, löst der Rückbau seine Vorgänge
jetzt **selbst** ab, und die Umstellung ist die Sicherung darunter — für ein
`DELETE` von Hand auf der Konsole, das am Panel vorbeigeht.

**Der Plan trägt die Namen nur rückwirkend nach.** Für jeden Vorgang, der
*danach* entsteht, stand nirgends, wer `subscription_name` schreibt — er wäre
nach dem nächsten Rückbau namenlos gewesen, und das fiele erst beim
zurückgebauten Abonnement auf, wenn nichts mehr zu heilen ist. Geschrieben wird
er jetzt vom Modell beim Anlegen, nicht von den sechs Stellen, an denen
Vorgänge entstehen. Dasselbe Muster wie bei `subscriptions.main_domain`: „nicht
von einem Dienst gepflegt, der daran denken muss, sondern vom Modell selbst".

**`srvpanel acceptance` vergibt Namen in einer Schleife.** Schritt 6 des Plans
nennt zwei Aufrufer im `SubscriptionController` und keinen der beiden
Abnahmekommandos. Beide riefen `nextSystemUser()`, das seit diesem Umbau nichts
mehr verbraucht: Alle Abonnements eines Laufs hätten `p1000` bekommen, und das
zweite wäre am eindeutigen Index gescheitert — im Abnahmelauf, auf dem
Zielserver. Der Wächter dazu prüft die Regel und nicht den Fall: Jeder
Systembenutzer, der in eine Zeile geschrieben wird, muss aus einem `claim()`
kommen.

Dazu drei kleinere: `Rule::unique('subscriptions', 'name')->withoutTrashed()`
hängt eine Bedingung auf `deleted_at` an und wäre ab der Migration ein
SQL-Fehler auf jedem Anlegen; und zwei Tests behaupteten, nach dem Rückbau
stehe die Zeile noch da.

**Der letzte davon ist der lehrreiche, und er kostete den einen roten Lauf.**
`DomainTest::test_withdrawing_a_subscription_clears_the_copy` benutzte weder
`withTrashed` noch `onlyTrashed` noch `deleted_at` — nur ein
`DB::table('subscriptions')->first()` und ein `assertNotNull`. Die Annahme „die
Zeile bleibt liegen" stand allein im Meldungstext der Behauptung, und kein
`grep` über die Vokabeln der weichen Löschung holt so etwas heraus. Daraus die
Lehre, die neben der aus dem TLS-Lauf steht:

> **Eine Annahme, die nur in einem Meldungstext steht, ist mit keinem
> Suchmuster zu finden. Die einzige Suche, die sie hergibt, ist der Lauf.**

**Und ein Bruch aus dem Plan beisst nicht.** `docs/35 §5.3` verlangt einen
Eingriff, der in `withdraw()` `delete()` statt `forceDelete()` schreibt. Ohne
`SoftDeletes` sind die beiden dasselbe — der Eingriff ist wirkungslos, und ein
Wächter, der ihn überlebt, sähe gesund aus. An seiner Stelle stehen zwei
Brüche, die es gibt: die Vorgänge, die am Abonnement hängen bleiben, und ein
`Subscription`, das seinen Trait zurückbekommt.

### Ein Zertifikat liess sich nicht löschen — vier Monate lang, unbemerkt

Der Purge aus docs/35 ist auf dem Zielserver **abgebrochen**, und der Wächter
hatte recht: An zurückgebauten Abonnements hingen noch zwölf Zertifikate.
`certificates.subscription_id` stand auf `cascadeOnDelete` — die Zeilen wären
mitgegangen, die Dateien unter `/etc/srvpanel/tls/certs` nicht. Die gehören dem
Agenten. Zurückgeblieben wären zwölf Verzeichnisse mit **privaten Schlüsseln**,
auf die nichts mehr zeigt.

**Der eigentliche Fehler ist älter und grösser als der Abbruch.** Dieses System
konnte ein Zertifikat anlegen und erneuern — aber **nirgends löschen**. Nicht im
Panel, nicht im Agenten: `Acme\Store` kannte `write` und `existing` und sonst
nichts. `subscription.remove` räumt drei Dinge ausserhalb des Abo-Verzeichnisses
weg, den Ablageort der Zertifikate nicht. Jedes zurückgebaute Abonnement liess
seinen privaten Schlüssel liegen, seit es Kundenzertifikate gibt. Aufgefallen
ist es erst, als eine Migration danach fragte — vorher hielt der Grabstein die
Zeile am Leben, und niemand hatte einen Anlass, sie anzusehen.

Behoben mit `acme.certificate.remove` im Agenten und `srvpanel tls --prune`.
Der Rückbau löst seine Zertifikate seitdem ab, statt sie kaskadieren zu lassen —
dieselbe Form wie bei den Vorgängen, und aus demselben Grund: Die Zeile ist der
einzige Wegweiser auf die Datei.

**Und die Diagnose hat eine Falle gefunden, bevor sie zuschlug.** Zwei
Zertifikate können denselben Ablageort nennen. Auf dem Server war
`cloudlab24.de` genau so ein Fall: einmal an einem zurückgebauten Abonnement,
einmal an einem **lebenden**. Ein `rm -rf` je Zeile hätte eine laufende Website
abgeschossen. Aufgeräumt wird deshalb je Ablageort, und nur wenn ihn keine Zeile
mehr nennt, die kein Waise ist. Auch unter den zwölf wiederholten sich
Ablageorte — Erneuerungen legen eine zweite Zeile auf dasselbe Verzeichnis.

**Das Zertifikat der Oberfläche ist die gefährlichste Verwechslung dabei.** Es
trägt `subscription_id = null`, und ein verwaistes seit dem harten Löschen
ebenfalls. Unterschieden werden sie allein an der Abschrift
`subscription_name`; ohne sie hielte das Aufräumen den Schlüssel, mit dem das
Panel antwortet, für einen Rest. `Certificate::forPanel()` fragte bis hierher
nur nach der Null — die Methode war ungenutzt und wäre beim ersten Aufruf falsch
gewesen.

### Der zweite Befund: eine halb migrierte Datenbank

Auf dem Server liefen Migration 1 und 2 durch, die dritte nicht — und der Code
rollte auf die vorige Fassung zurück, die das Verzeichnis der Systembenutzer
nicht kennt. Ein Abonnement, das in diesem Zustand entsteht, fehlt darin. Beim
zweiten Anlauf wird Migration 1 übersprungen, denn sie gilt als erledigt, und
der Name wäre für immer draussen: `nextSystemUser()` vergäbe ihn ein zweites
Mal. **Der Umbau hätte sich durch seinen eigenen Fehlschlag genau den Fehler
eingeschleppt, gegen den er gebaut ist.**

Migration 3 gleicht deshalb erst ab — lebende Abonnements und Grabsteine — und
prüft danach. Was sie nachträgt, schreibt sie hin; stillschweigend zu heilen
wäre dieselbe Sorte Nebenwirkung, die diesen Umbau nötig gemacht hat.

### docs/35 ist abgenommen — `p1121` vor und nach der Migration

**7. August 2026, `v0.4.1-rc.2` auf `cloudsrv24`.** Alle elf Kriterien erfüllt.
Die zentrale Invariante hält: `nextSystemUser()` sagt vor dem Umbau `p1121` und
danach `p1121` — durch eine Migration hindurch, die 121 Zeilen gelöscht hat.
`MAX(number) = 1120`, `subscriptions` ohne `deleted_at`, 403 verwaiste Vorgänge
mit ihrem Namen.

**Der Prune ist der Beleg dafür, dass je Ablageort entschieden wird und nicht je
Zeile:** zwölf verwaiste Zeilen, zehn Ablageorte, neun entfernt.
`cloudlab24.ipv64.de` und `_wildcard.cloudlab24.ipv64.de` kamen doppelt vor,
weil eine Erneuerung eine neue Zeile auf dasselbe Verzeichnis legt — je Zeile
gelöscht wäre der zweite Durchgang in ein Verzeichnis gelaufen, das der erste
schon weg hat. Und `cloudlab24.de` ist geblieben: der Ablageort, den ein
zurückgebautes und ein **lebendes** Abonnement teilten.

Danach liegen unter `/etc/srvpanel/tls/certs` genau zwei Verzeichnisse — eines
für das lebende Abonnement, eines für die Oberfläche. **Die privaten Schlüssel
von zehn zurückgebauten Abonnements sind von der Platte**, zum ersten Mal, seit
es Kundenzertifikate gibt.

Die Zeile `Verzeichnis nachgezogen: …` blieb aus: Zwischen dem Abbruch von rc.1
und dem zweiten Anlauf ist kein Abonnement entstanden, das im Verzeichnis
gefehlt hätte. Der Abgleich bleibt trotzdem stehen — er kostet nichts und deckt
einen Zustand ab, der sich jederzeit wiederholen kann.

**Was offen bleibt und nicht abgehakt wird:** `tests/waechter-brechen.sh` ist
zur Hälfte geprüft. Alle zwölf Eingriffe dieses Umbaus greifen nachweislich in
ihre Zieldatei; ob die Wächter danach rot werden, braucht ein lokales PHPUnit.
Wer als Nächstes mit `vendor/` an diesem Repo sitzt, holt das nach.

### P5 — Datenbanken

Der Plan steht als [`docs/36`](docs/36-datenbanken.md), die Entscheidungen des
Betreibers als §19 darin. Was hier steht, ist das, was beim Bauen gelernt wurde.

**Das Präfix ist der Systembenutzer und nicht der Abonnementname.** `p1001_shop`
statt `kunde.example.de_shop`, und der Grund ist erst seit `docs/35` verfügbar:
Eine Nummer aus `system_users` wird nie zweimal vergeben. Damit kann der
Schemaname eines neuen Abonnements niemals auf ein Verzeichnis unter
`/var/lib/mysql` treffen, das ein zurückgebautes hinterlassen hat. Mit dem
Abonnementnamen wäre genau das möglich, seit ein zurückgebautes Abonnement hart
gelöscht wird und seinen Namen freigibt. Dazu kommt das Praktische: `p` plus
Ziffern ist bereits ein Bezeichner ohne Anführung, ein Domainname müsste an
jeder Stelle in Backticks stehen — und „an jeder Stelle" ist die Formulierung,
aus der Lücken entstehen.

**In `GRANT … ON <db>.*` ist `<db>` ein Muster und kein Name.** Das ist der
teuerste Fund des Entwurfs, und er wäre im Betrieb nie aufgefallen. `_` steht
dort für ein beliebiges Zeichen: Der naheliegende Weg, einem Abonnement seine
Datenbanken freizugeben — `GRANT … ON \`p1001_%\`.*` —, trifft auch
`p10012_shop`. Fünf Zeichen `p1001`, dann `_` für die `2`, dann `%` für den
Rest. **Das ist ein Zugriff über die Mandantengrenze hinweg, und zwar genau
der, den das Abnahmekriterium von P5 ausschliesst.**

Deshalb wird nie auf ein Muster berechtigt, immer auf genau eine Datenbank, und
der Unterstrich wird maskiert (`` `p1001\_shop` ``). Ohne die Maskierung träfe
auch ein Name noch `p1001Xshop` — ein solcher Name kann heute nicht entstehen,
aber eine Regel, die *zufällig* gilt, gilt bis zur nächsten Änderung an einer
ganz anderen Stelle. `GrantPatternTest` rechnet **erst vor, dass die Falle echt
ist**, und prüft danach, dass sie zugeht: Eine Regel, deren Grund niemand mehr
nachvollziehen kann, wird beim nächsten Aufräumen entfernt.

**`RemovalPathTest` — der Wächter, den `docs/35` verdient hätte.** Dort fiel
auf, dass sich Zertifikate in diesem System nie löschen liessen: `create` wurde
zuerst gebaut, funktionierte danach, und `remove` wurde zur Nacharbeit, an die
ein Jahr lang niemand dachte. Zwölf private Schlüssel lagen deshalb auf dem
Zielserver. Der neue Wächter ist **nicht datenbankspezifisch**: Zu jeder
Operation der Registratur, die etwas anlegt, muss es eine geben, die es
entfernt — sonst steht der Grund in `WITHOUT_REMOVAL`, mit Begründung je
Eintrag. Er hätte die Lücke ein Jahr früher gemeldet. Die `remove`-Hälften von
P5 sind entsprechend **vor** ihren `create`-Hälften geschrieben.

**Das Datenbankpasswort liegt nirgends** (Entscheidung 3 des Betreibers). Das
Panel erzeugt es, schickt es in einem unmittelbaren Aufruf an den Agenten, zeigt
es genau einmal an und vergisst es. Der Massstab ist das siebte Kriterium aus
dem Abnahmelauf von P4: *„und das DNS-Token steht nirgends."* Zur Wahl standen
eine `encrypted`-Spalte im Panel — dann enthielte jede Sicherung der
Panel-Datenbank die Datenbankpasswörter aller Kunden, und der `APP_KEY` liegt
auf demselben Server — und eine Datei im Agenten wie bei den DNS-Zugangsdaten.
Der Preis ist ehrlich: Wer sein Passwort verliert, setzt es zurück.

Daraus folgt `SecretsStayOutOfTheQueueTest`. Die Regel, dass ein Geheimnis nicht
in `operations.payload` gehört, gilt seit P4 für den privaten Schlüssel und das
DNS-Token — durchgesetzt hat sie nichts. P5 macht sie zum dritten und vierten
Mal nötig; beim dritten Mal wird aus einer Gewohnheit ein Wächter. Er prüft
beide Hälften: den Weg (keine dieser Operationen wird eingereiht) und die Ablage
(keine Spalte, in die ein Geheimnis passte).

**Die Sperre eines Abonnements erreicht jetzt seine Datenbank.** Bis P4 nahm
`subscription.suspend` dem Abo-Verzeichnis das Ausführungsbit und schrieb die
Server-Blöcke auf 503 um — die Datenbank bediente jede Anwendung weiter, die die
Zugangsdaten hat. Auf demselben Server über den Socket, und bei
freigeschaltetem Fernzugriff von überall. Das ist keine Sperre, sondern eine
abgeschaltete Webseite. `DbLifecycle` beantwortet deshalb
`subscription.suspend` und `subscription.resume` mit `ALTER USER … ACCOUNT
LOCK`; das Schema bleibt unberührt, die Daten bleiben, `UNLOCK` ist die
vollständige Umkehrung. Ein `REVOKE` wäre die Alternative gewesen und die
schlechtere: Es müsste sich merken, was es weggenommen hat.

**Ein Kunde hätte seinen Zugang `r3f9a20c1` nennen dürfen.** Das ist die Form,
die das Zurückspielen einer Sicherung für ein paar Minuten anlegt;
`db.server.info` meldet sie nach einer Stunde als Rest eines abgebrochenen
Laufs. Der Kunde hätte seinen Zugang beim nächsten Aufräumen verloren, ohne dass
irgendetwas falsch programmiert wäre. Die Form ist jetzt reserviert, und
`DbNameTest` prüft beide Richtungen — sie lässt sich nicht wählen, und sie wird
als befristet erkannt. Gefunden beim Schreiben des Tests, nicht im Betrieb.

**Und zweimal derselbe Fehler: ein Name, der der Basisklasse gehört.**
`DatabaseFactory::for()` und `GrantPatternTest::matches()` — beide brechen beim
**Laden** der Klasse und nicht beim Ausführen, `php -l` sieht davon nichts. Der
erste fiel beim Schreiben auf, der zweite erst in der CI, und dort mit
Rückgabewert 255: Nicht ein Test stand still, sondern alle vierundsiebzig
Dateien. CLAUDE.md nennt diese Falle seit P4 — sie zu kennen hat beim Formular
geholfen und beim Testfall nicht.

**Und ein Fund, den P5 nicht verursacht, aber ausgelöst hat: jede gestapelte
Tabelle des Panels stand auf dem Telefon seitlich aus dem Bildschirm.** Alle
zehn, seit es `.scrolls` gibt. `.scrolls > table { width: max-content }` wiegt
0,1,1 und schlägt `.stacks { width: 100% }` mit 0,1,0 — eine Tabelle, die unter
720px zu Kärtchen zerfällt, war so breit wie ihr breitestes Kärtchen, und der
Rollbehälter machte daraus keinen Fehler, sondern eine Rollbewegung. Gemessen
bei 390px: **553px Tabelle in 358px Behälter, 195px waagerecht.**

Unsichtbar war das, weil es an der Länge einer Kennung hängt: Kürzere Kärtchen
passten zufällig. Der Ablagename einer Sicherung ist mit 52 Zeichen der erste,
der nicht mehr passt. Gefunden im Screenshot zu Schritt 6, nicht in einem Test.

Drei Dinge daran sind wichtiger als die CSS-Zeile:

- **Der vorhandene Wächter fragte nach dem Falschen.** Er prüft, dass eine
  Tabelle *eines von drei* Mustern trägt — nach `docs/24 §5` klang das nach
  Alternativen, und die naheliegende Verschärfung auf „genau eines" wäre falsch
  gewesen: `.stacks` wirkt erst unter 720px, darüber will dieselbe Tabelle
  rollen dürfen. Es sind zwei Antworten auf zwei Breiten. Was sich ausschliesst,
  ist `max-content` und ein Kärtchen — eine Frage an die **Kaskade**, nicht an
  das Markup. Die drei neuen Wächter rechnen sie deshalb nach und nennen den
  Selektor, der gewinnt.
- **Die Breite allein war ein Fix, der wie einer aussah:** 195px wurden 180px.
  Die Kennung trägt `nowrap`, und ein Kärtchen hat keinen Rand, an dem etwas
  hängenbliebe. Denselben Zweischritt hält `docs/24 §5` für die Paartabelle
  schon fest — es ist die dritte Fassung derselben Ausnahme.
- **Der neue Wächter war beim ersten Anlauf blind, und nur sein Bruch hat ihn
  überführt.** Sein Selektorvergleich kannte „passt" und „unbekannt, also
  Abbruch"; damit zählte `table.pairs td.ident` als Treffer — eine Regel für
  eine ganz andere Tabelle, Gewicht 0,2,2, die gewann und `white-space: normal`
  sagte. Der Bruch, der die Regel aus `app.css` entfernt, blieb grün. Die
  Trefferprüfung hat seitdem drei Ausgänge: passt, meint etwas anderes,
  unbekannt.

Ein dritter, leiserer Fund derselben Aufnahme: `.stacks td.multiline` dehnt
seine Kinder, und eine Zustandsmarke darin wurde 328px breit statt 116px — eine
farbige Fläche über die ganze Zeile. Nichts lief über, nichts wurde
abgeschnitten; es sah nur falsch aus, und deshalb hat es niemand gemeldet.
Sichtbar auf der Planseite, seit es `.multiline` gibt. `docs/24 §5` ist für alle
drei berichtigt.

**Und ein toter Winkel, den der Wächter selbst angekündigt hatte.** „Einspielen"
steht in docs/19 §3 auf der Liste der verbrauchten Wörter; für eine Sicherung
heisst es **zurückspielen**, und genau so hiess es überall ausser auf dem Knopf
und in der Rückfrage daneben. Den Knopf hat `WordChoiceTest` gemeldet, die
Rückfrage nicht: Er liest PHP-Literale und den `<template>`-Block, und seine
eigene Begründung sagte, in `<script>` stehe kein Anzeigetext — „sollte sich das
ändern, ist diese Zeile die Stelle, an der es nachzuziehen ist". Mit dem ersten
`confirm()` hat es sich geändert. Der Satz wäre ausgeliefert worden, neben einem
Knopf, den dieselbe CI-Runde beanstandet hat. Ein Wächter mit einer Annahme über
den *Ort* hat einen toten Winkel, und der wächst mit dem Projekt.

**Eine offene Schuld ist eingelöst: `nanoid` 3.3.16 → 3.3.18.** Die Meldung
GHSA-2v37-7h3g-55p8 (hoch) hing seit Wochen im Lauf „Schwachstellen und
Lizenzen" und war der einzige rote Job — ein eigener Beitrag, weil sie
`package-lock.json` anfasst und mit P5 nichts zu tun hat. Sie kostet genau
diese eine Zeile: `nanoid` kommt über `postcss` aus `vite`, `package.json`
bleibt unberührt, und nichts anderes im Baum wandert mit. Ein Lauf, der aus
einem bekannten Grund rot ist, hört irgendwann auf, gelesen zu werden — und
dann fällt der nächste, unbekannte Grund nicht mehr auf.

**Die Messung (§9): `db.usage`, ein Aufruf für alle Datenbanken.** Wörtlich
dieselbe Entscheidung wie bei `subscription.usage` — bei hundert Abonnements
wären hundert Prozessgründungen je Viertelstunde auf einem Server, der nebenbei
Webseiten ausliefert, und `information_schema` weiss ohnehin alles auf einmal.
Die Operation nimmt keine Argumente. Gemessen wird `data_length + index_length`,
also der **belegte** Platz einschliesslich des Freiraums nach gelöschten Zeilen;
deshalb sagt die Oberfläche „belegt" und nicht „Daten". Ein Zeitgeber und nicht
zwei: `srvpanel usage` misst seit P5 beides.

**Sie gibt nur die Schemata dieses Panels heraus.** `information_schema` kennt
`mysql` mit der Benutzertabelle, `sys`, `performance_schema` und die Datenbank
des Panels selbst — eine Operation, die die Schemaliste des Servers ausliefert,
wäre eine Auskunft, die niemand bestellt hat. Das Muster dafür stand schon in
`Names::existing()` und heisst jetzt `Names::isPanelName()`: eine Regel und
nicht zwei. Ein eigener Ausdruck in `DbUsage` wäre die zweite Fassung gewesen,
und die zweite ist die, die veraltet.

**`UsageReachTest` steht gegen einen Ausfall, der stumm ist.** Eine künftige
Messung — Postfach, Cronlauf — wird registriert, bekommt ihren Dienst, und
niemand ruft sie auf. Der Zeitgeber bleibt grün, die Oberfläche zeigt dauerhaft
„noch nicht gemessen", und das sieht aus wie ein Server, auf dem nichts liegt.
Geprüft wird über zwei Sprünge — welcher Dienst ruft die Operation, und nennt
das Kommando diesen Dienst —, damit eine Umbenennung die Prüfung nicht aushebelt.

**Ein dritter Zustand, den der Plan nicht kannte.** docs/36 §9 nennt „gemessen"
und „noch nicht gemessen". Ein Abonnement ohne Datenbanken hat nichts zu messen:
Mit zwei Zuständen stünde bei jedem frischen Abonnement „braucht einen
erreichbaren Datenbankserver" — ein Satz, der nach einem Defekt klingt, wo
nichts anzulegen war. Dieselbe Unterscheidung wie zwischen `null` und `0` bei
einer Grösse, nur eine Ebene höher; sie fehlte, weil der Plan die Anzeige von
der Zahl her gedacht hat und nicht vom Bestand.

**`database_mb` wird gemessen und nicht erzwungen**, und das steht in der
Oberfläche und nicht nur im Kommentar. MariaDB kennt keine Obergrenze je Schema,
und `/var/lib/mysql` liegt ausserhalb der Dateisystem-Quota des Systembenutzers:
Ein Kunde kann seinen Speicherplatz einhalten und den Datenträger über seine
Datenbank füllen. Das ist eine Lücke, und sie gehört benannt statt kaschiert —
Schwellen und Benachrichtigungen entstehen mit P9. Die Summe je Abonnement wird
dabei nicht abgelegt, sondern über die Datenbanken summiert: Eine mitgeführte
Spalte ginge auseinander, sobald eine Datenbank entfernt wird, ohne dass jemand
nachrechnet, und beide Zahlen sähen für sich plausibel aus.

**`srvpanel db` und `srvpanel db --prune` — der Weg zurück auf der
Kommandozeile.** Das Lesen zeigt Version, Horchadresse, den Bestand und die
befristeten Zugänge, die ein abgebrochenes Zurückspielen stehenliess; das
Aufräumen nimmt weg, was ein misslungener Rückbau hinterlassen hat. Die Auswahl
steht in `DatabasePrune` und nicht im Kommando — sie entscheidet, ob die Daten
eines Kunden von der Platte gehen, und ein Test soll sie prüfen können, ohne sie
nachzubauen. Zugänge vor Schemata vor Sicherungen: Ein Zugang ohne Schema ist
ein Zugang auf nichts, ein Schema mit Zugang ein offener Weg zu Daten.

**Und dabei ein Fund im Werkzeug selbst: vier von 129 Eingriffen in
`tests/waechter-brechen.sh` griffen ins Leere.** Der Bruch für den
Kommando-Wrapper suchte `|tls|vhost|` — zwischen den beiden steht seit P4 `dns`.
Dazu zwei Eingriffe, die auf `CertificateLifecycle` zeigten, obwohl der Code
längst in `CertificateRecord` und `CertificateChoice` steht, und einer auf
`Packet`, obwohl das Lesen eines Namenszeigers in `Dns\Name` gezogen ist.

Keiner war ein Fehler beim Schreiben: In allen vier Fällen ist der Code
umgezogen und der Eingriff stehengeblieben — das Muster aus CLAUDE.md an der
letzten Stelle, an der man es vermutet, nämlich im Werkzeug gegen genau dieses
Muster. Gemerkt hat es niemand, weil `griff_datei` erst beim Lauf des Skripts
greift und das Skript ein `vendor/` braucht.

Ein toter Eingriff ist dabei schlimmer als ein fehlender: Er sieht aus, als wäre
die Regel abgesichert, und der Wächter dahinter war vielleicht nie rot.
`BreakScriptTest` prüft den Bezug ab jetzt in der CI. Sein eigener Bruch kann
nicht im Skript stehen — er müsste das Skript ändern, und `wiederherstellen()`
nähme sich mitten im Lauf die Grundlage weg —, also steht die Befehlsfolge dafür
im Kopf des Tests.

Beim ersten Lauf hat er sich selbst überführt: Er meldete ausgerechnet die Zeile
mit den meisten Gegenschrägstrichen im Repo als tot, weil seine Entschlüsselung
der Python-Literale über eine Kette von `str_replace` lief — die sucht auf dem
schon veränderten Text weiter. Jetzt ein Scanner von links nach rechts.

**Zurückgenommen: eine Zusage in der Oberfläche ohne Funktion dahinter.**
Schritt 6 hatte das Hochladen einer Sicherung vorbereitet und nie gebaut — es
gab `ImportLimit` mit drei abgestimmten Zahlen, einen aufgeweiteten
`client_max_body_size`, einen aufgeweiteten FPM-Pool, die Spalte `kind` mit dem
Wert `import` und den Satz „Hochgeladene Dateien dürfen bis 512 MB gross sein".
Es gab keine Route, keine Methode und kein Formularfeld.

**Kein Wächter hat es gemeldet, und das ist die Lehre.** `UploadLimitTest` war
grün und hatte recht: Die drei Zahlen passten zueinander. Er prüfte die
Verträglichkeit einer Vorbereitung und nirgends, dass sie jemand benutzt — der
Satz aus dem P4-Abnahmelauf in neuer Gestalt: Ein Wächter, der drei Werte
gegeneinander hält, prüft nicht, dass sie gelten. Gefunden hat es eine Frage des
Betreibers, nicht ein Lauf.

Eine Zusage in der Oberfläche ist teurer als eine fehlende Funktion: Wer den Satz
liest, sucht das Feld und hält das Panel für kaputt. Und 544 MB Anfragekörper
anzunehmen ist für ein Panel, das keine Datei entgegennimmt, eine
Vergrösserung der Angriffsfläche für nichts. Die Grenzen stehen wieder auf
256m/256M wie vor P5.

Das Hochladen steht als eigener Schritt im Plan, samt den vier Prüfungen, die
heute nirgends stehen: die Magic Bytes `1f 8b`, eine Grenze für die
**ausgepackte** Grösse (400 MB gepackt können 40 GB werden, und `decompress()`
schreibt sie ohne Obergrenze auf denselben Datenträger wie die
Kundenverzeichnisse), der freie Platz vorher statt der Meldung hinterher, und
`kind = 'import'` als Färbung der Liste. Was er **nicht** braucht, ist ein
Filter über das SQL: Die Eindämmung ist der befristete Benutzer, und sie gilt
für eine mitgebrachte Datei wie für eine selbst erzeugte. Ein Filter wäre die
zweite, schwächere Fassung derselben Zusage.

**Und dieser Eintrag hat gleich den nächsten Wächter gefunden.** `ChangelogTest`
verlangt, dass jeder genannte Test existiert — und machte damit genau die Sorte
Eintrag unmöglich, die am meisten erklärt: den, der etwas **zurücknimmt**. Der
Changelog ist der Ort, an dem steht, was vorher falsch war; er muss das
Zurückgenommene benennen können.

Zwei Auswege wären schlechter gewesen: den Namen ohne Rückstriche zu schreiben,
dann greift der Ausdruck nicht und der Wächter ist umgangen statt erweitert —
oder ihn zu umschreiben, dann findet ihn niemand mehr in der Historie. Statt
dessen `ChangelogTest::REMOVED`, mit Datum und Grund je Eintrag, und dazu die
Gegenrichtung: Ein Eintrag, dessen Test wieder existiert, nähme ihn dauerhaft
von der Prüfung aus — `test_the_list_of_removed_tests_does_not_outlive_them`
meldet das. Dieselbe Falle, die dieses Projekt dreimal an Zählern erwischt hat,
nur in einer Ausnahmeliste.

Die Brüche dazu stehen **nicht** im Skript: Sie müssten eine Datei unter
`tests/` ändern, und `wiederherstellen()` fasst das Verzeichnis nicht an — es
nachzutragen ginge nicht, weil das Skript selbst darin liegt und ein
`git checkout -- tests/` irgendwann die Datei zurückschriebe, die bash gerade
liest. Der Kopf des Skripts hält das jetzt fest, die Befehlsfolgen stehen in den
betroffenen Tests.

**Schritt 9: `db.isolation.probe` und `srvpanel acceptance-db`.** Die
Selbstprobe baut eine echte Verbindung als der Datenbankbenutzer des Kunden auf
und stellt drei Fragen: `SHOW DATABASES` (die Anzeige), `USE` (der Wechsel),
`SELECT` (der Zugriff). Ein Server kann die Anzeige filtern und den Zugriff
zulassen — wer nur die Liste prüft, hat die Anzeige geprüft.

**Sie meldet Namen und keine Zahl**, und `IsolationVerdictTest` hält beide
Hälften fest: die Operation gibt die Liste heraus, der Lauf vergleicht sie als
Menge. Der Grund ist der teuerste Fund des P4-Abnahmelaufs — `count($visible) === 1`
wäre auch dann grün, wenn ein Benutzer eine *fremde* Datenbank sieht und die
eigene nicht.

Das Passwort überquert dafür den Socket, und das ist hier richtig: Es gibt
keinen anderen Weg, eine Verbindung als dieser Benutzer aufzubauen, und genau
die ist das Kriterium. `SHOW GRANTS` als root zu lesen zeigt, was dasteht, nicht
was MariaDB anwendet — derselbe Grund, aus dem die Selbstprobe von P3 ein Skript
ausführt statt die Pool-Vorlage zu lesen. Der Aufruf geht unmittelbar und nie
über die Warteschlange, sonst läge das Passwort in `operations.payload`.

**Der Lauf legt keine Abonnements an**, anders als `acceptance-web`. `system_users`
gibt eine Nummer nie wieder her; ein Abnahmelauf, den man zehnmal fährt,
verbrauchte zwanzig. Er bekommt zwei bestehende genannt, legt darin zwei
Datenbanken mit Zugängen an und räumt sie im `finally` wieder weg — auch wenn
ein Kriterium scheitert, denn sonst hinterliesse er genau die Reste, die
`srvpanel db --prune` danach wegräumen müsste.

Geprüft werden damit Kriterium 1 bis 3. Kriterium 4 bis 7 laufen von Hand: Sie
zu automatisieren hiesse, ein Abonnement zurückzubauen, und das ist genau das,
was dieser Lauf nicht tut. **Gefahren ist er noch nicht** — er braucht einen
Server mit MariaDB und zwei bestehenden Abonnements.

**Der Abnahmelauf ist gefahren — und der Fund darin ist der wichtigste dieses
Beitrags.** Am 8. August 2026 auf `cloudsrv24`, MariaDB 10.11.14. Der Lauf
meldete alle geprüften Kriterien erfüllt, und eine seiner Zeilen lautete:
„SELECT auf p1121_abnahme abgewiesen — Die Datenbank hat abgewiesen:
`--------------`".

`--------------` ist keine Fehlermeldung; der `mysql`-Client hatte die
gescheiterte Anweisung zwischen Strichzeilen ausgegeben, und der Lauf nahm die
erste Zeile. Dahinter stand das grössere Problem: `refused` wurde von **jeder**
Ausnahme gesetzt. `docs/36 §17` nennt die Nummern seit jeher — 1044 beim `USE`,
1142 oder 1044 beim `SELECT` —, gebaut war „es ist gescheitert". Ein
`ERROR 1146 Table doesn't exist`, also ein Tippfehler im Tabellennamen, hätte
sich gelesen wie eine funktionierende Abschottung. **Das Kriterium war nicht
belegt, obwohl es grün stand.**

Das ist die Lehre aus dem P4-Abnahmelauf eine Ebene tiefer: dort eine Zahl statt
der Namen, hier ein Fehlschlag statt des richtigen Fehlschlags. Und wieder hat
kein Test es gefunden, sondern der Blick auf eine grüne Ausgabe.

Die Probe meldet jetzt die Fehlernummer, der Lauf hält sie gegen die erwarteten,
und die Meldung wird nach der `ERROR`-Zeile durchsucht statt an einer Stelle
vermutet. `DbErrorCodeTest` führt beide Ausgaben mit, die der Server wirklich
geliefert hat — abgeschrieben und nicht nachgebildet: Eine Nachbildung dessen,
was der Client ausgeben *sollte*, hätte den Fall gerade nicht getroffen.

**Belegt hat der Lauf trotzdem viel**, und zwar an echtem MariaDB: dass
`db.server.info` Fassung und Horchadresse richtig liest, dass Anlegen,
Rechtevergabe und Benutzen laufen, dass das Passwort in keiner Vorgangsnutzlast
steht, dass `SHOW DATABASES` genau die eigene Datenbank und
`information_schema` zeigt — die Maskierung des Unterstrichs hält also am
Server —, dass das `USE` mit ERROR 1044 abgewiesen wird, und dass der Rückbau
nichts liegenlässt.

**Ungeprüft geblieben:** `db.usage` lief gegen einen Server ohne eine einzige
Datenbank und hat damit kein Schema getroffen — die Zerlegung seiner Ausgabe ist
weiter unbelegt. Kriterium 4 bis 7 stehen aus. Und ausserhalb von P5: Auf diesem
Server ist keine Dateisystem-Quota eingerichtet (`repquota: Cannot find
mountpoint for device /dev/vda3`), `disk_used_mb` ist dort also seit P2 für jedes
Abonnement „nicht gemessen".

**Der zweite Lauf hat die Abschottung belegt — und denselben Fehler eine Stelle
weiter gezeigt.** Die Fehlernummern stehen jetzt in der Ausgabe (ERROR 1044 beim
`USE`, ERROR 1142 beim `SELECT`, in beide Richtungen); Kriterium 1 bis 3 sind
damit an MariaDB 10.11.14 gemessen. Grün stand aber auch dies:

    2 Datenbank(en) gemessen.

Zwei war die richtige Zahl — gezählt waren die **geschriebenen Zeilen**. Eine
Datenbank ohne Treffer bekommt `size_mb = 0` als gemessene Null, und das ist
richtig: `information_schema` führt ein leeres Schema nicht auf. Also wäre genau
diese Zeile auch erschienen, wenn `db.usage` **gar nichts** geliefert hätte. Ein
Tippfehler in der Abfrage hätte sich als Erfolg gelesen, und `db.usage` war nach
zwei Läufen weiter unbelegt.

Das ist dasselbe Muster wie oben und wie in P4 — das dritte Mal in zwei
Ausbaustufen: **Eine Zahl über der eigenen Arbeit ist kein Messwert.** „1 fällig,
1 bestellt" (P4, falsches Zertifikat bestellt), `refused` von jeder Ausnahme
(oben), `measured` als Beleg für eine Messung (hier).

**Der dritte Lauf hat `db.usage` belegt — und die Zahl daneben als falsch
gerundet gezeigt.** Kriterium 1 bis 3 stehen jetzt vollständig, in beide
Richtungen und mit den Nummern in der Ausgabe. Die Messung meldete „2
Datenbank(en) geschrieben; der Server meldete 2 Schema(ta), 2 davon zugeordnet" —
damit ist die Abfrage am echten Server, die Aussonderung fremder Schemata und die
Zuordnung zu den Zeilen des Panels belegt.

Die *Grösse* war es nicht. Die Selbsttest-Tabelle mit einer Zeile belegt rund 16
KB, `Usage::apply()` rechnete `intdiv(bytes, 1024 * 1024)`, und die Oberfläche
zeigte „0 MB" — dasselbe wie für eine leere Datenbank. Dahinter stand ein
Widerspruch im eigenen Werk: `DbUsageScopeTest` begründet seit Schritt 6, warum
der Agent **Bytes** liefert — *„wer hier durch 1024² teilte, verlöre für jede
Datenbank unter einem Megabyte die Unterscheidung zwischen ‚leer' und ‚klein'"* —
und genau diese Division stand eine Zeile später im Panel. **Eine Begründung im
Test ist kein Wächter.**

`size_mb` heisst deshalb `size_bytes` und trägt, was der Agent liefert; die
Umbenennung ist eine eigene Migration, weil die Tabelle seit `v0.5.0-rc.1` auf
einem laufenden Server steht. `Subscription::databaseUsedMb()` summiert vor dem
Teilen — hundert Datenbanken zu je 300 KB sind 29 MB und nicht hundertmal null.
Gerundet wird an einer Stelle (`resources/js/bytes.ts`) statt an dreien: Liste,
Einzelansicht und Sicherungen hatten je ihre eigene Fassung, und die dritte war
die beste, weil sie als einzige KB kannte. So driften zwei Fassungen einer Regel
— nicht dadurch, dass eine falsch wird, sondern dadurch, dass eine besser wird
und niemand die andere nachzieht. `SizeUnitTest` hält beide Hälften, und die
Brüche dazu treffen je genau eine seiner Behauptungen.

**Das Abnahmekriterium von P5 ist am Server belegt, alle sieben Punkte.**
Anlegen, Benutzen, Sichern, Zurückspielen — und ein Datenbankbenutzer, der keine
fremde Datenbank sieht, mit den Fehlernummern von MariaDB 10.11.14 statt mit
einer geprüften Zeichenkette. Der Rückbau kam zuletzt dazu, und er hat zwei
Läufe gebraucht: Beim ersten hatte das zurückgebaute Abonnement nie gesichert,
also entstand kein `db.dump.remove`, und „das Verzeichnis ist nicht vorhanden"
stand da, **ohne dass je etwas entfernt wurde** — eine Abwesenheit ohne
Vorgeschichte, wortwörtlich die Falle, vor der derselbe Abschnitt zwei
Kriterien weiter oben warnt. Die Anleitung selbst hatte sie gestellt: Sie
verlangte Sicherungen für Kriterium 5 und 6 und liess offen, dass Kriterium 7
dieselben braucht. Der zweite Lauf holte es an einem eigenen Abonnement mit
genau einer Sicherung nach. `docs/36 §17` sagt das jetzt an der Stelle, an der
es zählt, und hat zwei Erwartungen dazubekommen: `mysql.db` muss leer sein — ein
Recht überlebt sein Schema, und `mysql.user` allein zeigt das nie —, und
`srvpanel db` muss „Nichts liegengeblieben" melden.

**Eine mitgebrachte Sicherung lässt sich hochladen — Schritt 11 aus `docs/36
§10.3`.** Die Datei geht nicht über den Socket: Das Panel legt sie in seinem
Schreibbereich ab, und `db.dump.import` holt sie von dort, durch
`Guard::pathInside()` aufgelöst und geprüft. Die vier Prüfungen, die §22.3f als
fehlend benannt hatte, sind gebaut — die Magic Bytes (im Panel für die schnelle
Meldung, im Agenten für das, was tatsächlich ankommt), die **ausgepackte**
Grösse (gezählt beim Auspacken, nicht aus dem Gzip-Trailer abgelesen: dort steht
sie modulo 2³² und ist fälschbar), der freie Platz auf dem Datenverzeichnis des
Datenbankservers, und die Herkunft. Letztere ist keine Kosmetik: Beim
Zurückspielen wird die Datenbank geleert, und wer nicht sieht, was dieser Server
geschrieben hat und was jemand mitgebracht hat, trifft die Wahl blind — die
Liste zeigt `mitgebracht` als Marke.

**Und `UploadLimitTest` kommt zurück, diesmal mit einer zweiten Hälfte.** Er
war gelöscht worden, weil er drei Zahlen gegeneinander hielt, während es das
Hochladen gar nicht gab — *ein Wächter, der drei Werte gegeneinander hält, prüft
nicht, dass sie gelten*. Er vergleicht sie weiter (nginx 544m ≥ `post_max_size`
544M ≥ `upload_max_filesize` 512M ≥ Prüfregel 512 MB, die engste zuletzt, damit
die Meldung vom Panel kommt und nicht vom Webserver) — **und fährt jetzt einen
Aufruf durch die Prüfregel**: ohne Datei, mit einer ZIP-Datei, die `.sql.gz`
heisst, und mit einer echten gzip-Datei, die im Bestand landen muss.

**Fernzugriff auf den Datenbankserver — Schritt 10 aus `docs/36 §12`.**
`srvpanel db --remote=on|off` schreibt über den Agenten eine eigene Datei
(`60-srvpanel.cnf`) in das Include-Verzeichnis des Servers und startet ihn neu.
Die `60` ist kein Geschmack: Debian und Ubuntu legen ihre `bind-address` in
`50-server.cnf` ab, und die Dateien werden lexikalisch gelesen. **Zurück
gemeldet wird, worauf der Server danach tatsächlich horcht** — `@@bind_address`
nach dem Neustart, nicht die Zeile, die wir geschrieben haben; weicht sie ab,
ist der Lauf ein Fehlschlag mit Grund und kein Erfolg. Der Neustart ist
zweifach abgesichert: Er wird vorher angesagt (Vorgabe „nein", wie bei
`--prune`), und scheitert er, stellt die Operation den vorherigen Inhalt wieder
her und startet erneut — der Datenbankserver trägt auch das Panel. Die Adresse
kommt aus einer Positivliste (`0.0.0.0`, `::`), weil ein freier Wert in einer
Konfigurationsdatei genau das ist, wovor die Positivliste des Agenten schützt.
Im Panel erscheint das Feld für eine fremde Adresse erst, wenn der Server
darauf horcht — und wenn nicht, steht der Grund darunter statt nichts; die
Sperre selbst sitzt im Steuerungscode, denn ein Formular ist keine Sperre.
Beim `purge` nimmt das Paket die Datei mit: Ein entferntes Panel darf keinen
offenen Datenbankport hinterlassen.

**Und der Schritt hat eine Lücke im Wächter über ihm freigelegt.**
`RemovalPathTest` erkennt eine anlegende Operation an ihrem Verb — `create`,
`apply`, `provision`. Eine Operation mit einem *Schalter* trägt keines davon,
und damit wäre ausgerechnet die Datei, deren Wirkung ein offener Datenbankport
ist, an dem Wächter vorbeigegangen, den es wegen `docs/35` gibt. Er prüft jetzt
zusätzlich die Sache statt des Namens: Wer im Agenten `file_put_contents`,
`mkdir`, `touch`, `copy` oder `rename` ruft, legt etwas ab, das liegenbleibt —
und heisst er nicht danach, sagt er, wo der Weg zurück ist. Im ganzen Agenten
trifft das auf zwei Operationen zu, und beide haben einen.

**Auf dem Schreibtisch musste man schieben, um einen Knopf zu treffen.** Die
Datenbankseite stellte „Zugänge" und „Sicherungen" in den Grundriss, und beide
tragen eine Aktionsspalte mit drei Knöpfen. `.scrolls > table` hält eine Tabelle
auf `max-content` — richtig, dafür gibt es den Rollbehälter —, und damit war die
Breite dieser Tabellen die Summe ihrer Knopfbeschriftungen: 755px und 923px, bei
548px Bereichsbreite auf einem 1440px-Bildschirm. Der letzte Knopf lag
ausserhalb. **Der Fehler wurde auf einem breiteren Bildschirm schlimmer:** Bis
1440px wich „Sicherungen" in eine eigene Zeile aus und stand richtig, ab 1600px
passten alle drei Bereiche nebeneinander und beide Tabellen rollten. Wer bei
1440px nachsah, sah die Hälfte. Beide Bereiche stehen jetzt über die volle
Zeile; der Überlauf ist von 1280px bis 1920px in beiden Dichtestufen 0.
`ActionColumnTest` verlangt das für jede Tabelle mit einer Knopfreihe in einer
Zelle — nicht für jede mit vier Spalten, denn vier Spalten sind kein Maß: Was
die Breite erzwingt, sind Knöpfe. Zum Lesen genügt Schieben, zum Drücken nicht.

**Der Rückbau nahm die Sicherungsdateien mit, ihre Zeilen aber nicht.**
`DbLifecycle::afterDump()` trug dazu einen Kommentar, der das Gegenteil
behauptete — „dort verschwinden die Zeilen mit dem Abonnement" —, und
`database_dumps.subscription_id` steht mit Absicht auf `nullOnDelete`, damit
eine Sicherung ihre Datenbank überlebt: Die Zeile ist der Wegweiser zu einer
Datei, auf die sonst nichts mehr zeigt, und davon lebt `srvpanel db --prune`.
Nach einem *erfolgreichen* Rückbau ist die Datei aber fort, und der Wegweiser
zeigt ins Leere. Auf dem Zielserver zählte der Bestand danach drei Sicherungen,
während zwei auf der Platte lagen. Das ist die teurere Hälfte des Fehlers: Ein
Rückbau, der sauber gelaufen ist, meldet einen Rest — und ein Melder, der jedes
Mal Alarm gibt, wird bald gelesen wie ein Rauschen. Ein `db.dump.remove` ohne
Gegenstand ist immer der Rückbau eines ganzen Abonnements, und der Vorgang trägt
zu diesem Zeitpunkt noch sein `subscription_id`; die Zeilen gehen jetzt im
selben Zug. Wer vor dieser Fassung zurückgebaut hat, wird die Zeile mit
`srvpanel db --prune` los.

**Eine entfernte Datenbank liess ihr Recht liegen.** `DROP DATABASE` nimmt in
MariaDB die auf das Schema vergebenen Rechte nicht mit — sie stehen in
`mysql.db` und bleiben dort —, und die Anwendung nannte dem Agenten nur die
Zugänge, die *mitgehen*. Wer an einer zweiten Datenbank hing und darum
überlebte, behielt sein `GRANT ALL` auf die entfernte. Auf `cloudsrv24` gefunden
als eine Rechtezeile für `p1118_demo`, ein Schema, das es seit Tagen nicht mehr
gab; entsteht der Name später wieder, hätte dieser Zugang sofort alle Rechte
darauf, ohne dass sie ihm jemand gegeben hat. Seit `db.user.grant` das Verbinden
zu einer ausdrücklichen Handlung macht, wich damit der Bestand des Panels von
dem ab, was MariaDB erlaubt. Der Auftrag trägt jetzt beide Listen: `users` geht,
`revoke` bleibt und verliert das Recht. Die Reihenfolge im Agenten ist Rechte,
Zugänge, Schema — `Session::execute()` bleibt beim ersten Fehler stehen, und von
den beiden Zwischenzuständen ist ein Schema ohne Zugang der harmlosere.
`OrphanedGrantTest` prüft beide Hälften des Weges und dazu die Eigenschaft, die
keine der Listen allein hat: kein verbundener Zugang fällt aus beiden heraus.
Gefunden hat das niemand beim Bauen, sondern der Betreiber beim Lesen einer
Ausgabe, die zu einem ganz anderen Kriterium gehörte.

**„Noch keine Ausgabe." stand unter einem fertigen Vorgang.** Das Wort sagt zu,
dass etwas kommt; an einem abgeschlossenen Vorgang kommt nichts mehr. Die Seite
kannte den Zustand und benutzte ihn für diesen Satz nicht. Auf einer leeren
Liste bleibt „Noch keine Domain" richtig — dort kann eine dazukommen, und genau
dieser Unterschied ist die Regel, die `WordChoiceTest` jetzt hält.

**Ein vorhandener Zugang lässt sich mit einer weiteren Datenbank verbinden.**
`Databases::grant()` und die Operation `db.user.grant` lagen seit P5 fertig da,
und kein Controller, keine Route und kein Test riefen sie auf — aufgefallen erst,
als das Anlegen einen vergebenen Namen abzuweisen begann und ein Kunde mit einer
Anwendung auf zwei Datenbanken damit gar keinen Weg mehr hatte. Jetzt über eine
Adresse für beide Richtungen, mit „Zugriff entziehen" an der Zeile und einer
Rückfrage davor.

**Die Form ist gemessen worden, nicht geschätzt.** Drei Entwürfe, gerendert mit
dem gebauten Stylesheet in beiden Themes: eine Kästchenspalte über alle Zugänge
(390 px: 1109 px hoch), eine Auswahlliste mit Entziehen je Zeile (837 px) und die
echte Matrix aus Zugängen und Datenbanken (626 px). Die Matrix ist die
kompakteste — und trotzdem nicht die Wahl für diese Seite: Sie beantwortet eine
Frage über *alle* Datenbanken, und diese Seite handelt von einer. Die
Kästchenspalte ist an etwas gescheitert, das erst in der Aufnahme zu sehen war:
Sie muss alle Zugänge auflisten, und auf dem Telefon steht dann neben einem
Zugang, der mit dieser Datenbank nichts zu tun hat, ein Knopf, der ihn ganz
löscht.

**Und ein Wächter mit einer Lücke, die drei Monate gehalten hat.**
`AgentOperationReachTest` nahm eine Operation als benutzt an, sobald sie in
`WITHOUT_LIFECYCLE` steht — dort steht sie aber, weil erklärt ist, *warum sie
keinen Lebenslauf hat*. Das ist eine andere Frage. Wer erklärt, dass ein Dienst
unmittelbar aufruft, muss jetzt zeigen, dass es einen Weg dorthin gibt. Der
strengere Test fand sofort eine zweite: `acme.account.ensure` ruft niemand auf —
das ACME-Konto entsteht beim Bestellen mit. Sie steht mit Datum und Grund in
`UNREACHED`; ob sie angeschlossen oder entfernt wird, ist eine Entscheidung mit
TLS-Folgen.

**Ein zweiter Zugang mit demselben Namen ersetzte das Passwort des ersten.** Der
Agent baut `CREATE USER IF NOT EXISTS` und danach `ALTER USER … IDENTIFIED BY` —
richtig für den Wiederholungslauf eines abgebrochenen Vorgangs, und derselbe Weg
galt für den ganz normalen zweiten Klick. Das Feld „Benutzername" ist mit `user`
vorbelegt; wer eine zweite Datenbank anlegte und es stehen liess, bekam keinen
zweiten Zugang, sondern denselben mit einem neuen Passwort. Die Anwendung, die
das alte in ihrer Konfigurationsdatei hatte, war ab da ausgesperrt, und das Panel
meldete „Zugang angelegt". Der Name wird jetzt abgewiesen, bevor der Agent
gefragt wird — dort ist eine Absicht bekannt, im Agenten nur ein Auftrag.

**Und drei Funde, die daran hingen.** Die Fehlermeldung landete fest auf
`user_label`, obwohl das Formular „Weiterer Zugang" `label` schickt — der
Feldname kommt jetzt vom Aufrufer. `DatabaseFormTest`, den ein Kommentar seit P5
versprach, gab es nicht; er ist geschrieben und fand bei seinem ersten Lauf, wofür
er versprochen war: Die Prüfregel des Formulars endete auf `$` ohne `D` und liess
damit einen Zeilenumbruch durch, den der Agent abweist. `AnchoredPatternTest` las
bis dahin nur unter `agent/` und liest jetzt auch die `regex:`-Regeln der
Formulare.

**`GuardReachTest`** ist der Ertrag daraus: Jeder Testname, der irgendwo im Code
steht, gehört zu einer Datei, die es gibt; Ausnahmen kommen aus
`ChangelogTest::REMOVED`. Beim ersten Lauf waren es drei — neben
`DatabaseFormTest` noch `DbTenancyTest` (im Plan §16.7 vorgesehen, nie
geschrieben: die Hälfte des Abnahmekriteriums, die im Panel spielt — jetzt
geschrieben) und `SecretsStayOutOfTheStoreTest`, dessen Regel längst als Methode
in `SecretsStayOutOfTheQueueTest` lebt. Ein toter Verweis auf eine Klasse fällt
beim nächsten Aufruf auf, einer auf einen Test niemals.

**Ein fehlgeschlagener Vorgang zeigte „Begonnen —" und „Beendet —".** Beide
Zeitstempel waren gesetzt; die Seite zeigte die Werte aus der ersten
Inertia-Antwort, und zu dem Zeitpunkt stand der Vorgang in der Warteschlange.
Der SSE-Kanal führte Zustand, Fortschritt und Meldung nach — die Zeiten nicht.
Zwei Quellen für dieselbe Angabe, und eine wird nicht nachgezogen; ein Neuladen
zeigte die richtigen Werte, wer zusah, sah einen Zustand, den es nie gab.

Der Kanal schickt jetzt beide Zeiten, und die Vorlage liest sie von dort.
`OperationStreamTest` prüft beide Richtungen: dass das Ereignis sie trägt (am
ausgelieferten Strom, nicht am Quelltext), und dass die Vorlage **kein** Feld
aus der Erstantwort druckt, das der Kanal nachführt — die Namen dafür kommen aus
dem Controller und nicht aus einer gepflegten Liste. Der zweite ist der
wichtigere: Der Kanal hätte die Zeiten schicken können, und solange die Vorlage
`props.operation.started_at` ausgibt, ändert das nichts.

Dabei ist ein Namenskonflikt aufgefallen und mitbehoben: Im Ereignis hiess
`label` der **Zustand** („fehlgeschlagen"), in der Seitennutzlast heisst `label`
die **Aufgabe** („db.restore"). Zwei Bedeutungen für einen Namen auf derselben
Seite; das Ereignis nennt es jetzt `status_label`.

**Eine fertige Sicherung liess sich nicht herunterladen — 404.** Gefunden vom
Betreiber beim Durchgehen der Kriterien 4 bis 7. Die Datei lag als
`root:srvpanel 0640`, die Gruppe des Panels durfte sie also lesen; ihr
Verzeichnis lag als `root:root 0750`, und damit fiel das Panel in „andere".
Unter Unix öffnet man eine Datei über ihren Pfad, und dafür braucht es das
`x`-Bit auf **jedem** Verzeichnis darüber — das `r` an der Datei war wertlos.

Der Kommentar am Code hat den Fehler begründet statt ihn zu verhindern: *„Nicht
der Gruppe des Panels: Sie soll die Dateien lesen dürfen und nicht das
Verzeichnis durchsuchen."* Die Absicht ist richtig und lässt sich so nur nicht
ausdrücken — wer nicht durchsuchen darf, liest auch nicht. Und `docs/36 §10`
hatte `root:srvpanel 0750` dastehen: **Der Plan war richtig, die Umsetzung ist
davon abgewichen**, mit einer Erklärung statt mit einer Frage.

Jetzt `0710` mit der Gruppe des Panels — enger als die ursprüngliche Angabe und
näher an ihrem Zweck: hingehen darf, wer den Namen kennt, auflisten niemand.
Gesetzt werden beide Ebenen und bei jedem Lauf, damit eine ältere Installation
sich mit der nächsten Sicherung selbst berichtigt. `DumpAccessTest` rechnet die
Unix-Regel nach, statt die Zahl ein zweites Mal hinzuschreiben: `x` auf jedem
Verzeichnis des Pfades, `r` an der Datei, kein `r` an den Verzeichnissen, nichts
für „andere" — und alles für dieselbe Gruppe. Die letzte Behauptung ist die, die
den Fehler gefunden hätte.

**Und die Datenbankseite hatte auf dem Telefon versetzte Striche.** Sie
beschriftete ihre Paar-Tabelle als einzige im Panel mit `<th>`. Die schmale
Fläche macht aus jeder Zeile eine Flexzeile und setzt dafür `table.pairs td`
zurück; ein `th` fällt unter keine dieser Regeln und behielt seinen Rand aus der
Tabellengestaltung — so breit wie die Beschriftung. `MobileLayoutTest` besteht
jetzt auf einer Form für alle elf Paar-Tabellen.

**Der Bestand auf der Übersicht führt Domains und Datenbanken.** Bis dahin
standen dort Kunden und Abonnements — also, wer und was gebucht. Das Gehostete
selbst, die Namen, unter denen jemand erreichbar ist, und die Daten dahinter,
fand man nur, wenn man den Verdacht schon hatte. Die Zahlen stehen nach Zustand,
und die Zeilen für „gesperrt", „werden angelegt" und „werden entfernt" erscheinen
nur, wenn es sie gibt.

**Gezählt wird, was die verlinkte Liste zeigt** — einschliesslich der verwaisten
Datenbank, deren Rückbau steckengeblieben ist. Sie auszunehmen, weil sie zu
keinem Abonnement mehr gehört, ergäbe eine zu kleine Zahl ausgerechnet dann,
wenn etwas nicht stimmt. `OverviewInventoryTest` prüft das an der Zählung und
zusätzlich die Verweise: Jede Adresse im Bestand ist eine Adresse, die der Router
kennt, und jede der vier Arten ist verlinkt. Beide Richtungen, weil die erste
Behauptung auch grün bliebe, wenn ein Verweis ersatzlos verschwände.

Beide Messungen geben jetzt drei Zahlen: `measured` (geschriebene Zeilen),
`reported` (was der Server genannt hat) und `matched` (was zuzuordnen war);
`srvpanel usage` zeigt alle drei und warnt beim Missverhältnis — ein Schema, das
der Server nennt und das Panel nicht kennt, ist ein Befund für `srvpanel db`.
Dass die Quota-Messung mitkommt, ist keine Zugabe: Die Lücke stand dort genauso,
und ein Wächter, der nur eine von zwei gleichen Stellen hält, ist der, den die
nächste Abschrift umgeht. `UsageEvidenceTest` prüft es am Verhalten — leere
Antwort gegen zwei Zeilen, `reported` muss null sein — und der Bruch dazu hat
seine letzte Behauptung gleich noch verbessert: Sie suchte die drei Zahlen im
ganzen Methodenrumpf und blieb grün, als sie aus der Erfolgsmeldung
verschwanden. Sie stehen weiter in der Warnung darunter, die aber nur im
Ausnahmefall kommt.

**Der Fernzugriff hatte im Panel keinen Ort, an dem sein Zustand steht.**
`docs/36 §19` legt den Schalter auf `srvpanel db --remote=on` und begründet das
damit, dass eine serverweite Horchadresse keine Folge eines Kundenhäkchens sein
darf — das ist eine Regel darüber, *wer schalten* darf, und war nie eine
darüber, *wer nachsehen* darf. Wer im Panel wissen wollte, ob der Server nach
aussen horcht, musste sich auf den Server einloggen; und wer auf einer
Datenbankseite las „nur lokal erreichbar", stand vor einem Satz, der einen
Befehl nennt, den er nicht ausführen darf. Neu ist **„Einstellungen →
Datenbankserver"** (`Database.vue`, hinter `can:manage-settings`): Art und
Version, Horchadresse, Fernzugriff an/aus, wie viele Zugänge auf eine fremde
Adresse lauten — nach Adresse aufgeschlüsselt —, und die beiden Befehle zum
Abtippen. **Kein Schalter, und das ist die Entscheidung und nicht ihre
Auslassung:** Ein Umschalten startet den Datenbankserver neu, auf dem dieses
Panel selbst arbeitet — die Anfrage verlöre ihre Verbindung mitten im Lauf, der
Arbeiter ebenso, und übrig bliebe ein Vorgang, der für immer auf „läuft" steht,
ausgerechnet der, an dem man ablesen will, ob es geklappt hat. Auf der
Kommandozeile gibt es das Problem nicht, weil `srvpanel db --remote` nach dem
Neustart selbst nachliest. `RemoteAccessTest` verlangt deshalb, dass unter
`/settings/database` keine schreibende Route liegt — wer dort doch einen Knopf
will, macht ihn rot und liest dabei den Grund. Der Zustand, der die Seite trägt,
ist der fünfte: Zugänge für fremde Adressen an einem Server, der nur lokal
horcht. Anlegen lassen sie sich so nicht, aber sie entstehen, wenn der
Fernzugriff *nachträglich* abgeschaltet wird — danach sehen sie aus wie jeder
andere Zugang und funktionieren nicht.

**Und dabei fiel ein ausgelieferter Fehler auf, den kein Test fangen konnte.**
Auf der Datenbankseite stand seit P5 in der Meldung zu einer verwaisten
Datenbank: „Aufgeräumt wird sie über `srvpanel db prune`." Das Kommando nimmt
kein Argument; wer die Zeile abtippt, bekommt „Too many arguments" und sonst
nichts. Der Befehl heisst `srvpanel db --prune` und steht drei Zeilen entfernt
im Quelltext des Kommandos richtig. **Das ist wortwörtlich das Muster, an dem
dieses Projekt sechsmal hängengeblieben ist** — eine Zeichenkette, die auf etwas
verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft —,
diesmal im Text, den ein Betreiber abtippt. `CommandReachTest` prüft ihn in zwei
Richtungen, weil eine nicht genügt hätte: Jede Option neben einem
`srvpanel`-Befehl muss in der `$signature` des Kommandos stehen und das
Unterkommando im `case`-Zweig des Aufrufers — und ein Befehl, den die Oberfläche
in einer Kennung abdruckt, muss **ganz** aus Optionen bestehen. Nur die zweite
fängt `prune` ohne Striche: Das ist kein Fehler *in* einer Option, sondern ein
Wort, das nach einer aussieht. Sein Bruch ist zugleich der Anlass, aus dem
`routes/` jetzt in beiden Listen von `tests/waechter-brechen.sh` steht — vorher
hätte `wiederherstellen` die Datei nicht zurückgeholt, und die Probe wäre eine
Änderung gewesen.

**Der Fortschritt eines Vorgangs kam nie an, und die Ausgabe des Agenten auch
nicht.** Der Agent schickt seit P0 Zwischenmeldungen der Form
`['type' => 'progress', 'pct' => …, 'text' => …]`; der Arbeiter las daraus
`percent` und `message` und setzte bei einem unbekannten Schlüssel
stillschweigend `0` ein — **also bei jedem einzelnen Frame**. Für die Ausgabe
dasselbe eine Ebene höher: gesendet wurde `type: log`, geprüft wurde auf
`type: output`. Vier Zeichenketten über eine Prozessgrenze hinweg, keine davon
passend, und beide Seiten sahen für sich richtig aus. Die Folge war eine
Abwesenheit und deshalb unsichtbar: **Der Balken jedes Vorgangs sprang von 0 auf
100, und die Ausgabe des Agenten hat nie ein Mensch gesehen.** Aufgefallen ist
es an einem gescheiterten Import, der „Fortschritt 0 %" zeigte, obwohl er
nachweislich weiter gekommen war; die Gegenprobe über 471 Vorgänge fand keinen
einzigen mit einem Wert dazwischen. Die Antwort ist nicht, die Namen
richtigzustellen, sondern sie nur noch einmal zu schreiben: `Frame` baut und
liest, `Context` und `OperationRecorder` gehen beide hindurch, und
`FrameContractTest` fährt einen echten Frame aus dem Agenten bis in die Zeile
der Datenbank. `consume()` stand dafür bis dahin als `private` im Arbeiter — kein
Test kam an sie heran; sie liegt jetzt am Recorder.

**Ein gescheiterter Upload blieb liegen.** Das `@unlink()` im Steuerungscode
umschliesst das Einreihen des Vorgangs, der Agent weist aber erst später im
Arbeiter ab — und dort gab es überhaupt keine Gegenrichtung. Nach einer
abgewiesenen Zip-Bombe lagen 109 MB in der Übergabe, die nichts im System je
wieder angefasst hätte: `srvpanel db --prune` sieht nur Zeilen ohne Abonnement
an, die Paketskripte fassen das Verzeichnis nicht an, und über das Panel ist die
Datei gar nicht erreichbar. Bis zu 512 MB je Versuch, ausgelöst von einem
Kunden. `AfterOperation` hat deshalb jetzt ein `afterFailure()` — die Richtung,
die seit Schritt 6 fehlte —, und damit fällt derselbe Schönheitsfehler mit: Die
Zeile einer gescheiterten Sicherung steht nicht mehr für immer auf „läuft",
sondern auf `failed` mit dem Grund des Agenten in `last_error`. Gelöscht wird
nur innerhalb der Übergabe: Der Pfad kommt aus einer Zeile in der Datenbank, und
ein `unlink()` darauf ohne Wurzelprüfung wäre die Sorte Zeile, mit der ein Panel
sich selbst löscht.

**Und die abgelegte Grösse einer Sicherung wurde nie gegen die Datei gehalten.**
Auf dem Zielserver wich sie bei einer von vier Sicherungen ab — 69255 im Bestand,
69362 auf der Platte —, und `bytes` ist genau die Zahl, die dem Kunden als
„Grösse" angezeigt wird. Woher die Abweichung dieser einen Zeile kam, war nicht
mehr zu klären; der Fund ist, dass es folgenlos blieb. Dieselbe Familie wie das
`GRANT`, das sein Schema überlebte, und die Zeile, die ihre Datei überlebte —
keinen der drei hat ein Test gefunden, sondern ein Abnahmelauf. `srvpanel db`
vergleicht jetzt beides und meldet Abweichung wie fehlende Datei, gemeldet und
nicht repariert: Das eine ist ein Schönheitsfehler, das andere ein
Datenverlust. Dabei fiel auf, dass ein toter Agent das Kommando bisher abbrach,
bevor es überhaupt zum Bestand kam — wer nachsieht, weil etwas kaputt ist,
bekommt jetzt beides.

**Die drei Funde aus dem Abnahmelauf sind auf dem Server nachgewiesen, nicht nur
gebaut.** `srvpanel db` meldet auf `cloudsrv24` genau die Sicherung, deren
abgelegte Grösse von der Datei abweicht — und prüft dabei 4 von 5 Zeilen, weil
die fünfte noch keine Datei hat. Ein erfolgreicher Sicherungsvorgang trägt jetzt
„fertig" als Meldung statt des Textes vom Einreihen; das ist der Beleg, dass die
Fortschrittsframes ankommen, und er funktioniert auch bei einer Sicherung, die in
unter einer Sekunde durchläuft und am Balken nichts zu sehen gibt. Der
gescheiterte Import steht auf 25 % — dem Punkt, an dem die Prüfung der
ausgepackten Grösse abbricht — und seine Übergabe ist leer, mit `failed` und
gefülltem `last_error` an der Zeile. Der feinste Beleg ist der Zeitstempel des
Übergabeverzeichnisses: Er ist auf die Uhrzeit des gescheiterten Uploads
gesprungen, es ist dort also etwas angelegt **und wieder entfernt** worden. Ein
leeres Verzeichnis allein wäre eine Abwesenheit gewesen.

**Und `kind` ist eine Aufzählung geworden.** Die Herkunft einer Sicherung stand
als nackte Zeichenkette an vier Stellen, während `DumpStatus` daneben längst
eine Aufzählung war — und die beiden Werte sind ungleich gebaut, ein Stamm und
ein Partizip. Gefährlich daran ist nicht die Asymmetrie, sondern das Tippen: Wer
sich vertippt, bekommt eine Bedingung, die nie zutrifft, und keine Meldung. Die
Werte selbst bleiben, wie sie sind — sie stehen in den Zeilen laufender
Installationen, und eine Datenmigration über Kundendaten, damit zwei Wörter
grammatisch zueinander passen, ist Unruhe ohne Gegenwert. Dabei kam eine vierte
Stelle zum Vorschein: Das Vue-Template verglich die Herkunft selbst, als
Zeichenkette über die Grenze zwischen PHP und Browser — dieselbe Bauart wie der
Frame-Fehler. Hinüber geht jetzt der fertige Text, wie bei `status_label`
daneben. `DumpKindTest` verbietet den Wert überall ausser in der Aufzählung und
der Migration, die die Spalte anlegt; er hat beim ersten Lauf sofort zugebissen,
und zwar auf einen Kommentar, der den alten Vergleich zitierte.

Der Kommentar in der Migration behauptete ausserdem, es gebe in P5 nur einen
Wert und die Spalte nehme später `import` auf. Beides ist seit Schritt 11 falsch,
und der zweite Wert heisst anders. Ein Kommentar, der eine Behauptung über die
Zukunft macht, wird von dieser Zukunft nicht benachrichtigt.

**Was in P5 ausdrücklich nicht gebaut wird:** Adminer (aufgeschoben,
Entscheidung 4 — grösste neue Angriffsfläche, und die Aufgabe ändert sich mit
P5b) und PostgreSQL (Entscheidung 1: eigene Stufe P5b mit eigenem Plan und
eigener Abnahme, statt „zweiter Schritt der Stufe"). `docs/20 §9` und `§15` sind
nachgezogen.

### P5b — PostgreSQL: die Messung kam vor dem Plan (docs/38)

**Das Abnahmekriterium von P5b war nicht erfüllbar, und das ist auf einem
echten PostgreSQL gemessen worden, bevor eine Zeile Plan entstand.** `docs/20
§9` verlangte, dass ein Datenbankbenutzer die *Namen* fremder Datenbanken nicht
aufzählen kann. `docs/37 §3` hatte ausdrücklich verlangt, das zu messen statt
aus dem Gedächtnis zu beantworten — und der Anlass dafür war die Lehre aus dem
TLS-Abnahmelauf: Wissen aus zweiter Hand sieht wie Wissen aus.

Gemessen wurde **in diesem Container**, und das war der erste Fund: `CLAUDE.md`
und `docs/36 §18` halten fest, dass hier keine Datenbank läuft. Für MariaDB
stimmt das, für PostgreSQL nicht — `postgresql-16` ist installiert, Server und
alles. Ein Wegwerf-Cluster auf einem eigenen Port, die Lage aus `docs/36 §17`
nachgebaut, zwei Abonnements. Damit ist der grösste Teil von P5b hier *fahrbar*
und nicht nur übersetzbar; jeder Fund, der lokal fällt, spart eine CI-Runde.

**Das Kriterium fällt aus zwei unabhängigen Gründen, und der zweite ist der
teurere.** Der erste: Der Verbindungsaufbau unterscheidet „keine Berechtigung"
von „gibt es nicht" und verrät damit die Existenz einer fremden Datenbank. Das
lässt sich durch keine Rechtevergabe schliessen, es ist Teil des Protokolls. Der
zweite: Das Aufzählen liesse sich schliessen — aber der Entzug von `pg_database`
nimmt dem **Kunden** `pg_dump`, und zwar nicht nur `--create`, sondern den
schlichten Export seiner eigenen Datenbank. Ein Panel, das die Abschottung
durchsetzt, indem es dem Kunden das Sicherungswerkzeug wegnimmt, hat einen
Sicherheitsgewinn gegen einen Datenverlust getauscht.

**Und `pg_database` war gar nicht der einzige Kanal — nur der bekannteste.** Ein
Rundgang durch den Katalog nach Spalten, die einen Datenbanknamen führen, fand
dreizehn Relationen, **elf davon für jeden Kunden lesbar**. `pg_stat_database`
nennt *alle* Datenbanken, auch die ohne jede Aktivität; `pg_stat_activity` gibt
zusätzlich die Rollennamen fremder Sitzungen preis. Wer nur `pg_database`
gesperrt hätte — also genau das, was `docs/37 §3` als „die" Frage benannte —,
hätte einen Kanal geschlossen und zehn offengelassen, und das Kriterium hätte
grün ausgesehen.

**Der unangenehmste Fund ist eine Absperrung, die sich selbst aufhebt.** Der
Entzug wirkt je Datenbank und nicht je Cluster. Eine mit `TEMPLATE template0`
angelegte Datenbank — und `template0` ist Pflicht, sobald eine Sortierung
gesetzt wird — kommt mit unveränderten Rechten zurück. Gemessen: dieselbe Rolle
sah in der einen Datenbank nichts und in der nächsten sieben Namen, und beide
sahen von aussen gleich aus. Deshalb läuft die Absperrung in P5b in derselben
Operation wie das Anlegen, und deshalb wird die Liste der Sichten **erfragt und
nicht verdrahtet**: Sie ist fassungsabhängig, und eine feste Liste wäre auf der
nächsten Fassung ein offener Kanal, den niemand bemerkt.

**An seine Stelle tritt ein Kriterium mit sieben Punkten** (`docs/38 §3`):
Namen, die nichts verraten, elf gesperrte Statistiksichten, kein Zugriff, eine
Absperrung, die der Kunde nicht aufheben kann, ein Dump, der nichts erzwingen
kann — und ausdrücklich, dass `pg_dump` für den Kunden weiter funktioniert.
Der letzte Punkt ist kein Zusatz, sondern die Gegenprobe zum ersten: Er
verhindert, dass jemand die Abschottung später doch über den Entzug löst und
dabei etwas kaputtmacht, das niemand prüft. Der verbleibende Ratekanal wird im
Abnahmelauf **gefahren und protokolliert** — ein Kriterium, das seine eigene
Grenze nicht misst, behauptet sie nur.

**Der Kunde bekommt seine Datenbank nicht mehr zu eigen, und das ist gemessen
und nicht vorsichtshalber.** Ein Eigentümer darf `GRANT CONNECT ON DATABASE …
TO PUBLIC`; danach verbindet sich jeder andere Kunde des Servers. Er darf
ausserdem `DROP DATABASE` auf die eigene — das Panel hätte danach eine Zeile und
keine Datenbank. Ein Abnahmekriterium, das der Geprüfte mit einer Zeile SQL
abschalten kann, ist keins. Der Preis ist ehrlich zu nennen: ohne Eigentum kein
`CREATE EXTENSION`, auch kein `pgcrypto`. Die Positivliste dafür steht als
Punkt 5b in `docs/20 §15`.

**Die Falle, an der P5b sich am leichtesten hätte täuschen lassen:** `psql -f`
gibt bei gescheitertem SQL **0** zurück und arbeitet weiter. Gemessen an vier
Anweisungen, von denen die dritte abgewiesen wurde: Rückgabewert 0, und die
vierte lief trotzdem. `mysql` bricht von selbst ab — genau darauf ruht der Beleg
von Kriterium 6 in P5, wörtlich „ERROR 1045 at line 6520". Ohne
`ON_ERROR_STOP=1` wäre in P5b ein vollständig gescheitertes Zurückspielen als
„erledigt" gemeldet worden, und für den bösartigen Dump hätte es überhaupt keine
Fehlermeldung gegeben, die man zitieren könnte. Das ist Lehre 3 aus `docs/37
§6` an einer Stelle, an der das andere System sie von selbst einhält — und
deshalb die gefährlichste Sorte: eine Regel, die man nur dort lernt, wo sie
gebrochen wird.

**Der Agent kommt als root nicht an PostgreSQL heran.** MariaDB erkennt root
über den Socket, das kostete in P5 keine Zeile; PostgreSQL bildet Unix-Kennungen
auf Rollen ab, und eine Rolle `root` gibt es nicht. Gebaut wird deshalb ein
„läuft als"-Feld im `Runner` und **nicht** `runuser` auf der Positivliste:
`runuser` ist ein Programm, das als root beliebige andere unter beliebiger
Kennung startet — auf einer Liste, von der `certbot` in P4 mit der Begründung
verschwunden ist, dass ein Programm mit Erlaubnisschein Angriffsfläche ist, wäre
das die weiteste Zeile überhaupt. Das Feld bekommt eine eigene Positivliste mit
genau einem Eintrag; ein `as`, das Freitext nimmt, wäre dieselbe Vollmacht eine
Ebene tiefer.

**`suggests: postgresql` stand seit P0 in `nfpm.yaml` und hat nie etwas
bewirkt.** Es war das einzige Vorkommen des Wortes im ganzen Quelltext — ohne
Kommentar, ohne Operation, ohne Prüfung, in einer Datei, deren übrige Zeilen
jede einzeln begründet sind. `Suggests` installiert nichts. Wieder das Muster,
das dieses Projekt am häufigsten trifft: eine Zeichenkette, die auf etwas
verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft; sie
liest sich wie eine Abhängigkeit und ist eine Absichtserklärung. `Depends` wäre
die falsche Korrektur — jede Installation bekäme einen zweiten Datenbankdienst,
gemessen 6 Prozesse, ~108 MiB und 79 MB Platte, auch auf Servern, die nie eine
PostgreSQL-Datenbank anlegen, und abwählbar wäre er nicht. Gebaut wird deshalb
die Form aus P3: erkennen immer, installieren auf Verlangen, hinter einem
Betreiberschalter. Ein vorhandener Cluster ist Bestand und wird benutzt.

**Ein Verweis in einem Dokument zeigte ins Leere**, und der Wächter dafür
existiert: `ChangelogTest::test_every_referenced_document_exists`. Er hätte es
trotzdem nicht gefunden — er sieht in den `CHANGELOG` und prüft die *Nummer*
über einen Glob, nicht den Dateinamen und nicht die Dokumente untereinander.
`docs/20 §15` verwies auf `37-postgresql.md`; die Datei heisst
`37-uebergabe-an-p5b.md`. Das ist die zweite Lehre über Wächter aus `docs/37
§6` eine Ebene weiter: Sie dürfen `docs/` nicht auslassen. `DocLinkTest` kommt
als Schritt 0 von P5b, und der Verweis ist berichtigt.

**Was P5b ausdrücklich nicht baut:** Fernzugriff. Die Wirtsbeschränkung steht in
PostgreSQL in `pg_hba.conf` und hat kein Gegenstück am Benutzer; die Datei
bleibt unangetastet, und einen Include-Punkt kennt sie erst ab PG 16 — bei
Debian auch dort nicht eingeschaltet. Zwei Umsetzungen für eine Zusage sind
keine. Er steht als Punkt 5c in `docs/20 §15`. `docs/20 §9 P5b` ist mitsamt
seinem Abnahmekriterium nachgezogen.

### Der Fernzugriff auf PostgreSQL kommt doch — und warum er erst nicht kam

**`docs/38 §14` hat in seiner ersten Fassung abgeraten, und die Begründung war
eine wahre Aussage über etwas, das die Aufgabe nicht verlangt.** Sie lautete:
Ein Include-Punkt für `pg_hba.conf` existiert erst ab PG 16 und ist bei Debian
auch dort nicht eingeschaltet — auf Ubuntu 22.04 und Debian 12 gibt es ihn
nicht, ein Fernzugriff wäre also auf der Hälfte der Zielplattformen anders
gebaut. Jeder Halbsatz davon stimmt. Nur braucht ein **verwalteter Block
zwischen Marken** überhaupt keinen Include-Punkt, und der ist auf PG 14 bis 17
derselbe Bau.

Das ist im selben Dokument zum zweiten Mal derselbe Fehler. `docs/38 §3` hält
für `pg_database` fest, dass ein zutreffender Satz die falsche Frage beantworten
kann — und §14 hat es dann selbst getan. Aufgefallen ist es nicht durch
Nachdenken, sondern weil der Betreiber gefragt hat, was sich änderte, wenn der
Fernzugriff doch gebaut würde, und daraufhin nachgemessen wurde. **Die Lehre ist
nicht „besser nachdenken", sondern: Eine Begründung, die eine Stufe verkleinert,
gehört genauso gemessen wie eine, die sie vergrössert.** Bisher galt der
Massstab nur für Zusagen.

**Was die Nachmessung dafür an echtem Risiko gefunden hat**, ist die
unangenehmste Bauart von Fehler, die dieses Projekt kennt — einer mit einer
Wartungsfrist zwischen Ursache und Wirkung:

| | |
|---|---|
| kaputte `pg_hba.conf` + Reload | Server bedient weiter, alte Regeln bleiben, `pg_hba_file_rules` nennt den Fehler mit Zeilennummer |
| kaputte `pg_hba.conf` + Neustart | `FATAL: could not load pg_hba.conf` — **der Cluster kommt nicht hoch** |

Eine falsche Zeile ist beim Schreiben unsichtbar und wochenlang folgenlos; sie
zündet beim nächsten Paketupdate oder Reboot, und dann sind alle Kunden ohne
Datenbank wegen einer Datei aus dem letzten Monat. `pg.remote.access` schreibt
deshalb, reloadet, liest `pg_hba_file_rules` auf Fehler — **und rollt die Datei
zurück, statt zu melden.** Eine Operation, die eine kaputte Datei liegenlässt
und darüber berichtet, hat den Server scharf gemacht und ein Protokoll
geschrieben. Der Wächter dazu steht in `docs/38 §18` und kommt mit Schritt 10;
er fährt gegen einen echten Cluster, weil es in diesem Container einen gibt.

*Hier stand sein Name in Rückstrichen, und das hat die CI rot gemacht:*
`ChangelogTest::test_every_named_test_exists` besteht darauf, dass jeder so
genannte Test existiert — und dieser entsteht erst mit dem Code, den er prüft.
Der Changelog hält fest, was geschehen ist; ein Plan hält fest, was geschehen
soll, und dort gehört der Name hin. Gefunden hat es der Wächter selbst, einen
Commit später.

Dass dieser Rückweg überhaupt möglich ist, ist Glück und gehört benannt: Der
Reload ist gnädig, wo der Neustart es nicht ist. Es ist die Umkehrung der Lehre
aus `docs/37 §6` — hier ist der gelesene Zustand nicht nur ehrlicher als die
geschriebene Zeile, er ist die einzige Gelegenheit, den Fehler zu sehen, bevor
er wirkt. Und weil ein Betreiber diese Datei auch von Hand ändert, liest
`srvpanel db` sie bei **jedem** Lauf mit und meldet auch Fehler, die nicht von
uns stammen.

**Und eine Stelle, an der ein Datenmodell aus P5 wirklich bricht** — die erste,
und `docs/37 §4` hatte sie als „die teuerste Zeile" der Übergabetabelle
angekündigt. In MariaDB sind `'p1001_web'@'localhost'` und
`'p1001_web'@'203.0.113.5'` zwei Benutzer mit zwei Passwörtern; deshalb ist
`(name, host)` eindeutig und richtig. In PostgreSQL ist es eine Rolle, ein
Passwort und mehrere erlaubte Netze. Zwei Zeilen mit demselben Namen wären dort
nicht zwei Zugänge, sondern zwei Regeln für einen — und `pg.role.create` liefe
zweimal und setzte ein zweites Passwort auf dieselbe Rolle. Die Netze bekommen
deshalb eine eigene Tabelle; `db_users.host` bleibt stehen, weil die Spalte für
MariaDB die Wahrheit sagt.

Die Folge davon steht nicht im Code, sondern auf der Seite: **Ein
PostgreSQL-Fernzugang hat kein eigenes Passwort.** Wer die Zugangsdaten
verliert, verliert ihn für jedes erlaubte Netz. Ein Kunde, der P5 kennt, nimmt
das Gegenteil an.

**Die Zeile nennt die Datenbank und nicht `all`** — gemessen: Damit kommt die
Rolle in ihre Datenbank und in `postgres` nicht. Das ist eine zweite Wand hinter
dem `REVOKE CONNECT` und kostet eine Zeile je Datenbank × Rolle × Netz.
`docs/20 §15` Punkt 5c ist damit erledigt, statt bis 1.0 offen zu bleiben.

### P5b Schritt 0 — `DocLinkTest`: die Wächter dürfen `docs/` nicht auslassen

**Ein Verweis in `docs/20` zeigte auf `37-postgresql.md`; die Datei heisst
`37-uebergabe-an-p5b.md`.** Gefunden beim Planen von P5b, nicht von einem Test
— obwohl es `ChangelogTest::test_every_referenced_document_exists` seit P4 gibt.
Der sieht in den `CHANGELOG` und nirgendwo sonst, und er prüft die **Nummer**
über ein Glob statt den Dateinamen. Beide Einschränkungen zusammen ergeben genau
die Lücke, durch die der Verweis gefallen ist: Er stand in einem Dokument statt
im Changelog, und er nannte einen Dateinamen statt einer Nummer.

Das ist die zweite Lehre über Wächter aus `docs/37 §6` eine Ebene weiter. Dort
steht, sie dürften `tests/` nicht auslassen, weil dort die zweite Fassung einer
Regel am häufigsten steht. Hier: **sie dürfen `docs/` nicht auslassen** — ein
Dokument enthält dieselben Zeichenketten wie Code, nur übersetzt sie niemand.

**Der Beleg, dass die Regel nötig war, kam sofort:** Derselbe Durchgang fand
einen zweiten Fall. `docs/19` verwies auf `14-bestaetigungen.md`, ein Dokument
des Vorgängers, das mit dem Repo-Übergang entfernt wurde — ein Verweis, der
einen Lizenzwechsel und einen Neuanfang überlebt hat, weil ihn nie jemand
geprüft hat. `DocLinkTest` prüft beide Schreibweisen: den Verweis mit Klammern
gegen den Dateinamen, und die blosse Nennung `docs/NN` gegen die Nummer. Den
`CHANGELOG` lässt er aus, weil `ChangelogTest` ihn hat; zwei Fassungen derselben
Regel sind der Fehler, gegen den dieses Projekt die meisten Wächter hat.

**Beide Brüche stehen im Skript, und `docs/` steht jetzt in seinen zwei
Listen.** Ohne diese Zeile wäre ein Bruch dort keine Probe, sondern eine
Änderung — dasselbe Argument, mit dem `routes/` und `database/` dazugekommen
sind. Gefahren am 9. August 2026, beide rot, danach wieder grün.

**Drei Funde beim Bauen des Wächters, und keiner davon vom Wächter:**

- **PHPStan hat einen toten Zweig gemeldet, und er hatte recht.** Der erste Wurf
  hatte eine leere Ausnahmeliste „für den Fall, dass ein Dokument einmal auf
  etwas zeigen muss, das es nicht gibt" — `isset()` auf `array{}`, also ein
  Zweig, der nie läuft. Es ist derselbe Nullfall, den `docs/36 §14` an
  `Feature::permission()` beschreibt: *in der falschen Richtung teurer als
  keiner*, weil er wie eine Erlaubnis aussieht, die schon jemand gebraucht hat.
  Die Liste ist wieder weg; wer den ersten echten Fall hat, legt sie mit ihm
  zusammen an. Bemerkenswert ist der Weg: `CLAUDE.md` nennt genau diesen Fehler
  als Beispiel dafür, dass ein PHPStan-Lauf über eine einzelne Datei sich lohnt
  — er hat sich beim nächsten Mal sofort wieder gelohnt.
- **`ChangelogTest` hat den Commit davor eingeholt — und diesen gleich noch
  einmal.** Der P5b-Eintrag nannte den Wächter über den Rückweg von
  `pg_hba.conf` (`docs/38 §18`) in Rückstrichen, und den gibt es erst mit
  Schritt 10: `test_every_named_test_exists` wäre in der CI rot gewesen. Beim
  Aufschreiben dieser Zeile ist derselbe Name ein zweites Mal in Rückstrichen
  gelandet, und der Wächter hat auch das gemeldet — im Abstand von zwei Minuten,
  in einem Absatz, der von genau diesem Fehler handelt. Der Changelog hält fest,
  was geschehen ist; was geschehen soll, steht im Plan, und dort gehört der Name
  hin.
- **Und die Warnung aus `CLAUDE.md` über `git checkout` hat sich bestätigt**,
  beim Gegenprüfen der Untergrenze: Der Bruch änderte die Testdatei selbst, und
  die war noch nicht eingecheckt — `git checkout` stellt nur wieder her, was git
  kennt, und hat sie nicht zurückgebracht, sondern gar nichts getan. Genau
  deshalb verweigert `waechter-brechen.sh` den Start bei ungesicherten
  Änderungen, und genau deshalb kommt ein Bruch erst nach dem Commit dazu, den
  er prüft.

### P5b Schritt 1 — die Bausteine, gegen einen echten PostgreSQL geprüft

`Pg\Names`, `Pg\Sql` und `Pg\Shielding`, dazu `PgNameTest` und
`PgShieldingTest`. **Und zum ersten Mal in diesem Projekt sind die erzeugten
Anweisungen nicht nur als Text geprüft, sondern gefahren:** Dieser Container hat
ein PostgreSQL 16.13, und die Absperrung ist gegen einen Wegwerf-Cluster
gelaufen, bevor eine Zeile in die CI ging. Für MariaDB musste jede solche
Behauptung eine Textprüfung bleiben (`docs/36 §3.1`).

**Der Name ist die Abschottung.** Das Präfix ist nicht mehr der Systembenutzer,
sondern sechzehn Hexziffern aus `random_bytes`, je Abonnement einmal vergeben —
denn PostgreSQL zeigt jedem Kunden die Namen aller Datenbanken, und
`p1002_shop` verriete damit, dass es ein Abonnement 1002 mit einem Shop gibt.
Der Wächter dafür prüft die **Form statt des Ergebnisses**: `newPrefix()` nimmt
keinen Parameter, und was keinen Wert bekommt, kann keinen verraten. Ein Test,
der nur nachsähe, dass keine Abonnementnummer im Präfix *vorkommt*, wäre grün,
sobald jemand sie durch eine Prüfsumme schickt.

**Ein Präfix bleibt es trotzdem**, und das ist kein Rest aus P5: `belongsTo()`
prüft im Agenten, ob ein Name zu dem Abonnement gehört, in dessen Auftrag die
Operation läuft. Ein durchweg zufälliger Name nähme dem Agenten diese Prüfung
ersatzlos, weil er keinen Zustand führt, aus dem er sie rekonstruieren könnte.

**Vier Eigenschaften von PostgreSQL wurden gemessen statt übernommen**, und eine
davon hebt den teuersten Fund von P5 auf:

- **Die Unterstrich-Falle gibt es hier nicht.** `docs/36 §3.1` musste in MariaDB
  `p1001\_shop` maskieren, weil das Ziel eines `GRANT` dort ein **Muster** ist und
  `p1001_%` auch `p10012_shop` trifft. In PostgreSQL ist es ein Bezeichner:
  Gemessen gibt `GRANT CONNECT ON DATABASE m29_a` Zugang zu `m29_a` und **nicht**
  zu `m29xa`. `Pg\Sql` hat deshalb kein `grantTarget()` — und das ist ein Befund
  und keine Auslassung. Wer eine Maskierung nachbaut, die es nicht braucht, baut
  eine Regel, die niemand mehr erklären kann.
- **Der Backslash maskiert nicht** (`standard_conforming_strings = on`). Zu
  verdoppeln ist nur das Anführungszeichen. Die Regel aus `Db\Sql` zu übernehmen
  ergäbe Passwörter mit einem Backslash zu viel.
- **Ein zu langer Bezeichner wird abgeschnitten und nicht abgewiesen** (63
  Zeichen). Zwei Namen, die sich erst danach unterscheiden, wären hinterher
  derselbe.
- **Die Absperrung wirkt je Datenbank.** Gefahren gegen eine Datenbank aus
  `TEMPLATE template0` — also im Fallenfall: vorher sah die Kundenrolle acht
  Datenbanken in `pg_stat_database`, danach `permission denied`, `pg_database`
  blieb offen, Arbeiten ging weiter, und `pg_dump` des Kunden lief.

**Die Kanalliste wird erfragt und nicht verdrahtet**, und der Wächter darüber
hat beim ersten Lauf zugebissen — auf den Klassenkopf, der `pg_stat_database` als
Beispiel nennt, um die Regel zu erklären. Ein Wächter, der verbietet, seine
eigene Regel zu erklären, wird umformuliert statt befolgt; die Frage beantwortet
jetzt `WithoutPhpComments` über `token_get_all()`.

**Zwei Funde, die kein Wächter gefunden hat:**

- **PHPStan hat wieder einen toten Zweig gemeldet**, diesmal ein
  `method_exists()` auf eine Methode, die es nicht gibt — zur Übersetzungszeit
  entscheidbar, also `function.impossibleType` und in der CI rot. Eine Behauptung
  über den Bestand einer Klasse muss zur Laufzeit gestellt werden, sonst ist sie
  kein Test, sondern ein Ausdruck. Reflection beantwortet sie.
- **Die Warnung über `git checkout` bei nicht eingecheckten Dateien hat zum
  dritten Mal an einem Tag zugeschlagen** — beim Gegenprüfen der drei neuen
  Brüche stand `agent/src/Pg/` noch nicht unter Versionskontrolle, und der
  Rückweg holte nichts zurück. Alle drei Wächter waren rot, wie sie sollten; die
  Dateien mussten von Hand zurückgedreht werden. Genau deshalb verweigert
  `waechter-brechen.sh` den Start bei ungesicherten Änderungen, und genau deshalb
  gehört ein Bruch erst nach dem Commit dazu, den er prüft.

### P5b Schritt 1 — der Agent meldet sich an, und §6 fiel dabei als Dritter

`Pg\Session`, `Pg\Server`, `pg.server.info`, dazu `AgentIdentityTest` und
`PgSessionTest`. Die Positivliste wächst um `psql`, `pg_dump` und `pg_restore`
— **und um keine Vollmacht.**

**`docs/38 §6` sah zuerst vor, dass `Runner` ein Feld „läuft als" bekommt.**
PostgreSQL bildet Unix-Kennungen auf Rollen ab, und root ist keine: Als root
scheitert `psql -U postgres` an `Peer authentication failed`. Der Plan nannte
dafür eine Bauform, ohne sie zu messen — und das ist im selben Dokument zum
dritten Mal derselbe Fehler, nach `pg_database` in §3 und dem Include-Punkt in
§14. **Ein Plan, der eine Bauform nennt, hat sie noch nicht gemessen.**

Gemessen wurde sie dann, und sie trägt nicht:

- `proc_open` — die einzige Stelle, an der der Agent ein Programm startet —
  kennt keine Option für eine fremde Kennung.
- `pcntl_fork` mit `posix_setuid` und `pcntl_exec` **läuft**, und die Umleitung
  der Dateinummern ist trotzdem unzuverlässig: Die Ausgabe von `psql` landete in
  der Datei für stderr, bei Rückgabewert 0 — während dieselbe Reihenfolge in
  einem isolierten Fall stimmte. Sie hängt davon ab, was der Prozess sonst offen
  hat. *Was Erfolg meldet und die Daten woanders ablegt* ist die Sorte Fehler,
  gegen die dieses Projekt seine Wächter baut, und sie stünde hier in der
  Klasse, durch die jede vorhandene Operation läuft.
- Der geforkte Prozess **erbt den Socket des Agenten**.

**Gebraucht wird von alledem nichts.** Debians `pg_hba.conf` enthält
`local all all peer`, und peer bildet die Unix-Kennung auf die *gleichnamige*
Rolle ab: Gibt es eine PostgreSQL-Rolle `root`, kommt der Agent als Superuser
durch — keine Datei wird angefasst, kein Programm wechselt die Kennung, kein
Passwort liegt irgendwo. Angelegt wird sie vom **Betreiber**, einmal, mit einem
Befehl, den das Panel anzeigt. Dieselbe Form wie `srvpanel db --remote=on`: Eine
Übergabe, die den Server verändert, ist eine Handlung des Betreibers.

**Daraus ein Zustand, den P5 nicht kennt.** Zwischen „läuft nicht" und „nutzbar"
liegt „läuft, aber noch nicht übergeben" — der Dienst antwortet, und der Agent
kommt trotzdem nicht hinein. Für den Betreiber sind das zwei verschiedene
Handgriffe, und ein Panel, das ihm beide Male dasselbe sagt, hilft bei keinem.
`Pg\Server` unterscheidet sie an der Meldung von `psql` und meldet beides als
**Auskunft und nicht als Fehlschlag** — dieselbe Entscheidung wie bei
„MariaDB läuft nicht".

**Der Wächter über den angezeigten Befehl** hält `Session::ROLE` gegen
`Server::HANDOVER`: Laufen die auseinander, legt der Betreiber eine Rolle an,
die niemand benutzt, und das Panel sagt ihm weiter, PostgreSQL sei nicht
übergeben. Ein abgedruckter Befehl, der ins Leere geht, hat `docs/36 §22.3v`
schon einmal gekostet.

**Und der Unterschied, der P5b beinahe still gekostet hätte, ist jetzt an
beiden Enden festgenagelt.** Nebeneinander gemessen, dieselbe Anweisungsfolge:
mit `ON_ERROR_STOP=1` bricht der Lauf ab und die dritte Anweisung läuft nicht
mehr; ohne den Schalter gibt `psql` **0** zurück, meldet Erfolg und führt die
vierte Anweisung trotzdem aus. `mysql` bricht von selbst ab — deshalb ist das
eine Regel, die man nur dort lernt, wo sie gebrochen wird. Sie steht als
Argument im Aufruf und nicht als Konstante daneben, und `PgSessionTest` prüft
beides: dass der Wert dasteht **und** dass er in den Aufruf geht.

**`git checkout` hat zum vierten Mal an einem Tag zugeschlagen, diesmal von der
anderen Seite.** Bisher traf es nicht eingecheckte Dateien, die es nicht
zurückholte; hier holte es die eingecheckte Fassung von `Runner.php` zurück und
nahm die noch nicht gesicherte Ergänzung der Positivliste mit. Beide Richtungen
haben dieselbe Ursache und dieselbe Regel: **Ein Bruch gehört erst nach dem
Commit dazu, den er prüft.**

### Die CI hat eine Zeile gefunden, die der Plan verlangt hatte

**Lauf 446: 1537 grün, einer rot** — und der eine ist ein echter Fund.
`docs/38 §17` führte `agent/src/Registry.php` in Schritt 1 auf, also wurde
`pg.server.info` dort eingetragen, mit einer Begründung in
`AgentOperationReachTest::WITHOUT_LIFECYCLE`. Der Wächter ist strenger als
angenommen: Er verlangt zu einer Operation ohne Lebenslauf einen **Aufrufer**
und nicht nur einen Grund. *„Code, der als root läuft und zu dem kein Weg führt,
ist Angriffsfläche ohne Nutzen."*

Die Regel ist älter als dieser Plan und wiegt schwerer als seine Dateiliste —
also ist der Plan nachgezogen worden und nicht der Wächter: **Eine Operation
wird in demselben Beitrag eingetragen, der ihr einen Aufrufer gibt.** Die
Klassen liegen bis dahin da und sind aus dem Agenten nicht erreichbar, und das
ist der richtige Zustand.

Bemerkenswert ist, wo der Fehler saass. Er stand in `docs/38 §17`, seit der Plan
geschrieben wurde, und hat den ganzen Weg von `docs/36 §15` mitgenommen — dort
steht `Registry.php` in Schritt 1 genauso. In P5 ist es nur deshalb nicht
aufgefallen, weil dessen Schritt 1 bis 3 in einem Zug gebaut wurden und der
Aufrufer damit im selben Lauf entstand. **Eine Reihenfolge, die zufällig
funktioniert, funktioniert bis zu dem Tag, an dem jemand sie einzeln geht.**

### P5b Schritt 1 abgeschlossen — sechs Operationen, und drei Unterschiede zu P5

`pg.database.create`/`remove`, `pg.role.create`/`remove`/`grant`/`lock`, dazu
`PgGrantTest`. `remove` steht in jedem Paar zuerst — die Mechanik aus
`docs/36 §2`, aus der die Zertifikatslücke von `docs/35` entstanden ist.

**Alle sechs sind gegen einen echten Cluster gefahren**, mit zwei Abonnements,
und die Kriterien 1 bis 4 aus `docs/38 §3` sind dabei belegt: dreizehn Kanäle
abgesperrt, `pg_stat_database` verschlossen, die sichtbaren Namen verraten
nichts, die fremde Datenbank abgewiesen — und der Kunde bekommt weder
`GRANT CONNECT … TO PUBLIC` noch `DROP DATABASE` auf seine eigene durch.

**Drei Stellen, an denen PostgreSQL sich anders verhält als MariaDB, jede
gemessen:**

- **`DROP DATABASE` scheitert an einer offenen Verbindung.** *ERROR: database
  „probe" is being accessed by other users.* MariaDB kennt das nicht; dort wirft
  ein `DROP` das Schema unter jeder laufenden Anwendung weg. Ohne `WITH (FORCE)`
  würde hier **jeder Rückbau an einem Kunden scheitern, dessen Anwendung einen
  Verbindungspool offen hält** — also am Normalfall. Ein Rückbau, der davon
  abhängt, ob gerade jemand verbunden ist, ist keiner.
- **`DROP ROLE` verweigert, solange die Rolle irgendwo Rechte hat**, und
  aufgeräumt wird das mit `DROP OWNED BY` — **je Datenbank.** Das ist die exakte
  Umkehrung von P5: Dort lässt `DROP USER` seine Rechte in `mysql.db` stehen,
  und `docs/36 §22.3p` hat auf `cloudsrv24` genau so eine Zeile für ein Schema
  gefunden, das es nicht mehr gab. PostgreSQL ist unbequemer und ehrlicher.
  Welche Datenbanken aufzuräumen sind, sagt die Anwendung — der Agent führt
  keinen Bestand. **Und der Rückfall ist sicher:** Vergisst sie eine, scheitert
  `DROP ROLE` mit einer Meldung, die sie beim Namen nennt. Beide Zweige sind
  gefahren.
- **`GRANT ALL ON DATABASE` reicht nicht.** In PostgreSQL sind Verbindung,
  Schema und Objekte drei Ebenen, und die Rechte auf der Datenbank sind die
  schwächste. Wer nur sie vergibt, hat einen Kunden, der sich verbindet und
  nichts tun kann. Dazu kommt `ALTER DEFAULT PRIVILEGES`, das es in MariaDB
  nicht gibt: Dort gilt ein Schemarecht für alles, was im Schema entsteht; hier
  gehört jede Tabelle dem, der sie angelegt hat — ohne diese Zeile sähe ein
  zweiter Zugang desselben Abonnements die Tabellen des ersten nicht. Genau das
  ist der Grund, aus dem `docs/36 §14` verlangt hat, die Isolationszusage neu zu
  **beweisen** statt zu übertragen.

**Und eine, die still geblieben wäre:** PostgreSQL kennt `IF EXISTS` für
`DROP ROLE` und `DROP DATABASE`, aber **nicht für `ALTER ROLE`, `REVOKE` und
`DROP OWNED BY`** — dort ist eine fehlende Rolle ein Fehler. `docs/36 §6` löst
dasselbe Problem mit `ALTER USER IF EXISTS` und schreibt dazu den Satz, auf den
es ankommt: *Die Sperre ist wichtiger als die Vollständigkeit der Buchführung.*
Hier muss der Code das selbst einlösen: `pg.role.lock` fragt, welche der
genannten Rollen es gibt, sperrt die vorhandenen und **meldet die fehlenden** —
denn eine Sperre, die eine Rolle übergeht, ohne es zu sagen, sieht aus wie eine
vollständige. Im Lauf gegen den Server: eine gesperrt, eine als fehlend
gemeldet.

### P5b Schritt 2 — `engine` als Aufzählung, und ein Präfix, das nie zurückkommt

Die Migration, `App\Enums\DatabaseEngine` und `DatabaseEngineTest`. Dazu die
zwei Korrekturen aus dem Messlauf auf `cloudsrv24` (`docs/38 §2.2c`).

**Eine Aufzählung von Anfang an, und das ist die Lehre aus `DumpKind`.** Dort
war der Wert bis zum 9. August eine nackte Spalte, deren Zeichenketten an vier
Stellen verstreut standen — bis eine im Vue-Template landete, also über eine
Grenze, die kein Typ prüft. `engine` wäre der nächste Kandidat gewesen: drei
Tabellen, jede Verzweigung zwischen `db.*` und `pg.*`, und eine Marke in der
Oberfläche.

**Der Wächter hat beim ersten Trockenlauf zugebissen**, und zwar auf genau die
Bauart, gegen die er entstand: `Settings/Database.vue` verglich
`server.flavour === 'mariadb'` — ein Wert des Agenten, im Browser verglichen.
Hinüber geht jetzt der fertige Text aus `DatabaseSettingsController::flavourLabel()`.
Das Wort `mariadb` heisst in diesem Repo damit dreierlei: das System einer
Kundendatenbank (die neue Aufzählung), der Verbindungstreiber des Panels
selbst, und was `db.server.info` aus `@@version` gelesen hat. Die Ausnahmeliste
nennt jede der drei Stellen mit ihrem Grund — und deckt seit dieser Änderung
die **Übersetzung** statt des Vergleichs.

**`db_prefix` wird bei `claim()` mitvergeben und nicht beim ersten Gebrauch.**
Es hat dieselbe Eigenschaft wie die Nummer des Systembenutzers: Es kommt nie
zurück. Ein Präfix, das erst entsteht, wenn jemand die erste
PostgreSQL-Datenbank anlegt, bräuchte einen zweiten Weg — abgesichert gegen
zwei gleichzeitige Anlagen, die beide dasselbe leere Feld sehen. So deckt der
eindeutige Index beide Spalten, und die Wiederholungsschleife, die es für die
Nummer schon gibt, deckt auch die Kollision hier.

**Und es wird nicht hochgezählt, sondern gewürfelt.** `p1002_shop` sagt jedem
Kunden des Servers, dass es ein Abonnement 1002 gibt — und in PostgreSQL sind
die Namen aller Datenbanken für jeden lesbar (gemessen). Eine fortlaufende Zahl
in anderer Schreibweise wäre dieselbe Auskunft mit einem Umweg.

**`db_user_networks` ist die eine Stelle, an der das Datenmodell von P5 bricht**
— `docs/37 §4` hat sie als „die teuerste Zeile" der Übergabetabelle
angekündigt, und sie ist es geworden. In MariaDB sind
`'p1001_web'@'localhost'` und `'p1001_web'@'203.0.113.5'` zwei Benutzer mit zwei
Passwörtern, weshalb `(name, host)` dort eindeutig und richtig ist; in
PostgreSQL ist es eine Rolle mit einem Passwort und mehreren erlaubten Netzen.

**Zwei Korrekturen aus dem Messlauf.** `cloudsrv24` hat kein PostgreSQL — kein
`psql`, kein Cluster, kein Benutzer `postgres` —, und der Kandidat von `apt`
ist `16+257build1.1` — die Nummer des Metapakets.
**Die Messungen dieses Plans sind für den Zielserver damit keine Näherung,
sondern dieselbe Konfiguration.** Und in `Pg\Server` stand, welche Fassung jede
der vier Zielplattformen liefert — gemessen war davon eine. Die drei anderen
Zahlen sind aus dem Gedächtnis geschrieben gewesen und stehen nicht mehr da;
was zählt, ist die Grenze selbst. Für die Abnahme ist das folgenlos, weil sie
auf `cloudsrv24` läuft; für die Freigabe steht es als offener Punkt.

### Zwei Fehler in Schritt 2, und beide sagen etwas über das Vorgehen

**Lauf 449: 1547 grün, zwei rot.**

**Der erste war ein Dokumentationsblock, der die Klasse gewechselt hat.**
`DatabaseSettingsController::server()` trägt seine Rückgabeform als
`@return array{…}` — und die neue Methode `flavourLabel()` ist zwischen den
Block und die Methode geraten, die er beschreibt. PHP stört das nicht, PHPStan
schon: *„return type has no value type specified in iterable type array."*

`CLAUDE.md` kennt diese Meldung, aber für einen anderen Anlass — einen
einzeiligen Block, in dem `@return` zu Fliesstext wird. **Das ist derselbe
Fehler eine Ebene höher:** Ein Dokumentationsblock gehört zu dem, was
*unmittelbar* auf ihn folgt, und wer etwas dazwischenschiebt, nimmt ihn dem
Eigentümer weg, ohne dass etwas fehlt.

**Der zweite ist der Wächter über das Werkzeug, und er hat funktioniert.**
`claim()` bekam eine Zeile — `'db_prefix' => Names::newPrefix()` —, und der
Eingriff in `waechter-brechen.sh`, der `claim()` bricht, suchte den Block noch
ohne sie. `BreakScriptTest` hat es gemeldet: *Ein Eingriff, der nichts ändert,
prüft nichts — und sieht dabei aus, als wäre die Regel abgesichert.* Genau der
Fall, für den er in P5 entstand, nur diesmal beim Hinzufügen statt beim Umziehen.

**Und der Vorwurf dazu gehört hierhin:** Das Werkzeug, das ihn lokal gefunden
hätte, lag bereit und wurde nicht benutzt. `BreakScriptTest` ist ohne `vendor/`
über eine Attrappe fahrbar, und in dieser Sitzung ist er sechsmal gelaufen — nur
nicht nach der letzten Änderung an `app/`. *Ein Wächter, den man am Ende nicht
noch einmal fragt, ist eine Runde CI.*

### `Pg\Server` konnte „nicht installiert" nicht von „läuft nicht" unterscheiden

**Der Fehler stand seit Schritt 1 im Repo, und gefunden hat ihn eine Frage des
Betreibers.** Auf „was tut die Installation, wenn schon ein Cluster da ist?"
folgte eine Messung — und die erste Zeile davon war: Bei einem installierten,
aber gestoppten PostgreSQL **fehlt `/var/run/postgresql` genauso wie bei einem
nicht installierten.** Genau daran hat `describe()` die beiden auseinandergehalten.

Zwei verschiedene Handgriffe des Betreibers — installieren oder starten —
hätten dieselbe Meldung bekommen. Das ist Lehre 3 aus `docs/37 §6`, und
besonders unangenehm ist, wo sie zugeschlagen hat: in dem Abschnitt, der als
Fortschritt gegenüber P5 aufgeschrieben war („ein Zustand, den P5 nicht kennt").
**Ein Zustand, den man benennt, ist noch keiner, den man messen kann.**

**Gefragt wird jetzt `pg_lsclusters`**, bevor irgendetwas verbunden wird —
Debians eigenes Werkzeug beantwortet in einem Aufruf, was sonst vier Fragen
wären: installiert? wie viele? läuft er? welcher Port? Sich das aus
`/etc/postgresql` zusammenzusuchen wäre eine zweite Fassung dieses Werkzeugs.
Und es ist zugleich der Fühler: Fehlt das Programm, ist PostgreSQL nicht
installiert — eine Prüfung auf eine Datei wäre wieder dieselbe Frage zweimal.

Daraus sieben Zustände statt zweier, jeder mit **genau einem** Handgriff:
`absent`, `no_cluster`, `stopped`, `ambiguous`, `not_handed_over`, `unusable`,
`ready`. Alle sieben sind gegen einen echten Cluster gefahren.

**Bei mehreren laufenden Clustern wählt das Panel nicht.** Das ist die eine
Stelle, an der Raten Kundendaten kostet — zwei Cluster heissen fast immer, dass
der Betreiber einen davon selbst betreibt. Gezählt werden dabei die
**laufenden** und nicht alle: `docs/20 §15` Punkt 4 hält für nginx dieselbe
feinere Fassung fest, weil auf manchen Systemen ein Dienst als Abhängigkeit
herumliegt, ohne je zu starten.

**Und das Gewählte wird gegen das Erreichte gehalten.** Der Cluster kommt aus
`pg_lsclusters`, das Datenverzeichnis aus der Verbindung selbst. Weichen sie ab,
hat `psql` mit einem anderen geredet als dem, den wir gemeint haben — und das
steht dann da, statt drei Wochen später ein Rätsel zu sein. Es ist dieselbe
Bauart, die `docs/36 §22.3w` beim Fernzugriff gekostet hat: geschrieben das
eine, gewirkt das andere, zurückgelesen nichts.

**Zwei Programme mehr auf der Positivliste**, `pg_lsclusters` und
`pg_ctlcluster` — beide von `postgresql-common`, beide mit genau einer Aufgabe.
Nicht `systemctl` für den Start: Der Unitname hängt an Fassung und Clustername
(`postgresql@16-main.service`), und ihn aus zwei Werten zusammenzusetzen ist der
Vorgang, den eine Positivliste verhindert.

*Nebenbei, und nur der Vollständigkeit halber:* Beim ersten Wurf las
`Clusters::all()` das Feld 4 als Datenverzeichnis — das ist der Eigentümer.
Aufgefallen beim Lauf gegen das echte Werkzeug, nicht beim Lesen; ein Cluster
mit dem Datenverzeichnis `postgres` sieht in einer Ablage nicht falsch aus.

### P5b Schritt 3 — PostgreSQL installieren, und zwei Wächter für eine Fussnote

`pg.server.install` (`agent/src/Ops/PgServerInstall.php`), dazu `PgClusterTest`
und `PgServerStateTest`.

**Die Operation entscheidet nichts, was `describe()` nicht schon beantwortet
hat.** Jeder der sieben Zustände hat genau einen Handgriff: `absent` wird
installiert, `stopped` gestartet, `no_cluster` und `ambiguous` werden
**abgewiesen** — mit dem Befehl im Klartext beziehungsweise mit dem Grund —, und
die restlichen drei heissen „PostgreSQL ist da". Gefahren wurden am 9. August
vier davon gegen einen echten Cluster: `stopped` → gestartet und `ready`,
`ready` → `changed=false`, die Rolle `root` entzogen → `not_handed_over` **ohne
Fehlschlag**, ein zweiter Cluster daneben → abgewiesen, alle Cluster entfernt →
abgewiesen. Der fünfte Zustand ist `absent`, und ihn zu fahren hiesse hier, das
Paket wirklich zu installieren.

**Eine fehlende Übergabe ist kein Fehlschlag.** Nach der Installation läuft
PostgreSQL und die Rolle `root` gibt es nicht; der Agent kann sie nicht anlegen,
denn dafür bräuchte er genau die Verbindung, die ihm fehlt (`docs/38 §6.1`). Die
Operation meldet deshalb Erfolg **und** den Befehl, den der Betreiber ausführt.
Ihn zu verschweigen hiesse, nach einer geglückten Installation „fertig" zu
sagen, während nichts geht.

**`no_cluster` wird nicht repariert, und das ist eine Entscheidung.** Nach einer
frischen Installation gibt es immer einen Cluster — das `postinst` legt ihn an.
Keinen zu haben heisst also, dass jemand ihn entfernt hat, und einen neuen
danebenzustellen wäre keine Reparatur, sondern eine zweite Meinung.

**Und dann die Fussnote von gestern.** Der Eintrag darüber schliesst mit einem
*„nebenbei, und nur der Vollständigkeit halber"*: `Clusters::all()` las Feld 4
statt Feld 5, den Eigentümer statt des Datenverzeichnisses. Das war die falsche
Einordnung. Es war kein Nebenbei, sondern eine Regel ohne Wächter — und dass sie
gegen ein laufendes `pg_lsclusters` gefunden wurde, heisst nur, dass sie in der
CI unauffindbar war.

Die Auswertung ist deshalb aus dem Aufruf herausgezogen: `Clusters::parse()`
nimmt eine Zeichenkette, und `PgClusterTest` hält echte Zeilen dagegen —
gestoppt, zwei laufende, Kopfzeile, leere Ausgabe. Derselbe Zuschnitt, mit dem
P3 seine Vorlagen prüft: *Was gemessen werden soll, ist eine Eigenschaft der
Zeichenkette.* Der Wächter prüft ausdrücklich mit, dass dort **nicht**
`postgres` steht — das ist der Wert, den die falsche Zählung lieferte, und er
ist auf jedem System derselbe.

**Der zweite Wächter hält eine Aufzählung zusammen, die es in drei Fassungen
gibt.** Die sieben Zustandsnamen sind blosse Zeichenketten: `describe()` erzeugt
sie, `PgServerInstall` verzweigt über sie, das Panel wird es gleich auch tun.
Das ist wortwörtlich das Muster aus `CLAUDE.md`. Der Fehlschlag, um den es geht,
ist nicht der Tippfehler — es ist der **achte** Zustand: Wer `describe()`
erweitert und den Installierer vergisst, bekommt dort stillschweigend den
`default`-Zweig, und der heisst „es ist nichts zu tun". Ein Server, der genau
dann nicht läuft, meldete Erfolg.

**Was PHPStan an einem der neuen Tests gefunden hat, gehört ebenfalls
hierhin.** `test_only_online_counts_as_running` fuhr über `down`,
`online,recovery` und `starting` — und prüfte damit ausschliesslich die
Nein-Seite. Jeder Vergleich war falsch, jede Behauptung ging durch; eine
Auswertung, die *nie* etwas als laufend liest, wäre grün geblieben. *Ein
Wächter, der nur die Ablehnung prüft, prüft die Regel nicht.* Gefunden hat es
`function.impossibleType` und nicht der Lauf — genau der tote Zweig, den
`CLAUDE.md` in diesem Container als unauffindbar beschreibt, und ein Beleg
dafür, dass der Einzeldateilauf über PHPStan die Runde wert ist.

**`pg.server.install` steht noch in keiner Registratur.** Sie kommt mit dem
Knopf, der sie auslöst — die Regel aus Lauf 446: *Eine Operation wird in
demselben Beitrag eingetragen, der ihr einen Aufrufer gibt.*

### P5b Schritt 3 abgeschlossen — der Knopf, der Schalter, und zwei Zeilen des Plans, die beim Bauen fielen

`pg.server.info` und `pg.server.install` stehen in der Registratur,
`Task::PostgresInstall` im Aufgabenkatalog, der Knopf in „Einstellungen →
Datenbankserver", `srvpanel db --postgresql=on|off` auf der Kommandozeile.

**Zwei Dinge, und sie sind ausdrücklich getrennt.** Der Schalter sagt, ob das
Panel PostgreSQL *anbietet*; der Knopf *installiert*. Das ist keine Symmetrie
um ihrer selbst willen: Ein Server kann ein PostgreSQL tragen, das dem
Betreiber gehört — für sein eigenes Zeug, mit seinen eigenen Rollen. Eine
Kundenfläche, die von selbst aufgeht, sobald `pg_lsclusters` etwas findet,
schriebe die erste Kundendatenbank in einen Cluster, den niemand dafür
vorgesehen hat. Der Grundzustand ist deshalb „nein", auch nach einem Update.

**Warum das Installieren ein Knopf sein darf und der Fernzugriff nicht.**
`DatabaseSettingsController` trägt seit P5 den Satz, dass hier kein Schalter
steht, und die Begründung ist nicht die Reichweite, sondern der Neustart:
`db.remote.access` startet den Datenbankserver neu, und der trägt auch das
Panel — die Anfrage, die den Vorgang anstösst, verlöre ihre Verbindung mitten
im Lauf. `apt-get install postgresql` fasst MariaDB nicht an. Der Unterschied
war schon aufgeschrieben; er musste nur gelesen werden.

**`ACTIONABLE` steht im Agenten und nicht in der Oberfläche.** Der Knopf
erscheint in `absent` und `stopped` — den beiden Zuständen, in denen die
Operation etwas tut. Nicht in `no_cluster` und `ambiguous`, wo sie abweist
(ein Knopf, dessen einzige Wirkung eine Fehlermeldung ist), und nicht in
`ready`, `not_handed_over` und `unusable`, wo PostgreSQL da ist (ein Knopf, der
„installieren" heisst und nichts tut). `CLAUDE.md` verlangt genau das: *Wer eine
Aktion zeigt, fragt vorher dieselbe Stelle, die sie später abweist.*

### Und zwei Zeilen des Plans, die beim Bauen nicht getragen haben

**`docs/38 §7` verlangte `postgresql.service` in `ServiceAction::ALLOWED_UNITS`
— mit der Begründung, der Agent könne den Dienst sonst nicht starten. Er
startet ihn mit `pg_ctlcluster`** (Entscheidung 9, zwei Tage jünger als der
Abschnitt) **und lädt in §14 mit `SELECT pg_reload_conf()`.** `systemctl` kommt
in keinem der beiden Wege vor, und `service.action` wird im ganzen Panel von
zwei Stellen gerufen — `webserver.reload` und `srvpanel setup`.

Der Eintrag entfällt. Ein Allowlist-Eintrag ohne Aufrufer ist nicht Vorsorge,
sondern wortwörtlich das, was `AgentOperationReachTest` verbietet: *Code, der
als root läuft und zu dem kein Weg führt, ist Angriffsfläche ohne Nutzen.* Und
das ist die allgemeinere Lehre daraus: **Eine Begründung altert mit dem, was
sie begründet.** Sie stand im Plan, sie war beim Schreiben richtig, und die
Entscheidung, die sie umwarf, steht drei Abschnitte weiter unten im selben
Dokument.

In der Unitliste der Übersicht steht `postgresql.service` dagegen sehr wohl —
dort geht es ums Sehen, und `service.status` führt keine Positivliste. **Die
Zeile erscheint nur, wenn es die Unit gibt.** Ein dauerhaftes „nicht vorhanden"
auf jedem Server ohne PostgreSQL wäre Rauschen an genau der Stelle, an der man
Störungen sucht — und Rauschen dort ist der Grund, warum irgendwann niemand
mehr hinsieht.

**`suggests: postgresql` in `nfpm.yaml` bekommt seine Begründung und bleibt.**
Bis P5b stand die Zeile ohne Kommentar da und ohne dass etwas dahinterhing — das
einzige Vorkommen des Wortes im ganzen Quelltext, in einer Datei, deren übrige
Zeilen jede einzeln begründet sind. Sie las sich wie eine Abhängigkeit und war
eine Absichtserklärung. Jetzt hängt `pg.server.install` dahinter, und `Suggests`
sagt weiterhin das Zutreffende: nützlich, nicht nötig.

### Ein `version()`, das die Seite gesprengt hätte

**`Pg\Server::describe()` meldete die volle Ausgabe von `version()`** —
„PostgreSQL 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1) on x86_64-pc-linux-gnu,
compiled by gcc (Ubuntu 13.3.0-6ubuntu2~24.04.1) 13.3.0, 64-bit", 130 Zeichen —
und die wären als Kennung in eine Wertzelle der Oberfläche gegangen. Das ist
Zeichen für Zeichen die Bauart, die `docs/20 §15` bezahlt hat: eine Kennung im
Fliesstext, die die Seite um 83px aus dem Bildschirm schob, vollständig grün
getestet und ausgeliefert.

Gemeldet wird jetzt `current_setting('server_version')` — 37 Zeichen, dieselbe
Auskunft, soweit sie jemanden vor einem Panel angeht. Der Compiler und die
Architektur beantworten dort keine Frage.

**Gefunden hat es die Aufnahme und nicht der Test**, und zwar auf dem Weg, den
`CLAUDE.md` für den Fall beschreibt, dass `artisan serve` nicht läuft: das
gebaute Stylesheet aus `public/build`, das Markup des Bausteins in einer
eigenen HTML-Datei, gerendert im vorinstallierten Chromium bei 390px,
`scrollWidth - clientWidth` als Text auf der Seite. Vier Läufe, beide Themes,
390px und 1280px — Überlauf überall 0.

**Der zweite Fund derselben Runde war ein Befehl im Fliesstext.** Bei 390px
brach `sudo srvpanel db --postgresql=off` mitten in der Option um, und `sudo -u
postgres psql -c "CREATE ROLE root SUPERUSER LOGIN"` mitten im Anführungszeichen
— beides in laufenden Sätzen, wo niemand mehr sieht, wo die Zeile anfängt und
wo sie aufhört. Beide stehen jetzt in einer Bezeichnungstabelle, jeder in
seiner eigenen Zelle. Dieselbe Entscheidung, die im Bereich „Umschalten"
darunter schon steht — sie war da und wurde beim Danebenbauen nicht gelesen.

### Zwei Wächter mehr, und ein offener Punkt, der vor der Abnahme entschieden gehört

`PgServerStateTest` hält jetzt auch `ACTIONABLE` gegen den Quelltext: Jeder
Name darin muss ein Zustand sein, den es gibt, **und** einer, für den
`execute()` wirklich etwas aufruft. Der Bruch dazu trägt `no_cluster` in die
Liste ein und prüft, dass es auffällt.

**`PhpVersions::EXTENSIONS` kennt `mysql` und nicht `pgsql`.** Ein Kunde, der in
diesem Panel eine PostgreSQL-Datenbank anlegt, bekommt sie — und seine Website
kann sich nicht damit verbinden. Das steht in `docs/38` nirgends und ist beim
Durchsehen der Paketbeziehungen für §7 aufgefallen. Es ist ausdrücklich **nicht**
mit dieser Änderung erledigt, und der Grund steht in `docs/38 §24.2`:
`PhpVersionInstall` kehrt bei einer installierten Version früh zurück, die neue
Erweiterung käme also auf keinem Server an, auf dem PHP schon liegt. Ob
`php.version.install` fehlende Erweiterungen nachinstallieren soll, ist eine
Änderung an P3 und gehört entschieden, nicht nebenbei gemacht.

### P5b Schritt 6b — Kunden-PHP kann PostgreSQL ansprechen, und eine Prüfung fragte einen Stellvertreter

`pgsql` in `PhpVersions::EXTENSIONS`, `dpkg-query` auf der Positivliste,
`php.version.install` läuft auf den Paketsatz zu statt auf den Handler,
„Ergänzen" in „Einstellungen → PHP-Versionen", `EngineExtensionTest` und
`PhpExtensionTest`. Vorgezogen vor Schritt 7 auf Entscheidung des Betreibers.

**Der Befund war eine Zeile, der Fehler ist eine Bauform.**
`php.version.install` fing so an:

    if (PhpVersions::installed($version)) {
        return ['already' => true, 'packages' => [], …];
    }

`installed()` ist `is_executable('/usr/sbin/php-fpm8.2')`. Die Operation
behauptet „der gewünschte Zustand ist schon da" und **prüft dabei einen
Stellvertreter**: den Handler statt des Paketsatzes. Solange `EXTENSIONS` sich
nie ändert, sind die beiden dasselbe — im Augenblick der ersten Änderung gehen
sie auseinander, und niemand merkt es, weil die Operation Erfolg meldet.

`pgsql` in die Liste zu schreiben ist ein Wort. Dass es auf einem Bestandsserver
ankommt, war die Änderung. Die Operation *konvergiert* jetzt: fragen was fehlt,
nur das installieren, denselben Satz zurücklesen. Fehlt nichts, ist sie in
Millisekunden fertig — und `already` heisst dann, was es sagt.

**Gefragt wird das Paketsystem und nicht `php-fpm -m`, und das ist gemessen.**
`php -m` in diesem Container:

    … mysqli mysqlnd … pdo_mysql pdo_pgsql … pgsql …

Kein Modul heisst `mysql`. Paketname und Modulnamen sind verschiedene Dinge; es
bräuchte je Eintrag eine Merkmalsangabe (`mysql → pdo_mysql`, `xml → dom`,
`fpm → gar keins`) — eine zweite Liste, die veraltet und die kein Test hier
prüfen kann. Dateien unter `mods-available/` haben dieselbe Lücke:
`php8.2-mysql` legt `mysqli.ini`, `mysqlnd.ini` und `pdo_mysql.ini` ab und keine
`mysql.ini`. **Die Frage ist eine Paketfrage, also antwortet dpkg.**

**Ein Rückgabewert, der ungelesen bleibt — mit Begründung an beiden Aufrufen.**
Gemessen:

    $ dpkg-query -W -f='${binary:Package} ${db:Status-Status}\n' a fehlt b
    a installed
    b installed                                    ← stdout, vollständig
    dpkg-query: no packages found matching fehlt   ← stderr
    rc=1

Der Code 1 heisst „eines davon kennt dpkg nicht" und nicht „der Aufruf ist
gescheitert" — er ist genau dann 1, wenn diese Frage etwas zu melden hat. Wer
ihn als Fehlschlag liest, bekommt eine Operation, die immer dann abbricht, wenn
sie etwas zu tun hätte. Die Auswertung steht als `PhpVersions::missing()` neben
dem Format, das sie liest, und ist ohne Server prüfbar — derselbe Schnitt wie
`Pg\Clusters::parse()` zwei Beiträge davor, und aus demselben Anlass.

**Gezählt wird `installed` und nicht „steht in der Ausgabe".** Ein Paket kann
`config-files` sein (entfernt, Konfiguration noch da) oder `half-installed`;
beides ist nicht benutzbar, und beides stünde in derselben Ausgabe.

### Der Neustart, den der Plan nicht hatte

**Ein laufender FPM lädt eine frisch installierte Erweiterung nicht von
selbst.** Das `postinst` der Distribution ruft `phpenmod` und stösst über einen
dpkg-Trigger einen Neustart an — *meistens*. „Meistens" ist in diesem Projekt
kein Zustand: Bleibt er aus, liegt `pgsql` auf der Platte, im Panel steht
„vollständig", und die Website des Kunden antwortet weiter *„could not find
driver"*. Ein Fehler, den niemand sucht, weil alles grün aussieht — und damit
genau dieselbe Klasse wie der, aus dem dieser Beitrag entstanden ist.

`php.version.install` startet die Unit deshalb ausdrücklich neu, **wenn sie
läuft**, und meldet es als `restarted`. Das kostet die Anfragen, die in diesem
Augenblick unterwegs sind; die Alternative kostet jede Anfrage danach. Die
Rückfrage im Panel sagt es vorher — wer es dort nicht liest, erfährt es aus dem
Fehlerprotokoll seiner Kunden. Läuft sie nicht, gilt die Regel von vorher: ohne
Pool bleibt sie stehen, mit einem wird sie gestartet.

### Der Wächter sitzt an der Aufzählung und nicht an der Liste

`DatabaseEngine::phpExtension()` nennt je System den Paketsuffix — `mariadb →
mysql`, `postgres → pgsql` —, und `EngineExtensionTest` hält ihn gegen
`PhpVersions::EXTENSIONS`. **Dieser Test wäre an dem Tag rot geworden, an dem
die Aufzählung entstand**, also drei Beiträge vor dem Fund, und er beisst
wieder, sobald ein drittes System dazukommt.

Der Name steht an der Aufzählung und nicht als weitere Zeile im Agenten, weil
das die Stelle ist, an der niemand vorbeikommt: Wer ein Datenbanksystem
hinzufügt, ändert `DatabaseEngine` — die Erweiterungsliste des Agenten sieht er
dabei nicht.

**Nicht in `srvpanel update`.** Ein Update, das von selbst `apt-get install`
fährt, kann an einer fremden Paketquelle scheitern und nimmt das Panel mit. Es
bleibt eine Handlung mit einem Vorgang, den man ansehen kann.

**Aufnahmen** wieder über das gebaute Stylesheet, beide Themes, 390px und
1280px, Überlauf 0 — und wieder mit einem Befund: `php8.2-pgsql` brach neben der
Marke als `php8.2-` / `pgsql` über zwei Zeilen. Die Oberfläche zeigt jetzt den
Suffix, denn die Versionsnummer steht in derselben Zeile schon da; der Agent
meldet weiter Paketnamen, weil das ist, was `apt-get` bekommt und was in sein
Protokoll gehört.

### Lauf 451 und 452 — zwei Wächter, und beide hatten in der Sache recht

**1556 grün, zwei rot, in beiden Läufen dieselben zwei.** Keiner davon war ein
Fehlalarm, und keiner liess sich mit einem Eintrag in einer Ausnahmeliste
erledigen — das war jeweils der erste Reflex und jeweils die schlechtere Antwort.

**`DatabaseEngineTest` fand `'postgres'` an drei Stellen**, an denen es nicht der
Wert der Aufzählung war: als Name der Kommandozeilenoption (`--postgres`) und als
Schlüssel der Einstellung. Die naheliegende Antwort wären zwei weitere Einträge
in `ALLOWED` gewesen — und die hätten den Wächter über zwei Dateien stumpf
gemacht, in denen ab Schritt 4 die echte Verzweigung zwischen `db.*` und `pg.*`
steht.

Umbenannt wurde stattdessen: Der Schalter heisst `srvpanel db --postgresql=on`,
der Einstellungsschlüssel `postgresql`, die Ablage im Inertia-Payload ebenso.
**Das ist keine Umgehung des Tests, sondern die Auflösung einer Zweideutigkeit,
die er richtig gerochen hat.** Ein Optionsname und ein Einstellungsschlüssel sind
keine Werte der Aufzählung; sie so zu schreiben, als wären sie es, hätte
irgendwann jemanden dazu gebracht, sie aneinander zu koppeln — und dann benennt
eine Änderung an `DatabaseEngine` still eine Kommandozeilenoption und einen
gespeicherten Schlüssel um. In `app/` steht `'postgres'` jetzt an genau einer
Stelle: in der Aufzählung selbst.

**`WordChoiceTest` fand „Fläche"**, und `docs/19 §3` führt das Wort als
verbraucht. Es stand als „Fläche freischalten" und „Fläche schliessen" in der
neuen Bezeichnungstabelle. Auch hier war die Ersetzung besser als die Ausnahme:
Die Zeilen heissen jetzt „Anbieten einschalten" und „Anbieten abschalten" — und
damit genauso wie die Zeile darüber, die „Wird angeboten" heisst. Der Wächter hat
nicht nur ein Wort gefunden, sondern eine Beschriftung, die zu ihrer eigenen
Tabelle nicht passte.

**Beide waren lokal auffindbar, und beide sind es nicht gewesen.** In diesem
Container laufen weder PHPUnit noch `vendor/`; was hier fährt, sind
handgeschriebene Attrappen für einzelne Wächter — und für diese beiden gab es
keine. Nachgeholt wurde es als Wegwerfskript im Scratchpad: derselbe Ausdruck,
dieselben Verzeichnisse, beide jetzt ohne Fundstelle. *Ein Wächter, den man vor
dem Commit nicht fragen kann, wird zu einer Runde CI* — die Antwort darauf ist
nicht, weniger Wächter zu bauen, sondern die Attrappe mitzuschreiben.

**Nebenbei, und ohne Zutun:** Der Lauf 451 hatte einen dritten roten Job,
„Installation auf Ubuntu 22.04", gescheitert beim Bereitstellen von nfpm nach
null Sekunden. In 452 lief derselbe Job durch. Ein Download, der einmal nicht
kam — kein Befund, und hier festgehalten, damit ihn beim nächsten Mal niemand für
einen hält.

### Die Messung zu Schritt 6b: sechs von dreizehn Paketen fehlten auf dem Zielserver

**Gesucht war `pgsql`, gefunden wurden sechs.** Am 9. August 2026 auf
`cloudsrv24` gegen die einzige dort installierte Version, PHP 8.4:

    vorhanden  fpm  mysql  xml  mbstring  curl  opcache  readline
    fehlt      pgsql  gd  zip  intl  bcmath  soap

Die sieben vorhandenen sind genau die, die `packaging/nfpm.yaml` als
Abhängigkeit des **Panels** nennt, plus was daran hängt. PHP 8.4 ist also nie
durch `php.version.install` gegangen — es kam als Abhängigkeit mit. Und die
Operation hätte es auch nie ergänzt: `/usr/sbin/php-fpm8.4` lag da, also meldete
sie `already => true` und tat nichts.

**Der Befund ist damit grösser als PostgreSQL.** Das Panel führt 8.4 als
installiert und bietet sie Kunden an; eine Website darauf hat kein `gd`, kein
`zip`, kein `intl`, kein `bcmath` und kein `soap` — die fünf, die eine übliche
Anwendung als Erstes verlangt. Das steht seit P3 so auf einem Server im Betrieb,
und gesehen hat es niemand, weil die einzige Stelle, die danach hätte fragen
können, einen Stellvertreter fragte. *Ein Stellvertreter verdeckt nicht nur den
Fall, für den man ihn bemerkt.*

**Was daraus nicht folgt:** dass eine unvollständige Version aus dem Auswahlfeld
der Domains verschwindet. `PhpSelection::installed()` bleibt bei „der Handler ist
da" — einem Kunden eine funktionierende PHP-Version wegen eines fehlenden `soap`
zu entziehen, wäre die härtere Änderung mit dem grösseren Schaden. Die richtige
Ebene ist, dass der Betreiber es sieht und ergänzt. Entscheidung, nicht
Auslassung; sie steht in `docs/38 §24.2`.

### Und eine Annahme, die nur ein deutsches System widerlegen konnte

`PhpVersions::missing()` vergleicht das Statusfeld von `dpkg-query` gegen die
Zeichenkette `installed`. Dieser Container spricht englisch — der Vergleich hätte
hier in jedem Fall gepasst. Auf `cloudsrv24` ist die *Meldung* deutsch („Kein
Paket gefunden, das auf php8.4-pgsql passt"), das Feld `${db:Status-Status}`
nicht.

Wäre es übersetzt, gälte **jedes** Paket als fehlend, und `php.version.install`
liesse bei jedem Aufruf `apt-get` laufen — lautlos, denn ein Lauf, der zu viel
installiert, sieht aus wie ein erfolgreicher. Die Messung steht jetzt als
Kommentar an der Methode, damit der Nächste die Frage nicht noch einmal stellt.

### Drei Befunde auf der Datenbankseite, und keinen davon hat ein Test gesehen

**Der erste Blick auf die echte Seite**, nachdem `v0.5.1-rc.1` auf
`cloudsrv24` lag. Sie war vollständig grün getestet, im Chromium dieses
Containers gemessen und ohne waagerechten Überlauf. Trotzdem drei Fehler, und
alle drei kommen daher, dass ein Baustein etwas über seine **Umgebung**
annimmt.

**1. Die Meldung stritt mit der Marke daneben.** Der Balken über der Tabelle
warnte gelb — „PostgreSQL ist auf diesem Server nicht installiert" —, während
die Marke in derselben Tabelle grau „nicht installiert" sagte. Beides auf einem
Bildschirm, beides über denselben Zustand. Der Rang war fest verdrahtet
(`class="notice warn"`) statt aus derselben Quelle wie die Marke zu kommen.

Der Satz dagegen steht in diesem Repo schon, in `Settings/Php.vue`: *Eine
Warnung schickt jemanden auf die Suche nach einem Problem, das keines ist.*
Dass PostgreSQL nicht installiert ist, ist eine Auskunft — dieselbe
Entscheidung, die `Pg\Server` für den Vorgang trifft und die im Klassenkommentar
ausdrücklich dasteht. In der Oberfläche galt sie nicht.

**2. Ein Text, der sich auf seinen Platz verliess.** Der Bereichshinweis lautete
„Ein zweites Datenbanksystem, unabhängig **vom oben genannten**" — und im
zweispaltigen Raster steht MariaDB nicht oben, sondern links. Auf dem Telefon
stapeln die Bereiche und der Satz stimmt wieder. Ein Hinweis, der je nach
Fensterbreite falsch ist.

Das ist Zeichen für Zeichen die Bauart aus `docs/20 §15`: *ein Abstand, der aus
der Reihenfolge der Seite abgeleitet war und mit der nächsten Ergänzung fiel.*
Diesmal war es kein Abstand, sondern eine Ortsangabe im Fliesstext. Sie ist
weg — „ein eigenständiges zweites Datenbanksystem" sagt dasselbe und zeigt
nirgendwohin.

**3. Und der Bereich, den die Ergänzung verschoben hat.** „Umschalten" gehört
zum Fernzugriff von MariaDB. Vor P5b stand er unter „Server"; der neue Bereich
dazwischen hat ihn im Raster neben PostgreSQL geschoben, wo sein eigener Text —
„Der Datenbankserver wird dabei neu gestartet" — plötzlich mehrdeutig ist, weil
es jetzt zwei gibt. Er heisst deshalb „Fernzugriff umschalten": Der Titel sagt,
worum es geht, statt sich darauf zu verlassen, wo er steht.

**Was daraus über das Vorgehen folgt.** Alle drei Fehler sind Eigenschaften des
Zusammenhangs und nicht des Bausteins — und die drei Aufnahmen, die ich vorher
gemacht habe, konnten sie nicht zeigen: Sie rendern den Baustein **allein** in
einer eigenen HTML-Datei. Das war der richtige Weg für die Frage „läuft etwas
über?", und es ist der falsche für „stimmt es neben dem, was daneben steht?".
Der Ersatz für die echte Seite ist er nie gewesen, und `CLAUDE.md` sagt das
auch; hier steht, was genau er nicht sieht.

### Punkt 3 bis 5 auf `cloudsrv24` — und eine Zahl, die niemand gemessen hatte

**Die drei unbelegten Behauptungen aus dem Pull Request sind zwei.**

`pg.server.install` hat im Zustand `absent` installiert: `apt-get` lief, das
`postinst` legte den Cluster an, `pg_lsclusters` meldet `16 main 5432 online`.
Der Zweig, den dieser Container nicht fahren konnte, trägt.

**Und die Ausgabe kam fortlaufend.** Damit ist der offene Punkt aus
`docs/37 §8` geschlossen: Die Ausgabe-Hälfte des Frame-Funds war seit Monaten
unbelegt, weil keine `db.*`-Operation streamt. `apt-get` ist die erste, die
lange genug läuft, um es zu zeigen — an etwas Harmlosem statt an einem
Zurückspielen während der Abnahme.

**Der Zustand danach war `not_handed_over`, und der Vorgang meldete Erfolg** —
die Entscheidung aus §6.1, im Betrieb bestätigt. Cluster und Port stehen dabei
in der Oberfläche, die Version nicht: Sie kommt aus der Verbindung, und die gibt
es ohne die Rolle nicht. Dass dort `—` steht, ist die Regel dieses Beitrags und
kein Loch — *gemeldet wird, was der Server geantwortet hat, nicht was wir über
ihn wissen.* Nach `CREATE ROLE root SUPERUSER LOGIN` steht sie da, kurz und
einzeilig.

`--postgresql=on|off|on` schaltet wie beschrieben, und `postgresql.service`
erscheint in der Dienstliste der Übersicht — die Zeile, die nur da ist, wenn es
die Unit gibt.

### Die Zahl

**`cloudsrv24` liefert PostgreSQL 16.14 und nicht 16.13.** In `Pg\Server` stand
von mir: *„Ubuntu 24.04 liefert 16.13 — zweimal belegt, im
Entwicklungscontainer und über den apt-Kandidaten auf `cloudsrv24`."* Belegt war
es **einmal**. Der zweite Beleg sollte `16+257build1.1` sein, und das ist die
Nummer des *Metapakets*; über die Serverfassung dahinter sagt sie nichts. 16.13
war die Zahl aus diesem Container, nicht vom Server.

Das ist wörtlich die Lehre, die in `CLAUDE.md` über P5b steht — *Wissen aus
zweiter Hand sieht aus wie Wissen* —, und diesmal steht sie in dem Dokument, das
sie aufgeschrieben hat. Bemerkenswert ist die Form: Der Satz war nicht falsch
geraten, sondern **falsch gezählt**. „Zweimal belegt" klingt nach mehr Sorgfalt
als „einmal gemessen" und war weniger.

**Folgenlos ist es, und zwar nachprüfbar:** Was §2.2 bis §2.2b gemessen haben,
hängt an der Hauptfassung — die dreizehn Kanäle, die `public`-Regel ab PG 15,
`WITH (FORCE)`, `DROP OWNED BY` je Datenbank, der Rückgabewert von `psql -f`.
Beide sind 16. Der Satz „dieselbe Konfiguration" gilt weiter; nur seine
Begründung war eine andere, als dastand.

### Der Betreiber fand den dritten Stellvertreter — diesmal in der Übersicht

**Punkt 6 lief durch, und daneben fiel ein Fehler auf, den ich eingebaut habe.**
Nach `systemctl stop postgresql@16-main.service` meldete die Datenbankseite
korrekt „läuft nicht" — und die **Übersicht zeigte PostgreSQL weiter als
`active`**.

Grund: Dort stand `postgresql.service`. Das ist Debians **Sammelunit**; sie
startet die Instanzen und bleibt danach mit `RemainAfterExit` auf `active`
stehen, unabhängig davon, ob darunter noch etwas läuft. Sie beantwortet „ist die
Sammelunit einmal gestartet worden" und nicht „läuft ein Cluster".

**Das ist in diesem Zweig zum dritten Mal dasselbe Muster.** `is_dir()` auf das
Socketverzeichnis, `is_executable()` auf den PHP-Handler, jetzt eine
Sammelunit — jedes Mal eine Prüfung, die im Fehlerfall dasselbe sagt wie im
Erfolgsfall. Und jedes Mal habe ich den Stellvertreter **nicht gemessen**,
sondern für die Sache gehalten: Dieser Container hat kein systemd, also gab es
nichts, was mir widersprochen hätte.

**Die Übersicht ist die Stelle, an der jemand nach Störungen sucht.** Eine
grüne Zeile neben einem stehenden Dienst ist dort schlechter als gar keine
Zeile — sie beendet die Suche an der falschen Stelle.

Gefragt wird jetzt die Instanzunit, `postgresql@16-main.service`, und welche das
ist, sagt `pg.server.info`: Fassung und Clustername kommen aus derselben Zeile
von `pg_lsclusters`, aus der auch Port und Zustand kommen. Der Name entsteht in
`Clusters::unit()` und nirgends sonst; `PgClusterTest` prüft beide Richtungen —
dass die Instanzunit entsteht **und** dass es nicht die Sammelunit ist. Zwei
Agent-Aufrufe statt einem, mit dem Grund im Kommentar.

**Was Punkt 6 im Übrigen belegt hat:** Der Knopf heisst im Zustand `stopped`
**Starten** und nicht „Installieren", der Vorgang lief in zwei Sekunden durch
(18:48:59 bis 18:49:01) und ohne eine Zeile `apt-get` — der Weg über
`pg_ctlcluster` also, nicht über die Paketverwaltung. Danach war der Cluster
wieder `online`.

### Punkt 7 auf `cloudsrv24` — der Neustart ist belegt, und zwei Befunde des Betreibers

**Die letzte unbelegte Behauptung ist erledigt.** PID vor dem Ergänzen
**66286**, danach **180618** — der Handler ist ein anderer Prozess, und
`php-fpm8.4 -m` führt `bcmath`, `gd`, `intl`, `pdo_pgsql`, `pgsql`, `soap`,
`zip`. `dpkg-query` meldet alle sechs als `installed`, Rückgabewert **0** statt
1. Der Vorgang lief in sechs Sekunden.

**Was der Lauf nicht beweist, gehört dazu:** In der Ausgabe steht auch
*„Processing triggers for php8.4-fpm"*. Der dpkg-Trigger hat also ebenfalls
gefeuert, und welcher von beiden die PID gewechselt hat, sagt dieser Lauf
nicht. Belegt ist die Eigenschaft, auf die es ankommt — **nach der Operation
hat der laufende Handler die Erweiterungen** —, und der ausdrückliche Neustart
bleibt, weil „der Trigger tut es meistens" kein Zustand ist.

**Und ein Nebenbefund, der die Vermutung von vorhin bestätigt:** Auf dem Server
liegt auch PHP 8.3, und dort fehlte **nur** `pgsql`. 8.3 ist also durch
`php.version.install` gegangen und hat alles bekommen; 8.4 kam als Abhängigkeit
des Panels und hatte sechs Lücken. Dieselbe Operation, zwei Wege auf den
Server, zwei sehr verschiedene Ergebnisse — und sichtbar wurde der Unterschied
erst, als jemand nach dem Paketsatz fragte statt nach dem Handler.

### Zwei Verbesserungen aus derselben Runde

**1. Zwei Knöpfe ohne Abstand.** Bis P5b trug jede Zeile der PHP-Seite genau
einen Knopf; mit „Ergänzen" neben „Entfernen" klebten sie aneinander.
`.button-row` ist die Antwort, die dieses Repo dafür längst hat — dieselbe wie
in `Customers/Index.vue`. Dazu eine Regel in `app.css`: `td.right` setzt
`text-align`, und das erreicht ein Flexkind nicht, also rutschte die Reihe nach
links. **Gefunden hat es der Betreiber am Server, nicht die Aufnahme** — im
nachgebauten Markup standen die beiden Knöpfe genauso eng und sahen normal aus.

**2. Die Zustandsspalte kannte nur den Mangel.** „Fehlt: pgsql" verschwindet,
sobald es getan ist, und danach stand nirgends mehr, was eine Version
überhaupt kann. `php.versions` meldet jetzt beide Hälften — aus **einem**
dpkg-Aufruf und derselben Auswertung, denn zweimal zu fragen hiesse, zwei
Antworten zu bekommen, die auseinandergehen können. Die Oberfläche zeigt
„fehlt:" und „vorhanden:", beide alphabetisch: Bei einem Namen ist die
Reihenfolge gleichgültig, bei zwölf ist eine Liste ohne Ordnung eine, in der
man nachzählt.

**Und dabei fiel der dritte Fehler auf, diesmal in der Aufnahme.** Bei 390px
standen „fehlt" und „vorhanden" **nebeneinander** in je einer schmalen Spalte,
und `bcmath` brach als `bcma` / `th` — eine Kennung mitten im Wort. Die Klasse
dagegen gibt es seit dem Optik-Rework, und ihr Kommentar in `app.css`
beschreibt den Fall wörtlich: *Beschriftung und Inhalt nebeneinander lassen den
Rest an den rechten Rand rutschen und dort umbrechen; sie gehören
untereinander.* Die Zustandszelle trägt jetzt `multiline`, sobald mehr als die
Marke darin steht.

*Dreimal in Folge lag die Lösung schon im Repo* — `.button-row`, `.multiline`,
und beim Meldungsbalken der Satz aus `Settings/Php.vue`. Das ist kein Zufall,
sondern die Kehrseite davon, einen Baustein allein zu bauen: Wer nur seine
eigene Datei ansieht, findet die Regel nicht, die nebenan schon steht.

### Lauf 458 — ein `array_values()` hinter einem `sort()`

Ein Befund, und er wäre lokal auffindbar gewesen: `sort()` gibt die Schlüssel
neu aus, das Ergebnis ist bereits eine Liste, und ein `array_values()` darum ist
wirkungslos.

**Der Grund, warum ich ihn nicht gesehen habe, ist der interessantere Teil.**
Der letzte lokale Lauf ging über `agent/src` — und nur darüber. Die Änderung lag
in `app/`. In dieser Sitzung ist PHPStan vorher fünfmal über die geänderten
`app/`-Dateien gelaufen und beim sechsten Mal nicht mehr; ein Schritt, den man
für erledigt hält, weil man ihn oft genug gemacht hat. *Eine Prüfung, die man
nach Gefühl auswählt, prüft irgendwann das, woran man ohnehin gedacht hat.*

### P5b Schritt 4 beginnt mit einer Messung, die den Rückbau vereinfacht

**Vorgelegt war eine Gabelung, und die Messung hat beide Äste verkürzt.**
`db.database.remove` macht in einem Vorgang drei Dinge — Schema werfen, die nur
daran hängenden Zugänge entfernen, den bleibenden das Recht nehmen.
`pg.database.remove` nahm nur einen Namen, und die Vermutung war: Rollen müssen
**vor** der Datenbank aufgeräumt werden, weil `DROP ROLE` verweigert, solange
eine Rolle etwas besitzt.

**Gemessen am 9. August 2026 gegen PostgreSQL 16.13:**

    vor  DROP DATABASE   pg_shdepend für die Rolle: dbid 0 → 1, dbid 24581 → 2
    nach DROP DATABASE   pg_shdepend für die Rolle: 0 Zeilen
    DROP ROLE ohne DROP OWNED BY → geht

`DROP DATABASE` nimmt **alles** mit, was in ihr wurzelt: die Eigentümerzeilen
der Objekte darin und den Eintrag auf die Datenbank selbst. Die Reihenfolge ist
also genau umgekehrt zur Vermutung — **erst die Datenbank, dann die Rollen** —,
und `DROP OWNED BY` ist dabei überflüssig.

**Und die zweite Liste entfällt ganz.** In MariaDB gibt es `revoke` für die
Zugänge, die bleiben, weil eine Rechtezeile in `mysql.db` ihr Schema überlebt
(`docs/36 §22.3p`, auf `cloudsrv24` gefunden). In PostgreSQL liegt dasselbe
Recht in `pg_database.datacl` und geht mit der Datenbank — gemessen: Nach dem
Werfen stand die bleibende Rolle nur noch an der Datenbank, die es noch gibt.
Eine `revoke`-Liste wäre Arbeit für einen Zustand, den es nicht gibt, und sähe
aus wie eine Zusage.

**Ein Vorgang statt zweier, und der Grund ist der Abbruch.** Rollen unmittelbar
zu entfernen und die Datenbank danach einzureihen hiesse: Bricht das
`DROP DATABASE` ab, sind die Zugänge fort und die Daten da — der Kunde sieht
seine Datenbank und kommt nicht mehr hinein. So bleibt bei einem Abbruch der
Zustand von vorher.

`DROP OWNED BY` bleibt, wo es hingehört: in `pg.role.remove`, für den anderen
Fall — eine Rolle, die entfernt wird, während ihre übrigen Datenbanken bestehen
bleiben. Dort verweigert `DROP ROLE` tatsächlich, und dort ist es gemessen.

**Gefahren gegen den echten Cluster:** Datenbank und zwei Rollen angelegt, in
einem Aufruf zurückgebaut — beide fort, wiederholbar (`removed=false` beim
zweiten Lauf), und eine Rolle mit fremdem Präfix abgewiesen. Der Wächter dazu
liest die **Reihenfolge** im Quelltext, weil sie sich sonst nur gegen einen
laufenden Server prüfen liesse.

**Die Lehre, die über den Rückbau hinausgeht:** Die Gabelung, die ich vorgelegt
habe, hatte beide Äste aus MariaDB gedacht — *„Rechte überleben ihr Schema"* ist
dort wahr und hier falsch. Ich hätte sie nicht vorlegen sollen, ohne sie vorher
zu messen; die Messung kostete vier Minuten und hat eine Liste, eine Operation
und einen Lebenslauf erspart.

### P5b Schritt 4 — ein Modell, eine Fläche, eine Verzweigung

`App\Support\Databases\Engines\` mit `EngineDriver`, `MariaDbDriver` und
`PostgresDriver`; `Databases` wählt den Treiber und bleibt sonst, was es war;
`PgLifecycle` neben `DbLifecycle`; fünf `pg.*`-Operationen in der Registratur —
sie haben jetzt einen Aufrufer.

**Die Verzweigung steht an einer Stelle, und das ist wörtlich `docs/38 §8`.**
`Databases::driver()` ist ein `match` über zwei Fälle, ohne `default`: Käme ein
drittes System dazu, meldete es der Übersetzer dort und nicht ein Kunde später.
Alles darüber — Kontingent, Namensprüfung, Mandantenklammer, das Schreiben der
Zeile — steht weiterhin genau einmal da und gilt für beide Systeme.

**Warum eine Schnittstelle und nicht fünf `match`.** Fünf Methoden mit je zwei
Zweigen sind fünf Gelegenheiten, einen zu vergessen. Eine Schnittstelle mit zwei
Umsetzungen macht daraus eine Liste, die vollständig sein *muss* — wer eine
Methode ergänzt, bekommt vom Übersetzer gesagt, dass die andere Umsetzung fehlt.
Das ist derselbe Gedanke wie bei `DatabaseEngine` selbst: nicht tippen, sondern
gesagt bekommen.

**`MariaDbDriver` bringt keine neue Regel mit.** Was dort steht, stand vorher
wörtlich in `Databases`. Wer einen Unterschied zu P5 sucht, findet keinen.

**Drei Unterschiede, und mehr sind es nicht.** Das Präfix (`p1001` gegen
`x7f3a…` aus `system_users.db_prefix`), die Sortierung (`charset`/`collation`
gegen `encoding`/`locale`, in denselben zwei Spalten) und der Zugang (`name` und
`host` als Schlüssel gegen eine clusterweite Rolle). Alles andere aus der
Tabelle in §8 liegt unterhalb des Agentenprotokolls.

**Nachgeschlagen wird das Präfix über die Nummer und nicht über den Namen.**
`docs/35` hat die Nummer zur bleibenden Kennung gemacht; ein Abonnement darf
umbenannt werden, und ein Nachschlagen am Namen ergäbe irgendwann ein anderes
Präfix — also fremde Datenbanken.

**Passwort setzen und Anlegen sind in PostgreSQL dieselbe Operation.**
`CREATE ROLE` kennt kein `IF NOT EXISTS`, also setzt `pg.role.create` an einer
vorhandenen Rolle das Passwort — der gewünschte Zustand ist beide Male
derselbe. Eine zweite Operation dafür wäre eine zweite Fassung derselben Regel.
Deshalb reicht der Treiber beim Zurücksetzen die Datenbanken mit: Ohne sie
verlöre der Zugang dabei seine Freigaben.

**`PgLifecycle` ist ein eigener Lebenslauf und kürzer als `DbLifecycle`.** Die
Antworten der beiden Rückbauoperationen haben nicht dieselbe Form —
`users_removed` als `name@host` gegen `roles_removed` als blosse Rollennamen —,
und ein gemeinsamer müsste in jeder Methode fragen, welches System gemeint ist.
Sein `afterFailure()` ist bewusst leer, mit Begründung: Bricht der Rückbau ab,
ist der Zustand der von vorher, und ein automatischer Rückweg auf `Active` wäre
eine Behauptung über den Server, die niemand geprüft hat.

**Eine Verhaltensänderung, die genannt gehört:** `Databases::remove()` wirft
jetzt, wenn zur Datenbank kein Abonnement mehr gehört. Vorher schickte es eine
leere Zeichenkette als Präfix an den Agenten, der sie abwies — die Meldung führte
an eine Stelle, an der niemand nach der Mandantenklammer sucht. Der Weg für
verwaiste Zeilen ist unverändert `srvpanel db --prune`.

**`PgRoleLock` steht noch nicht in der Registratur.** Die Sperre eines
Abonnements ist Schritt 5; dieselbe Regel wie für alle davor — eingetragen wird
sie in dem Beitrag, der ihr einen Aufrufer gibt.

**Und die Frage aus `docs/36 §14` hat eine Antwort.** Sie lautete: *„Muss P5b
`agent/src/Db/` aufreissen, war die Trennung falsch."* Bis hierher ist keine
Datei darunter geändert worden — `Runner` zählt nicht, der gehört keinem der
beiden. Die Trennung trägt.

### P5b Schritt 5, erste Hälfte — die Sperre erreicht beide Systeme

`PgLifecycle` beantwortet jetzt `subscription.suspend`, `subscription.resume`
und `pg.role.lock`; `PgRoleLock` steht in der Registratur, weil es seinen
Aufrufer hat. `NOLOGIN` statt `ACCOUNT LOCK` (`docs/38 §11`).

**Beide Lebensläufe hören auf dieselben zwei Aufgaben, und das ist keine
Doppelung.** Ein gesperrtes Abonnement soll seine Zugänge in *jedem* System
verlieren. Jeder reiht seinen eigenen Folgevorgang ein und fasst nur seine
eigenen Zeilen an — die Trennung liegt in `engine` und nicht in der Aufgabe.

**Ohne diese Trennung wäre Schritt 4 ein Fehler mit Ansage gewesen.**
`DbLifecycle::afterSubscription()` holte alle Zugänge eines Abonnements; seit
Schritt 4 sind darunter PostgreSQL-Rollen, und die gingen als `name@host` an
`db.user.lock`. Der Agent wiese sie ab — und ein gesperrtes Abonnement behielte
seine **MariaDB**-Zugänge, weil der ganze Vorgang scheitert. Beim Nachziehen
dasselbe von der anderen Seite: Zwei Vorgänge schrieben denselben Zustand, der
zweite gewänne, und auffallen würde es erst, wenn einer scheitert.

**Die Grenze, die P5 nie aufgeschrieben hat, steht jetzt im Code.** `NOLOGIN`
nimmt die Anmeldung und beendet **keine** bestehende Sitzung — `ACCOUNT LOCK`
tut das auch nicht. Eine Anwendung mit offenem Verbindungspool arbeitet nach der
Sperre weiter, bis sie neu verbindet. Wer das schliessen wollte, bräuchte
`pg_terminate_backend`, und dann sähe ein Kunde mitten in einer Transaktion
einen Abbruch. P5b sperrt und beendet nicht.

**Kein leerer Folgevorgang.** Ein Abonnement ohne PostgreSQL-Zugang bekommt
keinen — er stünde in der Liste, täte nichts, und wäre auf jedem Server ohne
PostgreSQL die Hälfte aller Zeilen.

### Und der neue Wächter hat sofort etwas gefunden, das ich nicht gesucht habe

`EngineScopeTest` verlangt, dass jede Abfrage über *alle* Zeilen eines
Abonnements auch das System nennt. Beim ersten Lauf meldete er eine Stelle, an
die ich nicht gedacht hatte: `DbLifecycle::removedAllDumps()` löscht bei
`db.dump.remove` alle Sicherungen des Abonnements — ohne `engine`.

Heute ist das folgenlos, weil alle Sicherungen MariaDB-Sicherungen sind. Ab
Schritt 6 gibt es `pg.dump.*`, und dann löschte dieser Aufruf auch die
PostgreSQL-Sicherungen: **Zeilen für Dateien, die noch auf der Platte liegen.**
Die Einschränkung steht jetzt da, bevor sie gebraucht wird.

Bemerkenswert ist, *warum* er sie gefunden hat: Der Wächter fragt nach
`subscription_id` und nicht nach `DbUser`. Hätte ich ihn auf die Tabelle
zugeschnitten, um die es mir ging, wäre die Sicherungszeile durchgerutscht —
*ein Wächter, der die Richtung prüft statt des Gegenstands, findet mehr als der,
der ihn gebaut hat.*

**Was von Schritt 5 noch fehlt:** die Messung (`docs/38 §12`) — `pg.usage` als
Operation und `srvpanel usage`, das ab P5b drei Dinge misst und weiter aus einem
Timer startet.

### P5b Schritt 5, zweite Hälfte — die Messung

`pg.usage` als Operation, `Usage` misst beide Systeme, `Database::query()` ist
dabei auf das gemessene System eingeschränkt.

**Beide werden immer gefragt, und nicht nach dem Schalter.** `pg.usage`
antwortet auf einem Server ohne PostgreSQL mit `available: false` und einem
Grund — genau wie `db.usage` es tut, wenn MariaDB steht. Eine Bedingung an der
Einstellung wäre eine zweite Fassung derselben Frage; und Datenbanken, die vor
dem Abschalten der Fläche entstanden sind, belegen weiter Platz und gehören
weiter gemessen.

**`apply()` fasst nur die Zeilen des gemessenen Systems an.** Ohne diese
Einschränkung bekäme eine PostgreSQL-Datenbank aus der MariaDB-Messung eine
`size_bytes` von 0 — sie steht in deren Antwort ja nicht —, und das wäre eine
**gemessene** Null für etwas, das niemand gemessen hat. Genau der Unterschied,
den `size_measured_at` sonst festhält.

**Und eine Abweichung vom Plan, mit Grund.** `docs/38 §12` schreibt die Abfrage
mit `WHERE datname ~ '^x[0-9a-f]{16}_'`. Das wäre die zweite Fassung des
Musters — die erste steht in `Pg\Names`. Ausgesondert wird deshalb in
`PgUsage::parse()` über `Names::isPanelName()`, dieselbe Stelle, die auch beim
Anlegen und beim Rückbau entscheidet, was zum Panel gehört. In der Abfrage steht
nur `datallowconn`, weil `pg_database_size()` an `template0` sonst einen
Verbindungsfehler auslöst.

**Gemessen gegen den echten Cluster:** eine Datenbank angelegt, `pg.usage`
gefragt — 7.683.095 Byte, und `postgres` und `template1` stehen nicht in der
Antwort. `pg_database_size()` zählt die Datenbank auf der Platte,
`data_length + index_length` in MariaDB die logische Grösse der Tabellen; die
Zahlen sind nicht vergleichbar, aber sie beantworten dieselbe Frage.

**Das Kontingent brauchte keine Zeile.** `Quota::Databases` zählt über
`Database::query()->where('subscription_id', …)` ohne `engine` — drei MariaDB-
und zwei PostgreSQL-Datenbanken sind damit fünf, wie `docs/38 §12` es verlangt.
Das war schon richtig und ist es geblieben.

### Und der neue Wächter hat mich korrigiert, nicht den Code

`test_the_measurement_only_names_what_belongs_to_the_panel` erwartete zuerst,
dass die befristete Datenbank eines Zurückspielens (`…_r1a2b3c4d`) aus der
Messung herausfällt. Sie tut es nicht — und sie soll es auch nicht: Sie gehört
dem Panel, belegt Platz, und im Bestand gibt es keine Zeile, auf die sie passt;
sie läuft also ins Leere und kostet einen Eintrag in einer Ablage.

Die Erwartung war meine Annahme und keine Regel. Worum es dem Wächter geht, ist
die andere Richtung: `postgres`, `template1` und die Datenbank des Betreibers
dürfen **nicht** in der Antwort stehen — ihre Grösse ginge sonst an `Usage` und
von dort in einen Kundenbericht.

*Ein Wächter, der beim ersten Lauf rot wird, hat zwei mögliche Ursachen, und die
erste, an die man denkt, ist der Code.*

### Lauf 460 bis 462 — drei Fehler, und einer davon ist ein Absturz

**Der teuerste war eine `use`-Klausel.** `Usage::apply()` bekam mit Schritt 5
den Parameter `$engine`, die Abfrage darunter steht aber in einer Closure — und
die Closure bekam ihn nicht. `Undefined variable $engine`, zehn rote Tests, und
im Betrieb wäre es die Messung gewesen, die alle fünfzehn Minuten abbricht.

**Zwei fehlende `@property`-Zeilen.** `DbUser` und `Database` haben `engine`
seit Schritt 2 in `$fillable` und in `casts()` — die Modellbeschreibung kannte
die Spalte nicht. Für PHP ist das gleichgültig, für larastan nicht.

**Und `BreakScriptTest`, wie er soll:** Drei Eingriffe in
`waechter-brechen.sh` zeigten auf Text, der mit den Treibern umgezogen ist —
der Zugangsnamensschutz hat andere Argumente bekommen, `'db.user.grant'` und
die `revoke`-Liste stehen jetzt in `MariaDbDriver`. Der Test sagt für genau
diesen Fall: *Meistens ist der Code umgezogen; dann zeigt der Eingriff auf
seinen neuen Ort.*

### Warum ich die ersten beiden nicht gesehen habe — zum zweiten Mal an einem Abend

Der lokale PHPStan-Lauf ging über `agent/src`. Die Änderung lag in `app/`.

**Dasselbe wie in Lauf 458**, wo `array_values()` hinter einem `sort()` durch
kam, aus demselben Grund: Ich habe den Teilbaum geprüft, an den ich *dachte*,
nicht den, den ich *geändert* hatte. Und beim `Access to an undefined property`
kommt eine zweite Schicht dazu — mein Filter gegen larastan-Rauschen enthielt
`Access to an undefined`, weil ohne larastan jedes `Model::query()` so aussieht.
Er hat damit einen echten Befund verschluckt: *Ein Filter, der Rauschen
entfernt, entfernt auch Befunde, die wie Rauschen aussehen.*

Die Antwort ist kein Vorsatz, sondern ein Skript. `pruefe.sh` im Scratchpad
läuft **immer** über `app`, `agent/src` und `tests/Support`, fährt alle
Attrappen und filtert nur noch namentlich genannte Zeilen heraus statt ganzer
Meldungsklassen. Beim ersten Lauf hat es die drei Eingriffe gefunden, die ich
nach Schritt 4 nicht mehr geprüft hatte — es hat sich sofort bezahlt.

### Lauf 463 — vier rote Tests, und keiner zeigte auf die Ursache

Der Lauf, der die drei Befunde von 462 bestätigen sollte, hat zwei neue
gebracht. Beide zeigten woandershin, als sie herkamen.

**`Databases::driver()` bekam `null`, wo der Übersetzer eine Aufzählung
verlangt.** `OrphanedGrantTest` scheiterte dreimal mit einem `TypeError` in
`app/Support/Databases/Databases.php` — an einer Zeile, die richtig ist. Die
Ursache liegt drei Schritte davor: `engine` trägt `default('mariadb')` in der
Migration, und ein `default` gilt beim `INSERT`. Was danach im Speicher steht,
ist das, was hineingeschrieben wurde — Eloquent liest die Zeile nicht zurück.
`DatabaseFactory` schrieb `engine` nicht mit, also war es `null`, obwohl das
Modell die Spalte seit dem Fix von 462 als `@property DatabaseEngine $engine`
führt: **ohne `null`.**

Zwei wahre Aussagen, die zusammen nicht stimmten. Die Spalte ist `NOT NULL` und
hat einen Vorgabewert; das Modell behauptet, sie sei immer da; die Factory baute
eine Zeile, die es so nie gibt. Der Ausweg, der sich zuerst anbietet — ein
`$attributes`-Vorgabewert im Modell — wäre die zweite Fassung dessen, was in der
Migration steht, und die zweite ist die, die veraltet. Was fehlte, war nicht der
Vorgabewert, sondern dass die Factory die Zeile so baut wie die Anwendung.

`FactoryDefaultTest` hält das jetzt für alle Modelle fest: Eine Spalte, die das
Modell als nicht-nullbare Aufzählung führt, wird von `definition()` gesetzt.
Gelesen wird der `@property`-Block, weil dort steht, was das Modell über sich
behauptet — und weil larastan dieselbe Stelle liest, ist sie gepflegt. Die
Gegenrichtung steht daneben: `Domain::$redirect_kind` darf `null` sein, und eine
Factory, die eine Weiterleitungsart erfände, wäre falscher als eine, die sie
weglässt. Beim Bauen fiel dabei auf, dass `DatabaseDump` seine `engine`-Zeile im
`@property`-Block überhaupt nicht hatte — derselbe Befund wie in 462, eine
Datei weiter, und niemand hätte ihn gemeldet, weil noch niemand `$dump->engine`
liest.

**Und `ChangelogTest` hielt einen Namensraum für eine Klasse.** Der Eintrag zu
Schritt 4 nennt `App\Support\Databases\Engines\` — mit abschliessendem
Backslash, wie man einen Namensraum schreibt. Der Ausdruck sah eine Klasse,
suchte `Engines.php` und fand ein Verzeichnis. Den Text umzuschreiben wäre der
schnellere Weg gewesen und der schlechtere: Er hätte eine Regel eingeführt, die
nirgends steht — *im Changelog darf kein Namensraum vorkommen* —, und der
nächste Beitrag hätte sie wieder gebrochen. Der Wächter fängt den Backslash
jetzt mit und prüft dann ein Verzeichnis statt einer Datei. Beide Richtungen
gebrochen, beide rot; die Befehlsfolge steht im Kopf des Tests, weil
`waechter-brechen.sh` `CHANGELOG.md` nicht wiederherstellt.

**Was sie gemeinsam haben.** Beide Fehler standen an einer Stelle, an der die
Regel *stimmte* — der `match` ohne `default`, der Ausdruck für Klassennamen —
und kamen von einer Annahme darüber, was ihnen geliefert wird. Das ist derselbe
Stellvertreter wie schon dreimal in P5b, nur eine Ebene höher: nicht ein
Verzeichnis für einen Dienst, sondern ein Vorgabewert der Datenbank für einen
Wert im Speicher.

Nachgetragen wurde bei der Gelegenheit der Bruch zu `EngineScopeTest` aus
Schritt 5, der im Skript fehlte.

### P5b Schritt 6, erste Hälfte — Sichern und Zurückspielen im Agenten

`pg.dump.create`, `pg.restore` und `pg.dump.import` liegen unter
`agent/src/Ops/`, dazu drei Bausteine: `SrvPanel\Agent\Pg\Hba`,
`SrvPanel\Agent\Pg\Credentials` und `SrvPanel\Agent\Pg\Ephemeral`. Eingetragen
in die Registratur werden sie mit dem Beitrag, der ihnen einen Aufrufer gibt —
dieselbe Reihenfolge wie in Schritt 1.

**Fünf Messungen standen vor der ersten Zeile Code, und zwei haben den Plan
umgeworfen.**

`docs/38 §13.1` hat sich bestätigt, wörtlich: `psql -f` gibt bei gescheitertem
SQL **0** zurück und arbeitet weiter — vier Anweisungen, die dritte abgewiesen,
Rückgabewert 0, und die vierte lief. Mit `ON_ERROR_STOP=1`: Rückgabewert 3,
Abbruch, und eine Meldung mit Datei, Zeilennummer und Grund. `mysql` macht das
von selbst richtig, und **genau darin liegt die Falle**: Wer aus P5 abschreibt,
schreibt eine Vorsicht ab, die dort in der *Abwesenheit* eines Schalters lag.

`§13.2` ebenfalls: null `DEFINER`-Angaben in einem `pg_dump`. Der Filter aus
`docs/36 §10.1` entfällt — und weil er über jede Zeile eines Dumps läuft, ist
„entfällt" hier mehr wert als „schadet nicht". `Dump::compress()` nimmt den
Filter deshalb seit heute als Argument statt ihn zu setzen; `PgRestoreTest`
prüft beide Richtungen.

**Was §13.4 nicht wusste: Die befristete Rolle kommt über den Unix-Socket gar
nicht herein.** Debians `pg_hba.conf` beginnt mit `local all all peer`, und
`peer` verlangt einen gleichnamigen Unix-Benutzer — den hat
`x7f3a…_r1a2b3c4d` nicht. In P5 meldet sich der befristete Benutzer über den
Socket mit Passwort an, MariaDB lässt das zu, und §13.4 sagte „wie
`docs/36 §10.2`". Der Fehlschlag sieht dabei wie ein Rechteproblem aus und
steht in einer Datei, die mit Rechten nichts zu tun hat.

Beide Auswege sind gemessen worden, bevor sie dem Betreiber vorlagen: TCP über
`127.0.0.1` läuft ohne jede Konfigurationsänderung, hängt aber an
`listen_addresses`. Der Betreiber hat den anderen gewählt — eine Gruppenrolle
`srvpanel_restore` und eine Zeile in `pg_hba.conf`, die ihre Mitglieder über den
Socket mit Passwort hereinlässt. Sie steht **ganz oben**, weil die erste
passende Zeile entscheidet, auch wenn sie abweist.

**Und der Runner hat zum ersten Mal überhaupt eine Ergänzung seiner festen
Umgebung bekommen.** `psql` kennt keinen Schalter für die Passwortdatei, nur
`PGPASSFILE`. Der erste Anlauf setzte sie mit `putenv()` — und lief in
`fe_sendauth: no password supplied`, weil `Runner` die Umgebung fest vorgibt.
Gemessen hatte ich ein nacktes `proc_open()`: **den Stellvertreter statt die
Sache**, zum vierten Mal in dieser Stufe. Die Antwort ist eine Positivliste nach
demselben Muster wie die für Programme, mit `PGPASSFILE` als einzigem Eintrag —
eine Umgebung ist dieselbe Angriffsfläche wie eine Kommandozeile, und
`LD_PRELOAD` lädt fremden Code in einen Prozess, der als root läuft.

**Der teuerste Fund kam beim Laufenlassen und hätte das Zurückspielen
unbrauchbar gemacht.** Ein `DROP ROLE` scheitert, solange der Rolle etwas
gehört; der naheliegende Weg ist `DROP OWNED BY` im `finally` — und der wirft
weg, was gerade eingespielt wurde. Der Lauf meldete Erfolg, und die Tabelle war
fort. Was eine Rolle anlegt, gehört ihr, und beim Zurückspielen legt sie die
ganze Datenbank an. In P5 gibt es dazu kein Gegenstück, weil MariaDB kein
Eigentum an einer Tabelle kennt — der Plan hat die Frage deshalb nicht gestellt.
`REASSIGN OWNED BY … TO` überträgt jetzt zuerst an den **Eigentümer der
Datenbank**, gefragt und nicht angenommen.

**Zwei Dinge, die es ausdrücklich nicht gibt.** Kein `pg.dump.remove`:
`db.dump.remove` entfernt eine Datei, und eine Datei hat kein Datenbanksystem.
Und keine zweite Fassung von `requireGzip`, `unpackedSize`, `requireSpace` und
`moveInto` — die sind aus `DbDumpImport` nach `Dump` gezogen, weil sie mit
MariaDB oder PostgreSQL nie etwas zu tun hatten. Beim Umzug hat `requireSpace()`
das Datenverzeichnis als Argument bekommen: Für MariaDB ist es fest, für
PostgreSQL steht es in `pg_lsclusters`.

Neu gemessen dazu noch zweierlei. Die befristete Rolle braucht
`GRANT ALL ON SCHEMA public` — seit PostgreSQL 15 darf `PUBLIC` dort nicht mehr
schreiben, und ohne die Zeile bricht das Zurückspielen an der ersten
`CREATE TABLE` ab. Und ein `pg_dump` einer einzelnen Datenbank enthält **kein**
`\connect`; `pg_dumpall` schon. Die Falle aus §13.4 trifft also nur
Mitgebrachtes, und dort greift der `REVOKE CONNECT` aus §10 ein zweites Mal.

**Und ein Wächter aus Schritt 1 hat den ersten Anlauf zurückgewiesen.**
`AgentIdentityTest` besteht darauf, dass `psql` an genau einer Stelle gerufen
wird — `PgRestore` hatte den Lauf bei sich stehen. Die Regel ist richtig, und
sie hat gehalten: Der Lauf ist nach `Pg\Session::restore()` gezogen, und damit
stehen Socketpfad, Anmeldeweise und `ON_ERROR_STOP` weiter genau einmal da.

**Eine zweite Regel war dagegen zu weit gefasst**, und das ist der seltenere
Fall. `PgSessionTest` verbot `-c` **und** `-f` mit der Begründung „dann steht
das SQL in der Kommandozeile" — für `-c` stimmt das, `-f` trägt einen
Dateinamen. Mitverboten war es, weil es in Schritt 1 keinen Fall dafür gab und
beide gleich aussahen. Eine solche Regel wird **geschärft und nicht
abgeschaltet**: `-c` bleibt verboten, `-f` ist auf eine Stelle begrenzt und muss
einen Pfad tragen, und daneben steht jetzt die Prüfung, um die es eigentlich
ging — kein Passwort unter den Argumenten.

### P5b Schritt 6, zweite Hälfte — ein Lebenslauf für Sicherungen

`App\Support\Databases\DumpLifecycle` übernimmt die vier Dump-Aufgaben von
`DbLifecycle` und bedient beide Systeme. **Eine Klasse je Gegenstand, nicht je
System** — was mit einer Sicherung geschieht, hängt an keinem Datenbanksystem:
Die Grösse kommt aus der Antwort, die Zeile geht auf fertig oder gescheitert,
beim Entfernen wird sie gelöscht, beim Zurückspielen nichts getan. Nur die
**Namen** der vier Aufgaben unterscheiden sich, und die stehen jetzt an einer
Stelle.

Derselbe Schnitt wie bei den vier Datei-Prüfungen, die in der ersten Hälfte aus
`DbDumpImport` nach `Dump` gezogen sind, und dieselbe Begründung: Eine Sicherung
ist eine Datei und eine Zeile, und beide wissen nichts von MariaDB oder
PostgreSQL.

**Eine Einschränkung fällt dabei weg, die es geben musste.** In
`removedAllDumps()` stand `->where('engine', MariaDb)` — richtig, solange
`db.dump.remove` zu `DbLifecycle` gehörte und sonst die PostgreSQL-Zeilen
desselben Abonnements mitgelöscht hätte. Seit dieselbe Aufgabe **beide** Systeme
bedient und der Agent beim Rückbau das ganze Verzeichnis entfernt, wäre die
Zeile der Fehler: Sie liesse die PostgreSQL-Zeilen stehen, und `srvpanel db`
meldete sie als verwaist.

`DbLifecycle::afterFailure()` ist damit leer. Das ist ein Zustand und kein Rest
— für Rückbau und Sperre gilt dieselbe Zurückhaltung wie in `PgLifecycle`.

**Das Präfix im Auftrag war der letzte offene Punkt, und er ist zu.** `db.*`
prüft gegen den Systembenutzer, `pg.*` gegen das Präfix aus `system_users`;
gewusst hat das nur der PostgreSQL-Treiber. Es in `Dumps` ein zweites Mal zu
holen wäre die zweite Fassung gewesen — statt dessen ist die Abfrage als
`PostgresDriver::prefixOf()` herausgezogen, und der Treiber ruft sie selbst.
Eine Stelle, zwei Aufrufer. Damit stehen `pg.dump.create`, `pg.restore` und
`pg.dump.import` in der Registratur, und `RemovalPathTest` führt die beiden
Ausnahmen mit Begründung: Der Weg zurück heisst `db.dump.remove` und gilt für
beide Systeme.

**Zwei Wächter haben den Umbau gefunden, bevor die CI es tat.** `BreakScriptTest`
meldete zwei Eingriffe, die auf Text zeigten, der mit den Methoden umgezogen
ist — das erwartete Muster. Und `DatabaseEngineTest` hat die erste Fassung von
`DumpLifecycle` zurückgewiesen: Sie führte die Aufgaben in einer Konstante, die
beide Systeme als Zeichenkette benannte. Die Regel ist dieselbe wie in Lauf 451
und richtig; die Tabelle ist jetzt ein `match` über das Enum, vollständig und
ohne `default` — käme ein drittes System hinzu, meldete es der Übersetzer dort
und nicht ein Kunde später.

### P5b Schritt 7 beginnt bei den Sätzen, die nur ein System kennen

Drei Stellen der Oberfläche erklärten ein Kontingent mit MariaDB, und seit
Schritt 4 zählt es über beide Systeme (`docs/38 §12`). Der Satz „MariaDB kennt
keine Obergrenze je Schema" ist dabei nicht bloss unvollständig — **er sagt
einem PostgreSQL-Kunden das Gegenteil dessen, was gemeint ist:** dass ihn die
Grenze nicht betrifft.

`Quota::hint()` für Anzahl und Grösse, der Kommentar darüber und der Hinweis in
`Subscriptions/Show.vue` nennen jetzt beide Server, ohne einen davon zu
benennen. Der Pfad `/var/lib/mysql` fällt dabei aus dem Text: Er war das
Beispiel für „liegt ausserhalb der Quota", und zwei Pfade nebeneinander erklären
weniger als die Aussage selbst.

### Und die Spalte „System" — zweimal falsch beim ersten Versuch

`Databases/Index.vue` zeigt das System als Marke, sobald es eine Datenbank gibt,
die nicht MariaDB ist. Der erste Entwurf beantwortete das im Template, mit einem
`some()` über die geladenen Zeilen, und war dabei gleich zweifach daneben.

**`DatabaseEngineTest` hat den Wert als Zeichenkette abgewiesen** — die Werte
eines Systems stehen im Enum und nirgends sonst, dieselbe Regel wie schon bei
`DumpLifecycle` eine Stunde vorher. Und beim Umbau fiel der zweite Fehler auf,
den kein Wächter gemeldet hätte: **`some()` läuft über eine Seite.** Bei zwanzig
Zeilen je Seite wäre die Spalte beim Blättern verschwunden, sobald eine Seite
nur MariaDB enthält — eine Tabelle, deren Spalten sich beim Weiterklicken
ändern.

Beides löst dieselbe Zeile: Der Server beantwortet die Frage, über den ganzen
Bestand und innerhalb der Mandantenklammer. **Gefragt wird nach einem Zustand
und nicht nach einer Einstellung** — „PostgreSQL ist angeboten" wäre die Absicht
des Betreibers; ob die Spalte etwas erklärt, entscheidet, ob es eine Datenbank
gibt, die sie braucht.

### Die Wahl des Systems — und ein Präfix, das nicht mitgezogen wäre

`Databases/Create.vue` zeigt das System zur Auswahl, **nur wenn es etwas zu
wählen gibt**: MariaDB steht immer da, PostgreSQL, wenn der Betreiber es
anbietet. Das ist die eine Stelle in P5b, an der eine *Absicht* die richtige
Bedingung ist und kein Zustand — ob PostgreSQL läuft, sagt `pg.server.info`; ob
es Kunden angeboten wird, entscheidet der Betreiber.

**Der Fund beim Bauen war das Präfix.** Es steht im Formular, während getippt
wird, damit der Kunde den fertigen Namen sieht — und es ist in den beiden
Systemen ein anderes: der Systembenutzer (`p1001`) gegen die gewürfelte Kennung
(`x7f3a…`). Ein Formular, das weiter `subscription.prefix` anzeigt, zeigt nach
dem Umschalten den falschen Namen an, und der Kunde trägt ihn in seine Anwendung
ein. Jeder Eintrag der Auswahl bringt sein Präfix deshalb selbst mit. Der Satz
„das Präfix ist der Systembenutzer des Abonnements" ist damit auch weg — für
PostgreSQL war er schlicht falsch.

**Und einmal mehr ein Stellvertreter, diesmal in meinem eigenen Entwurf.** Ob
das Formular eine Sortierung anbietet, hing an `engines[0]` — „der erste Eintrag
ist MariaDB". Eine Annahme über die Reihenfolge einer Liste, die beim ersten
Umsortieren still falsch geworden wäre. Die Frage gehört zum System und wandert
jetzt mit ihm.

Geprüft wird beim Absenden gegen dieselbe Liste, die das Formular gezeigt hat,
und nicht bloss gegen das Enum: `postgres` durchzulassen, während der Betreiber
es nicht anbietet, wäre eine Wahl, die es in der Oberfläche nie gab.

### `Databases/Show.vue` — und eine Angabe, die für PostgreSQL gelogen war

Die Detailseite nennt das System als Marke und sagt bei PostgreSQL, was beim
Eintragen der Verbindungsdaten gebraucht wird: **über `127.0.0.1` und nicht über
einen Socket.** Der Satz steht dort und nicht in einer Anleitung, weil die
Meldung im Fehlerfall auf etwas anderes zeigt als auf die Ursache — eine
Anwendung, die auf `localhost` als Socket verbindet, bekommt
„Peer authentication failed", und das liest sich wie ein Rechteproblem. Dazu der
Hinweis zu Erweiterungen aus `docs/38 §5`.

**Die Sortierung fällt weg, wo sie nichts bedeutet.** In der Zeile stand für
jede PostgreSQL-Datenbank `utf8mb4_unicode_ci` — der Vorgabewert der Spalte aus
P5, den diese Datenbank nie gesehen hat. Zeichensatz und Sortierung entstehen
dort aus der Vorlage des Servers. Der Server liefert für sie jetzt `null`, und
die Oberfläche zeigt die Zeile gar nicht: **eine fehlende Angabe ist ehrlicher
als eine falsche.**

Beide Bedingungen hängen an einer *Eigenschaft* und nicht am Namen des Systems
— `over_tcp`, und `collation === null`. Ein Vergleich mit dem Wert des Systems
im Template wäre eine Zeichenkette aus dem Enum; `DatabaseEngineTest` weist sie
ab und hat dabei sogar einen Kommentar erwischt, der das Wort nur als Beispiel
trug.

### Die Aufnahmen zu Schritt 7 — gemessen, aber nicht an der echten Seite

`vendor/` gibt es in diesem Container nicht, also läuft `artisan serve` nicht.
Gemessen wurde deshalb auf dem Weg, den `CLAUDE.md` für genau diesen Fall
beschreibt: das gebaute Stylesheet aus `public/build`, das Markup der drei
geänderten Bausteine in einer eigenen Datei, gerendert im vorinstallierten
Chromium.

**Und zwar mit dem längsten Namen, den dieses Panel vergeben kann.** Der erste
Lauf nahm `x7f3a91c2b40e15d6_shop` — zweiundzwanzig Zeichen —, und in der
Detailseite stand die Kennung sichtbar knapp am Rand. Erlaubt sind aber
vierunddreissig: siebzehn Präfix, ein Unterstrich, sechzehn Zusatz. Eine
Messung am bequemen Beispiel ist keine Messung; genau daran ist `v0.4.0-rc.4`
gescheitert.

Ergebnis mit dem Härtefall: **`scrollWidth - clientWidth` ist 0 px** — bei
390 px und bei 1280 px, hell und dunkel. Die Kennung bricht in der mobilen
Ansicht mitten im Wort und im Fliesstext an der Wortgrenze; beides ist hässlicher
als ein kurzer Name und besser als eine Seite, die sich schieben lässt.

**Was das nicht belegt, gehört dazu:** Der Weg zeigt einen Baustein und nicht
die Seite. Wie sich die Spalte „System" neben den übrigen Spalten verhält, wie
die Abschnitte zusammen umbrechen und ob eine Marke im Zusammenspiel kippt,
sagen erst Aufnahmen der laufenden Anwendung. Sie stehen aus und sind **nicht
abgehakt** — P4 Schritt 6 ist genau so ausgeliefert worden, und die nachgeholte
Runde fand drei Fehler auf einer vollständig grün getesteten Seite.

### Lauf 466 — der erste Lauf des Pull Requests, und zwei echte Fehler

**Vier Imports fehlten**, und alle vier aus demselben Grund: Ich habe sie mit
einem Skript hinter einer Zeile eingefügt, die es in dieser Datei nicht gab. Das
Skript meldete nichts, PHP meldete nichts, und lokal fiel es nicht auf, weil
PHPStan hier ohne Autoload läuft und `class.notFound` deshalb als Rauschen
gefiltert wird. **Ein Filter, der Rauschen entfernt, entfernt auch Befunde, die
wie Rauschen aussehen** — dieselbe Lehre wie in Lauf 462, an einer anderen
Stelle.

**Und ein Fehler, der Kunden getroffen hätte:** `Dumps::dispatch()` holte das
PostgreSQL-Präfix für **jede** Sicherung. Ein Abonnement ohne Präfix — Testdaten
oder eine Installation, deren Migration noch aussteht — konnte damit keine
**MariaDB**-Sicherung mehr anlegen: `PostgresDriver::prefixOf()` wirft, und der
Vorgang kam nie in die Warteschlange. Eine Angabe, die das eine System nicht
braucht, darf das andere nicht aufhalten. Das Präfix geht jetzt nur mit, wenn
die Sicherung zu PostgreSQL gehört.

**Der dritte Befund war ein Test, der auf den alten Ort zeigte.**
`DumpTeardownTest` rief `DbLifecycle::afterSuccess()` unmittelbar, und dort steht
die Regel seit dem Umzug nicht mehr — er meldete Rot für genau die Ordnung, die
er absichern soll. Dasselbe Muster wie bei den Eingriffen in
`waechter-brechen.sh`, nur in einem Test. Er geht jetzt über `Lifecycles`, also
über den Weg, den die Anwendung nimmt: Zieht die Regel noch einmal um, bleibt er
richtig.

### `srvpanel:version` — und ein Vorgabewert, der zwei Jahre die Antwort war

Beim Testlauf von `v0.5.1-rc.2` fragte der Betreiber `srvpanel --version` und
bekam „Laravel Framework 13.23.0". Richtig — `srvpanel` reicht alles an `artisan`
durch, und `artisan --version` nennt die Fassung des Frameworks. Für die Frage
„ist rc.2 installiert?" ist sie wertlos. **Das ist derselbe Stellvertreter, der
in P5b schon zweimal zugeschlagen hat:** gemessen wurde etwas, das an der
richtigen Stelle stand und die falsche Sache nannte.

**Die Suche nach der richtigen Stelle hat einen Fehler freigelegt, der seit der
ersten Woche ausgeliefert ist.** `config('app.version')` las
`env('SRVPANEL_VERSION', '0.1.0-dev')`, und **diese Variable wird nirgends
gesetzt** — nicht vom Paket, nicht von `srvpanel setup`, nicht in einer
`.env.example`. Die Marke im Menü zeigte also auf jedem Server dieser Welt
`0.1.0-dev`, und der Kommentar direkt daneben nennt sie „die erste Frage bei
jedem Fehlerbericht". Gemerkt hat es niemand, weil nichts den Bezug prüfte —
wortwörtlich das Muster, das `CLAUDE.md` als den teuersten Fehler dieses
Projekts führt.

> **Ein Vorgabewert für eine Variable, die niemand setzt, ist kein Vorgabewert —
> er ist die Antwort.**

Ein `env()` mit zweitem Argument sieht in jeder Durchsicht harmlos aus. Es wird
nicht dadurch falsch, dass es dasteht, sondern dadurch, dass die Variable
fehlt — und das steht nicht in der Zeile, sondern in ihrer Abwesenheit
anderswo. Es gibt keinen Ort, an dem man beim Lesen darüber stolpert.

**Die Fassung kommt jetzt aus dem Verzeichnisnamen.** Das Paket legt jede
Auslieferung nach `/opt/srvpanel/releases/<fassung>` und lässt `current` darauf
zeigen (`packaging/install.sh`); der Name ist damit nicht eine *Angabe über* die
laufende Fassung, sondern sie selbst. Eine Datei daneben — `VERSION`, ein
Eintrag in `srvpanel.php` — wäre eine zweite Fassung derselben Auskunft, und die
zweite ist die, die beim nächsten Update stehen bleibt. Der Integrationslauf
prüft denselben Zusammenhang längst von der anderen Seite („Das Verzeichnis der
Fassung traegt die Fassung").

**Im Quellbaum steht ein Wort und keine Nummer:** `Quellbaum`. Eine
erfundene Zahl — `0.0.0`, `dev` — sähe in einem Fehlerbericht aus wie eine
Auskunft und wäre keine; genau daran ist `0.1.0-dev` zwei Jahre lang
vorbeigekommen.

`srvpanel version` gibt die Fassung allein aus, ohne Satz drumherum: Der
häufigste Gebrauch ist eine Zeile in einem Fehlerbericht oder ein Vergleich in
einem Skript, und beides bricht an Zierrat. `--details` nennt dazu das
Verzeichnis, aus dem die Auskunft stammt, und den Commit — leer, wenn kein
Freigabelauf ihn gesetzt hat, denn eine erfundene Kennung wäre schlimmer als
keine. **Die Abkürzung `-v` gab es im ersten Entwurf und geht nicht:** Symfony
belegt sie für die Redseligkeit, und ein zweiter Anspruch darauf wirft beim
Aufbau der Kommandoliste — also bei *jedem* `artisan`-Aufruf, nicht nur bei
diesem. Derselbe Fehlertyp wie ein Methodenname, der der Basisklasse gehört.

**Und `srvpanel --version` beantwortet die Frage jetzt selbst.** Der Wrapper
reichte den Schalter an artisan durch; wer ihn tippt, meint aber das Programm,
das er aufruft.

**Der Wächter prüft die Zeile und nicht den Wert.** Ein Test gegen
`config('app.version')` wäre grün, sobald irgendjemand `SRVPANEL_VERSION` in
seiner eigenen `.env` setzt, und rot auf jedem Server, der das nicht tut — er
prüfte die Umgebung des Prüfers. `ReleaseVersionTest` liest deshalb
`config/app.php` als Text, verlangt dort `Release::version()`, verbietet `env(`
und sieht in beide Richtungen nach, dass die Auskunft die Oberfläche und den
Befehl erreicht. Beide Brüche stehen in `tests/waechter-brechen.sh`, und mit
ihnen kommt `config/` in die Liste der Verzeichnisse, die das Skript
wiederherstellt: Der eine Bruch baut die alte Zeile wieder ein, und ohne die
Liste bliebe sie stehen.

### Der Quelltext-Link zeigt jetzt auf den Stand, der läuft

**Derselbe Fund wie beim Fassungsbefehl, eine Datei weiter — und er betrifft die
Lizenz.** Abschnitt 13 der AGPL verlangt, dass wer die Software über das Netz
benutzt, an ihren Quelltext kommt; die Begründung über `config('srvpanel.source')`
sagt ausdrücklich „den der laufenden Fassung, nicht bloß das Repository". Genau
das war nicht eingelöst: Der Link hing an `SRVPANEL_COMMIT`, und diese Variable
setzt niemand — nicht das Paket, nicht der Freigabelauf. Die Fusszeile zeigte auf
jedem Server auf `main`.

**Bemerkenswert ist, wie es gefunden wurde.** Nicht durch Lesen, sondern durch
Suchen: Nachdem `SRVPANEL_VERSION` aufgefallen war, blieb die Frage, ob es die
Bauart noch einmal gibt. Es gab sie, ein `grep` entfernt. Ein Fehler, den man nur
findet, wenn man nach ihm sucht, wird nicht durch Aufmerksamkeit gefunden,
sondern durch die Frage „wo noch?".

Die Adresse kommt jetzt aus der Version, wenn kein Commit dasteht:
`…/tree/v0.5.1-rc.3`. Eine Freigabe ist ein annotierter Tag auf `main`, und das
Verzeichnis der Auslieferung trägt dieselbe Nummer — **die Angabe entsteht aus
dem, was ohnehin da ist, und kann deshalb nicht vergessen werden.** Der Commit
behält den Vorrang, falls ihn eines Tages jemand setzt; im Quellbaum steht das
Repository selbst, denn ein toter Link löst keine Auflage ein.

**Die Wahl ist dabei aus der Vorlage in den Server gezogen.** `PanelLayout.vue`
baute die Adresse aus `commit` und `repository` selbst zusammen — dieselbe Regel
ein zweites Mal, an der Stelle, an der man sie am wenigsten sucht. Die
Oberfläche bekommt jetzt eine fertige Adresse und keine Zutaten.

### Und was erst die Aufnahme gezeigt hat

`Release::UNRELEASED` hiess zuerst **„aus dem Quellbaum"** — ein Satz, und er
liest sich auf der Kommandozeile gut. Nur landet derselbe Wert in der
Versionsmarke neben dem Schriftzug, und die ist eine **Monospace-Marke für
Kennungen**. Ein deutscher Satz in Schreibmaschinenschrift, in einem Kästchen,
das sonst `0.5.1-rc.3` trägt.

Gemessen war nichts falsch: `scrollWidth - clientWidth` ist 0 px, bei 390 px und
1280 px, hell und dunkel, auch mit `0.10.0-rc.12` als längstem denkbaren Namen.
**Es sah nur falsch aus, und genau das findet kein Test.** Zwei Sekunden
Aufnahme, ein Wort statt eines Satzes: `Quellbaum`.

### `docs/39` — die Zwischenabnahme steht jetzt im Repo und nicht in einem Verlauf

Der Testlauf gegen `v0.5.1-rc.2` — neun Punkte auf `cloudsrv24`, mit der Frage,
ob die Schritte 4 bis 7 auf einem echten Server überhaupt tragen — stand zuerst
nur als Nachricht in einer Sitzung. **Das ist genau das Muster, das `CLAUDE.md`
als teuersten Fehler dieses Projekts führt, nur an der letzten Stelle, an der man
es sucht: in der Anleitung selbst.** Ein Verlauf lässt sich nicht durchsuchen,
nicht berichtigen, und `DocLinkTest` sieht ihn nicht.

Der Beleg kam am selben Tag. Punkt 0 enthielt zwei falsche Befehle:
`srvpanel --version` nannte die Fassung von Laravel, und ein `| head -2` schnitt
die Ausgabe von `psql` ab, bevor sie kam. Der Betreiber ist an beiden
hängengeblieben — und die Berichtigung stand danach wieder nur im Verlauf.

> **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile Code
> ist.**

Was das Dokument über die Sammlung von Befehlen hinaus festhält, ist jeweils der
Grund: warum die Ausgangsmessung in Punkt 1 *vor* Punkt 7 stattfinden muss (eine
Abwesenheit ohne Vorgeschichte belegt nichts), warum die Gegenprobe zur Sperre
zwei Hälften hat (sonst belegt sie nur, dass irgendetwas nicht ging), und warum
in Punkt 7d der **Eigentümer** der Tabelle wichtiger ist als ihre Zeilenzahl.

### Punkt 2 der Zwischenabnahme: ein Hinweis, der nicht befolgt werden kann

**Gefunden auf einem Bild, nicht von einem Test.** Bei gestopptem Cluster zeigte
„Einstellungen → Datenbankserver" den Hinweis *Rolle anlegen* mit dem Befehl
`sudo -u postgres psql -c "CREATE ROLE root SUPERUSER LOGIN"`. Der kann in genau
diesem Zustand nicht laufen: `psql` erreicht keinen Server. Die Seite gab also
eine Anweisung, deren einziges mögliches Ergebnis eine Fehlermeldung war —
gezeigt ausgerechnet dem, der gerade nicht weiterkommt.

Die Ursache war kein falscher Zweig. `Server::describe()` legte `handed_over` im
Grundzustand auf `false`, und drei der sieben Zustände überschreiben ihn nie:
`stopped`, `ambiguous` und `no_cluster` kommen gar nicht dazu, sich anzumelden.
Das Panel las `false` und schloss daraus „die Rolle fehlt", während die Wahrheit
**„nicht nachgesehen"** war.

> **Ein Vorgabewert, den niemand überschreibt, ist kein Vorgabewert — er ist die
> Antwort.**

Derselbe Satz stand zwei Tage vorher schon einmal hier, für
`env('SRVPANEL_VERSION', '0.1.0-dev')`. Dass er innerhalb einer Woche zweimal
zutrifft, ist der eigentliche Befund: Es ist kein Ausrutscher, sondern eine
Bauform. Ein Vorgabewert in einem `array_merge`-Grundzustand sieht genauso
harmlos aus wie ein zweites Argument an `env()`, und beide werden erst dadurch
falsch, dass die Überschreibung **anderswo** fehlt.

Die Angabe ist jetzt dreiwertig — `true`, `false`, `null` für „konnte nicht
nachsehen" —, und alle drei Stellen führen sie so: der Agent, der Controller
(`is_bool(…) ? … : null` statt `(… ?? false) === true`, was drei Werte auf zwei
einebnete) und die Vorlage (`=== false` statt `!handed_over`, denn `null` ist in
JavaScript ebenfalls falsch). **Eine fehlende Angabe ist ehrlicher als eine
falsche**, dieselbe Entscheidung wie bei der Sortierung einer
PostgreSQL-Datenbank.

`PgHandoverTest` prüft alle drei Stellen und beide Richtungen: dass der
Grundzustand nichts behauptet, **und** dass die gemessenen Fälle es ausdrücklich
sagen — ohne die zweite Hälfte wäre der Hinweis nie erschienen, auch dort nicht,
wo er hingehört. Zwei Brüche stehen im Skript, der dritte (der Controller) wäre
eine Änderung an einer Datei, die der Bruch der Seite schon erreicht.

**Was der Lauf sonst belegt hat:** Der gestoppte Cluster meldet sich als
`inactive` — über die Instanzunit `postgresql@16-main.service` und nicht über die
Sammelunit, die aktiv bleibt, wenn der Cluster steht. Der Fund vom 9. August ist
damit auf dem Server bestätigt und nicht nur repariert.

### Punkt 3 der Zwischenabnahme: jeder PostgreSQL-Zugang entstand in MariaDB

**Der teuerste Fund dieses Laufs, und der dritte derselben Bauform an einem
Tag.** `Databases::createUser()` trug
`DatabaseEngine $engine = DatabaseEngine::MariaDb`, und
`DatabaseController::createUserFor()` rief sie ohne dieses Argument. Wer zu
einer PostgreSQL-Datenbank einen Zugang anlegte, bekam deshalb `db.user.create`
— eine **MariaDB**-Kennung mit dem Systembenutzer als Präfix und dem
PostgreSQL-Namen in der Rechteliste.

Beide Zeilen sehen für sich richtig aus. Die Signatur ist sinnvoll, der Aufruf
ist sinnvoll, und in einer Welt mit einem Datenbanksystem waren sie es auch:
`MariaDb` war dort keine Annahme, sondern eine Tatsache. **Mit dem zweiten System
wurde aus derselben Zeile eine stille Wahl** — und niemand musste sie treffen.

> **Wer ein zweites Etwas einführt, erbt die Vorgabewerte des ersten.**

Die anderen beiden desselben Tages: `env('SRVPANEL_VERSION', '0.1.0-dev')` und
`handed_over => false` im Grundzustand von `Pg\Server::describe()`. Dreimal
derselbe Satz, dreimal an einer anderen Sprachstelle — Konfiguration,
Array-Grundzustand, Parametervorgabe. Es ist keine Nachlässigkeit, sondern eine
Bauform, und sie hat eine gemeinsame Gegenmassnahme:

**Der Vorgabewert ist weg, und der Wächter dagegen ist der Übersetzer.** Ohne
ihn kann kein Aufrufer die Frage übersehen — auch keiner, den es heute noch
nicht gibt. `EngineDefaultTest` hält nur fest, dass er nicht zurückkommt, und
prüft die Gegenrichtung mit: dass der Zugang sein System von der Datenbank
bekommt, an der er hängt, und nicht aus einem festen Wert eine Zeile weiter.

**Gefunden hat es der Betreiber auf einem echten Server**, in Punkt 3 der
Zwischenabnahme, nachdem MariaDB durchlief und PostgreSQL nicht. Kein Test hat
es gesehen — alle Tests, die einen Zugang anlegen, meinen MariaDB, und für die
war die Vorgabe richtig.

### Punkt 3, der eigentliche Fehler: PostgreSQL bekam eine MariaDB-Sortierung

```
ERROR: invalid LC_COLLATE locale name: "utf8mb4_unicode_ci"
```

**Keine einzige PostgreSQL-Datenbank liess sich anlegen — seit es die Funktion
gibt.** `DatabaseController::store()` füllte eine fehlende Sortierung mit
`?? $this->collations()[0]`, also mit der ersten **MariaDB**-Sortierung. Für
PostgreSQL zeigt das Formular das Feld gar nicht (`docs/38 §5`), es schickt also
nie eine — der Ersatzwert griff damit **immer**, und `pg.database.create` bekam
`utf8mb4_unicode_ci` als `LC_COLLATE`.

> **Ein Ersatzwert für etwas, das es nicht gibt, ist keine Vorsicht — er ist eine
> Behauptung.**

Der vierte Fehler derselben Bauform an einem Tag. Alle vier haben dieselbe
Herkunft: **ein zweites System, das die Ersatzwerte des ersten geerbt hat.**

| | |
|---|---|
| `env('SRVPANEL_VERSION', '0.1.0-dev')` | eine Variable, die niemand setzt |
| `'handed_over' => false` im Grundzustand | ein Zustand, den niemand gemessen hat |
| `DatabaseEngine $engine = MariaDb` | ein System, das niemand genannt hat |
| `?? $this->collations()[0]` | eine Sortierung, die es nicht gibt |

Die Sortierung ist jetzt `?string`, und **der Typ ist der Wächter**: Ein `string`
verlangt einen Wert, und wer keinen hat, erfindet einen — die Lücke *erzwang*
den Ersatzwert. `null` heisst „dieses System wählt sie nicht", und jeder Treiber
sagt selbst, was er daraus macht: MariaDB nimmt seine Vorgabe (dort, wo sie
gilt), PostgreSQL schickt gar kein `locale` und überlässt es dem Agenten, der es
neben `CREATE DATABASE` stehen hat.

**Kein Test hat es gesehen, und der Grund ist derselbe wie beim Vorgabewert für
das System:** Jeder Test, der eine Datenbank anlegt, gibt eine Sortierung mit,
weil er MariaDB meint. Für den war der Ersatzwert richtig.

### Und warum die Meldung niemanden erreichte

Der Fehler stand die ganze Zeit in einer `ValidationException` am Feld — nur
sichtbar wurde er nicht. Laravel leitet dabei **zurück**, und „zurück" ist die
letzte GET-Anfrage der Sitzung. Der Vorgangskanal `/operations/{id}/stream` ist
eine solche: `EventSource` schickt kein `X-Requested-With`, gilt damit nicht als
XHR und wird als vorige Seite gemerkt.

**Jeder Formularfehler landete deshalb auf einem Ereigniskanal statt auf dem
Formular** — und wenn der Vorgang einem anderen gehörte, mit einer 403. Der
Betreiber sah „nichts passiert", eine 403 ohne Auslöser und eine Flut von
`stream`-Anfragen; die Meldung, die alles erklärt hätte, kam nie an. Sichtbar
wurde sie erst in einem frischen Tab, in dem keine Vorgangsseite offen gewesen
war.

`CLAUDE.md` warnt seit P4 vor genau dieser Weiterleitung, und `RedirectTargetTest`
setzt es durch — **aber nur für `back()` im eigenen Code.** Die Weiterleitung
einer `ValidationException` macht das Framework.

> **Eine Regel mit Wächter, und daneben eine Tür, durch die dieselbe Regel
> gebrochen wird.**

Das ist die Lehre über Wächter, die dieser Tag den bisherigen hinzufügt: **Ein
Wächter deckt einen *Weg* ab, keine *Wirkung*.** Wer die Wirkung meint, sucht
nach dem zweiten Weg dorthin — und in diesem Fall lag er nicht im eigenen Code.

`KeepPreviousUrl` kennzeichnet den Kanal jetzt als das, was er ist: keine Seite,
zu der jemand zurückkehren könnte. Die Kennzeichnung ist die, die Laravel selbst
liest (`storeCurrentUrl()` überspringt XHR), und sie steht **vor** `can:` —
`storeCurrentUrl()` läuft auch dann, wenn der Zugriff abgewiesen wird, und eine
403 auf dem Kanal kaperte sonst weiterhin das „Zurück" der nächsten
Formularseite. Dieselbe Sorte Fehler wie „eine Prüfung, die eine Zeile zu spät
läuft" aus dem Abnahmelauf von P4.

`PreviousUrlTest` prüft die **Wirkung** und nicht die Kopfzeile: `ajax()` ist die
Frage, die das Framework stellt: welche Kopfzeile sie beantwortet, ist seine
Sache. Ein Test gegen `X-Requested-With` prüfte unsere Umsetzung gegen sich
selbst.

### Drei rote CI-Läufe für eine Zeile, die nichts geprüft hat

`PgHandoverTest` band sich mit `method_exists(Server::class, 'describe')` an die
Klasse, um die es ihm geht. Beide Namen stehen zur Übersetzungszeit fest, der
Aufruf ist immer wahr — PHPStan meldet `function.alreadyNarrowedType`, und er hat
recht: **Die Zeile prüfte nichts und sah aus wie eine Prüfung.**

Sie steht jetzt als Vergleich des Dateipfads gegen `ReflectionClass::getFileName()`
da und tut damit, was sie sollte: Zieht `Server` um, lesen die Textprüfungen
darüber eine andere Datei und wären mit ihr einverstanden.

**Teuer war nicht der Fehler, sondern dass er dreimal durchkam.** Der lokale
PHPStan-Durchgang lief über `app`, `agent/src` und `tests/Support` — nicht über
`tests/Unit` und `tests/Feature`. Genau dort sind an diesem Tag fünf neue Wächter
entstanden.

> **Ein Durchgang, der nicht überall läuft, wo geschrieben wird, ist kein
> Durchgang.**

`tests/Support` stand in der Liste, weil `CLAUDE.md` es ausdrücklich empfiehlt —
die Testdoppel dort hängen am Agenten, und ein fehlendes `method.abstract` bricht
den ganzen Lauf. Diese Begründung galt für *ein* Unterverzeichnis, und der
Durchgang ist bei ihm stehengeblieben, während die Wächter woanders wuchsen. Er
liest jetzt `tests` im Ganzen; vier Altbefunde daraus, die nur ohne larastan
entstehen, sind benannt statt stillschweigend geschluckt.

### Punkt 3, nachgemessen: die Sortierung kam aus einer Zeile statt aus dem Cluster

**Der fünfte Fehler derselben Bauform an einem Tag — und er stand in der Behebung
des vierten.** Seit das Panel für PostgreSQL kein Gebietsschema mehr mitschickt,
griff im Agenten `$args['locale'] ?? 'C.UTF-8'`. Diese Zeile war vorher nie
erreicht worden; ab dem Beitrag davor **war sie die Antwort.**

Gemessen auf `cloudsrv24`:

```
postgres / template0 / template1     de_DE.UTF-8
x90d271df69287335_kundendatenbank    C.UTF-8      ← die erste Kundendatenbank
```

`C.UTF-8` sortiert nach Bytes: In `ORDER BY name` steht „Äpfel" damit **hinter**
„Zebra". Für einen deutschen Kunden ist das sichtbar falsch — und anders als das,
was er in MariaDB bekommt.

**Gefragt statt angenommen, entschieden vom Betreiber.** Das Gebietsschema kommt
jetzt aus `template0`, also aus dem, was `initdb` gesetzt hat. Aus `template0`
und nicht aus `template1`, weil daraus auch angelegt wird: Ein Gebietsschema, das
zur Vorlage passt, ist immer zulässig. **Ohne Antwort wird nichts erfunden** —
ein Ersatzwert an dieser Stelle wäre derselbe Fehler noch einmal, er stünde still
da und würde eines Tages die Antwort.

Die Gegenrede gehört dazu: `C.UTF-8` ist stabil über Betriebssystem-Upgrades,
glibc ändert Sortierregeln zwischen Fassungen und macht damit Textindizes still
unbrauchbar. Der Betreiber hat die vertraute Sortierung vorgezogen; die
Entscheidung steht damit an einer Stelle, an der sie jemand getroffen hat.

**Gemessen wurde gegen einen Wegwerf-Cluster in diesem Container**, nicht
behauptet: die Abfrage nach `datcollate`, dass die Antwort das Muster passiert,
und dass `CREATE DATABASE … TEMPLATE template0 LC_COLLATE …` damit durchläuft und
die neue Datenbank den Wert trägt.

**Und ein Kommentar, der etwas Falsches sagte, ist mit weg.** In `Show.vue` stand
als Begründung für die fehlende Zeile „Sortierung": *„In PostgreSQL entstehen
Zeichensatz und Sortierung aus der Vorlage des Servers."* Das stimmte nicht — der
Agent legt immer mit `TEMPLATE template0` an und schreibt `LC_COLLATE`
ausdrücklich hin. Die Zeile fehlt weiter, aber jetzt aus dem Grund, der wirklich
gilt: Dieses Panel *wählt* die Sortierung für PostgreSQL nicht.

### Und die Zeile „Sortierung" steht jetzt auch für PostgreSQL da

**Der Grund für das Verstecken ist weggefallen, und damit das Verstecken.** In
`DatabaseController::row()` stand ein `=== MariaDb ? … : null`, und er war
richtig: Für PostgreSQL hätte dort der Vorgabewert aus P5 gestanden —
`utf8mb4_unicode_ci`, eine Angabe über eine Datenbank, die ihn nie gesehen hat.

Seit der Agent das Gebietsschema beim Cluster erfragt und in seiner Antwort
zurückmeldet, steht dort ein **gemessener** Wert. Und Sortierung ist keine
Nebensache: Sie ist die Frage, wegen der jemand seine Anwendung umschreibt.

> **Eine fehlende Angabe ist ehrlicher als eine falsche — eine unterschlagene ist
> beides nicht.**

Die Bedingung bleibt, hängt aber an der Angabe statt am System: Wo nichts steht,
steht keine Zeile. Das ist derselbe Gedanke wie vorher, nur an der richtigen
Frage festgemacht — und es ist der dritte Fall an diesem Tag, in dem eine
Bedingung von einer *Absicht* („welches System ist das?") auf einen *Zustand*
(„gibt es etwas zu sagen?") umgestellt wurde.

**Gemessen ist hier nichts nachzuholen:** Der längste Wert, der neu in die Zeile
kommt, ist ein Gebietsschema wie `de_DE.UTF-8` — elf Zeichen gegen die achtzehn
von `utf8mb4_unicode_ci`, die dort seit P5 stehen. Die Aufnahmen der laufenden
Seite kommen mit Punkt 4 der Zwischenabnahme.

### Punkt 4: die Spalte „System" hat es nie gegeben

Auf `cloudsrv24` stand am 10. August 2026 eine PostgreSQL-Datenbank in der
Liste, **ohne dass irgendwo stand, dass sie eine ist.** Gefunden auf einer
Aufnahme vom Telefon, hell und dunkel; die Kartenansicht zeigt fünf Zeilen, und
„System" ist keine davon.

`Databases/Index.vue` liest `props.shows_engine` seit Schritt 7. Der
Steuerungscode hat die Angabe **nie mitgeschickt**. In JavaScript ist
`undefined` falsch, also blieb `v-if="props.shows_engine"` immer aus — die
Spalte war gebaut, geprüft und unsichtbar.

**Es ist der Musterfehler dieses Projekts an einer Stelle, an der es ihn noch
nicht gab:** eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein
Test oder ein Werkzeug den Bezug prüft. Und diesmal liegt er genau *zwischen*
den Werkzeugen: `vue-tsc` prüft die Vorlage gegen ihre eigene Deklaration,
PHPStan prüft das Feld im Steuerungscode — die Brücke dazwischen ist eine
Zeichenkette in einem Array, und die sieht keiner von beiden.

**`DatabaseEngineTest` hat hier sogar zugebissen und trotzdem nichts gefunden.**
Er hat in Schritt 7 verlangt, dass die Frage „welches System?" vom Server
beantwortet wird und nicht im Template als Zeichenkette steht. Genau das ist
passiert — die Antwort wurde im Server *formuliert* und nie *abgeschickt*. Ein
Wächter, der die richtige Regel durchsetzt, sagt nichts darüber, ob sie
angekommen ist.

`InertiaPropsTest` vergleicht jetzt für jede Seite die Pflichteigenschaften aus
`defineProps<{…}>()` mit den Schlüsseln, die ein `Inertia::render()` mitgibt —
klammerweise auf der obersten Ebene, ohne Kommentare, abzüglich dessen, was
`share()` für alle beisteuert. **Er läuft über 31 Seiten und hatte genau einen
Befund:** diesen. Zwei Brüche sind gefahren worden, einer davon an einer ganz
anderen Seite (`Domains/Index`), damit die Regel nicht nur für ihren Anlass
gilt.

**Und das ist der Grund, warum dieser Testlauf auf Aufnahmen besteht.** Die
Seite war vollständig grün: Die Spalte stand im Markup, der Wert kam aus dem
Enum, das Kartenlayout trug `data-column`. Sichtbar war der Fehler erst auf
einem Telefon.

### Der Kernel steht jetzt in der Übersicht — und sagt, ob er der aktuelle ist

Auf Wunsch des Betreibers. Die interessante Angabe ist dabei nicht die Nummer,
sondern ob sie noch die richtige ist: **Nach einem `apt upgrade` läuft der alte
Kernel weiter, bis jemand neu startet**, und dem Panel sah man das bisher nicht
an.

**Der Kernel war schon da.** `SystemInfo` meldet ihn seit P1, der Steuerungscode
reicht ihn durch, `Overview.vue` erklärt ihn als Eigenschaft — und keine Zeile
hat ihn je gezeigt. Eine Angabe, die den ganzen Weg geht und am Ende nirgends
landet, ist Arbeit ohne Wirkung; aufgefallen ist es erst beim Bauen dieser Zeile.

**Gelesen wird `/boot` und kein Programm gerufen.** `uname -a` wäre ein Eintrag
mehr auf der Positivliste des Agenten — für eine Zeile in der Übersicht der
falsche Preis — und ausserdem ein Satz, aus dem man den Kernel erst
herausschneidet. Was als `vmlinuz-…` in `/boot` liegt, kann starten; das ist die
ehrliche Antwort auf „es gäbe einen neueren". `/lib/modules` wäre der schlechtere
Kandidat: Dort bleiben Verzeichnisse zurück, wenn ein Paket entfernt wird, und
ein Kernel ohne Abbild startet nicht.

**Und die Angabe ist von Anfang an dreiwertig.** Ist `/boot` leer oder unlesbar,
meldet der Agent `null` — nicht `false`. Die Oberfläche schweigt dann, statt „ist
aktuell" zu behaupten.

> **`null` heisst „nicht nachgesehen" und nicht „nein".**

Derselbe Satz hat an diesem Tag dreimal Geld gekostet: bei `handed_over` im
Grundzustand von `Pg\Server::describe()`, beim Vorgabewert für das
Datenbanksystem und beim Gebietsschema. Hier steht er im Code, bevor ihn jemand
bezahlen musste — und `KernelStaleTest` hält beide Enden fest: dass der Agent
nichts behauptet, und dass die Seite `=== true` prüft statt auf Wahrheit. `null`
ist in JavaScript ebenfalls falsch; `!kernel_stale` sähe richtig aus und meldete
auf jedem Server ohne lesbares `/boot` einen Neustart, den es nicht braucht.

**Der Vergleich ist an echten Namen gemessen**, nicht an ausgedachten:
`6.8.0-52` nach `6.8.0-51`, `6.11.0-9` nach `6.8.0-51`, `6.1.0-28` nach
`6.1.0-9`. Ein Melder, der grundlos Alarm gibt, wird bald gelesen wie ein
Rauschen.

### Lauf 484: ein fehlender Werttyp — und der Filter, der ihn verschluckt hat

`InertiaPropsTest::shared()` gab `array` ohne Werttyp zurück; PHPStan meldet das
auf Stufe 6 als `missingType.iterableValue`. Eine Zeile, eine Marke, erledigt.

**Interessant ist, warum es lokal nicht auffiel.** Der Durchgang in diesem
Container filtert Meldungen, die nur entstehen, weil larastan fehlt — und der
Ausdruck dafür strich `missingType` als Ganzes. Darunter fällt
`missingType.property` (`$signature` und `$description` an jedem
Artisan-Kommando, echtes Rauschen) — **und eben auch `missingType.iterableValue`,
das kein Rauschen ist.**

> **Ein Filter, der Rauschen entfernt, entfernt auch Befunde, die wie Rauschen
> aussehen.**

Derselbe Satz stand nach Lauf 466 schon einmal hier, damals für
`class.notFound`. Er ist jetzt zum zweiten Mal wahr geworden, und der Filter
nennt die Kennung ab sofort vollständig.

**Und die Gegenprobe hat gleich einen zweiten Befund freigelegt.** Mein Eingriff
in `SystemInfo` hatte an der *Signatur* von `distribution()` angesetzt und deren
Dokumentationsblock davor stehen lassen — `@return array{name:string,version:string}`
schwebte über der neuen Methode, und `distribution()` stand ohne. Das ist
wortwörtlich der Fehler aus `65a5a2b` vom 7. August, zum zweiten Mal: **Wer vor
einer Methode einfügt, fügt vor ihrem Dokumentationsblock ein — nicht vor ihrem
Namen.**

Gefunden hat ihn genau der Filter, den diese Runde geschärft hat.

### Die Spalte „System" steht jetzt immer da

Entschieden vom Betreiber beim Testlauf zu `rc.4`. Bis dahin hing sie an
`shows_engine` — einer Frage an den Bestand, die der Server beantwortete: Die
Spalte kam dazu, sobald die erste PostgreSQL-Datenbank entstand, und sie
verschwände wieder, wenn die letzte gelöscht wird.

> **Eine Tabelle, deren Spalten vom Inhalt abhängen, ist zweimal dieselbe
> Tabelle.**

Wer sie kennt, muss sie neu lesen; wer eine Aufnahme davon hat, hat eine von
zweien. Und die Spalte beantwortet auch dort eine Frage, wo alle Zeilen
dasselbe sagen — nämlich *welches* dieses eine System ist.

`shows_engine` ist damit ganz weg: aus dem Steuerungscode, aus der Vorlage, aus
der Eigenschaftsliste. **Eine Angabe weniger, die zwischen Server und Vorlage
verabredet sein muss** — und das ist der eigentliche Gewinn, denn genau an dieser
Verabredung ist sie vorgestern gescheitert.

**Der Bruch in `tests/waechter-brechen.sh` ist mitgezogen.** Er hat bis eben
`shows_engine` entfernt, um `InertiaPropsTest` zubeissen zu lassen; die Zeile
gibt es nicht mehr. Er greift jetzt `creatable` an — ein Eingriff, der auf eine
gelöschte Zeile zeigt, ist genau das Muster, gegen das `BreakScriptTest` da ist,
und es wäre peinlich, ihn im selben Beitrag zu erzeugen, der die Regel feiert.

### `docs/39` Punkt 5: eine Probe, die nach etwas fragte, das es nicht gibt

`current_setting('lc_collate')` antwortet auf PostgreSQL 16 mit
`ERROR: unrecognized configuration parameter`. **PostgreSQL 15 hat `lc_collate`
und `lc_ctype` als Laufzeitparameter entfernt** — sie sind seitdem nur noch
Eigenschaften einer Datenbank, zu lesen in `pg_database.datcollate`.

Wieder ein Stellvertreter: gefragt war „welche Sortierung hat diese Datenbank",
gefragt *wurde* eine Einstellung, die es in dieser Fassung nicht mehr gibt. Die
Probe steht berichtigt in `docs/39 §8` — mit dem Hinweis, dass ein **Serverfehler
an dieser Stelle schon die halbe Antwort ist:** Er heisst, dass die Anmeldung
über `127.0.0.1` gestanden hat.

Dazu zwei Spalten mehr in der Rollenabfrage: `rolsuper` und `rolcreatedb`, beide
`f`. Sie kosten nichts und belegen, dass die Rolle nichts kann, was sie nicht
soll — auf `cloudsrv24` gemessen.

### `docs/39` Punkt 5 und 6: zwei Befehle auf die falsche Sache gerichtet

**Das Kontingent steht nicht auf der Abonnement-Seite.** Diese Anleitung schickte
dorthin; dort listet der Abschnitt „Kontingente" aber nur, was der Plan erlaubt —
eine Auskunft über den *Vertrag*, nicht über den *Bestand*. Der Verbrauch steht
da, wo er eine Entscheidung beeinflusst: auf der Seite „Datenbank anlegen", als
*„Datenbanken: 2 von unbegrenzt. Datenbankbenutzer zählen nicht getrennt."* Der
Betreiber hat gemeldet, dass dort nur „unbegrenzt" steht — er hatte recht, und
die Anleitung nicht.

**Und `mysql.user` hat keine Spalte `account_locked`.** Seit MariaDB 10.4 ist
`mysql.user` nur noch eine Sicht auf `mysql.global_priv`, und die Sperre steht
dort im JSON-Feld `Priv`. Der Aufruf endet mit `ERROR 1054 Unknown column`.

Beides ist berichtigt, mitsamt dem Grund — **eine Anleitung, die auf die falsche
Seite zeigt, kostet denselben Umweg wie ein falscher Befehl**, und beide Male
merkt es nur, wer sie fährt. Das ist der dritte und vierte Befehl in `docs/39`,
den erst der Lauf selbst geradegezogen hat; genau dafür steht das Dokument im
Repo und nicht in einem Verlauf.

### `docs/39` Punkt 6: „Sperren" und „Zugriff entziehen" sind zwei Knöpfe

Die Anleitung sagte nur „Abonnement sperren". Der Betreiber hat **„Zugriff
entziehen"** auf der Datenbank-Detailseite gedrückt, und das Ergebnis sah eine
halbe Stunde lang nach einem schweren Fehler aus: CONNECT weg, `rolcanlogin`
unverändert, kein Vorgang in der Liste, MariaDB ungesperrt.

**Es war alles richtig.** „Zugriff entziehen" nimmt einer Rolle das CONNECT auf
*eine* Datenbank (`pg.role.grant`), unmittelbar und ohne Vorgang; „Sperren" nimmt
allen Rollen des Abonnements die Anmeldung (`pg.role.lock` → `NOLOGIN`) und läuft
über die Warteschlange. Zwei Knöpfe, zwei Mechanismen — und die Anleitung nannte
keinen von beiden beim Namen.

**Aufgeklärt hat es das Protokoll und nicht die Vorgangsliste.** Vorgänge zeigen,
was in der Warteschlange lief; das Protokoll zeigt, was *jemand getan hat*. Ich
habe zweimal nach Vorgängen gefragt, und beide Male war die Antwort „da steht
nichts" — richtig, und nutzlos.

> **Wenn eine Messung nicht zum Code passt, ist die nächste Frage nicht „was hat
> die Maschine getan", sondern „was hat der Mensch gedrückt".**

Geschenkt bekommen hat der Lauf dabei einen Beleg, der nicht auf dem Plan stand:
„Zugriff entziehen" und „Zugriff geben" arbeiten für PostgreSQL sauber und
verlustfrei — zweimal hin und zurück, `datacl` jedes Mal richtig.

### Eine Warnung, die bei jeder Freigabe erschien

Gemeldet vom Betreiber aus Vorgang 492:

```
usermod: unlocking the user's password would result in a passwordless account.
You should set a password with usermod -p to unlock this user's password.
```

**`usermod` hat sich geweigert, und das war richtig.** Der Systembenutzer eines
Abonnements hat kein Passwort — er wird ohne eines angelegt, seine Shell ist
`nologin`, der Zugang läuft über SFTP mit Schlüssel. Ein `--unlock` liesse das
Feld leer, und ein leeres Feld ist ein Konto ohne Passwort.

**Nur erschien die Meldung immer.** Kein Systembenutzer hat je ein Passwort, also
war das nicht der Ausnahmefall, sondern jeder Fall.

> **Ein Hinweis, der immer erscheint, erzieht dazu, die Ausgabe nicht zu lesen.**

Und die Ausgabe eines Vorgangs ist genau die Stelle, an der ein echter Fehler
auffallen soll — dasselbe Muster wie ein Melder, der grundlos Alarm gibt
(`docs/36 §22.3r`).

`subscription.resume` sieht jetzt vorher in `/etc/shadow` nach und entsperrt nur,
wo ein Passwort steht. **Die Sperre wird dadurch kein Stück schwächer:** `--lock`
beim Sperren bleibt, und `--expiredate` steht auf beiden Seiten **ohne
Bedingung** — das ist die Schranke, die SSH und SFTP tatsächlich prüfen.

Die Entscheidung ist eine reine Funktion über den Inhalt der Datei, damit sie
sich ohne `/etc/shadow` prüfen lässt. Die Felder darin sind gemessen und nicht
ausgedacht: `!` ist der Fall auf `cloudsrv24`, `!!` legt `useradd` an, `!*` ist
das ausdrückliche „keines". **Ist die Datei nicht lesbar, wird nichts
entsperrt** — `null` heisst „nicht nachgesehen", zum dritten Mal an diesem Tag.

**Und `AccountUnlockTest` hält die Untergrenze fest**, die hier teurer wäre als
die Regel selbst: Rutschte `--expiredate` in denselben Zweig wie `--unlock`,
bliebe ein freigegebenes Abonnement abgelaufen — auf jedem Server, denn kein
Systembenutzer hat ein Passwort. Aus einer stillen Warnung würde eine stille
Sperre.

### `docs/39` Punkt 7: die Prüfung, die dem Kunden gehört

Der Ablauf liess die Tabelle als `postgres` anlegen und prüfte nach dem
Zurückspielen die Zeilenzahl und den Eigentümer. **Beides beantwortet die Frage
des Betreibers und nicht die des Kunden.**

Der Weg beim Zurückspielen ist: `pg_dump --no-owner --no-privileges` wirft
Eigentum und Rechte weg, die befristete Rolle legt alles neu an, und
`REASSIGN OWNED BY … TO` überträgt es an den Eigentümer der Datenbank — an
`root`. Hatte der Kunde die Tabelle vorher selbst angelegt, gehörte sie ihm;
danach gehört sie `root`. **Ob er sie noch lesen kann, steht damit offen** — und
`sudo -u postgres` sieht davon nichts, weil ein Superuser immer darf.

Der Ablauf legt die Tabelle jetzt **als Kunde** an — so, wie es auf einem echten
Server zugeht — und liest sie nach dem Zurückspielen **als Kunde** wieder. Das
ist die Zeile, die entscheidet, ob eine wiederhergestellte Datenbank für ihren
Besitzer benutzbar ist.

> **Wer eine Wiederherstellung als Superuser prüft, prüft die Wiederherstellung
> und nicht ihren Zweck.**

### `docs/38` Entscheidung 11: eine Eigentümerrolle je Abonnement

Zwei Fehler aus Punkt 7 der Zwischenabnahme, beide gegen einen echten Cluster
gemessen, beide von demselben Unterschied zu MariaDB verursacht: Dort haben alle
Zugänge eines Abonnements dieselben Rechte auf dasselbe Schema; **in PostgreSQL
gehört eine Tabelle dem, der sie angelegt hat.**

- Ein zweiter Zugang bekommt `permission denied for table` auf alles, was der
  erste angelegt hat.
- Nach einem Zurückspielen gehört alles `root`, und der Kunde steht vor seinen
  eigenen Zeilen. Die Wiederherstellung meldete dabei „erledigt".

Und ein dritter, davor: **Ein Zurückspielen in eine Datenbank, die noch Tabellen
hat, scheitert überhaupt.** `pg_dump --format=plain` schreibt kein `DROP`;
`mysqldump` schreibt `DROP TABLE IF EXISTS` von sich aus. **P5b hat diese
stillschweigende Vorgabe geerbt, ohne dass jemand sie treffen musste** — dieselbe
Bauform wie an fünf Stellen zuvor an diesem Tag, nur diesmal geerbt statt
geschrieben.

`--clean` allein trägt nicht: Die befristete Rolle darf nicht wegräumen, was ihr
nicht gehört (`must be owner of table`, gemessen). **Das Leeren ist ein
privilegierter Vorgang, das Einspielen nicht.**

Die Entscheidung und ihre zwei verworfenen Alternativen stehen in `docs/38 §21`.
Was der Entwurf nebenbei erledigt, ist die schönste Zeile der Messung: Ist die
befristete Rolle Mitglied der Gruppe und läuft als sie, gehört ihr am Ende
**nichts** — und das `REASSIGN OWNED BY … TO`, an dem in Schritt 6 die
eingespielten Daten hingen, entfällt ersatzlos.

> **Ein Entwurf, der eine Fallgrube überflüssig macht, ist besser als einer, der
> sie umgeht.**

Bestandsdatenbanken zieht das Zurückspielen nach — es leert das Schema ohnehin
privilegiert, und die Rolle dort anzulegen kostet keine eigene Wanderung.

### Eine Domain, die sich selbst gesperrt hielt

Der Betreiber hat am 10. August ein Abonnement entsperrt, und die Domain darunter
blieb „gesperrt" — dauerhaft, ohne Knopf, der sie zurückgeholt hätte.

Der Weg dahin ist ein Kreis. `subscription.suspend` schreibt jeden Server-Block
neu, der Agent antwortet `suspended: true`, und der Web-Lebenslauf setzt die
Domain daraufhin auf `suspended`. Beim Entsperren baute derselbe Lebenslauf die
Argumente neu — und las dabei **genau diesen Zustand** wieder mit:

    'suspended' => $domain->status === DomainStatus::Suspended
        || $subscription?->status->usable() === false,

Das Abonnement war frei, die Domain nicht, also ging sie erneut gesperrt hinaus
und kam gesperrt zurück. Jeder weitere Versuch bestätigte nur, was der erste
hinterlassen hatte.

> **Ein Zustand, der aus dem Ergebnis der eigenen Entscheidung abgeleitet wird,
> hält sich selbst am Leben.**

`DomainStatus::Suspended` ist die *Anzeige* dieser Entscheidung und nicht ihre
Quelle — einen Weg, eine einzelne Domain zu sperren, gibt es nicht: keine Route,
keinen Knopf. Gefragt wird jetzt nur noch das Abonnement. Dieselbe Richtung, die
`DbLifecycle` für die Datenbankzugänge längst hat: Dort kommt `mode` aus der
*Aufgabe* und nie aus der Zeile, die sie ändert — deshalb kamen die Zugänge
zurück und die Website nicht.

**Und der Wächter war da, er zeigte nur in eine Richtung.**
`test_suspending_a_subscription_reapplies_its_sites` prüfte das Sperren, und das
Sperren war nie kaputt. Der Rückweg lief in keinem Test. Zum zweiten Mal in
dieser Woche derselbe Satz: *Ein Wächter deckt einen Weg ab, keine Wirkung.* Es
gibt jetzt zwei — einen auf die Argumente einer gesperrten Domain unter einem
freien Abonnement, einen auf den ganzen Weg von `subscription.resume` bis zum
Folgevorgang.

### Die Eigentümerrolle ist gebaut — und ein Kommentar war zwei Fassungen lang falsch

`docs/38 §21` Entscheidung 11 steht jetzt im Agenten: `Pg\Owner`, eine Rolle je
Abonnement (`<präfix>_owner`, `NOLOGIN`, ohne Passwort), der das Schema `public`
gehört. Jeder Zugang ist Mitglied, und **jede seiner Sitzungen läuft als sie** —
`ALTER ROLE … IN DATABASE … SET role`. Was ein Zugang anlegt, gehört damit dem
Abonnement; `session_user` bleibt der Zugang selbst, wer verbunden war steht
weiter im Protokoll von PostgreSQL.

Sechs Stellen: `pg.database.create` (Rolle anlegen, Schema übergeben),
`pg.role.create` (Mitgliedschaft), `pg.role.grant` (Sitzungsrolle je Datenbank,
`RESET` beim Entzug), `Pg\Ephemeral` (Mitglied und Sitzungsrolle),
`pg.restore` (Nachrüstung, privilegiertes Leeren) und `pg.database.remove` —
**der Weg zurück**, den `docs/35` erzwingt: Die Rolle geht mit der letzten
Datenbank ihres Abonnements, und ob es die letzte war, sagt der Katalog.

Gemessen am 10. August 2026 gegen PostgreSQL 16.13, mit den Anweisungen, die der
Code selbst erzeugt: `x_cron` liest, ändert und löscht, was `x_web` angelegt
hat; ein Zurückspielen in eine Datenbank mit Tabellen gelingt (vorher
`ERROR: relation "kunden" already exists`, Rückgabewert 3); die eingespielte
Tabelle gehört `x…_owner`; die befristete Rolle besitzt danach **0** Objekte,
und `DROP ROLE` geht ohne `REASSIGN OWNED BY`. Das `REASSIGN` ist deshalb weg —
es übertrug das Eingespielte an den Eigentümer der *Datenbank*, und die gehört
dem Panel.

**Und der teuerste Fund dieser Runde ist ein Kommentar.** In `PgRoleGrant` stand
seit zwei Fassungen `ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON
TABLES TO <rolle>` mit der Erklärung, das löse das Problem des zweiten Zugangs.
Es hat es nie gelöst: Ohne `FOR ROLE` gilt die Anweisung nur für Objekte, die
die **ausführende** Rolle anlegt — den Agenten. `PgGrantTest` hat sie geprüft und
dabei die Begründung mitgeprüft.

> **Ein Kommentar, der eine Wirkung behauptet, ist keine Messung — und ein
> Wächter, der ihn abschreibt, macht ihn zur Regel.**

Die zwei `GRANT`-Zeilen sind weg, die zwei `REVOKE` bleiben: Auf Servern einer
früheren Fassung stehen die Einträge noch in `pg_default_acl`. *Wer etwas nicht
mehr anlegt, baut den Weg zurück trotzdem.*

**Der Gegenbruch hat zwei neue Wächter als blind entlarvt**, bevor sie eine
Fassung erlebt haben. `PgOwnerTest` liest im Quelltext, ob das Leeren vor dem
Einspielen steht — und fand `Owner::reset(` zuerst in einem `{@see …}` im
Kommentar darüber. Der Test blieb grün, als die Reihenfolge absichtlich
vertauscht wurde. Seitdem geht der Quelltext durch `WithoutPhpComments`:

> **Ein Wächter, der im Quelltext liest, liest auch, was jemand über den
> Quelltext geschrieben hat.** Und in diesem Projekt steht darüber viel.

### Und die Freigabe eines Abonnements hat jetzt einen Wächter für alle drei

`SubscriptionResumeReachTest`. Ein Abonnement hat drei Sorten Untergebene —
Websites, MariaDB-Zugänge, PostgreSQL-Rollen —, und geprüft war bisher von
keinem, dass die *Freigabe* sie erreicht: `EngineScopeTest` sieht nach, ob die
Lebensläufe die Aufgabe kennen (`handles()`), nicht ob danach ein Vorgang mit
`mode: unlock` entsteht. Genau in dieser Lücke sass der Domainfehler darüber.
Beide Richtungen stehen jetzt in einem Test, und in einem: Drei getrennte
liessen offen, ob *zusammen* alles herauskommt — und genau das war der Fall, der
schiefging.

### Lauf 491: fünf rote Tests, vier Ursachen — und zwei davon lagen schon da

**Zwei stammen aus `v0.5.1-rc.4` und sind mit ihm ausgeliefert worden.**
`SubscriptionStateTest` verlangte `--unlock` in den Argumenten der Freigabe und
zählte, dass Sperren und Entsperren sich in genau drei Methoden unterscheiden.
Beides hat die Behebung der Dauerwarnung aus Vorgang 492 umgeworfen: `--unlock`
hängt seitdem daran, ob es ein Passwort zu entsperren gibt, und `unlocks()` ist
eine vierte eigene Methode. Der Zweig hat danach keinen vollen Lauf mehr
gesehen — deshalb fiel es erst jetzt auf.

Die Zusicherung ist nicht weggefallen, sondern umgezogen: Was `SubscriptionStateTest`
prüft, ist die Schranke, die SSH und SFTP wirklich lesen (`--expiredate`, ohne
Bedingung); ob ein `--unlock` dazugehört, prüft `AccountUnlockTest` in beide
Richtungen. Und `unlocks()` steht im Methodenvergleich jetzt **namentlich** statt
als aufgeweichte Grenze — eine fünfte beisst weiter.

**Der dritte ist der Preis der Ehrlichkeit von oben.** Der Bruch zu
`ALTER DEFAULT PRIVILEGES … GRANT` suchte eine Zeile, die es nicht mehr gibt;
`BreakScriptTest` hat ihn gemeldet, wie er soll. Er zeigt jetzt auf die Ebene,
die wirklich trägt: `GRANT ALL ON ALL SEQUENCES` — ohne sie bekommt ein Zugang
`permission denied for sequence` beim ersten `INSERT` in eine Tabelle mit
`serial`.

**Der vierte war ein Aufbaufehler im neuen Test**, und er hat etwas Richtiges
gezeigt: `SubscriptionResumeReachTest` legte ein Abonnement ohne Zeile in
`system_users` an, und der PostgreSQL-Lebenslauf brach mit „Zum Systembenutzer
p1077 gibt es kein Datenbankpräfix" ab. Auf einem echten Server entsteht sie in
`Lifecycle::claim()`; die Fabrik kennt sie nicht, weil sie zum **Server** gehört
und nicht zum Abonnement (`docs/35`). Der Test baut sie jetzt selbst — das ist
der Aufbau, den er meint: ein Abonnement, das PostgreSQL benutzen kann.

### Und `BreakScriptTest` hat einen Teil seines Gegenstands nie gelesen

Beim Nachrechnen von Hand fiel ein **zweiter** toter Eingriff auf, den der Lauf
nicht gemeldet hatte: Der Bruch zu `CertificateLifecycle` suchte
`$domain->certificate_id !== null || ! $this->settings->configured()` — eine
Bedingung, die der zweite Wurf von P4 durch `choice->satisfied()` ersetzt hat.
Der Grund für die Stille ist banal und teuer: Der Ausdruck las nur Blöcke mit der
Heredoc-Marke `PY2`, und **neunzehn Blöcke im Skript tragen `PY`**.

> **Ein Wächter, der einen Teil seines Gegenstands nicht liest, meldet für den
> Rest „alles in Ordnung".**

Beide Marken werden jetzt gelesen, mit einer Rückreferenz statt einer zweiten
Alternative — sonst endete ein `PY2`-Block an der ersten Zeile `PY` darin. Der
Bestand wächst damit von 225 auf 244 Blöcke und von 246 auf 247 geprüfte
Eingriffe; tot ist keiner mehr.

### `docs/39` Punkt 7 zieht mit dem Entwurf mit

Der Ablauf erwartete nach dem Zurückspielen `root` als Eigentümer, beschrieb
`REASSIGN OWNED BY … TO` als den Weg dorthin und kannte den Fall „Zurückspielen
in eine Datenbank, in der schon Tabellen stehen" gar nicht. Alle drei sind seit
`v0.5.1-rc.5` überholt.

> **Ein Ablauf, dessen Erwartung nicht mit dem Entwurf mitzieht, prüft die
> vorige Fassung.**

Neu gefasst: der Eigentümer ist `<präfix>_owner` (mit der Tabelle, welche der
zwei Antworten bei welchem Alter der Datenbank richtig ist), das Kaputtmachen vor
7d legt zusätzlich eine Tabelle **dazu**, und drei Fragen prüfen, was dem Kunden
gehört — lesen, schreiben, und `current_user`/`session_user` als Erklärung für
die ersten beiden. Dazu drei neue Schritte: **7d-2** spielt ein zweites Mal
zurück, diesmal in eine volle Datenbank (der erste Lauf lief in eine leere — ein
Erfolg, der nichts über die Wiederholbarkeit sagt), **7e** prüft den zweiten
Zugang, und Punkt 1 misst vorher, dass es die Eigentümerrolle **noch nicht**
gibt und `public` noch `root` gehört. Ohne diese Vormessung wäre die Nachrüstung
eine Beobachtung statt eines Belegs.

Punkt 9 zählt die Eigentümerrolle jetzt mit: Sie entsteht mit der ersten
Datenbank und geht mit der letzten. Bleibt sie stehen, ist der Rückbau
unvollständig — auch wenn er grün gemeldet hat.

### Zwei Abweichungen im Abnahmeablauf, die keine waren

Punkt 1 von `docs/39` fragte `rolname LIKE '%\_owner'` und erwartete `0`. Auf
`cloudsrv24` kam `1` — getroffen hat die Zeile `pg_database_owner`, eine
**eingebaute** Rolle (gemessen: `oid < 16384`), die es seit PostgreSQL 14 in
jedem Cluster gibt. Und die Zeile darunter erwartete `root` als Eigentümer des
Schemas `public`; seit PostgreSQL 15 gehört es `pg_database_owner` — gemessen an
einer frisch angelegten Datenbank auf 16.13.

> **Ein Wächter, der nach einer Endung fragt, findet, was so endet.**

Beide Zeilen haben eine Abweichung erzeugt, die keine war — in dem Dokument, das
Abweichungen erkennen soll. Gefragt wird jetzt nach dem Namen, den dieses Panel
vergibt (`<präfix>_owner`, exakt), und der Eigentümer des Schemas wird als
**Eigenschaft** geprüft (`nspowner = '<präfix>_owner'::regrole`) statt als Name:
Was davor dasteht, unterscheidet sich zwischen den vier Zielplattformen, und die
Frage lautet ohnehin nur „gehört es schon dem Abonnement".

Der Beleg für die Nachrüstung wandert damit auf die zwei Zeilen, die auf
`cloudsrv24` leer geblieben sind und deshalb tragen: Mitgliedschaft und
`pg_db_role_setting`. Sie sind das, was die Nachrüstung an einer
Bestandsdatenbank wirklich ändert.

**Der Code ist davon nicht betroffen.** `Owner::roles()` fragt mit
`starts_with(rolname, '<präfix>_')` **und** `rolcanlogin`, `Owner::ensure()` und
der Rückbau fragen den Namen exakt — eingebaute Rollen tragen weder das Präfix
noch ein Anmelderecht.

### `docs/40`: die Anzeige steht in UTC, und das war niemandem gesagt

Der Betreiber hat im Protokoll einen Eintrag um `12:31:26` gesehen und gefragt,
ob das deutsche Zeit sei. Es war UTC — `config/app.php` setzt sie so, und
`toDateTimeString()` geht als Zeichenkette in die Seite, die der Browser nicht
umrechnet. In der Sommerzeit sind das zwei Stunden.

**Ein Zeitstempel, den man falsch liest, ist schlimmer als keiner** — er sieht
aus wie eine Auskunft. Und die Filter „Von"/„Bis" im Protokoll vergleichen
ebenfalls gegen UTC: Wer abends nach 22:00 Uhr „heute" filtert, bekommt einen
Tag, der zwei Stunden vorher zu Ende ging. Die Seite zeigt dann eine Zeile, die
ihr eigener Filter nicht findet.

Entschieden sind die drei Fragen, an denen der Zuschnitt hängt: **serverweit**
statt je Konto, die **Filter rechnen mit**, und das **CSV bleibt UTC** — ein
Zeitstempel ohne Zone in einer Datei, die drei Jahre liegt, ist eine Falle.
Gemessen ist auch der Umfang: achtzehn Stellen, alle in Controllern, keine in
einer Vue-Komponente.

Gebaut wird nach P5b. Eine Änderung, die während einer Abnahme jede Zeitangabe
verschiebt, erklärt eine Messung, statt sie zu bestätigen.

### `v0.5.1-rc.5` hat einen Kunden ausgesperrt — mit einem Passwortwechsel

Der teuerste Fehler dieser Runde, und er stammt aus der Behebung von vorgestern.
`PgRoleCreate` setzte `ALTER ROLE … IN DATABASE … SET role = <präfix>_owner` und
liess das Schema, wie es war. Der Kunde arbeitete fortan als eine Rolle, die an
`public` **kein einziges Recht** hat. Gemessen auf `cloudsrv24`:

    current_user      → x90d271df69287335_owner
    current_schemas() → {pg_catalog}                    public fehlt
    CREATE TABLE      → ERROR: no schema has been selected to create in
    DROP TABLE IF EXISTS kunden → „does not exist, skipping"

Ausgelöst hat es ein **Passwortwechsel** — der harmloseste Vorgang, den dieses
Panel kennt. Und die Meldung zeigt nicht auf die Ursache: PostgreSQL überspringt
in `search_path` jedes Schema, in dem die Rolle nicht anlegen darf, und meldet
erst, wenn keines übrig ist. Kein `permission denied`, sondern
„kein Schema ausgewählt".

> **Wer umstellt, als wer jemand arbeitet, schuldet ihm alles, was er vorher
> hatte.**

**Die Bauform des Fehlers ist die bekannte:** `PgRoleGrant` hatte die fehlende
Zeile, `PgRoleCreate` nicht — zwei Stellen, dieselbe Aufgabe, eine nachgezogen.
Deshalb steht die Übereignung jetzt einmal in `Owner::adopt()` und wird von allen
vier Operationen gerufen, statt dreimal abgeschrieben zu werden.

**Und das Schema allein reicht nicht.** Für eine Datenbank, die es vor der
Eigentümerrolle gab, gehören die Tabellen dem einzelnen Zugang. Gemessen, beide
Wege:

    GRANT ALL ON ALL TABLES TO <owner>  → lesen ja, ALTER TABLE:
                                          „must be owner of table"
    REASSIGN OWNED BY <zugang> TO …     → lesen ja, ALTER TABLE ja

> **Ein Recht ersetzt kein Eigentum.** `ALTER` und `DROP` fragen nach dem
> Eigentümer, nicht nach der Rechtezeile.

`Owner::adoption()` schickt deshalb `ALTER SCHEMA public OWNER TO`,
`REVOKE ALL ON SCHEMA public FROM PUBLIC` und ein `REASSIGN OWNED BY` über alle
Zugänge der Datenbank. Dass dasselbe `REASSIGN` in `Pg\Ephemeral` entfallen ist,
ist kein Widerspruch: Dort zeigte es auf den Eigentümer der **Datenbank** — das
Panel —, und genau das war Fehler 2 aus Punkt 7. Hier zeigt es auf das
Abonnement.

**Der Wächter, der gefehlt hat**, prüft jetzt das Paar und nicht die einzelne
Zeile: `test_whoever_sets_the_session_role_hands_over_the_database` — eine
Methode, die `Owner::sessionRole(…, true)` schickt und kein `adopt()`, ist genau
der ausgelieferte Zustand. Dazu
`test_the_adoption_takes_ownership_and_not_only_a_privilege`, weil ein `GRANT`
an derselben Stelle grün aussähe und den Kunden seine eigene Tabelle nicht
ändern liesse.

Gefunden hat den Fehler der Abnahmelauf, und zwar an einer Stelle, an der er nur
wie ein Bedienfehler aussah: Ein `DROP TABLE IF EXISTS` meldete „skipping" für
eine Tabelle, die es gibt. *Ein `IF EXISTS`, das „skipping" sagt, hat nicht
nachgesehen, ob es das Ding gibt — sondern ob es ihm sichtbar ist.*

### `docs/39` ist durchgelaufen — und hat vier Fehler gefunden, keinen davon ein Test

Punkt 1 bis 9 gegen `v0.5.1-rc.6` auf `cloudsrv24`. Das Ergebnis steht in
`docs/39 §12a`; die vier Fehler stehen einzeln weiter oben. Drei davon hat erst
der **zweite** Anlauf desselben Punktes gezeigt, und der teuerste sah aus wie ein
Bedienfehler: Ein `DROP TABLE IF EXISTS` meldete „skipping" für eine Tabelle, die
es gibt — der Zugang sah sein eigenes Schema nicht mehr.

Die teuerste Zahl des Laufs ist die `0` in Punkt 9. Sie schliesst die
Eigentümerrolle ein: Sie entsteht mit der ersten Datenbank und geht mit der
letzten. Ohne den Weg zurück hätte dort `1` gestanden, und niemand hätte es
gemerkt — genau die Lücke, die `docs/35` einmal zwölf private Schlüssel gekostet
hat.

**Und der Lauf hat etwas offengelassen, das er selbst freigelegt hat.**
`srvpanel db` meldet „Nichts liegengeblieben", kann diese Frage für PostgreSQL
aber gar nicht stellen: Der Statusteil ruft nur `db.server.info`, das Feld
`stale_roles` aus `pg.server.info` liest niemand, und `--prune` räumt mit drei
MariaDB-Operationen. Die Zahlen stimmen — sie zählen Zeilen ohne Rücksicht auf
`engine` —, die Reichweite nicht.

> **Ein Werkzeug, das Entwarnung gibt, muss die ganze Fläche sehen können, über
> die es Entwarnung gibt.**

Deshalb musste Punkt 7f von Hand mit `SELECT … FROM pg_roles` messen.

### `srvpanel db` sieht jetzt beide Systeme

Der Abnahmelauf hat es freigelegt: Das Kommando meldete `Nichts liegengeblieben.`
und konnte diese Frage für PostgreSQL gar nicht stellen. Drei Stellen, drei
verschiedene Arten von Blindheit:

- **Der Statusteil** rief nur `db.server.info`. In der Ausgabe stand
  `mariadb 10.11.14 — nutzbar` und keine Zeile über den PostgreSQL-Cluster.
- **`stale_roles`** gab es im Agenten seit Schritt 6 — bei jedem Aufruf
  gerechnet und von niemandem gelesen. Eine befristete Rolle, die ein
  abgebrochenes Zurückspielen stehenliess, wurde nie gemeldet. *Ein Feld, das
  niemand liest, ist keine Auskunft, sondern Rechenzeit.*
- **`--prune`** räumte mit `db.user.remove`, `db.database.remove` und
  `db.dump.remove`. Eine liegengebliebene PostgreSQL-Rolle wäre an
  `Db\Names::existing()` abgewiesen worden — und hätte den ganzen Lauf
  mitgenommen.

> **Ein Werkzeug, das Entwarnung gibt, muss die ganze Fläche sehen können, über
> die es Entwarnung gibt.**

Der Rückbauplan trägt jetzt `engine` je Zeile, und die Verzweigung ist ein
`match` ohne Vorgabe: Kommt ein drittes System dazu, will diese Stelle einen
Fehler und keine stille Einordnung unter MariaDB. Die **Sicherungen** bleiben bei
`db.dump.remove` — ein Dump ist eine Datei, die Operation kennt kein SQL, und
eine Verzweigung wäre dort die zweite Fassung derselben Sache.

`DbCommandReachTest` ist der Wächter, und er ist die stillere Schwester von
`AgentOperationReachTest`: Dort wird eine Operation gebaut und nicht gerufen,
hier wird sie gerufen und eine ihrer Antworten nicht gelesen.

**Und der Gegenbruch hat ihn sofort als blind entlarvt — zum dritten Mal in
dieser Woche.** Der erste Entwurf hängte beide Methodenrümpfe aneinander und
suchte darin nach `pg.server.info`; er blieb grün, als der *Aufruf* aus
`showServer()` verschwand, weil `reportPostgres()` weiter dastand.

> **Eine Methode, die niemand ruft, beantwortet keine Frage.**

Geprüft wird jetzt das Paar: der Aufruf in `showServer()` **und** die Frage in
`reportPostgres()`.

### Das Bruchskript fährt jetzt von selbst

**`tests/waechter-brechen.sh` steht seit dem Optik-Rework im Repo und ist als
Ganzes nie gelaufen.** In der Entwicklungsumgebung fehlt `vendor/`, also wurde es
von Hand und stückweise gefahren — und genau dort war es allein in dieser Woche
**dreimal** fündig: dreimal ein Wächter, der grün blieb, während seine Regel
gebrochen war (`PgOwnerTest` fand `Owner::reset(` im Kommentar, `BreakScriptTest`
las nur Blöcke mit der Marke `PY2`, `DbCommandReachTest` hängte zwei
Methodenrümpfe aneinander).

> **Ein Werkzeug, das man nur von Hand fährt, fährt irgendwann niemand mehr.**

`.github/workflows/waechter.yml` fährt es auf Zuruf und montags um 04:00 UTC.
Ein eigener Ablauf und kein Job in `ci.yml`, aus zwei praktischen Gründen: Das
Skript **ändert Dateien** im Arbeitsbaum und stellt sie über git wieder her — in
einem Lauf, der nebenher ein Paket baut, wäre das ein Wettlauf um denselben
Baum —, und es fährt die Testsuite **473 Mal**, einmal je Prüfung. Das gehört
nicht vor jeden Pull Request.

Der Wächter dazu steht in `BreakScriptTest` und sucht den **Aufruf**, nicht den
Dateinamen: Ein Kommentar, der das Skript erwähnt, ist keine Ausführung.

### Eine Speichergrenze, die nichts begrenzte

Der Betreiber hat am 10. August zwei Abonnements angelegt. Beide Vorgänge
meldeten „fertig, 100 %", und in ihrer Ausgabe stand:

    setquota: Cannot find mountpoint for device
    setquota: No correct mountpoint specified.

**Die Ursache liegt nicht im Code.** Gemessen: `/var/www/vhosts` liegt auf `/`
(`/dev/vda3`, ext4), und die Mount-Optionen sind `rw,relatime` — **ohne
`usrquota`**. Die Quota ist auf diesem Server nicht eingeschaltet, und das darf
der Betreiber so wollen. `Mounts::deviceFor()` hat richtig gearbeitet.

**Der Fehler ist das Verschweigen.** `DiskQuota::apply()` gibt seit jeher
`['enforced' => false, 'reason' => …]` zurück und bricht ausdrücklich nicht ab —
ein Abonnement soll nicht scheitern, weil ein Dateisystem keine Quota kann. Nur
hat diese Antwort in `app/` **niemand gelesen**. Im Panel stand „15360 MB" und
meinte es nicht; die Wahrheit stand als rohes `stderr` neben einer grünen
Fortschrittsleiste.

Die Auskunft steht jetzt in zwei Spalten am Abonnement — dreiwertig, `null`
heisst „nicht nachgesehen" —, und die Seite sagt es **über** den Zahlen: Wer eine
Grenze liest, hat sie geglaubt, bevor er weiterliest. Der Grund kommt wörtlich
vom System; ein „konnte nicht gesetzt werden" hülfe beim Beheben nicht.

### Und der Wächter darüber, weil es das dritte Mal war

`handed_over`, `stale_roles`, `quota.enforced` — dreimal an einem Tag dieselbe
Bauform: **Der Agent meldet einen Fehlschlag innerhalb eines erfolgreichen
Vorgangs, und die Meldung kommt nirgends an.** Alle drei sahen im Panel aus wie
Erfolg.

> **Ein Feld, das niemand liest, ist keine Auskunft, sondern Rechenzeit.**

`AgentAnswerReachTest` führt sie als Liste mit Begründung **je Eintrag** —
dieselbe Form wie `Pg\Shielding::EXEMPT`, damit sie nicht wächst, bis sie alles
enthält. Geprüft werden **beide Richtungen**: Fehlt die Antwort im Agenten, ist
der Leser ein Griff ins Leere; fehlt der Leser, ist die Antwort Rechenzeit.

Ein Ausdruck über *alle* Schlüssel wäre in einer Woche abgeschaltet — die meisten
sind Belege fürs Protokoll und gehören in keine Oberfläche. Was der Test
ausdrücklich **nicht** prüft, ist, ob die lesende Methode gerufen wird; alle drei
Leser sind privat, und das meldet PHPStan auf Stufe 6 bereits. Eine zweite
Fassung derselben Regel wäre die, die veraltet.

### Die Quota an drei Stellen — und `docs/41`

Der Betreiber hat nach dem Befund oben `usrquota` in die `fstab` genommen und
neu eingehängt. Danach stand in `/proc/mounts` `rw,relatime,quota,usrquota` —
und `quotaon -p /` sagte weiter **`is off`**. Die Quotadatei war nie angelegt
worden.

> **Eine Option, die etwas erlaubt, ist nicht dasselbe wie ein Zustand, in dem
> es geschieht.**

Genau darauf wäre der erste Entwurf hereingefallen: Er sollte die Mount-Optionen
lesen. Gemessen wird jetzt der **Leseversuch** — `repquota` scheitert, solange
die Quota nicht läuft, und dieses Scheitern *ist* die Antwort.

**Drei Stellen, drei Fragen.** Die Übersicht sagt, ob der Server überhaupt Quota
führt (aus dem Messlauf, der ohnehin jede Viertelstunde läuft — kein neues
Programm, keine neue Operation, kein Aufruf beim Seitenaufbau). Die
Abonnementseite sagt, ob *diese* Grenze gilt. Der Vorgang zeigt wörtlich, was
`setquota` gesagt hat. Alle drei schweigen, solange nichts gemessen wurde.

**Und ein Weg, die Grenze anzuwenden, ohne sie zu ändern.** `update()` reiht
`subscription.quota` nur bei einem *anderen* Wert ein — richtig, aber es liess
den Betreiber, der gerade die Quota eingeschaltet hat, ohne Ausweg: Er hätte die
Grenze umstellen und zurückstellen müssen.

> **Eine Einstellung, die sich nur durch eine Änderung anwenden lässt, hat
> keinen Weg zurück in einen Zustand, den jemand anderes verändert hat.**

Der Knopf steht **beim Befund** und nicht bei den anderen: Er erscheint genau
dann, wenn die Grenze nachweislich nicht gilt. Und es gibt ihn nicht für alle
Abonnements auf einmal — das wäre ein Klick und hundert Vorgänge.

`docs/41` trägt die Anleitung, samt der Zeile, die man vergisst (`quotacheck`),
und dem Hinweis auf den Rettungszugang: Eine falsche `fstab` ist der klassische
Weg zu einem Server, der nicht mehr hochkommt.

Die vierte Antwort steht jetzt in `AgentAnswerReachTest`: `subscription.usage`
meldet `available: false` samt Grund, und das stand bis heute nur im Journal des
Timers.

### Der erste vollständige Bruchlauf hat 473 gesunde Wächter als kaputt gemeldet

Und der Fehler lag im Werkzeug. `pruefe()` las die Ausgabe von PHPUnit als
**JSON** — ein `python3 -c` mit `json.load` auf die Standardeingabe, das den
Schlüssel `result` erwartet. `vendor/bin/phpunit` schreibt kein JSON und hat es
nie getan; die Fassung ist gegen eine Umgebung entstanden, die Werkzeugaufrufe
in `{"tool":…,"result":…}` verpackt.

In der CI fiel damit **jede einzelne** der 473 Prüfungen in den Zweig „kein
Ergebnis", und die Schlusszeile las sich als Urteil über zweihundert fremde
Regeln: *„473 Prüfung(en) ohne Biss — diese Wächter halten ihre Regel nicht."*

> **Ein Parser, der nie zum Ziel passt, meldet nicht „ich kann das nicht" — er
> meldet, was er stattdessen findet.**

Gelesen wird jetzt, was PHPUnit wirklich schreibt, in **vier** Fällen: `passed`
(`OK (` und `OK, but there were issues!`), `failed` (`FAILURES!`, `ERRORS!`),
**`kein Test`** — das fängt einen vertippten Filter, der sonst als Biss
durchginge — und **`unlesbar`**, das auffällt, statt still zu sein.

**Und das Skript beweist zuerst, dass es messen kann.** `vorpruefung` fährt vor
dem ersten Eingriff einen Test, von dem feststeht, dass er grün ist, und bricht
sonst mit der Ausgabe von PHPUnit ab.

> **Ein Werkzeug, das über Wächter urteilt, muss zuerst beweisen, dass es messen
> kann.**

Die Schlusszeile unterscheidet jetzt außerdem: Steht hinter jedem Fehlschlag eine
fehlende Messung, sagt sie *„dieses Skript hat nichts gemessen, und über die
Wächter ist damit nichts gesagt"* — statt an 473 Stellen einen Fehler zu
behaupten, an denen keiner ist.

**Der Wächter darüber hat sich beim Schreiben selbst gefangen.** Er verlangt,
dass der JSON-Ausdruck nicht im Skript steht — und war rot, weil der Kommentar,
der den alten Ausdruck erklärt, ihn wörtlich zitierte. Zum vierten Mal in dieser
Woche liest ein Wächter die Erklärung statt des Codes.

Der Lauf hat damit genau das getan, wofür er gebaut wurde: Er hat beim ersten
Mal einen Fehler gefunden. Nur einen anderen als erwartet.

### Und der erste Lauf, der wirklich gemessen hat, fand fünf blinde Stellen

Nachdem `pruefe()` lesen konnte, was PHPUnit schreibt, ist derselbe Lauf noch
einmal gefahren. Von 473 Prüfungen blieben **fünf** ohne Biss übrig — fünf
Regeln, deren Bruch grün durchlief. Keine davon ist ein kaputter Wächter im
üblichen Sinn; jede ist eine andere Art, an der Regel vorbeizuzielen.

**Ein Beispiel in der richtigen Reihenfolge.** `NetcupTest` löscht aus drei
Einträgen den einen, der Name *und* Wert trifft — nur stand der mit dem fremden
Namen an dritter Stelle, und `find()` gibt den ersten Treffer zurück. Ohne den
Namensabgleich kam derselbe Satz heraus.

> **Ein Test, dessen Beispiel in der richtigen Reihenfolge steht, prüft die
> Reihenfolge und nicht die Regel.**

Der fremde Name steht jetzt vorn; gemessen ist beides, mit und ohne die Zeile
in `Netcup.php`.

**Eine Regel, die an zwei Stellen steht.** Der Bruch zu `CertificateChoice`
ersetzte die Deckungsprüfung in `usable()` — und `candidates()` prüft dieselbe
Deckung noch einmal. Über `satisfied()` läuft der Weg durch beide, der Bruch
war also keine Regression, sondern eine Umformung. Er nimmt jetzt jedes
`coversAll` dieser Datei.

**Ein Befund, der an der Maschine hing.** `XmlRpcTest` misst, dass eine externe
Entität nichts holt — mit `LIBXML_NOENT` statt der Marken der Klasse steht der
Inhalt von `/etc/hostname` im Wert. Das gilt für libxml 2.9.14 und damit für
Debian 12, wo dieses Panel läuft; die neueren Fassungen der CI lassen externe
Entitäten gar nicht erst zu, und dort bleibt der Wert auch mit `LIBXML_NOENT`
leer. Der Bruch lief deshalb im Container rot und in der CI grün.

> **Ein Wächter, dessen Befund an der Fassung einer Systembibliothek hängt,
> misst die Maschine und nicht die Regel.**

Der messende Fall bleibt — er ist die Messung, und die Gefahr ist auf der
Zielplattform echt. Dazu kommt einer, der die Marken im Quelltext liest und auf
jeder Maschine beisst; der Bruch zielt ab jetzt auf ihn.

**Ein Bruch, den jemand anderes repariert hat.** `DocLinkTest` sollte an einer
Dokumentnummer scheitern, die es nicht gibt — die Nummer war `docs/39`, und
dieses Dokument ist im selben Wurf entstanden.

> **Ein Bruch, dessen Gegenstand von aussen kommt, wird von aussen repariert —
> und meldet das nicht.**

Der fünfte war kein Wächter, sondern der Eingriff selbst: ein Python-Block, den
`python3` nicht lesen konnte. Er stand seit P5 im Skript.

### Ein Bruchskript, dessen Eingriffe nicht laufen, misst nichts

`BreakScriptTest::test_every_embedded_block_is_valid_python` fährt jeden der 252
eingebetteten Blöcke durch `ast.parse` und meldet, was daran nicht lesbar ist.
Gefunden hat er genau einen — den Eingriff zu `EngineDefaultTest`, dessen
mehrzeilige Nadel als einfach begrenzte Zeichenkette geschrieben war. Der Block
brach beim Übersetzen ab, `python3` schrieb seinen Fehler nach stderr, und das
Skript lief weiter, als wäre der Eingriff erfolgt.

> **Ein Wächter, der den Inhalt prüft, hat nichts über die Ausführbarkeit
> gesagt.**

Der bestehende Wächter prüfte bis dahin, dass jede Nadel in ihrer Zieldatei
**steht** — was sie tat. Dass der Block, der sie ersetzt, nie lief, hat er nicht
gesehen. Beide Fragen gehören zusammen, und sie stehen jetzt nebeneinander.

### Ein Knopf, der genau dort fehlte, wofür er gebaut war

`disk_quota_enforced` kam am 10. August 2026 ohne Backfill dazu, und die
Abonnementseite hängte den Knopf „Grenze erneut anwenden" an `=== false` — also
an eine **Messung**. Für die beiden Abonnements auf `cloudsrv24` gab es die nie:
Sie waren angelegt worden, bevor es die Spalte gab, und standen damit auf
`null`. Das Panel zeigte eine Speichergrenze, wandte sie nicht an und bot
keinen Weg, das zu ändern — ausser die Grenze zu *ändern*, weil
`SubscriptionController::update()` `subscription.quota` nur bei einem
abweichenden Wert einreiht.

> **Ein Knopf, der an einer Messung hängt, fehlt dort, wo nie gemessen wurde.**

Der dreiwertige Wert war richtig; falsch war, eine **Handlung** an genau einem
seiner drei Werte aufzuhängen. `null` führt jetzt zum selben Knopf, aber nicht
zum selben Satz: „gilt nicht" ist ein Befund und wird gewarnt, „nicht
nachgesehen" ist eine Auskunft und bleibt nüchtern. Ein Abonnement aus der Zeit
vor der Spalte bekäme sonst eine Warnung über einen Zustand, den niemand
gemessen hat — dieselbe Sorte Meldung wie die, die im August bei jeder Freigabe
erschien. Beide Hälften haben einen Wächter, die Seite und die Route: Ein
sichtbarer Knopf, den die Route abweist, ist die Falle aus `AbilityReachTest` in
der anderen Richtung.

### Und die Meldung daneben war 65px zu breit — ausgeliefert

Auf der Abonnementseite standen in `<p class="notice warn">` vier direkte
Kinder: ein `strong` und drei Kennungen. `.notice` ist `display: flex` ohne
`flex-wrap`, und damit ist jedes davon ein Flex-Item, das **neben** den anderen
steht statt mit ihnen umzubrechen. Bei 390px schob die Meldung die Seite um
**65px** aus dem Bild. Einzeln lief keine der drei Kennungen über — erst
zusammen.

Das ist derselbe Fehler wie der aus P4, der 83px gekostet hat, und er ist auf
demselben Weg gefunden worden: von einer Messung bei 390px, nicht von einem
Test. Der Bereich war vollständig grün. Ausgeliefert war er mit
`v0.5.1-rc.7` — die Screenshot-Runde war beim Bauen der Quota übersprungen
worden, weil ohne `vendor/` kein `artisan serve` läuft. Der Weg dafür stand seit
P4 in `CLAUDE.md` und ist diesmal gegangen worden: das gebaute Stylesheet aus
`public/build`, das Markup in einer eigenen Datei, gerendert im
vorinstallierten Chromium.

> **Eine Regel, die nur eine Seite befolgt, ist keine Regel, sondern ein
> Zufall.** `Overview.vue` wickelte seinen Text am selben Tag richtig ein.

`NoticeShapeTest` prüft jetzt alle 44 Meldungen der Oberfläche: Wer mehr als ein
Kind in eine Meldung setzt, wickelt sie in ein `span`. Geprüft wird die **Form**
und nicht die Breite — ob etwas überläuft, beantwortet nur ein Rendering, und
hier läuft kein Browser. Dieselbe Wahl wie bei `SiteTemplateTest`.

**Und derselbe Screenshot hat einen zweiten Fehler gezeigt**, den kein Test
findet: Der Absatz las sich als „…for device Der Weg dorthin steht in…". Eine
wörtlich übernommene Systemmeldung endet nicht verlässlich mit einem Punkt, und
was danach kommt, klebt an ihr. Sie steht jetzt am Satzende — auf beiden
Seiten, denn `Overview.vue` hatte dieselbe Reihenfolge.

**Und der Nachtrag dazu, weil er eine Runde CI gekostet hat:** Der Bruch zu
diesem Wächter war gegen den *alten* Wortlaut der Meldung geschrieben, und die
Umstellung des Satzes kam danach. Zwei seiner Nadeln zeigten damit ins Leere.
Gemerkt hat es `BreakScriptTest`, nicht die Wegwerfprobe daneben — die las die
Eingriffe über Pythons `ast` statt über denselben Ausdruck wie der Wächter und
meldete „0 tot" für dieselbe Datei.

> **Ein Prüfwerkzeug, das anders liest als der Wächter, gibt Entwarnung für
> etwas anderes.**

Die Probe liest jetzt mit demselben Ausdruck und derselben Entwertung wie
`BreakScriptTest`; gegengeprüft an genau diesem Fall — vorher zwei tote Nadeln,
nach der Reparatur keine.

### Der Abnahmelauf hat einen Fehlerweg gefunden, der selbst scheitert

**Punkt 8 von `docs/38 §19`**, gefahren am 11. August 2026 auf `cloudsrv24`: Ein
hochgeladener Dump wollte `ALTER ROLE … SUPERUSER`. Der Agent hat ihn abgewiesen
und genau das gemeldet, was das Abnahmekriterium verlangt — die Anweisung, ihre
Zeilennummer, den Grund:

    Das Zurückspielen ist gescheitert: psql:….restore.sql:74:
    ERROR:  permission denied to alter role
    DETAIL:  Only roles with the SUPERUSER attribute may change the
    SUPERUSER attribute.

**Im Panel stand davon nichts.** Dort stand *„Der Vorgang wurde von der
Warteschlange abgebrochen — vermutlich Zeitüberschreitung"*, an einem Vorgang,
der **eine Sekunde** lief.

Die Kette: `operations.message` war `varchar(255)` — angelegt als
`$table->string('message')`, die Voreinstellung, über die nie jemand nachgedacht
hat. Die Begründung ist 260 Zeichen lang. `OperationRecorder::fail()` schrieb
sie, MariaDB wies sie ab (`SQLSTATE[22001]`), und die `PDOException` flog aus
genau dem `catch (AgentException)`-Zweig heraus, der den Fehlschlag festhalten
sollte. Der Auftrag starb, Laravel rief `failed()`, der Vorgang stand noch
offen — und bekam die Vermutung dieses Handlers.

> **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**

> **Ein Fehlertext, der eine Ursache rät, ist schlimmer als einer, der keine
> nennt — er beendet die Suche.**

Und die Pointe steckt in der Länge: **Je wichtiger die Begründung, desto länger
ist sie.** „Datei nicht gefunden" passte immer in die Spalte. Die abgewiesene
Anweisung eines fremden Dumps — die einzige Auskunft, an der Kriterium 5 hängt —
passte nie.

Behoben an allen drei Stellen: Die Spalte ist `text`. Der `OperationRecorder`
kürzt auf 8 KB und sagt es (dieselbe Regel, die seine **Ausgabe** seit dem
ersten Tag hat — nur die Meldung hatte sie nie). Und `failed()` unterscheidet
jetzt an der Klasse der Ausnahme statt an einer Vermutung, meldet sie über
`report()` ins Protokoll des Panels und nennt sonst nur, was es weiss.

**Und der Wächter dazu hätte den Fehler beinahe wieder nicht gefunden.** Der
erste Entwurf war ein Verhaltenstest: schreiben, zurücklesen, vergleichen. Er
wäre grün gewesen — auch mit der alten Spalte. Diese Tests laufen gegen SQLite
im Speicher, und SQLite hält sich nicht an `varchar(255)`; es legt jede Länge
hinein.

> **Ein Test, der gegen eine andere Datenbank läuft als der Server, prüft die
> Grenzen der falschen.**

Genau daran ist dieser Fehler zwei Jahre vorbeigekommen: 1647 Tests, alle grün,
und keiner konnte die Breite der Spalte sehen. Geprüft wird sie jetzt am
**Schema**; die beiden anderen Stellen prüft ihr Verhalten.

### Und Punkt 9 hat einen Zugang gefunden, der den Rückbau überlebt hat

Nach dem Rückbau von `cloudlab24.de` auf `cloudsrv24` waren Datenbanken,
Sicherungen und die Eigentümerrolle fort — und `x45c97683d84c369c_web` stand
noch im Cluster. Der Vorgang `subscription.remove` meldete **fertig, 100 %**.
Gefunden hat es `srvpanel db`, das seit P5 genau dafür gebaut ist: *„1 Zeile(n)
ohne Abonnement — Zugang x45c97683d84c369c_web (PostgreSQL, cloudlab24.de)"*.

Die Ursache ist ein Zeitpunkt und kein vergessener Aufruf. Ein Zugang geht mit
seiner Datenbank, wenn er an keiner anderen hängt — und `removeAllFor()` reiht
**alle** Datenbanken des Abonnements auf einmal ein. Jeder dieser Vorgänge
berechnet seine Listen beim Einreihen, also während die anderen Datenbanken
noch dastehen: Bei `_shop` hängt `_web` noch an `_blog`, bei `_blog` noch an
`_shop`. Beide Vorgänge lassen ihn stehen.

> **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die
> anderen Vorgänge derselben Reihe nicht.**

Beim Rückbau hat die Frage keinen Gegenstand mehr — es verschwindet ja alles.
`usersOf()` beantwortet sie deshalb nicht mehr, wenn das Abonnement geht: Jeder
verbundene Zugang steht dann in der Liste, die mitgeht. Dass eine Rolle dabei
zweimal genannt wird, schadet nicht; der Agent entfernt sie mit `IF EXISTS`.

**Der Fehler trifft MariaDB genauso** und ist nur nie aufgetreten: Er verlangt
ein Abonnement mit mehr als einer Datenbank *und* einen Zugang an mehreren. Der
Abnahmelauf von P5 hatte diese Kombination nicht.

**Offen und benannt:** Ein Zugang, der an **gar keiner** Datenbank hängt, kommt
an keinen dieser Vorgänge und bliebe weiter stehen. Der Weg dorthin ist eine
gelöste Zuordnung; ob es ihn im Panel überhaupt gibt, ist ungeprüft. Er wird
hier nicht mitbehoben — ein Code, dessen Fall niemand gemessen hat, ist die
zweite Fassung einer Regel, und die veraltet.

### Erst hatte diese Meldung keinen Weg ins Panel, dann keinen Platz darin

Der Nachlauf zu Punkt 8 auf `cloudsrv24`: Mit `v0.5.1-rc.8` kommt die Begründung
des Agenten am Vorgang an — wörtlich, mit Zeilennummer, wie das Kriterium es
verlangt. Sie lautet:

    Das Zurückspielen ist gescheitert: psql:/var/lib/srvpanel/dumps/
    cloudlab24.de/.x729e5e5e3cc7e369-shop-20260811-083543-471485f4.restore.sql:67:
    ERROR:  permission denied to alter role …

Der Pfad darin ist **hundert Zeichen ohne ein einziges Leerzeichen**, und
`.notice` ist eine Flexbox. Bei 390px schob die Vorgangsseite damit **110px**
aus dem Bild — gemessen unmittelbar nachdem die Meldung überhaupt erst ankam.

`overflow-wrap: anywhere` steht jetzt an der Meldung selbst und nicht an einer
einzelnen Stelle:

> **Was in einer Meldung steht, kommt von aussen.** Vom Agenten, vom
> Betriebssystem, von einem fremden Anbieter — und keine dieser Quellen kennt
> die Breite eines Telefons.

`anywhere` und nicht `break-word`, aus demselben Grund wie bei `.ident`: Nur
`anywhere` verkleinert auch die min-content-Breite, und die hält ein Flex-Kind
sonst auf seiner Inhaltsbreite fest. Gemessen im Chromium, beide Themes:
110 → 0.

**Das ist der dritte Umbruchfehler dieser Art** — nach den 83px aus P4 und den
65px von gestern. Alle drei waren vollständig grün getestet, und alle drei hat
dieselbe Handbewegung gefunden: das gebaute Stylesheet, das Markup in einer
eigenen Datei, `scrollWidth - clientWidth` bei 390px.

### Punkt 9, zweiter Anlauf: die Rolle ging, die Zeile blieb

Der Rückbau eines Abonnements mit zwei Datenbanken auf `cloudsrv24`, gegen
`v0.5.1-rc.8`. Die Rolle `x729e5e5e3cc7e369_web` war diesmal **fort** — der Fix
von gestern wirkt. Dafür stand nun eine Zeile im Panel: die Datenbank
`x729e5e5e3cc7e369_shop`, ohne Abonnement. Gemeldet hat es wieder `srvpanel db`.

Die Ursache steht in der Fehlermeldung des Vorgangs, und die gibt es nur, weil
`operations.message` seit rc.8 `text` ist:

    553  pg.database.remove  failed
         ERROR:  role "…_web" cannot be dropped because some objects depend on it
         DETAIL:  privileges for database …_blog

Seit gestern nennt beim Rückbau **jeder** Datenbankvorgang alle Zugänge — sonst
bliebe einer stehen, der an zweien hängt. Damit lief der **erste** Vorgang in
ein `DROP ROLE`, das PostgreSQL verweigert, solange die Rolle an der zweiten
Datenbank noch Rechte hat. Er scheiterte **nach** dem `DROP DATABASE`: Der
Cluster war sauber, seine Zeile blieb. Ein Fehler war durch einen kleineren
ersetzt.

> **Eine Reihenfolge, die erst beim Ausführen entsteht, kann beim Einreihen
> niemand kennen.**

Das Panel entscheidet weiterhin, **ob** ein Zugang mitgehen soll — das ist eine
Frage an seinen Bestand. Ob er es **jetzt kann**, ist eine Frage an den Zustand
des Clusters, und die beantwortet der Agent unmittelbar vor dem `DROP ROLE`:
Hängt an der Rolle noch etwas, überspringt er sie und meldet sie nicht als
entfernt. Das Panel behält ihre Zeile, und der Vorgang der nächsten Datenbank
nimmt sie mit — beim Rückbau ist das die letzte.

**Gemessen gegen einen echten Cluster** statt geglaubt: Nach dem Werfen der
ersten Datenbank liefert die Abfrage `1`, und `DROP ROLE` scheitert mit genau
der Meldung von oben; nach der zweiten liefert sie nichts, und `DROP ROLE`
gelingt. Sie sagt voraus, was der Server tun wird, statt einen Fehler zu deuten
— eine Textprüfung auf eine englische Fehlermeldung wäre bei der ersten
lokalisierten Ausgabe still gescheitert.

Verbunden wird über `pg_roles` und nicht über `::regrole`: Die Umwandlung wirft,
sobald es die Rolle nicht mehr gibt, und in genau dem Fall lautet die Antwort
„nein" und nicht „Fehler". Derselbe Fallstrick wie in `docs/39`.

### P5b ist abgenommen — sieben Kriterien, sechs Fehler, keinen davon ein Test

Der Abnahmelauf nach `docs/38 §19` ist am 11. August 2026 auf `cloudsrv24`
durchgelaufen. **Alle sieben Kriterien aus `docs/38 §3` sind belegt**, das
Protokoll steht in `docs/42` — mit den gemessenen Werten und nicht mit „hat
funktioniert".

Er hat **sechs Fehler gefunden, und keinen davon ein Test.** Vier fand ein
echter Server, zwei eine Messung im Browser bei 390px. Und drei davon hat erst
der jeweils vorige Fix sichtbar gemacht: Die Begründung des Agenten kam nicht
an, weil ihre Spalte zu kurz war; kaum kam sie an, schob sie die Seite aus dem
Bild; und der Fix am Rückbau erzeugte einen zweiten, kleineren Rest, den nur die
inzwischen lesbare Fehlermeldung erklärte.

Der Plan trug an drei Stellen nicht — ein Vergleich, den der Bau selbst
abgeschafft hatte, eine Messung, die zwei Möglichkeiten nicht unterscheidet, und
ein mehrdeutiges `<abo>` im Dumppfad. Alle drei stehen in `docs/42 §2`, samt dem
Beleg, der weiterhin aussteht.

### Und eine Sicherung sagt jetzt, wann sie entstanden ist

`created_at` ging seit jeher an den Browser; die Tabelle „Sicherungen" zeigte
Name, Grösse, Zustand und Aktion. Zwei Sicherungen desselben Tages waren nur
über den Dateinamen zu unterscheiden — `…-20260811-093136-15abd902` —, und der
ist eine Kennung und kein Datum. Gemeldet vom Betreiber.

> **Ein Feld, das niemand liest, ist keine Auskunft, sondern Rechenzeit.**

Derselbe Satz wie bei der Quota, nur eine Grenze weiter: dort Agent → Panel,
hier Panel → Browser. Der Wächter dazu prüft deshalb nicht diese eine Spalte,
sondern die Ablage: **Was der Controller über eine Sicherung schickt, steht auch
auf der Seite.** Eine Liste einzelner Felder wäre gepflegt worden, bis das erste
vergessen wird — und genau das war ja passiert.

Das Format ist dasselbe wie bei „Begonnen", „Beendet" und „Gemessen am"; die
Zeit steht in UTC wie jede andere im Panel, und `docs/40` stellt das für alle
Stellen zugleich um statt für diese eine.

### `docs/40` beginnt: eine Klasse, die aus UTC eine Uhrzeit macht

`App\Support\Time\Clock` steht — die einzige Stelle, die aus einer gespeicherten
Zeit eine Anzeige macht und aus einer Filtergrenze wieder UTC. Dieselbe Bauform
wie `Names::fqdn()`, das viermal neu erfunden wurde, bevor es einen Wächter
dafür gab.

Gespeichert wird weiter in UTC; die Frage war nie, wo die Zeit herkommt,
sondern was auf der Seite steht.

**Und der Plan hat beim ersten Messen einen Denkfehler gezeigt.** `docs/40 §3.2`
nannte als Grenzfall einen Eintrag um `23:30` Ortszeit, „obwohl er in UTC am
nächsten Tag liegt". Für `Europe/Berlin` stimmt das nicht: `23:30` Ortszeit ist
`21:30` UTC, derselbe Tag. Bei einem **positiven** Offset kippt nicht der Abend,
sondern der frühe Morgen — `00:30` Ortszeit ist `22:30` UTC des Vortags.

> **Ein Beispiel, das die Richtung nicht mitdenkt, prüft die falsche Grenze.**

`ClockTest` prüft beide Enden des Tages, dazu die Rückfallregel: Eine unbekannte
Zone wirft nicht, sondern fällt auf UTC zurück — `setTimezone()` würde bei einem
Tippfehler mitten im Aufbau einer Seite werfen, an achtzehn Stellen. Verhindert
wird der Tippfehler beim **Setzen**.

Noch nicht gebaut: die achtzehn Aufrufe, das Feld in den Einstellungen und der
Bruch im Wächterskript. Die Klasse ändert bis dahin nichts — niemand ruft sie.

### Und die Filter des Protokolls rechnen mit

Die Anzeige im Protokoll geht durch `Clock`, und die Grenzen „Von" und „Bis"
gehen den Weg zurück: Sie kommen in der Anzeigezone herein und werden vor der
Abfrage nach UTC gedreht.

> **Ein Filter, der eine andere Zeitrechnung benutzt als die Anzeige daneben,
> findet die Zeile nicht, die er selbst anzeigt.**

Beides zusammen und nicht nacheinander — eine umgestellte Anzeige ohne
mitrechnenden Filter ist genau der Zustand, vor dem `docs/40 §3.2` warnt, und
er bricht still.

**Das CSV bleibt UTC** (`docs/40 §3.3`) und sagt es jetzt auch: Die Kopfzeile
heisst „Zeitpunkt (UTC)". Der Export baut seine Zeile aus derselben Abfrage,
ersetzt den Zeitstempel aber durch den gespeicherten Wert. Ein Zeitstempel ohne
Zone in einer Datei, die drei Jahre liegt, ist eine Falle — er wird gelesen,
wenn der Server längst umgezogen und die Einstellung eine andere ist.

### Und die übrigen dreizehn Stellen

Vorgänge, Domains, Abonnements, Kunden, Übersicht, Profil, Datenbanken, der
SSE-Kanal und die Testmail geben ihre Zeiten jetzt durch `Clock`. Zwei Stellen
tun es weiterhin nicht, und beide stehen mit ihrem Grund in
`TimeDisplayTest::EXEMPT`:

- **das CSV des Protokolls** — ein Beleg, den jemand aufhebt (`docs/40 §3.3`);
- **`Settings`** — dort wird *geschrieben* und nicht angezeigt. Eine Zeit in der
  Anzeigezone zu speichern hiesse, den Bestand von einer Einstellung abhängig
  zu machen, die sich ändern darf. Wer sie später zeigt, dreht sie an der
  Lesestelle.

Der Wächter zählt beide Richtungen: keine Stelle ausserhalb der Liste, und die
Liste selbst nicht leer — läuft der Ausdruck ins Leere, meldete er sonst „alles
in Ordnung" für eine Fläche, die er nicht mehr liest.

**Und er hat beim Einbauen einen bestehenden Bruch mitgenommen.** Der Eingriff
zu `OperationStreamTest` suchte `$operation->started_at?->toDateTimeString()` —
genau die Zeile, die jetzt durch `Clock` geht. `BreakScriptTest` hätte das in
der CI gemeldet; hier fand es die Gegenprobe vor dem Commit.

### Und die Zone lässt sich einstellen — auf einer Seite, die es nicht gab

`docs/40` verlangte „ein Feld in Einstellungen", und es gab keinen Ort dafür:
Die fünf vorhandenen Seiten sind themengebunden — PHP, Datenbankserver,
Mailversand, Zertifikat, DNS —, und das Profil gehört einem Konto. Die
Anzeigezone ist serverweit und hat mit keinem Dienst zu tun.

**Eine Seite mit einem Feld ist wenig, und das ist in Ordnung.** Der Ort für
serverweite Anzeigeeinstellungen fehlte; ihn beim ersten Bedarf anzulegen ist
billiger, als das Feld irgendwo unterzubringen, wo es niemand sucht.

Die Auswahl kommt aus `DateTimeZone::listIdentifiers()` und ist **kein
Freitextfeld**: Der Wert geht in `setTimezone()`, und ein unbekannter Name wirft
dort — mitten im Aufbau einer Seite. Geprüft wird beim Setzen, nicht beim Lesen.

**Und die Gegenprobe steht neben dem Feld:** dieselbe Zeit zweimal — was in der
Datenbank steht und was auf der Seite stünde, mit der Zonenmarke dahinter. Ohne
sie wäre die Auswahl eine Behauptung, und genau daran hing der Anlass:

> **Ein Zeitstempel, den man falsch liest, ist schlimmer als keiner — er sieht
> aus wie eine Auskunft.**

Das Zeichen im Menü ist eine Uhr und kein Zahnrad — das steht auf jeder zweiten
Oberfläche für „Einstellungen" überhaupt, und hier ist schon die ganze Gruppe
eine.

Gemessen im Chromium bei 390px, beide Themes: kein waagerechter Überlauf.
