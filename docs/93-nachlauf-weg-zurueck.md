# 93 — Nachlauf zu `0.7.3-rc.4`

Der Weg zurück von der Vorgangsseite (A und B aus `docs/92`), Befund 5, 6 und 7
aus `docs/91 §20`. **Keine dieser Behebungen hat einen Server gesehen**; dieser
Lauf sieht nach, ob sie auf `cloudsrv24` wirken.

Ausgeschrieben **vor** dem Fahren. Was hier als Erwartung steht, ist am
Quelltext nachgesehen und nicht aus dem Gedächtnis geschrieben — §0 sagt, was
dabei herauskam.

---

## 0 · Was das Ausschreiben schon gefunden hat

**`docs/91 §20.5` Punkt 1 ist so nicht fahrbar, und der Fehler ist meiner.**
Dort steht: „ein wartender oneshot-Dienst ist auf **beiden** Seiten grün".

Nachgesehen an `OverviewController::services()`: Die Übersicht zeigt **nicht**
alle sechzehn Units. Sie zeigt, was `Catalog::essential()` nennt — genau drei
Kandidatenlisten —, dazu je einen PostgreSQL-Cluster:

| Zeile | Unit | Bauart |
|---|---|---|
| 1 | `srvpanel-agentd.service` | Daemon |
| 2 | `nginx.service` | Daemon |
| 3 | `mariadb.service` (oder `mysql.service`) | Daemon |
| 4 | `postgresql@16-main.service` | `Type=notify` |

**Keine davon ist `Type=oneshot`.** Die vier Dienste, an denen Befund 1 hing
(`srvpanel-usage`, `-tls`, `-cron`, `-dns`), stehen ausschliesslich auf der
Dienste-Seite. Der zweite Teil von Befund 5 — dass `dienstRang()` die Nachsicht
für oneshot nicht kannte — ist auf der Übersicht **heute** nicht auslösbar.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

Das nimmt Befund 5 nichts: Die zweite Fassung war trotzdem falsch, und sie wäre
in dem Moment sichtbar geworden, in dem jemand eine oneshot-Unit in
`essential()` aufnimmt. Aber **gemessen** wird an dem, was dasteht — Punkt 1
fragt deshalb nach dem **Wort** und nicht nach der Farbe eines Dienstes, den
diese Seite nicht zeigt.

---

## 1 · Befund 5 — dieselbe Unit, dasselbe Wort

**Vorher:** Die Übersicht druckte `active_state` roh, also `active`. Die
Dienste-Seite sagte `läuft`.

Aufrufen: `/` (Übersicht), Bereich **Dienste**. Dann `/services`.

**Erwartet**

1. In der Zustandsspalte der Übersicht steht bei jeder Zeile ein **deutsches**
   Wort — `läuft`, `bereit`, `gestoppt`, `nicht installiert`. Nirgends
   `active`, `inactive`, `failed`.
2. Für **dieselbe Unit** steht auf `/services` dasselbe Wort. Vergleichbar sind
   `srvpanel-agentd.service`, `nginx.service` und `mariadb.service` — die drei,
   die auf beiden Seiten vorkommen.
3. Der Menüpunkt **Alle Dienste** steht am Kopf des Bereichs und führt auf
   `/services`.

**Gegenprobe, und sie ist der eigentliche Beleg.** Ein Wort, das auf beiden
Seiten gleich lautet, könnte auch von zwei Zufällen kommen. Deshalb einen
Dienst anhalten und **beide** Seiten neu laden:

    systemctl stop nginx
    # /  und  /services  ansehen
    systemctl start nginx

Erwartet: beide sagen `gestoppt`, beide malen die Zeile rot. Danach wieder
`läuft` auf beiden.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Was Punkt 1 nicht prüft** (siehe §0): dass ein wartender oneshot-Dienst auf
der Übersicht grün ist. Diese Seite zeigt keinen.

---

## 2 · Befund 6 — derselbe Knopf zweimal

**Vorher:** Der zweite Druck meldete `fehlgeschlagen`, mit der Zahl `0` im
eigenen Satz.

**a — den Zustand herstellen.** Auf `/updates` **Aktualisierungen einspielen**
drücken und den Vorgang zu Ende laufen lassen. Danach steht nichts mehr an.

**b — noch einmal, ohne die Liste neu zu holen.** Denselben Knopf ein zweites
Mal drücken.

**Erwartet**

| | |
|---|---|
| Zustand des Vorgangs | `fertig` — **nicht** `fehlgeschlagen` |
| Vorbehalt | die Meldung nennt „Es stand nichts an" |
| Farbe der Meldung | bernsteinfarben (Vorbehalt), nicht grün und nicht rot |

**c — die Unit dahinter.** Der Lauf wird über `systemd-run` abgesetzt; `--collect`
räumt sie ab. Solange sie noch da ist:

    systemctl list-units 'srvpanel-update-*' --all

Erwartet: keine Unit auf `failed`.

**Der Präfix stand hier erst als `srvpanel-apt-*`, und das war geraten.**
`AptLock::UNIT_PREFIX` lautet `srvpanel-update-`; das Muster hätte nie etwas
gefunden, und ein leeres Ergebnis liest sich wie „keine Unit auf failed".

> **Ein Muster, das nichts findet, beantwortet jede Frage mit Ja.**

**d — die Gegenprobe, und sie ist die wichtigere Hälfte.** Die Nachsicht gilt
**nur** im Zählmodus. Bei `--fassung` ist `nachher` eine Versionsnummer und nie
`0`; eine Fassung, die sich nicht ändert, war der Grund des Laufs und bleibt ein
Fehlschlag.

    srvpanel update

auf einem Stand, der schon der neueste ist. Erwartet: **Fehlschlag**, nicht
„Es stand nichts an".

> **Eine Nachsicht, die man nicht an ihrer Grenze misst, ist eine Vermutung über
> ihre Grenze.**

**Und was dieser Punkt belegt, ist enger als es klingt.** Er zeigt, dass die
Behebung von Befund 6 **nicht in den Fassungsmodus geleckt** ist — nicht, dass
der Fassungsmodus richtig entscheidet. Am Skript nachgesehen (31. August):

| | `vorher` | `nachher` | Urteil heute |
|---|---|---|---|
| schon aktuell | `0.7.3-rc.4` | `0.7.3-rc.4` | Fehlschlag |
| Einspielen misslungen | `0.7.3-rc.3` | `0.7.3-rc.3` | Fehlschlag |

Die beiden sind **an der gemessenen Grösse nicht zu unterscheiden**, und das ist
dieselbe Lage wie bei Befund 6 — nur ohne die Zahl, die dort die Unterscheidung
trug. Eine Versionsnummer ist nie `0`; es gibt keinen Wert, an dem „es stand
nichts an" ablesbar wäre.

Dass der Fall trotzdem zum Fehlschlag fällt, ist deshalb **keine
Unachtsamkeit, sondern die sichere Richtung**: Wer nicht unterscheiden kann,
meldet lieber einmal zu viel. Ablesbar **wäre** es — `apt-get -s install
--only-upgrade srvpanel` sagt vor dem Lauf, ob überhaupt etwas ansteht —, aber
das ist eine Änderung und keine Messung. Sie steht als Frage in §7 und wird in
diesem Lauf nicht gebaut.

> **Ein Fall, den man nicht unterscheiden kann, und einer, den man nicht
> unterscheiden will, sehen im Code gleich aus — der Unterschied steht im
> Kommentar oder nirgends.**

---

## 3 · Befund 7 — die Vorgangsseite bei 390 px

**Vorher:** `<td class="right">` mit einem `<a class="link ident">` darin schob
das Dokument um **59 px** aus dem Bild. Gemessen war das am Aufsatz im
Container; hier zählt die echte Seite.

Gebraucht wird ein Vorgang mit einem **langen** Gegenstand. Auf `cloudsrv24`
heisst die längste Datenbank `x1b311d2b6eedc3aa_p5c` (22 Zeichen) — das reicht
nicht. Also einen Vorgang an einer Domain, deren Name lang ist, oder eine
Datenbank mit einem langen Namen anlegen und wieder entfernen.

Aufrufen: die Vorgangsseite dieses Vorgangs, Fenster auf **390 px**.

**Erwartet**

1. `document.documentElement.scrollWidth - clientWidth` ist **0**.
2. Der Name des Gegenstands bricht **innerhalb** seiner Zelle um.
3. Die Gegenprobe aus `tests/bilder-messen.js` schlägt mit **200/200** aus.

Gemessen wird mit dem Messmittel aus dem Repo und nicht mit einer Zeile, die
hier entsteht:

> **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht noch
> einmal.**

---

## 4 · A und B — der Weg zurück und der Gegenstand

**a — die Herkunft.** Auf `/updates` **Nach Aktualisierungen sehen** drücken.
Der Brotkrümel der Vorgangsseite trägt links `← /updates` als Verknüpfung, und
sie führt dorthin zurück.

**b — die Herkunft mit Filter.** Auf `/updates` einen Filter setzen (etwa „nur
Sicherheit"), dann einen Knopf drücken.

**Erwartet:** Der Brotkrümel **zeigt** `/updates` ohne die Frage, der Verweis
**trägt** sie — nach dem Klick steht der Filter wieder.

> **Eine Beschriftung, die den ganzen Zustand nennt, sagt nicht mehr, wo man
> war — sie sagt nur, dass es kompliziert war.**

**c — der Gegenstand.** Eine Domain anlegen. Auf der Vorgangsseite steht eine
Zeile `Domain` mit dem Namen als Verknüpfung; sie führt auf `/domains/{id}`.

**d — die Gegenprobe, und ohne sie belegt c nichts.** Ein Vorgang **ohne**
Herkunft und ohne Gegenstand darf keinen leeren Verweis zeigen. Den liefert die
Automatik:

    srvpanel tls --renew

Erwartet: Die Vorgangsseite dieses Vorgangs trägt **kein** `←` im Brotkrümel
und **keine** Zeile „Domain" — weil er von keiner Seite kam.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

---

## 5 · Was dieser Lauf ausdrücklich nicht prüft

- **Die Herkunft über 255 Zeichen.** Sie wird verworfen; ein Pfad dieser Länge
  entsteht in diesem Panel nicht, und einen zu bauen hiesse, den Prüfling um
  eine Route zu erweitern.
- **Den Gegenstand „Sicherung" ohne Datenbank.** Er verlangt eine gelöschte
  Datenbank mit lebender Sicherung — herstellbar, aber es zerstört Bestand.
  Der Zweig ist im Wächter gehalten und hier benannt offen.
- **Ob die Reihenfolge der Wörter auf beiden Seiten gleich ist.** Punkt 1 fragt
  je Unit und nicht je Tabelle.

## 6 · Wann er durch ist

Alle vier Punkte erfüllt. **Punkt 2d ist das Ausschlusskriterium**: Fällt die
Nachsicht auch bei `--fassung`, ist Befund 6 falsch behoben und ein Panel-Update
ohne Wirkung meldete Erfolg — schlimmer als der Zustand vorher.

Punkt 3 darf als „nicht herstellbar" ausfallen, wenn sich kein hinreichend
langer Gegenstand ergibt, ohne Bestand anzulegen. Dann bleibt er benannt offen
und nicht abgehakt.

---

## 7 · Die eine Frage, die dieser Lauf aufwirft und nicht entscheidet

**Soll `srvpanel update` auf einem schon aktuellen Stand einen Fehlschlag
melden?**

Heute tut es das, und §2d belegt es. Der Grund ist gut (die sichere Richtung,
siehe dort), aber er ist nicht der einzige mögliche: `apt-get -s install
--only-upgrade srvpanel` wüsste vor dem Lauf, ob etwas ansteht, und dann liesse
sich „schon aktuell" von „nicht geschafft" trennen — genau wie Befund 6 es im
Zählmodus tut.

Das ist eine Entscheidung des Betreibers und keine des Laufs. Sie hängt daran,
wie oft `srvpanel update` auf einem aktuellen Stand läuft: von Hand selten, aus
einem nächtlichen Skript jede Nacht.
