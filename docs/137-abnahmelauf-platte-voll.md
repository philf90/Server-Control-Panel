# „Platte voll" — der Abnahmelauf

Ausgeschrieben am 27. September 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/136 §7` — sechs Punkte und ein Ausschlusskriterium, alle **an einer eigenen
Wegwerf-Platte im Loop und nie an der Wurzel**:

> 1. Die Übersicht zeigt jede Platte einmal.
> 2. Die Wegwerf-Platte über 85 % — beim dritten Lauf, der sie so sieht,
>    **eine** Meldung an den Betreiber, über beide Kanäle.
> 3. Über 95 % — eine zweite, als Störung; die Warnung bleibt stehen.
> 4. Unter 80 % — die Entwarnung für beide, und der nächste Lauf meldet nichts.
> 5. Inodes: dieselbe Platte ohne freie Inodes ergibt „Inodes voll", während
>    der Platz frei ist.
> 6. „Zuletzt gemessen" für den neuen Lauf steht auf der Diagnoseseite, und der
>    Zeitgeber hat einen nächsten Termin.

Der Plan ist `docs/136`, die Messrunde davor sein §3. Gefahren wird gegen
**`0.9.0-rc.4`**, die erste Freigabe, die „Platte voll" trägt; den Tag setzt der
Betreiber. §1 Block 0 fragt die installierte Fassung, bevor irgendetwas anderes
gemessen wird.

**Der Lauf braucht gut anderthalb Stunden am Stück**, und fast alles davon ist
Warten auf den Zeitgeber (§3). Von Hand angestossen wird dabei nichts — §0
Punkt 7 sagt, warum.

---

## §0 · Was beim Ausschreiben umgefallen ist

**Sieben Zeilen, und keine betrifft den Prüfling.** Zwei schärfen das Kriterium,
zwei berichtigen eine Erwartung, drei sagen, wie gemessen wird.

**1 · „Die Entwarnung für beide" kommt über einen Kanal und nicht über zwei.**
Punkt 2 verlangt die Meldung „über beide Kanäle", Punkt 4 die Entwarnung „für
beide" — gemeint sind beide **Befunde**, Warnung und Störung. Entwarnt wird
allein über den Webhook: `MailChannel` ist kein `ResolvingChannel`, und seine
Zeilen in der Warteschlange löscht der Meldelauf ungelesen (`docs/133` Punkt 8b).
Erwartet wird deshalb eine Entwarnung am Empfänger, **keine** Mail, und danach
eine leere Warteschlange — die Zeile für `mail` wie die für `webhook`.

> **Ein Kriterium, das „beide" sagt, muss sagen, wovon.**

**2 · Der Rückweg hat zwei Bänder, und Punkt 4 springt über beide.** Von über
95 % direkt unter 80 % gefahren, ginge dieselbe Messung auch durch, wenn es gar
keinen Rückweg gäbe — beide Befunde fielen dann nur früher. Gemessen wird er
dort, wo er etwas entscheidet: Bei **88 %** geht die Störung (unter 90) und die
Warnung bleibt (über 85), bei **82 %** bleibt die Warnung, obwohl die Platte
unter ihrer Schwelle liegt, und erst bei **78 %** geht auch sie. Punkt 4 hat
deshalb drei Stufen, und die mittlere ist die, ohne die „Rückweg" eine
Behauptung bleibt.

> **Ein Rückweg, den man nur von ganz oben nach ganz unten fährt, ist nicht
> gemessen** — er sieht dabei genauso aus wie keiner.

**3 · Nach dem Update läuft der Zeitgeber sofort und nicht am nächsten
Fünf-Minuten-Termin.** Gemessen am 27. September unter systemd 255, derselben
Fassung wie auf `cloudsrv24`: Ein frisch eingeschalteter Timer mit
`OnBootSec=3min` löst bei 224 s Uptime nach **0,12 s** aus — einmal gemessen,
und die Streuung von 30 s kam dabei nicht zum Zug —; derselbe Timer ohne
`OnBootSec` wartet auf den Kalender. `postinstall` schaltet
`srvpanel-disk.timer` mit `enable --now` ein, und zwar **nachdem** es den Agenten
neu gestartet hat. §1 Block 0 liest den ersten Lauf deshalb in derselben Minute
ab wie `status installed`. Hier stand zuerst „am nächsten Fünf-Minuten-Termin",
und in der Freigabenotiz beinahe auch.

> **Ein Zeitgeber, dessen Termin beim Einschalten schon verstrichen ist, holt
> ihn nach — und „der nächste Termin" ist dann jetzt.**

**4 · Ob der Agent die Wegwerf-Platte sieht, ist im Container gemessen — und
wird auf dem Server trotzdem vorher nachgesehen.** Der Agent läuft in einer
eigenen Mount-Namespace, entstanden bei seinem Start (`PrivateTmp=yes`,
`ReadOnlyPaths=`). `tests/platte-voll-messen.sh` M7: Eine Unit mit genau dieser
Sandbox sieht eine Einhängung, die nach ihrem Start entsteht, und der echte Leser
liest sie; dieselbe Unit mit `MountFlags=private` sieht sie nicht; ausgehängt ist
sie auch in der Unit fort. `docs/136 §7` verlangt die Messung auf dem Server, und
§2 fährt sie dort — **in der Namespace des Agenten** und nicht in der des
Rechners, sonst misst sie den falschen Weg.

**5 · Punkt 1 hat seine Gegenprobe an Ort und Stelle.** Die Übersicht mit drei
Zeilen für eine Platte sieht nur noch, wer `0.9.0-rc.3` vor dem Update ansieht
(`docs/136 §8`). Der Zustand, den der Leser zusammenfasst, steht aber weiter da:
In der Namespace des Agenten ist die Wurzel mehrfach eingehängt — `/tmp` und
`/var/tmp` durch `PrivateTmp`, `/boot` durch `ReadOnlyPaths=`, wenn es auf der
Wurzel liegt. Im Container gemessen: **vier** Einhängungen der Wurzel roh, **eine**
Zeile gelesen. Ohne die rohe Zahl daneben sagte „einmal" nichts — auch ein
Server ohne Sandbox zeigt jede Platte einmal.

> **Eine Eins ist nur dann eine Zusammenfassung, wenn daneben steht, wovon.**

**6 · Die Füllhilfe rechnet wie der Agent, und das ist gemessen.** Der Agent
zählt die Reserve von root als belegt (`docs/136 §3` M1). Die Wegwerf-Platte wird
deshalb **ohne Reserve** angelegt (`-m 0`), und die Hilfe in §2 rechnet aus
`stat -f` dieselbe Formel: gemessen im Container bei 87, 96, 88, 82 und 78 %, auf
die Zehntelstelle gleich mit dem Leser des Agenten. **An der Wurzel gehen die
beiden Zahlen auseinander** — um die Reserve, auf einem Server mit ext4 also um
etwa fünf Punkte. Im Container waren es 43 % bei `df` gegen 91,6 % beim Agenten,
weil dort das Kontingent der Sitzung dazwischenliegt. §1 druckt für die Wurzel
beide nebeneinander, damit ein Befund an `/` nicht als Überraschung kommt.

**7 · „Beim dritten Lauf" lässt sich nur an Läufen ablesen, die niemand von Hand
anstösst.** `srvpanel disk` misst und meldet nicht; ein Lauf von Hand zählte als
Lauf mit und setzte `first_seen_at`, ohne dass danach jemand sagen kann, der
wievielte der meldende war. Zwischen dem Füllen und dem Ablesen wird deshalb
**nichts** gestartet. Gelesen wird aus dem Journal der Unit und aus der Ablage —
`srvpanel tinker` misst nichts, und `system.filesystems` fragt den Agenten, ohne
einen Befund zu schreiben.

---

## §1 · Vorbedingungen — gemessen und nicht angenommen

**Alles in einem Block, und jede Zeile druckt, was sie gefunden hat.** Ein
`srvpanel tinker`, das gar nichts druckt, ist kein leeres Ergebnis, sondern ein
nicht gelaufener Block (`docs/133 §1`); jeder Block unten beginnt deshalb mit
einer Zeile, die immer dasteht.

```bash
# 0 · Welche Fassung läuft — und lief der Zeitgeber beim Einschalten sofort?
srvpanel version
systemctl cat srvpanel-disk.timer | grep -E '^(OnBootSec|OnCalendar|Persistent|RandomizedDelaySec|AccuracySec)='
printf 'Timer: %s, %s\n' "$(systemctl is-enabled srvpanel-disk.timer)" "$(systemctl is-active srvpanel-disk.timer)"
grep ' status installed srvpanel:' /var/log/dpkg.log | tail -1
journalctl -u srvpanel-disk.service -o short-iso --no-pager | grep -m1 -E 'Starting|Started'
systemctl list-timers srvpanel-disk.timer --no-pager

# 1 · Die Meldewege aus B1: Relay, Ziel des Webhooks, Empfänger der Mail
srvpanel tinker --execute='
  printf("Relay eingerichtet: %s\n", app(App\Support\Settings\Settings::class)->mail()->usable() ? "ja" : "NEIN");
  $ziel = app(App\Support\Notify\NotifyTarget::class)->describe();
  printf("Ziel des Webhooks: %s\n", $ziel === null ? "KEINES" : json_encode($ziel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  foreach (App\Models\Account::operators()->get() as $a) printf("Betreiber: %s\n", $a->email);
'

# 2 · Der Ausgangsbestand an den Dateisystemen — aus der Ablage gelesen, nicht gemessen
srvpanel tinker --execute='
  $b = App\Models\Finding::withoutGlobalScopes()->where("check", "disk.space")->orderBy("subject")->get();
  printf("Befunde disk.space: %d\n", $b->count());
  foreach ($b as $f) printf("  %-24s %-14s %s\n", $f->subject, $f->reason, $f->detail);
  printf("Warteschlange der Entwarnungen: %d\n", App\Models\FindingResolution::query()->count());
'

# 3 · Jede Platte, wie der Agent sie sieht — und die Wurzel daneben, wie df sie sieht
srvpanel tinker --execute='
  $p = app(SrvPanel\Agent\Client::class)->call("system.filesystems")["filesystems"];
  printf("Platten laut Agent: %d\n", count($p));
  foreach ($p as $r) printf("  %-20s %-16s %-5s Platz %5.1f %%   Inodes %s\n", $r["mount"], $r["device"], $r["type"], $r["percent"],
      $r["inodes"] === null ? "—" : sprintf("%5.1f %%", $r["inodes"]["percent"]));
'
df -h / | tail -1
```

**Erwartet:** Fassung `0.9.0-rc.4` oder später; aus der Unit genau
`OnBootSec=3min`, `OnCalendar=*:0/5`, `Persistent=true`,
`RandomizedDelaySec=30` und `AccuracySec=1s`; der Timer `enabled` und `active`.
Die Zeile aus `dpkg.log` und der erste `Starting` der Unit liegen **in derselben
Minute** (§0 Punkt 3), und `list-timers` nennt einen nächsten Termin höchstens
gut fünf Minuten entfernt.

In Block 1 `Relay eingerichtet: ja`, ein Ziel mit `host`, `provider`,
`stored_at` und `"signed":true` — ohne Adresse und ohne Geheimnis —, und
mindestens ein Betreiber mit Adresse. In Block 2 `Befunde disk.space: 0` und
`Warteschlange der Entwarnungen: 0`. In Block 3 jede Platte einmal und die Zahl
der Platten darüber; für `/` liegt der Wert des Agenten um die Reserve über
`df` (§0 Punkt 6).

**Fehlt in Block 1 das Relay oder das Ziel, ist Punkt 2 nicht fahrbar** — er
verlangt beide Kanäle. Eingerichtet werden sie wie in B1: das Relay auf
`/settings/mail`, das Ziel auf `/settings/notices` (`docs/133` Punkt 1).

**Steht in Block 2 schon ein Befund, gehört er zum Ausgangsbestand** und nicht
zum Lauf — etwa eine Wurzel oder ein kleines `/boot` über 85 %. Der Lauf geht
dann trotzdem, aber jede Ablesung unten filtert auf `/mnt/sp-platte`, und die
Zahlen des Meldelaufs enthalten diesen Befund nicht: Er ist gebucht, bevor der
Lauf beginnt. Eine Warteschlange über null wird vor dem Lauf nicht geleert —
sie gehört in die Notiz, und der erste Meldelauf nimmt sie mit.

**Solange noch `0.9.0-rc.3` läuft**, ist ein Blick auf die Übersicht der
Vorher-Zustand zu Punkt 1: Stehen `/`, `/tmp` und `/var/tmp` als drei Zeilen
derselben Platte da? Nach dem Update ist er nicht mehr herzustellen.

> **Was behoben ist, lässt sich nicht mehr kaputt vorführen.**

---

## §2 · Der Empfänger und die Wegwerf-Platte

### Der Empfänger des Webhooks

**Derselbe wie in B1** (`docs/133 §2`): eine `index.php` unter einer Domain mit
gültigem Zertifikat, die jeden Rumpf mit Zeitpunkt und Signatur in eine Datei
unter `tmp/` des Abonnements schreibt. Steht er nicht mehr, wird er von dort neu
angelegt, und das Ziel auf `/settings/notices` zeigt auf ihn.

```bash
# Die beiden Werte aus docs/133 §2 — dort sind sie gewählt, hier nur eingesetzt
HAKEN='<domain des Empfängers>'
LOG='/var/www/vhosts/<abonnement>/tmp/haken.log'

printf 'Empfängerprotokoll: %s — %s Zeile(n)\n' "$LOG" "$(wc -l < "$LOG" 2>/dev/null || echo FEHLT)"
ZEILEN=$(wc -l < "$LOG")
# Gegenprobe, dass der Empfänger annimmt — sonst misst jeder Punkt unten den Empfänger
curl -sS -o /dev/null -m 10 -w '%{http_code}\n' -X POST -d '{"probe":"platte-voll"}' "https://$HAKEN/haken/"
printf 'Empfängerprotokoll: %s -> %s Zeile(n)\n' "$ZEILEN" "$(wc -l < "$LOG")"
```

**Erwartet:** eine Zeilenzahl, `204` und eine Zeile mehr. Der `host` aus §1
Block 1 ist `$HAKEN`; zeigt das Ziel woandershin, landen die Meldungen dort und
nicht in diesem Protokoll.

### Die Wegwerf-Platte

**64 MiB in einer Datei auf der Wurzel, eingehängt unter `/mnt`.** Nicht unter
`/tmp` oder `/var/tmp`: Die gehören in der Namespace des Agenten `PrivateTmp`,
und was darunter hängt, liegt dort verdeckt. Angelegt **ohne Reserve** (`-m 0`,
§0 Punkt 6) und mit **1024 Inodes** (`-N 1024`), damit Punkt 5 sie in Sekunden
erschöpft. Die Datei wächst nur mit dem, was hineingeschrieben wird, und nie
über 64 MiB — das ist das Ausschlusskriterium, und `df` misst es davor und in
Punkt 7 danach.

```bash
BILD=/root/sp-platte.img
PLATTE=/mnt/sp-platte

df -h / | tail -1
truncate -s 64M "$BILD"
mkfs.ext4 -q -F -m 0 -N 1024 "$BILD"
mkdir -p "$PLATTE"
mount -o loop "$BILD" "$PLATTE"
printf 'Rechner sieht: %s\n' "$(findmnt -rn -o TARGET,SOURCE,FSTYPE "$PLATTE" || echo FEHLT)"

# Sieht der Agent sie? Gefragt wird seine Namespace und nicht die des Rechners (§0 Punkt 4).
AGENT=$(systemctl show -p MainPID --value srvpanel-agentd.service)
printf 'Agent %s sieht: %s\n' "$AGENT" "$(nsenter -t "$AGENT" -m -- findmnt -rn -o TARGET,SOURCE "$PLATTE" || echo FEHLT)"
```

**Erwartet:** zweimal `/mnt/sp-platte /dev/loopN`, dieselbe Nummer. **Scheitert
schon `mount -o loop`**, ist der Lauf auf dieser Maschine nicht herstellbar —
die Wurzel wird dafür nicht gefüllt. **Sieht der Rechner die Platte und der
Agent nicht**, ist das ein Befund und kein Ausfall: Eine Platte, die nach dem
Start des Agenten dazukommt, bliebe dann bis zu seinem Neustart unbeobachtet.
Er gehört ins Protokoll, und der Lauf geht nach
`systemctl restart srvpanel-agentd.service` weiter.

**Die Werkzeuge des Laufs** — einmal in die Sitzung, in der gefahren wird:

```bash
# Die Belegung, gerechnet wie der Agent: Was ein Nicht-root nicht mehr schreiben kann, gilt als belegt.
belegung() {
  stat -f -c '%b %a %c %d' "$PLATTE" \
    | awk '{ printf "Hilfe: Platz %.1f %%   Inodes %.1f %%\n", ($1 - $2) / $1 * 100, ($3 - $4) / $3 * 100 }'
}

# Auf eine Belegung in ganzen Prozent füllen — eine Datei, jedes Mal neu angelegt.
fuellen() {
  rm -f "$PLATTE/fuellung"; sync
  read -r b a s <<<"$(stat -f -c '%b %a %S' "$PLATTE")"
  ziel=$(( b * s * $1 / 100 - (b - a) * s ))
  [ "$ziel" -gt 0 ] && fallocate -l "$ziel" "$PLATTE/fuellung"
  belegung
}

# Was der Agent über die Platte sagt — über den echten Socket, ohne einen Befund zu schreiben.
agent() {
  srvpanel tinker --execute='
    printf("Agent:");
    foreach (app(SrvPanel\Agent\Client::class)->call("system.filesystems")["filesystems"] as $r)
        if ($r["mount"] === "/mnt/sp-platte")
            printf(" Platz %.1f %%   Inodes %s", $r["percent"], $r["inodes"] === null ? "—" : sprintf("%.1f %%", $r["inodes"]["percent"]));
    printf("\n");
  '
}

# Die Ablage: die Befunde der Platte, ihre Buchungen und die Warteschlange.
ablage() {
  srvpanel tinker --execute='
    $b = App\Models\Finding::withoutGlobalScopes()->where("check", "disk.space")
        ->where("subject", "/mnt/sp-platte")->orderBy("reason")->get();
    printf("Befunde: %d\n", $b->count());
    foreach ($b as $f) printf("  %-13s %-9s seit %s   %s\n", $f->reason, $f->state()->label(), $f->first_seen_at, $f->detail);
    printf("Buchungen: %d   Warteschlange: %d\n",
        App\Models\FindingNotification::query()->whereIn("finding_id", $b->pluck("id"))->count(),
        App\Models\FindingResolution::query()->where("subject", "/mnt/sp-platte")->count());
  '
}

# Die Läufe seit einem Zeitpunkt, aus dem Journal der Unit: je Lauf ein `Finished`.
laeufe() {
  journalctl -u srvpanel-disk.service --since "$1" -o short-iso --no-pager \
    | grep -E 'Finished|sp-platte|Nachricht|Entwarnung|Keine Befunde'
}

# Was beim Empfänger ankam, seit der Zähler gesetzt wurde.
empfang() {
  printf 'Empfängerprotokoll: %s -> %s Zeile(n)\n' "$ZEILEN" "$(wc -l < "$LOG")"
  tail -n +"$((ZEILEN + 1))" "$LOG" | cut -f3 | cut -c1-400
}

belegung; agent
```

**Erwartet:** `Hilfe: Platz` um 2 % und `Inodes` um 1 %, und `Agent:` mit
**denselben** Zahlen. Weichen die beiden ab, rechnet die Hilfe nicht wie der
Agent, und jede Stufe unten zielte daneben — dann wird hier aufgehört und nicht
geschätzt.

---

## §3 · Der Zeitplan

**Gerechnet aus der Unit und nicht geschätzt.** Der Zeitgeber feuert alle fünf
Minuten, jeder Lauf bis zu 31 Sekunden nach seinem Termin (`RandomizedDelaySec`
plus `AccuracySec`). Eine Meldung wartet acht Minuten ab dem ersten Lauf, der den
Befund sieht, also auf den dritten; eine Entwarnung wartet auf nichts.

| Punkt | was geschieht | wartet höchstens |
|---|---|---|
| §1, §2 | Vorbedingungen, Empfänger, Platte | — |
| 1 | ablesen | — |
| 2 | 87 % — Meldung beim dritten Lauf | gut 16 min (331 s bis zum ersten, 631 s bis zum dritten) |
| 3 | 96 % — die Störung kommt dazu | gut 16 min |
| 4a | 88 % — die Störung geht | gut 5 min |
| 4b | 82 % — nichts geschieht | gut 5 min |
| 4c | 78 % — die Warnung geht | gut 5 min, und ein Lauf danach |
| 5 | Inodes voll | gut 16 min |
| 5b | Inodes frei — die Entwarnung | gut 5 min |
| 6 | ablesen | — |
| 7 | abräumen | gut 5 min |

**Zusammen gut anderthalb Stunden.** Eine Pause zwischen zwei Punkten kostet
nichts: Jeder Punkt setzt seinen eigenen Zeitpunkt `T`, und bis zur nächsten
Stufe ändert sich an der Platte nichts.

---

## §4 · Die Punkte

### Punkt 1 · Die Übersicht zeigt jede Platte einmal

```bash
W=$(findmnt -n -o SOURCE /)
echo "roh — die Einhängungen der Wurzel in der Namespace des Agenten:"
nsenter -t "$AGENT" -m -- findmnt -rn -o TARGET,SOURCE | awk -v d="$W" '$2 == d || index($2, d "[") == 1'
echo "gelesen — was der Agent daraus macht:"
srvpanel tinker --execute='
  foreach (app(SrvPanel\Agent\Client::class)->call("system.filesystems")["filesystems"] as $r)
      printf("  %-20s %-16s %5.1f %%\n", $r["mount"], $r["device"], $r["percent"]);
'
```

**Erwartet:** roh **mindestens drei** Zeilen für die Wurzel — `/`, `/tmp` und
`/var/tmp` (die beiden als `…[/tmp/systemd-private-…]`), dazu `/boot`, wenn es
auf der Wurzel liegt. Gelesen steht die Wurzel **einmal** da und
`/mnt/sp-platte` auch; jedes Gerät hat genau eine Zeile. Auf der Übersicht im
Bereich der Dateisysteme dieselben Einhängepunkte, keiner doppelt — ein
Bildschirmfoto gehört dazu.

**Steht roh nur eine Zeile da**, läuft der Agent ohne `PrivateTmp`, und die
Gegenprobe fehlt; der Punkt ist dann erfüllt, aber nicht belegt — er gehört so
ins Protokoll.

### Punkt 2 · Über 85 %: eine Meldung beim dritten Lauf, über beide Kanäle

```bash
T=$(date '+%Y-%m-%d %H:%M:%S'); echo "T = $T"
ZEILEN=$(wc -l < "$LOG")
fuellen 87; agent
```

**Dann warten — gut sechzehn Minuten, und nichts von Hand starten** (§0 Punkt
7). Danach:

```bash
laeufe "$T"
ablage
empfang
```

**Erwartet — und das ist der Kern des Laufs:**

- `fuellen` und `agent` sagen beide `Platz 87.0 %`.
- Im Journal **drei** Läufe mit `Finished`. Jeder druckt
  `Auffällig: /mnt/sp-platte — 87,0 % belegt.`; der **erste** und der **zweite**
  danach `mail: 0 Nachricht(en) über 0 Befund(e).` und
  `webhook: 0 Nachricht(en) über 0 Befund(e).`, der **dritte**
  `mail: 1 Nachricht(en) über 1 Befund(e).` und
  `webhook: 1 Nachricht(en) über 1 Befund(e).` Ein vierter, falls er schon da
  ist, wieder zweimal `0`.
- Zwischen dem ersten und dem dritten `Finished` liegen **rund 570 bis 630
  Sekunden** — das Band aus `DiskCadenceTest`, hier einmal an der Wirklichkeit
  abgelesen.
- Ein Lauf, der kurz vor `T` begann und erst danach endet, druckt keine Zeile
  zur Platte — er hat vor dem Füllen gemessen. Er steht mit seinem `Finished`
  in der Ausgabe und zählt nicht; gezählt wird ab dem ersten Lauf, der die
  Platte bei 87 % sieht.
- `ablage`: **ein** Befund, `space_tight`, `Auffällig`, seit dem ersten der drei
  Läufe; `Buchungen: 2` (eine je Kanal), `Warteschlange: 0`.
- `empfang`: **eine** neue Zeile mit `"kind":"findings"`,
  `"subject":"/mnt/sp-platte"` und einem Eintrag `"reason":"space_tight"`,
  `"state":"warn"`, `"detail":"87,0 % belegt."`.
- Im Postfach des Betreibers **eine** Mail, Betreff
  `SrvPanel — ein neuer Befund auf <Rechner>`, darin der Satz
  „Das Dateisystem wird eng: gewarnt ab 85 %, entwarnt unter 80 %." und
  `87,0 % belegt.`
- Auf der Übersicht steht der Balken der Platte in der Farbe der Warnung — die
  Schwelle der Seite ist die der Prüfung.

**Meldet schon der zweite Lauf, ist die Haltezeit zu kurz; meldet erst der
vierte, zu lang** — beides ist ein Befund am Prüfling und genau der, den
`DiskCadenceTest` aus der Unit nachrechnet. Liegen erster und dritter Lauf
ausserhalb des Bandes, stimmt der Zeitgeber nicht mit seiner Unit überein
(`systemctl show srvpanel-disk.timer -p AccuracyUSec -p RandomizedDelayUSec`).

### Punkt 3 · Über 95 %: die Störung kommt dazu, die Warnung bleibt

```bash
T=$(date '+%Y-%m-%d %H:%M:%S'); echo "T = $T"
ZEILEN=$(wc -l < "$LOG")
fuellen 96; agent
du -h "$BILD"
```

Gut sechzehn Minuten, dann `laeufe "$T"`, `ablage`, `empfang`.

**Erwartet:**

- Jeder Lauf druckt **zwei** Zeilen: `Kaputt: /mnt/sp-platte — 96,0 % belegt.`
  und `Auffällig: /mnt/sp-platte — 96,0 % belegt.` Der dritte meldet
  `mail: 1 Nachricht(en) über 1 Befund(e).` und
  `webhook: 1 Nachricht(en) über 1 Befund(e).` — **ein** Befund, denn die
  Warnung ist seit Punkt 2 gebucht.
- `ablage`: **zwei** Befunde — `space_full`, `Kaputt`, seit dem ersten Lauf nach
  `T`, und `space_tight`, `Auffällig`, **mit demselben `seit` wie in Punkt 2**.
  Das ist „die Warnung bleibt stehen": nicht neu entstanden, sondern nie fort.
  `Buchungen: 4`.
- `empfang`: eine Zeile mit `"reason":"space_full"`, `"state":"fail"`.
- Eine zweite Mail, wieder `ein neuer Befund`, mit dem Satz über die fast volle
  Platte und MariaDB.
- `du` höchstens `64M` — die Datei auf der Wurzel ist nicht grösser als die
  Platte, die sie trägt.

### Punkt 4 · Der Rückweg in drei Stufen

**4a · 88 % — die Störung geht, die Warnung bleibt.**

```bash
T=$(date '+%Y-%m-%d %H:%M:%S'); echo "T = $T"
ZEILEN=$(wc -l < "$LOG")
fuellen 88; agent
```

Gut fünf Minuten — eine Entwarnung wartet auf keine Haltezeit —, dann
`laeufe "$T"`, `ablage`, `empfang`.

**Erwartet:** Der erste Lauf nach `T` druckt nur noch
`Auffällig: /mnt/sp-platte — 88,0 % belegt.`, danach
`mail: 0 Nachricht(en) über 0 Befund(e).`,
`webhook: 0 Nachricht(en) über 0 Befund(e).` und
`webhook: 1 Entwarnung(en) verschickt.` `ablage`: **ein** Befund,
`space_tight`, mit dem `seit` aus Punkt 2; `Buchungen: 2`, `Warteschlange: 0`.
`empfang`: eine Zeile mit `"kind":"resolved"` und `"reason":"space_full"`.
**Keine Mail** (§0 Punkt 1).

**4b · 82 % — die Warnung bleibt, obwohl die Platte unter ihrer Schwelle
liegt.**

```bash
T=$(date '+%Y-%m-%d %H:%M:%S'); echo "T = $T"
ZEILEN=$(wc -l < "$LOG")
fuellen 82; agent
```

Gut fünf Minuten, dann `laeufe "$T"`, `ablage`, `empfang`.

**Erwartet:** `Auffällig: /mnt/sp-platte — 82,0 % belegt.`, keine Nachricht und
keine Entwarnung. `ablage` wie in 4a, `seit` unverändert. `empfang`: keine neue
Zeile. **Das ist die Messung des Rückwegs** (§0 Punkt 2): Ohne ihn verschwände
der Befund hier, und die nächste Überschreitung begänne mit einem neuen `seit`.

**Und die Übersicht färbt den Balken hier nicht mehr** — sie zeigt den
Augenblick gegen die Schwelle, die Ablage den Befund mit seinem Rückweg. Beides
ist so gebaut und kein Widerspruch; es gehört als Beobachtung ins Protokoll.

**4c · 78 % — die Warnung geht, und der nächste Lauf meldet nichts.**

```bash
T=$(date '+%Y-%m-%d %H:%M:%S'); echo "T = $T"
ZEILEN=$(wc -l < "$LOG")
fuellen 78; agent
```

Gut fünf Minuten **und ein Lauf mehr**, dann `laeufe "$T"`, `ablage`,
`empfang`.

**Erwartet:** Der erste Lauf nach `T` druckt keine Zeile mehr zu
`/mnt/sp-platte` — ohne Ausgangsbestand `Keine Befunde an den Dateisystemen.` —
und `webhook: 1 Entwarnung(en) verschickt.`; der zweite druckt weder eine
Nachricht noch eine Entwarnung. `ablage`: `Befunde: 0`, `Buchungen: 0`,
`Warteschlange: 0`. `empfang`: **genau eine** neue Zeile, `"kind":"resolved"`,
`"reason":"space_tight"`.

### Punkt 5 · Voll an Inodes, bei freiem Platz

```bash
rm -f "$PLATTE/fuellung"; belegung
T=$(date '+%Y-%m-%d %H:%M:%S'); echo "T = $T"
ZEILEN=$(wc -l < "$LOG")

# In einer Unterschale: Scheitert die Umleitung, endet sonst die ganze Shell (CLAUDE.md, dash).
i=0; while ( : > "$PLATTE/i$i" ) 2>/dev/null; do i=$((i + 1)); done
echo "$i Dateien angelegt, die nächste: $( ( : > "$PLATTE/i$i" ) 2>&1 )"
belegung; agent
df -h "$PLATTE" | tail -1
```

Gut sechzehn Minuten, dann `laeufe "$T"`, `ablage`, `empfang`.

**Erwartet:**

- Rund tausend Dateien — im Container waren es **1013** —, und die nächste
  scheitert mit `No space left on device`, während `df` Platz frei zeigt.
- `belegung` und `agent`: `Platz` um 2 %, `Inodes 100.0 %`.
- Jeder Lauf druckt
  `Kaputt: /mnt/sp-platte — 100,0 % der Inodes vergeben, 0 frei.` und
  `Auffällig: /mnt/sp-platte — 100,0 % der Inodes vergeben, 0 frei.`; der dritte
  `mail: 1 Nachricht(en) über 2 Befund(e).` und
  `webhook: 1 Nachricht(en) über 2 Befund(e).`
- `ablage`: **zwei** Befunde, `inodes_full` und `inodes_tight`; **keiner** zum
  Platz. `Buchungen: 4`.
- Die Mail trägt ihren Betreff jetzt in der **Mehrzahl** —
  `SrvPanel — 2 neue Befunde auf <Rechner>` —, und `empfang` zeigt **eine**
  Zeile mit **zwei** Einträgen: Der Webhook bündelt je Einhängepunkt, die Mail
  je Lauf.

**5b · Abgeräumt — die Entwarnung.**

```bash
T=$(date '+%Y-%m-%d %H:%M:%S'); echo "T = $T"
ZEILEN=$(wc -l < "$LOG")
rm -f "$PLATTE"/i*; belegung
```

Gut fünf Minuten, dann `laeufe "$T"`, `ablage`, `empfang`.

**Erwartet:** keine Zeile mehr zu `/mnt/sp-platte`,
`webhook: 1 Entwarnung(en) verschickt.` — **eine** Entwarnung für beide Befunde,
dieselbe Bündelung wie beim Melden —, `ablage` dreimal `0`, und `empfang` eine
Zeile `"kind":"resolved"` mit zwei Einträgen.

### Punkt 6 · Die Diagnoseseite und der nächste Termin

```bash
systemctl list-timers srvpanel-disk.timer --no-pager
systemctl show srvpanel-disk.timer -p LastTriggerUSec -p NextElapseUSecRealtime
journalctl -u srvpanel-disk.service -o short-iso --no-pager | grep Finished | tail -1
```

**Erwartet:** ein `NEXT` höchstens gut fünf Minuten entfernt, `LastTriggerUSec`
auf dem letzten Lauf. Auf `/diagnose` der Satz
„Dateisysteme zuletzt nachgesehen: …" mit der Uhrzeit des letzten `Finished` —
in der Anzeigezone des Panels, also ohne den Versatz, den `short-iso` mitdruckt.
Ein Bildschirmfoto gehört dazu.

**Die Seite nennt jetzt drei Zeitpunkte**, Nacht, Sicherungen und Dateisysteme.
Stünde der dritte auf „noch nicht nachgesehen", liefe der Zeitgeber, ohne dass
sein Lauf seinen Zeitpunkt schreibt — derselbe Fall, den `DiagnoseWiringTest`
hält.

### Punkt 7 · Die Maschine bleibt, wie sie war

```bash
# Gezählt wird vor dem Löschen: Ohne die Datei sagt `losetup -j` rc=0 und druckt
# nichts — eine Null danach käme von dort genauso (gemessen).
printf 'Loop-Geräte auf dem Bild, eingehängt: %s\n' "$(losetup -j "$BILD" | wc -l)"
umount "$PLATTE"
printf 'Loop-Geräte auf dem Bild, ausgehängt: %s\n' "$(losetup -j "$BILD" | wc -l)"
rmdir "$PLATTE" && rm -f "$BILD"
T=$(date '+%Y-%m-%d %H:%M:%S'); echo "T = $T"
ZEILEN=$(wc -l < "$LOG")
```

Gut fünf Minuten, dann:

```bash
laeufe "$T"
ablage
empfang
printf 'Agent sieht die Platte noch: %s\n' \
    "$(nsenter -t "$AGENT" -m -- findmnt -rn -o TARGET /mnt/sp-platte || echo nein)"
df -h / | tail -1
```

**Erwartet:** eingehängt `1`, ausgehängt `0` — `mount -o loop` gibt sein Gerät
beim Aushängen selbst frei, gemessen im Container; der Lauf danach druckt keine Zeile
zu `/mnt/sp-platte` und keine Nachricht; `ablage` dreimal `0`; `empfang` keine
neue Zeile; der Agent sieht die Platte nicht mehr (§0 Punkt 4, die Gegenrichtung
aus M7); und die Wurzel steht, wo sie in §2 stand — bis auf das, was der Server
in anderthalb Stunden ohnehin schreibt.

**Der Empfänger aus B1 bleibt stehen**, wie er vorgefunden wurde; das Ziel auf
`/settings/notices` auch.

> **Ein Abnahmelauf, der eine Einhängung stehen lässt, hat den Server
> schlechter zurückgegeben, als er ihn vorgefunden hat** — und die nächste
> Diagnose meldet eine Platte, die niemand kennt.

---

## §5 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Die Wurzel und jede Platte, auf der etwas läuft.** Das ist das
  Ausschlusskriterium. Die Prüfung rechnet je Einhängepunkt gleich; was eine
  volle Wurzel anrichtet, ist im Container gemessen (`docs/136 §3` M5) und nicht
  hier.
- **Den Mailweg bei voller Platte** (`docs/136 §8`) — er hängt am Relay, und die
  Wegwerf-Platte trägt kein Postfach.
- **`unreachable`**: Ein angehaltener Agent ergibt einen Befund für `/` und die
  Einhängepunkte mit Befund. Das halten `DiskVerdictTest` und die Wächter der
  Diagnose; im Kriterium steht es nicht.
- **Andere Dateisystemarten.** btrfs meldet `0` Inodes und bekommt über Inodes
  kein Urteil (`DiskReaderTest`); gemessen wird hier ext4.
- **Die Streuung über viele Läufe.** Jeder Punkt ist **eine** Stichprobe; das
  Band aus Punkt 2 wird einmal abgelesen und muss einmal passen.
- **RAM und Load** — zurückgestellt (`docs/136 §1`).

---

## §6 · Wann er durch ist

**Wenn die Punkte 1 bis 7 erfüllt sind und die Wurzel nie gefüllt wurde.**
Punkt 4 ist erst mit allen drei Stufen erfüllt; 4b allein ist der Beleg für den
Rückweg.

**Nicht ausfallen dürfen die Punkte 2 und 5.** Punkt 2 ist die Zusage, für die
es den eigenen Lauf gibt — gemeldet beim dritten Lauf, über beide Kanäle —, und
Punkt 5 der Zustand, den der Platz allein nicht zeigt. Als „nicht herstellbar"
darf nur ausfallen, was an der Maschine scheitert und nicht am Prüfling:
`mount -o loop` in §2, dann der ganze Lauf.

**Was ein Befund ist und kein Ausfall:** ein Agent, der die Platte erst nach
einem Neustart sieht (§2); ein Band in Punkt 2, das nicht passt; ein
Ausgangsbestand, der den Meldelauf mitzählen lässt. Jeder davon gehört ins
Protokoll, und der Lauf geht weiter.

**Das Protokoll wird als §7 an dieses Dokument angehängt**, wie bei `docs/134`:
je Punkt die gemessenen Zeilen, die Zeitpunkte der drei Läufe aus Punkt 2 und
ihr Abstand, und was davon abwich.
