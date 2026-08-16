# Nachprüfung: mobile Ansicht des Dateimanagers und das einheitliche Rot

**Gegen `v0.6.0-rc.8` auf `cloudsrv24`, Abonnement `p6-b.invalid` (`p1136`).**

Dieser Lauf holt nach, was aus zwei Fassungen offengeblieben ist, und prüft die
vier Befunde vom 16. August. Er ist **kein** Abnahmelauf für eine Stufe — er
schliesst `docs/55` ab und macht Punkt 8 aus `docs/54` endlich messbar.

| Befund | Fassung, in der er behoben wurde | bisher nachgeprüft |
|---|---|---|
| 21 — Haken am Gerüst | `v0.6.0-rc.7` | **erfüllt 16.08.** |
| 22 — „Aktion" statt „Griffe" | `v0.6.0-rc.7` | **erfüllt 16.08.** |
| 23 — Baum und Krümel lasen sich als eine Liste | `v0.6.0-rc.8` | **erfüllt 16.08.** |
| 24 — Linie neben der Wurzel | `v0.6.0-rc.8` | **erfüllt 16.08.** |
| 25 — die Aktionen nahmen die halbe Zeile | `v0.6.0-rc.8` | **erfüllt 16.08.** |
| 26 — dasselbe „Entfernen" rot und grau | `v0.6.0-rc.8` | **erfüllt 16.08.** |
| `docs/54` Punkt 8 — die Bilderrunde | — | **erfüllt 16.08.** |
| 27 — Kopfhaken über leerer Auswahl | `v0.6.0-rc.9` | gefunden **von** diesem Lauf |

---

## 1. Was dieser Lauf ausdrücklich nicht prüft

- **Die Befunde 1 bis 20.** Sie sind gegen rc.3 bis rc.6 nachgeprüft und stehen
  in `docs/55`.
- **SFTP und Cron** (`docs/51 §12`, Schritte 8 und 9). Die gibt es noch nicht.
- **Den Angriffsdurchgang.** Der ist Schritt 11 und braucht einen eigenen Lauf.
- **Ob eine Handlung „kritisch" ist.** Punkt 6 prüft, ob Knopf und Rückfrage
  **dasselbe** sagen — nicht, ob die Einstufung die richtige ist. Die ist eine
  Entscheidung und keine Messung.

---

## 2. Vorbereitung — einmal, vor allem anderen

### 2.1 Die Fassung nachsehen und nicht annehmen

```bash
srvpanel --version
```

**Erwartet: `0.6.0-rc.8`.** Steht dort etwas anderes, hört der Lauf hier auf.

> Eine Fassungsprüfung, die in der falschen Datei sucht, hat in `docs/47` einen
> halben Lauf gekostet.

### 2.2 Der Bestand, gegen den gemessen wird

```bash
ABO=/var/www/vhosts/p6-b.invalid
ls -la "$ABO"/httpdocs/
ls -la "$ABO"/
```

Die zweite Zeile ist die wichtigere: Sie zeigt die **sechs Verzeichnisse des
Schemas** in der Abo-Wurzel, und um die geht es in Punkt 2.

### 2.3 Eine Datei mit einem langen Namen anlegen

Punkt 7 braucht sie, und ohne sie misst die Bilderrunde den bequemen Fall.

```bash
ABO=/var/www/vhosts/p6-b.invalid
sudo -u p1136 touch "$ABO/httpdocs/ein-sehr-langer-dateiname-den-jemand-wirklich-so-angelegt-hat-und-der-nicht-umbricht.txt"
sudo -u p1136 mkdir -p "$ABO/httpdocs/unter/tiefer/noch-tiefer/ganz-unten"
ls -la "$ABO/httpdocs/ein-sehr-langer-"*
ls -ld "$ABO/httpdocs/unter/tiefer/noch-tiefer/ganz-unten"
```

Die beiden letzten Zeilen sind die Gegenprobe: Ein `sudo -u`, das auf den
falschen Benutzer geht, legt die Datei trotzdem an, und der Dateimanager zeigt
sie danach als fremd. Nachsehen, dass **`p1136`** danebensteht.

> **Hier stand `ls -la … | tail -3`, und das konnte den eigenen Gegenstand nicht
> sehen.** `ls` sortiert nach Namen; ein Eintrag, der mit `e` anfängt, steht
> nicht unter den letzten dreien. Belegt war damit das `mkdir` und nicht das
> `touch` — die Datei selbst hat erst ein Bildschirmfoto des Panels gezeigt
> (16. August, erster Lauf dieses Dokuments).
>
> **Eine Gegenprobe, die nach dem Namen sortiert und die letzten Zeilen nimmt,
> prüft die Zeile, die zufällig dort steht.**

### 2.6 Prüfdaten für die Datenbankkonsole

**Die Datenbank `p1136_test` war leer**, und ein Baum ohne Tabellen lässt sich
nicht aufklappen. Angelegt am 16. August 2026 gegen **MariaDB** — welches System
eine Datenbank hält, beantwortet:

```bash
mysql -N -e "SHOW DATABASES LIKE 'p1136_test'" 2>/dev/null
sudo -u postgres psql -lqtA -F'|' 2>/dev/null | grep '^p1136_test|'
```

```bash
mysql --default-character-set=utf8mb4 p1136_test <<'SQL'
CREATE TABLE kunden (
  id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name     VARCHAR(120) NOT NULL,
  ort      VARCHAR(120) NULL,
  notiz    TEXT NULL,
  angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX kunden_ort ON kunden (ort);

CREATE TABLE bestellungen (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kunde  INT UNSIGNED NOT NULL,
  betrag DECIMAL(10,2) NOT NULL,
  KEY bestellungen_kunde (kunde)
);

CREATE TABLE ohne_schluessel (wert VARCHAR(80));

INSERT INTO kunden (name, ort, notiz) VALUES
  ('Müller & Söhne', 'Köln',   'unberührt'),
  ('Tab\tim Wert',   NULL,     'zwei  Leerzeichen'),
  ('Zeile\nUmbruch', '',       NULL),
  ('Ökonom',         'Zürich', 'schön');

INSERT INTO bestellungen (kunde, betrag) VALUES (1, 19.90), (1, 250.00), (4, 7.5);
SQL

mysql --default-character-set=utf8mb4 -e "SELECT name FROM p1136_test.kunden"
```

**`--default-character-set=utf8mb4` ist keine Zierde.** Unter dem `LC_ALL=C` aus
`Runner::ENVIRONMENT` handelt `mysql` sonst **latin1** aus; aus `ü` wird ein
einzelnes Byte, und `json_decode()` gibt für die **ganze Zeile** `null` zurück.
Das war der teuerste Befund aus `docs/55`. Die letzte Zeile ist die Gegenprobe:
Dort muss „Müller & Söhne" lesbar stehen — und tat es.

**Für PostgreSQL gehört ein `SET ROLE "<eigentümer>"` an den Anfang.** Ohne ihn
gehören die Tabellen `postgres`; der befristete Zugang der Konsole sieht sie im
Katalog und darf sie nicht lesen. MariaDB kennt keinen Tabelleneigentümer, dort
genügt root.

**Was die Werte mitbringen — und warum keiner davon Zierde ist:**

| Wert | Wofür |
|---|---|
| `Müller & Söhne`, `Ökonom`, `Zürich` | Kodierung. *Ein Testdatensatz aus ASCII prüft keine Kodierung.* |
| `Tab\tim Wert`, `zwei  Leerzeichen` | Weissraum — `CellWhitespaceTest`: drei verschiedene Werte dürfen nicht gleich aussehen |
| `Zeile\nUmbruch` | Zeilenumbruch im Wert |
| `NULL` bei `ort` und `notiz` | `NULL` gegen leere Zeichenkette — `psql -A -t` machte daraus dasselbe |
| `''` bei `ort` | die Gegenprobe dazu |
| `ohne_schluessel` | die Tabelle ohne Primärschlüssel |
| `kunden_ort` | ein Index, damit „Indexe" nicht leer ist |

**Sie bleiben stehen.** Ein Prüfbestand, den man für jede Runde neu anlegt, ist
bei der übernächsten anders. Wer ihn los sein will:

```bash
mysql p1136_test -e "DROP TABLE bestellungen, kunden, ohne_schluessel"
```

### 2.4 Genau einmal anmelden

**Drei Anmeldungen hintereinander sperren die Adresse** (`CLAUDE.md` §6.4).
Für die Punkte 2 bis 7 gilt: einmal anmelden, dann im selben Browser bleiben und
nur Theme und Breite umschalten.

Danach in den Dateimanager des Abonnements gehen und die **Abonnement-Nummer aus
der Adresse merken** — sie steht als `<ID>` in den Adressen unten:

```
https://<panel>/subscriptions/<ID>/files
```

### 2.5 Die Messung vorbereiten — und ihre Gegenprobe

Die Zahlen brauchen die Entwicklerkonsole (Firefox/Chrome: F12, dann
Responsive Design Mode auf 390px; Safari: Web Inspector).

**Einmal je Sitzung** in die Konsole, danach genügt `mess()` je Ansicht:

```js
window.mess = function () {
  // Der absichtliche Überläufer: Hier MUSS eine Zahl herauskommen.
  const probe = document.createElement('div')
  probe.style.cssText = 'width:' + (window.innerWidth + 400) + 'px;height:1px'
  document.body.appendChild(probe)
  const gegen = document.documentElement.scrollWidth - document.documentElement.clientWidth
  probe.remove()

  console.log(
    'breite=' + window.innerWidth,
    '| theme=' + document.documentElement.getAttribute('data-theme'),
    '| gegenprobe=' + gegen,
    '| ÜBERLAUF=' + (document.documentElement.scrollWidth - document.documentElement.clientWidth),
  )
}
```

`window.mess` und nicht `const mess`: Ein `const` lässt sich im selben
Konsolen-Kontext nicht noch einmal anlegen, und der überlebt jede
Inertia-Navigation.

**Erwartet: `gegenprobe=400`, `ÜBERLAUF=0`.** Steht die Gegenprobe auf 0, misst
das Skript nichts.

> **Eine Messung, die nie etwas anderes als Null liefern kann, ist keine.**

**Die Zeile trägt Breite und Theme mit.** Ohne sie steht neben dem Bild eine
Zahl, von der niemand mehr weiss, unter welchen Bedingungen sie entstanden ist —
und zwei Zahlen für dieselbe Ansicht bei verschiedenen Breiten sehen im
Protokoll gleich aus.

### Das Theme umschalten

```js
srvpanelTheme('dark')    // oder 'light', oder 'system'
```

**Das ist der Umschalter des Panels selbst** (`app.blade.php`), nicht ein
`data-theme` von Hand: Er setzt beide Attribute so, wie es die Einstellungsseite
tut. Er speichert nichts — für eine Bilderrunde ist das richtig, denn er spart
den Weg über den Server und damit eine Navigation.

**Nach einem vollständigen Neuladen sind `mess` und die Themewahl fort.** Eine
Inertia-Navigation lädt den Rahmen nicht neu; beides überlebt sie. `F5` nicht.

---

## 3. Punkt 1 — Befund 21: kein Haken am Gerüst

**Wo:** Dateimanager, **Abo-Wurzel** (`/subscriptions/<ID>/files`, ohne `path`).

### Schritte

1. In die Abo-Wurzel gehen. Sichtbar sind die sechs Verzeichnisse des Schemas
   (`httpdocs`, `conf`, `logs`, `tmp`, `.ssh`, und was `ls -la` in §2.2 sonst
   zeigt).
2. **Je Zeile ansehen:** Steht links ein Ankreuzfeld?
3. Den Haken **in der Kopfzeile** anklicken („Alle auswählen").
4. Die Auswahlleiste lesen, die daraufhin erscheint — oder eben nicht.

### Erfüllt, wenn

- **Keines** der sechs Verzeichnisse trägt ein Ankreuzfeld.
- In der Spalte „Aktion" steht bei ihnen **„gehört zum Aufbau"** und kein Strich.
- „Alle auswählen" wählt in der Abo-Wurzel **nichts** aus: Es erscheint keine
  Auswahlleiste, und es steht nirgends „6 Einträge ausgewählt".

### Woran es scheitern kann

Liegt in der Abo-Wurzel ausser dem Schema noch etwas — eine selbst angelegte
Datei —, dann **muss** die einen Haken haben. Sind alle Haken weg, ist das der
umgekehrte Fehler, und er ist zu melden.

### Gefahren am 16. August 2026 — erfüllt, mit einem Befund

| Teil | Ergebnis |
|---|---|
| Kein Ankreuzfeld an den sechs Verzeichnissen | **erfüllt** |
| „gehört zum Aufbau" statt eines Strichs | **erfüllt**, sechsmal |
| „Alle auswählen" wählt nichts aus | **erfüllt** — keine Auswahlleiste erschien |
| Überlauf | **0 px**, Gegenprobe 400 |

## Befund 27 — der Kopfhaken blieb angehakt über einer leeren Auswahl

Der Haken **in der Kopfzeile** stand in der Abo-Wurzel noch da, obwohl es dort
nichts auszuwählen gibt. Das allein wäre ein Schönheitsfehler — er wählte ja
nichts aus. Gemessen im Browser, unmittelbar nach dem Klick:

```
Kopfhaken angehakt: true | Auswahlleiste da: false
```

**Er blieb angehakt stehen.** Der Setzer schreibt `selected = []`, der Leser
rechnet daraus wieder `false` — und weil der Wert sich damit nicht **ändert**,
schreibt Vue das DOM nicht zurück. Der Klick des Betrachters bleibt stehen, und
über sechs nicht ausgewählten Zeilen steht sichtbar „alles ausgewählt".

> **Ein Kästchen, das der Betrachter setzt und das Modell nicht, zeigt danach
> den Klick und nicht den Zustand.**

Das ist dieselbe Halbheit wie Befund 21, eine Zeile höher: Die Zeilen-Haken
waren fort, der Kopf-Haken nicht. Der Knopf „Alle auswählen" in der
Auswahlleiste hatte seine Bedingung (`selected.length < selectable.length`) und
war korrekt verschwunden — das Kästchen daneben hatte sie nie.

**Und die Vorhersage war falsch.** Ich hatte geschrieben, wahrscheinlich springe
der Haken zurück, und den ernsteren Fall nur als Möglichkeit genannt. Er war der
eingetretene. Erschliessen liess sich das nicht — Vue schreibt ein DOM-Attribut
nur, wenn der neue Wert vom alten abweicht, und das hängt an einer Rechnung, die
man nachvollziehen kann, aber nicht raten sollte.

> **Ob eine Anzeige nach einem Klick stimmt, weiss man erst nach dem Klick.**

### Nachgeprüft gegen `v0.6.0-rc.9`

In der Abo-Wurzel steht **kein Haken mehr** — weder in der Kopfzeile noch an
einer der sechs Zeilen; sechsmal „gehört zum Aufbau". Die Spalte selbst ist
geblieben, Kopf und Rumpf haben weiter gleich viele.

**Offen: die Gegenprobe in `httpdocs`.** Dass irgendwo ein Haken fehlt, ist die
halbe Auskunft; die andere ist, dass er dort steht, wo er hingehört, und tut,
was er soll.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

Behoben: Das Kästchen trägt `v-if="selectable.length > 0"`. Die **Zelle** bleibt
— jede Zeile trägt ihr `<td data-column="Auswahl">` auch leer, und fünf Spalten
im Kopf über sechs im Rumpf verschieben die ganze Tabelle. Der Wächter ist
`SchemeHandleTest::test_the_header_tick_is_gone_when_nothing_can_be_ticked` und
prüft **beides**; beide Brüche sind gefahren.

---

## 4. Punkt 2 — Befund 22: die Spalte heisst „Aktion"

**Wo:** Dateimanager, `httpdocs`.

### Schritte

1. Bei **1440 px** in die Liste sehen: Der Spaltenkopf ganz rechts.
2. Auf **390 px** umschalten. Die Tabelle wird zu Kärtchen; jede Zelle trägt
   ihre Beschriftung links.
3. Dieselbe Zelle im Kärtchen lesen.

### Erfüllt, wenn

An **beiden** Stellen **„AKTION"** steht. Nirgends „Griffe".

> Wer nur eine der beiden ändert, bekommt zwei Wörter auf einer Seite — je
> nachdem, wie breit sie gerade ist.

### Gefahren am 16. August 2026 — erfüllt

| Breite | Ergebnis |
|---|---|
| 1440 px, Spaltenkopf | **AKTION** |
| 390 px, Beschriftung im Kärtchen | **AKTION** |
| Überlauf bei 390 px | **0 px**, Gegenprobe 400 |

Nirgends „Griffe". **Befund 22 ist damit bestätigt.**

---

## 5. Punkt 3 — Befund 23: der Baum ist abgesetzt

**Wo:** Dateimanager, bei **390 px**. Dieser Punkt existiert nur schmal.

### Schritte

1. Auf 390 px stellen und in die **Abo-Wurzel** gehen — dort steht „Abo-Wurzel"
   im Baum *und* als einziger Krümel, also der Fall, der die Verwechslung
   ausgelöst hat.
2. Von oben nach unten lesen.
3. In `httpdocs/unter/tiefer` gehen und dasselbe noch einmal ansehen.
4. Dann eine Datei auswählen und **Kopieren** drücken — der Baum wird zum
   Zielwähler.

### Erfüllt, wenn

- Über dem Baum steht sichtbar **„VERZEICHNISSE"**.
- Der Baum sitzt in einem **Rahmen** mit abgerundeten Ecken.
- Die Krümelspur steht **ausserhalb** dieses Rahmens, mit Abstand darunter.
- Beim Zielwählen (Schritt 4) steht dort **„ZIEL WÄHLEN"** statt
  „Verzeichnisse".

### Und bei 1440 px gegenprüfen

Auf 1440 px umschalten: Der Baum steht **links neben** der Liste, mit einer
senkrechten Trennlinie rechts von sich — und **ohne** Rahmen. Der Rahmen ist
absichtlich nur schmal da. Steht er auch breit, ist das ein Befund.

### Gefahren am 16. August 2026 — erfüllt

| Fall | Ergebnis |
|---|---|
| `httpdocs` bei 390 px | „VERZEICHNISSE", Rahmen, Krümel `Abo-Wurzel / httpdocs` **ausserhalb** |
| **Abo-Wurzel** bei 390 px — der Fall, der den Befund ausgelöst hat | „VERZEICHNISSE", Rahmen mit „Abo-Wurzel" darin, Rahmen zu, dann „Abo-Wurzel" als Krümel — **zwei getrennte Blöcke** |
| Zielwählen (Kopieren) | Überschrift wechselt auf **„ZIEL WÄHLEN"** |
| 1440 px | Baum links, Trennlinie rechts, **kein** Rahmen |
| Überlauf | **0 px** in jedem Fall, Gegenprobe 400 |

**Befund 23 ist damit bestätigt** — auch in dem Fall, für den es ihn gibt: Wo
zweimal „Abo-Wurzel" untereinandersteht, trennt jetzt eine Überschrift und ein
Rahmen die beiden Bedeutungen.

### Eine Konsolenzeile, die nicht dazugehört

In der Abo-Wurzel stand einmalig:

```
Uncaught (in promise) Error: A listener indicated an asynchronous response by
returning true, but the message channel closed before a response was received
```

**Das ist keine Zeile dieses Panels.** Der Wortlaut stammt aus Chromes
Erweiterungs-Schnittstelle (`chrome.runtime.sendMessage`); nachgesehen und nicht
angenommen: In `resources/js` steht **null**mal `sendMessage`, `chrome.runtime`,
`onMessage` oder `addListener`. Sie kommt aus einer Browsererweiterung, die ein
Skript in die Seite hängt.

Notiert, weil eine Fehlerzeile in einem Abnahmelauf nicht unkommentiert bleiben
darf — auch dann nicht, wenn sie einem anderen gehört.

> **Eine Meldung, die auf der eigenen Seite erscheint, ist nicht deshalb die
> eigene.**

---

## 6. Punkt 4 — Befund 24: die Linien des Baums

**Wo:** Dateimanager, Baum mit einem aufgeklappten Pfad. Gut sichtbar bei
390 px, prüfbar bei jeder Breite.

### Schritte

1. Den Baum so aufklappen, dass mindestens drei Ebenen offen sind —
   `httpdocs` → `unter` → `tiefer`.
2. Mit dem Auge ansehen:
   - Läuft **neben „Abo-Wurzel"** eine senkrechte Linie?
   - Berührt jeder Eintrag die Linie seiner Ebene mit einem kurzen
     **waagerechten** Strich?
   - Endet die Linie beim **letzten** Eintrag einer Ebene auf dessen Höhe, oder
     hängt sie darunter weiter?

### Erfüllt, wenn

- **Neben „Abo-Wurzel" steht keine Linie.** Sie ist die Wurzel; es gibt nichts
  zu verbinden.
- Jeder Eintrag darunter hat einen waagerechten Anschluss zur Linie seiner
  Ebene.
- Unter dem letzten Eintrag einer Ebene läuft die Linie **nicht** weiter.

### Die Zahl dazu

In der Konsole, mit offenem Baum:

```js
document.querySelectorAll('.branch .branch > li').forEach((li) => {
  const zweig = li.querySelector(':scope > .twig')
  const strich = getComputedStyle(li, '::before')
  const letzter = li === li.parentElement.lastElementChild
  console.log(
    zweig.textContent.trim().slice(0, 20).padEnd(22),
    'letzter=' + letzter,
    'senkrecht=' + strich.height,
    'waagerecht=' + getComputedStyle(li, '::after').width,
  )
})
// Und der Beweis, dass die alte Regel weg ist:
console.log('Wurzelast:', getComputedStyle(document.querySelector('.file-tree > .branch')).borderLeftWidth)
```

**Erwartet:**

- Jede Zeile trägt `waagerecht=11px`.
- Bei `letzter=false` ist `senkrecht` so hoch wie der Eintrag samt Kindern.
- Bei `letzter=true` steht `senkrecht=12px` — die halbe Zeilenhöhe, also genau
  bis zum eigenen Anschluss.
- **`Wurzelast: 0px`.** Steht dort `1px`, ist die Regel der Datenbankkonsole
  wieder eingerissen, und dann bitte auch die Konsole selbst ansehen.

### Was ausserdem noch schnell geht

Zur Sicherheit die **Datenbankkonsole** öffnen (`/databases/<ID>` → Konsole) und
ihren Baum ansehen. Ihre Einrückung darf sich **nicht** verändert haben — sie
war der eigentliche Eigentümer der alten Regel.

### Gefahren am 16. August 2026 — erfüllt

Gemessen bei 390 px, Baum bis in die vierte Ebene aufgeklappt:

| Zweig | letzter | senkrecht | waagerecht |
|---|---|---|---|
| `.ssh` | nein | 24px | 11px |
| `conf` | nein | 24px | 11px |
| `httpdocs` | nein | **142,5px** | 11px |
| `p6-fremd` | nein | 24px | 11px |
| `p6-gid` | nein | 24px | 11px |
| `test2` | nein | 24px | 11px |
| `unter` | **ja** | **12px** | 11px |
| `tiefer` | **ja** | **12px** | 11px |
| `logs` | nein | 23,5px | 11px |
| `mail` | nein | 24px | 11px |
| `tmp` | **ja** | **12px** | 11px |

```
Wurzelast: 0px
```

Alle drei Zusagen halten: `waagerecht=11px` an **jedem** Eintrag, `senkrecht=12px`
an **jedem** letzten, und der Wurzelast trägt keine Linie mehr.

**Die 12px an `unter` sehen falsch aus und sind richtig.** `unter` ist
aufgeklappt und trägt `tiefer` unter sich; sein `li` ist also hoch. Die Linie,
die hier gemessen wird, gehört aber zur **Ebene der Kinder von `httpdocs`**, und
auf dieser Ebene folgt nach `unter` nichts mehr. Sie endet deshalb an seinem
eigenen Anschluss; der Unterbaum bekommt eine Ebene tiefer seine eigene. Genau
das war vorher kaputt — dort lief sie 12px darüber hinaus ins Leere.

> **Die Höhe eines Eintrags und die Länge seiner Linie sind zwei verschiedene
> Sachen, sobald er Kinder hat.**

Die 142,5px an `httpdocs` sind die Gegenprobe dazu: **nicht** letzter, also läuft
die Linie über den ganzen Unterbaum hinweg bis zu `logs`.

### Die andere Richtung — die Datenbankkonsole

`Wurzelast: 0px` belegt, dass die Regel der Konsole den Dateibaum nicht mehr
trifft. Es belegt **nicht**, dass sie ihren eigenen noch trifft. Gemessen an
`p1136_test` (MariaDB), Tabelle `kunden` aufgeklappt:

```
gruppe: einzug=18px | linie=1px solid | aussen=0px
```

| Wert | Bedeutung |
|---|---|
| `einzug=18px` | `.tree ul` greift noch — die Ebene ist eingerückt |
| `linie=1px solid` | und zeichnet ihre Linie |
| `aussen=0px` | der äussere `<ul class="tree">` trägt keine; er ist die Wurzel |

**Befund 24 ist damit in beide Richtungen belegt.** Die Umbenennung hat dem
Dateibaum eine falsche Linie genommen und der Konsole keine richtige.

> **Eine Regel abzuziehen ist erst dann geprüft, wenn beide Bausteine gemessen
> sind — der, dem sie nicht mehr gilt, und der, dem sie noch gilt.**

**Und der Bestand dafür musste erst entstehen: Die Datenbank war leer.** Der
Baum hatte nichts zum Aufklappen, also gab es nichts zu messen. Die
Prüfdaten stehen in §2.6.

---

## 7. Punkt 5 — Befund 25: die Aktionen klappen zu

**Wo:** Dateimanager, `httpdocs`, bei **390 px**.

### Schritte

1. Auf 390 px stellen.
2. Ein Kärtchen einer **gewöhnlichen Datei** ansehen (nicht Schema, nicht
   `p6-fremd`) — etwa `index.html`.
3. In der Zeile „AKTION": Was steht dort?
4. Den Knopf drücken.
5. Die Knöpfe lesen, die erscheinen — **jeden Text ganz**.
6. Denselben Knopf wieder drücken.
7. Eine **zweite** Zeile aufklappen, während die erste offen ist.
8. In ein anderes Verzeichnis navigieren.
9. Auf **1440 px** umschalten.

### Erfüllt, wenn

| Schritt | Erwartung |
|---|---|
| 3 | Genau **ein** Knopf: „Aktionen" |
| 4 | Darunter erscheinen Umbenennen / Rechte / (Entpacken) / Entfernen |
| 5 | **Jeder Text ist vollständig lesbar** — kein „Umbenen…", kein „Entferne…" |
| 4 | Die Beschriftung wechselt auf „Aktionen zuklappen" |
| 6 | Die Knöpfe verschwinden wieder |
| 7 | Die erste Zeile klappt **zu** — höchstens eine ist offen |
| 8 | Nach der Navigation ist **keine** Zeile aufgeklappt |
| 9 | **Kein** „Aktionen"-Knopf; alle Knöpfe stehen nebeneinander wie immer |

**Schritt 5 ist der wichtigste.** Genau dort war mein erster Wurf kaputt, und
zwar bei einem Überlauf von **0 px** — die Zelle schnitt ab, statt die Seite zu
schieben. Keine Zahl hat das gemeldet.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

### Die Zahl dazu

Bei 390 px, an derselben Zeile, einmal zugeklappt und einmal aufgeklappt:

```js
const zelle = document.querySelector('td[data-column="Aktion"]')
const zeile = zelle.closest('tr')
console.log(
  'Kärtchen=' + Math.round(zeile.getBoundingClientRect().height),
  'Zelle=' + Math.round(zelle.getBoundingClientRect().height),
  'Überlauf=' + (document.documentElement.scrollWidth - document.documentElement.clientWidth),
)
```

**Erwartet:** Die Zelle ist **zugeklappt rund ein Drittel** so hoch wie
aufgeklappt, der Überlauf in beiden Fällen 0. Im Container gemessen: 54 px gegen
214 px, Kärtchen 236 px gegen 396 px. Die absoluten Zahlen werden auf der echten
Seite abweichen — das Verhältnis nicht.

**Und die Gegenprobe aus §2.5 gehört daneben**, sonst ist die 0 keine Messung.

### Gefahren am 16. August 2026 — erfüllt

| Schritt | Ergebnis |
|---|---|
| 3 — zugeklappt genau ein Knopf | **„Aktionen"** |
| 4 — aufgeklappt erscheinen die Griffe | Umbenennen · Rechte · Entfernen |
| **5 — jeder Text vollständig lesbar** | **erfüllt** — „Aktionen zuklappen", „Umbenennen", „Rechte", „Entfernen", nichts abgeschnitten |
| 4b — Beschriftung wechselt | „Aktionen zuklappen" |
| 6 — wieder zuklappen | erfüllt |
| 7 — zweite Zeile öffnen | die erste klappt zu; **höchstens eine offen** |
| 8 — nach der Navigation | **keine** Zeile offen |
| 9 — bei 1440 px | **kein** Umschalter, Knöpfe nebeneinander |
| Zahl bei 1440 px | `Kärtchen=40 Zelle=40 Überlauf=0` — **unverändert** |

**Schritt 5 ist der, um den es ging**, und er hält. Genau dort war der erste Wurf
kaputt: Die aufgeklappte Reihe stand neben dem Umschalter und wurde am rechten
Rand abgeschnitten, bei einem Überlauf von 0 px. Auf den Bildern steht jeder
Knopf über die volle Kärtchenbreite und trägt seinen ganzen Text.

Und Schritt 9 belegt die andere Richtung: Am Arbeitsplatz hat sich **nichts**
geändert — 40 px Zeilenhöhe wie vor dem Umbau, kein zusätzlicher Klick.

### Und die Zahlen bei 390 px

| Zustand | Kärtchen | Zelle „Aktion" |
|---|---|---|
| vorher (Stand rc.6) | 344 px | 162 px |
| **zugeklappt** | **236 px** | **54 px** |
| aufgeklappt | 396 px | 214 px |
| 1440 px, beide Zustände | 40 px | 40 px |

Das Kärtchen schrumpft um **31 %**, die Aktionszelle um **67 %**. Die Zelle nahm
vorher 47 % der Zeilenhöhe; jetzt sind es 23 %.

**Bemerkenswert ist etwas anderes: Die Zahlen stimmen mit der Vorhersage aus dem
Container aufs Pixel überein.** Dort gemessen wurden 236/54 und 396/214 — an
einer Wegwerf-HTML-Datei mit dem gebauten Stylesheet aus `public/build`, ohne
Datenbank, ohne PHP, ohne Server.

> **Ein Aufsatz, der das echte Markup und das gebaute Stylesheet benutzt, misst
> die echte Seite — und nicht etwas Ähnliches.**

Das ist der Grund, warum die Vorher-Zeile in dieser Tabelle glaubwürdig ist: Sie
stammt aus demselben Aufsatz, gegen den Stand vor dem Umbau, und ist auf dem
Server nie gemessen worden.

**Und die Kehrseite steht daneben, denn beide gehören zusammen:** Derselbe
Aufsatz meldete für den kaputten aufgeklappten Zustand einen Überlauf von
**0 px**. Die Zahl war exakt und blind; gefunden hat den Fehler das Bild, das
dieselbe Datei gerendert hat.

> **Dieselbe Messung kann aufs Pixel stimmen und trotzdem nichts über die
> Ansicht sagen.**

---

## 8. Punkt 6 — Befund 26: Rot heisst überall dasselbe

Dieser Punkt geht über den Dateimanager hinaus. Er prüft **sechs Stellen** in
vier Seiten.

### 8.1 Der Dateimanager — der eigentliche Fund

**Wo:** `httpdocs`, bei **390 px** (oder 1440 px, gilt für beide).

1. Eine gewöhnliche Datei anhaken.
2. Die **Auswahlleiste** oben ansehen: „Entfernen" ist rot umrandet.
3. Dieselbe Datei in ihrer Zeile aufklappen (Punkt 5, Schritt 4).
4. Das „Entfernen" **in der Zeile** ansehen.

**Erfüllt, wenn beide rot sind.** Vorher war das obere rot und das untere grau.

5. Auf das rote „Entfernen" der Zeile drücken.
6. Die Rückfrage oben lesen: Der zustimmende Knopf ist **ebenfalls rot**.
7. **Abbrechen.**

### 8.2 Die vier, die nicht mehr rot fragen

Jedes Mal: Knopf drücken, die Rückfrage ansehen, **abbrechen**.

| Seite | Knopf | Erwartung an die Rückfrage |
|---|---|---|
| Abonnement (`/subscriptions/<ID>`) | **Sperren** | Zustimmender Knopf ist **blau/Akzent**, nicht rot |
| Kunde (`/customers/<ID>`) | **Sperren** | dasselbe |
| Datenbank (`/databases/<ID>`) | **Zugriff entziehen** an einem Zugang | dasselbe |
| Datenbank | **Zurücknehmen** an einem Netz eines Zugangs | dasselbe |

**Der Grund:** Alle vier sind umkehrbar. Rot heisst in diesem Panel „lässt sich
nicht zurücknehmen" und ist keine Betonung.

#### Gefahren am 16. August 2026 — erfüllt

| Seite | Knopf | zustimmender Knopf |
|---|---|---|
| Abonnement `p6-b.invalid` | Sperren | **blau** |
| Kunde `K10004` | Sperren | **blau** |
| Datenbank `p1136_test` | Entziehen | **blau** |
| Datenbank `x51eb6f818cf493af_test` | Zurücknehmen | **blau** |

Und die Sätze der Rückfragen tragen die Begründung selbst: „…die Daten bleiben",
„Der Zugang bleibt bestehen", „Eine Anwendung, die von dort verbindet, kommt
danach nicht mehr herein." Kein einziger sagt „ist danach fort".

> **Wenn der Satz der Rückfrage sagt, dass etwas bleibt, ist der rote Knopf
> darüber eine Behauptung, die der Satz gleich widerruft.**

### 8.3 Der eine, der rot geworden ist

| Seite | Knopf | Erwartung |
|---|---|---|
| Datenbank, Abschnitt Sicherungen | **Zurückspielen** | Der Knopf ist jetzt **rot** umrandet, und die Rückfrage ebenso |

**Nicht bestätigen.** Ein Zurückspielen überschreibt den Bestand — genau
deshalb ist er rot. Ansehen und abbrechen.

#### Gefahren am 16. August 2026 — erfüllt

Der Knopf in der Sicherungszeile ist **rot** umrandet, und der zustimmende Knopf
der Rückfrage ebenso. Beide sagen dasselbe; vorher war der eine grau und der
andere rot.

Und die Zeile daneben ist die Gegenprobe im Kleinen: **„Herunterladen" grau,
„Zurückspielen" rot, „Entfernen" rot** — drei Knöpfe nebeneinander, zwei Ränge,
und die Einteilung stimmt für jeden einzelnen. Herunterladen nimmt nichts weg;
die beiden anderen tun es.

Der Satz der Rückfrage trägt die Begründung wieder selbst: „Der aktuelle Stand
von `p1136_test` wird dabei überschrieben." Das ist die Sorte Satz, nach der Rot
richtig ist — im Gegensatz zu den vier aus §6.2, die alle mit „bleibt" enden.

### 8.4 Was gleich geblieben sein muss

Zur Gegenprobe, dass nicht versehentlich überall Rot verschwunden ist:

| Seite | Knopf | Erwartung |
|---|---|---|
| Abonnement | **Zurückbauen** | rot, mit dem Abtippen des Namens |
| Datenbank | **Datenbank entfernen** | rot, mit dem Abtippen des Namens |
| Kunde | **Zurückziehen** | rot |
| Plan | **Löschen** | rot |

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Ohne §8.4 sagt §8.2 nur, dass irgendwo Farbe fehlt.

#### Gefahren am 16. August 2026 — zwei von vier

| Seite | Knopf | Ergebnis |
|---|---|---|
| Abonnement | Zurückbauen | **rot** — nebenbei belegt, weil er neben „Sperren" im Kopf steht |
| Kunde | Zurückziehen | **rot** — dito |
| Datenbank | Datenbank entfernen | **offen** |
| Plan | Löschen | **offen** |

Die beiden ersten sind auf denselben Bildern zu sehen wie §6.2: Im Kopf der
Abonnementseite steht „Sperren" grau **neben** „Zurückbauen" rot, und auf der
Kundenseite „Sperren" grau neben „Zurückziehen" rot. Das ist die Gegenprobe in
ihrer stärksten Form — zwei Ränge nebeneinander, im selben Bild, in derselben
Leiste.

**Nachgereicht am selben Tag, damit vier von vier:**

| Seite | Knopf | Ergebnis |
|---|---|---|
| Datenbank | Datenbank entfernen | **rot** — blass, weil `disabled`, solange der Name nicht abgetippt ist |
| Plan | Löschen | **rot**, neben „Speichern" blau und „Abbrechen" grau |

**Das Planformular ist die beste Gegenprobe des ganzen Punktes:** Dort stehen
**alle drei Ränge in einer Reihe** — `.button.primary` (Speichern),
`.button` (Abbrechen), `.button.danger` (Löschen). Wer die Einteilung falsch
gemacht hätte, sähe es hier auf einen Blick.

### Punkt 6 vollständig — erfüllt

Alle sechs Fälle aus Befund 26 sind nachgeprüft, dazu die vier Gegenproben:

| | Erwartung | Ergebnis |
|---|---|---|
| Dateiliste, „Entfernen" je Zeile | rot | **rot**, bei 390 und 1440 px |
| Datenbank, „Zurückspielen" | rot | **rot**, Knopf und Rückfrage |
| Abonnement, „Sperren" | nicht rot | **blau** |
| Kunde, „Sperren" | nicht rot | **blau** |
| Datenbank, „Zugriff entziehen" | nicht rot | **blau** |
| Datenbank, „Zurücknehmen" | nicht rot | **blau** |
| Abonnement, „Zurückbauen" | rot geblieben | **rot** |
| Kunde, „Zurückziehen" | rot geblieben | **rot** |
| Datenbank, „Datenbank entfernen" | rot geblieben | **rot** |
| Plan, „Löschen" | rot geblieben | **rot** |

**Befund 26 ist damit bestätigt.** Rot heisst in diesem Panel überall dasselbe,
und es steht überall dort, wo es hingehört — nicht mehr und nicht weniger.

---

## 9. Punkt 7 — die Bilderrunde (`docs/54` Punkt 8)

**Die Zahlen fehlen seit dem 15. August.** Ohne sie ist Punkt 8 nicht gemessen,
und `docs/54` bleibt unvollständig.

**Einmal anmelden** (§2.4), dann nur Theme und Breite umschalten. Bei **390 px**
und **1440 px**, in **beiden Themes** — also vier Durchgänge je Ansicht.

Je Aufnahme:

1. Screenshot machen.
2. Die Zahl aus §2.5 daneben notieren (**mit** Gegenprobe im ersten Durchgang je
   Breite).

### Angesehen werden

| # | Ansicht | Wie hinkommen |
|---|---|---|
| 1 | Liste mit dem **langen Dateinamen** aus §2.3 | `httpdocs` |
| 2 | **Auswahlleiste** mit allen sechs Knöpfen | zwei Dateien anhaken |
| 3 | **Rechte-Editor** mit seinen neun Ankreuzfeldern | „Rechte" an einer Datei |
| 4 | **Baum** mit dem tiefen Pfad aus §2.3 | `httpdocs/unter/tiefer/noch-tiefer` |
| 5 | **Editor** mit einer echten Datei | `index.html` anklicken |
| 6 | **Kärtchen mit aufgeklappten Aktionen** (neu) | Punkt 5, Schritt 4 |

### Erfüllt, wenn

- Der Überlauf ist in **jeder** der Ansichten **0 px**.
- Die Gegenprobe steht bei rund 400 — sonst zählt die 0 nicht.
- Auf keinem Bild ist Text abgeschnitten oder überlappt.

### Gefahren am 16. August 2026

| # | Ansicht | 390 dunkel | 390 hell | 1440 dunkel | 1440 hell |
|---|---|---|---|---|---|
| 1 | Liste mit langem Dateinamen | **0** | **0** | **0** | **0** |
| 2 | Auswahlleiste | **0** | **0** | **0** | **0** |
| 3 | Rechte-Editor | **0** | **0** | **0** | **0** |
| 3b | Rechte-Editor, langer Name | **0** | **0** | — | — |
| 4 | Baum, tiefer Pfad | **0** | **0** | **0** | **0** |
| 5 | Editor | **0** | **0** | **0** | **0** |
| 6 | Kärtchen, Aktionen offen | **0** | **0** | — | — |

Gegenprobe in jeder Zelle: **400**. **Vierundzwanzig Messungen, jede auf 0.**

**Punkt 7 ist damit erfüllt** — und `docs/54` Punkt 8 nachgeholt, der seit dem
15. August offenstand.

**Zu #6:** Die aufgeklappte Zeile trägt „Aktionen zuklappen", „Umbenennen",
„Rechte" und „Entfernen" über die volle Kärtchenbreite, jeden Text vollständig,
in beiden Themes. Die Zeile darunter steht auf „Aktionen" — höchstens eine ist
offen. Das ist die Ansicht, in der der erste Wurf kaputt war: Die Reihe stand
neben dem Umschalter und wurde abgeschnitten, **bei einem Überlauf von 0 px**.
Sie hat jetzt ihr Bild und ihre Zahl im Protokoll und nicht nur in einer
Erinnerung.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.** Deshalb steht neben jeder dieser Nullen ein Bild.

**Zu #1:** Der 76 Zeichen lange Dateiname bricht **innerhalb** des Kärtchens auf
drei Zeilen und schiebt nichts. Das ist `td.cell-name` und nicht `.ident` — die
Unterscheidung stammt aus `docs/46 §20.13`, wo `td .ident { white-space: nowrap }`
eine Tabelle auf 5710px trieb, ohne dass die Überlaufmessung eine Zahl lieferte.

> **Ein Format, das für Bezeichner reicht, reicht nicht für Werte.**

Und die Ankreuzfelder sind im dunklen Theme dunkel — `color-scheme` hält, was
`docs/55` Befund 20 behoben hat.

**Bei 1440 px verhält sich dieselbe Zelle anders, und das ist Absicht.** Dort
ist die Tabelle eine Tabelle (`.scrolls > table { width: max-content }`), der
lange Name steht auf **einer** Zeile, und die Tabelle wächst über den Behälter
hinaus — „Geändert" und „Aktion" stehen rechts ausserhalb und sind nur durch
Rollen **innerhalb** der Tabelle erreichbar. Der Dokumentüberlauf bleibt 0.

`.cell-name` trägt `overflow-wrap: anywhere` bei jeder Breite; es greift nur,
wenn die Zelle beschränkt ist. Unter 720 px ist sie es (`table.stacks {
width: 100% }`), darüber nicht — und das steht so in `docs/46 §20.13`: *Über
720px soll die Tabelle rollen dürfen, hier soll sie es nicht müssen.*

**Kein Befund, aber benannt:** Ein 76 Zeichen langer Name schiebt am
Arbeitsplatz die Aktionsspalte aus dem Bild. Das ist der Preis der Entscheidung
von P5c und keine Folge dieses Umbaus — vor ihm war es genauso. Ob er sich
lohnt, ist eine Frage an den Betreiber und nicht an diesen Lauf.

**Zu #2:** Bei 1440 px stehen alle sechs Knöpfe in **einer** Reihe. Bei 390 px
**bricht** die Leiste um — drei Reihen zu zwei Knöpfen — und stapelt **nicht**
auf volle Breite. Genau dafür trägt `.selection .button-row` seine Ausnahme von
der 480px-Regel: Sechs gestapelte Knöpfe wären 390 px hoch, also ein ganzer
Telefonbildschirm, bevor die Liste anfängt.

> **Der Grund zu stapeln gilt für eine Reihe, die nicht umbrechen kann. Eine mit
> sechs Knöpfen kann.**

„Entfernen" ist in beiden Breiten rot und die einzige rote der sechs — dieselbe
Einteilung wie in Punkt 6, nur an einem anderen Baustein.

**Und die Höhe der Leiste ist nachgemessen: `.selection` misst bei 390 px
207 px** — der ganze Block mitsamt Innenabstand und der Zeile „1 Eintrag
ausgewählt". Die 138 px, die `app.css` nennt, meinen die **Knopfreihe** darin;
die beiden Zahlen sind also nicht dieselbe Messung. Was sie belegt, ist der
Unterschied, um den es geht: Gestapelt wäre allein die Reihe 390 px hoch, der
Block also gut 460 px. 207 px ist der umgebrochene Fall.

**Der Aufruf dazu ist beim ersten Versuch abgestürzt**, und das war mein Fehler:
`document.querySelector('.selection')` gibt `null`, solange nichts ausgewählt
ist — die Leiste existiert dann gar nicht. Ich hatte das Anhaken nicht
dazugeschrieben.

> **Eine Messung an einem Baustein, den es erst unter einer Bedingung gibt,
> braucht die Bedingung im selben Satz.**

**Zu #3:** Die neun Ankreuzfelder sind im dunklen Theme dunkel — an der Stelle
mit den meisten davon auf einer Seite. Das ist die stärkste Gegenprobe zu
`docs/55` Befund 20.

**Zu #4:** Der Baum steht sechs Ebenen tief offen — Abo-Wurzel → `httpdocs` →
`unter` → `tiefer` → `noch-tiefer` → `ganz-unten`. Anschlüsse und Linien
stimmen auf jeder Ebene, und bei 1440 px hält die Spalte
(`minmax(200px, 280px)`) den tiefsten Namen, ohne sich aufzuziehen. Das ist die
Stelle, an der ohne `min-width: 0` an beiden Rasterkindern die Seite bei 800 px
um 242 px schob.

**Und die Krümelspur bricht.** Bei 390 px steht sie auf zwei Zeilen —
`Abo-Wurzel / httpdocs / unter / tiefer /` und darunter
`noch-tiefer / ganz-unten`. Genau dafür trägt `.crumbs .link` seine Ausnahme
(`min-width: 0` **und** `overflow-wrap: anywhere`), und der Kommentar dort sagt
den Grund: Ein Pfad aus tiefen Verzeichnissen wird länger als eine Zeile.

> **Ein `nowrap` über etwas, das wächst, ist keine Zusage über die Zeile,
> sondern eine über den Bestand.**

**Zu #5:** Der Editor zeigt dieselbe Datei in beiden Themes, und die
**Hervorhebung wechselt mit** — heller Grund mit dunklem Text im hellen Theme,
umgekehrt im dunklen. Ein mitgeliefertes CodeMirror-Theme täte das nicht; es
bliebe stehen, wie es ist. Das ist der Beleg für `docs/51 §8.1`, Auflage 2: Die
Farben kommen aus `app.css`.

**Bei 390 px rollt der Editor waagerecht**, und das ist richtig. Zeile 5 endet
sichtbar an `content="width=de` — die lange Zeile läuft im `cm-scroller`
weiter, nicht auf der Seite. Ein Editor, der Quelltext umbräche, verschöbe die
Zeilennummern gegen den Inhalt; das ist genau der Fall, in dem Rollen die
richtige Antwort ist und Umbrechen die falsche.

> **Ein Format, das für Bezeichner reicht, reicht nicht für Werte — und eines
> für Werte reicht nicht für Quelltext.**

**Der Härtefall ist nachgeholt.** Gefahren war #3 zuerst an `IMG_4398.jpeg` —
einem kurzen Namen. Der Bereichstitel trägt aber den Dateinamen („Rechte für
…"), und ein Titel, der nicht umbricht, hat in `docs/46 §20.11` die Seite um
99 px geschoben.

Mit dem 76 Zeichen langen Namen bei 390 px: **Der Titel bricht auf zwei Zeilen
und bleibt innerhalb der Karte.** Das ist die dritte Fassung derselben Ausnahme
— `overflow-wrap: anywhere` **mit** `min-width: 0`, weil ein Flexkind sonst
nicht unter seine Inhaltsbreite darf —, und
`MobileLayoutTest::test_a_section_heading_can_break` rechnet sie seitdem nach.

Gemessen in beiden Themes: `gegenprobe=400`, **`ÜBERLAUF=0`**.

Beim ersten Anlauf fehlte die Zahl — auf dem Bild stand die Konsole noch auf der
Messung aus #2. Sie ist nachgeholt, und das war richtig: Dass ein brechender
Titel keinen Überlauf erzeugt, ist ein **Schluss** und keine Messung. Dieser
Lauf unterscheidet die beiden.

Und die Höhe der Auswahlleiste ist im dunklen Theme dieselbe — 207 px. Ein
Themewechsel ändert an dieser Stelle nichts, und das ist die Auskunft, die eine
zweite Messung liefert.

### Was hier zu sehen sein wird und **kein** Fehler ist

Nichts mehr — `color-scheme` ist seit rc.4 gesetzt, die leeren Ankreuzfelder im
dunklen Theme sind behoben (`docs/55`, Befund 20). Sind sie wieder weiss, ist
das ein Rückfall und ein Befund.

---

## 9a. Der Lauf im Ganzen — gefahren am 16. August 2026

**Alle sieben Punkte erfüllt.** Sechs Befunde aus zwei Fassungen sind
nachgeprüft, und `docs/54` Punkt 8 ist nachgeholt.

| Punkt | Gegenstand | Ergebnis |
|---|---|---|
| 1 | Befund 21 — kein Haken am Gerüst | **erfüllt** |
| 2 | Befund 22 — „Aktion" in beiden Breiten | **erfüllt** |
| 3 | Befund 23 — Baum abgesetzt | **erfüllt** |
| 4 | Befund 24 — die Linien | **erfüllt** |
| 5 | Befund 25 — die Aktionen klappen zu | **erfüllt** |
| 6 | Befund 26 — Rot heisst überall dasselbe | **erfüllt**, zehn Stellen |
| 7 | Die Bilderrunde | **erfüllt**, 24 Messungen |

**Ein Befund, und er kam von diesem Lauf:** Befund 27, der Kopfhaken über einer
leeren Auswahl. Behoben, aber **noch nicht auf dem Server** — er braucht `rc.9`.

**Vier Befunde über den Lauf selbst**, und das ist die gewohnte Mehrheit aus
`docs/45`, `47`, `48`, `53` und `55`:

1. Die Gegenprobe in §2.3 konnte ihren eigenen Gegenstand nicht sehen
   (`ls | tail -3` an einem Namen, der mit `e` anfängt).
2. Die Messung an `.selection` stürzte ab, weil ich die Bedingung nicht
   dazugeschrieben hatte — die Leiste gibt es nur mit einer Auswahl.
3. #3 lief zuerst am kurzen Dateinamen statt am langen; der Härtefall stand im
   Dokument und ich hatte ihn nicht in den Ablauf geschrieben.
4. Zu 3b fehlte die Zahl, weil `mess()` nicht gelaufen war.

**Und zwei Beobachtungen, die keine Befunde sind, aber benannt stehen:**

- Ein 76 Zeichen langer Dateiname schiebt bei 1440 px die Aktionsspalte aus dem
  Bild (Rollen innerhalb der Tabelle, Dokumentüberlauf 0). Entscheidung aus
  `docs/46 §20.13`, keine Folge dieses Umbaus.
- Eine Fehlerzeile in der Konsole gehört einer Browsererweiterung und nicht
  diesem Panel — nachgesehen: `resources/js` kennt `sendMessage`,
  `chrome.runtime`, `onMessage` und `addListener` **nullmal**.

> **Eine Meldung, die auf der eigenen Seite erscheint, ist nicht deshalb die
> eigene.**

---

## 10. Wie das Protokoll entsteht

**Während des Laufs und nicht danach.** Jeder Punkt bekommt seine Zeile, sobald
er gefahren ist — mit dem gemessenen Wert und nicht mit „ok".

Die Erwartung aus sechs Läufen: **Ein guter Teil der Befunde wird diesen Lauf
selbst betreffen und nicht den Prüfling.** In `docs/45`, `47`, `48`, `53` und
`55` war es jedes Mal die Hälfte bis zwei Drittel.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

---

## 11. Aufräumen

**Hier stand etwas anderes, und es widersprach §2.6.** Der erste Wurf dieses
Abschnitts entstand vor dem Lauf und wollte die lange Datei und den tiefen Pfad
löschen. Drei Abschnitte weiter oben steht die Begründung, warum der
Datenbank-Prüfbestand stehenbleibt — und sie gilt für die Dateien genauso.

> **Ein Rückstand ist, was eine Handlung übriggelassen hat. Eine
> Messvorrichtung ist, was die nächste Messung braucht. Ein Aufräumplan, der
> beide gleich behandelt, baut die Vorrichtung jedes Mal neu — und beim
> übernächsten Mal anders.**

`docs/54 §6.1` hat aus genau diesem Grund `index.html` stehenlassen: „sie ist
der Nachbar, an dem sich jede weitere Messung misst."

### Was geht

```bash
ABO=/var/www/vhosts/p6-b.invalid

rm -f "$ABO/httpdocs/auswahl.zip" \
      "$ABO/httpdocs/p6-k1.txt" "$ABO/httpdocs/p6-k2.txt" "$ABO/httpdocs/p6-k3.txt" \
      "$ABO/httpdocs/p6-p1.txt" "$ABO/httpdocs/p6-p2.txt" "$ABO/httpdocs/p6-p3.txt" \
      "$ABO/httpdocs/p6-neu.txt" "$ABO/httpdocs/p6-probe.txt"

rm -rf "$ABO"/tmp/p6-*

ls -la "$ABO/httpdocs/" "$ABO/tmp/"
```

**Die letzte Zeile ist die Gegenprobe und nicht Zierde.** Ein `rm`, dessen
Muster nicht passt, schweigt — `docs/52`: *Ein Rückbau, den niemand nachzählt,
meldet Erfolg auch dann, wenn nichts geschehen ist.*

### Was bleibt, und wofür

| Gegenstand | Wofür |
|---|---|
| `ein-sehr-langer-…txt` | Härtefall für Bereichstitel und `.cell-name` (#1, #3b) |
| `unter/tiefer/noch-tiefer/ganz-unten` | Härtefall für Baumeinrückung und Krümelumbruch (#4) |
| `p6-fremd/`, `p6-gid/`, `p6-bit` | Rechte-Prüflinge; Schritt 11 braucht sie |
| `index.html`, `gleich.txt`, `test2/`, `unter/` | ältere Nachbarn, schon in `docs/54` behalten |
| `IMG_4398/4399/4400.jpeg` | die einzigen Dateien im Megabytebereich |
| die drei Tabellen aus §2.6 | Prüfbestand der Konsole |

**Wer die lange Datei doch löscht**, legt sie beim nächsten Mal
**zeichengenau** wieder so an — ein anderer Name ist ein anderer Härtefall.

### Gefahren am 16. August 2026

Gelaufen, mit der Gegenprobe. Danach stehen in `httpdocs/` genau die neun
Gegenstände aus der Tabelle oben, und `tmp/` ist leer.

**Und ein Nebenbefund: Der Rückbau aus `docs/54 §6.1` ist nie gelaufen.** Das
`ls` aus §2.2 zeigte `p6-fremd`, `p6-probe.txt` und `auswahl.zip` noch alle —
der Lauf endete am 15. August mit dem Abschnitt „Aufräumen", und ausgeführt
wurde er nicht.

> **Der letzte Abschnitt eines Laufs ist der, den niemand mehr fährt.**

---

## 12. Was danach kommt

Halten die sieben Punkte, ist `docs/55` abgeschlossen und `docs/54` Punkt 8
nachgeholt. Dann gehen die Schritte aus `docs/51 §12` weiter: **8 (SFTP)**,
**9 (Cron)**, danach der **Angriffsdurchgang** (§4) und die vollständige
Bilderrunde.

Weiterhin benannt offen und **nicht** Teil dieses Laufs:

- `attributes` in `lang/de/validation.php` ist leer — 106 Feldnamen.
- `BlockSpacingTest::OPEN_SEAMS` steht auf 40.
- Die gemischte Population: ältere Abonnements tragen die alten Modi.
- **„Abschalten" der Zwei-Faktor-Einrichtung** ist rot, obwohl die Handlung
  umkehrbar ist. Steht in `DangerRankTest::UNCOVERED` mit Begründung; wenn es
  grau werden soll, ist das eine Entscheidung und kein Befund.
