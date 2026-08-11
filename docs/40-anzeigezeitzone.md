# 40 — Die Zeitzone der Anzeige

**Stand: gebaut, am 11. August 2026, ausgeliefert mit `v0.5.1-rc.11`.** Die
Klasse aus §4 steht samt Wächter, die achtzehn Aufrufe gehen darüber, das Feld
sitzt auf der neuen Seite „Allgemein", und der Bruch steht im Wächterskript.

**Zwei Stellen hat der Bau anders vorgefunden, als dieses Dokument sie
beschrieb**, und beide stehen unten korrigiert statt stillschweigend gebogen:
das Beispiel in §3.2 prüfte die falsche Tagesgrenze (bei einem *positiven*
Versatz kippt der frühe Morgen, nicht der Abend), und für das Feld gab es
keinen Ort — die fünf vorhandenen Einstellungsseiten sind themengebunden, das
Profil gehört einem Konto.

---

## 1. Der Anlass

Am 10. August 2026 hat der Betreiber im Protokoll einen Eintrag um `12:31:26`
gesehen und gefragt, ob das deutsche Zeit sei. Es war UTC: `config/app.php`
setzt `'timezone' => 'UTC'`, `AuditQuery` gibt `created_at` als
`toDateTimeString()` heraus, und die Zeichenkette geht unverändert in die Seite
— der Browser rechnet nichts um. In der Sommerzeit sind das **zwei Stunden**
Unterschied zu dem, was der Betreiber auf seiner Uhr liest.

**Das Speichern in UTC ist richtig und bleibt.** Die Frage war nur, ob die
Anzeige umrechnet. Sie war es wert, gestellt zu werden: Ein Zeitstempel, den man
falsch liest, ist schlimmer als keiner — er sieht aus wie eine Auskunft.

Die Filter „Von" und „Bis" im Protokoll vergleichen ebenfalls gegen UTC. Wer
abends nach 22:00 Uhr deutscher Zeit „heute" filtert, bekommt einen Tag, der
zwei Stunden vorher zu Ende ging. **Das ist der Teil, der still bricht**, denn
die Seite zeigt dann eine Zeile, die ihr eigener Filter nicht findet.

---

## 2. Der Umfang, gemessen

**Achtzehn Stellen** in `app/` geben eine Zeit heraus, alle über
`toDateTimeString()` auf einem Carbon-Objekt, und alle in Controllern oder
Unterstützungsklassen. **Kein Datum entsteht in einer Vue-Komponente.** Das ist
der Grund, warum diese Änderung klein sein kann: eine Stelle, die formatiert, und
achtzehn Aufrufe, die auf sie zeigen.

Betroffen sind: `AuditQuery`, `OperationController`, `OperationStreamController`,
`DomainController`, `SubscriptionController`, `CustomerController`,
`OverviewController`, `ProfileController`, `AuditController` (CSV),
`Settings`, `Dumps`, `MailSettingsController`.

---

## 3. Die drei Entscheidungen des Betreibers

Vom 10. August 2026.

### 3.1 Serverweit, nicht je Konto

Eine Einstellung des **Servers** — ein Wert in `settings`, gesetzt vom Admin,
gilt für alle Betrachter. Kein Feld im Kundenprofil.

**Warum das die kleinere Zusage ist:** Eine Zone je Konto verlangt eine Antwort
auf „was sieht ein Konto ohne eigene Wahl", und die Antwort wäre wieder eine
serverweite Einstellung — also dieselbe Sache, mit einer zweiten Ebene darüber.
Wer sie später will, baut sie auf diese auf und nicht neben sie.

### 3.2 Die Filter rechnen mit

„Von" und „Bis" im Protokoll nehmen ein Datum in der Anzeigezone entgegen und
werden vor der Abfrage nach UTC gedreht. Ohne das zeigt die Seite `14:31` und
findet die Zeile nicht, wenn man nach diesem Tag sucht.

**Das ist die Stelle mit dem Wächter**, und zwar an der Grenze: Ein Eintrag muss
beim Filter auf *seinen* Ortszeit-Tag erscheinen und beim Nachbartag nicht —
obwohl er in UTC am anderen Tag liegt. Eine Prüfung, die nur mitten am Tag
misst, ist grün und beweist nichts.

**Hier stand `23:30` Ortszeit, und für `Europe/Berlin` trifft das nicht zu.**
Gemessen am 11. August 2026: `23:30` Ortszeit ist `21:30` UTC — derselbe Tag.
Bei einem **positiven** Offset kippt nicht der Abend, sondern der frühe Morgen:
`00:30` Ortszeit ist `22:30` UTC des Vortags. Für eine Zone westlich von
Greenwich ist es umgekehrt.

> **Ein Beispiel, das die Richtung nicht mitdenkt, prüft die falsche Grenze.**

`ClockTest` prüft deshalb **beide** Enden des Tages.

### 3.3 Das CSV bleibt UTC

Der Export ist ein Beleg, den jemand aufhebt. **Ein Zeitstempel ohne Zone in
einer Datei, die drei Jahre liegt, ist eine Falle** — er wird gelesen, wenn der
Server längst umgezogen und die Einstellung eine andere ist. Die Zone steht
deshalb in der Kopfzeile der Datei, und die Werte bleiben UTC.

Die Oberfläche darf umrechnen, weil sie die Zone daneben zeigen kann und weil
niemand eine Seite archiviert.

---

## 4. Der Zuschnitt

**Eine Klasse, die aus UTC eine Anzeige macht** — dieselbe Bauform wie
`SrvPanel\Agent\Names::fqdn()` in CLAUDE.md: die *einzige* Stelle, die diese
Frage beantworten darf. Sie ist viermal neu erfunden worden, bevor es dafür
einen Wächter gab; hier wird der Wächter gleich mitgebaut.

    Clock::display(?CarbonInterface): ?string     UTC → Anzeigezone
    Clock::toUtc(string $date, bool $end): string Filtergrenze → UTC
    Clock::zone(): string                         die eingestellte Zone
    Clock::label(): string                        „MESZ (UTC+2)" für die Seite

Dazu:

- ein Feld in „Einstellungen", gegen `DateTimeZone::listIdentifiers()` geprüft —
  **keine Freitexteingabe**, denn der Wert geht in `CarbonInterface::setTimezone()`
  und ein unbekannter Name wirft dort;
- die achtzehn Aufrufe umgestellt;
- `TimeDisplayTest`: *Jede Zeitangabe, die die Oberfläche erreicht, kommt aus
  `Clock` — keine aus `toDateTimeString()`.* Mit Untergrenze, damit der Wächter
  nicht ins Leere läuft, und mit der Ausnahme für das CSV, **begründet im Wert**
  wie in `Shielding::EXEMPT`;
- der Bruch dazu in `tests/waechter-brechen.sh`, in beide Richtungen: eine
  Anzeige, die wieder roh formatiert, und ein Filter, der nicht mitrechnet.

**Nicht betroffen:** Was in eine Datei oder einen Dateinamen geht
(`Dumps::…format('Ymd-His')`), und was der Agent protokolliert. Beides ist keine
Anzeige, sondern ein Beleg — und für Belege gilt 3.3.

---

## 5. Warum es nicht in P5b gehört

Es berührt jede Seite mit einem Zeitstempel, und P5b ist mitten in seinem
Abnahmelauf. **Eine Änderung, die während einer Abnahme jede Zeitangabe
verschiebt, erklärt eine Messung, statt sie zu bestätigen** — und die
Zwischenabnahme in `docs/39` vergleicht Zeitpunkte zwischen Vorgangsliste,
Protokoll und Serverausgabe.

Gebaut wird, sobald Punkt 9 aus `docs/39` durch ist.
