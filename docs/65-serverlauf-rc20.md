# Der Serverlauf zu `v0.6.0-rc.20`

**Was hier geprüft wird:** die sieben Befunde der zweiten Bilderrunde und die
drei Wünsche, die danach gebaut wurden — auf einem echten Server, mit echten
Daten, im Browser.

**Warum es diesen Lauf gibt.** Alles darunter ist im Container gemessen, gegen
das gebaute Stylesheet und mit Gegenprobe. Der Aufsatz trifft die echte Seite
aufs Pixel (`docs/56` Punkt 5) — aber er trifft sie an einer Seite, die jemand
von Hand zusammengesetzt hat. Drei Dinge kann er grundsätzlich nicht:

- **Ein Herunterladen.** `Blob` und `<a download>` brauchen die laufende Seite.
- **`<style scoped>`.** Vite bindet solche Regeln an ein Attribut, das nur der
  Übersetzer setzt (`docs/59`).
- **Echte Daten.** Ein Pfad, ein Dateiname, ein Befehl in einem Cronjob — ihre
  Länge entscheidet über die halbe Geometrie, und im Aufsatz erfinde ich sie.

> **Eine Messung an einer selbst gebauten Seite belegt das Stylesheet und nicht
> die Anwendung.**

---

## 0. Vorbereitung

### 0.1 Fassung prüfen — zuerst, und nicht später

`docs/47` hat eine Fassungsprüfung in der falschen Datei gesucht und den halben
Lauf gegen den alten Stand gefahren. Deshalb steht sie hier vorn:

```bash
readlink -f /opt/srvpanel/current
```

Erwartet wird ein Pfad, der auf `0.6.0-rc.20` endet. Steht dort etwas anderes,
ist alles Folgende ohne Wert.

### 0.2 Die Adressen ermitteln

Der Lauf braucht ein Abonnement mit SFTP-Zugang und Cronjobs. Diese Zeile
druckt die Kennungen — sie ändert nichts:

```bash
wert() { sed -n "s/^$1=//p" /etc/srvpanel/panel.env | tail -1 | sed 's/^"//;s/"$//'; }

MYSQL_PWD="$(wert DB_PASSWORD)" mysql --default-character-set=utf8mb4 \
  -h "$(wert DB_HOST)" -P "$(wert DB_PORT)" -u "$(wert DB_USERNAME)" \
  "$(wert DB_DATABASE)" \
  -e "SELECT id, name, system_user FROM subscriptions ORDER BY id;"
```

**Warum SQL und nicht `srvpanel tinker`.** Der naheliegende Weg wäre
`srvpanel tinker --execute="…"`. Er ist am 20. August auf `cloudsrv24`
gescheitert, und zwar in der Form, vor der `packaging/bin/srvpanel` in seinem
eigenen Kommentar warnt: eine Warnung, kein Ergebnis, Rückgabewert 0 (Befund 1
in `docs/66`). Behoben ist die Ursache, aber ein Plan hängt sich nicht an einen
Weg, der schon einmal still war.

> **Ein Abnahmelauf, der eine ungeprüfte Annahme als Anweisung führt, prüft sie
> nicht — er führt sie aus.**

Drei Dinge an dieser Abfrage mit Absicht: `--default-character-set=utf8mb4`,
weil `mysql` sonst latin1 aushandeln kann und aus einem `ü` ein einzelnes Byte
macht (`docs/47`); `MYSQL_PWD` statt `-p…`, damit das Passwort nicht in `ps`
steht; und kein Eloquent, also **keine Mandantenklammer** — es kommen alle
Abonnements.

Die Kennung des Abonnements, mit dem gearbeitet wird, kommt in eine Variable —
sie wird unten in jedem Befehl gebraucht:

```bash
ABO=140          # anpassen: die Zahl aus der Ausgabe oben
PANEL=https://cloudsrv24.de:8443   # anpassen: die Adresse Ihres Panels
```

Und die Adressen für den Browser druckt diese Zeile fertig aus:

```bash
for p in files sftp cron; do echo "$PANEL/subscriptions/$ABO/$p"; done
```

### 0.3 Das Messmittel laden

`tests/bilder-messen.js` aus dem Repo **vollständig** in die Konsole des
Browsers einfügen, dann je Lage aufrufen:

```js
JSON.stringify(bilderMessen())
```

**Drei Dinge, die dieser Lauf schon gekostet hat:**

1. **Nach jedem Neuladen ist das Skript fort** — es kommt aus der Zwischenablage
   zurück, und die altert nicht sichtbar. Jede Ausgabe trägt deshalb `stand`;
   steht dort nicht `2026-08-19`, ist es die alte Fassung.
2. **Nach einem Wechsel der Fensterbreite neu laden.** Eine Messung ohne
   Neuladen trägt Reste mit — derselbe Überlauf von 468 px bei 390 und bei
   1440, den dieselbe Seite frisch geladen nicht hat.
3. **`gegenprobe.ausschlag` muss `200` sein.** Steht dort etwas anderes, misst
   der Rest der Zeile nichts, und eine `0` bei `dokument` bedeutet nichts.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

### 0.4 Was zurückkommt

Je Punkt: die **Bilder**, die dort verlangt sind, und die **ganze Zeile** aus
`JSON.stringify(bilderMessen())` — nicht nur `dokument`. `schiebt` und `rollt`
nennen den Ort, und ohne sie ist eine Zahl nur eine Zahl.

---

## 1. Befund 17 — ein Wurzelelement, nicht zwei

Bis `rc.19` stand `@inertia` im `<head>`; die Direktive setzt ein `<div>`, im
Kopf ist das nicht erlaubt, und der Browser hat es in den Rumpf verschoben. Das
Dokument trug danach **zwei** Elemente mit `id="app"`.

**Auf einer beliebigen Seite des Panels, in der Konsole:**

```js
document.querySelectorAll('#app').length
```

| erwartet | |
|---|---|
| `1` | erfüllt |
| `2` | Befund 17 ist zurück |

**Und die Gegenprobe, damit die `1` etwas bedeutet:**

```js
document.querySelectorAll('body').length
```

Muss ebenfalls `1` ergeben — sonst zählt der Ausdruck nicht, was er soll.

Kein Bild nötig.

---

## 2. Befund 15 — die Rückmeldungen sprechen deutsch

Bis `rc.19` setzte Laravel den Bezeichner ein: „Das Feld **day of week** ist
erforderlich."

**Auf `/subscriptions/$ABO/cron`:**

1. Zum Bereich „Job anlegen" gehen (bei 390 px über den Griff in der Kopfzeile
   der Jobliste — das ist zugleich Punkt 6).
2. Beschriftung und Befehl **leer lassen**, „Anlegen" drücken.

**Erwartet** oben in der Zusammenfassung: „Das Feld **Beschriftung** ist
erforderlich", „Das Feld **Befehl** ist erforderlich" — deutsche Wörter, keine
Bezeichner.

**Und die zweite Stelle, an der es zählt** — auf
`/subscriptions/$ABO/databases`, beim Anlegen einer Datenbank ohne Namen:
Erwartet wird „Das Feld **Name**…" und nicht „name".

**Bild:** die Zusammenfassung, 390 px, ein Theme.

**Gegenprobe:** Trägt eine Meldung ein englisches Wort, notieren Sie **welches**
— der Wächter kennt 85 Namen, und ein fehlender ist ein Fund und kein
Missgeschick.

---

## 3. Befund 16 — die Meldung der Experteneingabe

Sie nannte Felder, die in dieser Ansicht **eingeklappt** sind.

**Auf `/subscriptions/$ABO/cron`, im Bereich „Job anlegen":**

1. Das Kästchen **„Den Zeitplan als Ausdruck eingeben"** ankreuzen.
2. In das Feld „Ausdruck" `* * *` eintragen (drei Sterne, nicht fünf).
3. Beschriftung und Befehl ausfüllen, „Anlegen" drücken.

**Erwartet:**

> Im Ausdruck fehlt der 4. Teil (Monat).
> Im Ausdruck fehlt der 5. Teil (Wochentag).

**Nicht** erwartet: „Das Feld Monat ist erforderlich."

**Und das Feld selbst wird rot** — es trägt `aria-invalid`. Prüfbar in der
Konsole:

```js
document.querySelector('#expression').getAttribute('aria-invalid')
```

Erwartet: `"true"`.

**Bild:** Meldung und Feld zusammen, 390 px, beide Themes (die Meldung bringt
einen eigenen Kontrast mit).

---

## 4. Befund 12 — die Auskunft über das volle Kontingent

Sie stand im Bereich „Job anlegen", also über dem Formular — bei 390 px
gemessen **3566 px** von der Oberkante auf einer Seite von 3795 px. Vier
Bildschirme.

**Vorbereitung: das Kontingent ausschöpfen.** Wie viele Jobs der Plan erlaubt,
steht auf der Seite selbst („… von 10"). Legen Sie so viele an, bis „Anlegen"
nicht mehr geht — oder setzen Sie das Kontingent für dieses Abonnement kurz
herunter (`/subscriptions/$ABO/edit`, Übersteuerung „Cronjobs" auf die Zahl der
vorhandenen Jobs). **Der zweite Weg ist der kürzere und wird danach
zurückgestellt.**

**Bei 390 px, Seite frisch geladen, in der Konsole:**

```js
JSON.stringify({
  ...bilderMessen(),
  kontingent: (() => {
    const n = [...document.querySelectorAll('.notice')]
      .find((e) => e.textContent.includes('Kontingent'))
    return n === undefined ? 'nicht gefunden' : {
      oben: Math.round(n.getBoundingClientRect().top + window.scrollY),
      text: n.textContent.replace(/\s+/g, ' ').trim(),
    }
  })(),
})
```

| | erwartet |
|---|---|
| `kontingent.oben` | **weit unter der halben Seitenhöhe** und im ersten Bildschirm — vorher 3566 von 3795 px |
| `kontingent.text` | enthält die Zahl, z. B. „(10 von 10)" |
| `dokument` | `0` |
| `gegenprobe.ausschlag` | `200` |

**Keine feste Pixelzahl.** Hier stand „18", gemessen am Aufsatz im Container —
und der hat kein Band „Sie arbeiten in der Sicht dieses Kunden", keine
Kopfzeile und keine Beizeile. Auf dem Server waren es **294**, und die Meldung
stand trotzdem genau richtig (Befund 4 in `docs/66`).

> **Ein Wert, der an einer anderen Seite gemessen wurde, gehört zu einer
> anderen Seite.**

Geprüft wird deshalb der **Ort**: Die Meldung steht **über** den Bereichen,
also vor „Zeitplan und Zeitzone" — und nicht im Bereich „Job anlegen".

**`nicht gefunden` ist kein Erfolg**, sondern eine kaputte Messung — dann steht
die Meldung nicht da, obwohl das Kontingent voll ist.

**Bild:** der obere Rand der Seite bei 390 px, beide Themes.

**Danach:** die Übersteuerung zurücknehmen oder die Testjobs entfernen.

---

## 5. Befund 14 — der Bereich „Job anlegen" bei 1440 px

Beschriftung / Befehl und darunter Schnellwahl / Zeitplan lagen als 2×2
nebeneinander; unter der Schnellwahl blieb eine grosse leere Fläche. Die
Schnellwahl steht jetzt **im** Zeitplan, denn sie stellt ihn ein.

**Bei 1440 px, Bereich „Job anlegen" im Blick:**

```js
JSON.stringify({
  ...bilderMessen(),
  gruppen: [...document.querySelectorAll('form .field-row, form .field.wide')]
    .map((e) => {
      const r = e.getBoundingClientRect()
      return `${e.className}  ${Math.round(r.width)}x${Math.round(r.height)}`
    }),
})
```

**Erwartet:** Die Schnellwahl steht **innerhalb** der Zeitplangruppe, und die
Zeitplangruppe hat die **volle** Breite (rund 1140 px bei 1440 px Fenster) und
nicht 540.

> **Eine Umgruppierung, die die Breite nicht mitnimmt, verschiebt die leere
> Fläche nur.**

Gemessen als tote Fläche: **134k** vorher, **34k** nachher — und dieselbe
Zusammenlegung **ohne** die volle Breite ergäbe **193k**, also mehr als vorher.
Deshalb ist die Breite hier das Entscheidende und nicht die Gruppierung.

**Bild:** der ganze Bereich bei 1440 px, beide Themes.

---

## 6. Befund 13 — der Griff zum Formular

Der Bereich „Job anlegen" ist der dritte von drei; dazwischen liegt die
Jobliste mit bis zu zehn Kärtchen.

**Bei 390 px auf `/subscriptions/$ABO/cron`:**

1. In der Kopfzeile des Bereichs **„Jobs"** steht ein Knopf **„Job anlegen"**.
2. Drücken.

**Erwartet:** Die Seite springt zum Formular, und das Formular ist **leer** —
wer den Griff drückt, meint anlegen und nicht ändern. Stand vorher ein Job im
Formular („Ändern"), ist er jetzt weg.

**Bild:** vorher und nachher, 390 px.

---

## 7. Befund 18 — das Ziel im Baum holt sich ins Bild

**Bei 390 px auf `/subscriptions/$ABO/files`:**

1. Zwei Einträge ankreuzen — die Auswahlleiste erscheint.
2. **„Verschieben"** drücken.

**Erwartet:** Der Baum, in dem das Ziel gewählt wird, ist danach **ganz** im
Bild. Prüfbar:

```js
(() => {
  const b = document.querySelector('.aside')
  if (b === null) return 'nicht gefunden'
  const r = b.getBoundingClientRect()
  return { oben: Math.round(r.top), unten: Math.round(r.bottom), fenster: window.innerHeight }
})()
```

| | erwartet |
|---|---|
| `oben` | **≥ 0** |
| `unten` | idealerweise ≤ `fenster`; ist der Baum höher als das Fenster, zählt allein `oben ≥ 0` |

**Warum `oben` und nicht „im Bild".** Der erste Anlauf hat „ist es sichtbar"
gefragt und `true` bekommen, obwohl 98 px oben abgeschnitten waren.

> **Etwas zu zentrieren, das nicht hineinpasst, schneidet oben ab.**

---

## 8. Wunsch 2 — einen Schlüssel im Panel erzeugen

**Das ist der Punkt, für den es diesen Lauf vor allem gibt:** Das Herunterladen
ist im Container grundsätzlich nicht prüfbar.

**Auf `/subscriptions/$ABO/sftp`, Bereich „Schlüssel eintragen":**

1. Über der Knopfreihe steht ein Satz: der private Teil entsteht auf Ihrem
   Gerät und wird **einmal** angeboten. **Er muss vor dem Knopf stehen, nicht
   danach.**
2. Eine Bezeichnung eintragen, z. B. `Testschlüssel rc20`.
3. **„Schlüssel erzeugen"** drücken.

**Erwartet, in dieser Reihenfolge:**

- Das Feld „Öffentlicher Schlüssel" füllt sich mit `ssh-ed25519 AAAA…`.
- Der Schlüssel erscheint in der Tabelle darüber, mit Fingerabdruck.
- **Erst danach** erscheint unten der Bereich mit dem privaten Teil, und die
  Seite holt ihn ins Bild.

4. **„Herunterladen"** drücken. Die Datei heisst `id_ed25519`.

**Auf Ihrem Rechner:**

```bash
mkdir -p ~/.ssh
mv ~/Downloads/id_ed25519 ~/.ssh/srvpanel-rc20
chmod 600 ~/.ssh/srvpanel-rc20

# Liest OpenSSH die Datei überhaupt?
ssh-keygen -y -f ~/.ssh/srvpanel-rc20

# Und ist es derselbe Schlüssel wie in der Tabelle?
ssh-keygen -l -f ~/.ssh/srvpanel-rc20
```

Der Fingerabdruck aus der letzten Zeile **muss** mit dem in der Panel-Tabelle
übereinstimmen.

**Dann die Anmeldung — der eigentliche Beleg.** Der Systembenutzer steht in der
Ausgabe von §0.2 in Klammern:

```bash
sftp -i ~/.ssh/srvpanel-rc20 -P 22 p1139@cloudsrv24.de
```

(`p1139` durch den Systembenutzer Ihres Abonnements ersetzen.)

Erwartet: eine Sitzung, `pwd` zeigt das Verzeichnis des Abonnements.

**Und die Gegenprobe, ohne die die Anmeldung nichts belegt:**

```bash
ssh-keygen -q -t ed25519 -f /tmp/fremd -N '' -C fremd
sftp -o BatchMode=yes -i /tmp/fremd -P 22 p1139@cloudsrv24.de
rm -f /tmp/fremd /tmp/fremd.pub
```

Erwartet: `Permission denied (publickey…)`. Kommt der fremde Schlüssel durch,
ist nicht der neue Schlüssel gut, sondern die Tür offen.

**Was das Panel dabei niemals tut** — bitte gegenprüfen:

- Der private Teil steht **nicht** in einer Erfolgsmeldung.
- Er lässt sich **kein zweites Mal** anzeigen; nach einem Neuladen ist er fort.
- Im Vorgangsprotokoll (`/operations`) taucht er nicht auf.

**Bilder:** der Bereich vor dem Erzeugen (mit dem Satz), und der Bereich mit dem
privaten Teil — 390 px und 1440 px, beide Themes.

**Aufräumen:** den Testschlüssel danach über „Entfernen" wieder löschen, wenn er
nicht gebraucht wird.

---

## 9. Wunsch 3 — die Suchleiste im Dateimanager

**Bei 1440 px auf `/subscriptions/$ABO/files`:**

Unter dem Seitenkopf steht eine Zeile: „Suchen in *(Pfad)*", ein Feld, das
Kästchen „auch im Inhalt" und „Suchen".

```js
JSON.stringify(bilderMessen())
```

| | erwartet |
|---|---|
| `dokument` | `0` |
| `gegenprobe.ausschlag` | `200` |

**Der Pfad muss sichtbar dastehen** — nicht als Platzhalter im Feld.

> **Eine Leiste, die immer da ist, sieht aus, als suchte sie überall.**

1. In ein tiefes Verzeichnis wechseln (`httpdocs/…`). **Der Pfad in der Leiste
   muss mitgehen.**
2. Einen Suchbegriff eintragen, **„auch im Inhalt" ankreuzen**, „Suchen".
3. Auf der Trefferseite: Das Kästchen ist dort **angekreuzt** — die Leiste hat
   es also übertragen. Das ging bis `rc.19` nicht.

**Bei 390 px, Seite frisch geladen:**

- Die Leiste ist **fort**; stattdessen steht in der Kopfleiste ein Knopf.
- Der Knopf trägt das Wort **„Suchen"** — auch dann noch, wenn die Leiste offen
  ist.
- Drücken öffnet die Leiste unmittelbar darunter.

```js
JSON.stringify({
  ...bilderMessen(),
  knopf: document.querySelector('.search-toggle')?.getAttribute('aria-expanded'),
})
```

`knopf` ist `"false"` vor dem Druck und `"true"` danach.

**Bilder:** 1440 px mit einem **langen** Pfad, 390 px zu und offen — beide
Themes.

---

## 10. Die Kopfleiste des Dateimanagers auf dem Telefon

Vier gestapelte Knöpfe waren **225 px** hoch. Jetzt stehen sie in einer Reihe,
Zeichen über Wort.

**Bei 390 px auf `/subscriptions/$ABO/files`:**

```js
JSON.stringify({
  ...bilderMessen(),
  kopf: (() => {
    const k = document.querySelector('.page-head')
    const kn = [...document.querySelectorAll('.page-head button')]
    return {
      hoehe: Math.round(k.getBoundingClientRect().height),
      zeilen: new Set(kn.map((e) => Math.round(e.getBoundingClientRect().top))).size,
      knoepfe: kn.map((e) => e.textContent.replace(/\s+/g, ' ').trim()),
    }
  })(),
})
```

| | erwartet |
|---|---|
| `kopf.zeilen` | **1** |
| `kopf.hoehe` | rund **120** (im Aufsatz gemessen; die echte Seite trägt eine Krümelzeile mehr) |
| `kopf.knoepfe` | `["Verzeichnis anlegen", "Datei anlegen", "Datei Hochladen", "Suchen"]` |

**Die Wörter sind der Punkt.** Sichtbar steht dort nur das Objekt; das Verb ist
aus dem **Bild** genommen und nicht aus dem Dokument — deshalb liest
`textContent` den ganzen Satz. Steht dort nur „Verzeichnis", ist das Verb mit
`display: none` verschwunden, und der Knopf heisst auch für die Vorlesesoftware
nur die Hälfte.

**Und mit dem Auge:** Jeder Knopf trägt ein Zeichen über seinem Wort, die
beiden Anlegen-Zeichen ein **Plus**. Alle vier haben dieselbe Strichstärke.

**Bilder:** 390 px, beide Themes.

**Zusatz, wenn Ihr Telefon schmaler als 375 px ist** (z. B. 360 px): Dort
brechen die Knöpfe in **zwei** Zeilen. Das ist gemessen und bekannt — keine
Meldung nötig, aber die Zahl interessiert.

---

## 11. Die Gegenprobe des ganzen Laufs

**Eine Runde, in der nichts auffällt, ist verdächtig.** Vier von vier bisherigen
Läufen haben zwischen sechs und zweiundzwanzig Befunde gebracht, und die
Mehrheit steckte im Prüfmittel und nicht im Prüfling.

Deshalb zum Schluss **eine Seite, von der bekannt ist, dass sie schiebt** — oder
ersatzweise dieser Eingriff auf einer beliebigen Seite:

```js
(() => {
  const d = document.createElement('div')
  d.style.cssText = 'width:3000px;height:1px'
  document.body.append(d)
  const zeile = JSON.stringify(bilderMessen())
  d.remove()
  return zeile
})()
```

`dokument` **muss** hier deutlich grösser als 0 sein und `schiebt` den
eingesetzten `div` nennen. Tut es das nicht, hat der ganze Lauf nichts gemessen.

> **Eine Messung, die nie etwas anderes als Null liefern kann, ist keine.**

---

## 12. Was dieser Lauf ausdrücklich **nicht** prüft

- **`PasswordFields.vue generate touched`** — ob der Satz „Das Passwort erfüllt
  die Richtlinie" bei 390 px im Bild steht, ist ungemessen und steht als offene
  Frage in `RevealTest::UNEXAMINED`.
- **Die Suche im ganzen Abonnement.** Gibt es nicht; §6.2 von `docs/64` vermutet,
  dass sie gefragt wird, sobald die Leiste da ist.
- **Die gestapelten Knopfreihen der anderen Seiten.** Die neue Form hat vier
  Knöpfe mit kurzen Objekten — das ist nicht überall so.
- **RSA und ECDSA erzeugen.** Entgegengenommen werden sie weiterhin, erzeugt
  wird nur Ed25519.

---

## 13. Wohin das Protokoll kommt

**Während des Laufs**, Punkt für Punkt, nach `docs/66-protokoll-serverlauf-rc20.md`
— nicht danach.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

Jeder Punkt bekommt: die gemessene Zahl, das Bild, und bei einer Abweichung den
Befund mit dem, was er über den Prüfling **oder über das Prüfmittel** sagt. Ein
Protokoll ohne seine Lücken liest sich wie eine Abnahme.
