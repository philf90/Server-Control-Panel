# A6 — der Abnahmelauf

Ausgeschrieben am **8. September 2026**, **vor** dem Fahren. Der Plan ist
`docs/111`, die Messrunde `docs/81 §2.3t`, die Bilderrunde im Container
`docs/111 §6b`. Gefahren wird auf `cloudsrv24`.

---

## 0 · Was beim Ausschreiben umgefallen ist

**Vier der acht Punkte aus `docs/111 §7` haben ihre Fassung gewechselt.** Drei
davon, weil der Prüfling sie so nicht hergibt, und einer, weil das Kriterium
eine andere Frage stellte als der Prüfling.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

### 0.1 Punkt 1 zählte etwas, das die Seite absichtlich nicht zeigt

Er lautete: „**Jede** Zeile aus `/etc/crontab` und `/etc/cron.d` erscheint mit
Zeitplan, Benutzer und Kommando — gezählt gegen die Dateien selbst."

Für die Dateien des Panels ist das **nach Entscheidung falsch**: `docs/111 §2`
Frage 2 sagt, sie stehen als *eine* Zeile mit Verweis da und nicht mit Inhalt.
Auf `cloudsrv24` liegt mindestens eine solche Datei je Abonnement mit Cronjobs
— der Punkt wäre also gegen den gebauten Zustand rot gewesen.

Neu gefasst: gezählt wird gegen die Dateien **ohne** `srvpanel-*`, und die
eigenen Dateien werden eigens als Einzeiler geprüft (das ist Punkt 5).

**Und der Zählbefehl war keiner.** „Zeilen der Datei" sind nicht die Zeilen der
Datei: Kommentare, Leerzeilen und Zuweisungen (`SHELL=`, `PATH=`, `MAILTO=`)
gehören nicht dazu. Der Befehl in §1 zählt sie ausdrücklich heraus.

### 0.2 Punkt 3 fragte nach anacron anders als der Prüfling

Er verlangte „gemessen gegen `command -v anacron`". Der Agent fragt
`is_executable('/usr/sbin/anacron')` — und zwar deshalb, weil **die Zeile in
`/etc/crontab` genau das fragt** (`test -x /usr/sbin/anacron`).

`command -v` fragt `$PATH`. Auf einem Server, dessen `$PATH` `/usr/sbin` nicht
führt — bei einem unprivilegierten Konto der Normalfall —, antworten die beiden
verschieden, und der Punkt meldete den Prüfling für etwas, das er zu Recht tut.

> **Eine Gegenprobe über einen anderen Weg als den benutzten prüft den falschen
> Weg.**

Gemessen wird deshalb mit `test -x /usr/sbin/anacron`.

**Und die Zahlen im Kriterium sind gestrichen.** Es stand „`cron.hourly` trägt
`17 * * * *`" — das ist der Wert *dieses Containers*. Verglichen wird gegen die
Zeile, die auf dem Server steht, welche auch immer das ist.

### 0.3 Punkt 4 setzt voraus, dass es `cron.yearly` gibt

Das Verzeichnis ist auf Debian und Ubuntu vorhanden und steht in keiner Zeile
von `/etc/crontab` — **hier gemessen, dort nicht**. Fehlt es auf `cloudsrv24`
oder trägt `/etc/crontab` dort eine Zeile dafür, ist der Punkt nicht
herstellbar, und er ist ein Ausschlusskriterium.

Er beginnt deshalb mit einer Prüfung und nicht mit einem Blick auf die Seite
(§4). Ist das Verzeichnis da und die Zeile fehlt, misst er wie geplant.

### 0.4 Punkt 6 hat einen Rückweg, den der A10-Lauf schon einmal bezahlt hat

Den Agenten anzuhalten legt **`srvpanel-worker` und `srvpanel-metrics` mit
hin** — `Requires=srvpanel-agentd.service` überträgt das Anhalten, und ein
`start` des Agenten holt sie **nicht** zurück (`docs/100 §9.10`).

Zurückgeholt wird deshalb über **`systemctl start srvpanel.target`**, und
gemessen wird **nach einem `sleep 2`**: Ein `is-active` unmittelbar nach dem
`stop` misst den Übergang und nicht den Zustand.

### 0.5 Was der Container nicht messen konnte und dieser Lauf messen muss

**Den anacron-Satz.** Hier ist kein anacron installiert; `cron.daily` zeigt
deshalb seinen Zeitpunkt, und der Satz „anacron bestimmt den Zeitpunkt" ist
**nie zu sehen gewesen**. Welche der beiden Hälften von Punkt 3 auf
`cloudsrv24` greift, entscheidet der Server — beide sind gültig, und die
ungeprüfte bleibt es.

**Die `@`-Form auf einer echten Maschine.** Acht Namen sind gegen cron gemessen
(`docs/81 §2.3t` M4), der Leser dagegen nur gegen Prüfkörper. Sie reitet in §1
als Prüfkörper mit.

---

## 0b · Der Vorflug — was hinterher wieder dastehen muss

**Vor jedem Eingriff**, und das Ergebnis gehört ins Protokoll:

    date -u +%FT%TZ
    srvpanel version
    md5sum /etc/crontab
    ls -la /etc/cron.d/ /etc/cron.daily/ /etc/cron.hourly/ /etc/cron.weekly/ /etc/cron.monthly/ /etc/cron.yearly/
    ls -d /etc/cron.* 
    test -x /usr/sbin/anacron && echo "anacron: da" || echo "anacron: fehlt"
    systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics srvpanel-web

Der Vorflug ist **kein Formalismus**: §1 und §2 legen Dateien unter `/etc`, §6
hält Dienste an. Ohne diese Zeilen ist „abgeräumt" eine Behauptung — und
`md5sum /etc/crontab` steht da, weil dieser Lauf die Datei **nicht** anfassen
darf und der Beleg dafür sonst fehlt.

> **Wer eine Vorbereitung von Hand trifft, belegt sie, bevor er misst.**

---

## 1 · Die Zeitpläne stehen da

### 1a · Der Bestand, gezählt an den Dateien

    # Zeilen mit Benutzerfeld, ohne Kommentare, Leerzeilen und Zuweisungen —
    # und ohne die Dateien, die das Panel selbst schreibt. `.placeholder` steht
    # in keinem Zweig, weil `*` keinen führenden Punkt trifft.
    for f in /etc/crontab /etc/cron.d/*; do
      case "$f" in */srvpanel-*) continue ;; esac
      n=$(grep -vE '^[[:space:]]*(#|$)' "$f" | grep -vE '^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*[[:space:]]*=' | wc -l)
      printf '%-40s %s\n' "$f" "$n"
    done
    echo "---"
    for f in /etc/crontab /etc/cron.d/*; do
      case "$f" in */srvpanel-*) continue ;; esac
      grep -vE '^[[:space:]]*(#|$)' "$f" | grep -vE '^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*[[:space:]]*='
    done | wc -l

Dann `/schedules` aufmachen und den Bereich **„Zeitpläne"** gegen diese Zahl
halten — die Zeilen der eigenen Dateien sind dabei je **eine** und zählen
einzeln mit.

**Erfüllt, wenn** die Summe stimmt und jede Zeile Zeitplan, Benutzer und
Kommando im Wortlaut trägt.

**Weicht die Zahl ab, ist das ein Befund und kein Fehlschlag der Messung** — die
fehlenden Zeilen gehören dann einzeln ins Protokoll. Der Leser lässt eine Zeile
mit weniger als sieben Feldern absichtlich weg, statt einen halben Eintrag zu
bauen; ob es auf diesem Server eine solche gibt, weiss niemand.

### 1b · Die `@`-Form und ein langes Kommando

**Ein Prüfkörper, und er ist mit Absicht harmlos.** `/bin/true` ignoriert seine
Argumente; feuerte die Zeile mitten im Lauf, geschähe nichts.

    cat > /etc/cron.d/zz-a6-probe <<'EOF'
    @daily	root	/bin/true --abnahme-a6
    17 4 * * *	root	/bin/true --ziel=/srv/backup/nacht --ausschluss=/var/lib/mysql --komprimieren=zstd --protokoll=/var/log/sicherung/taeglich.log --benachrichtigen=betrieb@example.invalid
    EOF
    chmod 0644 /etc/cron.d/zz-a6-probe
    ls -l /etc/cron.d/zz-a6-probe

**Erfüllt, wenn** auf der Seite zwei Zeilen für `/etc/cron.d/zz-a6-probe`
stehen:

| Zeitplan | Benutzer | Kommando |
|---|---|---|
| `@daily` | `root` | `/bin/true --abnahme-a6` |
| `17 4 * * *` | `root` | die ganze Zeile, **ungekürzt** |

**Die erste ist die neue Hälfte.** Wer die `@`-Form in fünf Zeitfelder zerlegt,
zeigt dort `@daily root /bin/true` als Zeitplan und lässt Benutzer und Kommando
leer — oder er zeigt `root` als Tag des Monats.

**Die Datei bleibt bis nach §8 liegen**, weil §8 das lange Kommando braucht.

### 1c · Die Umgebung steht als Satz

**Erfüllt, wenn** unter der Tabelle für jede fremde Datei mit Zuweisung eine
Zeile steht — auf einem Ubuntu mindestens `/etc/crontab setzt SHELL=/bin/sh.`
— und **keine** für die Dateien des Panels.

---

## 2 · Ein übergangenes Skript wird benannt *(Ausschluss)*

**Drei Prüfkörper statt einem**, je einer der drei gemessenen Gründe. Alle drei
werden von `run-parts` übergangen und laufen deshalb nie.

    printf '#!/bin/sh\nexit 0\n' > /etc/cron.daily/zz-a6-probe.sh
    printf '#!/bin/sh\nexit 0\n' > '/etc/cron.daily/zz-a6-probe~'
    printf '#!/bin/sh\nexit 0\n' > /etc/cron.daily/zz-a6-ohne-bit
    chmod 0755 /etc/cron.daily/zz-a6-probe.sh '/etc/cron.daily/zz-a6-probe~'
    chmod 0644 /etc/cron.daily/zz-a6-ohne-bit
    run-parts --test /etc/cron.daily

**Erfüllt, wenn** der Bereich **„Übergangen"** alle drei nennt und keiner davon
in der Skriptliste von `/etc/cron.daily` steht:

| Datei | Warum |
|---|---|
| `zz-a6-probe.sh` | Punkt im Namen |
| `zz-a6-probe~` | Zeichen, das run-parts nicht zulässt |
| `zz-a6-ohne-bit` | kein Ausführbit |

**Und die Gegenprobe steht daneben und nicht dahinter:** `run-parts --test`
nennt keinen der drei, und die Skripte, die es nennt, stehen vollzählig in der
Spalte „Skripte".

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil.**

**Ohne diesen Punkt ist M2 beschrieben und nicht behoben.** Er darf nicht
ausfallen.

**`.placeholder` darf dabei nicht auftauchen.** Es liegt in jedem dieser
Verzeichnisse und gehört zum Paket; stünde es da, meldete die Seite auf jedem
heilen Server einen Fund, den niemand beheben kann.

---

## 3 · Der Zeitpunkt eines Verzeichnisses stimmt

    grep -nE 'cron\.(hourly|daily|weekly|monthly|yearly)' /etc/crontab
    test -x /usr/sbin/anacron && echo "anacron: da" || echo "anacron: fehlt"

**Erfüllt, wenn** die Spalte „Läuft" je Verzeichnis genau das sagt, was die
Zeile hergibt:

| Zeile in `/etc/crontab` | anacron | erwartet |
|---|---|---|
| ohne Vorbehalt | gleich | der Zeitplan der Zeile, in Monospace |
| mit `test -x /usr/sbin/anacron ||` | **da** | „anacron bestimmt den Zeitpunkt" |
| mit `test -x /usr/sbin/anacron ||` | **fehlt** | der Zeitplan der Zeile |
| gar keine | gleich | „kein Zeitplan — nichts hier läuft" |

**Welche Hälfte greift, entscheidet der Server und nicht dieser Text.** Steht
anacron dort, ist der Satz zum ersten Mal überhaupt zu sehen (§0.5); fehlt es,
bleibt er ungeprüft und wird im Protokoll als offen benannt.

> **Eine Zeile, die eine Bedingung trägt, sagt ohne die Bedingung das
> Gegenteil.**

---

## 4 · `cron.yearly` trägt keinen Zeitpunkt *(Ausschluss)*

**Zuerst die Vorbedingung** (§0.3):

    ls -d /etc/cron.yearly && grep -c 'cron\.yearly' /etc/crontab

**Erfüllt, wenn** das Verzeichnis da ist, `/etc/crontab` es **nicht** nennt
(`0`), und die Seite in der Spalte „Läuft" **„kein Zeitplan — nichts hier
läuft"** zeigt — und nicht „—" ohne Erklärung.

**Gibt `grep -c` etwas anderes als `0`, ist der Punkt nicht herstellbar** und
das gehört ins Protokoll; er ist dann trotzdem nicht „erfüllt".

---

## 5 · Die Dateien des Panels sind erkennbar

    ls -la /etc/cron.d/srvpanel-*

**Gibt es keine**, wird eine hergestellt — und zwar **über das Panel** und nicht
von Hand: auf `/cron` ein Abonnement wählen, einen Job mit `/bin/true` anlegen,
danach die Datei prüfen. Ein von Hand geschriebenes `/etc/cron.d/srvpanel-x`
prüfte den Prüfkörper und nicht den Weg.

**Erfüllt, wenn** je Datei **eine** Zeile dasteht mit

- dem Pfad in der Spalte „Datei",
- `—` in „Zeitplan" und „Benutzer",
- dem Satz „Vom Panel verwaltet — **auf der Cronseite**" mit einem Verweis, der
  auf `/cron` führt,

und wenn der **Inhalt** dieser Dateien nirgends auf der Seite steht.

    grep -h '' /etc/cron.d/srvpanel-* | grep -vE '^[[:space:]]*(#|$)'

Keine dieser Zeilen darf im Bereich „Zeitpläne" vorkommen.

---

## 6 · Nicht feststellbar bleibt nicht feststellbar

    systemctl stop srvpanel-agentd
    sleep 2
    systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics srvpanel-web

**Erfüllt, wenn** `/schedules` mit **200** antwortet und oben steht *„Die
Zeitpläne sind nicht feststellbar — der Agent hat nicht geantwortet. Das heisst
nicht, dass keine laufen."*, und **keine** leere Tabelle mit der Aussage „keine
Zeitpläne".

**Zurück, über das Ziel und nicht über den Agenten** (§0.4):

    systemctl start srvpanel.target
    sleep 2
    systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics srvpanel-web

**Erwartet:** alle vier `active`.

---

## 7 · Die Tür

| Konto | `/schedules` | Menüpunkt „Zeitpläne" |
|---|---|---|
| **Betreiber** | 200 | sichtbar |
| **Administrator** | **403** | **nicht sichtbar** |
| **Kundenkonto** | **403** | nicht sichtbar |

**~~Der 403 ist Laravels englische Vorgabeseite~~ — dieser Satz war beim
Ausschreiben schon falsch.** Er stammt aus `docs/84`, und die Behebung
steht im selben Lauf: `resources/views/errors/` führt seit dem **25. August
2026** sieben Blades samt Layout — Befund 3 desselben Protokolls.
Zitiert habe ich also den Befund und nicht seine Behebung, die zwei Absätze
weiter steht. Gemessen im Lauf zeigt der 403
eine deutsche, gestaltete Seite — „Kein Zutritt · Dieser Bereich gehört einer
Rolle, die dieses Konto nicht hat" mit einem Weg zurück zur Übersicht.

> **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung — und
> nichts prüft sie.** Sie stand hier als Beruhigung („kein Befund"), und eine
> Beruhigung liest niemand nach.

Erfüllt ist der Punkt am **Rückgabewert** und an der Sichtbarkeit des
Menüpunkts; wie die Seite dahinter aussieht, ist nicht Gegenstand von A6.

**Und die zweite Spalte gehört gemessen und nicht angesehen.** Beim
Administrator, in der Konsole, **auf einer direkt geladenen Seite**:

    const p = JSON.parse(document.querySelector('script[data-page]').textContent).props
    console.log('operate-server:', p.abilities?.['operate-server'])

**Erwartet:** beim Administrator `false` oder `undefined`, beim Betreiber
`true`. Ein Menüpunkt, den der Betrachter sieht und der ihm einen 403 gibt, ist
der Zustand, gegen den es `AbilityReachTest` gibt.

> **Wer eine Aktion zeigt, fragt vorher dieselbe Policy, die sie später
> abweist.**

---

## 8 · 390 px

`tests/bilder-messen.js` in die Browserkonsole, dann `bilderMessen()` — **einmal
je frisch geladener Seite**, vier Lagen (390/1440 × hell/dunkel).

**Mit den Prüfkörpern aus §1b und §2 an ihrem Platz**, damit das lange Kommando
und der Bereich „Übergangen" im Bild stehen.

**Erwartet:** `dokument = 0`, `gegenprobe = 200 (soll 200)`, `schiebt = 0` —
dieselben vier Werte wie im Container (`docs/111 §6b`), aber gegen den echten
Bestand, der länger ist.

**Und die Zelle gegen ihren Bereich**, weil eine Zelle überlappen kann, ohne
dass die Seite schiebt:

    [...document.querySelectorAll('table.stacks td')].map(td => {
      const b = (td.closest('section, .section') || td.closest('table').parentElement).getBoundingClientRect()
      const z = td.getBoundingClientRect()
      return { text: td.textContent.trim().slice(0, 40), ueber: Math.round(z.right - b.right) }
    }).filter(x => x.ueber > 0)

**Erwartet:** eine leere Liste. Genau hier ist der Befund des Containers
entstanden — bei `dokument = 0` lief die Tabelle **736 px** über ihren Bereich,
weil `overflow-wrap` sagt, *wo* gebrochen werden darf, und nicht *wann*.

> **Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur keine Zahl,
> die sich beschwert.**

---

## 8b · Der Abbau, und er wird belegt

    rm -f /etc/cron.d/zz-a6-probe
    rm -f /etc/cron.daily/zz-a6-probe.sh '/etc/cron.daily/zz-a6-probe~' /etc/cron.daily/zz-a6-ohne-bit
    ls -la /etc/cron.d/ /etc/cron.daily/
    md5sum /etc/crontab
    systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics srvpanel-web

**Erwartet:** beide Verzeichnisse Zeile für Zeile wie im Vorflug, dieselbe
Prüfsumme für `/etc/crontab`, alle vier Dienste `active` — und `/schedules`
zeigt den Bereich „Übergangen" **gar nicht mehr**, weil er nur dasteht, wenn es
etwas zu zeigen gibt.

Der Cronjob aus §5, falls dafür einer angelegt wurde, wird über `/cron` wieder
entfernt und nicht von Hand.

---

## 8c · Was an dieser Vorschrift schon gefahren ist

**Die Befehlsfolgen aus §1a, §1b und §2 sind im Container gegen den Leser
gefahren worden, bevor dieses Dokument stand** — und der Prüfstand ist danach
abgeräumt und belegt.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

Gemessen dabei: `§1a` zählt 4 + 2 + 1 = **7** Zeilen und trifft damit genau die
Zahl, die die Seite im Bereich „Zeitpläne" zeigt. Die Prüfkörper aus §1b
erscheinen als `'@daily' | root | /bin/true --abnahme-a6` und
`'17 4 * * *' | root | …` — die `@`-Form also mit einem Zeitfeld. Und §2 ergibt
die drei erwarteten Gründe (`not-executable`, `dot`, `character`), während
`run-parts --test` keinen der drei nennt.

**Was das nicht belegt:** dass sie auf `cloudsrv24` dasselbe tun. Der Bestand
dort ist ein anderer, `/etc/crontab` kann eine andere Fassung sein, und anacron
kann da sein. Belegt ist nur, dass die Vorschrift ausführbar ist und misst, was
sie zu messen behauptet.

---

## 9 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Ob eine Zeile für cron gültig ist.** Gemessen (M4) nimmt ein unbekannter
  `@`-Name mit `Syntax error, this crontab file will be ignored` die **ganze
  Datei** mit, und zu sehen ist das nur, wenn man cron startet. A6 zeigt die
  Zeilen, wie sie dastehen; ein Nachbau von crons Parser wäre dessen zweite
  Fassung.
- **Die Cronjobs einzelner Benutzer** (`crontab -l -u`,
  `/var/spool/cron/crontabs`). Eigener Bestand, eigene Rechte, eigene Messrunde
  (`docs/111 §8`).
- **`/etc/anacrontab`.** A6 sagt, **dass** anacron den Zeitpunkt bestimmt, und
  nicht welchen.
- **Die nächste Fälligkeit.** Sie zu rechnen wäre eine Zusage über fremde
  Zeilen, deren Zone und Vorbehalte A6 nicht kennt.
- **Die Bestandsdiagnose.** A10 ist abgenommen; ein `check` für die stillen
  Fälle ist der nächste Schritt und braucht seinen eigenen Nachlauf
  (`docs/111 §2`, Frage 4).
- **Ein Schreibweg.** Es gibt keinen; `/schedules` trägt genau ein `GET`.

---

## 10 · Wann er durch ist

**Alle acht Punkte erfüllt**, und **2 und 4 dürfen nicht ausfallen**. Fällt
einer der übrigen als „nicht herstellbar" aus, wird er benannt — und „nicht
herstellbar" heisst am **Gegenstand** gescheitert und nicht am Werkzeug.

> **Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht
> „nicht herstellbar" — und ihn so zu nennen wäre die bequemere von zwei
> falschen Auskünften.**

Ins Protokoll gehören:

- die **Fassung**, gegen die gemessen wurde,
- je Punkt der **gemessene** Wert und nicht „erfüllt",
- welche Hälfte von Punkt 3 gegriffen hat und welche damit ungeprüft bleibt,
- der Beleg aus §8b, dass der Prüfstand abgeräumt ist,
- und was danach **offen bleibt**.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
