# Protokoll: der Abnahmelauf zum Löschen von Adminkonten

**Gefahren am 10. September 2026** auf `cloudsrv24` gegen `0.7.4-rc.1`. Der Plan
ist `docs/901`, der Lauf `docs/902`. Dieses Dokument wächst während des Laufs;
was hier steht, ist gemessen und nicht erwartet.

---

## 1. Der Zustand vor dem Lauf

```
0.7.4-rc.1
Migration da: ja
Protokollzeilen: 1286
davon mit Abschrift: 1285
davon ganz ohne Handelnden: 1
aktive Betreiber: 1
```

**Der Nachtrag ist vollständig, und das steht in der Summe.** 1285 + 1 = 1286 —
es bleibt keine Zeile übrig, die weder eine Abschrift trägt noch einen Grund
hat, keine zu tragen. Eine Zahl allein hätte das nicht gesagt: „1285 von 1286"
liesse offen, ob die eine übrige ein Rest des Nachtrags ist oder der Fall, für
den es ihn nicht gibt.

> **Zwei Zahlen, die sich zur dritten addieren, sagen mehr als jede von
> ihnen.**

### 1.1 Punkt 5 ist ein Blick und kein Eingriff

`docs/902 §0.2` hat den Fall ausgeschrieben, in dem der Punkt eine
Netzbeschränkung anlegen müsste — und damit jeden aussperren kann, der nicht in
diesem Netz sitzt. Gemessen ist der Fall nicht eingetreten: Die Zeile ohne
Handelnden gibt es schon.

**Der Punkt wird deshalb gelesen und nicht hergestellt.** Der Server bleibt
unberührt.

> **Ein Prüfkörper, der den Zustand herstellt, statt ihn zu suchen, ändert den
> Server für eine Zeile, die vielleicht schon dasteht.**

### 1.2 Die Reihenfolge des Laufs kehrt sich um — Punkt 6 zuerst

`docs/902 §8` sieht vor, den Zustand „ein einziger aktiver Betreiber"
herzustellen, indem das zweite Konto nach den übrigen Punkten wieder
herabgestuft wird. **Gemessen steht der Zustand schon da** (`aktive
Betreiber: 1`).

Punkt 6 wird deshalb **vor** §2 gemessen, im Ist-Zustand, und erst danach
entsteht der Prüfkörper. Das spart zwei Zustandswechsel — und jeder Wechsel,
den man nicht macht, ist einer, der nicht danebengehen kann.

> **Ein Lauf, der einen Zustand herstellt, den die Maschine schon hat, misst
> seine eigene Vorbereitung mit.**

Die Abweichung steht hier und nicht als stille Korrektur in `docs/902`: Ein
Lauf, den man während des Fahrens glattzieht, verliert die Stelle, an der seine
Reihenfolge nicht trug.

---

## 2. Punkt 5 — erfüllt, und er hat einen Befund freigelegt

```
System-Zeile          : 2026-08-25 11:33:05  auth.login.failed  wer=[System]
aelteste mit Abschrift: 2026-08-03 09:42:54  auth.login         wer=[Administrator]
Migration Stapel      : 27
```

**Der Punkt ist erfüllt:** Die Zeile ohne Handelnden liest sich als `System` und
nicht als gelöschter Benutzer. Damit ist belegt, was `docs/901 §1.3` verlangt —
die beiden Nullfälle gehen auseinander.

**Und die zweite Hälfte von Punkt 2 ist gleich mit belegt.** Die älteste Zeile
mit Abschrift ist vom **3. August**, fünf Wochen vor dem Update; der Nachtrag
hat den Bestand also wirklich erreicht und nicht nur die Zeilen von heute. Eine
Zeile von heute hätte nichts belegt — sie trüge ihren Namen ohnehin vom
Anlegen. Der Stapel 27 sagt dazu, dass die Migration mit diesem Update lief.

### Befund 1 — „System" für einen anonymen Anmeldeversuch

Die Zeile ohne Handelnden ist **nicht** die erwartete `settings.access` aus dem
A9-Lauf, sondern ein `auth.login.failed`. Nachgemessen am Quelltext:
`LoginController` übergibt `account: $account` — bei einer **bekannten** Adresse
trägt die Zeile also das Konto. Diese hier trägt keins, war also ein Versuch mit
einer Adresse, die es nicht gibt.

**Damit reitet eine dritte Bedeutung auf derselben Null.** `docs/901 §3.4` hat
zwei getrennt: „niemand war angemeldet" (Kommandozeile, Automatik) gegen „das
Konto ist gelöscht". Die Abschrift trennt diese beiden sauber. Innerhalb des
ersten Falls stecken aber **zwei**:

- Die Maschine hat gehandelt — `srvpanel access`, `Operations::dispatch()`.
  Dafür ist `System` richtig.
- Ein Mensch hat gehandelt, und wir wissen nicht welcher — ein Anmeldeversuch
  mit unbekannter Adresse. Dafür ist `System` falsch: Es behauptet, der Server
  habe sich selbst anzumelden versucht.

> **Eine Null, die schon zwei Bedeutungen trägt, bekommt eine dritte — und alle
> drei sehen gleich aus.**

**Was es schärft: die Auskunft ist da, und die Spalte daneben zeigt sie.**
`toArrayRow()` liefert `details` aus `context`, und `context['email']` trägt die
versuchte Adresse; `/audit` rendert sie unter „Einzelheiten".

> **Zwei Spalten derselben Zeile, von denen die eine „die Maschine" sagt und die
> andere die Adresse eines Menschen zeigt, widersprechen einander — und die
> neue ist die, die irrt.**

**Warum das jetzt und nicht früher auffällt:** Die Spalte „Wer" gibt es erst
seit dieser Fassung. Vorher stand dort nichts, und nichts behauptet nichts.

> **Ein Feld, das man sichtbar macht, macht auch seine Ungenauigkeit
> sichtbar.**

**Die Wirkung ist heute klein und wächst.** Auf `cloudsrv24` ist es genau
**eine** Zeile von 1286 — aber es ist genau die Sorte Zeile, für die ein
Prüfprotokoll existiert, und jeder weitere Versuch mit unbekannter Adresse legt
eine neue an.

**Nicht behoben während des Laufs.** `actor()` kann die beiden Fälle aus seinen
zwei Spalten nicht unterscheiden — die Handlung weiss es, nicht der Handelnde.
Wo die Behebung hingehört, entscheidet der Betreiber nach dem Lauf; ein
Abnahmelauf, der seinen Prüfling während des Fahrens ändert, misst danach einen
anderen.
