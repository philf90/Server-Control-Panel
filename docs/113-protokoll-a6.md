# A6 — die Zeitpläne des Servers: das Protokoll

**Gefahren am 8. September 2026 auf `cloudsrv24` gegen `0.7.3-rc.27`.**
Der Plan ist `docs/111`, der Lauf `docs/112`, die Messrunde davor
`docs/81 §2.3t`.

**Alle acht Punkte aus `docs/112` sind gemessen und erfüllt**, beide
Ausschlusskriterien (2 und 4) darunter, keiner als „nicht herstellbar"
ausgefallen. **A6 ist damit abgenommen.**

Drei Befunde stecken im Prüfling, einer in der Vorschrift. Zwei der drei sind
dieselbe Form an zwei Orten; keiner hat ein Kriterium gekippt, und keinen hat
eine Zahl gemeldet.

---

## 0 · Der Vorflug

    2026-09-08T07:16:56Z
    srvpanel version       0.7.3-rc.27
    md5sum /etc/crontab    85f7ff2020ac8c72212f076ddf33c0be
    anacron                FEHLT (test -x /usr/sbin/anacron)
    Dienste                active · active · active · active

Sechs Verzeichnisse (`cron.d`, `cron.daily`, `cron.hourly`, `cron.weekly`,
`cron.monthly`, `cron.yearly`). In `/etc/cron.d` liegen `e2scrub_all`,
`kernel`, `php`, `.placeholder` und die beiden Dateien des Panels
(`srvpanel-p1136`, `srvpanel-p1139`).

Die Prüfsumme steht da, weil dieser Lauf `/etc/crontab` **nicht** anfassen
darf und der Beleg dafür sonst fehlte. Sie ist in §8b unverändert
wiedergekommen.

---

## 1 · Die Zeitpläne stehen da — erfüllt

**§1a, gezählt an den Dateien:**

| Datei | Zeilen mit Benutzerfeld |
|---|---|
| `/etc/crontab` | 4 |
| `/etc/cron.d/e2scrub_all` | 2 |
| `/etc/cron.d/kernel` | 1 |
| `/etc/cron.d/php` | 1 |
| **Summe ohne `srvpanel-*`** | **8** |

Die Seite zeigt im Bereich „Zeitpläne" **12** Zeilen: diese acht, die beiden
Dateien des Panels als je **eine** Zeile, und die zwei Zeilen des Prüfkörpers
aus §1b. Jede der acht trägt Zeitplan, Benutzer und Kommando im Wortlaut, das
Kommando von `/etc/cron.d/php` (`[ -x /usr/lib/php/sessionclean ] && if …`)
ungekürzt über zwei Zeilen.

**§1b, die `@`-Form und ein langes Kommando.** Der Prüfkörper
`/etc/cron.d/zz-a6-probe` (220 Bytes, `0644 root:root`, beide Zeilen mit
Tabulatoren) erscheint als

| Zeitplan | Benutzer | Kommando |
|---|---|---|
| `@daily` | `root` | `/bin/true --abnahme-a6` |
| `17 4 * * *` | `root` | die ganze Zeile, ungekürzt |

Damit ist die `@`-Form zum ersten Mal **auf einer echten Maschine** durch den
Leser gegangen: `docs/81 §2.3t` M4 hat die acht Kurznamen gegen cron gemessen,
der Leser dagegen bis hierher nur gegen Prüfkörper im Container. Wer die Form
in fünf Zeitfelder zerlegte, zeigte `root` als Tag des Monats.

**§1c, die Umgebung.** Genau eine Zeile steht unter der Tabelle:

    /etc/crontab setzt SHELL=/bin/sh.

**Und dass da nur SHELL steht, ist der Beleg und nicht die Lücke.** Auf
Ubuntu 24.04 trägt `/etc/crontab` `PATH` als **Kommentar**
(`#PATH=/usr/local/sbin:…`, Zeile 9, im Container am 8. September nachgemessen);
ein Leser, der Zuweisungen ohne Rücksicht auf das `#` einsammelte, schriebe
hier zwei Zuweisungen hin, von denen die zweite nicht gilt. Für die beiden
Dateien des Panels steht keine Zeile — sie sind aus diesem Bereich
ausgenommen.

---

## 2 · Ein übergangenes Skript wird benannt — erfüllt *(Ausschluss)*

Drei Prüfkörper in `/etc/cron.daily`, je einer der drei gemessenen Gründe. Der
Bereich **„Übergangen"** nennt alle drei:

| Datei | Warum |
|---|---|
| `zz-a6-probe.sh` | Punkt im Namen |
| `zz-a6-probe~` | Zeichen, das run-parts nicht zulässt |
| `zz-a6-ohne-bit` | kein Ausführbit |

**Die Gegenprobe steht daneben und nicht dahinter:** `run-parts --test
/etc/cron.daily` nennt keinen der drei, und die sechs, die es nennt (`apport`,
`apt-compat`, `dpkg`, `logrotate`, `man-db`, `quota`), stehen vollzählig in der
Spalte „Skripte".

**`.placeholder` taucht nirgends auf** — weder in der Skriptliste noch unter
„Übergangen". Es liegt in jedem der sechs Verzeichnisse und gehört zum Paket
`cron-daemon-common`; stünde es da, meldete die Seite auf **jedem** heilen
Server einen Fund, den niemand beheben kann.

Über der Tabelle stand der Hinweis „3 Dateien liegen in einem Verzeichnis und
laufen nicht" — beide Verben in der Mehrzahl, weil `counted()` sie beide
übergeben bekommt und keines ableitet.

**Damit ist M2 behoben und nicht beschrieben.**

---

## 3 · Der Zeitpunkt eines Verzeichnisses — erfüllt, eine Hälfte

    anacron: fehlt

| Verzeichnis | Zeile in `/etc/crontab` | Spalte „Läuft" |
|---|---|---|
| `/etc/cron.hourly` | `17 * * * *`, ohne Vorbehalt | `17 * * * *` |
| `/etc/cron.daily` | `25 6 * * *`, mit `test -x …anacron ||` | `25 6 * * *` |
| `/etc/cron.weekly` | `47 6 * * 7`, mit Vorbehalt | `47 6 * * 7` |
| `/etc/cron.monthly` | `52 6 1 * *`, mit Vorbehalt | `52 6 1 * *` |
| `/etc/cron.yearly` | **keine** | „kein Zeitplan — nichts hier läuft" |

Jeder Zeitplan steht in Monospace, der Satz nicht — die Kennung reist am Wert
mit und wird nicht in der Vorlage entschieden.

**Was ungeprüft bleibt und benannt ist:** Auf `cloudsrv24` fehlt anacron, also
greift für die drei Zeilen mit Vorbehalt die zweite Hälfte der Tabelle. Der
Satz **„anacron bestimmt den Zeitpunkt"** ist auch in diesem Lauf **nicht zu
sehen gewesen** — im Container fehlt anacron ebenso (`docs/112 §0.5`). Er ist
gegen Prüfkörper gemessen (`CronScheduleTest`) und auf keiner Maschine.

> **Eine Zeile, die eine Bedingung trägt, sagt ohne die Bedingung das
> Gegenteil** — und welche der beiden Hälften man je zu sehen bekommt,
> entscheidet nicht der Prüfling.

---

## 4 · `cron.yearly` trägt keinen Zeitpunkt — erfüllt *(Ausschluss)*

    ls -d /etc/cron.yearly          → /etc/cron.yearly
    grep -c 'cron\.yearly' /etc/crontab → 0

Die Spalte „Läuft" zeigt **„kein Zeitplan — nichts hier läuft"** und nicht „—"
ohne Erklärung. Das ist der dritte Zustand aus `docs/111 §3.1`: `known: true`,
`schedule: null`. Ohne ihn sähe „nicht nachgesehen" genauso aus wie „es gibt
keine Zeile", und ein Verzeichnis, in dem nichts läuft, sähe aus wie eines,
über das man nichts weiss.

---

## 5 · Die Dateien des Panels sind erkennbar — erfüllt

`/etc/cron.d/srvpanel-p1136` (177 Bytes) und `/etc/cron.d/srvpanel-p1139`
(501 Bytes) standen schon im Vorflug; es musste keine hergestellt werden.

Beide stehen als **eine** Zeile da: der Pfad in „Datei", `—` in „Zeitplan" und
„Benutzer", und in „Kommando" der Satz *„Vom Panel verwaltet — **auf der
Cronseite**"* mit einem Verweis auf `/cron`.

Keine Zeile aus diesen beiden Dateien kommt im Bereich „Zeitpläne" vor. Der
Inhalt gehört der Cronseite; ihn hier ein zweites Mal auszuschreiben wäre eine
zweite Anzeige derselben Sache, und die zweite ist die, die veraltet.

**Genau dieser Satz hat den ersten Befund getragen** — siehe §9.1.

---

## 6 · Nicht feststellbar bleibt nicht feststellbar — erfüllt

    systemctl stop srvpanel-agentd ; sleep 2
    srvpanel-agentd   inactive
    srvpanel-worker   inactive
    srvpanel-metrics  inactive
    srvpanel-web      active

Dass Worker und Metrik mitgehen, ist kein Befund: `Requires=` überträgt das
Anhalten (`docs/100 §9.10`). `/schedules` antwortet mit **200** und trägt oben
den roten Streifen

> Die Zeitpläne sind nicht feststellbar — der Agent hat nicht geantwortet. Das
> heisst nicht, dass keine laufen.

**Keine** leere Tabelle mit der Aussage „keine Zeitpläne". Der Punkt ist damit
erfüllt — und was **daneben** steht, ist Befund 3 (§9.3).

**Zurück über das Ziel und nicht über den Agenten:**

    systemctl start srvpanel.target ; sleep 2
    active · active · active · active

Ein `start srvpanel-agentd` allein holte Worker und Metrik nicht zurück; das
ist am 4. September auf derselben Maschine gemessen und der Grund, aus dem
`srvpanel.target` existiert.

---

## 7 · Die Tür — erfüllt

| Konto | `/schedules` | Menüpunkt „Zeitpläne" |
|---|---|---|
| Betreiber | 200, Seite vollständig | sichtbar, zwischen „Dienste" und „Updates" |
| Administrator | **403** | **nicht sichtbar** — BETRIEB führt nur Dienste, Updates, Diagnose |
| Kundenkonto | **403** | nicht sichtbar |

**Die zweite Spalte ist gemessen und nicht angesehen.** Auf einer direkt
geladenen Seite als Administrator:

    operate-server: false

Ein Menüpunkt, den der Betrachter sieht und der ihm einen 403 gibt, ist der
Zustand, gegen den es `AbilityReachTest` gibt.

**Eine Verwechslung, die beim Nachlesen naheliegt:** Der Name unten links in
den Bildern dieses Laufs lautet „Administrator" und ist der **Anzeigename des
Betreibers**, nicht seine Rolle. Gemessen ist das an der Navigation: Sie führt
`Wartungsmodus`, `Ankündigungen`, `Zeitpläne` und `Konten`, und alle vier
Routen tragen `can:operate-server`.

---

## 8 · 390 px — erfüllt

`tests/bilder-messen.js`, vier Lagen, **je frisch geladene Seite**, mit den
Prüfkörpern aus §1b und §2 an ihrem Platz:

| Breite | Thema | dokument | gegenprobe | schiebt | rollt | versteckt |
|---|---|---|---|---|---|---|
| 1440 | hell | 0 | 200 (soll 200) | 0 | 0 | 0 |
| 1440 | dunkel | 0 | 200 (soll 200) | 0 | 0 | 0 |
| 390 | dunkel | 0 | 200 (soll 200) | 0 | 0 | 6 |
| 390 | hell | 0 | 200 (soll 200) | 0 | 0 | 6 |

`versteckt = 6` bei 390 px sind die auf 1×1 px geklemmten Kopfzeilen der drei
gestapelten Tabellen — `.stacks thead` nimmt sie dem Auge und lässt sie dem
Screenreader. Bei 1440 px sind sie sichtbar, und die Zahl ist 0.

**Und die Zelle gegen ihren Bereich:** leere Liste in allen vier Lagen. Diese
zweite Messung steht daneben, weil eine Zelle überlappen kann, ohne dass die
Seite schiebt — genau so ist der Befund des Containers entstanden
(`docs/111 §6b`: bei `dokument = 0` lief die Tabelle **736 px** über ihren
Bereich).

> **Zwei Messungen, von denen die eine den Schaden manchmal sieht, ersetzen
> einander nicht.**

---

## 8b · Der Abbau, und er ist belegt

    rm -f /etc/cron.d/zz-a6-probe
    rm -f /etc/cron.daily/zz-a6-probe.sh '/etc/cron.daily/zz-a6-probe~' \
          /etc/cron.daily/zz-a6-ohne-bit

| | Vorflug | danach |
|---|---|---|
| `/etc/cron.d/` | `e2scrub_all` `kernel` `php` `.placeholder` `srvpanel-p1136` `srvpanel-p1139` | **dieselben sechs** |
| `/etc/cron.daily/` | `apport` `apt-compat` `dpkg` `logrotate` `man-db` `quota` `.placeholder` | **dieselben sieben** |
| `md5sum /etc/crontab` | `85f7ff2020ac8c72212f076ddf33c0be` | **dieselbe Summe** |
| Dienste | active ×4 | active ×4 |

Und die Seite danach: Der Bereich **„Übergangen" ist ganz fort** — er steht nur
da, wenn es etwas zu zeigen gibt —, der gelbe Hinweis ebenso, und der Bereich
„Zeitpläne" führt **zehn** Zeilen statt zwölf. Die beiden Zeilen des
Prüfkörpers sind fort, die acht des Bestands und die zwei Einzeiler des Panels
stehen.

Der Zeitpunkt in beiden Verzeichnissen ist `Sep 8 12:05` — das ist der Abbau
selbst; er hat die Dateien der beiden Verzeichnisse nicht angefasst, nur die
eigenen entfernt.

Für §5 ist kein Cronjob angelegt worden, es bleibt also keiner über `/cron` zu
entfernen.

---

## 9 · Die Befunde

**Drei im Prüfling, einer in der Vorschrift.** Keinen hat ein Test gefunden,
und keinen eine Zahl: Zwei kommen aus einem Bild, einer aus dem Zustand, den
Punkt 6 herstellt, und einer aus dem Nachlesen einer eigenen Behauptung.

### 9.1 Der zerrissene Satz in der gestapelten Zelle — behoben

Der Satz aus §5 stand auf `cloudsrv24` bei 390 px so:

    KOMMANDO      Vom Panel            auf der          .
                  verwaltet —          Cronseite

Eine gestapelte Zelle ist bei ≤ 720 px eine Flexzeile mit
`justify-content: space-between`: Beschriftung links, Wert rechts. Der Wert ist
dabei **ein** anonymes Flexkind — hier waren es **drei** (Text, Verweis,
Schlusspunkt), und die drei hat `space-between` auseinandergeschoben.

> **Eine gestapelte Zelle verträgt genau ein Kind — mehrere werden von
> `space-between` zu einer Zeile mit Lücken.**

Behoben ist das mit einem `<span>` um den ganzen Satz und nicht mit einer neuen
Regel in `app.css`: Die Regel dort ist richtig, sie beschreibt nur eine Form,
die die Vorlage einhalten muss.

**Und im Bild der Bilderrunde stand er schon.** Die Aufnahme war auf drei
andere Fragen hin angesehen worden — den Überlauf, die Kennung im
Zeitplanfeld, den Bereich „Übergangen".

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.** Zum zweiten Mal nach `docs/59`.

### 9.2 Dieselbe Zelle auf der SFTP-Seite — behoben

Gesucht wurde nach dem ersten Befund, nicht gefunden beim Benutzen: Ein
Durchgang durch alle `.stacks`-Zellen mit mehr als einem Kind hat auf
`Sftp.vue` dieselbe Form ergeben — `<span class="ident">ed25519</span> 256
Bit` als zwei Kinder, im Nachbau bei 390 px **111 px** auseinandergezogen.

> **Wer etwas an einer Datei behebt, sieht in derselben Stunde nach, wo
> dieselbe Frage noch gestellt wird.** Derselbe Satz wie am 6. September bei
> den klebenden Knöpfen (`docs/105`).

### 9.3 Eine Kopfzeile über null Zeilen — offen, geht in dieselbe Fassung

Bei `readable: false` (§6) stehen unter dem roten Streifen **beide Bereiche mit
ihrer Erklärung und ihrer Kopfzeile über null Zeilen**: `DATEI · ZEITPLAN ·
BENUTZER · KOMMANDO` und `VERZEICHNIS · LÄUFT · SKRIPTE`. `tables` und
`directories` kommen als leere Listen, `zeilen` wird leer, und das `<thead>`
hängt an keiner Bedingung.

Das Kriterium ist wörtlich erfüllt — nirgends steht „keine Zeitpläne". Der
Fehler ist eine Stufe schwächer und derselbe Familie:

> **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen lässt,
> behauptet etwas, das sie nicht weiss.** Eine Seite, deren `/etc/cron.d`
> wirklich leer wäre, sähe unterhalb des Streifens genau gleich aus; getrennt
> werden die beiden allein durch den Streifen darüber.

Die Behebung ist eine Bedingung an der Hülle der Bereiche: Wo nichts feststeht,
steht der Satz und sonst nichts.

### 9.4 Der Lauf zitierte einen Befund statt seiner Behebung — behoben

`docs/112 §7` sagte beim Ausschreiben, der 403 sei „Laravels englische
Vorgabeseite", und berief sich auf `docs/84`. Der Satz stammt von dort — und
die Behebung steht **im selben Protokoll**, zwei Absätze weiter:
`resources/views/errors/` führt seit dem **25. August 2026** sieben Blades samt
Layout (Befund 3 desselben Laufs). Gemessen zeigt der 403 eine deutsche,
gestaltete Seite mit einem Weg zurück.

> **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung — und
> nichts prüft sie.** Sie stand da als Beruhigung („kein Befund"), und eine
> Beruhigung liest niemand nach.

---

## 10 · Was der Lauf über sich selbst gelernt hat

**Vier der acht Kriterien sind beim Ausschreiben umgefallen**, bevor eine Zeile
gemessen war (`docs/112 §0`): Punkt 1 zählte etwas, das die Seite nach
Entscheidung nicht zeigt; Punkt 3 fragte nach anacron über `command -v` und
damit über `$PATH`, während der Prüfling `test -x /usr/sbin/anacron` fragt, weil
`/etc/crontab` genau das fragt; Punkt 4 setzte ein Verzeichnis voraus; Punkt 6
hatte einen Rückweg, den `docs/100` schon einmal bezahlt hat.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

**Und drei der Befunde hat kein Werkzeug gemeldet.** Zwei kommen aus einem
Bild, einer aus dem Zustand, den Punkt 6 herstellt. Die Zahlen waren in allen
drei Fällen in Ordnung: `dokument = 0`, `schiebt = 0`, die Zelle gegen ihren
Bereich leer.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

---

## 11 · Die Bilanz

| Punkt | Gegenstand | Ergebnis |
|---|---|---|
| 1 | Der Bestand steht vollständig da | erfüllt — 8 gezählt, 12 gezeigt |
| 2 | Ein übergangenes Skript wird benannt *(Ausschluss)* | erfüllt — drei Gründe, Gegenprobe daneben |
| 3 | Der Zeitpunkt eines Verzeichnisses | erfüllt — die anacron-Hälfte bleibt ungeprüft |
| 4 | `cron.yearly` trägt keinen Zeitpunkt *(Ausschluss)* | erfüllt — der dritte Zustand steht da |
| 5 | Die Dateien des Panels sind erkennbar | erfüllt — je eine Zeile mit Verweis |
| 6 | Nicht feststellbar bleibt nicht feststellbar | erfüllt — 200 und der Satz |
| 7 | Die Tür | erfüllt — 403 und kein Menüpunkt |
| 8 | 390 px | erfüllt — `dokument = 0`, Gegenprobe 200/200 |

**A6 ist abgenommen.** Der Prüfstand ist abgeräumt und der Abbau belegt.

---

## 12 · Der Nachlauf — die drei Behebungen auf dem Server

**Gefahren am 8. September 2026 auf `cloudsrv24` gegen `0.7.3-rc.28`**, der
Fassung, die alle drei Behebungen trägt. *Eine Behebung gilt als behoben, wenn
jemand nachgesehen hat.*

### 12.1 Die beiden Zellen, gemessen statt angesehen

Ein Prüfkörper in der Browserkonsole misst die **Lücke innerhalb** einer
gestapelten Zelle — das Symptom, das kein Überlauf meldet — und läuft über alle
`table.stacks td` bei 390 px:

| Seite | Zellen mit Lücke > 8 px | Gegenprobe an der Zelle |
|---|---|---|
| `/schedules` | **keine** | mit Hülle **0**, ohne Hülle **14** |
| `/subscriptions/137/sftp` | **keine** | mit Hülle **0**, ohne Hülle **81** |

**Die Gegenprobe nimmt der Zelle im DOM ihre Hülle** (`h.replaceWith(...h.childNodes)`)
und stellt damit genau das alte Markup her. Ohne sie wäre die leere Liste
wertlos — *eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
Null steht.*

> **Ein Bild nach einer Gegenprobe zeigt den hergestellten Zustand und nicht den
> gemessenen.** Die Aufnahme der SFTP-Seite ist nach dem Eingriff entstanden und
> zeigt die Zelle auseinandergezogen — wer sie ohne diesen Satz aufhebt, hat
> einen Beleg für die Behebung, der wie ihr Gegenteil aussieht.

**Und der Prüfkörper selbst hat einen Fehler, der ihn vom Repo fernhält.** Er
überspringt Paare, deren Kästen verschieden hoch anfangen
(`Math.abs(top_i − top_{i−1}) > 2 → continue`) — und auf `rc.27` sah der Riss
genau so aus: Die Stücke standen nebeneinander und brachen **je für sich** um.

> **Ein Prüfkörper, der die Paare überspringt, deren Kästen verschieden hoch
> anfangen, überspringt genau den Fall, den er finden soll.**

Die 14 gegen die 81 sind derselbe Befund von zwei Seiten: Auf der SFTP-Seite
passen beide Stücke in eine Zeile und die volle Lücke wird gemessen; auf der
Zeitplanseite brechen sie um, und übrig bleibt der Rest. Wer ihn als
`tests/zellen-messen.js` ins Repo holen will, misst vorher nach, was die
richtige Regel ist — vermutlich `getClientRects()` je Zeile statt des
Vereinigungskastens.

### 12.2 Der Streifen ohne Tabelle darunter

    systemctl stop srvpanel-agentd && sleep 2
    inactive · inactive · inactive · active

`/schedules` antwortet mit 200 und trägt **nur** den Streifen:

    Bereiche: 0
    Tabellen: 0
    Streifen: Die Zeitpläne sind nicht feststellbar — der Agent hat nicht …

Gegen `rc.27` standen dort zwei Bereichsüberschriften und zwei Kopfzeilen über
null Zeilen. Zurück über das Ziel: viermal `active`, und die Seite trägt ihre
zehn Zeilen wieder.

### 12.3 Die vier Lagen

| Breite | Thema | dokument | gegenprobe | schiebt | rollt | versteckt |
|---|---|---|---|---|---|---|
| 390 | hell | 0 | 200 (soll 200) | 0 | 0 | 4 |
| 390 | dunkel | 0 | 200 (soll 200) | 0 | 0 | 4 |
| 1440 | dunkel | 0 | 200 (soll 200) | 0 | 0 | 0 |
| 1440 | hell | 0 | 200 (soll 200) | 0 | 0 | 0 |

**Angesagt war `versteckt = 4` nicht, sondern 6** — die Zahl aus dem Lauf gegen
`rc.27`, und dort standen die Prüfkörper noch, also **drei** Tabellen. Nach dem
Abbau sind es zwei. Zwei Elemente je gestapelter Tabelle, in beiden Läufen:
6 : 3 = 4 : 2.

> **Eine Zahl aus einer Messung unter anderen Bedingungen ist eine Vermutung,
> auch wenn sie aus einer Messung stammt.** Übernommen war sie aus einem
> Zustand, den derselbe Satz ausdrücklich ausgeschlossen hatte.

**Alle drei Behebungen sind damit auf dem Server nachgesehen.**

---

## 13 · Was offen bleibt

- **Der anacron-Satz** bleibt auf keiner Maschine gemessen (§3). Er ist gegen
  Prüfkörper gehalten und wartet auf einen Server, auf dem anacron liegt.
- **Der Rest aus P7** — `orphan.row` für `tls.cloudlab24.de`, unverändert.
- **Was A6 nicht ist und bleibt** (`docs/112 §9`): die Cronjobs einzelner
  Benutzer, `/etc/anacrontab`, die nächste Fälligkeit, ein `check` für die
  stillen Fälle und jeder Schreibweg.
