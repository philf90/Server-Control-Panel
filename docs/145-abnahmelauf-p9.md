# P9 — der Durchlauf mit einer Person, die das Projekt nicht kennt

Ausgeschrieben am 7. November 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/20 §9` und `docs/129 §9`:

> **Fertig, wenn** ein fremder Kunde das Panel benutzen kann, ohne zu fragen —
> gemessen an einem Durchlauf mit einer Person, die das Projekt nicht kennt.

Es ist das Kriterium der Stufe und nicht das eines Merkmals. B1 bis B9 sind
abgenommen, zuletzt B3 am 31. Oktober 2026 (`docs/138 §8`); `docs/129 §9`
stellt dieses Kriterium ausdrücklich **nach** sie, und keines von ihnen nimmt
es ab. `docs/92 §5` hat es schon im August benannt: Ein Fehler wie der fehlende
Weg zurück aus einem Vorgang fällt genau in diesem Durchgang auf.

**Dieser Lauf misst anders als alle vor ihm.** Das Messmittel ist ein Mensch,
und der Prüfling ist das Panel, wie es sich einem Kunden zeigt. Gemessen wird,
ob er seine Aufgaben allein erledigt, und jede Frage, die er stellen muss, ist
ein Befund am Panel und nicht an ihm. Der Betreiber sitzt daneben, schweigt
und schreibt mit; was die Person getan hat, belegen danach drei Spuren auf dem
Server (§1).

**Vorab gemessen am Code** (§0): Jeder Weg in §3 ist von der Navigation aus
durch Route, Policy, Controller und Vorlage nachgegangen, und jede Beschriftung
in Anführungszeichen steht so in der Vorlage. **Im Browser ist davon nichts
gemessen.** Der Container hat PHP 8.3, das Projekt verlangt 8.4, und eine
Nachinstallation war hier nicht möglich; der Lauf für B7 und B8 hatte das noch
(`docs/144 §7`). Wo das eine Erwartung betrifft, steht es dabei.

**Beim Ausschreiben sind neun Befunde am Prüfling herausgefallen** (§0). Drei
davon liegen so auf dem Weg, dass eine Aufgabe ohne Frage nicht zu erledigen
ist. Ob sie vor dem Lauf behoben werden, entscheidet der Betreiber (§7).

**Der Lauf braucht einen Vorbereitungsabend und einen Termin von gut zwei
Stunden.** Am Vortag legt der Betreiber DNS, Plan, Kunde und Abonnement an. Am
Termin kommen die Einführung, elf Aufgaben, das Nachgespräch und danach die
Auswertung am Server.

**Gefahren ist er noch nicht.** Das Protokoll wird §8.

---

## §0 · Was beim Ausschreiben herausgefallen ist

### Neun Befunde am Prüfling

Jeder ist am Code gemessen, mit Datei und Zeile, und jeder ist etwas, das ein
Kunde sieht oder nicht findet. Die ersten drei liegen auf dem Weg einer Aufgabe
und machen sie ohne Frage unlösbar oder ohne Rückmeldung. Die Befunde 4 bis 6
liegen auf dem Weg und kosten Verständnis, aber keine Aufgabe. Die Befunde 7 bis 9
liegen neben dem Weg dieses Laufs und treffen einen Kunden trotzdem.

1. **Die Seite „SFTP-Zugang" nennt keinen Rechner und keinen Port** (A7).
   - **Was sie zeigt:** Der Bereich „Verbinden" führt genau zwei Zeilen, „Benutzer" und „Verzeichnis" (`Subscriptions/Sftp.vue:205-229`).
   - **Was fehlt:** Ein SFTP-Programm verlangt zuerst den Rechner, und den nennt keine Kundenseite.
   - **Wer es weiss:** Nur der Betreiber, aus `hostname -f` und `sshd -T`.
   - **Vorschlag:** Rechner und Port gehören in diese Tabelle.
     - Den Rechner liefert `Names`, die einzige Stelle, die den Kernel danach fragt (`HostnameSourceTest`).
     - Den Port liefert dieselbe Prüfung, die schon das Verzeichnis liest: `SftpCheck` fragt `sshd -T -C user=…` (`agent/src/Ops/SftpCheck.php:33`), und dort steht auch `port`.

2. **Die Datenbankseite nennt für MariaDB keinen Server** (A6).
   - **Was sie zeigt:** Der Bereich „Datenbank" führt „Name auf dem Server", „System", „Sortierung", „Zustand" und „Belegt" (`Databases/Show.vue:371-442`).
   - **Wann eine Verbindungsangabe kommt:** Nur bei PostgreSQL über TCP, als Satz mit `127.0.0.1` (`Databases/Show.vue:449-455`).
   - **Was das für den Kunden heisst:** Jedes Installationsprogramm fragt nach Server, Name, Benutzer und Passwort, und drei davon hat die Seite.
   - **Vorschlag:** eine Zeile „Server" mit `localhost` für MariaDB und `127.0.0.1` für PostgreSQL. Der Satz über den Socket bleibt.

3. **Ein von Hand bestellter Zertifikatsvorgang erscheint nicht im Streifen** (A4).
   - **Was der Code tut:**
     - „Zertifikat bestellen" ruft `$order->place($domain, wildcard: …)` ohne Ursache auf (`DomainController.php:535`).
     - `place()` schreibt `'account_id' => $cause?->account_id` (`CertificateOrder.php:56`, `:74`), also `null`.
     - Der Streifen zeigt nur Vorgänge mit dem eigenen Konto (`RunningBand.php:101`).
   - **Was der Kommentar daneben verspricht:** „Den Fortschritt trägt jetzt der Streifen oben" (`DomainController.php:555-560`).
   - **Was der Kunde sieht:** Nach dem Klick weder eine Meldung noch einen Streifen; erst ein Neuladen zeigt den Vorgang in der Tabelle der Domain. Für den Betreiber gilt dasselbe.
   - **Was B8 davon gemessen hat:** Den Klick hatte der Lauf nicht unter seinen Punkten.
   - **Vorschlag:** `place()` bekommt das auslösende Konto. Ein Fall durch die Tür prüft, dass der Vorgang danach in `RunningBand` für dieses Konto steht.

4. **Ein Kunde erreicht seine eigene Kundenseite, und dort stehen vier Knöpfe, die mit 403 enden.**
   - **Der Weg:** „Abonnements" → Name → „Kunde" verlinkt auf `/customers/<eigene>` (`Subscriptions/Show.vue:217`, `:408`), und `CustomerPolicy::view()` lässt den eigenen Kunden durch (`CustomerPolicy.php:30-43`).
   - **Was dort ohne Bedingung steht** (`Customers/Show.vue:99-114`, `:178`):
     - der Brotkrümel „Kunden";
     - „Bearbeiten", „Sperren" und „Zurückziehen";
     - je Abonnement „Bearbeiten".
   - **Wohin sie führen:** Alle verlangen Adminrechte (`CustomerPolicy.php:50`, `:55`, `:75`).
   - **Die Regel, gegen die das verstösst:** die, die `AbilityReachTest` hält: Ein Knopf, den der Betrachter nicht drücken darf, wird nicht gezeigt.
   - **Vorschlag:** Die Seite bekommt eine Fähigkeitsablage wie `Subscriptions/Show`. Die vier Knöpfe und der Brotkrümel stehen nur mit der Fähigkeit da.

5. **Jeder Vorgang eines Kunden steht mit seiner Maschinenkennung da.**
   - **Woher die Bezeichnung kommt:**
     - Der Streifen schreibt `'label' => $vorgang->task ?? $vorgang->type` (`RunningBand.php:112`).
     - Die Vorgangsliste schreibt `$task?->label() ?? $operation->type` (`OperationController.php:247`).
     - Der Katalog `Task` hat elf Fälle, alle für den Server, von „Agent anpingen" bis „PostgreSQL installieren" (`Task.php:86-100`).
   - **Was die Person deshalb liest:** „Vorgang 812 web.site.apply — läuft · 40 %", nach einer PHP-Version `php.pool.apply` und nach „Jetzt sichern" `backup.create`.
   - **Vorschlag:** deutsche Bezeichnungen für die Kennungen, die ein Kunde auslöst, etwa „Domain einrichten", „PHP-Umgebung anlegen", „Zertifikat bestellen" und „Sicherung anlegen". Ein Wächter verlangt für jede Kennung, die eine Kundenroute erzeugt, eine Bezeichnung.

6. **Auf Kundenseiten stehen Sätze für den Betreiber und ein Satz in der falschen Anrede.**
   - **Ein frisches Abonnement** zeigt in der ersten Viertelstunde (`Subscriptions/Show.vue:497-499`, `:538-540`): „Noch nicht gemessen. Die Messung läuft im Viertelstundentakt (`srvpanel-usage.timer`) und braucht eine Dateisystem-Quota auf dem Mount von /var/www/vhosts."
   - **Vor „Datenbank entfernen"** steht: „Es gibt keine Sicherung davor — sie kommt mit einer späteren Ausbaustufe." (`Databases/Show.vue:829-830`). Das ist die Sprache dieses Repos und nicht die eines Kunden.
   - **Die Anrede:** Daneben steht als einziger Satz mit „du": „in die Datenbank kommt sie erst, wenn du sie zurückspielst" (`Databases/Show.vue:813`). Das Panel siezt.
   - **Die Fehlerseite 500** schickt jeden „unter Server → Logs" (`resources/views/errors/500.blade.php:5`); einem Kunden gibt es diese Seite nicht.
   - **Vorschlag:**
     - die Messung ohne Unit und Mount für Kunden: „Noch nicht gemessen — der erste Wert kommt innerhalb einer Viertelstunde.";
     - die Ausbaustufe streichen;
     - siezen;
     - die Fehlerseite je nach Rolle: „Melden Sie sich beim Betreiber dieses Servers."

7. **Ohne aktives Abonnement führt „Abonnements" auf eine Seite „Kein Zutritt".**
   - **Der Menüpunkt** steht für jeden Kunden da (`PanelLayout.vue:274`).
   - **Die Liste** weist aber jeden ab, der kein Abonnement erreicht (`SubscriptionPolicy.php:33-35`).
   - **Der Text** sagt: „Dieser Bereich gehört einer Rolle, die dieses Konto nicht hat." (`resources/views/errors/403.blade.php:5`). Das stimmt nicht: Es fehlt ein Abonnement, keine Rolle.
   - **Die Regel daneben:** Für Domains, Datenbanken, Dateien und die übrigen steht im selben Menü der Grund, warum sie ohne Abonnement fehlen: „eine Sackgasse mit Einladung" (`PanelLayout.vue:276-290`).
   - **Vorschlag:** „Abonnements" unter dieselbe Bedingung. Die Übersicht sagt dann schon: „Für Sie ist noch kein Abonnement eingerichtet."
   - **Auf dem Weg dieses Laufs liegt es nicht**, denn die Person hat ein Abonnement.

8. **Für ein Kundenkonto gibt es keinen Weg zu einem neuen Passwort.**
   - **Der Kunde selbst:** Die Anmeldeseite hat kein „Passwort vergessen", und `routes/web.php` keine Route dafür.
   - **Der Betreiber im Panel:** `Customers/Edit.vue` hat kein Passwortfeld. Die Route `/accounts/{admin}/password` löst nur Adminkonten auf (`SrvPanelServiceProvider.php:291-293`).
   - **Der Betreiber auf der Kommandozeile:** `srvpanel:admin` weist eine Kundenadresse ausdrücklich ab (`CreateAdmin.php:55`).
   - **Was bleibt:** Wer sein Passwort vergisst, muss fragen, und auch der Betreiber kann dann nur noch zu `srvpanel tinker` greifen.
   - **Vorschlag:** In der Kontenliste auf der Kundenseite bekommt der Betreiber „Neues Passwort". Es wird erzeugt und genau einmal angezeigt, wie bei einem Datenbankzugang (`Databases/Show.vue:319-326`).
   - **Auf dem Weg dieses Laufs liegt es nicht**; die Person hat ihren Zettel.

9. **Unter „Kontingente" steht „FTP-Konten: 5"**, und FTP-Konten gibt es nicht.
   - **Das Kontingent:** `Quota::FtpAccounts` führt die Bezeichnung „FTP-Konten", die Erklärung „Zusätzliche FTP-Konten" und die Vorgabe 5 (`Quota.php:123`, `:159`, `:322`).
   - **Was es begrenzt:** nichts. Ausserhalb von `Quota.php` liest es keine Zeile.
   - **Was es gibt:** Es gibt keine FTP-Konten, sondern einen SFTP-Zugang je Abonnement mit Schlüsseln.
   - **Was es kostet:** Ein Kunde, der die Tabelle liest, sucht fünf Konten, die nirgends angelegt werden.
   - **Vorschlag:** Das Kontingent fällt aus der Kundenansicht, bis es etwas begrenzt.

### Beobachtungen ohne Befund

Diese Punkte sind am Code gemessen und keine Fehler, sondern Entscheidungen.
Wie sie sich einem Fremden zeigen, misst erst der Lauf. Der Beobachtungsbogen
(§3) hat für jeden eine Zeile.

- **Die Übersicht führt nirgends hin.**
  - **Was sie zeigt:** je Abonnement Name, Zustand und Hauptdomain, und keine Verknüpfung (`CustomerOverview.vue:48-67`).
  - **Wohin der Weg geht:** Er führt über das Menü. Bei A2 und A3 steht damit die erste Entscheidung.
- **Eine Einladung gibt es nicht.**
  - **Wie ein Kunde entsteht:** Der Betreiber tippt das Passwort beim Anlegen selbst ein (`CustomerController::store`), und das Panel verschickt dazu keine Mail.
  - **Wie die Zugangsdaten zur Person kommen:** Der Lauf gibt sie auf einem Zettel weiter (§2), so wie ein Betreiber sie heute weitergeben müsste.
- **Der Dateimanager lädt nichts herunter.**
  - **Was es gibt:** Hochladen ja, Herunterladen nein; „Datei bearbeiten" verweist dafür auf SFTP.
  - **Für den Lauf:** A7 geht deshalb über SFTP.
- **Eine Sicherung zurückspielen kann nur der Betreiber** (`routes/web.php:1044-1049`). Keine Aufgabe verlangt es.
- **„Domain anlegen" und „Übernehmen" melden nichts.**
  - **Wohin es geht:** auf die Domainseite.
  - **Was es dort gibt:** Rückmeldung geben der Streifen und der Zustand „wird angelegt" (B8). Wie schnell eine Person das als Antwort liest, misst A4.
- **Der Zeitplan eines Cronjobs gilt in Serverzeit.**
  - **Was die Seite dazu hat:** Sie heisst so („Zeitplan (Serverzeit)") und nennt die nächsten Fälligkeiten in der Zone der Anzeige.
  - **Was A8 misst:** ob „3 Uhr" bei der Person 3 Uhr bleibt. Block 0 liest die Zone des Servers.
- **Vorgangsseite und Protokoll zeigen Rohes.**
  - **Die Vorgangsseite** hat den Bereich „Argumente" mit dem JSON, das der Agent bekommt.
  - **Das Protokoll** führt „Aktion" als Schlüssel, etwa `domain.created`.
  - **Was das für den Lauf heisst:** Beides ist so gebaut, und keine Aufgabe führt dorthin. Landet die Person dort, steht es im Bogen.

---

## §1 · Was gemessen wird

### Die Lesart des Kriteriums

Wörtlich lässt das Kriterium drei Dinge offen: was „benutzen" heisst, was
„fragen" ist und wann es erfüllt ist. Der Vorschlag hier ist §7 Frage 3. Bis
der Betreiber entschieden hat, gilt er nicht.

- **Benutzen** heisst, die elf Kernaufgaben aus §3 zu erledigen. Es sind die
  Dinge, für die ein Kunde das Panel in seiner ersten Stunde öffnet:
  - anmelden;
  - den Platz ablesen;
  - die eigene Seite hochladen;
  - eine zweite Adresse mit Zertifikat einrichten;
  - die PHP-Version wählen;
  - eine Datenbank anlegen;
  - per SFTP hinein;
  - einen Cronjob anlegen;
  - eine Sicherung anlegen und herunterladen;
  - einen Fehler im Protokoll finden;
  - das Passwort ändern.

  Zwei weitere sind Kür und zählen nicht.
- **Fragen** heisst: Die Person wendet sich mit einer Frage über das Panel an
  den Beobachter, etwa „Wo finde ich …?", „Was bedeutet …?" oder „Hat das
  geklappt?".
  - **Kein Fragen** ist es, wenn sie laut denkt, ohne sich an jemanden zu
    wenden; das wird mitgeschrieben, aber nicht gezählt.
  - **Eine Frage zur Karte** („Soll ich den Shop auch installieren?")
    beantwortet der Beobachter nur, indem er auf die Karte zeigt. Sie zählt
    als Befund an der Vorschrift und nicht am Panel.
- **Hilfe** ist alles, was der Person einen Weg zeigt: ein Wort, ein Blick auf
  eine Stelle des Bildschirms, ein Nicken. Es gibt keine.
- **Erfüllt** ist das Kriterium, wenn jede Kernaufgabe erledigt ist, ohne dass
  die Person fragt und ohne dass jemand hilft. Eine einzige Frage zur Bedienung
  oder ein einziger Abbruch macht den Lauf zu „nicht erfüllt". Die Stelle ist
  dann ein Befund.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten,
> ist keines mehr — es ist eine Zusammenfassung** (`docs/140 §5`).

### Die Person

**Sie kennt das Projekt nicht**, und das heisst:
- Sie hat das Panel nie gesehen.
- Sie hat nicht daran mitgearbeitet.
- Sie hat sich von niemandem, der es kennt, etwas darüber erzählen lassen.

**Sie ist aber ein möglicher Kunde.** Sie hat schon einmal Webspace oder eine
Website bei einem Hoster gehabt und weiss, was eine Domain und eine Datenbank
sind. Ein SFTP-Programm hat sie schon bedient oder ist bereit, eines zu
benutzen.

Wer noch nie eine Website betrieben hat, ist kein Kunde dieses Panels. Wer
beruflich Server verwaltet, auch nicht. Das Profil entscheidet der Betreiber
(§7 Frage 4).

**Persönliches kommt nicht ins Repo.**
- In §8 heisst sie „die Person". Ihr Name, ihre Adresse und ihre IP stehen in
  keiner Zeile.
- Sie willigt vorher ein, dass mitgeschrieben wird.
- Eine Bildschirmaufnahme gibt es nur mit ihrer Zustimmung; sie bleibt beim
  Betreiber und wird gelöscht, sobald §8 steht.
- Was Block E druckt, wird vor dem Abschreiben um die IP gekürzt.

### Der Beobachter

Der Betreiber, allein oder mit einer zweiten Person, die nur mitschreibt. Er
sitzt seitlich hinter der Person und schaut auf den Bildschirm.

- **Er schweigt.** Auf eine Frage sagt er genau diesen Satz und sonst nichts:
  *„Ich darf Ihnen dabei nicht helfen — machen Sie es so, wie Sie es allein
  machen würden."* Die Frage kommt wörtlich in den Bogen, mit der Seite, auf
  der sie fiel.
- **Er fasst weder Maus noch Tastatur an.** Die einzige Ausnahme ist eine
  Störung ausserhalb des Panels: das WLAN, ein abgestürzter Browser, ein
  Rechner, der schlafen geht. Dann hält er die Uhr an und schreibt es auf.
- **Er bricht ab, wenn die Grenze erreicht ist** oder die Person aufgeben will:
  *„Lassen wir diese Aufgabe hier."* Die Aufgabe ist dann nicht erfüllt.
  Aufgelöst wird erst im Nachgespräch.
- **Er benutzt das Panel während des Termins nicht**, auch nicht vom Telefon.
  Block E trennt die Person über ihre IP, und eine zweite Sitzung aus demselben
  Netz liefe in dieselben Zeilen.

### Die drei Spuren am Server

Was der Beobachter mitschreibt, ist eine Wahrnehmung. Was die Person getan hat,
belegen drei Spuren, die nur ihr Tun hinterlässt und die sie nicht stören:

| Spur | Wo | Was sie belegt |
|---|---|---|
| **Protokoll** | `audit_events`, Konto der Person, seit T0 | jede Aktion mit Ergebnis und Zeit — `auth.login`, `file.uploaded`, `domain.created`, `database.created` … |
| **Vorgänge** | `operations` des Abonnements seit T0 | was der Agent getan hat und ob es fertig wurde |
| **Seitenfolge** | `/var/log/srvpanel/panel-access.log` ab der Zeile von T0, IP der Person | jede Seite in ihrer Reihenfolge, mit Uhrzeit — der Umweg ist dort sichtbar und nicht nur erinnert |

Dazu misst Block E3 von aussen, was eine Aufgabe als Ergebnis verspricht:
- **SFTP:** die Anmeldung im Journal von `sshd`.
- **Datenbank:** die Zugangsdaten vom Bogen an MariaDB.
- **Seiten:** die beiden Seiten über HTTPS mit geprüftem Zertifikat.

> **Ein Endzustand, den man nach dem Ereignis abliest, sagt über den Weg dorthin
> nichts — dafür braucht es eine Spur, die nur dieser eine Weg hinterlässt.**

---

## §2 · Prüfkörper und Zeitplan

### Der Zeitplan

| Wann | Was | Dauer |
|---|---|---|
| **Vortag** | DNS, Plan, Kunde, Abonnement (unten), dann Block 0 | 45 min, dazu die Wartezeit des DNS |
| **Termin, vorher** | Rechner der Person prüfen, Karten und Bogen bereit, Block T | 15 min |
| **Termin** | Einführung · A1 bis A11 · Kür nach Zeit · Nachgespräch | etwa 100 min |
| **danach** | Block E1 bis E3, Bogen und Ausgaben an §8 | 15 min |
| **nach §8** | Rückbau, wenn der Betreiber ihn will (Block R) | 10 min |

**Das Abonnement entsteht am Vortag und nicht am Termin.** In der ersten
Viertelstunde stünde auf seiner Seite der Satz aus Befund 6. Die Kacheln aus
B4 zeigen einen Wert erst ab dem zweiten Tag (`docs/139`).

### Die Prüfkörper

`<testdomain>` ist ein Name unter einer Domain des Betreibers, etwa
`durchlauf.<eigene Domain>`. Er steht in diesem Dokument nur als Platzhalter.

| | Wert | Warum |
|---|---|---|
| **DNS** | `A` (und `AAAA`, wenn der Server eine IPv6 hat) für `<testdomain>` und `*.<testdomain>` auf `cloudsrv24` | Die Seite muss im Browser aufgehen und Let's Encrypt prüfen können; der Stern trägt `shop.<testdomain>` aus A4 |
| **Plan** `Durchlauf` | die Vorgaben eines neuen Plans (`Quota::default()`): Speicherplatz 5120 MB, Subdomains 25, Datenbanken 5, Cronjobs 10, Aufbewahrte Sicherungen 3, PHP 8.3 und 8.4 | Jede Kernaufgabe hat damit ihr Kontingent, und es bleibt kein Wert, den jemand von Hand eintippt |
| **Freigaben** | wie vorgegeben, nur „DNS-Einträge bearbeiten" **gesperrt**; „Eigene Zertifikate hochladen" ist es schon | Die Person hat keinen DNS-Anbieter, und ein Bereich für dessen Zugangsdaten wäre ein Rätsel ohne Aufgabe |
| **Kunde** | Vorname `Probe`, Nachname `Kunde`; Anmeldeadresse `kunde@<testdomain>`; Passwort über „Passwort erzeugen" | Keine Angabe der Person im Panel; die Adresse sieht aus wie die eines Kunden |
| **Abonnement** | Name `<testdomain>`, Plan `Durchlauf` | Aus dem Namen entsteht die Hauptdomain mit `httpdocs` und der Willkommensseite (`WebLifecycle::ensureMainDomain()`) |
| **PHP** | mindestens zwei Versionen installiert | A5 stellt um; Block 0 nennt, welche wählbar sind |
| **Let's Encrypt** | Kontaktadresse unter „Zertifikat" eingetragen | Ohne sie bestellt das Panel für keine Domain etwas |

**Der Zugangszettel** ist das, was ein Hoster heute per Mail schicken würde.
Ausgedruckt liegt er vor der Person:

```
Ihr Webspace ist eingerichtet.

Verwaltung:      https://<Rechner>:<Port>
Anmeldeadresse:  kunde@<testdomain>
Passwort:        <aus „Passwort erzeugen">
Ihre Website:    https://<testdomain>
```

**Die Datei für A3** liegt als `index.html` auf dem Schreibtisch des Rechners:

```html
<!doctype html>
<html lang="de">
<meta charset="utf-8">
<title>Probeseite für den Durchlauf</title>
<h1>Probeseite für den Durchlauf</h1>
<p>Wenn Sie diese Zeile im Browser lesen, ist die Seite angekommen.</p>
</html>
```

**Der Rechner** ist der der Person, mit ihrem gewohnten Browser (§7 Frage 5).
- **Ein SFTP-Programm** ist installiert, etwa FileZilla oder WinSCP. Fehlt es,
  wird es vor dem Termin installiert und nicht während A7.
- **Für das Panel** speichert der Browser kein Passwort.

### Block 0 — am Vortag, nach dem Anlegen

Liest nur. Schreibt die Ausgabe und vier Kennungen unter `/root`, auf dieselbe
Art wie `docs/144` Block 0: `srvpanel tinker` läuft nicht als root, und die
Kennungen gehen über die Ausgabe in die Datei.

```bash
# 0 · Fassung, Abonnement, Plan, Konto, Domain, PHP, ACME, Panel, DNS, sshd und Zone — liest nur; schreibt /root/p9-block0.txt und /root/p9-kennungen.sh
ABO='<testdomain>'
srvpanel version
ABO="$ABO" srvpanel tinker --execute='
app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(function () {
  $abo = App\Models\Subscription::query()->with("plan")->where("name", getenv("ABO"))->first();
  if ($abo === null) { echo "Abonnement ", getenv("ABO"), ": FEHLT\n"; return; }
  printf("Abonnement %d · %s · %s · %s · Plan %s\n", $abo->id, $abo->name, $abo->system_user, $abo->status->value, $abo->plan?->name ?? "—");
  foreach (["disk_mb", "subdomains", "databases", "database_mb", "cron_jobs", "backups"] as $q) printf("  Kontingent %s: %s\n", $q, $abo->quota($q) ?? "—");
  foreach (["backups", "php_settings", "dns_edit", "certificate_upload"] as $f) printf("  Freigabe %s: %s\n", $f, $abo->feature($f) ? "frei" : "gesperrt");
  $php = app(App\Support\Web\PhpSelection::class)->selectableFor($abo);
  printf("  PHP wählbar: %s\n", $php === [] ? "KEINE" : implode(", ", $php));
  $konten = $abo->customer->accounts()->orderBy("id")->get();
  foreach ($konten as $k) printf("  Konto %d · %s · %s · %s\n", $k->id, $k->type->value, $k->status->value, $k->email);
  foreach (App\Models\Domain::query()->where("subscription_id", $abo->id)->orderBy("id")->get() as $d) printf("  Domain %d · %s · %s · %s · PHP %s · Zertifikat: %s\n", $d->id, $d->name, $d->type->value, $d->status->value, $d->php_version ?? "—", app(App\Support\Tls\CertificateChoice::class)->effective($d)?->storage_name ?? "keines");
  printf("  Bestand: %d Datenbanken · %d Cronjobs · %d Sicherungen · %d Schlüssel\n", App\Models\Database::query()->where("subscription_id", $abo->id)->count(), App\Models\CronJob::query()->where("subscription_id", $abo->id)->count(), App\Models\Backup::query()->where("subscription_id", $abo->id)->count(), App\Models\SshKey::query()->where("subscription_id", $abo->id)->count());
  printf("ABO_ID=%d\nKONTO=%d\nSYSUSER=%s\n", $abo->id, $konten->first()?->id ?? 0, $abo->system_user);
});
printf("Kontaktadresse für Let’s Encrypt: %s\n", app(App\Support\Tls\AcmeSettings::class)->contact() === null ? "KEINE" : "eingetragen");
' | tee /root/p9-block0.txt
grep -E '^(ABO_ID|KONTO)=[0-9]+$|^SYSUSER=[a-z0-9_-]+$' /root/p9-block0.txt > /root/p9-kennungen.sh
printf "ABO='%s'\n" "$ABO" >> /root/p9-kennungen.sh
printf 'Kennungen in der Datei: %s von 4\n' "$(wc -l < /root/p9-kennungen.sh)"
P=$(awk -F= '/^PANEL_PORT=/{print $2; exit}' /etc/srvpanel/panel.env); H=$(hostname -f)
curl -sS --noproxy '*' --resolve "$H:$P:127.0.0.1" -o /dev/null -w "Panel https://$H:$P → %{http_code} · Zertifikat %{ssl_verify_result}\n" "https://$H:$P/login"
printf 'Adressen des Servers: %s\n' "$(hostname -I)"
for n in "$ABO" "shop.$ABO"; do printf '%s → %s\n' "$n" "$(getent ahosts "$n" | awk '{print $1}' | sort -u | tr '\n' ' ')"; done
curl -sS --noproxy '*' --resolve "$ABO:443:127.0.0.1" -o /dev/null -w "https://$ABO/ → %{http_code} · Zertifikat %{ssl_verify_result}\n" "https://$ABO/"
curl -sS --noproxy '*' --resolve "$ABO:443:127.0.0.1" "https://$ABO/" | grep -o '<title>[^<]*</title>'
printf 'sshd: %s· Zone des Servers: %s\n' "$(sshd -T 2>/dev/null | grep -E '^port ' | tr '\n' ' ')" "$(timedatectl show -p Timezone --value)"
```

**Erwartet:**

```
0.9.0-rc.<n>
Abonnement <ABO_ID> · <testdomain> · <Systembenutzer> · active · Plan Durchlauf
  Kontingent disk_mb: 5120
  Kontingent subdomains: 25
  Kontingent databases: 5
  Kontingent database_mb: 2048
  Kontingent cron_jobs: 10
  Kontingent backups: 3
  Freigabe backups: frei
  Freigabe php_settings: frei
  Freigabe dns_edit: gesperrt
  Freigabe certificate_upload: gesperrt
  PHP wählbar: <a>, <b>[, …]
  Konto <KONTO> · customer · active · kunde@<testdomain>
  Domain <n> · <testdomain> · main · active · PHP <b> · Zertifikat: <Ablagename>
  Bestand: 0 Datenbanken · 0 Cronjobs · 0 Sicherungen · 0 Schlüssel
ABO_ID=<…>
KONTO=<…>
SYSUSER=<…>
Kontaktadresse für Let’s Encrypt: eingetragen
Kennungen in der Datei: 4 von 4
Panel https://<Rechner>:<Port> → 200 · Zertifikat 0
Adressen des Servers: <IPv4> [<IPv6>]
<testdomain> → <IPv4> [<IPv6>]
shop.<testdomain> → <IPv4> [<IPv6>]
https://<testdomain>/ → 200 · Zertifikat 0
<title>Diese Domain ist eingerichtet</title>
sshd: port <Port> · Zone des Servers: <Zone>
```

- **Die Fassung** ist die mit den Entscheidungen aus §7. Steht dort eine ältere,
  gelten für A4, A6 und A7 die Befunde aus §0 und nicht die Wege in §3.
- **`PHP wählbar` hat mindestens zwei Einträge.** Für A5 wird einer gewählt,
  der **nicht** der der Domain ist, und auf die Karte geschrieben. Die
  Vorgabe einer neuen Domain ist die höchste wählbare
  (`PhpSelection::defaultFor()`).
- **Die Hauptdomain trägt ein Zertifikat**, `Zertifikat 0` ist die Prüfung
  der Kette gegen die Zertifizierungsstellen des Servers, und der Titel ist der
  der Willkommensseite. Steht `keines` da, ist die Bestellung noch nicht
  durch oder gescheitert. Dann wird nicht gefahren: A3 und A4 setzen eine
  erreichbare Seite voraus.
- **Beide Namen zeigen auf eine Adresse des Servers.** Zeigt `shop.<testdomain>`
  nirgends hin, fehlt der Stern im DNS.
- **Das Zertifikat des Panels** ist ebenfalls `0`. Eine Warnung beim ersten
  Aufruf wäre die erste Frage der Person, und sie ginge nicht auf das Panel.
- **Port und Zone** werden abgeschrieben:
  - Den Port braucht A7, solange Befund 1 steht.
  - Die Zone braucht A8. Liegt sie nicht in der Zone der Person, ist „3 Uhr"
    im Formular eine andere Stunde als auf ihrer Uhr.

---

## §3 · Die Aufgaben

### Vor dem Termin — Block T

Unmittelbar bevor die Person sich setzt. Merkt sich den Beginn und ruft eine
Seite auf, die es nicht gibt: A10 sucht sie später im Protokoll.

```bash
# T · Beginn — merkt sich Zeit und Zeilenzahl, ruft eine Seite auf, die es nicht gibt; schreibt /root/p9-beginn.sh
. /root/p9-kennungen.sh
printf "T0='%s'\nZEILE=%s\n" "$(date -u '+%Y-%m-%d %H:%M:%S')" "$(wc -l < /var/log/srvpanel/panel-access.log)" > /root/p9-beginn.sh
cat /root/p9-beginn.sh
curl -sS --noproxy '*' --resolve "$ABO:443:127.0.0.1" -o /dev/null -w "https://$ABO/preise.html → %{http_code} · $(date '+%H:%M') Uhr\n" "https://$ABO/preise.html"
```

**Erwartet:** `T0='<UTC>'`, `ZEILE=<n>`, `… → 404 · <hh:mm> Uhr`. Die Uhrzeit
kommt auf die Karte von A10.

### Die Einführung — vorgelesen

> Danke, dass Sie mitmachen. Wir prüfen heute nicht Sie, sondern eine
> Verwaltungsoberfläche für Webspace. Alles, was nicht klappt, ist ein Fehler
> der Oberfläche, und genau den suchen wir.
>
> Sie bekommen nacheinander kleine Aufgaben auf Karten. Machen Sie sie so, wie
> Sie es zu Hause allein machen würden. Bitte sagen Sie dabei laut, was Sie
> denken: was Sie suchen, was Sie erwarten, was Sie wundert.
>
> Ich darf Ihnen nicht helfen und werde auf Fragen nicht antworten. Das ist
> keine Unhöflichkeit, sondern der Sinn der Sache. Wenn eine Aufgabe nicht
> weitergeht, sagen Sie es — dann machen wir mit der nächsten weiter.
>
> Ich schreibe mit, was passiert. Ihr Name kommt nirgends hin.

### Der Beobachtungsbogen

Je Aufgabe eine Zeile, ausgedruckt:

| Aufgabe | Beginn | Ende | erfüllt / nicht / abgebrochen | Fragen — wörtlich, mit Seite | Zögern und Umwege — Seite | gesagt — wörtlich |
|---|---|---|---|---|---|---|
| A1 … A11, K1, K2 | | | | | | |

Darunter je eine Zeile für die Beobachtungen aus §0: Übersicht, Einladung,
Herunterladen, Meldung nach dem Anlegen, Serverzeit, Vorgangsseite und
Protokoll. Und eine Zeile für Störungen ausserhalb des Panels, mit angehaltener
Zeit.

### Die Karten

Jede Karte liegt einzeln vor der Person; die Uhr läuft, sobald sie gelesen
ist. **„Weg"**, **„Vorab"** und **„Spur"** sind für den Beobachter und stehen
nicht auf der Karte. Die Karten vermeiden die Wörter der Knöpfe, wo es geht,
denn eine Karte, die den Knopf nennt, misst das Lesen und nicht das Finden.

Die Karten hängen nicht aneinander. Wo eine auf eine frühere baut, sagt sie,
was gilt, wenn die frühere nicht erfüllt wurde.

#### A1 · Anmelden — Grenze 5 min

> **Karte:** Sie haben bei einem Hoster Webspace gemietet und diesen Zettel
> bekommen. Melden Sie sich in der Verwaltung an.

- **Erfüllt**, wenn die Übersicht dasteht.
- **Weg:** Adresse vom Zettel → „Anmeldung": „Adresse", „Passwort",
  „Anmelden" → „Übersicht", Unterzeile „Ihre Abonnements".
- **Vorab:** Einen zweiten Faktor verlangt das Panel nur von Admins
  (`RequireTwoFactor`). Eine Warnung am Zertifikat schliesst Block 0 aus.
- **Spur:** `auth.login · success` mit der IP der Person; sie ist der Schlüssel
  für E2.

#### A2 · Den Platz ablesen — Grenze 5 min

> **Karte:** Wie viel Speicherplatz belegt Ihre Website gerade, und wie viel
> steht Ihnen zu? Sagen Sie beide Zahlen laut.

- **Erfüllt**, wenn beide Zahlen genannt sind und mit der Seite übereinstimmen.
- **Weg:** Menü „Abonnements" → Name → Bereich „Speicher": „<x> MB von 5.120 MB".
  Die Übersicht führt nicht dorthin (§0, Beobachtungen).
- **Spur:** `GET /subscriptions/<ABO_ID>` in der Seitenfolge.

#### A3 · Die eigene Seite — Grenze 10 min

> **Karte:** Auf dem Schreibtisch dieses Rechners liegt die Datei
> `index.html`. Sorgen Sie dafür, dass `https://<testdomain>` diese Seite
> zeigt, und prüfen Sie es im Browser.

- **Erfüllt**, wenn der Browser „Probeseite für den Durchlauf" zeigt.
- **Weg:** „Dateien" → Verzeichnis `httpdocs` → „Datei Hochladen" → „Die
  Datei ist hochgeladen und hat eine vorhandene ersetzt."
- **Vorab:**
  - **Die Domainseite** nennt das Verzeichnis unter „Verzeichnis" als
    absoluten Pfad `/var/www/vhosts/<testdomain>/httpdocs`; der Dateimanager
    zählt von „Abo-Wurzel" aus. Ob die Person die beiden zusammenbringt,
    misst diese Aufgabe.
  - **Was ersetzt wird:** Die Willkommensseite heisst ebenfalls `index.html`
    (`agent/src/WelcomePage.php:45`).
- **Spur:** `file.uploaded`; in E3 der Titel der Seite.

#### A4 · Eine zweite Adresse mit Zertifikat — Grenze 10 min

> **Karte:** Für einen Shop brauchen Sie die Adresse `shop.<testdomain>`.
> Richten Sie sie ein. Fertig ist sie, wenn `https://shop.<testdomain>` im
> Browser ohne Warnung aufgeht.

- **Erfüllt**, wenn der Browser die Adresse ohne Warnung öffnet; er zeigt dann
  „Diese Domain ist eingerichtet".
- **Weg:**
  1. „Domains" → „Domain anlegen" → „Sorte": „Subdomain", „Gehört zu":
     `<testdomain>`, „Name": `shop.<testdomain>` → „Anlegen".
  2. Die Domainseite zeigt den Zustand „wird angelegt". Eine Meldung kommt
     nicht. Im Streifen steht `web.site.apply`, danach
     `acme.certificate.issue` (Befund 5).
  3. Ist der Vorgang durch, steht im Bereich „Zertifikat" ein Aussteller.
- **Vorab:**
  - **Nur „shop" eingetippt** gibt einen Fehler: „Eine Subdomain von
    `<testdomain>` endet auf .`<testdomain>`" (`Web/Domains.php:281`).
  - **Ein Klick auf „Zertifikat bestellen"** antwortet mit nichts (Befund 3).
  - **Warten:** Die Bestellung kommt von selbst, sobald der Server-Block
    steht. Die Wartezeit läuft in der Grenze mit, denn auch sie ist etwas,
    das die Person deuten muss.
- **Spur:** `domain.created`; die Vorgänge `web.site.apply` und
  `acme.certificate.issue` fertig; in E3 `200 · Zertifikat 0`.

#### A5 · Die PHP-Version — Grenze 5 min

> **Karte:** Ihre Shop-Software verlangt PHP `<Version aus Block 0>`. Stellen
> Sie `shop.<testdomain>` darauf um.
> *(Ist A4 nicht erfüllt: `<testdomain>` statt `shop.<testdomain>`.)*

- **Erfüllt**, wenn die Domainseite PHP `<Version>` nennt und der Vorgang
  fertig ist.
- **Weg:** Domainseite → Bereich „Verzeichnis und Handler" → „PHP-Version" →
  „Übernehmen". Eine Meldung kommt nicht; im Streifen steht `php.pool.apply`
  (Befund 5).
- **Spur:** `domain.updated`; der Bestand nennt die Version.

#### A6 · Eine Datenbank — Grenze 10 min

> **Karte:** Der Shop braucht eine Datenbank. Legen Sie eine an, und schreiben
> Sie auf diesen Bogen, was der Shop zum Verbinden braucht:
> **Server · Name der Datenbank · Benutzer · Passwort**.

- **Erfüllt**, wenn die vier Angaben vom Bogen in E3 eine Anmeldung an MariaDB
  tragen.
- **Weg:**
  1. „Datenbanken" → „Datenbank anlegen" → „Name", „Benutzername" →
     „Anlegen".
  2. Die Detailseite zeigt „Zugang `<…>` angelegt. Das Passwort lautet … —
     es steht hier zum **einzigen Mal**". Den Namen findet die Person unter
     „Name auf dem Server".
- **Vorab:**
  - **Der Server** steht nirgends (Befund 2). Schreibt die Person ihn trotzdem
    richtig auf, war es Vorwissen, und der Bogen sagt das.
  - **Gefragt wird** nach dem Server und nicht nach einem Rechner, weil
    Installationsprogramme so fragen.
- **Spur:** `database.created`, `database.user.created`; E3.

#### A7 · Per SFTP hinein — Grenze 15 min

> **Karte:** Sie möchten Ihre Dateien künftig mit einem SFTP-Programm
> bearbeiten (auf diesem Rechner ist eines installiert). Verbinden Sie sich
> damit, und laden Sie die Datei `index.html` Ihrer Website auf den
> Schreibtisch herunter.

- **Erfüllt**, wenn die Datei auf dem Schreibtisch liegt und `sshd` die
  Anmeldung des Systembenutzers meldet.
- **Weg:**
  1. „SFTP-Zugang" → „Schlüssel erzeugen". Der private Schlüssel steht einmal
     da, mit „Herunterladen". Wer einen eigenen Schlüssel hat, trägt ihn
     unter „Öffentlicher Schlüssel" ein → „Eintragen".
  2. Im Programm: Rechner, Port, Benutzer aus „Benutzer", die Schlüsseldatei.
- **Vorab:** Rechner und Port nennt keine Kundenseite (Befund 1). Ohne
  Behebung ist diese Aufgabe ohne Frage nur mit Raten zu erledigen. Rät die
  Person den Rechner aus der Adresse des Panels, ist das Raten und nicht ein
  Weg; der Bogen sagt das.
- **Spur:** `sftp.key.add`; in E3 `Accepted publickey for <SYSUSER>`.

#### A8 · Ein Cronjob — Grenze 5 min

> **Karte:** Ihr Shop braucht jede Nacht um 3:00 Uhr einen Aufräumlauf. Der
> Befehl dafür lautet `date` (er steht hier für den echten). Richten Sie das
> ein.

- **Erfüllt**, wenn ein aktiver Job mit dem Befehl `date` besteht und die
  Seite als nächste Fälligkeit 03:00 in der Zone der Person nennt.
- **Weg:**
  1. „Cronjobs" → „Job anlegen" → „Beschriftung", „Befehl", „Zeitplan
     (Serverzeit)" — eine Schnellwahl oder die Felder Minute und Stunde → „Anlegen".
  2. Die Meldung lautet „Der Cronjob ist gespeichert. Bis er gilt, kann es
     eine Minute dauern."
- **Vorab:** Die Zone aus Block 0. Steht der Server nicht in der Zone der
  Person, ist `0 3 * * *` falsch. Die Vorschau unter dem Formular nennt dann
  eine andere Stunde, und ob die Person sie liest, misst diese Aufgabe.
- **Spur:** `cron.job.add`; der Bestand nennt Zeitplan und Befehl.

#### A9 · Eine Sicherung — Grenze 10 min

> **Karte:** Bevor Sie am Shop etwas Grösseres ändern, möchten Sie eine
> Sicherung Ihres Webspace auf diesem Rechner haben. Legen Sie eine an, und
> laden Sie sie herunter.

- **Erfüllt**, wenn das Archiv im Download-Ordner liegt.
- **Weg:**
  1. „Sicherungen" → „Jetzt sichern" → „Die Sicherung wird erstellt." Im
     Streifen steht `backup.create` (Befund 5).
  2. Die Zeile wird „fertig" → „Herunterladen".
- **Spur:** `backup.created`, `backup.downloaded`; der Vorgang `backup.create`
  fertig.

#### A10 · Ein Fehler, den jemand meldet — Grenze 10 min

> **Karte:** Ein Besucher schreibt Ihnen: „Heute um `<Uhrzeit aus Block T>`
> bekam ich auf Ihrer Website die Meldung ‚Nicht gefunden'." Finden Sie heraus,
> welche Seite er aufrufen wollte.

- **Erfüllt**, wenn die Person `/preise.html` nennt.
- **Weg:** „Domains" → `<testdomain>` → „Protokolle" → „Zugriffe" → die
  Zeile von `127.0.0.1` mit `GET /preise.html` und `404`.
- **Vorab:** Die Uhrzeit trennt diese Zeile von den eigenen Aufrufen der
  Person; ihr Browser holt sich auch ein `/favicon.ico`, das es nicht gibt.
- **Spur:** `GET /domains/<id>/logs` in der Seitenfolge.

#### A11 · Ein eigenes Passwort — Grenze 5 min

> **Karte:** Ändern Sie Ihr Passwort in eines, das Sie sich ausdenken und
> nirgends sonst benutzen. Melden Sie sich danach ab und mit dem neuen wieder
> an.

- **Erfüllt**, wenn die neue Anmeldung gelingt.
- **Weg:**
  1. „Mein Konto" → „Passwort ändern" → „Aktuelles Passwort", „Neues
     Passwort", „Passwort wiederholen" → „Passwort ändern".
  2. Die Meldung lautet „Passwort geändert. Andere Sitzungen wurden
     abgemeldet."
  3. „Abmelden" steht unten in der Leiste, bei 390 px im Menü → „Anmeldung".
- **Spur:** `profile.password.changed`, `auth.logout`, `auth.login`.

#### Kür — nach Zeit, zählt nicht

- **K1 · Zweiter Faktor.**
  - **Karte:** „Schützen Sie Ihr Konto zusätzlich mit einem Code aus einer
    App auf Ihrem Telefon."
  - **Weg:** „Mein Konto" → „Zweiter Faktor", „verwalten" → QR-Code → „Code
    aus der App" → „Bestätigen".
  - **Spur:** `auth.two_factor.enabled`.
- **K2 · Am Telefon.**
  - **Karte:** „Melden Sie sich auf Ihrem Telefon an, und sehen Sie nach, wann
    Ihr Aufräumlauf das nächste Mal läuft."
  - **Weg:** Menü → „Cronjobs" → „Nächste Fälligkeit". Gemessen wird der Weg
    bei 390 px; die Anmeldung kommt in der Seitenfolge mit der IP des
    Telefons.

### Das Nachgespräch

Erst wenn der Bogen für die letzte Aufgabe steht. Fünf Fragen, die Antworten
wörtlich:

1. Was war am schwersten?
2. Gab es ein Wort, das Sie nicht verstanden haben?
3. Wo haben Sie etwas erwartet, das nicht da war?
4. Woran haben Sie gemerkt, ob etwas geklappt hat?
5. Würden Sie Ihre Website mit dieser Oberfläche betreiben — und was müsste
   dafür anders sein?

Danach, und erst danach, zeigt der Beobachter bei jeder nicht erfüllten
Aufgabe den Weg. Was die Person dann sagt („Ach, das heisst …"), kommt
ebenfalls in den Bogen.

---

## §4 · Die Auswertung — Block E

Nach dem Nachgespräch, am Server. Alle drei Blöcke lesen nur.

### E1 — Protokoll, Vorgänge und Bestand

```bash
# E1 · Was der Durchlauf hinterlassen hat — Protokoll des Kontos, Vorgänge und Bestand seit T0 — liest nur; schreibt /root/p9-ende.txt
. /root/p9-kennungen.sh; . /root/p9-beginn.sh
KONTO="$KONTO" ABO_ID="$ABO_ID" T0="$T0" srvpanel tinker --execute='
app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(function () {
  $t0 = getenv("T0"); $abo = (int) getenv("ABO_ID");
  $z = fn ($t) => $t === null ? "—" : substr((string) App\Support\Time\Clock::display($t), 11);
  echo "Protokoll des Kontos seit T0:\n";
  foreach (App\Models\AuditEvent::query()->where("account_id", (int) getenv("KONTO"))->where("created_at", ">=", $t0)->orderBy("id")->get() as $e) printf("  %s · %s · %s · %s · %s\n", $z($e->created_at), $e->action, $e->result->value, $e->ip_address ?? "—", json_encode($e->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo "Vorgänge des Abonnements seit T0:\n";
  foreach (App\Models\Operation::query()->withoutGlobalScopes()->where("subscription_id", $abo)->where("created_at", ">=", $t0)->orderBy("id")->get() as $o) printf("  Vorgang %d · %s · Konto %s · %s · angelegt %s · fertig %s\n", $o->id, $o->type, $o->account_id ?? "—", $o->status->value, $z($o->created_at), $z($o->finished_at));
  echo "Bestand:\n";
  foreach (App\Models\Domain::query()->where("subscription_id", $abo)->orderBy("id")->get() as $d) printf("  Domain %s · %s · %s · PHP %s · Zertifikat: %s\n", $d->name, $d->type->value, $d->status->value, $d->php_version ?? "—", app(App\Support\Tls\CertificateChoice::class)->effective($d)?->storage_name ?? "keines");
  foreach (App\Models\Database::query()->where("subscription_id", $abo)->orderBy("id")->get() as $b) printf("  Datenbank %s · %s\n", $b->name, $b->status->value);
  foreach (App\Models\DbUser::query()->where("subscription_id", $abo)->orderBy("id")->get() as $u) printf("  Datenbankzugang %s@%s\n", $u->name, $u->host);
  foreach (App\Models\CronJob::query()->where("subscription_id", $abo)->orderBy("id")->get() as $c) printf("  Cronjob „%s“ · %s %s %s %s %s · %s · %s\n", $c->label, $c->minute, $c->hour, $c->day_of_month, $c->month, $c->day_of_week, $c->active ? "aktiv" : "pausiert", $c->command);
  foreach (App\Models\Backup::query()->where("subscription_id", $abo)->orderBy("id")->get() as $s) printf("  Sicherung %s · %s · %s Byte\n", $s->storage_name, $s->status->value, $s->bytes ?? "—");
  foreach (App\Models\SshKey::query()->where("subscription_id", $abo)->orderBy("id")->get() as $k) printf("  Schlüssel %s · %s\n", $k->label, $k->fingerprint);
});
' | tee /root/p9-ende.txt
```

**Erwartet**, wenn alles erfüllt ist, je Kernaufgabe mindestens diese Zeilen:

| Aufgabe | im Protokoll | bei den Vorgängen | im Bestand |
|---|---|---|---|
| A1 | `auth.login · success · <IP>` | | |
| A3 | `file.uploaded · success` | | |
| A4 | `domain.created · success` | `web.site.apply · succeeded`, `acme.certificate.issue · succeeded` | `Domain shop.<testdomain> · subdomain · active · … · Zertifikat: <Ablagename>` |
| A5 | `domain.updated · success` | `php.pool.apply`, `web.site.apply` · `succeeded` | `PHP <Version>` |
| A6 | `database.created`, `database.user.created` | | `Datenbank <…> · active`, `Datenbankzugang <…>@localhost` |
| A7 | `sftp.key.add · success` | | `Schlüssel <Bezeichnung> · SHA256:…` |
| A8 | `cron.job.add · success` | | `Cronjob „…" · 0 3 * * * · aktiv · date` (oder die Stunde, die die Zone verlangt) |
| A9 | `backup.created`, `backup.downloaded` | `backup.create · succeeded` | `Sicherung <…> · ready` |
| A11 | `profile.password.changed`, `auth.logout`, `auth.login` | | |

- **Die Werte in der Tabelle sind aus dem Code gelesen und nicht gefahren**:
  die Namen der Aktionen aus den Aufrufen von `Audit`, die Zustände aus den
  Enums.
- **Ein `failure` oder `denied` im Protokoll** ist eine Stelle, an der die
  Person etwas versucht hat, das nicht ging. Sie wird mit der Uhrzeit im Bogen
  zusammengelegt.

### E2 — die Seitenfolge

Die IP ist die aus der Zeile `auth.login` in E1. Sie steht nur in dieser Sitzung
und in keiner Datei, die ins Repo geht.

```bash
# E2 · Die Seitenfolge der Person aus dem Zugriffsprotokoll des Panels, ab T0 — liest nur; schreibt /root/p9-seiten.txt
. /root/p9-beginn.sh
IP='<Adresse aus der Zeile auth.login in E1>'
tail -n +"$((ZEILE + 1))" /var/log/srvpanel/panel-access.log \
  | awk -v ip="$IP" '$1 == ip { split($7, u, "?"); print substr($4, 14, 8), substr($6, 2), u[1], $9 }' \
  | grep -v -E ' /(build|branding|favicon|apple-touch-icon|manifest)' \
  | awk '{ if ($2 " " $3 != last) print; last = $2 " " $3 }' | tee /root/p9-seiten.txt
printf 'Zeilen: %s · davon ändernd: %s\n' "$(wc -l < /root/p9-seiten.txt)" "$(grep -c -E ' (POST|PUT|PATCH|DELETE) ' /root/p9-seiten.txt)"
```

- **Eine Zeile ist eine Seite**: Uhrzeit, Methode, Pfad, Status.
- **Was nicht mitzählt:** Gleiche Zeilen hintereinander zählen einmal. Das
  sind die Nachfragen des Streifens, alle drei Sekunden an dieselbe Adresse
  (`OperationBand.vue:80`). Ebenfalls heraus fallen die Dateien von Vite und
  Marke.
- **Der Umweg** ist jede Seite zwischen Beginn und Ende einer Aufgabe, die
  nicht auf ihrem Weg in §3 liegt. Er kommt mit Uhrzeit in den Bogen.
- **Ein 403 in der Spalte Status** ist eine Stelle, an der das Panel der Person
  etwas angeboten hat, das sie nicht darf. Befund 4 ist eine solche Stelle,
  vorher gemessen.
- **Wie die Zeile gelesen wird:** Nach dem Format `combined`, mit dem das Panel
  protokolliert (`agent/src/Ops/PanelVhost.php:307`): `$1` Adresse, `$4` Zeit,
  `$6` und `$7` Methode und Pfad, `$9` Status.
  - **Im Container gefahren** an zwölf nachgebauten Zeilen: eine zweite
    Adresse, eine Zeile vor T0, ein Abfrageteil, eine Datei von Vite, drei
    Nachfragen hintereinander und ein 403. Heraus kamen sieben Zeilen, wie
    gewollt.
  - **Am echten Protokoll** ist er nicht gefahren.

### E3 — von aussen gemessen

```bash
# E3 · SFTP im Journal, die Datenbank mit den Angaben vom Bogen, die beiden Seiten über HTTPS — liest nur; fragt das Passwort vom Bogen ab
. /root/p9-kennungen.sh; . /root/p9-beginn.sh
journalctl -t sshd -t sshd-session --since "$T0 UTC" --no-pager -o short-iso | grep -E "for $SYSUSER |user $SYSUSER " | tail -n 8
DBHOST='<Server vom Bogen>'; DBNAME='<Name vom Bogen>'; DBUSER='<Benutzer vom Bogen>'
mariadb -B -h "$DBHOST" -u "$DBUSER" -p "$DBNAME" -e 'SELECT CURRENT_USER(), DATABASE();'
curl -sS --noproxy '*' --resolve "$ABO:443:127.0.0.1" "https://$ABO/" | grep -o '<title>[^<]*</title>'
curl -sS --noproxy '*' --resolve "shop.$ABO:443:127.0.0.1" -o /dev/null -w "https://shop.$ABO/ → %{http_code} · Zertifikat %{ssl_verify_result}\n" "https://shop.$ABO/"
```

**Erwartet:**

```
<Zeit> … sshd…: Accepted publickey for <SYSUSER> from <IP> port <…> ssh2: ED25519 SHA256:…
Enter password: <Passwort vom Bogen>
CURRENT_USER()	DATABASE()
<Benutzer>@localhost	<Name>
<title>Probeseite für den Durchlauf</title>
https://shop.<testdomain>/ → 200 · Zertifikat 0
```

- **Den Namen im Journal** gibt OpenSSH ab 9.8 als `sshd-session` aus und davor
  als `sshd`. Der Block fragt nach beiden.
- **Das Passwort** wird an der Eingabeaufforderung eingetippt und steht damit
  weder im Befehl noch in der Geschichte.
- **Trägt die Anmeldung an MariaDB nicht**, wird abgeschrieben, an welcher
  Angabe es lag. Das ist A6 und kein Fehler des Blocks.
- **Was diese drei Blöcke tragen:**
  - Auf dem Server ist keiner gefahren; E2 lief im Container an nachgebauten
    Zeilen.
  - Die Abfragen auf `Subscription`, `Domain`, `Operation` und
    `CertificateChoice` stehen in derselben Form in `docs/143` und `docs/144`
    und sind dort auf `cloudsrv24` gelaufen.
  - `AuditEvent`, `DbUser`, `CronJob`, `Backup`, `SshKey`, `PhpSelection` und
    `AcmeSettings` sind an ihren Klassen gelesen: Felder, Casts und
    Rückgabetypen.
  - Die Syntax ist geprüft, die des PHP mit `php -l` und die der Blöcke mit
    `bash -n`.

### Block R — Rückbau

Erst wenn §8 steht und der Betreiber entschieden hat, dass kein Nachlauf mit
diesem Abonnement folgt.

1. **Im Panel:** das Abonnement `<testdomain>` „Zurückbauen", danach den Kunden
   `Probe Kunde` „Zurückziehen" und den Plan `Durchlauf` entfernen.
2. **Beim DNS-Anbieter:** die Einträge für `<testdomain>` und
   `*.<testdomain>` entfernen.
3. **Am Server:**
   `rm /root/p9-block0.txt /root/p9-kennungen.sh /root/p9-beginn.sh /root/p9-ende.txt /root/p9-seiten.txt`
4. **Beim Betreiber:** die Bildschirmaufnahme löschen, falls es eine gibt.

---

## §5 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Mails an den Kunden.** In einem Termin gibt es keinen Anlass für eine. B5
  und B9 haben sie gemessen (`docs/141`, `docs/143`).
- **Die Seite des Betreibers.** Das Kriterium nennt den Kunden. Wie ein
  Betreiber, der das Panel nicht kennt, es einrichtet, wäre ein eigener
  Durchlauf.
- **Zusatzbenutzer.** Den Typ gibt es (`AccountType`), aber keine Oberfläche,
  die einen anlegt.
- **Eine Sicherung zurückspielen.** Das darf nur der Betreiber (§0,
  Beobachtungen).
- **Die Konsole der Datenbank, eigene Zertifikate, DNS-Zugangsdaten, eigene
  nginx-Direktiven und PHP-Einstellungen.** Sie gehören nicht in die erste Stunde
  eines Kunden, und zwei davon sind im Plan gesperrt.
- **Fremde Objekte.** Dass ein Kunde nicht an fremde Kennungen kommt, hält der
  Angriffsdurchgang aus P2 und für die Schnittstelle B7 (`docs/144 §8`).
- **Bildschirmleser und Tastaturbedienung.** Das Kriterium fragt nicht danach.
  Dass eine Person es allein schafft, sagt darüber nichts.
- **Mehr als eine Person.** Das Kriterium verlangt eine. Eine findet, woran
  eine scheitert, und das ist weniger, als fünf fänden.

---

## §6 · Wann er durch ist

**Nach der Lesart aus §1**, wenn der Betreiber sie so entscheidet: alle elf
Kernaufgaben erfüllt, keine Frage zur Bedienung, keine Hilfe. Die Kür zählt
nicht.

**Jede Frage und jeder Abbruch ist ein Befund**, mit der Seite und dem
Wortlaut aus dem Bogen und der Seitenfolge aus E2. Der Lauf ist dann nicht
erfüllt.

> **Ein Durchlauf mit derselben Person misst ihr Gedächtnis und nicht die
> Behebung.**

Nach den Behebungen folgt deshalb ein Nachlauf mit einer **neuen** Person,
über alle elf Kernaufgaben. Eine Behebung kann den Weg einer anderen Aufgabe
verschieben (§7 Frage 3).

**Gefahren wird gegen die Freigabe mit den Entscheidungen aus §7.** Steht dann
ein Befund aus §0 noch so da, wie er hier steht, ist seine Aufgabe von
vornherein belastet, und der Bogen sagt es. Die Abnahme spricht der Betreiber
aus.

---

## §7 · Sechs Fragen an den Betreiber — vor dem Lauf

Alle betreffen, was ein Kunde sieht, und stehen damit unter der Regel vom
27. September: *Macht es Sinn und gibt es einen spürbaren Mehrwert für einen
Nutzer im Panel?*

**Frage 1 · Die Befunde 1 bis 3 vor dem Lauf beheben?**

**Vorschlag: ja, alle drei.** Jeder macht eine Kernaufgabe ohne Frage unlösbar
oder lässt die Person ohne Antwort:
- **Befund 1:** A7 ohne Rechner.
- **Befund 2:** A6 ohne Server.
- **Befund 3:** A4 ohne Rückmeldung, wenn die Person den Knopf drückt.

Jeder ist klein und hat seinen Fall durch die Tür. Ein Lauf, der sie stehen
lässt, misst, was schon gemessen ist, und verbrennt dafür die Person: Eine
zweite, die das Projekt nicht kennt, ist schwerer zu finden als eine Freigabe.

**Frage 2 · Die Befunde 4 bis 9?**

**Vorschlag: 4, 5, 6, 7 und 9 mit derselben Freigabe**:
- 4, 5 und 6 liegen auf dem Weg.
- 7 und 9 sind je eine Bedingung.

**Befund 5 ist der grösste.** Es sind die Bezeichnungen für jede Kennung, die
ein Kunde auslöst, mit einem Wächter, der neue Kennungen ohne Bezeichnung
meldet.

**Befund 8 ist ein eigenes kleines Merkmal**, „Neues Passwort" für den
Betreiber:
- **Vorschlag:** bauen, aber nicht als Vorbedingung für diesen Lauf, denn er
  liegt nicht auf seinem Weg.
- **Was ohne ihn bleibt:** Ein Kunde, der sein Passwort vergisst, kann heute
  nur fragen, und der Betreiber kann ihm im Panel nicht antworten.

**Frage 3 · Wie wird das Kriterium gelesen?**

**Vorschlag:** wie in §1:
- elf Kernaufgaben;
- eine Frage oder ein Abbruch macht „nicht erfüllt";
- danach Behebung und ein Nachlauf mit einer neuen Person über alle elf.

**Die andere Lesart** zählte Fragen gegen eine Schwelle, etwa „höchstens zwei".
Sie wäre leichter zu erfüllen und sagte weniger. Das Kriterium nennt keine
Zahl, und „ohne zu fragen" ist eine.

**Frage 4 · Wer ist die Person?**

**Vorschlag:**
- **Wer sie ist:** jemand, der schon einmal Webspace oder eine eigene Website
  bei einem Hoster hatte, beruflich aber nicht mit Servern arbeitet.
- **Was sie nicht kennt:** das Projekt, und es hat ihr auch niemand davon
  erzählt.
- **Was nicht geht:** Verwandte oder Freunde, die beim Bauen zugesehen haben.
  Sie kennen das Projekt in dem Sinn, auf den es ankommt.

**Frage 5 · Auf welchem Gerät?**

**Vorschlag: auf ihrem eigenen Rechner**, mit ihrem gewohnten Browser und einem
SFTP-Programm, das vor dem Termin installiert ist.
- **Was ein fremder Rechner kostet:** Er misst ihre Fremdheit mit dem Rechner
  mit.
- **K2:** auf ihrem Telefon, wenn Zeit bleibt.

**Frage 6 · Gegen welche Freigabe?**

**Vorschlag: `0.9.0-rc.19`**, mit den Behebungen aus Frage 1 und 2.
- **Was auf `main` schon liegt und nicht ausgeliefert ist:**
  - die Zone auf der Wartungsseite;
  - die Lücke über „Von" bei 390 px;
  - `symfony/mime` 8.1.10;
  - `vite` 8.3.5.
- **Wer den Tag setzt:** Der Betreiber; die Nachricht und die Befehle bereite
  ich vor.

---

## §8 · Protokoll

Noch nicht gefahren.
