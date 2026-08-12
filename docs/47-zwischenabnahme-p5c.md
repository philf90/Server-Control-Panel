# 47 — Die Zwischenabnahme von P5c (Schritte 0 bis 3)

**Was hier steht:** ein Testlauf auf `cloudsrv24` für die Schritte 0 bis 3 aus
`docs/46 §13` — den Agenten beider Systeme und die Anwendung, die ihn ruft. Etwa
anderthalb Stunden. **Was hier nicht steht:** die Abnahme von P5c. Die ist
`docs/46 §15`, sie braucht die Oberfläche aus den Schritten 4 bis 6 und ist noch
nicht fahrbar.

**Der Lauf kommt ohne ein einziges Bild aus**, und das ist kein Mangel, sondern
der Grund, warum er jetzt geht: Nach Schritt 3 ist die ganze Fläche über
`srvpanel tinker` und `psql`/`mysql` erreichbar, und was sie beantwortet, hat mit
Anzeige nichts zu tun.

> **Gefahren am 12. August 2026** gegen `0.5.3-rc.1` und ab Punkt 7 gegen
> `0.5.3-rc.2`. Sechs Kriterien erfüllt, das siebte benannt offen, **sechs
> Befunde und keinen davon ein Test** — einer davon hat den Lauf unterbrochen,
> bis er behoben und ausgeliefert war. Das Protokoll steht in §6. Die Punkte
> unten tragen die Korrekturen, die er an sich selbst gefunden hat.

---

## 1. Warum dieser Lauf eigenständig ist

**Zwei Gründe, und der erste duldet keinen Aufschub.**

**Die Form einer befristeten Kennung ist breiter geworden** (`docs/46 §20.2`,
Risiko 8). `Names::ephemeral()` erzeugte bis P5b `<präfix>_r<8 hex>`; die Konsole
bekommt `c`, und damit prüft `isEphemeral()` auf `[rc]`. Das ist richtig für
alles, was ab jetzt entsteht, und **rückwirkend nicht**: Ein Kundenzugang, der
heute `<präfix>_c1234abcd` heisst, ist ab dieser Fassung ein Rest.

Was ihm dann passiert, ist schlimmer, als es klingt. Er wird nicht gelöscht — er
**verschwindet**. `Pg\Owner::roles()` überspringt jeden Namen dieser Form, also
steht der Zugang nicht mehr in der Liste der Zugänge seiner Datenbank; er lässt
sich im Panel weder berechtigen noch entfernen. Gleichzeitig meldet
`srvpanel db` ihn unter „liegengeblieben" mit dem Satz „Sie gehen mit
`srvpanel db --prune`". Der Kunde verbindet sich weiter, das Panel kennt ihn
nicht mehr, und der Betreiber liest eine Aufforderung, ihn wegzuräumen.

**Diese Frage muss beantwortet sein, bevor die Fassung auf dem Server ankommt.**
Danach ist sie zwar immer noch zu stellen, aber die Antwort steht dann unter
einem Panel, das den Zugang schon nicht mehr anzeigt. Deshalb ist sie Punkt 1
dieses Laufs und nicht Punkt 12.

**Der zweite Grund:** Schritte 0 bis 3 sind gegen Server gemessen, die dieser
Container hochgezogen hat — einen Debian-Cluster PostgreSQL 16 und ein
MariaDB 10.11.14 aus dem Ubuntu-Archiv (`docs/46 §13`). Das ist deutlich mehr,
als es vor P5c aussah, und es ist trotzdem nicht dasselbe:

| | hier gemessen gegen | dort |
|---|---|---|
| Anmeldung der Konsolenrolle über den Socket | eine von Hand geschriebene `pg_hba.conf` | die, die `Pg\Hba::ensure()` schreibt |
| Zeitlimit und Kürzung | Tabellen mit drei Zeilen | echte Kundendaten |
| Filterung von `information_schema` | zwei frische Abonnements | die Bestände aus P5 und P5b |
| Reste des Aufräumlaufs | ein leerer Server | die Reste, die `docs/37 §8` benennt |
| der Weg zum Agenten | ein Attrappensocket | `srvpanel-agentd` unter systemd |

Die letzte Zeile ist die, die diesen Lauf trägt: **In diesem Container gibt es
keinen Agenten.** Alles, was `App\Support\Databases\Console` tut, ist hier gegen
eine Attrappe geprüft. Ob die zehn Operationen unter dem echten Daemon
überhaupt erreichbar sind, hat noch nie jemand gemessen.

> **Ein Aufruf gegen eine Attrappe prüft den Aufrufer und nicht die Leitung.**

---

## 2. Was man braucht

- **`cloudsrv24` mit einer Fassung, die Schritt 3 enthält** — siehe §3.
- **Zwei Abonnements**, A und B, jedes mit **einer MariaDB- und einer
  PostgreSQL-Datenbank**. Der Lauf legt sie **nicht** an: Eine Kundennummer ist
  auf Dauer verbraucht und ein Systembenutzer erst recht (`docs/35`). B braucht
  nichts weiter als seine zwei Datenbanken — es ist die fremde Seite in Punkt 9.
- **Das Kundenkonto von A**, also die Mailadresse, mit der es sich anmeldet.
  `forAccount()` braucht es, und ohne es klammert die Mandantenklammer jede
  Abfrage auf nichts.
- **Zwei Terminals.** Punkt 4 misst etwas, das eine halbe Sekunde lebt; das geht
  nicht nacheinander.
- Etwa anderthalb Stunden.
- Die Bereitschaft, **jede Ausgabe zu schicken — auch die, die richtig
  aussieht.** Der teuerste Fehler von P4 hat `1 fällig, 1 bestellt` gemeldet,
  also genau die Zahl, die das Kriterium verlangte, und das Falsche getan.

> **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.**

**Vier Werte stehen unten nicht ausgeschrieben, weil sie es nicht können:** das
Präfix von A (`x…`, gewürfelt, `docs/38 §4`), das Präfix von B, der
Systembenutzer von A (`p…`) und die Mailadresse des Kontos. Punkt 0 holt alle
vier. **Alles andere ist wörtlich einzugeben** — auch die Fassung `16` im
Clusterpfad, falls dort eine andere steht.

### Zwei Fallen in `tinker`, die einen Lauf stumm scheitern lassen

Beide sind in P4 passiert, beide **ohne eine einzige Fehlermeldung**
(`docs/34 §10`, der Lauf vom 7. August 2026):

> **`HOME=/tmp` davor**, sonst darf psysh seine Einrichtung nicht schreiben und
> führt den Code gar nicht aus. **Und die Mandantenklammer als erste Zeile**,
> sonst liefert jede Abfrage eine leere Menge — `Database` trägt den globalen
> Filter aus `BelongsToSubscription`.

**Die Aufrufe unten stehen in einfachen Anführungszeichen und benutzen in PHP
doppelte.** `docs/35` macht es umgekehrt und muss deshalb jedes `$` mit einem
Rückstrich versehen; bei einem Aufruf mit fünf Variablen ist das eine
Fehlerquelle mehr, als der Lauf braucht.

**Und jeder Aufruf gibt aus, was er getan hat.** Ein `tinker`-Aufruf, der nichts
druckt, ist von einem, der nicht gelaufen ist, nicht zu unterscheiden.

### Und eine dritte, die dieser Lauf gefunden hat

**`srvpanel tinker` läuft nicht als root.** Der Wrapper gibt die Rechte ab:
`setpriv --reuid=srvpanel --regid=srvpanel --init-groups`. Eine Hilfsdatei unter
`/root` — dort `0700` — kann er deshalb nicht lesen, und die Meldung
(„Failed opening required") sieht nach einem Tippfehler im Pfad aus. Sie gehört
nach `/tmp`, und die Leseprobe davor ist eine Zeile wert:

```bash
setpriv --reuid=srvpanel --regid=srvpanel --init-groups head -1 /tmp/p5c/namen.php
```

> **Ein Werkzeug, das Rechte abgibt, liest die Datei nicht mehr, die man ihm als
> root hinlegt.**

---

## 3. Welche Fassung — und warum das hier steht

| Fassung | was dazukam | welche Punkte |
|---|---|---|
| `v0.5.2-rc.7` | der Stand **vor** P5c | **1** — und nur der |
| Schritte 0–3 (PR #107) | `Pg\Console`, `Db\Console`, zehn Operationen, `App\Support\Databases\Console`, fünf Routen, `DatabasePolicy::console()` | 2–15 |

**Punkt 1 gehört vor die Auslieferung und nicht hinter sie.** Er ist die einzige
Frage dieses Laufs, deren Antwort sich durch das Einspielen ändert — nicht die
Lage auf dem Server, aber die Sicht des Panels darauf. Wer ihn nach dem Update
fährt, misst dasselbe und liest es unter einer Oberfläche, die den fraglichen
Zugang bereits versteckt.

Alles andere braucht die neue Fassung. Nachgesehen wird sie so:

```bash
dpkg-query -W -f='${Version}\n' srvpanel
readlink /opt/srvpanel/current
HOME=/tmp srvpanel tinker --execute='
  $n = array_filter((new SrvPanel\Agent\Registry(new SrvPanel\Agent\Config))->names(),
                    fn (string $x): bool => str_contains($x, ".console."));
  echo count($n), "\n"; print_r(array_values($n));
'
#    erwartet: 10, und die zehn Namen.
#    DAS IST DIE PRÜFUNG, NICHT DIE NUMMER OBEN. Eine Fassungsnummer sagt,
#    welches Paket installiert wurde; diese Abfrage sagt, ob die Operationen im
#    Agenten registriert sind — und genau das war in dieser Stufe zweimal die
#    Frage (docs/46 §20.5, §20.7).
#
#    HIER STAND EIN `grep -c "pg\.console\.tables"` AUF Registry.php, UND DER
#    KANN NUR 0 FINDEN. Die Registratur trägt Objekte (`new PgConsoleTables`),
#    der Name steht in der Op-Klasse. Der Ausdruck hätte bei einer kaputten
#    Fassung dasselbe gesagt wie bei einer heilen. Gefunden am 12. August 2026
#    im Lauf selbst.
#
#    > Ein Wächter, der in der falschen Datei sucht, ist kein Wächter — er ist
#    > eine Zeile, die immer dasselbe sagt.
```

> **Eine Fassungsnummer ist eine Zusage, ein registrierter Name ist eine
> Messung.**

---

## 4. Der Lauf

```
# 0  DIE NAMEN, MIT DENEN GEARBEITET WIRD
mariadb srvpanel -e "
SELECT s.id, s.name, s.system_user, s.status, s.customer_id, su.db_prefix
  FROM subscriptions s
  LEFT JOIN system_users su ON CONCAT('p', su.number) = s.system_user
 ORDER BY s.id;
-- HIER STAND `ON su.subscription_id = s.id`, UND DIE SPALTE GIBT ES NICHT.
-- `system_users` führt `number`, `subscription` (eine Abschrift des Namens,
-- kein Fremdschlüssel) und `db_prefix`; die Verbindung läuft über
-- `subscriptions.system_user`, das `p<number>` heisst (docs/35).
SELECT d.id, d.name, d.engine, d.subscription_id FROM databases d ORDER BY d.id;
SELECT a.id, a.email, a.type, a.customer_id FROM accounts a ORDER BY a.id;
"
#    BELEG: diese drei Ausgaben ins Protokoll. Aus ihnen kommen die einzigen
#           Werte, die unten einzusetzen sind:
#             <APG>  <AMY> — die beiden Datenbanken von Abo A
#             <BPG>  <BMY> — die beiden von Abo B
#             <APRE>       — die db_prefix (x…) von Abo A
#             <APNR>       — die number von A, als p<number>
#             <KUNDE>      — die Mailadresse des Kontos zu Abo A
#           Das Präfix von B wird NICHT gebraucht: Punkt 9 richtet Abo A auf
#           eine Datenbank von B und nicht umgekehrt.

# 1  RISIKO 8 — UND ER WIRD GEFAHREN, BEVOR DIE NEUE FASSUNG KOMMT
sudo -u postgres psql -Atc \
  "SELECT rolname FROM pg_roles WHERE rolname ~ '^x[0-9a-f]{16}_c[0-9a-f]{8}$'"
mariadb -e \
  "SELECT user, host FROM mysql.user WHERE user REGEXP '^p[0-9]+_c[0-9a-f]{8}$'"
#    erwartet: BEIDE LEER.
#
#    IST EINE DAVON NICHT LEER, IST HIER SCHLUSS. Das ist ein Kundenzugang und
#    kein Rest, und die Erweiterung auf [rc] muss anders aussehen, BEVOR die
#    Fassung auf diesen Server kommt. Was ihm sonst passiert, steht in §1: Er
#    verschwindet aus der Zugangsliste seiner Datenbank (Pg\Owner::roles()
#    überspringt ihn), ist im Panel weder zu berechtigen noch zu entfernen, und
#    `srvpanel db` fordert dazu auf, ihn wegzuräumen.
#
#    UND DIE ZWEITE, LOSE ABFRAGE GEHÖRT DAZU — sie wird GELESEN, nicht gezählt:
sudo -u postgres psql -Atc \
  "SELECT rolname FROM pg_roles WHERE rolname LIKE '%\_c%' ORDER BY 1"
mariadb -e "SELECT user, host FROM mysql.user WHERE user LIKE '%\_c%' ORDER BY 1"
#    Sie trifft mehr, als sie soll — in einem nackten PostgreSQL 16 unter
#    anderem pg_checkpoint und pg_create_subscription (gemessen am 12. August
#    2026, docs/46 §20.4). Genau deshalb steht sie hier: Eine strenge Abfrage,
#    deren Anker falsch ist, ist leer und sieht aus wie eine Entwarnung. In
#    MariaDB ist `$` in REGEXP ausserdem PCRE und passt auch vor einem
#    abschliessenden Zeilenumbruch — dieselbe Falle wie die neun Muster aus P3.
#    BELEG: BEIDE LISTEN ins Protokoll, nicht ihre Länge.

#    ---- AB HIER GILT DIE NEUE FASSUNG. srvpanel update, dann §3 nachsehen. ----

# 2  DER AUSGANGSZUSTAND, GEMESSEN UND NICHT ANGENOMMEN
sudo -u postgres psql -Atc \
  "SELECT rolname FROM pg_roles WHERE rolname ~ '^x[0-9a-f]{16}_[rc][0-9a-f]{8}$'"
mariadb -e \
  "SELECT user FROM mysql.user WHERE user REGEXP '^p[0-9]+_[rc][0-9a-f]{8}$'"
srvpanel db
#    erwartet: beide leer, und „Nichts liegengeblieben."
#    BELEG: die Ausgabe von `srvpanel db` ganz. Punkt 15 vergleicht gegen sie.

# 3  DIE LEITUNG STEHT   ← die Frage, die dieser Container nicht beantworten kann
HOME=/tmp srvpanel tinker --execute='
  $konto = App\Models\Account::where("email", "<KUNDE>")->firstOrFail();
  app(App\Support\Tenancy\Tenancy::class)->forAccount($konto);
  $pg = App\Models\Database::where("name", "<APG>")->firstOrFail();
  echo $pg->id, " ", $pg->engine->value, " ", $pg->subscription->name, "\n";
  print_r(app(App\Support\Databases\Console::class)->tables($pg));
'
#    erwartet: die Tabellen der Datenbank, jede mit schema, name, kind, rows,
#              bytes, key.
#    KOMMT HIER „Zu dieser Datenbank gibt es kein Abonnement mehr.", ist die
#    Mandantenklammer nicht gesetzt oder das Konto falsch — nicht die Konsole.
#    KOMMT EINE LEERE ZEILE STATT EINER AUSGABE, hat psysh nicht geschrieben
#    werden dürfen: HOME=/tmp fehlt.
#    BELEG: die Ausgabe. Sie ist der erste Beleg überhaupt dafür, dass die zehn
#           Operationen unter dem echten Daemon erreichbar sind — in diesem
#           Container gibt es keinen (§1).

# 4  DER BEFRISTETE ZUGANG ENTSTEHT UND IST DANACH FORT   ← Kriterium 1
#    IM ERSTEN TERMINAL, VOR dem Aufruf starten:
while :; do
  sudo -u postgres psql -Atc \
    "SELECT rolname FROM pg_roles WHERE rolname ~ '_c[0-9a-f]{8}$'"
done | grep --line-buffered . | tee /root/p5c-rolle.txt
#    IM ZWEITEN TERMINAL den Aufruf aus Punkt 3 zwanzigmal wiederholen:
#      for i in $(seq 20); do <der Aufruf aus Punkt 3> >/dev/null; done
#    Dann die Schleife abbrechen.
#    ZWANZIGMAL UND NICHT EINMAL: Eine Konsolenabfrage ist in gut fünfzig
#    Millisekunden fertig, und ein `psql` je Schleifendurchlauf braucht selbst
#    zwanzig. Ein einzelner Aufruf ist ein Fenster, das der Beobachter mit
#    einiger Wahrscheinlichkeit verpasst — und dann steht in der Datei nichts,
#    und das sieht aus wie ein Befund. Wer sicher gehen will, nimmt statt der
#    Tabellenliste den Aufruf aus Punkt 10: Der lebt fünf Sekunden.
#    erwartet: /root/p5c-rolle.txt enthält Namen der Form <APRE>_c<8 hex> — je
#              Aufruf einen anderen, immer dieselbe Form, und in KEINER Zeile
#              zwei zugleich.
#    BELEG: DER NAME. „Es ist einer entstanden" ist keine Antwort auf ein
#           Kriterium, das den Namen verlangt.
#
#    OHNE DIESEN PUNKT IST KRITERIUM 1 NICHT GEFAHREN, und zwar auf eine Art,
#    die niemandem auffällt: Die Abfrage nach Resten (Punkt 2, Punkt 15) ist
#    auch dann leer, wenn nie eine Konsole lief. Dieselbe Falle wie
#    `docs/36 §17` Kriterium 5.
#
#    > Eine Abfrage, die nach Resten sucht, belegt nicht, dass es etwas gab.
#
#    Dasselbe für MariaDB, mit derselben Schleife:
while :; do mariadb -Ns -e \
  "SELECT user FROM mysql.user WHERE user REGEXP '_c[0-9a-f]{8}$'"; done \
  | grep --line-buffered . | tee /root/p5c-benutzer.txt
#    erwartet: <APNR>_c<8 hex>.
#    Und danach BEIDE Dateien gegen Punkt 2 prüfen: die Rolle ist wieder fort.

# 5  DIE STRUKTUR EINER TABELLE
#    Vorher, in BEIDEN Datenbanken von A — und in PostgreSQL mit
#      SET ROLE <präfix>_owner;
#    vor jedem CREATE und INSERT. Die Eigentümerrolle besitzt das Schema public
#    (Pg\Owner: ALTER SCHEMA public OWNER TO …), und Console::TABLES zeigt nur,
#    was has_table_privilege(…, 'SELECT') bejaht: Eine als postgres angelegte
#    Tabelle wäre für den Kunden UNSICHTBAR, und der Lauf sähe aus wie ein
#    Fehler der Konsole. Für MariaDB entfällt das — der befristete Benutzer
#    bekommt GRANT ALL auf die ganze Datenbank.
#      CREATE TABLE probe (id int primary key, leer text, nichts text,
#                          tab text, umbruch text);
#    PostgreSQL:
#      INSERT INTO probe VALUES (1, '', NULL, e'a\tb', e'z1\nz2');
#    MariaDB (kennt kein e'…', wertet Rückstriche aber ohnehin aus):
#      INSERT INTO probe VALUES (1, '', NULL, 'a\tb', 'z1\nz2');
#    NACHSEHEN, DASS ES WIRKLICH EIN TABULATOR IST, bevor der Lauf weitergeht:
#      SELECT id, length(tab), length(umbruch) FROM probe;   → 1, 3, 5
#    Ein Wert, in dem „\t" als zwei Zeichen steht, macht Punkt 6 grün, ohne dass
#    er etwas geprüft hätte.
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->forAccount(
    App\Models\Account::where("email", "<KUNDE>")->firstOrFail());
  $k = app(App\Support\Databases\Console::class);
  foreach (["<APG>", "<AMY>"] as $name) {
    $d = App\Models\Database::where("name", $name)->firstOrFail();
    echo $name, ":\n";
    print_r($k->columns($d, "probe"));
  }
'
#    erwartet: fünf Spalten, id mit key=true, die vier anderen mit key=false,
#              binary durchweg false.
#    BELEG: beide Ausgaben.

# 6  DIE VIER WERTE   ← Kriterium 2, die Hälfte, die ohne Oberfläche geht
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->forAccount(
    App\Models\Account::where("email", "<KUNDE>")->firstOrFail());
  $k = app(App\Support\Databases\Console::class);
  foreach (["<APG>", "<AMY>"] as $name) {
    $d = App\Models\Database::where("name", $name)->firstOrFail();
    echo $name, ":\n";
    var_export($k->rows($d, "probe", "id"));
    echo "\n";
  }
'
#    erwartet, und die vier gehören EINZELN ins Protokoll:
#      leer    => ""        (leere Zeichenkette)
#      nichts  => NULL      (und nicht "")
#      tab     => "a\tb"    (EIN Wert mit einem Tabulator darin)
#      umbruch => "z1\nz2"  (EIN Wert mit einem Zeilenumbruch darin)
#    var_export und nicht print_r: print_r zeigt NULL und "" beide als nichts,
#    und dann ist genau der Unterschied unsichtbar, um den es geht.
#
#    > „Sonderzeichen kommen an" wäre erfüllt, solange irgendetwas ankommt.
#
#    UND: id kommt als "1" und nicht als 1. Das ist richtig und nicht kaputt —
#    jede Spalte ausser einer binären wird nach text gecastet, damit die
#    Kürzung auf jedem Typ gilt (docs/46 §20.3). Ein Protokoll, das hier einen
#    Befund meldet, meldet den Plan.
#
#    DIE MARIADB-HÄLFTE IST DIE, AN DER ES HÄNGT (docs/46 §8.1, N1/N2): Ohne
#    --raw stünde in tab der Wert "a\\tb" — gültiges JSON, vier Zeichen statt
#    drei, kein Fehler nirgends.

# 7  DIE KÜRZUNG UND DIE ZELLE EINZELN
#    In beiden Datenbanken:
#      CREATE TABLE lang (id int primary key, txt text, leer text, notiz text);
#      INSERT INTO lang VALUES (1, repeat('a', 5000), NULL, 'unberührt');
#    (MariaDB: REPEAT('a', 5000).)
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->forAccount(
    App\Models\Account::where("email", "<KUNDE>")->firstOrFail());
  $k = app(App\Support\Databases\Console::class);
  foreach (["<APG>", "<AMY>"] as $name) {
    $d = App\Models\Database::where("name", $name)->firstOrFail();
    $seite = $k->rows($d, "lang", "id");
    echo $name, " zelle=", strlen($seite["rows"][0]["txt"]),
         " gekuerzt=", implode(",", $seite["truncated"]), "\n";
    $ganz = $k->cell($d, "lang", ["id" => "1"], "txt");
    echo $name, " ganz=", strlen((string) $ganz["value"]),
         " gekuerzt=", var_export($ganz["truncated"], true), "\n";
  }
'
#    erwartet je System ZWEI Zeilen:
#      zelle=512  gekuerzt=txt
#      ganz=5000  gekuerzt=false
#    BELEG: beide Zahlen. 512 allein sagt nichts — eine Kürzung, die sich nicht
#    meldet, sieht aus wie ein kurzer Wert.

# 8  DER FILTER, UND ZWAR MIT EINEM PROZENTZEICHEN IM WERT
#      INSERT INTO probe VALUES (2, 'x', 'y', '100%', 'z');
#      INSERT INTO probe VALUES (3, 'x', 'y', '100 Prozent', 'z');
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->forAccount(
    App\Models\Account::where("email", "<KUNDE>")->firstOrFail());
  $k = app(App\Support\Databases\Console::class);
  foreach (["<APG>", "<AMY>"] as $name) {
    $d = App\Models\Database::where("name", $name)->firstOrFail();
    $s = $k->rows($d, "probe", "id", false, 0,
                  ["column" => "tab", "operator" => "contains", "value" => "100%"]);
    echo $name, " treffer=", count($s["rows"]),
         " id=", implode(",", array_column($s["rows"], "id")), "\n";
  }
'
#    erwartet je System: treffer=1 id=2.
#    KOMMEN ZWEI, ist das Prozentzeichen als Platzhalter durchgekommen — der
#    Filter benutzt dann LIKE statt einer Enthaltensprüfung, und ein Kunde, der
#    nach „100%" sucht, bekommt alles, was mit 100 anfängt.
#    Die drei Operatoren sind drei: equals, contains, empty. Ein vierter ist ein
#    Befund und kein Bonus.

# 9  DIE FREMDE DATENBANK   ← Kriterium 3, und hier stimmt der Plan nicht
#
#    ZWEI WÄNDE, UND SIE WERDEN GETRENNT GEMESSEN (Pg\Console::within()):
#      (1) Names::belongsTo() — UNSERE Prüfung im Agenten.
#      (2) die Rechte der befristeten Rolle — die Meldung kommt vom SERVER.
#
#    Wand 1, über den Weg, den die Anwendung geht:
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  $b = App\Models\Database::where("name", "<BPG>")->firstOrFail();
  try {
    print_r(app(App\Support\Databases\Console::class)->tables($b));
  } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
'
#    HIER STEHT EIN BEFUND, UND ER GEHÖRT INS PROTOKOLL:
#    Dieser Aufruf GELINGT, und er soll es. `docs/46 §15` Punkt 3 sagt „die
#    Konsole von Abo A auf eine Tabelle aus Abo B richten, der Weg dafür ist die
#    Adresse" — das ist über diesen Weg nicht fahrbar. Das Präfix reist mit der
#    DATENBANK und nicht mit dem Aufrufer: App\Support\Databases\Console holt es
#    aus $database->subscription. Wer die Mandantenklammer abschaltet, richtet
#    damit nicht die Konsole von A auf B, sondern die von B auf B.
#
#    > Eine Wand, die man nur erreicht, indem man die davor abschaltet, wird
#    > durch das Abschalten nicht erreicht — sie wird umgangen.
#
#    Wand 1 wird deshalb dort gemessen, wo sie sitzt, mit einer erfundenen
#    Nutzlast am Agenten vorbei an der Anwendung:
HOME=/tmp srvpanel tinker --execute='
  try {
    print_r(app(SrvPanel\Agent\Client::class)->call("pg.console.tables", [
      "prefix" => "<APRE>", "database" => "<BPG>", "schema" => "public",
    ]));
  } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
'
#    erwartet: „Diese Datenbank gehört nicht zu diesem Abonnement."
#    Dasselbe mit db.console.tables, "<AMY>"-Präfix <APNR> und "<BMY>".
#
#    UND WAND 2, DIE EINZIGE, DIE UNS NICHT GEHÖRT. Sie ist über keine
#    Nutzlast erreichbar — mit dem Präfix von B entsteht eine Rolle, die zu B
#    gehört, und dann ist der Zugriff rechtmässig. Gemessen wird sie deshalb an
#    einer Rolle, die den befristeten Zugang NACHBAUT, Zeile für Zeile wie in
#    Pg\Ephemeral::with():
sudo -u postgres psql -c \
  "CREATE ROLE p5c_probe LOGIN PASSWORD 'p5c' IN ROLE srvpanel_restore, <APRE>_owner"
sudo -u postgres psql -c "GRANT CONNECT ON DATABASE <APG> TO p5c_probe"
PGPASSWORD=p5c psql -h /var/run/postgresql -U p5c_probe -d <APG> -Atc "SELECT 1"
PGPASSWORD=p5c psql -h /var/run/postgresql -U p5c_probe -d <BPG> -Atc "SELECT 1"
#    erwartet: die erste Zeile gibt 1, die zweite meldet
#              FATAL: permission denied for database "<BPG>"
#    DIE MELDUNG WÖRTLICH INS PROTOKOLL. „Scheitert" wäre auch ein Tippfehler im
#    Datenbanknamen (docs/36 §22.3m) — und die erste Zeile ist der Beleg, dass es
#    keiner war.
#
#    ÜBER DEN SOCKET UND NICHT ÜBER 127.0.0.1, denn genau so verbindet der Agent
#    (Pg\Session::linesAs() ruft psql mit -h /var/run/postgresql). Die
#    Mitgliedschaft in srvpanel_restore ist die, die die verwaltete Zeile ganz
#    oben in pg_hba.conf hereinlässt; über TCP griffe eine andere Regel, und dann
#    prüfte dieser Punkt einen Weg, den niemand benutzt.
#
#    > Eine Gegenprobe über einen anderen Weg als den benutzten prüft den
#    > falschen Weg.  (docs/44)
sudo -u postgres psql -c "DROP OWNED BY p5c_probe"
sudo -u postgres psql -c "DROP ROLE p5c_probe"
#    BEIDE ZEILEN, IN DIESER REIHENFOLGE. Hier stand nur die zweite, und sie
#    scheitert: „role cannot be dropped because some objects depend on it —
#    privileges for database …". Pg\Ephemeral::with() macht im finally genau
#    dieses DROP OWNED BY davor, und der Kommentar dort erklärt den Fehler.
#
#    > Ein Nachbau, der eine Zeile weglässt, beweist ihre Notwendigkeit besser
#    > als jeder Test.
#
#    Für MariaDB dasselbe, ebenfalls nachgebaut wie Db\Ephemeral::with() —
#    ein Benutzer mit GRANT ALL auf GENAU EINE Datenbank:
mariadb -e "CREATE USER 'p5c_probe'@'localhost' IDENTIFIED BY 'p5c';
            GRANT ALL PRIVILEGES ON \`<AMY>\`.* TO 'p5c_probe'@'localhost'"
mariadb --protocol=socket -u p5c_probe -pp5c -e "SHOW TABLES FROM \`<AMY>\`"
mariadb --protocol=socket -u p5c_probe -pp5c -e "SHOW TABLES FROM \`<BMY>\`"
#    --protocol=socket, weil der Agent so verbindet (Db\Session::CLIENT). Steht
#    in einer my.cnf ein Host, ginge der Klient sonst über TCP — und dann prüfte
#    diese Gegenprobe einen Weg, den niemand benutzt.
#    erwartet: die erste Zeile listet die Tabellen, die zweite meldet
#              ERROR 1044 (42000): Access denied for user 'p5c_probe'@'localhost'
#              to database '<BMY>'
mariadb -e "DROP USER 'p5c_probe'@'localhost'"
#
#    UND BEIDE PROBEN WERDEN WIEDER ABGERÄUMT — sie tragen keinen Namen, den
#    isEphemeral() erkennt, also fällt keine von beiden je einem Aufräumlauf auf.
#    Ein Zugang, den niemand meldet, ist der, den man selbst wegräumen muss.
#
#    OHNE DIESEN TEIL IST KRITERIUM 3 NICHT GEFAHREN: Mit Wand 1 allein wäre
#    belegt, dass unsere Prüfung greift, und genau das ist nicht die Frage.

# 10 DAS ZEITLIMIT   ← Kriterium 4
#    In beiden Datenbanken eine Tabelle mit einigen Millionen Zeilen:
#      CREATE TABLE viel AS
#        SELECT g AS id, md5(g::text) AS wert FROM generate_series(1, 8000000) g;
#    (MariaDB: eine Schleife oder ein INSERT … SELECT über eine Hilfstabelle;
#     die Zeilenzahl ist beliebig, solange die Sortierung länger als 5 s dauert.)
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->forAccount(
    App\Models\Account::where("email", "<KUNDE>")->firstOrFail());
  $k = app(App\Support\Databases\Console::class);
  foreach (["<APG>", "<AMY>"] as $name) {
    $d = App\Models\Database::where("name", $name)->firstOrFail();
    $t0 = microtime(true);
    try { $k->rows($d, "viel", "wert", true, 7000000); echo $name, " KEIN ABBRUCH\n"; }
    catch (Throwable $e) { echo $name, " ", $e->getMessage(), "\n"; }
    echo $name, " dauer=", round(microtime(true) - $t0, 1), "s\n";
  }
'
#    erwartet: Abbruch nach rund 5 s, und die Meldung nennt den Grund —
#              „canceling statement due to statement timeout" bzw. bei MariaDB
#              „Query execution was interrupted (max_statement_time exceeded)".
#    „KEIN ABBRUCH" steht im Aufruf, weil ein Erfolg hier sonst wie ein
#    schneller Server aussieht: Die Tabelle war zu klein, und dann ist der Punkt
#    nicht gefahren. In dem Fall die Zeilenzahl erhöhen und wiederholen.
#    BELEG: DIE MELDUNG UND DIE DAUER. „Fehlgeschlagen" ist eine Aussage über
#           uns und nicht über den Server (docs/36 §17, Kriterium 6).
sudo -u postgres psql -c \
  "SELECT pid, state, left(query, 70) FROM pg_stat_activity
    WHERE pid <> pg_backend_pid()
      AND usename ~ '^x[0-9a-f]{16}_c[0-9a-f]{8}$'"
#    erwartet: keine Zeile — die abgebrochene Abfrage rechnet NICHT weiter (M12).
#
#    NACH DER KENNUNG UND NICHT NACH DEM ABFRAGETEXT. Hier stand
#      SELECT count(*) … WHERE state='active' AND query LIKE '%viel%'
#    und der Text DIESER Abfrage enthält `viel` — sie stand in ihrer eigenen
#    Ergebnismenge und zählte sich mit. Herausgekommen ist 2, wo 0 erwartet war.
#
#    > Eine Frage an den Bestand, die sich selbst enthält, zählt sich mit.
#
#    Der Ausdruck oben kann sich nicht selbst treffen (`postgres` passt nicht
#    auf das Muster) und auch keinen Hintergrundprozess.

# 11 OHNE SCHLÜSSEL   ← Kriterium 5, die Hälfte, die ohne Oberfläche geht
#      CREATE TABLE ohne_schluessel (a int, b text);
#      INSERT INTO ohne_schluessel VALUES (1,'x'), (1,'x');
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->forAccount(
    App\Models\Account::where("email", "<KUNDE>")->firstOrFail());
  $k = app(App\Support\Databases\Console::class);
  foreach (["<APG>", "<AMY>"] as $name) {
    $d = App\Models\Database::where("name", $name)->firstOrFail();
    foreach ($k->tables($d) as $t) {
      if ($t["name"] === "ohne_schluessel") { echo $name, " key=", var_export($t["key"], true), "\n"; }
    }
    try { $k->write($d, "ohne_schluessel", "update", ["a" => "1"], ["b" => "y"]); }
    catch (Throwable $e) { echo $name, " ", $e->getMessage(), "\n"; }
  }
'
#    erwartet: key=false, und die Meldung nennt das Wort „Primärschlüssel".
#    Die Zeilen sind danach unverändert — nachsehen:
#      SELECT a, b FROM ohne_schluessel;

# 12 GENAU EINE ZEILE   ← Kriterium 6, erste Hälfte
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->forAccount(
    App\Models\Account::where("email", "<KUNDE>")->firstOrFail());
  $k = app(App\Support\Databases\Console::class);
  foreach (["<APG>", "<AMY>"] as $name) {
    $d = App\Models\Database::where("name", $name)->firstOrFail();
    print_r($k->write($d, "probe", "update", ["id" => "1"], ["leer" => "geaendert"]));
    try { $k->write($d, "probe", "update", ["id" => "999999"], ["leer" => "nein"]); }
    catch (Throwable $e) { echo $name, " ", $e->getMessage(), "\n"; }
  }
'
#    erwartet: der erste Aufruf meldet affected=1; der zweite SCHEITERT und
#              nennt die Zahl der getroffenen Zeilen (0).
#    Danach in beiden Datenbanken:
#      SELECT id, leer FROM probe ORDER BY id;
#    erwartet: NUR Zeile 1 ist geändert. Beide Seiten gezählt, vorher und
#              nachher — der Fall „trifft mehr als eine" gehört in den vollen
#              Lauf (§6).

# 13 DIE UNBERÜHRTE SPALTE   ← Kriterium 6, zweite Hälfte
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->forAccount(
    App\Models\Account::where("email", "<KUNDE>")->firstOrFail());
  $k = app(App\Support\Databases\Console::class);
  foreach (["<APG>", "<AMY>"] as $name) {
    $d = App\Models\Database::where("name", $name)->firstOrFail();
    print_r($k->write($d, "lang", "update", ["id" => "1"], ["notiz" => "angefasst"]));
  }
'
#    Danach in BEIDEN Datenbanken:
#      SELECT length(txt), leer IS NULL, notiz FROM lang WHERE id = 1;
#    erwartet: 5000, wahr, „angefasst".
#
#    OHNE DIESEN PUNKT IST KRITERIUM 6 HALB GEFAHREN. Er ist der einzige des
#    Laufs, dessen Fehlschlag an der Zeile NICHT ZU SEHEN IST: Sie steht da, sie
#    sieht richtig aus, und 4488 Zeichen sind fort. Und ein NULL, das als leere
#    Zeichenkette zurückgeschrieben wird, sieht in jeder Anzeige gleich aus.
#
#    > Der gefährlichste Datenverlust ist der, nach dem die Zeile noch da ist.

# 14 UND EINE ZEILE ANLEGEN UND LÖSCHEN
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->forAccount(
    App\Models\Account::where("email", "<KUNDE>")->firstOrFail());
  $k = app(App\Support\Databases\Console::class);
  foreach (["<APG>", "<AMY>"] as $name) {
    $d = App\Models\Database::where("name", $name)->firstOrFail();
    print_r($k->write($d, "probe", "insert", [],
      ["id" => "42", "leer" => "", "nichts" => null, "tab" => "t", "umbruch" => "u"]));
    print_r($k->write($d, "probe", "delete", ["id" => "42"], []));
  }
'
#    erwartet: zweimal affected=1 je System.
#    UND DIE GEGENPROBE AN DER ZEILE, VOR DEM LÖSCHEN — sie ist der Punkt:
#      SELECT id, leer = '' AS ist_leer, nichts IS NULL FROM probe WHERE id = 42;
#    erwartet: wahr und wahr. „null bleibt null" ist die Regel aus §10.1, und
#    sie geht durch die Anwendung, den Agenten und die Anweisung; jede der drei
#    Stellen könnte daraus eine leere Zeichenkette machen.

# 15 NICHTS BLEIBT LIEGEN
sudo -u postgres psql -Atc \
  "SELECT rolname FROM pg_roles WHERE rolname ~ '^x[0-9a-f]{16}_[rc][0-9a-f]{8}$'"
mariadb -e \
  "SELECT user FROM mysql.user WHERE user REGEXP '^p[0-9]+_[rc][0-9a-f]{8}$'"
#    UND die Proben aus Punkt 9 — sie tragen KEINEN Namen, den isEphemeral()
#    erkennt, also meldet sie kein Aufräumlauf. Wer sie stehen lässt, hinterlässt
#    einen Zugang, den niemand mehr findet.
sudo -u postgres psql -Atc "SELECT rolname FROM pg_roles WHERE rolname LIKE '%p5c_probe%'"
mariadb -Ns -e "SELECT user FROM mysql.user WHERE user LIKE '%p5c_probe%'"
srvpanel db
#    erwartet: alle vier leer, und die Ausgabe von `srvpanel db` WORTGLEICH die
#              aus Punkt 2.
#    „Nichts liegengeblieben." steht dort NICHT — den Satz gibt es seit
#    `docs/45 §5` nicht mehr. Schweigen IST die Entwarnung; gemeldet wird nur,
#    was stehengeblieben ist.
#    Nach fünfzehn Punkten mit gut zwei Dutzend Konsolenaufrufen ist das die
#    Zusage aus `docs/46 §19`: Die Konsole legt nichts an, was sie überlebt.
#    Und Punkt 4 ist der Beleg, dass überhaupt etwas anzulegen war.

# 16 DAS PROTOKOLL — DIE MESSUNG, DIE EIN NEIN ERWARTET
mariadb srvpanel -e \
  "SELECT COUNT(*) FROM audit_events WHERE action LIKE '%console%'"
#    erwartet: 0.
#    Das ist kein Fehlschlag, sondern der festgehaltene Ausgangszustand:
#    Kriterium 7 gehört zu Schritt 7 und ist in dieser Fassung NICHT GEBAUT.
#    Er steht hier, damit später niemand annimmt, er sei geprüft worden — die
#    Zahl 0 ist der Unterschied zwischen „nicht gebaut" und „vergessen".
```

---

## 5. Was zurückkommen soll

**Jede Ausgabe, nicht die Zusammenfassung.** Was dieser Lauf misst, steht in
Werten, die richtig aussehen können und es nicht sind — ein `""`, das ein `NULL`
war, ein `"a\\tb"`, das ein `"a\tb"` war, eine Rolle, die es nie gab.

Sechs Dinge gehören ausdrücklich dazu, weil sie sonst untergehen:

1. **Beide Listen aus Punkt 1**, die strenge und die lose. Die strenge allein ist
   auch dann leer, wenn ihr Anker falsch ist.
2. **Der Rollenname aus Punkt 4**, wörtlich, und der Benutzername daneben.
   Kriterium 1 fragt nach einem Namen und nicht nach einer Anzahl.
3. **Die vier Werte aus Punkt 6, einzeln, als `var_export`.** Für beide Systeme.
   „Stimmt" ist hier keine Antwort — der Fehler aus N1 sieht in einer Zählung wie
   ein Erfolg aus (fünf Spalten, fünf Werte).
4. **Beide Zahlenpaare aus Punkt 7** (512/`txt` und 5000/`false`). Eine Kürzung,
   die sich nicht meldet, ist von einem kurzen Wert nicht zu unterscheiden.
5. **Die drei Meldungen aus Punkt 9**, wörtlich — die unsere und die zwei der
   Server. Die Herkunft ist der Beleg, nicht der Fehlschlag.
6. **Die drei Zahlen aus Punkt 13** (`5000`, `wahr`, `angefasst`), für beide
   Systeme. Das ist der Punkt, dessen Fehlschlag man an der Zeile nicht sieht.

Und wenn ein Punkt anders ausgeht als beschrieben: **die Ausgabe schicken und
nicht nacharbeiten.** Ein Lauf, der unterwegs repariert wird, misst den Zustand
nach der Reparatur.

**Punkt 9 trägt einen Befund gegen den Plan, und der ist schon eingearbeitet.**
`docs/46 §15` Punkt 3 verlangte, für den Beleg der Serverwand die
Mandantenklammer abzuschalten und die Adresse einer fremden Datenbank
aufzurufen — das misst etwas anderes, als es sagt, weil das Präfix mit der
Datenbank reist und nicht mit dem Aufrufer. Der Punkt steht dort seit dem
12. August 2026 als drei getrennte Wände, mit der alten Fassung darunter. Was
dieser Lauf dazu beiträgt, ist die Messung; die Korrektur ist keine Frage mehr,
sondern gegen den Quelltext gelesen.

---

## 6. Der Lauf vom 12. August 2026

**Gefahren auf `cloudsrv24`** gegen `0.5.3-rc.1` und, ab Punkt 7, gegen
`0.5.3-rc.2`. Abo A war `cloudlab24.de` (`p1130`, Präfix `x1b311d2b6eedc3aa`),
Abo B `cloudlab24.ipv64.de` (`p1131`) — beide unter demselben Kunden, je eine
frische Datenbank `p5c` in beiden Systemen. **Kein einziges Bild**, wie geplant.

### 6.1 Was gemessen wurde

| Kriterium | gemessen | Wert |
|---|---|---|
| **1** — befristeter Zugang, belegt am Namen | ✓ | 40 Aufrufe, 40 verschiedene Rollen `x1b311d2b6eedc3aa_c<8 hex>`, 40 Benutzer `p1130_c<8 hex>`; danach beide Kataloge leer |
| **2** — die vier Werte unterscheidbar | ✓ (halb) | `''` · `NULL` · `'a⇥b'` (laenge=3) · `'z1⏎z2'` (laenge=5), in **beiden** Systemen. Die andere Hälfte — „in der Oberfläche" — gibt es noch nicht |
| **3** — fremde Datenbank, Meldung des Servers | ✓ | `FATAL: permission denied for database "xb5692b0b484effac_p5c"` / `ERROR 1044 (42000): Access denied … to database 'p1131_p5c'` |
| **4** — Zeitlimit, Grund wörtlich | ✓ | `canceling statement due to statement timeout` (5,2 s) · `Query execution was interrupted (max_statement_time exceeded)` (5,3 s); danach keine laufende Abfrage der Konsolenrolle |
| **5** — ohne Schlüssel nicht änderbar | ✓ | `key=false`, zwei Zeilen lesbar, drei Schreibwege abgewiesen mit dem Wort „Primärschlüssel" |
| **6** — genau eine Zeile, nur geänderte Spalten | ✓ | `affected=1`; `leer=['geaendert','x','x','x']`; die unberührte Spalte behielt 5000 Zeichen und ihr `NULL` |
| **7** — das Protokoll | **nicht gebaut** | `SELECT COUNT(*) … action LIKE '%console%'` → `0`. Gehört zu Schritt 7 des Plans |

**Und zwei Messungen, die der Lauf zusätzlich mitgenommen hat:**

Die **Zeilenschätzung** hat ihren dritten Zustand in beiden Systemen gezeigt, so
wie §9 es vorhergesagt hat: PostgreSQL meldet `rows=NULL` für nie analysierte
Tabellen (`reltuples = -1`), MariaDB die exakte Zahl. Wer nur eine Hälfte prüft,
schreibt im anderen System „0 Zeilen" unter eine Tabelle mit Inhalt.

Die **Kodierung** ist nach Befund 1 in alle drei Richtungen belegt: hinaus (ein
Wert mit `ü` kommt lesbar zurück), hinein als Bedingung (ein Filter auf `üß` und
auf `Köln` findet je genau eine Zeile) und hinein als Wert (ein `INSERT` mit
`'Grüße'` steht danach als fünf Zeichen bzw. sieben Bytes in der Tabelle). Der
ursprüngliche Fehler betraf nur die erste.

**Nichts blieb liegen.** Nach rund sechzig Konsolenaufrufen, achtzig befristeten
Zugängen, sechs Schreibvorgängen und zwei abgebrochenen Abfragen war die Ausgabe
von `srvpanel db` **wortgleich** die aus Punkt 2.

### 6.2 Sechs Befunde, und keinen davon hat ein Test gefunden

Drei betreffen den Code, drei den Abnahmelauf selbst — dasselbe Verhältnis wie
beim Fernzugriff (`docs/45`: zwölf Fehler, fünf davon im Lauf).

#### Befund 1 — Der MariaDB-Klient nannte seinen Zeichensatz nicht

**Gefunden in Punkt 7, an einem einzigen `ü`, und er hat den Lauf
unterbrochen.** `Db\Session` rief `mysql` ohne `--default-character-set`; unter
`LC_ALL=C` aus `Runner::ENVIRONMENT` handelt der Klient dann **latin1** aus, der
Server konvertiert `JSON_OBJECT()` am Ausgang, und aus `ü` wird das einzelne Byte
`FC`. `json_decode()` gibt `null` zurück — für die **ganze Zeile**.

```
env -i LC_ALL=C LANG=C mysql --protocol=socket --batch --skip-column-names \
  -e "SELECT @@character_set_client, @@character_set_results, @@character_set_connection"
→ latin1   latin1   latin1

… derselbe Aufruf auf JSON_OBJECT('n', notiz):
ohne  --default-character-set        : 75 6e 62 65 72  fc     68 72 74
mit   --default-character-set=utf8mb4: 75 6e 62 65 72  c3 bc  68 72 74
```

Das trifft **jede Kundendatenbank mit einem Umlaut**. `Db\Session` ist die
einzige Stelle im Agenten, die `mysql` ruft — die Verbindung war also seit P0
latin1, für jeden MariaDB-Zugriff. Dass es nicht früher auffiel, liegt daran,
dass Bezeichner ASCII sind und ein `mysqldump` sein `SET NAMES utf8mb4` selbst
mitbringt.

**Behoben in `0.5.3-rc.2`** (`docs/46 §8.3`): `Db\Session::CLIENT` trägt die
Angabe, `run()` und `linesAs()` benutzen die Konstante. Der Wächter steht in
`ResultEncodingTest`, zwei Brüche in `tests/waechter-brechen.sh`; beide waren
nachweislich rot. **Gegengeprüft auf demselben Server**, an derselben Zeile:
`notiz='unberührt'` steht seitdem lesbar da.

Vier Dinge hingen daran:

> **Zwei Systeme unter derselben Umgebung treffen entgegengesetzte Vorgaben —
> und die eine ist verlustfrei, die andere nicht.** `psql` fällt unter `LC_ALL=C`
> auf `SQL_ASCII` zurück, also auf *keine* Konvertierung. Deshalb war die
> PostgreSQL-Hälfte desselben Punktes fehlerfrei — nicht, weil jemand es besser
> gemacht hätte.

> **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von beiden
> ist der Ort, an dem man nachsieht.** Die Argumente standen in `run()` und in
> `linesAs()`. Genau daran ist die fehlende Angabe jahrelang nicht aufgefallen.

> **Ein Hinweis, der genau eine Ursache nennt, ist eine Diagnose — und eine
> falsche Diagnose ist teurer als keine.** Die Meldung fragte „Steht eine binäre
> Spalte in der Abfrage?"; es stand keine.

> **Ein Testdatensatz aus ASCII prüft keine Kodierung.** Das einzige
> nicht-ASCII-Zeichen im ganzen Bestand dieses Laufs ist das `ü` in
> `'unberührt'` — hingeschrieben als deutsches Wort, nicht als Prüfung. Um ein
> Haar hätte auch dieser Lauf nichts gemerkt.

#### Befund 2 — Derselbe Satz, zwei Verpackungen

Der Schreibvorgang, der null Zeilen trifft, meldet in beiden Systemen wörtlich
denselben Satz. Was **um ihn herum** steht, unterscheidet sich:

```
PostgreSQL  Die Datenbank hat abgewiesen: ERROR:  Der Vorgang hat 0 Zeilen …
            CONTEXT:  PL/pgSQL function inline_code_block line 7 at RAISE
MariaDB     Der Vorgang hat 0 Zeilen …
```

Der Satz ist **unserer** — er steht in dem `RAISE EXCEPTION`, das der Agent baut
— und kommt als Datenbankfehler verkleidet zurück, mit einer Zeilennummer auf
eine Datei, die es nicht gibt. Beim Zeitlimit ist dieselbe Verpackung richtig:
Dort *ist* es die Meldung des Servers, und `docs/36 §17` verlangt sie wörtlich.

> **Eine Verpackung, die für eine fremde Meldung richtig ist, ist für die eigene
> falsch.**

**Offen, mit Absicht.** Was ein Kunde liest, entscheidet sich in Schritt 6 des
Plans; ein Fix jetzt wäre eine Vermutung darüber, wie die Oberfläche die Meldung
zeigt.

#### Befund 3 — Die Fassungsprüfung dieses Dokuments suchte in der falschen Datei

Siehe §3. Ein `grep -c "pg.console.tables"` auf `Registry.php` kann nur `0`
finden — die Registratur trägt Objekte, der Name steht in der Op-Klasse. Der
Ausdruck hätte bei einer kaputten Fassung dasselbe gesagt wie bei einer heilen.

> **Ein Wächter, der in der falschen Datei sucht, ist kein Wächter — er ist eine
> Zeile, die immer dasselbe sagt.**

#### Befund 4 — Eine Hilfsdatei unter `/root` ist für `srvpanel tinker` unlesbar

Siehe §2. Der Wrapper gibt seine Rechte ab (`setpriv --reuid=srvpanel`), `/root`
ist `0700`, und die Meldung „Failed opening required" liest sich wie ein
Tippfehler im Pfad.

> **Ein Werkzeug, das Rechte abgibt, liest die Datei nicht mehr, die man ihm als
> root hinlegt.**

#### Befund 5 — Die Gegenprobe zum Zeitlimit zählte sich selbst mit

Punkt 12 fragte

```sql
SELECT count(*) FROM pg_stat_activity WHERE state='active' AND query LIKE '%viel%'
```

und **der Text dieser Abfrage enthält `viel`**. Sie stand in ihrer eigenen
Ergebnismenge. Herausgekommen ist `2`, wo `0` erwartet war; eine der beiden
Zeilen war sie selbst, die zweite war vorübergehend und liess sich Minuten
später nicht mehr feststellen. Sie steht deshalb **nicht** als Befund da — ein
Autovacuum-Arbeiter auf der frischen Tabelle war der Verdacht, belegt ist er
nicht.

Die Abfrage fragt jetzt nach der **Kennung** des Aufrufers statt nach dem
Abfragetext: Sie kann sich nicht selbst treffen, und sie trifft auch keinen
Hintergrundprozess.

> **Eine Frage an den Bestand, die sich selbst enthält, zählt sich mit.**

> **Eine Vermutung, die man nicht mehr prüfen kann, gehört nicht ins Protokoll —
> auch wenn sie plausibel ist.**

#### Befund 6 — Der Nachbau liess eine Zeile weg und bewies damit ihre Notwendigkeit

Punkt 11 baut den befristeten Zugang von Hand nach, um die Wand des Servers zu
messen. Beim Abräumen:

```
ERROR:  role "p5c_probe" cannot be dropped because some objects depend on it
DETAIL:  privileges for database x1b311d2b6eedc3aa_p5c
```

`Pg\Ephemeral::with()` macht im `finally` ein `DROP OWNED BY` vor dem
`DROP ROLE`, und der Kommentar dort erklärt genau diesen Fehler. Der Nachbau
hatte die Zeile nicht — und ist prompt hineingelaufen.

> **Ein Nachbau, der eine Zeile weglässt, beweist ihre Notwendigkeit besser als
> jeder Test.**

Der Punkt trägt die beiden `DROP`-Zeilen jetzt in der richtigen Reihenfolge, und
die Schlussprüfung sucht ausdrücklich auch nach `p5c_probe`: Ein Zugang mit
einem Namen, den `isEphemeral()` nicht erkennt, fällt keinem Aufräumlauf auf.

### 6.3 Was der Lauf über Abnahmen selbst gezeigt hat

**Drei der sechs Befunde stecken im Lauf und nicht im Code** — dieselbe
Beobachtung wie in `docs/45`. Ein Abnahmelauf ist Code, den niemand ausführt, bis
es darauf ankommt, und er hat dieselbe Fehlerdichte wie anderer Code.

**Und die zwei Belege, die den Unterschied zwischen Messung und Behauptung
machen, sind beide erst beim Schreiben dazugekommen:**

- In Punkt 5 zählt nicht die Leere der Restabfrage, sondern der **Name** aus dem
  Beobachter. Eine Abfrage nach Resten ist auch dann leer, wenn nie eine Konsole
  lief.
- In Punkt 11 zählt nicht die Abweisung, sondern die **gelungene** erste Zeile
  jedes Paares. Ohne sie wäre eine Abweisung von einem Tippfehler im
  Datenbanknamen nicht zu unterscheiden.

Dasselbe gilt für Punkt 18: `konsole = 0` bekam seine Bedeutung erst durch die
eine fremde Zeile daneben (`impersonation.stop`), die zeigt, dass die Tabelle
überhaupt beschrieben wird.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 7. Was dieser Lauf ausdrücklich nicht prüft

- **Die Oberfläche.** Es gibt sie noch nicht (Schritte 4 bis 6). Kriterium 2
  verlangt „in der Oberfläche unterscheidbar"; gemessen wird hier, dass die vier
  Werte **in der Antwort** unterscheidbar sind. Das ist die Hälfte, die vor der
  anderen kommt, und sie ersetzt sie nicht. Keine Screenshots, keine Messung bei
  390px.
- **Das Protokoll** (Kriterium 7). Schritt 7 ist nicht gebaut; Punkt 16 hält den
  Ausgangszustand fest und mehr nicht. Die Entprellung — ein Eintrag für zwanzig
  Seitenaufrufe — ist damit ebenfalls ungeprüft.
- **Blättern und Sortieren als Bedienung.** Punkt 10 blättert mit einem grossen
  `OFFSET`, um das Zeitlimit auszulösen; dass Seite 2 bei Zeile 51 beginnt und
  die Sortierung die Reihenfolge dreht, misst `docs/46 §15` Punkt 2 an der
  Blätterleiste.
- **Der Fall „trifft mehr als eine Zeile".** Er braucht einen Eingriff von
  aussen, während eine Zeile im Formular offen ist (`docs/46 §15` Punkt 7), und
  ohne Formular gibt es kein „währenddessen". Punkt 12 misst die andere Richtung
  — null getroffene Zeilen —, und die geht durch denselben `DO`-Block bzw.
  dieselbe `ROW_COUNT()`-Prüfung.
- **Die Fassungsspanne.** Gefahren wird gegen PostgreSQL 16 und MariaDB 10.11 auf
  Ubuntu 24.04. Debian 12, Debian 13 und Ubuntu 22.04 misst die CI, nicht dieser
  Lauf.
- **Wie eine Meldung beim Kunden ankommt.** Befund 2 aus §6 — die PL/pgSQL-
  `CONTEXT`-Zeile an einer Meldung, die der Kunde lesen soll — bleibt mit Absicht
  offen: Die Entscheidung gehört in Schritt 6, wo die Meldung zum ersten Mal auf
  einen Menschen trifft.
- **Last und Dauerbetrieb.** Was ein befristeter Zugang je Anfrage kostet, wenn
  zwanzig Kunden gleichzeitig blättern, steht als Risiko 4 in `docs/46 §18` und
  ist weiter ungemessen. Punkt 4 legt zwei an, nicht zweitausend.
