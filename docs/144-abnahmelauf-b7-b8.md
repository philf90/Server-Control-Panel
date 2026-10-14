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
(§6a). Die Kriterien berühren sie nicht. Am selben Vormittag kamen zwei
Wünsche des Betreibers dazu: **Jedes Band hat ein x, und die Zugangsmarken
heissen API-Tokens** (§6b). **Gefahren wird gegen die Freigabe, die all das
trägt**; Block 0, A4, A7, A8, B8 und B9 zeigen es.

**Der Lauf braucht einen Nachmittag.** Teil A (B7) läuft auf dem Server und in
einem privaten Fenster, Teil B (B8) im Browser des Betreibers am Schreibtisch,
mit offener Konsole. Zwischen beiden muss keine Zeit vergehen.

**Gefahren ist er vom 11. bis 14. Oktober 2026 auf `cloudsrv24` gegen
`0.9.0-rc.17`.** Die tragenden Punkte A2, A6, B1 und B3 sind erfüllt, die
dazugelegten A3 und B2 auch. Von B4 ist die zweite Hälfte auf dem Server
nicht gemessen, entschieden vom Betreiber. Am Prüfling ist ein Befund
herausgefallen, ausserhalb der Kriterien: Der zweite Faktor lässt sich in
fremder Sicht einschalten. An der Vorschrift sechs. Das Protokoll ist §8, und
wo die Vorschrift danach berichtigt ist, steht „Berichtigt nach dem Lauf".

**Die Behebung des zweiten Faktors ist am 14. Oktober gebaut**, für
`0.9.0-rc.18` und wie entschieden (Variante a). Die Tür weist in fremder Sicht
ab, „verwalten" fällt dort weg, und ein Wächter fragt von der Seite der Tür.
Das steht in §9, mit dem Nachlauf, der es auf dem Server zeigt.

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

### Block 0 — Fassung, Kennungen, API-Tokens

Liest nur und schreibt zwei Dateien unter `/root`: die Ausgabe und die fünf
Kennungen für Teil A. `srvpanel tinker` läuft nach `setpriv` nicht als root und
kann dort nicht schreiben; die Kennungen gehen deshalb über die Ausgabe in die
Datei.

```bash
# 0 · Fassung, Kennungen für Konto 6, API-Tokens, Drosselung — liest nur; schreibt /root/b78-block0.txt und /root/b78-kennungen.sh
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
  printf("API-Tokens: %d%s\n", $m->count(), $m->isEmpty() ? "" : " — ".$m->map(fn ($t) => "Konto ".$t->account_id." ".$t->name)->implode(", "));
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
API-Tokens: 0
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
- **`API-Tokens: 0`.** Steht dort eins, wird es abgeschrieben; der Lauf
  lässt es stehen.
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
Leitung des Servers mit dem Panel, so wie ein Programm von aussen; `tokens`,
`vorgaenge` und `spur` lesen. Geschrieben wird von keiner — jede Anfrage
berührt nur den Zähler der Drosselung und `last_used_at` des API-Tokens.

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

# Eine Anfrage an api/v1: Status, Art und der Anfang der Antwort. Das Token kommt
# aus einer Variablen und steht damit weder im Befehl noch in der Geschichte.
#   api "$T" /api/v1/me      — mit Token
#   api ''   /api/v1/me      — ohne Token
api() {
  local token="$1" pfad="$2" kopf=()
  [ -n "$token" ] && kopf=(-H "Authorization: Bearer $token")
  "${C[@]}" "${kopf[@]}" -o /tmp/b78-antwort -w '%{http_code} · %{content_type} · ' "$U$pfad"
  head -c 240 /tmp/b78-antwort | tr '\n' ' '; echo
  rm -f /tmp/b78-antwort
}

# Eine Liste aus api/v1: der Status, dann je Eintrag Kennung und Name
liste() {
  local token="$1" pfad="$2"
  "${C[@]}" -H "Authorization: Bearer $token" -o /tmp/b78-antwort -w 'Status %{http_code}\n' "$U$pfad"
  jq -r '.data[]? | "  \(.id) · \(.name)"' /tmp/b78-antwort
  rm -f /tmp/b78-antwort
}

# n Anfragen hintereinander an /api/v1/me: je Status die Zahl und was eine im Mittel kostet
drossel() {
  local token="$1" n="${2:-61}" i
  for i in $(seq 1 "$n"); do
    "${C[@]}" -H "Authorization: Bearer $token" -o /dev/null -w '%{http_code} %{time_total}\n' "$U/api/v1/me"
  done > /tmp/b78-drossel
  awk '{n[$1]++; t[$1]+=$2} END {for (s in n) printf "  %s: %d Anfragen, im Mittel %.0f ms\n", s, n[s], 1000*t[s]/n[s]}' /tmp/b78-drossel | sort
  rm -f /tmp/b78-drossel
}

# Die API-Tokens in der Ablage: wem, wie sie heissen, ihr Anfang — misst nichts
tokens() {
  srvpanel tinker --execute='
    $m = App\Models\ApiToken::query()->orderBy("id")->get();
    printf("API-Tokens: %d\n", $m->count());
    foreach ($m as $t) printf("  Token %d · Konto %d · %s · %s… · zuletzt benutzt %s · angelegt %s\n", $t->id, $t->account_id, $t->name, $t->preview, App\Support\Time\Clock::display($t->last_used_at) ?? "nie", App\Support\Time\Clock::display($t->created_at));
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
printf 'Werkzeuge: %s von 6 · %s Zeilen · %s\n' "$(declare -F | awk '{print $3}' | grep -cxE 'api|liste|drossel|tokens|vorgaenge|spur')" "$(wc -l < /root/b78-werkzeuge.sh)" "$(sha256sum /root/b78-werkzeuge.sh | cut -c1-16)"
printf 'Panel: %s · EA=%s FA=%s ED=%s FD=%s NA=%s\n' "$U" "$EA" "$FA" "$ED" "$FD" "$NA"
```

**Erwartet:**

```
Werkzeuge: 6 von 6 · 67 Zeilen · 8b56817a615927a6
Panel: https://<Rechnername>:<Port> · EA=140 FA=<…> ED=54 FD=<…> NA=999999
```

Die Prüfsumme ist im Container über die Datei gerechnet, die dieser Block
schreibt (§7). Steht dort eine andere, ist beim Einfügen etwas verloren
gegangen, und der Block wird wiederholt.

> **Was eine Datei enthält, sagt ihre Prüfsumme und nicht das Echo, mit dem sie
> entstand.**

- **Die API-Tokens stehen nur in der Sitzung**, in der sie eingelesen wurden,
  und ihr Klartext nur einmal auf der Seite. Geht die Sitzung verloren,
  entfernt das Konto das Token und legt ein neues an.
- **`spur` druckt den Wert nicht**, nur seinen Anfang und seine Länge — auch
  das Protokoll dieses Laufs bekommt kein Token.

### Die Taktprobe für Teil B

`tests/takt-messen.js`, **Stand 2026-10-11**, aus dem Repository: vor dem Merge
von diesem Zweig, danach von `main`. Eine ältere Fassung meldet weder ganze
Seiten noch Ströme, und mit ihr ist B3 nicht zu lesen. Die Probe lebt bis zum
nächsten vollen Neuladen der Seite; Navigationen über das Menü überlebt sie,
denn Inertia lädt dabei das Dokument nicht neu.

---

## §2 · Prüfkörper und Reihenfolge

- **Das API-Token von Konto 6**, `b7-abnahme`, angelegt in A1 und entfernt in A7.
- **Ein Wegwerfkunde „B7 Leer"** ohne Abonnement, angelegt in A3, in A8 der
  Kunde, in dessen Sicht der Betreiber wechselt, und zurückgezogen in A9.
  Vertrags- und Anmeldeadresse `b7-leer@b7.invalid`, reserviert nach
  RFC 2606; eine Mail ginge an niemanden, und das Anlegen verschickt keine
  (gelesen am `CustomerController`). Zurück bleibt sein Grabstein mit einer
  verbrauchten Kundennummer, wie bei jedem zurückgezogenen Kunden.
- **Drei Paketauffrischungen** aus `/updates` in Teil B, mit B8 und B9 fünf.
  Und **eine Sicherung** von `p6-abnahme.invalid`, wenn B7 gefahren wird; die
  Aufbewahrung nimmt sie in der Nacht mit wie jede andere. *Berichtigt nach
  dem Lauf (§8, Befund 6 an der Vorschrift):* Hier stand „mit B8 vier"; B9
  kam mit §6b dazu, und seine Auffrischung fehlte in der Zählung.
- **`srvpanel vhost`** in B5. Es schreibt den Server-Block des Panels und die
  Rotationsdateien neu, wie jedes Update, und reiht je Abonnement einen Vorgang
  ohne Konto ein.

**Reihenfolge:** Block 0, Block H, Teil A von A0 bis A9, Teil B von B0 bis B9.

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

`START` braucht A6 für das Journal. Gesetzt wird er **vor** dem Anlegen des
API-Tokens, damit jede Zeile, die dabei entsteht, im Fenster liegt.

### A1 — Das API-Token

Im privaten Fenster als Konto 6 anmelden, „Mein Konto", Bereich
„API-Tokens": Bezeichnung `b7-abnahme`, **„API-Token anlegen"**. Oben steht
danach *„Das API-Token ist angelegt. Es steht genau einmal hier."* und der
Bereich „Das neue API-Token" mit dem Klartext, darunter die Liste:
`b7-abnahme`, Anfang `srvp_…`, „Zuletzt benutzt" `nie`. Den Klartext kopieren.

```bash
# A1 · Das API-Token in die Shell — liest es verdeckt ein; schreibt nichts
read -rs -p 'API-Token: ' T; echo
printf 'Anfang %s… · %d Zeichen\n' "${T:0:9}" "${#T}"
tokens
```

**Erwartet:**

```
Anfang srvp_<4 Zeichen>… · 53 Zeichen
API-Tokens: 1
  Token <n> · Konto 6 · b7-abnahme · srvp_<4 Zeichen>… · zuletzt benutzt nie · angelegt <jetzt>
```

Der Anfang ist derselbe wie in der Spalte „Anfang" auf der Seite und in
`tokens`. 53 Zeichen sind `srvp_` und 48 Hexziffern.

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
`b7-leer@b7.invalid` anmelden, „Mein Konto", Bezeichnung `b7-leer`,
„API-Token anlegen", den Klartext kopieren.

```bash
# A3 · Punkt 2 — das API-Token eines Kunden ohne Abonnement; liest nur
read -rs -p 'API-Token von B7 Leer: ' T2; echo
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
  nicht von einem abgewiesenen Token zu trennen — das gäbe 401.
- **403 und nicht `200 []`** (§0, Punkt 1 an der Vorschrift): Eine Route ohne
  Klammer gäbe `200 []`, der leere Kunde bekommt 403. Die beiden Fälle sind
  unterscheidbar, und das ist der Punkt.
- **Das Abonnement von Konto 6 ist für ihn fremd**, 404 wie in A2.

### A4 — Ohne Token, mit einem erfundenen, und die Beschreibung

```bash
# A4 · Ohne Token, ein erfundenes, und die Beschreibung ohne Token — liest nur
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
  Anfrage samt Token, `<y>` eine, die schon an der Drosselung endet. Im
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

### A6 — Punkt 3: Das API-Token steht in keinem Protokoll

**Darf nicht ausfallen.** Läuft nach A2 bis A5, damit jede Anfrage dieses
Laufs im Fenster liegt.

```bash
# A6 · Punkt 3 — das API-Token in keinem Protokoll, der Abfrageteil schon; liest nur
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

**Erfüllt, wenn** beide API-Tokens überall 0 ergeben **und** das Merkzeichen in
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
tokens
```

**Erwartet:** die Zeile `b7-abnahme` mit derselben Zeit wie auf der Seite.
Geschrieben wird sie höchstens einmal je Minute
(`ApiToken::USAGE_RESOLUTION_SECONDS`); auf die Sekunde gleich mit der letzten
Anfrage muss sie deshalb nicht sein. Das API-Token von B7 Leer steht ebenfalls da.

Dann in der Zeile `b7-abnahme` **„Entfernen"**. Oben steht danach die
Rückfrage (Befund 3, §6a): *Das API-Token „b7-abnahme“ entfernen?*, darunter
*Jedes Programm, das es benutzt, bekommt danach 401.*, mit den Knöpfen
„Entfernen" in Rot und „Abbrechen". Zuerst **„Abbrechen"**:

```bash
# A7b · Nach „Abbrechen" — liest nur
api "$T" /api/v1/me
tokens
```

**Erwartet:** für `/me` dieselbe Zeile wie in A2, und in der Ablage beide
API-Tokens:

```
API-Tokens: 2
  Token <n> · Konto 6 · b7-abnahme · srvp_<4 Zeichen>… · zuletzt benutzt <Zeit> · angelegt <Zeit>
  Token <n> · Konto <id> · b7-leer · srvp_<4 Zeichen>… · zuletzt benutzt <Zeit> · angelegt <Zeit>
```

Die Frage ist zu, und auf der Seite steht keine Meldung. Dann noch einmal
**„Entfernen"** in der Zeile und in der Rückfrage **„Entfernen"**. Oben steht
„Das API-Token ist entfernt.", und die Zeile ist fort.

```bash
# A7c · Das entfernte API-Token an der Tür — liest nur
api "$T" /api/v1/me
tokens
```

**Erwartet:**

```
401 · application/json · {"message":"Unauthenticated."}
API-Tokens: 1
  Token <n> · Konto <id> · b7-leer · srvp_<4 Zeichen>… · zuletzt benutzt <Zeit> · angelegt <Zeit>
```

Und im Hauptfenster, als Betreiber: „Mein Konto" hat **keinen** Bereich
„API-Tokens". Ein Adminkonto bekommt kein API-Token (`docs/131 §9`), und für
ein Adminkonto steht dort `null` und keine leere Liste.

### A8 — Die fremde Sicht

Im Hauptfenster: Kunden, in der Zeile von **B7 Leer** „Anmelden als",
„Wechseln". Dann „Mein Konto" — **nur ansehen, nichts anklicken.**

**B7 Leer und nicht der Kunde von Konto 6:** Dessen API-Token ist seit A7
fort, und an einer leeren Liste wäre nicht zu sehen, ob sie in fremder Sicht
dasteht. Das API-Token `b7-leer` gibt es bis A9.

**Erwartet** (Befund 2, §6a): die Bereiche „Darstellung", „Sicherheit" und
„API-Tokens"; „Konto" und „Passwort ändern" fehlen, und die drei Knöpfe der
Darstellung sind gesperrt. In „API-Tokens" steht die Liste mit der Zeile
`b7-leer` und ihrer Zeit unter „Zuletzt benutzt" — und kein Formular, kein
„API-Token anlegen" und kein „Entfernen". Dazu in die Konsole:

```js
(() => { const s = [...document.querySelectorAll('section, .section')].find((x) => x.querySelector('h2')?.textContent.trim() === 'API-Tokens'); return s ? `Formulare ${s.querySelectorAll('form').length} · Spalten ${s.querySelectorAll('thead th').length} · Zeilen ${s.querySelectorAll('tbody tr').length} · Knöpfe [${[...s.querySelectorAll('button')].map((b) => b.innerText.trim()).join(', ')}]` : 'kein Bereich API-Tokens' })()
```

**Erwartet:** `Formulare 0 · Spalten 4 · Zeilen 1 · Knöpfe []`. Dieselbe Zeile
in der eigenen Sicht eines Kunden gibt im Container `Formulare 1 · Spalten 5 ·
Zeilen 1 · Knöpfe [API-Token anlegen, Entfernen]`; unter `rc.16` stand das, mit
„Marke anlegen", auch in fremder Sicht da.

**Dann das x am Band der fremden Sicht** (§6b): Das Band „Sie arbeiten in der
Sicht dieses Kunden." trägt rechts oben ein x. Ein Klick blendet es aus, und
mit ihm „Zurück zur Verwaltung". Danach in der Leiste „Mein Konto" anklicken:
Das Band steht wieder da — weggeklickt ist es nur bis zum nächsten
Seitenwechsel, entschieden vom Betreiber. Im Container gemessen: nach dem x
`0` Knöpfe „Zurück zur Verwaltung", nach dem Klick in der Leiste `1`.

Danach oben im Band **„Zurück zur Verwaltung"**.

### A9 — Abräumen: der Wegwerfkunde

*Berichtigt nach dem Lauf (§8, Befunde 1 und 2 an der Vorschrift):* Die erste
Fassung suchte das API-Token in A9b über die Adresse `b7-leer@b7.invalid`. Das
Zurückziehen gibt diese Adresse frei: Das Konto bleibt stehen, seine Adresse
wird `NULL`, und die Abfrage hätte `entfernt: 0` gemeldet und das API-Token
liegen lassen. Gemessen im Container und auf dem Server. Und die erste Fassung
las nicht davor: Ein leeres `$T2`, etwa nach einer neuen Sitzung, gibt
ebenfalls 401.

Zuerst, solange B7 Leer noch Kunde ist:

```bash
# A9 vorher · Gilt das API-Token von B7 Leer, und welchem Konto gehört es? — liest nur
printf 'Anfang %s… · %d Zeichen\n' "${T2:0:9}" "${#T2}"
api "$T2" /api/v1/me
K2=$("${C[@]}" -H "Authorization: Bearer $T2" "$U/api/v1/me" | jq -r '.data.account_id // empty')
printf 'Konto von B7 Leer: %s\n' "${K2:-—}"
zaehlen() { K2="$K2" srvpanel tinker --execute='printf("über die Adresse: %d · über das Konto: %d\n", App\Models\ApiToken::query()->whereHas("account", fn ($k) => $k->where("email", "b7-leer@b7.invalid"))->count(), App\Models\ApiToken::query()->where("account_id", (int) getenv("K2"))->count());'; }
zaehlen
```

**Erwartet:** `Anfang srvp_<4 Zeichen>… · 53 Zeichen`, `200` mit
`"subscriptions":0`, `Konto von B7 Leer: <id>` und `über die Adresse: 1 · über
das Konto: 1`. Steht dort `0 Zeichen` oder `—`, hat die Sitzung das API-Token
verloren. Dann legt B7 Leer im privaten Fenster ein neues an (§1), und jede
`1` unten wird eine `2`.

Dann im Hauptfenster: Kunden, B7 Leer, **„Zurückziehen"**, die Rückfrage
bestätigen. Kunden werden nicht gelöscht, sondern zurückgezogen; die Nummer
bleibt vergeben, und das sagt die Rückfrage auch. Danach:

```bash
# A9a · Das API-Token eines zurückgezogenen Kunden — liest nur
api "$T2" /api/v1/me
zaehlen
tokens
```

**Erwartet:**

```
401 · application/json · {"message":"Unauthenticated."}
über die Adresse: 0 · über das Konto: 1
API-Tokens: 1
  Token <n> · Konto <id> · b7-leer · …
```

Das API-Token gilt nicht mehr, und seine Zeile steht noch da (Beobachtung in
§0). Über die Adresse findet sie niemand mehr, denn die hat das Zurückziehen
freigegeben. Es geht ausdrücklich, und zwar über das Konto:

```bash
# A9b · Die API-Tokens des Wegwerfkunden entfernen — schreibt: löscht genau die Zeilen seines Kontos in api_tokens
K2="$K2" srvpanel tinker --execute='
  $q = App\Models\ApiToken::query()->where("account_id", (int) getenv("K2"));
  foreach ($q->get() as $t) printf("weg: Token %d · Konto %d · %s\n", $t->id, $t->account_id, $t->name);
  printf("entfernt: %d\n", $q->delete());
'
tokens
unset T T2 K2
```

**Erwartet:** `weg: Token <n> · Konto <id> · b7-leer`, `entfernt: 1` und
`API-Tokens: 0` — dieselbe Zahl wie in Block 0. Ist `K2` leer, fragt die
Abfrage nach Konto 0 und entfernt nichts.

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

*Berichtigt nach dem Lauf (§8, Befund 3 an der Vorschrift):* Die erste
Fassung rechnete mit rund 25 Sekunden für die beiden Vorgänge, und das sollte
die Zeit für B2 sein. Auf `cloudsrv24` brauchte eine Auffrischung 2 bis 4
Sekunden, und beide waren fertig, bevor der erste Seitenwechsel möglich war.
Gefahren ist B2 deshalb mit angehaltener Warteschlange, und so steht es jetzt
hier: Ein wartender Vorgang ist für den Streifen so offen wie ein laufender,
und er wartet, solange B2 braucht.

```bash
# B1 vorher · Die Warteschlange anhalten — hält srvpanel-worker an; die Vorgänge bleiben auf „wartet"
systemctl stop srvpanel-worker
sleep 2
systemctl is-active srvpanel-worker
```

**Erwartet:** `inactive`. Wieder gestartet wird die Schlange in B3.

**Darf nicht ausfallen.** **„Jetzt nachsehen"**, „Nachsehen" — und sofort ein
zweites Mal. Beide Vorgänge warten dann, bis B3 die Schlange wieder startet.

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
- **Oben stehen zwei Streifen**, `Vorgang <n> system.packages.refresh — wartet`,
  je mit „ansehen".

Eine Zeile `Seite` je Klick ist das Absenden selbst, und die `Nachladen`-Zeile
mit `packages` danach die Pakettabelle der Seite, auf die es zurückführt. Eine
Zeile `Seite bei … — /operations/<n>` stünde dort, wenn der Klick wegführte.

### B2 — Punkt 2: der Streifen überlebt den Seitenwechsel, der Takt läuft einmal

Solange beide Vorgänge warten, über das Menü **„Kunden"**, dann
**„Abonnements"**, und auf jeder Seite rund zehn Sekunden bleiben. Danach auf
„Abonnements" bleiben.

**Erwartet:**

```
Seite bei c — /customers
Nachladen bei c + 3,0 — runningOperations
…
Seite bei d — /subscriptions
Nachladen bei d + 3,0 — runningOperations
…                                            (solange beide warten)
```

- **Der Streifen steht auf jeder Seite**, mit denselben beiden Vorgängen.
- **Zwischen zwei `Nachladen` liegen nie weniger als rund drei Sekunden.** Ein
  zweiter Takt stünde als zweite Zeile dazwischen; nach zwei Wechseln wären es
  drei.
- **Die erste Zeile nach einem Wechsel kommt drei Sekunden nach ihm.** Der
  Streifen wird mit der Seite neu aufgebaut, und sein Takt beginnt neu.

Mit angehaltener Schlange endet keiner der beiden, bevor B3 sie wieder
startet. Im Lauf zeigten alle drei Seiten dieselben beiden Streifen mit
`— wartet`.

### B3 — Punkt 3: nach dem Ende fragt niemand mehr nach

**Darf nicht ausfallen.** Zuerst die Schlange wieder starten:

```bash
# B3 vorher · Die Warteschlange wieder starten — startet srvpanel-worker
systemctl start srvpanel-worker
sleep 2
systemctl is-active srvpanel-worker
```

**Erwartet:** `active`. Dann auf „Abonnements" bleiben, bis beide Streifen
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
`succeeded`, 100 %, mit Zeiten für angelegt, begonnen und fertig; „begonnen"
liegt nach dem Start der Schlange. Die Spanne von begonnen bis fertig ist die
Laufzeit von heute, nach der `docs/132 §5` fragt; `docs/92 §6` nannte am
31. August 12 Sekunden, am 11. Oktober waren es 2 bis 4 (§8).

### B4 — Ein Strom nur auf der Vorgangsseite

*Berichtigt nach dem Lauf (§8, Befunde 3 und 4 an der Vorschrift):* Die erste
Fassung sagte „im Streifen sofort „ansehen", solange der Vorgang läuft". Ein
Vorgang lief 2 Sekunden, und „ansehen" ist zugleich der Verweis und ein Verb.
Im Lauf kam der Wechsel auf die Vorgangsseite in drei Durchgängen nicht in der
Konsole an. Jetzt wartet der Vorgang, bis die Seite offen ist, und der Satz
sagt „klicken".

Bis hierher steht **keine** Zeile `Strom` in der Konsole — durch `/updates`,
`/customers` und `/subscriptions`. Dann die Schlange anhalten:

```bash
# B4 vorher · Die Warteschlange anhalten — hält srvpanel-worker an; der Vorgang bleibt auf „wartet"
systemctl stop srvpanel-worker
sleep 2
systemctl is-active srvpanel-worker
```

Über das Menü zu „Updates", **„Jetzt nachsehen"**, „Nachsehen", und dann
**auf den Verweis „ansehen" rechts im Streifen klicken**. Die Adresse wechselt
auf `/operations/<n>`.

**Erwartet:**

```
Seite bei g — /updates
Nachladen bei g — packages,packagesError
Seite bei h — /updates/refresh
Nachladen bei h — packages,packagesError
Seite bei i — /operations/<n>
Strom bei i — /operations/<n>/stream         (genau eine)
```

Die Vorgangsseite öffnet ihren Strom nur, solange der Vorgang offen ist, und
ein wartender ist offen (`OperationStatus::open()`). Auf ihr fragt der
Streifen weiter alle 3 Sekunden nach, denn er steht auf jeder Seite; eine
zweite Zeile `Strom` kommt trotzdem nicht, weil die Nachfragen nur den
Streifen nachladen. Dann die Schlange wieder starten:

```bash
# B4 danach · Die Warteschlange wieder starten — startet srvpanel-worker
systemctl start srvpanel-worker
sleep 2
systemctl is-active srvpanel-worker
```

**Erwartet:** `active`. Die Vorgangsseite springt ohne Neuladen auf „fertig",
und danach kommt keine Zeile mehr. B5 folgt innerhalb von zwei Minuten.

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

*Berichtigt nach dem Lauf (§8, Befunde 3 und 5 an der Vorschrift):* Die erste
Fassung sagte „zwei Takte im Wechsel". Gemessen laufen sie im Gleichschritt,
denn beide beginnen mit dem Aufbau der Seite nach dem Absenden. Und eine
Sicherung von `p6-abnahme.invalid` ist in Sekunden fertig; gefahren ist B7
deshalb mit angehaltener Schlange. Eine neue Sicherung steht ab dem Klick auf
„wird erstellt" (`BackupStatus::Pending`), und die Liste fragt dann nach, auch
solange der Vorgang wartet.

Im privaten Fenster als Konto 6: Abonnement `p6-abnahme.invalid`,
„Sicherungen", die Seite neu laden und die Probe einfügen. Dann die Schlange
anhalten wie in B4, **„Jetzt sichern"**, rund 15 Sekunden zusehen und
`location.pathname` in die Konsole. Danach die Schlange wieder starten wie in
B4 und warten, bis die Sicherung fertig ist.

**Erwartet:** Die Adresse bleibt `/subscriptions/140/backups`, oben steht
`Vorgang <n> backup.create — wartet`, und die Probe zeigt **zwei** Takte im
Gleichschritt:

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

### B9 — Das x am Vorgang (darf ausfallen)

*Berichtigt nach dem Lauf (§8, Befund 3 an der Vorschrift):* „Auch solange
der Vorgang noch läuft" setzt einen Vorgang voraus, der läuft, und einer lief
2 Sekunden. Gefahren ist B9 mit angehaltener Schlange und mit zwei Zeitmarken
in der Konsole, die den Klick einrahmen.

Die Gerätesicht aus B8 wieder aus, `/updates` **neu laden** und die Probe
einfügen, einmal. Die Schlange anhalten wie in B4. **„Jetzt nachsehen"**,
„Nachsehen", und sobald der Streifen steht und zwei Nachfragen dastehen,
**auf das x rechts oben im Streifen klicken**. Gleich danach und 15 Sekunden
später je eine Zeitmarke in die Konsole: `new Date().toLocaleTimeString()`.

**Erwartet:**

- **Der Streifen ist fort**, und mit ihm die Hülle, wenn kein anderes Band
  dasteht.
- **Zwischen den Zeitmarken steht keine Zeile `Nachladen — runningOperations`**,
  obwohl der Vorgang weiter wartet. Der Takt fragt nur für Vorgänge nach, die
  jemand sieht.

Dann über das Menü zu **„Kunden"**, danach die Seite **ganz neu laden**, und
in die Konsole:

```js
Object.keys(localStorage).filter((k) => k.startsWith('srvpanel.bands.')).map((k) => k + ' = ' + localStorage.getItem(k)).join('\n') || 'nichts gemerkt'
```

**Erwartet:** auf beiden Seiten kein Streifen, und

```
srvpanel.bands.account.<Kennung des Betreibers> = ["operation:<n>"]
```

mit `<n>` dem Vorgang, den `vorgaenge 1` als jüngsten zeigt. Steht in der
Liste mehr, hat in diesem Browser vorher schon jemand Bänder weggeklickt.

Dann die Schlange wieder starten:

```bash
# B9 danach · Die Warteschlange wieder starten — startet srvpanel-worker; dann der jüngste Vorgang
systemctl start srvpanel-worker
sleep 5
systemctl is-active srvpanel-worker
vorgaenge 1
```

**Erwartet:** `active` und `Vorgang <n> · system.packages.refresh · Konto
<Betreiber> · succeeded · 100 %`, „begonnen" nach dem Start. Ohne das x
stünde der fertige Vorgang jetzt noch zwei Minuten im Streifen.

**Zwei Minuten nach seinem Ende** — die Zeit steht in `vorgaenge` unter
„fertig" — über das Menü auf eine Seite wechseln und dieselbe Zeile noch
einmal einfügen. **Erwartet:** `operation:<n>` steht nicht mehr da, und ohne
andere Einträge heisst die Antwort `nichts gemerkt`. Ein Vorgang, der nicht
mehr in den Streifen kommen kann, wird vergessen; sonst wüchse der Speicher
mit jedem.

Im Container gemessen (§6b): vor dem x zwei Nachfragen in sieben Sekunden,
danach null, auch nach dem Wechsel; nach dem vollen Neuladen weiter fort; ein
zweiter Vorgang desselben Kontos steht trotzdem da; eine Minute nach dem Ende
bleibt der Schlüssel, nach drei Minuten ist er fort.

---

## §5 · Was dieser Lauf ausdrücklich nicht prüft

- **Das API-Token im Abfrageteil.** Es schriebe ein gültiges Token ins
  Protokoll (§0, Punkt 3 an der Vorschrift). Dass die Wache dort nicht liest,
  hält `ApiTokenTransportTest` an der Wirkung; im Container gab dasselbe Token
  im Abfrageteil 401.
- **Ein API-Token an einem Adminkonto.** Die Oberfläche legt keins an. Dass
  die Wache ein bestehendes abweist, hält `AuthenticateToken` und sein Wächter; im
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
- **A5, A5b, B7, B8 und B9 dürfen ausfallen.** Sie beantworten Fragen aus
  `docs/131 §8` und `docs/132 §5` oder zeigen die Behebungen der Befunde 1
  und 5 und das x der Bänder auf dem Server.
- **Block 0, A4, A7 und A8 zeigen die Behebungen der Befunde 2 bis 4** (§6a).
  Gemessen sind sie im Container; auf dem Server hat sie noch niemand
  gesehen.
- **A9 wird gefahren**, auch wenn ein Punkt davor ausfällt. Ein Kunde ohne
  Abonnement mit einem API-Token ist ein Rest und kein Prüfkörper.

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

## §6b · Das x und das API-Token — 11. Oktober 2026

Am selben Vormittag kamen zwei Wünsche des Betreibers, beide für denselben
Zweig: *„Ein Band soll sich wegklicken lassen. Dafür ist ein ‚x' in jedem Band
erforderlich."* Und: *„Der Begriff Zugangsmarke ist nicht sehr zugänglich und
entspricht auch keinem verwendeten technischen Begriff. Es sollte sich API
oder API Token wiederfinden."* Gebaut ist beides für `0.9.0-rc.17`, gemessen
im Container gegen dieselbe SQLite-Datenbank unter `artisan serve` wie §6a,
im echten Chromium. Auf dem Server sehen es A8 und B9 nach.

### Was das x tut

Zwei Fragen hat der Betreiber gleich danach entschieden, beide wie
vorgeschlagen:

| Band | weggeklickt, bis … | gemerkt |
|---|---|---|
| Ankündigung | sie sich ändert oder endet | im Browser, je Konto |
| laufender Vorgang | immer — er kommt nicht wieder | im Browser, je Konto |
| fremde Sicht | zum nächsten Seitenwechsel | nirgends |
| Wartung | zum nächsten Seitenwechsel | nirgends |

**Eine Ankündigung kommt wieder, wenn sich ändert, was ihr Band zeigt.** In der
Frage stand „bis zu ihrem Ende". Beim Bauen fiel auf, dass sich eine
Ankündigung seit dem 6. September ändern lässt, und eine geänderte Fassung ist
genau das, was jemand sehen soll, der die alte weggeklickt hat. Ihr Schlüssel
trägt deshalb einen Fingerabdruck über Rang und Text (`announcementKey()` in
`useDismissedBands.ts`). Ein geändertes Ende der Sichtbarkeit lässt das Band,
wie es ist, und holt es nicht zurück. Dem Betreiber ist diese Lesart beim
Bauen gesagt worden.

**Die fremde Sicht und die Wartung melden einen Zustand**, und das Band der
fremden Sicht trägt den einzigen Rückweg zur Verwaltung (`docs/20 §6.3`:
„Rückweg jederzeit"). Beide kommen beim nächsten Seitenwechsel wieder. Den
Zustand hält `PanelLayout` selbst und nicht der Speicher. Ein Nachladen auf
derselben Seite, auch ein partielles, lässt sie weggeklickt, ein Klick im
Menü bringt sie zurück (B10 bis B12, B17 bis B19).

**Gemerkt wird je Konto**, unter `srvpanel.bands.account.<Kennung>`: Der
Betreiber und die fremde Sicht, die er gerade hält, teilen sich einen Browser.
Auf den beiden Seiten der Anmeldung lässt sich die Störung auch wegklicken,
gemerkt unter `guest`. **Und vergessen wird, was es nicht mehr gibt** — die
Schlüssel einer Ankündigung, die geendet hat oder geändert wurde, und eines
Vorgangs, der aus dem Streifen gefallen ist. Sonst wüchse der Speicher mit
jedem Vorgang.

### Die Gestaltung

**Das x steht neben dem Band und nicht darin.** Zwei der vier Bänder sind
selbst ein Verweis, die Ankündigung und die Wartung, und ein Knopf in einem
`<a>` ist kein gültiges HTML. Die Hülle `.band-item` trägt beides, und das x
liegt über der rechten oberen Ecke des Bandes: eine Fläche von `--tap` (44 px
bei 390, 38 bei 1440), darin ein Zeichen von 16 px, ohne Rand und ohne Grund.

**Das Band hält ihm den Platz frei, bis zum Zeichen und eine Fuge weiter**:
46 px bei 390 und 43 bei 1440 statt 24. Im ersten Wurf war der Platz so breit
wie die Fläche, und bei 1440 px stand „ansehen" damit 11 px vor dem Zeichen
und las sich wie ein Wort damit; jetzt sind es 16 px wie zwischen den
Handgriffen eines Bandes.

Gemessen beim Betreiber auf `/subscriptions`, mit einer Wartung, einer Störung,
einer Ankündigung von 289 Zeichen und drei Vorgängen (wartet, fehlgeschlagen,
fertig), in vier Lagen:

```
                    390 px            1440 px
Wartung             104 px            41 px
Störung              62 px            41 px
Ankündigung          62 px            62 px
Vorgang wartet       99 px            41 px
Vorgang fehlgeschl. 122 px            48 px
Vorgang fertig      122 px            48 px
x                   44 × 44, oben 0 rechts 0      38 × 38, oben 0 rechts 0
Inhalt → Zeichen    16 px             16 px
dokument            0 · Gegenprobe 200/200 · schiebt 0, in allen vier Lagen
Zeichen in Ruhe     5,09:1 bis 6,18:1 auf dem gemischten Grund jedes Bandes
beim Überfahren     12,91:1 bis 16,96:1
```

Die Grenze eines Bedienelements verlangt 3:1 (WCAG 1.4.11), und das x wird an
seinem Zeichen erkannt; deshalb steht es in `--text-muted` und beim Überfahren
in `--text-strong`. Die Vorgänge haben dieselben Höhen wie vor dem x (B8,
§6a).

**Der freigehaltene Platz kann eine Zeile kosten**, und das stand zuerst
andersherum im Kommentar an `.band-item`. Gemessen an denselben Bändern mit
und ohne die eine Regel, die den Platz freihält:

```
390 px   6 Ankündigungen von 24 bis 289 Zeichen, Wartung, Vorgang    gleich 8 · anders 0
390 px   51 Ankündigungen von 20 bis 70 Zeichen                      gleich 51 · anders 2 (39 und 40 Zeichen: 41 → 62 px)
1440 px  101 Ankündigungen von 130 bis 230 Zeichen                   gleich 100 · anders 3 (182 bis 184 Zeichen: 41 → 62 px)
```

Ein Text, der in den letzten 22 px einer Zeile endet (bei 1440 px 19 px),
bekommt damit eine zweite. Die Klammer auf zwei Zeilen bleibt die Obergrenze,
die A14 einer Ankündigung zusagt.

> **Ein Kommentar, der „ändert nichts" sagt, braucht die Messung mit und ohne
> die Regel — eine Messung nur mit ihr sagt nicht, was ohne sie wäre.**

**Oben und nicht in der Mitte.** Bei einem Band mit zwei Zeilen gehört das x
zur ersten. Bei einem einzeiligen Vorgang mit Knopf, 48 px hoch bei 1440 px,
steht das Zeichen dadurch rund 5 px über der Mitte der Zeile; das bleibt so.

### Das Verhalten

Gemessen mit demselben Bestand, in einem Lauf und in dieser Reihenfolge:

```
B0  Ausgang                         Wartung | Störung | Info | Vorgang 8 | Vorgang 6 | Vorgang 5 · Speicher: null
B1  Info weggeklickt                ohne Info · ["announcement:7:5e337606"]
B2  Seitenwechsel /customers        ohne Info · Speicher gleich
B3  volles Neuladen                 ohne Info · Speicher gleich
B4  Ende der Sichtbarkeit geändert  ohne Info · Speicher gleich
B5  Text geändert                   Info wieder da · Speicher: null
B6  Takt mit wartendem Vorgang      7 s: 2 Nachfragen
B7  laufenden Vorgang weggeklickt   ohne Vorgang 8 · ["operation:8"]
B8  Takt ohne sichtbaren            7 s: 0 Nachfragen
B9  partielles Nachladen            ohne Vorgang 8 · Speicher gleich
B10 Wartung weggeklickt             ohne Wartung · Speicher gleich
B11 router.reload()                 ohne Wartung
B12 Seitenwechsel                   Wartung wieder da
B13 alles weggeklickt               Hüllen .bands: 0
B14 Seitenwechsel                   Bänder: Wartung · Hüllen: 1
B15 Vorschau auf /announcements     offen 1 · nach dem x 0 · Speicher gleich
B16 fremde Sicht                    Sie arbeiten in der Sicht … | Störung | Info | Vorgang 9 · Speicher Konto 2: null
B17 fremde Sicht weggeklickt        Rückweg da: 0
B18 router.reload()                 Rückweg da: 0
B19 Klick in der Leiste             Rückweg da: 1 · Speicher Konto 2: null
B20 zurück                          Verwaltung · /customers
G1  Anmeldeseite                    Bänder: Störung · Zugriff: ok
G2  weggeklickt                     Hüllen: 0 · ["announcement:8:d71e29cd"] unter guest
G3  Neuladen                        weiter fort
G4  angemeldet im selben Browser    alle sechs Bänder — der Gast merkt nicht für das Konto
S1  Seitendaten gesperrt            Zugriff: SecurityError · Bänder: Störung
S2  weggeklickt                     fort, Hüllen: 0
S3  Neuladen                        Störung wieder da
Seitenfehler                        keine, im Panel, als Gast und mit gesperrten Seitendaten
```

Und für B9 dieses Laufs, eigens gemessen:

```
V0 vorher           Vorgang 8 — wartet · Takt 7 s: 2 · nichts gemerkt
V1 nach dem x       Bänder: — · Hüllen: 0 · Takt 7 s: 0 · srvpanel.bands.account.1 = ["operation:8"]
V2 Menü „Kunden"    Bänder: — · Takt 7 s: 0
V3 volles Neuladen  Bänder: — · srvpanel.bands.account.1 = ["operation:8"]
V4 zweiter Vorgang  Vorgang 9 — wartet
V5 fertig vor 1 min Bänder: — · srvpanel.bands.account.1 = ["operation:8"]
V6 fertig vor 3 min Bänder: — · nichts gemerkt
```

**Die Vorschau auf `/announcements` hat ihr x auch** und schliesst damit die
Vorschau; gemerkt wird dort nichts (B15).

### Ein Satz, der nicht stimmte

Der Kommentar in `Overview.vue`, der den Speicher des Browsers seit dem Takt
der Übersicht in einem `try` liest, begründete das so: In einem privaten
Fenster werfe schon das Lesen von `window.localStorage`. Gemessen im Chromium
liest ein Profil, das nichts behält, ohne Ausnahme — `Zugriff: ok`. Es wirft
erst bei abgeschalteten Seitendaten, `SecurityError` (S1). Die Klammer war
richtig, die Begründung nicht.

> **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist auch
> dann falsch, wenn der Handgriff daneben richtig ist.**

Seit dem x läuft ein solcher Zugriff im Layout **jeder** Seite. Ohne die
Klammer stünde dort jede Seite weiss da, und zwar genau bei dem, der seine
Seitendaten mit Absicht abgeschaltet hat. `BrowserStorageTest` hält deshalb,
dass jeder Zugriff in einem `try` steht.

### API-Token statt Zugangsmarke

Das Fachwort stand die ganze Zeit in `docs/19 §2` Punkt 2: *Token* ist dort
unter den etablierten englischen Fachwörtern genannt. Die Regel, die es
verlangte, stand nicht auf der Liste der verbrauchten Wörter, und kein Lauf
hat es gesehen. Jetzt steht `Zugangsmarke → API-Token` in `docs/19 §3`, und
`WordChoiceTest` hält es für Vorlagen, Skripte der Seiten und die
Zeichenketten unter `app/`. *Marke* allein bleibt erlaubt: Es heisst in diesem
Panel auch die Marke des Betreibers und eine Zustandsmarke.

Umgestellt sind die Profilseite, die drei Meldungen von
`ProfileController`, die Gründe in `RouteGuard`, die Beschreibung unter
`docs/openapi-v1.yaml`, die Kommentare und die Tests. Die Bezeichner waren
schon englisch und bleiben. Gemessen im echten Panel:

```
eigene Sicht · Bereiche: Konto, Passwort ändern, Darstellung, Sicherheit, API-Tokens
eigene Sicht · Zeile:    Formulare 1 · Spalten 5 · Zeilen 1 · Knöpfe [API-Token anlegen, Entfernen]
nach dem Anlegen:        Das API-Token ist angelegt. Es steht genau einmal hier.
                         Bereich „Das neue API-Token" · srvp_<48 Hexziffern>
Rückfrage:               Das API-Token „messung-api-token" entfernen? ¶ Jedes Programm, das es benutzt, bekommt danach 401.
nach dem Entfernen:      Das API-Token ist entfernt.
Betreiber:               kein Bereich API-Tokens
fremde Sicht · Zeile:    Formulare 0 · Spalten 4 · Zeilen 1 · Knöpfe []
fremde Sicht · x:        „Ausblenden" · danach Rückweg 0 · nach „Mein Konto" in der Leiste 1
```

**§0, §6a und §7 behalten das alte Wort**, wo sie zitieren, was die Seite
damals sagte. Die Anweisungen von §1 bis §6 sprechen vom API-Token.

### Die Wächter

- **`BandDismissTest`**, fünf Fälle: Jedes Band steht neben seinem x in einer
  `.band-item`, kein x steht in einem Band, einem Verweis oder einem Knopf,
  die fremde Sicht und die Wartung kommen zurück und der Speicher kennt sie
  nicht, der Schlüssel einer Ankündigung deckt jedes Feld, das ihr Band
  zeigt, und jede Hülle fragt die sichtbare Liste, die sie zeigt.
- **`BrowserStorageTest`**, zwei Fälle: Jeder Zugriff auf den Speicher des
  Browsers steht in einem `try`, und die Zählung der Blöcke trennt das `try`
  von seinen Nachbarn.
- **`OperatorControlTest`** lässt die Wache jetzt als Glied einer
  `&&`-Bedingung gelten, auch in `v-else-if`; ein `||` und eine Klammer
  zählen nicht. Das Wartungsband steht seitdem hinter
  `maintenance && !hiddenUntilNavigation.has('maintenance')`, und der Wächter
  verlangte vorher wörtlich `v-if="maintenance"`.
- **`MaintenanceBandTest`** liest das Band in seiner Hülle und hält, dass die
  Hülle aller Bänder seine Bedingung mitfragt.
- **`WordChoiceTest`** führt das Paar, **`ClassNameTest`** das Wort `dismiss`.

**Und die Falle aus `BaseMethodClashTest` zum sechsten Mal.** Die Hilfsmethode
für die `&&`-Bedingung hiess im ersten Wurf `requires()`, und das ist eine
`final`-Methode von `PHPUnit\Framework\TestCase`. Der Lauf starb beim Laden
der Klasse mit einem fatalen Fehler, bevor ein Test lief.

> **Eine Regel, an die man sich erinnern muss, ist keine Regel, sondern eine
> Gewohnheit.**

### Die Brüche

Dreizehn Eingriffe im Bruchskript: sieben an `BandDismissTest`, zwei an
`BrowserStorageTest`, einer an `OperatorControlTest` (ein `||` als `&&`
gelesen) und drei an `WordChoiceTest`, je einer in einer Vorlage, in einem
Skript und in einer Meldung von `ProfileController`. Sechs bestehende
Eingriffe hatten ihren Anker in einer der umgebauten Stellen und sind
nachgezogen: die Hülle der Anmeldeseite, der Verweis der Ankündigung, zwei an
der Wartung, das Formular und der Knopf „Entfernen" der API-Tokens.

**Im vollen Lauf über `88d6eb08` beissen alle**: 3416 Prüfungen, jede wie
erwartet. Über `579f5e03` waren es 3390, und die 26 dazwischen sind die
dreizehn neuen Eingriffe mit je ihrer Gegenprobe.

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
`7090a2e6deb3ead4`. Mit §6b heisst `marken` jetzt `tokens`, und die Datei hat
seitdem die Prüfsumme `8b56817a615927a6`; so steht sie in §1, und so stand sie
auf dem Server (§8).

---

## §8 · Protokoll

Gefahren vom 11. bis 14. Oktober 2026 auf `cloudsrv24` gegen `0.9.0-rc.17`.
Block 0, H und Teil A bis A8 liefen am Mittag des 11. Oktober bis 12:55 Uhr,
A8 am Schreibtisch und A9 am Abend desselben Tages, B0 bis B6 von 19:58 bis
20:30 Uhr. B8 und B9 folgten am Morgen des 12. Oktober, B7 am Morgen des 14.
Was hier steht, ist von den Bildern und Ausgaben des Betreibers abgelesen. Die
Uhrzeiten sind die der Anzeigezone, CEST, und die Zeiten der Taktprobe stehen
als Sekunden seit ihrem Einsetzen da. API-Tokens stehen nur mit ihrem Anfang
da.

**Die tragenden Punkte sind erfüllt, A2 und A6 für B7, B1 und B3 für B8**, und
die beiden dazugelegten A3 und B2 auch. Block 0, A4, A7 bis A9, B0 und B5 bis
B9 sind erfüllt, A5 und A5b gemessen. **Von B4 ist eine Hälfte erfüllt:** Über
die ganze Sitzung öffnete keine Seite ausser der Vorgangsseite einen Strom. Die
andere Hälfte, genau ein Strom auf der Vorgangsseite, ist auf dem Server nicht
gemessen; so hat es der Betreiber entschieden, und im Container ist sie
gemessen. **Die fünf Behebungen aus §6a sind auf dem Server gesehen**, aus
§6b das x an der fremden Sicht und am Streifen und das Wort „API-Token".

**Am Prüfling ein Befund, ausserhalb der Kriterien:** Der zweite Faktor lässt
sich in fremder Sicht am Konto des Kunden einschalten. Der Betreiber hat
Variante a entschieden, und gebaut wird sie nach dem Lauf, für
`0.9.0-rc.18`. **An der Vorschrift sechs Befunde.** Alle sind in dieser
Fassung berichtigt, jede Stelle mit einem Vermerk.

### Block 0 und H — 11. Oktober

- **`0.9.0-rc.17`**, `srvpanel-worker` und `srvpanel-agentd` beide `active`.
- **Konto 6 ist ein Kunde mit zwei Abonnements**, 140 `p6-abnahme.invalid` und
  141 `p6-nochmaltest`; das zweite hat keine Domain. Das erste fremde
  Abonnement ist 137 `p6-b.invalid`, die erste fremde Domain 51. In der Datei
  standen `EA=140 FA=137 ED=54 FD=51 NA=999999`, fünf von fünf.
- **`API-Tokens: 0`**, `Kunden ohne Abonnement: 0`, `Cache: database ·
  Drosselung api/v1: 60 je Minute`, `jq` da.
- **Unter `docs` lag `openapi-v1.yaml`, und nur sie.** Damit ist Befund 4 aus
  §0 auf dem Server behoben; unter `rc.16` gab es das Verzeichnis im Paket
  nicht.

Block H gab `Werkzeuge: 6 von 6 · 67 Zeilen · 8b56817a615927a6`, die Prüfsumme
aus §1. Das Echo beim Einfügen sah zerhackt aus, und die Datei war heil.

### A1 und A2 — Punkt 1, erfüllt

A1 legte Token 1 an, `b7-abnahme` an Konto 6, Anfang `srvp_2c8c…`, 53 Zeichen,
„zuletzt benutzt" `nie`. Es ist das erste API-Token auf diesem Server.

- **`/me`** gab `200` mit `"account_id":6` und `"subscriptions":2`. Die Liste
  trug genau `140 · p6-abnahme.invalid` und `141 · p6-nochmaltest`.
- **Eigene Kennungen:** Abonnement 140 und Domain 54 gaben `200`, die Domains
  von 140 genau `54 · p6-abnahme.invalid`.
- **Fremde und erfundene Kennungen:** Die Abonnements 137 und 999999 gaben
  denselben Satz, `404` mit „No query results for model
  [App\\Models\\Subscription]", nur mit der eigenen Zahl am Ende. Die Domains
  von 137 gaben denselben Satz wie das Abonnement 137. Die Domains 51 und
  999999 gaben `404` mit „… [App\\Models\\Domain]".
- **Nirgends ein `403`.** Die Kennzahlen von 140 kamen mit `200` und
  `"retention_days":30`.

### A3 — Punkt 2, erfüllt

Der Wegwerfkunde B7 Leer bekam Konto 12. Sein API-Token, `srvp_1db3…`, 53
Zeichen, angelegt um 12:37:36, gab an `/me` `200` mit `"subscriptions":0`.
Die Liste gab `403` und nicht `200 []`; Abonnement 140 und Domain 54 gaben
`404` wie in A2.

### A4 — erfüllt

Ohne Token und mit einem erfundenen je `401` mit
`{"message":"Unauthenticated."}`. `/api/v1/openapi.yaml` ohne Token gab
`200 · application/yaml; charset=utf-8` und den Anfang der Beschreibung.
Befund 4 ist damit auch an der Tür gesehen.

### A5 und A5b — gemessen

Genau 60 Anfragen mit `200`, im Mittel **25 ms**, und eine mit `429`,
**17 ms**. Die nächste gab `429` mit Limit 60, Rest 0 und `retry-after: 58`.
Der Zähler in der Datenbank kostete **1,48 ms** je Anfrage; 100 Versuche
gezählt, danach 0. Im Container waren es 14 und 9 ms und mit SQLite 2,645 ms.
Mit MariaDB ist der Zähler schneller, und von einer ganzen Anfrage ist er ein
kleiner Teil.

### A6 — Punkt 3, erfüllt

`/me` mit dem Merkzeichen im Abfrageteil gab `200` für Konto 6. Dann:

- **Das Merkzeichen** (`b7-gegenp…`, 24 Zeichen) steht in `panel-access.log`
  einmal und in jeder anderen Datei und im Journal null Mal.
- **Beide API-Tokens** (`srvp_…`, 53 Zeichen) stehen in keiner Datei und nicht
  im Journal.

Die 1 beim Merkzeichen belegt, dass `spur` die Dateien liest; erst neben ihr
sind die Nullen eine Messung.

### A7 — erfüllt, in anderer Reihenfolge

- **A7a:** zwei API-Tokens, `b7-abnahme` zuletzt benutzt um 12:44:21, also mit
  der Anfrage aus A6, daneben `b7-leer`.
- **Die Rückfrage stand da, wie in §6a gebaut.** Das Bild zeigt sie im Konto
  von B7 Leer für `b7-leer`: „Das API-Token „b7-leer" entfernen?", darunter
  „Jedes Programm, das es benutzt, bekommt danach 401.", „Entfernen" in Rot und
  „Abbrechen". Damit ist Befund 3 auf dem Server gesehen.
- **A7b zeigte schon, was A7c erwartet:** `401` für `b7-abnahme` und nur noch
  `b7-leer`. Die Spur auf dem Server hat den Ablauf geklärt. Entfernt wurde
  genau einmal, `b7-abnahme`, von Konto 6, um 12:48:03: eine Anfrage
  `DELETE /settings/tokens/1` mit `303` und eine Zeile `api.token.removed ·
  success`. Ein `DELETE /settings/tokens/2` gibt es danach nicht. Die
  Rückfrage für `b7-leer` um 12:50 schickte also nichts, und `b7-leer` stand
  um 12:55 noch da.
- **Nach dem Entfernen stand oben „Das API-Token ist entfernt."**, bestätigt
  vom Betreiber.
- **Das Konto des Betreibers hat keinen Bereich „API-Tokens".** Abgelesen am
  Abend vor A9, mit der Zeile aus A8: `kein Bereich API-Tokens`.

Ob vor dem Entfernen von `b7-abnahme` einmal „Abbrechen" gedrückt wurde, sieht
der Server nicht, denn „Abbrechen" schickt nichts. Gezeigt ist es an der
Rückfrage für `b7-leer`. Ein Bild vom Telefon um 12:45 zeigt dazu den Bereich
„API-Tokens" mit den neuen Wörtern: Überschrift, Hinweis, „API-Token anlegen"
und die Karte von `b7-leer` mit „Entfernen".

### A8 — erfüllt, auf dem Telefon und am Schreibtisch

Auf dem Telefon um 12:55, in der Sicht von B7 Leer:

- **Das Band der fremden Sicht**, „Angemeldet als B7 Leer, gewechselt von
  Administrator", mit „Zurück zur Verwaltung" und dem x.
- **Die Bereiche „Darstellung", „Sicherheit" und „API-Tokens".** „Konto" und
  „Passwort ändern" fehlen, und die drei Knöpfe der Darstellung sind gesperrt.
- **Unter „API-Tokens" eine Karte**, `b7-leer`, `srvp_1db3…`, zuletzt benutzt
  12:38:30, angelegt 12:37:36. Kein Formular, kein „API-Token anlegen" und
  kein „Entfernen". **Damit ist Befund 2 auf dem Server gesehen.**
- **„Letzte Anmeldung 12:49:57" war eine Anmeldung von B7 Leer** und nicht der
  Wechsel in seine Sicht. `last_login_at` schreiben nur die Anmeldung und die
  Abfrage des zweiten Faktors.

Am Schreibtisch am Abend:

```
Formulare 0 · Spalten 4 · Zeilen 1 · Knöpfe []
```

Dazu das x am Band der fremden Sicht, mit der Zahl der Knöpfe „Zurück zur
Verwaltung": `1` vor dem x, `0` nach dem x und `1` nach dem Klick auf „Mein
Konto" in der Leiste. Das x nimmt den Rückweg mit, und der Seitenwechsel
bringt beides zurück (§6b).

Auf dem zweiten Bild vom Telefon stand neben „Zweiter Faktor" der Verweis
„verwalten". Daraus kam der Befund am Prüfling, weiter unten.

### A9 — erfüllt, mit der berichtigten Vorschrift

Die alte Sitzung war fort und mit ihr `$T2`. Gefahren ist A9 in einer neuen
Sitzung, mit dem Klartext, den der Betreiber noch hatte, und mit dem Block,
der jetzt in §3 steht. Im Lauf stand darin Konto 12 fest, und so druckte er
„über Konto 12" statt „über das Konto":

```
Werkzeuge: 6 von 6 · 67 Zeilen · 8b56817a615927a6
Anfang srvp_1db3… · 53 Zeichen
200 · application/json · {"data":{"account_id":12,"name":"B7 Leer","type":"customer","subscriptions":0}}
über die Adresse: 1 · über Konto 12: 1
```

„Zuletzt benutzt" von Token 2 sprang dabei von 12:38:30 auf 19:51:08; die
`200` kam also von genau diesem API-Token. Nach dem Zurückziehen:

```
401 · application/json · {"message":"Unauthenticated."}
über die Adresse: 0 · über Konto 12: 1
```

Die Null zeigt auf dem Server, was der Container vorher gezeigt hatte: Das
Zurückziehen gibt die Adresse frei, und A9b in der ersten Fassung hätte
`entfernt: 0` gemeldet (Befund 1). „Zuletzt benutzt" blieb auf 19:51:08; eine
abgewiesene Anfrage zählt nicht als Benutzung. A9b über das Konto:

```
weg: Token 2 · Konto 12 · b7-leer
entfernt: 1
API-Tokens: 0
```

Das ist dieselbe Zahl wie in Block 0.

### B0 bis B3 — 11. Oktober, ab 19:58

**B0:** Das zweite Einsetzen wies die Probe ab, „Die Taktprobe läuft schon
(Stand 2026-10-11) …". Den Stand setzt erst das erste Einsetzen; es lief also
die Fassung aus §1.

**B1, Punkt 1 — erfüllt.** Die beiden Klicks ergaben `Seite bei 65.1s —
/updates/refresh` und `Seite bei 67.6s — /updates/refresh`, jede gefolgt von
`Nachladen — packages,packagesError`. `location.pathname` sagte `'/updates'`,
die Pakettabelle stand darunter, und oben standen zwei Streifen, Vorgang 1096
und 1097. `Nachladen — runningOperations` kam bei 68.3 und 71.3 s, genau
3,0 s auseinander. **Bei 71.3 s waren beide fertig**, spätestens 6,2 s nach
dem ersten Klick (Befund 3). Beide standen auf grünem Grund, „Seite
aktualisieren" und „ansehen" nebeneinander: Befund 5 aus §6a, bei 1440 px.

**B2, Punkt 2 — erfüllt, mit angehaltener Schlange.** `inactive`, dann zwei
Auffrischungen, 1098 und 1099, beide `— wartet`. Von 547.8 bis 571.8 s kam
alle 3,0 s eine Nachfrage. Nach dem Wechsel zu `/customers` bei 571.9 s kam
die nächste bei 575.0 s, nach dem Wechsel zu `/subscriptions` bei 590.9 s die
nächste bei 593.9 s. **Bei 574.8 und 593.0 s steht keine Zeile:** Der alte
Takt hörte mit dem Wechsel auf, und nirgends liegen zwei Nachfragen enger als
3 s. Auf allen drei Seiten standen dieselben beiden Streifen mit `— wartet`.

**B3, Punkt 3 — erfüllt.** `active`, und die Nachfrage bei 617.9 s brachte
„fertig". Danach kam 45 Sekunden lang keine Zeile, und „Seite aktualisieren"
gab genau eine, `Seite bei 663.3s — /subscriptions`. `vorgaenge 4` zeigte die
vier Auffrischungen mit Konto 1, `succeeded` und `100 %`. **Eine
Auffrischung brauchte 2 bis 4 Sekunden**; am 31. August waren es 12
(`docs/92 §6`). 1098 und 1099 warteten 68 s in der angehaltenen Schlange und
liefen danach in je 2 s.

**Der erste Durchgang stützt B3.** Nach dem Ende von 1096 und 1097 kam bis zum
Beginn von B2 bei 537.4 s keine Nachfrage mehr. Dazwischen stehen drei
Seitenwechsel mit je genau einer Zeile, 98.2 s `/customers`, 99.8 s `/domains`
und 246.0 s `/subscriptions`. Die ersten beiden fielen in die zwei Minuten, in
denen der fertige Streifen noch dasteht. Auch ein Streifen, der nur fertige
Vorgänge zeigt, fängt also keinen Takt an.

### B4 — eine Hälfte erfüllt, die andere auf dem Server nicht gemessen

Gefahren in drei Durchgängen mit angehaltener Schlange, Vorgang 1100, 1104 und
1108. Auf dem Server lief jeder wie erwartet: 1100 wartete 44 s und 1108 32 s,
und jeder lief nach dem Start in 2 s durch. Auf `/updates` fragte der Streifen
dabei alle 3,0 s nach, im ersten Durchgang bei 1043.4, 1046.4 und 1049.4 s.

**Die erste Hälfte ist erfüllt.** In der ganzen Sitzung steht keine Zeile
`Strom`, von B0 bis B6 über rund eine halbe Stunde und über `/updates`,
`/customers`, `/domains` und `/subscriptions`. Das gilt auch für `/updates`,
wo der Streifen einen wartenden Vorgang zeigte. Gelesen ist es am Ende mit dem
Filter `/Seite|Strom/` in der Konsole.

**Die zweite Hälfte ist auf dem Server nicht gemessen.** In keinem Durchgang
steht eine Zeile `Seite … /operations/<n>`; die Probe hat die Vorgangsseite
nie gesehen. Auf die Frage, was ein Klick auf „ansehen" tut, hat der
Betreiber geantwortet, dass die Seite wechselt. Danach hat er entschieden, die
Hälfte als nicht gemessen einzutragen (Befund 4).

Im Container ist der Klick am selben Abend nachgestellt, gegen den Stand von
`rc.17`, mit einem wartenden Vorgang im Streifen, bei 1440 px und in vier
Lagen. In allen vier stand danach `/operations/8` in der Adresse:

- **Maus, ohne Probe:** keine Zeile, weil keine Probe lief.
- **Maus, mit Taktprobe:** `Seite bei 0.5s — /operations/8` und `Strom bei
  0.5s — /operations/8/stream`.
- **Touch** und **Touch als Mobilgerät:** dieselben beiden Zeilen, der Strom
  bei 0.6 s.

„ansehen" hat dort einen Kasten von 67 × 21 px, und das x beginnt 5 px
rechts davon. An allen fünf gemessenen Punkten des Verweises liegt „ansehen"
selbst oben. Der Klick wechselt also die Seite, und die Vorgangsseite öffnet
genau einen Strom.

### B5 und B6 — erfüllt

Nach dem Ende von 1108 um 20:27:28 reihte `srvpanel vhost` um 20:27:56 die
Vorgänge 1109 bis 1111 ein, alle `web.logrotate.apply` mit `Konto —`, fertig
um 20:27:57. In den beiden Durchgängen davor waren es 1101 bis 1103 und 1105
bis 1107, 23 und 9 s nach dem Ende der Auffrischung.

- **B5, bei 1798.4 s, etwa 20:28:25:** Auf „Abonnements" zeigte der Streifen
  nur `Vorgang 1108 … — fertig` und keinen der drei. Sie waren 28 Sekunden alt
  und hätten nach ihrem Alter dagestanden, filterte der Streifen nicht nach dem
  Konto. Neben ihrer Abwesenheit stand der eigene Vorgang in derselben Hülle.
- **B6, bei 1899.9 s, etwa 20:30:06:** Auf „Kunden" stand kein Streifen mehr,
  158 Sekunden nach dem Ende.

### B8 — erfüllt, 12. Oktober

Vorgang 1113, angelegt um 08:28:44 und fertig um 08:28:50, ohne angehaltene
Schlange; „Abonnements" bei 390 × 844 neu geladen. `bilder-messen.js` im Stand
vom 6. September gab `dokument=0`, Gegenprobe `200 (soll 200)`, `schiebt=0`
und `rollt=0`, und die Zeile aus §4:

```
122 px · ansehen 1 Zeile(n)
```

Das ist auf das Pixel die Höhe aus dem Container. Unter `rc.16` waren es dort
146 px und sechs Zeilen. Das Bild zeigt 1113 fertig auf grünem Grund, „Seite
aktualisieren" und „ansehen" nebeneinander rechts unter dem Text und das x
oben rechts. **Damit sind Befund 1 und Befund 5 aus §6a auf dem Server
gesehen.**

### B9 — erfüllt, 12. Oktober

`Werkzeuge: 6 von 6 · 67 Zeilen · 8b56817a615927a6`, die Probe frisch auf
`/updates`, der Speicher vorher `'nichts gemerkt'` und die Schlange
`inactive`. Dann:

- **Vor dem x:** `Seite bei 63.8s — /updates/refresh`, Vorgang 1114 wartet, und
  von 67.0 bis 85.0 s kam alle 3,0 s `Nachladen — runningOperations`.
- **Nach dem x:** Streifen und Hülle waren fort. Die Zeitmarken standen auf
  09:35:25 und 09:35:50, und seit 85.0 s, etwa 09:35:17, kam keine Nachfrage
  mehr. In diesen rund 33 Sekunden wären sonst elf gekommen, und 1114 wartete
  weiter.
- **„Kunden" und ganz neu laden:** kein Streifen, und der Speicher sagte
  `srvpanel.bands.account.1 = ["operation:1114"]`, genau der Vorgang aus dem
  Streifen.
- **Schlange wieder an:** `active`, 1114 `succeeded`, angelegt 09:34:56,
  begonnen 09:37:18, fertig 09:37:20. Er wartete 2 Minuten 22 Sekunden und lief
  zwei.
- **Nach den zwei Minuten:** auf der Übersicht `'nichts gemerkt'` und kein
  Streifen.

Das Bild von „Abonnements" gleich nach dem Ende fehlt; es war eine Zugabe und
ist nicht gesehen. **Das x am Streifen ist damit auf dem Server gesehen:** Es
nimmt den Vorgang weg, danach fragt nichts mehr nach, solange er läuft, das
Wegklicken übersteht Seitenwechsel und Neuladen, und der Speicher vergisst
ihn, sobald er nicht mehr in den Streifen gehört.

### B7 — erfüllt, 14. Oktober

Im privaten Fenster als Konto 6 auf `/subscriptions/140/backups`, die Probe
frisch und die Schlange `inactive`. Dann „Jetzt sichern":

- **Man bleibt auf der Seite.** Genau eine Zeile `Seite bei 53.1s —
  /subscriptions/140/backups`, das Absenden, und `location.pathname` sagte
  `'/subscriptions/140/backups'`.
- **Oben stand „Die Sicherung wird erstellt."**, der Streifen zeigte
  `Vorgang 1115 backup.create — wartet`, und die Liste eine neue Zeile
  `p6-abnahme-invalid-20261014-063228-577abbfa` mit „wird erstellt".
- **Zwei Takte im Gleichschritt, keiner doppelt.** Von 56.6 bis 113.6 s kam
  alle 3 s je eine Zeile `backups` und eine `runningOperations`, in derselben
  oder in der nächsten Zehntelsekunde (Befund 5).
- **Das Ende.** 1115 begann und endete um 08:33:27, nach 58 Sekunden in der
  angehaltenen Schlange. 53.1 s der Probe sind das Anlegen um 08:32:29, also
  ist 113.6 s etwa 08:33:29: Die letzte Nachfrage beider Reihen ist die erste
  nach dem Ende. Danach stand der Streifen auf `— fertig` und die Sicherung auf
  „vorhanden", 3 MB, 7 Dateien.

Nach 113.6 s trägt das letzte Bild keine Zeile mehr. Wann es entstand, steht
nicht darauf; verlangt waren 15 Sekunden Stille davor. `vorgaenge` fehlte
zuerst, weil die SSH-Sitzung neu war, und ist nachgeholt (unten).

### Die Vorgänge des Laufs

Nachgeholt am 14. Oktober in einer neuen Sitzung, nach `. /root/b78-werkzeuge.sh`:

```
Vorgang 1110 · web.logrotate.apply      · Konto — · succeeded · 100 % · angelegt 20:27:56 · begonnen 20:27:57 · fertig 20:27:57
Vorgang 1111 · web.logrotate.apply      · Konto — · succeeded · 100 % · angelegt 20:27:56 · begonnen 20:27:57 · fertig 20:27:57
Vorgang 1112 · system.packages.refresh  · Konto 1  · succeeded · 100 % · angelegt 20:38:05 · begonnen 20:38:06 · fertig 20:38:07
Vorgang 1113 · system.packages.refresh  · Konto 1  · succeeded · 100 % · angelegt 08:28:44 · begonnen 08:28:45 · fertig 08:28:50
Vorgang 1114 · system.packages.refresh  · Konto 1  · succeeded · 100 % · angelegt 09:34:56 · begonnen 09:37:18 · fertig 09:37:20
Vorgang 1115 · backup.create            · Konto 6  · succeeded · 100 % · angelegt 08:32:29 · begonnen 08:33:27 · fertig 08:33:27
```

1110 und 1111 gehören zu B5, 1113 zu B8, 1114 zu B9 und 1115 zu B7. 1112
entstand um 20:38:05, als die Frage aus B4 offen stand, ohne angehaltene
Schlange; ein Bild der Konsole gibt es dazu nicht.

**Zurück bleibt**, was §2 nennt, in den Zahlen dieses Laufs: zehn
Paketauffrischungen (1096 bis 1100, 1104, 1108 und 1112 bis 1114), neun
`web.logrotate.apply` ohne Konto, eine Sicherung von `p6-abnahme.invalid`, die
die Aufbewahrung mitnimmt, und der Grabstein von B7 Leer mit seiner
Kundennummer. API-Tokens gibt es keine.

### Der Befund am Prüfling: der zweite Faktor in fremder Sicht

Neben „Zweiter Faktor" steht auf „Mein Konto" der Verweis „verwalten", auch in
fremder Sicht. Er führt zu `/settings/two-factor`, und
`TwoFactorSetupController` fragt die fremde Sicht nicht. Er ändert das
angemeldete Konto, und in fremder Sicht ist das das Konto des Kunden.

Gemessen am 11. Oktober im Container, durch die echten Routen und mit einem
Wegwerftest, der nicht ins Repo geht; auf dem Server ist nichts angefasst. Der
Betreiber steht in der Sicht eines Kunden ohne zweiten Faktor. Die Ausgabe ist
gekürzt; die Adresse des Kunden und die Kennungen der Konten stehen als
Platzhalter da.

```
Gegenprobe PUT /settings/theme · 403 · Thema des Kunden NULL
GET /settings/two-factor · 200 · Seite Auth/TwoFactorSetup · active false · Geheimnis ja (32 Zeichen) · URI nennt totp/SrvPanel:<Adresse des Kunden>
POST falscher Code · 302 · Fehler „Dieser Code stimmt nicht. Bitte die Uhrzeit des Geräts prüfen." · zweiter Faktor aus
POST richtiger Code · 302 → /settings/two-factor · zweiter Faktor AN · Wiederherstellungscodes in der Kurzmeldung 8
Protokoll · profile.theme.changed · denied · Konto <Betreiber> · handelnd als <Kunde>
Protokoll · auth.two_factor.enabled · success · Konto <Betreiber> · handelnd als <Kunde>
Anmeldung des Kunden mit Passwort · 302 → /two-factor
```

- **Ein Klick auf „verwalten", der QR-Code in der eigenen App und ein Code
  genügen.** Danach hat das Konto des Kunden einen zweiten Faktor, dessen
  Geheimnis nur beim Betreiber liegt. Die acht Wiederherstellungscodes sieht
  der Betreiber und nicht der Kunde, und bei der nächsten Anmeldung wird der
  Kunde nach einem Code gefragt, den er nicht hat.
- **Nachvollziehbar ist es:** Das Protokoll nennt den Betreiber, „handelnd
  als" Kunde.
- **Zurück geht es nur mit einem Code aus derselben App.**
  `TwoFactorSetupController::destroy()` verlangt einen gültigen Code, und eine
  andere Stelle, die den zweiten Faktor eines Kunden zurücksetzt, gibt es im
  Panel nicht.
- **Die Seite widerspricht sich.** Oben steht in fremder Sicht „Das Konto eines
  Kunden lässt sich von hier aus nicht ändern".

**Unter `/settings` ist das die einzige Lücke**, gemessen mit `route:list`.
Ohne Fähigkeit erreicht ein Kunde dort Profil, Passwort, Darstellung,
API-Tokens und den zweiten Faktor. Die ersten vier weisen in fremder Sicht ab,
der zweite Faktor nicht.

**Kein Wächter hat es gesehen, und der nächstliegende konnte es nicht.**
`ImpersonationControlTest` liest die Handlungen, die ein Controller in fremder
Sicht abweist, aus den Aufrufen von `refuseWhileImpersonating()` und hält die
Seite daran: Sie bietet nichts an, was die Tür abweist. Ob die Tür alles
abweist, was sie abweisen müsste, fragt er nicht. Ein Controller, der die
Abweisung nie ruft, kommt in seiner Liste gar nicht vor.

> **Ein Wächter, der die Abweisungen aus ihren Aufrufen liest, sieht keine
> Tür, die gar nicht abweist.**

Die drei Wege, die dem Betreiber vorlagen:

| | Variante | |
|---|---|---|
| **a** | Der zweite Faktor wird behandelt wie Profil, Passwort, Darstellung und API-Token. In fremder Sicht fällt „verwalten" weg, der Zustand „eingerichtet" oder „nicht eingerichtet" bleibt stehen. Anzeigen, Einschalten und Abschalten unter `/settings/two-factor` weisen ab, mit einer Zeile `denied` im Protokoll. Dazu ein Wächter von der Seite der Tür: Jede Route, die ein Kunde ohne Fähigkeit erreicht und die sein Konto ändert, weist in fremder Sicht ab oder steht mit Grund da | **entschieden am 11. Oktober** |
| b | Nur Einschalten und Abschalten weisen ab. Die Seite bliebe erreichbar und zeigte einen QR-Code, mit dem sich nichts anfangen lässt | |
| c | Es bleibt, wie es ist, etwa um einem Kunden beim Einrichten zu helfen. Die Wiederherstellungscodes sähe dann nur der Betreiber, und das Panel hat keinen Weg, sie dem Kunden zu geben | |

Gebaut wird Variante a nach dem Lauf, für `0.9.0-rc.18`. B7 und B8 berührt
sie nicht.

### Die sechs Befunde an der Vorschrift

| | wo | was | gefunden | Stand |
|---|---|---|---|---|
| 1 | Vorschrift | A9b suchte das API-Token über die Adresse `b7-leer@b7.invalid`. Das Zurückziehen gibt sie frei: Das Konto bleibt, seine Adresse wird `NULL`, und A9b hätte `entfernt: 0` gemeldet und das API-Token liegen lassen | vor dem Fahren von A9, im Container durch die echte Route gemessen; auf dem Server bestätigt mit `über die Adresse: 0 · über Konto 12: 1` | über das Konto gefahren; berichtigt, §3 A9 |
| 2 | Vorschrift | A9 las nicht davor. Ein leeres `$T2`, etwa nach einer neuen Sitzung, gibt an `/me` ebenfalls 401, und die 401 nach dem Zurückziehen hätte nichts belegt | als die SSH-Sitzung neu war | mit einer Ablesung davor gefahren; berichtigt, §3 A9 |
| 3 | Vorschrift | B1 rechnete mit rund 25 Sekunden für zwei Auffrischungen, und das war die Zeit für B2. Gemessen sind 2 bis 4 Sekunden je Auffrischung; beide waren fertig vor dem ersten möglichen Wechsel. Die 25 kamen aus den 12 Sekunden vom 31. August (`docs/92 §6`), und im Container bleibt ein Vorgang ohne Arbeiter auf „wartet" | B1 | B2, B4, B9 und B7 mit angehaltener Schlange gefahren; berichtigt, §4 B1 bis B4, B7 und B9 |
| 4 | Vorschrift | B4 sagte „im Streifen sofort „ansehen", solange der Vorgang läuft". „ansehen" ist dort Verweis und Verb zugleich, und der Vorgang lief 2 Sekunden. In drei Durchgängen stand keine Zeile `/operations` in der Konsole, und meine Anweisung kam dreimal gleich zurück | B4, nach dem dritten Durchgang | die zweite Hälfte nicht gemessen, entschieden vom Betreiber; berichtigt, §4 B4 |
| 5 | Vorschrift | B7 erwartete zwei Takte „im Wechsel". Gemessen laufen sie im Gleichschritt, denn beide beginnen mit dem Aufbau der Seite nach dem Absenden. Die Erwartung stand nicht in der Vorabmessung (§7), und B7 sagte das nicht | B7 | berichtigt, §4 B7 |
| 6 | Vorschrift | §2 zählte „mit B8 vier" Paketauffrischungen. B9 kam mit §6b dazu, und seine fehlte in der Zählung | beim Schreiben dieses Protokolls | berichtigt, §2 |

### Beobachtungen, keine Befunde

1. **A7 lief in anderer Reihenfolge, und das Ergebnis stimmt.** Das Bild der
   Rückfrage zeigt sie für `b7-leer` im Konto von B7 Leer; `b7-abnahme` war um
   12:48:03 schon entfernt, von Konto 6. Gezeigt ist damit an `b7-leer`, dass
   „Abbrechen" nichts schickt.
2. **A8 lief zuerst auf dem Telefon und dann am Schreibtisch.** Auf dem Telefon
   gibt es keine Konsole; die Zeile mit `Spalten 4` und die Zahlen am x kamen
   am Abend dazu.
3. **„6 issues" auf dem eigenen „Mein Konto" des Betreibers, „No issues" in
   fremder Sicht.** Die Zahl passt zu den sechs Feldern von „Konto" und
   „Passwort ändern", die in fremder Sicht fehlen. Die Meldung gehört zu
   Chromes Ausfüllhilfe und ist seit dem 23. August als kein Befund
   entschieden (`docs/76`, `docs/126`).
4. **A9, B9 und B7 liefen in neuen SSH-Sitzungen.** Die Werkzeuge kamen als
   Datei mit: `Werkzeuge: 6 von 6 · 67 Zeilen · 8b56817a615927a6` stand in A9
   und B9 wieder da. In B7 fehlte `vorgaenge`, bis die Datei eingelesen war.
   Der Klartext in `$T2` überlebt keine Sitzung, und A9 lief mit dem Klartext,
   den der Betreiber noch hatte.
5. **Probe und Betreiber gehen bei „ansehen" auseinander.** Die Probe sah in
   der ganzen Sitzung keine Vorgangsseite, der Betreiber sagt, die Seite
   wechselt, und im Container wechselt sie in vier Lagen. Wo sie beim
   Betreiber wechselte, war die Probe nicht dabei, etwa weil ein Klick mit
   gehaltener Taste einen neuen Tab öffnet.
6. **„Entfernen" steht in der Karte eines API-Tokens auf dem Telefon
   eingerückt.** Das ist das leere `::before` einer Zelle ohne Beschriftung,
   bekannt seit dem 28. September (CLAUDE.md, „Karten neben einer
   mehrzeiligen Zelle").

### Was der Lauf über sich selbst gelernt hat

**Eine Abfrage suchte über ein Merkmal, das der Schritt davor freigibt.** A9b
fand das API-Token über die Adresse des Kunden, und das Zurückziehen setzt
genau diese Adresse auf `NULL`. Gescheitert wäre die Abfrage nicht; sie hätte
`entfernt: 0` gemeldet, also dasselbe wie für einen Bestand ohne API-Token.

> **Eine Abfrage über ein Merkmal, das der Schritt davor freigibt, findet
> danach nichts — und meldet das wie einen Bestand, in dem nichts war.**

Und die 401 danach hätte allein nichts belegt, denn ein leeres `$T2` gibt
dieselbe. Erst die `200` davor macht sie zu einer Messung.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

**Die Dauer kam aus einem anderen Lauf, und der Container hat den Unterschied
verdeckt.** Die 25 Sekunden waren zwei mal zwölf vom 31. August. Am
11. Oktober brauchte eine Auffrischung 2 bis 4 Sekunden. Im Container bleibt
jeder Vorgang ohne Arbeiter auf „wartet", so lange man will, und B2, B4 und
B9 sind dort in einem Zustand entstanden, der von selbst hält.

> **Eine Erwartung aus einer Messung unter anderen Bedingungen ist eine
> Vermutung, auch wenn sie aus einer Messung stammt.**

> **Ein Prüfstand ohne Arbeiter hält jeden Vorgang offen — und eine
> Vorschrift, die dort entsteht, kennt nicht die Dauer, die der Server ihm
> lässt.**

**Die angehaltene Schlange hat den Zustand gehalten, ohne den Prüfling
anzufassen.** `systemctl stop srvpanel-worker` lässt jeden neuen Vorgang auf
„wartet", und für den Streifen ist ein wartender Vorgang so offen wie ein
laufender (`OperationStatus::open()`). Websites berührt das nicht, nur die
Vorgänge des Panels warten. B2, B4, B9 und B7 liefen so, und jeder Durchgang
endete mit `active`.

> **Ein Zustand, der zwei Sekunden hält, wird nicht schneller gemessen,
> sondern länger hergestellt.**

**Dreimal kam in B4 dieselbe Lücke zurück, und dreimal habe ich dieselbe
Anweisung genauer geschrieben.** Keine Zeile `/operations`, und jedes Mal
dieselbe Vermutung, der Klick sei nicht passiert. Entschieden hat den Punkt
erst die Frage, was beim Klick geschieht, und da hatte der Betreiber ihn
dreimal gefahren.

> **Kommt ein Schritt zweimal mit derselben Lücke zurück, fehlt keine genauere
> Anweisung, sondern die Frage, was beim Fahren geschieht.**

**Eine Erwartung stand in der Vorschrift wie eine gemessene.** Die Einleitung
sagt, jede Erwartung sei eine Zeile aus der Vorabmessung oder sage, warum
nicht. §7 misst die Seite der Sicherungen nicht, und B7 sagte „zwei Takte im
Wechsel", ohne das zu sagen.

> **Eine Zahl neben einer gemessenen Zahl sieht aus wie gemessen.** Hier war
> es ein Wort neben gemessenen Zeilen.

Der sechste Befund ist dieselbe Familie wie viele hier: B9 kam am Vormittag
des 11. Oktober dazu, und §2 zählte die Prüfkörper weiter ohne ihn.

### Die Abnahme

**Steht beim Betreiber**, nach diesem Protokoll. Die tragenden Punkte beider
Kriterien sind erfüllt. Auf dem Server nicht gemessen ist die zweite Hälfte
von B4, eingetragen auf Entscheidung des Betreibers. Der Befund zum zweiten
Faktor berührt keines der beiden Kriterien.

### Was aussteht

- **Variante a für den zweiten Faktor**, für `0.9.0-rc.18`, mit dem Wächter
  von der Seite der Tür. *Gebaut am 14. Oktober (§9); auf dem Server sieht
  sie der Nachlauf dort.*
- **B4, zweite Hälfte:** genau ein Strom auf der Vorgangsseite. Im Container
  gemessen, auf dem Server nicht.
- **B7, das Ende:** Ob nach 113.6 s noch eine Zeile kam, zeigt das letzte Bild
  nur, wenn es mindestens drei Sekunden danach entstand.

---

## §9 · Variante a — gebaut am 14. Oktober 2026

Entschieden am 11. Oktober (§8, „Der Befund am Prüfling"), gebaut nach dem
Lauf für `0.9.0-rc.18`. B7 und B8 berührt es nicht. Gemessen ist der Bau im
Container, durch die echten Routen in PHPUnit und im echten Chromium gegen die
SQLite-Datenbank aus §7 unter `artisan serve`. Auf dem Server gesehen hat ihn
noch niemand; das tut der Nachlauf am Ende dieses Abschnitts.

### Die Tür

`TwoFactorSetupController` weist in fremder Sicht ab, in allen drei Methoden
und jeweils als erste Zeile: `show()` mit `auth.two_factor.viewed`, `store()`
mit `auth.two_factor.enabled` und `destroy()` mit `auth.two_factor.disabled`.
Jede Abweisung schreibt eine Zeile `denied` ins Protokoll und bricht mit 403
ab.

**Dass schon die Anzeige abweist, ist Absicht.** `show()` legt beim ersten
Aufruf ein neues Geheimnis in die Sitzung und zeigt den QR-Code dazu. In
fremder Sicht darf ihn niemand bestätigen, und ein QR-Code, der sich nicht
bestätigen lässt, ist kein Angebot, sondern eine Falle. Die Abweisung steht
vor dem Geheimnis, und `ImpersonationDoorTest` hält, dass danach keins in der
Sitzung liegt.

**Die Abweisung ist ein Trait geworden.** Bis dahin war sie eine private
Methode von `ProfileController`, und der zweite Controller hätte sie
abschreiben müssen. Das wäre eine zweite Fassung derselben Regel gewesen, und
die zweite ist die, die veraltet. Sie steht jetzt in
`App\Http\Controllers\Concerns\RefusesWhileImpersonating`, und beide
Controller benutzen sie.

### Die Seite

Auf „Mein Konto" steht in fremder Sicht neben „Zweiter Faktor" nur noch der
Zustand, „eingerichtet" oder „nicht eingerichtet". „verwalten" steht hinter
`v-if="!props.impersonating"`, und zwar an der Zelle und nicht am Verweis:
Ohne die Zelle hat die Zeile zwei Zellen wie die beiden darunter.

Gemessen im Chromium, als Kunde Eins selbst und als Betreiber in den Sichten
von Kunde Eins (ohne zweiten Faktor) und Kunde Zwei (mit; eingerichtet für die
Messung und danach zurückgenommen):

```
A1 · eigene Sicht · Zeile: Zweiter Faktor | nicht eingerichtet | verwalten · Zellen je Zeile 3/2/2 · verwalten 1
A2 · nach dem Klick: Auth/TwoFactorSetup · /settings/two-factor · active false · Geheimnis 32 Zeichen
B · Kunde Eins · 390 px · light · Zeile: Zweiter Faktor | nicht eingerichtet · Zellen je Zeile 2/2/2 · verwalten 0 · td block · dokument=0 · gegenprobe=200/200 · schiebt=0
B · Kunde Eins · 390 px · dark  · Zeile: Zweiter Faktor | nicht eingerichtet · Zellen je Zeile 2/2/2 · verwalten 0 · td block · dokument=0 · gegenprobe=200/200 · schiebt=0
B · Kunde Eins · 1440 px · light · Zeile: Zweiter Faktor | nicht eingerichtet · Zellen je Zeile 2/2/2 · verwalten 0 · td table-cell · dokument=0 · gegenprobe=200/200 · schiebt=0
B · Kunde Eins · 1440 px · dark  · Zeile: Zweiter Faktor | nicht eingerichtet · Zellen je Zeile 2/2/2 · verwalten 0 · td table-cell · dokument=0 · gegenprobe=200/200 · schiebt=0
B · Kunde Eins · Protokoll seitdem (erwartet: nichts): —
C1 · GET /settings/two-factor in fremder Sicht · 403 · 403 · Kein Zutritt
C2 · Protokoll seitdem: auth.two_factor.viewed · denied · Konto 1 · handelnd als 2
B · Kunde Zwei · 390 px · light · Zeile: Zweiter Faktor | eingerichtet · Zellen je Zeile 2/2/2 · verwalten 0 · td block · dokument=0 · gegenprobe=200/200 · schiebt=0
B · Kunde Zwei · 390 px · dark  · Zeile: Zweiter Faktor | eingerichtet · Zellen je Zeile 2/2/2 · verwalten 0 · td block · dokument=0 · gegenprobe=200/200 · schiebt=0
B · Kunde Zwei · 1440 px · light · Zeile: Zweiter Faktor | eingerichtet · Zellen je Zeile 2/2/2 · verwalten 0 · td table-cell · dokument=0 · gegenprobe=200/200 · schiebt=0
B · Kunde Zwei · 1440 px · dark  · Zeile: Zweiter Faktor | eingerichtet · Zellen je Zeile 2/2/2 · verwalten 0 · td table-cell · dokument=0 · gegenprobe=200/200 · schiebt=0
B · Kunde Zwei · Protokoll seitdem (erwartet: nichts): —
D1 · GET /settings/two-factor in eigener Sicht des Betreibers · 200 · Auth/TwoFactorSetup · /settings/two-factor
D2 · Protokoll seitdem (erwartet: nichts): —
```

- **A1 ist die Gegenprobe zu B.** Dieselbe Abfrage sieht den Verweis, wo er
  steht; die `0` in fremder Sicht ist also eine Messung.
- **`td block` bei 390 px und `table-cell` bei 1440 px ist der Ladebeleg.**
  Ohne Stylesheet stünde beide Male `table-cell`.
- **D1 bindet die Abweisung an die fremde Sicht und nicht an die Seite:**
  Derselbe Betreiber kommt nach „Zurück zur Verwaltung" an dieselbe Adresse,
  und das Protokoll bekommt dabei keine Zeile.

### Der Wächter von der Tür

`ImpersonationDoorTest` fragt, was `ImpersonationControlTest` nicht fragen
kann: ob die Tür alles abweist, was sie abweisen müsste. Die Routen kommen aus
dem Router, und zwar jede, die ein angemeldetes Konto ohne Fähigkeit erreicht
und die schreibt: `auth` ohne `can:`, die Gruppen aufgelöst und abzüglich
dessen, was eine Route ablegt, und eine Methode ausser GET. Gezählt am
14. Oktober sind es neun. In fremder Sicht geht jede davon durch die Tür, mit
leerem Rumpf, und muss 403 geben und eine Zeile `denied` schreiben. Danach
sind Name, Adresse, Passwort, Darstellung, zweiter Faktor und die Zahl der
API-Tokens des Kunden dieselben wie vorher.

Zwei Routen stehen mit Grund als Ausnahme da, in beide Richtungen gehalten:
Eine Ausnahme muss eine schreibende Route ohne Fähigkeit sein, und in fremder
Sicht darf sie nicht abweisen.

| Route | Grund |
|---|---|
| `POST impersonation/stop` | Der Rückweg zur Verwaltung. Wiese er ab, käme der Betreiber nur noch durch Abmelden heraus |
| `POST logout` | Beendet die Sitzung und mit ihr die fremde Sicht; am Konto des Kunden ändert es nichts |

Dazu zwei Fälle über die Anzeige. In fremder Sicht gibt `/settings/two-factor`
403, legt kein Geheimnis in die Sitzung und schreibt
`auth.two_factor.viewed · denied`. In der eigenen Sicht eines Kunden
antwortet dieselbe Seite; ohne diesen Fall bestünde der erste auch dann, wenn
die Seite jedem 403 gäbe.

**Gegen den ausgelieferten Controller gefahren, meldet er genau den Befund**,
und die Anzeige gab dort 200:

```
DELETE settings/two-factor — 302 statt 403
POST settings/two-factor — 302 statt 403
```

Die 302 heisst hier: zuerst geprüft und dann nicht abgewiesen. Der leere Rumpf
scheiterte an der Prüfung des Codes, und eine Frage nach der fremden Sicht gab
es dort nicht.

**Eine Route mit `can:` fragt er nicht.** Was dort in fremder Sicht geschieht,
entscheidet die Policy, und das ist gewollt: Der Wechsel ist dafür da, an den
Abonnements des Kunden zu sehen, was der Kunde sieht.

**Die Auflösung der Mittelschichten gibt es dabei nur einmal.**
`RouteAuthorizationTest` hatte sie schon, mit den drei Fallen aus B7: Gruppen
werden aufgelöst, was eine Route ablegt, fällt weg, und der HTTP-Kernel wird
angefasst, bevor gefragt wird. Sie steht jetzt in
`Tests\Support\ResolvesRouteMiddleware`, und beide Wächter benutzen sie.

> **Ein Wächter, der die Abweisungen aus ihren Aufrufen liest, sieht keine
> Tür, die gar nicht abweist.** Einer, der die Routen beim Router abholt,
> sieht sie.

### `ImpersonationControlTest`, erweitert

Er kannte bis dahin nur Bedienelemente auf den Seiten des Controllers, der
abweist. „verwalten" steht auf „Mein Konto", und diese Seite zeigt
`ProfileController` und nicht der Controller des zweiten Faktors. Drei Dinge
sind dazugekommen:

- **Eine abgewiesene Anzeige.** Für eine Handlung mit GET ist das
  Bedienelement ein Verweis auf ihren Pfad, und der kann auf jeder Seite
  stehen. Gesucht wird deshalb in allen `.vue` unter `resources/js`.
- **Eine Seite, die in fremder Sicht gar nicht erscheint, bietet dort nichts
  an.** Ihre Bedienelemente gelten als nicht angeboten, wenn jede Methode, die
  sie zeigt, abweist. Gemessen wird das am Controller, und eine zweite
  Methode, die dieselbe Seite ohne Abweisung zeigt, nimmt es zurück.
- **Ein Aufruf direkt im Attribut**, wie `@submit.prevent="setup.post('/…')"`
  auf der Seite des zweiten Faktors. Ein eigener Fall prüft den Leser an einem
  Prüfkörper mit zwei Verweisen, einer davon in fremder Sicht verborgen, einem
  Aufruf im Attribut und einem Verweis mit Methode.

Die Untergrenze steht bei acht Handlungen: den fünf vom 11. Oktober und den
drei des zweiten Faktors.

**Ein Eingriff hat dabei zuerst nichts über die Regel gesagt.** Ohne die
Abweisung in `show()` war der Wächter rot, aber an der Untergrenze: sieben
statt acht, und die steht vor der Regel und bricht ab. Ob die Regel selbst die
Bedienelemente der Seite meldet, war damit nicht gezeigt. Der Eingriff dafür
lässt die Zahl bei acht und baut eine zweite Methode, die dieselbe Seite ohne
Abweisung zeigt. Dann meldet die Regel Einschalten und Abschalten.

> **Eine Prüfung hinter einer anderen, die im selben Fall zuerst anschlägt,
> ist keine — sie ist ein Kommentar mit Assertion.** Der Satz steht seit
> „Platte voll" in CLAUDE.md, dort über einen Wächter. Hier betraf er einen
> Eingriff.

### Die Brüche

Zehn neue Eingriffe im Bruchskript, jeder einzeln rot und zurück grün belegt:

- drei an der Tür: Einschalten, Abschalten und die Anzeige ohne Abweisung;
- zwei an den Ausnahmen: der Rückweg ohne Eintrag und ein Eintrag ohne Route;
- einer an der Frage an den Router, der an der Untergrenze beisst;
- zwei an `ImpersonationControlTest`: „verwalten" ohne `v-if` und dieselbe
  Seite ohne Abweisung;
- einer am Leser der Aufrufe im Attribut;
- einer an `ResolvesRouteMiddleware`, der `RouteAuthorizationTest` trifft:
  Was eine Route ablegt, zählt wieder mit.

Ein bestehender Eingriff hat seinen Anker nachgezogen. „Entfernen steht in
fremder Sicht" suchte `<td v-if="!props.impersonating" class="right">`, und
das steht seitdem zweimal auf der Seite. Alle 18 Eingriffe der berührten
Dateien sind einzeln gefahren, und alle beissen.

### Die übrigen Prüfungen

PHPUnit über alles: 4059 Fälle, 21883 Zusicherungen, einer übersprungen und
vier riskant, dieselben vier wie vorher; Rückgabe 0. Dazu Pint, PHPStan über
die geänderten Dateien mit einem Prüfkörper im selben Aufruf,
`npm run types` und `npm run build`, alle ohne Befund.

### Zwei Beobachtungen, keine Befunde

1. **Die abgewiesene Seite sagt nicht, warum.** Wer in fremder Sicht
   `/settings/two-factor` in die Adresszeile tippt, liest „403 · Kein
   Zutritt" und darunter den Satz jeder 403-Seite: „Dieser Bereich gehört
   einer Rolle, die dieses Konto nicht hat." Dass es an der fremden Sicht
   liegt, steht dort nicht, denn die Fehlerseite zeigt die Meldung der
   Abweisung nicht. Hinkommen lässt sich dort nur über die Adresse, weil der
   Verweis fort ist. Nicht gebaut; ob es einen eigenen Satz wert ist,
   entscheidet der Betreiber.
2. **Über „Von" steht bei 390 px eine Lücke.** Im Bereich „Sicherheit" ist
   die Zeile „Von" 63 px hoch und die darüber 40. Beide Zellen sind 21 px
   hoch und stehen 33 px unter der Oberkante ihrer Zeile, in der eigenen
   Sicht genauso wie in der fremden. Die Lücke ist älter als dieser Bau, und
   ihre Ursache ist nicht gesucht.

### Der Nachlauf gegen `0.9.0-rc.18` — ausgeschrieben vor dem Fahren

Am Schreibtisch mit offener Konsole, dazu zwei Blöcke auf dem Server. Er
braucht eine Viertelstunde, und die Werkzeugdatei aus §1 braucht er nicht.
Jede Erwartung ist eine Zeile aus der Messung im Container, im Chromium oder
mit denselben Befehlen gegen die SQLite-Datenbank aus §7.

#### N0 — Die Fassung (Server)

```bash
# N0 · Die installierte Fassung und die drei Abweisungen darin — liest nur
readlink -f /opt/srvpanel/current
grep -nF "refuseWhileImpersonating(\$request, \$audit, 'auth.two_factor" /opt/srvpanel/current/app/Http/Controllers/Auth/TwoFactorSetupController.php
```

**Erwartet:**

```
/opt/srvpanel/releases/0.9.0-rc.18
50:        $this->refuseWhileImpersonating($request, $audit, 'auth.two_factor.viewed');
80:        $this->refuseWhileImpersonating($request, $audit, 'auth.two_factor.enabled');
129:        $this->refuseWhileImpersonating($request, $audit, 'auth.two_factor.disabled');
```

Gegen die Datei aus `rc.17` druckt das `grep` nichts, gemessen.

#### N1 — Fremde Sicht: nur der Zustand (Browser)

Kunden, in der Zeile eines Kunden „Anmelden als", „Wechseln". Dann in der
Leiste „Mein Konto" und in die Konsole:

```js
(() => { const s = [...document.querySelectorAll('section, .section')].find((x) => x.querySelector('h2')?.textContent.trim() === 'Sicherheit'); const z = s && [...s.querySelectorAll('tr')].find((tr) => tr.cells[0]?.textContent.trim() === 'Zweiter Faktor'); return z ? `Zeile: ${[...z.cells].map((td) => td.textContent.replace(/\s+/g, ' ').trim()).join(' | ')} · verwalten ${s.querySelectorAll('a[href="/settings/two-factor"]').length}` : 'keine Zeile „Zweiter Faktor"' })()
```

**Erwartet:** `Zeile: Zweiter Faktor | nicht eingerichtet · verwalten 0`,
oder `eingerichtet`, wenn der Kunde einen zweiten Faktor hat. Unter `rc.17`
stand dort zusätzlich `| verwalten`, und die Zahl war `1`.

#### N2 — Fremde Sicht: die Adresse direkt (Browser, dann Server)

In derselben Sicht `/settings/two-factor` in die Adresszeile. **Erwartet:**
„403" und „Kein Zutritt". Danach mit dem Zurück-Knopf des Browsers auf „Mein
Konto"; das Band der fremden Sicht steht wieder da. Dann auf dem Server:

```bash
# N2 · Die jüngsten Zeilen zum zweiten Faktor im Protokoll — liest nur
srvpanel tinker --execute='
  foreach (DB::table("audit_events")->whereIn("action", ["auth.two_factor.viewed", "auth.two_factor.enabled", "auth.two_factor.disabled"])->orderByDesc("id")->limit(3)->get()->reverse() as $z)
    printf("%s · %s · %s · Konto %s · handelnd als %s\n", App\Support\Time\Clock::displayText($z->created_at), $z->action, $z->result, $z->account_id ?? "—", $z->acting_as_account_id ?? "—");
'
```

**Erwartet** als letzte Zeile, in der Minute von N2:

```
<Zeit> · auth.two_factor.viewed · denied · Konto <Betreiber> · handelnd als <Kunde>
```

Davor können ältere Zeilen stehen, etwa das Einschalten des eigenen zweiten
Faktors, oder keine. Im Container stand dort
`auth.two_factor.viewed · denied · Konto 1 · handelnd als 2`.

#### N3 — Eigene Sicht: Verweis und Seite (Browser, dann Server)

Oben im Band „Zurück zur Verwaltung", dann in der Leiste „Mein Konto" und
dieselbe Zeile aus N1 in die Konsole. **Erwartet:**
`Zeile: Zweiter Faktor | eingerichtet | verwalten · verwalten 1`. Danach
„verwalten" anklicken: Es öffnet sich die Seite „Zweiter Faktor" mit dem
eigenen, eingerichteten Faktor. Zum Schluss N2 noch einmal auf dem Server:
**Erwartet** ist dieselbe letzte Zeile wie in N2 und keine neue.

N3 ist die Gegenprobe zu N1 und N2. Dieselbe Abfrage sieht den Verweis, wo er
steht, und dieselbe Adresse antwortet, sobald die fremde Sicht vorbei ist.

#### N4 — 390 px (darf ausfallen)

In fremder Sicht „Mein Konto" bei 390 px in der Gerätesymbolleiste, frisch
geladen, `tests/bilder-messen.js` aus dem Repository einfügen und
`bilderMessen()`. **Erwartet:** `dokument = 0`, Gegenprobe `200/200`,
`schiebt = 0`, wie im Container in vier Lagen. Ausfallen darf N4, weil die
Zeile nur eine Zelle verliert und keine gewinnt.

#### Wann er durch ist

Wenn N0 bis N3 erfüllt sind. N4 darf ausfallen.
