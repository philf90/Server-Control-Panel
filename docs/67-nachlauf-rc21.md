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
| Fassung | `v0.6.0-rc.21` | |
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
| 1 | Suche ohne und mit Häkchen | beide Male eine Trefferliste | | |
| 2 | Vorschau auf der Cronseite | Satz und drei Fälligkeiten | | |
| 3 | Entprellung | deutlich unter 20 Anfragen, mindestens 1 | | |
| 4 | `/audit` bei 390 px | `dokument: 0` | | |
| 5 | Eine Protokollzeile nennt ihr Stück | `job: … · schedule: …` | | |

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
