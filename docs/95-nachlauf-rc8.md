# Der Nachlauf zu `0.7.3-rc.8` — M1, Befund 2, Befund 3 und die Wege zurück

**Ausgeschrieben am 1. September 2026, vor dem Fahren.** Er gehört auf
`cloudsrv24`, gegen `0.7.3-rc.8`, und er ist der erste Lauf, der drei Dinge
messen kann, die bis heute nur behauptet sind.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

---

## 0 · Was vor dem Lauf gelesen wird

**Drei Punkte aus `docs/93` waren nicht erfüllbar, und alle drei hätte ein Blick
in den Quelltext gezeigt.** Die Kriterien hier sind deshalb am Quelltext
abgeleitet; wo eine Zeile eine Zahl oder einen Wortlaut nennt, steht daneben,
woher sie kommt.

> **Ein Lauf, dessen Kriterien man aus dem Merkmal ableitet statt aus dem
> Server, misst das Merkmal am Wunsch und nicht am Bestand.**

**Und jeder Prüfkörper ist daraufhin angesehen, ob Erfolgs- und Fehlerfall
verschieden aussehen.** Punkt 8 aus `docs/83` lief gegen ein Konto, das den
Zielzustand schon hatte; Punkt 4c aus `docs/93` konnte den Erfolg nicht vom
Fehlschlag unterscheiden, weil beide Fälle kein `←` zeigten.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

### Die Reihenfolge ist bindend

Punkt 1 **verbraucht** den Zustand „es steht ein Update an" — genau wie in
`docs/88 §21`, wo Punkt 4 einen Knopf verlangte, den sein eigener Vorlauf
entfernt hatte.

> **Ein Lauf, der einen Bestand braucht, wird von dem Lauf davor geleert.**

Punkt 2 braucht deshalb den Zustand **nach** Punkt 1, und Punkt 4 rührt die
Paketquelle an und stellt sie wieder her, bevor Punkt 5 anfängt.

### Was auf dem Server vorher stimmen muss

    srvpanel version          -> 0.7.3-rc.7      (nicht rc.8 — sonst gibt es nichts zu messen)
    systemctl is-active srvpanel-worker srvpanel-agentd
    ls -l /opt/srvpanel/current

**Steht dort schon `rc.8`, ist dieser Lauf nicht mehr fahrbar** und wartet auf
`rc.9`. Das ist keine Kleinigkeit: Punkt 1 ist der einzige Grund, warum es ihn
gibt.

---

## 1 · Punkt 1 — M1 und Befund 5 in einem Zug

**Der Kern des Laufs.** `rc.7`s `Update.php` liest `rc.7`s `apt-run` — der erste
Sprung, bei dem beide Hälften behoben sind.

```
date +%T; time srvpanel update; echo "rc=$?"; date +%T
```

**Erwartet:**

| | |
|---|---|
| Der Befehl **bleibt**, bis der Lauf durch ist | mindestens die Dauer eines `apt-get install`, nicht drei Sekunden |
| Mitgelesen erscheinen die Zeilen von apt in grau | `Outcome::lines()` gibt **alle** Zeilen, `mitlesen()` druckt sie |
| Die erste graue Zeile ist die Auffrischung, **ohne** Präfix | `PanelUpdate` schreibt sie, seit Befund 2 |
| Das Urteil lautet `apt-run: Fassung 0.7.3~rc.7 wurde zu 0.7.3~rc.8.` | grün, mit **beiden** Nummern |
| `rc=0` | |

**Der Beleg ist die Dauer und nicht die Fassungsnummer.** Die Fassung steht
danach auch dann auf `rc.8`, wenn der Befehl wie am 1. September nach drei
Sekunden mit einer Fortschrittszeile zurückkam — das Update lief ja weiter.

> **Ein falsches Verfahren, dessen Ergebnis zufällig stimmt, sieht von aussen
> aus wie ein richtiges.**

**Damit ist M1 belegt oder widerlegt.** Der Befehl kann das Urteil nur nennen,
wenn sein Prozess den Symlink-Wechsel überlebt **und** `vorladen()` genug
geladen hat: `agent/` liegt im Fassungsverzeichnis, und das Paket räumt es ab.
Ein Abbruch mit `Class "SrvPanel\Agent\Outcome" not found` mitten im Lauf ist
das Gegenteil und ein Befund.

**Fällt Punkt 1 aus** (kein Update verfügbar, weil schon `rc.8` steht), fällt
der ganze Lauf — siehe §0.

---

## 2 · Punkt 2 — Befund 2: nichts anstehendes ist kein Fehlschlag

Unmittelbar nach Punkt 1, ohne etwas dazwischen:

```
srvpanel update; echo "rc=$?"
```

**Erwartet:** `apt-run: Es stand nichts an — Fassung unverändert: 0.7.3~rc.8.`
und **`rc=0`**.

Vor dieser Fassung stand dort `Der Lauf hat nichts verändert — Fassung vorher
wie nachher: …` mit `rc=3`, und die Unit auf `failed`. Gemessen am 27. August
(`docs/94 §4`).

**Gegenprobe im selben Schritt** — die transiente Unit darf nicht rot sein. Der
Name steht in der Zeile „Das Update läuft als …":

```
journalctl -u srvpanel-update-<kennung> --no-pager | tail -5
```

Erwartet: **keine** Zeile `Failed with result 'exit-code'` und kein
`status=3/NOTIMPLEMENTED`. **Ein grünes Urteil auf der Konsole und eine rote
Unit wären zwei Antworten auf dieselbe Frage** — und genau das war der Zustand
vor dieser Fassung.

> **Berichtigt am 1. September 2026, nachdem die erste Fassung gefahren
> war** (`docs/96 §2`, Befund 9). Sie las den neuesten Vorgang aus der
> Datenbank und erwartete `succeeded` — `srvpanel update` legt aber gar keinen
> Vorgang an: Es ruft `panel.update` unmittelbar über den Agenten, und das Panel
> hat für die eigene Aktualisierung keine Fläche. Zurück kam eine fremde Zeile
> vom Vortag, und sie stand auf `succeeded`.
>
> **Eine Frage nach dem neuesten Datensatz beantwortet, welcher der neueste ist
> — nicht, ob der gesuchte darunter ist.**

---

## 3 · Punkt 3 — die Auffrischung steht im Protokoll und kommt nicht mehr aus `apt-run`

```
grep -n 'Paketlisten' /var/log/srvpanel/update.log
grep -c 'apt-get.*update' /usr/lib/srvpanel/apt-run
```

**Erwartet:**

- Im Protokoll steht `Paketlisten aufgefrischt; jede Quelle hat geantwortet.`
  **ohne** `apt-run: ` davor — sonst läse der Leser sie als Urteil (Befund 5).
- `apt-run` enthält **null** Aufrufe von `apt-get update`.

Beide Hälften derselben Naht: Stünde die Meldung an beiden Stellen, erschiene
sie zweimal, und die zweite käme aus einem Lauf, der gar nicht mehr auffrischt.

---

## 4 · Punkt 4 — die tote eigene Quelle bricht ab, statt „nichts verändert" zu melden

**Das ist die Absicherung gegen M5.** Die Simulation, die Befund 2 trägt, kann
„war schon aktuell" nicht von „die Listen sind zu alt" unterscheiden; deshalb
prüft `PanelUpdate` vor dem Absetzen die eigene Quelle über `Apt::hitting()`.

**Dieser Punkt fasst eine Datei von Hand an.** Erst sichern:

```
cp -a /etc/apt/sources.list.d/srvpanel.sources /root/srvpanel.sources.bak
sed -i 's#^URIs:.*#URIs: https://gibtesnicht.invalid/srvpanel#' \
    /etc/apt/sources.list.d/srvpanel.sources
srvpanel update; echo "rc=$?"
```

**Erwartet:** Der Befehl bricht **vor** dem Absetzen ab, mit einer Meldung, die
die Quelle nennt:

    Die Paketquelle des Panels gibtesnicht.invalid ist nicht erreichbar: …
    Ohne sie kennt apt nur die alten Paketlisten, und ein Update fände nichts
    Neues — es wurde deshalb nicht begonnen.

und `rc=1`. **Es darf keine Unit entstehen** und im Protokoll keine neue Zeile.

> **Eine Prüfung, die den Zustand fängt, hat über die Ursache nichts gesagt —
> und der Leser sucht dort, wohin die Meldung zeigt.**

Zurück, und zwar **belegt**:

```
cp -a /root/srvpanel.sources.bak /etc/apt/sources.list.d/srvpanel.sources
diff /root/srvpanel.sources.bak /etc/apt/sources.list.d/srvpanel.sources && echo 'zurueck'
```

### 4b · Und die Lücke daneben, die dieser Punkt aufdeckt

**`Enabled: no` ist nicht dasselbe wie unerreichbar** — und das ist beim
Ausschreiben aufgefallen, nicht beim Bauen. Eine **abgeschaltete** Quelle
erzeugt keinen Fehlschlag: apt holt sie gar nicht erst, `Apt::readFailures()`
findet nichts, `hitting()` gibt `null`, und der Lauf geht durch. Danach sieht
apt keine neue Fassung, `ansteht` ist `0` — und gemeldet würde **„Es stand
nichts an"**.

Das ist zu messen und nicht zu vermuten:

```
srvpanel tinker --execute='
  echo json_encode(SrvPanel\Agent\Sources::uris(
      SrvPanel\Agent\Sources::PANEL_SOURCE)), PHP_EOL;'
sed -i 's/^Enabled:.*/Enabled: no/' /etc/apt/sources.list.d/srvpanel.sources \
  || printf 'Enabled: no\n' >> /etc/apt/sources.list.d/srvpanel.sources
srvpanel update; echo "rc=$?"
cp -a /root/srvpanel.sources.bak /etc/apt/sources.list.d/srvpanel.sources
```

**Erwartet wird nichts** — dieser Punkt hat keine Sollantwort, er stellt eine
Frage. Meldet der Lauf `Es stand nichts an`, ist das ein **Befund**: Das Panel
sagt „du bist aktuell", während seine eigene Quelle abgeschaltet ist. Bricht er
ab oder meldet er „nichts verändert", ist die Lücke keine.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

---

## 5 · Punkt 5 — Befund 4 vollständig: `←` **und** der Gegenstand

**Auf der Datenbankseite und nicht auf `/updates`.** Der Grund steht in
`docs/88 §21`: Bei null offenen Aktualisierungen gibt es dort weder Tabelle noch
Knopf. Eine Sicherung lässt sich dagegen beliebig oft auslösen.

1. Im Browser `/databases` öffnen, eine Datenbank anklicken — Adresse notieren,
   z. B. `/databases/22`.
2. **Sicherung erstellen** drücken. Das Panel leitet auf `/operations/{id}`.

**Erwartet auf der Vorgangsseite:**

| | |
|---|---|
| Im Brotkrümel steht `← /databases/22` als **Verknüpfung** | `Show.vue:196`, ein `<Link>` |
| In der Tabelle steht eine Zeile **Sicherung** mit dem Namen der Sicherung, ebenfalls verknüpft | `Show.vue:236` und `:255` |

Der Klick auf `←` führt zurück auf die Datenbankseite.

**Vorgang 729 vom 31. August zeigte den Gegenstand und kein `←`** — das war
Befund 4, und beides zusammen ist der Beleg, dass er behoben ist.

---

## 6 · Punkt 6 — Befund 3: der Zurück-Knopf des Browsers

**Der Fall, für den die Kopfzeile gebaut wurde.** Unmittelbar nach Punkt 5, in
demselben Fenster:

1. Auf der Vorgangsseite den **Zurück-Knopf des Browsers** drücken. Inertia
   stellt `/databases/22` aus dem History-Zustand her — **der Server sieht keine
   Anfrage**.
2. Wieder **Sicherung erstellen** drücken.

**Erwartet:** Der neue Vorgang trägt `← /databases/22`.

**Vor dieser Fassung trüge er `← /operations/{id der ersten}`** — die Sitzung
stand auf der zuletzt gerenderten Seite, und das war die Vorgangsseite.

> **Eine Herkunft, die der Server führt, veraltet bei jeder Navigation, die der
> Server nicht sieht.**

**Und die Ironie gehört zum Befund:** Der Weg, den der Brotkrümel ersetzen soll,
ist genau der, der ihn falsch machte.

**Dies ist zugleich der einzige Beleg, dass `router.on('before')` überhaupt
feuert.** Bis hierher ist das aus der Typdefinition von Inertia 3.6 gelesen und
nicht beobachtet — trägt der zweite Vorgang **gar kein** `←`, feuert das
Ereignis bei `router.post` nicht, und der Entwurf ist falsch.

---

## 7 · Punkt 7 — 4d: ein Vorgang ohne Seite zeigt kein `←`

**`srvpanel vhost --sites` und nicht `srvpanel tls --renew`.** Das Erneuern legt
nur bei einem fälligen Zertifikat einen Vorgang an; `vhost --sites` legt für
**jede** Kundendomain einen an, ohne Bedingung (`ApplyVhost::sites()`).

```
srvpanel vhost --sites
srvpanel tinker --execute='
  foreach (App\Models\Operation::withoutGlobalScopes()->latest("id")->take(3)->get() as $o) {
      echo $o->id, " ", $o->type, " origin=", var_export($o->origin, true), PHP_EOL;
  }'
```

**Erwartet:** `origin=NULL` bei allen dreien, und auf der Vorgangsseite **kein**
`←`.

`null` heisst „von keiner Seite" und ist die Wahrheit für die Konsole, die
Warteschlange und jeden Lauf der Automatik.

**Vor der Behebung war das nicht messbar:** Vorgang 729 zeigte kein `←` *mit*
Sitzung, und damit sahen Erfolgs- und Fehlerfall gleich aus. Erst weil Punkt 5
und 6 ein `←` **zeigen**, sagt sein Fehlen hier etwas.

---

## 8 · Punkt 8 — die Vorgangsseite bei 390 px, mit Herkunft und Gegenstand

`docs/94` Punkt 3 fiel als „nicht herstellbar" aus, weil kein Gegenstand über 25
Zeichen zu bekommen war. Eine Sicherung trägt den Namen der Datenbank samt
Zeitstempel — der ist länger.

Am Vorgang aus Punkt 5, im Browser bei 390 px, in **beiden** Themes:

```
document.documentElement.scrollWidth - document.documentElement.clientWidth
```

**Erwartet: 0.** Dazu ein Blick auf den Brotkrümel — er darf nicht mehr als zwei
Zeilen nehmen (`docs/94 §6b`, Befund ohne Zahl).

> **Eine Beschriftung, die den ganzen Zustand nennt, sagt nicht mehr, wo man war
> — sie sagt nur, dass es kompliziert war.**

**Ohne Gegenprobe ist die Null keine Messung.** Im selben Fenster:

```
document.body.insertAdjacentHTML('beforeend',
  '<div style="width:' + (document.documentElement.scrollWidth + 200) + 'px;height:1px"></div>');
document.documentElement.scrollWidth - document.documentElement.clientWidth
```

Erwartet: **200**. Danach neu laden.

---

## 9 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Den Sprung selbst auf anderen Plattformen.** Gemessen wird auf
  `cloudsrv24` (Ubuntu 24.04). Debian 12/13 und Ubuntu 22.04 deckt der
  CI-Job `apt-Messrunde`.
- **Die Laufzeit über 142 Pakete** (`docs/81 §2.3h` Punkt 1). Punkt 1 spielt
  ein Paket ein.
- **Den Filter in der Herkunft.** Die Filter auf `/updates` sind `ref`s und
  stehen nie in der Adresse (`docs/94 §7`) — das `split('?')` im Brotkrümel
  bleibt unbenutzt und ist nur im Container belegt.
- **Den zweiten Faktor, die Netzbeschränkung, die Rollenteilung.** Abgenommen
  in `docs/84`.

---

## 10 · Wann er durch ist

**Erfüllt sein müssen die Punkte 1, 2, 3, 5, 6 und 7.** Punkt 1 trägt M1 und
Befund 5, Punkt 2 Befund 2, die Punkte 5 bis 7 die Wege zurück.

**Ausfallen dürfen als „nicht herstellbar":**

- **Punkt 8**, wenn der Name der Sicherung wider Erwarten kurz bleibt — dann ist
  der Prüfkörper der falsche und nicht die Seite.
- **Punkt 4b**, wenn `Enabled: no` an der eigenen Quelle den Zugang zum Panel
  selbst stört. Er ist eine Frage und kein Kriterium.

**Nicht ausfallen darf Punkt 4.** Er ist die einzige Stelle, an der belegt wird,
dass Befund 2 M5 nicht wieder aufgerissen hat — und das ist der Fehler, mit dem
diese ganze Stufe angefangen hat.

**Das Protokoll bekommt die nächste freie Nummer und wird hier bewusst nicht
genannt.** `docs/81` hat einmal eine genannt, die einem anderen Dokument
gehörte; seitdem trägt `DocLinkTest` die Regel, und beim Schreiben dieses
Dokuments hat er sofort zugebissen.

> **Eine Nummer, die man vergibt, bevor es das Dokument gibt, ist ein Verweis
> auf nichts.**

Es trägt die gemessenen Werte, die Befunde mit ihren Lehren und am Ende, was
offen bleibt — ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.
