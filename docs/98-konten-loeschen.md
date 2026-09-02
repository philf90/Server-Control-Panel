# Adminkonten löschen — die Abschrift statt des Verbots

*Der Plan, der Entscheidung 1 aus `docs/82` ablöst. Ausgeschrieben am
2. September 2026 auf Frage des Betreibers.*

> **Gebaut ist nichts.** Dieses Dokument ist die Vorschrift und nicht das
> Protokoll. Wer es abarbeitet, schreibt das Protokoll daneben.

---

## 0. Warum dieses Dokument entstanden ist

Der Betreiber hat gefragt:

> *Wieso lassen sich in der Betreiberansicht auf `/accounts` die Konten nur
> deaktivieren und nicht löschen? Durch die Tests und ggf. spätere zusätzliche
> Konten wächst die Liste stetig, ohne dass ein sinnvolles Aufräumen möglich
> ist.*

Die Antwort stand an drei Stellen im Quelltext und lautete überall gleich —
`docs/82 §1.1`, der Kopf von `App\Http\Controllers\AccountController` und der
Kopf von `App\Support\Authorization\LastOperator`:

> **Ein Protokoll, aus dem sich der Handelnde nachträglich entfernen lässt, ist
> kein Protokoll — es ist eine Liste von Ereignissen.**

Der Satz stimmt. Beim Nachmessen am 2. September 2026 stellte sich aber
heraus, dass die Auskunft, die er schützt, **an keiner Stelle der Oberfläche als
Name erscheint** (§1.2). Der Bann schützt seit dem 24. August eine Absicht.

> **Ein Verbot, das eine Auskunft schützt, die niemand anzeigt, schützt eine
> Absicht und keine Auskunft.**

Und die Behebung ist keine Aufhebung des Satzes, sondern seine Erfüllung:

> **Löschen und Vergessen sind zwei Dinge. Die Zeile darf verschwinden; was sie
> getan hat, darf es nicht.**

Der Preis sind **zwei Spalten** und ein Nachtrag, den es genau einmal gibt.

---

## 1. Der Bestand, gemessen am Quelltext

**Nicht aus dem Gedächtnis.** Wer hier weiterbaut, fängt bei diesen drei
Tabellen an und nicht bei null. Gemessen am 2. September 2026.

### 1.1 Die fünf Verweise auf ein Konto

| Verweis | Deklariert in | Verhalten beim Löschen |
|---|---|---|
| `audit_events.account_id` | `2026_08_02_120100_create_operations_and_audit_tables` | → `NULL` |
| `audit_events.acting_as_account_id` | dieselbe Migration | → `NULL` |
| `operations.account_id` | dieselbe Migration | → `NULL` |
| `operations.cancelled_by` | `2026_08_03_100000_add_cancel_request_to_operations` | → `NULL` |
| `sessions.user_id` | `0001_01_01_000000_create_users_table` | **bleibt liegen** |

Die letzte Zeile ist ein eigener Befund und stand in `docs/82` nicht:
`foreignId('user_id')->nullable()->index()` — **ohne `constrained()`**. Kein
`nullOnDelete` greift dort, weil es dort keinen Fremdschlüssel gibt. Es ist
keine Rechteausweitung (die Authentifizierung findet kein Konto und behandelt
den Besucher als Gast), aber die Zeilen bleiben bis zur Sitzungsbereinigung
stehen. **Der Löschweg räumt sie selbst ab** (§3.6).

Zwei Dinge, die dabei **nicht** betroffen sind, und beide mit Grund:

- **`accounts.customer_id`** steht auf `cascadeOnDelete`, ist bei einem
  Adminkonto aber immer `null`.
- **`audit_events.acting_as_account_id`** trägt während „Anmelden als" das
  **Kundenkonto**, nicht den Admin — `Audit::record()` schreibt
  `'account_id' => $impersonator ?? $signedIn?->id`. Der Handelnde steht also
  in `account_id`, und den deckt die Abschrift. Wird eines Tages das Löschen von
  **Kundenkonten** gebaut, gilt dieser Satz nicht mehr (§9).

### 1.2 Was heute vom Handelnden zu sehen ist

| Ort | Zeigt |
|---|---|
| `/audit` | Zeitpunkt · Aktion · Ergebnis · Ziel · Einzelheiten · IP — **keine Spalte für den Handelnden** |
| CSV-Export | eine Spalte `Konto`, und darin `account_id` — die **nackte Zahl** |
| `/operations/{id}` | `Ausgelöst von` mit `{{ account ?? '—' }}` — die einzige Stelle mit einem Namen |

`AuditQuery::toArrayRow()` legt `account_id` und `acting_as_account_id` in die
Ablage; `resources/js/Pages/Audit/Index.vue` liest beide in seinem Typ und
rendert keines von beiden.

> **Ein Feld im Payload ist noch keine Spalte** (`docs/86`).

Der Beleg, den jemand drei Jahre aufhebt, sagt heute „Konto 3". Das ist die
Hälfte des Problems, und sie besteht **unabhängig** vom Löschen.

### 1.3 Der Fund, der den Entwurf entscheidet

**`account_id = NULL` trägt hier schon eine Bedeutung**, und der Quelltext sagt
sie wörtlich. `App\Console\Commands\Access` schreibt seinen Protokolleintrag
ohne Konto und begründet es daneben:

> *Ohne handelndes Konto, und das ist keine Lücke. Auf der Kommandozeile ist
> niemand angemeldet; ein Eintrag, der sich einen Handelnden ausdenkt, wäre
> schlechter als einer ohne.*

Dasselbe bei `Operations::dispatch()`: `'account_id' => $account?->id`. Jeder
Lauf der Automatik, jede Zertifikatserneuerung, jeder `srvpanel`-Aufruf legt
Zeilen ohne Handelnden an.

**Daraus folgt Entscheidung 2 (§2): Ein Sammelname als gespeicherter Wert ist
ausgeschlossen.** Wer die Zeilen eines gelöschten Kontos auf denselben Zustand
abbildet und ihn „gelöschter Benutzer" nennt, beschriftet damit **jeden
Cron-Lauf** als gelöschten Benutzer.

> **Eine Null, die schon eine Bedeutung trägt, kann keine zweite bekommen — die
> beiden Fälle sehen danach gleich aus.**

Das ist die Familie, an der dieses Projekt wiederholt bezahlt hat: *Eine Null,
die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu tun"* (M5, `docs/81`).

### 1.4 Was es an Schutz schon gibt

`LastOperator::permits($account, ?AdminRole $role, AccountStatus $status)` fragt
nach dem **Zielzustand** und nicht nach der Handlung — und der Kopf der Klasse
nennt das Löschen ausdrücklich als den dritten der drei Wege:

> **Eine Prüfung, die die Handlung entgegennimmt, muss jede Handlung kennen.
> Eine, die den Zielzustand entgegennimmt, kennt sie alle.**

Für das Löschen ist der Zielzustand „keine Rolle, nicht aktiv". Es gibt also
**nichts zu bauen**, nur einen Aufruf zu setzen. Zwei Wächter stehen als
Stolperdraht davor und gehen beim ersten `DELETE` absichtlich rot (§8).

---

## 2. Die Entscheidungen des Betreibers

Getroffen am 2. September 2026. Sie tragen den Entwurf; wer sie ändert, ändert
den Plan und nicht nur eine Zeile.

**1. Komplett löschen.** Die Zeile in `accounts` verschwindet. Kein
Soft-Delete, kein Grabsteinkonto, kein gesperrtes Konto, das nur nicht mehr
angezeigt wird. `Account` trägt heute kein `SoftDeletes`, und das bleibt so.

**2. Die Abschrift trägt den echten Namen, kein Sammelname.** Begründung
in §1.3. „Gelöschter Benutzer" ist als **Anzeige** richtig und als
**gespeicherter Wert** falsch.

**3. Man kann sich nicht selbst löschen.** Auch dann nicht, wenn ein zweiter
aktiver Betreiber übrig bliebe.

**4. Der letzte aktive Betreiber ist geschützt — über `LastOperator`.** Nicht
über eine Marke am Konto.

**Und ausdrücklich: es gibt keinen „initialen Betreiber".** Die Frage stand im
Raum und ist verneint:

- `accounts` trägt keine Marke dafür. Das erste Konto entsteht über
  `srvpanel:admin` und ist von jedem späteren nicht zu unterscheiden.
- Ein für immer geschütztes Konto ist keine Absicherung, sondern eine Last: Der
  Mensch, der den Server aufgesetzt hat, geht irgendwann, und sein Konto bliebe
  unlöschbar.
- Die Eigenschaft, die man wirklich will, heisst nicht „dieses Konto überlebt",
  sondern „mindestens ein aktiver Betreiber überlebt". Das ist `LastOperator`,
  und es ist die stärkere Zusage: Sie übersteht Umbenennung, Übergabe und
  Weggang.

> **Eine Marke am Konto wäre eine zweite Achse neben dem Aussperrschutz — also
> eine zweite Stelle, die gefragt werden muss. Genau davor warnt der Kopf von
> `LastOperator`.**

Der echte Rückweg bleibt `srvpanel admin` und `srvpanel access --clear`, beide
mit root. Das ist die ehrliche Untergrenze.

---

## 3. Was gebaut wird

### 3.1 Zwei Spalten, und ausdrücklich nicht vier

```
audit_events.account_name   string, nullable, nach account_id
operations.account_name     string, nullable, nach account_id
```

**Warum nicht auch `acting_as_account_name`:** Die Spalte zeigt auf ein
Kundenkonto, und dieser Weg löscht nur Adminkonten (§1.1).

**Warum nicht auch `cancelled_by_name`:** `operations.cancelled_by` wird von
`OperationController` geschrieben und **von keiner Oberfläche gelesen** —
gemessen am 2. September, ausser der Typangabe am Modell gibt es keine
Fundstelle. Eine Abschrift dafür wäre ein ungelesenes Feld neben einem
ungelesenen Feld.

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt** (`docs/66`).

Wer die Anzeige von `cancelled_by` baut, baut seine Abschrift im selben Schritt.
Steht als offener Punkt in §9.

**Die Fremdschlüssel bleiben, wie sie sind.** Alle vier stehen schon auf
`nullOnDelete()`; im Unterschied zum Vorbild
`2026_08_07_100100_operations_survive_a_deleted_subscription` ist hier **kein**
`ALTER` an einem Fremdschlüssel nötig — und damit fällt die SQLite-Falle
dieses Vorbilds weg.

### 3.2 Geschrieben beim Anlegen und nicht beim Löschen

An **einer** Stelle je Modell, im `creating`-Ereignis von `booted()` — genau wie
`subscription_name` seit `docs/35`.

```php
static::creating(function (AuditEvent $event): void {
    if ($event->account_id === null || $event->account_name !== null) {
        return;
    }

    $name = Account::query()->whereKey($event->account_id)->value('name');

    // `is_string` und nicht `(string)`: Findet die Abfrage nichts, käme aus
    // der Umwandlung ein leerer Name heraus. Der sieht aus wie eine Abschrift
    // und ist keine.
    $event->account_name = is_string($name) ? $name : null;
});
```

**Drei Gründe, warum das Anlegen der richtige Zeitpunkt ist**, und der zweite
ist der tragende:

1. **Ein Sammelname beim Löschen wäre ein `UPDATE` über die ganze Historie** —
   in einer Anfrage, über womöglich sechsstellig viele Zeilen.
2. **Namen ändern sich.** `AccountController::update()` erlaubt das Umbenennen.
   Wer erst beim Löschen schreibt, stempelt den **letzten** Namen auf Zeilen,
   die unter einem früheren entstanden sind. Die Abschrift beim Anlegen hält
   fest, was damals galt — das ist der Unterschied zwischen einer Abschrift und
   einer nachträglichen Behauptung.
3. **Sechzehn anlegende Stellen wären sechzehn Gelegenheiten, es zu vergessen**
   (`docs/94 §6b`). Die vergessene fiele niemandem auf, weil eine fehlende
   Abschrift aussieht wie ein Eintrag der Automatik.

> **Was jede Stelle anders weiss, gehört an die Stelle. Was überall dasselbe
> ist, gehört an eine — und die muss eine sein, an der niemand vorbeikommt.**

**Ohne Mandantenklammer nachschlagen ist hier nicht nötig** — `Account` trägt
keine (nachgesehen am 24. August 2026, festgehalten im Kopf von
`LastOperator::active()`). Wer das ändert, ändert auch diese Stelle.

**Gelesen wird mit Rückfall**, wie `DatabaseDump::path()`:

```php
$this->account?->name ?? $this->account_name
```

### 3.3 Der Nachtrag — es gibt ihn genau einmal

Alle heute vorhandenen Zeilen haben keine Abschrift. Eine Migration trägt sie
aus `accounts` nach, **solange die Konten noch da sind**. Danach kann keine
spätere Migration sie rekonstruieren.

Das Vorbild ist
`2026_08_07_100100_operations_survive_a_deleted_subscription::carryTheNamesOver()`,
und seine beiden Lehren gelten hier unverändert:

- **In PHP und nicht als `UPDATE … JOIN`.** Der Einzeiler läuft auf MariaDB und
  nicht auf SQLite, und die Tests laufen auf SQLite.
- **Über die Konten iterieren und nicht über die Protokollzeilen** — das kostet
  eine Abfrage je Konto statt eine je Zeile. Ob auf `account_id` ein Index
  liegt, ist **ungemessen**: `constrained()` legt keinen an, InnoDB tut es für
  jeden Fremdschlüssel von sich aus, und SQLite tut es nicht. Der Grund oben
  trägt ohne diese Annahme.

```php
DB::table('accounts')->where('type', 'admin')->orderBy('id')
    ->chunkById(200, function ($accounts): void {
        foreach ($accounts as $account) {
            DB::table('audit_events')->where('account_id', $account->id)
                ->update(['account_name' => $account->name]);
            DB::table('operations')->where('account_id', $account->id)
                ->update(['account_name' => $account->name]);
        }
    });
```

**Diese Migration muss vor dem Löschweg laufen und danach nie wieder.** Steht
sie in derselben Auslieferung wie die Route, ist die Reihenfolge durch den
Dateinamen gesichert; wer sie trennt, liefert die Migration zuerst aus.

**Was der Nachtrag nicht kann:** Er schreibt den **heutigen** Namen auf alle
alten Zeilen, auch auf die, die unter einem früheren entstanden sind. Das ist
die eine Stelle, an der die Abschrift nicht hält, was §3.2 verspricht — und sie
ist unvermeidbar, weil der frühere Name nirgends steht. **Sie gehört als Satz
in den `CHANGELOG.md`** und nicht bloss in den Kopf der Migration.

> **Ein Nachtrag kann nur abschreiben, was heute dasteht — nicht, was damals
> galt.**

### 3.4 Die Anzeige — die Spalte, die es nie gab

Ohne sie behebt dieser Plan die Hälfte. Drei Zustände, drei Darstellungen:

| `account_id` | `account_name` | Anzeige |
|---|---|---|
| gesetzt | (gleich) | der Name des Kontos, verknüpft auf `/accounts/{id}/edit` |
| leer | gesetzt | `Anna Berger (gelöscht)` — ohne Verknüpfung |
| leer | leer | `System` |

**Das ist der Ertrag der Spalte**: Ohne sie fielen der zweite und der dritte
Fall zusammen, und jede Beschriftung wäre eine Behauptung über etwas, das
niemand mehr weiss. **Hier — und nur hier — ist „gelöscht" das richtige Wort.**

Zu ändern sind:

- **`AuditQuery::toArrayRow()`** — `account_name` und ein fertiger
  Anzeigetext in die Ablage. Der Satz entsteht dort und nicht auf der Seite,
  aus demselben Grund wie `details`: Liste und Export gehen durch dieselbe
  Abbildung, und zwei Formulierungen laufen auseinander.
- **`resources/js/Pages/Audit/Index.vue`** — eine siebte Spalte `Wer`. Die
  Tabelle ist `class="stacks"`; `MobileTableTest` verlangt, dass jede
  gestapelte Zelle die Beschriftung ihrer Spalte trägt.
- **Der CSV-Export** — die Spalte `Konto` schreibt künftig den Namen statt der
  Zahl. `AuditController::harmless()` entschärft dabei schon heute jeden Wert,
  der mit `=`, `+`, `-`, `@`, Tabulator oder Wagenrücklauf beginnt; ein Name
  aus einem Formular geht also nicht ungeprüft in die Datei.
- **`OperationController::show()`** — `$operation->account?->name` bekommt den
  Rückfall auf `account_name`.

**Und die Bilderrunde gehört dazu**: eine siebte Spalte in einer
`stacks`-Tabelle bei 390 px ist genau der Fall, an dem dieses Projekt
wiederholt Überlauf gemessen hat. `tests/bilder-messen.js` ist die Vorschrift,
Gegenprobe inbegriffen.

### 3.5 Der Löschweg

**Route.** `DELETE /accounts/{admin}` → `AccountController::destroy()`, mit
derselben Fähigkeit wie die übrigen Kontenrouten.

**Drei Prüfungen, in dieser Reihenfolge:**

1. **Sich selbst nicht** (Entscheidung 3). Sie gehört **nicht** in
   `LastOperator` — die Klasse beantwortet „bleibt ein aktiver Betreiber
   übrig", und das ist eine andere Frage: Betreiber Nr. 2 von 2, der sich
   selbst löscht, sperrt niemanden aus und schiesst sich trotzdem ins Knie.
   Eine eigene, benannte Prüfung mit eigener Meldung.
2. **`LastOperator::permits($admin, null, AccountStatus::Disabled)`** — der
   Zielzustand eines gelöschten Kontos ist „keine Rolle, nicht aktiv". Bei
   `false` dieselbe `ValidationException` wie in `update()`, mit
   `LastOperator::refusal()`.
3. **Eine Bestätigung.** Das Löschen ist der einzige Griff dieser Seite, den
   niemand zurücknehmen kann.

**Reihenfolge im Rumpf** — und sie ist tragend:

```
1. Zustand für das Protokoll lesen (id, name, email, role)
2. Sitzungen dieses Kontos beenden
3. Protokolleintrag account.deleted schreiben
4. Konto löschen
```

**Der Eintrag steht vor dem Löschen**, weil `audit_events` `nullableMorphs`
benutzt: `target_id` zeigt nach dem Löschen auf eine Zeile, die es nicht mehr
gibt. Deshalb trägt der `context` **Name, Anmeldeadresse und Rolle** — er ist
die einzige Stelle, an der die Bindung „dieser Name gehörte zu dieser Adresse
und dieser Kennung" **einmal** festgehalten wird.

```php
$audit->success('account.deleted', $admin, [
    'name' => $admin->name,
    'email' => $admin->email,
    'role' => $admin->role?->value,
]);
```

**Auf der Seite** bekommt jede Zeile `is_self` neben dem vorhandenen
`is_last_operator` — aus derselben Quelle, die es später abweist. Ein Knopf,
den der Aufruf danach ablehnt, ist genau das, was `AbilityReachTest` und
`OperatorControlTest` verbieten. Die Zeile des eigenen Kontos zeigt den Knopf
gar nicht; die des letzten Betreibers trägt schon heute das Merkmal `letzter`.

### 3.6 Die Sitzungen

`sessions.user_id` hat keinen Fremdschlüssel (§1.1) — niemand räumt dort auf.
`Sessions` kennt heute `of()`, `readable()` und `forget()` für **eine**
Sitzung. Es braucht eine Methode für alle Sitzungen eines Kontos, und sie
gehört dorthin und nicht in den Controller: Zwei Stellen, die `DB::table
('sessions')` anfassen, sind eine zu viel.

Ohne sie bleiben die Zeilen bis zur Sitzungsbereinigung liegen. Keine
Rechteausweitung — die Authentifizierung findet kein Konto und behandelt den
Besucher als Gast —, aber der Rest eines Kontos, das gelöscht sein soll.

---

## 4. Was ausdrücklich **nicht** gebaut wird

- **Kein Löschen von Kundenkonten.** Die Routenbindung `{admin}` löst
  ausschliesslich Adminkonten auf (`SrvPanelServiceProvider`), und für Kunden
  gilt weiter, was `2026_08_06_140000_release_the_address_of_a_withdrawn_account`
  entschieden hat: Das Konto bleibt, die Anmeldeadresse wird frei.
- **Kein Massenlöschen und keine Auswahlkästchen.** Ein Griff, den man
  nicht zurücknehmen kann, bekommt keine Mehrfachauswahl.
- **Kein Papierkorb, keine Wiederherstellung.** Wer ein Konto zurück will,
  legt es neu an; die Anmeldeadresse ist nach dem Löschen wieder frei, weil
  der Unique-Index sie hergibt.
- **Keine Marke `protected` am Konto** (§2).
- **Keine Änderung an den vier Fremdschlüsseln.** `nullOnDelete` ist richtig
  und bleibt.
- **Kein Wechsel der Anmeldeadresse.** Steht weiter offen, `docs/82 §9`.

---

## 5. Was beim Bauen schiefgehen kann

**5.1 Der Nachtrag wird vergessen oder läuft zu spät.** Dann verliert die
erste Löschung genau die Historie, um derentwillen der Bann bestand — und
zwar lautlos, weil eine Zeile ohne Abschrift aussieht wie eine der Automatik.
Das ist der teuerste denkbare Fehler dieses Plans.

**5.2 Die Abschrift wird gebaut und nicht angezeigt.** Dann ist sie von einer,
die es nicht gibt, von aussen nicht zu unterscheiden — die Familie aus
`docs/66` Befund 7 zum wiederholten Mal.

**5.3 `(string)` statt `is_string()`.** Ergibt einen leeren Namen, der aussieht
wie eine Abschrift und keine ist. Der Kommentar an `Operation::booted()` sagt
es seit `docs/35`; er ist trotzdem leicht zu übersehen.

**5.4 Die Selbstprüfung wandert in `LastOperator`.** Sie beantwortet eine
andere Frage (§3.5). Wer sie dort einbaut, macht aus einer klaren Klasse eine
Sammelstelle — und beim nächsten Fall fragt jemand die falsche Methode.

**5.5 Die siebte Spalte schiebt die Seite bei 390 px.** Ein Name darf 255
Zeichen lang sein. `MobileTableTest` prüft die Beschriftung, nicht die Breite;
die Breite misst nur die Bilderrunde.

**5.6 Der Knopf steht da, wo er nicht gedrückt werden darf.** `is_self` und
`is_last_operator` müssen aus derselben Quelle kommen wie die Prüfung im
Controller. Eine zweite Bedingung in der `.vue` wäre eine zweite Fassung der
Regel, und die zweite veraltet.

---

## 6. Das Abnahmekriterium

Auf einem echten Server, nicht im Container. Der Lauf gehört als eigenes
Dokument daneben, **vor** dem Fahren ausgeschrieben.

1. **Ein Adminkonto lässt sich löschen.** Danach ist die Zeile in `accounts`
   fort — nachgewiesen über `srvpanel tinker`.

   **Und dort gilt die Mandantenklammer je Modell verschieden**, gemessen am
   2. September 2026: `Account` und `AuditEvent` tragen sie **nicht**,
   `Operation` trägt sie über `BelongsToSubscription`. Wer im `tinker` ohne
   angemeldetes Konto nach Vorgängen fragt, braucht `withoutGlobalScopes()` —
   sonst kommen null Zeilen, und zwar wortlos.

   > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer
   > leeren Liste und nicht mit einem Fehler** (`docs/78`).
2. **Seine Protokollzeilen tragen weiter seinen Namen.** Gemessen an einer
   Zeile, die **vor** dem Löschen entstanden ist, und an einer, die vor dem
   **Nachtrag** entstanden ist.
3. **`/audit` zeigt den Namen**, und zwar mit dem Zusatz „gelöscht".
4. **Der CSV-Export trägt den Namen** statt der Kennung.
5. **Ein Eintrag der Automatik oder der Kommandozeile liest sich als
   „System"** und nicht als gelöschter Benutzer. Herzustellen mit
   `srvpanel access` — das Kommando schreibt nachweislich ohne Handelnden.
   **Dieser Punkt darf nicht ausfallen**: Er ist die Messung zu §1.3 und damit
   der einzige Beleg, dass die beiden Nullfälle auseinandergehalten werden.
6. **Der letzte aktive Betreiber lässt sich nicht löschen** — die Meldung ist
   `LastOperator::refusal()`, und der Knopf steht auf der Seite gar nicht erst.
7. **Das eigene Konto lässt sich nicht löschen**, auch bei zwei aktiven
   Betreibern. Ebenfalls ohne Knopf auf der Seite.
8. **Die Anmeldeadresse ist danach wieder frei** — dasselbe Konto lässt sich
   unter derselben Adresse neu anlegen.
9. **Die offenen Sitzungen sind fort.** Gemessen in `sessions`, nicht an der
   Oberfläche.
10. **Der Eintrag `account.deleted` trägt Name, Adresse und Rolle** im
    Zusammenhang.
11. **Vier Lagen der Bilderrunde** (hell/dunkel × 390/1440 px), Überlauf 0 px,
    mit Gegenprobe. Die Seiten sind `/accounts` und `/audit`.

**Was der Lauf ausdrücklich nicht prüft:** die Wirkung auf Kundenkonten (gibt
es nicht), das Verhalten bei zwei gleichnamigen Konten (§9), und die Laufzeit
des Nachtrags über ein grosses Protokoll (§9).

---

## 7. Die Schritte

In dieser Reihenfolge, und die ersten drei gehören in **eine** Auslieferung.

| | Schritt | Warum hier |
|---|---|---|
| 1 | Migration: zwei Spalten **und** der Nachtrag | Der Nachtrag gilt einmal (§3.3) |
| 2 | `booted()` an `AuditEvent` und `Operation`, Lesen mit Rückfall | Ohne ihn ist Schritt 1 ein Feld ohne Zukunft |
| 3 | Anzeige: `/audit`, CSV, `/operations/{id}` | Ohne sie ist die Abschrift ungelesen (§5.2) |
| 4 | `Sessions`: alle Sitzungen eines Kontos | Der Löschweg braucht sie |
| 5 | Löschweg: Route, `destroy()`, zwei Prüfungen, Bestätigung | Erst jetzt darf gelöscht werden |
| 6 | Seite: Knopf, `is_self`, Bestätigung | |
| 7 | Wächter und Brüche (§8) | |
| 8 | Bilderrunde, vier Lagen | |
| 9 | `CHANGELOG.md`, `docs/82 §9` und `CLAUDE.md` nachziehen | Drei Stellen sagen heute das Gegenteil |

**Schritt 9 ist keine Kosmetik.** Nach dem Bau behaupten der Kopf von
`AccountController`, der Kopf von `LastOperator`, der Kommentar an
`LastOperatorTest::test_there_is_no_third_way()` und `docs/82 §9`, dass es das
Löschen nicht gibt.

> **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung — und
> nichts prüft sie.**

---

## 8. Die Wächter

**Zwei bestehende gehen absichtlich rot und müssen erweitert werden — nicht
abgeschaltet:**

- **`LastOperatorTest::test_there_is_no_third_way()`** sucht ein `DELETE` auf
  `accounts/{…}` und meldet, dass der dritte Weg entstanden ist. Er wird zu
  einem Fall, der prüft, dass die Löschroute den Aussperrschutz **fragt** und
  dass der letzte Betreiber an ihr scheitert.
- **`AccountMutationTest::test_every_mutating_account_route_asks_the_guard()`**
  liest den Quelltext nach `LastOperator::permits(` und führt eine Liste
  `HARMLESS` mit Begründung je Methode. `destroy()` gehört **nicht** in diese
  Liste — es muss fragen.

**Neu zu bauen:**

- **`AccountTombstoneTest`** — jede Stelle, die einen Kontonamen anzeigt, hat
  den Rückfall auf die Abschrift; und die Abschrift wird an **einer** Stelle je
  Modell gesetzt, nicht an den Aufrufern. Beide Richtungen, wie
  `OperationOriginTest`: Die erste allein hat den Befund aus `docs/94 §6b` nicht
  gesehen.
- **`SelfDeletionTest`** — die Löschroute weist das eigene Konto ab, und die
  Seite zeigt den Knopf dort nicht. Gemessen an der Wirkung, nicht am Wort.
- **`ActorLabelTest`** — die drei Zustände aus §3.4 ergeben drei verschiedene
  Anzeigen. Der Prüfkörper ist der Fall „kein Konto, keine Abschrift": Er muss
  „System" ergeben und **nicht** „gelöscht".

**Jeder neue Wächter bekommt seinen Eingriff in `tests/waechter-brechen.sh` und
wird einzeln gegengeprüft.** Ein Wächter, der nie rot war, ist kein Wächter.
Für die drei neuen gilt die bekannte Falle: Der Eingriff muss die **Regel**
verletzen und nicht den Code zerstören — und die Kommentare, die den
Vorzustand festhalten, gehören vor dem Suchen abgestreift
(`Tests\Support\WithoutPhpComments`).

---

## 9. Was benannt offen bleibt

- **Zwei Konten mit demselben Namen.** Nach dem Löschen beider sind ihre
  Protokollzeilen nicht mehr auseinanderzuhalten — die Kennung ist `NULL`, und
  die Abschrift trägt nur den Namen. Die Bindung Name → Adresse → Kennung hält
  einmalig der Eintrag `account.deleted`; liegt der ausserhalb des exportierten
  Zeitraums, ist sie nicht zur Hand. **Bewusst so entschieden**, weil das
  Vorbild `subscription_name` es ebenso hält; wer es anders will, nimmt die
  Kennung mit in die Abschrift.
- **`operations.cancelled_by` hat keine Abschrift** (§3.1) und keinen Leser.
  Wer die Anzeige baut, baut beides.
- **Die Laufzeit des Nachtrags** über ein Protokoll mit vielen Zeilen ist
  ungemessen. Auf `cloudsrv24` sind es zum Zeitpunkt dieses Plans wenige
  Tausend; die Grenze kennt niemand.
- **Der Name bleibt dauerhaft im Protokoll.** Für ein Prüfprotokoll ist das der
  Zweck. Sollte die Anforderung „auch der Name muss weg" entstehen, steht sie
  gegen den Zweck des Protokolls und ist eine eigene Entscheidung — keine
  Erweiterung dieses Plans.
- **Kundenkonten** bleiben unberührt (§4). Wird ihr Löschen je gebaut, gilt
  §1.1 nicht mehr: Dann braucht auch `acting_as_account_id` seine Abschrift.
- **`docs/82 §9` wird durch dieses Dokument abgelöst**, sobald Schritt 5
  gebaut ist — vorher nicht.
