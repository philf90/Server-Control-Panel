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
