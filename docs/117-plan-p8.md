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

## §3 · Die Frage, die die Form entscheidet — **entschieden am 16. September 2026**

> **Der Betreiber hat Form A entschieden.** Damit bleibt `docs/35` unberührt:
> Eine Wiederherstellung holt keine Reservierung zurück, sondern nimmt die
> nächste freie Nummer. §6 Schritt 7 baut A, Schritt 8 sagt dem Kunden, was
> sich geändert hat, und §8 Punkt 6 ist genau deshalb ein Ausschlusskriterium.
>
> **Form B bleibt unten stehen und ist nicht gebaut.** Sie steht da, weil eine
> Entscheidung ohne ihre verworfene Alternative in einem Jahr wie eine
> Selbstverständlichkeit aussieht — und weil der Nächste, der sie umdrehen
> will, hier die drei Bedingungen findet, unter denen sie tragen würde.

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

**Was sich dabei ändert, ist weniger als „alles" — und mehr als eine Zeile.**
Ausgezählt am Quelltext, nicht geschätzt:

| Grösse | bei Form A | merkt der Kunde? |
|---|---|---|
| Abonnementname | **bleibt** — `subscriptions` wird seit `docs/35` hart gelöscht, `Rule::unique` gibt den Namen wieder frei | nein |
| `/var/www/vhosts/<name>` | **derselbe Pfad** — er hängt am Abonnementnamen und nicht am Benutzer | nein |
| UID und Eigentum | neu, aus dem neuen Systembenutzer **gesetzt** und nicht aus dem Archiv gelesen | nein |
| Systembenutzer `p1000` → `p1005` | **neu** | **ja — es ist sein SFTP-Benutzername** (`SftpController` gibt `subscription->system_user` aus) |
| `db_prefix` → Datenbanknamen | **neu** — `Lifecycle::claim()` vergibt ihn in derselben Zeile wie die Nummer | **ja — in `wp-config.php`** |
| Datenbankpasswörter | neu; sie stehen nirgends und sind nicht zu sichern (§4) | **ja** |
| Domains, DNS, Cron, Vhost-Datei | aus der Beschreibung **erzeugt**, inhaltlich gleich | nein |
| SSH-Schlüssel der SFTP-Zugänge | in der Beschreibung, `authorized_keys` wird erzeugt | nein |

**Der Pfad bleibt nur, solange der Name frei ist.** Hat der Betreiber in der
Zwischenzeit ein neues Abonnement `shop` angelegt, weist `Rule::unique` ab, und
die Wiederherstellung braucht einen anderen Namen — dann wandert auch das
Verzeichnis. Das ist kein Fehler, sondern der Fall, für den es Bedingung 3 von
Form B gibt; er gehört in die Meldung von §6 Schritt 8.

**Drei Dinge also, die der Kunde anfasst, und der Plan nannte bis zum
16. September nur eines.** Der SFTP-Benutzername stand nicht da — gemessen an
`SftpController.php:109`, wo er als `system_user` hinausgeht.

> **Eine Aufzählung, die einen von drei Preisen nennt, liest sich wie der ganze
> Preis.**

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

**Die Frage ist nicht neu, und sechs Panels beantworten sie verschieden.** Was
hier steht, ist teils am **Quelltext gemessen** (HestiaCP, Virtualmin,
CyberPanel) und teils aus Herstellerdokumentation und Wissensdatenbanken
zusammengetragen (cPanel, Plesk, DirectAdmin) — deren Doku lässt der
Egress-Proxy nicht durch, elf Hosts mit `403` am CONNECT, zweimal gemessen am
16. September. **Wo Wissen aus zweiter Hand steht, sagt es die Tabelle**, denn:

> **Wissen aus zweiter Hand sieht aus wie Wissen.**

**Die drei gemessenen sind die quelloffenen**, und das ist kein Zufall, sondern
die Auswahl: Wo die Doku gesperrt ist und der Quelltext offenliegt, ist der
Quelltext der bessere Weg und nicht der Ersatzweg.

> **Ein Panel, dessen Quelltext man lesen kann, muss man nicht nachlesen.**

| | Systembenutzer bei der Wiederherstellung | Datenbankname | Vhost-Datei |
|---|---|---|---|
| **cPanel** | behält den Namen; ihn zu **ändern** ist ein eigener, gewarnter Vorgang | trägt den Benutzernamen als Präfix (`user_db`) | aus Vorlage |
| **Plesk** | nimmt den alten Namen, **wenn er frei ist**; sonst erfindet er einen (`sub_1783193419`), **warnt** und lässt ihn nachträglich ändern | Präfix ist **einstellbar** und nicht zwingend | aus Vorlage |
| **DirectAdmin** | Wiederherstellung geht in einen **benannten** Benutzer | trägt den Benutzernamen als Präfix | aus Vorlage, über einen Tokenizer — die Doku warnt ausdrücklich davor, die erzeugte Datei zu kopieren |
| **HestiaCP** *(am Quelltext gemessen)* | **gibt den alten Namen nicht zurück** und **behält die UID nicht** | trägt den Benutzernamen als Präfix und wird **umbenannt** | wird **neu gebaut** (`rebuild_web_domain_conf`) |
| **Virtualmin** *(am Quelltext gemessen)* | **behält den Namen**, vergibt die **Nummer neu** (`$reuid = 1`, `$reuser = 0`); der Name wechselt nur bei echter Kollision und nur, wenn man es verlangt | **bleibt, wie er war** — das Präfix wird beim Zurückspielen nie angefasst | aus Vorlage |
| **CyberPanel** *(am Quelltext gemessen)* | behält den Namen | **bleibt, wie er war** (`prepareDatabaseForRestore(dbName, …)` aus der Sicherungsbeschreibung) | aus Vorlage |

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

**Virtualmin ist der Gegenfall, und er liess sich ebenfalls messen.** Sein
`backups-lib.pl` trennt die beiden Grössen, die HestiaCP zusammen wegwirft:

    elsif ($opts->{'reuid'}) {
            # Re-allocate the UID and GID
            $d->{'gid'} = &allocate_gid(\%gtaken);
            $d->{'uid'} = &allocate_uid(\%taken);
            }
    …
    if (!$parentdom && $opts->{'reuser'} && $usertaken{$d->{'user'}}) {
            # Re-allocated user name if there is a clash
            $d->{'restoreolduser'} = $d->{'user'};
            $d->{'user'} = $newuser;
            }

Die Vorgaben stehen in `restore-domain.pl` Zeile 141/142 und im Formular als
`ui_yesno_radio("reuid", 1)` / `("reuser", 0)`: **die Nummer wird neu vergeben,
der Name bleibt.** Ein Namenswechsel kostet ausdrücklich zwei Bedingungen — man
muss ihn verlangen *und* es muss eine echte Kollision geben. Danach wird das
Eigentum umgeschrieben (`set_home_ownership($d)` in `feature-dir.pl`), genau wie
HestiaCPs vier `chown`-Zeilen.

**Und das Präfix fasst der Rückweg nie an.** Ausgezählt: `prefix` kommt in den
7920 Zeilen von `backups-lib.pl` **sechsmal** vor, und keiner der sechs Treffer
ist das Präfix einer Domain — es sind Pfad- und Protokollpräfixe.
*(Gegenprobe: `'user'}` steht in derselben Datei 27-mal. Eine Null wäre sonst
keine Messung.)*

> **Ein Panel, das keinen Bestand an vergebenen Namen führt, kann einen Namen
> zurückgeben, ohne ihn zurückzuholen — er war nie fort.**

**Der teuerste Fund an Virtualmin steht in einer Zahl.** `restoreolduser` — das
Feld, das den alten Benutzernamen über die Wiederherstellung rettet — wird an
**genau einer** Stelle gelesen: in `feature-mail.pl`, um ein Postfach
umzubenennen. Kein Leser schreibt damit eine Kundendatei um.

> **Ein Panel, das den alten Namen aufhebt, hebt ihn für seine eigenen Objekte
> auf und nicht für die des Kunden.**

> **Sechs Panels, fünf Wege — und keines spielt die erzeugte Vhost-Datei
> zurück.** §4 steht damit nicht allein da; es ist der Konsens. *(Froxlor sagt
> dazu nichts: siehe den Prüfmittelbefund unten.)*

**Drei Dinge folgen daraus für §3.**

**Erstens: Form A ist erprobt und nicht theoretisch.** HestiaCP fährt sie in
einem ausgelieferten Panel, und der Handgriff, den sie kostet — das Umschreiben
des Eigentums nach der Wiederherstellung — steht dort in vier Zeilen.

**Zweitens: Vier Panels sind näher an Form B, und bei allen vieren ist der Grund
ein anderer Bau.** Dass Plesk, cPanel, Virtualmin und CyberPanel den alten Namen
zurückgeben *können*, liegt nicht an einem klügeren Wiederherstellen, sondern an
einer schwächeren Bindung: Plesks Datenbankpräfix ist **einstellbar** und sein
Systembenutzer nachträglich **umbenennbar**, Virtualmins Präfix wohnt **im
Datensatz der Domain** und in keiner Vergabeliste, CyberPanel liest den
Datenbanknamen aus der Sicherungsbeschreibung. Hier ist beides fest: Der Präfix
ist zwingend, `Names::belongsTo()` setzt ihn im Agenten durch, und
`system_users` kennt keine Freigabe.

**Und die gemessenen Fassungen sagen, woran es wirklich hängt.** Virtualmins
`generate_random_available_user` fragt `getpwnam`, `getgrnam` und die
**vorhandenen** Domains — also: *ist der Name jetzt frei?* `system_users` fragt
seit `docs/35`: *war er jemals vergeben?*

> **Wer nur fragt, ob ein Name jetzt frei ist, kann ihn zurückgeben. Wer
> festhält, dass er einmal vergeben war, kann es nicht — und beide Antworten
> sind richtig, weil es zwei verschiedene Fragen sind.**

> **Ein Panel, das einen Namen zurückgeben kann, hat dafür nicht den besseren
> Rückweg — es hat die schwächere Bindung.**

**Drittens, und das ist der Fund, der nicht in der Frage stand: Von sechs Panels
benennt genau eines die Datenbank um — und genau dieses sagt es dem Kunden
nicht.** HestiaCP schreibt `DB="${user}_${DB}"` und warnt an derselben Stelle
über ein **fehlendes Passwort** (*„Please use the web interface to set a
password after the restore process finishes."*) und über den Namenswechsel
nicht. Die übrigen fünf haben nichts zu sagen, weil sich nichts geändert hat.

**Das dreht den ersten Wurf dieses Absatzes um.** Er lautete „kein Panel sagt es
dem Kunden" und las sich wie ein gemeinsames Versäumnis von vieren. Gemessen ist
es das Versäumnis von **einem** — und die fünf daneben sind kein Vorbild,
sondern ein anderer Fall.

> **Ein Versäumnis, das man fünf Unbeteiligten mit zuschreibt, sieht aus wie ein
> unvermeidlicher Zustand.**

**Und damit gibt es für SrvPanels Lage kein Vorbild.** Form A stellt es in
HestiaCPs Position — der Name *muss* wechseln —, und das ist die einzige
Position, in der ein Panel diese Auskunft schuldet. Genau dort gibt es keine,
die man abschreiben könnte.

> **Ein Zustand, den nur ein einziges Panel hat und dort schlecht löst, ist
> keine Selbstverständlichkeit — er ist die Stelle, an der sich etwas
> verbessern lässt.**

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

**Ein Befund am Prüfmittel gehört dazu, und er hätte ein siebtes Panel
erfunden.** `froxlor/Froxlor` war geklont und durchsucht: kein Treffer auf
`backup`, keine Datei mit `ackup` im Namen — daraus wäre „Froxlor liefert gar
keine Sicherung aus" geworden. Der Baum enthält aber nur `artisan`, `config`,
`public` und `resources`; die Logik wohnt seit dem Umbau in `froxlor/framework`,
einem eigenen Paket, das `composer.json` in Zeile 41 nennt. Gemessen war ein
leeres Gehäuse.

> **Ein leerer Griff in die falsche Datei sieht aus wie ein Befund.** Zum
> zweiten Mal nach `docs/78`, diesmal an einem ganzen Repository.

**Froxlor sagt hier deshalb nichts** — weder dass es eine Sicherung hat noch
dass es keine hat. Es steht als Nichtmessung da und nicht als Zeile in der
Tabelle.

### Warum der Vergleich hier endet und die drei gesperrten Dokus nicht fehlen

**Die Frage von §3 ist keine, die eine Herstellerdoku beantwortet.** Sie lautet
nicht „gibt das Panel den Namen zurück" — das steht in jeder Doku — sondern
„führt es Buch darüber, welcher Name einmal vergeben war". Das ist interne
Buchführung und keine Zusage an den Benutzer; sie steht in keinem Handbuch,
weil niemand sie dort suchen würde.

> **Eine Doku beschreibt, was ein Panel anbietet — nicht, welches Buch es
> führt.** Die drei gemessenen haben die Frage nur deshalb beantwortet, weil
> ihr Quelltext lesbar ist.

**Und die drei gemessenen decken beide Richtungen ab.** HestiaCP wirft Name,
Nummer und Präfix weg und schreibt jede Datei um — das ist Form A, in einem
ausgelieferten Panel. Virtualmin und CyberPanel behalten Name und Präfix, und
gemessen ist auch der Grund: Es gibt bei ihnen nichts zurückzuholen, weil nie
etwas verbraucht wurde. Ein vierter Datenpunkt für eine der beiden Richtungen
verschiebt nichts.

**Was die gesperrten drei noch trügen, ist bereits entschieden oder steht in
§10.** JetBackups *Integrity Check* stützt Entscheidung 1, cPanels Trennung von
serverweitem Ziel und einmaligem Kundenstoss ist ein Vorschlag für später, und
keiner der drei benennt beim Zurückspielen eine Datenbank um — es gibt dort
also auch nichts zu der Auskunft zu sagen, um die es in §8 Punkt 6 geht.

**Die drei Zeilen bleiben deshalb als Wissen aus zweiter Hand stehen, und das
ist eine benannte Grenze und kein Mangel.** Wer sie später misst, misst eine
Bestätigung; wer §3 anders entscheiden will, braucht keine vierte Zeile,
sondern eine Entscheidung — **denn den Weg, den Form B ginge, ist keines der
sechs Panels gegangen.**

### Was der Plan daraus macht

**§4 bis §7 sind für beide Formen geschrieben.** Sie unterscheiden sich an genau
zwei Stellen, und die sind unten benannt: dem Schritt, der die Nummer besorgt
(§6 Schritt 7), und dem, der dem Kunden sagt, was sich geändert hat (§6
Schritt 8).

**Vorgeschlagen ist nach der Recherche vom 16. September: A, und B nicht.**
Der erste Wurf dieses Plans schlug B mit A als Rückfall vor. Die vier Panels, die
den Namen zurückgeben können, tun das nicht, weil sie den besseren Rückweg
haben, sondern weil ihre Bindung schwächer ist — Plesks Datenbankpräfix ist
einstellbar und sein Systembenutzer umbenennbar, Virtualmins Präfix wohnt im
Datensatz der Domain und in keiner Vergabeliste, und keines der vier führt
Buch darüber, welcher Name einmal vergeben *war*. Hier ist beides fest,
`Names::belongsTo()` setzt es im Agenten durch, und `system_users` führt genau
dieses Buch.

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
| **Beschreibung** | Domains, Cron, SFTP-Schlüssel, Struktur der Datenbanken und Zugänge, Plan und Kontingente, PHP-Einstellungen | als `.srvpanel-manifest.json` im Archiv; die Wiederherstellung **erzeugt** daraus neu |
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
trägt, ist bei rund **14 000 Einträgen** am Ende von `Connection::CONTENT_MAX`.

> **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**

**Die Zahl daneben stand hier bis zum Bau falsch.** Der Plan nannte
`Packer::MAX_ENTRIES` mit 20 000; gebaut sind **100 000**, und zwar gemessen:
Nicht das Format bindet (`ZipArchive` hat 70 000 Einträge geschrieben und
gelesen — libzip schreibt zip64), sondern der Speicher des Agenten
(`MemoryMax=512M`; 100 000 Einträge sind 122 MiB Spitze, also 24 %). Der Abstand
zu den 14 000 ist damit grösser als gedacht und der Schluss derselbe.

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

1. **Der Ablageort.** `Backup\Store` im Agenten — `/var/lib/srvpanel/backups`,
   `root:srvpanel 0640`, Verzeichnis `0710`, nach dem Vorbild von `Db\Dump`.
   Dessen Lehre gilt hier wörtlich: Ohne `x` auf **jedem** Verzeichnis darüber
   nützt das `r` an der Datei nichts.

   **Hier stand `sicherungen`, und das wäre der erste deutsche Pfad dieses
   Servers gewesen.** Ausgezählt am 16. September: **23 von 23** Pfaden unter
   `/var/lib/srvpanel` und `/etc/srvpanel` sind englisch — `dumps`, `metrics`,
   `acme-challenge`, `php-source`. Ein Pfad ist ein Bezeichner, und `docs/19
   §4a` sagt dazu englisch; deutsch ist, was auf dem Bildschirm steht.

   > **Eine Regel, die für Klassennamen offensichtlich gilt, gilt für einen
   > Pfad genauso — nur prüft sie dort kein Wächter.**
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

6. **Wie lange ein Prüflauf über echte Kundenarchive wirklich braucht.**
   `srvpanel-backup-verify.service` trägt `TimeoutStartSec=7200`, gerechnet
   gegen 1,2 GB/s — die Zahl dieses Containers (`§13` M9). Die Platte von
   `cloudsrv24` ist nicht diese; der Griff ist `time srvpanel backup-verify`
   neben `du -sh /var/lib/srvpanel/backups`.

7. **Eine Sicherungsdatei, zu der es keine Zeile gibt.** Aufgefallen beim Bau
   von Schritt 6: `Checks\Backups` geht von den **Zeilen** aus und findet
   deshalb nur, was das Panel kennt. Die Gegenrichtung — eine Datei unter
   `/var/lib/srvpanel/backups`, die in keiner Zeile steht — prüft niemand;
   {@see \App\Support\Diagnose\Checks\Orphans} kennt Zertifikate,
   Systembenutzer und Cron-Dateien und keine Sicherungen.

   Das ist **kein** Befund von Schritt 6, sondern eine benannte Lücke: Sie
   entsteht, wenn ein `backup.remove` scheitert, nachdem die Zeile fort ist —
   und sie kostet Platz, den niemand zuordnet. Sie gehört zu Schritt 9
   (Aufbewahrung) und nicht hierher.

   > **Ein Wächter, der vom Bestand des Panels ausgeht, sieht nur, was das Panel
   > kennt — und ein Rest ist gerade das, was es nicht kennt.**

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

---

## §11 · Was beim Bauen anders war als im Plan

Geführt ab dem 16. September 2026, Schritt für Schritt — nach dem Vorbild von
`docs/901 §8a` und `docs/904 §10a`. Was hier steht, ist gemessen und nicht
erinnert.

### a · Schritt 1 und 2 — die Ablage und das Verzeichnis

**Der Pfad war deutsch und ist es nicht mehr.** `docs/117 §6` Schritt 1 schrieb
`/var/lib/srvpanel/sicherungen`. Ausgezählt sind **23 von 23** Pfaden unter
`/var/lib/srvpanel` und `/etc/srvpanel` englisch; `docs/19 §4a` sagt für einen
Bezeichner englisch, und ein Pfad ist einer. Gebaut ist `backups`.

**Und der Name des Verzeichnisses im Archiv ebenso.** §4 schrieb
`verzeichnis.json`, gebaut ist `.srvpanel-manifest.json` — mit dem Punkt, damit
er beim Auspacken nicht zwischen den Dateien des Kunden steht.

### b · Schritt 3 — der Packer, und eine Messung, die zu schmal war

**`docs/116` M1b sagte, `ZipArchive` gebe Verzeichnisse als `0777` zurück.**
Nachgemessen trägt es **gar keinen** Modus: Jeder Eintrag bekommt `0777`
beziehungsweise `0666` gegen die **umask**. Die `0777` waren die umask des
Prüfstands (0), nicht ein Wert von `ZipArchive`. `srvpanel-agentd.service` setzt
kein `UMask=`, dort gilt `0022` — ein privater Schlüssel mit `0600` käme ohne
das Verzeichnis als **`0644`** zurück. Der Nachtrag steht in `docs/116` M1b.

> **Ein gemessener Wert, dessen Bedingung niemand mitgeschrieben hat, ist auf
> der nächsten Maschine eine Vermutung.**

**`SplFileInfo::getPerms()` ist an einem Verweis zweimal falsch** — gemessen: An
einem *heilen* gibt es den Modus des **Ziels** zurück (`100600`), an einem
**toten** wirft es. Ein Kunde mit einem kaputten Symlink hätte jede Sicherung
zum Absturz gebracht. Ein Verweis trägt jetzt immer `0777`, ein toter wird
gemeldet statt fatal.

**Und eine Begründung im Unpacker war falsch, während der Handgriff stimmte.**
Die Verzeichnisrechte werden von innen nach aussen gesetzt; dastand, ein `0500`
am Elternteil nähme dem eigenen Lauf das *Schreib*recht. Gemessen geht das
**Durchqueren** verloren, und nur für einen unprivilegierten Aufrufer: als root
gelingt das `chmod` am Kind, als `uid 65534` scheitert es mit `No such file or
directory`. Der Agent läuft als root — die Reihenfolge ist dort wirkungslos. Sie
bleibt, kostet nichts, und steht als **Frage** im Wächter statt als Zusage, weil
ein Eingriff dafür grün bliebe.

### c · Ein Loch, das erst der nächste Schritt gezeigt hat

**`ZipArchive::addFromString()` überschreibt einen vorhandenen Eintrag
wortlos** — gemessen: ein Eintrag statt zwei, keine Warnung, `close()` gibt
`true`, und beim Auspacken liegt unsere Fassung da. Eine Datei
`.srvpanel-manifest.json` im Wurzelverzeichnis eines Kunden wäre aus **seiner
eigenen Sicherung** verschwunden, und aufgefallen wäre es erst beim
Zurückspielen.

> **Ein Schreiber, der einen vorhandenen Eintrag ersetzt und Erfolg meldet,
> verliert Daten mit einem Rückgabewert, der wie ein Beleg aussieht.**

`Manifest::RESERVED` nennt die Namen, die die Sicherung selbst belegt, und der
Packer weist sie **beim Packen** ab — laut und mit dem Pfad in der Meldung.
Gefragt wird am **ersten Namensteil**: `.srvpanel-databases-alt` gehört dem
Kunden und kommt durch.

### d · Schritt 4 — die Datenbanken, und zwei Operationen statt einer

**`backup.remove` ist mitgebaut worden, und nicht aus Fleiss.**
`RemovalPathTest` verlangt zu jeder anlegenden Operation ihren Rückweg; ohne ihn
wäre `backup.create` gar nicht erst durch die Wächter gekommen. Die Regel ist
älter als P8 und stammt aus `docs/35`:

> **Wer etwas anlegt, das auf der Platte bleibt, baut den Weg zurück mit; sonst
> findet ihn Jahre später eine Datenmigration.**

**Die Reihenfolge stellt das Panel her.** `backup.create` ruft `db.dump.create`
nicht — keine Operation dieses Agenten ruft eine andere. Sie bekommt die
Ablagenamen und legt die fertigen Dateien hinein; **ein benannter Dump, der
fehlt, bricht ab**, statt eine Sicherung ohne Datenbanken auszugeben, die von
einer vollständigen nicht zu unterscheiden wäre.

### e · `Packer::MAX_ENTRIES` — die Zahl stimmte, die Rechnung nicht

Der Plan nannte in §4 **20 000**; gebaut sind **100 000**. Nicht das Format
bindet (`ZipArchive` schreibt zip64 und hat 70 000 Einträge geschrieben und
gelesen), sondern der Speicher des Agenten.

**Die erste Messung dazu war keine.** Sie lief alle Fälle in *einem* Prozess und
gab Faktoren zwischen 1,5 und 9,7 aus — der Heap wächst über die Fälle hinweg,
und `memory_get_peak_usage(true)` misst ihn mit. Ein Fall je Prozess gibt
stabile und wiederholbare Zahlen: 30 / 60 / **122 MiB** bei 25 000 / 50 000 /
100 000.

> **Ein Prüfkörper, der sich am gegenwärtigen Zustand bemisst, verändert den
> Zustand, an dem er sich bemisst.**

**Und die Spitze hängt nicht an der Länge der Pfade.** Zwei Prüfkörper, einer
kurz (125 B je Eintrag als JSON) und einer in der Tiefe eines WordPress-Baums
(171 B): **beide 122 MiB**. Was den Speicher füllt, ist das Feld aus 100 000
kleinen Feldern und nicht die Zeichenkette daraus.

> **Zwei Grössen, die man zusammen misst, sehen verbunden aus — und welche von
> beiden die Zahl treibt, sagt erst der Prüfkörper, der nur eine von ihnen
> ändert.**

`BackupEntryLimitTest` misst deshalb mit **zwei** Messmitteln:
`memory_get_usage(false)` für die Speicherfrage — additiv, gemessen Byte für
Byte gleich in einem frischen Prozess und in einem mit gewachsenem Heap — und
die JSON-Grösse für die Leitungsfrage.

**Der Wächter hat beim ersten Lauf zugebissen**, und er hatte recht: Seine erste
Fassung rechnete `JSON × 8,7` und meldete 142 MiB für einen Zustand, der
gemessen 122 verbraucht. Der Faktor stammte aus der Messung mit den kurzen
Pfaden — derselbe Fehler eine Ebene höher.

**Und ein Eingriff dazu hat nichts gemessen.** „Das Verzeichnis bekommt ein
Feld" ergab `1044 B` je Eintrag mit dem Feld und ohne, auf das Byte gleich: Ein
Feldliteral aus lauter Konstanten legt PHP **einmal unveränderlich** ab, und
alle 20 000 Einträge zeigten darauf. Mit Werten je Eintrag sind es 1484 B, und
der Wächter wird rot.

> **Ein Eingriff, der einen Zustand herstellt, den der Prüfling ohnehin gleich
> beantwortet, misst die Regel nicht — er misst, dass sie unempfindlich ist.**

### f · Was `backup.create` noch nicht tut, und wo es hingehört

**Der private Schlüssel eines hochgeladenen Zertifikats ist nicht drin.** §4
sagt, er gehöre in die Sicherung — er liegt unter `/etc/srvpanel/tls/certs`,
also ausserhalb des Kundenbaums, und käme wie die Dumps als eigener Eintrag
hinein. Gebaut ist er nicht. Das ist genau die Frage, die §7 dem Wächter
**`BackupReachTest`** zuweist („jede Art aus §4 hat einen Weg"); er steht
deshalb dort und nicht als stille Lücke hier.

> **Eine stille Lücke meldet sich erst Jahre später.**

### g · Der Wächter hat die Schrittgrenze verschoben

**`AgentOperationReachTest` ist rot geworden**, sobald die beiden Operationen
registriert waren: *„Diese Operationen kennt der Agent, und niemand ruft sie
auf."* Er hat recht, und seine Begründung gilt über P8 hinaus:

> **Code, der als root läuft und zu dem es keinen Weg gibt, ist Angriffsfläche
> ohne Nutzen.**

Damit ist die Trennung zwischen Schritt 3/4 (Agent) und Schritt 5 (Seite) keine,
die man ausliefern kann. Gebaut ist deshalb mit den Operationen zusammen die
**Grundlage** der Seite: `backups`-Tabelle, `App\Models\Backup`,
`App\Enums\BackupStatus`, `App\Support\Backups\BackupLifecycle` (in
`Lifecycles::HANDLERS`) und `App\Support\Backups\Backups` als Aufrufer. Was
bleibt, ist die Seite selbst — Controller, Routen, `.vue`.

> **Eine Operation des Agenten und ihr Aufrufer sind eine Arbeitseinheit und
> nicht zwei.** Dasselbe von der anderen Seite wie `context` in `docs/66`: Ein
> Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von einem zu
> unterscheiden, das es nicht gibt.

**`OperationSubject` bekommt dabei noch keinen Fall**, und das ist eine
Entscheidung und keine Auslassung: Jeder Fall dort nennt einen **Ort**, und
`OperationOriginTest` hält, dass der eine angemeldete GET-Route ist. Solange die
Seite fehlt, wäre jeder genannte Ort erfunden. Der Lebenslauf findet seine Zeile
bis dahin über `storage_name` — eindeutig, in der Antwort des Agenten, und
derselbe Name, unter dem die Datei liegt. **Mit der Seite ersetzt `subject_id`
diese Suche, nicht daneben**; zwei Wege von einem Vorgang zu seiner Zeile wären
zwei Fassungen derselben Frage.

### h · Zwei Wächter haben im eigenen neuen Code zugebissen

**`CountedNounTest`** an zwei Stellen: `'%d Dateien'` und `'mehr als %d
Dateien'`. Bei genau eins liest sich das als „1 Dateien". Behoben am **Wert**
(`$files === 1 ? … : …`) und durch Umstellen des Satzes, sodass die Zahl kein
Hauptwort hinter sich hat.

**Und PHPStan an einer Zeile, die still durchgegangen wäre.**
`Backups::create()` las `$subscription->db_prefix`; die Spalte gibt es dort
nicht — sie steht auf `system_users` (`docs/38`, Migration vom 9. August).
Eloquent hätte `null` geliefert, der Agent hätte es angenommen, und im
Verzeichnis stünde **kein Präfix** — genau die Angabe, aus der eine
Wiederherstellung nach Form A die Zuordnung alt → neu baut.

> **Ein Wert, den ein Modell nicht hat, ist `null` und kein Fehler — und `null`
> sieht aus wie „gibt es nicht".**

**Und die Dateiliste für PHPStan war beim ersten Lauf zu kurz**, ohne dass etwas
es gesagt hätte: `git status --porcelain` meldet ein **neues Verzeichnis** als
eine Zeile, nicht als seine Dateien. Zwei der vierzehn Dateien — beide neu unter
`app/Support/Backups/` — sind so aus dem Lauf gefallen, und genau in einer davon
stand der Befund. Der Griff ist `--untracked-files=all`.

> **Eine abgeschnittene Liste sieht aus wie eine vollständige — sie sagt nicht,
> wo sie aufhört.**

### i · Der Nahtwächter hat beim ersten Lauf einen Fehler in meinem Code gefunden

**`BackupSeamTest`** hält, dass der Ablagename, den das Panel baut, einer ist,
den der Agent nimmt. Der Grund ist gemessen: Zwei Zeichenmengen, die sich um
**genau ein Zeichen** unterscheiden.

| Wer | am Anfang | in der Mitte | Länge |
|---|---|---|---|
| Ein Abonnementname | `a-z0-9` | `a-z`, `0-9`, **Punkt**, Bindestrich | bis 63 |
| Ein Ablagename | `a-z0-9` | `a-z`, `0-9`, Unterstrich, Bindestrich | bis 96 |

Der Unterschied ist der **Punkt**, und ein Abonnement heisst regelmässig
`shop.example`.

**Die beiden Ausdrücke selbst stehen im Kopf von `BackupSeamTest` und nicht
hier**, und dafür gibt es einen gemessenen Grund: `DocLinkTest` hat den ersten
Wurf dieser Tabelle als toten Verweis gemeldet. Eine Zeichenklasse in eckigen
Klammern, gefolgt von einer Gruppe in runden, ist buchstäblich die Form eines
Markdown-Links — Text, dann Ziel.

> **Ein regulärer Ausdruck in einem Dokument ist nicht nur schwer zu lesen — er
> kann auch etwas anderes sein.**

Und die Berichtigung ist demselben Wächter ein zweites Mal aufgefallen: Der
Satz, der die Meldung *erklärte*, schrieb die Form noch einmal hin.

> **Ein Text, der eine Meldung über einen toten Verweis zitiert, enthält den
> toten Verweis.** (`docs/100`, zum zweiten Mal.)

> **Zwei Prüfungen, die fast dasselbe erlauben, sind die gefährlichere Art von
> Naht: Sie halten für jeden Prüfkörper, den man beiläufig wählt.**

**Und der Wächter hat gleich einen zweiten Fehler gemeldet, an den ich nicht
gedacht hatte.** Der Name endete auf `<hhmmss>`; zwei Sicherungen desselben
Abonnements in **derselben Sekunde** bekamen denselben Namen, die
`unique`-Bedingung schlug zu, und wer zweimal klickt, bekam einen 500er.
`Dumps::record()` löst genau das seit P5 mit acht Hexziffern — **und schreibt
den Grund daneben.** Ich habe den Fehler noch einmal gemacht.

> **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
> wieder da, wenn die Vermeidung nicht die Regel wurde.** Zum fünften Mal in
> diesem Repo.

**Und die erste Fassung des Wächters hat die Mandantenklammer gemessen statt
der Zeilen.** `Backup::query()->count()` gab `0` zurück — in einem Test ist
niemand angemeldet, und die dritte Grenze steht im Grundzustand auf
`whereRaw('0 = 1')`. Derselbe Fall wie bei `srvpanel tinker` in `docs/78`.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler.**

### j · Und ein Wächter, der die Tür hielt und nicht ihren Gebrauch

Die Prüfung der Panel-Fassung stand zuerst **in** `BackupCreate` — privat, und
damit vom Panel aus nicht messbar. Sie sitzt jetzt als
`Manifest::panelVersion()` dort, wo das Feld wohnt, und `BackupSeamTest` fährt
`config('app.version')` durch dieselbe Tür, durch die der Agent sie nimmt.
Gemessen: In einem Quellbaum steht dort das Wort `Quellbaum`, auf einem Server
die Freigabe; beide kommen durch.

**Der erste Wurf des Wächters hatte trotzdem ein Loch, und ein Eingriff hat es
gezeigt:** Nimmt man den *Aufruf* aus `BackupCreate`, bleibt er grün. Er misst
die Tür und nicht, ob jemand hindurchgeht.

> **Ein Wächter über eine Prüfung sagt nichts darüber, ob sie jemand benutzt.**

Dieselbe zweite Richtung, auf der `SourceKeyFilterTest` seit A1 besteht —
*rechnet richtig* **und** *wird gerufen*. Sie steht jetzt daneben, und der
Eingriff beisst.

### k · Was die Gegenlese des eigenen Diffs gefunden hat

Drei Stellen, keine davon von einem Wächter gemeldet:

- **`is_int($args['system_user'])`** hätte eine Nummer, die als `"1001"`
  ankommt, wortlos zu `null` gemacht — und `null` heisst im Verzeichnis „das
  Abonnement hatte keinen Systembenutzer". Über den Socket reist JSON; die Form
  einer Zahl ist nichts, worauf man sich verlässt. Jetzt `is_numeric`.

  > **Eine Null, die schon eine Bedeutung trägt, kann keine zweite bekommen.**

- **`catch (AgentException)`** um das Hineinlegen der Dumps und das Schreiben
  des Verzeichnisses. Ein `TypeError` aus einer Bibliothek hätte das halb
  geschriebene Archiv liegen lassen — mit falschen Rechten und ohne
  Verzeichnis, also eine Datei, die wie eine Sicherung aussieht. Jetzt
  `Throwable`.

  > **Ein Fehlerweg, der nur einen Teil der Fehler fängt, ist keiner.**

- Und ein Kommentarblock, den eine frühere Berichtigung an einen anderen
  gestossen hatte — zwei Begründungen ohne Leerzeile dazwischen, die sich als
  eine lasen.

---

## §12 · Schritt 5 — die Seite

### a · Die Frage vor dem Bau, und sie war schon beantwortet

**Wo sucht ein Kunde „meine Sicherung"?** Am Quelltext nachgesehen statt
geraten: `/files`, `/sftp` und `/cron` stehen alle als **eigener Menüpunkt** im
Kundenmenü, hinter `has_active_subscription`, und beantworten „welches
Abonnement" selbst. Jedes lag vorher drei Klicks tief, jedes hat der Betreiber
gemeldet (`docs/55` Befund 8, `docs/59` Befund 19, `docs/64` Befund 13).

Sicherungen sind das **vierte** Merkmal mit dieser Frage. `PanelLayout.vue`
schreibt die Antwort selbst als Regel hin — „das dritte Merkmal mit dieser
Frage, und damit ist der Weg keine Entdeckung mehr, sondern die Regel". Gebaut
ist `/backups` an derselben Stelle, hinter Cronjobs.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

### b · Ein Recht, das seit P0 niemand gefragt hat

`Permission::Backups` und `Feature::Backups` gibt es **seit P0**, beide sind
aufeinander abgebildet — und **keine Policy hat sie je gefragt**. Ein Plan
konnte „Sicherungen" freigeben oder verweigern, ein Konto das Recht bekommen
oder nicht, und es bedeutete nichts.

> **Ein Recht, das keine Policy fragt, ist von aussen nicht von einem zu
> unterscheiden, das es nicht gibt.**

Dieselbe Familie wie `context` im Protokoll (`docs/66`), `subject_type`
(`docs/94`) und `Settings::saveDnsAddresses()` (`docs/74`) — nur an einer
Berechtigung, wo es am teuersten ist: **Ein Recht, das nichts durchsetzt, sieht
aus wie Sicherheit.**

`SubscriptionPolicy::manageBackups()` fragt es seitdem, und
**`PermissionReachTest`** hält die Regel dahinter. **Die Frage hat gleich einen
zweiten Fall gefunden**, den niemand gesucht hat: `Permission::Statistics` —
keine Policy, kein Plan-Feature, keine Oberfläche. Er steht als Ausnahme mit
Grund da; das ist ein Befund ausserhalb von P8.

### c · Der Gegenstand kommt mit seiner Seite

In Schritt 3+4 fand der Lebenslauf seine Zeile über `storage_name`, weil
`OperationSubject` je Fall einen **Ort** verlangt und es die Seite noch nicht
gab. Jetzt gibt es sie: `OperationSubject::Backup` zeigt auf
`/subscriptions/{id}/backups`, der Vorgang trägt `subject_id`, und **die Suche
ist fort statt daneben stehen geblieben**.

> **Zwei Wege von einem Vorgang zu seiner Zeile wären zwei Fassungen derselben
> Frage.**

### d · Die Kette Dumps → Sicherung, und worauf sie ruht

`Backups::create()` reiht je Datenbank einen Dump ein und danach die Sicherung.
Dass die Reihenfolge trägt, ist gemessen und nicht angenommen:

- `srvpanel-worker.service` fährt `queue:work` **ohne** `--max-processes` — ein
  Worker, eine Spur.
- Der Datenbanktreiber gibt FIFO.
- `config/queue.php` lässt `DB_QUEUE_CONNECTION` ungesetzt, also teilt sich die
  Warteschlange die Verbindung mit den Vorgängen und **committet mit ihnen**.
  Ohne das wäre `after_commit => false` ein Rennen.

### e · Was die Bilderrunde gefunden hat, und was keine Zahl gemeldet hat

**Die erste Messrunde hat die falsche Seite gemessen.** Nach der Anmeldung stand
`/settings/two-factor`, und `dokument: 0` mit Gegenprobe 200/200 sah aus wie ein
Ergebnis. Gefangen hat es die **Klassenprobe** neben der Messung: eine Tabelle
statt zweier, ein Bereich statt zweier.

> **Ein Ladebeleg gehört in die Messung und nicht in die Erinnerung.**

**Dann drei Befunde, alle am Bild und keiner an der Zahl.**

**1 · Die Knöpfe lagen bei 1440 px ausserhalb.** `dokument = 0`, ein erlaubter
Roller — und „Herunterladen" und „Entfernen" nicht zu sehen. Derselbe Befund wie
in `docs/901`. Ausgemessen, was jede Spalte **kostet** (also wie stark die
Tabelle schrumpft, wenn sie fehlt — nicht ihre Breite, denn die Nachbarn nehmen
sich, was frei wird):

| ohne | Tabelle | Überlauf |
|---|---|---|
| — (voll) | 1610 | 470 |
| nur die Fehlermeldung | 1249 | 109 |
| Spalte „Inhalt" | 1376 | 236 |
| Spalte „Zustand" | 1140 | **0** |

Die Meldung kostete **361 px**. Behoben an **beiden** Ursachen: ein Deckel für
die Meldung in `app.css` und die Spalte „Inhalt" unter den Namen. Danach rollt
in keiner der vier Lagen etwas.

**Der Deckel ist dabei zweimal danebengegangen, bevor er sass.** Gemessen:

| Regel | Meldung | Sicherungen | Dumps |
|---|---|---|---|
| ohne | 505 px, 1 Zeile | 470 | 217 |
| `inline-block; max-width: 46ch` | 410 px, 2 Zeilen | **519** | 265 |
| nur `display: block` | 505 px, 1 Zeile | **470** | **217** |
| `block` + `max-width: 30ch` | 267 px, 2 Zeilen | 233 | **0** |

Als `inline-block` steht die Meldung **neben** der Zustandsmarke, und der
Überlauf wurde **grösser** als ohne Regel. `display: block` allein tut gar
nichts: Ein Block in einer Zelle mit automatischem Tabellenlayout ist so breit
wie sein Inhalt.

> **Zwei Angaben, von denen jede allein nichts tut, sind keine Verzierung — sie
> sind eine Regel, die man nicht halbieren kann.**

**Und es war nicht meine Seite, sondern die Regel:** Die Dump-Tabelle aus P5 hat
denselben Bau und hatte **217 px** Überlauf mit derselben Meldung. Der Deckel
steht deshalb in `app.css` und behebt beide.

**2 · Ein nackter Gedankenstrich unter dem Namen.** Bei einer laufenden und
einer gescheiterten Sicherung stand dort `—` — eine zweite Zeile, die nichts
sagt. Keine Zahl hat sich beschwert.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

**3 · Die Tabelle „Was nicht mitgesichert wird" schnitt ihre Gründe ab.** Als
`pairs` stand der Grund bei 390 px rechts neben dem Namen: „Protokolle rotieren
und werden nicht zurückge…". `docs/24 §5` sagt es — `.pairs` ist für ein Paar
aus Beschriftung und **Wert**, was man Zeile für Zeile liest, ist `.stacks`.

> **Ein Format, das für Bezeichner reicht, reicht nicht für Werte.**

### f · Und ein Handgriff, der Erfolg meldete und nichts tat

Zwei von drei Ersetzungen an der `.vue` haben ihre Zielstelle **nicht gefunden**
— die Einrückung war zwei Zeichen gewachsen, weil die Bereiche kurz zuvor einen
Behälter bekommen hatten. **Nur eine der drei trug eine Zusicherung**, also
meldete das Skript Erfolg. Aufgefallen ist es erst an der gemessenen Kopfzeile,
in der `<th>Inhalt</th>` weiter stand.

> **Ein `sed`, das nichts findet, meldet Erfolg — und der Rückfall, der daran
> hängt, läuft nie.** (`docs/96`, an einem anderen Werkzeug.)

Seitdem trägt **jede** Ersetzung ihre Zusicherung; die nächste hat sofort
zugebissen, weil ein `colspan` schon berichtigt war.

---

## §13 · Die Messrunde vor Schritt 6

Gefahren am 16. September 2026, **vor** der ersten Zeile von `backup.verify`.
Sie hat die naheliegende Bauform zweimal umgeworfen.

### M8 · Welche Prüfung findet welchen Schaden?

Vier Arten, ein Zip zu prüfen, gegen vier Arten von Schaden. Der Prüfkörper ist
jedes Mal ein Archiv, das vorher heil war.

| Schaden | `open()` | `open(CHECKCONS)` | jeden Eintrag **lesen** | CRC gegen das Verzeichnis |
|---|---|---|---|---|
| heil | ok | ok | ok | ok |
| ein Byte in den Daten gekippt | ok | **ok** | **ok** | **findet ihn** |
| die letzten 4 KiB abgeschnitten | rc=19 | rc=19 | — | — |
| die ersten 100 Bytes genullt | ok | rc=19 | 1 unlesbar | 1 unlesbar |

**Zwei Zeilen darin sind das Ergebnis.**

**Jeden Eintrag zu lesen findet ein gekipptes Byte nicht.** Das fühlt sich nach
der gründlichsten Prüfung an und ist die teuerste, die den Schaden nicht sieht:
PHPs `getStream()` gibt die entpackten Bytes zurück, ohne die CRC zu prüfen.

> **Eine Prüfung, die teurer ist, ist deshalb nicht gründlicher — und welche
> Schäden sie findet, sagt erst der Prüfkörper, der sie herstellt.**

**Und `CHECKCONS` findet ihn auch nicht** — obwohl die **erste** Messung genau
das behauptet hat (`rc=21`). Der Unterschied: Dort war das gekippte Byte an
Offset 200 und traf ein Kopffeld; die zweite Runde hat drei Bytes in echten
Nutzdaten gekippt und dreimal `ok` bekommen.

**Die zweite Runde war dabei selbst keine Messung**, und das ist der teuerste
Fehler dieses Vormittags: Sie kippte ein Byte und fragte die Prüfungen, **ohne
zu belegen, dass der Schaden entstanden ist**. Dreimal `ok` sah wie ein Ergebnis
aus. Erst die dritte Runde sucht den Offset, an dem sich der **entpackte Inhalt
messbar ändert**, und fragt erst dann.

> **Ein Prüfkörper, dessen Wirkung man nicht belegt, misst nicht — und sein
> erstes Ergebnis sieht wie die Antwort aus.**

Damit bleibt **eine** Prüfung, die den Schaden findet, vor dem eine Sicherung
schützen soll: der Vergleich der CRC jedes Eintrags mit der im Verzeichnis des
Archivs.

### M9 · Was sie kostet — und warum sie strömen muss

Ein Fall je Prozess, ein Archiv von 300 MiB mit **einem** Eintrag von 300 MiB:

| Art | Zeit | Spitze |
|---|---|---|
| **strömend** (`getStream` + `hash_update('crc32b')`) | 248–257 ms | **2 MiB** |
| ganzer Eintrag (`getFromIndex` + `crc32`) | 394–426 ms | **302 MiB** |

Die strömende Fassung ist **schneller und braucht ein Hundertfünfzigstel des
Speichers**. Das ist kein Feinschliff: `srvpanel-agentd.service` trägt
`MemoryMax=512M`, und ein Kunde mit einer Datei von 600 MB im Archiv hätte den
Vorgang wortlos getötet — dieselbe Grenze, an der schon `Packer::MAX_ENTRIES`
hängt.

**Die erste Messung dazu gab 602 MiB Spitze für die strömende Fassung** und
damit das Gegenteil. Der Grund stand im Prüfkörper: Das `file_get_contents()`
für die Gegenprobe lag im selben Prozess, und `memory_get_peak_usage()` misst
den Prozess.

> **Ein Prüfkörper, der sich am gegenwärtigen Zustand bemisst, verändert den
> Zustand, an dem er sich bemisst.** Zum zweiten Mal in dieser Stufe.

**Und die Gegenprobe ist gefahren**: Mit einem gekippten Byte meldet die
strömende Fassung genau einen kaputten Eintrag, und zwar den richtigen.

### Was daraus für den Bau folgt

1. **`backup.verify` strömt und vergleicht CRCs.** Nichts anderes findet den
   Schaden, vor dem eine Sicherung schützen soll.
2. **Sie läuft in einer eigenen Unit und nicht im Nachtlauf der
   Bestandsdiagnose.** Der kostet gemessen 391 ms (`docs/100` M19); eine Prüfung,
   die Kundenarchive von der Platte liest, gehört nicht hinein.

   > **Ein `check`, der den Bestand des Kunden liest, gehört nicht in denselben
   > Lauf wie einer, der eine Konfigurationsdatei prüft — auch wenn beide
   > dieselbe Form von Befund erzeugen.** (`docs/98 §4`, für A13 geschrieben.)
3. **Was der Container nicht sagen kann**, steht in `§9` und nicht hier als
   Zusage: Der Durchsatz auf der Platte des Servers ist ungemessen. 1173 bis
   1382 MiB/s sind die Zahl dieses Containers, und seine Platte ist nicht die
   von `cloudsrv24`.

---

## §14 · Schritt 6 — `backup.verify`, und was beim Bauen anders war

Gebaut am 16. September 2026. Die Messrunde aus `§13` hat die Bauform
entschieden; was hier steht, ist das, was sie **nicht** entschieden hatte.

### M10 · Ist `hash('crc32b')` die CRC, die ein Zip führt?

Gemessen, weil der Kopf von `BackupVerify` es behauptet hätte und niemand es
nachgesehen hätte. 100 029 Bytes Zufall plus Umlaute, vier Wege zum selben
Wert:

| Weg | Wert |
|---|---|
| `crc32()` | `a3a5aeeb` |
| `hash('crc32b')` | `a3a5aeeb` |
| `hash_init`/`hash_update` in Stücken zu 256 KiB | `a3a5aeeb` |
| `statIndex()['crc']` eines echten Zips | `a3a5aeeb` |

**Gegenprobe:** ein gekipptes Byte ergibt einen anderen Wert. Ohne sie belegte
die Gleichheit oben nur, dass alle vier Wege dasselbe raten.

Verglichen wird seitdem als **Zeichenkette** gegen `sprintf('%08x', …)` und
nicht über `hexdec()`: Das gibt `int|float` und machte aus `!==` eine Frage nach
dem Typ.

### M11 · Greift eine kontextuelle Bindung auch bei `handle()`?

Die Frage entstand, weil `Run` `final` ist: Es gibt keinen zweiten Typ, an den
sich der Lauf der Sicherungen binden liesse. Laravel 13, drei Fälle in einem
Prozess:

| Aufruf | geliefert |
|---|---|
| ohne Kontext, `handle()` | `vorgabe` |
| mit Kontext, Konstruktor | `kontext` |
| mit Kontext, `handle()` | **`kontext`** |

Die erste Zeile ist die Gegenprobe und trägt die Messung — ohne sie sagte das
`kontext` darunter nichts darüber, ob die Bindung etwas bewirkt hat.

**Gemessen heisst hier nicht zugesagt.** Das ist eine Eigenschaft des
Frameworks, und dieses Repo hat für einen Kommentar über eine Framework-Zusage
schon einmal bezahlt (`FindingLog::record()`, `updateOrCreate`). Gehalten wird
sie deshalb von `DiagnoseWiringTest` an der **Wirkung**: Das Kommando läuft, und
danach steht der Zeitstempel unter `diagnose.backups` und **nicht** unter
`diagnose`.

> **Ein Kommentar, der eine Zusage des Frameworks behauptet, ist keine Prüfung —
> er ist eine Zeile, die aussieht wie eine.**

### Befund 1 · Die Datenbanken wären ungeprüft geblieben

**Der grösste Fund dieses Schritts, und er stand im ersten Wurf.** Die Prüfung
filterte über `Manifest::reserves()` — dieselbe Zeile, die `Packer` und
`Unpacker` tragen. Dort ist sie richtig: Es geht um den Baum des Kunden, und was
der Sicherung selbst gehört, hat darin nichts zu suchen.

Hier ging es um den **Inhalt des Archivs**, und `.srvpanel-databases/shop.sql.gz`
ist eine Datei wie jede andere — `BackupCreate::addDumps()` schreibt für jede
einen Eintrag ins Verzeichnis. Gefiltert hätte die Prüfung eine Sicherung, der
**jede Datenbank** fehlt, als heil gemeldet.

> **Dieselbe Frage an zwei Stellen hat nicht dieselbe Antwort, wenn die Stellen
> verschiedene Gegenstände haben — und die übernommene Zeile sieht aus wie
> Sorgfalt.**

Aufgefallen ist es nicht beim Nachdenken, sondern beim Schreiben der Messung zu
M10: Beim zweiten Lesen der Schleife stand die Zeile da, die den Satz aus dem
Kopf derselben Klasse verletzt — *„Ein Archiv, das stillschweigend weniger
enthält, ist schlimmer als keines."*

Zwei Eingriffe halten die beiden Richtungen einzeln, denn sie erzeugen
verschiedene Befunde: filtert nur die Erwartung, meldet der Lauf
`entry_unexpected`; filtert nur das Archiv, meldet er `entry_missing`.

### Befund 2 · Zwei Läufe hätten sich einen Zeitstempel geteilt

`SettingsRunLog` schrieb nach `Settings::DIAGNOSE` — dem Wert, den die
Diagnoseseite als „Zuletzt gemessen" zeigt. Ein zweiter Nachtlauf darauf hätte
die Angabe für die Hälfte der Befunde falsch gemacht, und zwar in beide
Richtungen.

> **Zwei Läufe, die sich einen Zeitstempel teilen, sagen beide die Wahrheit über
> den letzten von beiden und über keinen etwas Verlässliches.**

`Settings::RUN_KEYS` ist seitdem eine **Positivliste** und kein freier Text: Ein
Tippfehler legte sonst wortlos einen dritten Schlüssel an, der für immer nach
„noch nie gemessen" aussähe. Und die Seite nennt beide Zeitpunkte — wo die
Sicherungen noch nie geprüft wurden, steht das ausgeschrieben da und nicht als
Lücke.

### Befund 3 · Eine Zusage, die an einer von zwei Listen gemessen wurde

`DiagnoseRunTest` hält, dass jeder Schlüssel des Katalogs genau einen Schreiber
hat — gemessen über `Catalog::CHECKS`. Mit dem zweiten Lauf war die Zusage rot,
obwohl nichts kaputt war: Die Regel ist eine über den **Bestand der Befunde**
und nicht über einen Zeitgeber.

> **Eine Zusage, die man an einer von zwei Listen misst, gilt für die andere
> nicht — und welche der beiden gemeint war, sagt die Messung nicht.**

`Catalog::every()` ist die Liste, über die die Wächter gehen. Dazu eine neue
Zusage, die es vorher nicht brauchte: Die beiden Läufe sind
**überschneidungsfrei**. `FindingLog::replace()` ersetzt alle Zeilen einer
Prüfung; stünde eine in beiden, löschte der zweite Lauf jede Nacht die Befunde
des ersten.

### Befund 4 · Drei bestehende Eingriffe lasen die `case`-Zeile bis zum Ende

`backup-verify` im Wrapper hat drei Eingriffen des Bruchskripts ihren Text
weggenommen. Gemeldet hat es `BreakScriptTest`, und das ist der Satz aus dem
A3-Lauf noch einmal:

> **Ein Eingriff geht nicht nur kaputt, wenn seine Zielstelle umzieht — auch,
> wenn jemand sie um zwei Leerzeichen verschiebt.**

Behoben ist es nicht, indem die Literale nachgetragen wurden, sondern indem die
Zielstellen **kürzer** greifen: Der tote Eintrag kommt an den Anfang der Liste,
die beiden anderen greifen ein Stück in der Mitte. Damit ist das nächste
Kommando kein Anlass mehr.

### Befund 5 · Zwei Wächter über eine Zahl des Tages

`UnitCatalogTest` prüfte `assertCount(16, …)` — und beide Richtungen darüber
hielten die Gleichheit von Katalog und Paketierung schon. Die Zahl war keine
Zusage, sondern der Stand ihres Tages; die neue Unit hat sie rot gemacht, ohne
dass etwas kaputt war. Das ist die bekannte Falle in ihrer harmlosen Form:

> **Ein Wächter, der beim Aufräumen zubeisst, wird beim Aufräumen abgeschaltet.**

Sie ist jetzt eine Untergrenze, und was sie allein trägt — der Boden gegen „beide
Seiten leer" — steht in ihrem Kopf.

### Befund 6 und 7 · Zwei alte Bekannte im angezeigten Text

`CountedNounTest` an `'%d Einträge geprüft'` — bei genau einem Eintrag „1
Einträge". Dass `REPORT_EVERY` bei 500 steht und die Eins deshalb nie vorkommt,
ist eine Eigenschaft der Konstante und keine des Satzes. Und `WordChoiceTest` an
„die Fassungen gehen auseinander": `docs/19 §3` führt *Fassung* als verbrauchtes
Wort.

### Was Schritt 6 ausdrücklich **nicht** sagt

- **Ob sich eine Sicherung zurückspielen lässt.** Ein Archiv, dessen Bytes
  stimmen, kann eine Beschreibung tragen, die zu keinem Server dieser Fassung
  mehr passt. Das zu beantworten hiesse zurückzuspielen, und genau das hat der
  Betreiber ausgeschlossen.

  > **Ein Beleg für den Weg ist keiner für das Ziel.**

- **Ob eine Datei im Archiv dasselbe enthält wie am Tag der Sicherung.** Geprüft
  wird gegen die Prüfsumme, die **im Archiv** steht; wer beides ändert, kommt
  durch.

  > **Eine Prüfsumme, die neben ihrem Gegenstand liegt, belegt die Übertragung
  > und nicht die Herkunft.**

- **Wie sich ein deflationierter Eintrag mit gekipptem Byte verhält.** Die
  Prüfkörper legen unkomprimiert ab, damit ein Byte gezielt kippbar ist. Der
  deflationierte bricht beim Lesen meist schon ab — ein anderer Weg zum selben
  Befund, hier nicht gemessen.

- **Was ein Lauf über echte Kundenarchive kostet.** `TimeoutStartSec=7200` ist
  gegen 1,2 GB/s dieses Containers gerechnet. Die Platte von `cloudsrv24` ist
  nicht diese; die Zahl gehört auf den Server (`§9`).

---

## §15 · Schritt 7 und 8 — die Wiederherstellung, und was beim Ausschreiben umfiel

Gebaut am 16. September 2026, in **Form A** (`§3`). Was hier steht, sind die
Funde — drei davon liegen in Code, der schon gebaut war, und einer hätte eine
Sicherheitslücke ergeben.

### M12 · Folgt `chown()` einem Verweis?

Der gefährlichste Handgriff dieser Stufe, gemessen **bevor** eine Zeile
entstand. Ein Verweis im Baum zeigt auf eine Datei ausserhalb:

| Griff | der Verweis | sein Ziel |
|---|---|---|
| `chown()` | bleibt `uid=0` | **wird `uid=65534`** |
| `lchown()` | wird `uid=65534` | bleibt `uid=0` |

`Backup\Unpacker` legt Verweise an und prüft ihr Ziel **mit Absicht nicht** —
sein Kopf begründet das: *„Was ein Kunde in seinem eigenen Baum anlegen darf,
darf eine Wiederherstellung ihm zurückgeben."* Das ist richtig, solange niemand
den Verweisen folgt. Ein `chown -R` nach dem Auspacken folgt ihnen: Ein Verweis
auf `/etc/shadow` im Archiv, und nach der Wiederherstellung gehört die Datei dem
Kunden.

> **Ein Verweis, dessen Ziel man nicht prüft, ist harmlos, solange niemand ihm
> folgt — und ein rekursiver Griff folgt ihm, ohne es zu sagen.**

Dieselbe Familie eine Ebene höher: Der Rundlauf läuft **ohne**
`FOLLOW_SYMLINKS`, sonst führte ein Verweis auf ein Verzeichnis ihn aus dem Baum
hinaus — und dann träfe `chown()` jede Datei dahinter, ohne dass ein einziger
Verweis gechownt würde. `BackupRestoreTest` hält beides an einem echten Baum,
mit der Gegenprobe, dass ein gewöhnliches `chown()` dem Verweis hier wirklich
folgen **würde**.

### M13 · Und `chgrp($pfad, $user)` war eine Annahme über einen Namen

Gefunden hat es die **Vorbedingungszeile des eigenen Wächters**, nicht das
Nachdenken: Der Prüfkörper stellte den Schaden gar nicht her, den er messen
wollte. Der Grund war `chgrp($pfad, $user)` — die Annahme, dass es zum Benutzer
eine **Gruppe gleichen Namens** gibt. Für ein Abonnement stimmt sie
(`subscription.provision` legt sie an), für `nobody` nicht, und `@` davor hat
den Fehlschlag verschluckt: Die Gruppe wäre `root` geblieben, über den ganzen
Baum, wortlos.

> **Eine Annahme über einen Namen, die meistens stimmt, ist mit `@` davor nicht
> mehr von einer zu unterscheiden, die immer stimmt.**

Gefragt wird seitdem die **primäre Gruppe** des Benutzers, einmal aufgelöst statt
je Eintrag — bei 100 000 Einträgen sind das 200 000 gesparte Abfragen an
`/etc/passwd` und `/etc/group` für eine Antwort, die sich nicht ändert. Und ein
misslungener Wechsel wird **gesammelt und geworfen** statt verschluckt: Eine
Datei, die dem alten Eigentümer gehören bleibt, ist für den Kunden nicht lesbar,
und eine Wiederherstellung, die das verschweigt, sieht aus wie eine gelungene.

### Befund 1 · Die Dumpliste hat die Struktur der Datenbanken überschrieben

`BackupCreate::addManifest()` schrieb `$description['databases'] = $dumps` —
unter demselben Schlüssel, unter dem `Description` die **Struktur** ablegt:
Beschriftung, Zeichensatz, Sortierung. Am echten Code belegt: Nach der Zuweisung
ist von `collation` nichts mehr da.

**Jede bis dahin geschriebene Sicherung trägt sie nicht**, und eine
Wiederherstellung daraus legte jede Datenbank mit der Vorgabe des Servers an
statt mit ihrer Sortierung — eine Datenbank, die aussieht wie die alte und
anders sortiert.

> **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf genau dieser
> Seite fort — und der Ausfall liest sich wie ein Rechteproblem.**

Zum **dritten** Mal in diesem Repo: `can` gegen `abilities` (`docs/82`
Schritt 5), `errors` auf `/updates` (`docs/904`), und jetzt `databases`. Der
Schlüssel heisst `dumps`; `BackupFormTest` hält, dass `BackupCreate` **keinen**
Schlüssel beschreibt, den die Beschreibung schon führt — die Liste dazu kommt aus
`Description::of()` und nicht aus einer Aufzählung im Test.

### Befund 2 · Die Beschreibung reichte für eine Subdomain nicht

`App\Support\Web\Domains::create()` verlangt für `subdomain` und `alias` die
Zeile, unter der sie hängen, und weist sonst mit *„Diese Sorte braucht eine
Domain, unter der sie hängt"* ab. Die Beschreibung trägt **keine Kennungen** —
das ist richtig und in `BackupSecretTest` festgehalten, weil eine Kennung dieses
Panels auf einem anderen Server ins Leere führte.

Sie trägt jetzt den **Namen** des Elternteils, und die Wiederherstellung legt
Haupt- und Addon-Domains zuerst an. Gefunden beim Ausschreiben von Schritt 8 und
nicht beim Bauen von Schritt 5: Dort sah die Beschreibung vollständig aus, weil
niemand sie gelesen hat.

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt** — und ob es reicht, sagt erst der
> Leser.

### Befund 3 · Ein rekursiver Griff ebnet das Verzeichnisschema ein

`httpdocs` gehört `%u:www-data`, `logs` gehört `%u:adm`, `conf` gehört
`root:root` — das steht in `SubscriptionProvision::TREE` und nirgends sonst. Ein
`chown -R` macht daraus dreimal `%u:%u`, und der Webserver käme an das
Dokumentenverzeichnis nicht mehr heran.

> **Ein Schema, das eine Stelle kennt, wird von jedem rekursiven Griff
> eingeebnet — und der Schaden sieht aus wie ein Rechteproblem irgendwo
> anders.**

`applyTree()` ist deshalb öffentlich und wird **nach** dem Eigentümerwechsel
gerufen. Die Wiederherstellung baut das Schema nicht nach: Eine zweite
Aufzählung wäre die, die beim nächsten Zuwachs von `TREE` veraltet.
`BackupFormTest` misst beides — die Wirkung an einem echten Baum und die
Reihenfolge im Rumpf von `execute()`.

### Befund 4 · Ein Wächter aus Schritt 5, der nie beissen konnte

Der volle Bruchlauf hat ihn gemeldet: `Ablagename ungekürzt — passed (erwartet:
failed)`. Die Kürzung des Abonnementnamens auf 60 Zeichen war mit *„63 Zeichen
plus Zeitstempel plus Zufallsteil überschreiten die 96"* begründet, und das ist
falsch gerechnet:

| | Zeichen |
|---|---|
| Abonnementname, Höchstlänge im Formular | 63 |
| `-<Ymd-His>-<8 hex>` | 25 |
| zusammen | **88** |
| was `Store::storageName()` zulässt | **96** |

> **Eine Zahl in einer Erwartung, die man nicht gezählt hat, ist eine Vermutung
> mit Anspruch.**

**Tragend ist die Kürzung trotzdem**, und zwar dort, wo der Prüfer des Formulars
nicht hinkommt: `subscriptions.name` ist ein `varchar(255)`, und `max:63` gilt
nur für den Weg über das Formular.

> **Eine Prüfung am Formular ist keine über die Spalte.**

Der Platz wird jetzt aus `Store::MAX_NAME` gerechnet, und der Prüfkörper des
Wächters hat einen Fall an der Breite der **Spalte** bekommen. Danach beisst der
Eingriff.

### Was Form A den Kunden kostet — und wo es steht

`§3` zählt drei Preise auf, und alle drei stehen **vor** dem Knopf auf der
Seite und nicht danach: der neue Systembenutzer (sein SFTP-Benutzername), das
neue `db_prefix` (die Datenbanknamen in seiner Konfigurationsdatei) und die
neuen Passwörter.

**Die Passwörter kann die Wiederherstellung nicht nennen**, und das ist eine
Entscheidung und keine Auslassung: Sie entstehen in einem Hintergrundlauf, und
ein Passwort, das dieses Panel ablegt oder in ein Vorgangsergebnis schreibt,
stünde auf der Vorgangsseite — `Operations/Show.vue` rendert `result` als JSON,
und `OperationPolicy::view()` lässt jeden Admin und den Kunden hindurch.

> **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf der
> Vorgangsseite.**

Die Zugänge entstehen deshalb mit ihren Rechten und einem verworfenen Passwort,
und das Ergebnis sagt je Lauf, dass jeder eines neu braucht. Das ist genau die
dritte Art aus `§4`: *Was weder beschrieben noch erzeugt werden kann, muss die
Wiederherstellung benennen.*

### Zwei Vorgänge und nicht einer

`subscription.provision` legt Konto, Schema und Quota an, `backup.restore` packt
hinein. **Keine Operation dieses Agenten ruft eine andere**; die Reihenfolge
stellt das Panel her, wie schon bei `Backups::create()`, und sie trägt, weil
`queue:work` einspurig ist.

Sie trägt **laut**: Läuft die Bereitstellung nicht durch, findet
`backup.restore` kein Verzeichnis und bricht mit genau diesem Satz ab. Ein
Auspacken, das sich sein Ziel selbst anlegte, wäre die halbe Bereitstellung in
einer zweiten Fassung — ohne Konto, ohne Quota, ohne Schema.

### Und was danach noch offen ist

- **Punkt 5 des Abnahmekriteriums ist hier nicht messbar.** Ob eine
  wiederhergestellte Vhost-Datei die Bestandsdiagnose besteht, braucht nginx,
  die echte Vorlage und einen Nachtlauf. `BackupFormTest` hält, dass die
  **Eingaben** vollständig sind, aus denen sie entsteht — nicht das Ergebnis.

  > **Ein Beleg für den Weg ist keiner für das Ziel.**

- **Der ganze Weg ist nie auf einem Server gefahren.** Kein Abonnement ist
  gelöscht und zurückgeholt worden; was hier steht, ist an Prüfkörpern gemessen.
  Das ist `§8` Punkt 1 bis 6 und gehört auf `cloudsrv24`.

- **Eine Wiederherstellung, die auf halbem Weg scheitert, räumt nicht auf.** Das
  Abonnement steht dann da, die Nummer ist verbraucht, und was schon angelegt
  wurde, bleibt. Der Lauf sagt in seinem Ergebnis, was misslungen ist; ein
  Rückbau von Hand ist `subscription.remove`. Ein automatischer wäre ein zweiter
  Weg, der im Fehlerfall läuft — also der, der am wenigsten geprüft ist.

---

## §16 · Schritt 9 und 10 — Aufbewahrung, Zeitplan und der Griff davor

Gebaut am 16. September 2026. Damit ist der Bau von P8 durch; was auf einem
Server zu messen bleibt, steht in `§9`.

### Die Aufbewahrung ist ein Kontingent und keine Servereinstellung

`Quota::Backups` — je Plan gesetzt, je Abonnement übersteuerbar, genau wie die
Domains und die Datenbanken. Eine Zahl an einer anderen Stelle wäre eine zweite
Fassung derselben Regel.

**Sie darf nicht unbegrenzt sein**, und der Wächter hat den Grund erzwungen:
`QuotaCatalogTest::test_only_shared_resources_have_no_unlimited` ist rot
geworden, bis er danebenstand. Er lautet wörtlich wie die Begründung über
`disk_mb` — eine Sicherung ist das Grösste, was dieses Panel je Abonnement auf
die Platte schreibt.

> **Eine Aufbewahrung ohne Obergrenze ist keine Aufbewahrung, sondern ein
> Wachstum.**

Die drei Zahlen daneben sind ebenfalls entschieden und nicht gegriffen:
**mindestens 1** (eine Aufbewahrung von 0 löschte jede Sicherung in dem
Augenblick, in dem sie fertig ist — das ist kein enges Paket, sondern ein
kaputtes), **höchstens 365** (ein Jahr täglicher Stände, als Vertipper-Fang) und
**drei als Vorgabe** (zwei reichen nicht für „gestern war auch schon kaputt").

### Befund 1 · Die Aufbewahrung hätte jede Nacht eine Datei liegengelassen

**Der grösste Fund dieses Schritts, und er liegt in Code aus Schritt 3+4.**
`Backups::remove()` las `$backup->subscription` — eine **faul geladene
Beziehung**, und die nimmt die Mandantenklammer. Aus einem Aufruf ohne
angemeldetes Konto — also aus genau dem nächtlichen Lauf, den Schritt 9 baut —
kam immer `null` zurück, und die Zeile ging den Zweig „ohne Umweg über den
Agenten": gelöscht, ohne dass die Datei je angefasst worden wäre.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler.** (`docs/78`)

**Und der Kommentar daneben war auch falsch.** Er behauptete, ein
zurückgebautes Abonnement habe „sein ganzes Verzeichnis verloren" und die Zeile
beschreibe eine Datei, die es nicht mehr gibt. Der Kopf der Migration sagt das
Gegenteil, und er hat recht: `/var/lib/srvpanel/backups/<abo>` liegt ausserhalb
von allem, was `subscription.remove` anfasst — *„Die Sicherung überlebt ihr
Abonnement."*

> **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist auch dann
> falsch, wenn der Handgriff daneben richtig ist.**

Eine Sicherung ohne Abonnement geht seitdem trotzdem über den Agenten, mit dem
**abgeschriebenen** Namen und einem Vorgang ohne `subscription_id`. Das ist
zugleich die Antwort auf `§9` Punkt 7: Die Lücke, die dort benannt stand, wäre
ab Schritt 9 keine seltene mehr gewesen, sondern eine nächtliche.

### Der Zeitplan fragt das Alter und nicht den Kalender

`Retention::isDue()` fragt, wie alt der jüngste Stand ist. Ein Lauf, der zweimal
am Tag fährt, legt damit trotzdem nur eine Sicherung an, und ein Server, der
zwei Tage aus war, holt genau eine nach.

> **Ein Zeitgeber, der fragt „wie alt ist der letzte Stand", ist wiederholbar.
> Einer, der fragt „welcher Tag ist heute", ist es nicht.**

**Das Fenster ist 20 Stunden und nicht 24, und die Zahl ist gerechnet.** Der
Timer steht auf `OnCalendar=daily` mit `RandomizedDelaySec=2h`; zwei
aufeinanderfolgende Läufe liegen damit zwischen **22 und 26 Stunden**
auseinander. Bei 24 fiele jeder Lauf aus, dessen Abstand die Streuung nach vorn
gezogen hat — still, denn es entstünde einfach keine Sicherung.

> **Ein Fälligkeitsfenster, das so gross ist wie der Takt, verliert jeden Lauf,
> den die Streuung nach vorn zieht.**

`BackupRetentionTest` rechnet das **aus der Unit-Datei** nach und nicht gegen
eine Zahl im Test: Ändert jemand die Streuung, wird der Wächter rot und nicht
der Lauf still.

### Abräumen immer, anlegen nur auf Ansage

Die beiden Hälften des Nachtlaufs hängen nicht zusammen:

- **Aufräumen ist Hygiene.** Die Aufbewahrungszahl steht im Plan und gilt auch
  für Stände, die ein Kunde von Hand angelegt hat. Sie nur dann greifen zu
  lassen, wenn die Automatik an ist, hiesse: Wer nicht automatisch sichert, hat
  gar keine Grenze.
- **Anlegen ist eine Entscheidung.** `automatic` steht auf **aus**; ein Update,
  das für jedes Abonnement nächtliche Sicherungen anschaltet, füllt den
  Datenträger, ohne dass jemand gefragt hätte.

Beides steht auf `/settings/backups` — `operate-server`, wie PHP und die
Datenbanken. Was den Datenträger füllt und was beim Rückbau geschieht, gehört
dem Betreiber.

### Schritt 10 · Nur vor dem Rückbau, und die anderen beiden mit Grund

`docs/20 §9` nennt drei riskante Handlungen. Gebaut ist **eine**, und die beiden
anderen stehen hier statt stillschweigend zu fehlen:

| Handlung | gebaut | warum |
|---|---|---|
| **Löschen eines Abonnements** | ja | Der eine Griff dieses Panels, der nichts zurücklässt. |
| **PHP-Wechsel** | nein | Durch einen zweiten Wechsel zurückzunehmen. Eine Sicherung des ganzen Baums dafür wäre Minuten für einen Griff, der Sekunden dauert. |
| **Wiederherstellung** | nein | Sie legt in Form A ein **neues** Abonnement an und überschreibt nichts (`§3`). Es gibt nichts, was verloren ginge. |

> **Eine Vorsichtsmassnahme vor jedem Griff ist keine Vorsicht, sondern eine
> Gewohnheit — und sie wird als Erstes abgeschaltet, wenn sie stört.**

**Die Sicherung vor dem Rückbau fragt den Plan ausdrücklich nicht.**
`Feature::Backups` entscheidet, ob der **Kunde** sichern darf; hier sichert der
Betreiber, bevor er etwas unwiederbringlich entfernt.

> **Eine Vorsichtsmassnahme, die der Tarif abschalten kann, schützt den
> Betreiber nicht vor seinem eigenen Griff.**

**Und sie trägt nur, weil die Zeile den Rückbau überlebt.** Ohne
`nullOnDelete` wäre sie in derselben Sekunde fort, in der sie gebraucht würde.

### Befund 2 · Ein Wächter, der die Gruppe zählt

`NavGroupTest` ist rot geworden: „Einstellungen" hat mit `/settings/backups`
**acht** Punkte und damit die Obergrenze erreicht, die
`test_no_group_grows_back_into_a_pot` setzt. Das ist die bekannte Aufräumfalle
in ihrer nützlichen Richtung — ein Halt, an dem jemand einmal entscheiden muss.

Entschieden: Sie bleibt eine Gruppe, weil jeder ihrer Punkte dieselbe Frage
beantwortet. „Betrieb" war bei neun keine mehr, weil dort **zwei** Fragen
standen — was ist und was war.

> **Eine Gruppe ist zu gross, wenn sie zwei Fragen beantwortet — und nicht, wenn
> sie viele Punkte hat.**

### Befund 3 · Zwei Prüfkörper, die etwas anderes gemessen haben als gedacht

`created_at` steht nicht in `Backup::$fillable`; Eloquent lässt es wortlos
fallen. Jede Zeile des ersten Wurfs trug `now()`, und der Fall über das Alter
prüfte nichts.

> **Ein Prüfkörper, der einen Wert setzt, den das Modell nicht annimmt, misst
> den Vorgabewert.**

Und `SubscriptionFactory` lässt `system_user` auf `null`. Der Fall über die
Sicherung vor dem Rückbau mass damit die Vorbedingung, die
`Backups::beforeRemoval()` zu Recht abweist — und sah aus wie ein Fehler am
Prüfling.

> **Ein Prüfkörper, der eine Vorbedingung nicht herstellt, misst die
> Vorbedingung.**

### Was `BackupReachTest` hält — und was er benennt

Jede Art aus `§4` hat einen Weg, oder sie steht mit ihrem Grund da. Zwei stehen
da:

- **Der private Schlüssel eines hochgeladenen Zertifikats.** `§4` will ihn in
  der Sicherung, und gebaut ist es nicht: Damit trüge eine Sicherung erstmals
  ein Geheimnis, und die Datei geht über `response()->download()` an den Kunden.
  Der Wächter misst dazu, dass der Ablageort **wirklich** ausserhalb des
  Kundenbaums liegt — sonst wäre die Ausnahme eine Zeile, die man auch dann noch
  läse, wenn der Schlüssel längst in jeder Sicherung stünde.
- **Die Datenbankpasswörter**, und die sind kein Rest: Dieses Panel hält keine
  und kann sie nicht sichern. Die Wiederherstellung benennt es.

### Befund 4 · Drei bestehende Wächter am Schluss, und der dritte hat entschieden

`AttributeNameTest`, `RedirectTargetTest` und `AttributeLabelTest` sind am
vollen Lauf rot geworden, alle drei an derselben neuen Datei
(`BackupSettingsController` mit seiner Seite). Der zweite war ein Handgriff —
`back()` statt eines benannten Ziels.

**Die beiden anderen sind ein Paar, und sie haben genau die Frage gestellt, die
`docs/66` Befund 15 gekostet hat.** `AttributeNameTest` verlangt, dass jedes
validierte Feld einen deutschen Namen **hat**; `AttributeLabelTest` verlangt,
dass dieser Name der ist, der auf der Seite **steht**. Der erste war mit
„Nächtlich sichern" und „Vor dem Rückbau sichern" zufrieden, der zweite nicht:
Neben den Kästchen steht ein ganzer Satz — „Jede Nacht eine Sicherung je
Abonnement anlegen".

> **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**

**Entschieden hat der Wächter selbst, nicht das Nachdenken.** Seine Meldung
nennt beide Ausgänge — den Namen am Aufruf setzen, oder die Beschriftung mit
Begründung nach `KEIN_NAME` —, und seine Liste trug den Fall bereits dreimal:
„Ausgeliefert wird" ist ein Satzanfang, „Erreichbar von — für {{ … }}" trägt
einen eingesetzten Wert, und bei den Ankündigungen gehört die Beschriftung dem
einzelnen Kästchen, während der Name des Feldes als `span` darübersteht.

Genau das ist die Lage hier: Der Satz am Kästchen ist kein Name, die
**Überschrift des Bereichs** ist einer und steht sichtbar darüber. Eingesetzt
ergäbe der Satz „Das Feld Jede Nacht eine Sicherung je Abonnement anlegen muss
wahr oder falsch sein" — ein Satz in einem Satz.

> **Ein Wächter, der beim Melden sagt, welche Ausgänge es gibt, erspart dem
> Nächsten die Überlegung, welcher der richtige ist — und seine Ausnahmeliste
> ist die Sammlung der Fälle, in denen jemand sie schon einmal angestellt hat.**

Die Ausnahme ist in **beide Richtungen** gegengeprüft: Zeigt ihr Schlüssel auf
ein Feld, das es nicht gibt, meldet `test_every_exception_still_points_somewhere`
sie — und dasselbe Feld steht gleichzeitig wieder als Abweichung da. Was sie
**nicht** kann, steht im Kopf des Wächters: Sie schweigt für dieses Feld
dauerhaft, auch wenn der allgemeine Name später ein anderer wird.

Keiner der drei kam aus dem Bruchlauf, sondern aus dem vollen Testlauf vor dem
Commit. Sie stehen hier, weil die Reihenfolge das Tragende ist: Wäre committet
worden, bevor er durch war, stünde eine englische Meldung im Repo.

### Befund 5 · Die Behebung von Befund 1 war eine zweite Fassung derselben Regel

Der erste Wurf der Berichtigung an `Backups::remove()` hat für den Fall „ohne
Abonnement" eine **eigene** anlegende Methode bekommen —
`dispatchWithoutSubscription()`, mit ihrem eigenen `Operation::create()`. Sie
stand vier Zeilen unter der gemeinsamen `dispatch()`, und die gemeinsame
verlangte ein `Subscription` im Typ.

Gefunden hat es die Gegenlese des eigenen Diffs, und zwar an einem Feld, das die
beiden **verschieden** gefüllt haben: Die neue setzte `account_id` aus der
Anfrage, die gemeinsame setzt es gar nicht. Dieselbe Handlung hätte damit auf
`/audit` je nach Bestand des Abonnements einmal „Anna Berger" und einmal
„System" ergeben — genau die Spalte, die `docs/901` sichtbar gemacht hat.

> **Zwei Stellen, die dasselbe anlegen, unterscheiden sich zuerst an dem Feld,
> an das beim Schreiben der zweiten niemand gedacht hat.**

Gebaut ist jetzt **eine** Stelle: `dispatch()` nimmt ein `?Subscription` und
daneben den abgeschriebenen Namen. Der Rückfall ist dabei ausdrücklich keiner —
fehlen beide, wirft sie:

> **Ein Rückfall, der immer etwas liefert, macht aus „unbekannt" eine falsche
> Auskunft.** Eine leere Zeichenkette ergäbe einen Pfad auf die Wurzel der
> Sicherungen und eine Meldung über ein Abonnement ohne Namen.

Der Fall im Wächter misst seitdem auch den **Gegenstand** des Vorgangs: Ohne
Abonnement ist die Zeile der Sicherung das Einzige, worüber ein Fehlschlag noch
auffindbar ist. Gegengeprüft — nimmt man `dispatch()` den Gegenstand weg, meldet
er es.

**Und eine Annahme darin war falsch und hat nichts gekostet:** `subject_type`
ist auf `Operation` **nicht** als Aufzählung gegossen, sondern eine
Zeichenkette. Der erste Wurf der Behauptung las `?->value` darauf und starb an
„Attempt to read property \"value\" on string".

**Und dabei fiel auf, dass die Klammer selbst ungemessen war.** Der Eingriff zu
Befund 1 bricht `$name` und nicht die Klammer — und der Fall, an dem er hängt,
kann sie gar nicht messen: Dort ist das Abonnement wirklich fort, und
`subscription()->first()` antwortet mit und ohne Klammer `null`.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

Gemessen wird sie jetzt an dem Fall, an dem das Abonnement **lebt**:
`test_the_oldest_go_and_the_newest_stay` läuft ohne angemeldetes Konto — genau
der Zustand des Nachtlaufs — und prüft, dass jeder der drei Vorgänge sein
Abonnement trägt. Ohne die Klammer hinge er an keinem; der Agent entfernte die
Datei zu Recht, und der Vorgang stünde in keiner Liste des Kunden.

> **Ein Vorgang, der seinen Gegenstand verliert, tut trotzdem das Richtige — und
> niemand findet ihn danach wieder.**

Der achte Eingriff hält es.

**Und die Behebung hat einen Kommentar falsch werden lassen.**
`Retention::prune()` zog die ganze Schleife in die Klammer, begründet damit,
dass `remove()` ungeklammert las. Seit `remove()` selbst klammert, stimmt die
Begründung nicht mehr — und schlimmer: Eine zweite Klammer um den Aufruf machte
ausgerechnet den einen Aufrufer blind, an dem ein Rückfall auffiele. Sie steht
jetzt um die Abfrage und nicht um die Schleife.

> **Eine Vorsichtsmassnahme, die den Fall verdeckt, für den sie gedacht war, ist
> keine mehr.**

### Befund 6 · Die Frage nach dem Verzeichnis stand nur an einer der zwei Stellen

`Backups::beforeRemoval()` fragt seit Schritt 10, ob das Abonnement überhaupt
einen Systembenutzer hat — ohne ihn gibt es kein Verzeichnis, und die Sicherung
wäre ein Vorgang, der an einem fehlenden Pfad scheitert. `RunBackups::eligible()`
fragte den Zustand, die Funktion des Plans und das Kontingent, und diese Frage
nicht.

Für ein aktives Abonnement ohne Systembenutzer hätte der Nachtlauf damit **jede
Nacht** einen scheiternden Vorgang angelegt und **jede Nacht** einen Fehlschlag
des Kommandos gemeldet.

> **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
> wieder da, wenn die Vermeidung nicht die Regel wurde.**

Sie steht jetzt als `Backups::hasDirectory()` an **einer** Stelle, und beide
Aufrufer fragen sie. Der Fall misst dabei **beide** Richtungen in einem Lauf:
Ein Prüfkörper ohne Verzeichnis allein liefe auch dann grün durch, wenn das
Kommando gar nichts anlegte — das Abonnement daneben sagt, dass es angelegt
hätte.

**Und im selben Rumpf stand ein Kontingent als Wort.** `$subscription->quota('backups')`
statt `Quota::Backups->value`, zwei Dateien neben einem `Retention::keeps()`, das
es richtig macht — dieselbe Zeichenkette ohne geprüften Bezug, die dieses Repo am
häufigsten bezahlt hat.

### Befund 7 · Die Bilderrunde hat den Knopf gefunden, und keine Zahl hat sich beschwert

Die Einstellungsseite mass in allen vier Lagen `dokument = 0`, Gegenprobe
200/200, `schiebt = []`, `rollt = []`. Und bei 1440 px stand **„Speichern" oben
rechts neben der Überschrift des zweiten Bereichs** statt unter dem Formular.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

Die Knopfreihe war ein **direktes Kind** von `.sections`. Gemessen ist der
Mechanismus eine einzige Zeile in `app.css`: `.form > .button-row` trägt
`flex-basis: 100%`, `.sections > .button-row` nichts dergleichen — die Regel
gilt nach dem **Elternteil** und nicht nach der Klasse. Als Flexkind von
`.sections` lief die Reihe neben den Bereichen mit; bei 390 px ist daneben kein
Platz, und dort sah sie richtig aus.

**Die Regel gab es schon, und zwar als Kommentar.** `Settings/Tls.vue` hält seit
P7 fest: *„Die Knopfreihe steht neben dem Bereich und nicht darin — so wie in
jeder anderen Maske des Panels."* Dort war sie an einem fehlenden Abstand
bezahlt worden, hier an einer Spalte.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

`ButtonRowPlacementTest` hält sie jetzt, mit der Voraussetzung daneben: Fällt
`flex-basis: 100%` weg, sagt die Stelle im Baum nichts mehr über die Anzeige,
und der Wächter wird rot statt still.

### Befund 8 · Und sein Leser hat einen Fehler geerbt, den es seit P6 gibt

Der erste Lauf des neuen Wächters meldete eine Knopfreihe in `.sections`, die in
Wahrheit in einem Bereich steht — `Subscriptions/Backups.vue:219`. Der Leser
zählt Elemente auf einen Stapel und fragt den Elternteil; `link` steht in seiner
Liste der leeren Elemente, weil `<link>` in HTML kein Ende hat.

**Inertias `<Link>` ist eine Komponente mit Inhalt und Ende.** Kleingeschrieben
sehen die beiden gleich aus — und der Leser wandelte den Namen um, bevor er
fragte. Ab der ersten `<Link>` verschob sich der Stapel um eins, und jeder
Elternteil danach stand daneben.

> **Eine Liste leerer HTML-Elemente trifft eine Komponente, die zufällig so
> heisst — und Vue unterscheidet die beiden allein an der Grossschreibung.**

**`TemplateSpacingTest` trägt denselben Leser und denselben Fehler, seit es ihn
gibt.** Gemessen an der Bilanz: Von 82 Vorlagen endeten **23** mit einem Stapel
ungleich null, mit der Berichtigung **keine einzige**. Der Wächter blieb dabei
grün — der Fehler hat heute nichts verdeckt, und das ist Glück und keine
Eigenschaft.

> **Ein Wächter, der grün ist, während sein Leser den Faden verloren hat, sagt
> über die Regel nichts — er sagt, dass niemand hingesehen hat.**

Die Prüfung, die das sofort gemeldet hätte, steht jetzt daneben:
`test_the_reader_keeps_track` verlangt, dass **jede** Vorlage mit einem leeren
Stapel endet. Gegengeprüft an genau dem Fehler, der sie ausgelöst hat — mit der
alten Fassung meldet sie 23 Dateien.

> **Ein Leser, der den Faden verliert, meldet nicht sich selbst — er meldet die
> Datei.**

### Was danach offen bleibt

- **Der ganze Weg ist nie auf einem Server gefahren** — das gilt unverändert aus
  `§15` und jetzt auch für den Nachtlauf.
- **Dass die Warteschlange die Sicherung vor dem Rückbau abarbeitet, ist eine
  Eigenschaft der Umgebung** (`queue:work` einspurig, Datenbank-Warteschlange
  FIFO) und keine Zusage des Codes. `BackupReachTest` hält die **Reihenfolge im
  Rumpf**; die Reihenfolge in der Warteschlange gehört auf `cloudsrv24`.
- **Ein Fernziel gibt es nicht** (`§5`, `§8` Punkt 8). S3 ist entworfen und nicht
  gebaut; `§2` Entscheidung 3 und die Messung dazu stehen, der Weg fehlt.
- **`Backups::removeAll()` hat weiterhin keinen Aufrufer.** Es war für den
  Rückbau gedacht und widerspricht damit dem, was die Migration entschieden hat:
  Die Sicherung überlebt ihr Abonnement. Es zu rufen wäre falsch, es zu löschen
  eine eigene Entscheidung — es steht hier, damit die nächste Sitzung nicht
  dieselbe halbe Stunde damit verbringt, den Widerspruch noch einmal zu finden.

  > **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
  > einem zu unterscheiden, das es nicht gibt** — und eine Methode, die niemand
  > ruft, genauso.
