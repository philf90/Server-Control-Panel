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

---

## 3. Punkt 6a — erfüllt, mit der Gegenprobe im selben Bild

Gemessen auf `/accounts` am Telefon, dunkles Thema:

| Konto | Rolle | Marke | Knöpfe |
|---|---|---|---|
| Administrator (`philipp@netzhost24.de`) | Betreiber | **letzter** | nur *Bearbeiten* |
| Dritte Verwaltung (`test@homesrv24.de`) | Administrator | — | *Bearbeiten*, **Löschen** |

**Die zweite Zeile ist das, was die erste zu einer Messung macht.** „Kein
Löschknopf" allein liesse offen, ob die Regel greift oder ob der Knopf
überhaupt nirgends steht — etwa nach einem halben Bau. Beide Zustände auf
demselben Bildschirm schliessen das aus.

> **Eine Abwesenheit ist nur dann ein Befund, wenn die Anwesenheit im
> Erfolgsfall daneben steht.**

Nebenbei belegt: `is_last_operator` folgt der **Rolle** und nicht dem Kontotyp.
„Dritte Verwaltung" ist aktiv und Admin, aber kein Betreiber, zählt also nicht
in `LastOperator::active()` — und trägt deshalb zu Recht weder die Marke noch
den fehlenden Knopf.

### 3.1 Was das Telefonbild verdeckt hat — die Liste hat fünf Zeilen

Am Rechner nachgesehen (1440 px, dunkles Thema, dieselbe Seite): Die Kopfzeile
sagt **„5 für die Verwaltung dieses Servers"**. Das Telefonbild zeigte die
ersten beiden.

| Konto | Adresse | Rolle | Zustand | 2. Faktor | letzte Anmeldung | Marke | Knöpfe |
|---|---|---|---|---|---|---|---|
| Administrator | `philipp@netzhost24.de` | Betreiber | aktiv | eingerichtet | 2026-09-10 19:53:33 | **letzter** | nur *Bearbeiten* |
| Dritte Verwaltung | `test@homesrv24.de` | Administrator | aktiv | noch nicht | noch nie | — | *Bearbeiten*, *Löschen* |
| Neu von Hand | `neu@cloudlab24.de` | **Betreiber** | **deaktiviert** | noch nicht | noch nie | — | *Bearbeiten*, *Löschen* |
| Wegwerf | `wegwerf@cloudlab24.de` | **Betreiber** | **deaktiviert** | noch nicht | 2026-08-25 12:36:53 | — | *Bearbeiten*, *Löschen* |
| Zweite Verwaltung | `philipp@homesrv24.de` | Administrator | aktiv | eingerichtet | 2026-09-08 21:02:04 | — | *Bearbeiten*, *Löschen* |

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.** Zum sechsten Mal in diesem Repo. Gefragt
> war „Marke und Knöpfe", geantwortet hat das Bild darauf; dass die Liste
> weitergeht, stand ausserhalb der Frage und ausserhalb des Ausschnitts.

**Punkt 6a bleibt erfüllt** — die beiden Zeilen sind richtig abgelesen. Falsch
war nicht die Messung, sondern der Eindruck daneben, die Liste sei damit
vollständig.

**Und die vollständige Liste trägt eine Gegenprobe, die die kurze nicht hatte.**
„Dritte Verwaltung" trennt nur die **Rolle** — Admin, kein Betreiber. Die
beiden deaktivierten Betreiber trennen den **Zustand**: Sie sind Betreiber und
tragen trotzdem einen Löschknopf, weil `LastOperator::isLast()` beide Hälften
fragt und ein deaktivierter Betreiber in `active()` nicht mitzählt.

> **Eine Gegenprobe, die nur eine der beiden Bedingungen umdreht, belegt die
> andere nicht.**

### 3.2 Befund 2 — der Hinweis nennt zwei von drei Wegen

Unter der Liste steht: *„Es gibt genau einen aktiven Betreiber. Er lässt sich
weder herabstufen noch sperren, solange er der letzte ist — sonst käme niemand
mehr an die Einstellungen dieses Servers."*

Seit `docs/901` gibt es einen **dritten** Weg, und `LastOperator` kennt ihn:
löschen. Der Satz nennt ihn nicht — und ausgerechnet er ist der, dessen Knopf
in der ersten Zeile **fehlt**. Der Kommentar über dem Satz sagt selbst, wozu er
da ist: *„Der Grund steht unter der Liste und nicht erst hinter der
Ablehnung."* Für zwei Wege löst er das ein, für den sichtbarsten nicht.

> **Ein Hinweis, der erklärt, was nicht geht, ist unvollständig, sobald ein Weg
> dazukommt — und die Lücke fällt niemandem auf, weil der Satz ja stimmt.**

Gemessen ist auch, dass es der **einzige** Satz der Seite ist: genau ein
`class="hint"` in `Accounts/Index.vue`. Für die fehlenden Knöpfe der **eigenen**
Zeile gibt es damit überhaupt keine Auskunft — weder für „das ist dein Konto"
noch für „du bist der letzte".

**Nicht während des Laufs behoben.** Eine Behebung ist eine Änderung, und jede
Änderung ist ein neuer Anlass zu messen; sie käme nach Punkt 11.

### 3.3 Befund 3 — der übergebene Messbefehl war gegen die falsche Fassung geschrieben

Der Befehl für Punkt 6b lautete
`JSON.parse(document.getElementById('app').dataset.page)` und ergab
`"undefined" is not valid JSON`. Das Element gibt es, das Attribut nicht.

Gemessen in `node_modules`, nicht überlegt: Dieses Panel fährt
**`@inertiajs/vue3 ^3.6.1`**, und Inertia 3 liefert die Ablage nicht mehr als
Attribut am Wurzelelement, sondern als eigenes Element —
`getInitialPageFromDOM` in `@inertiajs/core` sucht
`script[data-page="app"][type="application/json"]` und liest dessen
`textContent`. Der Befehl stammt aus der Zeit von Inertia 1/2.

> **Ein Prüfkörper, der gegen eine andere Fassung geschrieben ist als der
> Prüfling, misst nicht — und dass er gar nichts liefert, ist der gnädige
> Fall.**

**Der ungnädige stünde daneben, und deshalb ist auch das Script-Element die
falsche Quelle.** Es trägt die Seite, mit der das Dokument **geladen** wurde,
und Inertia navigiert danach ohne Neuladen. Wer auf `/accounts` klickt statt
die Adresse einzugeben, liest dort die Ablage der vorigen Seite — und die sieht
aus wie eine Messung.

> **Eine Ablage, die beim ersten Laden entsteht und bei jeder Navigation
> stehenbleibt, liefert nach dem zweiten Klick eine Messung der vorigen
> Seite.**

Gefragt wird deshalb die **lebende** Ablage: `plugin.install()` von
`@inertiajs/vue3` legt sie als `$page` in `app.config.globalProperties`, und
Vue hängt die Anwendung als `__vue_app__` an den Behälter, in den sie montiert
wurde (`rootContainer.__vue_app__ = app`, gemessen in
`runtime-core.cjs.prod.js` — also nicht nur im Entwicklungsbau — und die
Zeichenkette steht im gebauten Bündel `public/build/assets/app-*.js`).

**Und die Messung druckt ihre Herkunft mit:** `p.url` muss `/accounts` sein.
Ohne diese Zeile wäre die Verwechslung von oben nicht zu sehen.

**Nebenbei aus derselben Konsole:** Der Aufruf aus 6c lief mit dem
Platzhalter — `DELETE /accounts/%3Cid%3E` — und gab **404**. Das ist richtig
und kein Befund: `<id>` ist keine Kennung, die Bindung findet nichts, und die
Tür antwortet mit „gibt es nicht" statt mit einem Fehler. Über den Fall eines
**Kundenkontos** (Punkt 10) sagt das nichts; dort ist die Kennung gültig und
die Zeile existiert.

## 4. Punkt 6b — erfüllt, und die Ablage misst mehr als die Knöpfe

Gemessen auf `/accounts` bei 1440 px über die **lebende** Ablage:

```
Seite: /accounts · aktive Betreiber: 1
```

| # | id | name | self | letzter |
|---|---|---|---|---|
| 0 | 1 | Administrator | **true** | **true** |
| 1 | 10 | Dritte Verwaltung | false | false |
| 2 | 9 | Neu von Hand | false | false |
| 3 | 8 | Wegwerf | false | false |
| 4 | 7 | Zweite Verwaltung | false | false |

**Punkt 6b verlangt `self: true` und `letzter: true` in derselben Zeile.**
Beides steht da, und die vier übrigen Zeilen tragen in **beiden** Spalten
`false`.

**Die zweite Spalte ist dabei die schärfere Gegenprobe.** Wäre
`is_last_operator` an „ist Betreiber" gehängt statt an `LastOperator::isLast()`,
stünden „Neu von Hand" und „Wegwerf" auf `true` — sie **sind** Betreiber. Sie
stehen auf `false`, weil sie deaktiviert sind und in `active()` nicht
mitzählen. Die Hälfte der Regel, die §3.1 an den Knöpfen abgelesen hat, steht
damit auch in der Ablage.

**Und die Zahl daneben ist die dritte Quelle für dieselbe Tatsache:**
`operators: 1` aus dem Payload, der Satz unter der Liste, und `aktive
Betreiber: 1` aus dem Zustandsblock in §1. Alle drei kommen aus
`LastOperator::active()` — das ist keine dreifache Bestätigung, sondern der
Beleg, dass es **eine** Stelle ist.

Nebenbei: Die Kennungen sind nicht fortlaufend (1, 7, 8, 9, 10). Sortiert wird
nach `name`, nicht nach `id` — die Reihenfolge der Tabelle sagt nichts über das
Alter eines Kontos.

### 4.1 Befund 4 — ein Platzhalter, der in der Sprache des Prüfkörpers etwas bedeutet

Der übergebene Befehl für 6c lautete ``fetch(`/accounts/${<id>}`, …)`` und
endete mit `Uncaught SyntaxError: Unexpected token '<'`. Nicht der Prüfling,
sondern der Prüfkörper: `<id>` steht dort **innerhalb** eines
Template-Literals, und dort ist `<` JavaScript.

Die Fassung davor schrieb `'/accounts/<id>'` als gewöhnliche Zeichenkette.
Wörtlich eingefügt lief sie durch und gab `404` — harmlos und sichtbar falsch.
Dieselbe Marke, zwei Formen, zwei ganz verschiedene Ausgänge; geändert hat sich
nicht der Platzhalter, sondern die Syntax um ihn herum.

> **Ein Platzhalter, der in der Sprache des Prüfkörpers selbst etwas bedeutet,
> ist keiner — er ist ein Fehler, den erst der Einsetzende bemerkt.**

Ein Prüfkörper zum Einfügen trägt deshalb den **gemessenen** Wert und keine
Marke: `/accounts/1`.

**Und eine Meldung derselben Konsole gehört nicht zu diesem Panel:** *„A
listener indicated an asynchronous response by returning true, but the message
channel closed before a response was received"* kommt von einer
Browsererweiterung. Sie steht hier, damit sie später niemand als Befund
aufschreibt.

---

## 5. Befund 5 — der Prüfkörper nahm die Voreinstellung des Frameworks an

Der Aufruf aus 6c ergab **nicht** die erwartete `422`, sondern:

```
DELETE https://cloudsrv24.de:8443/accounts   405 (Method Not Allowed)
Uncaught SyntaxError: Unexpected token '<', "<!DOCTYPE "... is not valid JSON
```

**Die Adresse in der Meldung ist nicht die, die gerufen wurde.** Gerufen war
`/accounts/1`; gescheitert ist `/accounts`. Dazwischen liegt eine Weiterleitung,
und sie erklärt alles drei.

**Die Ursache steht in `bootstrap/app.php` und ist Absicht:**

```php
$exceptions->shouldRenderJsonWhen(
    fn (Request $request) => $request->is('api/*'),
);
```

Damit ist Laravels eigene Aushandlung abgeschaltet: `Accept: application/json`
entscheidet hier **nichts**. Eine `ValidationException` nimmt deshalb den
HTML-Weg — `redirect()->back()->withErrors(…)`, und `back()` ist dank
`RememberPageUrl` genau `/accounts`.

**Und `fetch` folgt dieser Weiterleitung mit derselben Methode.** Die Spezifikation
schreibt nur `POST` auf `GET` um; ein `DELETE` bleibt eines. Aus der abgewiesenen
Anfrage wurde also eine zweite, die niemand gestellt hat — `DELETE /accounts` —,
und dort gibt es nur `GET` und `POST`: **405**, HTML als Rumpf, und `r.json()`
stirbt am `<`.

> **Ein Prüfkörper, der die Voreinstellung des Frameworks annimmt, misst die
> Anwendung nicht — sie darf sie abgestellt haben.**

> **Eine Weiterleitung, der `fetch` folgt, macht aus einer abgewiesenen Anfrage
> eine zweite, die es nie gab — und deren Fehler liest sich wie der Befund.**

**Was damit belegt ist und was nicht.** Die `405` an einer Adresse, die niemand
gerufen hat, ist nur durch eine Weiterleitung nach `/accounts` erklärbar, und
die entsteht auf diesem Weg genau dann, wenn eine `ValidationException` fliegt.
Dass **eine** der beiden Prüfungen gegriffen hat, steht damit fest. **Welche**
und **mit welchem Satz**, steht nicht fest — und genau darauf zielt Punkt 6c.

> **Eine Spur ist kein Wortlaut.**

### 5.1 Der berichtigte Prüfkörper geht durch dieselbe Tür wie der Knopf

`docs/902 §8c` begründet den unmittelbaren Aufruf damit, die Route werde „durch
dieselbe Kette aus Middleware und Controller" gerufen, „nur ohne das
Bedienelement". Ein rohes `fetch` erfüllt das **nicht**: Der Knopf ruft
`router.delete(…)`, und Inertia setzt eigene Kopfzeilen, prüft die Fassung und
schreibt eine 302 auf einer schreibenden Methode zu **303** um — womit die
Weiterleitung als `GET` weiterläuft statt als `DELETE`.

Gerufen wird deshalb derselbe Klient, den die Seite benutzt:

```js
document.getElementById('app').__vue_app__.config.globalProperties.$inertia
  .delete('/accounts/1', {
    preserveScroll: true,
    onError: (e) => console.log('abgewiesen:', e),
    onSuccess: () => console.log('GELÖSCHT — das wäre der Befund'),
  })
```

Das ist Zeile für Zeile, was `loeschen(row)` in `Accounts/Index.vue` tut —
ohne den Bestätigungsdialog davor. `onError` bekommt die Ablage der Fehler;
erwartet wird dort `account` mit dem Satz der **Selbstprüfung**.

> **Ein Prüfkörper, der eine andere Form misst als die des Prüflings, misst die
> falsche.** Zum zweiten Mal in diesem Lauf, nach Befund 3 — und beide Male war
> die falsche Form die, die aussieht wie die neutralere.

---

## 6. Punkt 6c — erfüllt, und die Vorhersage aus §0.1 trifft zu

Gerufen mit dem Klienten der Seite, `$inertia.delete('/accounts/1')`:

```
abgewiesen:
  {account: 'Das eigene Konto lässt sich nicht löschen. Ein zweiter Betreiber kann es tun.'}
```

**Wort für Wort `AccountController::SELF_REFUSAL`** — und ausdrücklich **nicht**
`LastOperator::refusal()` („Das ist der letzte aktive Betreiber…"). Damit ist
gemessen, was `docs/902 §0.1` **vor** dem Fahren am Quelltext vorhergesagt hat:
An dieser Tür lassen sich die beiden Regeln nicht trennen, weil der letzte
aktive Betreiber nur das eigene Konto sein kann und die Selbstprüfung zuerst
antwortet.

> **Eine Vorhersage, die am Quelltext entsteht und an der Tür gemessen wird,
> ist etwas anderes als eine, die beides am selben Ort tut.**

**Die Gegenprobe steht im selben Bild:** Die Liste führt danach unverändert
fünf Konten. Der Aufruf ist abgewiesen worden und nicht etwa halb
durchgelaufen.

**Und der Weg selbst ist mitgemessen.** Die Meldung kam über `onError` an, also
hat Inertia die Antwort als Fehler einer schreibenden Anfrage behandelt — die
303-Umschreibung und die ganze Kette dahinter haben sich verhalten wie beim
echten Knopf. Der rohe `fetch` aus Befund 5 konnte das nicht zeigen.

**Punkt 6 ist damit vollständig** — 6a die Seite, 6b die Ablage, 6c die Tür,
alle drei in der Fassung aus `docs/902 §0.1`.

---

## 7. Punkt 7 — erfüllt, und hier trennen sich die beiden Punkte

Hergestellt mit einem Schalter statt einer Rollenänderung: „Wegwerf" ist
Betreiber und war deaktiviert; über *Bearbeiten* auf **aktiv** gesetzt, sind es
zwei aktive Betreiber.

**a) Die Seite.** Die eigene Zeile trägt weiterhin **keinen** Löschknopf — und
die Marke `letzter` ist **fort**. „Wegwerf" steht jetzt als `aktiv` da und
behält seinen Löschknopf.

**Und der Satz unter der Liste ist verschwunden.** Er hängt an
`v-if="props.operators <= 1"`, also an derselben Zahl wie die Marke. Beide sind
gemeinsam gegangen; wären sie zwei Fassungen derselben Frage, wäre genau hier
eine von ihnen stehengeblieben.

> **Zwei Anzeigen, die aus derselben Zahl folgen, belegen einander erst, wenn
> sie gemeinsam kippen.**

**b) Die Ablage.**

```
Seite: /accounts · aktive Betreiber: 2
```

| # | id | name | self | letzter |
|---|---|---|---|---|
| 0 | 1 | Administrator | **true** | **false** |
| 1 | 10 | Dritte Verwaltung | false | false |
| 2 | 9 | Neu von Hand | false | false |
| 3 | 8 | Wegwerf | false | false |
| 4 | 7 | Zweite Verwaltung | false | false |

Dieselbe Zeile wie in §4, ein Feld anders: `letzter` ist von `true` auf `false`
gekippt, `self` steht unverändert auf `true`. **Das ist die ganze Trennung
zwischen Punkt 6 und Punkt 7** — und sie ist nur hier zu sehen.

**c) Die Tür.**

```
abgewiesen:
  {account: 'Das eigene Konto lässt sich nicht löschen. Ein zweiter Betreiber kann es tun.'}
```

**Dieselbe Meldung wie bei Punkt 6, Zeichen für Zeichen** — obwohl sich der
Zustand dazwischen geändert hat. Genau das war die Vorhersage aus
`docs/902 §0.1`, und sie ist damit von **beiden** Seiten gemessen: bei einem
Betreiber und bei zweien.

> **Zwei Punkte, die dieselbe Meldung ergeben, sind nicht derselbe Punkt — aber
> sie sind es an der Stelle, an der man sie misst.**

### 7.1 Der Prüfling fürs Löschen steht fest — „Wegwerf" bringt seine Geschichte mit

```
Kennung: 8
Zeilen als Handelnder: 2
davon mit Abschrift: 2
Sitzungen: 0
```

**Beide Zeilen tragen die Abschrift, und beide sind älter als die Behebung.**
Wegwerfs letzte Anmeldung war der 25. August; `0.7.4-rc.1` steht seit heute auf
diesem Server. Ihr `account_name` kann also nicht vom Haken beim Anlegen
stammen — er kommt aus dem **Nachtrag** der Migration. Damit misst Punkt 2 an
diesem Konto die Hälfte, die sich nach dem Löschen nie wieder herstellen liesse.

> **Ein Prüfling, dessen Zeilen jünger sind als die Behebung, prüft die
> Behebung und nicht den Nachtrag.**

**Und nach der Anmeldung stehen beide Arten nebeneinander:** die zwei
nachgetragenen vom August und die frischen, deren Name beim Anlegen geschrieben
wurde. Auseinanderzuhalten sind sie am Datum.

**`Sitzungen: 0` ist der Grund, warum die Anmeldung nicht übersprungen werden
kann** — Punkt 9 hat sonst keinen Gegenstand.

---

### 7.2 Die Reihenfolge für den Rest — neu, gegen den vollständigen Bestand

Die erste Fassung dieser Reihenfolge stand gegen einen Bestand aus zwei Konten
und wollte „Dritte Verwaltung" zum Betreiber heben. Gegen fünf Konten gerechnet
ist beides billiger und schärfer:

**Ein zweiter aktiver Betreiber ist ein Schalter und keine Rollenänderung.**
„Neu von Hand" und „Wegwerf" **sind** Betreiber und nur deaktiviert. Wer einen
davon aktiviert, hat zwei aktive Betreiber, ohne eine Rolle anzufassen — und
ändert damit genau die eine Grösse, um die es geht.

**Und der Prüfling für das Löschen bringt seine Geschichte schon mit.**
„Wegwerf" hat sich am 25. August angemeldet, „Zweite Verwaltung" am
8. September. Beide tragen damit Protokollzeilen **von vor der Migration** —
und das misst mehr als ein frisch angelegtes Konto: nicht den Haken beim
Anlegen, sondern den **Nachtrag** für den Bestand.

> **Ein Prüfling, dessen Zeilen jünger sind als die Behebung, prüft die
> Behebung und nicht den Nachtrag.**

Die Falle aus der ersten Fassung bleibt und bekommt einen Befehl:

> **Punkt 2 misst die Zeilen des gelöschten Kontos. Hat es keine, ist er nach
> dem Löschen nicht offen, sondern für dieses Konto für immer unmessbar.**

```bash
srvpanel tinker --execute="
  \$k = \App\Models\Account::where('email','wegwerf@cloudlab24.de')->firstOrFail();
  echo 'Kennung: ', \$k->id, PHP_EOL;
  echo 'Zeilen als Handelnder: ', \App\Models\AuditEvent::where('account_id',\$k->id)->count(), PHP_EOL;
  echo 'davon mit Abschrift: ', \App\Models\AuditEvent::where('account_id',\$k->id)
      ->whereNotNull('account_name')->count(), PHP_EOL;
  echo 'Sitzungen: ', \Illuminate\Support\Facades\DB::table('sessions')->where('user_id',\$k->id)->count(), PHP_EOL;
"
```

Weder `Account` noch `AuditEvent` tragen `BelongsToSubscription`; die
Mandantenklammer greift hier nicht, und `withoutGlobalScopes()` ist deshalb
nicht nötig (gemessen am Quelltext, und §1 hat mit denselben Fragen schon
Zahlen geliefert).

Die Reihenfolge daraus:

1. **Punkt 6b/6c** im Ist-Zustand — ein aktiver Betreiber, und der ist das
   eigene Konto.
2. **„Wegwerf" aktivieren** → zwei aktive Betreiber. Keine Rolle angefasst.
3. **Punkt 7b/7c** — dieselbe eigene Zeile, jetzt `letzter: false`. Hier
   trennen sich 6 und 7; an der Tür tun sie es nicht.
4. Als „Wegwerf" **anmelden**, zweiten Faktor einrichten, eine Seite aufrufen.
   Das öffnet die Sitzung für Punkt 9. Der Block oben sagt vorher, ob das Konto
   überhaupt Zeilen hat — sonst ist ein anderes zu wählen.
5. **Vor** dem Löschen messen: Punkt 1 (`= 1`), Punkt 2 (Kennung **und** Name),
   Punkt 9 (`>= 1`), Punkt 8 als Gegenprobe (die Adresse ist vergeben).
6. Löschen.
7. **Nach** dem Löschen: dieselben vier, dazu Punkt 3, 4 und 10.
8. **Punkt 11**, die Bilderrunde.
