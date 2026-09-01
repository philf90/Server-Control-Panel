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

**Gemessen ist die Behebung im Container und nicht auf dem Server.** Ob sie beim
nächsten echten Sprung trägt, sagt erst `rc.9`.

---

## 2 bis 8

Offen. Der Zustand nach Punkt 1 ist der, den Punkt 2 braucht.

---

## Bilanz

Wird geführt, wenn der Lauf durch ist.
