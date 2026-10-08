# B9 — der Abnahmelauf für Zertifikat und Sicherung beim Kunden

Ausgeschrieben am 8. Oktober 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/129 §9`:

> Eine Domain, deren hochgeladenes Zertifikat in weniger als dreissig Tagen
> abläuft, und ein Abonnement, dessen jüngste Sicherung gescheitert ist,
> bringen dem Kunden je genau eine Mail; zwei Zustände desselben Abonnements in
> einer Nacht stehen als zwei Abschnitte in einer Mail. Der Betreiber bekommt
> dazu keine Mail, er sieht auf „Diagnose", dass sie zugestellt wurden, und eine
> gelungene Sicherung nimmt den Befund zurück.

Gebaut ist B9 seit dem 7. Oktober (`docs/142 §10`) und freigegeben seit dem
8. Oktober als `0.9.0-rc.15`: die Prüfung `tls.expiry` für die Laufzeit eines
Zertifikats, die Prüfung `backup.latest` für die jüngste Sicherung, und eine
Mail an den Kunden je Abonnement mit einem Abschnitt je Art (`CustomerNotice`).
Die Laufzeit wird nach zwanzig Stunden gemeldet (`Notices::HOLD_HOURS`), eine
gescheiterte Sicherung im ersten Lauf, der sie sieht
(`Notices::BACKUP_HOLD_MINUTES`, null).

**Gefahren wird gegen `0.9.0-rc.15`.**

**Vorab im Container gemessen** (§6): der Weg, auf dem eine Sicherung scheitert,
mit den echten Operationen des Agenten gegen MariaDB 10.11.14, dieselbe Fassung
wie auf `cloudsrv24`; die ganze Folge der Punkte mit den echten Teilen des
Panels gegen eine eigene Datenbank; und die Werkzeuge dieses Laufs gegen eine
eigene SQLite-Datenbank. Jede Erwartung in §3 ist eine Zeile daraus. **Am
Prüfling ist dabei kein Befund herausgefallen**, an der Vorschrift sieben
Punkte (§0) und eine Frage an den Betreiber (§0, Beobachtung).

**Der Lauf braucht zwei Tage und beginnt vor dem Update.** Block 0 liest die
Empfänger in voller Länge, bevor `rc.15` installiert ist, denn das Update
selbst bringt einem Kunden eine Mail (§0 Punkt 1). Teil 1 lässt eine Sicherung
scheitern und gelingen und lädt das Zertifikat hoch (T0), Teil 2 misst ab
T0 + 20 h die Mail mit beiden Abschnitten und räumt danach ab (§2).

---

## §0 · Was beim Ausschreiben herausgefallen ist

### Sieben Punkte an der Vorschrift

1. **Das Update schickt selbst eine Mail an einen Kunden, und dafür läuft ab
   dem ersten Lauf danach eine Frist.** Seit dem 21. September steht auf
   „Diagnose" `tls.file / expired` an `p6-b.invalid`: das hochgeladene
   Wegwerfzertifikat aus A10 (`docs/100 §6`), abgelaufen am 13. September um
   19:10 UTC. Der erste Lauf nach dem Update, ein Nachtlauf oder einer von
   Hand, entwarnt die Zeile beim Webhook unter ihrem alten Schlüssel und
   befindet die Laufzeit neu, als `tls.expiry` mit `expiring` und `expired`.
   Zwanzig Stunden danach geht an die Konten von `p6-b.invalid` eine Mail
   „Zertifikat abgelaufen" (`docs/142 §10`, nachgefahren in §6).

   Wer sie bekommt, ist nicht nachgesehen, und im Lauf für B5 stand hinter
   einer Anmeldeadresse eine Attrappe auf einer fremden Domain (`docs/141 §7`,
   Befund 3). Aus dem Hinweis in `docs/142 §10` wird deshalb eine Frist:
   **Block 0 läuft vor dem Update, oder, wenn es schon gelaufen ist, sofort.**
   Fällt der erste Lauf nach dem Update in eine Nacht, geht die Mail in der
   Nacht darauf hinaus. Block 0 druckt die Adressen in voller Länge, und
   daneben, ob die Domain reserviert ist.

   > **Ein Update, das einen Befund neu befindet, stellt eine Uhr — wer die
   > Empfänger erst beim Lauf nachsieht, sieht womöglich nach, wenn sie
   > abgelaufen ist.**

2. **Eine Sicherung soll scheitern, ohne einem Kunden etwas zu nehmen.**
   `docs/142 §5` hat offengelassen, wie. Drei Wege lagen nahe:

   - **Zu wenig Platz.** `BackupCreate` verlangt 512 MB über der Schätzung
     (`RESERVE_BYTES`). Dafür müsste die Wurzel volllaufen, und auf ihr liegt
     die Datenbank des Panels; was MariaDB dann tut, steht in `docs/136 §3`
     (M5). Ausgeschieden.
   - **Ein Ablageort, in den nicht geschrieben werden kann** (`chattr +i` auf
     das Verzeichnis der Sicherungen). Das ist ein Eingriff am Panel selbst und
     ein Zustand, den kein Kunde herstellen kann. Ausgeschieden.
   - **Eine kaputte Sicht in einer eigenen Datenbank des Abonnements.** Das
     kann jeder Kunde: eine Tabelle löschen, auf die eine Sicht zeigt. Daran
     bricht `mysqldump` ab, der Dump liegt nicht, und `backup.create` weist die
     Sicherung ab, weil sie ohne ihre Datenbanken von einer vollständigen nicht
     zu unterscheiden wäre (Kopf von `BackupCreate`). Zurück geht es mit
     `DROP VIEW`. **Gewählt.**

   Gemessen im Container mit den echten Operationen des Agenten (§6):
   `db.dump.create` bricht mit Fehler 1356 ab und legt keine Datei ab,
   `backup.create` mit dem Satz aus dem Quelltext, 172 Zeichen, und das halbe
   Archiv ist fort. Der Satz passt in `backups.last_error` (255). Ohne die Sicht
   gelingen beide. Und die beiden Abfragen auf `information_schema`, die davor
   laufen, die Messung der Datenbanken alle fünfzehn Minuten und die
   Platzprüfung des Dumps, kommen an der kaputten Sicht vorbei, mit `rc=0` und
   0 Byte für `p1139_b9`.

3. **Das Prüfzertifikat soll nach dem Lauf keinen Rest hinterlassen.** An der
   Hauptdomain `p6-abnahme.invalid` bliebe es für immer: Von einem
   hochgeladenen Zertifikat lässt sich eine Domain nur auf ein anderes
   umstellen und nicht lösen (`DomainController::chooseCertificate()`), und ein
   Ersatz für ein Jahr brächte elf Monate später eine Mail an den Kunden. Der
   Lauf legt deshalb die Subdomain **`b9.p6-abnahme.invalid`** an und entfernt
   sie am Ende; das Zertifikat räumt danach `srvpanel tls --prune` ab.

   **Die Subdomain bestellt beim Anlegen einmal bei Let's Encrypt**, wie jede
   Domain, deren Zertifikat ihre Namen nicht deckt (`CertificateLifecycle`).
   Für einen Namen unter `.invalid` stellt niemand aus, und die Bestellung
   wird abgewiesen (`docs/78 §5`). Der gescheiterte Vorgang gehört zum Lauf und
   ist kein Befund.

4. **`srvpanel tls --upload` liest Zertifikat und Schlüssel nicht als root.**
   Das Kommando wechselt vor artisan auf den Dienstbenutzer `srvpanel`, und ein
   Schlüssel unter `/root` scheitert immer; das Kommando sagt es. Die Dateien
   entstehen deshalb in `/var/tmp/b9-tls`, das `srvpanel` gehört und sonst
   niemandem offensteht. **Hochgeladen wird über die Kommandozeile und nicht
   über das Formular der Domain**, damit der Schlüssel nicht durch die
   Zwischenablage geht. Beide Wege legen das Zertifikat über dieselbe Stelle ab
   (`CertificateRecord::store()`) und schreiben den Server-Block über denselben
   Vorgang neu. Der Schlüssel entsteht auf dem Server, wie in A10, denn in
   diesem Container wird kein privates Schlüsselmaterial erzeugt.

5. **Eine Zeile der Mail steht in einer anderen Zone als die übrigen.** Das
   Prüfzertifikat gilt zwanzig Tage und damit über den 25. Oktober hinaus, an
   dem die Sommerzeit endet. „Gültig bis" steht deshalb in `CET (UTC+01:00)`,
   jeder andere Zeitpunkt der Mail in `CEST (UTC+02:00)`. Das ist richtig: Die
   Zone gehört zum genannten Zeitpunkt (`docs/102`). Es steht hier, damit es
   beim Lesen der Mail nicht als Befund gilt.

6. **Der Webhook bündelt anders als die Mail.** Die Mail geht je Abonnement,
   der Webhook je Gegenstand. Am Tag 2 kommt deshalb eine Mail an
   `p6-abnahme.invalid` mit zwei Abschnitten, und der Webhook bekommt zwei
   Meldungen, eine für die Subdomain und eine für das Abonnement. Steht auch
   die Mail an `p6-b.invalid` in diesem Lauf an, sind es zwei Mails und drei
   Meldungen (§3, Punkt 7).

7. **„Der Betreiber bekommt keine Mail" steht im Betreff und nicht nur im
   Postfach.** Die Mail an den Betreiber heisst `<Marke> — ein neuer Befund auf
   <Rechner>` oder `… <n> neue Befunde auf …` (`DiagnoseReport`), die an den
   Kunden `<Marke> — <Überschriften>: <Abonnement>` (`CustomerNotice`). Liest
   der Betreiber beide Postfächer, oder sind es dasselbe, entscheidet der
   Betreff. Block 0 druckt beide Adressen.

### Eine Beobachtung — und eine Frage an den Betreiber

**Die Mail nennt, dass der Dump fehlt, und nicht, warum.** Scheitert der Dump,
weist `backup.create` die Sicherung mit dem Satz ab, der in §6 gemessen ist:

    Der Dump p1139-b9-20261009-082000-abcd1234 der Datenbank p1139_b9 liegt
    nicht — die Sicherung wäre ohne ihre Datenbanken und von einer
    vollständigen nicht zu unterscheiden.

Genau dieser Satz steht in `backups.last_error`, auf der Seite der Sicherungen
und in der Mail unter „Meldung". Der Grund steht am Vorgang davor,
`db.dump.create`: `View 'p1139_b9.b9_kaputt' references invalid table(s) …`.
Der Kunde sieht ihn unter „Vorgänge"; die Mail führt ihn nicht dorthin.

Gebaut ist es so mit Absicht: Die Mail nennt denselben Satz wie die Seite
(`docs/142 §4`). Ob die Sicherung den Grund des gescheiterten Dumps übernehmen
soll, ist eine Frage an den Betreiber, und gebaut würde es erst mit einer
neuen Freigabe. **Dieser Lauf misst `rc.15`, wie er ist**; jede Erwartung in
§3 nennt den Satz oben.

---

## §1 · Die Werkzeuge und die Vorbedingung

### Block 0 — vor dem Update, und noch einmal danach

**Er läuft unter `rc.14` und unter `rc.15`**; alles, was er fragt, gibt es in
beiden. Gefahren wird er vor dem Update und gleich danach, beim zweiten Mal
steht in der ersten Zeile `0.9.0-rc.15`. Ist das Update schon gelaufen, genügt
der zweite.

**Die Adressen stehen in voller Länge da.** Diese Ausgabe geht nicht so ins
Protokoll; dort stehen sie gekürzt. Im Lauf für B5 verbarg die gekürzte Zeile
eine Attrappe (`docs/141 §7`, Befund 3).

> **Eine Adresse, die man gekürzt bestätigt, hat niemand gelesen.**

```bash
# 0 · Fassung, die beiden Abonnements, Empfänger in voller Länge, Meldewege und Befunde — liest nur
srvpanel version
srvpanel tinker --execute='
  $reserviert = function (string $adresse): string {
    $domain = strtolower((string) substr((string) strrchr($adresse, "@"), 1));
    return preg_match("/(^|\.)(test|example|invalid|localhost)$|(^|\.)example\.(com|net|org)$/", $domain) === 1 ? "reserviert" : "NICHT reserviert";
  };
  app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(function () use ($reserviert) {
    foreach (["p6-abnahme.invalid", "p6-b.invalid"] as $name) {
      $abo = App\Models\Subscription::query()->with("plan")->where("name", $name)->first();
      if ($abo === null) { echo "Abonnement $name: FEHLT\n"; continue; }
      printf("Abonnement %d · %s · %s · %s · Plan %s · Hochladen: %s · Sicherungen: %s, Kontingent %s\n", $abo->id, $abo->name, $abo->system_user, $abo->status->value, $abo->plan?->name ?? "—", $abo->feature("certificate_upload") ? "ja" : "nein", $abo->feature("backups") ? "ja" : "nein", $abo->quota("backups") ?? "—");
      foreach ($abo->customer->accounts()->orderBy("id")->get() as $k) printf("  Konto %d · %s · %s · %s · %s · sieht das Abonnement: %s\n", $k->id, $k->type->value, $k->status->value, $k->email, $reserviert((string) $k->email), $k->mayAccessSubscription($abo) ? "ja" : "nein");
      $an = (new ReflectionMethod(App\Support\Notify\MailChannel::class, "customerAddresses"))->invoke(app(App\Support\Notify\MailChannel::class), $abo->name);
      printf("  Empfänger der Kundenmail: %d — %s\n", count($an), implode(", ", $an));
      foreach (App\Models\Domain::query()->where("subscription_id", $abo->id)->orderBy("id")->get() as $d) printf("  Domain %d · %s · %s · Zertifikat: %s\n", $d->id, $d->name, $d->type->value, app(App\Support\Tls\CertificateChoice::class)->effective($d)?->storage_name ?? "keines");
    }
    printf("Betreiber: %s\n", App\Models\Account::operators()->orderBy("id")->get()->map(fn ($k) => $k->id." · ".$k->email)->implode(", "));
  });
'
srvpanel tinker --execute='
  $s = app(App\Support\Settings\Settings::class);
  printf("Marke: %s · Relay eingerichtet: %s · Sicherungen automatisch: %s\n", $s->brand()->name, $s->mail()->usable() ? "ja" : "NEIN", $s->backups()["automatic"] ? "ja" : "nein");
  try {
    $ziel = app(App\Support\Notify\NotifyTarget::class)->describe();
    printf("Ziel des Webhooks: %s\n", $ziel === null ? "KEINES" : json_encode($ziel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  } catch (Throwable $e) {
    printf("Ziel des Webhooks: nicht feststellbar — %s\n", $e->getMessage());
  }
  $alle = App\Models\Finding::query()->with("notifications")->orderBy("check")->orderBy("subject")->orderBy("reason")->get();
  printf("Befunde insgesamt: %d\n", $alle->count());
  foreach ($alle as $f) printf("  %s · %s · %s · seit %s · fällig ab %s · gemeldet: %s\n", $f->check->value, $f->subject, $f->reason, App\Support\Time\Clock::display($f->first_seen_at), App\Support\Time\Clock::display(App\Support\Notify\Notices::dueAt($f)) ?? "—", $f->notifications->sortBy("channel")->map(fn ($n) => $n->channel." ".App\Support\Time\Clock::display($n->notified_at))->implode(", ") ?: "—");
'
grep -E ' (install|upgrade) srvpanel:' /var/log/dpkg.log | tail -n 2
journalctl -u srvpanel-diagnose.service -n 40 --no-pager -o short-iso | grep -E 'gefahren,|Entwarnung' | tail -n 4
printf 'Nachtlauf: Streuung %s, Genauigkeit %s · nächster %s\n' "$(systemctl show -p RandomizedDelayUSec --value srvpanel-diagnose.timer)" "$(systemctl show -p AccuracyUSec --value srvpanel-diagnose.timer)" "$(systemctl show -p NextElapseUSecRealtime --value srvpanel-diagnose.timer)"
printf 'Datenbank p1139_b9: %s · /var/tmp/b9-tls: %s\n' "$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'p1139_b9'")" "$(ls -d /var/tmp/b9-tls 2>/dev/null || echo fehlt)"
srvpanel tls --prune --dry-run
```

**Erwartet**, Zeile für Zeile, mit dem, was der Lauf daraus liest:

```
0.9.0-rc.14                                  (vor dem Update; danach 0.9.0-rc.15)
Abonnement 140 · p6-abnahme.invalid · p1139 · active · Plan Standard · Hochladen: <ja|nein> · Sicherungen: ja, Kontingent <n>
  Konto 6 · customer · active · <Adresse> · <reserviert|NICHT reserviert> · sieht das Abonnement: ja
  Empfänger der Kundenmail: 1 — <Adresse>
  Domain 54 · p6-abnahme.invalid · main · Zertifikat: keines
Abonnement 137 · p6-b.invalid · p1136 · active · Plan <Name> · Hochladen: <ja|nein> · Sicherungen: <ja|nein>, Kontingent <n>
  Konto <k> · customer · active · <Adresse> · <…> · sieht das Abonnement: ja
  Empfänger der Kundenmail: <n> — <Adressen>
  Domain 51 · p6-b.invalid · main · Zertifikat: _uploaded.p6-b.invalid
  Domain 55 · cloudlab24.de · … (und die übrigen Domains von p6-b.invalid)
Betreiber: <id> · <Adresse>
Marke: <Name> · Relay eingerichtet: ja · Sicherungen automatisch: <ja|nein>
Ziel des Webhooks: {"host":"<domain des Empfängers>","provider":"generic",…,"signed":true}
Befunde insgesamt: <n>
  tls.file · p6-b.invalid · expired · seit 2026-09-1… · fällig ab … · gemeldet: mail 2026-09-21 …[, webhook …]
  …
<eine oder zwei Zeilen aus dpkg.log: die Fassung und wann sie kam>
<die letzten Läufe des Nachtlaufs: Zeit und „10 Prüfung(en)" unter rc.15, „9" davor>
Nachtlauf: Streuung 1h, Genauigkeit 1min · nächster <Zeitpunkt>
Datenbank p1139_b9: 0 · /var/tmp/b9-tls: fehlt
Keine ungebrauchten Zertifikate.
```

- **Jede Adresse unter „Empfänger der Kundenmail" ist eine, die der Betreiber
  liest, oder sie ist reserviert.** Gelesen wird die volle Zeile, nicht ihr
  Anfang. **Steht dort eine Adresse, die `NICHT reserviert` ist und die der
  Betreiber nicht liest, kommt vor das Update nichts anderes:** Die
  Anmeldeadresse wird auf eine gestellt, die er liest, wie für Konto 6 in
  `docs/141 §7`, N1 (Passwort über `srvpanel tinker`, Adresse unter
  „Mein Konto"). Ist das Update schon gelaufen, gilt dieselbe Reihenfolge, und
  die Frist steht in §0 Punkt 1.
- **Konto 6 trägt seit dem 6. Oktober eine Adresse, die der Betreiber liest**
  (`docs/141 §7`, N1). Steht dort eine andere, wird nachgesehen, bevor der Lauf
  beginnt.
- **Die Adresse unter „Betreiber" ist nicht die des Kunden.** Sind beide
  gleich, unterscheidet der Betreff (§0 Punkt 7), und das gehört ins
  Protokoll.
- **„Hochladen" und „Sicherungen automatisch" mit dem Kontingent entscheiden
  zwei Sätze der Mail** (§6): „Sie können es auf der Seite der Domain im Panel
  hochladen." oder „Hochladen kann es Ihr Anbieter.", und „Die nächste
  Sicherung legt das Panel in der kommenden Nacht von selbst an." nur bei
  `ja` und einem Kontingent über null. Beide Werte werden für §3 abgeschrieben.
- **Unter „Befunde insgesamt" steht `tls.file / expired / p6-b.invalid`**, und
  zwar bis zum ersten Lauf nach dem Update; danach dieselben zwei Zeilen als
  `tls.expiry`. Steht dort noch eine Zeile `tls.file / expiring` für ein
  Zertifikat von Let's Encrypt, gilt für sie dasselbe, mit der neuen Schwelle
  von 28 Tagen (`docs/142 §10`). Ob die alte Zeile über den Webhook gemeldet
  ist, entscheidet, ob Punkt 1 eine Entwarnung erwartet.
- **Jeder andere Befund, der noch eine Mail bekommt, gehört zum
  Ausgangsbestand** und wird abgeschrieben. Fällt er in einen Lauf unten,
  zählt die Zeile `mail: …` ihn mit; entscheidend ist dann `befunde`.
- **`Nachtlauf: Streuung 1h, Genauigkeit 1min`** trägt die Grenze für T0 in
  §2, wie in B5.
- **`Keine ungebrauchten Zertifikate.`** Steht dort etwas, nimmt Punkt 11 es
  beim Abräumen mit; dann wird dort nicht bestätigt, bevor der Betreiber
  entschieden hat (§3, Punkt 11).

### Block H — die Werkzeuge

**Als Datei, wie seit B5** (`docs/141 §1`): Der Block schreibt
`/root/b9-werkzeuge.sh` und liest sie ein; in einer neuen Sitzung genügt
`. /root/b9-werkzeuge.sh`. Keine der Funktionen schreibt etwas, solange sie nur
definiert wird, und auch beim Aufruf schreibt nur `lauf`: Er fährt die
Diagnose und den Meldelauf wie in der Nacht. Punkt 11 entfernt die Datei
wieder.

`HAKEN` und `LOG` sind der Empfänger des Webhooks aus `docs/137 §2`, dieselben
Werte wie in B5.

```bash
# H · Werkzeuge als Datei — schreibt /root/b9-werkzeuge.sh und liest sie ein; die Funktionen schreiben nichts
cat > /root/b9-werkzeuge.sh <<'WERKZEUGE'
# H · Werkzeuge für docs/143 (B9) — in jeder Sitzung: . /root/b9-werkzeuge.sh
HAKEN='<domain des Empfängers>'                   # wie in docs/137 §2
LOG='/var/www/vhosts/<abonnement>/tmp/haken.log'   # wie in docs/137 §2

# Diagnose und Meldelauf wie in der Nacht — und was beide dabei sagen
lauf() {
  local start; start=$(date '+%F %T')
  echo "Lauf ab $start"
  systemctl start srvpanel-diagnose.service
  journalctl -u srvpanel-diagnose.service --since "$start" --no-pager -o cat \
    | grep -E 'gefahren,|Nachricht\(en\)|Entwarnung|ohne Empfänger|nicht eingerichtet|nicht angekommen|nicht durchgelaufen'
}

# Die Befunde zu Zertifikat und Sicherung an den drei Orten des Laufs, mit ihren Buchungen — misst nichts
befunde() {
  srvpanel tinker --execute='
    $orte = ["p6-abnahme.invalid", "b9.p6-abnahme.invalid", "p6-b.invalid"];
    $f = App\Models\Finding::query()->with("notifications")->whereIn("check", ["tls.file", "tls.expiry", "tls.wire", "backup.latest"])->whereIn("subject", $orte)->orderBy("check")->orderBy("subject")->orderBy("reason")->get();
    printf("Befunde: %d\n", $f->count());
    foreach ($f as $b) {
      printf("  %-13s %-22s %-8s seit %s · fällig ab %s · zuletzt %s\n", $b->check->value, $b->subject, $b->reason, App\Support\Time\Clock::display($b->first_seen_at), App\Support\Time\Clock::display(App\Support\Notify\Notices::dueAt($b)) ?? "—", App\Support\Time\Clock::display($b->measured_at));
      printf("      %s\n", $b->detail ?? "—");
      foreach ($b->notifications->sortBy("channel") as $n) printf("      gemeldet über %-7s %s\n", $n->channel, App\Support\Time\Clock::display($n->notified_at));
    }
    printf("Entwarnungen, die noch ausstehen: %d\n", App\Models\FindingResolution::query()->whereIn("subject", $orte)->count());
  '
}

# Die jüngsten Sicherungen und Vorgänge von p6-abnahme.invalid — misst nichts
vorgaenge() {
  srvpanel tinker --execute='
    app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(function () {
      $abo = App\Models\Subscription::query()->where("name", "p6-abnahme.invalid")->firstOrFail();
      foreach (App\Models\Operation::query()->where("subscription_id", $abo->id)->orderByDesc("id")->limit(5)->get()->reverse() as $o) {
        printf("Vorgang %d · %-21s · %-9s · %s\n", $o->id, $o->type, $o->status->value, $o->message ?? "—");
      }
      foreach (App\Models\Backup::query()->where("subscription_id", $abo->id)->orderByDesc("id")->limit(3)->get()->reverse() as $b) {
        printf("Sicherung %d · %-8s · erstellt %s · %s\n", $b->id, $b->status->value, App\Support\Time\Clock::display($b->created_at), $b->last_error ?? "—");
      }
      $gut = App\Support\Diagnose\Checks\LatestBackups::latest($abo, App\Support\Diagnose\Checks\LatestBackups::SUCCEEDED);
      printf("Jüngste gelungene: %s\n", $gut === null ? "keine" : App\Support\Time\Clock::display($gut->created_at));
    });
  '
}

# Die Mail an ein Abonnement, wie der Kanal sie aus den Befunden baut — verschickt nichts
kundenmail() {
  ABO="$1" srvpanel tinker --execute='
    $abo = (string) getenv("ABO");
    $facts = app(App\Support\Notify\CustomerFacts::class);
    $f = App\Models\Finding::query()->whereIn("check", array_map(fn ($c) => $c->value, App\Support\Notify\MailChannel::CUSTOMER))->orderBy("check")->orderBy("subject")->orderBy("reason")->get()->filter(fn ($x) => $facts->subscriptionOf($x->check, $x->subject) === $abo)->values()->all();
    if ($f === []) {
      echo "Keine Befunde zu $abo — es gibt keine Mail.\n";
    } else {
      $m = new App\Mail\CustomerNotice($abo, $facts->sections($abo, $f));
      $t = $m->render();
      $l = array_map("mb_strlen", explode("\n", $t));
      printf("Betreff: %s\nZeilen: %d · die längste: %d Zeichen\n---\n%s", $m->envelope()->subject, count($l), max($l), $t);
    }
  '
}

# Die letzten n Meldungen des Empfängers — was der Webhook gebracht hat
haken() {
  printf 'Empfängerprotokoll: %s Zeile(n)\n' "$(wc -l < "$LOG")"
  tail -n "${1:-1}" "$LOG" | cut -f3- | jq -c '{kind: .event.kind, subject: .event.subject, befunde: [.event.findings[] | [.check, .reason]], seit: [.event.findings[].since], satz: [.event.findings[].label]}'
}
WERKZEUGE
. /root/b9-werkzeuge.sh
printf 'Werkzeuge: %s von 5\n' "$(declare -F | awk '{print $3}' | grep -cxE 'lauf|befunde|vorgaenge|kundenmail|haken')"
```

**Erwartet:** `Werkzeuge: 5 von 5`.

- **`lauf` fährt nur die Diagnose.** Sie ist `Type=oneshot` mit zwei
  `ExecStart`, Prüfungen und Meldelauf; `systemctl start` kehrt zurück, wenn
  beide fertig sind. Die Messung der Datenbanken braucht dieser Lauf nicht, sie
  läuft ohnehin alle fünfzehn Minuten. Unter `rc.15` druckt er
  `10 Prüfung(en) gefahren`, unter `rc.14` waren es neun: `backup.latest` ist
  die zehnte.
- **`kundenmail` baut die Mail aus allen Befunden des Abonnements**, die zu
  einer Prüfung des Kunden gehören, gleich ob fällig oder gemeldet. Der
  Meldelauf nimmt nur die fälligen und noch nicht gemeldeten. Verglichen wird
  deshalb mit der Mail, die gerade hinausging, und nicht davor.
- **Das Abonnement reist als Umgebungsvariable** in `srvpanel tinker`, wie das
  Passwort in `docs/141 §7` (N1); `setpriv` und der Starter lassen sie stehen.

### Block 0b — nimmt der Empfänger an?

Wie in `docs/137 §2` und `docs/141 §1`; schreibt eine Zeile in sein Protokoll
und sonst nichts:

```bash
# 0b · Nimmt der Empfänger an? — schreibt eine Zeile in sein Protokoll
. /root/b9-werkzeuge.sh
ZEILEN=$(wc -l < "$LOG")
curl -sS -o /dev/null -m 10 -w '%{http_code}\n' -X POST -d '{"probe":"b9"}' "https://$HAKEN/haken/"
printf 'Empfängerprotokoll: %s -> %s Zeile(n)\n' "$ZEILEN" "$(wc -l < "$LOG")"
```

**Erwartet:** `204` und eine Zeile mehr.

---

## §2 · Die Prüfkörper und der Zeitplan

### Der Zeitplan

| Teil | Wann | Was |
|---|---|---|
| — | vor dem Update | Block 0; die Empfänger von `p6-b.invalid` nachsehen (§1) |
| — | das Update auf `0.9.0-rc.15` | danach Block 0 noch einmal, Block H, 0b |
| 1 | Tag 1, **T0 nicht vor 05:05** | Punkte 1 bis 5 |
| — | die Nacht dazwischen | Punkt 6: der Nachtlauf meldet das Zertifikat nicht |
| 2 | Tag 2, ab **T0 + 20 h** | Punkte 7 bis 10 |
| 3 | Tag 2, danach | Punkt 11, der Rückweg |

**T0 ist der Lauf von Punkt 5**, nach dem Hochladen. Der Nachtlauf wird
zwischen 00:00 und 01:00 fällig und feuert spätestens um 01:01
(`docs/141 §2`); liegt T0 nach 05:05, kommt er sicher **vor** der Haltezeit.
Das misst Punkt 6.

**Zwischen T0 und Punkt 7 läuft kein `lauf`.** Ab T0 + 20 h ist das Zertifikat
fällig; der erste Meldelauf danach schickt es. Steht dann die gescheiterte
Sicherung noch nicht da, kommt es allein, und die Mail mit zwei Abschnitten
ist nicht mehr herzustellen. Punkt 7 lässt deshalb zuerst die Sicherung
scheitern und fährt erst danach den Lauf.

**Das Update gehört vor Teil 1 und nicht in die Nacht davor**, wenn es sich
einrichten lässt. Dann ist Punkt 1 ein Lauf von Hand, und die Mail an
`p6-b.invalid` wird zwanzig Stunden danach fällig, also mit der Mail aus
Punkt 7 im selben Lauf. Lief das Update früher, kam der Übergang mit einem
Nachtlauf, und die Mail an `p6-b.invalid` kommt dann womöglich in der Nacht vor
Teil 2. Punkt 1 liest den Übergang in diesem Fall aus Journal und Empfänger,
und Punkt 6 und Punkt 7 sagen, wie sich die Zahlen dann ändern.

### Die Prüfkörper

Alle am Abonnement **`p6-abnahme.invalid`** (`/subscriptions/140`,
Systembenutzer `p1139`), dem Prüfstand der B-Läufe. Nicht an `p6-b.invalid`:
Dort misst `docs/138` Teil 2 im Oktober.

| Prüfkörper | Wo | Was er herstellt |
|---|---|---|
| Datenbank `b9` (`p1139_b9`) | im Panel angelegt, MariaDB, ohne Zugang | den Gegenstand des Dumps |
| Sicht `b9_kaputt` | in `p1139_b9`, als root angelegt; ihre Tabelle `b9_quelle` gelöscht | einen Dump, der mit Fehler 1356 abbricht, und damit eine gescheiterte Sicherung |
| Subdomain `b9.p6-abnahme.invalid` | im Panel angelegt, PHP wie die Hauptdomain | den Ort des Zertifikats |
| Zertifikat für 20 Tage | `/var/tmp/b9-tls`, auf dem Server erzeugt, über `srvpanel tls --upload` abgelegt | `tls.expiry / expiring`, gemeldet ab 30 Tagen |
| Zertifikat für 365 Tage | ebenda | den Rückweg des Zertifikats in Punkt 10 |

**Was das am Bestand hinterlässt.** Zwei gescheiterte Sicherungen bleiben in
der Liste von `p6-abnahme.invalid`, denn die Aufbewahrung räumt gescheiterte
nicht ab (`Retention`). Die gelungenen aus den Punkten 4 und 9 zählen zum
Kontingent, und der nächtliche Lauf räumt die ältesten ab, wenn es überschritten
ist. Unter „Vorgänge" bleiben die Vorgänge des Laufs stehen, darunter die
abgewiesene Bestellung bei Let's Encrypt. `p6-b.invalid` trägt danach
`tls.expiry` statt `tls.file`, gemeldet.

### Die Prüfkörper anlegen

Die Datenbank in Teil 1, vor Punkt 2: **`/subscriptions/140/databases/create`**,
Name `b9`, MariaDB, ohne Zugang. Das Formular nennt das Abonnement und das
Präfix `p1139_`. Nicht über `/databases`: Dort steht die Auswahl auf dem ersten
Abonnement des Betrachters (`docs/141 §7`, Befund 5).

```bash
# Die Datenbank ist angekommen — liest nur
mariadb -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'p1139_b9'"
```

**Erwartet:** `p1139_b9`.

Die Subdomain und die Zertifikate entstehen in den Punkten 5 und 10.

---

## §3 · Die Punkte

### Teil 1

#### Punkt 1 — Der Übergang: die alte Zeile wird lesbar entwarnt

`docs/142 §10`, Punkt 4. Gebaut ist er für genau diesen Augenblick, und er
kommt auf jedem Server einmal.

**Fall A — seit dem Update ist noch kein Lauf gefahren.** Block 0 zeigt dann
noch `tls.file / expired / p6-b.invalid`.

1a. **Die Seite lädt mit der alten Zeile.** `/diagnose` im Browser, dann in der
    Konsole:

```js
// Was „Diagnose" zu den drei Orten des Laufs sagt — liest nur
(() => {
  const orte = ['p6-abnahme.invalid', 'b9.p6-abnahme.invalid', 'p6-b.invalid']
  const zeilen = [...document.querySelectorAll('table.stacks tbody tr')]
  const unsere = zeilen.filter((tr) => orte.includes(tr.querySelector('td[data-column="Ort"]')?.textContent.trim()))
  console.log(`Seite ${location.pathname} · Befunde ${zeilen.length} · davon an den Orten des Laufs ${unsere.length}`)
  for (const tr of unsere) {
    const feld = (spalte) => tr.querySelector(`td[data-column="${spalte}"]`)?.textContent.trim()
    const meldung = [...tr.querySelectorAll('td[data-column="Befund"] .delivery')].map((s) => s.textContent.trim()).join(' / ')
    console.log(`  ${feld('Prüfung')} · ${feld('Ort')} · ${feld('Zustand')} → ${meldung}`)
  }
})()
```

**Erwartet:** die Seite und keine 500, darin
`Zertifikat auf dem Datenträger · p6-b.invalid · Kaputt → Gemeldet über
Mailversand: 2026-09-21 … ` und, wenn gemeldet, `/ Gemeldet über Meldeziel
(Webhook): …`. Den Satz liest die Seite aus `FindingCheck::retired()`; ohne ihn
gab sie einen 500er (`RetiredReasonTest`).

1b. **Der erste Lauf entwarnt die alte Zeile unter ihrem Schlüssel.**

```bash
# 1b · Der erste Lauf nach dem Update — meldet die Entwarnung
. /root/b9-werkzeuge.sh
lauf
befunde
haken
```

**Erwartet** im Lauf:

```
10 Prüfung(en) gefahren, <T1>.
  mail: 0 Nachricht(en) über 0 Befund(e).
  webhook: 0 Nachricht(en) über 0 Befund(e).
  webhook: 1 Entwarnung(en) verschickt.
```

In `befunde`:

```
Befunde: 2
  tls.expiry    p6-b.invalid           expired  seit <T1> · fällig ab <T1 + 20 h> · zuletzt <T1>
      gültig bis 2026-09-13 19:10 UTC
  tls.expiry    p6-b.invalid           expiring seit <T1> · fällig ab <T1 + 20 h> · zuletzt <T1>
      gültig bis 2026-09-13 19:10 UTC
Entwarnungen, die noch ausstehen: 0
```

In `haken` die Entwarnung unter dem **alten** Schlüssel mit dem **alten** Satz:

```
{"kind":"resolved","subject":"p6-b.invalid","befunde":[["tls.file","expired"]],"seit":[null],"satz":["Das Zertifikat ist abgelaufen."]}
```

- **`T1 + 20 h` wird abgeschrieben.** Ab dann ist die Mail an `p6-b.invalid`
  fällig (Punkt 7).
- **War die alte Zeile nicht über den Webhook gemeldet** (Block 0), gibt es
  nichts zu entwarnen: keine Zeile `Entwarnung`, und `haken` bleibt beim
  Stand von 0b. Das ist dann kein Ausfall.
- **Keine Mail.** Die Mail kennt keine Entwarnung, und die neuen Befunde warten
  ihre Haltezeit ab.

**Fall B — ein Nachtlauf war schneller.** Dann steht in Block 0 schon
`tls.expiry` an `p6-b.invalid`, und der Übergang wird nachgelesen:

```bash
# 1 (Fall B) · Was der erste Lauf nach dem Update gesagt hat — liest nur
. /root/b9-werkzeuge.sh
journalctl -u srvpanel-diagnose.service --since '<Zeit des Updates aus Block 0>' --no-pager -o short-iso | grep -E 'gefahren,|Nachricht\(en\)|Entwarnung' | head -n 4
grep '"resolved"' "$LOG" | tail -n 1 | cut -f3- | jq -c '{kind: .event.kind, subject: .event.subject, befunde: [.event.findings[] | [.check, .reason]], satz: [.event.findings[].label]}'
befunde
```

**Erwartet:** der erste Lauf nach dem Update mit `10 Prüfung(en)` und
`webhook: 1 Entwarnung(en) verschickt.`, im Protokoll des Empfängers dieselbe
Zeile wie in Fall A, und in `befunde` die beiden `tls.expiry` mit `seit` gleich
dem Zeitpunkt dieses Laufs. Die Seite mit der alten Zeile (1a) ist dann nicht
mehr herzustellen; das gehört ins Protokoll und ist kein Ausfall.

> **Was behoben ist, lässt sich nicht mehr kaputt vorführen.**

#### Punkt 2 — Eine gescheiterte Sicherung bringt sofort genau eine Mail

Die Datenbank `p1139_b9` ist angelegt (§2).

```bash
# 2a · Die Sicht, an der der Dump scheitert — schreibt in p1139_b9
mariadb p1139_b9 -e "CREATE TABLE b9_quelle (a INT); CREATE VIEW b9_kaputt AS SELECT a FROM b9_quelle; DROP TABLE b9_quelle;"
mariadb -N p1139_b9 -e "SHOW FULL TABLES"
```

**Erwartet:** `b9_kaputt	VIEW` und sonst nichts.

Dann im Panel **`/subscriptions/140/backups` → „Jetzt sichern"**. Der Klick
reiht zwei Vorgänge ein, den Dump und die Sicherung; sie laufen in dieser
Reihenfolge.

```bash
# 2b · Was aus der Sicherung geworden ist — liest nur
. /root/b9-werkzeuge.sh
vorgaenge
```

**Erwartet**, sobald beide fertig sind:

```
Vorgang <d> · db.dump.create        · failed    · Die Sicherung ist gescheitert: mysqldump: Couldn't execute 'SHOW FIELDS FROM `b9_kaputt`': View 'p1139_b9.b9_kaputt' references invalid table(s) or column(s) or function(s) or definer/invoker of view lack rights to use them (1356)
Vorgang <d+1> · backup.create       · failed    · Der Dump <Dump> der Datenbank p1139_b9 liegt nicht — die Sicherung wäre ohne ihre Datenbanken und von einer vollständigen nicht zu unterscheiden.
…
Sicherung <s> · failed   · erstellt <Klick> · Der Dump <Dump> der Datenbank p1139_b9 liegt nicht — …
Jüngste gelungene: <Zeitpunkt>
```

`<Dump>` heisst `p1139-b9-<JJJJMMTT>-<hhmmss>-<acht Hexziffern>`, mit der Zeit
in UTC. Steht ein Vorgang noch auf `queued` oder `running`, wird `vorgaenge`
wiederholt. **`Jüngste gelungene` wird abgeschrieben**; die Mail nennt sie.

```bash
# 2c · Der Lauf nach dem Scheitern — verschickt die Mail
. /root/b9-werkzeuge.sh
lauf
befunde
haken
kundenmail p6-abnahme.invalid
```

**Erwartet** im Lauf:

```
10 Prüfung(en) gefahren, <T2>.
  mail: 1 Nachricht(en) über 1 Befund(e).
  webhook: 1 Nachricht(en) über 1 Befund(e).
```

In `befunde`, über den beiden Zeilen von `p6-b.invalid` aus Punkt 1:

```
  backup.latest p6-abnahme.invalid     failed   seit <T2> · fällig ab <T2> · zuletzt <T2>
      erstellt <Klick> CEST (UTC+02:00): Der Dump <Dump> der Datenbank p1139_b9 liegt nicht — die Sicherung wäre ohne ihre Datenbanken und von einer vollständigen nicht zu unterscheiden.
      gemeldet über mail    <T2>
      gemeldet über webhook <T2>
```

- **`fällig ab` ist `seit`**: keine Haltezeit (`docs/142 §6`, Frage 3).
- **`<Klick>` ist die Minute, in der die Zeile der Sicherung entstand**, also
  der Klick auf „Jetzt sichern".

In `haken`:

```
{"kind":"findings","subject":"p6-abnahme.invalid","befunde":[["backup.latest","failed"]],"seit":["<T2 in UTC>"],"satz":["Die jüngste Sicherung dieses Abonnements ist fehlgeschlagen."]}
```

`kundenmail p6-abnahme.invalid` druckt den Betreff
`<Marke> — Sicherung fehlgeschlagen: p6-abnahme.invalid`, `Zeilen: 21` mit dem
Satz über die nächste Nacht oder `Zeilen: 19` ohne ihn, die längste mit
`76 Zeichen`, und den Text aus §6, mit den Zeiten dieses Laufs.

**Im Postfach des Kunden: genau eine Mail**, in `An` nur seine Adresse, der
Betreff wie oben und der Text Wort für Wort wie in `kundenmail`. **Im Postfach
des Betreibers: keine** Mail `… neuer Befund auf …` zu diesem Lauf.

**Auf der Seite**, `/diagnose` neu geladen und das Snippet aus Punkt 1:

```
  Jüngste Sicherung · p6-abnahme.invalid · Kaputt → Gemeldet über Mailversand: <T2> / Gemeldet über Meldeziel (Webhook): <T2>
  Laufzeit eines Zertifikats · p6-b.invalid · Kaputt → Gemeldet wird ab <T1 + 20 h>.
  Laufzeit eines Zertifikats · p6-b.invalid · Auffällig → Gemeldet wird ab <T1 + 20 h>.
```

Die Zeiten sind die Buchungen aus `befunde`, auf die Sekunde; die Reihenfolge
der Zeilen ist die der Seite (nach Prüfung und Ort, `docs/141 §7`, Befund 2).

#### Punkt 3 — Der nächste Lauf schickt keine zweite

```bash
# 3 · Ein Lauf nach der Mail — meldet nichts mehr
. /root/b9-werkzeuge.sh
lauf
befunde
```

**Erwartet:** `mail: 0 Nachricht(en) über 0 Befund(e).` und
`webhook: 0 Nachricht(en) über 0 Befund(e).`. In `befunde` dieselben
Buchungen mit denselben Zeiten, nur `zuletzt` ist neu. **Im Postfach kommt
nichts dazu**; das liest der Betreiber ein paar Minuten danach nach.

#### Punkt 4 — Eine gelungene Sicherung nimmt den Befund zurück

```bash
# 4a · Die Sicht entfernen — schreibt in p1139_b9
mariadb p1139_b9 -e "DROP VIEW b9_kaputt"
mariadb -N p1139_b9 -e "SHOW FULL TABLES" | wc -l
```

**Erwartet:** `0`. Dann im Panel noch einmal **„Jetzt sichern"**.

```bash
# 4b · Die zweite Sicherung und der Lauf danach — meldet die Entwarnung
. /root/b9-werkzeuge.sh
vorgaenge
lauf
befunde
haken
```

**Erwartet:** in `vorgaenge` beide neuen Vorgänge `succeeded`, die Sicherung
`ready` und `Jüngste gelungene: <Klick>`. Steht noch einer auf `queued` oder
`running`, wird `vorgaenge` vor dem `lauf` wiederholt. Im Lauf:

```
10 Prüfung(en) gefahren, <T3>.
  mail: 0 Nachricht(en) über 0 Befund(e).
  webhook: 0 Nachricht(en) über 0 Befund(e).
  webhook: 1 Entwarnung(en) verschickt.
```

In `befunde` keine Zeile `backup.latest` mehr, die beiden von `p6-b.invalid`
unverändert. In `haken`:

```
{"kind":"resolved","subject":"p6-abnahme.invalid","befunde":[["backup.latest","failed"]],"seit":[null],"satz":["Die jüngste Sicherung dieses Abonnements ist fehlgeschlagen."]}
```

**Im Postfach nichts:** Die Mail kennt keine Entwarnung (`docs/142 §7`).

#### Punkt 5 — T0: ein hochgeladenes Zertifikat, das in zwanzig Tagen abläuft

**Nicht vor 05:05** (§2). Zuerst die Subdomain, im Panel
**`/subscriptions/140/domains/create`**: Sorte „Subdomain", Gehört zu
`p6-abnahme.invalid`, Name `b9.p6-abnahme.invalid`, alles andere wie
vorgeschlagen. Die Seite zeigt darüber, wie viele Subdomains das Kontingent
erlaubt.

```bash
# 5a · Die Subdomain ist angelegt — liest nur
. /root/b9-werkzeuge.sh
vorgaenge
```

**Erwartet:** `web.site.apply` `succeeded` für die Subdomain und danach
`acme.certificate.issue` `failed` mit der Abweisung von Let's Encrypt (§0
Punkt 3). Steht die Bestellung noch auf `queued` oder `running`, wird
gewartet; sie endet in Sekunden.

```bash
# 5b · Schlüssel und Zertifikat für zwanzig Tage, abgelegt für die Subdomain — schreibt /var/tmp/b9-tls und das Zertifikat
install -d -m 0700 /var/tmp/b9-tls
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 20 \
  -subj '/CN=b9.p6-abnahme.invalid' -addext 'subjectAltName=DNS:b9.p6-abnahme.invalid' \
  -keyout /var/tmp/b9-tls/schluessel-20.pem -out /var/tmp/b9-tls/zertifikat-20.pem
chown -R srvpanel:srvpanel /var/tmp/b9-tls
openssl x509 -in /var/tmp/b9-tls/zertifikat-20.pem -noout -enddate -text | grep -E 'notAfter=|DNS:'
srvpanel tls --upload --domain=b9.p6-abnahme.invalid --certificate=/var/tmp/b9-tls/zertifikat-20.pem --key=/var/tmp/b9-tls/schluessel-20.pem
```

**Erwartet:**

```
notAfter=<heute + 20 Tage, Uhrzeit> GMT
                DNS:b9.p6-abnahme.invalid
Abgelegt für b9.p6-abnahme.invalid: b9.p6-abnahme.invalid (gültig bis <TT.MM.JJJJ>).
  Der Server-Block wird neu geschrieben.
```

**Endet das Kommando mit „nicht lesbar"**, gehört eine der beiden Dateien nicht
`srvpanel`; das Kommando nennt den Benutzer. Die Uhrzeit aus `notAfter` wird
abgeschrieben: Der Befund nennt sie auf die Minute.

```bash
# 5c · T0 — der Lauf nach dem Hochladen
. /root/b9-werkzeuge.sh
vorgaenge
lauf
befunde
kundenmail p6-abnahme.invalid
```

**Erwartet:** in `vorgaenge` ein neues `web.site.apply` `succeeded`, und keine
neue Bestellung: Das hochgeladene Zertifikat deckt den Namen. Im Lauf
`mail: 0 Nachricht(en) über 0 Befund(e).` und
`webhook: 0 Nachricht(en) über 0 Befund(e).`. In `befunde`:

```
  tls.expiry    b9.p6-abnahme.invalid  expiring seit <T0> · fällig ab <T0 + 20 h> · zuletzt <T0>
      gültig bis <notAfter, JJJJ-MM-TT hh:mm> UTC
```

- **`seit` ist T0.** Er wird abgeschrieben, samt `fällig ab`.
- **Kein `tls.file` und kein `tls.wire` an der Subdomain.** Die Datei deckt den
  Namen, und die Leitung wird nicht gefragt, solange die Zeit einen Befund hat
  (`Certificates::judge()`).
- **Nur `expiring`, kein `expired`.**

`kundenmail p6-abnahme.invalid` zeigt den Betreff
`<Marke> — Zertifikat läuft ab: p6-abnahme.invalid` und den Abschnitt zum
Zertifikat mit `Gültig bis: <…> CET (UTC+01:00)` (§0 Punkt 5). Auf der Seite
sagt das Snippet `Laufzeit eines Zertifikats · b9.p6-abnahme.invalid ·
Auffällig → Gemeldet wird ab <T0 + 20 h>.`, auf die Sekunde wie `fällig ab`.

### Die Nacht dazwischen

#### Punkt 6 — Der Nachtlauf meldet das Zertifikat nicht

Am Morgen von Tag 2, **vor** T0 + 20 h:

```bash
# 6 · Was der Nachtlauf gesagt hat — liest nur
. /root/b9-werkzeuge.sh
journalctl -u srvpanel-diagnose.service --since '<Tag 2> 00:00' --until '<Tag 2> 02:00' --no-pager -o short-iso \
  | grep -E 'gefahren,|Nachricht\(en\)|Entwarnung|ohne Empfänger|nicht eingerichtet'
befunde
```

**Erwartet:** genau ein Lauf zwischen 00:00 und 01:01 mit
`mail: 0 Nachricht(en) über 0 Befund(e).` und
`webhook: 0 Nachricht(en) über 0 Befund(e).`. In `befunde` die Zeile der
Subdomain mit `seit <T0>` wie gestern, neuem `zuletzt` und **ohne** Buchung.

**Lag `T1 + 20 h` vor diesem Nachtlauf** (Punkt 1, Fall B), kommt in dieser
Nacht die Mail an `p6-b.invalid`: `mail: 1 Nachricht(en) über 2 Befund(e).` und
`webhook: 1 Nachricht(en) über 2 Befund(e).`, und die beiden Zeilen von
`p6-b.invalid` tragen ihre Buchungen. Die Zeile der Subdomain bleibt trotzdem
ohne. Was in dieser Mail steht, misst Punkt 7 mit `kundenmail p6-b.invalid`.

### Teil 2 — ab T0 + 20 h

#### Punkt 7 — Beide Zustände in einer Mail

**Zuerst die Sicherung, dann der Lauf** (§2):

```bash
# 7a · Die Sicht wieder anlegen — schreibt in p1139_b9
mariadb p1139_b9 -e "CREATE TABLE b9_quelle (a INT); CREATE VIEW b9_kaputt AS SELECT a FROM b9_quelle; DROP TABLE b9_quelle;"
mariadb -N p1139_b9 -e "SHOW FULL TABLES"
```

**Erwartet:** `b9_kaputt	VIEW`. Dann im Panel **„Jetzt sichern"**, und:

```bash
# 7b · Die Sicherung ist gescheitert — liest nur
. /root/b9-werkzeuge.sh
vorgaenge
```

**Erwartet:** wie in 2b, mit einem neuen `<Dump>`; beide Vorgänge `failed`.
**Erst wenn beide fertig sind:**

```bash
# 7c · Der Lauf — verschickt die Mail mit zwei Abschnitten
. /root/b9-werkzeuge.sh
lauf
befunde
haken 3
kundenmail p6-abnahme.invalid
kundenmail p6-b.invalid
```

**Erwartet** im Lauf, wenn die Mail an `p6-b.invalid` noch aussteht (Punkt 1,
Fall A):

```
10 Prüfung(en) gefahren, <T7>.
  mail: 2 Nachricht(en) über 4 Befund(e).
  webhook: 3 Nachricht(en) über 4 Befund(e).
```

Kam sie schon in der Nacht (Punkt 6), stehen dort
`mail: 1 Nachricht(en) über 2 Befund(e).` und
`webhook: 2 Nachricht(en) über 2 Befund(e).`.

In `befunde` tragen alle Zeilen eine Buchung über `mail` und `webhook`; die
beiden aus diesem Lauf auf `<T7>`:

```
  backup.latest p6-abnahme.invalid     failed   seit <T7> · fällig ab <T7> · zuletzt <T7>
      erstellt <Klick> CEST (UTC+02:00): Der Dump <Dump> der Datenbank p1139_b9 liegt nicht — …
      gemeldet über mail    <T7>
      gemeldet über webhook <T7>
  tls.expiry    b9.p6-abnahme.invalid  expiring seit <T0> · fällig ab <T0 + 20 h> · zuletzt <T7>
      gültig bis <notAfter> UTC
      gemeldet über mail    <T7>
      gemeldet über webhook <T7>
```

In `haken 3`, je Gegenstand eine Meldung, gemessen in dieser Reihenfolge:

```
{"kind":"findings","subject":"p6-abnahme.invalid","befunde":[["backup.latest","failed"]],"seit":["<T7 in UTC>"],…}
{"kind":"findings","subject":"b9.p6-abnahme.invalid","befunde":[["tls.expiry","expiring"]],"seit":["<T0 in UTC>"],…}
{"kind":"findings","subject":"p6-b.invalid","befunde":[["tls.expiry","expired"],["tls.expiry","expiring"]],"seit":["<T1 in UTC>","<T1 in UTC>"],…}
```

Die dritte Zeile nur in Fall A; sonst steht dort die Meldung davor.

**Die Mail an `p6-abnahme.invalid`**, wie `kundenmail` sie druckt: Betreff
`<Marke> — Zertifikat läuft ab und Sicherung fehlgeschlagen:
p6-abnahme.invalid`, `Zeilen: 28` mit dem Satz über die nächste Nacht oder
`26` ohne ihn, die längste mit `76 Zeichen`. Erst der Abschnitt zum Zertifikat,
dann der zur Sicherung, dann einmal der Satz „Sie bekommen diese Nachricht
einmal je Zustand." (§6). „Die jüngste gelungene Sicherung" nennt die aus
Punkt 4, wenn die Nacht keine neue angelegt hat; `vorgaenge` aus 7b sagt es.

**Die Mail an `p6-b.invalid`**: Betreff `<Marke> — Zertifikat abgelaufen:
p6-b.invalid`, `Zeilen: 20`, ein Abschnitt, **nur „abgelaufen"**, obwohl
`expiring` daneben steht (`CertificateSection::shown()`), mit
`Gültig bis: 2026-09-13 21:10 CEST (UTC+02:00)` und dem Absatz „Ein
abgelaufenes Zertifikat lehnen Browser ab". Sie geht an die Empfänger aus
Block 0.

**Im Postfach von Konto 6: genau eine Mail zu `p6-abnahme.invalid`** mit zwei
Abschnitten, Wort für Wort wie in `kundenmail`. Sieht Konto 6 auch
`p6-b.invalid`, liegt daneben die zweite, mit ihrem eigenen Betreff. **Im
Postfach des Betreibers keine** Mail `… neuer Befund auf …` zu diesem Lauf.

**Auf der Seite**, mit dem Snippet aus Punkt 1:

```
  Jüngste Sicherung · p6-abnahme.invalid · Kaputt → Gemeldet über Mailversand: <T7> / Gemeldet über Meldeziel (Webhook): <T7>
  Laufzeit eines Zertifikats · p6-b.invalid · Kaputt → Gemeldet über Mailversand: <…> / Gemeldet über Meldeziel (Webhook): <…>
  Laufzeit eines Zertifikats · b9.p6-abnahme.invalid · Auffällig → Gemeldet über Mailversand: <T7> / Gemeldet über Meldeziel (Webhook): <T7>
  Laufzeit eines Zertifikats · p6-b.invalid · Auffällig → Gemeldet über Mailversand: <…> / Gemeldet über Meldeziel (Webhook): <…>
```

#### Punkt 8 — Der nächste Lauf schickt keine zweite

```bash
# 8 · Ein Lauf nach der Mail — meldet nichts mehr
. /root/b9-werkzeuge.sh
lauf
befunde
```

**Erwartet:** `mail: 0 Nachricht(en) über 0 Befund(e).` und
`webhook: 0 Nachricht(en) über 0 Befund(e).`, in `befunde` dieselben
Buchungen, und im Postfach nach ein paar Minuten nichts Neues.

#### Punkt 9 — Die gelungene Sicherung nimmt ihren Befund zurück, das Zertifikat bleibt

```bash
# 9a · Die Sicht entfernen — schreibt in p1139_b9
mariadb p1139_b9 -e "DROP VIEW b9_kaputt"
mariadb -N p1139_b9 -e "SHOW FULL TABLES" | wc -l
```

**Erwartet:** `0`. Dann **„Jetzt sichern"**, und wenn `vorgaenge` beide
Vorgänge `succeeded` zeigt:

```bash
# 9b · Der Lauf nach der gelungenen Sicherung — meldet die Entwarnung
. /root/b9-werkzeuge.sh
lauf
befunde
haken
```

**Erwartet:** im Lauf `mail: 0 …`, `webhook: 0 …` und
`webhook: 1 Entwarnung(en) verschickt.`; in `befunde` keine Zeile
`backup.latest`, die Zeile der Subdomain unverändert mit ihren Buchungen; in
`haken` die Entwarnung für `backup.latest` wie in Punkt 4. **Im Postfach
nichts.**

#### Punkt 10 — Ein neues Zertifikat nimmt seinen Befund zurück

Was die Mail dem Kunden rät: Die Domain braucht ein neues.

```bash
# 10a · Schlüssel und Zertifikat für ein Jahr, abgelegt für die Subdomain — schreibt /var/tmp/b9-tls und das Zertifikat
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 365 \
  -subj '/CN=b9.p6-abnahme.invalid' -addext 'subjectAltName=DNS:b9.p6-abnahme.invalid' \
  -keyout /var/tmp/b9-tls/schluessel-365.pem -out /var/tmp/b9-tls/zertifikat-365.pem
chown -R srvpanel:srvpanel /var/tmp/b9-tls
srvpanel tls --upload --domain=b9.p6-abnahme.invalid --certificate=/var/tmp/b9-tls/zertifikat-365.pem --key=/var/tmp/b9-tls/schluessel-365.pem
```

**Erwartet:** `Abgelegt für b9.p6-abnahme.invalid: b9.p6-abnahme.invalid
(gültig bis <TT.MM.JJJJ in einem Jahr>).` und `Der Server-Block wird neu
geschrieben.`

```bash
# 10b · Liefert der Server das neue aus? Dann der Lauf — meldet die Entwarnung
. /root/b9-werkzeuge.sh
vorgaenge
openssl s_client -connect 127.0.0.1:443 -servername b9.p6-abnahme.invalid </dev/null 2>/dev/null | openssl x509 -noout -enddate
lauf
befunde
haken
```

**Erwartet:** in `vorgaenge` das neue `web.site.apply` `succeeded`, **erst
dann** weiter. `openssl s_client` zeigt `notAfter=` in einem Jahr: Der Server
liefert das neue aus. Das ist die Bedingung dafür, dass der Lauf keinen Befund
`tls.wire` schreibt, denn jetzt hat die Zeit keinen mehr, und die Leitung wird
gefragt.

Im Lauf `mail: 0 …`, `webhook: 0 …` und
`webhook: 1 Entwarnung(en) verschickt.`. In `befunde` nur noch die beiden
Zeilen von `p6-b.invalid`. In `haken`:

```
{"kind":"resolved","subject":"b9.p6-abnahme.invalid","befunde":[["tls.expiry","expiring"]],"seit":[null],"satz":["Das Zertifikat läuft demnächst ab."]}
```

**Steht `notAfter` noch auf zwanzig Tagen**, ist der Server-Block nicht neu
geschrieben; dann wird nicht gefahren, sondern auf den Vorgang gewartet.
Liefe der Lauf trotzdem, stünde dort `tls.wire / not_served`, ein Befund für
den Betreiber, und zwar zu Recht.

### Teil 3 — zurück

#### Punkt 11 — Der Rückweg

Im Panel, in dieser Reihenfolge:

1. **`/subscriptions/140` → Datenbanken:** `p1139_b9` entfernen.
2. **Die Seite der Domain `b9.p6-abnahme.invalid` → Entfernen**, mit der
   Rückfrage, die den Pfad nennt. Weiter erst, wenn `vorgaenge` den Vorgang
   `web.site.remove` als `succeeded` zeigt.

```bash
# 11a · Was abgeräumt würde — liest nur
srvpanel tls --prune --dry-run
```

**Erwartet:**

```
0 verwaiste Zeile(n), 2 Zeile(n) ohne Domain, 1 Ablageort(e) zu entfernen.
  _uploaded.b9.p6-abnahme.invalid: Ablageort und Zeile(n) — ohne Domain
--dry-run: es wurde nichts angefasst.
```

Zwei Zeilen, weil jedes Hochladen eine eigene Zeile anlegt, und ein Ablageort,
weil beide denselben benutzen. **Steht dort mehr als dieser Ablageort**, wird
11b nicht bestätigt: Was sonst dasteht, gehörte nicht zum Lauf, und ob es fort
darf, entscheidet der Betreiber.

```bash
# 11b · Das Zertifikat abräumen — schreibt; fragt zurück, Vorgabe nein
srvpanel tls --prune
```

Auf die Rückfrage `yes`. **Erwartet:** `  _uploaded.b9.p6-abnahme.invalid:
entfernt` und `Aufgeräumt.`.

```bash
# 11c · Der Lauf danach und die Reste — schreibt den Lauf, entfernt /var/tmp/b9-tls
. /root/b9-werkzeuge.sh
rm -r /var/tmp/b9-tls
lauf
befunde
srvpanel tls --prune --dry-run
mariadb -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'p1139_b9'"
ls -d /var/tmp/b9-tls
```

**Erwartet:** im Lauf `mail: 0 …` und `webhook: 0 …`, ohne Entwarnung: Die
Subdomain war nicht mehr gemeldet. In `befunde` nur die beiden Zeilen von
`p6-b.invalid`, gemeldet. Dann `Keine ungebrauchten Zertifikate.`, `0` und
`No such file or directory`. Auf der Seite sagt das Snippet `davon an den
Orten des Laufs 2`.

**Erst abräumen, dann laufen:** Ein Zertifikat ohne Domain meldet die Diagnose
als `orphan.row` (`Orphans`), und zwar dem Betreiber. Lief zwischen dem
Entfernen der Subdomain und 11b ein Lauf, steht dieser Befund da, und der
nächste Lauf nach 11b nimmt ihn wieder weg; gemeldet wird er erst nach
zwanzig Stunden, also nicht.

```bash
# 11d · Die Werkzeuge entfernen — schreibt
rm -f /root/b9-werkzeuge.sh
ls -la /root/b9-werkzeuge.sh
```

**Erwartet:** `No such file or directory`.

---

## §4 · Was dieser Lauf ausdrücklich nicht prüft

- **Zertifikate von Let's Encrypt.** Dass eine gelingende Erneuerung nichts
  meldet und eine scheiternde schon, zeigt ein Lauf von zwei Nächten nicht;
  eine Erneuerung steht alle sechzig Tage an. Getragen wird das von der
  Rechnung in `docs/142 §3` (M1) und von `CertificateCadenceTest`. Dazu
  gehören die Zeilen „Erneuerung fällig seit" und „Letzter Versuch" der Mail
  (`CustomerNoticeTest`).
- **Mehr als zwanzig fällige Erneuerungen in zwei Nächten** und **ein
  Platzhalter an seinen Unterdomains** (`docs/142 §10`, offen).
- **Mehrere Empfänger und ein Kunde ohne Adresse.** Gemessen im Lauf für B5
  und im Container (`QuotaRecipientTest`, `NoticeAudienceTest`); der Weg ist
  derselbe.
- **Eine Sicherung, die in der Nacht scheitert.** Frage 4 hat entschieden,
  dass die jüngste zählt, gleich wer sie angestossen hat, und der Weg in der
  Nacht ist derselbe Aufruf (`Backups::create()`). Ein Hindernis über Nacht
  stehen zu lassen, hiesse, eine gescheiterte Nachtsicherung neben die
  Messung zu legen, die Punkt 6 machen soll.
- **Ein Zertifikat, das während des Laufs abläuft.** `expiring` neben
  `expired` steht an `p6-b.invalid` da (Punkt 1); den Augenblick des Ablaufs
  hält `CertificateVerdictTest`.
- **Bilder.** Die Seite „Diagnose" ist seit B5 unverändert (`docs/141 §6a`)
  und wird hier gelesen, nicht vermessen.

---

## §5 · Wann er durch ist

- **Die Punkte 2 bis 9 tragen das Kriterium** und dürfen nicht ausfallen:
  je genau eine Mail für die gescheiterte Sicherung (2, 3) und für das
  Zertifikat (5 bis 8), beide Zustände in einer Mail (7), keine Mail an den
  Betreiber und die Zustellung auf „Diagnose" (2, 7), und eine gelungene
  Sicherung nimmt den Befund zurück (4, 9).
- **Punkt 1 darf nicht ausfallen**, ausser in Fall B, und dort nur 1a. Der
  Übergang ist die eine Abweichung des Baus vom Entwurf, die nur ein Update
  zeigen kann.
- **Punkt 10 zeigt den Rückweg des Zertifikats** und die Leitung; er gehört
  nicht zum Kriterium und wird trotzdem gefahren, vor Punkt 11.
- **Ohne Meldeziel** fallen die Webhook-Teile aus: die Zeilen `webhook`, die
  Buchungen `gemeldet über webhook`, `haken` und die Entwarnungen. Das
  Kriterium spricht von der Mail an den Kunden; der Lauf ist dann trotzdem
  durch, und im Protokoll steht, dass kein Ziel da war.
- **Liest der Betreiber das Postfach der Empfänger von `p6-b.invalid` nicht**
  und ist ihre Domain reserviert, fällt der Teil im Postfach zu dieser Mail
  aus; was das Panel dazu sagt, bleibt gemessen.
- **Punkt 11 wird gefahren**, auch wenn ein Punkt davor ausfällt. Eine
  Subdomain mit einem Wegwerfzertifikat und eine kaputte Sicht sind Reste und
  keine Prüfkörper.

---

## §6 · Vorab im Container gemessen

Am 8. Oktober 2026, gegen `main` bei `c0bba3f5`, also gegen den Stand von
`0.9.0-rc.15`.

### Die Sicherung, die scheitert

Ein Wegwerf-Server MariaDB 10.11.14 im Scratchpad, auf dem Socket, den der
Agent erwartet. Darin `p1139_b9` mit der Sicht aus Punkt 2. Zuerst
`mysqldump` mit genau den Argumenten aus `DbDumpCreate`:

```
mysqldump: Couldn't execute 'SHOW FIELDS FROM `b9_kaputt`': View 'p1139_b9.b9_kaputt' references invalid table(s) or column(s) or function(s) or definer/invoker of view lack rights to use them (1356)
mysqldump rc=2
```

Mit der kaputten Sicht gaben die Abfrage der Messung (`DbUsage::SQL`) und die
Platzprüfung des Dumps `rc=0` und für `p1139_b9` den Wert `0`. Nach
`DROP VIEW` gab `mysqldump` `rc=0`.

Dann die beiden Operationen des Agenten selbst, `db.dump.create` und
`backup.create`, aus einem Skript mit einem echten `Context` und gegen einen
nachgebauten Baum unter `/var/www/vhosts/p6-abnahme.invalid`:

```
ABBRUCH [exec_failed] Die Sicherung ist gescheitert: mysqldump: Couldn't execute 'SHOW FIELDS FROM `b9_kaputt`': View 'p1139_b9.b9_kaputt' references invalid table(s) or column(s) or function(s) or definer/invoker of view lack rights to use them (1356)
Datei /var/lib/srvpanel/dumps/p6-abnahme.invalid/p1139-b9-20261009-101500-abcd1234.sql.gz: liegt nicht
ERFOLG {"name":"p1139_b9","storage":"p1139-b9-20261009-101600-abcd1235","bytes":534}

ABBRUCH [bad_request] Der Dump p1139-b9-20261009-101500-abcd1234 der Datenbank p1139_b9 liegt nicht — die Sicherung wäre ohne ihre Datenbanken und von einer vollständigen nicht zu unterscheiden.
Länge der Meldung: 172 Zeichen
Archiv p6-abnahme-invalid-20261009-101500.zip: liegt nicht
ERFOLG {"storage":"p6-abnahme-invalid-20261009-101700","files":1,"entries":4,"databases":1,…,"bytes":1528}
```

Die beiden `ERFOLG` sind die Gegenproben: dieselben Operationen ohne die Sicht
beziehungsweise mit dem Dump, der liegt. Der Wegwerf-Server ist danach
angehalten, und `/var/lib/srvpanel`, `/var/www/vhosts` und `/run/mysqld` sind
wieder fort.

### Die Folge der Punkte

Ein Wegwerf-Test im Scratchpad mit den echten Teilen: `LatestBackups`,
`Certificates::judge()` mit den echten Zeilen der Domains, `FindingLog`, der
Meldelauf und beide Kanäle, die Seite `/diagnose` durch die Tür.
Festgehalten ist nur die Antwort des Agenten auf `acme.certificate.info` und
der Fingerabdruck der Leitung, weil hier kein Schlüssel entsteht. Die
Anzeigezone ist `Europe/Berlin`, T1 der 9. Oktober um 10:05, T0 um 11:05:

| Lauf | Zeit (CEST) | Was davor geschah | Meldelauf |
|---|---|---|---|
| 1 | 9. Okt 10:05 | das Update, alte Zeile `tls.file / expired` gemeldet | `webhook: 1 Entwarnung`; zwei `tls.expiry`, fällig ab 10. Okt 06:05 |
| 2 | 10:25 | Sicherung gescheitert | `mail: 1 über 1`, `webhook: 1 über 1` |
| 3 | 10:30 | — | nichts |
| 4 | 10:45 | Sicherung gelungen | `webhook: 1 Entwarnung` |
| 5 (T0) | 11:05 | Subdomain, Zertifikat bis 29. Okt 09:00 UTC | nichts; `expiring`, fällig ab 10. Okt 07:05 |
| 6 | 10. Okt 00:30 | die Nacht | nichts |
| 7 | 10:05 | Sicherung gescheitert | `mail: 2 über 4`, `webhook: 3 über 4` |
| 8 | 10:10 | — | nichts |
| 9 | 10:25 | Sicherung gelungen | `webhook: 1 Entwarnung` |
| 10 | 10:35 | Zertifikat für ein Jahr | `webhook: 1 Entwarnung`, kein `tls.wire` |
| 11 | 10:45 | Subdomain fort, Zertifikat abgeräumt | nichts |

**In keinem Lauf ging eine Mail an den Betreiber.** Die Entwarnung aus Lauf 1
trug den alten Schlüssel und den alten Satz,
`[["tls.file","expired"]]` mit „Das Zertifikat ist abgelaufen.".

### Die Mails

Aus Lauf 2, mit „Sicherungen automatisch: ja" und einem Kontingent über null.
Die Trennzeile der Unterschrift trägt in der Mail ein Leerzeichen am Ende
(`docs/140 §6e`), hier nicht. Betreff `SrvPanel — Sicherung fehlgeschlagen:
p6-abnahme.invalid`, 21 Zeilen, die längste 76 Zeichen:

```
Guten Tag,

für Ihr Abonnement p6-abnahme.invalid hat das Panel Folgendes festgestellt:

- Die jüngste Sicherung dieses Abonnements ist fehlgeschlagen.
  Erstellt: 2026-10-09 10:20 CEST (UTC+02:00)
  Meldung: Der Dump p1139-b9-20261009-102000-abcd1234 der Datenbank p1139_b9
  liegt nicht — die Sicherung wäre ohne ihre Datenbanken und von einer
  vollständigen nicht zu unterscheiden.

Die jüngste gelungene Sicherung dieses Abonnements ist vom 2026-10-09 01:31
CEST (UTC+02:00).

Die nächste Sicherung legt das Panel in der kommenden Nacht von selbst an.

Sie bekommen diese Nachricht einmal je Zustand. Erst wenn er vorbei ist und
wieder eintritt, meldet sich das Panel erneut.

--
SrvPanel
```

Aus Lauf 7, dieselben Einstellungen und „Hochladen: nein". Betreff
`SrvPanel — Zertifikat läuft ab und Sicherung fehlgeschlagen:
p6-abnahme.invalid`, 28 Zeilen, die längste 76 Zeichen:

```
Guten Tag,

für Ihr Abonnement p6-abnahme.invalid hat das Panel Folgendes festgestellt:

- Das Zertifikat läuft demnächst ab.
  Domain: b9.p6-abnahme.invalid
  Gültig bis: 2026-10-29 10:00 CET (UTC+01:00)

Ein hochgeladenes Zertifikat erneuert das Panel nicht; die Domain braucht
ein neues. Hochladen kann es Ihr Anbieter.

- Die jüngste Sicherung dieses Abonnements ist fehlgeschlagen.
  Erstellt: 2026-10-10 10:00 CEST (UTC+02:00)
  Meldung: Der Dump p1139-b9-20261010-100000-0123abcd der Datenbank p1139_b9
  liegt nicht — die Sicherung wäre ohne ihre Datenbanken und von einer
  vollständigen nicht zu unterscheiden.

Die jüngste gelungene Sicherung dieses Abonnements ist vom 2026-10-09 10:40
CEST (UTC+02:00).

Die nächste Sicherung legt das Panel in der kommenden Nacht von selbst an.

Sie bekommen diese Nachricht einmal je Zustand. Erst wenn er vorbei ist und
wieder eintritt, meldet sich das Panel erneut.

--
SrvPanel
```

Aus demselben Lauf an `p6-b.invalid`. Betreff `SrvPanel — Zertifikat
abgelaufen: p6-b.invalid`, 20 Zeilen, die längste 75 Zeichen:

```
Guten Tag,

für Ihr Abonnement p6-b.invalid hat das Panel Folgendes festgestellt:

- Das Zertifikat ist abgelaufen.
  Domain: p6-b.invalid
  Gültig bis: 2026-09-13 21:10 CEST (UTC+02:00)

Ein abgelaufenes Zertifikat lehnen Browser ab: Wer die Domain öffnet, sieht
eine Warnung statt Ihrer Website.

Ein hochgeladenes Zertifikat erneuert das Panel nicht; die Domain braucht
ein neues. Hochladen kann es Ihr Anbieter.

Sie bekommen diese Nachricht einmal je Zustand. Erst wenn er vorbei ist und
wieder eintritt, meldet sich das Panel erneut.

--
SrvPanel
```

**Mit „Hochladen: ja" und „Sicherungen automatisch: nein"** ist derselbe Lauf
noch einmal gefahren, und es ändern sich genau diese Zeilen: „Hochladen kann es
Ihr Anbieter." heisst „Sie können es auf der Seite der Domain im Panel
hochladen.", und der Satz über die nächste Nacht fehlt samt seiner Leerzeile.
Die Mails haben dann 19 und 26 Zeilen statt 21 und 28; die längste bleibt bei
76 Zeichen.

### Die Werkzeuge

Block H und Block 0 sind gegen eine eigene SQLite-Datenbank gefahren, mit einem
`srvpanel`, das auf `php artisan` zeigt, und mit Zeilen wie nach Punkt 2,
dazu der alten Zeile von vor dem Update. Jede Funktion druckte, was §3
erwartet. `kundenmail` druckte dieselbe Mail wie der Meldelauf in Lauf 7 und
für ein Abonnement ohne Befund `Keine Befunde zu … — es gibt keine Mail.`.
`Notices::dueAt()` warf an der alten Zeile nicht; sie hat ihr Urteil aus
`FindingCheck::retired()`. Der Abgleich der Adressen nannte `example.org`
reserviert.

**Nicht vorab gefahren** ist, was nur der Server hat: `systemctl`, das Journal
und die Zeitgeber, die Bestellung bei Let's Encrypt, `openssl req` (kein
Schlüssel in diesem Container), nginx mit dem hochgeladenen Zertifikat, der
Empfänger des Webhooks und die Postfächer.

---

## §7 · Protokoll

Noch nicht gefahren.
