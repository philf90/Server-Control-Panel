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

## 3 bis 8

Offen. **Punkt 3 ist zur Hälfte belegt** (§1b): Die Auffrischung steht als
`Paketlisten aufgefrischt; jede Quelle hat geantwortet.` im Protokoll, ohne
`apt-run: ` davor. Die zweite Hälfte ist der `grep` über `apt-run`.

---

## Bilanz

Wird geführt, wenn der Lauf durch ist.
