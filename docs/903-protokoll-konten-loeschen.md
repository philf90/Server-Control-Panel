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
