# Abnahmelauf 0.3.1 — der Nachweis, der vor P4 fehlt

Dieses Dokument ist der Prüfweg für `0.3.1-rc.3` auf einem echten Server. Es
steht hier und nicht im Sitzungsfenster, weil ein Abnahmelauf, dessen Anleitung
mit dem Fenster verschwindet, beim zweiten Mal neu erfunden wird — und beim
zweiten Mal anders aussieht als beim ersten.

Alles darin ist im Quelltext nachgesehen: Schwellen aus
`OverviewController::series()`, Sitzungswerte aus `PanelProvision`,
Seitengrösse aus `App\Support\Web\Page`.

---

## 1. Warum dieser Lauf vor P4 kommt und nicht danach

P4 fasst drei Stellen an: das Zertifikat der Oberfläche, den Server-Block des
Panels und die Vorlage der Kundendomains. Das sind genau die Stellen, an denen
der Optik-Rework nie unter echten Bedingungen gelaufen ist — abgenommen wurde
P3 aus `0.3.0~rc.5`, und seither hat sich `app/` geändert.

**Ohne diesen Nachweis ist bei jedem Fehlschlag in P4 offen, ob P4 ihn
verursacht hat oder ob er schon vorher dalag.** Diese Zweideutigkeit kostet mehr
als der Lauf. Der Plan verlangt den Nachweis ohnehin auf einem echten Server und
nicht als Schätzung (§8 und §9).

Was konkret ungeprüft ist, steht in `docs/32 §1`: ob die Schwellen der
Verlaufskacheln unter echter Last greifen, und wie Übersicht und Zertifikatsseite
mit laufenden Diensten und einem echten Zertifikat aussehen. Für die Kacheln sind
bisher nur Messwerte von Hand in den Ringpuffer geschrieben worden.

---

## 2. Vorbereitung — und die Falle, die vor allem anderen kommt

**`srvpanel update` schreibt `/etc/srvpanel/panel.env` nicht neu.** Die Datei
entsteht bei `panel.provision`, also bei `srvpanel setup`. Wer von `0.3.0` nur
aktualisiert hat, läuft weiter mit den alten Sitzungswerten — und dann ist der
Befund aus Abschnitt E über die Sitzung auf dem Telefon nicht der von `rc.3`,
sondern der von `0.3.0`.

Zuerst also nachsehen, was tatsächlich dasteht:

```bash
grep -E 'SESSION_SAME_SITE|SESSION_LIFETIME' /etc/srvpanel/panel.env
```

Erwartet werden die Werte aus `PanelProvision`:

```
SESSION_SAME_SITE=lax
SESSION_LIFETIME=480
```

Steht dort `strict` oder `120`, ist die Datei die alte. Dann entweder die zwei
Zeilen von Hand ändern und `systemctl restart srvpanel-fpm.service` — oder den
Lauf gleich auf einer frisch eingerichteten Maschine fahren. **Der Unterschied
gehört ins Ergebnis**, denn er entscheidet, ob Abschnitt E überhaupt `rc.3`
prüft.

Ausserdem gebraucht: ein Kunde, ein Plan mit mindestens zwei freigegebenen
PHP-Versionen, und ein Konto, mit dem man sich als Kunde anmelden kann. Der
Abnahmelauf aus Abschnitt B legt sonst nichts an.

---

## 3. Die Reihenfolge — und warum sie so ist

Erst das Maschinelle, dann das Sichtbare, zuletzt das Telefon. Die
automatisierten Läufe bauen sich selbst zurück; alles danach sieht sich einen
Server an, der wieder im Ruhezustand ist. Umgekehrt sähe man die Kacheln unter
einer Last, die aus dem Abnahmelauf stammt, und wüsste am Ende nicht, was man da
gemessen hat.

### A — Fassung bestätigen

```bash
dpkg -l | grep srvpanel
srvpanel --version 2>/dev/null || php /opt/srvpanel/current/artisan --version
```

Es muss `0.3.1~rc.3` sein. Läuft der Lauf gegen eine andere Fassung, prüft er
etwas anderes, als hier steht.

### B — Die beiden bestehenden Abnahmeläufe

Sie sind die einzigen Stellen, an denen `app/` mit echtem nginx, echtem PHP-FPM
und echten Rechten zusammenkommt.

```bash
srvpanel acceptance --force
srvpanel acceptance-web --force
srvpanel tls
```

- `acceptance` legt N Abonnements an, baut sie zurück und sucht nach Rückständen
  (P2-Kriterium).
- `acceptance-web` legt zwei Abonnements mit je drei Domains und zwei
  PHP-Versionen an und weist nach, dass keines an die Dateien des anderen kommt
  (P3-Kriterium). Er ist der teure, und er ist der, auf den es ankommt.
- `srvpanel tls` sagt, welche Namen im Zertifikat stehen und ob es noch gilt.

**Was ein Fehlschlag hier bedeutet:** Nicht die Optik ist kaputt, sondern etwas
aus `app/`, das der Rework mitgenommen hat. Das ist genau der Befund, wegen dem
dieser Lauf vor P4 steht.

### C — Die Schwellen der Verlaufskacheln, unter echter Last

Drei Kacheln tragen eine Schwelle (`OverviewController`, Zeilen 251–253):

| Kachel | Schwelle | woher |
|---|---|---|
| CPU | 85 % | fester Wert |
| RAM | 85 % | fester Wert |
| Load | Anzahl der Kerne | `nproc` dieses Servers |

Gewarnt wird, wenn **der letzte** Messwert über der Schwelle liegt
(`Store::build()`, `'warns'`), nicht der höchste. Die Kurve wechselt dann die
Farbe — das ist das Merkmal, wegen dem „Kontor" gewählt wurde, und es ist beim
Umbau schon einmal verlorengegangen, ohne dass es jemandem auffiel.

CPU und Load zusammen lassen sich ohne zusätzliches Paket auslösen:

```bash
nproc                                        # die Schwelle der Load-Kachel
for i in $(seq "$(nproc)"); do yes >/dev/null & done
```

Zweieinhalb Minuten laufen lassen — der Sammler misst alle zehn Sekunden, und
die Kachel braucht ein paar Stützstellen, damit man eine Kurve und nicht einen
Punkt sieht. In der Zeit die Übersicht offen halten und ansehen. Danach:

```bash
pkill -x yes
```

`pkill -x` trifft nur Prozesse, die genau `yes` heissen — kein Muster, das
sonst noch etwas erwischt.

**Zwei Dinge, die dabei ehrlich dazugehören.** Erstens kostet das alle Kerne für
zweieinhalb Minuten; auf einem Server mit Kundenlast gehört es in eine ruhige
Stunde. Zweitens: **RAM nicht mit Gewalt auf 85 % treiben.** Der naheliegende
Weg dorthin endet beim OOM-Killer, und der sucht sich sein Opfer selbst. Die
RAM-Kachel läuft durch denselben Code wie die CPU-Kachel, mit demselben Wert —
was CPU zeigt, zeigt sie auch. Wenn RAM ohnehin über 85 % steht, ist das der
bessere Beleg als jeder erzwungene.

Zu prüfen:

1. CPU-Kurve wechselt in die Warnfarbe, solange die Last läuft.
2. Load-Kurve ebenso, sobald sie über die Kernzahl steigt (dauert länger als
   CPU — der Load-Durchschnitt zieht nach).
3. Nach `pkill` fallen beide binnen weniger Messungen zurück und die Farbe geht
   mit.
4. Die Zahl in der Kachel und die Kurve widersprechen sich nicht.

### D — Übersicht und Zertifikat mit echten Daten

Auf `/` (als Betreiber):

- Die drei Dienste stehen mit ihrem wirklichen Zustand da:
  `srvpanel-agentd.service`, `nginx.service`, `mariadb.service`.
- Prozessliste, Dateisysteme, Uptime sind gefüllt und nicht „noch keine
  Messwerte".
- Der Punkt am Ende jeder Kurve ist **rund** und nicht flachgedrückt oder halb
  abgeschnitten. Das ist der Fehler, der zuletzt gemeldet wurde; belegt ist er
  bisher nur durch `SparklineShapeTest` und ein Bild.

Auf `/settings/tls`:

- Name, Aussteller, Laufzeit und die abgedeckten Namen stehen da.
- **Die Adresse, unter der du gerade im Panel bist, ist abgedeckt** — sonst
  warnt die Seite, und das ist eine Auskunft, die vor P4 stimmen muss: P4 baut
  auf `Names` auf.
- `self_signed` ist gesetzt. Nach P4 steht hier das Gegenteil; heute wäre alles
  andere ein Fehler.

### E — Telefon: Sitzung, gestapelte Kacheln, kein Überlauf

Mit einem echten Telefon, nicht mit dem Emulator — der Punkt der Übung ist, dass
zwei der letzten Fehler nur auf fremder Hardware sichtbar waren.

1. Anmelden, eine Seite weiterklicken, **das Telefon sperren, fünf Minuten
   warten, entsperren, weiterklicken.** Die Sitzung muss stehen. Genau das tat
   sie mit `SESSION_SAME_SITE=strict` nicht (siehe Abschnitt 2).
2. Übersicht, Abonnements, Domains, Vorgänge, Protokoll durchgehen: Die Kacheln
   stapeln sich, nichts läuft waagerecht über.
3. Menü öffnen: Jeder Eintrag trägt sein Zeichen, die Trennüberschriften heben
   sich ab.

### F — Was ein Kunde sieht

Als Kunde anmelden (nicht „Anmelden als" — das ist ein anderer Weg durch die
Rechteprüfung, und geprüft werden soll der gewöhnliche).

- Auf `/subscriptions` steht **kein** Knopf ausser „Domain anlegen" am
  Abonnement.
- Der Menüpunkt **Domains** ist da, sofern ein aktives Abonnement existiert —
  und fehlt, wenn keines existiert.
- Die Abkürzung „Domain anlegen" führt bei genau einem Abonnement direkt hin,
  bei mehreren auf eine Auswahl.

`AbilityReachTest` prüft die Kette im Container; hier wird geprüft, dass die
Fahnen mit echten Daten auch ankommen.

### G — Blättern

Ein Verzeichnis mit mehr als 50 Zeilen (`Page::SIZE`) — nach Abschnitt B ist das
Protokoll unter `/audit` das mit Abstand längste. Zweite Seite öffnen, einen
Filter setzen, weiterblättern: **Der Filter muss die Seitenzahl überleben**
(`withQueryString`).

---

## 4. Der Ergebnisblock

Zurück kommt das hier, ausgefüllt — nicht „lief durch", sondern was dastand:

```
Fassung:                     0.3.1~rc.3 / andere: ...
panel.env vor dem Lauf:      SAME_SITE=... LIFETIME=...
srvpanel acceptance:         ok / Fehler: ...
srvpanel acceptance-web:     ok / Fehler: ...
srvpanel tls:                Namen: ...
C  CPU-Kurve warnt:          ja / nein
C  Load-Kurve warnt:         ja / nein  (nproc = ...)
C  Farbe geht zurück:        ja / nein
D  Dienste/Prozesse/FS:      ok / ...
D  Punkt am Kurvenende:      rund / platt
D  /settings/tls:            Adresse abgedeckt: ja / nein
E  Sitzung nach 5 min:       steht / weg
E  Überlauf bei 390 px:      keiner / bei: ...
F  Kundensicht:              ok / Knopf zuviel: ...
G  Blättern mit Filter:      ok / ...
```

---

## 5. Was danach passiert

**Grün:** P4 beginnt, und jeder spätere Fehlschlag hat einen Ausgangspunkt, an
dem er nicht gelegen haben kann.

**Rot:** Der Befund wird zuerst behoben, und zwar auf einem eigenen Zweig — ein
Fehler aus dem Rework, den P4 mitschleppt, wird in P4 gesucht.

**Teilweise rot in den Abschnitten C bis G:** Das ist Optik und nicht
Fundament. Es geht in den CHANGELOG und wird eingeplant, hält P4 aber nicht auf
— mit einer Ausnahme: `/settings/tls` und `srvpanel tls` (Abschnitt D) sind das
Fundament von P4 selbst. Was dort nicht stimmt, wird vorher behoben.
