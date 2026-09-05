# Der Abnahmelauf für A14 — die Ankündigungen im Panel

Acht Punkte auf `cloudsrv24`, gegen die Fassung, die dort installiert ist. Das
Kriterium steht in `docs/103 §8`; dieses Dokument macht daraus eine
Befehlsfolge. **Punkt 3 und Punkt 6 dürfen nicht ausfallen** — sie sind die
beiden, für die es diese Stufe gibt.

Ausgeschrieben **vor** dem Fahren, aus demselben Grund wie `docs/99`:

> **Ein Abnahmelauf, der eine ungeprüfte Annahme als Anweisung führt, prüft sie
> nicht — er führt sie aus.**

---

## 0 · Was vor dem Lauf gelesen wird

### Punkt 4 ist berichtigt — das Kriterium war nicht erfüllbar

Es lautete: „im Streifen zwei Zeilen, **Verweis auf den vollen Text**, der Text
vollständig auf `/announcements`". Beim Vorflug am 5. September fiel auf, dass
der Prüfling das nicht erfüllen kann, und zwar doppelt: Der Verweis war nie
gebaut — und `/announcements` steht dreimal hinter `can:operate-server`. Kunde,
Administrator und der Unangemeldete auf der Anmeldeseite hätten dort einen 403
bekommen, also genau die drei Gruppen, für die der Verweis da war.

> **Ein Verweis auf einen Ort, den der Leser nicht betreten darf, ist kein Weg
> zum Text — er ist eine zweite Sackgasse.**

Gebaut ist seitdem die Leseseite `GET /announcements/{id}` (PR #215). Punkt 4
misst sie jetzt **aus der Sicht eines Kunden**, denn das ist der Fall, den die
alte Fassung verschwiegen hätte.

### Zwei Zahlen sind offen, und eine davon entscheidet dieser Lauf

**Die Höhe der Hülle.** `docs/103 §8` nennt 214 px für drei Bänder bei 390 px,
aus einer Messung an der echten Seite. Der Vorflug hat gegen das gebaute
Stylesheet **226 px** gemessen. Welche gilt, sagt der Server.

Getragen ist davon die **Eigenschaft** und nicht der absolute Wert: Bei 390 px
ergaben 60, 120, 250 und 500 Zeichen alle dieselben 62 px je Band, und `div`
gegen `a` in acht Lagen Zeile für Zeile dasselbe.

> **Eine Differenz zweier Messungen unter denselben Bedingungen trägt, auch
> wenn die absoluten Werte an der Umgebung hängen.**

**Und ein Prüfkörper des Vorflugs hat danebengemessen**, weil er mit 32 Zeichen
unterhalb der Spanne lag, die das Kriterium nennt (60 bis 500): Er ergab 41 px
statt 62 und sah nach einem Widerspruch aus.

> **Ein Prüfkörper, der ausserhalb der Spanne liegt, die das Kriterium nennt,
> prüft eine andere Frage.**

### Was gegen MariaDB noch nie gemessen wurde

Alle Prüfungen von A14 laufen gegen SQLite. Drei Dinge hängen an der echten
Datenbank und sind hier zum ersten Mal auf einem Server zu sehen:

1. **`whereJsonContains('audiences', …)`** — die Publikumsfrage. SQLite und
   MariaDB beantworten sie über verschiedene Wege.
2. **Die beiden `dateTime`-Spalten.** Die Migration nennt den Grund, aus dem
   sie nicht `timestamp` sind (`explicit_defaults_for_timestamp`); gesehen hat
   das dort noch niemand.
3. **Der Vergleich des Fensters in UTC**, mit einer Anzeigezone daneben, die
   einen Versatz hat.

### Was auf dem Server vorher stimmen muss

```
srvpanel version
curl -sS -o /dev/null -w '%{http_code}\n' https://<panel>/health
systemctl is-active srvpanel-agentd srvpanel-worker
php /opt/srvpanel/current/artisan tinker --execute='echo Schema::hasTable("announcements") ? "Tabelle da" : "Tabelle fehlt";'
```

Fehlt die Tabelle, trägt diese Maschine A14 nicht und der Lauf ist nicht
fahrbar. **Die Fassung wird notiert**, bevor irgendetwas gemessen wird — ein
Protokoll ohne sie sagt über den Prüfling nichts.

> **Der Prüfling einer Aktualisierung ist die installierte Fassung und nicht
> die eingespielte.** (`docs/96 §1b`)

### Wie ein Punkt gemessen wird

Dieselbe Form wie in `docs/99`: **Zustand herstellen, belegen, dass er da ist,
messen, lesen, zurückbauen, noch einmal messen.** Der zweite Blick ist kein
Aufräumen, sondern der Beleg, dass die Anzeige den Zustand *misst* und nicht
*behält*.

**Angelegt wird über die Oberfläche und nicht über `tinker`.** Es gibt kein
`srvpanel announcements`, und das ist Absicht: Was der Lauf prüfen soll, ist
der Weg, den der Betreiber wirklich geht. `tinker` wird nur zum **Nachsehen**
benutzt.

Zum Nachsehen genügt dabei die schlichte Abfrage — `Announcement` trägt
**keine** Mandantenklammer (die Migration nennt den Grund), also braucht es
hier kein `withoutGlobalScopes()`:

```
php /opt/srvpanel/current/artisan tinker --execute='
  foreach (App\Models\Announcement::all() as $a) {
      printf("%d %-8s von=%s bis=%s %s%s",
          $a->id, $a->category->value,
          $a->visible_from?->toDateTimeString() ?? "—",
          $a->visible_until?->toDateTimeString() ?? "—",
          implode(",", $a->audiences ?? []), PHP_EOL);
  }'
```

Die Zeiten stehen dort in **UTC** — das ist die Ablage. Was die Seite zeigt,
ist die Anzeigezone, und genau der Unterschied ist Punkt 6.

### Die Bilderrunde

Gemessen wird mit `tests/bilder-messen.js` aus dem Repo: in die Konsole des
Browsers einfügen, je Lage `bilderMessen()` aufrufen. **Vier Lagen** — 390 ×
844 und 1440 px, je hell und dunkel.

Zwei Dinge, die schon einmal eine Messung gekostet haben:

- **Vor jeder Messung neu laden.** Ein Prüfkörper, der sich am gegenwärtigen
  Zustand bemisst, misst beim zweiten Lauf ohne Neuladen sich selbst (400 statt
  200, `docs/96 §8`).
- **Das Thema wird über den Umschalter der Seite gestellt und nicht über
  `prefers-color-scheme`.** `app.css` kennt keine solche Regel; es hängt allein
  an `data-theme` (`docs/84`). Wer es anders stellt, hat zwei helle Läufe und
  ein Bild, das wie ein Ergebnis aussieht.

Die Gegenprobe **muss** 200 ergeben. Ein anderer Wert heisst, dass die Messung
nichts misst — und dann bedeuten ihre Nullen nichts.

---

## 1 · Punkt 1 — eine Ankündigung erscheint

**Herstellen.** Als Betreiber auf `/announcements`: Kategorie **Info**, Text
`Wartungsfenster heute 22:00 Uhr.`, kein Fenster, Publikum **Betreiber**.

**Lesen.** Irgendeine Seite des Panels aufrufen.

**Erwartet.** Ein Streifen ganz oben; er beginnt mit dem Wort **Info**; die
Fläche trägt die Marke `ok`. Der Rang steht auf drei Trägern — Fläche, Rand
links (3 px), Textfarbe.

**Notiert.** Ein Bild bei 1440 px, hell.

---

## 2 · Punkt 2 — drei gleichzeitig, und keiner verdeckt

**Der Punkt braucht die breite Ansicht.** Der Befund aus M2 zeigt sich bei
1440 px und **nicht** bei 390 px — dort stapeln dieselben drei korrekt.

> **Ein `grid-row`, das ein Element ausdrücklich nimmt, nimmt jedes
> Geschwister in dieselbe Zelle — und „mehrere gleichzeitig" heisst dann
> „übereinander".**

**Herstellen.** Zwei weitere anlegen: **Warnung** und **Störung**, beide
Publikum **Betreiber**, kurzer Text.

**Erwartet.** **Drei** Streifen untereinander, jeder lesbar, keiner verdeckt.
Reihenfolge nach Rang: Störung, Warnung, Info.

**Notiert.** Ein Bild bei 1440 px, und die Zahl der sichtbaren Bänder.

---

## 3 · Punkt 3 — die Höhe hängt nicht an der Textlänge *(Ausschluss)*

**Gemessen wird eine Eigenschaft und nicht eine Zahl.** Die Zahl steht daneben,
damit ein Ausreisser auffällt.

**Herstellen.** Den Text der drei auf **60 Zeichen** setzen. Messen. Dann auf
**500 Zeichen** setzen. Wieder messen.

**Erwartet bei 390 px:**

| | 60 Zeichen | 500 Zeichen |
|---|---|---|
| Höhe je Band | X | **dasselbe X** |
| Höhe der Hülle | Y | **dasselbe Y** |
| `schiebt` | 0 | 0 |
| Gegenprobe | 200 | 200 |

Der Vorflug erwartet X = 62 px. **Y ist offen** — 214 oder 226; dieser Lauf
entscheidet es, und der Wert wird in `docs/103 §8` nachgetragen.

**Der Punkt fällt aus**, wenn die beiden Höhen sich unterscheiden. Die Zahl
allein ist kein Ausfall; sie ist eine Beobachtung.

---

## 4 · Punkt 4 — 500 Zeichen, und ein Kunde kommt an den vollen Text

**Herstellen.** Eine Ankündigung mit **500 Zeichen** und Publikum **Kunde**.

**Lesen — als Kunde.** Mit einem Kundenkonto anmelden (oder „Anmelden als").

**Erwartet, der Reihe nach:**

1. Der Streifen zeigt **zwei Zeilen** und schneidet den Rest ab.
2. Ein Klick auf das Band führt auf `/announcements/<id>`.
3. Dort steht der Text **vollständig und ungeklammert**.
4. **Kein 403.** Das ist der Kern dieses Punktes.

**Gegenprobe, und sie gehört dazu:** Als derselbe Kunde eine Ankündigung
aufrufen, deren Publikum **Betreiber** ist (die Kennung steht in der
`tinker`-Ausgabe). Erwartet ist **404** — nicht 403, denn ein 403 bestätigte
die Existenz.

---

## 5 · Punkt 5 — das Publikum trennt

**Herstellen.** Zwei Ankündigungen mit **verschiedenem** Text: eine mit
Publikum **Kunde**, eine mit Publikum **Betreiber**.

**Erwartet.** Das Kundenkonto sieht **genau eine** — die für Kunden. Das
Betreiberkonto sieht **genau eine** — die für Betreiber. Beide Richtungen
gehören ins Protokoll; die stille Hälfte ist die, die zuviel zeigt.

**Und hier zeigt sich `whereJsonContains` gegen MariaDB zum ersten Mal.**
Antwortet es anders als SQLite, sieht das Kundenkonto entweder nichts oder
alles — beides fällt hier auf.

---

## 6 · Punkt 6 — das Fenster, mit einem Versatz *(Ausschluss)*

**Ohne Versatz misst dieser Punkt nichts.** In UTC sind der richtige und der
falsche Vergleich gleich; eine fehlende Umrechnung sähe aus wie eine gelungene
(M7).

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

**Vorbereiten.** Auf `/settings/general` die Anzeigezeitzone auf
**`Europe/Berlin`** stellen. Belegen, dass sie steht — die Zeiten auf
`/announcements` müssen sich um den Versatz verschoben haben (im September
UTC+2).

**Drei Fälle, alle in Ortszeit eingetippt:**

| | Fenster | erwartet |
|---|---|---|
| a | von *jetzt − 5 min* bis *jetzt + 5 min* | **sichtbar** |
| b | von *jetzt + 30 min* bis *jetzt + 40 min* | nicht sichtbar |
| c | von *jetzt − 40 min* bis *jetzt − 30 min* | nicht sichtbar |

**Fall a trägt den Punkt.** Rechnete der Filter in der Anzeigezone statt in
UTC, wäre die Ankündigung genau während ihrer eigenen Laufzeit unsichtbar — und
b und c sähen trotzdem richtig aus.

**Dazu die Ablage nachsehen:** `tinker` muss für Fall a eine `visible_from`
zeigen, die **zwei Stunden vor** der eingetippten Ortszeit liegt. Steht dort
die Ortszeit, ist die Umrechnung an der Eingabe ausgefallen — derselbe Fehler,
der am 4. September die Endzeit des Wartungsmodus nie beim Agenten ankommen
liess.

**Zurück.** Die Anzeigezeitzone auf ihren vorigen Wert stellen und das
notieren.

---

## 7 · Punkt 7 — die Anmeldeseite trägt Störungen und sonst nichts

**Herstellen.** Je eine Ankündigung der Kategorie **Störung** und **Info**, in
beiden Fällen Publikum **Betreiber** (das Publikum spielt hier keine Rolle —
`onLoginPage()` filtert die Kategorie und **nicht** das Publikum, und genau das
soll sichtbar werden).

**Lesen.** Abmelden. `/login` aufrufen.

**Erwartet.** Die **Störung** steht oben. Die **Info** steht dort **nicht**.

**Und der zweite Schritt:** Auf das Band klicken. Erwartet ist die Leseseite
mit dem vollen Text — **unangemeldet**, mit dem Verweis „Zur Anmeldung"
darunter. Danach dieselbe Kennung wie in Punkt 4b, also eine Ankündigung, die
keine Störung ist: erwartet **404**.

> **Was auf der Anmeldeseite steht, steht vor jedem, der die Adresse kennt.**

**Notiert.** Ein Bild von `/login` bei 390 px — dort ist der Streifen zuletzt
mit einem eigenen Befund aufgefallen (die Seite rollte senkrecht um genau seine
Höhe).

---

## 8 · Punkt 8 — das partielle Nachladen

**Herstellen.** Mindestens eine sichtbare Ankündigung, angemeldet als
Betreiber.

**Lesen.** Auf die Übersicht (`/`) gehen und den Selbstlauf abwarten — sie lädt
`only: ['tiles']` nach.

**Erwartet.** Der Streifen steht danach **unverändert** da.

**Warum der Punkt existiert:** `announcements` ist in `share()` ein
**Verschluss** und kein fertiger Wert. Ein fertiger Wert würde bei jedem
partiellen Nachladen berechnet, das ihn gar nicht mitschickt (M5, gemessen an
den Abfragen: voll 2, partiell 1).

> **Ein fertiger Wert in `share()` läuft bei jeder Anfrage, auch bei einer, die
> ihn gar nicht mitschickt.**

Verschwindet der Streifen, ist der Verschluss nicht angekommen; bleibt er, ist
beides in Ordnung — der Server schickt ihn nicht, und der Klient hält ihn.

---

## 9 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Die Zustellung an ein Abonnement.** A14 adressiert nach Publikum und nicht
  nach Kunde; das steht als Nicht-Ziel in `docs/103 §10`.
- **Ein Wegklicken.** Der Betreiber hat es nicht bestellt; es gäbe einen
  Zustand je Betrachter.
- **Eine Automatik am Fenster.** Es ist ein Filter beim Lesen und kein
  Zeitgeber — genau der Unterschied, der A12 seine Automatik gekostet hat.
- **Die Kontrastwerte.** Die Messrunde hat alle zwölf zwischen 5,40:1 und
  9,21:1 gemessen; sie hängen an `app.css` und nicht am Server.
- **Den vollen Bruchlauf.** Er ist in der CI gefahren („Alle Wächter beissen",
  PR #215).

---

## 10 · Wann er durch ist

**Alle acht Punkte erfüllt**, und **3 und 6 dürfen nicht ausfallen**. Fällt
einer der übrigen als „nicht herstellbar" aus, wird er benannt und nicht
stillschweigend übergangen.

Dazu gehört ins Protokoll:

- die **Fassung**, gegen die gemessen wurde,
- die **Hüllenhöhe** aus Punkt 3, damit `docs/103 §8` sie bekommt,
- jede Messung mit ihrer **Gegenprobe** (200),
- die vier Bilder der Bilderrunde,
- und was danach **offen bleibt**.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

Der Prüfstand wird abgeräumt und das wird belegt: alle Prüf-Ankündigungen
gelöscht, die Anzeigezeitzone zurück auf ihren vorigen Wert, `tinker` zeigt
danach die Zahl, die vorher dastand.
