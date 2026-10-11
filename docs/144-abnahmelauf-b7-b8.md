# B7 und B8 — der Abnahmelauf für API v1 und den Streifen der laufenden Vorgänge

Ausgeschrieben am 11. Oktober 2026, **vor** dem Fahren. Die Kriterien stehen in
`docs/129 §9`:

> **B7** · Ein Token eines Kunden liest über `api/v1` genau seine Abonnements —
> und dasselbe Token an einer fremden ID bekommt `404` und nicht `403`.
>
> **B8** · Ein Vorgang, der aus einer Liste heraus angestossen wird, führt
> zurück in diese Liste — die vier Fragen aus `docs/92 §4`.

Beide Pläne legen je zwei Punkte dazu, weil ein Lauf sonst den lauten Fall
misst und den leisen nicht (`docs/131 §7`, `docs/132 §4`). In beiden darf
Punkt 1 und Punkt 3 nicht ausfallen.

Gebaut sind beide seit dem 21. September 2026, ausgeliefert seit
`0.9.0-rc.1`; auf `cloudsrv24` läuft `0.9.0-rc.16`. Abgenommen ist keines von
beiden, und einen Lauf hatte bisher keines.

**Vorab im Container gemessen** (§7): jede Route von B7 durch die echte Wache,
mit Marken aus dem echten Formular, gegen eine eigene SQLite-Datenbank unter
`artisan serve`; der Streifen von B8 im echten Chromium mit der Taktprobe vom
11. Oktober; und die Werkzeuge dieses Laufs. Jede Erwartung in §3 und §4 ist
eine Zeile daraus oder sagt, warum nicht.

**Beim Ausschreiben sind vier Befunde am Prüfling herausgefallen** (§0) und
sieben Punkte an der Vorschrift und am Messmittel. Der Betreiber hat die vier
am selben Tag entschieden, alle wie vorgeschlagen, und behoben sind sie für
`0.9.0-rc.17` — zusammen mit einem fünften, der beim Beheben herausfiel
(§6a). Die Kriterien berühren sie nicht. **Gefahren wird gegen die Freigabe,
die die Behebungen trägt**; Block 0, A4, A7, A8 und B8 zeigen sie.

**Der Lauf braucht einen Nachmittag.** Teil A (B7) läuft auf dem Server und in
einem privaten Fenster, Teil B (B8) im Browser des Betreibers am Schreibtisch,
mit offener Konsole. Zwischen beiden muss keine Zeit vergehen.

---

## §0 · Was beim Ausschreiben herausgefallen ist

### Vier Befunde am Prüfling

1. **Bei 390 px bricht der Streifen „ansehen" Buchstabe für Buchstabe um**
   (B8). Gemessen im Container mit drei Vorgängen, einem wartenden und zwei
   fertigen: Ein fertiger Vorgang ist **146 px** hoch, „ansehen" steht auf
   **sechs** Zeilen, und `system.packages.refresh` bricht mitten im Namen.
   `tests/bilder-messen.js` sagt dazu in allen vier Lagen `dokument = 0`,
   Gegenprobe 200/200: Nichts läuft über.

   > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
   > Betrachter.**

   Die Ursache steht in `.band`: eine Flexzeile mit `justify-content:
   space-between` und `overflow-wrap: anywhere`. Damit darf jedes Kind bis auf
   ein Zeichen schrumpfen, und neben „Seite aktualisieren", das seine Breite
   behält, schrumpfen Text und Verweis. Die übrigen Bänder haben ein Kind
   (Ankündigung, Wartung) oder zwei (die fremde Sicht); der Streifen ist das
   einzige mit dreien.

   **Ein Vorschlag, im Container gemessen und nicht gebaut:** Die Zeile darf
   umbrechen, „Seite aktualisieren" und „ansehen" schrumpfen nicht, und der
   Knopf schiebt beide nach rechts:

   ```css
   .band { flex-wrap: wrap; }
   .band > .button, .band > .link { flex: none; }
   .band > .button { margin-left: auto; }
   ```

   Bei 390 px steht danach der Text oben und die beiden Handgriffe darunter
   rechts, „ansehen" in einer Zeile, ein fertiger Vorgang **122 px** hoch. Bei
   1440 px stehen beide rechts statt den Knopf in der Mitte, die Höhe bleibt
   48 px. Ein Rest bleibt: Beim wartenden Vorgang rutscht „ansehen" bei 390 px
   links unter den Text.

2. **In fremder Sicht steht „Marke anlegen" auf der Seite, und der Klick endet
   mit 403** (B7). Gemessen im Container: Ein Betreiber wechselt über „Anmelden
   als" in die Sicht eines Kunden. „Mein Konto" zeigt dann „Darstellung",
   „Sicherheit" und **„Zugangsmarken"** samt Formular und „Entfernen"; „Konto"
   und „Passwort ändern" sind ausgeblendet. Ein Klick auf „Marke anlegen" gibt
   **403**, und das Protokoll bucht `api.token.created · denied` am Konto des
   Betreibers.

   Die Tür hält, und sie hält zu Recht (`refuseWhileImpersonating()`). Falsch
   ist der Satz daneben: *„Die Oberfläche zeigt in diesem Zustand kein
   Formular. Wer hier ankommt, hat die Anfrage selbst gestellt."* Für „Konto"
   und „Passwort ändern" stimmt er, für die Zugangsmarken nicht — und der
   Eintrag im Protokoll nennt einen Klick auf einen sichtbaren Knopf einen
   selbst gestellten Versuch.

   > **Eine Begründung, die ein Formular für abwesend erklärt, gilt für die
   > Formulare, die es gab, als sie geschrieben wurde.**

   `AbilityReachTest` hält „ein Knopf, den der Betrachter nicht drücken darf,
   wird nicht gezeigt" über die Fähigkeiten der Policy. Die fremde Sicht ist
   keine Fähigkeit, und so sieht er den Fall nicht.

3. **Eine Marke wird ohne Rückfrage entfernt** (B7). „Entfernen" in der Liste
   ist der einzige `<Link method="delete">` dieses Panels; jeder andere
   Löschknopf fragt über `ask()` — auch der eines SSH-Schlüssels, und der ist
   dieselbe Art Ding: ein Zugang, den ein anderes Programm benutzt. Gemessen:
   ein Klick, und anderthalb Sekunden später gibt die Marke 401. Zurückholen
   lässt sie sich nicht, der Klartext ist nirgends abgelegt. Wer sich
   verklickt, legt eine neue an und trägt sie in jedem Programm neu ein.

4. **Die OpenAPI-Beschreibung liegt im Repo und nicht im Paket** (B7).
   `SpecController` liest `base_path('docs/openapi-v1.yaml')`, und die
   Positivliste in `packaging/build.sh` nimmt `docs` nicht mit. Auf dem Server
   gibt `/api/v1/openapi.yaml` damit 404. Gemessen im Container ohne die Datei:
   `404 · application/json · {"message": ""}` — nicht einmal ein Text.
   `OpenApiReachTest` hält die Beschreibung im Repo in beide Richtungen gegen
   die Routen, und genau dort liegt sie.

   > **Eine Datei, die ein Wächter im Repo prüft, ist damit noch nicht auf dem
   > Server.**

   Der Satz steht seit dem 15. August im Kopf von `build.sh`, damals über
   `lang`. Ausgezählt liest sonst keine Stelle unter `app/` eine Datei, die das
   Paket nicht mitnimmt.

### Die Fragen an den Betreiber

| | Frage | Vorschlag |
|---|---|---|
| **1** | Der Streifen bei 390 px | **a** — vor dem Lauf beheben, mit den drei Zeilen aus Befund 1 und einem Wächter dazu. b — nach dem Lauf. c — lassen. |
| **2** | „Zugangsmarken" in fremder Sicht | **a** — die Liste bleibt, Formular und „Entfernen" nicht: Wer einem Kunden hilft, sieht, ob es eine Marke gibt und wann sie zuletzt benutzt wurde. b — der Bereich fällt ganz weg, wie „Konto". c — lassen. |
| **3** | Entfernen ohne Rückfrage | **a** — eine Rückfrage wie beim SSH-Schlüssel. b — lassen. |
| **4** | Die Beschreibung im Paket | **a** — `docs/openapi-v1.yaml` kommt in die Positivliste von `build.sh`, und ein Wächter hält, dass jede Datei, die `app/` über `base_path()` liest, unter einem Teil liegt, den das Paket mitnimmt. b — lassen. |

**Entschieden am 11. Oktober 2026: 1a, 2a, 3a und 4a**, alle wie
vorgeschlagen. Gebaut ist alles für `0.9.0-rc.17`; was dabei anders lief als
im Vorschlag und wie es gemessen ist, steht in §6a. Die Befunde oben stehen so
da, wie sie vor dem Bau gemessen waren. Gefahren wird gegen die Freigabe mit
den Behebungen, und Block 0, A4, A7, A8 und B8 zeigen sie.

### Sieben Punkte an der Vorschrift und am Messmittel

1. **`docs/131 §7` Punkt 2 nennt `200 []` für einen Kunden ohne Abonnements;
   die echte Route gibt 403.** `SubscriptionPolicy::viewAny()` antwortet
   zuerst. `docs/131 §6` hat das am 21. September beim Bauen festgehalten, §7
   desselben Dokuments nicht.

   > **Zwei Zeilen desselben Dokuments über dieselbe Frage laufen auseinander,
   > und keine von beiden ist der Ort, an dem man nachsieht.**

   Der Punkt bleibt trotzdem fahrbar und sagt dasselbe, nur an anderen Zahlen:
   Eine Route ohne Klammer gäbe `200 []`, weil die Klammer im Grundzustand
   alles verweigert, und der leere Kunde bekommt 403. `docs/131 §7` trägt
   seitdem einen Nachsatz.

2. **`docs/131 §7` Punkt 3 nennt `access.log` und `access.log.1`; die API
   schreibt in `/var/log/srvpanel/panel-access.log`.** Die beiden Dateien
   gehören den Kundendomains, und `api/v1` geht an den Server-Block des Panels
   (`PanelVhost::ACCESS_LOG`). Gesucht wird deshalb über alle Protokolle des
   Panels und das Journal.

3. **Die Gegenprobe von Punkt 3 schreibt keine Marke in ein Protokoll.**
   `docs/131 §7` verlangte, dieselbe Marke im Abfrageteil zu schicken und dort
   zu finden. Das legte eine gültige Marke in eine Datei, die vierzehn Tage
   liegt und die das Panel auf `/logs` vorliest — genau den Schaden, gegen den
   der Punkt prüft. Die Gegenprobe ist ein Merkzeichen ohne Wert,
   `b7-gegenprobe-<Zeit>`, im Abfrageteil einer Anfrage mit der Marke im Kopf.
   Steht das Merkzeichen im Protokoll und die Marke nicht, ist beides gemessen.

   > **Eine Gegenprobe, die den Schaden herstellt, gegen den der Punkt prüft,
   > ist kein Prüfkörper, sondern ein Leck mit Messwert.**

4. **Die Taktprobe sah keine ganzen Seiten und keine Ströme, und ein zweites
   Einsetzen hätte jede Zeile verdoppelt.** `tests/takt-messen.js` meldete bis
   heute nur Teilladungen. Für B8 heisst „nach dem Ende fragt niemand mehr
   nach" aber auch „und die Seite lädt sich nicht von selbst neu", und ohne
   eine Zeile für ganze Seiten wäre beides nicht zu trennen gewesen. Die Probe
   meldet seitdem ganze Seiten (`Seite bei …`) und jedes Öffnen einer
   `EventSource` (`Strom bei …`), und sie weist ein zweites Einsetzen ab. Drei
   Fälle in `TickProbeTest`, drei Eingriffe ins Bruchskript, jeder rot und
   zurück grün belegt.

   > **Eine Probe, die sich zweimal einhängt, meldet ihr eigenes Doppel als
   > Doppel des Prüflings.**

5. **Ein offener Strom steht nicht in `performance.getEntriesByType('resource')`.**
   Der erste Vorabflug las dort für „kein Strom auf den Listen" eine 0 — und
   dieselbe 0 gibt eine Vorgangsseite mit offenem Strom. Gemessen im Chromium
   an einem eigenen kleinen Server: offen **0**, vom Klienten geschlossen
   **1**, vom Server beendet **1**. Gelesen wird deshalb die Zeile der Probe
   beim Öffnen.

   > **Eine Messung über abgeschlossene Anfragen sieht die offene nicht — und
   > ein Strom ist offen, solange er etwas taugt.**

6. **Die Drosselung zählt je Adresse und nicht je Marke** —
   `Limit::perMinute(60)->by($request->ip())`, vor der Wache, damit eine
   erfundene Marke keine Abfrage kostet. Alles, was in derselben Minute von
   `127.0.0.1` kam, zählt mit, auch die Anfragen ohne Marke. Gemessen im
   Container: 61 Anfragen in ein leeres Fenster ergeben **60 × 200 und
   1 × 429**; dieselben 61 kurz nach neun anderen ergeben **51 × 200 und
   10 × 429**. Der Block von A5 beginnt deshalb mit 61 Sekunden Pause.

7. **Die Kennungen kommen über den Kunden und nicht über die Klammer.** Konto 6
   sieht womöglich mehr als ein Abonnement. Block 0 liest die eigenen über
   `customer_id`, also auf einem anderen Weg als die API mit `ApplyTenancy` —
   sonst bestätigte eine falsche Klammer sich selbst.

   > **Eine Erwartung, die über denselben Weg entsteht wie die Messung,
   > bestätigt den Weg und nicht das Ergebnis.**

### Beobachtungen ohne Frage

- **Der Streifen nennt den Namen der Operation**, `system.packages.refresh` und
  `backup.create`, und nicht die Beschriftung aus der Skizze in `docs/132 §2`
  („Pakete einspielen"). Gebaut ist `task ?? type`, dieselbe Quelle wie die
  Seiten unter „Vorgänge". Für die hat der Betreiber am 10. Oktober
  entschieden, dass sie bleiben, wie sie sind (`docs/143 §8`); der Streifen
  folgt ihnen.
- **Die Antwort auf eine fremde Kennung nennt die Klasse des Modells**,
  `App\Models\Subscription`. Das ist Laravels Vorgabe für ein nicht gefundenes
  Modell, und sie ist für eine fremde und eine erfundene Kennung gleich —
  darauf kommt es an.
- **Die Marke eines zurückgezogenen Kunden bleibt in der Ablage.** Gemessen im
  Container: Nach dem Zurückziehen gibt sie 401, Konto und Marke stehen weiter
  da, und mit dem Kunden käme sie zurück wie sein Passwort. Einen Weg zurück
  gibt die Oberfläche nicht. A9 nimmt die Marke des Wegwerfkunden deshalb
  ausdrücklich mit.

---

## §1 · Die Werkzeuge und die Vorbedingung

### Block 0 — Fassung, Kennungen, Marken

Liest nur und schreibt zwei Dateien unter `/root`: die Ausgabe und die fünf
Kennungen für Teil A. `srvpanel tinker` läuft nach `setpriv` nicht als root und
kann dort nicht schreiben; die Kennungen gehen deshalb über die Ausgabe in die
Datei.

```bash
# 0 · Fassung, Kennungen für Konto 6, Marken, Drosselung — liest nur; schreibt /root/b78-block0.txt und /root/b78-kennungen.sh
srvpanel version
KONTO=6 srvpanel tinker --execute='
$konto = App\Models\Account::query()->find((int) getenv("KONTO"));
if ($konto === null) { echo "Konto ", getenv("KONTO"), ": FEHLT\n"; return; }
printf("Konto %d · %s · %s · Kunde %s\n", $konto->id, $konto->type->value, $konto->status->value, $konto->customer_id ?? "—");
app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(function () use ($konto) {
  $abos = App\Models\Subscription::query()->orderBy("id")->get();
  $eigene = $abos->where("customer_id", $konto->customer_id)->values();
  $fremde = $abos->where("customer_id", "!=", $konto->customer_id)->values();
  $domains = App\Models\Domain::query()->withoutGlobalScopes()->orderBy("id")->get();
  $eigeneDomains = $domains->whereIn("subscription_id", $eigene->pluck("id"))->values();
  $fremdeDomains = $domains->whereNotIn("subscription_id", $eigene->pluck("id"))->values();
  printf("  eigene Abonnements: %d — %s\n", $eigene->count(), $eigene->map(fn ($a) => $a->id." ".$a->name." (".$a->status->value.")")->implode(", "));
  printf("  eigene Domains: %d — %s\n", $eigeneDomains->count(), $eigeneDomains->map(fn ($d) => $d->id." ".$d->name)->implode(", "));
  printf("  fremde Abonnements: %d, das erste: %s\n", $fremde->count(), $fremde->first() ? $fremde->first()->id." ".$fremde->first()->name : "KEINES");
  printf("  fremde Domains: %d, die erste: %s\n", $fremdeDomains->count(), $fremdeDomains->first() ? $fremdeDomains->first()->id." ".$fremdeDomains->first()->name : "KEINE");
  $na = 999999;
  printf("  Abonnement %d: %s · Domain %d: %s\n", $na, $abos->contains("id", $na) ? "GIBT ES" : "gibt es nicht", $na, $domains->contains("id", $na) ? "GIBT ES" : "gibt es nicht");
  $ea = $eigene->first(fn ($a) => $eigeneDomains->contains("subscription_id", $a->id)) ?? $eigene->first();
  $ed = $eigeneDomains->firstWhere("subscription_id", $ea?->id);
  foreach (["EA" => $ea?->id, "FA" => $fremde->first()?->id, "ED" => $ed?->id, "FD" => $fremdeDomains->first()?->id, "NA" => $na] as $name => $wert) printf("%s=%s\n", $name, $wert ?? "");
  $m = App\Models\ApiToken::query()->orderBy("id")->get();
  printf("Zugangsmarken: %d%s\n", $m->count(), $m->isEmpty() ? "" : " — ".$m->map(fn ($t) => "Konto ".$t->account_id." ".$t->name)->implode(", "));
  printf("Kunden ohne Abonnement: %d\n", App\Models\Customer::query()->whereDoesntHave("subscriptions")->count());
});
printf("Cache: %s · Drosselung api/v1: %d je Minute\n", config("cache.default"), App\Providers\SrvPanelServiceProvider::API_PER_MINUTE);
' | tee /root/b78-block0.txt
grep -E '^(EA|FA|ED|FD|NA)=[0-9]+$' /root/b78-block0.txt > /root/b78-kennungen.sh
printf 'Kennungen in der Datei: %s von 5\n' "$(wc -l < /root/b78-kennungen.sh)"
printf 'Zugriffsprotokoll des Panels: %s Zeilen · jq: %s\n' "$(wc -l < /var/log/srvpanel/panel-access.log)" "$(command -v jq || echo FEHLT)"
ls /opt/srvpanel/current/docs 2>&1
systemctl is-active srvpanel-worker.service srvpanel-agentd.service
```

**Erwartet**, Zeile für Zeile:

```
0.9.0-rc.17
Konto 6 · customer · active · Kunde <k>
  eigene Abonnements: <n> — 140 p6-abnahme.invalid (active)[, …]
  eigene Domains: <m> — 54 p6-abnahme.invalid[, …]
  fremde Abonnements: <…>, das erste: <FA> <Name>
  fremde Domains: <…>, die erste: <FD> <Name>
  Abonnement 999999: gibt es nicht · Domain 999999: gibt es nicht
EA=140
FA=<…>
ED=54
FD=<…>
NA=999999
Zugangsmarken: 0
Kunden ohne Abonnement: <z>
Cache: database · Drosselung api/v1: 60 je Minute
Kennungen in der Datei: 5 von 5
Zugriffsprotokoll des Panels: <n> Zeilen · jq: /usr/bin/jq
openapi-v1.yaml
active
active
```

- **`0.9.0-rc.17`** ist die Freigabe mit den Behebungen aus §6a. Steht dort
  eine ältere, gelten für A4, A7, A8 und B8 die Befunde aus §0 und nicht die
  Erwartungen hier; gefahren wird gegen die neue.
- **`EA=140` und `ED=54`** sind das Abonnement des Prüfstands und seine
  Hauptdomain, wie in `docs/143`. Die erste fremde Kennung ist die kleinste,
  die nicht dem Kunden von Konto 6 gehört. `137 p6-b.invalid` ist eine; ob sie
  die erste ist, sagt Block 0.
- **„fremde Abonnements" ist nicht 0.** Sonst gäbe es keinen Prüfkörper für
  „fremd", und A2 mässe nur „gibt es nicht".
- **`Zugangsmarken: 0`.** Steht dort eine, wird sie abgeschrieben; der Lauf
  lässt sie stehen.
- **„Kunden ohne Abonnement"** wird abgeschrieben. Nach A3 ist es einer mehr,
  nach A9 wieder dieselbe Zahl.
- **`Cache: database`** trägt A5: Die Drosselung zählt in der Datenbank.
- **`openapi-v1.yaml`** unter `docs`, und nur sie, ist die Behebung von
  Befund 4 auf dem Server (§6a). Unter `rc.16` stand dort `No such file or
  directory`; das Verzeichnis gab es im Paket nicht.

### Block H — die Werkzeuge

**Als Datei, wie seit B5** (`docs/141 §1`): Der Block schreibt
`/root/b78-werkzeuge.sh` und liest sie ein; in einer neuen Sitzung genügt
`. /root/b78-werkzeuge.sh`. `api`, `liste` und `drossel` sprechen über die
Leitung des Servers mit dem Panel, so wie ein Programm von aussen; `marken`,
`vorgaenge` und `spur` lesen. Geschrieben wird von keiner — jede Anfrage
berührt nur den Zähler der Drosselung und `last_used_at` der Marke.

```bash
# H · Werkzeuge als Datei — schreibt /root/b78-werkzeuge.sh und liest sie ein; die Funktionen schreiben nichts
cat > /root/b78-werkzeuge.sh <<'WERKZEUGE'
# H · Werkzeuge für docs/144 (B7 und B8) — in jeder Sitzung: . /root/b78-werkzeuge.sh
P=$(awk -F= '/^PANEL_PORT=/{print $2; exit}' /etc/srvpanel/panel.env)
H=$(hostname -f)
U="https://$H:$P"
C=(curl -sk --noproxy '*' --resolve "$H:$P:127.0.0.1" -H 'Accept: application/json')
# Die Kennungen aus Block 0: EA/ED eigenes Abonnement und eigene Domain,
# FA/FD fremde, NA eine, die es nicht gibt.
[ -f /root/b78-kennungen.sh ] && . /root/b78-kennungen.sh

# Eine Anfrage an api/v1: Status, Art und der Anfang der Antwort. Die Marke kommt
# aus einer Variablen und steht damit weder im Befehl noch in der Geschichte.
#   api "$T" /api/v1/me      — mit Marke
#   api ''   /api/v1/me      — ohne Marke
api() {
  local marke="$1" pfad="$2" kopf=()
  [ -n "$marke" ] && kopf=(-H "Authorization: Bearer $marke")
  "${C[@]}" "${kopf[@]}" -o /tmp/b78-antwort -w '%{http_code} · %{content_type} · ' "$U$pfad"
  head -c 240 /tmp/b78-antwort | tr '\n' ' '; echo
  rm -f /tmp/b78-antwort
}

# Eine Liste aus api/v1: der Status, dann je Eintrag Kennung und Name
liste() {
  local marke="$1" pfad="$2"
  "${C[@]}" -H "Authorization: Bearer $marke" -o /tmp/b78-antwort -w 'Status %{http_code}\n' "$U$pfad"
  jq -r '.data[]? | "  \(.id) · \(.name)"' /tmp/b78-antwort
  rm -f /tmp/b78-antwort
}

# n Anfragen hintereinander an /api/v1/me: je Status die Zahl und was eine im Mittel kostet
drossel() {
  local marke="$1" n="${2:-61}" i
  for i in $(seq 1 "$n"); do
    "${C[@]}" -H "Authorization: Bearer $marke" -o /dev/null -w '%{http_code} %{time_total}\n' "$U/api/v1/me"
  done > /tmp/b78-drossel
  awk '{n[$1]++; t[$1]+=$2} END {for (s in n) printf "  %s: %d Anfragen, im Mittel %.0f ms\n", s, n[s], 1000*t[s]/n[s]}' /tmp/b78-drossel | sort
  rm -f /tmp/b78-drossel
}

# Die Zugangsmarken in der Ablage: wem, wie sie heissen, ihr Anfang — misst nichts
marken() {
  srvpanel tinker --execute='
    $m = App\Models\ApiToken::query()->orderBy("id")->get();
    printf("Zugangsmarken: %d\n", $m->count());
    foreach ($m as $t) printf("  Marke %d · Konto %d · %s · %s… · zuletzt benutzt %s · angelegt %s\n", $t->id, $t->account_id, $t->name, $t->preview, App\Support\Time\Clock::display($t->last_used_at) ?? "nie", App\Support\Time\Clock::display($t->created_at));
  '
}

# Die jüngsten Vorgänge, mit dem Konto, das sie ausgelöst hat — misst nichts
vorgaenge() {
  N="${1:-6}" srvpanel tinker --execute='
    $z = fn ($t) => $t === null ? "—" : substr((string) App\Support\Time\Clock::display($t), 11);
    foreach (App\Models\Operation::query()->withoutGlobalScopes()->orderByDesc("id")->limit((int) getenv("N"))->get()->reverse() as $o) {
      printf("Vorgang %d · %-24s · Konto %-2s · %-9s · %3d %% · angelegt %s · begonnen %s · fertig %s\n", $o->id, $o->type, $o->account_id ?? "—", $o->status->value, $o->progress, $z($o->created_at), $z($o->started_at), $z($o->finished_at));
    }
  '
}

# Wo steht ein Wert? Zählt Zeilen je Protokolldatei des Panels und im Journal
# seit $2. Druckt den Wert nicht, nur seinen Anfang und seine Länge.
#   spur "$T" "2026-10-12 09:00"
spur() {
  local wert="$1" seit="$2"
  printf 'Gesucht: %s… (%d Zeichen), seit %s\n' "${wert:0:9}" "${#wert}" "$seit"
  grep -c -F -- "$wert" /var/log/srvpanel/*.log /var/log/srvpanel/*.log.1 /var/lib/srvpanel/storage/logs/*.log 2>/dev/null | sed 's/^/  /'
  printf '  Journal seit %s: %s\n' "$seit" "$(journalctl --since "$seit" --no-pager -q 2>/dev/null | grep -c -F -- "$wert")"
}
WERKZEUGE
. /root/b78-werkzeuge.sh
printf 'Werkzeuge: %s von 6 · %s Zeilen · %s\n' "$(declare -F | awk '{print $3}' | grep -cxE 'api|liste|drossel|marken|vorgaenge|spur')" "$(wc -l < /root/b78-werkzeuge.sh)" "$(sha256sum /root/b78-werkzeuge.sh | cut -c1-16)"
printf 'Panel: %s · EA=%s FA=%s ED=%s FD=%s NA=%s\n' "$U" "$EA" "$FA" "$ED" "$FD" "$NA"
```

**Erwartet:**

```
Werkzeuge: 6 von 6 · 67 Zeilen · 7090a2e6deb3ead4
Panel: https://<Rechnername>:<Port> · EA=140 FA=<…> ED=54 FD=<…> NA=999999
```

Die Prüfsumme ist im Container über die Datei gerechnet, die dieser Block
schreibt (§7). Steht dort eine andere, ist beim Einfügen etwas verloren
gegangen, und der Block wird wiederholt.

> **Was eine Datei enthält, sagt ihre Prüfsumme und nicht das Echo, mit dem sie
> entstand.**

- **Die Marken stehen nur in der Sitzung**, in der sie eingelesen wurden, und
  ihr Klartext nur einmal auf der Seite. Geht die Sitzung verloren, entfernt
  das Konto die Marke und legt eine neue an.
- **`spur` druckt den Wert nicht**, nur seinen Anfang und seine Länge — auch
  das Protokoll dieses Laufs bekommt keine Marke.

### Die Taktprobe für Teil B

`tests/takt-messen.js`, **Stand 2026-10-11**, aus dem Repository: vor dem Merge
von diesem Zweig, danach von `main`. Eine ältere Fassung meldet weder ganze
Seiten noch Ströme, und mit ihr ist B3 nicht zu lesen. Die Probe lebt bis zum
nächsten vollen Neuladen der Seite; Navigationen über das Menü überlebt sie,
denn Inertia lädt dabei das Dokument nicht neu.

---

## §2 · Prüfkörper und Reihenfolge

- **Die Marke von Konto 6**, `b7-abnahme`, angelegt in A1 und entfernt in A7.
- **Ein Wegwerfkunde „B7 Leer"** ohne Abonnement, angelegt in A3, in A8 der
  Kunde, in dessen Sicht der Betreiber wechselt, und zurückgezogen in A9.
  Vertrags- und Anmeldeadresse `b7-leer@b7.invalid`, reserviert nach
  RFC 2606; eine Mail ginge an niemanden, und das Anlegen verschickt keine
  (gelesen am `CustomerController`). Zurück bleibt sein Grabstein mit einer
  verbrauchten Kundennummer, wie bei jedem zurückgezogenen Kunden.
- **Drei Paketauffrischungen** aus `/updates` in Teil B, mit B8 vier. Und **eine Sicherung**
  von `p6-abnahme.invalid`, wenn B7 gefahren wird; die Aufbewahrung nimmt sie
  in der Nacht mit wie jede andere.
- **`srvpanel vhost`** in B5. Es schreibt den Server-Block des Panels und die
  Rotationsdateien neu, wie jedes Update, und reiht je Abonnement einen Vorgang
  ohne Konto ein.

**Reihenfolge:** Block 0, Block H, Teil A von A0 bis A9, Teil B von B0 bis B8.

**Private Fenster eines Browsers teilen sich ihre Cookies.** Konto 6 und
B7 Leer melden sich deshalb nacheinander im selben privaten Fenster an, nicht
in zweien; die zweite Anmeldung beendete sonst die erste, ohne dass es eine
Meldung gibt.

---

## §3 · Teil A — B7

### A0 — Der Beginn

```bash
# A0 · Beginn — liest nur
. /root/b78-werkzeuge.sh
START=$(date '+%F %T'); echo "Beginn: $START"
```

`START` braucht A6 für das Journal. Gesetzt wird er **vor** dem Anlegen der
Marke, damit jede Zeile, die dabei entsteht, im Fenster liegt.

### A1 — Die Marke

Im privaten Fenster als Konto 6 anmelden, „Mein Konto", Bereich
„Zugangsmarken": Bezeichnung `b7-abnahme`, **„Marke anlegen"**. Oben steht
danach „Die neue Zugangsmarke" mit dem Klartext, darunter die Liste:
`b7-abnahme`, Anfang `srvp_…`, „Zuletzt benutzt" `nie`. Den Klartext kopieren.

```bash
# A1 · Die Marke in die Shell — liest sie verdeckt ein; schreibt nichts
read -rs -p 'Marke: ' T; echo
printf 'Anfang %s… · %d Zeichen\n' "${T:0:9}" "${#T}"
marken
```

**Erwartet:**

```
Anfang srvp_<4 Zeichen>… · 53 Zeichen
Zugangsmarken: 1
  Marke <n> · Konto 6 · b7-abnahme · srvp_<4 Zeichen>… · zuletzt benutzt nie · angelegt <jetzt>
```

Der Anfang ist derselbe wie in der Spalte „Anfang" auf der Seite und in
`marken`. 53 Zeichen sind `srvp_` und 48 Hexziffern.

### A2 — Punkt 1: genau die eigenen; fremd und erfunden sind gleich

**Darf nicht ausfallen.**

```bash
# A2 · Punkt 1 — eigene, fremde und erfundene Kennungen; liest nur
api "$T" /api/v1/me
liste "$T" /api/v1/subscriptions
api "$T" "/api/v1/subscriptions/$EA"
api "$T" "/api/v1/subscriptions/$FA"
api "$T" "/api/v1/subscriptions/$NA"
liste "$T" "/api/v1/subscriptions/$EA/domains"
api "$T" "/api/v1/subscriptions/$FA/domains"
api "$T" "/api/v1/domains/$ED"
api "$T" "/api/v1/domains/$FD"
api "$T" "/api/v1/domains/$NA"
api "$T" "/api/v1/subscriptions/$EA/metrics"
```

**Erwartet**, Zeile für Zeile:

```
200 · application/json · {"data":{"account_id":6,"name":"<Name>","type":"customer","subscriptions":<n>}}
Status 200
  140 · p6-abnahme.invalid
  … genau die eigenen aus Block 0
200 · application/json · {"data":{"id":140,"name":"p6-abnahme.invalid","status":"active","plan":"Standard",…
404 · application/json · {     "message": "No query results for model [App\\Models\\Subscription] <FA>" }
404 · application/json · {     "message": "No query results for model [App\\Models\\Subscription] 999999" }
Status 200
  54 · p6-abnahme.invalid
  … genau die eigenen Domains von 140 aus Block 0
404 · application/json · {     "message": "No query results for model [App\\Models\\Subscription] <FA>" }
200 · application/json · {"data":{"id":54,"subscription_id":140,"name":"p6-abnahme.invalid",…
404 · application/json · {     "message": "No query results for model [App\\Models\\Domain] <FD>" }
404 · application/json · {     "message": "No query results for model [App\\Models\\Domain] 999999" }
200 · application/json · {"retention_days":30,"data":[{"day":"2026-…","metric":"…","value":…},…
```

**Erfüllt, wenn:**

- die Liste genau die eigenen Abonnements aus Block 0 trägt, keines mehr und
  keines weniger, und `subscriptions` in `/me` ihre Zahl ist;
- eine fremde und eine erfundene Kennung dieselbe Antwort geben, 404 mit
  demselben Satz und nur einer anderen Zahl — für Abonnements wie für Domains;
- nirgends 403 steht. Ein 403 an einer fremden Kennung verriete, dass es sie
  gibt.

### A3 — Punkt 2: ein Kunde ohne Abonnement

Im Hauptfenster: Kunden, **„Kunde anlegen"**. Vorname `B7`, Nachname `Leer`,
E-Mail und Anmeldeadresse `b7-leer@b7.invalid`, „Passwort erzeugen" und das
Passwort notieren, „Anlegen". Im privaten Fenster: als Konto 6 abmelden, als
`b7-leer@b7.invalid` anmelden, „Mein Konto", Bezeichnung `b7-leer`, „Marke
anlegen", den Klartext kopieren.

```bash
# A3 · Punkt 2 — die Marke eines Kunden ohne Abonnement; liest nur
read -rs -p 'Marke von B7 Leer: ' T2; echo
printf 'Anfang %s… · %d Zeichen\n' "${T2:0:9}" "${#T2}"
api "$T2" /api/v1/me
api "$T2" /api/v1/subscriptions
api "$T2" "/api/v1/subscriptions/$EA"
api "$T2" "/api/v1/domains/$ED"
```

**Erwartet:**

```
Anfang srvp_<4 Zeichen>… · 53 Zeichen
200 · application/json · {"data":{"account_id":<id>,"name":"B7 Leer","type":"customer","subscriptions":0}}
403 · application/json · {     "message": "This action is unauthorized." }
404 · application/json · {     "message": "No query results for model [App\\Models\\Subscription] 140" }
404 · application/json · {     "message": "No query results for model [App\\Models\\Domain] 54" }
```

- **`/me` mit `subscriptions: 0` ist der Ladebeleg.** Ohne ihn wäre das 403
  nicht von einer abgewiesenen Marke zu trennen — die gäbe 401.
- **403 und nicht `200 []`** (§0, Punkt 1 an der Vorschrift): Eine Route ohne
  Klammer gäbe `200 []`, der leere Kunde bekommt 403. Die beiden Fälle sind
  unterscheidbar, und das ist der Punkt.
- **Das Abonnement von Konto 6 ist für ihn fremd**, 404 wie in A2.

### A4 — Ohne Marke, mit einer erfundenen, und die Beschreibung

```bash
# A4 · Ohne Marke, eine erfundene, und die Beschreibung ohne Marke — liest nur
api '' /api/v1/me
api 'srvp_000000000000000000000000000000000000000000000000' /api/v1/me
api '' /api/v1/openapi.yaml
```

**Erwartet:**

```
401 · application/json · {"message":"Unauthenticated."}
401 · application/json · {"message":"Unauthenticated."}
200 · application/yaml; charset=utf-8 · # Die Beschreibung von API v1 (B7, `docs/131 §5`). # # **Von Hand gepflegt, und gegen die Routen gehalten.** `OpenApiReachTest` # prüft beide Richtungen: Jede
```

Die dritte Zeile ist die Behebung von Befund 4 (§6a), im Container gemessen,
Zeichen für Zeichen. Unter `rc.16` stand dort
`404 · application/json · {     "message": "" }`: Die Beschreibung lag nicht im
Paket, und die Antwort hatte nicht einmal einen Text.

### A5 — Die Drosselung, und was sie kostet

**Kein Punkt des Kriteriums**, aber `docs/131 §8` hat beide Fragen für den
Server offen gelassen: was eine Anfrage an `api/v1` kostet, und was davon der
Zähler in der Datenbank ist.

```bash
# A5 · 61 Anfragen in ein leeres Fenster — liest; zählt im Cache der Drosselung
sleep 61
drossel "$T" 61
"${C[@]}" -D - -o /dev/null -H "Authorization: Bearer $T" "$U/api/v1/me" | grep -iE '^HTTP|^retry-after|^x-ratelimit'
```

**Erwartet:**

```
  200: 60 Anfragen, im Mittel <x> ms
  429: 1 Anfragen, im Mittel <y> ms
HTTP/<…> 429
x-ratelimit-limit: 60
x-ratelimit-remaining: 0
retry-after: <s>
```

- **Genau 60 und 1.** Steht dort weniger als 60, kam in derselben Minute eine
  andere Anfrage von dieser Adresse (§0, Punkt 6 an der Vorschrift), und der
  Block wird wiederholt.
- **Die Kopfzeilen** schreibt HTTP/2 klein und HTTP/1.1 gross
  (`X-RateLimit-Limit`); `grep -i` findet beide.
- **Die beiden Mittelwerte** schreibt das Protokoll ab: `<x>` ist eine ganze
  Anfrage samt Marke, `<y>` eine, die schon an der Drosselung endet. Im
  Container waren es 14 und 9 ms.

```bash
# A5b · Was der Zähler je Anfrage kostet — schreibt einen Schlüssel in den Cache und räumt ihn ab
srvpanel tinker --execute='
  $k = "b7-drossel-probe"; Illuminate\Support\Facades\RateLimiter::clear($k);
  $t = hrtime(true);
  for ($i = 0; $i < 100; $i++) { Illuminate\Support\Facades\RateLimiter::tooManyAttempts($k, 1000); Illuminate\Support\Facades\RateLimiter::hit($k, 60); }
  $ms = (hrtime(true) - $t) / 1e6 / 100;
  $n = Illuminate\Support\Facades\RateLimiter::attempts($k);
  Illuminate\Support\Facades\RateLimiter::clear($k);
  printf("Speicher %s · %d Versuche gezählt · je Anfrage %.3f ms · danach %d\n", config("cache.default"), $n, $ms, Illuminate\Support\Facades\RateLimiter::attempts($k));
'
```

**Erwartet:** `Speicher database · 100 Versuche gezählt · je Anfrage <z> ms ·
danach 0`. Je Anfrage fragt die Drosselung, ob die Grenze erreicht ist, und
zählt einen Versuch; die Schleife tut hundertmal dasselbe. `100 Versuche
gezählt` belegt, dass sie es getan hat, `danach 0`, dass der Schlüssel fort
ist. Im Container mit SQLite: 2,645 ms; derselbe Lauf mit dem Speicher im
Arbeitsspeicher 0,026 ms.

### A6 — Punkt 3: Die Marke steht in keinem Protokoll

**Darf nicht ausfallen.** Läuft nach A2 bis A5, damit jede Anfrage dieses
Laufs im Fenster liegt.

```bash
# A6 · Punkt 3 — die Marke in keinem Protokoll, der Abfrageteil schon; liest nur
PROBE="b7-gegenprobe-$(date +%s)"
api "$T" "/api/v1/me?b7=$PROBE"
spur "$PROBE" "$START"
spur "$T" "$START"
spur "$T2" "$START"
```

**Erwartet:**

```
200 · application/json · {"data":{"account_id":6,…
Gesucht: b7-gegenp… (24 Zeichen), seit <START>
  /var/log/srvpanel/panel-access.log:1
  /var/log/srvpanel/<jede andere Datei>:0
  …
  Journal seit <START>: 0
Gesucht: srvp_<4 Zeichen>… (53 Zeichen), seit <START>
  /var/log/srvpanel/panel-access.log:0
  … jede Datei 0
  Journal seit <START>: 0
Gesucht: srvp_<4 Zeichen>… (53 Zeichen), seit <START>
  … jede Datei 0
  Journal seit <START>: 0
```

**Erfüllt, wenn** beide Marken überall 0 ergeben **und** das Merkzeichen in
`panel-access.log` 1. Ohne die 1 wäre die 0 keine Messung: Sie sagte nur, dass
`spur` in diesen Dateien nichts findet.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

### A7 — „Zuletzt benutzt" und Entfernen

Im privaten Fenster als B7 Leer abmelden und als Konto 6 anmelden, „Mein
Konto": In der Zeile `b7-abnahme` steht unter „Zuletzt benutzt" eine Zeit von
heute und nicht mehr `nie`.

```bash
# A7a · Was die Ablage dazu sagt — liest nur
marken
```

**Erwartet:** die Zeile `b7-abnahme` mit derselben Zeit wie auf der Seite.
Geschrieben wird sie höchstens einmal je Minute
(`ApiToken::USAGE_RESOLUTION_SECONDS`); auf die Sekunde gleich mit der letzten
Anfrage muss sie deshalb nicht sein. Die Marke von B7 Leer steht ebenfalls da.

Dann in der Zeile `b7-abnahme` **„Entfernen"**. Oben steht danach die
Rückfrage (Befund 3, §6a): *Die Zugangsmarke „b7-abnahme“ entfernen?*, darunter
*Jedes Programm, das sie benutzt, bekommt danach 401.*, mit den Knöpfen
„Entfernen" in Rot und „Abbrechen". Zuerst **„Abbrechen"**:

```bash
# A7b · Nach „Abbrechen" — liest nur
api "$T" /api/v1/me
marken
```

**Erwartet:** für `/me` dieselbe Zeile wie in A2, und in der Ablage beide
Marken:

```
Zugangsmarken: 2
  Marke <n> · Konto 6 · b7-abnahme · srvp_<4 Zeichen>… · zuletzt benutzt <Zeit> · angelegt <Zeit>
  Marke <n> · Konto <id> · b7-leer · srvp_<4 Zeichen>… · zuletzt benutzt <Zeit> · angelegt <Zeit>
```

Die Frage ist zu, und auf der Seite steht keine Meldung. Dann noch einmal
**„Entfernen"** in der Zeile und in der Rückfrage **„Entfernen"**. Oben steht
„Die Zugangsmarke ist entfernt.", und die Zeile ist fort.

```bash
# A7c · Die entfernte Marke an der Tür — liest nur
api "$T" /api/v1/me
marken
```

**Erwartet:**

```
401 · application/json · {"message":"Unauthenticated."}
Zugangsmarken: 1
  Marke <n> · Konto <id> · b7-leer · srvp_<4 Zeichen>… · zuletzt benutzt <Zeit> · angelegt <Zeit>
```

Und im Hauptfenster, als Betreiber: „Mein Konto" hat **keinen** Bereich
„Zugangsmarken". Ein Adminkonto bekommt keine Marke (`docs/131 §9`), und für
ein Adminkonto steht dort `null` und keine leere Liste.

### A8 — Die fremde Sicht

Im Hauptfenster: Kunden, in der Zeile von **B7 Leer** „Anmelden als",
„Wechseln". Dann „Mein Konto" — **nur ansehen, nichts anklicken.**

**B7 Leer und nicht der Kunde von Konto 6:** Dessen Marke ist seit A7 fort,
und an einer leeren Liste wäre nicht zu sehen, ob sie in fremder Sicht
dasteht. Die Marke `b7-leer` gibt es bis A9.

**Erwartet** (Befund 2, §6a): die Bereiche „Darstellung", „Sicherheit" und
„Zugangsmarken"; „Konto" und „Passwort ändern" fehlen, und die drei Knöpfe
der Darstellung sind gesperrt. In „Zugangsmarken" steht die Liste mit der
Zeile `b7-leer` und ihrer Zeit unter „Zuletzt benutzt" — und kein Formular,
kein „Marke anlegen" und kein „Entfernen". Dazu in die Konsole:

```js
(() => { const s = [...document.querySelectorAll('section, .section')].find((x) => x.querySelector('h2')?.textContent.trim() === 'Zugangsmarken'); return s ? `Formulare ${s.querySelectorAll('form').length} · Spalten ${s.querySelectorAll('thead th').length} · Zeilen ${s.querySelectorAll('tbody tr').length} · Knöpfe [${[...s.querySelectorAll('button')].map((b) => b.innerText.trim()).join(', ')}]` : 'kein Bereich Zugangsmarken' })()
```

**Erwartet:** `Formulare 0 · Spalten 4 · Zeilen 1 · Knöpfe []`. Dieselbe Zeile
in der eigenen Sicht eines Kunden gibt im Container `Formulare 1 · Spalten 5 ·
Zeilen 1 · Knöpfe [Marke anlegen, Entfernen]`; unter `rc.16` stand das auch in
fremder Sicht da.

Danach oben im Band **„Zurück zur Verwaltung"**.

### A9 — Abräumen: der Wegwerfkunde

Im Hauptfenster: Kunden, B7 Leer, **„Zurückziehen"**, die Rückfrage
bestätigen. Kunden werden nicht gelöscht, sondern zurückgezogen; die Nummer
bleibt vergeben, und das sagt die Rückfrage auch. Danach:

```bash
# A9a · Die Marke eines zurückgezogenen Kunden — liest nur
api "$T2" /api/v1/me
marken
```

**Erwartet:**

```
401 · application/json · {"message":"Unauthenticated."}
Zugangsmarken: 1
  Marke <n> · Konto <id> · b7-leer · …
```

Die Marke gilt nicht mehr, und ihre Zeile steht noch da (Beobachtung in §0).
Sie geht ausdrücklich:

```bash
# A9b · Die Marke des Wegwerfkunden entfernen — schreibt: löscht genau diese eine Zeile in api_tokens
srvpanel tinker --execute='
  $n = App\Models\ApiToken::query()->whereHas("account", fn ($k) => $k->where("email", "b7-leer@b7.invalid"))->delete();
  printf("entfernt: %d\n", $n);
'
marken
unset T T2
```

**Erwartet:** `entfernt: 1` und `Zugangsmarken: 0` — dieselbe Zahl wie in
Block 0.

---

## §4 · Teil B — B8

Im Hauptfenster als Betreiber, am Schreibtisch, die Konsole der
Entwicklerwerkzeuge offen. Mit der Uhr der Probe wird gerechnet, nicht mit der
des Rechners.

### B0 — Die Probe

`/updates` öffnen und warten, bis die Pakettabelle steht. Dann
`tests/takt-messen.js` in die Konsole einfügen — **zweimal**.

**Erwartet:**

```
Taktprobe läuft (Stand 2026-10-11). Uhr auf 0. Zuerst die Gegenprobe: …
Die Taktprobe läuft schon (Stand 2026-10-11). Ein zweites Einsetzen hinge sich ein zweites Mal ein, und jede Anfrage stünde doppelt da.
```

Die erste Zeile belegt die Fassung; ohne `(Stand 2026-10-11)` ist es eine
ältere, und Teil B beginnt mit der richtigen neu. Die zweite belegt die
Sperre. Steht dort zweimal die erste Zeile, ist die Seite dazwischen neu
geladen worden.

### B1 — Punkt 1: aus der Liste heraus, und man bleibt dort

**Darf nicht ausfallen.** **„Jetzt nachsehen"**, „Nachsehen" — und sofort ein
zweites Mal. Zwei Vorgänge laufen dann nacheinander, rund 25 Sekunden; das ist
die Zeit, die B2 braucht.

**Erwartet** in der Konsole, mit den Zeiten der Probe:

```
Seite bei a — /updates/refresh
Nachladen bei a — packages,packagesError
Seite bei b — /updates/refresh
Nachladen bei b — packages,packagesError
Nachladen bei … — runningOperations
Nachladen bei … — runningOperations          (im Abstand von 3,0 s)
```

und auf der Seite:

- **Die Adresse bleibt `/updates`**, und die Pakettabelle steht darunter.
  `location.pathname` in der Konsole sagt es.
- **Oben stehen zwei Streifen**, `Vorgang <n> system.packages.refresh — wartet`
  oder `— läuft`, je mit „ansehen".

Eine Zeile `Seite` je Klick ist das Absenden selbst, und die `Nachladen`-Zeile
mit `packages` danach die Pakettabelle der Seite, auf die es zurückführt. Eine
Zeile `Seite bei … — /operations/<n>` stünde dort, wenn der Klick wegführte.

### B2 — Punkt 2: der Streifen überlebt den Seitenwechsel, der Takt läuft einmal

Solange beide Vorgänge laufen, über das Menü **„Kunden"**, dann
**„Abonnements"**. Danach dort bleiben.

**Erwartet:**

```
Seite bei c — /customers
Nachladen bei c + 3,0 — runningOperations
…
Seite bei d — /subscriptions
Nachladen bei d + 3,0 — runningOperations
…                                            (bis beide fertig sind)
```

- **Der Streifen steht auf jeder Seite**, mit denselben beiden Vorgängen.
- **Zwischen zwei `Nachladen` liegen nie weniger als rund drei Sekunden.** Ein
  zweiter Takt stünde als zweite Zeile dazwischen; nach zwei Wechseln wären es
  drei.
- **Die erste Zeile nach einem Wechsel kommt drei Sekunden nach ihm.** Der
  Streifen wird mit der Seite neu aufgebaut, und sein Takt beginnt neu.

Endet der zweite Vorgang, bevor beide Wechsel gemacht sind, wird B1 mit einem
weiteren Paar wiederholt.

### B3 — Punkt 3: nach dem Ende fragt niemand mehr nach

**Darf nicht ausfallen.** Auf „Abonnements" bleiben, bis beide Streifen
`— fertig` sagen und „Seite aktualisieren" tragen. Dann **15 Sekunden nichts
tun**, danach einmal **„Seite aktualisieren"**.

**Erwartet:**

```
Nachladen bei e — runningOperations          (die letzte; sie bringt „fertig")
                                             (15 s keine Zeile)
Seite bei f — /subscriptions                 (genau eine)
```

- **Nach der letzten `Nachladen`-Zeile kommt keine mehr**, solange niemand
  etwas tut — weder eine Teilladung noch eine ganze Seite.
- **„Seite aktualisieren" gibt genau eine Zeile `Seite`.** Sie ist die
  Gegenprobe: Ohne sie wäre die Stille von „die Probe sieht nichts mehr" nicht
  zu trennen.

Auf `/updates` gäbe derselbe Knopf zwei Zeilen, weil die Seite ihre
Pakettabelle nachreicht; deshalb wird B3 auf „Abonnements" gelesen.

```bash
# B3 · Die beiden Vorgänge in der Ablage — liest nur
. /root/b78-werkzeuge.sh
vorgaenge 4
```

**Erwartet:** die beiden `system.packages.refresh` mit dem Konto des Betreibers,
`succeeded`, 100 %, mit Zeiten für angelegt, begonnen und fertig. Die Spanne
von begonnen bis fertig ist die Laufzeit von heute, nach der `docs/132 §5`
fragt; `docs/92 §6` nannte am 31. August 12 Sekunden.

### B4 — Ein Strom nur auf der Vorgangsseite

Bis hierher steht **keine** Zeile `Strom` in der Konsole — durch `/updates`,
`/customers` und `/subscriptions`. Dann über das Menü zu „Updates", **„Jetzt
nachsehen"**, „Nachsehen", und im Streifen sofort **„ansehen"**, solange der
Vorgang läuft.

**Erwartet:**

```
Seite bei g — /updates
Nachladen bei g — packages,packagesError
Seite bei h — /updates/refresh
Nachladen bei h — packages,packagesError
Seite bei i — /operations/<n>
Strom bei i — /operations/<n>/stream         (genau eine)
```

Die Vorgangsseite öffnet ihren Strom nur, solange der Vorgang offen ist. Ist
er schon fertig, wenn die Seite steht, kommt keine Zeile `Strom`, und B4 wird
mit einem weiteren Klick wiederholt.

### B5 — Ein Vorgang ohne Konto erscheint bei niemandem

**Innerhalb von zwei Minuten nach dem Ende des Vorgangs aus B4**, sonst ist der
eigene Streifen schon fort, und die Abwesenheit hätte nichts neben sich:

```bash
# B5 · Vorgänge ohne Konto — schreibt den Server-Block des Panels und die Rotationsdateien neu, wie jedes Update
srvpanel vhost
sleep 5
vorgaenge 6
```

**Erwartet:** `srvpanel vhost` meldet den Server-Block und `<n> Rotationsdateien
der Abonnements eingereiht.`; `vorgaenge` zeigt unter dem Vorgang aus B4 je
Abonnement einen `web.logrotate.apply` mit `Konto —`, fertig oder kurz davor.

Danach im Browser über das Menü zu „Abonnements". **Der Streifen zeigt den
eigenen Vorgang aus B4 und keinen `web.logrotate.apply`.**

> **Eine Abwesenheit belegt eine Grenze erst, wenn daneben etwas anwesend ist,
> das dieselbe Hülle braucht.**

### B6 — Nach zwei Minuten ist der Streifen fort

Zwei Minuten nach dem Ende des Vorgangs aus B4 — die Zeit steht in `vorgaenge`
unter „fertig" — über das Menü auf eine Seite wechseln.

**Erwartet:** kein Streifen mehr (`RunningBand::FRESH_SECONDS`, 120). Der
Klient fragt dafür nicht nach; der Streifen fällt beim nächsten Seitenaufbau
weg.

### B7 — Der Kunde (darf ausfallen)

Im privaten Fenster als Konto 6: Abonnement `p6-abnahme.invalid`,
„Sicherungen", die Probe einfügen, **„Jetzt sichern"**.

**Erwartet:** Die Adresse bleibt `/subscriptions/140/backups`, oben steht
`Vorgang <n> backup.create — …`, und die Probe zeigt **zwei** Takte im Wechsel:

```
Nachladen bei … — backups
Nachladen bei … — runningOperations
```

Die Liste der Sicherungen fragt ihren eigenen Zustand nach (`Backups.vue`,
seit P8), der Streifen seinen. Zwei Eigenschaften, zwei Takte — und keiner
davon doppelt. Nach dem Ende hören beide auf.

### B8 — Der Streifen bei 390 px (darf ausfallen)

In den Entwicklerwerkzeugen die Gerätesicht auf 390 × 844 stellen,
„Jetzt nachsehen", und während der Streifen steht, „Abonnements" **neu
laden**. Dann `tests/bilder-messen.js` einfügen, `bilderMessen()` aufrufen, und
dazu:

```js
[...document.querySelectorAll('.bands .band')].map((b) => { const v = b.querySelector('.link'); return Math.round(b.getBoundingClientRect().height) + ' px · ansehen ' + (v ? Math.round(v.getBoundingClientRect().height / parseFloat(getComputedStyle(v).lineHeight)) : 0) + ' Zeile(n)' }).join('\n')
```

**Erwartet** (Befund 1, §6a): `dokument = 0`, Gegenprobe 200/200, und bei
jedem Vorgang „ansehen" in **einer** Zeile. Die Handgriffe stehen rechts unter
dem Text, bei einem fertigen Vorgang „Seite aktualisieren" und „ansehen"
nebeneinander. Im Container ist ein fertiger Vorgang so 122 px hoch und ein
wartender 99 px; die Höhe hängt an der Schrift des Rechners (CLAUDE.md, „Diese
Umgebung"), die eine Zeile nicht. Und ein fertiger Vorgang steht auf grünem
Grund (Befund 5, §6a).

Unter `rc.16` stand „ansehen" bei einem fertigen Vorgang im Container in sechs
Zeilen bei 146 px, und der fertige Vorgang hatte keinen Grund.

---

## §5 · Was dieser Lauf ausdrücklich nicht prüft

- **Die Marke im Abfrageteil.** Sie schriebe eine gültige Marke ins Protokoll
  (§0, Punkt 3 an der Vorschrift). Dass die Wache dort nicht liest, hält
  `ApiTokenTransportTest` an der Wirkung; im Container gab dieselbe Marke im
  Abfrageteil 401.
- **Eine Marke an einem Adminkonto.** Die Oberfläche legt keine an. Dass die
  Wache eine bestehende abweist, hält `AuthenticateToken` und sein Wächter; im
  Container gab sie 401.
- **Zusatzbenutzer.** Kein Formular legt einen an (`docs/141 §4`).
- **Eine Route ohne Klammer.** Es gibt keine, und herstellen lässt sie sich
  nur durch einen Eingriff am Prüfling. `ApiEmptyListTest` und
  `MiddlewareOrderTest` halten beide Richtungen.
- **Was die Beschreibung sagt.** `OpenApiReachTest` hält sie gegen die Routen.
- **Last auf dem Pool.** Ein offener Reiter fragt alle drei Sekunden einmal
  nach; viele Reiter zugleich stellt dieser Lauf nicht her (`docs/132 §5`).
  Was eine Nachfrage kostet, steht in B1 bis B3 nicht als Zahl da.

---

## §6 · Wann er durch ist

- **A2 und A6 tragen das Kriterium von B7** und dürfen nicht ausfallen.
- **B1 und B3 tragen das Kriterium von B8** und dürfen nicht ausfallen.
- **A3 und B2 sind die beiden dazugelegten Punkte**; sie werden gefahren.
- **A5, A5b, B7 und B8 dürfen ausfallen.** Sie beantworten Fragen aus
  `docs/131 §8` und `docs/132 §5` oder zeigen die Behebungen der Befunde 1
  und 5 auf dem Server.
- **Block 0, A4, A7 und A8 zeigen die Behebungen der Befunde 2 bis 4** (§6a).
  Gemessen sind sie im Container; auf dem Server hat sie noch niemand
  gesehen.
- **A9 wird gefahren**, auch wenn ein Punkt davor ausfällt. Ein Kunde ohne
  Abonnement mit einer Marke ist ein Rest und kein Prüfkörper.

---

## §6a · Behoben am 11. Oktober 2026

Der Betreiber hat die vier Fragen aus §0 am selben Tag entschieden, alle wie
vorgeschlagen: **1a, 2a, 3a und 4a**. Gebaut ist alles für `0.9.0-rc.17`, und
beim Bauen fiel ein fünfter Befund heraus. Gemessen ist jede Behebung im
Container, vorher und nachher, gegen eine eigene SQLite-Datenbank unter
`artisan serve` und im echten Chromium. Auf dem Server gesehen hat sie noch
niemand; das tun Block 0, A4, A7, A8 und B8.

### Befund 1 — der Streifen bei 390 px

Gebaut sind die drei Zeilen aus dem Vorschlag und eine vierte:

```css
.band { flex-wrap: wrap; }
.band > .button, .band > .link { flex: none; }
.band > span + .button, .band > span + .link { margin-left: auto; }
```

**Die vierte nimmt den Rest aus dem Vorschlag mit.** Dort trug nur der Knopf
den Abstand nach links, und beim wartenden Vorgang, der keinen Knopf hat,
rutschte „ansehen" bei 390 px links unter den Text. Jetzt trägt ihn der erste
Handgriff nach dem Text, gleich welcher es ist.

**Und das Umbrechen steht jetzt in `app.css` und nicht mehr im Layout.**
`PanelLayout` hatte `.band { flex-wrap: wrap; }` unter 720 px in seinem
`<style scoped>`, und das erreichte nur die Bänder aus seiner eigenen Vorlage:
die fremde Sicht und die Wartung. Die Ankündigungen kommen aus `Bands.vue`,
die Vorgänge aus `OperationBand.vue`, beide mit mehreren Wurzeln, und eine
Kindkomponente mit mehreren Wurzeln trägt das Merkmal der gescopten Regel
nicht. Gemessen als Kunde auf `/subscriptions`, mit zwei Ankündigungen und
einem wartenden Vorgang:

```
.bands (PanelLayout): data-v-e3b6d303
  a.band.critical: —
  a.band.info: —
  div.band.info: —
```

> **Eine gescopte Regel erreicht die Wurzel einer Kindkomponente nur, wenn sie
> eine hat — bei mehreren Wurzeln erreicht sie keine.**

`ScopedReachTest` war mit der Regel zufrieden, und zu Recht: Sie traf ein
Element ihrer eigenen Vorlage, das Band der fremden Sicht. Dass sie die
Vorgänge verfehlte, kann er nicht sehen.

Gemessen mit `tests/bilder-messen.js` und je Band mit Höhe, Lage der Kinder
und Kontrast. Als Betreiber mit drei Vorgängen — einem wartenden, einem
fertigen und einem fehlgeschlagenen —, zwei Ankündigungen und der Wartung,
dazu als Betreiber in fremder Sicht; jedes in hell und dunkel:

| Lage | vorher | nachher |
|---|---|---|
| 390 px, wartender Vorgang | 83 px, „ansehen" in 2 Zeilen | 99 px, 1 Zeile, rechts unter dem Text |
| 390 px, fertiger Vorgang | 146 px, „ansehen" in 6 Zeilen | 122 px, 1 Zeile |
| 390 px, fehlgeschlagener Vorgang | 167 px, „ansehen" in 7 Zeilen | 122 px, 1 Zeile |
| 390 px, fremde Sicht | 143 px, „Zurück zur Verwaltung" links unter dem Text | 143 px, rechts unter dem Text |
| 1440 px, fertiger und fehlgeschlagener Vorgang | 48 px, „Seite aktualisieren" bei x = 751 und 786 | 48 px, bei x = 1139, neben „ansehen" |
| Ankündigungen und Wartung | 104, 62 und 62 px bei 390, je 41 bei 1440 | unverändert |

In allen sechzehn Lagen, vorher wie nachher, `dokument = 0`, Gegenprobe
200/200 und `schiebt = 0`. Die Zahl hat den Befund nie gesehen; die Höhe und
die Zeilen sehen ihn.

`BandHandleTest` hält vier Dinge: dass `.band` umbrechen darf, dass jeder
Handgriff eines Bandes `flex: none` hat, dass der erste nach dem Text nach
rechts geht, und dass keine Vorlage das Umbrechen in ihrem eigenen Stilblock
ein zweites Mal hat. Die Handgriffe liest er aus den Vorlagen und nicht aus
einer Liste; ein neues Band mit einem neuen Handgriff macht ihn rot, bevor
jemand es bei 390 px gesehen hat.

### Befund 5 — ein fertiger Vorgang hatte keinen Rang

Herausgefallen beim Messen von Befund 1, an den Zeilen, die je Band Grund und
Kontrast drucken: Das Band eines fertigen Vorgangs stand auf
`rgba(0, 0, 0, 0)`, in der Schriftfarbe der Seite. `OperationBand.vue` setzt
für einen gelungenen Vorgang `ok`, und `app.css` kannte `.band.info`,
`.band.warn` und `.band.critical`, aber kein `.band.ok`. So stand es seit B8
da, seit `0.9.0-rc.1`.

**Auf den Bildern der Vorabmessung war es zu sehen** (§7): ein Band ohne
Fläche neben zwei getönten. Angesehen waren sie auf den Umbruch hin.

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.**

`ClassReachTest` konnte es nicht sehen, weil die Klasse als Ausdruck kommt
(`:class="rang(vorgang.status)"`). Und `RankReachTest` kannte `rang()` nicht
als Erzeuger. Am 9. September hatte seine Gegenrichtung `.band.ok` als tot
gemeldet, weil damals niemand die Klasse erzeugte, und die Regel ist gegangen
(Commit `6cf5c2a7`). B8 kam am 21. September; die Regel kam nicht zurück, und
hätte sie jemand geschrieben, hätte derselbe Fall sie abgewiesen.

> **Ein Wächter, der die Erzeuger einer Regel aufzählt, weist die Regel ab,
> die ein neuer Erzeuger braucht.**

Gebaut ist `.band.ok` wie die anderen drei Ränge, mit Schrift, Grund und Rand
aus `--ok` und `--ok-surface` — Zeile für Zeile die Regel, die am 9. September
ging. Gemessen gegen den getönten Grund:

| | hell | dunkel |
|---|---|---|
| Text | 5,38:1 | 7,24:1 |
| „ansehen" | 8,57:1 | 6,71:1 |

`RankReachTest` liest seitdem die Rückgabewerte von `rang()` als dritten
Erzeuger und verlangt für jeden eine Regel `.band.<Rang>`; die Gegenrichtung
kennt ihn ebenfalls.

### Befund 2 — die Zugangsmarken in fremder Sicht

Gebaut wie vorgeschlagen: In fremder Sicht steht die Liste, Formular und
„Entfernen" nicht. Die Spalte des Knopfes fällt mit, Kopf und Zelle. Der Satz
in `ProfileController::refuseWhileImpersonating()` ist berichtigt und nennt
den Befund. Gemessen als Betreiber in der Sicht von Kunde Eins, mit der
Konsolenzeile aus A8 und mit derselben Zeile als Kunde selbst:

```
fremde Sicht · Bereiche: Darstellung, Sicherheit, Zugangsmarken
fremde Sicht · Zeile:    Formulare 0 · Spalten 4 · Zeilen 1 · Knöpfe []
fremde Sicht · Darstellung: System (gesperrt), Hell (gesperrt), Dunkel (gesperrt)
eigene Sicht · Bereiche: Konto, Passwort ändern, Darstellung, Sicherheit, Zugangsmarken
eigene Sicht · Zeile:    Formulare 1 · Spalten 5 · Zeilen 1 · Knöpfe [Marke anlegen, Entfernen]
```

Bei 390 und 1440 px dazu `dokument = 0` und Gegenprobe 200/200, und das
Protokoll bekam in fremder Sicht keine Zeile.

`ImpersonationControlTest` hält es für jede Handlung, die ein Controller in
fremder Sicht abweist. Die Handlungen liest er aus den Aufrufen von
`refuseWhileImpersonating()`, ihre Pfade aus `routes/web.php`; auf der Seite
desselben Controllers sucht er jedes Bedienelement, das diese Anfrage schickt,
und verlangt, dass es in fremder Sicht fehlt oder gesperrt ist. Heute sind es
fünf Handlungen: Marke anlegen und entfernen, Konto, Darstellung und Passwort.
Die Darstellung bleibt sichtbar und gesperrt, wie bisher; das ist eine
Entscheidung, und der Wächter lässt beides zu.

> **Eine Begründung, die ein Formular für abwesend erklärt, gilt für die
> Formulare, die es gab, als sie geschrieben wurde.** Ein Wächter, der sie aus
> dem Controller liest, gilt für jede spätere.

### Befund 3 — Entfernen mit Rückfrage

Gebaut wie vorgeschlagen, über `ask()` wie beim SSH-Schlüssel. Gemessen als
Kunde Eins bei 390 px an einer Marke `probe-entfernen`:

```
Rückfrage: Die Zugangsmarke „probe-entfernen“ entfernen? Jedes Programm, das sie benutzt, bekommt danach 401.
Knöpfe: Entfernen [button danger] · Abbrechen [button]
bei 390 px mit offener Frage: dokument=0 · gegenprobe=200/200 · schiebt=0
nach „Abbrechen": 0 Fragen offen · Marken: vorab-k1, probe-entfernen
nach „Entfernen": Die Zugangsmarke ist entfernt. · Marken: vorab-k1
Protokoll seitdem: api.token.removed · success · Konto 2
```

Der Knopf war der einzige `<Link method="delete">` dieses Panels, und
`DangerRankTest` sah ihn nicht: Er liest `<button>`-Tags, und der Verweis trug
`as="button"` und die Klassen eines roten Knopfes, ohne einer zu sein.

> **Ein Wächter, der den Knopf an seinem Tag erkennt, sieht keinen, der nur so
> aussieht.**

Ein neuer Fall dort verlangt, dass kein `<Link>` eine Anfrage ausser GET
schickt. Eine Handlung geht über einen Knopf, und der fragt, wenn sie etwas
kostet.

### Befund 4 — die Beschreibung im Paket

Gebaut wie vorgeschlagen: `docs/openapi-v1.yaml` steht in der Positivliste
von `packaging/build.sh`, als Datei und nicht als Verzeichnis. Unter `docs`
liegen die Dokumente für Entwickler, und das Panel liest genau eine Datei
davon. Gemessen ist die Schleife der Positivliste, alt und neu, gegen ein
eigenes Ziel im Scratchpad und ohne `vendor`:

```
alt · 13 Teile · Dateien unter docs/: 0 · docs/openapi-v1.yaml: fehlt
neu · 14 Teile · Dateien unter docs/: 1 · docs/openapi-v1.yaml: da · 6885 B · sha256 ac0f75d7b663983c
im Repo:                                 6885 B · sha256 ac0f75d7b663983c
```

`nfpm` packt den Baum als Ganzes nach `/opt/srvpanel/releases/<Fassung>`, und
`base_path()` zeigt auf dem Server dorthin. Block 0 sieht nach.

`ShippedFileTest` hält es als Regel und nicht als Eintrag: Jeder Pfad, den
`app/` über `base_path()` und seine Geschwister liest, gibt es im Repo, und er
liegt unter einem Eintrag der Positivliste. Was sich nicht auflösen lässt, ist
ein Befund und kein Übergehen. Heute liest `app/` genau eine solche Datei.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.** Am 15. August war es
> `lang`, behoben mit einem Eintrag und einem Wächter über genau dieses
> Verzeichnis.

### Die Brüche

Zwölf Eingriffe im Bruchskript, jeder einzeln rot und zurück grün belegt: vier
an `BandHandleTest`, zwei an `RankReachTest`, drei an
`ImpersonationControlTest`, einer an `DangerRankTest` und zwei an
`ShippedFileTest`.

---

## §7 · Vorab im Container gemessen

Am 11. Oktober 2026 gegen `main` bei `a270182c`, also gegen den Stand von
`0.9.0-rc.16`: eine frisch migrierte SQLite-Datenbank im Scratchpad mit einem
Betreiber mit zweitem Faktor, drei Kunden, drei Abonnements und zwei Domains,
`artisan serve` darauf, Chromium 141 über Playwright. Die Marken sind über
`ApiToken::mint()` angelegt und im Browser über das echte Formular.

### B7

Jede Route durch die echte Wache (`curl`, Marke im Kopf):

```
me · Kunde 1                      200  {"data":{"account_id":2,"name":"Kunde Eins","type":"customer","subscriptions":2}}
Liste · Kunde 1                   200  {"data":[{"id":1,"name":"eins.test",…},{"id":2,…
eigenes Abo 1                     200  {"data":{"id":1,"name":"eins.test",…
fremdes Abo 3                     404  {     "message": "No query results for model [App\\Models\\Subscription] 3" }
Abo 99999 gibt es nicht           404  {     "message": "No query results for model [App\\Models\\Subscription] 99999" }
Domains eigenes Abo 1             200  {"data":[{"id":1,"subscription_id":1,"name":"eins.test",…
Domains fremdes Abo 3             404  {     "message": "No query results for model [App\\Models\\Subscription] 3" }
eigene Domain 1                   200  {"data":{"id":1,"subscription_id":1,"name":"eins.test",…
fremde Domain 2                   404  {     "message": "No query results for model [App\\Models\\Domain] 2" }
Domain 99999                      404  {     "message": "No query results for model [App\\Models\\Domain] 99999" }
Kennzahlen Abo 1                  200  {"retention_days":30,"data":[{"day":"2026-10-09","metric":"errors","value":2},…
me · Kunde 3 ohne Abo             200  {"data":{"account_id":4,"name":"Kunde Drei","type":"customer","subscriptions":0}}
Liste · Kunde 3 ohne Abo          403  {     "message": "This action is unauthorized." }
ohne Marke                        401  {"message":"Unauthenticated."}
falsche Marke                     401  {"message":"Unauthenticated."}
Marke im Abfrageteil              401  {"message":"Unauthenticated."}
Marke eines Betreibers            401  {"message":"Unauthenticated."}
Beschreibung ohne Marke           200  application/yaml; charset=utf-8 · # Die Beschreibung von API v1 …
```

Im Browser, als Kunde: Anlegen, einmal benutzen, entfernen.

```
A1 · neue Marke: srvp_1331… 53 Zeichen
A2 · Liste vor dem Benutzen: ["b7-abnahme srvp_1331… nie 2026-10-11 05:54:39 Entfernen", …]
A3 · /me mit der Marke: 200
A4 · Liste nach dem Benutzen: ["b7-abnahme srvp_1331… 2026-10-11 05:54:39 2026-10-11 05:54:39 Entfernen", …] · Klartext noch da: false
A5 · Liste nach dem Entfernen: [ohne b7-abnahme] · /me danach: 401
B1 · Betreiber, Bereich „Zugangsmarken": false
```

Die fremde Sicht (Befund 2), als Betreiber nach „Anmelden als":

```
F1 · Bereiche auf „Mein Konto" in fremder Sicht: ["Darstellung","Sicherheit","Zugangsmarken"]
F2 · Knopf „Marke anlegen" sichtbar: true · Knöpfe „Entfernen": 1
F3 · Antworten: ["POST /settings/tokens → 403"]
F5 · Neue Protokollzeilen: … impersonation.start · success · Konto 1 …
                           … api.token.created · denied · Konto 1 · {"reason":"während „Anmelden als""}
F6 · Marken in der Ablage: unverändert, keine neue
```

Der erste Anlauf dazu hat nichts gemessen: `text=Wechseln` ist in Playwright
eine Teilsuche ohne Gross- und Kleinschreibung und traf die Frage „… wechseln?"
statt des Knopfes. Der Wechsel fand nicht statt, und die Seite zeigte die
Bereiche des Betreibers — erkannt an „Konto" und „Passwort ändern", die in
fremder Sicht fehlen.

Die Drosselung, das Ende eines Kunden und die Beschreibung ohne Datei:

```
61 Anfragen, leeres Fenster:        200: 60 Anfragen, im Mittel 14 ms · 429: 1 Anfragen, im Mittel 9 ms
dieselben kurz nach neun anderen:   200: 51 Anfragen · 429: 10 Anfragen
die 62.:                            429 · X-RateLimit-Limit: 60 · X-RateLimit-Remaining: 0 · Retry-After: 59
der Zähler je Anfrage:              database 2,645 ms · array 0,026 ms
zurückgezogener Kunde:              vorher 200 · nachher 401, Konto und Marke noch da · zurückgeholt 200
/api/v1/openapi.yaml ohne Datei:    404 · application/json · {"message": ""}
```

Die Beschreibung ist dafür mit `cp` gesichert, weggenommen und zurückgeholt
worden; `git diff --quiet` danach war still.

### B8

Mit der Taktprobe vom 11. Oktober, zwei Auffrischungen aus `/updates`, zwei
Seitenwechseln, der Vorgangsseite und dem Ende. Ohne Warteschlange bleibt ein
Vorgang hier auf `wartet`; das Ende ist von Hand in der Ablage gesetzt.

```
Taktprobe läuft (Stand 2026-10-11). …
Die Taktprobe läuft schon (Stand 2026-10-11). …
Seite bei 0.4s — /updates/refresh
Nachladen bei 0.5s — packages,packagesError
Seite bei 2.0s — /updates/refresh
Nachladen bei 2.0s — packages,packagesError
Nachladen bei 3.5s — runningOperations
Nachladen bei 6.5s — runningOperations
Seite bei 7.7s — /subscriptions
Nachladen bei 10.7s — runningOperations
Nachladen bei 13.7s — runningOperations
Seite bei 14.7s — /customers
Nachladen bei 17.8s — runningOperations
Nachladen bei 20.8s — runningOperations
Seite bei 21.8s — /operations/5
Strom bei 21.8s — /operations/5/stream
Seite bei 24.4s — /updates
Nachladen bei 24.4s — packages,packagesError
Nachladen bei 27.4s — runningOperations
Nachladen bei 30.4s — runningOperations
                                         (Ende gesetzt; 15 s keine Zeile)
Seite bei 47.6s — /updates               (Seite aktualisieren)
Nachladen bei 47.7s — packages,packagesError
```

Dazu: Adresse nach dem Klick `/updates`; Streifen auf `/customers` mit beiden
Vorgängen; vor „ansehen" null Zeilen `Strom`, danach eine; nach dem Ende
`— fertig Seite aktualisieren ansehen`; ein `web.logrotate.apply` mit
`Konto —` erschien im Streifen nicht; 121 Sekunden nach dem Ende war der
Streifen fort. Der Strom zur Vorgangsseite ist dabei im Prüfstand abgebrochen
worden — `artisan serve` bedient eine Anfrage zugleich, und ein offener Strom
hielte jede weitere an. Das Öffnen sieht die Probe trotzdem.

`performance.getEntriesByType('resource')` an einem eigenen kleinen
SSE-Server (§0, Punkt 5 an der Vorschrift):

```
offen · geöffnet: 1 · Einträge mit /stream: 0
vom Klienten geschlossen · Einträge mit /stream: 1
zweiter offen · Einträge mit /stream: 1
vom Server beendet · Einträge mit /stream: 2
```

Der Streifen in vier Lagen mit `tests/bilder-messen.js`, ein wartender und
zwei fertige Vorgänge, Höhen der drei Bänder:

```
390 hell     dokument=0 · gegenprobe=200/200 · schiebt=0 · 83/146/146 px
390 dunkel   dokument=0 · gegenprobe=200/200 · schiebt=0 · 83/146/146 px
1440 hell    dokument=0 · gegenprobe=200/200 · schiebt=0 · 41/48/48 px
1440 dunkel  dokument=0 · gegenprobe=200/200 · schiebt=0 · 41/48/48 px
```

Und mit dem Vorschlag aus Befund 1 als eingespielter Regel:

```
gebaut     390   83 px „ansehen" 2 Zeilen | 146 px 6 Zeilen | 146 px 6 Zeilen
Vorschlag  390   99 px „ansehen" 1 Zeile  | 122 px 1 Zeile  | 122 px 1 Zeile
gebaut     1440  41 / 48 / 48 px, je 1 Zeile
Vorschlag  1440  41 / 48 / 48 px, je 1 Zeile
```

`dokument = 0` und Gegenprobe 200/200 auch dort, in allen Lagen. Die Texte
stehen hier in DejaVu Sans; auf dem Rechner des Betreibers in `system-ui`, und
wo ein Ergebnis an der Breite eines Textes hängt, gilt es für diese Schrift.

### Die Werkzeuge

Gegen `artisan serve`, mit einem `srvpanel`, das `php artisan tinker` gegen die
eigene Datenbank ruft, und mit den Pfaden des Servers auf Verzeichnisse im
Scratchpad umgebogen: `api`, `liste`, `marken`, `vorgaenge`, `spur` und
`drossel` druckten jede Zeile wie oben erwartet, `spur` das Merkzeichen mit 1
und die Marke mit 0. Block 0 lief gegen dieselbe Datenbank mit Kunde 1 statt
Konto 6 und schrieb `EA=1 FA=3 ED=1 FD=2 NA=999999`. Die Prüfsumme von Block H
ist über die Datei gerechnet, die der Block schreibt: 67 Zeilen,
`7090a2e6deb3ead4`.
