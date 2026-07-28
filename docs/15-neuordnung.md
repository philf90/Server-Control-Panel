# 14 — Neuordnung der Oberfläche

Drei Entwürfe dafür, wie das Panel angeordnet sein müsste, damit beim Öffnen das
Gefühl entsteht, an den Reglern zu sitzen statt einen Bericht zu lesen. Die
Mappe mit allen Mockups in Hell und Dunkel:

**[docs/entwuerfe/neuordnung.html](entwuerfe/neuordnung.html)** — im Browser öffnen.

Die Datei ist statisches HTML ohne Schriftdateien und ohne externe Aufrufe,
genau wie das Panel selbst. Jeder Rahmen hat einen eigenen Hell/Dunkel-Schalter,
oben stellt einer alle zugleich.

---

## Befund

Vier Entscheidungen, die einzeln richtig waren und zusammen dazu führen, dass
man liest statt bedient.

1. **Der Zustand verschwindet, sobald man handelt.** Die Kennzahlen stehen nur
   auf der Übersicht. Wer auf „Dienste“ wechselt, um den Ausfall zu beheben,
   sieht CPU, Speicher und Platte nicht mehr.
2. **Zahlen sind Text, keine Griffe.** Nichts auf der Übersicht ist anklickbar.
   Der Weg von einer auffälligen Zahl zu der Stelle, an der man etwas tut, führt
   über das Menü.
3. **Handlungen hinterlassen keine Spur.** Ein Neustart lädt die Seite neu. Was
   der Befehl war, ob er durchlief, wie lange er brauchte — steht nirgends im
   Blickfeld.
4. **Alle Bereiche wiegen gleich viel.** Das Menü verrät nicht, ob irgendwo
   etwas offen ist. Man muss jede Seite besuchen, um zu wissen, dass nichts zu
   tun ist.

## Fünf Grundsätze, die alle drei Entwürfe befolgen

| | |
|---|---|
| I | Der Zustand geht nie weg — Kennzahlen bleiben auf jeder Seite sichtbar |
| II | Jede Zahl ist ein Griff — ein Klick führt dorthin, wo man sie ändert |
| III | Handlungen sind quittiert — was, wie lange, wie ausgegangen |
| IV | Das Panel verschweigt nichts — ausgeführte Befehle im Klartext |
| V | Erst das Urteil, dann die Zahlen |

## Die drei Entwürfe

| | 1 · Kommandobrücke | 2 · Werkbank | 3 · Leitstand |
|---|---|---|---|
| Wodurch Kontrolle entsteht | alles im Blick, nichts verborgen | unmittelbare Handhabung, umkehrbare Eingriffe | eindeutiger Zustand, Handlung daneben |
| Grundriss | Statusleiste, Symbolschiene, Kachelmatrix, Konsole | Schiene, Liste, Inspektor, Änderungsablage | Kopfmenü, Urteil, Mimikbild, große Kacheln |
| Kennzeichen | Konsolen-Echo jedes ausgeführten Befehls, Befehlspalette (⌘K) | Änderungen sammeln und als ein Vorgang anwenden, mit Rückfallfrist | Farbe trägt ausschließlich Zustand, Signalweg als Bild |
| Für wen | Admins, die sonst per SSH arbeiten | Teams, gemischte Kenntnisstände, viele Module | Selfhoster, gelegentliche Nutzung, Wandmonitor |
| Umbau am Bestand | mittel | groß | klein |
| Ohne JavaScript | ja, ohne Palette | ja, Auswahl in der URL | ja, vollständig |

## Empfehlung: drei Schichten statt einer Wahl

Die Entwürfe arbeiten auf verschiedenen Ebenen und schließen sich weniger aus,
als es aussieht. Nach jeder Stufe ist etwas Fertiges da.

**Stufe 1 — Entwurf 3 für die Übersicht.** Urteilsband, Mimikbild, vier
Zustandskacheln mit Knöpfen darin, darunter Tableau und Spur. Kleinster
Eingriff, größte sofortige Wirkung: genau die Stelle, an der der erste Eindruck
entsteht.
`internal/ui/templates/dashboard.html` · `internal/ui/static/app.css` ·
`internal/ui/ui.go` (Ports je Dienst für das Mimikbild)

**Stufe 2 — Entwurf 1 als Schicht über allem.** Statusleiste und Konsolen-Echo
als zwei neue Teilvorlagen, die jede Seite erbt. Damit gilt Grundsatz I sofort
überall, ohne dass eine einzige Seite umgebaut wird. Die Befehlspalette kommt
zuletzt und bleibt optional.
`internal/ui/templates/partials.html` · `internal/ui/static/` (neu) ·
`internal/privops` (Befehl, Exit, Dauer protokollieren)

**Stufe 3 — Entwurf 2 für die Arbeitsseiten.** Dienste zuerst, dann Firewall,
dann Dateien und Systembenutzer. Die Auswahl steht in der URL
(`/services?unit=nginx.service`), damit es ohne JavaScript trägt und Links
teilbar bleiben. Die Änderungsablage lohnt zuerst bei der Firewall.
`internal/ui/templates/services.html`, `firewall.html`, `files.html`,
`sysusers.html` · neue Teilvorlage „inspektor“

## Vorher zu klären

- **Zielgruppe.** Ist das Panel auch für Leute ohne SSH-Erfahrung gedacht,
  verliert Entwurf 1 an Gewicht und Entwurf 3 gewinnt.
- **Zahl der Module.** Bei acht bis zehn trägt das waagerechte Menü aus
  Entwurf 3; ab zwölf braucht es die Schiene aus 1 oder 2.
- **Konsole mit Eingabe?** Als reines Echo ist sie harmlos. Als Terminal ist sie
  ein eigenes Modul mit eigener Sicherheitsbetrachtung.
