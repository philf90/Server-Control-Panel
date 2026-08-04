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
