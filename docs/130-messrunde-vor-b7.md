# Die Messrunde vor B7 — was eine API v1 an diesem Panel vorfindet

Gefahren am **21. September 2026** im Container, gegen
`claude/messrunde-vor-p9-4c9vzi @ de2b010b`, **vor** der ersten Zeile Plan.
Die Messvorschrift liegt als **`tests/api-messen.php`** daneben und nicht in
einem Sitzungsverlauf.

> **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht noch
> einmal.** (`docs/66`)

**Sie fängt nicht bei null an.** `docs/128` M8 hat den Bestand schon gemessen
und ausdrücklich aufgeschrieben, was es *nicht* sagt: nichts über Tokens,
nichts über eine Route ohne `can:`, nichts über Ratenbegrenzung. Genau diese
Lücken misst diese Runde. Dass sie überhaupt gesucht wurden, ist die Gewohnheit
aus A8: **Wer entscheidet, was als Nächstes gebaut wird, sieht vorher am
Quelltext nach, ob es das schon gibt** — und hier war schon eine Antwort da.

**Jede Messung hat ihre Gegenprobe, und jede sagt, was sie nicht sagt.** Zwei
Messungen dieser Runde haben beim ersten Anlauf etwas anderes gemessen als
ihren Gegenstand, und beide Male hat es die Gegenprobe gefangen.

---

## §0 · Was die Runde umgeworfen hat

**Sechs Dinge, und vier davon ändern die Form, die `docs/129 §3` sich gedacht
hatte.** Zwei davon sind meine eigenen Erwartungen, und beide waren falsch.

1. **Die Vorkehrung für die API steht seit jeher da — und sie ist richtig.**
   `bootstrap/app.php` trägt `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))`
   für ein Merkmal, das es nicht gibt. Ich hatte erwartet, dass eine Anfrage
   ohne Konto unter `api/` die Weiterleitung aus `redirectGuestsTo` bekommt,
   also **302 nach `/login`**. Gemessen sind es **401 ohne Weiterleitung**, und
   eine Ausnahme kommt als JSON statt als HTML (A2).

   > **Eine Vorkehrung für ein Merkmal, das es nicht gibt, kann richtig sein —
   > gemessen, nicht geglaubt.**

2. **Die Vorgabegruppe `api` trägt genau einen Eintrag: `SubstituteBindings`**
   (A1, deckt sich mit `docs/128` M8). Keine Klammer, keine Wache, nichts. Eine
   B7-Route, die dort landet, bindet, bevor irgendetwas klammert.

3. **Die Begründung in `bootstrap/app.php` ist nicht das, was die Messung
   zeigt.** Dort steht seit P7b, eine Bindung vor der Klammer mache aus „nicht
   gefunden" ein „verboten", *„und damit ließe sich abzählen, welche IDs es
   gibt"*. Gemessen (A3) gibt die umgedrehte Reihenfolge **404 für das fremde
   und 404 für das eigene Abonnement**: Die Klammer steht beim Binden im
   Grundzustand, und der verweigert alles. Der Handgriff ist richtig, die
   Begründung daneben nicht — der Schaden ist kein Leck, sondern ein Panel, in
   dem kein Kunde mehr seine eigene Seite sieht.

   > **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist auch
   > dann falsch, wenn der Handgriff daneben richtig ist — und er hält länger
   > als der Handgriff, weil ihn der Nächste liest und glaubt.**

4. **Und den Test, den derselbe Kommentar verspricht, gibt es nicht.**
   *„Ein Test hält die Reihenfolge fest, damit sie nicht beim nächsten Umbau
   still zurückfällt."* Ausgezählt nennt **keine** Datei unter `tests/` diese
   beiden Mittelschichten zusammen; gehalten wird nur das Paar
   `EnforceAccountAccess` vor `EnforceAdminNetwork`
   (`AccountAccessReachTest`).

   > **Eine Zeile, die einen Wächter behauptet, ist teurer als keine — der
   > Nächste baut ihn nicht, weil er ihn für gebaut hält.**

5. **Ein zweites Routenverzeichnis halbiert die Reichweite von achtzehn
   Wächtern, ohne einen einzigen rot zu machen.** Ausgezählt lesen **18
   Dateien unter `tests/`** (dazu `tests/mandant-messen.js` und das
   Bruchskript) `routes/web.php` als **Text**; nur **vier** fragen den Router.

   Die Zahl „vier" ist dabei zweimal berichtigt worden. Ein `grep` nach
   `getRoutes()` meldete **sechs** — `QueryBooleanTest` hat eine **eigene**
   private Methode dieses Namens, die `routes/web.php` liest, und
   `OverviewInventoryTest` nennt den Router nur in einem Kommentar, der sagt,
   was er *nicht mehr* tut.

   > **Ein Ausdruck, der einen Methodennamen sucht, findet jede Methode mit,
   > die so heisst — und den Kommentar, der von ihr erzählt.**
   Der Fall, der am teuersten aussah, war `TenancySweepTest` — sein Kopf sagt
   *„jede Route, die es gibt"*. **Beim Bauen hat sich das als zu weit gelesen
   erwiesen** (23. September): Sein Ausdruck sammelt
   `/subscriptions/{subscription}/(files|sftp|cron)` aus `routes/web.php` und
   hält sie gegen die Liste in `tests/mandant-messen.js`. Der Satz in seinem
   Kopf meint diesen Bestand und nicht den des Panels.

   > **Ein Satz im Kopf eines Wächters beschreibt seine Absicht. Was er misst,
   > steht in seinem Ausdruck.**

   > **Ein Lauf, der zählt, was er kennt, misst sein Gedächtnis.** (aus seinem
   > eigenen Kopf)

6. **Ein Token in der Adresse steht im Zugriffsprotokoll, ein Token im Kopf
   nicht** (A7). Gemessen gegen echtes nginx 1.24.0 mit dem Format, das
   `SiteTemplate::httpConfig()` schreibt: `"$request"` ist die Anfragezeile
   **mitsamt Abfrageteil**.

---

## §1 · Der Bestand, ausgezählt

| | |
|---|---|
| `routes/api.php` | **gibt es nicht** — `routes/` trägt `web.php` und `console.php` |
| `withRouting(…)` | `web`, `commands`, `health` — **kein `api`** |
| Produktionsabhängigkeiten | **drei**: `inertiajs/inertia-laravel`, `laravel/framework`, `laravel/tinker` |
| Sanctum, Passport, OpenAPI | **kein Treffer im ganzen Baum** |
| Token-Tabelle, Token-Modell | keine; 25 Modelle, keines davon eines |
| Routen heute | 169, davon 70 GET, **143 mit `can:`** |
| `throttle`-Mittelschicht | **keine.** `LoginThrottle` ist handgebaut und rechnet auf IP + Adresse |
| Wächter, die den Router fragen | **4**: `RouteAuthorizationTest`, `SubscriptionReachTest`, `RemoteAccessTest`, `RedirectTargetTest` |
| Wächter, die `routes/web.php` als Text lesen | **18** |

---

## §2 · A0 bis A6 — die Messungen

Gefahren mit `php tests/api-messen.php`. **Jede Anfrage bekommt eine frische
Anwendung**, und das ist kein Beiwerk: `Tenancy` ist ein Singleton, zwei
Anfragen in einem Prozess teilen sich seinen Zustand, php-fpm tut das nicht.

**Der erste Anlauf von A3 ist genau daran gescheitert.** Die Gegenprobe „ohne
Wache" lief als *dritte* Anfrage und gab **200** — sie mass die Klammer der
ersten. Als erste Anfrage eines Prozesses gibt dieselbe Route **404**.

> **Ein Prüfstand, der mehrere Anfragen in einem Prozess fährt, misst den
> Zustand, den die vorige hinterlassen hat.** (`docs/103 §1`, hier zum zweiten
> Mal bezahlt)

### A0 · Ladebeleg

`GET /api/ladebeleg` → **200 `{"ok":true}`**. Ohne diese Zeile ist jede Null
darunter von „nichts gemessen" nicht zu trennen.

### A1 · Die Mittelschichtgruppen, am gebooteten Kernel

| Gruppe | Einträge | |
|---|---|---|
| `web` | **13** | `ApplyTenancy` an **7.**, `SubstituteBindings` an **8.** Stelle |
| `api` | **1** | `SubstituteBindings` — und sonst nichts |

### A2 · Was `api/*` heute tut, ohne dass es eine API gibt

| | |
|---|---|
| offen, ohne Wache | 200 |
| hinter `auth`, ohne Konto | **401**, keine Weiterleitung |
| Ausnahme unter `api/` | 500 **`application/json`** |
| **Gegenprobe** · Ausnahme unter `web/` | 500 `text/html; charset=utf-8` |

Die Gegenprobe ist der Punkt: Ohne sie sagte das JSON nichts über `api/*`.

### A3 · Trägt die Mandantenklammer eine Wache ohne Sitzung?

| Reihenfolge | eigenes Abo | fremdes Abo | ID, die es nicht gibt |
|---|---|---|---|
| **Klammer vor Bindung** | **200** | **404** | **404** |
| Bindung vor Klammer | **404** | 404 | — |
| **Gegenprobe** · ohne Wache | **404** | — | — |

**Damit ist die Kernfrage von B7 beantwortet: Die Klammer braucht keine zweite
Fassung.** `ApplyTenancy` fragt `$request->user()` und nicht die Sitzung;
`forAccount()` unterscheidet Admin → `allowAll()`, Kunde → eigene Abonnements,
Zusatzbenutzer → zugewiesene. Eine Token-Wache, die das Konto vor `ApplyTenancy`
einsetzt, bekommt dieselbe Klammer wie eine Sitzung.

Und das Abnahmekriterium — *„eine fremde ID bekommt 404 und nicht 403"* — ist
kein Bau, sondern eine **Eigenschaft der Reihenfolge**. Die Zeile „ID, die es
nicht gibt" steht daneben, weil erst sie zeigt, dass beide Fälle wirklich
ununterscheidbar sind.

### A4 · Die Liste, wenn die Klammer nie gesetzt wurde

| | |
|---|---|
| mit Wache | 200 `[1]` |
| ohne Wache | **200 `[]`** |

`docs/128` M8 sagt es voraus, gemessen ist es hier zum ersten Mal: **Die
Bindung fällt auf, die Liste nicht.** Ein Kunde ohne Abonnements und eine
Route, die den Mandanten zu setzen vergisst, sehen von aussen gleich aus.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler — und `200 []` ist die Antwort, die niemand
> meldet.**

### A5 · Was die Prüfung eines Tokens je Anfrage kostet

| | |
|---|---|
| `sha256` | **0,00011 ms** |
| `bcrypt`, Kostenfaktor 12 | **193,13 ms** |
| Faktor | **≈ 1 700 000** |

Ein Token ist achtundvierzig Zeichen aus einem Zufallsgenerator und kein
Passwort eines Menschen; der Arbeitsfaktor von bcrypt schützt eine Entropie,
die hier nicht fehlt. Bei zwölf Arbeitern im Panel-Pool (`docs/128` M9) wären
193 ms je Anfrage die Obergrenze des Ganzen.

### A6 · Der Grundzustand der Klammer, ohne jede Anfrage

| | |
|---|---|
| frisch · `unrestricted()` | `false` |
| frisch · `isSet()` | `false` |
| frisch · `Subscription::count()` | **0** |
| **Gegenprobe** · nach `allowAll()` | **2** |

Die Null ist eine Messung, weil daneben eine Zwei steht.

---

## §3 · A7 · Wie ein Token reist — und was danach im Protokoll steht

Gemessen gegen **nginx 1.24.0** in einem Wegwerf-Aufbau unter `/var/tmp`, mit
dem Format aus `SiteTemplate::httpConfig()` und daneben nginx' Vorgabe
`combined`.

```
"GET /api/v1/subscriptions?token=GEHEIM-IN-DER-ADRESSE HTTP/1.1" 200 3 …
"GET /api/v1/subscriptions HTTP/1.1" 200 3 …
```

| | |
|---|---|
| Token im Abfrageteil, im Protokoll gefunden | **1** |
| **Gegenprobe** · Token im Kopf `Authorization`, im Protokoll gefunden | **0** |

Beide Richtungen in **einer** Ablesung. Der Grund ist `"$request"` — die
Anfragezeile mitsamt Abfrageteil —, und er gilt für **beide** Formate.

**Das ist die vierte Grenze an einer neuen Stelle.** Ein Geheimnis, das als
Argument reist, steht auf der Vorgangsseite; eines, das in der Adresse reist,
steht im Protokoll — vierzehn Tage lang (`rotate 14`), in einer Datei, die
`0640 <benutzer> adm` gehört, und auf einer Seite, die dieses Panel selbst
anzeigt.

> **Ein Geheimnis, das in der Adresse reist, steht in einer Datei, die das Panel
> dem Betreiber vorliest.**

---

## §4 · Die achtzehn Wächter, die `routes/web.php` als Text lesen

Ausgezählt und einzeln beurteilt. Die Frage ist nicht „ist er blind?" — das
sind alle achtzehn —, sondern **„gilt seine Regel für `api/` auch?"**

**Gilt auch dort (5):**

| | |
|---|---|
| ~~`TenancySweepTest`~~ | **Berichtigt am 23. September 2026:** Sein Kopf sagt „jede Route, die es gibt", und gemeint ist damit der Bestand, den `tests/mandant-messen.js` misst — die `{subscription}`-Routen unter `files`, `sftp` und `cron`. Über `api/` sagt er nichts, und geweitet gehört er nicht. |
| `PolicyReachTest` | führt zu jeder Fähigkeit ein Weg — die Gegenrichtung |
| `AdminAbilityTest` | jede Adminfähigkeit gehört einer Rolle |
| `AdminPayloadTest` | was eine Rolle nicht sehen darf, steht nicht in der Antwort |
| `QueryBooleanTest` | ein Wahrheitswert, der durch eine Adresse reist, ist ein Wort |

**Gilt dort nicht (13)** — sie reden über Seiten, Knöpfe, Brotkrumen und
Formulare: `OperationOriginTest`, `LinkReachTest`, `StreamNotAPageTest`,
`RankReachTest`, `CronPageReachTest`, `CronPreviewTest`, `CronPayloadTest`,
`OperatorControlTest`, `BulkActionTest`, `AccountMutationTest`,
`RoleGateTest`, `RedirectTargetTest` (sein Textfall; sein neuer Fall fragt den
Router), `BreakScriptTest`.

**Was daraus folgt, ist ein eigener Wächter — und `docs/129 §8` lag näher an
der Wahrheit als meine erste Korrektur daran.** Hier stand, ein neuer
`ApiTenancyTest` wäre die zweite Fassung der Regel von `TenancySweepTest`.
Das war aus dessen **Kopf** geschlossen und nicht aus seinem Ausdruck; gebaut
ist die Regel jetzt als `ApiEmptyListTest`, und sie hält mehr als die
Klammer — auch den Fall `200 []`, den A4 gemessen hat.

> **Wer entscheidet, ob eine Regel schon jemand hält, liest den Ausdruck und
> nicht den Kopf.**

---

## §5 · Die Entscheidungen des Betreibers — 21. September 2026

| | |
|---|---|
| Tokens | **eigene Tabelle, keine neue Abhängigkeit** |
| Wer eines anlegt | **Kunden und Zusatzbenutzer** |
| Umfang von v1 | **nur lesen** |
| OpenAPI | **von Hand, mit Wächter in beide Richtungen** |

Die erste ist durch A5 gedeckt und die dritte durch A3: Eine lesende Fläche
stellt keine neue Frage an Vorgänge, Lebensläufe und den Agenten.

---

## §6 · Was im Container nicht messbar ist

Keines davon darf geschätzt werden.

- **Was der Panel-Pool unter Last aushält.** `docs/128` M9 hat zwölf Arbeiter
  und 16 s Wartezeit an einem Pool von zwei gemessen; was eine ungedrosselte
  API dort anrichtet, sagt nur der echte Server.
- **Ob ein Token im Protokoll des Panel-Vhosts landet.** `PanelVhost` schreibt
  `access_log <pfad>;` **ohne Formatnamen**, also nginx' `combined` — gelesen
  und nicht gemessen; A7 misst den Kundenblock.
- **Was eine Antwort über die echte Leitung kostet**, mit echten Beständen
  statt zwei Zeilen in sqlite.

---

## §7 · Was daraus für den Plan folgt

1. **Eine eigene Gruppe, und ihre Reihenfolge ist die des `web`-Blocks.**
   Token-Wache → `ApplyTenancy` → `SubstituteBindings` → `can:`. Die
   Vorgabegruppe `api` ist nicht brauchbar (A1), und der Grund steht gemessen
   in A3.
2. **Ein Wächter über genau diese Reihenfolge** — für `web` **und** `api`. Er
   fehlt heute für beide, obwohl ein Kommentar ihn seit P7b behauptet.
3. **`TenancySweepTest` wird geweitet, statt daneben einen zweiten zu stellen.**
4. **Der Token reist im Kopf `Authorization`, nie im Abfrageteil** — mit einem
   Wächter, der genau das hält (A7).
5. **`sha256` über ein Token mit voller Entropie, ein eindeutiger Index** — kein
   bcrypt (A5).
6. **Die leere Liste braucht ihren eigenen Wächter** (A4): Eine Route, die den
   Mandanten nicht setzt, ist von einem Kunden ohne Abonnements nicht zu
   unterscheiden. Gemessen wird an der **Wirkung** — eine Route ohne
   `ApplyTenancy` muss rot sein.
7. **Eine Ratenbegrenzung gehört dazu**, und es gibt heute keine einzige.

---

## §8 · Korrekturen

**An `bootstrap/app.php`:** Die Begründung der Reihenfolge nennt einen Ausgang
(403 statt 404), den die Messung nicht zeigt, und behauptet einen Test, den es
nicht gibt. Beides gehört berichtigt — der Handgriff bleibt.

**An `docs/129 §8`:** `ApiTenancyTest` als eigener Wächter wäre die zweite
Fassung der Regel von `TenancySweepTest`.
