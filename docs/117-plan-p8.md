# P8 — Sicherungen und Wiederherstellung: der Plan

Geschrieben am **15. September 2026**, **nach** der Messrunde (`docs/116`) und
nach den Entscheidungen des Betreibers vom selben Tag. Die Übergabe davor ist
`docs/115`, die Stufenzeile steht in `docs/20 §9`.

> **Jede Stufe seit P5b hat ihre Messrunde vor dem Plan gehabt, und jede hat den
> Entwurf umgeworfen.** Diese auch — vier Ergebnisse aus `docs/116` ändern, was
> P8 überhaupt bauen kann, und eines wirft das Abnahmekriterium der Stufe um.

---

## §0 · Was beim Ausschreiben umgefallen ist

Drei Zeilen, die vor diesem Plan als richtig galten.

**1 · Das Abnahmekriterium aus `docs/20 §9` ist so nicht erfüllbar.** Es lautet:
*„ein vollständig gelöschtes Abonnement wird aus einer Sicherung
wiederhergestellt und danach funktionieren Webseiten, Datenbanken, DNS und
Cron."* Gemessen (`docs/116` M5) bekommt ein wiederhergestelltes Abonnement
einen **neuen** Systembenutzer und ein **neues** Datenbankpräfix; die alten
Datenbanknamen weist der Agent ab. Die `wp-config.php` des Kunden — eine
Kundendatei in derselben Sicherung — nennt danach eine Datenbank, die es nicht
gibt. „Danach funktionieren die Webseiten" ist damit **keine Eigenschaft der
Wiederherstellung**, sondern hängt an §3.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

Neu gefasst steht es in §8.

**2 · Der Prüflauf ist kein Rückspiellauf mehr.** `docs/20 §9` nennt *„ein
Prüflauf, der eine Sicherung regelmässig testweise zurückspielt"*. Der Betreiber
hat am 15. September **„nur prüfen, nicht zurückspielen"** entschieden, und zwar
aus einem gemessenen Grund: Jede Wiederherstellung verbraucht dauerhaft eine
Systembenutzernummer und ein Datenbankpräfix (`docs/116` M5, es gibt keine
Freigabe). Ein nächtlicher Rückspiellauf verbrennt jede Nacht eine Nummer.

**Das bewegt eine Zeile in `docs/20 §9`**, und es steht hier, damit es nicht
still geschieht:

> **Eine Aufzählung dessen, was ein Merkmal nicht wird, ist nur dann eine
> Entscheidung, wenn das Fehlende darin steht — sonst ist sie eine Lücke mit
> Überschrift.** (`docs/105`)

**3 · S3 und FTP kosten kein Programm, SFTP schon.** Gemessen: Der Agent hat mit
`Acme\Outbound` eine geprüfte Naht nach draussen, `ext-curl` ist eine
Paketabhängigkeit, `ext-ftp` ist im Grundbestand — `ext-ssh2` ist es **nicht**.
SFTP bräuchte also entweder eine neue Erweiterung (und der Agent ist
abhängigkeitsfrei) oder `sftp`/`scp` auf der Positivliste des Runners. Das ist
eine Entscheidung und keine Kleinigkeit; §5 schlägt vor, sie zu vertagen.

---

## §1 · Was P8 ist

Sicherung und Wiederherstellung **je Abonnement** — Dateien, Datenbanken und die
Beschreibung dessen, was das Panel für das Abonnement erzeugt (Domains, DNS,
Cron, SFTP-Zugänge, Zertifikate). Dazu Zeitpläne, Aufbewahrungsregeln und ein
Weg nach draussen.

**Die Wiederherstellung gehört dem Kunden**, nicht nur dem Betreiber — das ist
die Zeile aus `docs/20 §9`, die diese Stufe von einem Betreiberwerkzeug
unterscheidet.

---

## §2 · Die Entscheidungen des Betreibers

**Am 15. September 2026 entschieden**, nach der Messrunde und mit ihren Zahlen
daneben.

| | Frage | Entscheidung |
|---|---|---|
| **1** | Wo der Prüflauf zurückspielt | **Nur prüfen, nicht zurückspielen.** Der Lauf prüft Archiv und Verzeichnis auf Vollständigkeit und Lesbarkeit und legt kein Abonnement an. |
| **2** | Ob eine Sicherung im Raum des Kunden liegt | **Daneben, `root:srvpanel`** — wie die Datenbank-Sicherungen seit P5. Sie zählt nicht gegen die Quota und ist über SFTP nicht erreichbar. |
| **3** | Wer ein Fernziel einrichtet | **Nur der Betreiber.** Der Kunde wählt ein Ziel aus und sieht nur dessen Namen. |

**Entscheidung 2 ist gemessen und nicht übernommen.** `docs/116` M2 zeigt: Nicht
der Pfad entscheidet, sondern der **Eigentümer** — `SubscriptionUsage` liest
`repquota` je UID über das ganze Dateisystem, und `/var/lib/srvpanel` und
`/var/www/vhosts` liegen im Grundfall auf demselben. Was die bestehenden Dumps
von der Quota fernhält, ist ihr `root:srvpanel` und nicht ihr Ablageort.

> **Ein Ablageort ausserhalb des Kundenverzeichnisses hält eine Datei von der
> Quota nur fern, solange sie jemand anderem gehört.**

**Entscheidung 3 hat eine gemessene Kehrseite**, die der Bau nicht vergessen
darf: Ein Geheimnis darf **nicht als Argument eines Vorgangs reisen**.
`Operations/Show.vue` rendert `payload` als JSON, und `OperationPolicy::view()`
lässt jeden Admin und den Kunden des Abonnements hindurch (`docs/116` M4).
`DnsCredentialStore` nennt genau das als Grund, **keinen** Vorgang einzureihen —
und ist damit der Vorläufer für jedes Fernziel.

> **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf der
> Vorgangsseite.**

---

## §3 · Die eine Frage, die noch offen ist — und sie entscheidet die Form

**Die Messrunde hat sie erzeugt; `docs/115 §6.2` kannte sie nicht.**

Ein zurückgebautes Abonnement wird seit `docs/35` **hart** gelöscht, und
`Lifecycle::claim()` verbraucht seine Nummer dauerhaft in `system_users`. Das
Modell sagt dazu: *„Kein `released_at`. Es gibt keine Freigabe. Ein Feld dafür
wäre eine Einladung, sie doch einzubauen."* Gemessen gibt es in `app/` **fünf
Zugriffsstellen** auf `SystemUser`, und **keine fragt nach `subscription`**.

Damit stehen zwei Formen der Wiederherstellung zur Wahl, und sie unterscheiden
sich nicht im Aufwand, sondern im Ergebnis für den Kunden.

### Form A — neue Nummer, und das Panel sagt, was sich geändert hat

Die Wiederherstellung legt ein neues Abonnement an, bekommt `p1005` statt
`p1000` und ein neues Präfix, erzeugt die Datenbanken unter den **neuen** Namen
und zeigt dem Kunden die Zuordnung alt → neu.

- **Trägt die Regel aus `docs/35` unverändert.** Kein neuer Weg, keine neue
  Ausnahme.
- **Der Auftritt des Kunden ist danach kaputt**, bis er seine
  Konfigurationsdatei anfasst. Für den häufigsten Fall — „ich habe aus Versehen
  gelöscht, spiel es zurück" — ist das die teure Antwort.

### Form B — die eigene Reservierung zurückholen, unter Bedingungen

Die Sicherung trägt in ihrem Verzeichnis die ursprüngliche **Nummer** und den
ursprünglichen Abonnementnamen. Die Wiederherstellung darf die Reservierung
wiederverwenden, wenn **alle drei** Bedingungen gelten:

1. Die Zeile in `system_users` mit dieser Nummer gibt es noch **und** ihr
   `subscription` ist derselbe Name wie im Verzeichnis der Sicherung.
2. **Kein lebendes Abonnement** trägt diese Nummer.
3. Es gibt **kein Unix-Konto** dieses Namens mehr — die Frage, die
   `Diagnose\Checks\SystemUsers` seit A10 ohnehin stellt.

- **Der Pfad, die Datenbanknamen, die Cron-Datei und die Konfigurationsdatei des
  Kunden passen danach wieder.** Der Auftritt läuft.
- **Sie öffnet eine Tür, die `docs/35` zugemacht hat.** Der Grund dort war, dass
  ein *neuer* Kunde nicht erben darf, was auf der Platte noch der alten UID
  gehört. Eine Wiederherstellung **desselben** Abonnements ist nicht dieser
  Fall — genau dafür steht der Name in der Reservierungszeile. Aber ein Name ist
  kein Beweis: Zwei Kunden können nacheinander „shop" heissen. Bedingung 3 ist
  deshalb die tragende, und der Betreiber bestätigt den Griff ausdrücklich.

> **Eine Reservierung, die festhält, wem eine Nummer gehörte, beantwortet die
> Frage „darf dieses Abonnement sie zurückbekommen" — sie beantwortet nicht die
> Frage, ob es dasselbe Abonnement ist.**

### Was die anderen Panels tun — recherchiert am 16. September 2026

**Die Frage ist nicht neu, und vier Panels beantworten sie verschieden.** Was
hier steht, ist teils am **Quelltext gemessen** (HestiaCP) und teils aus
Herstellerdokumentation und Wissensdatenbanken zusammengetragen — die Sperre des
Egress-Proxys lässt `support.plesk.com`, `docs.plesk.com` und
`docs.directadmin.com` nicht durch, für sie steht hier also Wissen aus zweiter
Hand. **Wo das gilt, sagt es die Tabelle**, denn:

> **Wissen aus zweiter Hand sieht aus wie Wissen.**

| | Systembenutzer bei der Wiederherstellung | Datenbankname | Vhost-Datei |
|---|---|---|---|
| **cPanel** | behält den Namen; ihn zu **ändern** ist ein eigener, gewarnter Vorgang | trägt den Benutzernamen als Präfix (`user_db`) | aus Vorlage |
| **Plesk** | nimmt den alten Namen, **wenn er frei ist**; sonst erfindet er einen (`sub_1783193419`), **warnt** und lässt ihn nachträglich ändern | Präfix ist **einstellbar** und nicht zwingend | aus Vorlage |
| **DirectAdmin** | Wiederherstellung geht in einen **benannten** Benutzer | trägt den Benutzernamen als Präfix | aus Vorlage, über einen Tokenizer — die Doku warnt ausdrücklich davor, die erzeugte Datei zu kopieren |
| **HestiaCP** *(am Quelltext gemessen)* | **gibt den alten Namen nicht zurück** und **behält die UID nicht** | trägt den Benutzernamen als Präfix und wird **umbenannt** | wird **neu gebaut** (`rebuild_web_domain_conf`) |

**HestiaCP ist der Fall, der sich messen liess**, und sein `bin/v-restore-user`
tut genau das, was §3 als **Form A** beschreibt:

    old_uid=$(cut -f 3 -d : $tmpdir/pam/passwd)
    new_uid=$(grep "^$user:" /etc/passwd | cut -f 3 -d :)
    …
    # Re-chowning files if uid differs
    if [ "$old_uid" -ne "$new_uid" ]; then
        find $HOMEDIR/$user/web/$domain/ -user $old_uid -exec chown -h $user:$user {} \;
    fi
    …
    DB=$(echo "$DB" | sed -e "s/${old_user}_//")
    DB="${user}_${DB}"

Also: neuer Benutzer, neue UID, **jede Datei umgeschrieben**, und der
Datenbankname verliert das alte Präfix und bekommt das neue. Dasselbe für den
FTP-Benutzer und für einen DocumentRoot, der den alten Pfad nannte. Die Konfigurationen
werden **erzeugt** und nicht zurückgespielt — `rebuild_web_domain_conf`,
`rebuild_dns_domain_conf`, `rebuild_mysql_database`.

> **Vier Panels, vier Wege — und keines spielt die erzeugte Vhost-Datei
> zurück.** §4 steht damit nicht allein da; es ist der Konsens.

**Drei Dinge folgen daraus für §3.**

**Erstens: Form A ist erprobt und nicht theoretisch.** HestiaCP fährt sie in
einem ausgelieferten Panel, und der Handgriff, den sie kostet — das Umschreiben
des Eigentums nach der Wiederherstellung — steht dort in vier Zeilen.

**Zweitens: Plesk ist näher an Form B, und der Grund ist ein anderer Bau.** Dass
es den alten Namen zurückgeben *kann*, liegt nicht an einem klügeren
Wiederherstellen, sondern daran, dass sein Datenbankpräfix **einstellbar** ist
und der Name des Systembenutzers sich nachträglich **ändern** lässt. Beides gibt
es hier nicht: Der Präfix ist in SrvPanel zwingend, `Names::belongsTo()` setzt
ihn im Agenten durch, und `system_users` kennt keine Freigabe.

> **Ein Panel, das einen Namen zurückgeben kann, hat dafür nicht den besseren
> Rückweg — es hat die schwächere Bindung.**

**Drittens, und das ist der Fund, der nicht in der Frage stand: Kein Panel sagt
dem Kunden, dass seine Datenbank jetzt anders heisst.** HestiaCP warnt an dieser
Stelle über ein **fehlendes Passwort** (*„Please use the web interface to set a
password after the restore process finishes."*) und über den Namenswechsel
nicht; cPanel überlässt es der Wissensdatenbank; Plesk nennt den neuen
Systembenutzer in einer Migrationswarnung, die der Kunde nie sieht. Die
`wp-config.php` bleibt überall stehen, wie sie war.

> **Ein Zustand, den vier Panels gleich schlecht lösen, ist keine
> Selbstverständlichkeit — er ist die Stelle, an der sich etwas verbessern
> lässt.**

Deshalb ist **§8 Punkt 6 ein Ausschlusskriterium** und nicht eine
Bequemlichkeit.

**Und die beiden anderen Entscheidungen des Betreibers bestätigt die Recherche.**
Entscheidung 1 („prüfen statt zurückspielen") ist das, was JetBackup — die
verbreitetste Sicherungserweiterung für cPanel, DirectAdmin und Plesk — als
**Integrity Check** führt: Es prüft die Sicherung und markiert sie als
*Damaged*, statt sie probeweise einzuspielen. Entscheidung 2 („daneben, root")
vermeidet genau den Dauerbefund von cPanel, dessen benutzerseitige
Vollsicherung im Heimatverzeichnis landet und **gegen die Quota zählt** — die
Empfehlung jeder Wissensdatenbank dazu lautet, sie dort nicht liegen zu lassen.

**Ein Punkt, an dem cPanel mehr anbietet als §5**, und er gehört benannt: Es
trennt **zwei** Wege nach draussen. Die serverweiten Ziele des Betreibers
(WHM, *Additional Destinations*) und einen **einmaligen** Stoss des Kunden aus
dem Sicherungsassistenten, bei dem er seine FTP- oder SCP-Zugangsdaten für genau
diese eine Übertragung eintippt. Der zweite Weg ist mit Entscheidung 3
vereinbar, denn **er legt nichts ab**: Das Geheimnis lebt so lange wie die
Übertragung. Er steht trotzdem nicht in P8 (§10) — aber als Vorschlag für später
ist er besser als „der Kunde bekommt ein dauerhaftes Ziel".

> **Ein Geheimnis, das nur so lange lebt wie die Übertragung, ist kein
> verwahrtes Geheimnis — und die Frage, wer es verwahren darf, stellt sich
> dann nicht.**

### Was der Plan daraus macht

**§4 bis §7 sind für beide Formen geschrieben.** Sie unterscheiden sich an genau
zwei Stellen, und die sind unten benannt: dem Schritt, der die Nummer besorgt
(§6 Schritt 7), und dem, der dem Kunden sagt, was sich geändert hat (§6
Schritt 8).

**Vorgeschlagen ist nach der Recherche vom 16. September: A, und B nicht.**
Der erste Wurf dieses Plans schlug B mit A als Rückfall vor. Die Panels, die den
Namen zurückgeben können, tun das nicht, weil sie den besseren Rückweg haben,
sondern weil ihre Bindung schwächer ist — Plesks Datenbankpräfix ist
einstellbar, seine Systembenutzer sind umbenennbar. Hier ist beides fest, und
`Names::belongsTo()` setzt es im Agenten durch.

B brächte damit **einen zweiten Weg an `system_users`** und eine Ausnahme von
der Regel aus `docs/35`, und beides kaufte genau eine Ersparnis: dass der Kunde
seine `wp-config.php` nicht anfassen muss. A kostet ihn diese eine Änderung —
und HestiaCP führt vor, dass ein ausgeliefertes Panel damit auskommt.

> **Eine Ausnahme von einer Regel, die eine frühere Stufe ausdrücklich
> geschlossen hat, muss mehr einbringen als eine Bequemlichkeit.**

**Entschieden ist es nicht**, und B bleibt beschrieben: Es ist die einzige Frage
dieser Stufe, die eine Regel aus einer früheren berührt, und wer sie umdreht,
findet hier die drei Bedingungen, unter denen sie tragen würde.

---

## §4 · Die Form einer Sicherung

**Drei Arten von Inhalt, gemessen und nicht aufgezählt** (`docs/116` M6):

| Art | Was | Wie sie in die Sicherung kommt |
|---|---|---|
| **Beschreibung** | Domains, Cron, SFTP-Schlüssel, Struktur der Datenbanken und Zugänge, Plan und Kontingente, PHP-Einstellungen | als `verzeichnis.json` im Archiv; die Wiederherstellung **erzeugt** daraus neu |
| **Wörtlich** | Dateien des Kunden, Inhalt der Datenbanken | als Dateien im Archiv |
| **Weder noch** | Schlüsselmaterial eines **hochgeladenen** Zertifikats, Datenbankpasswörter | siehe unten |

**Die Beschreibung wird erzeugt und nicht zurückgespielt**, und das ist
gemessen: Eine wörtlich zurückgespielte Vhost-Datei aus einer älteren Fassung
meldet der Nachtlauf in der Nacht darauf als `directive_lost` (`docs/116` M6, an
der echten Vorlage und am echten Leser, mit der unveränderten Datei als
Gegenprobe).

> **Eine Sicherung, die erzeugte Dateien aufhebt, sichert den Ausgang einer
> Rechnung und nicht ihre Eingaben — und die Rechnung ändert sich mit der
> nächsten Fassung.**

Das gilt für die Vhost-Datei, für `pg_hba.conf`, für die Cron-Dateien und für
`authorized_keys` — überall dort schreibt ein verwalteter Bereich.

**Und die dritte Art braucht eine Entscheidung je Fall:**

- **Ein ACME-Zertifikat** wird nicht gesichert. Es wird nach der
  Wiederherstellung neu bestellt; das ist der Weg, den P4 ohnehin geht.
- **Ein hochgeladenes Zertifikat** hat seinen privaten Schlüssel nirgends sonst
  (`certificates` führt `storage_name` und **kein** Schlüsselmaterial). Er
  gehört in die Sicherung — und damit trägt eine Sicherung erstmals ein
  Geheimnis. Sie liegt deshalb `root:srvpanel 0640` und geht nur über das Panel
  hinaus (§2 Entscheidung 2).
- **Datenbankpasswörter** stehen nirgends und werden nicht gesichert. Nach der
  Wiederherstellung bekommt jeder Zugang ein neues, und die Seite sagt es.

> **Was weder beschrieben noch erzeugt werden kann, muss die Sicherung selbst
> tragen — oder die Wiederherstellung muss sagen, dass es fehlt.**

### Warum das Verzeichnis **in** das Archiv gehört und nicht durch den Socket

Gemessen (`docs/116` M4): Ein Verzeichnis, das je Datei Rechte und Verweisziel
trägt, ist bei rund **14 000 Einträgen** am Ende von `Connection::CONTENT_MAX`,
während `Packer::MAX_ENTRIES` **20 000** zulässt.

> **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**

Es reist deshalb gar nicht über die Leitung. Der Agent schreibt es in das
Archiv, das Panel liest aus dem Archiv, was es anzeigen muss.

### Warum das Verzeichnis Rechte trägt, aber keinen Eigentümer

Gemessen (`docs/116` M1b): Weder `ZipArchive` noch `PharData` legen Eigentümer,
Verweis oder das setgid-Bit ab; nur `tar(1)` tut es, und `tar` steht nicht auf
der Positivliste des Runners.

**Den Eigentümer braucht die Sicherung trotzdem nicht.** Er ändert sich bei der
Wiederherstellung ohnehin (Form A immer, Form B nie) und wird aus dem
Systembenutzer **neu gesetzt** statt aus dem Archiv gelesen. Was gebraucht wird,
sind **Rechte** und **Verweisziele** — und die trägt das Verzeichnis.

> **Ein Wert, der sich beim Zurückspielen sowieso ändert, gehört nicht in die
> Sicherung — er gehört neu gerechnet.**

Damit wächst die Positivliste des Runners in P8 um **kein** Programm. Dieselbe
Zusage wie in P5.

---

## §5 · Der Weg nach draussen

**Nur der Betreiber richtet Ziele ein** (§2 Entscheidung 3). Vorgeschlagen für
P8:

- **Lokal** — die Vorgabe, `/var/lib/srvpanel/sicherungen`.
- **S3-kompatibel** — reitet auf `Acme\Outbound` mit, also auf einer Naht, die
  seit P4 gegen ein Drehbuch prüfbar ist. Signiert wird mit `hash_hmac`;
  `ext-curl` ist Paketabhängigkeit, `ext-openssl` und `ext-hash` sind im
  Grundbestand. **Kein neues Programm.**
- **SFTP und FTP: vertagt.** FTP ginge mit `ext-ftp` ohne neues Programm, ist
  aber unverschlüsselt und damit für eine Sicherung mit Schlüsselmaterial die
  falsche Antwort. SFTP kostet `ext-ssh2` oder ein Programm auf der
  Positivliste. Beides ist eine eigene Entscheidung und gehört nicht in dieselbe
  Stufe wie das Sichern selbst.

Die Zugangsdaten gehen über eine eigene Operation nach dem Vorbild von
`dns.credential.store`: **einmal hinein, nie zurück, und ohne Warteschlange.**
Zurück kommt der Name des Ziels und sein Anbieter.

---

## §6 · Der Bau

Zehn Schritte. Die Reihenfolge ist so gewählt, dass jeder für sich auf einem
Server messbar ist.

1. **Der Ablageort.** `Backup\Store` im Agenten — `/var/lib/srvpanel/sicherungen`,
   `root:srvpanel 0640`, Verzeichnis `0710`, nach dem Vorbild von `Db\Dump`.
   Dessen Lehre gilt hier wörtlich: Ohne `x` auf **jedem** Verzeichnis darüber
   nützt das `r` an der Datei nichts.
2. **Das Verzeichnis.** `Backup\Manifest` — Fassung, Zeitpunkt, Abonnementname,
   ursprüngliche Systembenutzernummer, je Datei Pfad, Rechte und Verweisziel,
   dazu die Beschreibung aus §4. Framework- und abhängigkeitsfrei, damit beide
   Seiten denselben Leser haben.
3. **`backup.create`** — packt Dateien und Verzeichnis, meldet Fortschritt über
   `Context::progress()`. Der Kanal ist da und wird von 57 der 112 Operationen
   benutzt, darunter `DbDumpCreate` (`docs/116` M7).
4. **Die Datenbanken mit hinein.** Kein neuer Weg: `db.dump.create` und
   `pg.dump.create` gibt es seit P5/P5b; `backup.create` legt ihre Ausgabe in
   das Archiv.
5. **Die Seite.** Sicherungen je Abonnement anlegen, ansehen, herunterladen,
   entfernen — über `response()->download()`, das gemessen strömt (`docs/116`
   M3, 8 MiB Spitze bei 512 MiB Datei).
6. **`backup.verify`** — Entscheidung 1 des Betreibers: prüft Archiv und
   Verzeichnis auf Lesbarkeit und Vollständigkeit und legt **nichts** an.
   Nächtlich, neben der Bestandsdiagnose.
7. **`backup.restore`, Teil 1: die Nummer.** Hier und nur hier unterscheiden
   sich Form A und Form B aus §3.
8. **`backup.restore`, Teil 2: der Bestand.** Abonnement anlegen, Dateien
   entpacken, Eigentümer neu setzen, Rechte aus dem Verzeichnis anwenden,
   Datenbanken anlegen und Dumps einspielen, Domains und Cron aus der
   Beschreibung **erzeugen**. Danach sagt die Seite, was sich geändert hat —
   bei Form A die Zuordnung alt → neu, bei Form B, dass nichts umzustellen ist.
9. **Aufbewahrung und Zeitplan.** Wie viele Sicherungen je Abonnement bleiben,
   und wann eine entsteht. Der Zeitgeber ist eine Unit wie die fünf anderen.
10. **Die Sicherung vor einer riskanten Handlung** — `docs/20 §9` nennt sie.
    Sie ist ein Aufruf von Schritt 3 und kein eigener Weg.

---

## §7 · Die Wächter

Jeder mit seinem Bruch in `tests/waechter-brechen.sh`, und jeder Bruch **einzeln
gefahren** und nicht bloss geschrieben.

- **`BackupStoreTest`** — der Ablageort ist `root:srvpanel`, das Verzeichnis
  durchsuchbar und nicht auflistbar, und er liegt **nicht** unter
  `/var/www/vhosts`. Gemessen an der Wirkung, nicht an einer Zeichenkette.
- **`BackupPromiseTest`** — was das Verzeichnis über eine Datei sagt, kommt beim
  Entpacken wieder heraus: Rechte und Verweisziel, in **beide** Richtungen. Der
  Prüfkörper ist der aus `docs/116` M1b, samt leerem Verzeichnis — genau der
  Fall, den `PharData` fallen lässt.
- **`BackupSecretTest`** — kein Geheimnis reist als Argument eines Vorgangs.
  Gehalten an der Wirkung: Die Zugangsdaten eines Ziels kommen aus keiner
  Operation zurück, und keine Route, die sie entgegennimmt, reiht einen Vorgang
  ein.
- **`BackupFormTest`** — die Wiederherstellung **erzeugt** die Vhost-Datei und
  spielt sie nicht zurück. Gehalten daran, dass nach einer Wiederherstellung
  `SiteTemplate::promised()` vollständig gedeckt ist — also an demselben Leser,
  der den Befund aus `docs/116` M6 gefunden hat.
- **`BackupEntryLimitTest`** — die Obergrenze der Einträge einer Sicherung und
  die des Verzeichnisses stehen in einem Verhältnis, das die Leitung trägt.
  **Er ist die Antwort auf M4**: Er rechnet die Grösse des Verzeichnisses gegen
  `Connection::CONTENT_MAX` nach, statt eine Zahl zu glauben.
- **`BackupReachTest`** — jede Art aus §4 hat einen Weg. Ein hochgeladenes
  Zertifikat, dessen Schlüssel niemand sichert, wäre eine stille Lücke; sie
  meldet sich erst Jahre später.

**Und eine Frage, die kein Test halten kann**, und die deshalb hier steht:

> **Wo sucht ein Kunde „meine Sicherung zurückspielen", und steht sie dort?**
> Dreimal ist diese Frage in diesem Projekt versäumt worden, und jedes Mal hat
> es der Betreiber gemeldet.

---

## §8 · Das Abnahmekriterium — neu gefasst

Das alte steht in `docs/20 §9` und ist nach §0 nicht erfüllbar. Neu:

1. Ein Abonnement mit Dateien, zwei Datenbanken, Domains und Cronjobs bekommt
   eine Sicherung; sie liegt `root:srvpanel` und zählt **nicht** gegen die Quota
   des Kunden (`repquota` vorher und nachher).
2. Das Abonnement wird **vollständig gelöscht** — Zeile, Verzeichnis,
   Unix-Konto, Datenbanken.
3. Die Wiederherstellung läuft **durch einen Vorgang** und nicht von Hand, und
   sie meldet ihren Fortschritt.
4. Danach steht das Abonnement wieder: Verzeichnis mit den ursprünglichen
   **Rechten** und Verweisen, Datenbanken mit ihrem Inhalt, Domains und Cronjobs
   aus der Beschreibung **erzeugt**.
5. **Die Vhost-Datei besteht die Bestandsdiagnose** — `SiteTemplate::promised()`
   ist vollständig gedeckt, und der Nachtlauf meldet in der Nacht darauf **kein**
   `directive_lost`. *(Dieser Punkt darf nicht ausfallen; er ist der, an dem sich
   „erzeugt" von „zurückgespielt" unterscheidet.)*
6. Die Seite sagt, **was sich geändert hat** — bei Form A die Zuordnung der
   Datenbanknamen alt → neu und die neuen Passwörter, bei Form B, dass nichts
   umzustellen ist. *(Darf nicht ausfallen: Eine Wiederherstellung, die
   schweigt, lässt den Kunden vor einem Auftritt stehen, der nicht läuft, ohne
   ihm zu sagen warum.)*
7. `backup.verify` meldet eine absichtlich beschädigte Sicherung als beschädigt
   — **und eine heile als heil.** Die Gegenprobe gehört in denselben Lauf.
8. Eine Sicherung auf ein S3-Ziel kommt dort an, und die Zugangsdaten stehen auf
   **keiner** Seite des Panels und in **keinem** Vorgang.

**Zwei Punkte dürfen nicht ausfallen: 5 und 6.** Punkt 5, weil ohne ihn nicht
belegt ist, dass die Beschreibung erzeugt und nicht zurückgespielt wird — der
Unterschied, um den es in §4 geht. Punkt 6, weil ohne ihn die Wiederherstellung
formal gelingt und der Kunde trotzdem vor einem toten Auftritt steht.

---

## §9 · Was auf dem Server zu messen bleibt

Nichts davon ist im Container zu beantworten, und nichts darf geschätzt werden.
Die ersten vier stehen schon in `docs/116`.

1. **Die greifende Quota** — ob eine Sicherung im Kundenraum den Schreibvorgang
   des Kunden zum Scheitern bringt, und wie `/var` auf `cloudsrv24` eingehängt
   ist. Dieser Container kann Quota nicht erzwingen.
2. **Der Weg zum Kunden bei mehreren GB** — hinter echtem nginx und php-fpm:
   puffert nginx die FastCGI-Antwort auf die Platte? Ein `X-Accel-Redirect` wäre
   der Ausweg und setzt voraus, dass `www-data` bis zur Datei kommt — die Lehre
   der ACME-Prüfdatei (`docs/78 §5`).
3. **Der Durchsatz auf der Platte des Servers** — 31 MB/s sind im Container
   gemessen und dort eine Vermutung.
4. **`retry_after` gegen einen Lauf von 1800 s** — 90 s stehen in
   `config/queue.php` als Laravels unbegründete Vorgabe.
5. **Ob `php8.4-zip` auf `cloudsrv24` liegt.** Gemessen im Repo: Drei Dateien
   des Agenten benutzen `ZipArchive` (`Files\Archive`, `Files\Packer`,
   `Ops\FilesCompress`), und **weder `packaging/nfpm.yaml` noch `composer.json`
   nennen `ext-zip`**. Ob es trotzdem da ist, weiss nur der Server — der Griff
   ist `php -m | grep -i zip`. Das ist ein Befund **ausserhalb von P8**; er
   steht hier, weil P8 sich stärker auf `ZipArchive` stützt als P6.

   > **Eine Erweiterung, die der Code benutzt und die Paketierung nicht nennt,
   > ist auf jedem Server vorhanden, auf dem sie zufällig jemand anderes
   > mitgebracht hat.**

---

## §10 · Was P8 ausdrücklich **nicht** wird

Damit die Aufzählung eine Entscheidung ist und keine Lücke mit Überschrift
(`docs/105`):

- **Kein Rückspiel-Prüflauf.** Entscheidung 1 des Betreibers; der nächtliche
  Lauf prüft und spielt nicht zurück.
- **Kein SFTP und kein FTP als Ziel** (§5). S3 und lokal ja.
- **Keine Sicherung des ganzen Servers.** P8 sichert je Abonnement. Was dem
  Betreiber gehört — Panel, Zertifikate, Agentenkonfiguration — ist eine eigene
  Frage.
- **Keine Sicherung, die ein Kunde selbst hochlädt.** Das kann seit P5 genau
  eine Sache: ein Datenbank-Dump. Eine hochgeladene **Abonnement**-Sicherung
  wäre ein Archiv aus fremder Hand, das der Agent auspackt — die Angriffsfläche
  von `files.extract`, nur mit Rechten und Datenbanknamen darin.
- **Keine Wiederherstellung einzelner Dateien aus der Oberfläche.** Der Kunde
  lädt die Sicherung herunter und nimmt heraus, was er braucht. Eine
  Teilwiederherstellung ist eine eigene Stufe.
- **Kein Umschreiben von Kundendateien.** Wenn nach Form A die Datenbanknamen
  wechseln, sagt das Panel es (§8 Punkt 6) — es fasst die `wp-config.php` des
  Kunden nicht an.

  > **Ein Panel, das die Dateien seiner Kunden bearbeitet, hat keinen Weg
  > zurück, wenn es sich irrt.**
