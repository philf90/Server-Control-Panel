# Protokoll des Serverlaufs zu `v0.6.0-rc.20`

**Der Lauf steht in `docs/65`.** Dieses Dokument füllt sich **während** des
Laufs, Punkt für Punkt — nicht danach.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

**Stand: noch nicht gefahren.** Was unten steht, sind die Felder, nicht die
Ergebnisse. Eine Zeile ohne Zahl ist eine offene Zeile und kein erfüllter Punkt.

---

## 0. Der Rahmen

| | |
|---|---|
| Fassung | *(`readlink -f /opt/srvpanel/current`)* |
| Server | |
| Abonnement | |
| Systembenutzer | |
| Browser | |
| Gefahren am | |
| Stand des Messmittels | *(`stand` aus `bilderMessen()`)* |

---

## 1. Die Punkte

Je Punkt: die gemessene Zahl, das Bild, und bei einer Abweichung der Befund mit
dem, was er über den Prüfling **oder über das Prüfmittel** sagt.

| # | Punkt | erwartet | gemessen | |
|---|---|---|---|---|
| 1 | Ein Wurzelelement | `1` | | |
| 2 | Rückmeldungen deutsch | keine Bezeichner | | |
| 3 | Meldung der Experteneingabe | „Im Ausdruck fehlt der 4. Teil (Monat)." | | |
| 4 | Kontingentauskunft oben | `oben` ≈ 18 | | |
| 5 | „Job anlegen" bei 1440 px | Zeitplan in voller Breite | | |
| 6 | Griff zum Formular | springt, Formular leer | | |
| 7 | Zielbaum im Bild | `oben` ≥ 0 | | |
| 8 | Schlüssel erzeugen und anmelden | Anmeldung gelingt, Fremdschlüssel abgewiesen | | |
| 9 | Suchleiste | ab 720 px da, Pfad sichtbar, Inhalt übertragen | | |
| 10 | Kopfleiste am Telefon | eine Zeile, vier ganze Wörter | | |
| 11 | Gegenprobe des Laufs | `dokument` ≫ 0 | | |

---

## 2. Die Befunde

*(Je Befund: was gesehen wurde, welche Zahl dazugehört, und ob er am Panel, am
Prüfmittel oder am Kriterium hängt. Die Aufteilung ist der Punkt — in vier
Läufen davor steckte die Mehrheit nicht im Prüfling.)*

---

## 3. Was offen bleibt

*(Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.)*

Aus `docs/65 §12` schon vor dem Lauf benannt:

- `PasswordFields.vue generate touched` — ungemessen.
- Die Suche im ganzen Abonnement gibt es nicht.
- Die gestapelten Knopfreihen der anderen Seiten sind nicht gemessen.
- RSA und ECDSA werden nicht erzeugt.
