# Changelog

Alle nennenswerten Änderungen. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung nach
[SemVer](https://semver.org/lang/de/).

Die Einträge unter **Unveröffentlicht** stehen im Repository, sind aber noch
nicht als Release getaggt.

## [Unveröffentlicht]

## [0.4.1] — 2026-07-31

**Die alte Oberfläche ist weg.** Was 0.4.0 unter `/alt/` als Rückweg
stehengelassen hat, ist entfernt: 18 Vorlagen, 12 statische Skripte, das
eingecheckte CodeMirror-Bundle samt seiner Node-Kette, 68 Routen und die Handler
dahinter. Unterm Strich **19.400 Zeilen weniger** bei 773 neuen.

Damit gibt es einen Weg an die Systemfunktionen und nicht zwei. Solange es zwei
gab, war jede Frage nach dem Verhalten des Panels zweimal zu beantworten — und
eine Bestätigungsstufe, eine Rollenprüfung oder eine Pfadwache, die man an einer
von zwei Stellen korrigiert, ist nicht korrigiert.

**Für Sie ändert sich, wenn Sie 0.4.0 laufen haben, sichtbar nur eines:** Ein
Lesezeichen auf `/alt/…` antwortet jetzt 404 statt der eingefrorenen Fläche. Die
Adressen der neuen Oberfläche sind unverändert, es gibt keine Migration, und die
Sitzungen bleiben gültig.

### Entfernt

- **Die server-gerenderte Panel-Oberfläche** unter `/alt/`: 68 Routen, 18
  Vorlagen (`dashboard`, `services`, `service`, `packages`, `firewall`, `files`,
  `file-entry`, `file-edit`, `logs`, `audit`, `account`, `users`, `sysusers`,
  `reset`, `certificate`, `update`, `totp-change`, `bestaetigung`), 12 Skripte
  (`live.js`, `bestaetigen.js`, `spark.js`, `job.js`, `update.js`, `certform.js`,
  `zielwahl.js`, `rechte.js`, `countdown.js`, `files-upload.js`,
  `passkey-register.js`, `theme.js`) und die Handler dazu. Der `unused`-Linter
  war dabei das Werkzeug: Er hat 124 tote Deklarationen gefunden, von denen ein
  Teil erst nach dem Löschen der vorigen sichtbar wurde.

- **Die zweite Node-Kette.** `packaging/editor/` baute mit esbuild ein eigenes
  CodeMirror-Bundle nach `internal/ui/static/editor/cm.js` für die alte
  Editorseite — mit eigenem Lockfile, eigenem Reproduzierbarkeits-Job und eigener
  Lizenzprüfung in der CI. CodeMirror kommt jetzt allein aus `web/`. Zwei
  Lockfiles sind zwei Gelegenheiten, dass eines veraltet; `make editor` und der
  CI-Job „Editor-Bundle reproduzierbar" sind entfallen.

- **Die Hintergrunderhebung des Handlungsbedarfs.** Der Messtakt zog alle fünf
  Minuten `systemctl list-units --failed` und den apt-Stand nach und legte das
  Ergebnis in einem Cache ab, aus dem die Warnpunkte an der Symbolschiene
  gespeist wurden — sie standen auf *jeder* gerenderten Seite, und sie bei jedem
  Seitenaufbau frisch zu erheben hätte jede Seite an ein `systemctl` gehängt. Mit
  der Schiene ist der einzige Leser des Caches gegangen. Was geblieben wäre, ist
  eine Erhebung für niemanden. `/api/v1/signals` erhebt frisch bei jeder Anfrage:
  teurer pro Aufruf, ehrlicher im Ergebnis.

- **Der zweite Antwortweg des Uploads.** `POST /api/v1/files/upload` lieferte
  ohne `Accept: application/json` die gerenderte Verzeichnisliste zurück, damit
  ein Formular ohne JavaScript eine Antwort bekam. Die Seite gibt es nicht mehr;
  ein API-Endpunkt, der je nach Kopfzeile einmal JSON und einmal HTML liefert,
  ist keine Schnittstelle, sondern zwei.

- **Der Umschalter für Hell/Dunkel** auf den Seiten vor der Anmeldung. Der Knopf
  saß im Fuß der Symbolschiene. Ein bestehendes `asylum_theme`-Cookie gilt
  weiter — wer hell gewählt hat, behält es —, und die Systemeinstellung
  (`prefers-color-scheme`) wirkt wie bisher. Einen neuen Umschalter bringt die
  neue Oberfläche mit, sobald sie ein helles Schema hat.

### Geändert

- **`internal/ui` trägt nur noch die neun Seiten vor der Anmeldung.** Anmeldung,
  Ersteinrichtung, zweiter Faktor, Wiederherstellungscodes, „Passwort
  vergessen", das neue Passwort danach, der erzwungene Wechsel und die
  Fehlerseite — dazu die Teilvorlage mit Rahmen und Passwortprüfung. Sie bleiben
  server-gerendert, und der Grund ist immer derselbe: **Wer sich nicht anmelden
  kann, kann auch kein Bundle laden.** `app.css` schrumpfte dabei von 2.259 auf
  343 Zeilen; die Statusleiste, die Schiene, die Konsole, die Tabellen, die
  Aufklapper und das Rechtegitter sind mit ihrem Markup gegangen.

- **Zwei neue Wächter halten den Abbau fest** (`internal/ui/vorseiten_test.go`,
  vormals `responsiv_test.go`): Eine zehnte Vorlage unter `templates/` oder eine
  fünfte Datei unter `static/` bricht den Testlauf mit der Frage, ob die Seite
  wirklich vor die Anmeldung gehört. Ohne das wäre ein server-gerendertes
  Formular auf Dauer der bequemere Weg — und damit der Anfang einer zweiten
  Oberfläche, diesmal versehentlich.

- **Die Deckungsgrenze für `internal/httpd` steigt von 63 auf 68 %** (gemessen
  70,0 %). Der Abbau nahm überwiegend ungetestete Anzeigelogik mit; eine Grenze,
  die danach sieben Punkte unter dem Stand liegt, hält nichts.

- **Ein Browserdurchlauf weniger, derselbe Nachweis.** Der Passkey-Test fuhr die
  Registrierung zweimal: einmal über `/alt/account` (Modus `flow`), einmal über
  `/konto` (Modus `v2`). Es gibt eine Kontoseite, also einen Durchlauf — jetzt
  `konto`, und er prüft zusätzlich, dass am Passkey nach der Anmeldung nicht mehr
  „noch nie benutzt" steht.

## [0.4.0] — 2026-07-31

**Die neue Oberfläche ist die Oberfläche.** Wer das Panel aufruft, bekommt sie;
die alte ist eine Fassung lang unter `/alt/` als Rückweg erreichbar und
eingefroren. Damit ist die Stufe „Neues Fundament" aus
[docs/16-neukonzeption.md](docs/16-neukonzeption.md) abgeschlossen: Parität zu
allen zwölf Modulen der alten Fläche, `/api/v1` als einzige Datenquelle, ein
einheitliches Job-Modell, Cron & systemd-Timer und API-Tokens.

**Nach dem Update ist eine Anmeldung nötig — einmal.** Alle Sitzungen werden
verworfen (Migration 0006); API-Tokens bleiben gültig.

**Was ein Beta-Tester zuerst wissen sollte:**

- Die Adressen haben sich geändert. `/services` heißt jetzt `/dienste`,
  `/packages` → `/pakete`, `/system-users` → `/benutzer`, `/users` → `/zugaenge`,
  `/account` → `/konto`, `/update` → `/updates`, `/certificate` → `/zertifikate`.
  Ein Lesezeichen auf einen alten Pfad landet unter `/alt/…` — dort steht die
  eingefrorene Fläche.
- **Die Kernoberfläche braucht JavaScript.** Anmeldung, Erstinstallation, der
  erzwungene Passwortwechsel und der Weg für ein vergessenes Passwort laufen
  weiter ohne — sie sind server-gerendert und bleiben es.
- **Ein Cron-Eintrag ist eine Shell-Zeile**, und wer einen anlegen darf, führt
  Code als den eingetragenen Benutzer aus. Die Betrachtung dazu steht in
  docs/16-neukonzeption.md unter 4.2 und 7.2.
- **Ein API-Token ist ein Zugang.** Er erbt die Rolle des Kontos, das ihn anlegt,
  und der Klartext erscheint genau einmal.

### Hinzugefügt

- **API-Tokens als zweiter Anmeldeweg** (`/tokens`, drei Routen unter
  `/api/v1/tokens`, Migration 0005 — die erste Store-Erweiterung dieses
  Vorhabens). Gespeichert wird nur der Hash, wie bei den Sitzungen: Ein
  Datenbankabzug erlaubt keine Anmeldung. Der Klartext steht genau einmal in einer
  Antwort; es gibt keinen Endpunkt, der ihn zurückgäbe.

  Die tragende Entscheidung steht im Kopf von `internal/httpd/tokenauth.go`: Ein
  Cookie ist eine UMGEBENDE Berechtigung, ein Token eine mitgebrachte. Einen
  `Authorization`-Kopf kann eine fremde Seite nicht setzen — darum braucht der
  Token-Weg keine CSRF-Prüfung, und nur darum. Daraus folgt: **Ist der Kopf da,
  gilt ausschließlich der Token-Weg; ein ungültiger endet mit 401 und fällt NICHT
  auf das Cookie zurück.** Der Rückfall wäre der eigentliche Angriff — ein
  unsinniger Kopf würde die CSRF-Prüfung abschalten, und die mitgeschickte Sitzung
  täte die Arbeit.

  Weitere Schranken: Drei Familien sind für Tokens gesperrt (`tokens`,
  `panel-users`, `account`) — die erste, weil ein entwendeter Token sonst einen
  frischen mintet und seinen eigenen Widerruf überlebt. Ein Token gilt nur unter
  `/api/`, die Familienliste ist eine Allowlist, die Rolle bleibt die Obergrenze,
  und `nur_lesen` senkt sie für diesen Zugang. Fehlversuche werden je IP gebremst.
  Im Audit-Protokoll steht, dass ein Token gehandelt hat — sonst sagt es „philipp
  hat den Dienst gestoppt", während es ein Skript war.

- **Modul Cron & systemd-Timer** (`/cron`) — mit 0.4.0-rc.5 gebaut; die
  Einzelheiten stehen dort.

### Geändert

- **Umgeschaltet: die neue Oberfläche liegt an der Wurzel.** `/v2/` ist
  verschwunden; wer das Panel aufruft, bekommt die neue Fläche. Die alte ist
  **eine Fassung lang unter `/alt/` erreichbar** und eingefroren — keine
  Gestaltung, keine Funktion, nur ein Rückweg.

  Das Konzept sah vor, beides in einem Zug zu tun (umschalten und die alte Fläche
  entfernen). Der Grund für die zwei Schritte liegt in der Bauart dieses
  Programms: **Es aktualisiert sich selbst.** Ein Fehler in der neuen Fläche, der
  jemanden aussperrt, sperrt ihn auch aus dem Panel aus, über das der Rückweg
  einzuspielen wäre. Der Abbau — 27 Vorlagen, 17 statische Dateien, rund 4.500
  Zeilen Handler und die daran hängenden Tests — folgt in 0.4.1. *(Nachtrag: Er
  ist mit 0.4.1 erfolgt und fiel größer aus — siehe oben.)*

- **Alle Sitzungen werden beim Update einmalig verworfen** (Migration 0006). Ein
  Cookie von vor dem Update stammt aus einer Zeit, in der das Panel anders aussah,
  und das Sitzungstoken der alten Fläche steckt in jeder Seite, die noch im
  Browser steht. Nach dem Update ist eine Anmeldung nötig — einmal.

  **API-Tokens bleiben gültig.** Sie hängen an `/api/v1`, und das ist unverändert;
  sie zu verwerfen hieße, jede Automatisierung mit einem Update stillschweigend
  abzuschalten.

- **`min-upgradable-from` bleibt leer.** Keine der Migrationen 0005 und 0006 macht
  einen direkten Sprung unmöglich — beide sind vorwärtsgerichtet und ergänzend.
  Ein Wert dort gehört nur hinein, wenn eine Migration einen Sprung wirklich
  verhindert.

- **Unbekannte Pfade antworten weiter 404.** `GET /` ist seit dem Umschalten der
  allgemeine Rückfall des Multiplexers; ohne Prüfung bekäme jede erdachte Adresse
  die Hülle mit Status 200, und ein abgeschaltetes Modul wäre von einem
  vorhandenen nicht zu unterscheiden. Der Server prüft den ersten Pfadteil gegen
  die Liste der Seiten; ein Test hält sie mit dem Router der Oberfläche zusammen.

### Behoben

- **Ein Browsertest prüfte den Journalstrom in einem Rennen.** Er sah gleich nach
  dem Klick in der Liste der Anfragen nach, ob der Strom geöffnet wurde — der
  Puls erscheint aber, sobald der Zustand umschlägt, und die Verbindung ist dann
  erst angestoßen. Es ging gut, solange der Aufbau schneller war als die Prüfung.
  Beim Umschalten fiel es auf, weil sich die Zeitverhältnisse verschoben haben.
  Jetzt wird auf die Anfrage gewartet.


## [0.4.0-rc.5] — 2026-07-31

**Cron & systemd-Timer** — das erste Modul der neuen Oberfläche, das keine alte
Fläche hat, und damit das erste, für das `privops` um eine neue Familie wächst.
Von den zwei Neuerungen, die für die 0.4 über der Parität noch offen waren, ist
damit eine erledigt; offen bleiben **API-Tokens**. Die alte Oberfläche unter `/`
ist unverändert.

**Was ein Beta-Tester wissen sollte, bevor er die Seite benutzt:** Diese Fläche
ist die einzige des Panels, an der ein FREIER Befehl entsteht. Wer sie bedienen
darf (Owner-Rolle), führt Code als den eingetragenen Benutzer aus — als root also
mit vollen Rechten, unbeaufsichtigt, zur eingetragenen Zeit. Das ist nicht zu
umgehen und nicht wegzutypisieren, denn genau das ist cron. Was das Panel
stattdessen tut, steht unten und ausführlich in
[docs/16-neukonzeption.md](docs/16-neukonzeption.md) unter 4.2 und 7.2.

Die alte Oberfläche ist nachprüfbar unverändert: kein Diff in
`internal/ui/templates`, `internal/ui/static` und in keiner der Handlerdateien,
die sie bedienen. Cron gibt es dort nicht und wird es nicht geben — das Modul ist
neu und steht nur unter `/v2/cron`.

### Hinzugefügt

- **Modul Cron & systemd-Timer in der neuen Oberfläche** (`/v2/cron`, vier
  Routen unter `/api/v1/schedules`). Das erste Modul, das keine alte Fläche hat —
  und damit das erste, für das `privops` um eine neue Familie wächst:
  `CronList`, `CronWrite`, `CronDelete`, `TimerList`, `TimerRuns`.

  Es ist die einzige Fläche des Panels, an der ein FREIER Befehl entsteht, denn
  ein Cron-Eintrag *ist* eine Shell-Zeile — cron gibt sie an `/bin/sh`. Das ist
  keine Aufweichung des Verzichts auf eine Shell, sondern das Wesen von cron, und
  es steht ausgeschrieben in
  [docs/16-neukonzeption.md](docs/16-neukonzeption.md) unter 4.2 und 7.2. Was den
  Weg eng hält:

  - **Geschrieben wird nur in eigene Dateien:** `/etc/cron.d/asylum-<name>`, mit
    einem Marker in der ersten Zeile, eine Datei je Eintrag. Der Marker und nicht
    der Dateiname entscheidet — wer von Hand eine Datei `asylum-backup` anlegt,
    hat sie damit nicht dem Panel überschrieben. Fremde Crontabs
    (`/etc/crontab`, fremde Dateien in `/etc/cron.d`, die Spool-Crontabs der
    Benutzer, die `run-parts`-Verzeichnisse) werden gelesen und nie geschrieben.
  - **Geprüft wird das Dateiformat, nicht der Befehlsinhalt.** Zeilenumbruch (der
    einzige echte Injektionsweg in eine Crontab: er erzeugt einen zweiten Eintrag
    mit eigenem Benutzerfeld), Steuerzeichen, ein unmaskiertes Prozentzeichen und
    der Zeitplan gegen die Wertebereiche. Semikolon, Pipe und Umleitung bleiben
    erlaubt — sie zu verbieten gäbe eine Sicherheit vor, die es nicht gibt.
  - **Schreiben verlangt die Owner-Rolle**, Lesen genügt das Leserecht.
  - **Ein Eintrag als root ist Stufe 3 mit dem Hostnamen** — eine begründete
    Abweichung von [docs/14-bestaetigungen.md](docs/14-bestaetigungen.md), wo er
    als löschbar und damit umkehrbar Stufe 2 wäre: Der Eintrag ist umkehrbar,
    seine Folgen sind es nicht, und er läuft unbeaufsichtigt. Als anderer
    Benutzer Stufe 2, Abschalten Stufe 1, Löschen Stufe 2.
  - **Der ganze Befehl steht im Audit-Protokoll.** Er ist die Antwort auf „was
    lief da".

  Dazu der Zeitplan **in Worten** neben dem rohen Feld („täglich um 03:17", „an
  Werktagen um 06:30") — aus dem Server, damit es nur eine Auslegung der fünf
  Felder gibt. Wo die Worte nicht reichen, sagt der Satz das offen statt zu
  raten, und den Sonderfall benennt er ausdrücklich: Monatstag UND Wochentag
  verknüpft cron mit ODER.

  Timer sind **lesend**: Liste mit ausgelöster Unit, nächstem und letztem Lauf,
  `OnCalendar` und `Persistent`, dazu das Ergebnis des letzten Laufs auf Abruf.
  Gefragt wird dafür nach dem *Dienst*, den der Timer auslöst, nicht nach dem
  Timer — der glückt immer, sobald er auslöst. Geschaltet werden Timer über die
  Dienste (ein Timer ist eine Unit); das *Anlegen* eines Timers fehlt bewusst, die
  Begründung steht in `internal/privops/timer.go`.

- **Abgeschaltete Cron-Einträge bleiben lesbar.** Abschalten schreibt die Zeile
  auskommentiert, statt sie zu entfernen. Ein Eintrag, der beim Abschalten
  verschwindet, wird beim nächsten Mal ein zweites Mal angelegt.

## [0.4.0-rc.4] — 2026-07-30

**Die Funktionsparität der neuen Oberfläche ist erreicht.** Alle zwölf Module der
alten Fläche stehen unter `/v2/`; die Liste steht in
[docs/16-neukonzeption.md](docs/16-neukonzeption.md) unter „Stand der Parität".
Nicht übertragen und absichtlich server-gerendert bleiben Anmeldung,
Erstinstallation, erzwungener Passwortwechsel und der Weg für ein vergessenes
Passwort — sie liegen vor der Anmeldung und müssen ohne JavaScript laufen.

Offen für die 0.4 bleiben die zwei Neuerungen, die keine Parität sind: Cron &
systemd-Timer sowie API-Tokens. Die alte Oberfläche unter `/` ist unverändert und
bleibt bis zum Umschalten erreichbar.

### Hinzugefügt

- **Modul Audit in der neuen Oberfläche.** Das Protokoll mit Filter auf dem
  Server (Akteur, Bereich, Ergebnis, Suche in Ziel und Einzelheiten) und
  Blätterung über die Kennung statt über `OFFSET` — bei einem Protokoll, das
  während des Blätterns wächst, verschiebt `OFFSET` die Grenze und überspringt
  Einträge. Nur lesend, und das ist keine Auslassung: Der Store hat bewusst
  keine Lösch- oder Änderungsfunktion.

- **Modul Benutzer & SSH in der neuen Oberfläche.** Sechs Routen: Systemkonten
  des Wirtsystems und ihre Schlüssel. Ein Konto ohne Schlüssel ist eine
  Auffälligkeit mit eigenem Zähler — aber nur bei Menschenkonten, bei einem
  Dienstkonto ist es die Bauart. Der letzte Schlüssel eines Kontos verlangt
  Stufe 3 mit dem Kontonamen: Ihn zu entfernen legt den Zugang still.

- **Modul Panel-Zugänge in der neuen Oberfläche.** Sieben Routen — die Konten
  DIESER Oberfläche, nicht die des Servers. Vier Schranken aus der alten Fläche
  bleiben unverändert: nur die Owner-Rolle (auch lesend), jede Zurücksetzung
  verlangt das eigene Passwort des Owners, das eigene Konto läuft nicht über
  diesen Weg, und das letzte Owner-Konto lässt sich nicht löschen.

  **`store.User` wird nie serialisiert.** Der Typ trägt `PasswordHash` und
  `TOTPSecret`. Die Antwort zählt ihre Felder ausdrücklich auf, statt den
  Store-Typ mit `json:"-"` an den heiklen Stellen einzubetten: Kommt dem Store
  ein Feld hinzu, wandert es beim Einbetten mit, hier nicht. Ein Test prüft das
  an den WERTEN im rohen Körper und nicht an Feldnamen — ein umbenanntes Feld
  rutschte sonst durch.

  **Die Bedingung steht vor dem Handgriff.** Das Feld für das eigene Passwort
  steht offen im Inspektor, und die drei Zurücksetzungen sind gesperrt, solange
  es leer ist. Ein Knopf, der erst nach dem Klick mit 403 antwortet, ist selbst
  der Fehler. Nach jedem Aufruf wird das Feld geleert — auch nach einem
  gescheiterten, weil ein gefülltes Feld zum zweiten Versuch mit demselben
  falschen Wort verleitet.

  **Ein Einmalpasswort steht genau einmal da**, in einem Dialog, den Escape
  nicht schließt. Es erscheint nicht im Audit-Protokoll, nicht im Konsolen-Echo
  und in keiner zweiten Antwort; wer es verliert, setzt erneut zurück.

- **Modul Eigenes Konto in der neuen Oberfläche.** Dreizehn Routen: Passwort,
  zweiter Faktor mit QR-Code, Wiederherstellungscodes, Passkeys (WebAuthn) und
  die offenen Sitzungen. Keine Werkbank, sondern ein Stapel benannter Blöcke —
  hier gibt es ein Konto und fünf verschiedene Handgriffe daran, jeder mit dem
  Satz darüber, warum es ihn gibt.

  **Jede Rolle verwaltet ihr eigenes Konto**, auch „readonly". Der einzige
  schreibende Bereich der Schnittstelle ohne Rollenprüfung, und das ist kein
  Versehen: Sonst bliebe ein Konto mit Leserecht auf dem Einmalpasswort sitzen,
  mit dem es angelegt wurde. Die Schranke ist stattdessen das aktuelle Passwort
  bei jeder Änderung an einem Anmeldeweg, und dass diese Endpunkte ausschließlich
  das Konto der laufenden Sitzung anfassen.

  **Die eigene Sitzung überlebt die eigene Passwortänderung.** Alle Sitzungen des
  Kontos werden beendet — auch die, in der man sitzt —, die eigene wird sofort neu
  aufgebaut, und die Antwort trägt das frische Sitzungstoken. Ohne beides wäre die
  Oberfläche nach einer geglückten Änderung abgemeldet, und die naheliegende
  Deutung wäre „hat nicht funktioniert".

  **Der halbe Wechsel des zweiten Faktors liegt auf dem Server** und übersteht
  ein Neuladen, mit der Frist daneben. Neu gegenüber der alten Oberfläche ist ein
  Knopf zum Abbrechen: Dort verließ man die Seite und wartete die 15 Minuten ab —
  in einer Einzelseiten-Anwendung ist „die Seite verlassen" kein Vorgang mehr.

  **Passkeys in der neuen Oberfläche.** Die Zeremonie läuft über zwei Aufrufe,
  weil dazwischen der Browser mit dem Gerät spricht; die Umrechnung base64url ↔
  ArrayBuffer steht in `web/src/lib/api.ts`. Geprüft ist das mit einem virtuellen
  Authenticator im Browser, und zwar nicht daran, dass ein Eintrag in der Liste
  erscheint, sondern daran, dass ein über `/v2/konto` hinterlegter Passkey eine
  echte Anmeldung trägt — ein Fehler in der Umrechnung fällt in keinem Go-Test
  auf, weil die Endpunkte korrekt antworten und nur nie ein gültiger Nachweis
  ankommt.

- **Modul Zertifikate in der neuen Oberfläche.** Zustand des ausgelieferten
  Zertifikats, Einstellungen für den ACME-Bezug und der Bezug selbst. Oben, was
  gerade ausgeliefert wird, darunter, wie es bezogen wird — wer die Seite öffnet,
  will in den meisten Fällen das Erste wissen.

  **Einstellung und Wirklichkeit werden auseinandergehalten.** „acme"
  eingestellt heißt nicht „acme ausgeliefert": Bis der erste Bezug glückt, bleibt
  das selbstsignierte Zertifikat aktiv. Beides steht da, und der Zwischenzustand
  ist benannt — ohne das sucht jemand den Fehler an der falschen Stelle.

  **Das Formular zeigt nur, was zur Wahl passt.** DNS-01 braucht einen Anbieter,
  Hook zwei Pfade, Cloudflare ein Token; Felder, die zur getroffenen Wahl nichts
  beitragen, stehen nicht da. Geschickt wird, was zu sehen ist, und nicht der
  letzte Zustand jedes Feldes — sonst antwortet der Server mit einer Begründung
  für ein Feld, das gar nicht dasteht.

  **Der Bezug ist ein Vorgang und bekommt keinen eigenen Ereignisstrom.** Er
  läuft über `/api/v1/jobs/certificate/events` wie der Paketvorgang und das
  Einspielen von ufw — das einheitliche Job-Modell aus
  [docs/16-neukonzeption.md](docs/16-neukonzeption.md) statt eines vierten
  Endpunkts. Die alte Oberfläche behält ihren Strom unter `/certificate/events`.

  **Der Rückschritt auf ein selbstsigniertes Zertifikat fragt zurück** (Stufe 2)
  — danach warnt jeder Browser beim Aufruf des Panels. Der einzige Unterschied im
  Verhalten gegenüber der alten Fläche.

- **Modul Updates in der neuen Oberfläche.** Fünf Routen: Zustand, Poller,
  Prüfen, Einspielen, Rückweg. Damit ist die Funktionsparität der 0.4 erreicht —
  alle Module der alten Oberfläche stehen unter `/v2/`.

  **Kein Ereignisstrom, sondern ein Poller.** Dieses Modul startet seinen eigenen
  Dienst neu; ein offener Kanal übersteht das nicht, und ein Job im Speicher des
  Prozesses, den der Vorgang gerade beendet, auch nicht. Der Update-Lauf schreibt
  in eine Protokolldatei, und `/api/v1/update/status` liest sie — nach dem
  Neustart genauso wie davor.

  **Der Verbindungsabbruch ist der Normalfall und wird vorher angekündigt.** Die
  Oberfläche hält drei Zustände auseinander: es läuft und der Dienst antwortet, es
  läuft und der Dienst antwortet nicht (das ist der Neustart — als Fehlermeldung
  würde jemand neu laden, während unter ihm das Binary getauscht wird), und der
  Dienst antwortet wieder. Im dritten Fall entscheidet die FASSUNG, was gemeldet
  wird: eine andere als zu Beginn heißt „durch", dieselbe heißt „schiefgegangen,
  der Verlauf sagt was". Sie kommt aus dem neuen Programm und ist damit die
  verlässlichste Auskunft, die es gibt.

  **Nur die Owner-Rolle löst aus**, Prüfen darf jede schreibberechtigte Rolle
  (es ändert nichts). Eingespielt wird genau die Fassung, die der Auslöser gesehen
  hat — nicht eine, die zwischen Anzeige und Klick veröffentlicht wurde.

- **Rollenabhängige Navigation in der neuen Oberfläche.** Was eine Rolle nicht
  erreicht, steht nicht in der Seitenleiste und nicht in der Befehlspalette —
  wie in der alten Oberfläche (`{{if .IsOwner}}`). Gefiltert wird an einer
  Stelle (`web/src/lib/ziele.ts`): Zwei Filter derselben Regel laufen
  auseinander, und der übersehene wäre die Palette. Verbindlich bleibt die
  Route.

### Geändert

- **Der Menüpunkt „Einstellungen" ist aufgeteilt.** Er zeigte auf `/users`, die
  Kontenliste — ein Name, der etwas anderes versprach, als dahinter stand. Jetzt
  „Panel-Zugänge" (unter `/v2/zugaenge`) und „Eigenes Konto" (unter `/v2/konto`).
  „Panel-Zugänge" steht direkt unter „Benutzer & SSH": Die zwei Kontenarten sind
  die häufigste Verwechslung im Panel.

- **Eine abgelehnte Rechtefrage ist kein Ladefehler mehr.** Antwortet die
  Schnittstelle mit 403, sagt die Seite den Grund und stellt keinen Knopf
  „Erneut versuchen" daneben — er brächte nie ein anderes Ergebnis.

### Hinweise zu diesem Stand

- **Die alte Oberfläche ist unverändert.** Kein Diff in
  `internal/ui/templates`, `internal/ui/static`, `handlers_app.go`,
  `handlers_reset.go`, `handlers_cert.go`, `handlers_update.go`,
  `handlers_passkey.go` und `tlsctl.go`. In `handlers_account.go` steht
  ausschließlich eine neue Methode, die nur die neue Fläche ruft.

- **`internal/privops` und `internal/store` wachsen rein additiv.** Neu sind
  `LoginShells` und `Groups` (Auskünfte für die Auswahlfelder beim Anlegen eines
  Systemkontos) und `store.DeleteUser`. Nichts davon ändert bestehendes
  Verhalten; anders als in rc.3 wirkt diesmal keine Korrektur auf beide Flächen.

- **`packaging/min-upgradable-from` bleibt leer und damit ohne Grenze.** Dieser
  Stand bringt keine Migration mit — kein neues `internal/store/migrations/*` —,
  ein Rückweg auf 0.4.0-rc.3 trifft also kein neueres Schema. Die ersten
  Migrationen kommen mit dem Verlauf der Vorgänge und den API-Tokens; dann gehört
  dort ein Wert hinein.

- **Nicht gegen echte Systeme geprüft**, weil es sie in der Bauumgebung nicht
  gibt: `useradd`/`usermod`, `/etc/shadow`, ein echter ACME-Server sowie
  `systemd-run` samt Signaturprüfung des Selbstupdates. Geprüft sind die Aufträge
  an privops, die Ablehnungen und die Anzeige — für die drei Flächen, die diese
  Werkzeuge brauchen, ist ein Durchlauf auf einem echten Server vor der Freigabe
  1.0 vorgesehen.

## [0.4.0-rc.3] — 2026-07-30

Das größte Modul der neuen Oberfläche und das einzige echte technische Risiko
des Umbaus: CodeMirror liegt jetzt im Vite-Bundle, und die Inhaltsrichtlinie
bleibt streng. Dazu drei behobene Fehler, die älter sind als dieses Modul —
einer davon betraf alle Rückfragen der neuen Oberfläche seit ihrem ersten Modul.

Die alte Oberfläche unter `/` ist unverändert: kein Diff in
`internal/ui/templates`, `internal/ui/static` und `handlers_system.go`. Zwei
Korrekturen in `internal/privops` wirken allerdings auf beide Flächen — sie
stehen unten unter „Behoben" benannt.

### Hinzugefügt

- **Modul Dateien in der neuen Oberfläche — Blättern, Verändern, Editor.** Das
  größte Modul des Panels (17 Routen in der alten Oberfläche, `privops.Files` mit
  23 Methoden) in drei Schritten. Blättern mit Krumenpfad und Inspektor, alle
  Handgriffe der alten Fläche, und der Editor mit CodeMirror.

  **Die Oberfläche bietet nur an, was gehen kann.** Der Server rechnet je Eintrag
  aus, welche Handgriffe zu ihm passen, und die Oberfläche zeigt nur diese. Das
  ist eine Bedienhilfe und keine Rechteprüfung — verbindlich bleibt die Pfadwache
  in privops. Der Grund dafür: Ein Knopf, der zuverlässig in ein 403 oder 413
  läuft, nennt den Fehler erst nach dem Klick, und dann ist der Knopf schon der
  Fehler. Ein gesperrter Eintrag steht sichtbar in der Liste und ist benannt,
  sein Inhalt wird aber nie angefasst; Kopieren hängt am Ziel und nicht an der
  Quelle; der Editor erscheint nur unter seiner Größengrenze.

  **Der Ort ist ein Schritt im Verlauf.** Wer drei Ebenen tief steht, kommt mit
  dem Zurück-Knopf eine Ebene höher und nicht aus der Seite heraus. Gesucht wird
  auf dem Server und nicht im Browser: Die Liste ist bei zweitausend Einträgen
  gekürzt, und ein Browserfilter darüber behauptete „kein Treffer" für eine Datei,
  die es gibt. Am Treffer steht der Ort, weil ein Ergebnis quer über Unterordner
  sonst eine Sammlung von Namen ist, von denen keiner auffindbar ist.

  **Der Editor hält drei Zusagen, und alle drei sind älter als diese
  Oberfläche:** Zeilenenden und ein fehlender Schlussumbruch bleiben, wie sie
  waren; wurde die Datei zwischenzeitlich von außen geändert, wird die fremde
  Änderung nicht überschrieben; und lehnt das Prüfprogramm des Systems die Datei
  ab, ist der Vorzustand zurückgeschrieben, samt wörtlicher Ausgabe des Programms.
  Der Konflikt hat zwei Auswege — die eigene Fassung durchsetzen oder die fremde
  übernehmen —, und beide stehen da.

  **CodeMirror liegt jetzt im Vite-Bundle**, als eigener Brocken, der erst beim
  Öffnen des Editors nachgeladen wird: Das Hauptbündel bleibt bei ~166 KiB, der
  Editor kommt mit ~356 KiB dazu, wenn er gebraucht wird. Die Fassungen sind exakt
  festgeschrieben, die Herkunft steht in `web/THIRD-PARTY.md`, und die
  Reproduzierbarkeit ist über drei Fälle nachgewiesen — zwei Läufe hintereinander,
  ein anderer Verzeichnispfad, ein frisches `npm ci`.

  Das brauchte einen Nonce für Stile: CodeMirror legt für seine Regeln ein
  `<style>`-Element an, und `style-src 'self'` verwirft das. Derselbe Weg wie auf
  der Editorseite der alten Oberfläche — nicht `unsafe-inline`, weil damit jeder
  eingeschleuste Stil erlaubt wäre.

  Nicht gegen ein echtes System geprüft: `sshd -t` und `nft -c -f` gibt es in der
  Entwicklungsumgebung nicht. Der Weg mit Prüfung und Rückrollen ist deshalb nur
  gegen eine Attrappe gelaufen.

- **Angekündigte Module sagen, dass es sie noch nicht gibt.** Cron, Docker,
  Webserver, Datenbanken und Backups stehen im Menü der neuen Oberfläche, weil
  sie zum Leitbild gehören — sie zeigten aber auf die Startseite und landeten
  stillschweigend dort. Ein Klick auf „Docker", der die Übersicht bringt, sieht
  wie ein Fehler aus, und in einem Panel ist das nicht harmlos: Es ist die Stelle,
  an der man anfängt, der Oberfläche nicht mehr zu glauben. Sie haben jetzt eigene
  Pfade und eine Seite, die nennt, mit welcher Fassung das Modul kommt und was
  heute an seiner Stelle geht.

- **Modul Firewall in der neuen Oberfläche, mit sichtbarer Probezeit.** Grundsatz
  VI aus [docs/16-neukonzeption.md](docs/16-neukonzeption.md): „Was schiefgehen
  kann, hat einen Rückweg." Jede Änderung gilt zunächst auf Probe — ohne
  Bestätigung binnen 60 Sekunden stellt der Server den vorherigen Stand wieder
  her. Neu ist nicht die Sicherung selbst, die gab es schon: neu ist, dass man ihr
  zusehen kann.

  Die Probe steht über allem anderen auf der Seite, mit einer Uhr, die
  herunterläuft, und dem Knopf, der sie beendet. Es ist der einzige Ort im Panel,
  an dem Untätigkeit etwas rückgängig macht — wer hereinkommt, muss zuerst diesen
  Knopf sehen.

  **Und sie übersteht ein Neuladen.** Das ist der Punkt, auf den es ankommt: Die
  Frist ist Zustand des Servers und wird über `GET /api/v1/firewall`
  mitgeliefert, nicht als Ereignis verschickt. Wer die Seite neu lädt — und man
  lädt neu, *weil* etwas hakt —, findet den Countdown vor, statt eine Änderung zu
  verlieren, ohne zu wissen warum. Gerechnet wird im Browser aus einem festen
  Ablaufzeitpunkt und nicht sekundenweise: Ein Zähler, der bei jedem Takt eins
  abzieht, geht falsch, sobald der Tab in den Hintergrund kommt.

  Die Stufen sind dieselben wie in der alten Oberfläche und aus demselben Grund:
  Regeln übernehmen und ufw einschalten sind Stufe 2, weil die Probe einen Fehler
  von selbst zurücknimmt. **Ausschalten ist Stufe 3 mit dem Hostnamen** — es ist
  die einzige der drei Aktionen ohne Probe, weil sie den Server *öffnet* und
  dieser Zustand bleibt, bis jemand ihn ändert.

  Dazu die zwei Sicherungen, die keine Rückfrage ersetzen kann: Ohne Regel für den
  Panel-Port wird das Einschalten verweigert — vor der Rückfrage, nicht danach —,
  und die Regel für diesen Port wird der Anfrage nicht überlassen, sondern
  ergänzt. Der Regelsatz wird immer vollständig übergeben, nicht als Einzeländerung:
  Damit ist der Zustand danach eindeutig, auch wenn zwei Personen gleichzeitig
  arbeiten. Ein Entwurf, der noch nicht übernommen ist, sagt das; Vorschläge (etwa
  der Port, auf dem sshd laut Konfiguration lauscht) sind blasser und gelten
  erst, wenn man sie annimmt.

- **Die 60-Sekunden-Frist ist jetzt prüfbar.** `firewallGuard` trägt sie als Feld
  statt als Konstante im Code. Damit läuft der Rückbau in Tests in Millisekunden
  ab — und die wichtigste Sicherung des Panels ist nicht länger die einzige
  ungeprüfte. Drei Tests decken sie ab: dass ohne Bestätigung zurückgerollt wird,
  dass eine Bestätigung das verhindert, und dass eine zweite Änderung die erste
  ersetzt statt einen überholten Stand wiederherzustellen.

## [0.4.0-rc.2] — 2026-07-30

Drei Module der neuen Oberfläche und das Muster, das die weiteren übernehmen.
Die alte Oberfläche unter `/` ist unverändert — kein Diff in
`internal/ui/templates`, `internal/ui/static` und `handlers_system.go`.

### Hinzugefügt

- **Befehlspalette in der neuen Oberfläche.** `⌘K` beziehungsweise `Strg+K`
  öffnet ein Suchfeld über allem und springt von dort an jede Stelle des Panels;
  der Hinweis im Statusband ist selbst der Knopf, damit das Kürzel kein Wissen
  voraussetzt, das die Oberfläche verschweigt. Bedienbar allein mit der Tastatur:
  Pfeile wählen, `Pos1`/`Ende` springen an die Enden, `Enter` öffnet, `Escape`
  schließt und gibt den Fokus dorthin zurück, wo er war.

  Gesucht wird nicht nur im Namen. Jedes Ziel führt die Wörter mit, unter denen
  jemand danach sucht — `nginx` findet den Webserver, `ssl` das Zertifikat,
  `apt` die Pakete —, und Umlaute sind aufgelöst, sodass auch `ubersicht`
  trifft. Gewichtet wird in vier Stufen, damit das Naheliegende oben steht und
  die Reihenfolge nicht vom Menüaufbau abhängt.

  Damit ist der offene Punkt aus
  [docs/15-neuordnung.md](docs/15-neuordnung.md) erledigt: In der alten
  Oberfläche wäre die Palette ein Skript in der Seite gewesen, das die
  Inhaltsrichtlinie verwirft. Die Navigationsziele stehen jetzt **einmal** in
  `web/src/lib/ziele.ts` und werden von Seitenleiste und Palette gemeinsam
  gelesen — zwei Listen desselben Menüs laufen sonst auseinander, und ein neues
  Modul wäre in der Leiste zu sehen, aber nicht zu finden. Dienste, Dateien und
  Regeln als eigene Treffer kommen dazu, sobald die Module sie über `/api/v1`
  anbieten; die Suche ist dafür so geschnitten, dass eine zweite Quelle nur eine
  weitere Liste ist.

- **Modul Dienste in der neuen Oberfläche** — das erste neben der Übersicht, und
  damit die Form, die die weiteren übernehmen (siehe
  [docs/16-neukonzeption.md](docs/16-neukonzeption.md) 8.4). Liste links,
  Inspektor rechts, kein Seitenwechsel: Wer einen Dienst neustartet, sieht danach
  die Liste mit der neuen Zeile darin und muss die Stelle nicht wiederfinden.

  Die Auswahl steht in der Adresse (`?unit=nginx.service`). Ein Verweis auf einen
  bestimmten Dienst ist damit teilbar, ein Neuladen zeigt denselben Zustand, und
  der Zurück-Knopf schließt den Inspektor. Gesucht wird auch in der Beschreibung
  — wer „web" tippt, sucht nginx, und der Unitname sagt das nicht —, und die
  Zähler über der Liste sind selbst die Filter.

  Gescheitertes steht oben, weil es der Grund ist, die Seite zu öffnen. Zustand,
  Zähler, Sortierung und die zum Zustand passenden Aktionen rechnet der Server:
  `static` und `masked` bekommen keinen Autostart-Knopf, weil `systemctl enable`
  daran scheitert und ein Knopf, der immer einen Fehler liefert, schlimmer ist als
  keiner.

- **Rückfragen ohne gerenderte Seite.** `/api/v1` führt eine zerstörende Aktion
  nicht aus, solange `bestaetigt` fehlt, und antwortet stattdessen mit **409** und
  dem Text der Rückfrage — Titel, Frage, Folgen, Knopfbeschriftung und bei Stufe 3
  das zu tippende Wort. Das ist [docs/14-bestaetigungen.md](docs/14-bestaetigungen.md)
  wortgleich übersetzt: Die Zwischenseite wird ein Objekt, verbindlich bleibt der
  Handler. Ein selbstgebautes POST ohne das Feld tut weiterhin nichts.

  Der Dialog ist ein echtes `<dialog>` mit `showModal()` — Fokusfang, oberste
  Ebene und Escape kommen vom Browser. Der gefährliche Knopf bekommt den Fokus
  nicht, und bei Stufe 3 bleibt er gesperrt, bis das Wort stimmt. Zwei Tests an
  den Quellen halten fest, dass niemand die Abkürzung über `window.confirm` nimmt:
  Die würde funktionieren — und wäre eine Rückfrage, an der ein POST vorbeikommt.

- **Wegewahl ohne Neuladen.** Die neue Oberfläche hat einen eigenen Router von
  etwa achtzig Zeilen statt einer Bibliothek. Beim Seitenwechsel bleiben
  Statusband und Live-Kanal stehen — in der alten Oberfläche flackerten die Zahlen
  oben bei jedem Klick, weil jede Seite neu kam. Ziele, deren Modul noch fehlt,
  zeigen weiter auf die alte Oberfläche und laden ganz normal; Mittelklick und
  „in neuem Tab öffnen" funktionieren überall, weil die Verweise echte `<a href>`
  bleiben.

- **Modul Pakete in der neuen Oberfläche** — das erste mit einer Handlung, die
  Minuten dauert, und damit die Stelle, an der Grundsatz III („Handlungen sind
  quittiert") Form bekommt. Sicherheitsupdates stehen oben, weil sie der Grund
  sind, die Seite zu öffnen; die Zähler darüber sind selbst die Filter; ein
  einzelnes Paket lässt sich in seiner Zeile einspielen.

  Ein ausstehender Neustart steht über allem anderen: Er bedeutet, dass
  eingespielte Updates noch nicht wirken, und nennt das Paket, das ihn verlangt.
  Der Knopf dazu erscheint nur, wenn er aussteht — ein Knopf, der immer da ist,
  wird irgendwann versehentlich gedrückt — und nur für die Owner-Rolle. Er ist
  die einzige Aktion der neuen Oberfläche mit Bestätigungsstufe 3: Getippt wird
  der Hostname, damit niemand den richtigen Neustart auf dem falschen Server
  auslöst.

- **Vorgänge als eigene Ressource, mit Live-Auszug.** `/api/v1/jobs/{art}` sagt,
  was läuft, wer es angestoßen hat, wie lange es dauert und wie es ausging; ein
  Ereignisstrom daneben liefert die Zeilen, während sie entstehen. Eine Platte in
  der Oberfläche zeigt das für jeden Vorgang gleich — Docker, Backups und die
  Firewall-Einrichtung erben sie.

  Der Start antwortet mit **202** und wartet nicht auf apt: Eine Anfrage, die
  zwanzig Minuten offen bleibt, überlebt keinen Zwischenserver. Der Vorgang läuft
  auf dem Server weiter, wenn jemand die Seite verlässt — wer zurückkommt, findet
  ihn samt Auszug vor. Ein zweiter Paketvorgang wird abgewiesen (**409**), statt
  sich an der dpkg-Sperre zu verklemmen. Ein Teilerfolg von `apt-get update` gilt
  weiter als Teilerfolg und nennt die klemmende Quelle: verschwiegen wäre er eine
  Zusage, die niemand halten kann.

- **Modul Logs in der neuen Oberfläche, mit Live-Verfolgung.** Das Journal
  abgefragt oder verfolgt: Filter für Unit, Stufe, Zeitraum und Freitext stehen in
  der Adresse, ein Verweis auf „nginx, ab heute, nur Fehler" ist damit teilbar.
  Die neuesten Zeilen oben, ab Fehler rot, bei Warnung bernstein — und immer mit
  dem Wort daneben, nie mit der Farbe allein.

  Verfolgen ist ein Schalter und keine Vorgabe. Das hat einen Grund, der über
  Geschmack hinausgeht: Jeder Zuschauer hat seinen eigenen Filter und braucht
  deshalb einen eigenen `journalctl --follow` — vier offene Tabs wären vier
  Prozesse. Es gibt daher eine Obergrenze; wird sie erreicht, sagt die Seite es,
  statt einen Knopf anzubieten, der abgewiesen wird. Beim Verlassen der Seite wird
  angehalten. Das ist der Unterschied zu einem Paketvorgang, der weiterläuft:
  Dessen Abbruch schadet, das Weiterlaufen eines Journals kostet nur.

  Dazu gehört eine neue privops-Operation, `LogsFollow` — die einzige **ohne eigene
  Frist**: Der Kontext des Betrachters ist die Frist, und sein geschlossener Tab
  beendet den Prozess. Die Filter baut sie aus derselben Funktion wie die Abfrage;
  hätte der Strom eigene, könnte er mehr zeigen als die Abfrage vorher hergab.

  Zwei Einzelheiten, die im Betrieb wehtun, wenn sie fehlen: Ein Herzschlag hält
  die Verbindung offen, weil ein Reverse-Proxy eine stille nach einer Minute
  schließt und ein ruhiges Journal genau das ist. Und verworfene Zeilen werden
  gemeldet — schreibt das Journal schneller als die Leitung überträgt, sagt die
  Seite, wie viele fehlen. Eine Lücke, die niemand sieht, ist schlimmer als eine,
  die dasteht.

### Behoben

- **Alle Rückfragen der neuen Oberfläche saßen in der linken oberen Ecke.** Ein
  modales `<dialog>` zentriert der Browser über `margin: auto` aus seinem eigenen
  Stylesheet, und der Rücksetzer der Oberfläche (`* { margin: 0 }`) schlug das —
  eine Autorenregel kommt vor der des Browsers, unabhängig von der Spezifität. Sie
  funktionierten, sahen aber aus wie ein Fehler. Aufgefallen ist es erst am
  Dateimodul, weil dort zum ersten Mal ein Bildschirmfoto mit offenem Dialog
  entstand; der Sitz wird jetzt im Browsertest gemessen.

- **Ein leerer Ordner verlangte zum Löschen seinen Namen als Tippbestätigung.**
  Die Zählung eines Verzeichnisses enthält es selbst — `fs.WalkDir` besucht die
  Wurzel des Laufs. Damit war jeder Ordner Stufe 3, auch der leere, und die Frage
  lautete „enthält 0 Dateien, 1 Ordner" für etwas, worin nichts liegt. Eine Hürde
  ohne Anlass entwertet die Hürde dort, wo sie zählt. Die neue Oberfläche zieht den
  Eintrag heraus; die alte ist eingefroren und behält den Fehler.

- **Die Größengrenze des Editors wurde als Bedienfehler protokolliert.** Eine zu
  große Datei antwortete 400 statt 413, weil der Fehler nicht in `ErrTooLarge`
  eingewickelt war — der Upload macht es seit 0.3.0 richtig. Betrifft beide
  Oberflächen.

- **Escape schloss zwei Dinge auf einmal.** Ein Escape im Rückfrage-Dialog brach
  nicht nur die Rückfrage ab, sondern schloss auch den Inspektor darunter — die
  Auswahl war danach weg. Ein offener Dialog besitzt Escape jetzt allein. Gesehen
  hat das der Browsertest, kein Nachdenken.

- **Der bestätigende Knopf im Neustart-Dialog blieb für immer gesperrt.** Die
  Paketseite hielt die Aktion noch für laufend, während der Dialog schon stand —
  eine Rückfrage, die man nicht bestätigen kann. Auch das hat der Browsertest
  gefunden; im Code sah die Bedingung richtig aus.

- **Jede Journalzeile stand doppelt da, sobald man auf „verfolgen" drückte.** Der
  Strom bringt seinen eigenen Rückblick mit — dieselben letzten Einträge, die die
  Abfrage schon geliefert hatte. Bei 200 geholten Zeilen sah die Seite nach einem
  Klick wie 400 Ereignisse aus. Gesehen hat das ein Bildschirmfoto; die Tests waren
  grün, weil sie zählten, dass Zeilen dazukommen, und nicht welche.

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
