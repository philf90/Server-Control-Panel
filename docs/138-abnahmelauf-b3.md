# B3 — der Abnahmelauf für die verdichtete Tabelle

Ausgeschrieben am 28. September 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/129 §9`:

> Nach dreissig Nächten stehen dreissig Zeilen je Abo und Kennzahl, und die
> einunddreissigste Nacht löscht die erste.

Gebaut ist B3 seit dem 21. September und ausgeliefert seit `v0.9.0-rc.1`; der
Plan ist `docs/129 §6`, die Begründungen stehen im CHANGELOG unter „B3 — die
verdichtete Tabelle". Gefahren wird gegen die installierte Fassung, auf
`cloudsrv24` ist das `0.9.0-rc.6`. §1 Block 1 fragt sie, bevor irgendetwas
anderes gemessen wird.

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
mit einer Gegenprobe in beide Richtungen** (§1a). Block 1 braucht einen Server.

```bash
# 1 · Welche Fassung läuft, und wie stehen die beiden Timer, die die Tabelle füllen sollen?
srvpanel version
for t in srvpanel-traffic.timer srvpanel-usage.timer; do
  printf '%s\n' "$t"
  systemctl cat "$t" | grep -E '^(OnCalendar|OnBootSec|Persistent|RandomizedDelaySec)='
  systemctl show "$t" -p LastTriggerUSec -p NextElapseUSecRealtime
done
```

**Erwartet:** `0.9.0-rc.6`. Für den Zähllauf `OnCalendar=daily`,
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
    $z = DB::table($t)->orderBy("id")->get(["id", "day", "metric", "value"]);
    printf("%-22s %4d Zeilen  Prüfsumme %s\n", $t, $z->count(), substr(md5($z->toJson()), 0, 12));
}
'
srvpanel tinker --execute="$B3_STAND"
srvpanel traffic
srvpanel tinker --execute="$B3_STAND"
```

**Erwartet:** `srvpanel traffic` meldet `Abgelegt: N Zeile(n) je Domain, M je
Abonnement.` mit **N über null**, und die beiden Ausgaben von `B3_STAND` sind
Zeile für Zeile gleich — dieselbe Zahl, dieselbe Prüfsumme. Das ist
„überschreibend und nicht addierend" an der Tabelle des Servers: Der Lauf hat
N Zeilen geschrieben, und keine ist dazugekommen.

**Steht dort `Abgelegt: 0`, hat der Block nichts gemessen** — zwei gleiche
Stände über einem Lauf, der nichts geschrieben hat, sind kein Beleg. Dann kam
gestern keine Anfrage; eine heute an eine Kundendomain macht den Block morgen
fahrbar:

```bash
curl -s -o /dev/null -w '%{http_code}\n' "https://<domain>/?b3=1"
```

**Mit der Behebung legt der Lauf auch an einem ruhigen Tag ab** — vier Nullen je
ganz gelesener Domain, und der Block ist jeden Tag fahrbar. `Abgelegt: 0` heisst
dann, dass keine Domain ganz gelesen war; der Lauf nennt jede unter „nicht ganz
gelesen".

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
keine Tabelle führt mehr als 30 Tage, und die Abonnements von gestern haben
wieder 30 Zeilen je Kennzahl.

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

**Teil 1 ist durch, wenn die Blöcke 1 bis 4 gefahren sind und ihre Ausgabe im
Protokoll steht.** Er bestätigt oder widerlegt §0 Punkt 1 und Punkt 2 auf dem
Server. **Block 4 darf ausfallen**, wenn gestern keine Anfrage kam
(`Abgelegt: 0`); er wird dann am nächsten Tag nachgeholt.

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
