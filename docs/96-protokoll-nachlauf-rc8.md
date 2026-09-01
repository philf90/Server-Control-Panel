# Protokoll — der Nachlauf zu `0.7.3-rc.8`

Der Lauf ist `docs/95`, gefahren auf `cloudsrv24` am **1. September 2026**.
Dieses Dokument wird Punkt für Punkt geführt; was noch nicht gefahren ist, steht
als offen da und nicht als erfüllt.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

---

## 0 · Der Ausgangszustand

Gemessen vor Punkt 1, wie `docs/95 §0` es verlangt:

    srvpanel version                    -> 0.7.3-rc.7
    systemctl is-active srvpanel-worker -> active
    systemctl is-active srvpanel-agentd -> active
    ls -l /opt/srvpanel/current         -> /opt/srvpanel/releases/0.7.3-rc.7

**Die Bedingung ist erfüllt** — es stand ein Update an, und der Lauf war
fahrbar.

---

## 1 · Punkt 1 — M1 und Befund 5

**Gefahren um 19:05:51**, die Unit hiess `srvpanel-update-bbe560d4`.

| Erwartung aus `docs/95 §1` | gemessen | |
|---|---|---|
| Der Befehl bleibt, bis der Lauf durch ist | 19:05:51 → 19:06:06 | erfüllt |
| Zeilen von apt in grau mitgelesen | vollständig, bis `needrestart` | erfüllt |
| Erste graue Zeile ist die Auffrischung | `Paketlisten werden aufgefrischt.` | erfüllt |
| Urteil grün, mit beiden Nummern | `apt-run: Fassung 0.7.3~rc.7 wurde zu 0.7.3~rc.8.` | erfüllt |
| `rc=0` | **`rc=255`** | **nicht erfüllt** |

`srvpanel version` danach: `0.7.3-rc.8`. Das Update selbst ist gelungen.

### Was damit belegt ist

**Befund 5 ist behoben, und M1s erste Hälfte steht.** Der Prozess hat den
Symlink-Wechsel überlebt, `vorladen()` hat für die Warteschleife gereicht, und
das Urteil kam beim Befehl an. Genau dafür gab es diesen Lauf.

**Und der Beleg ist nicht die Dauer.** `docs/95 §1` nennt sie — fünfzehn
Sekunden sind für ein Paket von 6,5 MB auf dieser Leitung eine vollständige
Installation, aber die Zahl allein trüge das nicht. Was trägt, ist das Urteil:
`apt-run` schreibt es erst, wenn der Lauf durch ist. Der Lauf vom 1. September
gegen `rc.6` kam nach drei Sekunden mit einer Fortschrittszeile zurück und
**ohne** Urteil.

> **Ein Beleg, der an einer Zahl hängt, hängt an ihrer Auslegung. Einer, der an
> einer Auskunft hängt, die es vorher nicht geben kann, hängt an nichts.**

### Befund 8 — `vorladen()` deckt die Warteschleife und nicht den Abbau

Nach der bernsteinfarbenen Bereitschaftszeile stirbt der Prozess an zwei
aufeinanderfolgenden fatalen Fehlern:

    PHP Fatal error: Uncaught ErrorException: include(/opt/srvpanel/releases/
    0.7.3-rc.7/vendor/composer/../laravel/framework/src/Illuminate/Foundation/
    Exceptions/Handler.php): Failed to open stream

    PHP Fatal error: Uncaught ErrorException: include(/opt/srvpanel/releases/
    0.7.3-rc.7/vendor/composer/../symfony/error-handler/Error/FatalError.php):
    Failed to open stream

Beide Wege gehen über `HandleExceptions` — der erste über `renderForConsole()`,
der zweite über `handleShutdown()`. Der Autolader dieses Prozesses zeigt in
`/opt/srvpanel/releases/0.7.3-rc.7`, und dpkg hat das Verzeichnis beim Update
geleert.

**Der Rückgabewert lügt damit wieder, nur andersherum.** `srvpanel update && …`
bekommt für ein gelungenes Update ein `255`.

> **Ein Rückgabewert, der einen gelungenen Lauf als Fehlschlag meldet, ist
> derselbe Fehler wie einer, der einen misslungenen als Erfolg meldet — nur in
> die andere Richtung.**

Dasselbe Paar wie Befund 6 aus `docs/91 §20`, wo `apt-run` „nichts zu tun" und
„nicht geschafft" gleich benannte. Und dasselbe wie M5, mit dem P7b angefangen
hat.

### Der Nachbau im Container

Ein Server war dafür nicht nötig, und der Nachbau hat mehr gesagt als die
Aufnahme. Eine hartverlinkte Wegwerf-Fassung unter `/home/user/faux/releases/rcA`
mit `current` als Symlink darauf, ein Kommando, das sich mitten im Lauf
**selbst** abräumt und danach sein Urteil druckt:

| | Ausgabe | Rückgabewert |
|---|---|---|
| `return` aus der Warteschleife (wie bisher) | Urteil, dann dieselben zwei Fatals | **255** |
| `exit()` unmittelbar nach dem Urteil | Urteil, sonst nichts | **0** |

Die Kaskade ist Zeile für Zeile dieselbe wie auf dem Server, bis auf den Pfad.

**Und der erste fehlende Name ist gemessen und nicht geraten.** Ein
vorangestellter Autolader hat mitgeschrieben, was nach `handle()` noch gesucht
wurde:

    Symfony\Component\Console\Event\ConsoleTerminateEvent   ← der erste
    Illuminate\Foundation\Exceptions\Handler                ← das Rendern des Fehlers
    Symfony\Component\ErrorHandler\Error\FatalError         ← das Rendern des Fehlers am Fehler

### Warum die Liste nicht die Antwort ist

Die naheliegende Behebung wäre, `vorladen()` um diese Namen zu erweitern.
**Gemessen ist sie keine:** Mit ihnen vorgeladen kommt der Lauf nicht durch,
sondern nennt vier neue — `Illuminate\Console\Events\CommandFinished`,
`Illuminate\Foundation\Configuration\Exceptions`, `Illuminate\Log\LogManager`,
`Illuminate\Cache\RateLimiting\Limit`.

> **Eine Positivliste über das, was ein fremdes Framework nach dem eigenen Code
> nachlädt, wächst, während man sie füllt.**

Und sie wüchse weiter mit jeder Fassung von Laravel und Symfony, ohne dass etwas
es meldete — der Fehler zeigt sich ausschliesslich auf einem Server, im Moment
eines echten Updates.

### Die Behebung

`exit($this->mitlesen($log, $unit));` am Ende von `handle()`. Das überspringt
`Kernel::terminate()` und damit jedes Nachladen; die Ausgabe steht zu diesem
Zeitpunkt vollständig auf dem Kanal. Es ist der erste `exit()` in `app/`, und er
steht dort mit seiner Begründung.

Der Wächter ist `UpdateWaitTest::test_the_wait_ends_the_process_itself`, gebrochen
in beide Richtungen: mit `return` statt `exit` rot, und mit der verbotenen Zeile
**nur im Kommentar** grün, während ein roher Leser sie fände. Genau dafür trägt
der Wächter `WithoutPhpComments`.

**Gemessen ist die Behebung im Container und nicht auf dem Server.** Wann sie
sich auf einem zeigen kann, steht in §1b — und es ist eine Fassung später, als
hier zuerst stand.

---

## 1b · Der Sprung `rc.8` → `rc.9` hat Befund 8 **nicht** geprüft

**Gefahren am 1. September um 20:03:11**, Unit `srvpanel-update-c3a05a15`, durch
in achtzehn Sekunden. Das Urteil steht grün da — `Fassung 0.7.3~rc.8 wurde zu
0.7.3~rc.9.` —, danach dieselbe Kaskade wie beim Sprung davor und wieder
`rc=255`. `srvpanel version` meldet `0.7.3-rc.9`.

**Das ist kein Fehlschlag der Behebung, sondern ein Fehler in meiner Erwartung.**
Die Behebung ist Teil von `rc.9`. Wartend war der Prozess, der den Befehl
ausführt, und der kam aus `/opt/srvpanel/current` **zum Zeitpunkt des Aufrufs** —
also aus `rc.8`, wo am Ende der Warteschleife noch `return` steht. Die
Rückverfolgung sagt es wörtlich: jeder Rahmen bis `#19 {main}` liegt unter
`/opt/srvpanel/releases/0.7.3-rc.8/`.

> **Der Prüfling einer Aktualisierung ist die installierte Fassung und nicht die
> eingespielte.**

**Und der Hinweis stand schon in Punkt 1, aufgeschrieben und nicht angewandt:**
Dort war die erste graue Zeile `Paketlisten werden aufgefrischt.` — aus `rc.7`s
`apt-run`, weil die installierte Fassung ihr eigenes Skript liest. Derselbe Satz,
eine Stunde später an derselben Naht übersehen.

> **Ein Satz, den man in einem Protokoll festhält, prüft die nächste Erwartung
> nicht von selbst.**

**Eine Behebung an der Warteschleife lässt sich damit grundsätzlich erst eine
Fassung später belegen.** Für Befund 8 ist das der Sprung `rc.9` → `rc.10`; ein
früherer Prüfkörper existiert nicht.

### Was der Lauf trotzdem belegt hat

**Befund 2 lebt auf dem Server.** Die erste graue Zeile lautet jetzt
`Paketlisten aufgefrischt; jede Quelle hat geantwortet.` — geschrieben von
`PanelUpdate` im Agenten, **ohne** `apt-run: ` davor. Damit ist die erste Hälfte
von Punkt 3 nebenbei erfüllt, und die Auffrischung kommt nachweislich nicht mehr
aus `apt-run`.

**Befund 5 ein zweites Mal.** Der Befehl blieb über den ganzen Lauf, hat die
Zeilen von apt mitgelesen und das Urteil mit beiden Fassungsnummern gedruckt.

**Und `1 aktualisiert` heisst, dass etwas anstand** — der Zustand, den Punkt 2
braucht, ist danach wiederhergestellt: Auf `rc.9` steht nichts mehr an.

---

## 2 · Punkt 2 — Befund 2 auf dem Server

Gefahren nach §1b, auf `0.7.3-rc.9`, Unit `srvpanel-update-a5a80d58`.

    srvpanel ist schon die neueste Version (0.7.3~rc.9).
    0 aktualisiert, 0 neu installiert, 0 zu entfernen und 9 nicht aktualisiert.
    apt-run: Es stand nichts an — Fassung unverändert: 0.7.3~rc.9.

    Es stand nichts an — Fassung unverändert: 0.7.3~rc.9.        (grün)
    rc=0

**Das Kriterium ist erfüllt.** Vor dieser Fassung stand dort `Der Lauf hat
nichts verändert — Fassung vorher wie nachher: …`, rot, mit `rc=3` und der Unit
auf `failed` (`docs/94 §4`). Befund 2 ist damit auf einem echten Server belegt,
in beiden Hälften: Die Auffrischung steht als eigene Zeile im Protokoll, und der
Lauf ohne Anlass ist kein Fehlschlag mehr.

**Und ein Nebenbefund zu Befund 8, der keiner ist:** Dieser Lauf ist der erste
mit `rc.9`s `exit()` — er lief durch und endete mit `0`. Das belegt, dass der
Ausstieg im gewöhnlichen Fall nichts kaputt macht, und **nicht**, dass er den
Fall trägt, für den es ihn gibt: Hier wurde kein Fassungsverzeichnis abgeräumt,
der Autolader hatte seine Dateien.

> **Ein Prüfkörper ohne die Bedingung, gegen die gebaut wurde, misst den Bau und
> nicht die Bedingung.**

### Befund 9 — die Gegenprobe von Punkt 2 fragt an der Sache vorbei

`docs/95 §2` liest den neuesten Vorgang und erwartet `succeeded`. Gemessen:

    729 db.dump.create succeeded fertig

**Vorgang 729 stammt vom 31. August** (er steht in `docs/94 §6b`), und
`db.dump.create` ist nicht das, was hier lief. Der Grund: `srvpanel update` ruft
`panel.update` **unmittelbar über den Agenten** und legt keinen Vorgang an — das
Panel hat für die eigene Aktualisierung gar keine Fläche, sie läuft
ausschliesslich über die Kommandozeile.

Die Gegenprobe hat also nach einer Zeile gesucht, die es nicht geben kann, eine
fremde gefunden und **grün gemeldet**.

> **Eine Frage nach dem neuesten Datensatz beantwortet, welcher der neueste ist
> — nicht, ob der gesuchte darunter ist.**

Sie ist in `docs/95 §2` ersetzt worden: Gefragt wird das Journal der transienten
Unit, denn dort steht der Rückgabewert von `apt-run`, an dem der Fehlschlag
hing.

### Befund 10 — der Vorbehalt stand unter einem Lauf, der nichts eingespielt hat

Unter dem grünen Urteil stand:

    Antwortet die Bereitschaftsprüfung danach nicht, setzt das Paket selbst auf
    die vorige Version zurück.

Es hat aber nichts entpackt: kein `dpkg`, also keine Kopie unter
`/opt/srvpanel/rollback`, keine Bereitschaftsprüfung, kein Rückweg. Der Satz
verspricht ein Netz, das niemand gespannt hat — direkt unter der Zeile, die
gerade gesagt hat, dass die Fassung unverändert ist.

> **Zwei Sätze über denselben Lauf, von denen einer eine Installation
> voraussetzt, die der andere ausschliesst.**

**Behoben:** `Outcome::unchanged()` unterscheidet den Fall, und `urteilen()`
druckt den Vorbehalt nur noch darunter. Im Zweig für `--no-wait` bleibt er
stehen — dort ist der Ausgang unbekannt, und der Vorbehalt gilt.

Die Naht zu `apt-run` ist gehalten (`Outcome::UNCHANGED` gegen den Satz im
Skript, Kommentare abgestreift). Läuft sie auseinander, antwortet `unchanged()`
mit `false` — ein Vorbehalt zuviel statt eines fehlenden, also die richtige
Richtung.

---

## 3 · Punkt 3 — die Auffrischung steht im Protokoll und kommt nicht aus `apt-run`

    grep -n 'Paketlisten' /var/log/srvpanel/update.log
    1:Paketlisten aufgefrischt; jede Quelle hat geantwortet.
    2:Paketlisten werden gelesen…

    grep -c 'apt-get.*update' /usr/lib/srvpanel/apt-run
    5

**Beide Hälften sind erfüllt.** Zeile 1 ist die Meldung aus `PanelUpdate`, ohne
`apt-run: ` davor — sie kommt also nicht als Urteil an. Zeile 2 ist apts eigene
Ausgabe und gehört dazu.

Und die `5` ist **kein** Treffer: Alle fünf Zeilen sind Kommentare (45, 117, 118,
125, 215). Aufrufe sind es null.

### Befund 11 — die Messvorschrift zählt die Kommentare mit

`docs/95 §3` schrieb `grep -c` über den rohen Text. In diesem Repo hält **jede**
Behebung ihren Vorzustand im Kommentar fest; ein roher Zähler über eine
entfernte Zeile findet sie deshalb zuverlässig wieder.

> **Ein Prüfmittel, das eine Zeichenkette sucht, zählt die Kommentare mit — und
> in diesem Repo zitiert jede Behebung ihren Vorzustand im Kommentar.**

**Es ist derselbe Kommentar wie am selben Tag bei `OutcomeTest`, nur mit
umgekehrtem Vorzeichen.** Dort blieb ein Wächter grün, weil die Zeile, die das
Entfernte erklärt, es wörtlich hinschreibt; hier meldet eine Messung rot, aus
genau demselben Grund.

> **Derselbe Kommentar, der einen Wächter fälschlich grün hält, macht eine
> Messung fälschlich rot.**

**Gebaut wurde dafür nichts, und das ist der Punkt:** `AptResultTest` streift die
Kommentare seit dem 26. August ab und hält beide Richtungen — dass `apt-run`
keinen Aufruf mehr enthält, und dass die eine erlaubte Stelle noch einen hat.
Der Wächter war die ganze Zeit richtig. Falsch war nur der Handgriff im
Abnahmelauf, und der ist in `docs/95 §3` ersetzt.

**Der berichtigte Griff ist auf demselben Server nachgefahren worden und gibt
nichts aus.** Damit ist Punkt 3 mit dem richtigen Werkzeug belegt und nicht bloss
erklärt.

Und die Gegenprobe dazu ist die falsche Messung selbst: Der ungefilterte Griff
hat fünf Zeilen gefunden, der gefilterte keine. Eine leere Ausgabe heisst hier
also „alle Treffer sind Kommentare" und nicht „der Griff findet nichts".

> **Die falsche Messung war die Gegenprobe der richtigen** — sie belegt, dass
> der Ausdruck überhaupt trifft, und ohne sie wäre die leere Ausgabe von einer
> kaputten Leitung nicht zu unterscheiden.

---

## 4 · Punkt 4 — die tote eigene Quelle bricht ab

    URIs: https://gibtesnicht.invalid/srvpanel
    srvpanel update

    Die Paketquelle des Panels https://gibtesnicht.invalid/srvpanel/ ist nicht
    erreichbar: Could not resolve 'gibtesnicht.invalid'. Ohne sie kennt apt nur
    die alten Paketlisten, und ein Update fände nichts Neues — es wurde deshalb
    nicht begonnen.
    rc=1

**Erfüllt, und in allen vier Teilen.** Die Meldung nennt die Quelle beim Namen
und den Grund von apt; der Abbruch liegt **vor** dem Absetzen (die Zeile „Das
Update läuft als …" fehlt, es gibt also keine Unit); der Rückgabewert ist `1`;
und der Rückweg ist belegt — `diff … && echo 'zurueck'` hat `zurueck` gedruckt.

Damit ist gezeigt, dass Befund 2 M5 nicht wieder aufgerissen hat: Die
Simulation, die „es stand nichts an" trägt, steht hinter einer Prüfung, die eine
tote eigene Quelle abfängt.

---

## 4b · Die Frage ohne Sollantwort — und die Antwort ist ein Befund

`Sources::uris(PANEL_SOURCE)` vorher: `["https://repo.cloudsrv24.de/apt"]` — eine
Adresse, die Datei ist also die richtige.

Danach, mit `Enabled: no`:

    Paketlisten aufgefrischt; jede Quelle hat geantwortet.
    srvpanel ist schon die neueste Version (0.7.3~rc.9).
    apt-run: Es stand nichts an — Fassung unverändert: 0.7.3~rc.9.

    Es stand nichts an — Fassung unverändert: 0.7.3~rc.9.        (grün)
    rc=0

### Befund 12 — das Panel meldet „du bist aktuell", während seine Quelle aus ist

**Die Lücke aus `docs/95 §4b` ist echt.** Und sie ist M5 in einer dritten
Gestalt, in der jede einzelne Stufe richtig antwortet:

1. apt holt eine abgeschaltete Quelle gar nicht erst → keine `W:`-Zeile.
2. `Apt::readFailures()` findet nichts → `hitting()` gibt `null`.
3. Die Simulation sieht mangels neuer Listen nichts Anstehendes → `ansteht = 0`.
4. Die Fassung ist vorher wie nachher dieselbe → „Es stand nichts an."

> **Eine Quelle, die nicht gefragt wird, antwortet nicht falsch — sie fehlt, und
> das sieht aus wie Zustimmung.**

**Und die Auffrischungszeile lügt mit.** `Paketlisten aufgefrischt; jede Quelle
hat geantwortet.` stimmt für die Quellen, die apt **gefragt** hat — die eigene
war nicht darunter.

> **Eine Zusage über „jede Quelle" gilt für die, die gefragt wurden, und nicht
> für die, die es gibt.**

### Die Behebung

`Sources::enabledUris()` liest die Adressen der **eingeschalteten** Stanzas —
über `Sources::stanzas()`, denn `Enabled:` ist eine Eigenschaft einer Stanza und
ohne deren Grenzen nicht zu beantworten. `PanelUpdate` fragt damit **vor** der
Auffrischung, ob die eigene Quelle überhaupt in Kraft ist, und unterscheidet
zwei Zustände mit zwei Meldungen: die Datei fehlt, oder es ist keine
eingeschaltete Quelle mit Adresse übrig.

Die Reihenfolge ist das Tragende: Stünde die Frage danach, wäre sie wirkungslos.
`AptResultTest::test_the_panels_own_source_is_in_force_before_the_refresh` misst
deshalb die Reihenfolge und nicht den Aufruf.

Und `hitting()` bekommt seitdem die **eingeschalteten** Adressen. Eine
abgeschaltete Stanza kann keinen Fehlschlag erzeugt haben; sie mitzuführen
hiesse, in den Meldungen nach einer Quelle zu suchen, die apt nie angefasst hat.

**Nicht behoben ist die Auffrischungszeile.** Sie steht weiter so da; nach dieser
Änderung kommt ein Lauf mit abgeschalteter eigener Quelle gar nicht mehr bis zu
ihr, und für die übrigen Quellen stimmt sie. Wer sie schärfer haben will, müsste
die Zahl der gefragten Quellen nennen — das ist ein eigener Vorschlag und kein
Befund dieses Laufs.

---

## 5 · Punkt 5 — Befund 4 vollständig

Vorgang **732**, `db.dump.create`, ausgelöst über **Sicherung erstellen** auf
`/databases/37`.

| Erwartung | gemessen |
|---|---|
| `← /databases/37` im Brotkrümel, als Verknüpfung | steht da, verknüpft |
| Zeile **Sicherung** mit dem Namen, verknüpft | `p1136_test`, verknüpft |

**Erfüllt.** Vorgang 729 vom 31. August zeigte den Gegenstand und kein `←`
(`docs/94 §6b`); beides zusammen ist der Beleg, dass Befund 4 behoben ist — und
dass `Operation::booted()` die Herkunft an der einen Stelle setzt, an der
niemand vorbeikommt.

---

## 6 · Punkt 6 — der Zurück-Knopf des Browsers

Vorgang **733**, achtundzwanzig Sekunden nach 732, nach einem Druck auf den
Zurück-Knopf des Browsers und einem zweiten **Sicherung erstellen**.

**Der Brotkrümel trägt wieder `← /databases/37`** — und nicht
`← /operations/732`.

**Erfüllt, und dieser Punkt trägt mehr als sein Kriterium.** Er ist der einzige
Beleg, dass `router.on('before')` bei `router.post` überhaupt feuert; bis hierher
stand das aus Inertias Typdefinition gelesen da und nicht beobachtet. Trüge 733
gar kein `←`, wäre der Entwurf von Befund 3 falsch gewesen.

> **Eine Herkunft, die der Server führt, veraltet bei jeder Navigation, die der
> Server nicht sieht.** Inertia stellt `/databases/37` aus dem History-Zustand
> her; der Server sieht davon nichts.

---

## 7 · Punkt 7 — ein Vorgang ohne Seite zeigt kein `←`

    srvpanel vhost --sites
    4 Server-Blöcke der Kundendomains eingereiht.

    741 web.site.apply origin=NULL
    740 php.pool.apply  origin=NULL
    739 web.site.apply  origin=NULL

**Erfüllt**, alle drei. `null` heisst „von keiner Seite" und ist die Wahrheit für
die Konsole, die Warteschlange und jeden Lauf der Automatik.

**Und das sagt erst durch die Punkte 5 und 6 etwas.** Vor der Behebung zeigte
auch ein Vorgang *mit* Sitzung kein `←` — Erfolgs- und Fehlerfall sahen gleich
aus.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

---

## 8 · Punkt 8 — der Prüfkörper ist der falsche

### Befund 13 — die Vorschrift hat die Länge des Gegenstands angenommen

`docs/95 §8` schrieb: *„Eine Sicherung trägt den Namen der Datenbank samt
Zeitstempel — der ist länger."* Gemessen steht dort `p1136_test`, **zehn
Zeichen**.

Und das ist keine Überraschung, sondern eine Entscheidung, die im Quelltext
begründet steht: `OperationSubject::nameOf()` gibt für eine Sicherung
ausdrücklich `database_name` zurück und **nicht** `storage_name` — *„wer eine
Sicherung wiedererkennt, erkennt sie an der Datenbank, aus der sie stammt."*

> **Ein Prüfkörper, dessen Länge man annimmt, statt sie am Quelltext
> nachzusehen, ist eine Vermutung mit Fussnote.**

Dasselbe Muster wie Befund 9 und 11: dritter Fund dieses Laufs am eigenen
Prüfmittel, und alle drei wären beim Ausschreiben zu vermeiden gewesen.

**Der richtige Prüfkörper ist eine Domain und keine Sicherung.**
`nameOf()` gibt für `Domain` das Feld `name` zurück, und ein Domainname darf 63
Zeichen je Label tragen. `docs/95 §8` ist entsprechend berichtigt.

### Gemessen mit dem berichtigten Prüfkörper

Vorgang **745**, `web.site.apply`, Gegenstand
`domain-mit-richtig-langem-namen.invalid` — **achtunddreissig Zeichen**, also
deutlich über den 25, an denen `docs/94` Punkt 3 als „nicht herstellbar"
ausgefallen war.

Bei 390 × 844, in **beiden** Themen:

    (dunkel)
    document.documentElement.scrollWidth - document.documentElement.clientWidth
    0

    srvpanelTheme('light')
    undefined
    document.documentElement.scrollWidth - document.documentElement.clientWidth
    0

`undefined` ist dabei die Antwort einer Funktion ohne Rückgabewert und nicht die
eines fehlenden Aufrufs — ein Name, den es nicht gibt, endet mit einem
`ReferenceError`. Der Umschalter ist also gelaufen, und das ist hier die Frage:
`app.css` kennt keine `prefers-color-scheme`-Regel, sondern hängt allein an
`data-theme` am `<html>`.

> **Eine Umstellung, die der Prüfling nicht liest, hat nichts umgestellt — und
> das Bild daneben sieht aus wie ein Ergebnis.** (`docs/A5`, die Bildrunde, die
> zweimal hell gemessen hat.)

Der Brotkrümel nimmt **zwei** Zeilen — die Grenze aus `docs/94 §6b` ist
eingehalten und nicht unterschritten. Der Domainname bricht in seine eigene
Zeile unter die Beschriftung, statt die Zeile zu weiten; genau dafür trägt die
Zelle ihre Kennung (`docs/94`, vierte Wiederholung derselben Ausnahme).

### Die Gegenprobe — und was sie über sich selbst gesagt hat

    document.body.insertAdjacentHTML('beforeend',
      '<div style="width:' + (document.documentElement.scrollWidth + 200) + 'px;height:1px"></div>');
    document.documentElement.scrollWidth - document.documentElement.clientWidth
    400

**Der Prüfkörper schlägt aus, und damit ist die Null von oben eine Messung** —
sie ist nicht die Zahl eines Messstands, der gar nichts sehen kann.

**Erwartet waren 200, gemessen sind 400**, und das ist kein Fehler an der Seite,
sondern die Form des Prüfkörpers: Er bemisst sich am **gegenwärtigen**
`scrollWidth`. Beim ersten Lauf ist das die Breite des Dokuments, also entsteht
ein Überhang von 200. Läuft er ein zweites Mal ohne Neuladen, ist der eigene
Block von eben schon Teil des Masses, und der neue liegt 200 px darüber — 400.

> **Ein Prüfkörper, der sich am gegenwärtigen Zustand bemisst, verändert den
> Zustand, an dem er sich bemisst — beim zweiten Lauf misst er sich selbst.**

Das ist dieselbe Familie wie die berichtigte Vorschrift aus `docs/58 §12`, nur
von der anderen Seite: Dort war ein fester Block bei 1440 px keine Gegenprobe
mehr, weil er nicht mehr das Breiteste war; hier ist ein relativer Block beim
zweiten Lauf zu breit. **`tests/bilder-messen.js` ist davon nicht betroffen** —
es misst je Aufnahme in einer frisch geladenen Seite. Es trifft nur den Griff von
Hand in der Konsole, und die Abhilfe ist ein Neuladen davor.

`docs/95 §8` sagt das jetzt dazu. **Punkt 8 ist erfüllt.**

---

## Bilanz

**Der Lauf ist durch, und alle acht Punkte sind gefahren.** Die Pflichtpunkte
aus `docs/95 §10` sind 1, 2, 3, 5, 6 und 7.

| Punkt | | |
|---|---|---|
| 1 | M1 und Befund 5 | erfüllt bis auf `rc=0` → **Befund 8** |
| 1b | der Sprung rc.8 → rc.9 | Befund 8 **nicht** geprüft; Befund 2 und 5 belegt |
| 2 | Befund 2 | erfüllt → **Befund 9, 10** |
| 3 | die Auffrischung | erfüllt → **Befund 11** |
| 4 | die tote eigene Quelle | erfüllt |
| 4b | die abgeschaltete Quelle | **Befund 12** |
| 5 | Herkunft und Gegenstand | erfüllt |
| 6 | der Zurück-Knopf | erfüllt |
| 7 | ein Vorgang ohne Seite | erfüllt |
| 8 | 390 px, beide Themen | erfüllt — 0 px, Gegenprobe 400 → **Befund 13** |

**Sechs Befunde, und fünf davon stecken im Prüfmittel oder in der Vorschrift:**
9 (die Gegenprobe fragt an der Sache vorbei), 11 (die Messvorschrift zählt
Kommentare mit), 13 (die Länge des Prüfkörpers angenommen statt nachgesehen) —
dazu die falsche Erwartung an §1b und die Zahl in meinem ersten Bericht zu
Punkt 1. Im Prüfling stecken drei: 8, 10 und 12.

Das ist wieder das Verhältnis aus `docs/45`, `docs/48`, `docs/59` und `docs/84`
und nicht das aus `docs/91`. Der Unterschied zu `docs/91` ist benennbar: Dort
lag das Messmittel als geprüftes Werkzeug im Repo. Hier waren es Handgriffe, die
ich beim Ausschreiben erfunden habe — und **alle drei wären durch Nachsehen am
Quelltext zu vermeiden gewesen.**

> **Eine Vorschrift, die eine Eigenschaft des Prüflings annimmt, prüft die
> Annahme mit — und meldet ihren eigenen Irrtum als Befund am Prüfling.**

### Was offen bleibt

- **Befund 8** ist behoben und ungeprüft. Sein einziger Prüfkörper ist der
  Sprung `rc.9` → `rc.10`.
- **Befund 10 und 12** sind gebaut und haben keinen Server gesehen; sie liegen
  in `rc.10`.
- **Die Zeile `Paketlisten aufgefrischt; jede Quelle hat geantwortet.`** gilt für
  die gefragten Quellen und nicht für die vorhandenen (§4b). Kein Befund dieses
  Laufs, sondern ein Vorschlag.

---

## Bilanz

Wird geführt, wenn der Lauf durch ist.
