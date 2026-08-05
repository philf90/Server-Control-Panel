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
