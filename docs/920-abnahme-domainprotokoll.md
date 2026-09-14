# 920 — Der Abnahmelauf für die Fusszeile der Domainseite

Ausgeschrieben am 14. September 2026 **vor** dem Fahren, gegen den Bau aus
`docs/919`. Er liegt im 900er-Block neben seinem Plan: Ein Lauf, den achtzig
Nummern von seinem Plan trennen, wird nicht neben ihm gelesen.

**Neun Punkte. Punkt 3 und Punkt 7 dürfen nicht ausfallen.**

---

## §0 Was vor dem Lauf gelesen wird — und was beim Ausschreiben umgefallen ist

Zu lesen sind `docs/919` (der Plan, besonders §1 und §5) und `docs/914 §2` (die
beiden Gründe, aus denen ein Fenster unvollständig sein kann). Wer nur diesen
Lauf liest, weiss nicht, warum Punkt 3 nicht ausfallen darf.

**Vier Kriterien aus `docs/919 §9` haben beim Ausschreiben ihre Fassung
gewechselt.** Keines davon hat der Prüfling zu verantworten.

### (a) Punkt 5 setzte eine Berechtigung voraus, die er nicht nannte

„Der Kunde sieht dieselbe Fusszeile wie der Betreiber" ist nur messbar, wenn der
Kunde die Seite überhaupt erreicht. `DomainPolicy::viewLogs` verlangt von einem
Nicht-Admin **`Permission::FilesRead`** auf dem Abonnement — und ohne sie gibt es
keinen anderen Fusszeilentext, sondern einen **403**.

> **Ein Kriterium, das an einer Vorbedingung scheitern kann, die es nicht nennt,
> fällt für einen Grund aus, der mit seinem Gegenstand nichts zu tun hat.**

Die Bedingung wird deshalb in §1 **gemessen** und nicht angenommen. Der Punkt
heisst jetzt Punkt 7.

### (b) Punkt 2 mass an einem Gegenstand, der sich beim Messen verändert

Ein Zugriffsprotokoll einer lebenden Domain wächst zwischen den beiden
Messungen. „Die erste Zeile wandert zurück" bliebe zwar wahr, aber die letzte
wanderte mit — und genau ihr Stillstand ist die Hälfte des Belegs.

> **Ein Prüfkörper, der seinen Gegenstand beim Messen verändert, meldet den
> Unterschied als Fehler des Gemessenen.** Derselbe Satz wie in `docs/918`, dort
> an `agent.log` bezahlt.

Gefahren wird deshalb an einer Domain, die gerade niemand aufruft — und dass sie
stillsteht, wird belegt (`wc -l` zweimal) und nicht geglaubt.

### (c) Punkt 3 verlangte einen Zustand und sagte nicht, wie er entsteht

„Ein Protokoll mit Zeilen über 5242 B" ist auf einem echten Server keine
Fundsache. Hergestellt wird er über die **echte Route**: nginx schreibt
`access_log <pfad>;` ohne Formatnamen, also im Vorgabeformat `combined`, und das
enthält `"$request"` vollständig. Ein Aufruf mit langer Abfragezeichenkette
erzeugt damit eine echte Protokollzeile in der gewünschten Länge.

**Ob sie wirklich ankam, wird gemessen** — nginx kann eine zu lange Anfragezeile
mit `414` abweisen, und dann stünde eine kurze Zeile da, die wie ein Befund
aussähe.

### (d) Das Dokument zählte sich selbst falsch

`docs/919 §9` führt **sieben** Punkte, §13 sprach von „den acht Punkten aus §9".
Beides von mir, in derselben Datei.

> **Zwei Zeilen desselben Dokuments über dieselbe Frage laufen auseinander, und
> keine von beiden ist der Ort, an dem man nachsieht.**

Dieser Lauf hat **neun** Punkte: die sieben aus §9, dazu die Gegenprobe zum
Deckel von Hand (Punkt 4) und der Fall, dass der Agent nicht antwortet (Punkt 8).
Den zweiten gibt es, weil `docs/919` die Meldung des Fehlschlags **umgebaut** hat
— sie steht jetzt neben der Antwort statt darin —, und kein Kriterium sie anfasste.

> **Eine Änderung ohne Kriterium ist eine Änderung, die niemand nachsieht.**

---

## §1 Vorbedingungen — gemessen und nicht angenommen

Alles als `root` auf `cloudsrv24`, gegen die Fassung, die den Bau trägt.

```bash
srvpanel version
systemctl is-active srvpanel-agentd srvpanel-worker
```

**Erwartet:** die Fassung mit dem Bau aus `docs/919`, beide `active`.

**Die Prüfdomain und ihr Abonnement.** Gebraucht wird eine Domain, die gerade
niemand aufruft — sonst misst Punkt 2 einen wachsenden Gegenstand.

```bash
D=<domain>            # die Prüfdomain
U=<systembenutzer>    # p1xxx des Abonnements
L=/var/www/vhosts/$U/logs/$D

ls -l $L
wc -l $L/access.log; sleep 20; wc -l $L/access.log
```

**Erwartet:** zweimal dieselbe Zahl. Weicht sie ab, ruft jemand die Domain auf,
und Punkt 2 gehört an eine andere.

**Die Berechtigung für Punkt 7** — der Fund aus §0 (a). Gemessen und nicht
angenommen:

```bash
srvpanel tinker --execute='
use App\Models\{Domain, Account};
use App\Enums\Permission;
$d = Domain::withoutGlobalScopes()->where("name", "'"$D"'")->firstOrFail();
$s = $d->subscription;
echo "Domain ", $d->id, " Abo ", $s?->name, "\n";
foreach (Account::withoutGlobalScopes()->where("type", "customer")->get() as $a) {
    if (! $a->mayAccessSubscription($s)) { continue; }
    printf("  Konto %d %s  status=%s  FilesRead=%s\n", $a->id, $a->email,
        $a->status->value, $a->hasPermission($s, Permission::FilesRead) ? "ja" : "nein");
}'
```

**Erwartet:** mindestens ein Konto mit `status=active` **und** `FilesRead=ja`.
Gibt es keines, fällt Punkt 7 aus — und zwar **als Ausfall**, nicht als „nicht
herstellbar": Die Berechtigung lässt sich vergeben.

**Der Prüfkörper für die lange Zeile, einmal probeweise:**

```bash
curl -sk -o /dev/null "https://$D/?x=$(python3 -c 'print("a"*6000)')"
awk '{ print length }' $L/access.log | tail -1
```

**Erwartet:** rund **6150**. Kommt dort eine kurze Zahl oder liefert `curl` eine
`414`, weist nginx die Anfragezeile ab; dann wird die Zeile mit `printf` direkt
angehängt — schwächer, weil nicht über die Route entstanden, und für den Leser
ausreichend. **Was gefahren wurde, gehört ins Protokoll.**

---

## §2 Punkt 1 — die kurze Datei

Eine Domain, deren Zugriffsprotokoll weniger Zeilen hat als die Anfrage. Notfalls
herstellen:

```bash
wc -l $L/access.log          # muss unter 100 liegen
```

Dann im Browser: `/domains/<id>/logs?kind=access&lines=100`

**Erwartet**
- Fusszeile: **„36 Zeilen · das ist die ganze Datei."** (die Zahl ist die
  gemessene aus `wc -l`)
- **Kein** Knopf „Mehr Zeilen"
- Kein Satz über den Bytedeckel

Die Zahl der Fusszeile wird gegen `wc -l` gehalten und nicht gegen die Zeilen auf
dem Bild — abgezählte Zeilen auf einem Bildschirmfoto sind eine Vermutung.

---

## §3 Punkt 2 — die lange Datei, und der Knopf bewirkt etwas

Eine Domain mit mehr als 200 Zeilen im Zugriffsprotokoll, die **stillsteht**
(§1).

`/domains/<id>/logs?kind=access&lines=100`, dann den Knopf drücken.

**Erwartet**

| | Zeilen | erste Zeile | letzte Zeile |
|---|---|---|---|
| vorher | 100 | *(notieren)* | *(notieren)* |
| nach dem Drücken | 200 | **eine andere, weiter oben** | **dieselbe** |

- Fusszeile beide Male: „… Zeilen · die Datei ist länger."
- Knopf danach: „Mehr Zeilen (200 → 400)"

**Die letzte Zeile ist der halbe Beleg.** Eine Anfrage, die bloss mehr Zeilen
**anhinge**, sähe an der Zahl genauso aus.

> **Zwei Enden, von denen eines wandert und eines steht, sagen mehr als die Zahl
> dazwischen.**

Notiert werden die **ersten zwanzig Zeichen** der beiden Zeilen, nicht die ganze
— eine Zeile eines Zugriffsprotokolls ist zu lang, um sie abzuschreiben.

---

## §4 Punkt 3 — der Bytedeckel *(Ausschlusskriterium)*

**Der Fall, den es ohne die Messrunde nicht gäbe.** Von aussen sieht ein
gedeckeltes Fenster Zeichen für Zeichen aus wie eine kurze Datei.

```bash
for i in $(seq 1 120); do
  curl -sk -o /dev/null "https://$D/?x=$(python3 -c 'print("a"*6000)')"
done
awk '{ print length }' $L/access.log | tail -100 | sort -n | head -1
```

**Erwartet:** die kürzeste der letzten hundert Zeilen liegt über **5243**. Liegt
sie darunter, hat sich fremder Verkehr dazwischengeschoben; dann noch einmal
feuern.

Dann `/domains/<id>/logs?kind=access&lines=100`

**Erwartet**
- Deutlich **weniger als 100** Zeilen (bei 6150 B je Zeile rund 85)
- Fusszeile: **„… Zeilen · weiter zurück wurde nicht gelesen; das Fenster ist
  auch in Bytes begrenzt."**
- **Kein** Knopf
- Der Satz „das ist die ganze Datei" steht **nicht** da

---

## §5 Punkt 4 — von Hand mehr verlangen ändert beim Deckel nichts

Unmittelbar danach, ohne den Zustand zu verändern, in der Adresszeile
`lines=100` durch `lines=500` ersetzen.

**Erwartet:** **dieselbe** Zeilenzahl, **dieselbe** erste Zeile, **dieselbe**
letzte, **derselbe** Satz.

Damit ist die Abwesenheit des Knopfes keine Entwurfsmeinung, sondern eine
Messung — durch Agent, Controller und Seite. Im Container ist dasselbe an
`WebLogsTail::tail()` gemessen (`docs/919 §1`, M9); hier nimmt es den ganzen Weg.

> **Eine Abwesenheit ist begründet, wenn das Vorhandene gemessen nichts geändert
> hätte.**

---

## §6 Punkt 5 — die Gegenprobe zum Deckel

Dieselbe Domain, dieselbe Seite, nur kurze Zeilen:

```bash
for i in $(seq 1 120); do curl -sk -o /dev/null "https://$D/"; done
awk '{ print length }' $L/access.log | tail -100 | sort -n | tail -1
```

**Erwartet:** die **längste** der letzten hundert Zeilen liegt unter 5243. Dann
die Seite neu laden.

**Erwartet:** 100 Zeilen, Fusszeile „… die Datei ist länger.", Knopf **da**, und
der Satz über den Bytedeckel **fort**.

> **Ein Satz, der immer dasteht, sagt nichts.** Ohne diese Gegenprobe bliebe
> offen, ob die Seite den Deckel gemessen oder nur behauptet hat.

---

## §7 Punkt 6 — die Grösse neben dem Pfad

```bash
stat -c%s $L/access.log
```

**Erwartet:** Neben dem Pfad steht dieselbe Grösse, gerundet nach
`formatBytes` — unter 1024 B roh in `B`, darunter bis 1 MiB **gerundete** KB
(`Math.round(bytes / 1024)`), darüber MB mit einer Nachkommastelle, jeweils in
deutscher Schreibweise. Beispiel: 280 303 B → **„274 KB"**.

Die Erwartung wird **ausgerechnet, bevor die Seite angesehen wird**.

> **Eine Erwartung, die man aus den Zahlen ausrechnet statt sie zu schätzen,
> macht aus dem Ergebnis einen Beleg.**

---

## §8 Punkt 7 — der Kunde sieht dieselbe Fusszeile *(Ausschlusskriterium)*

Die Seite gehört dem Kunden; der Betreiber sieht sie mit.

Mit dem Konto aus §1: über **Kunden → Wechseln** in das Kundenkonto wechseln
(`POST /customers/{customer}/impersonate`), dann `/domains/<id>/logs` aufrufen —
und danach **zurückwechseln**.

**Erwartet**
- Kein 403
- Dieselbe Fusszeile, Wort für Wort, wie sie der Betreiber in Punkt 1 bis 3
  gesehen hat
- Derselbe Pfad und dieselbe Grösse daneben

**Was das nicht prüft:** ob der Kunde den Satz so liest, wie er gemeint ist. Das
hängt an einer Erwartung und nicht an einer Eigenschaft des Quelltextes; es steht
in `docs/919 §11` als Frage und nicht hier als Zusage.

---

## §9 Punkt 8 — der Agent antwortet nicht

Der Fall, den `docs/919` umgebaut hat: Der Fehlschlag reist jetzt **neben** der
Antwort statt in ihr.

```bash
systemctl stop srvpanel-agentd
systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics
```

**Erwartet:** alle drei `inactive` — `Requires=` überträgt das Anhalten.

Dann die Seite laden.

**Erwartet:** der Streifen **„Der Agent antwortet nicht: …"**, und **keine**
Fusszeile, kein Knopf, keine leere Liste, die wie ein leeres Protokoll aussähe.

**Danach — und das ist kein Aufräumen, sondern Teil des Punktes:**

```bash
systemctl start srvpanel.target
systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics
```

**Erwartet:** alle drei wieder `active`. **Ein `start` des Agenten allein genügt
nicht** — `Requires=` überträgt das Anhalten und nicht das Starten, und der
Worker ist die Warteschlange (`docs/100 §9.10`). Wer hier `systemctl start
srvpanel-agentd` tippt, lässt den Server halb liegen, und jeder Vorgang bleibt
wortlos auf „wartet" stehen.

---

## §10 Punkt 9 — die Bilderrunde

Vier Lagen: zwei Themen × 390 px und 1440 px, **jede in einer frisch geladenen
Seite** — `tests/bilder-messen.js` wirft beim zweiten Aufruf ohne Neuladen, und
ein zweites Einfügen scheitert an der Wiederdeklaration von `STAND`.

Gefahren wird der Zustand mit dem **längsten** Satz, also der gedeckelte aus
Punkt 3: Er hat die zwei Zeilen Fusszeile und ist damit der engste Fall.

**Erwartet je Lage**
- `dokument = 0`
- Gegenprobe **200/200**
- `schiebt = 0`
- `rollt` enthält den Protokollrahmen — das ist gewollt (`docs/24`) und kein
  Befund

Dazu je Lage eine Aufnahme. **Angesehen wird sie auf mehr als die Zahl**: ob die
Fusszeile lesbar umbricht, ob der Pfad mit seiner Grösse nicht zerreisst, und ob
bei 390 px der Knopf — wo er steht — unter dem Satz sitzt und nicht neben ihm.

> **Eine Zahl sagt, ob die Seite schiebt. Ein Bild sagt, was darauf steht. Keines
> von beiden ersetzt das andere.**

Der Container hat dafür vier Werte vorhergesagt (`docs/919 §13`): `dokument = 0`,
Gegenprobe 200/200, `schiebt = 0` in allen zwölf Lagen, und der Rinnstein
existiert hier nicht. Wo eine Erwartung vor der Messung feststeht, ersetzt das
Nebeneinanderlegen das Beurteilen.

---

## §11 Was dieser Lauf ausdrücklich nicht prüft

- **Die Wächter.** `LogFooterTest` und die acht Eingriffe im Bruchskript sind
  Sache der CI; ein Abnahmelauf, der sie noch einmal führe, misste die CI.
- **Die Nummernspalte.** Entschieden am 14. September, sie kommt nicht
  (`docs/919 §15`).
- **`/logs` selbst.** Die Seite ist am 14. September gegen `0.7.4-rc.9`
  abgenommen und von diesem Bau nicht berührt — geändert hat sich der Wächter
  darüber und keine Zeile ihres Quelltextes.
- **Die Grösse von `MAX_BYTES`.** Entschieden in `docs/914 §6`.
- **Ob der Kunde den Satz richtig versteht.** Eine Frage und keine Zusage
  (`docs/919 §11`).
- **Welche Protokolle auf diesem Server von sich aus über der Schwelle liegen.**
  Punkt 3 stellt den Zustand her, statt ihn zu suchen — und sagt das.

---

## §12 Wann er durch ist

**Alle neun Punkte erfüllt.** Punkt 3 (der Bytedeckel) und Punkt 7 (der Kunde)
**dürfen nicht ausfallen** — der erste ist der Fall, für den es diesen Bau gibt,
der zweite die Seite, für die er gemacht ist.

Ein Punkt, der am **Werkzeug** scheitert und nicht am Gegenstand, ist nicht
„nicht herstellbar" — er wird nachgeholt.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten, ist
> keines mehr — es ist eine Zusammenfassung.**

Das Protokoll bekommt die nächste freie Nummer und hält je Punkt den
**gemessenen** Wert fest, nicht einen Haken. Befunde werden **während** des Laufs
aufgeschrieben und **danach** gebaut: Eine Behebung ist eine Änderung am
Prüfling.
