# P8 — Sicherungen und Wiederherstellung: das Protokoll

**Gefahren am 17. September 2026 auf `cloudsrv24`**, gegen `0.7.4-rc.11` (Punkt 1,
erster Versuch) und `0.7.4-rc.12` (alles Übrige). Der Plan ist `docs/117`, der
Lauf `docs/118`, die Messrunde davor `docs/116`.

**Sieben der acht Punkte aus `docs/118` sind gemessen und erfüllt**, beide
Ausschlusskriterien (5 und 6) darunter. **Punkt 4 ist nicht erfüllt**: Nach einer
Wiederherstellung antwortet die Website des Kunden mit **HTTP 403**.

**P8 ist damit nicht abgenommen.** Was fehlt, steht in §12.

Zehn Befunde am Prüfling, sechs an der Vorschrift und am Prüfmittel. **Vier der
zehn hat der Betreiber beim Benutzen gemeldet und keine Messung** — dasselbe
Verhältnis wie in `docs/105`.

---

## 0 · Der Vorflug

    2026-09-17    cloudsrv24
    srvpanel version            0.7.4-rc.11 → 0.7.4-rc.12
    Diagnose                    8 Prüfungen · Kaputt: 1
    Der eine Befund             fail tls.file · expired · p6-b.invalid
    Sicherungen                 0 Zeilen, /var/lib/srvpanel/backups fehlt
    Abonnements                 3 (p6-abnahme.invalid, p6-b.invalid, p6-nochmaltest)

**Der Schalter wurde gelesen und nicht gesetzt:** `before_removal => 1`,
`automatic` leer. Es gab nichts herzustellen und am Ende nichts zurückzustellen.

> **Ein Prüfkörper, der den Zustand herstellt, statt ihn zu suchen, ändert den
> Server für eine Zeile, die vielleicht schon dasteht.** (`docs/902`)

**Die Benutzerquota ist auf diesem Server wirklich eingeschaltet** —
`user quota on / (/dev/vda3) is on`, Gruppe und Projekt aus. `docs/41` hatte für
denselben Server einmal `is off` gemessen; Punkt 1 misst damit eine echte
Schranke und keine Buchhaltung.

**Und die Rollen sind getrennt, entgegen dem ersten Eindruck.** Konto #1 heisst
`Administrator` und trägt die Rolle `operator`. Echte Administratoren sind #7 und
#10. Ohne diese Ablesung hätte die Gegenprobe von Punkt 8 den falschen Betrachter
gemessen.

---

## 0b · Der Prüfkörper

    Abonnement        #142  p8-abnahme.invalid   Plan 1 (Standard)
    Systembenutzer    p1141                      db_prefix x9405b6af92eaa985
    Datenbanken       p1141_mariadb (mariadb)
                      x9405b6af92eaa985_postgres (postgres)
    Domain            p8-abnahme.invalid         type=main
    Cronjob           #24 test · 0 9 * * 1-5 · aktiv · /etc/cron.d/srvpanel-p1141
    Dateien           index.html 1109 (640) · Mietvertrag.pdf 7963827 (644)
                      Onlineabschluss….pdf 725468 (644) · unas-pro.txt 75 (644)
                      alle p1141:www-data
    §0.5              httpdocs/hinaus -> /root/p8-opfer.txt   Ziel root:root 600

**`db_prefix` steht am Systembenutzer und nicht am Abonnement.** Die erste
Abfrage hat es nicht gedruckt, weil sie es am falschen Modell suchte —
`SystemUser::$fillable` führt es, und `Backups::prefixOf()` liest es über die
**Nummer**. Derselbe Irrtum steht seit `docs/38` als Kommentar in
`Backups.php:505`.

**Zwei Namensschemata, und das ist der Grund für §0.4:** MariaDB nimmt den
Systembenutzernamen als Präfix, PostgreSQL das `db_prefix`. Form A wechselt
**beide**.

---

## 1 · Eine Sicherung entsteht und zählt nicht gegen die Quota — erfüllt

**Der erste Versuch gegen `0.7.4-rc.11` schlug fehl** (Befund 1):
*„Unbekanntes Datenbanksystem in der Dumpliste."*

Gegen `0.7.4-rc.12`:

    p8-abnahme-invalid-20260917-131349-61b03d6f
    4 Dateien, 2 Datenbanken · 8 569 962 B (Seite: 8,2 MB) · vorhanden

    Verzeichnis   drwx--x---  root srvpanel   (0710)
    Datei         -rw-r-----  root srvpanel   (0640)

**„2 Datenbanken" ist die Messung, für die es Befund 1 gibt** — die
PostgreSQL-Datenbank ist mit drin.

Die Quota des Kunden, gemessen mit Gegenprobe:

| | `p1141` | Dateien |
|---|---|---|
| vor der Sicherung | 8528K | 11 |
| **nach der Sicherung** | **8528K** | **11** |
| mit 20-MiB-Prüfkörper im Kundenbaum | 29008K | 12 |
| nach dem Löschen | 8528K | 11 |

Die Differenz in der Mitte ist **20480K**, also exakt 20 MiB. Damit ist
„unverändert" eine Messung und kein Schweigen.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Nebenbei belegt:** Der Ablagename trägt UTC (`131349`), die Spalte „Erstellt"
die eingestellte Zone (`15:13:49`, CEST).

---

## 2 · Das Abonnement wird vollständig gelöscht — erfüllt

Vorgang **888** `subscription.remove`, `fertig`, Argumente typisiert:
`{"name": "p8-abnahme.invalid", "user": "p1141", "quota_mb": 5120}`.

    id -u p1141                      id: 'p1141': no such user
    /var/www/vhosts/                 nur die drei p6-Bäume
    MariaDB  | grep p1141            leer
    PostgreSQL | grep x9405b6…       leer
    Archive                          ZWEI, 8 569 962 und 8 569 974 B
    Zeilen                           2 und 3, sub=NULL, Name abgeschrieben

**Die Reihenfolge aus Schritt 10 ist belegt:** Die zweite Sicherung trägt
`131955`, `subscription.remove` lief `13:19:56 UTC` — eine Sekunde später. Erst
sichern, dann abreissen.

Das ist der Satz, für den `backups.subscription_id` auf `nullOnDelete` steht: Die
Sicherung überlebt ihr Abonnement, und was sie gesichert hat, steht als Abschrift
daneben — wie `subscription_name` seit `docs/35`.

---

## 3 · Die Wiederherstellung läuft durch einen Vorgang — erfüllt, mit einer Lücke

    886 pg.database.remove      succeeded  13:19:56
    887 db.dump.remove          succeeded  13:19:56
    888 subscription.remove     succeeded  13:19:56
    889 subscription.provision  succeeded  13:28:36   ← vorher
    890 backup.restore          succeeded  13:28:36   ← danach
    891 web.logrotate.apply     succeeded  13:28:37
    892 php.pool.apply          succeeded  13:28:37
    893 web.site.apply          succeeded  13:28:37
    894 db.restore              succeeded  13:28:38
    895 pg.restore              succeeded  13:28:38
    896 acme.certificate.issue  failed     13:28:38 → 13:28:39

`subscription.provision` vor `backup.restore`, beide `succeeded` — die
Reihenfolge, die das Kriterium verlangt.

**Vorgang 896 ist kein Befund.** `.invalid` ist nach RFC 2606 dafür reserviert,
nie aufzulösen; keine Zertifizierungsstelle kann dafür ausstellen.
`docs/913 §15` hat den Fall entschieden.

**Der Fortschrittsteil ist nicht messbar.** Von der Bereitstellung bis
`pg.restore` liegen **zwei Sekunden**; ein Balken hat daran nichts zu zeigen.

> **Ein Kriterium, das eine Dauer braucht, misst an einem Gegenstand, der keine
> hat, nichts.**

Das ist am **Prüfkörper** gescheitert (8,5 MB, zwei leere Datenbanken) und nicht
am Gegenstand — es fällt deshalb nicht als „nicht herstellbar" aus.

---

## 4 · Danach steht das Abonnement wieder — **nicht erfüllt**

**Erfüllt und gemessen:**

    id p1142                     uid=1004(p1142) gid=1004(p1142)
    db_prefix                    xc3b82b2c371424d2
    Datenbanken                  p1142_mariadb · xc3b82b2c371424d2_postgres
    Modi                         640 · 644 · 644 · 644   (unverändert)
    Cronjob                      /etc/cron.d/srvpanel-p1142 · 0 9 * * 1-5 p1142
    §0.5  /root/p8-opfer.txt     root:root 600            (unverändert)
          httpdocs/hinaus        p1142:p1142 -> /root/p8-opfer.txt

**Der Eigentümerwechsel ist dem Verweis nicht gefolgt.** Das ist die Hälfte, die
`BackupRestoreTest` in der CI nicht halten kann, weil dort die Kennung fehlt —
und sie steht jetzt auf einem echten Server. `lchown` statt `chown`, gemessen an
der Wirkung.

**Nicht erfüllt: die Website antwortet mit 403.** Siehe Befund 4.

**Die Zeilenzahl ist nicht messbar** — beide Datenbanken wurden leer angelegt,
`show tables` gibt nichts. Am Prüfkörper gescheitert, nicht am Gegenstand.

---

## 5 · Die Vhost-Datei besteht die Bestandsdiagnose *(Ausschluss)* — erfüllt

| | `Kaputt` | `web.%`-Befunde |
|---|---|---|
| heil, 15:45:17 | 1 | **0** |
| ohne `access_log` | 2 | `fail web.file · directive_lost · p8-abnahme.invalid` |
| Zeile zurück, 15:45:22 | 1 | **0** |

`nginx -t` im heilen Zustand: `syntax is ok`, `test is successful`. Die Datei ist
3646 B, `root:root`.

> **Eine Sicherung, die erzeugte Dateien aufhebt, sichert den Ausgang einer
> Rechnung und nicht ihre Eingaben.**

**Zwei Lücken, damit das Protokoll seine eigenen nicht verschweigt:** Der `sed`
hat **drei** `access_log`-Zeilen entfernt und nicht eine (10, 40, 57) — die
Gegenprobe war gröber als bestellt. Und `nginx -t` ist im **kaputten** Zustand
nicht gefahren worden; dass es dazu `rc=0` gibt, steht aus `docs/102` Punkt 8 und
ist hier nicht nachgemessen.

---

## 6 · Die Seite sagt, was sich geändert hat *(Ausschluss)* — erfüllt

Der Ergebnisblock auf `/operations/890`, im Wortlaut:

    system_user   alt: 1141                         neu: "p1142"
    db_prefix     alt: "x9405b6af92eaa985"          neu: "xc3b82b2c371424d2"
    databases     alt: "p1141_mariadb"              neu: "p1142_mariadb"
                  alt: "x9405b6af92eaa985_postgres" neu: "xc3b82b2c371424d2_postgres"

    passwords     „Die Datenbankzugänge stehen wieder da und haben ein neues,
                   unbekanntes Passwort. Jeder braucht einmal ‚Passwort neu
                   setzen'."

Alle drei Teile des Kriteriums stehen da. Der Zertifikatsteil trifft nicht zu:
`p8-abnahme.invalid` hat keines, und unter `.invalid` kann es keines geben.
Gemessen: genau **ein** hochgeladenes Zertifikat auf diesem Server, `sub=137`
(`p6-b.invalid`), Datei **und** Zeile — kein Rest.

**Daneben zwei Befunde** (5 und 6) und eine Beobachtung: Der Block ist rohes
JSON, und `OperationPolicy::view()` lässt auch den **Kunden** auf diese Seite.
Das Kriterium verlangt keinen Fliesstext; gelesen wird der deutsche Satz
trotzdem zwischen `"db_prefix": {` und `"failures": [`.

---

## 7 · `backup.verify` urteilt in beide Richtungen — erfüllt

| Zustand | Urteil | Zähler |
|---|---|---|
| heil | keine Befunde | — |
| ein Byte bei 2048 gekippt | `fail corrupt` | Kaputt: 1 |
| Byte zurück | keine Befunde | 0 |
| Datei ohne Zeile | `warn orphan` | Auffällig: 1 |
| Datei fort | keine Befunde | 0 |

**Die beiden Schweregrade sind gemessen und nicht behauptet:** ein beschädigtes
Archiv ist `fail`, eine Datei ohne Zeile nur `warn`.

> **Ein Befund, der verschwindet, wenn sein Grund verschwindet, ist gemessen.
> Ein Befund, der nur entsteht, ist abgelegt.**

**Und die Laufzeit beantwortet eine offene Frage:** `backup-verify` läuft
**205 ms** über zwei Archive zu je 8,5 MB (`real 0m0,205s`). `docs/117 §9`
Punkt 4 fragte nach `retry_after` (90 s) gegen einen Lauf von 1800 s — drei
Grössenordnungen, wie schon bei A10 (391 ms).

---

## 8 · 390 px — erfüllt

Zwanzig Lagen, fünf Seiten × 390/1440 × hell/dunkel:

| Seite | 390 hell | 390 dunkel | 1440 hell | 1440 dunkel |
|---|---|---|---|---|
| `/backups` | 0 | 0 | 0 | 0 |
| `/subscriptions/143/backups` | 0 | 0 | 0 | 0 |
| `/backups/2/restore` | 0 | 0 | 0 | 0 |
| `/operations/890` | 0 · rollt 2 | 0 · rollt 2 | 0 | 0 |
| `/settings/backups` | 0 | 0 | 0 | 0 |

Alle zwanzig mit `gegenprobe=200 (soll 200)` und `schiebt=0`. Die zwei Roller auf
der Vorgangsseite sind die JSON-Blöcke und sollen rollen.

**Die Gegenprobe zum Knopf, als Administrator (#7/#10):** „Herunterladen" steht
nicht da, und `/subscriptions/143/backups/4/download` gibt **403** mit der eigenen
Fehlerseite („Kein Zutritt · Dieser Bereich gehört einer Rolle, die dieses Konto
nicht hat.") — nicht Laravels englische Vorgabe.

Belegt ist dabei auch, dass die Rolle wirklich griff: kein „Logs", kein
„Sicherungen" unter Einstellungen, kein Wartungsmodus, keine Konten.

**„Zurückspielen" steht dort zu Recht:** `SubscriptionPolicy::create()` fragt
`isAdmin()`, also den **Typ**. Genau diese Trennung ist der Grund, dass
`downloadBackup()` eine eigene Methode bekommen hat.

**Nicht nachgesehen:** Auf `/backups/2/restore` meldet Chromium `3 issues`; auf
den vier anderen Seiten `No issues`. Die Seite ist die einzige mit einem
Formular. Was dort steht, ist offen.

**Am 20. September 2026 gemessen — `docs/126`.** Drei Einträge, einer je
Bedienelement: *„A form field element should have an id or name attribute"*. Die
Ausfüllhilfe und kein Fund. Der Satz oben trägt dabei nicht als Erklärung: Ohne
`<form>` darum stehen dieselben drei Einträge da; die Seite ist die einzige der
fünf, die überhaupt Bedienelemente hat.

**Und das `No issues` der vier anderen ist am selben Tag erklärt.**
`/settings/backups` trägt zwei `<input type="checkbox">` ohne `id` und `name`,
und **Chrome 153 übergeht Kästchen und Optionsknöpfe** — gemessen an drei
Prüfblättern, die aufrechnen (1 · 6 von 8 · 1 von 3). Das `No issues` ist dort
die richtige Antwort, und die **2** des Nachbaus unter Chromium 141 ebenso.

> **Zwei Werkzeuge, die dasselbe messen und verschieden antworten, widersprechen
> einander nicht — sie messen verschiedene Grundmengen.**

---

## 8b · Der Abbau — belegt

| | |
|---|---|
| Sicherungen | vier eingereiht, **0** übrig, Dateien fort |
| Abonnements | **3** — die drei p6-Bäume |
| Diagnose | `8 Prüfungen · Kaputt: 1` |
| Der eine Befund | `fail tls.file · expired · p6-b.invalid` |
| `backup-verify` | „Keine Befunde an den Sicherungen" |

Zeile für Zeile die Grundlinie aus §0. `before_removal` stand auf `1` und ist nie
gesetzt worden.

**Entfernt wurde über `Backups::remove()` aus `tinker` und nicht über das
Panel** — siehe Befund 8: Den Weg über die Oberfläche gibt es nicht.

---

## 9 · Die Befunde

### Befund 1 · Keine Sicherung für PostgreSQL *(behoben in rc.12)*

`BackupCreate::dumps()` liess `['mariadb', 'postgresql']` durch; das Panel
schickt `DatabaseEngine::Postgres`, also `postgres`. Jedes Abonnement mit einer
PostgreSQL-Datenbank bekam keine Sicherung — von Hand mit sichtbarem Fehlschlag,
im nächtlichen Lauf als ein fehlgeschlagener Vorgang je Nacht.

Die Liste war in **beide** Richtungen falsch: Der Wert konnte vom Panel nicht
kommen, und `RestoreLifecycle` könnte ihn mit `tryFrom()` nicht zurücklesen.

> **Ein Wert, den der Absender nicht senden und der Empfänger nicht lesen kann,
> ist keine Positivliste mit einem Tippfehler — er ist eine Wand.**

Kein Wächter sah es: `DatabaseEngineTest` hält die Panelseite, der Agent darf das
Enum nicht importieren (erste Grenze). Jede Seite für sich war in Ordnung.

> **Fehler an Nähten zwischen zwei Dateien** — dieselbe Stelle wie die sechs
> Befunde von A10.

Und die schärfste Zeile: Der Kopf von `DatabaseEngine` schreibt ausdrücklich hin,
*warum* der Wert `postgres` heisst.

> **Eine Begründung schützt die Datei, in der sie steht.**

`BackupEngineSeamTest` misst die Naht an der **Wirkung**, zwei Eingriffe im
Bruchskript. Ein Wächter über die Konstante allein hätte nicht gereicht —
gemessen: mit richtiger Konstante und einem Literal in `dumps()` bleibt er grün.

### Befund 2 · Die Seite der Sicherungen stand still *(behoben in rc.12)*

Gemeldet vom Betreiber. Nach „Jetzt sichern" blieb die Zeile auf dem Stand des
Seitenaufbaus. Von 58 Seiten fragte nur die Übersicht nach.

> **Eine Anzeige, die den Zustand vor der Änderung zeigt, verleitet zu der
> Handlung, die die Änderung zurücknimmt.** (`docs/84`)

**`PartialReloadTest` hat den ersten Wurf angehalten:** `only: ['backups']` zeigte
auf eine Angabe, die der Steuerungscode fertig übergab. `backups` ist jetzt ein
Verschluss.

**Ob die Zeile ohne Neuladen springt, ist nicht beobachtet worden** — dreimal
gefragt, dreimal war der Betreiber in dem Moment woanders. Die Behebung ist im
Test belegt, im Betrieb nicht.

### Befund 3 · Ein Eingriff traf die Sekundengrenze *(behoben in rc.12)*

Der Wächterlauf meldete `Ablagename ohne Zufallsteil — passed (erwartet:
failed)`. Der Fehler lag nicht im Eingriff: Der Wächter setzte seine beiden
`create()` nackt untereinander und verliess sich darauf, dass sie in derselben
Sekunde landen. Sein eigener Kopf behauptete das Gegenteil.

Gemessen: mit dem Eingriff **1 grüner Lauf von 25**; nach der Behebung **0 von
25**, heil **25 von 25** grün.

> **Ein Prüfkörper, der einen Zustand behauptet, statt ihn herzustellen, misst
> ihn fast immer — und die Läufe, in denen er es nicht tut, sehen aus wie ein
> Wächter, der seine Regel nicht hält.**

**Nicht über `Carbon::setTestNow()`:** Der Zeitstempel kommt aus `gmdate()`.

> **Eine Uhr, die man anhält, hält nur die an, die auf sie hört.**

### Befund 4 · Nach der Wiederherstellung antwortet die Website mit 403

**Das ist der Ausfall von Punkt 4.**

    runuser -u www-data -- test -r …/httpdocs/index.html   NICHT lesbar
    curl -H 'Host: p8-abnahme.invalid' http://127.0.0.1/   HTTP 403

Der Vergleich mit einem unberührten Abonnement sagt, wie es aussehen müsste:
`p6-abnahme.invalid` trägt `-rw-r----- p1139 www-data`. Beide Male Modus `640` —
der Unterschied ist allein die **Gruppe**: `p1141:www-data` wurde zu
`p1142:p1142`.

`SubscriptionProvision` schreibt die Absicht wörtlich hin: *„`httpdocs` gehört
`<benutzer>:www-data`, und der Webserver kommt über die Gruppe heran — jede
Datei, die dort entsteht, trägt `p1132:www-data 0640`."* Dafür trägt `httpdocs`
setgid (`0o2750`).

`BackupRestore::own()` setzt über den ganzen Baum `uid:gid` des Benutzers und
überschreibt damit die setgid-Vererbung. `applyTree()` holt danach das Schema
zurück — aber nur für die **Verzeichnisse**; `applyTree()` ist nicht rekursiv.

**Und der Kopf von `BackupRestore` beschreibt genau diesen Schaden**, eine Ebene
zu hoch: *„Ein `chown` über den ganzen Baum macht daraus dreimal `%u:%u`, und der
Webserver käme an das Dokumentenverzeichnis nicht mehr heran."*

> **Eine Behebung, die eine Ebene zu hoch ansetzt, sieht aus wie die Lösung des
> Problems, das sie beschreibt.**

Kein Wächter konnte es sehen: `BackupFormTest::test_the_directory_scheme_is_really_rebuilt`
misst das Verzeichnisschema — die Verzeichnisse. Über die Dateien darin sagt
nichts etwas, und in der CI scheitert ein `chown` ohnehin an der Kennung.

### Befund 5 · `failures` meldet einen Fehlschlag, den es nicht gab

    "failures": [{ "gegenstand": "p8-abnahme.invalid",
                   "grund": "Diese Sorte Domain lässt sich nicht anlegen." }]

Die Domain ist da — gemessen, `type=main`, Vhost-Datei liegt, Punkt 5 findet
keinen `web.%`-Befund.

`RestoreLifecycle::rebuildDomains()` läuft über **alle** Domains der Beschreibung
und ruft `Domains::create()`. `DomainType::creatable()` gibt
`[Addon, Subdomain, Alias]` zurück — `Main` gehört nicht dazu, denn die
Hauptdomain legt `subscription.provision` an (Vorgang 889, vorher). Die Abweisung
ist korrekt; sie als Fehlschlag zu melden ist es nicht.

> **Ein gemeldeter Fehlschlag für etwas, das gelungen ist, ist schlimmer als kein
> Bericht — er schickt den Leser dorthin, wo nichts zu beheben ist.**

Und die zweite Wirkung wiegt schwerer: Wer bei **jeder** Wiederherstellung einen
unechten Eintrag in dieser Liste sieht, liest sie beim nächsten Mal nicht mehr —
und dann steht dort ein echter.

### Befund 6 · `alt` und `neu` tragen zwei Formen

`"alt": 1141` ist eine **Zahl**, `"neu": "p1142"` eine **Zeichenkette**. Dieselbe
Grösse, nebeneinander, in zwei Fassungen.

> **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte Auskunft,
> sondern eine widersprüchliche.** (`docs/91`, Befund 5)

### Befund 7 · Der Betreiber hat keinen Menüpunkt zu `/backups`

Gemeldet vom Betreiber. `PanelLayout.vue` trägt `Sicherungen → /backups` im
**Kundenzweig** (`is_admin === false`) und `Sicherungen → /settings/backups` im
Betreiberzweig unter *Einstellungen*. Die Gruppe *Verwaltung* des Betreibers
trägt Kunden, Pläne, Abonnements, Domains, Datenbanken — sonst nichts.

**Und der Quelltext behauptet das Gegenteil:** *„Die beiden Einträge stehen in
verschiedenen Gruppen — „Verwaltung" führt zu den Sicherungen der Kunden."*
Diesen Eintrag gibt es nicht.

> **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung — und
> nichts prüft sie.**

Das ist die **vierte** Wiederholung derselben Familie — Dateimanager
(`docs/55` Befund 8), SFTP-Zugang (`docs/59` Befund 19), „Job anlegen"
(`docs/64` Befund 13). Und jedes Mal hat der Betreiber es gemeldet.

Diesmal kommt etwas dazu: Der Bereich **„Ohne Abonnement"** ist nach `docs/117`
nur für den Betreiber und der einzige Weg, eine Sicherung ohne Abonnement
zurückzuspielen. **Die eine Handlung, die nur der Betreiber ausführen kann, liegt
auf der einen Seite, zu der nur der Kunde einen Menüpunkt hat.**

### Befund 8 · Eine verwaiste Sicherung lässt sich nicht entfernen

Gemeldet vom Betreiber. Der Bereich „Ohne Abonnement" hat keine Spalte „Aktion",
und die Route kann es auch nicht:

```php
// BackupController::destroy()
abort_unless($backup->subscription_id === $subscription->id, 404);
```

Bei einer verwaisten Zeile ist `subscription_id` `null` — die Bedingung kann nie
zutreffen, und die Adresse braucht ohnehin ein Abonnement, das es nicht gibt.

**Der Griff selbst existiert und ist sorgfältig gebaut.** `Backups::remove()` hat
einen eigenen Zweig für genau diesen Fall, mit einer Berichtigung vom
16. September. Ausgezählt, wer ihn erreichen kann: `destroy()` sperrt ihn aus,
`Retention::prune()` fragt `where('subscription_id', …)`. **Der Zweig hat keinen
erreichbaren Aufrufer.**

> **Ein Griff, den es gibt und zu dem kein Weg führt, ist von einem, den es nicht
> gibt, nicht zu unterscheiden.**

Die Ironie ist der Punkt: `Backups::removeAll()` wurde am **16. September**
gelöscht, *weil* es keinen Aufrufer hatte — und am selben Tag entstand dieser
Zweig, der auch keinen hat.

### Befund 9 · Aus einem Cronjob sind zwei geworden

Die Beschreibung in Sicherung 5 (Rückbau von #143) trägt **zwei byteweise
identische** Einträge:

    { "label": "test", "command": "true", "minute": "0", "hour": "9",
      "day_of_month": "*", "month": "*", "day_of_week": "1-5", "active": true }

Die Domains daneben stehen korrekt mit **einem** Eintrag.

**Gemessen:** #142 hatte einen, die Beschreibung in Sicherung 2 trug einen, #143
hatte bei seinem Rückbau zwei.

**Nicht gemessen: wann der zweite entstand.** `rebuildCron()` wird in
`afterSuccess()` genau einmal gerufen und legt je Eintrag eine Zeile an;
`Cron::create()` legt eine an. Zwischen Wiederherstellung (15:28) und Rückbau
(16:26) liegt eine Stunde Lauf, in der nichts angelegt wurde.

Die Ursache entscheidet die nächste Runde mit **einer** Ablesung unmittelbar nach
dem Zurückspielen.

> **Zwei Messungen, die auseinandergehen, entscheidet keine Überlegung, sondern
> die dritte.**

### Befund 10 · Ein leeres Verzeichnis bleibt und wird nicht gemeldet

Nach dem Entfernen aller Stände steht
`/var/lib/srvpanel/backups/p8-abnahme.invalid/` leer da, und `backup-verify`
meldet „Keine Befunde an den Sicherungen". Die Prüfung sucht Dateien ohne Zeile;
ein leeres Verzeichnis hat keine Datei.

`docs/117 §9` sagt „was liegenbleibt, meldet die Bestandsdiagnose" — für dieses
eine gilt es nicht. Der Griff dafür (`backup.remove` ohne `storage`) existiert im
Agenten und hat, wie der Zweig aus Befund 8, keinen Aufrufer.

---

## 10 · Was der Lauf über sich selbst gelernt hat

**Sechs Fehler steckten in der Vorschrift oder im Prüfmittel.**

### Die Vorschrift zählte sieben Punkte, es sind acht

`docs/118 §10` sagte „Alle sieben Punkte erfüllt". Die Zahl stammt aus der Zeit,
als §0.1 den alten Punkt 8 (das Fernziel) strich; „390 px" rückte nach, die Zahl
blieb stehen. Berichtigt am 17. September.

> **Eine Zahl neben einer Aufzählung wird nicht dadurch richtig, dass die
> Aufzählung stimmt — sie ist die einzige Stelle, an der niemand nachzählt.**

### §8b verlangte etwas, das der Prüfling nicht kann

„Die Sicherungen dieses Laufs entfernen — über das Panel, nicht mit `rm`." Das
geht nicht (Befund 8).

> **Ein Abnahmelauf, der eine ungeprüfte Annahme als Anweisung führt, prüft sie
> nicht — er führt sie aus.**

### Ein Leser mit `take(4)` hat die falschen vier geholt

Punkt 3 fragte nach zwei Vorgängen; eine Wiederherstellung erzeugt sieben. Die
vier neuesten waren die Folgeoperationen.

> **Eine abgeschnittene Liste sieht aus wie eine vollständige — sie sagt nicht,
> wo sie aufhört.**

### Ein `printf` machte aus `p1141` ein `p11141`

`"p1%d"` gegen eine Spalte, die schon `1141` trägt. Punkt 4 vergleicht genau
diesen Namen alt → neu; mit der falschen Grundlinie hätte der Lauf einen Befund
erfunden. Widerlegt hat es der `ls` in derselben Aufnahme.

### Eine Abfrage traf die alte Reservierung

`SystemUser::query()->where('subscription', $name)->first()` gab `p1141` und das
alte Präfix zurück. `system_users` hält seit `docs/35` die **verbrauchten** Namen
fest — nach einem Rückbau steht derselbe Name zweimal darin. Ausgezählt liest
**keine** Stelle in `app/` diese Tabelle über den Namen; alle sechs gehen über
`number`.

> **Eine Spalte, die eine Reservierung festhält, ist kein Schlüssel.**

### Und eine Behauptung über die Policy war falsch

„Zurückspielen ist Betreibersache" — `SubscriptionPolicy::create()` fragt
`isAdmin()`, also den Typ. Die Messung hat es in derselben Minute widerlegt.

---

## 11 · Die Bilanz

| Punkt | |
|---|---|
| 1 Sicherung, Quota | **erfüllt** |
| 2 Rückbau | **erfüllt** |
| 3 Vorgang, Reihenfolge | **erfüllt** — Fortschritt nicht messbar |
| 4 Abonnement steht wieder | **nicht erfüllt** (Befund 4) |
| 5 Bestandsdiagnose *(Ausschluss)* | **erfüllt** |
| 6 Die Seite sagt es *(Ausschluss)* | **erfüllt** |
| 7 `backup.verify` | **erfüllt** |
| 8 390 px | **erfüllt** |

**Vier der zehn Befunde hat der Betreiber beim Benutzen gemeldet** — der fehlende
Takt, der fehlende Menüpunkt, der fehlende Entfernen-Knopf, und die Frage nach
der Navigation, aus der Befund 7 wurde. Keine Messung hat einen davon gefunden.

**Und drei der zehn sitzen an derselben Art Stelle:** Ein Griff ist gebaut, und
es führt kein Weg dorthin (8, 10), oder eine Zeile behauptet einen Weg, den es
nicht gibt (7). Das ist die Familie, die `docs/117` am 16. September mit
`Backups::removeAll()` einmal aufgelöst hat — und die am selben Tag zweimal neu
entstanden ist.

---

## 12 · Was offen bleibt

**Gebaut am 17. September 2026, und keine dieser Behebungen hat einen Server
gesehen** — der Nachlauf dazu steht unten:

1. **Befund 4** — die Gruppe unter `httpdocs`. `BackupRestore::own()` löst die
   Kennung je Bereich des Schemas auf, das Schema nennt
   `SubscriptionProvision::area()`.
2. **Befund 8** — `DELETE /backups/{backup}` und der Knopf auf der Auswahlseite.
3. **Befund 5** — `rebuildDomains()` überspringt, was schon dasteht; damit
   findet auch eine Subdomain unter der Hauptdomain ihren Elternteil wieder.
4. **Befund 6** — beide Seiten des Paares tragen den Namen.
5. **Befund 7** — der Menüpunkt unter „Verwaltung"; die Einstellungsseite heisst
   jetzt „Automatische Sicherung".
6. **Befund 10** — `backup.list` gibt die Verzeichnisse heraus, und die Diagnose
   meldet ein leeres als Rest.

**Offen:**

- **Befund 9** — die Ursache der Verdopplung. Sie entscheidet die nächste Runde
  mit **einer** Ablesung unmittelbar nach dem Zurückspielen; vorher ist jede
  Behebung geraten.
- **Der Griff zu Befund 10 hat weiterhin keinen Aufrufer.** `backup.remove` ohne
  `storage` räumt das Verzeichnis ab, und kein Weg im Panel führt dorthin — der
  Betreiber bekommt einen Befund, den er aus dem Panel nicht klären kann. Ob das
  Panel ein leeres Verzeichnis von sich aus entfernen darf und an welcher
  Stelle, ist eine **Entscheidung** und keine Ableitung; sie gehört vor die
  Abnahme.

  > **Ein Griff, den es gibt und zu dem kein Weg führt, ist von einem, den es
  > nicht gibt, nicht zu unterscheiden.**

**Der Nachlauf dazu ist `docs/120`**, ausgeschrieben vor dem Fahren. Er misst in
**einer** Runde:

- **Punkt 4** vollständig, gegen die behobene Fassung.
- **Der Fortschritt** aus Punkt 3 — an einem Prüfkörper, der lange genug braucht.
- **Die Zeilenzahl** aus Punkt 4 — an Datenbanken mit Inhalt.
- **Der Cronjob** unmittelbar nach dem Zurückspielen.

**Benannt offen und kein Kriterienausfall:**

- Der Rest aus P7/A6: `tls.file / expired / p6-b.invalid`. `.invalid` ist nach
  RFC 2606 nicht ausstellbar; der Befund ist ein Rest des Prüfstands und nicht
  des Prüflings (`docs/913 §15`). Wer die Zeile loswerden will, entfernt die
  Domain.
- Die `3 issues` auf `/backups/2/restore` sind nicht nachgesehen.
  **Am 20. September 2026 nachgesehen** — `docs/126`. Drei Einträge, einer je Bedienelement, alle `FormEmptyIdAndNameAttributesForInputError`; die Ausfüllhilfe und kein Fund, entschieden schon am 23. August in `docs/76`.
- Ob die Zeile auf der Sicherungsseite ohne Neuladen springt, ist nicht
  beobachtet worden.
- `nginx -t` im kaputten Zustand von Punkt 5 ist nicht gefahren.
- Dass eine Sicherung nach dem Zurückspielen **verwaist bleibt**, ist richtig
  (ein neues Abonnement ist ein anderes, und die Aufbewahrung des neuen löschte
  sonst die einzige Kopie des alten) — steht aber in keinem Dokument
  ausgeschrieben.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
