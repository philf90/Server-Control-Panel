# Der Nachlauf zu `v0.6.0-rc.21`

*Angelegt am 21. August 2026, **während** des Laufs und nicht danach.*

## 0. Wofür es diesen Lauf gibt

`docs/66` hat elf Punkte gemessen und acht Befunde gebracht. Alle acht sind
behoben und mit `v0.6.0-rc.21` ausgeliefert — **aber eine Behebung ist keine
Messung.** Fünf Dinge stehen in `docs/66 §3` als „wartet auf die nächste
Fassung", und dieser Lauf holt sie nach.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

Der wichtigste ist Punkt 1: Er war der **einzige nicht erfüllte Punkt** des
vorigen Laufs.

| | Rahmen | |
|---|---|---|
| Fassung | `v0.6.0-rc.21` | |
| Server | `cloudsrv24` | |
| Abonnement | 140, `p6-abnahme.invalid`, Systembenutzer `p1139` | |
| Messmittel | `tests/bilder-messen.js`, Stand **2026-08-21** | mit `versteckt` |

**Neu am Messmittel, und beim Lesen zu beachten:** Das Feld `versteckt` zählt
die Kästen, die nur für die Vorlesesoftware da sind und deshalb nicht mehr in
`schiebt` stehen (Befund 2). Eine Zahl dort ist **kein** Fund; eine kürzere
`schiebt`-Liste als früher ist der Zweck und nicht ein Ausfall.

---

## 1. Die Punkte

| # | Punkt | erwartet | gemessen | |
|---|---|---|---|---|
| 1 | Suche ohne und mit Häkchen | beide Male eine Trefferliste | | |
| 2 | Vorschau auf der Cronseite | Satz und drei Fälligkeiten | | |
| 3 | Entprellung | deutlich unter 20 Anfragen, mindestens 1 | | |
| 4 | `/audit` bei 390 px | `dokument: 0` | | |
| 5 | Eine Protokollzeile nennt ihr Stück | `job: … · schedule: …` | | |

---

## 2. Die Befunde

*(Je Befund: was gesehen wurde, welche Zahl dazugehört, und ob er am Panel, am
Prüfmittel oder am Kriterium liegt.)*

---

## 3. Was offen bleibt

*(Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.)*
