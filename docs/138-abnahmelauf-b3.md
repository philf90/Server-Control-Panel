# B3 — der Abnahmelauf für die verdichtete Tabelle

Ausgeschrieben am 28. September 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/129 §9`:

> Nach dreissig Nächten stehen dreissig Zeilen je Abo und Kennzahl, und die
> einunddreissigste Nacht löscht die erste.

Gebaut ist B3 seit dem 21. September und ausgeliefert seit `v0.9.0-rc.1`; der
Plan ist `docs/129 §6`, die Begründungen stehen im CHANGELOG unter „B3 — die
verdichtete Tabelle". Gefahren wird gegen die installierte Fassung; §1 Block 1
fragt sie, bevor irgendetwas anderes gemessen wird. Ausgeschrieben war der Lauf
gegen `0.9.0-rc.6`. **Seit dem Abend des 28. September läuft auf `cloudsrv24`
`0.9.0-rc.7`, die Freigabe mit der Behebung aus §5**; Teil 1 wird gegen sie
gefahren, und was davon gefahren ist, steht in §7.

**Der Lauf hat zwei Teile, weil das Kriterium an der Uhr hängt.** Teil 1 ist
heute fahrbar: Er liest den Bestand, bestimmt den ersten Tag und rechnet daraus
die beiden Nächte, an denen Teil 2 misst. Teil 2 ist derselbe Block an Nacht 30
und an Nacht 31 — zwei Messungen an derselben Stelle, die eine Änderung
belegen.

**Und er hat beim Ausschreiben einen Befund am Prüfling gebracht, bevor er
gefahren wurde** (§0 Punkt 1): Zwei der sechs Kennzahlen eines Abonnements
schreibt kein Lauf. Teil 1 misst, ob das auf dem Server genauso steht; für
diese beiden kann Teil 2 erst nach einer Behebung etwas zeigen (§5).

**Behoben ist er am selben Tag**, zusammen mit §0 Punkt 2, nachdem der
Betreiber beide Fragen aus §6 wie vorgeschlagen entschieden hatte. Solange auf
`cloudsrv24` eine Fassung ohne die Behebung läuft, gilt Teil 1, wie er dasteht;
was sich mit ihr an den Erwartungen ändert, steht bei jedem Block und in §5.
**Seit `0.9.0-rc.7` gelten die Erwartungen „mit der Behebung".** Block 4 ist
dafür vor dem Fahren berichtigt worden, und Block 0 ist dazugekommen (§7).

**Teil 2 ist vom 21. bis 31. Oktober 2026 gefahren** (§8), gegen
`0.9.0-rc.18`. Jede vorausgerechnete Zahl hat der Server getroffen. Zwei
Erwartungen in §2 stammten noch aus der Zeit vor der Behebung; berichtigt
sind sie dort. **Der Betreiber hat B3 am 31. Oktober 2026 abgenommen**
(§8, „Die Abnahme").

---

## §0 · Was beim Ausschreiben umgefallen ist

**Vier Zeilen, und zwei davon betreffen den Prüfling.**

**1 · Platz und Datenbanken schreibt niemand.** `DailyMetric::ofASubscription()`
nennt sechs Kennzahlen: die vier aus dem Zugriffsprotokoll und dazu `disk_mb`
und `database_bytes`. Geschrieben wird die Tabelle an genau einer Stelle,
`Daily::record()`, und die legt vier ab — die, die `web.access.count` liefert.
`History` liest für die Kacheln „Speicherplatz" und „Datenbanken" die beiden
anderen, und nichts schreibt sie. Gemessen werden beide durchaus:
`srvpanel:usage` läuft alle fünfzehn Minuten, legt sie aber als
**gegenwärtigen** Wert ab (`subscriptions.disk_used_mb`,
`databases.size_bytes`), und der Commit von B3 hat `MeasureUsage` nicht
angefasst.

Im Container nachgebaut (MariaDB 10.11.14, Schema aus den Migrationen, drei
Abonnements, geschrieben über den **echten** `Daily::record()`): nach sechs
Tagen 40 Zeilen je Tabelle, **keine davon `disk_mb` oder `database_bytes`** —
und die Kacheln stehen auf `disk —` und `databases —`, während daneben 1000 MB
und 3 145 728 B gemessen sind (§1a).

**Gesehen hat es kein Wächter, und das liegt an ihrer Aufteilung.**
`DailyMetricsTest` prüft den Schreiber an den vier Kennzahlen, die er schreibt.
`DailyHistoryTest` legt die Zeilen **von Hand** an, und zwar ausdrücklich, weil
er den Leser prüft und nicht den Schreiber. Jeder hält seine Seite; die Naht
zwischen der Messung und der Tabelle hält keiner.

> **Zwei Prüfungen, die je eine Seite einer Naht mit einem selbst geschriebenen
> Wert füttern, prüfen die Naht nicht.** Der Satz steht seit `docs/102` in
> CLAUDE.md; hier fehlt nicht der richtige Wert, sondern der Schreiber.

Damit ist das Kriterium für zwei der sechs Kennzahlen **nicht erfüllbar**, und
die Abonnementseite zeigt zwei ihrer fünf Kacheln leer. Das ist ein Befund an B3
und an B4 zugleich; behoben wird er nicht in diesem Lauf, sondern nach einer
Entscheidung (§6 Frage 1). **Entschieden und gebaut am selben Tag** (§5).

**2 · Ein Tag ohne Anfrage bekommt keine Zeile.** Die Tage kommen aus den
Zeilen der Protokolle: `AccessLog::countFile()` legt einen Tag an, wenn eine
Zeile ihn trägt. Eine Domain, auf die an einem Tag niemand zugreift, liefert für
ihn nichts, und `Daily::record()` legt dann weder in `domain_metrics` noch in
`subscription_metrics` eine Zeile ab. „Dreissig Zeilen je Abo und Kennzahl" gilt
damit nur für Abonnements mit mindestens einer Anfrage an **jedem** Tag — und
`cloudsrv24` ist ein Server ohne nennenswerten Verkehr (`docs/129 §10`).

Das trifft auch die Kurve. `History` reiht die Stützstellen nach dem **Index**
und nicht nach dem Datum; ein ruhiger Tag verschwindet, und seine Nachbarn
rücken zusammen — genau so, wie der Kopf von `History` es für eine ausgefallene
Nacht beschreibt, nur ohne dass eine ausgefallen wäre.

> **Ein Tag ohne Anfrage ist kein Tag ohne Zahl — die Zahl ist null, und wer
> sie nicht ablegt, lässt die Kurve behaupten, es habe ihn nicht gegeben.**

Entschieden ist die Frage nirgends. `docs/129 §5` sagt „Ein Tag ohne Zahlen ist
ehrlicher als ein Tag mit halben" — über den Tag mit gemischtem Format, nicht
über den stillen. Sie steht in §6 Frage 2 und ist am selben Tag entschieden und
gebaut (§5).

**3 · Die Rechnung des Kriteriums stimmt — gerechnet am Quelltext und im
Container gemessen.** `Daily::forget($today)` räumt ab, was vor `$today − 30`
liegt, und läuft **nach** `record()`. Der Lauf am Tag `D0 + 30` legt
`D0 + 29` ab; danach stehen `D0` bis `D0 + 29`, dreissig Tage. Der Lauf am Tag
`D0 + 31` legt `D0 + 30` ab und nimmt `D0` weg; es bleiben wieder dreissig.
`$today` kommt aus `ServerZone` und nicht aus `now()` in UTC — sonst verschöbe
sich die Grenze nach Mitternacht um einen Tag, eine Stunde lang im Winter und
zwei im Sommer, und in genau diesem Fenster läuft der Timer.

Gegen MariaDB gefahren, mit `D0` = 22. September: `forget` am 22. Oktober
räumt **nichts** ab, am 23. Oktober **8 und 8** Zeilen — genau die, die Block 5
am Abend davor für den ersten Tag gezählt hatte —, und der erste Tag rückt auf
den 23.

**4 · Der erste Tag ist nicht die erste Nacht.** Vor `0.9.0-rc.2`
(24. September), die die Vortagsregel brachte, legte der Lauf jeden zählbaren
Tag ab, den er in `access.log` und `access.log.1` fand; seitdem nur den
Vortag. `D0` ist deshalb der früheste
Tag **in der Tabelle** und nicht der Tag, an dem `rc.1` eingespielt wurde; die
beiden Nächte rechnet Block 2 aus dem Bestand und nicht aus einem
Freigabedatum.

---

## §1 · Teil 1, heute — der Bestand, gemessen und nicht angenommen

**Jede Zeile druckt, was sie gefunden hat, und nichts in Teil 1 schreibt** —
ausser dem Lauf von Hand in Block 4, der tut, was der Timer jede Nacht tut.

Gelesen wird über `DB::table()` und nicht über die Modelle: Beide tragen
`BelongsToSubscription`, und `srvpanel tinker` läuft ohne angemeldetes Konto —
über das Modell kämen **wortlos null Zeilen** zurück (CLAUDE.md, „Eine Frage,
die im Grundzustand alles verweigert"). Wo ein Modell nötig ist, in Block 3 für
die Kacheln, steht es in `withoutRestriction()`.

**Die Blöcke 2 bis 5 sind im Container gegen MariaDB 10.11.14 gefahren, jeder
mit einer Gegenprobe in beide Richtungen** (§1a). Die Blöcke 0 und 1 brauchen
einen Server.

**Block 0 ist nach dem Update auf `0.9.0-rc.7` dazugekommen.** Er ist die erste
Stelle, an der die Behebung aus §5 auf dem Server sichtbar wird, und er braucht
nichts als das Journal.

```bash
# 0 · Hat srvpanel:usage seit dem Update Platz und Datenbanken abgelegt?
systemctl show srvpanel-usage.timer -p LastTriggerUSec
journalctl -u srvpanel-usage.service --since today --no-pager | grep -E 'Verlauf für|nicht lesbar|scheiterte' | tail -3
```

**Erwartet:** die letzte Auslösung vor weniger als einer Viertelstunde und im
Journal `Verlauf für <heute> (<Zone>): N Abonnement(s) mit Platz, M mit
Datenbanken.`, mit N und M gleich der Zahl der Abonnements, wenn jede Messung
gelungen ist. Ein Abonnement ohne Datenbank zählt bei M mit, denn es bekommt
eine Null. **Steht dort keine solche Zeile, läuft die Behebung nicht**, und
Block 1 sagt, welche Fassung stattdessen läuft. Eine Zeile mit `nicht lesbar`
heisst, dass die Zone fehlt und der Lauf deshalb nichts ablegt. Eine Zeile mit
`scheiterte` heisst, dass eine Messung gescheitert ist; dann steht N oder M
unter der Zahl der Abonnements.

```bash
# 1 · Welche Fassung läuft, und wie stehen die beiden Timer, die die Tabelle füllen sollen?
srvpanel version
for t in srvpanel-traffic.timer srvpanel-usage.timer; do
  printf '%s\n' "$t"
  systemctl cat "$t" | grep -E '^(OnCalendar|OnBootSec|Persistent|RandomizedDelaySec)='
  systemctl show "$t" -p LastTriggerUSec -p NextElapseUSecRealtime
done
```

**Erwartet:** `0.9.0-rc.7`. Ausgeschrieben war hier `0.9.0-rc.6`, die Fassung
ohne die Behebung. Für den Zähllauf `OnCalendar=daily`,
`Persistent=true`, `RandomizedDelaySec=1h` und die letzte Auslösung in der
vergangenen Nacht zwischen 00:00 und 01:00; für die Messung des Platzes
`OnBootSec=5min`, `OnCalendar=*:0/15`, `Persistent=true`,
`RandomizedDelaySec=90` und die letzte Auslösung vor weniger als einer
Viertelstunde. Gegengelesen an den Unit-Dateien unter `packaging/systemd/`.

```bash
# 2 · Der Bestand je Kennzahl, der Index und der erste Tag
srvpanel tinker --execute='
foreach (["subscription_metrics" => App\Enums\DailyMetric::ofASubscription(), "domain_metrics" => App\Enums\DailyMetric::ofADomain()] as $t => $soll) {
    $ist = DB::table($t)->selectRaw("metric, count(*) AS n, count(DISTINCT day) AS tage, min(day) AS erster, max(day) AS letzter")
        ->groupBy("metric")->get()->keyBy("metric");
    printf("%s\n", $t);
    foreach ($soll as $m) {
        $z = $ist[$m->value] ?? null;
        printf("  %-24s %s\n", $m->value, $z === null ? "KEINE ZEILE"
            : sprintf("%5d Zeilen  %3d Tage  %s bis %s", $z->n, $z->tage, $z->erster, $z->letzter));
    }
    $schluessel = $t === "subscription_metrics" ? "subscription_id" : "domain_id";
    $eindeutig = collect(DB::select("SHOW INDEX FROM {$t} WHERE Non_unique = 0"))
        ->reject(fn ($r) => $r->Key_name === "PRIMARY")->groupBy("Key_name")
        ->map(fn ($s) => $s->sortBy("Seq_in_index")->pluck("Column_name")->implode(","))->implode(" | ");
    $doppelt = DB::table($t)->select($schluessel, "day", "metric")->groupBy($schluessel, "day", "metric")
        ->havingRaw("count(*) > 1")->get()->count();
    printf("  eindeutig über (%s), doppelt: %d\n", $eindeutig === "" ? "KEINEN INDEX" : $eindeutig, $doppelt);
}
$d0 = DB::table("subscription_metrics")->min("day");
$zone = App\Support\Cron\ServerZone::current();
printf("Erster Tag: %s   Nacht 30: %s   Nacht 31: %s   heute (%s): %s\n", $d0 ?? "—",
    $d0 === null ? "—" : Illuminate\Support\Carbon::parse($d0)->addDays(30)->toDateString(),
    $d0 === null ? "—" : Illuminate\Support\Carbon::parse($d0)->addDays(31)->toDateString(),
    $zone?->getName() ?? "Zone unlesbar", $zone === null ? "—" : now()->setTimezone($zone)->toDateString());
'
```

**Erwartet:**

- In `subscription_metrics` die vier Kennzahlen des Verkehrs mit Zeilen, und
  **`disk_mb` und `database_bytes` mit `KEINE ZEILE`** — das ist §0 Punkt 1 auf
  dem Server. **Stehen dort Zeilen, ist der Befund widerlegt**, und etwas, das
  der Quelltext nicht zeigt, schreibt sie; dann wird angehalten und nachgesehen.
  Das gilt für eine Fassung ohne die Behebung aus §5. **Mit ihr stehen dort
  Zeilen**, und `erster` ist der Tag, an dem sie eingespielt wurde — nicht
  früher: Nachgetragen wird nichts.
- In `domain_metrics` dieselben vier mit Zeilen.
- `eindeutig über (subscription_id,day,metric)` beziehungsweise
  `(domain_id,day,metric)`, beide Male `doppelt: 0`.
- `letzter` ist der Vortag von heute, sofern der Zähllauf in der Nacht durchkam.
- Die letzte Zeile nennt den ersten Tag `D0` und die **beiden Tage, an denen
  Teil 2 misst**, dazu die Zone des Servers. Steht dort `Zone unlesbar`, wird
  angehalten: Ohne sie ist jede Grenze in diesem Lauf geraten, und Block 3 kann
  nicht rechnen.

```bash
# 3 · Je Abonnement: Zeilen je Kennzahl, Tage ohne Zeile, was die Kacheln zeigen, was gemessen ist
srvpanel tinker --execute='
$zone = App\Support\Cron\ServerZone::current();
$gestern = now()->setTimezone($zone)->subDay()->toDateString();
$d0 = DB::table("subscription_metrics")->min("day");
$soll = $d0 === null ? [] : collect(Carbon\CarbonPeriod::create($d0, $gestern))->map->toDateString()->all();
printf("Spanne %s bis %s: %d Tage\n", $d0 ?? "—", $gestern, count($soll));
$h = app(App\Support\Metrics\History::class);
app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(function () use ($soll, $h) {
    foreach (App\Models\Subscription::query()->orderBy("id")->get() as $s) {
        $je = DB::table("subscription_metrics")->where("subscription_id", $s->id)
            ->selectRaw("metric, count(*) AS n")->groupBy("metric")->pluck("n", "metric");
        $tage = DB::table("subscription_metrics")->where("subscription_id", $s->id)
            ->where("metric", "requests")->pluck("day")->all();
        $fehlt = array_values(array_diff($soll, $tage));
        printf("%s\n  Zeilen: %s\n", $s->name, collect(App\Enums\DailyMetric::ofASubscription())
            ->map(fn ($m) => $m->value . " " . ($je[$m->value] ?? 0))->implode("  "));
        printf("  ohne Zeile: %d von %d Tagen%s\n", count($fehlt), count($soll), $fehlt === [] ? ""
            : " — " . implode(" ", array_slice($fehlt, 0, 8)) . (count($fehlt) > 8 ? " …" : ""));
        printf("  Kacheln: %s\n", collect($h->forSubscription($s))->map(fn ($k) => $k["key"] . " " . $k["value"])->implode("  |  "));
        printf("  jetzt gemessen: Platz %s MB (%s), Datenbanken %s B\n", $s->disk_used_mb ?? "—",
            $s->disk_usage_measured_at ?? "nie", DB::table("databases")->where("subscription_id", $s->id)->sum("size_bytes"));
    }
});
'
```

**Erwartet** je Abonnement:

- `disk_mb 0  database_bytes 0` und bei den Kacheln `disk —` und `databases —`
  — der Befund aus §0 Punkt 1, so wie ihn ein Kunde sieht. **Mit der Behebung**
  stehen dort Zeilen ab dem Tag der Freigabe, und die Kacheln zeigen einen Wert
  erst ab dem **zweiten** Tag: Ein einzelner Tag ist keine Kurve, und die Kachel
  sagt dann „—" (`DailyHistoryTest::test_a_single_day_is_not_a_curve`).
- Daneben `jetzt gemessen: Platz … MB` mit einem Zeitpunkt vor weniger als
  einer Viertelstunde (UTC). **Das ist die Gegenprobe zum Befund:** Die Zahl
  gibt es, nur die Tabelle bekommt sie nicht — „nicht gemessen" und „nicht
  abgelegt" sind damit getrennt.
- Für die vier Kennzahlen des Verkehrs **je Abonnement dieselbe Zahl** — sie
  entstehen in `Daily::record()` in einem Zug.
- `ohne Zeile: K von N Tagen` ist die Messung von §0 Punkt 2. Eine Erwartung je
  Abonnement gibt es nicht; `K = 0` heisst, dass an jedem Tag mindestens eine
  Anfrage kam. **Die Abonnements mit `K = 0` sind die, an denen Teil 2 die
  dreissig Zeilen messen kann** — sie werden im Protokoll festgehalten. Mit der
  Behebung bekommt ein ruhiger Tag eine Null, aber erst ab der Freigabe; die
  Tage davor bleiben ohne Zeile, bis sie aus den dreissig fallen. Ohne Zeile
  bleibt auch danach, wer nicht ganz gelesen ist — der Nachtlauf nennt ihn —,
  und eine Domain für einen Tag, an dem es sie noch nicht gab.
- `jetzt gemessen: … Datenbanken … B` summiert, was an den Datenbanken steht,
  auch einen Wert, der nie gemessen wurde; dann zählt er als 0. Ob jede
  Datenbank am Tag gemessen wurde, sagt die Zeile `database_bytes`: Sie fehlt,
  wenn eine nicht gemessen wurde.

```bash
# 4 · Derselbe Vortag noch einmal — die Zeilen bleiben, was sie sind
B3_STAND='
foreach (["subscription_metrics", "domain_metrics"] as $t) {
    $z = DB::table($t)->whereNotIn("metric", ["disk_mb", "database_bytes"])->orderBy("id")->get(["id", "day", "metric", "value"]);
    printf("%-22s %4d Zeilen  %4d Nullen  Prüfsumme %s\n", $t, $z->count(), $z->where("value", 0)->count(), substr(md5($z->toJson()), 0, 12));
}
'
srvpanel tinker --execute="$B3_STAND"
srvpanel traffic
srvpanel tinker --execute="$B3_STAND"
srvpanel traffic
srvpanel tinker --execute="$B3_STAND"
```

**Berichtigt am 28. September, nach dem Update auf `0.9.0-rc.7` und bevor der
Block lief.** Die erste Fassung las zweimal, vor und nach einem Lauf, und bildete
die Prüfsumme über alle Zeilen. Gegen `rc.7` hätte sie aus zwei Gründen
angeschlagen, und beide Male hätte „überschreibend" wie „addierend" ausgesehen:

- **Der erste Lauf nach dem Update legt nach, was die alte Fassung nicht
  kannte.** Unter `rc.6` bekam ein ruhiger Vortag keine Zeile. Der erste Lauf
  unter `rc.7` legt denselben Vortag noch einmal ab und gibt ihm dabei seine
  vier Nullen: Die Tabelle wächst um genau diese Nullen, und das ist richtig.
  Verglichen wird deshalb die zweite mit der dritten Ablesung; zwischen der
  ersten und der zweiten dürfen nur Nullen dazukommen.
- **Platz und Datenbanken schreibt ein zweiter Lauf.** `srvpanel:usage` legt
  sie alle fünfzehn Minuten für den laufenden Tag neu ab, überschreibend. Ein
  Wert, der sich zwischen zwei Ablesungen um ein Megabyte ändert, änderte die
  Prüfsumme, ohne dass eine Zeile dazukäme, und nach Mitternacht käme eine
  dazu. Beide Kennzahlen stehen deshalb nicht in der Prüfsumme: Dieser Block
  misst den Nachtlauf und nicht den Lauf daneben.

> **Eine Prüfsumme über eine Tabelle, in die ein zweiter Lauf schreibt, misst
> beide Läufe.**

**Erwartet:**

- Beide Läufe melden dasselbe, bis auf die Dauer in `Fertig in … ms`, darin
  `Abgelegt: N Zeile(n) je Domain, M je Abonnement.` mit **N über null**. Eine
  Zeile `Älter als …` kann nur beim ersten stehen.
- **Die zweite und die dritte Ablesung sind Zeile für Zeile gleich:** dieselbe
  Zahl, dieselben Nullen, dieselbe Prüfsumme. Das ist „überschreibend und
  nicht addierend" an der Tabelle des Servers, über einem Lauf, der
  geschrieben hat.
- Zwischen der ersten und der zweiten kommen **nur Nullen** dazu, also so viele
  Zeilen wie Nullen. Beim ersten Lauf unter `rc.7` sind es in `domain_metrics`
  vier je Domain, die der Lauf „ruhig" nennt, und in `subscription_metrics`
  vier je Abonnement, dessen Domains am Vortag alle ruhig waren. Ist schon ein
  Nachtlauf unter `rc.7` gelaufen, sind alle drei Ablesungen gleich.

**Steht dort `Abgelegt: 0`, hat der Block nichts gemessen:** Zwei gleiche
Stände über einem Lauf, der nichts geschrieben hat, sind kein Beleg. Mit der
Behebung legt der Lauf auch an einem ruhigen Tag ab, vier Nullen je ganz
gelesener Domain; `Abgelegt: 0` heisst dann, dass keine Domain ganz gelesen
war, und der Lauf nennt jede unter „nicht ganz gelesen". Unter einer Fassung
ohne die Behebung hiess es, dass gestern keine Anfrage kam. Dann machte eine
Anfrage heute an eine Kundendomain den Block morgen fahrbar:

```bash
curl -s -o /dev/null -w '%{http_code}\n' "https://<domain>/?b3=1"
```

### §1a · Was im Container gemessen ist

Eine Wegwerf-MariaDB 10.11.14 im Scratchpad, das Schema aus den Migrationen,
drei Abonnements mit je einer Domain: `alpha.test` mit Verkehr an jedem der
sechs Tage vom 22. bis 27. September, `beta.test` mit zwei ruhigen Tagen (24.
und 26.), `gamma.test` ohne jeden. **Geschrieben über `Daily::record()` und
`Daily::forget()`, nicht von Hand** — gemessen werden soll, was der Nachtlauf
hinterlässt.

| Block | Zustand | gezeigt |
|---|---|---|
| 2 | wie gebaut | vier Kennzahlen mit je 10 Zeilen, `disk_mb` und `database_bytes` `KEINE ZEILE`, eindeutig über den Schlüssel, `doppelt: 0`; erster Tag 22.09., Nacht 30 am 22.10., Nacht 31 am 23.10. |
| 2 | Index entfernt, eine Dublette und eine `disk_mb`-Zeile eingefügt | `disk_mb 1 Zeilen`, `KEINEN INDEX`, `doppelt: 1`, `traffic_sent_bytes 11 Zeilen` |
| 2 | zurück | wie gebaut |
| 3 | wie gebaut | `alpha` 0 von 6 ohne Zeile, `beta` 2 von 6 (24. und 26.), `gamma` 6 von 6; überall `disk —` und `databases —`, daneben 1000, 900 und 1000 MB und 3 145 728 B gemessen |
| 3 | zwei `disk_mb`-Zeilen für `alpha`, die beiden Lücken von `beta` gefüllt | `disk 1.000`; `beta` 0 von 6 |
| 3 | zurück | wie gebaut |
| 4 | derselbe Vortag noch einmal durch `record()` und `forget()` | je 8 Zeilen geschrieben, 40 und 40 Zeilen, Prüfsummen vorher und nachher gleich |
| 4 | ein Wert in `subscription_metrics` um eins erhöht | deren Prüfsumme ändert sich, die von `domain_metrics` nicht |
| 4, berichtigt | der Bestand aus dem Vorflug von B4 (`docs/139 §1a`), mit Platz und Datenbanken an zwei Tagen | `subscription_metrics 180 Zeilen 8 Nullen`, `domain_metrics 212 Zeilen 8 Nullen` |
| 4, berichtigt | `disk_mb` des laufenden Tages um eins erhöht, dazu eine `database_bytes`-Zeile für den nächsten Tag | beide Prüfsummen unverändert |
| 4, berichtigt | dazu ein `requests`-Wert in `subscription_metrics` um eins erhöht | deren Prüfsumme ändert sich, die von `domain_metrics` nicht |
| 4, berichtigt | dazu eine Null in `domain_metrics` eingefügt | `213 Zeilen 9 Nullen`, Prüfsumme geändert |
| 4, berichtigt | zurück | beide Prüfsummen wie am Anfang |
| 5 | `forget` am 22.10. / am 23.10. | 0 und 0 / 8 und 8; der erste Tag rückt vom 22. auf den 23., die Tage von 6 auf 5 |

**Dass beide Tabellen im Container dieselbe Prüfsumme trugen, ist kein Fehler
des Blocks:** Mit einer Domain je Abonnement stehen in beiden dieselben Werte
unter denselben Kennungen. Dass die beiden Zeilen verschiedene Tabellen lesen,
hat die Gegenprobe belegt — sie hat nur die eine verändert. Auf dem Server, mit
mehreren Domains je Abonnement, gehen die beiden Summen auseinander.

**Und die erste Gegenprobe zu Block 2 hat selbst nichts gemessen.** Sie fragte
`information_schema.STATISTICS` nach `Key_name` — die Spalte heisst dort
`INDEX_NAME`, `Key_name` gibt es nur in der Ausgabe von `SHOW INDEX`. Der Name
des Index kam deshalb leer zurück, das `ALTER` damit ins Leere, und die
Einfügungen dahinter liefen gar nicht erst; Block 2 zeigte denselben Stand wie
vorher. Aufgefallen ist es daran und an nichts anderem, und seitdem druckt jede
Gegenprobe `Eingriff steht: N Zeilen` neben ihr Ergebnis. Das Zurücksetzen hat
dabei einen **zweiten** eindeutigen Index angelegt — `ADD UNIQUE INDEX` ohne
Namen benennt ihn selbst —, und InnoDB verweigert das Entfernen eines Index, den
ein Fremdschlüssel braucht (Fehler 1553), solange kein anderer dafür dasteht.

> **Eine Gegenprobe, deren Eingriff scheitert, zeigt denselben Stand wie die
> Messung davor — und liest sich wie ein Beleg dafür, dass die Messung
> unempfindlich ist.**

---

## §2 · Teil 2 — Nacht 30 und Nacht 31

**Derselbe Block an beiden Tagen, die Block 2 genannt hat, jeweils nach dem
Nachtlauf** — also nicht vor 01:00, und die erste Zeile belegt, dass er gelaufen
ist. Dazu die Blöcke 2 und 3 unverändert.

```bash
# 5 · Nacht 30 und Nacht 31: der erste Tag, seine Zeilen, und was der Lauf dazu gesagt hat
systemctl show srvpanel-traffic.timer -p LastTriggerUSec
journalctl -u srvpanel-traffic.service --since today --no-pager | grep -E 'Laufender Tag|Abgelegt|Älter als|Fertig'
srvpanel tinker --execute='
foreach (["subscription_metrics", "domain_metrics"] as $t) {
    $erster = DB::table($t)->min("day");
    printf("%-22s erster Tag %s mit %d Zeile(n), %d Tage insgesamt\n", $t, $erster ?? "—",
        $erster === null ? 0 : DB::table($t)->where("day", $erster)->count(), DB::table($t)->distinct()->count("day"));
}
'
```

**Erwartet an Nacht 30:** die letzte Auslösung heute; im Journal eine Zeile
`Abgelegt:` und **keine** `Älter als`; beide Tabellen mit erstem Tag `D0`. Die
beiden Zeilenzahlen des ersten Tags werden **abgeschrieben** — sie sind die
Vorhersage für die nächste Nacht. In Block 3 hat jedes Abonnement, das in
Teil 1 `K = 0` hatte und seitdem jeden Tag Verkehr, **30** Zeilen je Kennzahl
des Verkehrs.

**Erwartet an Nacht 31:** im Journal
`Älter als 30 Tag(e) entfernt: X je Domain, Y je Abonnement.` — und **X und Y
sind genau die beiden Zahlen von gestern**, über Kreuz: Block 5 nennt zuerst
die Abonnements, das Journal zuerst die Domains. Der erste Tag ist `D0 + 1`,
`domain_metrics` führt 30 Tage und `subscription_metrics` 31, und die
Abonnements von gestern haben wieder 30 Zeilen je Kennzahl des Verkehrs
(berichtigt, Absatz unten).

> **Eine Erwartung, die man aus den Zahlen ausrechnet statt sie zu schätzen,
> macht aus dem Ergebnis einen Beleg** (`docs/913 §15`). Das Journal von Nacht
> 31 wird nicht auf „hat abgeräumt" gelesen, sondern auf die beiden Zahlen, die
> am Abend davor feststanden.

**Für `disk_mb` und `database_bytes` sagt Teil 2 nichts**, solange §0 Punkt 1
steht. Mit der Behebung aus §5 beginnt ihre Uhr mit ihrer ersten Zeile, und
Nacht 30 und 31 werden aus **deren** erstem Tag gerechnet — Block 2 druckt ihn
je Kennzahl in der Spalte `erster`.

**Und ein Stand zählt einen Tag mehr.** `srvpanel:usage` legt den **laufenden**
Tag ab, der Nachtlauf den Vortag, und abgeräumt wird für beide nach derselben
Grenze. An Nacht 30 ihrer Uhr stehen deshalb einunddreissig Zeilen je
Abonnement — dreissig abgeschlossene Tage und der laufende —, und Nacht 31
nimmt den ersten. Die Kachel zeigt davon die letzten dreissig. Die Vorhersage
aus Block 5 hält dabei: Er zählt alle Zeilen des ersten Tags, und dieselbe
Grenze nimmt sie alle (gemessen, §5).

**Berichtigt nach Teil 2, gefunden am 15. Oktober 2026 beim Nachrechnen
davor.** Zwei Erwartungen dieses Abschnitts stammten aus der Zeit vor der
Behebung, und der Absatz darüber hatte sie nicht mitgenommen:

- **Tage insgesamt.** Hier stand, an Nacht 31 führe keine Tabelle mehr als 30
  Tage. Block 5 zählt die Tage über alle Kennzahlen, und seit `rc.7` legt
  `srvpanel:usage` nach Mitternacht den laufenden Tag der Stände ab.
  `subscription_metrics` führt deshalb an jedem Tag von Teil 2 31 Tage,
  dreissig des Verkehrs und den laufenden; `domain_metrics` führt 30. Ebenso
  meinte „30 Zeilen je Kennzahl" nur die Kennzahlen des Verkehrs.
- **Nacht 30 der Stände räumt ab.** Am 28. Oktober ist die Uhr des Verkehrs
  sieben Nächte weiter, und `forget()` nimmt dessen Zeilen vom 27. September,
  vorausgerechnet 24 je Domain und 8 je Abonnement. Dass von Platz und
  Datenbanken nichts entfernt ist, steht in Block 2 an `erster` und nicht an
  der Zeile `Älter als`. Punkt 1 aus §4 gilt für ihre Uhr in dieser Lesart.

Nachgefahren ist beides im Container, gegen eine Wegwerf-SQLite mit dem
Bestand aus §7, mit dem echten `Daily::forget()` und dem Wortlaut der Blöcke 5
und 2: mit den Ständen 31 und 30 Tage, ohne sie 30 und 30. Der Server hat
beides bestätigt (§8).

**Und jeder Block wird am Kalendertag seiner Nacht gefahren.** Die nächste
Nacht rückt den ersten Tag weiter. Was der Lauf gesagt hat, hält danach das
Journal; den Stand der Tabelle hält niemand (§8).

---

## §3 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Die Werte in den Zeilen.** Dass eine Tageszeile mit den Protokollen
  übereinstimmt, ist das Kriterium von B2 und dort abgenommen (`docs/134 §7`).
  Dieser Lauf zählt Zeilen und Tage.
- **Die Kacheln als Bild.** Das ist B4. Block 3 druckt nur, welchen Wert jede
  zeigt.
- **Die Monatssumme der Kontingentprüfung (B5).** Ein Monat hat bis zu 31 Tage,
  aufbewahrt werden 30; am 31. fehlt der Summe der erste Tag des Monats. Die
  Grenze ist seit B5 benannt und wird hier nicht gemessen.
- **Eine ausgefallene Nacht.** Sie sieht in der Tabelle aus wie ein ruhiger Tag;
  unterscheiden kann beide nur das Journal von `srvpanel-traffic.service`.
- **Die Zeilen einer zurückgebauten Domain.** Sie gehen über `cascadeOnDelete`
  mit; kein Punkt stellt diesen Zustand her.
- **Die Schnittstelle** `api/v1/subscriptions/{subscription}/metrics` — das ist
  B7.

---

## §4 · Wann er durch ist

**Teil 1 ist durch, wenn die Blöcke 0 bis 4 gefahren sind und ihre Ausgabe im
Protokoll steht.** Er bestätigt oder widerlegt §0 Punkt 1 und Punkt 2 auf dem
Server, und mit `0.9.0-rc.7` deren Behebung. **Block 4 darf ausfallen**, wenn
der Lauf nichts ablegt (`Abgelegt: 0`); er wird dann am nächsten Tag
nachgeholt.

**Teil 2 erfüllt das Kriterium für die vier Kennzahlen des Verkehrs**, wenn

1. an Nacht 30 beide Tabellen mit `D0` beginnen und das Journal nichts abräumt,
2. an Nacht 31 das Journal **genau** die beiden Zahlen des ersten Tags von
   gestern entfernt und beide Tabellen mit `D0 + 1` beginnen, und
3. jedes Abonnement, das in beiden Nächten `ohne Zeile: 0` hat, je Kennzahl des
   Verkehrs **30** Zeilen führt.

**Punkt 2 darf nicht ausfallen** — er ist die einunddreissigste Nacht, die das
Kriterium verlangt. Punkt 3 fällt aus, wenn kein Abonnement an jedem Tag Verkehr
hatte; dann entscheidet §6 Frage 2, ob er überhaupt messbar wird. **Entschieden
ist sie** (§5): Mit der Behebung bekommt ein ruhiger Tag eine Null, und Punkt 3
wird für jedes Abonnement messbar, dessen Domains ganz gelesen sind —
dreissig Tage nach der Freigabe, weil die Tage davor ohne Zeile bleiben.

**Nach dem Wortlaut des Kriteriums ist B3 erst abgenommen, wenn dasselbe auch
für `disk_mb` und `database_bytes` gilt** — mit der Behebung aus §5 und mit
deren eigener Uhr (§2), bei der ein Stand neben den dreissig abgeschlossenen
Tagen den laufenden trägt. Ein Kriterium „je Abo und Kennzahl", das an vier
von sechs Kennzahlen erfüllt ist, ist nicht erfüllt; die Abnahme spricht der
Betreiber aus.

---

## §5 · Die Behebung und was sie für diesen Lauf heisst

**Gebaut am 28. September 2026**, nach der Entscheidung zu beiden Fragen aus
§6. Die Begründungen stehen im CHANGELOG unter „B3 ist behoben".

- **Platz und Datenbanken** legt `srvpanel:usage` nach jeder Messung für den
  **laufenden** Tag ab, überschreibend (`Daily::levels()`). Ein Tag bekommt
  nur, was an ihm gemessen wurde; die Datenbanken nur, wenn **jede** des
  Abonnements an dem Tag gemessen wurde; ein Abonnement ohne Datenbank eine
  Null. Ohne lesbare Zone legt der Lauf nichts ab und sagt es.
- **Ein ruhiger Vortag** bekommt vier Nullen, wenn der Agent die Domain ganz
  gelesen hat — mindestens eine Datei, keine unlesbare Zeile — und es sie vor
  dem Tag schon gab. Wer nicht ganz gelesen ist, nennt der Nachtlauf unter
  „nicht ganz gelesen". Das Abonnement bekommt seine Null nur, wenn keine
  seiner Domains an dem Tag eine Lücke hatte; eine Summe mit Zählbarem bleibt
  wie bisher stehen.
- **Die Kacheln** zählen die dreissig Tage je Kennzahl: der Verkehr die dreissig
  bis gestern, der Platz die dreissig bis heute. Ein gemeinsames Fenster endete
  heute und nähme dem Verkehr seinen ältesten Tag.

**Teil 1 bleibt gültig** und misst den Stand der installierten Fassung — gegen
`0.9.0-rc.6` den Befund, gegen eine Fassung mit der Behebung die Zeilen ab
ihrem ersten Tag; bei jedem Block steht, was sich dann ändert. **Teil 2 für die
vier Kennzahlen des Verkehrs läuft ungestört weiter**: Die Nullen sind Zeilen
wie andere, und die Vorhersage aus Block 5 hält. Für `disk_mb` und
`database_bytes` beginnt mit der Freigabe eine eigene Uhr (§2), gemessen mit
denselben Blöcken.

### §5a · Nachgemessen mit den echten Kommandos

Im Container, am 28. September 2026 (Zone `Etc/UTC`), gegen MariaDB 10.11.14:
ein echter Agent auf eigenem Socket, fünf Protokollverzeichnisse unter
`/var/www/vhosts` und `srvpanel:traffic` und `srvpanel:usage`, wie der Timer sie
ruft. `alpha` hat drei Zeilen am Vortag und eine Datenbank in MariaDB, `beta`
nur eine Zeile vom 20. September und keine Datenbank, `gamma` eine unlesbare
Zeile und eine PostgreSQL-Datenbank, die hier niemand messen kann, `delta` ein
leeres Protokoll und eine Domain von heute, `omega` ein Verzeichnis, das das
Panel nicht kennt.

| Lauf | gezeigt |
|---|---|
| `srvpanel:traffic` | `1 Tageswert(e) vom Vortag zählbar, 3 ruhig (eine Null), 0 übersprungen (gemischtes Format), 1 nicht ganz gelesen, 1 noch offen (laufender Tag), 2 älter und nicht erneut abgelegt.` — `nicht ganz gelesen: gamma.test / gamma.test — 1 unlesbare Zeile(n)` — `Abgelegt: 8 Zeile(n) je Domain, 8 je Abonnement.` — `ohne Zeile im Panel: omega.test / omega.test` |
| die Tabelle danach | `alpha` 3 Anfragen, 2400 B hinaus, 270 herein; `beta` vier Nullen; `gamma` und `delta` nichts |
| derselbe Lauf noch einmal | 8 und 8 Zeilen, Prüfsummen vorher und nachher gleich |
| `srvpanel:usage` | `Messung scheiterte: repquota ist auf diesem System nicht installiert.`, PostgreSQL nicht erreichbar, `Verlauf für 2026-09-28 (Etc/UTC): 0 Abonnement(s) mit Platz, 3 mit Datenbanken.` — `alpha` 507 904 B, `beta` und `delta` 0, `gamma` keine Zeile |
| dasselbe nach einer gelungenen Messung früher am Tag | `4 Abonnement(s) mit Platz` — der gescheiterte Lauf lässt die letzte gelungene des Tages stehen |
| die Messung von `alpha` auf gestern gesetzt | `3 Abonnement(s) mit Platz`, `alpha` ohne Zeile |
| Zeilen auf `heute − 31` gelegt, dazu ein Stand auf `heute − 30` | Block 5 zählte 6 und 4, der Nachtlauf meldete `Älter als 30 Tag(e) entfernt: 4 je Domain, 6 je Abonnement.`, der Stand auf der Grenze blieb |

Die Blöcke 2 und 3 unverändert auf diesem Bestand: Die Stände beginnen am
28., der Verkehr am 27., und jede Kachel steht auf „—" — beide haben einen Tag.

---

## §6 · Zwei Fragen an den Betreiber — vor Teil 2

**Beide entschieden am 28. September 2026, wie vorgeschlagen, und gebaut**
(§5). Beim Bauen kamen zwei Regeln dazu, die keine neue Frage waren, sondern
aus der Antwort folgen: Eine Null gibt es nur für einen Tag, an dem es die
Domain schon gab. Und das Abonnement bekommt seine Null nur ohne Lücke; eine
Summe mit Zählbarem bleibt stehen, auch neben einer Lücke. Sonst nähme eine
einzige kaputte Datei in einer ruhigen Domain — logrotate dreht eine leere
Datei nicht, sie bliebe im Lesebereich — dem ganzen Abonnement jeden weiteren
Tag.

Beide betreffen, was ein Kunde auf seiner Abonnementseite sieht, und stehen
damit unter der Regel vom 27. September: *Macht es Sinn und gibt es einen
spürbaren Mehrwert für einen Nutzer im Panel?*

**Frage 1 · Wer schreibt Platz und Datenbanken in die Tabelle?** Gemessen werden
beide schon, alle fünfzehn Minuten von `srvpanel:usage`. Der naheliegende Ort
ist deshalb dieser Lauf und kein neuer: Er legt mit jeder Messung die Zeile des
**laufenden** Tages überschreibend ab, und am Abend steht dort die letzte
Messung des Tages. Der Grund für den laufenden Tag und nicht den Vortag steht
auf der Seite selbst: Über der Kachel „Speicherplatz" zeigt die Seite den
gegenwärtigen Wert, und eine Kachel, die darunter den von gestern nennt, zeigte
dieselbe Grösse in zwei Fassungen. Ohne Behebung bleiben zwei von fünf Kacheln
auf jeder Abonnementseite leer.

**Frage 2 · Bekommt ein ruhiger Tag eine Null?** Der Vorschlag: ja, aber nur
für eine Domain, deren Protokolle der Nachtlauf **vollständig gelesen** hat —
nicht für eine, die das Budget liegen liess, und nicht für einen Tag im alten
Format. Sonst wird aus „nicht gemessen" ein „nichts gewesen". Ohne die Null
zeigt die Kurve eines ruhigen Abonnements eine Nachbarschaft von Tagen, die es
so nicht gab, und Punkt 3 aus §4 ist auf `cloudsrv24` womöglich an keinem
Abonnement messbar.

---

## §7 · Protokoll — Teil 1, gefahren vom 28. bis 30. September 2026

Gefahren auf `cloudsrv24`. Block 0 und Block 4 liefen am Abend des
28. September gegen `0.9.0-rc.7`, die Blöcke 1 bis 3 am 29. und 30. September
gegen `0.9.0-rc.8`, und Block 4 lief am 30. ein zweites Mal. **Teil 1 ist damit
durch** (§4). `rc.8` ändert, wie die Kacheln ihre Zahlen zeigen
(`docs/139 §6a`), und keinen Schreiber dieser Tabellen. Für diesen Lauf sind
beide Fassungen deshalb gleich; nur die Zeile `Kacheln:` in Block 3 zeigt die
Zahlen in der Form von `rc.8`.

### Block 0 — der Schreiber aus §5 läuft auf dem Server

```
LastTriggerUSec=Mon 2026-09-28 21:15:56 CEST
Sep 28 21:15:56 cloudsrv24 php[316640]: Verlauf für 2026-09-28 (Europe/Berlin): 3 Abonnement(s) mit Platz, 3 mit Datenbanken.
```

Jedes der drei Abonnements hat für den 28. eine Zeile Platz und eine Zeile
Datenbanken, die Zone ist lesbar, und keine Messung ist gescheitert. **Das ist
die Behebung von §0 Punkt 1, zum ersten Mal auf einem Server gesehen**; die
Freigabenotiz von `rc.7` sagte noch, gesehen habe es niemand. Nachgetragen wird
nichts: Der erste Tag von `disk_mb` und `database_bytes` ist der 28. September.
Nacht 30 ihrer Uhr (§2) fällt damit auf den **28. Oktober**, Nacht 31 auf den
**29. Oktober** — vorausgerechnet, und Block 2 bestätigt es mit dem ersten Tag
je Kennzahl. Eine Kurve zeigen die beiden Kacheln ab dem 29.

### Block 1 — die beiden Zeitgeber

Am Nachmittag des 29. September, gegen `0.9.0-rc.8`:

```
0.9.0-rc.8
srvpanel-traffic.timer
OnCalendar=daily
Persistent=true
RandomizedDelaySec=1h
NextElapseUSecRealtime=Wed 2026-09-30 00:18:49 CEST
LastTriggerUSec=Tue 2026-09-29 00:20:01 CEST
srvpanel-usage.timer
OnBootSec=5min
OnCalendar=*:0/15
Persistent=true
RandomizedDelaySec=90
NextElapseUSecRealtime=Tue 2026-09-29 17:16:03 CEST
LastTriggerUSec=Tue 2026-09-29 17:01:04 CEST
```

Jede Zeile der beiden Unit-Dateien steht so da, wie §1 sie aus
`packaging/systemd/` abgelesen hat. Der Nachtlauf hat um 00:20:01 ausgelöst,
im Fenster zwischen 00:00 und 01:00, der Messlauf um 17:01:04, wenige Minuten
vor der Ablesung. Erwartet war hier `0.9.0-rc.7`; am 29. war schon `rc.8`
eingespielt, und es trägt dieselbe Behebung.

### Block 2 — der Bestand je Kennzahl, der Index und der erste Tag

Am Nachmittag des 29. September:

```
subscription_metrics
  traffic_sent_bytes          10 Zeilen    8 Tage  2026-09-21 bis 2026-09-28
  traffic_received_bytes      10 Zeilen    8 Tage  2026-09-21 bis 2026-09-28
  requests                    10 Zeilen    8 Tage  2026-09-21 bis 2026-09-28
  errors                      10 Zeilen    8 Tage  2026-09-21 bis 2026-09-28
  disk_mb                      6 Zeilen    2 Tage  2026-09-28 bis 2026-09-29
  database_bytes               6 Zeilen    2 Tage  2026-09-28 bis 2026-09-29
  eindeutig über (subscription_id,day,metric), doppelt: 0
domain_metrics
  traffic_sent_bytes          30 Zeilen    8 Tage  2026-09-21 bis 2026-09-28
  traffic_received_bytes      30 Zeilen    8 Tage  2026-09-21 bis 2026-09-28
  requests                    30 Zeilen    8 Tage  2026-09-21 bis 2026-09-28
  errors                      30 Zeilen    8 Tage  2026-09-21 bis 2026-09-28
  eindeutig über (domain_id,day,metric), doppelt: 0
Erster Tag: 2026-09-21   Nacht 30: 2026-10-21   Nacht 31: 2026-10-22   heute (Europe/Berlin): 2026-09-29
```

- **Jede Zahl ist die vom Abend des 28., um eine Nacht weitergezählt.** Für
  jenen Abend standen hier je Kennzahl des Verkehrs 8 Zeilen in
  `subscription_metrics` und 24 in `domain_metrics`, dazu je 3 für `disk_mb`
  und `database_bytes`. Der Nachtlauf des 29. hat für den 28. die beiden
  Abonnements mit Zeilen und die sechs Domains dazugelegt (`8 je Abonnement`
  und `24 je Domain`, geteilt durch die vier Kennzahlen), der erste Messlauf
  nach Mitternacht den 29. für alle drei Abonnements.
- Beide Tabellen sind über `(…, day, metric)` eindeutig, `doppelt: 0`.
- Der letzte Tag des Verkehrs ist der Vortag, und die Zone ist lesbar.
- **`D0` ist der 21. September.** Teil 2 misst den Verkehr damit an Nacht 30
  am **21. Oktober** und an Nacht 31 am **22. Oktober**. Für Platz und
  Datenbanken bleibt es beim 28. und 29. Oktober aus Block 0, denn ihr erster
  Tag ist der 28. September.

### Block 3 — je Abonnement

Am Morgen des 30. September, einen Tag nach Block 2 und nach dem ersten
Nachtlauf unter `rc.8`. Alles steht deshalb einen Tag weiter als am 29.:

```
Spanne 2026-09-21 bis 2026-09-29: 9 Tage
p6-b.invalid
  Zeilen: traffic_sent_bytes 9  traffic_received_bytes 9  requests 9  errors 9  disk_mb 3  database_bytes 3
  ohne Zeile: 0 von 9 Tagen
  Kacheln: disk 68  |  traffic 6,0  |  requests 14.088  |  errors 97  |  databases 0
  jetzt gemessen: Platz 68 MB (2026-09-30 08:45:11), Datenbanken 0 B
p6-abnahme.invalid
  Zeilen: traffic_sent_bytes 3  traffic_received_bytes 3  requests 3  errors 3  disk_mb 3  database_bytes 3
  ohne Zeile: 6 von 9 Tagen — 2026-09-21 2026-09-22 2026-09-23 2026-09-24 2026-09-25 2026-09-26
  Kacheln: disk 3  |  traffic 0  |  requests 0  |  errors 0,00  |  databases 0
  jetzt gemessen: Platz 3 MB (2026-09-30 08:45:11), Datenbanken 0 B
p6-nochmaltest
  Zeilen: traffic_sent_bytes 0  traffic_received_bytes 0  requests 0  errors 0  disk_mb 3  database_bytes 3
  ohne Zeile: 9 von 9 Tagen — 2026-09-21 2026-09-22 2026-09-23 2026-09-24 2026-09-25 2026-09-26 2026-09-27 2026-09-28 …
  Kacheln: disk 0  |  traffic —  |  requests —  |  errors —  |  databases 0
  jetzt gemessen: Platz 0 MB (2026-09-30 08:45:11), Datenbanken 0 B
```

- **`p6-b.invalid` hat an jedem der neun Tage eine Zeile**, `ohne Zeile: 0`.
  An ihm misst Teil 2 die dreissig Zeilen (§4 Punkt 3).
- **`p6-abnahme.invalid` hat Zeilen erst ab dem 27.**, dem Vortag der
  Behebung. Die ruhigen Tage davor bleiben ohne Zeile, nachgetragen wird
  nichts. Auch der 29. war ruhig, und seine Null kam aus dem Nachtlauf.
- **`p6-nochmaltest` hat keine Domain.** `docs/139` Block 2 druckt die Domains
  je Abonnement, und unter diesem steht keine (`docs/139 §7`). Damit ist die
  Frage aus Block 4 beantwortet: ohne Domain keine Zeile des Verkehrs.
- Die vier Kennzahlen des Verkehrs stehen je Abonnement mit derselben Zahl da,
  9, 3 und 0. Zusammen sind es 12: die 10 aus Block 2 und die 2 des
  Nachtlaufs.
- `disk_mb` und `database_bytes` haben bei allen drei je drei Zeilen, den 28.,
  29. und 30.
- Platz und Datenbanken tragen ab dem zweiten Tag eine Zahl, und sie gleicht
  der gerade gemessenen: `disk 68` neben `Platz 68 MB`, `3` neben `3`, `0`
  neben `0`. Gemessen war um 08:45:11 UTC, sieben Minuten vor der Ablesung.
- Die Kacheln zeigen die Form von `rc.8`: `requests 14.088` und `0`,
  `errors 0,00`, und `—` ohne Einheit, wo keine Zeile ist.

### Block 4 — überschreibend, über einem Lauf, der geschrieben hat

Gefahren in der berichtigten Fassung (§1 Block 4). Die erste Ablesung, der
erste Lauf und die zweite Ablesung:

```
subscription_metrics     28 Zeilen     0 Nullen  Prüfsumme 84938c68a5a4
domain_metrics           84 Zeilen     1 Nullen  Prüfsumme 1ed9d8818098
  Laufender Tag auf dem Server: 2026-09-28 (Europe/Berlin).
  6 Domain(s) gelesen, 32665 Zeile(n), davon 32657 gedeutet, 8 aus dem alten Zeitalter, 0 unlesbar.
  3 Tageswert(e) vom Vortag zählbar, 3 ruhig (eine Null), 0 übersprungen (gemischtes Format), 0 nicht ganz gelesen, 3 noch offen (laufender Tag), 5 älter und nicht erneut abgelegt.
  Abgelegt: 24 Zeile(n) je Domain, 8 je Abonnement.
  Fertig in 73 ms.
subscription_metrics     32 Zeilen     4 Nullen  Prüfsumme 312a417c319a
domain_metrics           96 Zeilen    13 Nullen  Prüfsumme c6bffbc35398
```

Der zweite Lauf meldete Zeile für Zeile dasselbe, auch die 73 ms, und die
dritte Ablesung gleicht der zweiten:

```
subscription_metrics     32 Zeilen     4 Nullen  Prüfsumme 312a417c319a
domain_metrics           96 Zeilen    13 Nullen  Prüfsumme c6bffbc35398
```

- **Die zweite und die dritte Ablesung sind gleich**, über einem Lauf, der 24
  und 8 Zeilen abgelegt hat: Der Nachtlauf überschreibt und addiert nicht,
  gemessen an der Tabelle des Servers. **Das Kriterium dieses Blocks ist
  erfüllt.**
- **Zwischen der ersten und der zweiten kamen nur Nullen dazu**, so viele wie
  vorausgesagt. In `domain_metrics` sind es 12 Zeilen und 12 Nullen, vier je
  ruhiger Domain; der Lauf nennt drei. In `subscription_metrics` sind es 4
  Zeilen und 4 Nullen, also ein Abonnement, dessen Domains am 27. alle ruhig
  waren. **Das ist die Behebung von §0 Punkt 2 auf dem Server.** Nachgelegt hat
  sie genau den einen Tag, den die Vortagsregel zulässt; die ruhigen Tage davor
  bleiben ohne Zeile.
- Die übrigen abgelegten Zeilen standen schon da: zwölf für die drei zählbaren
  Domains und vier für ihr Abonnement, geschrieben vom Nachtlauf unter `rc.6`.
  Ob sie dabei ihre Werte behielten, sagt die Prüfsumme dieses einen Laufs
  nicht, denn die Nullen haben sie ohnehin bewegt. Dass zwei Sichten desselben
  Vortags dieselben Zahlen ergeben, ist seit B2 gemessen (`docs/134 §7`).
- **`8 je Abonnement` sind zwei Abonnements, und der Server hat drei** (Block
  0). Eines hat Verkehr, eines hat die Nullen, und **das dritte bekam keine
  Zeile**: Entweder war keine seiner Domains unter den sechs gelesenen, oder es
  hat keine. Welches von beiden zutrifft, zeigt Block 3 mit `ohne Zeile` und
  seinen Kacheln; die Domains je Abonnement druckt `docs/139` Block 2.
  **Es hat keine** (Block 3, nachgetragen am 30. September).
- `8 aus dem alten Zeitalter` sind die acht Zeilen aus B2 (`docs/134 §7`),
  sechs vom 15. August und zwei vom 5. September, in den Dateien einer Domain
  ohne Verkehr. Sie liegen ausserhalb des Vortags und stören ihn nicht.
- `0 nicht ganz gelesen`: Jede der sechs Domains war ganz gelesen, und keine
  Lücke hält eine Null auf.

### Der erste Nachtlauf unter der Behebung, und Block 4 noch einmal

Der Nachtlauf des 29. September lief um 00:20:01 unter `rc.7`. Sein Journal
steht in `docs/139 §7` (Vorbedingung): `3 Tageswert(e) vom Vortag zählbar,
3 ruhig (eine Null)`, `0 nicht ganz gelesen` und `Abgelegt: 24 Zeile(n) je
Domain, 8 je Abonnement.` — dieselben Zahlen wie der Lauf von Hand am Abend
davor, bis auf `1 noch offen` statt `3`: Der laufende Tag war um 00:20 zwanzig
Minuten alt. Die fünfte Zeile, `6 Domain(s) gelesen`, filtert der Block dort
heraus.

Block 4 lief am 30. September gegen 11 Uhr ein zweites Mal, nach dem ersten
Nachtlauf unter `rc.8`. Die drei Ablesungen und die beiden Läufe:

```
subscription_metrics     48 Zeilen    12 Nullen  Prüfsumme b1dd272a2f2a
domain_metrics          144 Zeilen    37 Nullen  Prüfsumme 85b5394c50bb
  Laufender Tag auf dem Server: 2026-09-30 (Europe/Berlin).
  6 Domain(s) gelesen, 24104 Zeile(n), davon 24096 gedeutet, 8 aus dem alten Zeitalter, 0 unlesbar.
  3 Tageswert(e) vom Vortag zählbar, 3 ruhig (eine Null), 0 übersprungen (gemischtes Format), 0 nicht ganz gelesen, 3 noch offen (laufender Tag), 5 älter und nicht erneut abgelegt.
  Abgelegt: 24 Zeile(n) je Domain, 8 je Abonnement.
  Fertig in 51 ms.
subscription_metrics     48 Zeilen    12 Nullen  Prüfsumme b1dd272a2f2a
domain_metrics          144 Zeilen    37 Nullen  Prüfsumme 85b5394c50bb
  Laufender Tag auf dem Server: 2026-09-30 (Europe/Berlin).
  6 Domain(s) gelesen, 24104 Zeile(n), davon 24096 gedeutet, 8 aus dem alten Zeitalter, 0 unlesbar.
  3 Tageswert(e) vom Vortag zählbar, 3 ruhig (eine Null), 0 übersprungen (gemischtes Format), 0 nicht ganz gelesen, 3 noch offen (laufender Tag), 5 älter und nicht erneut abgelegt.
  Abgelegt: 24 Zeile(n) je Domain, 8 je Abonnement.
  Fertig in 58 ms.
subscription_metrics     48 Zeilen    12 Nullen  Prüfsumme b1dd272a2f2a
domain_metrics          144 Zeilen    37 Nullen  Prüfsumme 85b5394c50bb
```

- **Alle drei Ablesungen sind gleich**, über zwei Läufen, die je 24 und 8
  Zeilen abgelegt haben: überschreibend und nicht addierend, nach einem
  Nachtlauf, der die Nullen schon kennt. Zwischen der ersten und der zweiten
  kam diesmal nichts dazu, wie für diesen Fall vorausgesagt.
- **Beide Zahlen waren vorher ausgerechnet.** 48 Zeilen sind 9 + 3 + 0 je
  Kennzahl mal vier, 144 sind 36 je Kennzahl mal vier. Die Nullen stehen genau
  an der vorausgesagten Untergrenze, 12 und 37: Ausser den ruhigen Tagen und
  der einen Null aus der ersten Ablesung vom 28. steht keine da.
- Beide Läufe melden dasselbe bis auf die Dauer, 51 und 58 ms, und wie am 28.
  `8 aus dem alten Zeitalter`.

### Ein Befund am Prüfmittel

**Block 4 stand in einer Fassung da, die gegen `rc.7` angeschlagen hätte, und
zwar ohne Fehler am Prüfling.** Geschrieben war er gegen `rc.6`: zwei
Ablesungen um einen Lauf, die Prüfsumme über alle Zeilen. Der erste Lauf unter
`rc.7` legt die Nullen des Vortags nach, die der Nachtlauf unter `rc.6` nicht
kannte. Ausserdem schreibt `srvpanel:usage` alle fünfzehn Minuten den Platz des
laufenden Tages in dieselbe Tabelle. Beides hätte die Prüfsumme zwischen den
beiden Ablesungen bewegt, und „überschreibend" hätte ausgesehen wie
„addierend". Gefunden hat es das Nachrechnen der Erwartung nach dem Update,
bevor der Block lief. Berichtigt ist er in §1, die Gegenprobe steht in §1a.

> **Der erste Lauf nach einem Update misst den Übergang und nicht den Zustand —
> er legt nach, was die alte Fassung nicht kannte.**

### Was noch aussteht

- **Teil 2.** Den Verkehr misst er am **21. und 22. Oktober**, Nacht 30 und
  Nacht 31 ab `D0`, dem 21. September (Block 2). Platz und Datenbanken misst
  er am **28. und 29. Oktober**. Punkt 3 aus §4 misst an `p6-b.invalid`, dem
  einzigen Abonnement mit `ohne Zeile: 0` (Block 3): `p6-abnahme.invalid` hat
  Zeilen erst ab dem 27. September, und `p6-nochmaltest` hat keine Domain.
  Gefahren ist er vom 21. bis 31. Oktober; das Protokoll ist §8.
- Am 30. September hat `p6-abnahme.invalid` für den Lauf von B4 eine
  Datenbank mit 3.801.036 B bekommen (`docs/139 §7`). Sie ändert, was
  `database_bytes` an diesem Tag trägt, und keine Zahl von Zeilen.

---

## §8 · Protokoll — Teil 2, gefahren vom 21. bis 31. Oktober 2026

Gefahren auf `cloudsrv24` gegen `0.9.0-rc.18`. An Schreiber, Abräumen, Kacheln
und Zeitgebern hat sich seit `rc.8` nichts geändert, nachgesehen am Verlauf der
betroffenen Dateien. Was Teil 1 gemessen hat, gilt damit für Teil 2.

| Tag | gefahren |
|---|---|
| 21. Oktober | Blöcke 5, 2 und 3 — Nacht 30 des Verkehrs |
| 22. Oktober | nur eine Abfrage zur Kachel (unten), die Blöcke nicht |
| 23. Oktober | das Journal vom 22. und die Blöcke 5, 2 und 3 |
| 28. und 29. Oktober | nichts |
| 30. Oktober | das Journal vom 28. und 29. und die Blöcke 5, 2 und 3 |
| 31. Oktober | die Blöcke 5, 2 und 3 |

**Jede Zahl war vorher ausgerechnet, und der Server hat jede getroffen.**
Gerechnet ist sie aus dem Bestand von Teil 1 (§7): `p6-b.invalid` mit drei
Domains und Verkehr ab dem 21. September, `p6-abnahme.invalid` mit drei Domains
ab dem 27., die Stände aller drei Abonnements ab dem 28. Am 15. Oktober ist sie
im Container nachgefahren (§2, Berichtigung). Die Besetzung blieb über den
ganzen Lauf dieselbe: Jeder gelesene Nachtlauf meldete
`Abgelegt: 24 Zeile(n) je Domain, 8 je Abonnement.`

### Nacht 30 des Verkehrs — 21. Oktober

```
0.9.0-rc.18
LastTriggerUSec=Wed 2026-10-21 00:57:08 CEST
Okt 21 00:57:08 cloudsrv24 php[212526]:   Laufender Tag auf dem Server: 2026-10-21 (Europe/Berlin).
Okt 21 00:57:08 cloudsrv24 php[212526]:   Abgelegt: 24 Zeile(n) je Domain, 8 je Abonnement.
Okt 21 00:57:08 cloudsrv24 php[212526]:   Fertig in 122 ms.
subscription_metrics   erster Tag 2026-09-21 mit 4 Zeile(n), 31 Tage insgesamt
domain_metrics         erster Tag 2026-09-21 mit 12 Zeile(n), 30 Tage insgesamt
```

- **Keine Zeile `Älter als`, und beide Tabellen beginnen bei `D0`.** Punkt 1
  aus §4 ist für den Verkehr erfüllt.
- Block 2: die vier Kennzahlen des Verkehrs mit 54 Zeilen bei den Abonnements
  und 162 bei den Domains, 30 Tage vom 21.09. bis 20.10.; `disk_mb` und
  `database_bytes` mit je 72 Zeilen, 24 Tage vom 28.09. bis 21.10.; beide
  Tabellen `doppelt: 0`.
- Block 3, `Spanne 2026-09-21 bis 2026-10-20: 30 Tage`: `p6-b.invalid` mit 30
  Zeilen je Kennzahl des Verkehrs und `ohne Zeile: 0 von 30 Tagen`,
  `p6-abnahme.invalid` mit 24 und den sechs Tagen vom 21. bis 26. September
  ohne Zeile, `p6-nochmaltest` ohne Zeile des Verkehrs. Platz und Datenbanken
  bei allen dreien mit 24 Zeilen, also an jedem Tag seit dem 28. September
  gemessen.
- **Die 31 Tage zeigt die Ausgabe selbst.** In Block 2 endet der Verkehr am
  20., die Stände am 21.; der 31. Tag ist der heutige Stand.
- Abgeschrieben: 4 und 12.

### Nacht 31 des Verkehrs — 22. Oktober, nachgelesen am 23.

Am 22. sind die Blöcke nicht gefahren worden. Das Journal, gelesen am 23.:

```
Okt 22 00:08:14 cloudsrv24 php[279547]:   Laufender Tag auf dem Server: 2026-10-22 (Europe/Berlin).
Okt 22 00:08:14 cloudsrv24 php[279547]:   Abgelegt: 24 Zeile(n) je Domain, 8 je Abonnement.
Okt 22 00:08:14 cloudsrv24 php[279547]:   Älter als 30 Tag(e) entfernt: 12 je Domain, 4 je Abonnement.
Okt 22 00:08:14 cloudsrv24 php[279547]:   Fertig in 58 ms.
```

Und die Blöcke am 23.:

```
LastTriggerUSec=Fri 2026-10-23 00:39:53 CEST
Okt 23 00:39:53 cloudsrv24 php[394121]:   Älter als 30 Tag(e) entfernt: 12 je Domain, 4 je Abonnement.
subscription_metrics   erster Tag 2026-09-23 mit 4 Zeile(n), 31 Tage insgesamt
domain_metrics         erster Tag 2026-09-23 mit 12 Zeile(n), 30 Tage insgesamt
```

- **Das Journal vom 22. hat genau die beiden Zahlen vom Abend davor entfernt**,
  über Kreuz. Das ist die tragende Hälfte von Punkt 2, gemessen.
- **Dass beide Tabellen danach am 22. September begannen, ist erschlossen.**
  Der Lauf vom 23. hat genau die Zeilen des 22. entfernt, und danach beginnen
  sie am 23. Hätte der Lauf vom 22. eine ältere Zeile stehen lassen, wäre am 23.
  mehr entfernt worden. Abgelesen hat den Stand vom 22. niemand.
- Block 2 am 23.: 56 und 168 Zeilen, 30 Tage vom 23.09. bis 22.10.; die Stände
  mit je 78 Zeilen und 26 Tagen. Block 3: `p6-b.invalid` mit 30 und
  `ohne Zeile: 0`, `p6-abnahme.invalid` mit 26 und vier Tagen ohne Zeile.
  **Punkt 3 hält an `p6-b.invalid`**, gemessen am 21. und am 23.

### Nacht 30 und 31 von Platz und Datenbanken — 28. und 29. Oktober, nachgelesen am 30.

An beiden Tagen ist nichts gefahren worden. Das Journal, gelesen am 30.:

```
Okt 28 00:29:32 cloudsrv24 php[709981]:   Laufender Tag auf dem Server: 2026-10-28 (Europe/Berlin).
Okt 28 00:29:32 cloudsrv24 php[709981]:   Abgelegt: 24 Zeile(n) je Domain, 8 je Abonnement.
Okt 28 00:29:32 cloudsrv24 php[709981]:   Älter als 30 Tag(e) entfernt: 24 je Domain, 8 je Abonnement.
Okt 28 00:29:32 cloudsrv24 php[709981]:   Fertig in 43 ms.
Okt 29 00:03:01 cloudsrv24 php[770881]:   Laufender Tag auf dem Server: 2026-10-29 (Europe/Berlin).
Okt 29 00:03:01 cloudsrv24 php[770881]:   Abgelegt: 24 Zeile(n) je Domain, 8 je Abonnement.
Okt 29 00:03:01 cloudsrv24 php[770881]:   Älter als 30 Tag(e) entfernt: 24 je Domain, 14 je Abonnement.
Okt 29 00:03:01 cloudsrv24 php[770881]:   Fertig in 47 ms.
```

- **Am 28. gingen 8 Zeilen je Abonnement**, die des Verkehrs vom 27. September.
  Platz und Datenbanken hatten keine Zeile, die alt genug war; ihr erster Tag
  ist der 28. Das ist Punkt 1 für ihre Uhr, gelesen am Journal.
- **Am 29. gingen 14**: die 8 des Verkehrs vom 28. September und die 6 Stände
  desselben Tags, ihres ersten. Ihr erster Tag ist damit genau in ihrer
  einunddreissigsten Nacht gegangen. Stünde am 28. schon 14 da, wäre er eine
  Nacht zu früh gegangen; stünde am 29. nur 8, gar nicht.
- Vorausgesagt waren beide Zahlen am 15. Oktober aus Teil 1. Den Block 5 vom
  Abend des 28., der die Vorhersage am Server festgehalten hätte, gibt es
  nicht.

### Der ganze Ablauf einmal direkt — 30. und 31. Oktober

Beide Paare haben damit je eine erschlossene Hälfte. Am 30. und 31. sind die
Blöcke deshalb an zwei Tagen hintereinander gefahren. Seit der
einunddreissigsten Nacht der Stände trägt der erste Tag Zeilen beider Uhren, und
jede Nacht tut mit ihm, was die einunddreissigste mit `D0` getan hat.

```
LastTriggerUSec=Fri 2026-10-30 00:36:01 CET
Okt 30 00:36:01 cloudsrv24 php[833956]:   Älter als 30 Tag(e) entfernt: 24 je Domain, 14 je Abonnement.
subscription_metrics   erster Tag 2026-09-30 mit 14 Zeile(n), 31 Tage insgesamt
domain_metrics         erster Tag 2026-09-30 mit 24 Zeile(n), 30 Tage insgesamt
```

```
LastTriggerUSec=Sat 2026-10-31 00:44:41 CET
Okt 31 00:44:41 cloudsrv24 php[898002]:   Älter als 30 Tag(e) entfernt: 24 je Domain, 14 je Abonnement.
subscription_metrics   erster Tag 2026-10-01 mit 14 Zeile(n), 31 Tage insgesamt
domain_metrics         erster Tag 2026-10-01 mit 24 Zeile(n), 30 Tage insgesamt
```

- **Am Abend des 30. stand der erste Tag mit 14 und 24 Zeilen da, am 31. hat
  das Journal genau diese entfernt, und der erste Tag ist um einen gerückt.**
  Das ist Punkt 2 einmal ganz gemessen, über alle sechs Kennzahlen, nur nicht
  in der einunddreissigsten Nacht.
- Block 2 an beiden Tagen: der Verkehr mit 60 und 180 Zeilen und 30 Tagen,
  `disk_mb` und `database_bytes` mit je 93 Zeilen und 31 Tagen, am 30. vom
  30.09. bis 30.10. und am 31. vom 01.10. bis 31.10.; `doppelt: 0`.
- Block 3 an beiden Tagen: alle drei Abonnements mit 31 Zeilen Platz und 31
  Datenbanken, **Punkt 3 für die Stände**. `p6-b.invalid` und
  `p6-abnahme.invalid` mit je 30 Zeilen des Verkehrs und `ohne Zeile: 0`:
  **Punkt 3 des Verkehrs hält jetzt an zwei Abonnements.** Das zweite haben die
  Nullen für ruhige Tage dahin gebracht, die es seit `rc.7` gibt (§5).
- Die Zeitumstellung am 25. Oktober liegt zwischen den beiden Paaren und hat
  an keiner Zahl etwas geändert. Die Zeitgeber melden seitdem `CET`.

### Eine Beobachtung an der Kachel, kein Befund

Die Traffic-Kachel von `p6-abnahme.invalid` stand in Teil 1 auf `0` und am
21. Oktober auf `0,0`. Die Kachel zeigt den Wert von gestern, Einheit und
Stellen wählt `Points::bytesFormatter()` aber nach dem grössten Tag im Fenster:
unter 1.000 B ohne Nachkommastelle, ab 1 kB mit einer. Nachgesehen am
22. Oktober, an welchen Tagen das Abonnement ausgehenden Verkehr hatte:

```
25 Tage mit Zeile, davon 1 über null
  2026-10-09  693009 B
```

Der 9. Oktober liegt im Lauf für B9 (`docs/143`). Ab dem 9. November ist er aus
dem Fenster, und die Kachel steht wieder auf `0`; das ist gerechnet und nicht
gemessen. B3 misst die Kacheln nicht (§3).

### Was der Lauf über sich selbst gelernt hat

**Zwei Erwartungen aus §2 waren seit `rc.7` falsch, und gefunden hat sie das
Nachrechnen vor dem Lauf und nicht der Lauf.** Der Absatz über den Stand war mit
der Behebung dazugekommen; die Erwartung zwei Absätze darüber blieb, wie sie
vorher stand.

> **Eine Erwartung, die vor einer Behebung geschrieben wurde, gilt für den
> Prüfling von vorher — auch wenn der Absatz daneben die Behebung schon
> beschreibt.**

**Drei Ablesetage sind ausgefallen.** Was die Läufe an ihnen gesagt hatten,
stand noch Tage später im Journal; den Stand der Tabelle zwischen zwei Läufen
hält niemand.

> **Eine Ablesung, die an einen Tag gebunden ist, lässt sich nicht nachholen —
> nur erschliessen, und nur so weit, wie eine Spur stehen bleibt.**

Der Ausweg war nicht, den Tag zu wiederholen. Nach der einunddreissigsten Nacht
tut jede Nacht mit ihrem ersten Tag dasselbe, und an zwei beliebigen Tagen
danach liess sich der ganze Ablauf messen.

> **Ein Kriterium über die einunddreissigste Nacht misst einen Vorgang, der
> danach jede Nacht wiederkommt — eine verpasste Nacht kostet dann einen Tag und
> nicht die Messung.**

**Und das Echo auf dem Telefon war verstümmelt, die Ausgabe nicht.** Am 31.
stand in der eingefügten Zeile von Block 3 ein Rest wie `'); }ize_bytes"));`,
und darunter jede Zeile der Ausgabe. Was gelaufen ist, sagt die Ausgabe; für
eine Datei und ihre Prüfsumme galt dasselbe in `docs/143 §8`.

### Wie das Kriterium steht

| Punkt aus §4 | Verkehr | Platz und Datenbanken |
|---|---|---|
| 1 · Nacht 30 beginnt bei `D0`, nichts davon entfernt | erfüllt am 21.10., Block 5 und Journal | am Journal vom 28.10.: entfernt nur der Verkehr vom 27.09. |
| 2 · Nacht 31 entfernt genau die vorhergesagten Zahlen, Beginn bei `D0 + 1` | Journal vom 22.10. gemessen, der Beginn erschlossen | Journal vom 29.10. gemessen, Vorhersage aus Teil 1, der Beginn erschlossen |
| 2 · der ganze Ablauf, direkt | am 30. und 31.10., über alle sechs Kennzahlen | ebenso |
| 3 · ohne Lücke 30 bzw. 31 Zeilen je Abonnement | `p6-b.invalid` am 21. und 23.10., dazu `p6-abnahme.invalid` am 30. und 31.10. | alle drei Abonnements am 30. und 31.10. |

Offen ist kein Punkt, der sich noch messen liesse. Erschlossen statt abgelesen
sind die Hälften, deren Tag vorbei ist.

### Die Abnahme

**Der Betreiber hat B3 am 31. Oktober 2026 abgenommen**, nach Teil 1 (§7) und
diesem Protokoll, mit den erschlossenen Hälften von Punkt 2, wie sie in der
Tabelle darüber stehen. Damit sind alle Merkmale von P9 abgenommen. Die
Dokumentation bleibt vertagt.
