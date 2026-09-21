# B7 — API v1, Tokens und OpenAPI

Geschrieben am **21. September 2026**, **nach** der Messrunde (`docs/130`) und
nach den vier Entscheidungen des Betreibers vom selben Tag. `docs/129 §3` sagt
über B7: *„das grösste und das unsicherste … weil sein Zuschnitt erst noch
entsteht."* Dieser Plan ist der Zuschnitt.

---

## §0 · Was beim Ausschreiben umgefallen ist

**Drei Zeilen, und alle drei aus der Messrunde.**

1. **`ApiTenancyTest` als eigener Wächter fällt weg.** `docs/129 §8` sieht ihn
   vor — *„Jede Route unter `api/` durchläuft die Mandantenklammer"*.
   `TenancySweepTest` beansprucht in seinem eigenen Kopf schon *„jede Route,
   die es gibt"*. Ein zweiter daneben wäre die zweite Fassung derselben Regel.

   > **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von
   > beiden ist der Ort, an dem man nachsieht.**

2. **„404 und nicht 403" ist kein Bau, sondern eine Eigenschaft der
   Reihenfolge** (`docs/130` A3). Was gebaut werden muss, ist nicht das
   Verhalten, sondern der **Wächter**, der die Reihenfolge hält — und der fehlt
   heute auch für `web`.

3. **Der teure Fall ist nicht die fremde ID, sondern die leere Liste.** Eine
   gebundene fremde ID gibt 404 und fällt auf. Eine Liste ohne Klammer gibt
   `200 []` und fällt niemandem auf (`docs/130` A4). Das Abnahmekriterium aus
   `docs/129 §9` misst den lauten Fall; der Plan misst beide.

---

## §1 · Was B7 ist

Eine **lesende** Schnittstelle unter `api/v1`, mit Tokens, die ein Kunde oder
ein Zusatzbenutzer selbst anlegt, und einer OpenAPI-Beschreibung, die im Repo
steht und gegen die Routen gehalten wird.

**Was sie nicht wird, steht in §9** — und zwar ausgeschrieben, weil eine
Aufzählung nur dann eine Entscheidung ist, wenn das Fehlende darin steht
(`docs/105`).

---

## §2 · Die Entscheidungen des Betreibers

| | |
|---|---|
| Tokens | **eigene Tabelle, keine neue Abhängigkeit** |
| Wer eines anlegt | **Kunden und Zusatzbenutzer** |
| Umfang von v1 | **nur lesen** |
| OpenAPI | **von Hand, mit Wächter in beide Richtungen** |

Die erste ist durch `docs/130` A5 gedeckt (bcrypt kostet je Anfrage 193 ms,
sha256 0,00011 ms), die dritte durch A3: Eine lesende Fläche stellt keine neue
Frage an Vorgänge, Lebensläufe und den Agenten.

---

## §3 · Die Form

### Die Ablage

`api_tokens` — `account_id` (Fremdschlüssel, `cascadeOnDelete`), `name`,
`token_hash` (eindeutig), `last_used_at`, `created_at`, `updated_at`.

**Gehasht mit `sha256` und nicht mit bcrypt**, und das ist gemessen und nicht
geschmackssache: Ein Token sind 48 Zeichen aus `random_bytes()`, kein Passwort
eines Menschen — der Arbeitsfaktor von bcrypt schützt eine Entropie, die hier
nicht fehlt, und kostet dafür das Tausendfache je Anfrage.

> **Ein Arbeitsfaktor, der eine fehlende Entropie ausgleicht, ist dort, wo sie
> nicht fehlt, nur noch Preis.**

**Der Klartext steht genau einmal auf dem Bildschirm** — beim Anlegen, und
danach nie wieder. Dieselbe Regel wie beim Wiederherstellungscode des zweiten
Faktors.

**`cascadeOnDelete` und nicht `nullOnDelete`.** Ein Token ohne Konto ist kein
Protokolleintrag, dessen Handelnder fehlt, sondern ein Schlüssel ohne Schloss —
er gehört weg. Das ist der Unterschied zu `audit_events`, und er gehört
aufgeschrieben, weil `docs/82` für die andere Richtung entschieden hat.

### Die Wache

`App\Http\Middleware\AuthenticateToken` liest **ausschliesslich** den Kopf
`Authorization: Bearer …`, hasht, schlägt über den eindeutigen Index nach und
setzt das Konto mit `Auth::setUser()`.

**Nie aus dem Abfrageteil**, und das ist gemessen (`docs/130` A7): nginx
schreibt `"$request"` mitsamt Abfrageteil ins Zugriffsprotokoll — vierzehn Tage
lang, in eine Datei, die dieses Panel selbst anzeigt.

> **Ein Geheimnis, das in der Adresse reist, steht in einer Datei, die das Panel
> dem Betreiber vorliest.**

**Ein Adminkonto kommt hier nicht durch.** Nicht weil die Anlegeroute für ihn
unerreichbar ist — das ist sie auch —, sondern weil `forAccount()` für einen
Admin `allowAll()` ruft: Ein Bearer-Token ohne Klammer über den ganzen Server,
ohne zweiten Faktor und ohne Netzbeschränkung, wäre die grösste Fläche, die
dieses Panel je hatte. Geprüft wird beim **Anmelden** und nicht beim Anlegen,
weil ein Konto seinen Typ überleben kann und der Token dann eine Zusage trüge,
die niemand mehr gemacht hat.

### Die Gruppe

Die Vorgabegruppe `api` trägt **einen** Eintrag — `SubstituteBindings`, und
sonst nichts (`docs/130` A1). Sie wird deshalb genauso behandelt wie `web`:
`SubstituteBindings` herausgenommen und hinter der Klammer wieder eingesetzt.

    AuthenticateToken → EnforceAccountAccess → ApplyTenancy
                      → SubstituteBindings → can:

**Dieselbe Reihenfolge und dieselbe Begründung wie im `web`-Block** — eine
Fassung, nicht zwei.

---

## §4 · Die Fläche von v1

| | |
|---|---|
| `GET /api/v1/me` | Wer das Token trägt, und welche Abonnements es erreicht |
| `GET /api/v1/subscriptions` | die Abonnements, die die Klammer durchlässt |
| `GET /api/v1/subscriptions/{id}` | eines davon |
| `GET /api/v1/subscriptions/{id}/domains` | seine Domains |
| `GET /api/v1/subscriptions/{id}/metrics` | die Tageswerte aus B3 |
| `GET /api/v1/domains/{id}` | eine Domain |
| `GET /api/v1/openapi.yaml` | die Beschreibung, offen |

**Sechs lesende Routen und eine Beschreibung.** Jede trägt `can:` wie die 143
Routen des Panels, und keine bekommt einen eigenen Rechteweg.

---

## §5 · OpenAPI

Eine Datei im Repo, von Hand gepflegt, ausgeliefert unter
`GET /api/v1/openapi.yaml` ohne Anmeldung — eine Beschreibung ist keine
Auskunft über den Bestand.

**Gehalten wird sie in beide Richtungen**: Jede Route unter `api/v1` steht in
der Beschreibung, und jeder Pfad der Beschreibung ist eine Route. Die zweite
Richtung ist die, an der ein toter Eintrag wirklich entsteht — bei einer
Umbenennung trägt man den neuen Pfad nach, und der alte bleibt liegen.

---

## §6 · Die Wächter

Für jede Regel einer, und jeder wird gebrochen; der Eingriff kommt in
`tests/waechter-brechen.sh` **vor die Bilanz** und wird einzeln gegen seinen
eigenen Fall gefahren.

| | hält |
|---|---|
| `MiddlewareOrderTest` | Die Klammer steht vor der Bindung — in **beiden** Gruppen. Gemessen an der **Wirkung** durch die Tür und nicht an der Liste: eine umgedrehte Gruppe muss für das **eigene** Abonnement 404 geben. Er schliesst die Lücke, die ein Kommentar seit P7b für geschlossen erklärt. |
| `TenancySweepTest` *(geweitet)* | liest **beide** Routenverzeichnisse. Seine Zusage lautet „jede Route, die es gibt", und mit einem zweiten Verzeichnis wäre sie falsch. |
| `ApiTokenStorageTest` | Der Klartext steht nirgends in der Ablage; gemessen an der **Spalte** und nicht an der Absicht. Und die Gegenrichtung: derselbe Klartext findet seine Zeile. |
| `ApiTokenTransportTest` | Die Wache liest den Kopf und **nie** `$request->query()`. Gemessen an der Wirkung: Dasselbe Token im Abfrageteil ergibt 401. |
| `ApiEmptyListTest` | Eine `api/v1`-Route ohne `ApplyTenancy` ist rot. Gemessen an der **Antwort**: `200 []` gegen `200 [1]`, und die Gegenprobe ist ein Kunde, der wirklich nichts hat. |
| `OpenApiReachTest` | Beide Richtungen zwischen Beschreibung und Routen. |
| `ApiThrottleTest` | Jede Route unter `api/` trägt eine Begrenzung — oder steht mit Grund daneben. |

**Und einer, der schon fällig ist und nicht zu B7 gehört:**
`BreakScriptTest::test_every_intervention_checks_its_own_file` trennt die
Abschnitte an `echo "── ` und sieht die **298** Abschnitte in der zweiten
Schreibweise `== … ==` nicht. Gemessen erreicht er **1167 von 1558** Eingriffen;
**391** liegen ausserhalb. Gekreuzt ist heute keines der Paare — was gehalten
wird, stimmt also, und nichts hält es.

> **Ein Zustand, der stimmt und den nichts hält, ist von einem, der nicht
> stimmt, nur durch Glück getrennt.**

---

## §7 · Das Abnahmekriterium

`docs/129 §9` sagt: *„Ein Token eines Kunden liest über `api/v1` genau seine
Abonnements — und dasselbe Token an einer fremden ID bekommt `404` und nicht
`403`."* Das bleibt, und **zwei Punkte kommen dazu**, weil der Lauf sonst den
lauten Fall misst und den leisen nicht:

1. Ein Token eines Kunden liest genau seine Abonnements; eine fremde ID gibt
   **404**, und eine ID, die es gar nicht gibt, gibt **dieselbe** Antwort.
2. **Ein Kunde ohne Abonnements und eine Route ohne Klammer sind
   unterscheidbar** — die erste Antwort ist `200 []`, die zweite gibt es nicht,
   weil der Wächter sie vorher aufhält.
3. **Das Token steht nach dem Lauf in keinem Zugriffsprotokoll** — `grep` über
   `access.log` und `access.log.1`, mit der Gegenprobe, dass dasselbe Token im
   Abfrageteil dort **steht**.

Punkt 1 und 3 dürfen nicht ausfallen.

---

## §8 · Was auf dem Server zu messen bleibt

- **Was eine Ratenbegrenzung über den Datenbank-Cache je Anfrage kostet.** Auf
  dem Server steht `CACHE_STORE=database` (`PanelProvision`), also ein Lesen
  und ein Schreiben je Anfrage. `LoginThrottle` geht denselben Weg und wird
  dreimal am Tag gefragt; eine API wird es öfter.
- **Was der Panel-Pool unter Last aushält.** `docs/128` M9: zwölf Arbeiter, und
  zwei belegte lassen die nächste Anfrage 16 s warten.
- **Ob ein Token im Protokoll des Panel-Vhosts landet.** `PanelVhost` schreibt
  `access_log` ohne Formatnamen, also nginx' `combined` — gelesen, nicht
  gemessen.

---

## §9 · Was B7 ausdrücklich **nicht** wird

- **Kein Schreiben.** Kein Anlegen, kein Ändern, kein Löschen, kein Anstossen
  eines Vorgangs. Ein Vorgang ohne Oberfläche braucht einen Weg, seinen Ausgang
  zurückzugeben, und das ist B8s Frage.
- **Kein Token für Administratoren und Betreiber.** `forAccount()` ruft für sie
  `allowAll()`.
- **Keine Abilities je Token.** Ein Token kann, was sein Konto kann — eine
  zweite Rechteachse wäre eine zweite Fassung der Policy.
- **Kein Ablaufdatum und keine Erneuerung.** Ein Token gilt, bis es gelöscht
  wird. Eine Frist ohne Erinnerung ist ein Ausfall zur Unzeit.
- **Kein v2 und keine Fassungsverhandlung.** `api/v1` steht im Pfad, und mehr
  Mechanik braucht eine Fassung, die es nicht gibt.
- **Keine Ausfuhr von Protokollzeilen.** Was `/logs` dem Betreiber zeigt, bleibt
  dort.
