# 94 — Protokoll des Nachlaufs zu `0.7.3-rc.4`

Der Lauf ist `docs/93`, gefahren am **31. August 2026** auf `cloudsrv24` gegen
`0.7.3-rc.4`. Gegenstand sind die Befunde 5, 6 und 7 aus `docs/91 §20` und die
beiden Wege zurück aus `docs/92`.

---

## 1 · Was das Ausschreiben gefunden hat, bevor der Lauf begann

Drei Fehler, alle in **meiner** Vorschrift, alle vor dem ersten Befehl gefunden
und in `docs/93` berichtigt.

**a) Punkt 1 war so nicht fahrbar.** `docs/91 §20.5` verlangte, ein wartender
oneshot-Dienst sei „auf **beiden** Seiten grün". Die Übersicht zeigt aber nicht
alle sechzehn Units, sondern was `Catalog::essential()` nennt: `srvpanel-agentd`,
nginx, mariadb — dazu einen PostgreSQL-Cluster. **Keine davon ist
`Type=oneshot`.**

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

**b) Der Unitname in §2c war geraten.** Dort stand `srvpanel-apt-*`;
`AptLock::UNIT_PREFIX` lautet `srvpanel-update-`.

> **Ein Muster, das nichts findet, beantwortet jede Frage mit Ja.**

**c) §2d belegte weniger als sein Wortlaut versprach** — siehe §4 unten, wo der
Lauf die Frage dann selbst entschieden hat.

---

## 2 · Punkt 1 — erfüllt

**a) Der Gleichlauf im Ruhezustand.**

| Seite | Unit | Zustand |
|---|---|---|
| Übersicht | `srvpanel-agentd.service` | läuft |
| Übersicht | `nginx.service` | läuft |
| Übersicht | `mariadb.service` | läuft |
| Übersicht | `postgresql@16-main.service` | läuft |
| Dienste | `srvpanel-agentd.service` | läuft |

Wo bis `0.7.3-rc.3` das englische `active` stand, steht `läuft` — in allen vier
Zeilen, auch bei den fremden Units. **Alle Dienste** steht am Kopf des Bereichs.

**b) Der Zustandswechsel**, über `srvpanel-metrics.service`:

| | Zustand | PID | Neustarts | Meldung oben |
|---|---|---|---|---|
| gestoppt | **gestoppt**, rot | — | 0 | „1 Dienst läuft nicht." |
| wieder an | **läuft**, grün | 904859 | 0 | „Jeder Dienst ist in Ordnung, und jeder Timer hat einen Termin." |

Genau, was die Unit-Datei versprach: `Type=simple`, kein Timer, also keine
Nachsicht. `Restart=always` hat den Stopp nicht überfahren — es gilt nur für ein
Ende, das der Dienst selbst herbeiführt.

**Und das Bild zeigt, was der A2-Lauf nicht herstellen konnte.** In derselben
Tabelle, im selben Augenblick: `srvpanel-metrics` **gestoppt** (rot),
`srvpanel-usage` **wartet auf seinen Timer** (grün). Beide stehen auf
`inactive`. Die Nachsicht unterscheidet also wirklich, statt pauschal zu decken;
im A2-Lauf gab es nur 0 oder 4 Ausfälle, nie den Kontrast nebeneinander.

Dazu der gezählte Numerus: **„1 Dienst läuft nicht"**, Singular — der Fehler aus
P5c („geschätzt 1 Zeilen") ist an dieser Stelle nicht wieder entstanden. Gezählt
wird über `rang`, die vier wartenden oneshot-Dienste sind zu Recht nicht dabei.

### Beobachtung 1 — was übersetzt wird und was zitiert

Die **Beschreibung** fremder Units ist englisch: „A high performance web server
and a reverse proxy server". Das ist **kein** Befund und der Unterschied ist der
Grund, warum `ServicesViewTest::test_no_page_prints_a_raw_unit_state`
ausdrücklich nur `active_state`, `sub_state` und `load_state` abdeckt:

> **Ein Wert, den das Panel kennt, gehört übersetzt; einer, den es nur
> weiterreicht, gehört zitiert.**

`active_state` ist eine Aufzählung mit sechs Werten. `Description=` ist Freitext
aus einer fremden Unit-Datei; eine Übersetzung wäre eine Erfindung und wiche von
dem ab, was `systemctl status` daneben zeigt.

### Was Punkt 1 nicht belegt

Den Gleichlauf **im Zustandswechsel**. Er ist nicht herstellbar, und der Grund
ist struktureller Natur: Auf beiden Seiten stehen genau drei Units — `agentd`,
`nginx`, `mariadb` — und **jede von ihnen trägt das Panel**, das die beiden
Seiten rendert.

> **Wenn jede Unit, die zwei Seiten gemeinsam haben, für beide Seiten gebraucht
> wird, ist der Gleichlauf im Zustandswechsel nicht herstellbar, ohne den
> Prüfling abzuschalten.**

Belegt ist deshalb in zwei Hälften: der Gleichlauf im Ruhezustand über drei
Units, der Zustandswechsel auf einer Seite über eine vierte.

---

## 3 · Punkt 2 — erfüllt, Befund 6 ist auf dem Server behoben

| Vorgang | Zeit | Zustand | Meldung |
|---|---|---|---|
| 727 | 20:44:00–18 | fertig | „19 von 19 Aktualisierungen eingespielt, 0 bleiben offen." |
| **728** | 20:44:34–51 | **fertig** | **„Es stand nichts an — offene Aktualisierungen: 0."** |

Genau die Zeile, die bis `0.7.3-rc.3` `fehlgeschlagen` meldete.

`systemctl list-units 'srvpanel-update-*' --all` → `0 loaded units listed`;
keine Unit blieb als gescheitert liegen.

### Befund 1 — meine Farberwartung war falsch, nicht die Seite

`docs/93 §2b` erwartete die Meldung **bernsteinfarben**. Sie ist grün, und das
ist richtig: `notizart` färbt bernstein nur, wenn ein `warning` dasteht, und
„Es stand nichts an" ist kein Vorbehalt auf einem halb gelungenen Lauf, sondern
ein vollständig gelungener Lauf ohne Anlass.

Geschrieben hatte ich die Erwartung aus der **Form des Satzes** statt aus dem
Code, der die Farbe entscheidet — dieselbe Sorte Fehler wie die drei
Unit-Datei-Erwartungen in A2.

---

## 4 · Befund 2 — die Unterscheidung stand schon da, und niemand hat sie gelesen

`docs/93 §2d` erwartete bei `srvpanel update` auf aktuellem Stand einen
Fehlschlag, und den gab es:

    apt-run: Der Lauf hat nichts verändert — Fassung vorher wie nachher: 0.7.3~rc.4.

Damit ist belegt, dass die Behebung von Befund 6 **nicht in den Fassungsmodus
geleckt ist**. Beim Ausschreiben hatte ich das mit „nicht unterscheidbar, also
sichere Richtung" begründet: Eine Versionsnummer ist nie `0`, es gibt keinen
Wert, an dem „es stand nichts an" ablesbar wäre.

**Das war falsch, und der Lauf selbst zeigt es.** Drei Zeilen über dem Urteil
steht im selben Log:

    srvpanel ist schon die neueste Version (0.7.3~rc.4).
    0 aktualisiert, 0 neu installiert, 0 zu entfernen und 0 nicht aktualisiert.

Die Auskunft, die „schon aktuell" von „nicht geschafft" trennt, entsteht im Lauf
und wird weggeworfen.

> **Eine Unterscheidung, die im Lauf schon dasteht, muss nicht hergestellt
> werden — nur gelesen.**

Damit ist es kein benannter Verzicht mehr, sondern dieselbe Lücke wie Befund 6,
eine Ebene weiter. Zu bauen bleibt: `apt-run` liest im Fassungsmodus die Zeile
`ist schon die neueste Version` und meldet dann „Es stand nichts an" statt eines
Fehlschlags.

---

## 5 · Befund 3 — die Herkunft veraltet bei einer Navigation, die der Server nicht sieht

**Vorgang 727** trägt `← /updates` — richtig, der Knopf steht dort.
**Vorgang 728** trägt `← /operations/727` — und der Knopf, der ihn ausgelöst
hat, steht ebenfalls auf `/updates`.

`RememberPageUrl` setzt `previousUrl` bei jeder Inertia-GET-Anfrage, die eine
Seite rendert. Die Kette:

1. `/updates` geladen → `previousUrl = /updates`
2. Installieren → Vorgang 727, `origin = /updates` ✓
3. Weiterleitung → GET `/operations/727` → `previousUrl = /operations/727`
4. zurück nach `/updates` **über den Zurück-Knopf des Browsers** — Inertia stellt
   aus dem History-Zustand her, der Server sieht **keine Anfrage**
5. Installieren → Vorgang 728, `origin = /operations/727` ✗

> **Eine Herkunft, die der Server führt, veraltet bei jeder Navigation, die der
> Server nicht sieht.**

Es ist der Verwandte des Fehlers aus `docs/45`: Dort war `_previous.url` blind
für Inertia-XHR, hier ist es blind für History-Navigation. `RememberPageUrl` hat
die erste Hälfte behoben und kann die zweite nicht sehen — es läuft nur, wenn
eine Anfrage kommt.

**Und die Ironie gehört zum Befund:** Der Weg, den A ersetzen soll — der
Zurück-Knopf —, ist genau der, der A falsch macht. Wer den neuen Verweis
benutzt, bekommt die richtige Herkunft.

**Der Entwurf, der das behebt, ist nicht „an 21 Aufrufstellen mitgeben".** Die
Seite, die absetzt, kennt ihre eigene Adresse; ein einziger Abfangpunkt in
`app.ts` kann sie an jede Inertia-Anfrage hängen, und `Operations::origin()`
liest sie statt der Sitzung — mit derselben Prüfung, die heute schon dafür
sorgt, dass nur ein Pfad und keine fremde Adresse ankommt. **Eine Stelle bleibt
eine Stelle.**

---

## 6 · Wunsch 1 — `srvpanel update` sagt nicht, wie es ausgegangen ist

**Gemeldet vom Betreiber während des Laufs.** `srvpanel update` druckt die Unit
und den Pfad zum Log und endet; was der Lauf bewirkt hat, steht nur im Log.

**Das ist Form A aus `docs/86 §5`, an der einen Stelle, die die Behebung nie
bekommen hat.**

> **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt über den Ausgang
> dessen, was er abgesetzt hat, nichts.**

`AwaitDispatchedRun` behebt das seit dem 28. August für die Vorgänge des Panels.
Die Kommandozeile blieb aussen vor — und dort wiegt es schwerer: `srvpanel
update && …` in einem Skript bekommt heute `SUCCESS` für einen gescheiterten
Lauf, weil `Update::handle()` nur das Absetzen meldet.

**Das meiste steht schon da:** `Outcome::verdict()` liest das Urteil,
`PanelUpdate` leert sein Log zu Beginn jedes Laufs (ein Versatz wird also nicht
gebraucht), und die Versionsnummern stehen wörtlich im Urteil.

**Was vorher gemessen werden muss, und es entscheidet den Entwurf:** Die Meldung
behauptet „diese Sitzung endet vorher". Ob der CLI-Prozess den Symlink-Wechsel
und den Neustart wirklich nicht überlebt, hat nie jemand nachgesehen.

> **Ein Satz, den die Oberfläche behauptet und den niemand gemessen hat, ist
> eine Vermutung mit Fussnote.**

Der Fortschrittsbalken berührt ausserdem eine offene Entscheidung: **bindet
`docs/19` die Kommandozeile?** Farbe, Balken und Wortwahl im Terminal sind
bisher nirgends geregelt.

### Warum ein naives „warten" es schlechter machen kann

Heute übergibt der Befehl sauber: Unit, Logpfad, Rückweg. Wartet er stattdessen
und stirbt dabei, sieht der Betreiber einen Befehl, der hängt und dann abbricht.

> **Eine Verbesserung, die im Fehlerfall weniger liefert als der Zustand davor,
> ist keine.**

Deshalb steht die Messung vor dem Bau und nicht daneben.

### M1 — die Messung, die den Entwurf entscheidet

**Der Wrapper ruft `/opt/srvpanel/current/artisan`, also über den Symlink.** Wo
der Autoloader eines laufenden Prozesses danach wurzelt, entscheidet, ob eine
Warteschleife überhaupt Code nachladen kann. Das ist nicht nachlesbar, sondern
zu messen — und zwar **beim nächsten Update**, weil es einen echten Wechsel
braucht.

**Vorher:**

    BISHER=$(readlink -f /opt/srvpanel/current)
    echo "$BISHER"
    srvpanel version

    cd "$BISHER"
    nohup /opt/srvpanel/bin/php -r '
    $alt = getenv("BISHER");
    $bis = time() + 300;
    while (time() < $bis) {
        printf("%s  pid=%d  cwd=%s  artisan=%d  log=%d\n",
            date("H:i:s"), getmypid(),
            (@getcwd() ?: "FORT"),
            (int) is_readable($alt."/artisan"),
            (int) is_readable("/var/log/srvpanel/upgrade.log"));
        sleep(5);
    }' > /tmp/ueberlebt.log 2>&1 &

**Dann** `srvpanel update`, **danach** `cat /tmp/ueberlebt.log` und
`srvpanel version`.

**Beide Pfade sind absolut und gehen nicht durch den Symlink** — das ist der
Punkt. Die erste Fassung dieser Vorschrift fragte
`is_readable("/opt/srvpanel/current/composer.json")` und hatte kein `cd`:

> **Eine Spalte, die ihren Wert nicht annehmen kann, ist keine Messung.**

`current` zeigt nach dem Wechsel auf die **neue** Fassung und ist immer lesbar;
`cwd` stand auf `/root` und wäre nie `FORT` geworden. Beide hätten „alles in
Ordnung" gemeldet, gleich was geschieht.

| Spalte | beantwortet |
|---|---|
| Zeilen bis zum Ende | überlebt der Prozess den Neustart überhaupt |
| `cwd=FORT` | wird das alte Fassungsverzeichnis unter ihm abgeräumt |
| `artisan=0` | kann er danach noch aus **seiner** Fassung nachladen |

**Die Gegenprobe ist `srvpanel version` vorher und nachher.** Sind sie gleich,
hat kein Wechsel stattgefunden — dann misst der Lauf nichts, und drei Spalten
voller Einsen bedeuten nichts.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Was aus dem Ergebnis folgt:**

- `artisan=0` nach dem Wechsel → die Warteschleife darf **nichts** nachladen.
  Alles, was sie braucht, wird vor dem Absetzen geladen. Das ist eine
  Bauvorschrift, die man vorher kennen muss — nachträglich fällt sie als
  Absturz mitten im Update auf.
- Der Prozess stirbt sofort → die Vorgabe bleibt, wie sie ist, und der Ausgang
  kommt über einen zweiten Griff statt über eine Warteschleife.

### M1 — gemessen am 1. September 2026, quer über `rc.4` → `rc.5`

    05:41:02  pid=934334  cwd=/opt/srvpanel/releases/0.7.3-rc.4  artisan=1  log=1
    …
    05:41:37  pid=934334  cwd=/opt/srvpanel/releases/0.7.3-rc.4  artisan=1  log=1
    05:41:42  pid=934334  cwd=FORT                               artisan=0  log=1
    …
    05:42:17  pid=934334  cwd=FORT                               artisan=0  log=1

**Gegenprobe:** `srvpanel version` stand vorher auf `0.7.3-rc.4`, nachher auf
`0.7.3-rc.5`. Der Wechsel hat stattgefunden; die Nullen bedeuten etwas.

| | |
|---|---|
| **Der Prozess überlebt** | dieselbe PID vor und nach dem Umschalten, fünfzig Sekunden weiter noch am Leben |
| `cwd=FORT` ab 05:41:42 | das alte Fassungsverzeichnis wird unter ihm abgeräumt |
| `artisan=0` ab 05:41:42 | er kann **nichts** mehr aus seiner Fassung nachladen |
| `log=1` durchgehend | das Log bleibt lesbar |

**Die Meldung „diese Sitzung endet vorher" war falsch.** Sie stand seit P0 im
Kopf der Klasse und in der Ausgabe an den Betreiber, und niemand hat sie
geprüft — der Befehl hat nie gewartet, also konnte es nie auffallen.

> **Ein Satz, den die Oberfläche behauptet und den niemand gemessen hat, ist
> eine Vermutung mit Fussnote.**

### M2 — die Lücke in M1, gemessen im selben Zug

Der Messprozess lief als **root**. `srvpanel update` läuft über `setpriv` als
**`srvpanel`**, und `update.log` legt eine root-Unit an.

> **Ein Leseversuch als root sagt nichts über einen als `srvpanel`.**

Derselbe Schnitt wie beim ACME-Befund aus `docs/78` — dort war der Weg das
Problem, hier wäre es der Modus gewesen.

    -rw-r--r-- 1 root root 2739 Sep  1 07:41 /var/log/srvpanel/update.log
    setpriv --reuid=srvpanel … test -r … → lesbar

`/var/log/srvpanel` gehört `srvpanel` (0750), die Datei ist `0644`. Der Entwurf
trägt.

### Wunsch 1 ist gebaut

`srvpanel update` wartet jetzt, zeigt die Zeilen des Laufs, nennt am Ende das
Urteil und **gibt den passenden Rückgabewert zurück**. `--no-wait` setzt nur ab
wie bisher.

**Drei Entscheidungen, die aus den Messungen folgen:**

1. **`vorladen()` steht vor dem Absetzen.** `agent/` liegt im
   Fassungsverzeichnis, und M1 sagt `artisan=0`. Ein `class_exists()` nach dem
   Umschalten scheitert lautlos, und der Befehl stürbe mitten im Update.
2. **Versatz 0 genügt.** `PanelUpdate` leert sein Log mit `@unlink()` im
   Agenten, **vor** `systemd-run` — synchron beim Absetzen. Ein Urteil eines
   früheren Laufs ist damit nicht zu erwischen. Der Wächter hält beide Dateien
   zusammen: Zöge das Leeren in die Unit, läse der Befehl beim ersten Blick ein
   fremdes Urteil.
3. **Kein Fortschrittsbalken.** `apt` nennt keinen Anteil, und dieser Befehl
   kennt auch keinen.

   > **Ein Balken, der keinen Anteil kennt, behauptet einen.**

   Gezeigt werden die Zeilen selbst, sobald sie im Log stehen — das ist der
   Fortschritt, den es wirklich gibt.

**Und die abgelaufene Frist ist kein Erfolg.** Ein Rückgabewert kennt kein „ich
weiss es nicht".

> **Ein Rückgabewert, der „ich weiss es nicht" nicht ausdrücken kann, muss sich
> entscheiden — und die sichere Seite ist die, die den Aufrufer anhalten
> lässt.**

**Beim Gegenprüfen: ein Eingriff, der wirkte und nichts belegte.** Der erste
Wurf des fünften Bruchs schob `@unlink` nur näher an `systemd-run` — textlich
immer noch davor, die Regel also eingehalten. Der Wächter blieb zu Recht grün,
und das sah aus wie ein stumpfer Wächter.

> **Ein Eingriff, der wirkt und nichts belegt, sieht aus wie einer, der
> beisst.**

**Was der Wächter nicht hält:** dass der Prozess den Neustart wirklich
überlebt. Das ist eine Eigenschaft des Servers und keine des Quelltextes — sie
steht als M1 hier und nicht als Zusage im Test.

---

## 6b · Befund 4 — die Herkunft stand an einer von sechzehn Stellen

**Gefunden von Punkt 4c.** Vorgang 729 (`db.dump.create`, ausgelöst auf
`/databases/{id}`) zeigt die Zeile **Sicherung → `p1136_test`** als Verknüpfung
— aber **kein `←`** im Brotkrümel. Vorgang 727 (`system.packages.upgrade`) trug
`← /updates`. Beide waren von einer Seite aus ausgelöst worden.

Der Grund: `Dumps::dispatch()` legt seine Zeile mit
`Operation::query()->create()` selbst an und geht nicht durch
`Operations::dispatch()` — und dort stand die Herkunft. Ausgezählt über `app/`:
**sechzehn** anlegende Stellen, davon **eine** mit Herkunft.

> **Ein Wächter, der prüft, dass *eine* Stelle es tut, hat nicht geprüft, dass
> es *nur eine* Stelle gibt.**

`OperationOriginTest::test_the_origin_is_taken_in_one_place` hiess so und prüfte
das nicht: Er suchte `'origin' => $this->origin(),` in `Operations.php` und fand
es. Im PR zu #194 steht daneben, einundzwanzig Aufrufstellen wären
einundzwanzig Gelegenheiten zu vergessen — geschrieben, ohne nachzuzählen, wie
viele es wirklich sind.

### Warum der Gegenstand da war und die Herkunft nicht

| | Art | gehört wohin |
|---|---|---|
| `subject_type`/`subject_id` | **jede Stelle weiss es anders** — nur der Aufrufer kennt den Gegenstand | an jede Stelle, richtig gebaut |
| `origin` | **überall dasselbe** — die Sitzung weiss es, unabhängig vom Aufrufer | an **eine**, und die war keine |

> **Was jede Stelle anders weiss, gehört an die Stelle. Was überall dasselbe
> ist, gehört an eine — und die muss eine sein, an der niemand vorbeikommt.**

### Behoben am Modell, mit einem Präzedenzfall in derselben Methode

`Operation::booted()` setzt die Herkunft jetzt im `creating`-Ereignis, wenn sie
noch leer ist. Dort steht seit `docs/35` schon `subscription_name` aus genau
demselben Grund — und der Kommentar sagte das wörtlich, mit der Zahl **sechs**.

**Die Zahl war veraltet, und das hat den Befund verdeckt:** Ich habe die
Herkunft nach ihr entworfen.

> **Eine Zahl im Kommentar altert mit dem Code, den sie zählt, und nichts meldet
> es.**

`App\Support\Operations\Origin::current()` trägt das Lesen; `Operations` hat
seine private Methode verloren, damit es keine zweite Fassung gibt.

### Der Wächter hält jetzt beide Richtungen

`test_the_origin_is_taken_on_the_model` prüft, dass das Modell sie setzt **und**
dass keine der anlegenden Stellen sie selbst setzt. Beide Eingriffe beissen.

**Zwei Fehler in ihm selbst, beide beim Gegenprüfen gefunden:**

Der erste Ausdruck kannte `Operation::query()->create(` und `Operation::create(`
— und übersah ausgerechnet `Operations::dispatch()`, das `new Operation([…])`
schreibt.

> **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die Gewohnheit und
> nicht die Regel.**

Der zweite suchte `'origin' =>` in der **ganzen** Datei und meldete
`OperationController` — der die Herkunft im Payload der Seite *liest*.

> **Ein Ausdruck, der eine Zuweisung sucht, findet jede Lesestelle mit, solange
> er den Zusammenhang nicht abgrenzt.**

Gemessen wird jetzt im Argumentblock jeder anlegenden Stelle, über Klammern
gezählt; schliesst eine nicht, meldet der Leser einen Fehlschlag statt eines
leeren Blocks.

### Und ein Fehler beim Gegenprüfen selbst

Der erste Eingriff für die Gegenrichtung traf eine Zielstelle, die es zweimal
gibt — die `assert`-Zeile schlug fehl, der Eingriff wurde **nicht** angewandt,
und der Testlauf daneben meldete `OK (1 test)`.

> **Ein Eingriff, der nicht angewandt wurde, und ein Wächter, der nicht
> zubeisst, sehen im Protokoll gleich aus.**

---

## 7 · Stand

| Punkt | | |
|---|---|---|
| 1 | Befund 5 — dieselbe Unit, dasselbe Wort | ✓ (Gleichlauf im Wechsel nicht herstellbar, §2) |
| 2 | Befund 6 — derselbe Knopf zweimal | ✓ |
| 3 | Befund 7 — 390 px an der echten Seite | **nicht herstellbar** |
| 4 | A und B — der Weg zurück | 4a ✓ · 4b nicht herstellbar · 4c **Befund 4** · 4d erst nach der Behebung |

**Vier Befunde und ein Wunsch**, dazu drei Fehler in der Vorschrift, die vor dem
Lauf gefunden wurden. Befund 1 ist meiner, Befund 2, 3 und 4 sind am Prüfling —
und Befund 4 ist der schwerste, weil er die Zusage trifft, die der PR gemacht
hat.

### Drei Kriterien, die dieser Server nicht erfüllen kann

Punkt 1 (kein `Type=oneshot` auf der Übersicht), Punkt 3 (kein Gegenstand über
25 Zeichen) und Punkt 4b (kein Filter in der Adresse). Alle drei hätte ein Blick
in den Quelltext vor dem Schreiben gezeigt.

> **Ein Lauf, dessen Kriterien man aus dem Merkmal ableitet statt aus dem
> Server, misst das Merkmal am Wunsch und nicht am Bestand.**

**Punkt 4b ist dabei die interessanteste Absage:** Die Filter auf `/updates`
sind `ref`s über einem `computed` und stehen nie in der Adresse. Damit ist das
`split('?')` im Brotkrümel **heute unbenutzt** — nicht falsch, aber belegt nur
im Container. Und der neue Rückweg kann auf dieser Seite den Filter
grundsätzlich nicht wiederherstellen, weil der Filter den Browser nie verlässt.

### Was gegen `rc.5` zu messen bleibt

1. **4c vollständig** — eine Sicherung zeigt `←` **und** den Gegenstand.
2. **4d** — ein Vorgang ohne Sitzung (`srvpanel tls --renew`) zeigt kein `←`.
   Vor der Behebung war das nicht messbar: Vorgang 729 zeigte kein `←` *mit*
   Sitzung, und damit sahen Erfolgs- und Fehlerfall gleich aus.
3. **Befund 3** — die Herkunft nach einer Navigation mit dem Zurück-Knopf.
   Unbehoben und benannt.

---

## 8 · Befund 5 — der Präfix markierte jede Meldung, nicht das Urteil

**Gefahren am 1. September 2026 auf `cloudsrv24`, gegen `0.7.3-rc.6` — der
erste Lauf des neuen Befehls.** Er endete nach zwei Sekunden:

    Das Update läuft als srvpanel-update-a2e0b3dd.
    Das Panel startet dabei neu. Dieser Befehl liest mit und nennt am Ende den Ausgang.

      apt-run: Paketlisten werden aufgefrischt.        ← mitgelesen

      Paketlisten werden aufgefrischt.                 ← als Urteil ausgegeben, grün

    Rückgabewert: 0

`Outcome::verdict()` nimmt die **letzte** Zeile mit dem Präfix `apt-run: `. Am
Ende eines Laufs ist das richtig — währenddessen ist die letzte auch die erste.

> **Ein Leser, der „die letzte Zeile" nimmt, liest während des Laufs die
> erste.**

**Die Warteschleife war damit in genau dem Modus wirkungslos, für den sie
gebaut wurde.** Ausgezählt in `apt-run`: sieben Zeilen tragen den Präfix, fünf
davon beenden den Lauf. Die beiden anderen stehen ausschliesslich im
Fassungsmodus — also dort, wo `srvpanel update` liest. `system.packages.upgrade`
schreibt keine, und `AwaitDispatchedRun` konnte den Fehler deshalb nie sehen.

> **Ein Präfix, der jede Zeile eines Werkzeugs markiert, unterscheidet die
> Meldung nicht vom Urteil.**

### Behoben im Skript, und die Regel ist jetzt eine Eigenschaft

Die beiden Fortschrittszeilen tragen den Präfix nicht mehr; sie stehen
unprefixed im Log, wo apts eigene Ausgabe steht. Dazu zwei Kleinigkeiten, die
die Regel ausnahmslos machen: `fehler()` schreibt `Aufruf falsch — …` (und
steht damit in `Outcome::BAD`, statt als grünes Urteil durchzugehen), und die
beiden durchfallenden Urteile enden ausdrücklich mit `exit 0`.

**Gehalten wird die Eigenschaft und keine Liste.** Eine Aufzählung der fünf
Urteilssätze in PHP wäre eine zweite Fassung dessen, was `apt-run` schreibt —
und die zweite veraltet. `OutcomeTest::test_every_prefixed_line_ends_the_run`
prüft stattdessen, was ein Urteil von einer Meldung unterscheidet: **Es beendet
den Lauf.** Eine sechste Urteilsform ist damit von selbst gedeckt, eine dritte
Fortschrittszeile fällt auf.

Die Gegenrichtung steht daneben: Die Meldung muss noch da sein, nur ohne
Präfix. Sonst wäre die Regel auch dadurch zu erfüllen, dass der Betreiber
nichts mehr sieht.

### Was das für die nächste Fassung heisst

**Das Update von `rc.6` auf `rc.7` wird den Fehler noch einmal zeigen** — und
das ist keine gescheiterte Behebung. Der Befehl, der ein Update ausführt, ist
immer der **schon installierte**: `rc.6`s `Update.php` liest `rc.6`s `apt-run`.
Erst der Sprung von `rc.7` auf `rc.8` läuft mit beiden behobenen Teilen.

> **Eine Behebung an dem Werkzeug, das die Behebung ausliefert, wirkt erst eine
> Fassung später.**

Ein Ausweg wäre eine Ausnahmeliste im Leser für die zwei bekannten Sätze — also
eine zweite Fassung von Wissen über `apt-run`, für genau einen Zyklus. Sie ist
bewusst nicht gebaut.

### Und M1 ist damit weiterhin ungeprüft

Dieser Lauf hat nichts installiert und nichts umgeschaltet. Ob die Warteschleife
einen echten Symlink-Wechsel übersteht — ob `vorladen()` reicht —, ist nach wie
vor offen und braucht `rc.7` → `rc.8`.

> **Ein Lauf ohne den Vorgang, gegen den er sich behaupten soll, prüft die
> Behauptung nicht.**

---

## 8b · Der Befund an der Behebung — shellcheck hat den Zweig rot gemacht

Der PR zu §8 kam mit rotem `Shell-Skripte` zurück. Zweimal **SC2317**, „Command
appears to be unreachable", auf den Rümpfen von `offen()` und `fassung()` —
also auf den beiden Funktionen, die dieses Skript überhaupt tragen.

```
In packaging/bin/apt-run line 111:
    apt-get -s dist-upgrade 2>/dev/null | grep -c '^Inst ' || true
    ^-- SC2317 (info): Command appears to be unreachable.
```

**Der Befund ist nicht das `exit 0`, sondern das, was es sichtbar gemacht hat.**
Gerufen wurden die beiden seit ihrem ersten Tag über `vorher=$($mass)` — einen
Namen in einer Variablen. Für shellcheck ist das kein Aufruf; es hat die Rümpfe
nie als erreichbar gesehen. Gemeldet hat es das trotzdem nicht, solange das
Skript am Ende **durchfallen** konnte: Eine Datei, die ihr Ende erreicht, könnte
eingebunden werden, und dann rufe der Einbindende die Funktionen eben selbst.
Mit dem `exit 0` aus §8 fiel diese Annahme weg.

Gemessen in beide Richtungen, damit die Ursache nicht geraten ist:

| Prüfkörper | SC2317 |
|---|---|
| Fassung vor §8 | keine |
| Fassung vor §8, nur ein `exit 0` angehängt | **zwei** |
| Fassung aus §8, nur das letzte `exit 0` entfernt | keine |

> **Ein Aufruf über einen Namen in einer Variablen ist für ein Werkzeug keiner —
> gemeldet wird er erst, wenn nichts mehr die Annahme trägt, dass jemand ihn von
> aussen macht.**

**Behoben ist es am Aufruf und nicht mit einer Unterdrückung.** Ein
`# shellcheck disable=SC2317` hätte die Meldung genommen und die Blindheit
gelassen: Ab da wäre auch wirklich toter Code in diesen beiden Funktionen nicht
mehr aufgefallen. Stattdessen steht die Fallunterscheidung jetzt als `messen()`
da, `$mass` ist wieder das, was es an den zwei Vergleichen weiter unten ohnehin
war — ein Schalter und kein Befehl.

Belegt ist der Umbau an fünf Wegen mit Attrappen für `apt-get` und
`dpkg-query`, weil eine Fallunterscheidung, die still auf den falschen Zweig
fällt, dieselbe Ausgabe hätte wie vorher, nur mit der falschen Zahl:

| Aufruf | Meldung | rc |
|---|---|---|
| `all`, nichts offen | `Es stand nichts an — offene Aktualisierungen: 0.` | 0 |
| `all`, 2 offen, 0 danach | `2 von 2 Aktualisierungen eingespielt, 0 bleiben offen.` | 0 |
| `all`, 2 offen, 2 danach | `Der Lauf hat nichts verändert — … vorher wie nachher: 2.` | 3 |
| `panel`, Fassung wechselt | `Fassung 0.7.3-rc.6 wurde zu 0.7.3-rc.7.` | 0 |
| `panel`, Fassung bleibt | `Der Lauf hat nichts verändert — Fassung … 0.7.3-rc.6.` | 3 |

### Und der eigentliche Fehler war ein Handgriff, nicht eine Zeile

**shellcheck ist in diesem Container installiert.** Vor dem Push ist
`bash -n packaging/bin/apt-run` gefahren worden und sonst nichts — geprüft
wurde, ob die Datei sich einlesen lässt, und nicht, was die CI an ihr prüft. Der
Aufruf steht wörtlich in `ci.yml` und war einmal Kopieren.

> **Ein Werkzeug, das die CI fährt und das lokal daneben liegt, wird nicht durch
> ein anderes ersetzt, das eine ähnliche Frage stellt.** `bash -n` beantwortet
> „parst es", shellcheck „stimmt es" — die zweite Frage hat die Runde gekostet.

---

## 8c · Der Sprung `rc.6` → `rc.7` — die Vorhersage ist gemessen

Gefahren auf `cloudsrv24` am 1. September 2026, unmittelbar nach der Freigabe
von `0.7.3-rc.7`:

```
  Das Update läuft als srvpanel-update-99a88bd6.
  Das Panel startet dabei neu. Dieser Befehl liest mit und nennt am Ende den Ausgang.

    apt-run: Paketlisten werden aufgefrischt.     ← mitgelesen, grau

    Paketlisten werden aufgefrischt.              ← als Urteil ausgegeben, grün

  Antwortet die Bereitschaftsprüfung danach nicht, setzt das Paket selbst auf
  die vorige Version zurück.
```

Danach: `srvpanel version` → **`0.7.3-rc.7`**.

**§8 hat genau das vorhergesagt, und es ist keine gescheiterte Behebung.** Der
Befehl, der ein Update ausführt, ist immer der **schon installierte**: `rc.6`s
`Update.php` hat `rc.6`s `apt-run` gelesen, und dort trug die Fortschrittszeile
den Präfix noch. `Outcome::verdict()` nahm sie beim ersten Takt der Schleife —
nach drei Sekunden — für das Urteil, `Outcome::failed()` fand sie nicht in
`BAD`, und `urteilen()` gab `SUCCESS` zurück.

> **Eine Behebung an dem Werkzeug, das die Behebung ausliefert, wirkt erst eine
> Fassung später.** Zweimal belegt: einmal als Vorhersage in §8, einmal als
> Messung hier.

### Und die gefährliche Hälfte dieses Laufs

**Das Urteil war falsch gelesen und im Ergebnis richtig.** Das Update ist
durchgelaufen, die Fassung steht auf `rc.7`, und `SUCCESS` war der Rückgabewert,
den ein richtig gelesener Lauf auch gegeben hätte. Nichts an dieser Ausgabe
unterscheidet den einen Fall vom anderen.

> **Ein falsches Verfahren, dessen Ergebnis zufällig stimmt, sieht von aussen
> aus wie ein richtiges — und wer nur auf das Ergebnis sieht, hält es für
> belegt.**

Deshalb steht hier die **Zeitmarke** und nicht die Fassungsnummer als Beleg: Der
Befehl kam nach rund drei Sekunden zurück, während `apt-get` noch lief. Ein
richtig gelesener Lauf wäre bis zum echten Urteil geblieben und hätte
`Fassung 0.7.3~rc.6 wurde zu 0.7.3~rc.7.` genannt — mit den beiden Nummern, um
derentwillen Wunsch 1 gebaut wurde.

### Was der nächste Sprung als Erster prüft

**`rc.7` → `rc.8` ist der erste Lauf mit beiden behobenen Hälften** — `rc.7`s
`Update.php` liest `rc.7`s `apt-run`. Er prüft damit in einem Zug:

1. **Befund 5 behoben** — das Urteil nennt beide Fassungsnummern, und der Befehl
   bleibt bis dahin.
2. **M1** — ob die Warteschleife den Symlink-Wechsel wirklich übersteht, also ob
   `vorladen()` reicht. Das ist bis heute ungeprüft: Der Lauf von heute Morgen
   ist ausgestiegen, **bevor** das Paket umgeschaltet hat, und dieser hier
   ebenso.

> **Ein Lauf, der vor dem Vorgang endet, gegen den er sich behaupten soll, prüft
> die Behauptung nicht** — auch dann nicht, wenn er dabei erfolgreich aussieht.

---

## 9 · Befund 2 gebaut — und beim Bauen zerfiel er in zwei Hälften

`docs/94 §4` schrieb vor: „`apt-run` liest im Fassungsmodus die Zeile `ist schon
die neueste Version` und meldet dann ‚Es stand nichts an' statt eines
Fehlschlags." **Beide Hälften dieses Satzes waren falsch**, und das hat der Bau
gezeigt.

### Die erste Hälfte: der Satz ist übersetzt

Gemessen am 1. September 2026 gegen den deutschen Katalog von apt 2.8.3, gelesen
aus dem `.deb` des installierten apt:

| | Katalogeinträge | im Lauf |
|---|---|---|
| `%s is already the newest version (%s).` | **1** — `%s ist schon die neueste Version (%s).` | deutsch auf `cloudsrv24`, englisch hier |
| `Inst ` | **0 von 387** | `Inst` in beiden Sprachen, Zähler 6 gegen 6 |

Ein Ausdruck über den Satz hätte auf genau einer der beiden Maschinen
funktioniert und auf der anderen wortlos nichts gefunden.

> **Ein Satz, den apt übersetzt, ist keine Schnittstelle.**

Gefragt wird deshalb `apt-get -s "$@"` — dieselbe Simulation, die `offen()` seit
A1 fährt, mit **denselben Argumenten wie der echte Lauf**. `ansteht()` zählt
`^Inst `.

### Die zweite Hälfte: „im Fassungsmodus" war die falsche Einschränkung

Die alte Nachsicht hing an `[ "$mass" = offen ] && … && [ "$nachher" -eq 0 ]`,
und `OutcomeTest` verlangte diese Beschränkung ausdrücklich. Sie liest die Frage
„stand etwas an?" an einem Wert ab, der sie **nur für `all`** beantwortet: Bei
`packages` bleiben andere Aktualisierungen offen, bei `panel` ist `nachher` eine
Versionsnummer.

> **Ein Wert, der eine Frage nur in einem Modus beantwortet, ist in den anderen
> keine Antwort, sondern ein Zufall.**

> **Ein Wächter kann eine Beschränkung festhalten, die selbst der Fehler ist —
> und dann ist er das Letzte, was sich ändert.**

### Und der Bau hätte M5 wieder aufgerissen

**Die Simulation kann „war schon aktuell" nicht von „die Listen sind zu alt"
unterscheiden** — bei veralteten Listen sieht apt nichts Anstehendes und schreibt
denselben Satz. Wer die Nachsicht so in den Fassungsmodus liesse, machte aus
einer toten Paketquelle eine **grüne** Meldung: M5 zurück, eine Ebene höher.

> **Eine Unterscheidung, die der Gefragte selbst nicht treffen kann, muss vor
> der Frage getroffen werden.**

`apt-run` konnte sie nicht treffen. Der Rückgabewert von `apt-get update` trägt
nichts (M5), und die `W:`-Zeilen ebensowenig: Gemessen erzeugt **eine** tote
Quelle **drei** davon, und eine der drei („Download is performed unsandboxed…")
hat mit Quellen nichts zu tun. `Apt::readFailures()` liest sie seit A1 richtig,
mit drei ausgeschriebenen Fallen im Kopf.

> **Eine Auskunft, die anderswo schon richtig gelesen wird, holt man sich von
> dort — auch wenn der Aufruf daneben stünde.**

**Deshalb ist die Auffrischung nach `PanelUpdate` gezogen.** Sie geht jetzt über
`Apt::refresh()`, und `hitting(Sources::uris(Sources::PANEL_SOURCE))` fragt, ob
die **eigene** Quelle darunter war; wenn ja, wird gar nicht erst abgesetzt. Das
Vorbild ist `PhpVersionInstall` samt dem Wortlaut seiner Meldung: Der Betreiber
soll an der Quelle suchen und nicht am Paket.

Der deb822-Leser dafür stand in `PhpVersions::sourceUris()` — er hat mit PHP
nichts zu tun und heisst jetzt `Sources::uris()`; `PhpVersions` reicht durch.

> **Ein Name, der den ersten Aufrufer nennt, wird beim zweiten zur falschen
> Auskunft.**

### Ein Satz im Quelltext ist damit zurückgenommen

Im Kopf von `PanelUpdate` stand seit Schritt 6:

> Diese eine Frage ersetzt jede Prüfung am Rückgabewert von `apt-get update`:
> Sie fällt gleich aus, ob eine Quelle tot war, ob die Listen alt waren oder ob
> es schlicht nichts Neues gibt.

Der erste Halbsatz stimmt, der zweite ist der Fehler: Dass die Frage in allen
drei Fällen gleich ausfällt, war als **Vorzug** aufgeschrieben und ist der
Mangel.

> **Ein Verlust an Unterscheidung, den man als Einfachheit aufschreibt, wird
> erst dann als Fehler sichtbar, wenn jemand die Unterscheidung braucht.**

### Was der Bau an den Wächtern gefunden hat

**Ein Wächter war grün, weil mein eigener Kommentar die entfernte Zeile
zitierte.** `test_the_script_tells_nothing_pending_from_nothing_changed` suchte
`[ "$mass" = offen ]` im ganzen Skript — und die Zeile, die erklärt, dass es das
nicht mehr gibt, schreibt es wörtlich hin.

> **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
> steht — und ein Kommentar, der die entfernte Zeile zitiert, stellt sie für ihn
> wieder her.**

Das wiegt in diesem Repo schwerer als anderswo, weil hier **jede** Behebung ihren
Vorzustand im Kommentar festhält. `Tests\Support\WithoutHashComments` gibt es
seit dem 26. August genau dafür; sechs Wächter benutzen ihn, `OutcomeTest` nicht.
Jetzt schon — belegt in beide Richtungen: mit der verbotenen Bedingung **nur im
Kommentar** bleibt er grün, roh gelesen wäre er fälschlich rot.

**Und zwei Wächter haben eine tot gewordene Ausnahme gemeldet.**
`AptResultTest::EXCEPTIONS` führte `packaging/bin/apt-run` mit genau der
Begründung, die oben zurückgenommen ist. Gemeldet hat es nicht die Regel,
sondern ihre Gegenrichtung — „ruft kein `apt-get update` mehr".

> **Eine Ausnahme, deren Begründung fällt, fällt mit ihr — und dass sie
> liegenbleibt, meldet nur der Wächter, der die Gegenrichtung prüft.**

Die Liste ist damit leer, und die Untergrenze daneben musste von `> 1` auf `> 0`
— sie zählte die Stelle **und** die Ausnahme. Das ist die bekannte Aufräumfalle
dieses Repos zum vierten Mal.

**Vierzehn Bruch-Eingriffe auf die beiden Dateien, alle beissen** — vor und nach
Pint gefahren.

---

## 10 · Befund 3 gebaut — die Herkunft kommt jetzt von der Seite

`RememberPageUrl` schreibt `previousUrl` bei jeder Inertia-GET-Anfrage. Der
Zurück-Knopf des Browsers erzeugt keine, Inertia stellt aus dem History-Zustand
her — und die Herkunft veraltet.

Geschickt wird sie jetzt von der Seite: ein `router.on('before')` in
`resources/js/app.ts` hängt `X-Srvpanel-Origin` an **jede** Inertia-Anfrage, aus
`window.location.pathname + search`. `Origin::current()` liest die Kopfzeile
statt der Sitzung, ohne Rückfall.

**Eine Stelle bleibt eine Stelle** — sie steht nur am anderen Ende der Leitung.
Einundzwanzig Aufrufstellen wären einundzwanzig Gelegenheiten zu vergessen, und
die vergessene fiele niemandem auf: Eine fehlende Herkunft sieht aus wie ein
Vorgang der Automatik.

### Und damit wird die Prüfung strenger — das ist der eigentliche Fund

Der Wert kommt jetzt aus **fremder Hand**. Gemessen am 1. September 2026 mit dem
URL-Parser, den auch der Browser benutzt, gegen `https://panel.example/`:

| Kopfzeile | löst auf zu | alte Prüfung |
|---|---|---|
| `/updates` | `panel.example` | durch — richtig |
| `//evil.example/x` | **evil.example** | `parse_url` streicht den Host — zufällig harmlos |
| `/\evil.example/x` | **evil.example** | **kommt durch — die Lücke** |
| `/<TAB>/evil.example/x` | **evil.example** | `parse_url` ersetzt durch `_` — zufällig harmlos |
| `/ /evil.example/x` | `panel.example` | durch — richtig |
| `/%2fevil.example/x` | `panel.example` | durch — richtig |

Drei Mechanismen: Der Browser liest `//` als Anfang eines Rechnernamens, er
normalisiert `\` zu `/`, und er **entfernt** Tab, LF und CR vor dem Parsen.

> **Eine Prüfung, die für einen selbst gesetzten Wert genügt, genügt nicht für
> denselben Wert aus fremder Hand.**

**Zwei der drei hatte `parse_url` zufällig entschärft.** Darauf wird nichts mehr
gebaut: `Origin::pfad()` prüft die Zeichenkette selbst — Länge, führender
Schrägstrich, kein `//`, kein Rückstrich, kein Steuerzeichen.

> **Eine Prüfung, die aus einem Nebeneffekt folgt, ist keine — sie ist ein
> Zustand, der sich mit der nächsten Fassung ändern darf.**

### Und ein Wächter, der die Wirkung misst statt des Textes

`OperationOriginTest` läuft ohne Framework und hält, was am Text zu halten ist:
den Namen der Kopfzeile an beiden Enden, die Rechnung von `Origin::pfad()` an
dreizehn **gemessenen** Fällen, die Herkunft am Modell. Keines davon belegt, dass
am Ende ein Wert in der Spalte steht.

> **Ein Wächter über den Quelltext sagt, dass die Teile zusammenpassen. Dass sie
> zusammen etwas tun, sagt er nicht.**

`OriginHeaderTest` misst genau das: Kopfzeile rein, Spalte raus — und die drei
Gegenrichtungen (ohne Kopfzeile bleibt sie leer, eine fremde Adresse kommt nicht
an, eine schon gesetzte Herkunft überlebt).

**Sieben Bruch-Eingriffe, alle beissen.**

### Was daran nicht gemessen ist

**Kein Browser hat das gefahren.** Dass `router.on('before')` bei einer
`router.post`-Anfrage wirklich feuert und `window.location` dabei noch auf der
absetzenden Seite steht, ist aus der Typdefinition von Inertia 3.6 gelesen und
nicht beobachtet. Das gehört in den Nachlauf auf `cloudsrv24`.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**
