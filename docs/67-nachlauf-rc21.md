# Der Nachlauf zu `v0.6.0-rc.21`

*Angelegt am 21. August 2026, **während** des Laufs und nicht danach.*

## 0. Wofür es diesen Lauf gibt

`docs/66` hat elf Punkte gemessen und acht Befunde gebracht. Alle acht sind
behoben und mit `v0.6.0-rc.21` ausgeliefert — **aber eine Behebung ist keine
Messung.** Fünf Dinge stehen in `docs/66 §3` als „wartet auf die nächste
Fassung", und dieser Lauf holt sie nach.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

Der wichtigste ist Punkt 1: Er war der **einzige nicht erfüllte Punkt** des
vorigen Laufs.

| | Rahmen | |
|---|---|---|
| Fassung | `v0.6.0-rc.21`, ab Befund 1 **`v0.6.0-rc.22`** | |
| Server | `cloudsrv24` | |
| Abonnement | 140, `p6-abnahme.invalid`, Systembenutzer `p1139` | |
| Messmittel | `tests/bilder-messen.js`, Stand **2026-08-21** | mit `versteckt` |

**Neu am Messmittel, und beim Lesen zu beachten:** Das Feld `versteckt` zählt
die Kästen, die nur für die Vorlesesoftware da sind und deshalb nicht mehr in
`schiebt` stehen (Befund 2). Eine Zahl dort ist **kein** Fund; eine kürzere
`schiebt`-Liste als früher ist der Zweck und nicht ein Ausfall.

---

## 1. Die Punkte

| # | Punkt | erwartet | gemessen | |
|---|---|---|---|---|
| 1 | Suche ohne und mit Häkchen | beide Male eine Trefferliste | `content=0` und `content=1`, beide Male Treffer, Häkchen bleibt | **erfüllt** |
| 2 | Vorschau auf der Cronseite | Satz und drei Fälligkeiten | Satz da, drei Fälligkeiten, Zone umgerechnet; `*/15` ohne Satz mit drei Zeitpunkten | **erfüllt** |
| 3 | Entprellung | deutlich unter 20 Anfragen, mindestens 1 | **1** zügig gegen **20** langsam | **erfüllt** |
| 4 | `/audit` bei 390 px | `dokument: 0` | `dokument: 0`, aber `schiebt` voll | **nicht erfüllt — Befund 6** |
| 5 | Eine Protokollzeile nennt ihr Stück | `job: … · schedule: …` | Ziel **und** Einzelheiten da; Einzelheiten auch rückwirkend | **erfüllt** |

---

## 1a. Punkt 1 — der nachgeholte Punkt des rc20-Laufs

**Gemessen am 21. August auf `cloudsrv24` gegen `v0.6.0-rc.23`**, 1440 px, hell.
Die Adresse sagt es unmissverständlich:

    …/files/search?content=0&path=%2F&query=conf     ← ohne Häkchen
    …/files/search?content=1&path=%2F&query=conf     ← mit Häkchen

`0` und `1` statt `false` und `true`. Beide Male kommt die Trefferliste
(„Gesucht unter / — angesehene Einträge: 18", ein Treffer `/conf` im Namen),
**das Kästchen bleibt angehakt**, die Leiste nennt das Verzeichnis, und
`dokument: 0`.

**Damit ist Befund 5 aus `docs/66` auf einem echten Server belegt** — die Suche,
die seit P6 Schritt 5 an keinem einzigen Tag durchgekommen war, in beiden
Zuständen des Kästchens.

### Zwei Beobachtungen am Messmittel, beide ohne Einfluss auf das Ergebnis

**Der Aufsatz war der alte.** Die Ausgabe trug `"stand":"2026-08-19"`, und das
Feld `versteckt` fehlte; aktuell ist `2026-08-21`. Genau dafür gibt es diesen
Stand.

> **Ein Werkzeug, das nach jedem Neuladen aus der Zwischenablage kommt, ist so
> alt wie die Zwischenablage und sagt es nicht.**

**Und die eine Zeile in `schiebt` gehörte nicht uns.**
`div#lp-menu-live-region` mit `aria-live="polite"` und `clip: rect(…)`,
Überlauf 468 px — dieses Element kommt im Quelltext dieses Projekts **nirgends**
vor. Bauart und Präfix deuten auf eine Browser-Erweiterung, die in jede Seite
eine versteckte Ansagefläche einhängt.

> **Ein Messmittel, das im Browser des Betreibers läuft, misst auch, was der
> Browser dazutut.**

Für die weiteren Punkte gilt deshalb: Fenster **ohne Erweiterungen**. Nicht
weil es hier etwas verfälscht hätte — `dokument` war 0 —, sondern weil die
nächste fremde Einhängung vielleicht nicht geklippt ist und dann wie ein Fund
aussieht.

## 1b. Punkt 2 — die Vorschau während des Tippens

**Gemessen am 21. August, 1440 px, hell, im Inkognito-Fenster ohne
Erweiterungen.** Die Messung ist zum ersten Mal in diesem Lauf vollständig
sauber: `stand: 2026-08-21`, `versteckt: 0`, `schiebt: []`, `dokument: 0`,
`gegenprobe: 200/200`. `rollt` nennt allein die Jobtabelle
(`div.scrolls`, 248 px, `darf: true`).

**Nebenbei damit erledigt: die fremde Ansagefläche aus Punkt 1.** Im
Inkognito-Fenster ist `div#lp-menu-live-region` schlicht nicht mehr da. Sie kam
von einer Erweiterung und nicht von diesem Panel.

**a) `15 3 * * *`**

    Läuft jeden Tag um 03:15.
    Nächste Fälligkeiten (Europe/Berlin):
      2026-08-22 05:15:00 · 2026-08-23 05:15:00 · 2026-08-24 05:15:00

Drei Zeitpunkte, wie vorgesehen — zwei lesen sich wie „täglich" und wie „alle
24 Stunden" gleichermassen. **Und die Umrechnung steht zum ersten Mal als
Zahl da:** 03:15 Serverzeit (UTC) sind 05:15 in der Anzeigezone. Genau der
Unterschied, den der Kasten oben auf der Seite erklärt.

**b) `*/15 * * * *`**

    (kein Satz)
    Nächste Fälligkeiten (Europe/Berlin):
      2026-08-21 14:30:00 · 14:45:00 · 15:00:00

**Das ist der wichtigste Teilschritt des ganzen Wunsches.** Der häufigste
Zeitplan überhaupt bekommt keine Prosa, weil `Spoken` nur übersetzt, was sie
sicher übersetzen kann — und trotzdem eine eindeutige Antwort. Die Entscheidung
für Weg B (`docs/66 §4.2`) hing genau daran:

> **Die Fälligkeiten sind der eigentliche Gewinn.** Sie brauchen keine
> Übersetzungsregel, also gibt es sie auch für die Fälle, für die es keinen
> Satz gibt.

**c) Ein unbrauchbarer Zeitplan** — abgesendet:

> Das Formular wurde nicht gespeichert.
> Das Feld **„Minute"** des Zeitplans enthält unerlaubte Zeichen.

Die Meldung nennt das Feld so, wie es auf der Seite heisst. **Das belegt
nebenbei Befund 3 aus `docs/66`** an einer zweiten Stelle.

**Und die Vorschau während des Tippens ist nachgereicht** — sie kam als
Nebenprodukt der Messung zu Punkt 3. Mit `12345678901234567890` im Minutenfeld
sind Satz **und** Fälligkeiten verschwunden; stehen bleiben nur der feste
Hinweis auf die erlaubten Zeichen und die Zeile „Ergibt: …". **Kein roter Rand,
keine Meldung.**

Damit ist die Zusage aus dem Code gemessen und nicht mehr behauptet: Wer beim
dritten Zeichen einer Spanne rot wird, wird bei jeder Spanne rot — also wird
hier niemand rot. Den Satz zum Fehler gibt es beim Absenden, und dort nennt er
das Feld „Minute" so, wie es auf der Seite heisst.

## 1c. Punkt 3 — die Entprellung, und warum eine Zahl allein nicht reicht

**Gemessen am 21. August** über einen Zähler um `fetch`, der nur Aufrufe an
`/cron/preview` mitzählt:

| | Anfragen |
|---|---|
| zwanzig Anschläge, **zügig** getippt | **1** |
| zwanzig Anschläge, **langsam** getippt (über 300 ms Abstand) | **20** |

**Die erste Zahl allein wäre nichts gewesen.** Eine `1` entsteht auf zwei Wegen,
die im Ergebnis gleich aussehen: zwanzig Anschläge, die die Entprellung
zusammenfasst — oder ein einziges Eingabeereignis, etwa ein Einfügen. Erst die
20 daneben entscheidet es: Jeder Anschlag löst aus, also war die 1 die
Entprellung und kein Zufall.

> **Eine Zahl, die bei zwanzig Anschlägen und bei einem Einfügen dieselbe ist,
> misst nicht die Entprellung.**

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Hier bekommt die Eins ihre Bedeutung durch die Zwanzig.

`vorschauAnfragen` — der Zähler, der beim Bauen von Wunsch 4 ausdrücklich dafür
eingebaut wurde, dass die Entprellung messbar ist statt behauptet — hat genau
dafür getaugt.

### Und ein Fehler in meiner Messanweisung

Der erste Anlauf war unbrauchbar: Der Griff um `fetch` stand **zweimal** in der
Konsole, weil Chrome ein erneutes `const` erlaubt. Der zweite Griff lag über dem
ersten, und jede Anfrage zählte doppelt. Aufgefallen ist es nur, weil das Bild
die Konsole mit zeigte — die Zahl selbst hätte ich als Ergebnis genommen.

> **Ein Messmittel, das man zweimal einfügt, misst zweimal.**

Die berichtigte Fassung merkt sich das echte `fetch` unter einem eigenen Namen
und überlebt damit mehrfaches Einfügen.

## 1d. Punkt 5 — das Protokoll nennt jetzt den Gegenstand

**Gemessen am 21. August auf `/audit`**, Filter `cron.`, 1440 px:

| Zeit | Aktion | Ziel | Einzelheiten |
|---|---|---|---|
| 14:25:54 | `cron.job.add` | `CronJob#21` | `job: Y · schedule: */15 * * * *` |
| 14:22:48 | `cron.job.remove` | `CronJob#15` | `job: H` |
| 08:37:51 | `cron.job.add` | **`—`** | `job: Z · schedule: 0 5 1 * *` |
| 20.08. 12:02 | `cron.job.add` | **`—`** | `job: X · schedule: */15 * * * *` |

**Die beiden Hälften von Befund 7 trennen sich hier von selbst**, und das ist
der Teil, den ich nicht vorhergesehen hatte:

**Die Einzelheiten stehen auch bei den alten Einträgen** — vom 20. August, lange
vor der Behebung. Der `context` wurde die ganze Zeit geschrieben; es hat ihn nur
niemand gelesen. Die Behebung macht **rückwirkend** lesbar, was immer schon
dastand.

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt** — und wenn es dann jemand liest,
> war es die ganze Zeit da.

**Das Ziel dagegen bleibt bei den alten Einträgen `—`.** Es wurde nie
aufgezeichnet, und das lässt sich nicht nachholen. Der Unterschied zwischen
„ungelesen" und „ungeschrieben" ist hier als Zeitachse zu sehen.

Und `cron.job.remove` mit `CronJob#15 · job: H` zeigt den Fall, für den man ein
Protokoll überhaupt aufschlägt: **Die Kennung steht da, obwohl es die Zeile
nicht mehr gibt.**

---

## 1e. Der Lauf im Ganzen

**Fünf Punkte, vier erfüllt, einer nicht** — und **sechs Befunde**, von denen
drei erst durch diesen Lauf entstanden sind:

| # | Punkt | |
|---|---|---|
| 1 | Suche ohne und mit Häkchen | **erfüllt** |
| 2 | Vorschau auf der Cronseite | **erfüllt** (alle drei Teilschritte) |
| 3 | Entprellung | **erfüllt** — 1 gegen 20 |
| 4 | `/audit` bei 390 px | **nicht erfüllt — Befund 6**, behoben, wartet auf eine Fassung |
| 5 | Protokollzeile nennt ihr Stück | **erfüllt** |

**Was dieser Lauf über sich selbst sagt:** Von den sechs Befunden stecken
**drei im Prüfmittel oder in meiner eigenen Anweisung** — der doppelt eingefügte
Zähler (Punkt 3), der Wettlauf gegen den Dienststart (Befund 5), und der
Aufsatz aus der Zwischenablage (Punkt 1). Zwei weitere sind Folgen von
Behebungen aus dem vorigen Lauf: Befund 6 kommt aus der Spalte, die Befund 7
behoben hat, und Befund 4 kam aus der Gegenprobe zu Befund 1.

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.**

---

## 2. Die Befunde

*(Je Befund: was gesehen wurde, welche Zahl dazugehört, und ob er am Panel, am
Prüfmittel oder am Kriterium liegt.)*

### Befund 1 — nach dem Update gab jede Seite einen 500er

**Gesehen am 21. August, unmittelbar nach dem Einspielen von `v0.6.0-rc.21`.**
Die Anmeldeseite eingeschlossen. Im Journal:

    There is no existing directory at
    "/opt/srvpanel/releases/0.6.0-rc.21/storage/logs"
    and it could not be created: Permission denied

Die Anwendung kam nicht einmal an ihr eigenes Protokoll — `laravel.log` blieb
deshalb leer, und der erste Blick ging ins Leere.

**Was der Zustand war:**

    /var/lib/srvpanel          0755 root:root
    /var/lib/srvpanel/storage  0700 root:root
    …/storage/logs             0700 root:root      schreibbar für srvpanel: nein

**Die Ursache steht in einer Unit und nicht im Panel.**
`srvpanel-agentd.service` läuft als root und trug:

    LogsDirectory=srvpanel
    StateDirectory=srvpanel

`StateDirectory=X` heisst für systemd „`/var/lib/X` gehört diesem Dienst" — und
systemd zieht dann bei **jedem Start** den Modus auf `StateDirectoryMode` nach,
dessen Vorgabe `0755` ist. Fünf Stellen im Projekt sagen etwas anderes:
`nfpm.yaml`, `postinstall.sh:95`, `testbed.sh` und `PackagingTest`.

> **Ein Verzeichnis, dessen Rechte an zwei Stellen festgelegt werden, hat die
> Rechte der Stelle, die zuletzt läuft.**

**Gemessen auf `cloudsrv24`**, und die Messung hat die Hälfte meiner Vermutung
widerlegt:

    vorher   750 srvpanel:srvpanel
    systemctl restart srvpanel-agentd
    nachher  755 srvpanel:srvpanel

Der **Modus** kippt, der **Eigentümer** nicht. Meine Behauptung, systemd ziehe
rekursiv auf root nach, war falsch; wodurch die Verzeichnisse zusätzlich
`root:root` wurden, ist nicht mehr feststellbar — der Zustand war beim ersten
Rettungsversuch schon überschrieben.

> **Was man beim Aufräumen misst, ist nicht mehr das, was kaputt war.**

**Nachgeprüft auf `cloudsrv24` gegen `v0.6.0-rc.22`**, mit demselben Griff, der
es umgeworfen hat:

    750 srvpanel:srvpanel      750 srvpanel:srvpanel
    700 srvpanel:srvpanel  →   700 srvpanel:srvpanel
              systemctl restart srvpanel-agentd

Vorher und nachher identisch. **Befund 1 ist damit nicht nur behoben, sondern
belegt** — und zwar an der Stelle, an der er entstanden ist, nicht nur in der
CI.

> **Eine Behebung ist keine Messung.**

**Behoben:** Die beiden Direktiven sind aus der Unit. Der Agent braucht sie
nicht — er liest weder `$STATE_DIRECTORY` noch `$LOGS_DIRECTORY`, sondern trägt
seine Pfade absolut (`Config::$logFile`, `Dump::ROOT`, `Staging::ROOT`), und
`postinstall.sh` legt beide Verzeichnisse mit dem gewollten Eigentümer an.
`RuntimeDirectory` bleibt: `/run` ist ein tmpfs, und `0755` steht dort
ausdrücklich statt als Vorgabe.

### Befund 2 — vier grüne Installationsläufe haben es durchgelassen

`packaging/testbed.sh` prüft `/var/lib/srvpanel` auf `750 srvpanel:srvpanel` —
und stand mit dieser Prüfung **hinter** `apt-get remove srvpanel`. Zu diesem
Zeitpunkt läuft `srvpanel-agentd` nicht mehr und startet nie wieder. Gemessen
wurde also der eine Augenblick, in dem der zweite Eigentümer schläft.

> **Eine Prüfung, die zum falschen Zeitpunkt misst, misst einen Zustand, den es
> im Betrieb nie gibt.**

Der Prüfstand misst jetzt an drei Stellen: bei laufenden Diensten, **nach einem
Neustart des Agenten** — das war der Griff, der es umwarf — und wie bisher nach
dem Entfernen.

### Der Wächter, und die zwei Löcher darin

`ServiceDirectoryTest` braucht keinen Zeitpunkt: Er vergleicht die **Absichten**.
Für jede `StateDirectory`/`LogsDirectory`/`CacheDirectory`/`Configuration­Directory`
einer Unit rechnet er Pfad, Modus (`…DirectoryMode`, Vorgabe `0755`) und
Eigentümer (`User=`, Vorgabe `root`) aus und hält sie gegen das, was
`nfpm.yaml` und `postinstall.sh` für denselben Pfad sagen. Zwei
widersprüchliche Absichten sind schon vor dem ersten Start ein Fehler.

**Er hatte beim Bauen zwei Löcher, und beide fand erst der Gegenbruch:**

Die Untergrenze stand über der **vereinigten** Menge aus beiden Quellen. Zerstört
man die Auslese von `nfpm.yaml`, hält `postinstall.sh` die Zahl allein — der
Wächter war grün für eine halb blinde Messung.

> **Eine Untergrenze über zwei Quellen fängt den Ausfall einer von beiden nicht
> — die andere zahlt für sie mit.**

Und die Reihenfolgeprüfung las `strpos`, also das **erste** Vorkommen. Schiebt
man eine der beiden Messungen hinter das Entfernen, steht die andere noch davor
und deckt sie zu.

> **Eine Prüfung über das erste Vorkommen sagt nichts über das zweite — und das
> zweite ist das, das umzieht.**

**Und ein drittes Mal in derselben Stunde derselbe Satz:** Der Wächter war beim
ersten Anlauf rot, weil `strpos` das `apt-get remove` in **meinem eigenen
Kommentar** fand — in dem Satz, der erklärt, dass die Prüfung früher dort stand.

> **Ein Wächter, der eine Datei liest, liest auch, was jemand über sie
> geschrieben hat.**

### Befund 3 — der Prüfstand im Repo läuft in der CI gar nicht

`packaging/testbed.sh` wird im Workflow **nur geshellcheckt**. Die vier
Installationsläufe tragen eine **eigene, eingebaute Fassung** derselben Schritte
(`.github/workflows/ci.yml`, Zeilen 325–529). Meine Verbesserung an Befund 2
ging damit in die Fassung, die niemand ausführt — und `PackagingTest` prüft
ebenfalls gegen sie.

> **Zwei Fassungen derselben Prüfung, und nur eine läuft — die andere ist eine
> Zusage ohne Deckung.**

**Behoben, soweit es ohne Umbau geht:** Der Schritt „Ein Neustart des Agenten
nimmt nichts mit" steht jetzt im **Workflow**, also in der laufenden Fassung,
und `ServiceDirectoryTest::test_the_run_that_actually_runs_checks_the_restart`
hält ihn dort fest.

**Offen und eine Entscheidung des Betreibers:** ob die CI künftig
`packaging/testbed.sh` *aufrufen* soll, statt eine zweite Fassung zu pflegen.
Das ist ein Umbau am Workflow und keine Fehlerbehebung; ich habe ihn nicht von
mir aus gemacht.

### Befund 4 — ein Neustart des Agenten nahm den Socket von PHP-FPM mit

**Gesehen am 21. August nach dem Einspielen von `v0.6.0-rc.22`**, ausgelöst
durch meine eigene Gegenprobe zu Befund 1. nginx meldete **502 Bad Gateway**,
und beide Dienste meldeten dabei `active`:

    srvpanel-web      active (running) seit 13:39:57   idle: 3, Requests: 2
    srvpanel-agentd   active (running) seit 13:40:11
    /run/srvpanel     drwxr-xr-x root root   Aug 21 13:40

**Die Ursache:**

    # packaging/etc/fpm.conf
    listen = /run/srvpanel/fpm.sock
    pid    = /run/srvpanel/fpm.pid

`/run/srvpanel` ist das `RuntimeDirectory` von **`srvpanel-agentd`**, und
systemds Vorgabe `RuntimeDirectoryPreserve=no` löscht es beim **Stoppen** der
Unit mitsamt Inhalt. Ein `systemctl restart srvpanel-agentd` heisst also:

1. Agent stoppt → `/run/srvpanel` fällt weg, **`fpm.sock` mit**
2. Agent startet → das Verzeichnis entsteht neu und leer (Zeitstempel 13:40)
3. PHP-FPM läuft weiter und legt seinen Socket **nicht** neu an
4. nginx findet nichts mehr → 502

> **Ein Verzeichnis, das einem Dienst gehört, nimmt beim Neustart mit, was ein
> anderer hineingelegt hat.**

**Behoben mit `RuntimeDirectoryPreserve=yes`.** Gefahrlos, und beides ist
nachgesehen statt angenommen: `/run` ist ein tmpfs, die Zusage von
`Credentials::DIRECTORY` („überlebt keinen Neustart") gilt weiter für den
Rechnerneustart; und ein liegengebliebener `agent.sock` hält den Agenten nicht
auf, weil `Daemon::listen()` ihn vor dem Binden entfernt und das Verzeichnis
notfalls selbst anlegt.

**Nachgeprüft auf `cloudsrv24` gegen `v0.6.0-rc.23`:**

    systemctl restart srvpanel-agentd
    sleep 2
    ls -l /run/srvpanel/
      srw-rw---- root     srvpanel   agent.sock     ← neu gebunden
      -rw-r--r-- root     root       fpm.pid
      srw-rw---- srvpanel www-data   fpm.sock       ← hat den Neustart überlebt
    curl .../health
      {"ready":true,"app":"0.6.0-rc.23","agent":"reachable"}

Kein Anfassen von `srvpanel-web` nötig. **Befund 4 ist belegt.**

**Der Wächter dazu fragt nicht nach dem Namen, sondern nach dem Nachbarn:**
Schreibt irgendetwas ausserhalb der Unit in ihr Laufzeitverzeichnis, muss
`RuntimeDirectoryPreserve` gesetzt sein. Wer es für sich allein hat, darf es
weiter räumen lassen.

**Und die Gegenprobe hat einen Fehler im Wächter gefunden**, bevor er
ausgeliefert war: Er zählte `agent.json` als fremden Schreiber — die eigene
Konfigurationsdatei des Dienstes, der das Verzeichnis anmeldet. Er wäre rot
gewesen, ohne dass es einen Nachbarn gibt.

> **Ein Wächter, der aus dem falschen Grund rot ist, wird beim nächsten Umbau
> aus dem falschen Grund grün.**

Erkannt wird sie jetzt daran, dass die Unit sie in ihrem `ExecStart` nennt.

### Befund 6 — die Spalte „Einzelheiten" schiebt die Kärtchen auseinander

**Gesehen bei 390 px auf `/audit`, hell und dunkel dieselben Zahlen.**
`dokument: 0`, `gegenprobe: 200/200`, `versteckt: 2` — und `schiebt` mit einem
guten Dutzend Einträgen:

    table.stacks   überlauf 145
    tbody          überlauf 145
    tr:6, tr:10, tr:20, …
    td.quiet:5     überlauf 150–160
       „Fingerprint: SHA256:Sn3W6HvtgEGjDuvTnuZvc7Zys8zk1ndfoNv9EADbKjs"

**Die Spalte „Einzelheiten" ist meine Behebung von Befund 7** aus `docs/66` —
und sie bringt lange Kennungen ohne ein einziges Leerzeichen in einen
Kärtchenmodus, der sie nicht brechen kann.

**`dokument` bleibt dabei 0**, weil der Rollbehälter es auffängt. Der Fund ist
also nur in `schiebt` zu sehen; die Seite meldet sich nicht. Genau dieser Satz
steht seit `docs/46 §20.13` im Projekt:

> **Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur keine Zahl,
> die sich beschwert.**

**Die Ursache** steht in `app.css`: `.stacks td` ist im Kärtchenmodus eine
Flexzeile (Beschriftung links, Wert rechts). Der Wert ist ein **anonymes**
Flexkind — nicht ansprechbar, und sein automatisches Mindestmass folgt der
Mindestinhaltsbreite. Die Ausnahme dafür gab es nur für `.stacks td .ident`
und `.stacks td.ident`; meine Zelle trägt `quiet`.

**Behoben mit einer Regel an `.stacks td` statt einer sechsten Ausnahme.**
Dieselbe Ausnahme gab es vorher fünfmal — `.ident`, `.stacks td .ident`,
`.section-head h2`, `.cell-value`, `.subline` —, jede für einen *bestimmten*
Inhalt, der zu lang war. Die sechste hätte `class="ident"` auf eine Zelle
gesetzt, die keine Kennung enthält: Monospace für einen Satz.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

**Gemessen im echten Chromium gegen das gebaute Stylesheet, 390 px:**

    normal        schiebt: [table.stacks 64, tbody 64, tr 65, td.quiet 79]
    break-word    schiebt: [table.stacks 64, tbody 64, tr 65, td.quiet 79]
    anywhere      schiebt: []

**`break-word` verhält sich Pixel für Pixel wie gar keine Regel.** Nur
`anywhere` verkleinert die Mindestinhaltsbreite — der Unterschied steht in der
Spezifikation und ist hier nachgemessen, statt aus ihr zitiert zu werden.

> **Zwei Werte, von denen einer richtig aussieht und nichts tut, sind schlimmer
> als ein fehlender.**

`MobileLayoutTest::test_a_stacked_cell_can_break_a_long_value` rechnet die
Kaskade nach — nicht das Vorhandensein der Zeile, sondern welche Regel an einer
gestapelten Zelle **gewinnt**. Zwei Brüche: die Regel weg, und `break-word`
statt `anywhere`.

**Punkt 4 ist damit nicht erfüllt** und wartet auf eine Fassung mit dem Fix.

### Befund 5 — meine eigene Prüfung rannte gegen den Start

**Beim ersten Nachmessen von Befund 4 fehlte `agent.sock`, und `/health` gab
503.** Es sah nach einem zweiten Fehler aus und war keiner:
`srvpanel-agentd.service` ist **`Type=simple`**. `systemctl restart` kehrt
zurück, sobald der Prozess *läuft* — nicht, wenn er seinen Socket gebunden hat.
Dazwischen liegen ein PHP-Start, das Lesen der Konfiguration, `unlink` und
`bind`. Zwei Sekunden später war alles da.

> **Eine Prüfung, die vom Zeitpunkt abhängt, ist beim nächsten Lauf eine
> andere.**

**Und derselbe Wettlauf steckte in dem Schritt, den ich einen Beitrag vorher in
die CI gesetzt hatte** — `restart`, `is-active`, `stat`, `curl`, ohne einen
Augenblick dazwischen. Bei `Type=simple` sagt `is-active` sofort „aktiv". Dass
er auf vier Plattformen grün war, ist kein Beleg für Verlässlichkeit, sondern
für die Laufzeiten von `docker exec`.

> **Ein Lauf, der aus Glück grün ist, ist beim nächsten Mal aus Pech rot — und
> beide Male hat sich nichts geändert.**

**Behoben:** Der Schritt **wartet** auf `/run/srvpanel/agent.sock`, mit einer
Frist von 30 Sekunden und einem Blick ins Journal, wenn sie reisst — ein echtes
Nichtstarten bleibt damit rot und wird nicht zur Geduldsprobe. Dazu prüft er
jetzt ausdrücklich, dass **`fpm.sock`** den Neustart überlebt hat; das ist
Befund 4, und ohne diese Zeile fiele ein Rückfall nicht auf.

Beides steht auch in `packaging/testbed.sh` — der Fassung, die niemand fährt
(Befund 3). Solange es zwei gibt, laufen sie wenigstens nicht auseinander.

### Was ich beim Suchen falsch gemacht habe

Drei Vermutungen, alle drei widerlegt, bevor die Ursache feststand: der Modus
`0750` sperre „andere" aus (nein — der Dienst *ist* der Eigentümer);
`postinstall.sh` sei abgebrochen (nein — dpkg meldet `installed`); `chown -R`
folge dem `storage`-Verweis (nein — mit Gegenprobe `-RL` gemessen).

Zwei Behauptungen im Quelltext, die ich nie gemessen hatte, standen dabei als
Kommentar da und haben mich in die Irre geführt, bis ich sie gemessen habe.
Beide stimmten am Ende — das macht sie nicht besser.

> **Wissen aus zweiter Hand sieht aus wie Wissen, auch wenn es das eigene von
> gestern ist.**

**Und ein Fehler, der den Server länger unten gehalten hat als der Befund
selbst:** Mein Rückweg lautete „zeig `current` wieder auf rc20". Den gibt es
nicht mehr — `prune_releases()` löscht beim Update jede Fassung ausser der
aktiven. Der Befehl führte von einem 500er zu „File not Found".

> **Ein Rückweg, den man nicht nachgesehen hat, ist ein zweiter Ausfall.**

---

## 3. Was offen bleibt

*(Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.)*
