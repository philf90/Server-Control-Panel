# Die Messrunde vor P8 — Sicherungen und Wiederherstellung

Gefahren am **15. September 2026** im Container, gegen `main @ ad1ecdf`, **vor**
der ersten Zeile Plan. Sie beantwortet die sieben Fragen aus
`docs/115 §6.1`; die Messvorschrift liegt als **`tests/sicherung-messen.php`**
daneben und nicht in einem Sitzungsverlauf.

> **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht noch
> einmal.** (`docs/66`)

**Jede Messung hat ihre Gegenprobe, und jede sagt, was sie nicht sagt.** Der
Abschnitt „sagt nicht" ist kein Beiwerk: `docs/100` und `docs/113` sind an
Sätzen teuer geworden, die mehr behaupteten als der Prüfkörper hergab.

**Der Prüfkörper von M1 und M4** ist ein Baum in der Form eines Kundenauftritts
und trägt seine Gegenprobe in sich: `text/` sind echte Dateien dieses Repos,
`medien/` sind Zufallsbytes — der Grenzfall „schon komprimiert", dem ein JPEG
sehr nahe kommt. Ergäben beide dasselbe Verhältnis, misst der Lauf nicht die
Verdichtung, sondern das Kopieren.

---

## Was die Runde umgeworfen hat

Fünf Dinge, und **vier davon ändern die Form**, die `docs/115` sich gedacht
hatte.

1. **Kein Schreiber in PHP trägt, was eine Wiederherstellung braucht** (M1b).
   Weder `ZipArchive` noch `PharData` legen Eigentümer, Verweis oder das
   setgid-Bit ab. Nur `tar(1)` tut es — und `tar` steht **nicht** auf der
   Positivliste des Runners.
2. **Ein Verzeichnis je Datei passt nicht durch die Leitung zum Agenten** (M4).
   Bei rund **14 000 Einträgen** ist Schluss, während `Packer::MAX_ENTRIES`
   **20 000** zulässt. Das Archiv nimmt also mehr, als die Leitung beschreiben
   kann.
3. **Ein wiederhergestelltes Abonnement bekommt weder seinen Systembenutzer
   noch sein Datenbankpräfix zurück** (M5) — und der Agent **weist die alten
   Datenbanknamen ab**. Damit zeigt die `wp-config.php` des Kunden nach einer
   Wiederherstellung auf eine Datenbank, die es nicht mehr gibt.
4. **Eine wörtlich zurückgespielte Vhost-Datei aus einer älteren Fassung wird
   vom Nachtlauf gemeldet** (M6) — gemessen an der echten Vorlage und am echten
   Leser, nicht an einer nachgebauten Liste.
5. **Und eine Begründung im Quelltext stimmt nicht.** `FilesCompress` sagt seit
   P6, `phar.readonly` erlaube `PharData` nur das Lesen. Gemessen schreibt
   `PharData` sehr wohl; abgewiesen wird `Phar`. Die **Entscheidung** (Zip statt
   Tar) trägt trotzdem — M1b zeigt `PharData` sogar als den schlechteren von
   beiden.

   > **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist auch
   > dann falsch, wenn der Handgriff daneben richtig ist — und er hält länger
   > als der Handgriff, weil ihn der Nächste liest und glaubt.**

---

## M1 · Was kostet eine Dateisicherung — Zeit, Platz, Speicher?

Prüfkörper: **1354 Dateien, 60,2 MiB.**

| | roh | gepackt | Verhältnis | Dauer | Durchsatz | Speicher |
|---|---|---|---|---|---|---|
| Quelltext | 15,0 MiB | 5,8 MiB | **0,388** | 0,80 s | 19 MB/s | **+0** |
| schon komprimiert | 45,2 MiB | 45,3 MiB | **1,000** | 1,17 s | 39 MB/s | **+0** |
| beides | 60,2 MiB | 51,1 MiB | 0,848 | 1,97 s | 31 MB/s | **+0** |

**Die Gegenprobe steckt in den beiden Verhältnissen.** 0,388 gegen 1,000 — zwei
verschiedene Zahlen, also misst der Lauf die Verdichtung. Wären beide gleich,
mässe er das Kopieren.

**Die Zahl, auf die es ankommt, ist die letzte Spalte.** An einer einzelnen
Datei von 1 GiB gemessen bleibt die Spitze bei **2,0 MiB** — `ZipArchive`
strömt, die Grösse der Quelle geht nicht in den Speicher. Damit ist die Sorge
aus `Db\Dump` („der Weg, auf dem der Agent den Speicher des Servers füllt") für
das **Packen** ausgeräumt; sie gilt weiter für das **Zurückreichen** durch den
Socket.

**Was M1 nicht sagt:** wie lange dasselbe auf einer langsamen Platte dauert, und
was ein Auftritt mit 100 000 Dateien kostet — der Prüfkörper hat 1354.

---

## M1b · Welches Archiv trägt Rechte, Eigentümer und Verweis?

Die eigentliche Frage von M1, und sie war in `docs/115` nicht gestellt.

Prüfkörper: eine Datei `0644`, ein privater Schlüssel `0600` mit fremder UID,
ein Symlink, ein Verzeichnis mit **setgid** (`2750`) und ein **leeres**
Verzeichnis (`0700`) — `tmp` und `mail` eines Abonnements sind im Grundzustand
leer.

| Schreiber | zurück wie vorher | was fehlt |
|---|---|---|
| `ZipArchive` (der Weg, den `Packer` geht) | **1 von 5** | Eigentümer, Verweis; Verzeichnisse kommen als **0777** zurück |
| `PharData` (Tar aus PHP) | **1 von 5** | Eigentümer, Verweis, setgid — und das **leere Verzeichnis ganz** |
| `tar(1)` von aussen | **5 von 5** | — |

**`tar` von aussen ist die Gegenprobe.** Trüge auch das nichts, läge der Fehler
in der Messung und nicht in den Schreibern.

> **Ein Archiv, das den Eigentümer nicht trägt, ist keine Sicherung eines
> Abonnements — es ist eine Sicherung seiner Dateinamen.**

**Und `phar.readonly` blockiert `PharData` nicht** (gemessen, mit `Phar` als
Gegenprobe):

    phar.readonly        = '1'
    PharData schreiben   : GELUNGEN   (Tar, danach wieder gelesen)
    PharData komprimieren: GELUNGEN   (Phar::GZ)
    Phar schreiben       : ABGEWIESEN — "disabled by the php.ini setting phar.readonly"

**Was M1b nicht sagt:** ob `tar` je auf die Positivliste des Runners darf — das
ist eine Entscheidung und keine Messung. Und nicht, was ein Zip mit
`setExternalAttributesName` trüge; gemessen ist der Weg, den `Packer` heute
geht.

---

## M2 · Zählt eine Sicherung gegen die Quota des Kunden?

**Gefragt wird der Eigentümer und nicht der Pfad**, und das folgt aus dem
Quelltext und nicht aus einer Auslegung: `SubscriptionUsage` liest `repquota`
über das Dateisystem, das `/var/www/vhosts` trägt, und gibt heraus, was der Form
`pNNNN` entspricht. Ein Verzeichnis kommt in dieser Frage gar nicht vor.

Gemessen im Container:

    /var/www/vhosts           Gerät /dev/vda · Nummer 65024
    /var/lib/srvpanel/dumps   Gerät /dev/vda · Nummer 65024
    dasselbe Dateisystem?     JA — dann schützt der Pfad nichts
    Gegenprobe /dev/shm       Nummer 27, also verschieden

**Die Gegenprobe ist nötig**, weil eine Gerätenummer, die überall gleich wäre,
dieselbe Antwort gäbe wie eine richtige Messung.

Was die bestehenden Dumps von der Quota des Kunden fernhält, ist damit **nicht**
ihr Ablageort, sondern ihr Eigentümer: `Db\Dump` legt sie als `root:srvpanel
0640` ab.

> **Ein Ablageort ausserhalb des Kundenverzeichnisses hält eine Datei von der
> Quota nur fern, solange sie jemand anderem gehört.**

**Was M2 nicht sagt:** ob die Quota greift. Dieser Container kann sie nicht
erzwingen — `quotaon` endet mit `Quota format not supported in kernel` (rc=1,
ohne Rohr gemessen), und ein Dateisystem mit der ext4-eigenen Quota lässt sich
gar nicht erst einhängen (`mount` rc=32). Und nicht, wie `/var` auf
`cloudsrv24` eingehängt ist; ein eigenes Dateisystem dort änderte die Antwort.

---

## M3 · Wie kommt die Datei zum Kunden?

Der bestehende Weg ist `response()->download()` im Panel
(`DatabaseController::download()`) und **nicht** der Webserver.

| | Grösse | Dauer | Speicher |
|---|---|---|---|
| `klein.bin` | 1,0 MiB | 0,00 s | **+0** |
| `gross.bin` | 512,0 MiB | 0,16 s | **+0** |
| *Gegenprobe:* 100 MiB als Zeichenkette | — | — | **+98 MiB** |

`BinaryFileResponse` strömt; die Spitze bleibt bei 8,0 MiB, gleich wie gross die
Datei ist. **Die Gegenprobe gehört in denselben Lauf** — ein Zuwachs von 0 sagt
nur dann etwas, wenn dieselbe Messung daneben einen Zuwachs sehen kann.

**Der erste Anlauf dieser Messung war keiner.** Er benutzte `ob_start()` ohne
Stückgrösse, sammelte die Antwort im Speicher und starb bei 1 GiB an
`Allowed memory size exhausted` — ein Befund am Prüfstand, der sich wie einer am
Prüfling las.

> **Ein Prüfkörper, der seinen Gegenstand beim Messen verändert, meldet den
> Unterschied als Fehler des Gemessenen.**

Die Grenzen des Panels dazu, aus der Paketierung gelesen:
`request_terminate_timeout = 3600s`, `memory_limit = 256M`,
`fastcgi_read_timeout 3600s`, `open_basedir` schliesst `/var/lib/srvpanel` ein.

**Was M3 nicht sagt:** wie php-fpm puffert (hier ist `output_buffering` `'0'`,
auf dem Server ist es FPMs Vorgabe), und ob nginx die FastCGI-Antwort auf die
Platte puffert. Beides entscheidet der Server. Ein `X-Accel-Redirect` wäre der
Ausweg — er setzt voraus, dass `www-data` bis zur Datei kommt, und
`/var/lib/srvpanel/dumps` ist `0710 root:srvpanel`. Das ist die Lehre der
ACME-Prüfdatei (`docs/78 §5`) und gehört gemessen, bevor jemand darauf baut.

---

## M4 · Passt ein Verzeichnis der Sicherung durch die Leitung zum Agenten?

    REQUEST_MAX          1 048 576 B
    CONTENT_MAX            983 040 B
    Prüfkörper       1425 Einträge → 99 455 B JSON (69,8 B je Eintrag)
    Schluss bei rund   14 085 Einträgen
    Packer::MAX_ENTRIES   20 000
    Gegenprobe         28 500 Einträge → 1 989 081 B — REISST die Grenze

Ein Verzeichnis, das trägt, was `ZipArchive` und `PharData` nach M1b verlieren
(Pfad, Rechte, UID, GID, Grösse), passt für 1425 Einträge bequem in eine Zeile —
und ist bei rund **14 000** zu Ende, **bevor** das Archiv seine 20 000 erreicht.

> **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**
> Derselbe Satz wie bei `FilesRead::MAX_BYTES` gegen `REQUEST_MAX`
> (`docs/62` Punkt 12b), eine Stufe weiter draussen.

Daraus folgt für die Form: **das Verzeichnis gehört in das Archiv und nicht
durch den Socket.**

**Und der zweite Teil von M4 — die Zugangsdaten eines Fernziels — ist
entschieden, bevor jemand ihn plant.** `DnsCredentialStore` ist der Vorläufer,
und sein Kopf nennt den Grund: **kein eingereihter Vorgang**, weil ein
eingereihter Vorgang seine Argumente in `operations.payload` ablegt. Gemessen,
dass das keine Vorsicht ist:

- `OperationController::show()` gibt `payload` an die Seite,
- `Operations/Show.vue:276` rendert es als `JSON.stringify(...)`,
- `OperationPolicy::view()` lässt jeden Admin und den Kunden des Abonnements
  hindurch.

> **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf der
> Vorgangsseite.**

**Was M4 nicht sagt:** wie gross ein Verzeichnis mit längeren Pfaden wird — der
Prüfkörper hat kurze. Und nicht, was das Verzeichnis **im** Archiv kostet;
gemessen ist der Weg durch den Socket.

---

## M5 · Bekommt ein wiederhergestelltes Abonnement seinen Systembenutzer zurück?

**Nein — und auch sein Datenbankpräfix nicht.**

    vorher / nachher              p1005 / p1006            NEU
    db_prefix vorher / nachher    x931f…  / x5c8f…         VERSCHIEDEN
    Gegenprobe · alte Reservierung  steht da — deshalb ist der Name vergeben
    Gegenprobe · zwei frische Namen p1007 / p1008  verschieden
    Leser nach subscription         keiner — es gibt keinen Weg zurück

**Zwei Gegenproben, und beide sind nötig.** Die erste, weil ein neuer Name auch
aus einem anderen Grund entstehen könnte, wäre die alte Zeile fort. Die zweite,
weil `claim()` andernfalls womöglich immer dasselbe zurückgäbe und die Messung
eine Tautologie wäre.

**Es gibt keinen Weg zurück, und das ist keine Auslegung.** Ausgezählt hat
`SystemUser` genau fünf Zugriffsstellen in `app/`: `PostgresDriver` (nach
**`number`**), `Orphans` (alle, nach `number` sortiert), `Lifecycle` zweimal mit
`max('number')` und `Lifecycle::claim()` mit dem einen `create()`. **Keine davon
fragt nach `subscription`** — die Frage „welche Nummer hatte dieses Abonnement"
wird nirgends gestellt. Das Modell sagt es selbst: *„Kein `released_at`. Es
gibt keine Freigabe. Ein Feld dafür wäre eine Einladung, sie doch einzubauen."*

**Was am Namen hängt — und `docs/115 §6.1` nennt es halb richtig:**

| | hängt an | nach der Wiederherstellung |
|---|---|---|
| `/var/www/vhosts/<…>` | **dem Abonnementnamen**, nicht dem Benutzer | derselbe Pfad |
| Eigentum an allem darunter | dem Systembenutzer | muss umgeschrieben werden |
| `/etc/cron.d/srvpanel-<…>` | dem Systembenutzer | **neuer Dateiname** |
| MariaDB-Namen `p1000_web` | dem Systembenutzer | **neuer Name** |
| PostgreSQL-Namen `x…_shop` | dem `db_prefix` | **neuer Name** |

**Und der Agent weist die alten Namen ab** — gemessen an `Names::belongsTo()`,
mit Gegenprobe:

    MariaDB      belongsTo('p1000_web', 'p1002')  = false
                 belongsTo('p1000_web', 'p1000')  = true      (Gegenprobe)
    PostgreSQL   belongsTo(alter Name, neues Präfix) = false
                 belongsTo(alter Name, altes Präfix) = true    (Gegenprobe)

**Und die Prüfung wird erreicht — sie steht nicht bloss da.** Ausgezählt ruft
`Names::belongsTo()` an **achtzehn** Stellen im Agenten; auf dem Weg der
Wiederherstellung sind es `DbRestore` (`belongsTo($database, $prefix)`) und
`PgRestore`. Der Weg daneben ist noch enger: `DbDatabaseCreate` **baut** den
Namen aus dem gegenwärtigen Präfix (`Names::database($prefix, $suffix)`) und
kann den alten gar nicht erst erzeugen.

> **Ein Wächter, den man im Quelltext findet, sagt nichts darüber, ob der
> geprüfte Weg an ihm vorbeikommt.** Hier tut er es, auf beiden Wegen und aus
> zwei verschiedenen Gründen.

Daraus die Folge, die die Form der Wiederherstellung entscheidet:

> **Eine Wiederherstellung kann die Datenbanken des Kunden nicht unter ihren
> alten Namen zurückbringen — und die Konfigurationsdatei seines Auftritts, die
> sie beim Namen nennt, liegt als Kundendatei in derselben Sicherung.**

**Was M5 nicht sagt:** ob eine Wiederherstellung den Namen zurückgeben *soll* —
das ist eine Entscheidung. Und nicht, was auf dem Dateisystem liegenbleibt; das
misst der Abnahmelauf auf dem Server.

---

## M6 · Speichert eine Sicherung die Beschreibung oder die erzeugte Datei?

Ein Server-Block ist klein — die vier Formen, gerendert aus der echten Vorlage:

| Form | Bytes | Zeilen | Anweisungen |
|---|---|---|---|
| `php` | 3531 | 90 | 24 |
| `static` | 3098 | 80 | 19 |
| `redirect` | 2052 | 48 | 13 |
| `suspended` | 2341 | 50 | 13 |

**Der entscheidende Fall ist die wörtlich zurückgespielte Datei.** Nachgestellt,
indem einer PHP-Domain eine Anweisung fehlt — so, wie eine Datei aus einer
älteren Fassung aussähe:

    wörtlich zurückgespielt (älter)  3531 → 3501 B · Diagnose meldet: client_max_body_size
    Gegenprobe unverändert           meldet nichts (richtig)

Der Nachtlauf meldet sie also in der Nacht nach der Wiederherstellung.

> **Eine Sicherung, die erzeugte Dateien aufhebt, sichert den Ausgang einer
> Rechnung und nicht ihre Eingaben — und die Rechnung ändert sich mit der
> nächsten Fassung.** (`docs/115 §6.1`, hier gemessen statt vermutet.)

**Und die Runde hat eine dritte Art gefunden, die `docs/115` nicht führt.**
Ausgezählt an den Spalten der Tabellen:

1. **Beschreibung reicht** — `domains`, `cron_jobs`, `ssh_keys` (`public_key`
   ist die Sache selbst), die Struktur von `databases` und `db_users`. Daraus
   erzeugt `web.site.apply` den Block neu, und `PROMISED_BY_FORM` passt wieder.
2. **Nur wörtlich** — die Dateien des Kunden und der Inhalt seiner Datenbanken.
3. **Weder noch** — `certificates` führt `storage_name`, aber **kein
   Schlüsselmaterial**; `db_users` führt **kein Passwort**. Ein Zertifikat aus
   ACME lässt sich neu bestellen, ein **hochgeladenes** nicht, und ein
   Datenbankpasswort steht nirgends.

> **Was weder beschrieben noch erzeugt werden kann, muss die Sicherung selbst
> tragen — oder die Wiederherstellung muss sagen, dass es fehlt.**

**Was M6 nicht sagt:** wie oft sich eine Vorlage wirklich ändert — gemessen ist
die Wirkung, nicht die Häufigkeit. Und nicht, was für `pg_hba.conf` und die
Cron-Dateien gilt; dort schreibt derselbe verwaltete Bereich, und die Messung
stand an der Vhost-Datei.

---

## M7 · Wie meldet ein langer Lauf seinen Ausgang?

    RunAgentOperation::$timeout   1800 s
    RunAgentOperation::$tries     1
    Ops mit Fortschritt           57 von 112
    darunter DbDumpCreate         ja
    Gegenprobe · AgentPing        meldet keinen — die Zahl unterscheidet also

**Form A aus `docs/86 §5` wird dafür nicht gebraucht.** `AwaitDispatchedRun` ist
die Nachlese für einen Lauf, der das Panel **überleben** muss — nicht für einen,
der lange dauert. Den Fortschrittskanal gibt es, und die nächste Verwandte einer
Dateisicherung benutzt ihn bereits.

Was die Grenze von 1800 s mit dem Durchsatz aus M1 bedeutet:

| Durchsatz | reicht für |
|---|---|
| 19 MB/s (Quelltext) | 33,4 GiB |
| 31 MB/s (gemischt) | 54,5 GiB |
| 39 MB/s (schon komprimiert) | 68,6 GiB |

**Was M7 nicht sagt:** ob `retry_after` — 90 s in `config/queue.php`, Laravels
unbegründete Vorgabe — einem Lauf von 1800 s in die Quere kommt. **Ungemessen**,
und es steht hier als Frage und nicht als Zusage.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

---

## Was auf dem Server zu messen bleibt

Keines davon ist im Container zu beantworten, und keines darf geschätzt werden:

1. **Die Quota, wenn sie greift** (M2) — ob eine Sicherung im Kundenraum den
   Schreibvorgang des Kunden zum Scheitern bringt, und wie `/var` auf
   `cloudsrv24` eingehängt ist.
2. **Der Weg zum Kunden bei mehreren GB** (M3) — hinter echtem nginx und
   php-fpm, nicht hinter `artisan serve`: puffert nginx die Antwort auf die
   Platte, und trägt die Leitung sie.
3. **Der Durchsatz auf der Platte des Servers** (M1) — 31 MB/s sind hier
   gemessen und dort eine Vermutung.
4. **`retry_after` gegen einen Lauf von 1800 s** (M7).

---

## Korrekturen an `docs/115`

- **§6.1 Punkt 5 nennt `/var/www/vhosts/<benutzer>`.** Der Pfad hängt am
  **Abonnementnamen** (`Site::subscriptionRoot()`, `Files\Workspace::fromArgs()`, `SubscriptionProvision`);
  am Benutzer hängen das Eigentum und `/etc/cron.d/srvpanel-<benutzer>`. Die
  Folge ist nicht kosmetisch: Der Pfad überlebt eine Wiederherstellung, das
  Eigentum nicht.
- **§6.1 Punkt 6 fragt „Beschreibung oder erzeugte Datei".** Gemessen sind es
  **drei** Arten; die dritte — Schlüsselmaterial und Passwörter — steht in
  keiner der beiden.
