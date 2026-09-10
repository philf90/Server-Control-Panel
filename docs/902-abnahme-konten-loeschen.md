# Der Abnahmelauf für das Löschen von Adminkonten

**Ausgeschrieben am 10. September 2026, vor dem Fahren.** Der Prüfling ist
`0.7.4-rc.1` auf `cloudsrv24`; der Plan ist `docs/901`, das Kriterium dessen §6.
Das Protokoll bekommt die nächste freie Nummer im 900er-Block und steht daneben.

Der Lauf gehört hierher und nicht in die laufende Zählung: `docs/900` sagt, wer
im 900er-Block ablegt, nimmt die erste freie Nummer **darin** — und ein Lauf,
der von seinem Plan durch achtzig Nummern getrennt ist, wird nicht neben ihm
gelesen.

---

## 0. Was vor dem Lauf gelesen wird — und die drei Kriterien, die dabei umgefallen sind

Gelesen werden `docs/901 §1.1` (die sechs Verweise auf ein Konto), §3.5 und
§3.6 (der Löschweg und die Sitzungen) sowie §8b (was beim Bauen anders war).
Beim Ausschreiben sind **drei** der elf Punkte umgefallen. Sie stehen hier und
nicht als stille Korrektur weiter unten, weil das der teuerste Teil dieses
Dokuments ist:

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

### 0.1 Punkt 6 verlangt eine Meldung, die es an dieser Tür nicht gibt

§6 Punkt 6 lautet: „Der letzte aktive Betreiber lässt sich nicht löschen — die
Meldung ist `LastOperator::refusal()`, und der Knopf steht auf der Seite gar
nicht erst."

**Die zweite Hälfte gilt, die erste ist nicht herstellbar.** Gemessen am Code
und nicht überlegt:

- `DELETE /accounts/{admin}` trägt `can:operate-server`. Wer die Route
  erreicht, ist **aktiver Betreiber**.
- `LastOperator::isLast($konto)` ist nur wahr, wenn das Konto aktiver Betreiber
  ist **und** `active() <= 1`. Der Kommentar im Code sagt es selbst: *„Das
  Konto selbst zählt mit — bei genau einem ist es das eigene."*
- Ist der Zielkonto der letzte aktive Betreiber, ist es also das eigene — und
  `AccountController::destroy()` prüft **zuerst** auf das eigene Konto.

`LastOperator::refusal()` ist damit an dieser Route unerreichbar. Der Aufruf
bleibt trotzdem stehen; er ist die ehrliche Fassung der Regel und wird wirksam,
sobald eine dritte Rolle oder eine zweite Fähigkeit die Tür öffnet.
`AccountMutationTest` hält ihn über den Quelltext.

> **Zwei Regeln, die sich nur an einem Zustand trennen lassen, den es nicht
> geben kann, lassen sich durch die Tür nicht auseinanderhalten.**

**Punkt 6 lautet deshalb neu** (Punkt 6 in §7 unten): Die Zeile des letzten
aktiven Betreibers trägt keinen Löschknopf und in der Ablage
`is_last_operator: true`; der Aufruf weist ab, und die Meldung ist die der
Selbstprüfung. **Punkt 6 und Punkt 7 ergeben an der Tür dieselbe Meldung** —
getrennt werden sie in der Ablage, und genau dort misst der Lauf sie.

### 0.2 Punkt 5 nennt ein Kommando, das aussperren kann

§6 Punkt 5 will einen Eintrag ohne Handelnden, „herzustellen mit `srvpanel
access`". Zwei Messungen am Code:

- **Das Kommando schreibt nur, wenn es etwas ändert.** Ohne Optionen zeigt es
  nur und kehrt zurück; ändert `--add`/`--remove` nichts am Bestand, ebenso.
  Ein Aufruf „zum Anschauen" erzeugt also keinen Eintrag.
- **Und es ist scharf.** Eine leere Netzliste heisst „keine Beschränkung". Wer
  `--add 192.0.2.0/24` auf eine leere Liste anwendet, legt eine Beschränkung
  **an** — und seit A9 wird sie bei jeder Anfrage geprüft, nicht nur bei der
  Anmeldung. Die Sitzung aus einem anderen Netz endet sofort.

`srvpanel access` ist dabei das **einzige** Konsolenkommando dieses Panels, das
überhaupt ins Protokoll schreibt (ausgezählt über `app/Console/Commands/`, ein
Aufruf, und keiner nennt ein Konto). Der Plan hat es also richtig benannt.

**Punkt 5 fängt deshalb mit einer Frage an den Bestand an** und stellt den
Zustand nur her, wenn es ihn nicht schon gibt — der A9-Lauf (`docs/83`) ist
diesen Weg gegangen, seine Zeilen liegen vermutlich noch da.

### 0.3 Punkt 8 misst ohne Gegenprobe nichts

„Die Anmeldeadresse ist danach wieder frei — dasselbe Konto lässt sich unter
derselben Adresse neu anlegen." Das ist erst dann eine Aussage, wenn belegt
ist, dass es **vorher** nicht ging: `AccountController::store()` trägt
`Rule::unique('accounts', 'email')`, also gibt es die Hürde. Ohne die Messung
davor bestünde der Punkt auch auf einem Panel, das gar nicht prüft.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 1. Der Zustand vor dem Lauf

Jeder Block druckt seinen Zustand mit. Eine Messung, die ihn nicht mitdruckt,
ist von einer, die ihn nicht hatte, nicht zu unterscheiden.

```bash
srvpanel version                       # erwartet: 0.7.4-rc.1

srvpanel tinker --execute="
  echo 'Migration da: ', \Illuminate\Support\Facades\DB::table('migrations')
      ->where('migration','like','%the_log_keeps_the_name%')->exists() ? 'ja' : 'NEIN', PHP_EOL;
  echo 'Protokollzeilen: ', \App\Models\AuditEvent::count(), PHP_EOL;
  echo 'davon mit Abschrift: ', \App\Models\AuditEvent::whereNotNull('account_name')->count(), PHP_EOL;
  echo 'davon ganz ohne Handelnden: ', \App\Models\AuditEvent::whereNull('account_id')
      ->whereNull('account_name')->count(), PHP_EOL;
  echo 'aktive Betreiber: ', \App\Support\Authorization\LastOperator::active(), PHP_EOL;
"
```

**Was die Zahlen bedeuten.** „mit Abschrift" grösser als null belegt, dass der
Nachtrag gelaufen ist und **Bestandszeilen** erreicht hat — das ist die zweite
Hälfte von Punkt 2, und sie ist nur *jetzt* zu haben: Ist erst gelöscht, lässt
sich nicht mehr zeigen, dass die Zeile ihren Namen schon vorher trug.

„ganz ohne Handelnden" ist der Vorrat für Punkt 5.

---

## 2. Der Prüfkörper

Angelegt wird über `/accounts` im Browser, nicht über die Kommandozeile: Der
Lauf soll den Weg messen, den ein Betreiber geht.

1. **Ein zweites Betreiberkonto** `Anna Berger`, Rolle *Betreiber*, Zustand
   *aktiv*. Damit gibt es zwei aktive Betreiber — die Voraussetzung dafür, dass
   Punkt 7 überhaupt an der Selbstprüfung ankommt und nicht schon am
   Aussperrschutz.
2. **Anna meldet sich an**, richtet ihren zweiten Faktor ein und ruft eine
   Seite auf. Damit entstehen Protokollzeilen **unter ihrem Konto und vor dem
   Löschen** — der Gegenstand von Punkt 2.
3. **Eine zweite Sitzung von Anna bleibt offen** (privates Fenster, nicht
   abmelden). Sie ist der Gegenstand von Punkt 9.

Ihre Kennung notieren:

```bash
srvpanel tinker --execute="
  \$a = \App\Models\Account::where('email','anna@example.org')->first();
  printf(\"id=%d  name=%s  rolle=%s  zustand=%s\n\", \$a->id, \$a->name, \$a->role->value, \$a->status->value);
"
```

Diese Kennung heisst unten `\$ID`.

---

## 3. Punkt 1 — die Zeile ist fort

**Davor** (muss `1` sein), löschen über den Knopf auf `/accounts`, **danach**
(muss `0` sein):

```bash
srvpanel tinker --execute="echo \App\Models\Account::withoutGlobalScopes()->whereKey(\$ID)->count(), PHP_EOL;"
```

`withoutGlobalScopes()` steht hier, obwohl `Account` die Mandantenklammer gar
nicht trägt — sie kostet nichts und nimmt die Frage weg, ob eine `0` das
Ergebnis oder die Klammer ist. Bei `Operation` wäre sie zwingend
(`docs/901 §6` Punkt 1).

---

## 4. Punkt 2 — die Protokollzeilen tragen weiter ihren Namen

Vor **und** nach dem Löschen dasselbe:

```bash
srvpanel tinker --execute="
  foreach (\App\Models\AuditEvent::where('account_name','Anna Berger')->latest()->limit(5)->get() as \$e) {
      printf(\"%-24s id=%-6s name=%s\n\", \$e->action, var_export(\$e->account_id, true), \$e->account_name);
  }
"
```

**Erwartet:** davor `id=<zahl>` und der Name; danach `id=NULL` und **derselbe
Name**. Die Zeilen selbst bleiben, ihre Zahl ändert sich nicht.

**Die zweite Hälfte** — eine Zeile, die vor dem *Nachtrag* entstanden ist:

```bash
srvpanel tinker --execute="
  \$e = \App\Models\AuditEvent::whereNotNull('account_name')->oldest()->first();
  printf(\"%s  %s  %s\n\", \$e->created_at, \$e->action, \$e->account_name);
"
```

Ihr Zeitstempel muss **vor** dem Update liegen. Eine Zeile von heute belegte
den Nachtrag nicht — sie hätte ihren Namen ohnehin beim Anlegen bekommen.

---

## 5. Punkt 3 — `/audit` zeigt den Namen mit „gelöscht"

Im Browser auf `/audit`, nach Anna suchen. Die vierte Spalte heisst **„Wer"**,
und dort steht nach dem Löschen `Anna Berger (gelöscht)`.

**Gegenprobe in derselben Ansicht:** eine Zeile des eigenen Kontos steht ohne
Zusatz da. Stünde überall „(gelöscht)", sagte die Spalte nichts.

---

## 6. Punkt 4 — die Ausfuhr trägt den Namen

`/audit` ausführen, CSV herunterladen, dann:

```bash
head -1 <datei>              # vierte Spalte: Wer   (hiess bis 0.7.3 „Konto")
grep -c 'Anna Berger' <datei>
```

**Erwartet:** die Kopfzeile nennt `Wer` an vierter Stelle, und die Zeilen tragen
`Anna Berger (gelöscht)` statt einer nackten Zahl. Die **Zahl** der Spalten
bleibt gleich — das ist der Unterschied zwischen einer umbenannten und einer
zusätzlichen Spalte, und wer die CSV weiterverarbeitet, muss ihn kennen.

---

## 7. Punkt 5 — ein Eintrag ohne Handelnden liest sich als „System"

**Zuerst fragen, dann herstellen.** Aus §1 ist die Zahl „ganz ohne Handelnden"
bekannt.

**Ist sie grösser als null**, genügt das Ansehen — eine solche Zeile auf
`/audit` aufsuchen (etwa `settings.access` aus dem A9-Lauf) und lesen: In der
Spalte „Wer" steht `System`.

**Ist sie null**, wird eine erzeugt — und hier ist Vorsicht geboten
(§0.2). Erst den Zustand ansehen:

```bash
srvpanel access                  # zeigt nur, schreibt nicht
```

- **Es steht eine Beschränkung da:** ein Netz aufnehmen und wieder entfernen.
  `192.0.2.0/24` ist TEST-NET-1 und erreicht niemanden, das Aufnehmen
  **erweitert** also nur.

  ```bash
  srvpanel access --add=192.0.2.0/24
  srvpanel access --remove=192.0.2.0/24
  ```

  Zwei Einträge, beide ohne Handelnden, und der Endzustand ist der
  Anfangszustand.

- **Es steht keine da:** Ein `--add` legt eine an und sperrt jeden aus, der
  nicht in diesem Netz sitzt. Dann wird das Netz genommen, in dem der Betreiber
  gerade **selbst** sitzt, und unmittelbar danach `--clear`. Wer das nicht will,
  lässt Punkt 5 offen und misst ihn beim nächsten Lauf mit vorhandener
  Beschränkung — ein ausgesperrter Betreiber ist teurer als ein offener Punkt.

**Dieser Punkt darf nicht ausfallen** (`docs/901 §6`): Er ist der einzige
Beleg, dass die beiden Nullfälle auseinandergehalten werden — „niemand war
angemeldet" gegen „das Konto ist gelöscht". Fällt er aus, ist der Lauf nicht
durch.

---

## 8. Punkt 6 — der letzte aktive Betreiber

Neu gefasst nach §0.1. Herzustellen, indem Anna auf *Administrator* herabgestuft
oder gesperrt wird; danach ist der Betreiber der einzige aktive.

**a) Die Seite.** Auf `/accounts` trägt seine Zeile die Marke `letzter` und
**keinen** Löschknopf.

**b) Die Ablage.** In der Konsole des Browsers auf `/accounts`:

```js
const z = JSON.parse(document.getElementById('app').dataset.page)
  .props.accounts.data.map(r => ({ name: r.name, self: r.is_self, letzter: r.is_last_operator }))
console.table(z)
```

**Erwartet:** die eigene Zeile mit `self: true` **und** `letzter: true`.

**c) Die Tür.** Den Knopf gibt es nicht, also wird die Route unmittelbar
gerufen — durch dieselbe Kette aus Middleware und Controller **und mit
demselben Klienten**, nur ohne das Bedienelement:

```js
document.getElementById('app').__vue_app__.config.globalProperties.$inertia
  .delete('/accounts/<eigene id>', {
    preserveScroll: true,
    onError: (e) => console.log('abgewiesen:', e),
    onSuccess: () => console.log('GELÖSCHT — das wäre der Befund'),
  })
```

**Erwartet:** `onError` mit `account` und der Meldung der **Selbstprüfung**
(„Das eigene Konto lässt sich nicht löschen. Ein zweiter Betreiber kann es
tun."). Nicht `LastOperator::refusal()` — und das ist richtig, siehe §0.1.

> **Diese Fassung ist am 10. September während des Laufs berichtigt worden**
> (`docs/903 §5`). Vorher stand hier ein rohes `fetch` mit
> `Accept: application/json` und der Erwartung `422`. Beides trägt hier nicht:
> `bootstrap/app.php` schaltet Laravels Aushandlung über
> `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))` ab, die Ablehnung kommt
> also als Weiterleitung — und `fetch` folgt ihr mit derselben Methode, womit
> aus `DELETE /accounts/1` ein `DELETE /accounts` und daraus eine `405` wird.
> Der Knopf ruft `router.delete()`; wer die Tür ohne ihn prüfen will, ruft
> denselben Klienten.

Danach Anna wieder auf *Betreiber* und *aktiv* setzen.

---

## 9. Punkt 7 — das eigene Konto bei zwei aktiven Betreibern

Mit Anna wieder aktiv (`LastOperator::active()` ist `2`) dasselbe noch einmal:

**a)** Die eigene Zeile trägt weiterhin keinen Löschknopf, aber **keine** Marke
`letzter`.

**b)** In der Ablage: `self: true`, `letzter: false`. **Hier trennen sich die
beiden Punkte** — an der Tür tun sie es nicht.

**c)** Derselbe Aufruf wie in §8c gibt dasselbe `onError` mit derselben
Meldung der Selbstprüfung.

> **Zwei Punkte, die dieselbe Meldung ergeben, sind nicht derselbe Punkt —
> aber sie sind es an der Stelle, an der man sie misst.**

---

## 10. Punkt 8 — die Anmeldeadresse ist wieder frei

**Die Gegenprobe zuerst, solange Anna noch da ist** (§0.3): auf `/accounts` ein
neues Konto mit `anna@example.org` anlegen wollen. Erwartet: Das Formular weist
ab, die Adresse ist vergeben.

**Nach dem Löschen** derselbe Versuch. Erwartet: Das Konto entsteht.

Es danach wieder löschen — sonst bleibt ein Prüfkörper auf dem Server liegen.

---

## 11. Punkt 9 — die offenen Sitzungen sind fort

Gemessen in `sessions` und nicht an der Oberfläche: Eine Sitzung, die im
Browser noch offen aussieht, sagt über die Zeile in der Tabelle nichts.

```bash
srvpanel tinker --execute="
  echo 'Anna: ', \Illuminate\Support\Facades\DB::table('sessions')->where('user_id',\$ID)->count(), PHP_EOL;
  echo 'gesamt: ', \Illuminate\Support\Facades\DB::table('sessions')->count(), PHP_EOL;
"
```

**Erwartet:** davor `Anna >= 1`, danach `Anna = 0`. **Die Gegenprobe steht
daneben:** „gesamt" sinkt genau um Annas Zahl. Sänke es weiter, hätte
`Sessions::forgetAll()` fremde Sitzungen mitgenommen — der Fall, den der Wächter
`AccountDeletionTest` im Container hält und den hier ein echter Bestand prüft.

---

## 12. Punkt 10 — der Eintrag `account.deleted`

```bash
srvpanel tinker --execute="
  \$e = \App\Models\AuditEvent::where('action','account.deleted')->latest()->first();
  printf(\"wer=%s  ziel=%s/%s\n\", \$e->actor(), \$e->target_type ?? '-', var_export(\$e->target_id, true));
  print_r(\$e->context);
"
```

**Erwartet:** `context` trägt `name`, `email` und `role`. Er ist die einzige
Stelle, an der diese drei aneinander gebunden bleiben — die Abschrift auf den
übrigen Zeilen trägt nur den Namen. `target_id` zeigt auf eine Zeile, die es
nicht mehr gibt; das ist `nullableMorphs` und kein Befund.

---

## 13. Punkt 11 — die Bilderrunde

`tests/bilder-messen.js` in der Browserkonsole, vier Lagen (hell/dunkel ×
390/1440 px), auf `/accounts` **und** `/audit`.

**Vor jeder Lage neu laden.** `bilderMessen()` wirft beim zweiten Aufruf ohne
Neuladen, und ein zweites Einfügen der Vorschrift scheitert an der
Wiederdeklaration von `STAND` — dass jede Lage eine eigene geladene Seite
hatte, steht damit in den Zahlen selbst.

**Erwartet:** `dokument = 0`, Gegenprobe `200/200`. Ein Roller in `rollt` ist
nur dann ein Befund, wenn er nicht `.scrolls` heisst.

**Kein Befund ist der Überlauf der Kontentabelle bei sehr langen Namen.** Er ist
gemessen (240 px ohne den Löschknopf, 334 px mit ihm), älter als diese Spalte
und steht als benannter Rest in `docs/901 §9`. Wer ihn hier neu meldet, meldet
den Bestand.

---

## 14. Was der Lauf ausdrücklich nicht prüft

- **Kundenkonten.** Ihr Löschen gibt es nicht (`docs/901 §4`).
- **Zwei gleichnamige gelöschte Konten.** Ihre Protokollzeilen sind danach
  nicht mehr auseinanderzuhalten; bewusst so entschieden (`docs/901 §9`).
- **Die Laufzeit des Nachtrags über ein grosses Protokoll.** Auf `cloudsrv24`
  sind es wenige Tausend Zeilen; die Grenze kennt niemand.
- **Das Protokoll des Agenten.** Es liegt ausserhalb der Datenbank und trägt
  weiter die nackte Kennung.
- **`operations.account_name`** an der Vorgangsseite. Die Abschrift steht dort,
  und `/operations/{id}` zeigt sie als „Ausgelöst von" — ein eigener Punkt ist
  das nicht, weil dieselbe Abbildung dahintersteht wie bei `/audit`.

---

## 15. Wann er durch ist

**Alle elf Punkte erfüllt**, wobei Punkt 6 in der Fassung aus §0.1 gilt.

**Zwei dürfen nicht ausfallen:** Punkt 5 (die beiden Nullfälle gehen
auseinander) und Punkt 2 (die Abschrift überlebt das Löschen). Ohne den ersten
ist nicht belegt, dass „System" und „gelöscht" zwei Zustände sind; ohne den
zweiten ist der ganze Plan nicht belegt.

**Ausfallen darf** Punkt 5 in seiner *herstellenden* Form, wenn der Server keine
Netzbeschränkung trägt und der Betreiber sie nicht anlegen will — dann muss er
über eine bestehende Zeile erfüllt werden. Findet sich auch die nicht, ist der
Punkt offen und der Lauf **nicht** durch.

> **Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht
> „nicht herstellbar" — und ihn so zu nennen wäre die bequemere von zwei
> falschen Auskünften.**

Was gefunden wird, kommt ins Protokoll, auch wenn es mit dem Löschen nichts zu
tun hat: Ein Abnahmelauf misst den Bestand mit.
