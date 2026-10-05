# B5 — der Abnahmelauf für die Meldungen an den Kunden

Ausgeschrieben am 5. Oktober 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/129 §9`:

> Ein Kunde, dessen Kontingent überschritten wird, bekommt genau eine Mail —
> und der Betreiber sieht, dass sie zugestellt wurde.

Gebaut ist B5 seit dem 21. September (`8ab8488c`) und ausgeliefert seit
`v0.9.0-rc.1`: die Prüfung `quota.exceeded` im Nachtlauf (`QuotaOverrun`), die
Kundenmail `QuotaWarning` über den Mailkanal aus B1 und eine Haltezeit von
zwanzig Stunden vor der ersten Meldung (`Notices::HOLD_HOURS`).

**Beim Vorabmessen im Container sind sechs Befunde am Prüfling herausgefallen**
(§0, Befunde 1 bis 6), beim Ausschreiben ein siebter (Befund 7) und eine Grenze,
an der diese Vorschrift selbst gescheitert wäre (§0, Punkt 8). Die Wächter von
B5 waren grün. Gezeigt haben sich die Befunde an einer Mail, die im Container
wirklich gerendert und verschickt wurde, mit zwei Befunden und drei Konten.
**Der Betreiber hat die vier Fragen dazu am selben Tag entschieden, alle wie
vorgeschlagen** (§6), und behoben sind die Befunde für `0.9.0-rc.13` (§6a).
Befund 7 ist nach dem Vorbild von „Platte voll" gebaut und nicht gefragt
worden; §6 sagt, wo ihm widersprochen werden kann.

**Gefahren wird gegen `0.9.0-rc.13`**, die Freigabe, die die Behebungen trägt.
Gegen `rc.12` mässe der Lauf die Befunde und nicht ihre Behebung.

**Der Lauf braucht zwei Tage**, weil das Kriterium an der Haltezeit hängt:
Teil 1 stellt den Zustand her und setzt den Zeitpunkt T0, Teil 2 misst ab
T0 + 20 h die Mail und die Seite, Teil 3 füllt den Platz ganz und räumt danach
ab (§2).

---

## §0 · Was beim Ausschreiben umgefallen ist

### Die Mail vor der Behebung

Gerendert und verschickt im Container gegen den Stand von `main`
(`1d9aa2c2`), durch den echten Mailkanal: ein Abonnement mit Datenbanken über
dem Kontingent (3 MB von 1 MB), ein Speicherplatz, dessen Kontingent unter den
Verbrauch gesetzt war (3 MB von 2 MB), und drei aktive Konten des Kunden. Der
Betreff lautete `SrvPanel — Kontingent überschritten: p6-abnahme.invalid`, und
alle drei Adressen standen in **einer** Zeile `To`:

```
Guten Tag,

für Ihr Abonnement p6-abnahme.invalid ist ein Kontingent überschritten:

- Die Datenbanken dieses Abonnements liegen zusammen über ihrem Kontingent.: 3 MB von 1 MB
- Der belegte Platz liegt über dem Kontingent des Plans.: 3 MB von 2 MB

Diese Kontingente werden gemessen und nicht erzwungen — es wird nichts
abgeschaltet und nichts gesperrt. Die Zahlen stehen mit ihrem Verlauf der
letzten dreissig Tage auf der Seite Ihres Abonnements im Panel.

Beim Verkehr zählt diese Zahl, was der Webserver protokolliert hat. Die
Abrechnung Ihres Anbieters kann höher liegen: Er zählt TCP, TLS und
Wiederholungen mit.

Sie bekommen diese Nachricht einmal je Überschreitung. Sinkt der Wert wieder
unter das Kontingent, meldet sich das Panel erst wieder, wenn es erneut
darüber liegt.

--
SrvPanel
```

**Ohne Befund getragen hat**, im selben Prüfstand gemessen: die Haltezeit (bei
T0 + 19 h 59 min nichts, bei T0 + 20 h die Mail), eine Mail je Abonnement auch
bei zwei Befunden, keine zweite beim nächsten Lauf, nichts an ein gesperrtes
Konto, und vor der Unterschrift die Leerzeile und `-- ` aus `docs/140 §6e`.

### Die sieben Befunde

1. **Die Zeilen waren verunglückt.** Auf den Punkt des Satzes folgte ein
   Doppelpunkt und dahinter der Wert („Kontingent.: 3 MB"). Die erste Zeile war
   **90 Zeichen** lang, gezählt nach Zeichen; ein Mailprogramm bricht sie, wo es
   will. Und „ein Kontingent" stand vor zwei Zeilen.

2. **Ein Satz war für den Speicherplatz falsch.** „Gemessen und nicht
   erzwungen — es wird nichts abgeschaltet und nichts gesperrt" stimmt für
   Datenbanken und Traffic. Den Platz erzwingt die Dateisystem-Quota, und in
   genau der Lage, über die diese Mail schreibt, scheitern die Schreibzugriffe
   der Website. Der Absatz über die Abrechnung des Traffics stand auch in
   Mails, in denen es um Traffic gar nicht ging.

   > **Eine Mail, die einen Satz für alle Fälle hat, hat ihn für einen davon
   > falsch.**

3. **Beim Speicherplatz kam die Mail nie, wenn sie gebraucht wurde.**
   `DiskQuota::apply()` setzt weiche und harte Grenze auf denselben Wert, und
   `repquota` zählt in ganzen MB, abgerundet. Der Verbrauch erreicht das
   Kontingent also höchstens; die Prüfung fragte nach „darüber". Gemeldet wurde
   deshalb nur, wenn der Betreiber ein Kontingent **unter** den Verbrauch
   herabsetzte, und nie, wenn ein Kunde seinen Platz füllte — also genau dann
   nicht, wenn seine Website nicht mehr schreiben kann.

   > **Ein Kontingent, das erzwungen wird, wird nicht überschritten — es wird
   > erreicht, und die Meldung gehört davor.**

4. **Die Mail ging an zu viele Empfänger, und alle standen offen
   nebeneinander.** Sie ging an jedes aktive Konto des Kunden, auch an
   Zusatzbenutzer, denen das Abonnement gar nicht zugewiesen ist, und alle
   Adressen standen in einer einzigen Zeile `To`.

   **Auf `cloudsrv24` lässt sich dieser Befund nicht herstellen.** Kein
   Formular des Panels legt einen Zusatzbenutzer oder ein zweites Kundenkonto
   an; ein Kunde hat dort genau das eine Konto, mit dem er angelegt wurde. Der
   Befund ist deshalb ein schlafender, und gemessen ist seine Behebung im
   Container (`QuotaRecipientTest`, §4).

   Beim Beheben fiel heraus, warum „je Empfänger eine Mail" eine eigene
   Instanz braucht: **Eine Mailable sammelt Empfänger.** `Mail::to()` hängt sie
   über `setAddress()` an, statt sie zu ersetzen — dieselbe Instanz zweimal
   verschickt ginge beim zweiten Mal an beide.

5. **Ob eine bestimmte Mail angekommen war, sah der Betreiber nicht.** Er sah
   je Kanal „Zuletzt erfolgreich zugestellt", also nicht, welche Mail an
   welchen Kunden ging. Und ein Kunde ohne Konto mit Adresse bekam nie etwas;
   das stand nur im Journal.

6. **Der Hinweis am Traffic-Kontingent nannte die falsche Seite.** Seit P1
   stand dort „Die Überschreitung erscheint in der Übersicht"; erschienen ist
   sie seit B5 auf der Seite „Diagnose" und nie in der Übersicht.

   > **Eine Zusage im Hinweistext ist eine Zusage.**

7. **Ein voller Platz entwarnte seine Vorwarnung** — gefunden beim
   Ausschreiben dieses Laufs, an der Behebung von Befund 3. Gebaut waren zwei
   Gründe für den Platz, „fast ausgeschöpft" ab 95 % und „ausgeschöpft" an der
   Grenze, und sie schlossen einander aus. Erreichte der Platz die Grenze,
   löste der schwerere Befund den leichteren ab, und der Lauf schloss die
   Vorwarnung:

   - Das Meldeziel bekam „erledigt" für die Vorwarnung in dem Augenblick, in
     dem die Website nicht mehr schreiben konnte.
   - Wer danach Platz freiräumte, bekam die Vorwarnung als **neuen** Befund
     und nach der Haltezeit eine zweite Mail.

   > **Ein Befund, den ein schwererer ablöst, ist nicht erledigt — und wer ihn
   > dabei schliesst, meldet eine Entwarnung für einen Zustand, der schlimmer
   > geworden ist.**

   Das Vorbild stand im Repo: Bei „Platte voll" sind Warnung und Störung zwei
   Befunde mit je eigenem Rückweg (`DiskSpace`, entschieden am
   27. September). Gebaut ist es jetzt genauso (§6a).

### Und eine Grenze, an der die Vorschrift gescheitert wäre

8. **Das Kontingent „Speicherplatz" nimmt nichts unter 64 MB**
   (`Quota::minimum()`). Der Entwurf dieses Laufs rechnete mit einem
   Kontingent von 40 MB, weil 5 % davon zwei ganze MB sind. Das Formular hätte
   die Zahl abgewiesen, und der Lauf wäre am ersten Speichern stehengeblieben.
   Gerechnet wird mit **80 MB**: 5 % sind 4 MB, und jede Stufe unten ist eine
   ganze Zahl.

   > **Ein Prüfkörper, der durch ein Formular muss, wird gegen die Regeln des
   > Formulars gemessen — und nicht gegen die Rechnung, die ihn bequem macht.**

---

## §1 · Die Werkzeuge und die Vorbedingung

### Block H — die Werkzeuge

**In jeder Sitzung zuerst einfügen.** Die Funktionen schreiben nichts, solange
sie nur definiert werden. Geschrieben wird erst, wenn `fuellen` gerufen wird,
und das steht unten jedes Mal in einem eigenen Block, der es sagt.

`belegt` und `hart` lesen dieselbe Zeile wie der Agent: `repquota -u -O csv`
auf dem Gerät von `/var/www/vhosts`, vierte und sechste Spalte, in KiB. Das
Panel zählt daraus `KiB / 1024`, abgerundet. `fuellen N` legt die Füllung so
an, dass der Platz in der **Mitte** des N-ten MB steht; ein paar Zeilen mehr
in einem Protokoll verschieben das Ergebnis dann nicht.

`HAKEN` und `LOG` sind der Empfänger des Webhooks aus `docs/137 §2`. Gibt es
ihn nicht mehr, bleiben die beiden Zeilen stehen, wie sie sind, und der Lauf
geht ohne die Webhook-Teile (§5).

```bash
# H · Werkzeuge für diesen Lauf — definiert nur; geschrieben wird erst beim Aufruf von fuellen
BEN=p1139
D=$(findmnt -n -o SOURCE --target /var/www/vhosts)
HAKEN='<domain des Empfängers>'                   # wie in docs/137 §2
LOG='/var/www/vhosts/<abonnement>/tmp/haken.log'   # wie in docs/137 §2

# Belegter Platz und harte Grenze von p1139 in KiB — dieselbe Zeile, die der Agent liest
belegt() { repquota -u -O csv "$D" | awk -F, -v b="$BEN" '$1 == b { print $4 }'; }
hart()   { repquota -u -O csv "$D" | awk -F, -v b="$BEN" '$1 == b { print $6 }'; }

# Die Füllung so anlegen, dass p1139 in der Mitte des N-ten MB steht — schreibt
fuellen() {
  local ohne kib jetzt
  rm -f /var/www/vhosts/p6-abnahme.invalid/tmp/b5-fuellung.bin
  ohne=$(belegt)
  [ -n "$ohne" ] || { echo "p1139 steht nicht in repquota — nichts gefüllt"; return 1; }
  kib=$(( $1 * 1024 + 512 - ohne ))
  runuser -u "$BEN" -- fallocate -l "$(( kib * 1024 ))" /var/www/vhosts/p6-abnahme.invalid/tmp/b5-fuellung.bin || return 1
  jetzt=$(belegt)
  printf 'Ziel %s MB · ohne Füllung %s KiB · Füllung %s KiB · belegt %s KiB = %s MB · hart %s KiB\n' \
    "$1" "$ohne" "$kib" "$jetzt" "$(( jetzt / 1024 ))" "$(hart)"
}

# Messen, Diagnose und Meldelauf wie in der Nacht — und was die drei dabei sagen
lauf() {
  local start; start=$(date '+%F %T')
  echo "Lauf ab $start"
  systemctl start srvpanel-usage.service
  systemctl start srvpanel-diagnose.service
  journalctl -u srvpanel-usage.service -u srvpanel-diagnose.service --since "$start" --no-pager -o cat \
    | grep -E 'geschrieben;|gefahren,|Nachricht\(en\)|Entwarnung|ohne Empfänger|nicht eingerichtet|scheiterte|nicht durchgelaufen'
}

# Die Befunde des Abonnements und ihre Buchungen, aus der Ablage gelesen — misst nichts
befunde() {
  srvpanel tinker --execute='
    $abo = app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(fn () => App\Models\Subscription::query()->where("name", "p6-abnahme.invalid")->first());
    printf("Platz laut Panel: %s MB, gemessen %s\n", $abo?->disk_used_mb ?? "—", App\Support\Time\Clock::display($abo?->disk_usage_measured_at) ?? "nie");
    $f = App\Models\Finding::query()->with("notifications")->where("check", "quota.exceeded")->where("subject", "p6-abnahme.invalid")->orderBy("reason")->get();
    printf("Befunde zu p6-abnahme.invalid: %d\n", $f->count());
    foreach ($f as $b) {
      printf("  %-15s %-26s seit %s · fällig ab %s · zuletzt %s\n", $b->reason, $b->detail, App\Support\Time\Clock::display($b->first_seen_at), App\Support\Time\Clock::display(App\Support\Notify\Notices::dueAt($b)), App\Support\Time\Clock::display($b->measured_at));
      foreach ($b->notifications->sortBy("channel") as $n) printf("      gemeldet über %-7s %s\n", $n->channel, App\Support\Time\Clock::display($n->notified_at));
    }
    printf("Entwarnungen, die noch ausstehen: %d\n", App\Models\FindingResolution::query()->where("subject", "p6-abnahme.invalid")->count());
  '
}

# Die Mail, wie der Kanal sie aus den Befunden baut — verschickt nichts
kundenmail() {
  srvpanel tinker --execute='
    $f = App\Models\Finding::query()->where("check", "quota.exceeded")->where("subject", "p6-abnahme.invalid")->orderBy("reason")->get()->all();
    if ($f === []) {
      echo "Keine Befunde zu p6-abnahme.invalid — es gibt keine Mail.\n";
    } else {
      $m = new App\Mail\QuotaWarning("p6-abnahme.invalid", (new ReflectionMethod(App\Support\Notify\MailChannel::class, "overruns"))->invoke(null, $f));
      $t = $m->render();
      $l = array_map("mb_strlen", explode("\n", $t));
      printf("Betreff: %s\nZeilen: %d · die längste: %d Zeichen · Punkt mit Doppelpunkt: %d\n---\n%s", $m->envelope()->subject, count($l), max($l), substr_count($t, ".:"), $t);
    }
  '
}

# Die letzte Zeile des Empfängers — was der Webhook zuletzt gebracht hat
haken() {
  printf 'Empfängerprotokoll: %s Zeile(n)\n' "$(wc -l < "$LOG")"
  tail -n 1 "$LOG" | cut -f3- | jq -c '{kind: .event.kind, subject: .event.subject, gruende: [.event.findings[].reason], seit: [.event.findings[].since]}'
}
```

**`lauf` fährt die beiden Units, aus denen ein Nachtlauf schöpft**, in der
Reihenfolge, in der sie zusammengehören: erst die Messung des Platzes und der
Datenbanken (`srvpanel-usage.service`, sonst alle fünfzehn Minuten), dann die
Diagnose samt Meldelauf (`srvpanel-diagnose.service`, zwei `ExecStart`).
Beide sind `Type=oneshot`; `systemctl start` kehrt erst zurück, wenn der Lauf
fertig ist, und seine Zeilen stehen danach im Journal.

**Steht in `befunde` ein anderer Platz als das Ziel**, hat der Zeitgeber der
Messung (alle fünfzehn Minuten) gerade selbst gemessen, bevor die Füllung lag,
und `systemctl start` ist in diesen Lauf eingestiegen. Dann `lauf` noch einmal.

### Block 0 — vor dem Lauf

```bash
# 0 · Fassung, Abonnement, Empfänger, Meldewege und Quota vor dem Lauf — liest nur
srvpanel version
srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(function () {
    $abo = App\Models\Subscription::query()->where("name", "p6-abnahme.invalid")->first();
    if ($abo === null) { echo "Abonnement p6-abnahme.invalid: FEHLT\n"; return; }
    printf("Abonnement %d · %s · %s · Plan %s\n", $abo->id, $abo->system_user, $abo->status->value, $abo->plan?->name ?? "—");
    printf("Platz: %s MB belegt, gemessen %s · Kontingent %s MB\n", $abo->disk_used_mb ?? "—", App\Support\Time\Clock::display($abo->disk_usage_measured_at) ?? "nie", $abo->quota("disk_mb") ?? "—");
    printf("Datenbanken: %s MB · Kontingent %s MB · Übersteuerungen %s\n", $abo->databaseUsedMb() ?? "—", $abo->quota("database_mb") ?? "—", json_encode($abo->quota_overrides ?: new stdClass));
    $m = fn (string $a): string => mb_substr($a, 0, 1)."…@".mb_substr((string) strstr($a, "@"), 1, 1)."…".strrchr($a, ".");
    foreach ($abo->customer->accounts()->orderBy("id")->get() as $k) printf("Konto %d · %s · %s · %s · sieht das Abonnement: %s\n", $k->id, $k->type->value, $k->status->value, $m($k->email), $k->mayAccessSubscription($abo) ? "ja" : "nein");
    $an = (new ReflectionMethod(App\Support\Notify\MailChannel::class, "customerAddresses"))->invoke(app(App\Support\Notify\MailChannel::class), $abo->name);
    printf("Empfänger der Kundenmail: %d — %s\n", count($an), implode(", ", array_map($m, $an)));
  });
'
srvpanel tinker --execute='
  $s = app(App\Support\Settings\Settings::class);
  printf("Marke: %s · Relay eingerichtet: %s\n", $s->brand()->name, $s->mail()->usable() ? "ja" : "NEIN");
  $ziel = app(App\Support\Notify\NotifyTarget::class)->describe();
  printf("Ziel des Webhooks: %s\n", $ziel === null ? "KEINES" : json_encode($ziel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  $offen = App\Models\Finding::query()->with("notifications")->orderBy("check")->orderBy("subject")->get()->filter(fn ($f) => App\Support\Notify\Notices::dueAt($f) !== null && $f->notifications->where("channel", "mail")->isEmpty());
  printf("Befunde, die noch eine Mail bekommen: %d\n", $offen->count());
  foreach ($offen as $f) printf("  %s · %s · %s · fällig ab %s\n", $f->check->value, $f->subject, $f->reason, App\Support\Time\Clock::display(App\Support\Notify\Notices::dueAt($f)));
'
printf 'Gerät: %s · p1139 belegt %s KiB, hart %s KiB\n' "${D:-KEINES}" "$(belegt)" "$(hart)"
ls -la /var/www/vhosts/p6-abnahme.invalid/tmp/ | grep -c 'b5-'
befunde
```

**Erwartet**, Zeile für Zeile:

```
0.9.0-rc.13
Abonnement 140 · p1139 · active · Plan <Name>
Platz: 3 MB belegt, gemessen <Zeit> · Kontingent 5120 MB
Datenbanken: — MB · Kontingent <Wert des Plans> MB · Übersteuerungen {}
Konto <n> · customer · active · <x>…@<y>….<tld> · sieht das Abonnement: ja
Empfänger der Kundenmail: 1 — <x>…@<y>….<tld>
Marke: <Name> · Relay eingerichtet: ja
Ziel des Webhooks: {"host":"<domain des Empfängers>","provider":"generic",…,"signed":true}
Befunde, die noch eine Mail bekommen: 0
Gerät: /dev/<…> · p1139 belegt <um 3000> KiB, hart 5242880 KiB
0
Platz laut Panel: 3 MB, gemessen <Zeit>
Befunde zu p6-abnahme.invalid: 0
Entwarnungen, die noch ausstehen: 0
```

- **Die Adressen sind gekürzt**, auf ihren ersten Buchstaben, den ersten der
  Domain und die Endung: Diese Ausgabe geht ins Protokoll. Wer liest, weiss,
  welches Postfach gemeint ist, und **muss es lesen können** — sonst ist
  Punkt 4 nicht messbar. Ist es keines, das der Betreiber liest, wird die
  Anmeldeadresse des Kundenkontos vorher auf eines gestellt, das er liest:
  angemeldet als dieser Kunde unter `/settings/profile`, mit dessen Passwort.
- **`Empfänger der Kundenmail: 0`** heisst: Der Lauf meldet `ohne Empfänger`,
  und Punkt 4 fällt aus. Dann zuerst ein Konto mit Adresse.
- **`Relay eingerichtet: NEIN`** hält den Lauf auf; eingerichtet wird es auf
  `/settings/mail` wie in B1.
- **`Ziel des Webhooks: KEINES`** hält ihn nicht auf. Die Webhook-Teile fallen
  dann aus (§5), und `lauf` druckt `webhook: nicht eingerichtet — …`.
- **`Befunde, die noch eine Mail bekommen` über 0**: Diese Befunde gehören zum
  Ausgangsbestand, und wenn sie in einen Lauf unten fallen, zählt die Zeile
  `mail: N Nachricht(en)` sie mit. Sie werden abgeschrieben. Entscheidend für
  die Punkte ist dann `befunde` und nicht die Zahl.
- **Die `0` unter der Gerätezeile** zählt Dateien `b5-*` in `tmp/`; eine `1`
  wäre der Rest eines abgebrochenen Laufs.

**Gegenprobe des Empfängers**, wie in `docs/137 §2` — sie schreibt eine Zeile
in sein Protokoll und sonst nichts:

```bash
# 0b · Nimmt der Empfänger an? — schreibt eine Zeile in sein Protokoll
ZEILEN=$(wc -l < "$LOG")
curl -sS -o /dev/null -m 10 -w '%{http_code}\n' -X POST -d '{"probe":"b5"}' "https://$HAKEN/haken/"
printf 'Empfängerprotokoll: %s -> %s Zeile(n)\n' "$ZEILEN" "$(wc -l < "$LOG")"
```

**Erwartet:** `204` und eine Zeile mehr.

---

## §2 · Die Prüfkörper und der Zeitplan

### Der Zeitplan

| Teil | Wann | Was |
|---|---|---|
| 1 | Tag 1, **T0 nicht vor 05:00** | §1, Prüfkörper anlegen, Punkte 1 und 2 |
| — | die Nacht dazwischen | der Nachtlauf zwischen 00:00 und 01:00 meldet **nichts** |
| 2 | Tag 2, ab T0 + 20 h | Punkte 3 bis 6 |
| 3 | Tag 2, danach | Punkte 7 bis 9, Rückweg |

**T0 ist der Lauf von Punkt 2**, und an ihm hängt alles danach. Der
Nachtlauf (`srvpanel-diagnose.timer`, `OnCalendar=daily`,
`RandomizedDelaySec=1h`) feuert zwischen 00:00 und 01:00. Liegt T0 nach 05:00,
liegt T0 + 20 h nach 01:00 am Tag 2, und der Nachtlauf kommt sicher **vor**
der Haltezeit. Das ist Punkt 3: Er belegt, dass die Mail nicht früher kommt.
Ein T0 um 04:00 machte aus dem Nachtlauf den meldenden, und Punkt 3 hätte
nichts zu messen.

**Zwischen Teil 1 und Teil 2 wird `p6-abnahme.invalid` nicht angefasst** — kein
Hochladen, kein Löschen, keine Änderung am Abonnement.

### Die Prüfkörper

Alle am Abonnement **`p6-abnahme.invalid`** (`/subscriptions/140`,
Systembenutzer `p1139`), dem Prüfstand der B-Läufe. Nicht an `p6-b.invalid`:
Dort misst `docs/138` Teil 2 im Oktober, und ein enges Kontingent hielte die
Protokolle an, die er zählt.

| Prüfkörper | Wo | Was er herstellt |
|---|---|---|
| Kontingent „Speicherplatz" **80 MB** | Übersteuerung am Abonnement | die Grenze, gegen die Punkte 1, 2 und 7 rechnen |
| Kontingent „Datenbankgröße" **1 MB** | Übersteuerung am Abonnement | den zweiten Befund in derselben Mail |
| Datenbank `b5` (`p1139_b5`) | im Panel angelegt, ohne Zugang | leer bis T0, dann 3,8 MB MyISAM |
| `tmp/b5-fuellung.bin` | Datei des Kunden, angelegt als `p1139` | den Platz auf 78, 74, 70, 74 und 76 MB |
| `tmp/b5-voll.bin` | Datei des Kunden, angelegt als `p1139` | den vollen Platz in Punkt 7 |

**Die Füllung liegt in `tmp/` und nicht unter `httpdocs`**: `tmp` gehört
`p1139` (`2700`), liegt ausserhalb jedes Dokumentenverzeichnisses und zählt zu
seiner Quota. Angelegt wird sie **als `p1139`** mit `runuser`, und das ist
tragend: Eine Datei, die root anlegt und danach übergibt, kann die Quota
überschreiten, weil root sie nicht spürt. `fallocate` vergibt die Blöcke, ohne
sie zu schreiben; `repquota` zählt vergebene Blöcke.

**MyISAM und nicht InnoDB** (`docs/139 §7`): InnoDB meldete direkt nach dem
Einfügen 16.384 B und erst nach `ANALYZE TABLE` die wirkliche Grösse; MyISAM
meldet sofort, was in der Datei steht. 3.800.000 Zeichen ergaben dort
3.801.036 B, also 3 MB ganz.

**Was das am Bestand hinterlässt:** Die Kachel „Speicherplatz" von
`p6-abnahme.invalid` trägt die Tage des Laufs mit 70 bis 80 MB, und das bleibt
dreissig Tage in ihrer Kurve (B3). Während Punkt 7 kann die Website nicht
schreiben, ihre Zugriffsprotokolle eingeschlossen; die Zeilen dieser Minuten
fehlen dem Traffic des Tages.

### Die Prüfkörper anlegen — im Panel

1. **`/subscriptions/140` → Bearbeiten.** Unter den Kontingenten
   „Speicherplatz" auf **80** und „Datenbankgröße" auf **1** übersteuern und
   speichern. Für den Platz setzt das Panel einen Vorgang „Speichergrenze
   anwenden" ab; der Streifen oben zeigt ihn. Weiter erst, wenn er fertig ist.
2. **Eine Datenbank `b5` für `p6-abnahme.invalid` anlegen**, MariaDB und
   **ohne** Zugang — derselbe Weg wie für `rundung` in `docs/139 §7`. Sie heisst
   danach `p1139_b5` und bleibt leer bis Punkt 2.

Danach:

```bash
# Die Grenze ist angekommen — liest nur
printf 'p1139 belegt %s KiB, hart %s KiB\n' "$(belegt)" "$(hart)"
mariadb -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'p1139_b5'"
```

**Erwartet:** `hart 81920 KiB` (80 × 1024) und `p1139_b5`. Steht dort noch
`5242880`, ist der Vorgang nicht durch.

---

## §3 · Die Punkte

### Punkt 1 — Die Vorwarnung beginnt ab 95 %, hält bis 90 % und beginnt darunter nicht neu

Entschieden am 5. Oktober (§6, Befund 3). Vier Läufe, jeder in einem eigenen
Block mit Vorbereitung, Lauf und Ablesung. Der Prüfling ist das Gedächtnis
des Rückwegs: die eigenen Befunde vom vorigen Lauf.

```bash
# 1a · 78 MB von 80 = 97,5 % — schreibt die Füllung
fuellen 78
lauf
befunde
```

**Erwartet:** `fuellen` meldet `= 78 MB · hart 81920 KiB`. `lauf` druckt die
zwei Zeilen der Messung, `… Prüfung(en) gefahren, <a>.` und:

```
  mail: 0 Nachricht(en) über 0 Befund(e).
  webhook: 0 Nachricht(en) über 0 Befund(e).
```

`befunde`:

```
Platz laut Panel: 78 MB, gemessen <a>
Befunde zu p6-abnahme.invalid: 1
  disk_near_limit 78 MB von 80 MB (97,5 %)   seit <a> · fällig ab <a + 20 h> · zuletzt <a>
Entwarnungen, die noch ausstehen: 0
```

```bash
# 1b · 74 MB von 80 = 92,5 % — schreibt die Füllung
fuellen 74
lauf
befunde
```

**Erwartet:** `disk_near_limit 74 MB von 80 MB (92,5 %)`, **`seit <a>`** wie in
1a und `zuletzt <b>`. Die Warnung hält, und sie ist dieselbe: Ein neuer Befund
trüge `seit <b>`.

```bash
# 1c · 70 MB von 80 = 87,5 % — schreibt die Füllung
fuellen 70
lauf
befunde
```

**Erwartet:** `Befunde zu p6-abnahme.invalid: 0`. Unter 90 % endet die Warnung.
Gemeldet war sie nicht; `lauf` druckt deshalb keine Zeile `Entwarnung`.

```bash
# 1d · wieder 74 MB = 92,5 % — schreibt die Füllung
fuellen 74
lauf
befunde
```

**Erwartet:** `Befunde zu p6-abnahme.invalid: 0`. Dieselben 92,5 % wie in 1b,
ohne Befund vom vorigen Lauf — zwischen 90 und 95 % beginnt keine Warnung.
**1b und 1d sind die Gegenprobe zueinander:** derselbe Platz, zwei Antworten,
und der Unterschied ist allein der vorige Lauf.

### Punkt 2 — T0: der Platz bei genau 95 % und die Datenbanken über ihrem Kontingent

```bash
# 2 · 76 MB von 80 = 95,0 % und 3,8 MB in p1139_b5 — schreibt Füllung und Tabelle
fuellen 76
DB=$(mariadb -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'p1139_b5'")
printf 'Datenbank: %s\n' "${DB:-KEINE GEFUNDEN}"
[ -n "$DB" ] && mariadb "$DB" -e "CREATE TABLE fuellung (b LONGBLOB) ENGINE=MyISAM; INSERT INTO fuellung VALUES (REPEAT('x', 3800000));"
mariadb -N -e "SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = 'p1139_b5'"
lauf
befunde
```

**Erwartet:** `Datenbank: p1139_b5`, die Grösse `3801036`, im Lauf
`mail: 0 Nachricht(en) über 0 Befund(e).`, und:

```
Platz laut Panel: 76 MB, gemessen <T0>
Befunde zu p6-abnahme.invalid: 2
  databases_over  3 MB von 1 MB              seit <T0> · fällig ab <T0 + 20 h> · zuletzt <T0>
  disk_near_limit 76 MB von 80 MB (95,0 %)   seit <T0> · fällig ab <T0 + 20 h> · zuletzt <T0>
Entwarnungen, die noch ausstehen: 0
```

- **`seit` ist T0.** Er wird für Teil 2 abgeschrieben, samt `fällig ab`.
- **95,0 % genau** ist die Schwelle selbst: Sie zählt mit (`>=`).
- **Beide Befunde haben dasselbe `seit`**, weil derselbe Lauf sie gefunden hat.
  Deshalb werden sie zusammen fällig und kommen in **einer** Mail.

**Auf der Seite:** `/diagnose` im Browser, dann in der Konsole:

```js
// Was „Diagnose" zu p6-abnahme.invalid sagt — liest nur
(() => {
  const zeilen = [...document.querySelectorAll('table.stacks tbody tr')]
  const unsere = zeilen.filter((tr) => tr.querySelector('td[data-column="Ort"]')?.textContent.trim() === 'p6-abnahme.invalid')
  console.log(`Seite ${location.pathname} · Befunde ${zeilen.length} · davon p6-abnahme.invalid ${unsere.length}`)
  for (const tr of unsere) {
    const zelle = tr.querySelector('td[data-column="Befund"]')
    const wert = zelle.querySelector('pre')?.textContent.trim() ?? '—'
    const meldung = [...zelle.querySelectorAll('.delivery')].map((s) => s.textContent.trim()).join(' / ')
    console.log(`  ${wert} → ${meldung}`)
  }
})()
```

**Erwartet:**

```
Seite /diagnose · Befunde <n> · davon p6-abnahme.invalid 2
  3 MB von 1 MB → Gemeldet wird ab <T0 + 20 h>.
  76 MB von 80 MB (95,0 %) → Gemeldet wird ab <T0 + 20 h>.
```

Die Zeit ist dieselbe wie `fällig ab` in `befunde`, auf die Sekunde: Seite und
Lauf rechnen sie an derselben Stelle (`Notices::dueAt()`). Gemessen im
Container gegen die echte Seite (§6a).

### Punkt 3 — Die Nacht dazwischen meldet nicht

Am Morgen von Tag 2, **vor** T0 + 20 h:

```bash
# 3 · Was der Nachtlauf gesagt hat — liest nur
journalctl -u srvpanel-diagnose.service --since '<Tag 2> 00:00' --until '<Tag 2> 02:00' --no-pager -o short-iso \
  | grep -E 'gefahren,|Nachricht\(en\)|Entwarnung|ohne Empfänger|nicht eingerichtet'
befunde
```

**Erwartet:** ein Lauf zwischen 00:00 und 01:00, darin
`mail: 0 Nachricht(en) über 0 Befund(e).` und
`webhook: 0 Nachricht(en) über 0 Befund(e).`. In `befunde` beide Befunde mit
`seit <T0>` wie gestern, `zuletzt <Zeit des Nachtlaufs>` und **ohne** Zeile
`gemeldet über`. Der Nachtlauf hat beide gesehen und nicht gemeldet.

Standen in Block 0 Befunde, die noch eine Mail bekommen, und sind sie in der
Nacht fällig geworden, steht dort eine Zahl über 0 — dann entscheidet
`befunde`: keine Buchung an den beiden.

### Punkt 4 — Ab T0 + 20 h genau eine Mail

```bash
# 4 · Der erste Lauf nach der Haltezeit — verschickt die Mail
lauf
befunde
haken
```

**Erwartet** im Lauf:

```
  mail: 1 Nachricht(en) über 2 Befund(e).
  webhook: 1 Nachricht(en) über 2 Befund(e).
```

In `befunde` je Befund zwei Buchungen mit derselben Zeit `<T2>`:

```
  databases_over  3 MB von 1 MB              seit <T0> · fällig ab <T0 + 20 h> · zuletzt <T2>
      gemeldet über mail    <T2>
      gemeldet über webhook <T2>
  disk_near_limit 76 MB von 80 MB (95,0 %)   seit <T0> · fällig ab <T0 + 20 h> · zuletzt <T2>
      gemeldet über mail    <T2>
      gemeldet über webhook <T2>
```

In `haken` als letzte Zeile
`{"kind":"findings","subject":"p6-abnahme.invalid","gruende":["databases_over","disk_near_limit"],"seit":[<T0>,<T0>]}`
— **eine** Meldung für beide Befunde, gebündelt je Abonnement.

**Im Postfach des Empfängers aus Block 0: genau eine Mail**, und in ihrer
Zeile `An` steht nur seine Adresse. Der Betreff:

```
<Marke> — Datenbankgröße überschritten und Speicherplatz fast ausgeschöpft: p6-abnahme.invalid
```

Daneben, wie der Kanal sie baut:

```bash
# 4b · Die Mail aus den Befunden — verschickt nichts
kundenmail
```

**Erwartet:** derselbe Betreff, `Zeilen: 27 · die längste: 75 Zeichen ·
Punkt mit Doppelpunkt: 0` und der Text aus §6a, Wort für Wort wie in der
empfangenen Mail. Höchstens 77 Zeichen sind die Grenze; 75 ist der Wert im
Container mit diesen Zahlen.

**Die Gegenprobe zu „genau eine" ist Punkt 6**, nicht diese Zahl.

### Punkt 5 — Der Betreiber sieht, dass sie zugestellt wurde

`/diagnose` neu laden, das Snippet aus Punkt 2 noch einmal. **Erwartet:**

```
Seite /diagnose · Befunde <n> · davon p6-abnahme.invalid 2
  3 MB von 1 MB → Gemeldet über Mailversand: <T2> / Gemeldet über Meldeziel (Webhook): <T2>
  76 MB von 80 MB (95,0 %) → Gemeldet über Mailversand: <T2> / Gemeldet über Meldeziel (Webhook): <T2>
```

Die Zeiten sind die Buchungen aus `befunde`, auf die Sekunde. Dazu **ein
Bild** der beiden Zeilen auf dem Telefon (390 px): Der Zeitpunkt steht dort
in einem Stück und nicht am Bindestrich gebrochen (§6a).

**Ohne Meldeziel** steht in jeder Zeile nur der Mailversand. Das Meldeziel
fragt die Seite nicht beim Agenten nach; sie zeigt, was gebucht ist.

### Punkt 6 — Der nächste Lauf schickt keine zweite

Gleich danach:

```bash
# 6 · Ein Lauf nach der Mail — meldet nichts mehr
lauf
befunde
```

**Erwartet:** `mail: 0 Nachricht(en) über 0 Befund(e).` und
`webhook: 0 Nachricht(en) über 0 Befund(e).`. In `befunde` dieselben vier
Buchungen mit denselben Zeiten, nur `zuletzt` ist neu. **Im Postfach kommt
nichts dazu** — das liest der Betreiber ein paar Minuten danach nach.

### Punkt 7 — Ein voller Platz: „ausgeschöpft" kommt dazu, die Vorwarnung bleibt

Befund 7. Der Platz wird bis an die Grenze gefüllt, und zwar so, wie ein Kunde
es täte: als `p1139`, schreibend, bis die Quota abweist.

```bash
# 7 · Den Platz ganz füllen — schreibt, bis die Quota abweist
runuser -u p1139 -- dd if=/dev/zero of=/var/www/vhosts/p6-abnahme.invalid/tmp/b5-voll.bin bs=1M status=none; echo "dd: rc=$?"
printf 'belegt %s KiB · hart %s KiB\n' "$(belegt)" "$(hart)"
lauf
befunde
```

**Erwartet:** `dd: error writing '…/tmp/b5-voll.bin': Disk quota exceeded`,
`dd: rc=1` und `belegt 81920 KiB · hart 81920 KiB`. Im Lauf
`mail: 0 Nachricht(en) über 0 Befund(e).` und
`webhook: 0 Nachricht(en) über 0 Befund(e).` und **keine Zeile
`Entwarnung`**. In `befunde`:

```
Platz laut Panel: 80 MB, gemessen <T3>
Befunde zu p6-abnahme.invalid: 3
  databases_over  3 MB von 1 MB              seit <T0> · fällig ab <T0 + 20 h> · zuletzt <T3>
      gemeldet über mail    <T2>
      gemeldet über webhook <T2>
  disk_near_limit 80 MB von 80 MB (100,0 %)  seit <T0> · fällig ab <T0 + 20 h> · zuletzt <T3>
      gemeldet über mail    <T2>
      gemeldet über webhook <T2>
  disk_over       80 MB von 80 MB            seit <T3> · fällig ab <T3 + 20 h> · zuletzt <T3>
Entwarnungen, die noch ausstehen: 0
```

- **Die Vorwarnung steht noch da, mit `seit <T0>`**, und ist gemeldet. Mit dem
  Bau vor Befund 7 wäre sie hier verschwunden, und `lauf` hätte
  `webhook: 1 Entwarnung(en) verschickt.` gedruckt — für einen Platz, der
  gerade voll wurde.
- **„Ausgeschöpft" ist neu und noch nicht fällig.** Gemeldet würde es nach
  zwanzig Stunden, in einer Mail, die nur „ausgeschöpft" nennt (§6a). Darauf
  wartet dieser Lauf nicht (§4).
- **`belegt` gleich `hart`** ist die Bedingung, unter der „ausgeschöpft"
  überhaupt entsteht: `repquota` zählt in KiB, das Panel in ganzen MB. Bleibt
  `belegt` unter `hart`, steht dort 79 MB und kein `disk_over`; dann fällt der
  Punkt als „nicht herstellbar" aus, mit beiden Zahlen im Protokoll (§5).

Das Snippet aus Punkt 2 auf `/diagnose` zeigt jetzt drei Zeilen; die neue
sagt `80 MB von 80 MB → Gemeldet wird ab <T3 + 20 h>.`

### Punkt 8 — Wieder Platz: „ausgeschöpft" geht, die Vorwarnung bleibt

**Gleich nach Punkt 7**; solange die Datei liegt, kann die Website nicht
schreiben.

```bash
# 8 · Die volle Datei entfernen — schreibt
rm -f /var/www/vhosts/p6-abnahme.invalid/tmp/b5-voll.bin
printf 'belegt %s KiB = %s MB\n' "$(belegt)" "$(( $(belegt) / 1024 ))"
lauf
befunde
```

**Erwartet:** `= 76 MB`. Im Lauf keine Nachricht und **keine Entwarnung**:
„Ausgeschöpft" war nie gemeldet, also gibt es nichts zu entwarnen. In
`befunde` wieder zwei Befunde, beide `seit <T0>` und gemeldet um `<T2>` — die
Vorwarnung hat den vollen Platz überdauert und beginnt nicht neu. Mit dem Bau
vor Befund 7 stünde sie hier mit `seit <T4>` als neuer Befund da, und nach
zwanzig Stunden käme die zweite Mail „fast ausgeschöpft".

### Punkt 9 — Zurück

Zuerst die Füllung, dann im Panel:

```bash
# 9a · Die Füllung entfernen — schreibt
rm -f /var/www/vhosts/p6-abnahme.invalid/tmp/b5-fuellung.bin
ls -la /var/www/vhosts/p6-abnahme.invalid/tmp/ | grep -c 'b5-'
```

**Erwartet:** `0`. Dann im Panel:

1. **`/subscriptions/140` → Datenbanken:** `p1139_b5` entfernen.
2. **`/subscriptions/140` → Bearbeiten:** die beiden Übersteuerungen leeren
   und speichern. Der Vorgang „Speichergrenze anwenden" setzt die Grenze des
   Plans zurück; weiter erst, wenn er fertig ist.

```bash
# 9b · Der Lauf danach — meldet die Entwarnung
lauf
befunde
haken
printf 'p1139 belegt %s KiB, hart %s KiB\n' "$(belegt)" "$(hart)"
mariadb -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'p1139_b5'"
```

**Erwartet:**

- `mail: 0 Nachricht(en) über 0 Befund(e).` und
  `webhook: 0 Nachricht(en) über 0 Befund(e).`, darunter
  `webhook: 1 Entwarnung(en) verschickt.` — **eine** Entwarnung für beide
  Befunde, gebündelt je Abonnement. Die Mail kennt keine Entwarnung; der Kunde
  bekommt nichts.
- `Befunde zu p6-abnahme.invalid: 0` und `Entwarnungen, die noch ausstehen: 0`.
- In `haken`
  `{"kind":"resolved","subject":"p6-abnahme.invalid","gruende":["databases_over","disk_near_limit"],…}`.
- `hart 5242880 KiB` und die Zahl `0`.
- Auf `/diagnose` sagt das Snippet `davon p6-abnahme.invalid 0`.

---

## §4 · Was dieser Lauf ausdrücklich nicht prüft

- **Mehrere Empfänger** (Befund 4). Auf `cloudsrv24` gibt es nur das eine
  Konto. Gemessen im Container: Wer das Abonnement sieht, bekommt die Mail, ein
  Zusatzbenutzer ohne Zuweisung und ein gesperrtes Konto nicht, jeder Empfänger
  seine eigene Mail, und eine Adresse, die das Relay abweist, hält die Buchung
  nicht auf (`QuotaRecipientTest`, mit einem Transport, der wirklich abweist).
- **Ein Kunde ohne Konto mit Adresse.** Der Lauf meldet dann `ohne Empfänger`,
  der Befund bleibt fällig, und die Seite sagt „Mailversand: noch nicht
  zugestellt, fällig seit …" (`DiagnosePageTest`).
- **Die Mail „ausgeschöpft".** Sie käme zwanzig Stunden nach Punkt 7, und so
  lange bliebe die Website ohne Schreibzugriff. Gemessen im Container: Auf der
  Grenze nennt die Mail nur „ausgeschöpft", ohne die Vorwarnung daneben und
  ohne den Satz über 90 % (`QuotaWarningTest`).
- **Traffic über dem Kontingent.** Der Prüfstand hat keinen Monat Verkehr in
  dieser Grösse; der Absatz über die Abrechnung und die Rechnung über den
  Kalendermonat sind im Container gemessen (`QuotaWarningTest`,
  `QuotaOverrunTest`).
- **Bilder in beiden Themen und bei 1440 px.** Die Seite ist im Container in
  vier Lagen gemessen (§6a); auf dem Server steht ein Bild bei 390 px.

---

## §5 · Wann er durch ist

- **Die Punkte 2 bis 6 tragen das Kriterium** und dürfen nicht ausfallen:
  eine Mail genau nach der Haltezeit (3 und 4), keine zweite (6), und der
  Betreiber sieht beide Zustellungen am Befund (5).
- **Punkt 1** belegt die Entscheidung zu Befund 3 und darf nicht ausfallen.
- **Die Punkte 7 und 8** belegen Befund 7. Punkt 7 darf als „nicht
  herstellbar" ausfallen, wenn `belegt` nach dem `dd` unter `hart` bleibt;
  dann fällt Punkt 8 mit, und beide Zahlen stehen im Protokoll.
- **Ohne Meldeziel** fallen die Webhook-Teile aus — die Zeilen `webhook`, die
  Buchungen `gemeldet über webhook`, `haken` und die Entwarnung in Punkt 9.
  Das Kriterium spricht von der Mail an den Kunden; der Lauf ist dann trotzdem
  durch, und im Protokoll steht, dass kein Ziel da war.
- **Punkt 9 wird gefahren**, auch wenn ein Punkt davor ausfällt. Ein voller
  oder enger Platz an einem Abonnement, das danach niemand ansieht, ist ein
  Rest und kein Prüfkörper.

---

## §6 · Die Entscheidungen des Betreibers — 5. Oktober 2026

Gefragt nach dem Vorabmessen, mit der Mail aus §0 daneben. **Alle vier wie
vorgeschlagen:**

| Frage | Entschieden |
|---|---|
| **Befund 3:** Wann soll ein Kunde beim Speicherplatz eine Mail bekommen? | **Ab 95 %, entwarnt unter 90 %.** Vorwarnung, bevor Schreibzugriffe scheitern; dieselbe Schwelle wie bei „Platte voll" für den Server. Betreff und Text sagen dann „fast erreicht" statt „überschritten". |
| **Befund 4:** An wen soll die Mail über ein Abonnement gehen? | **Wer das Abonnement sieht, je eine Mail.** Die Kundenkonten und die Zusatzbenutzer, denen dieses Abonnement zugewiesen ist; jeder bekommt seine eigene Mail, keine Adressen nebeneinander. |
| **Befund 5:** Wie soll der Betreiber sehen, dass die Mail angekommen ist? | **Je Befund auf „Diagnose".** Neben jedem Befund steht, über welchen Kanal er wann gemeldet wurde, oder dass er fällig ist und noch nicht zugestellt. |
| **Befunde 1, 2 und 6:** Sollen die Texte berichtigt werden? | **Alle drei berichtigen.** Zeilen ohne „.:" und unter 78 Zeichen, Einzahl und Mehrzahl richtig, die Absätze nur zu den Kontingenten, die in der Mail stehen, und der Hinweis nennt „Diagnose". |

Abgelehnt waren damit: „Erst, wenn er voll ist" und „So lassen" (Befund 3),
„Nur die Kundenkonten" und „So lassen" (Befund 4), „Wie gebaut, je Kanal"
(Befund 5).

**Befund 7 kam nach den vier Fragen und ist nicht gefragt worden.** Gebaut ist
er nach der Entscheidung zu „Platte voll" vom 27. September (`docs/136`):
Warnung und Störung sind zwei Befunde mit je eigenem Rückweg, und auf der
Grenze stehen beide da. **Wer die beiden lieber ausschliessend hätte, sagt es
vor der Freigabe** — dann bleibt die falsche Entwarnung, und die Punkte 7 und 8
erwarten sie.

---

## §6a · Gebaut für `0.9.0-rc.13`

Drei Commits auf `claude/messrunde-vor-p9-4c9vzi`: `3c9f8f21` (die Befunde 1
bis 6), `c7dd3c02` (Wächter nach dem vollen Testlauf) und `2040b776`
(Befund 7).

### Was gebaut ist

- **Der Platz** (Befund 3, 7). `QuotaOverrun::disk()` entscheidet zwei Gründe
  getrennt: `disk_near_limit` ab 95 % und zurück erst unter 90 %, mit den
  eigenen Befunden vom vorigen Lauf als Gedächtnis, und `disk_over` an der
  Grenze selbst. Auf der Grenze stehen beide. Der Anteil wird abgerundet, damit
  „fast ausgeschöpft" unter der Grenze nie „100,0 %" sagt.
- **Die Empfänger** (Befund 4). `MailChannel` schreibt an die Konten, die das
  Abonnement sehen (`Account::mayAccessSubscription()`), je Adresse eine
  eigene `QuotaWarning`. Angekommen ist die Mail, wenn sie bei einem
  angekommen ist; sonst bleibt sie fällig.
- **Die Mail** (Befunde 1, 2, 7). Satz und Wert stehen untereinander, gebrochen
  nach Zeichen bei 76; die Absätze stehen je Kontingent und nur, wenn es in der
  Mail vorkommt. Der Betreff nennt, was los ist. Auf der Grenze nennt sie nur
  „ausgeschöpft" (`QuotaWarning::shown()`); gebucht werden beide Befunde.
- **Die Seite** (Befund 5). Unter jedem Befund auf „Diagnose" steht, über
  welchen Kanal er wann gemeldet wurde, wann er gemeldet wird, oder seit wann
  er fällig ist. Die Fälligkeit kommt aus `Notices::dueAt()`, derselben Stelle,
  nach der der Lauf meldet, und die Seite fragt den Agenten nicht
  (`Channel::knownUsable()`).
- **Die Hinweise** (Befund 6). Die Hinweise an „Speicherplatz",
  „Datenbankgröße" und „Traffic je Monat" nennen „Diagnose", so wie das Menü
  die Seite nennt. Die Namen der
  Kanäle stehen in `resources/js/channels.ts` und nicht mehr in einer Seite.

### Die Mail danach

Im Container durch den echten Kanal verschickt, mit den Zahlen von Punkt 4.
Betreff `SrvPanel — Datenbankgröße überschritten und Speicherplatz fast
ausgeschöpft: p6-abnahme.invalid`, die längste Zeile 75 Zeichen:

```
Guten Tag,

für Ihr Abonnement p6-abnahme.invalid hat das Panel Folgendes gemessen:

- Die Datenbanken dieses Abonnements liegen zusammen über ihrem Kontingent.
  Gemessen: 3 MB von 1 MB
- Der Speicherplatz ist fast ausgeschöpft. Ist er voll, lassen sich keine
  Dateien mehr schreiben.
  Gemessen: 76 MB von 80 MB (95,0 %)

Den Speicherplatz begrenzt das Dateisystem: Ist er ausgeschöpft, scheitert
jeder Schreibzugriff Ihrer Website, bis Dateien gelöscht werden oder das
Kontingent steigt. Die Warnung endet, wenn der belegte Platz unter 90 % des
Kontingents fällt.

Die Datenbankgröße wird gemessen und nicht erzwungen — dafür wird nichts
abgeschaltet und nichts gesperrt.

Die Zahlen stehen mit ihrem Verlauf der letzten dreissig Tage auf der Seite
Ihres Abonnements im Panel.

Sie bekommen diese Nachricht einmal je Zustand. Erst wenn er vorbei ist und
wieder eintritt, meldet sich das Panel erneut.

--
SrvPanel
```

### Die Folge des Laufs, im Container vorab gefahren

Gegen eine eigene SQLite-Datenbank, mit den echten Teilen: `QuotaOverrun`,
`FindingLog`, `Notices` und dem Mailkanal mit einem Transport, der ablegt.
Gemessen wurde die Folge der Punkte 1 bis 9 mit dem Kontingent von 80 MB, und
jede Erwartung in §3 ist eine Zeile daraus:

| Lauf | Platz | Befunde danach | Meldelauf |
|---|---|---|---|
| 1a | 78 MB | Vorwarnung, `seit` 1a | nichts |
| 1b | 74 MB | Vorwarnung, `seit` 1a | nichts |
| 1c | 70 MB | keine | nichts |
| 1d | 74 MB | keine | nichts |
| 2 (T0) | 76 MB + 3 MB Datenbank | beide, `seit` T0 | nichts |
| Nacht | 76 MB | beide, `seit` T0 | nichts |
| T0 + 20 h | 76 MB | beide gebucht | `mail: 1 über 2` |
| danach | 76 MB | unverändert | nichts |
| 7 | 80 MB | drei; Vorwarnung `seit` T0 | nichts, keine Entwarnung |
| 8 | 76 MB | zwei; Vorwarnung `seit` T0 | nichts, keine Entwarnung |
| 9 | 3 MB, ohne Datenbank | keine | zwei Entwarnungen verbraucht, keine Mail |

**Nicht vorab gefahren** ist, was nur der Server hat: die Quota selbst (der
Kernel dieses Containers erzwingt keine), `systemctl` und das Journal, der
Webhook und das Postfach. Die Hilfe `fuellen` ist gegen Attrappen von
`repquota` und `runuser` gefahren: Sie rechnet die Füllung und trifft die Mitte
des MB.

### Die Seite

Die Zustellung stand im ersten Wurf in einer **sechsten Spalte** „Gemeldet".
Gemessen im Chromium gegen die echte Seite bei 1440 px: Die Tabelle war mit
kurzen Orten **1388 px** breit und mit langen **1548 px**, in einem Behälter
von 1140 — sie rollte um 248 und 408 px. In der Zelle „Befund" ist sie 1140 px
breit, bei 390 px 358 von 358, und `tests/bilder-messen.js` misst in vier
Lagen `dokument = 0`, Gegenprobe 200, `schiebt = 0`.

> **Eine Spalte, die bei kurzen Werten passt, rollt bei langen — und welche
> auf dem Server stehen, entscheidet der Bestand.**

**Und der Zeitpunkt brach am Bindestrich.** Chromium bricht nach einem
Bindestrich, und „2026-10-" stand am Zeilenende, „05 00:29:12" darunter. Der
Zeitpunkt steht seitdem in einem Stück, die Zeile davor bricht.

Das Snippet aus Punkt 2 ist gegen dieselbe Seite gefahren, mit einem Befund zu
`p6-abnahme.invalid` und zwei Buchungen: Es druckt `Befunde 5 · davon
p6-abnahme.invalid 1` und die Zeile mit beiden Kanälen.

### Die Wächter

| Wächter | Was er hält |
|---|---|
| `QuotaOverrunTest` | ab 95 % und nicht darunter, nie „100,0 %" unter der Grenze, Halt bis 90 %, kein Neubeginn zwischen 90 und 95 %, auf der Grenze beide Befunde, die Vorwarnung überdauert den vollen Platz mit ihrem `first_seen_at`, das Gedächtnis liest nur den Platz |
| `QuotaWarningTest` | jeder meldbare Grund hat eine Überschrift, der Rückfall wirft, der Betreff sagt „fast", keine Zeile über 77 Zeichen, kein „.:", die Absätze je Kontingent in beide Richtungen, auf der Grenze nur „ausgeschöpft" |
| `QuotaRecipientTest` | wer das Abonnement sieht, je eine Mail; eine angekommene Mail bucht, keine angekommene lässt fällig |
| `QuotaHintTest` | der Hinweis nennt eine Seite des Menüs, und dort steht die Überschreitung — durch die Tür gefragt |
| `DiagnosePageTest` | alle vier Zustände einer Zustellung durch die Tür, die Seite fragt den Agenten nicht, gemessen in einer Zone mit Versatz |
| `DiagnoseViewTest` | die Zustellung steht in der Zelle und nicht in einer sechsten Spalte, der Zeitpunkt bricht nicht |
| `ChannelReachTest` | die Namen der Kanäle stehen an einer Stelle |

**Brüche:** 27 neu und sechs bestehende auf ihre neuen Anker gezogen
(`3c9f8f21`), zwei neu nach dem vollen Testlauf (`c7dd3c02`), drei neu und
zwei Anker nachgezogen für Befund 7. Die Auswahl über alle Eingriffe an den
berührten Dateien: **93 Prüfungen, alle beissen.**

**Der volle Testlauf hat zwei Fehler gefunden, die die Auswahl nicht
fand.** `ClassNameTest` kannte drei neue Klassen nicht. Und `DiagnosePageTest`
erwartete die Zeit einer Buchung in UTC: Einzeln war er grün, im vollen Lauf
stand dort `05:00:12` statt `03:00:12`, weil ein früherer Test
`Europe/Berlin` in `Clock` zurückgelassen hatte. Er setzt seine Zone jetzt
selbst, mit Versatz.

> **Ein Test, dessen Ergebnis davon abhängt, was vor ihm lief, misst die
> Reihenfolge mit — und einzeln gefahren ist er grün.**

---

## §7 · Protokoll

Noch nicht gefahren.
