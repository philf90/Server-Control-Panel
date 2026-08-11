# 42 — Die Abnahme von P5b

Gefahren am 11. August 2026 auf `cloudsrv24` (Ubuntu 24.04, PostgreSQL 16.14),
nach der Befehlsfolge aus `docs/38 §19`. Belegt sind **alle sieben Kriterien**
aus `docs/38 §3`.

Der Lauf hat **sechs Fehler gefunden, und keinen davon ein Test.** Drei davon
hat erst der jeweils vorige Fix sichtbar gemacht — das ist die wichtigste
Beobachtung dieses Dokuments und steht in §3.

---

## 1. Was gemessen wurde

| # | Kriterium | Beleg |
|---|---|---|
| 1 | keine Aufzählung mit Zugehörigkeit | `SELECT datname FROM pg_database` liefert fünf Namen: `postgres`, `template0`, `template1`, `x4019d319cc321a7a_laden`, `x45c97683d84c369c_shop`. Keiner nennt Abonnement, Kunde oder Domain. |
| 2 | Statistiksichten verschlossen | `permission denied for view pg_stat_database` und `… pg_stat_activity`, wörtlich — geprüft in **zwei** Datenbanken desselben Abonnements (`_shop` und `_blog`). |
| 3 | kein Zugriff auf fremde | `FATAL: permission denied for database "x4019d319cc321a7a_laden"` mit `DETAIL: User does not have CONNECT privilege.` |
| 4 | Absperrung nicht aufhebbar | `WARNING: no privileges were granted` **und** `GRANT` als Ergebniszeile; `ERROR: must be owner of database`; danach weiterhin `permission denied` für die fremde Rolle. |
| 5 | Dump erzwingt nichts | Vorgang 552 `failed`, im Panel wörtlich: `psql:….restore.sql:67: ERROR: permission denied to alter role`. Dazu `rolsuper = f`, keine `/tmp/ausbruch.txt`, kein `fremd_erreicht`. |
| 6 | `pg_dump` funktioniert weiter | Als Kunde von aussen: 3448 Byte. |
| 7 | Rückbau lässt nichts liegen | Datenbanken leer, Rollen leer, Dumpverzeichnis fort, „Nichts liegengeblieben." — **und alle beteiligten Vorgänge auf `succeeded`.** |

**Der Ratekanal aus `docs/38 §22` ist gefahren und protokolliert, nicht
verschwiegen:** `psql …/gibtsnicht` antwortet `FATAL: database "gibtsnicht"
does not exist`. Das ist kein Fehlschlag des Laufs, sondern die Messung der
eigenen Grenze — ein Kriterium, das sie nicht misst, behauptet sie nur.

**Schritt 10 (Fernzugriff) ist nicht gebaut.** Nach `docs/38 §19` ist die Stufe
ohne ihn abnahmefähig.

---

## 2. Die drei Stellen, an denen der Plan nicht trug

**Punkt 3b verlangte einen Vergleich, den der Bau abgeschafft hatte.** Der
Schritt wollte eine zweite Datenbank „mit gesetzter Sortierung, die `TEMPLATE
template0` erzwingt". Für PostgreSQL gibt es im Panel kein Sortierungsfeld: Der
Agent liest die Sortierung aus `template0` und legt **jede** Datenbank so an.
Der Plan beschrieb die Welt vor seiner eigenen Lösung — der Fund vom 9. August
war ja der Anlass, `template0` zur Regel zu machen.

> **Ein Abnahmeschritt, der zwei Fälle vergleicht, wird sinnlos, wenn der Bau
> einen davon abgeschafft hat — und er merkt es nicht.**

An seine Stelle traten zwei Messungen: die zweite Datenbank desselben
Abonnements ist ebenso abgesperrt, und der Katalog belegt, dass keine
Kundendatenbank aus der Reihe fällt.

**Und die zweite dieser Messungen belegt weniger, als sie sollte.** `template0`
und `template1` tragen auf diesem Server dieselbe Sortierung; `datcollate` kann
sie also gar nicht unterscheiden.

> **Eine Messung, die zwei Möglichkeiten nicht unterscheiden kann, sagt nicht,
> welche vorliegt.**

Der echte Beleg wäre ein Artefakt in `template1` und die Gegenprobe, dass es in
einer neuen Kundendatenbank fehlt. **Er steht aus** und ist hier benannt statt
weggeredet.

**`<abo>` im Dumppfad ist der Abonnementname**, nicht der Systembenutzer —
`Dump::directory()` ruft `SubscriptionProvision::subscriptionName()`. Im Plan
stand „`<abo>`" neben „`<A>`" für ein Präfix und „p1126" für einen
Systembenutzer; die Verwechslung kostete einen Durchgang.

---

## 3. Die sechs Fehler — und warum drei davon erst sichtbar wurden

### 3.1 Der Rückbau liess einen Zugang stehen *(Kriterium 7, erster Anlauf)*

`x45c97683d84c369c_web` überlebte `subscription.remove`; der Vorgang meldete
**fertig, 100 %**. Gefunden hat es `srvpanel db`.

Die Ursache ist ein Zeitpunkt: Ein Zugang geht mit seiner Datenbank, wenn er an
keiner anderen hängt — und beim Rückbau werden alle Datenbanken **auf einmal**
eingereiht. Jeder Vorgang berechnet seine Listen beim Einreihen, also während
die anderen noch dastehen.

> **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die
> anderen Vorgänge derselben Reihe nicht.**

Der Fehler trifft MariaDB genauso und war nur nie aufgetreten: Er verlangt ein
Abonnement mit mehr als einer Datenbank *und* einen Zugang an mehreren.

### 3.2 Der Fehlerweg scheiterte an seiner eigenen Begründung *(Kriterium 5, erster Anlauf)*

Der Agent wies den Dump ab und meldete genau das, was das Kriterium verlangt.
Im Panel stand *„Der Vorgang wurde von der Warteschlange abgebrochen —
vermutlich Zeitüberschreitung"*, an einem Vorgang, der **eine Sekunde** lief.

`operations.message` war `varchar(255)`, die Begründung 260 Zeichen. MariaDB
wies sie ab, und die `PDOException` flog aus genau dem `catch`-Zweig heraus, der
den Fehlschlag festhalten sollte.

> **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**

> **Ein Fehlertext, der eine Ursache rät, ist schlimmer als einer, der keine
> nennt — er beendet die Suche.**

Und die Pointe steckt in der Länge: **Je wichtiger die Begründung, desto länger
ist sie.** „Datei nicht gefunden" passte immer. Die einzige Auskunft, an der ein
Abnahmekriterium hing, passte nie.

### 3.3 Der erste Fix erzeugte den zweiten Fehler *(Kriterium 7, zweiter Anlauf)*

Seit 3.1 nennt beim Rückbau jeder Datenbankvorgang alle Zugänge. Damit lief der
**erste** in ein `DROP ROLE`, das PostgreSQL verweigert, solange die Rolle an
der zweiten Datenbank noch Rechte hat. Er scheiterte **nach** dem `DROP
DATABASE`: Der Cluster war sauber, seine Zeile blieb im Panel. Vorher blieb eine
Rolle, jetzt eine Zeile.

Lesbar war das nur, weil 3.2 behoben war.

> **Eine Reihenfolge, die erst beim Ausführen entsteht, kann beim Einreihen
> niemand kennen.**

Das Panel entscheidet weiterhin, **ob** ein Zugang mitgehen soll; ob er es
**jetzt kann**, fragt der Agent unmittelbar vor dem `DROP ROLE`.

### 3.4 Die Meldung hatte keinen Platz *(Nachlauf zu 3.2)*

Kaum kam die Begründung im Panel an, schob sie die Vorgangsseite bei 390px um
**110px** aus dem Bild: Sie trägt den Pfad des Dumps, hundert Zeichen ohne ein
einziges Leerzeichen, und `.notice` ist eine Flexbox.

*Erst hatte diese Meldung keinen Weg ins Panel, dann keinen Platz darin.*

> **Was in einer Meldung steht, kommt von aussen.** Vom Agenten, vom
> Betriebssystem, von einem fremden Anbieter — und keine dieser Quellen kennt
> die Breite eines Telefons.

### 3.5 Die Meldung daneben war 65px zu breit *(vor dem Lauf, mit `rc.7` ausgeliefert)*

Vier direkte Kinder in einer `.notice`. Einzeln lief keine der drei Kennungen
über — erst zusammen. Derselbe Fehler wie der aus P4 mit seinen 83px.

### 3.6 Der Zeitstempel einer Sicherung stand im Payload und auf keiner Seite

`created_at` ging seit jeher an den Browser; die Tabelle „Sicherungen" zeigte
Name, Grösse, Zustand, Aktion. Zwei Sicherungen desselben Tages waren nur über
den Dateinamen zu unterscheiden — und der ist eine Kennung, kein Datum.

> **Ein Feld, das niemand liest, ist keine Auskunft, sondern Rechenzeit.**

Derselbe Satz wie bei der Quota, nur eine Grenze weiter: dort Agent → Panel,
hier Panel → Browser.

---

## 4. Was der Lauf über die Wächter gesagt hat

**Keiner der sechs Fehler wurde von einem Test gefunden.** Vier fand ein echter
Server, zwei eine Messung im Browser bei 390px. Für jeden gibt es jetzt einen
Wächter und einen Bruch dazu (`tests/waechter-brechen.sh`, 267 Eingriffe).

**Zwei Wächter hätten ihren eigenen Fehler beinahe verfehlt:**

Der Test zur Spaltenbreite war zuerst ein Verhaltenstest — schreiben,
zurücklesen, vergleichen. Er wäre grün gewesen, auch mit `varchar(255)`: Die
Tests laufen gegen SQLite im Speicher, und SQLite legt jede Länge in eine so
deklarierte Spalte.

> **Ein Test, der gegen eine andere Datenbank läuft als der Server, prüft die
> Grenzen der falschen.**

Und der Test zur Dumpablage las zuerst einen festen Ausschnitt von 2000 Zeichen
— der reichte in die nächste Methode und meldete deren Felder.

> **Ein Wächter, der die falsche Fläche liest, findet dort auch etwas.**

---

## 5. Was offen bleibt, benannt

1. **Der `template1`-Beleg** aus §2 — die Gegenprobe, dass keine
   Kundendatenbank über `template1` entsteht.
2. **Ein Zugang ohne jede Datenbank** käme an keinen der Entfernungsvorgänge.
   Ob das Panel diesen Zustand überhaupt erlaubt, ist ungemessen; blind gebaut
   wäre die Behandlung die zweite Fassung einer Regel.
3. **Schritt 10, der Fernzugriff** (`docs/38 §19` Punkt 10) — nicht gebaut.
4. **Die Anzeigezeitzone** (`docs/40`) — alle Zeiten stehen in UTC, auch der
   neue Zeitstempel der Sicherungen.
