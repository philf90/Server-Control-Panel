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

**Gefahren ist er vom 8. bis 10. Oktober 2026 auf `cloudsrv24`.** Alle elf
Punkte sind erfüllt, Punkt 1 in Fall B: Den Übergang hat ein Nachtlauf
gemacht, und 1a war danach nicht mehr herzustellen. Am Prüfling ist kein
Befund herausgefallen, an der Vorschrift acht. Das Protokoll ist §7, und wo
die Vorschrift danach berichtigt ist, steht „Berichtigt nach dem Lauf".
**Der Betreiber hat B9 am 10. Oktober 2026 abgenommen.**

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
   dem ersten Lauf danach eine Frist.** Seit dem 14. September steht auf
   „Diagnose" `tls.file / expired` an `p6-b.invalid`, gemeldet am 21. Es ist
   das hochgeladene Wegwerfzertifikat aus A10 (`docs/100 §6`), abgelaufen am
   13. September um 19:10 UTC. Der erste Lauf nach dem Update, ein Nachtlauf
   oder einer von Hand, entwarnt die Zeile beim Webhook unter ihrem alten
   Schlüssel und befindet die Laufzeit neu, als `tls.expiry` mit `expiring`
   und `expired`. Zwanzig Stunden danach geht an die Konten von
   `p6-b.invalid` eine Mail „Zertifikat abgelaufen" (`docs/142 §10`,
   nachgefahren in §6).

   *Berichtigt nach dem Lauf (§7, Befund 1):* Hier stand „seit dem
   21. September". An dem Tag ging die Mail hinaus; die Zeile steht seit dem
   ersten Nachtlauf nach dem Ablauf, dem 14. September um 00:49:42.

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

*Entschieden nach dem Lauf, am 10. Oktober 2026 (§7, Was aussteht):* Die
Sicherung übernimmt den Grund nicht, die Mail zeigt dorthin. Gebaut für
`0.9.0-rc.16`, endet ihr Abschnitt mit dem Satz

    Was genau gescheitert ist, zeigt das Panel unter „Vorgänge".

Gemeint ist der Menüpunkt des Kunden. Der Bereich auf der Seite des
Abonnements nennt nur die letzten zehn Vorgänge, und eine Sicherung legt je
Datenbank einen an. Jede Mail mit einem Abschnitt der Sicherung hat damit
zwei Zeilen mehr; die Zahlen in §3 und §6 gelten für `rc.15`.

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

`HAKEN` und `LOG` sind der Empfänger des Webhooks: `HAKEN` der Rechner aus der
Zeile „Ziel des Webhooks" in Block 0, `LOG` die Datei, in die er schreibt.
Den Pfad misst H0, bevor Block H ihn braucht, und 0b belegt ihn.

*Berichtigt nach dem Lauf (§7, Befund 2):* Die erste Fassung nannte für beide
„wie in `docs/137 §2`", und dort steht derselbe Platzhalter. Den Pfad nannte
kein Dokument.

```bash
# H0 · Wo schreibt der Empfänger hin? — liest nur
ls -l /var/www/vhosts/*/tmp/haken.log
wc -l /var/www/vhosts/*/tmp/haken.log
```

**Erwartet:** genau eine Datei. Auf `cloudsrv24` ist es
`/var/www/vhosts/p6-b.invalid/tmp/haken.log`, denn `cloudlab24.de` gehört zu
`p6-b.invalid`. Zeigt H0 keine oder mehrere, läuft Block H erst, wenn
nachgesehen ist, in welche Datei der Empfänger schreibt.

```bash
# H · Werkzeuge als Datei — schreibt /root/b9-werkzeuge.sh und liest sie ein; die Funktionen schreiben nichts
cat > /root/b9-werkzeuge.sh <<'WERKZEUGE'
# H · Werkzeuge für docs/143 (B9) — in jeder Sitzung: . /root/b9-werkzeuge.sh
HAKEN='cloudlab24.de'                              # Ziel des Webhooks aus Block 0
LOG='/var/www/vhosts/p6-b.invalid/tmp/haken.log'   # aus H0

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

**Erwartet:** `204` und eine Zeile mehr. Bleibt die Zahl stehen, schreibt der
Empfänger in eine andere Datei, und `LOG` ist falsch.

---

## §2 · Die Prüfkörper und der Zeitplan

### Der Zeitplan

| Teil | Wann | Was |
|---|---|---|
| — | vor dem Update | Block 0; die Empfänger von `p6-b.invalid` nachsehen (§1) |
| — | das Update auf `0.9.0-rc.15` | danach Block 0 noch einmal, Block H, 0b |
| 1 | Tag 1, **T0 nicht vor 05:05**, in Fall B vor `T1 + 20 h` | Punkte 1 bis 5 |
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

**In Fall B hat Teil 1 eine Frist.** Zwanzig Stunden nach dem Nachtlauf, der
den Übergang gemacht hat, ist die Mail an `p6-b.invalid` fällig, und jeder
Lauf danach schickt sie mit. Der Nachtlauf feuert zwischen 00:00 und 01:01,
die Frist liegt also zwischen 20:00 und 21:01 desselben Tages. Der letzte
Lauf von Teil 1, T0, gehört davor; am 9. Oktober war die Frist 20:31:34.
Liefe ein Lauf von Teil 1 danach, schickte er die Mail an `p6-b.invalid` mit,
und seine Zeilen `mail:` und `webhook:` stimmten nicht mehr.

*Berichtigt nach dem Lauf (§7, Befund 8):* Die erste Fassung sagte für
Fall B, wie sich die Zahlen ändern, und nicht, dass daraus eine Frist folgt.

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
printf 'Jetzt: %s · Timer zuletzt: %s\n' "$(date '+%F %T')" "$(systemctl show -p LastTriggerUSec --value srvpanel-diagnose.timer)"
journalctl -u srvpanel-diagnose.service --since '<Zeit des Updates aus Block 0>' --no-pager -o short-iso | grep -E 'gefahren,|Nachricht\(en\)|Entwarnung' | head -n 4
printf 'Empfängerprotokoll: %s Zeile(n)\n' "$(wc -l < "$LOG")"
grep '"resolved"' "$LOG" | tail -n 1 | cut -f3- | jq -c '{kind: .event.kind, subject: .event.subject, befunde: [.event.findings[] | [.check, .reason]], satz: [.event.findings[].label]}'
befunde
srvpanel tinker --execute='
  $alle = App\Models\Finding::query()->with("notifications")->orderBy("check")->orderBy("subject")->orderBy("reason")->get();
  printf("Befunde insgesamt: %d\n", $alle->count());
  foreach ($alle as $f) printf("  %s · %s · %s · seit %s · fällig ab %s · gemeldet: %s\n", $f->check->value, $f->subject, $f->reason, App\Support\Time\Clock::display($f->first_seen_at), App\Support\Time\Clock::display(App\Support\Notify\Notices::dueAt($f)) ?? "—", $f->notifications->sortBy("channel")->map(fn ($n) => $n->channel." ".App\Support\Time\Clock::display($n->notified_at))->implode(", ") ?: "—");
'
```

**Erwartet:** `Timer zuletzt` auf dem Termin aus Block 0, der erste Lauf nach
dem Update auf derselben Sekunde mit `10 Prüfung(en)` und
`webhook: 1 Entwarnung(en) verschickt.`, im Protokoll des Empfängers dieselbe
Zeile wie in Fall A, und in `befunde` die beiden `tls.expiry` mit `seit` gleich
dem Zeitpunkt dieses Laufs. Er ist T1, und `T1 + 20 h` ist die Frist für
Teil 1 (§2). Die Seite mit der alten Zeile (1a) ist dann nicht mehr
herzustellen; das gehört ins Protokoll und ist kein Ausfall.

*Ergänzt nach dem Lauf:* Gefahren ist der Block mit drei Ablesungen mehr als
in der ersten Fassung, der Zeit des Timers, den Zeilen beim Empfänger und den
Befunden über den ganzen Server. Die Zeit des Timers trennt einen Nachtlauf
von einem Lauf von Hand, und die Zählung zeigt, dass der Übergang sonst nichts
angefasst hat.

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
- *Berichtigt nach dem Lauf (§7, Befund 7):* **Die Buchungen tragen die Zeit
  des Meldelaufs.** Er ist das zweite `ExecStart` der Unit und ein eigener
  Prozess nach den Prüfungen; sie stehen deshalb auf `<T2>` oder eine Sekunde
  danach. Am 9. Oktober war es dieselbe Sekunde, in der Nacht und in Punkt 7
  die nächste.

In `haken`:

```
{"kind":"findings","subject":"p6-abnahme.invalid","befunde":[["backup.latest","failed"]],"seit":["<T2 in UTC>"],"satz":["Die jüngste Sicherung dieses Abonnements ist fehlgeschlagen."]}
```

`kundenmail p6-abnahme.invalid` druckt den Betreff
`<Marke> — Sicherung fehlgeschlagen: p6-abnahme.invalid`, `Zeilen: 21` mit dem
Satz über die nächste Nacht oder `Zeilen: 19` ohne ihn, die längste mit
`76 Zeichen`, und den Text aus §6, mit den Zeiten dieses Laufs. **Stand in 2b
`Jüngste gelungene: keine`**, nennt die Mail statt des Datums „Eine gelungene
Sicherung dieses Abonnements gibt es nicht.", in einer Zeile statt zwei: dann
`Zeilen: 20` oder `18`.

*Berichtigt nach dem Lauf (§7, Befund 3):* Die erste Fassung kannte nur 21
und 19. Der Prüfstand im Container hatte eine gelungene Sicherung aus der
Nacht davor, `p6-abnahme.invalid` auf dem Server keine.

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
der Zeilen ist die der Seite: erst nach dem Zustand, Kaputt vor Nicht gemessen
vor Auffällig, dann nach Prüfung und Ort (`DiagnoseController`).

*Berichtigt nach dem Lauf (§7, Befund 6):* Die erste Fassung nannte nur
Prüfung und Ort, nach `docs/141 §7`, Befund 2; dort hatten beide Zeilen
denselben Zustand. Die Erwartungen oben stimmten trotzdem.

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
# 4b-1 · Ist die zweite Sicherung fertig? — liest nur
. /root/b9-werkzeuge.sh
vorgaenge
```

**Erwartet:** beide neuen Vorgänge `succeeded`, die Sicherung `ready` und
`Jüngste gelungene: <Klick>`. Steht noch einer auf `queued` oder `running`,
wird 4b-1 wiederholt. **Erst dann 4b-2.**

*Berichtigt nach dem Lauf (§7, Befund 4):* Die erste Fassung stellte
`vorgaenge` und `lauf` in einen Block und sagte, `vorgaenge` sei notfalls zu
wiederholen. Eingefügt fährt so ein Block den Lauf, bevor jemand die Ablesung
gelesen hat.

```bash
# 4b-2 · Der Lauf nach der gelungenen Sicherung — meldet die Entwarnung
. /root/b9-werkzeuge.sh
lauf
befunde
haken
```

**Erwartet** im Lauf:

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

**Nicht vor 05:05**, und in Fall B vor `T1 + 20 h` (§2). Zuerst die
Subdomain, im Panel
**`/subscriptions/140/domains/create`**: Sorte „Subdomain", Gehört zu
`p6-abnahme.invalid`, Name `b9.p6-abnahme.invalid`, alles andere wie
vorgeschlagen. Die Seite zeigt darüber, wie viele Subdomains das Kontingent
erlaubt.

```bash
# 5a · Die Subdomain ist angelegt — liest nur
. /root/b9-werkzeuge.sh
vorgaenge
```

**Erwartet:** `php.pool.apply` und `web.site.apply` `succeeded` für die
Subdomain und danach `acme.certificate.issue` `failed` mit der Abweisung von
Let's Encrypt (§0 Punkt 3). Steht die Bestellung noch auf `queued` oder
`running`, wird gewartet; sie endet in Sekunden.

*Berichtigt nach dem Lauf (§7, Befund 5):* Die erste Fassung nannte den Pool
nicht. Eine Subdomain mit PHP legt ihren eigenen an, und nach jedem
Hochladen schreibt das Panel ihn noch einmal.

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
# 5c-1 · Ist der Server-Block neu geschrieben? — liest nur
. /root/b9-werkzeuge.sh
vorgaenge
```

**Erwartet:** ein neues `php.pool.apply` und ein neues `web.site.apply`, beide
`succeeded`, und keine neue Bestellung: Das hochgeladene Zertifikat deckt den
Namen. Steht einer noch auf `queued` oder `running`, wird 5c-1 wiederholt.
**Erst dann 5c-2.**

*Berichtigt nach dem Lauf (§7, Befunde 4 und 5):* Die erste Fassung stellte
`vorgaenge` und den Lauf in einen Block und nannte den Pool nicht.

```bash
# 5c-2 · T0 — der Lauf nach dem Hochladen
. /root/b9-werkzeuge.sh
lauf
befunde
kundenmail p6-abnahme.invalid
```

**Erwartet** im Lauf `mail: 0 Nachricht(en) über 0 Befund(e).` und
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
ohne. Was in dieser Mail steht, misst dann schon Punkt 6, mit `haken` und
`kundenmail p6-b.invalid` nach `befunde`: Ihr Text gehört neben das Postfach,
solange beide frisch sind.

*Ergänzt nach dem Lauf:* Die erste Fassung liess die Mail erst in Punkt 7
messen, einen halben Tag nach ihrem Eingang.

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
beiden aus diesem Lauf auf `<T7>` oder eine Sekunde danach, wie in Punkt 2:

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

*Berichtigt nach dem Lauf (§7, Befund 7):* Die erste Fassung erwartete die
Buchungen auf `<T7>`. Am 10. Oktober standen sie auf 09:01:18, bei T7 =
09:01:17.

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

Die Zeiten sind die Buchungen aus `befunde`, auf die Sekunde.

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
# 10b-1 · Liefert der Server das neue aus? — liest nur
. /root/b9-werkzeuge.sh
vorgaenge
openssl s_client -connect 127.0.0.1:443 -servername b9.p6-abnahme.invalid </dev/null 2>/dev/null | openssl x509 -noout -enddate
```

**Erwartet:** in `vorgaenge` ein neues `php.pool.apply` und ein neues
`web.site.apply`, beide `succeeded`, und `openssl s_client` zeigt `notAfter=`
in einem Jahr: Der Server liefert das neue aus. Das ist die Bedingung dafür,
dass der Lauf keinen Befund `tls.wire` schreibt, denn jetzt hat die Zeit
keinen mehr, und die Leitung wird gefragt. **Erst dann 10b-2**; sonst wird
10b-1 wiederholt.

*Berichtigt nach dem Lauf (§7, Befunde 4 und 5):* Die erste Fassung stellte
die Ablesung und den Lauf in einen Block. Eingefügt fährt er den Lauf auch
dann, wenn `notAfter` noch in zwanzig Tagen liegt.

```bash
# 10b-2 · Der Lauf mit dem neuen Zertifikat — meldet die Entwarnung
. /root/b9-werkzeuge.sh
lauf
befunde
haken
```

**Erwartet** im Lauf `mail: 0 …`, `webhook: 0 …` und
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
   Rückfrage, die den Pfad nennt. Weiter erst, wenn `vorgaenge`
   `web.site.remove` und `php.pool.remove` als `succeeded` zeigt, daneben
   `db.database.remove` aus Schritt 1.

   *Berichtigt nach dem Lauf (§7, Befund 5):* Die erste Fassung nannte nur
   `web.site.remove`.

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

Gefahren vom 8. bis 10. Oktober 2026 auf `cloudsrv24` gegen `0.9.0-rc.15`:
Block 0, H und 0b am 8. Oktober nach dem Update, Teil 1 am 9., Punkt 6 und
die Teile 2 und 3 am Vormittag des 10. Was hier steht, ist von den Bildern und
Ausgaben des Betreibers abgelesen. Die Uhrzeiten sind die der Anzeigezone,
CEST; nur das Meldeziel schreibt UTC. Adressen stehen gekürzt.

**Alle elf Punkte sind erfüllt**, die Punkte 2 bis 9 darunter, und keiner ist
als „nicht herstellbar" ausgefallen. **Punkt 1 lief in Fall B:** Zwischen
Block 0 und Teil 1 lag eine Nacht, und den Übergang hat der Nachtlauf gemacht.
1a war danach nicht mehr herzustellen, und genau das lässt §5 zu. Die Mail an
`p6-b.invalid` kam deshalb in der Nacht vor Teil 2, wie §2 es für diesen Fall
vorsieht.

**Am Prüfling kein Befund.** Jede Zahl, jede Buchung und jede Zeile der drei
Mails stand vorher in §3 oder §6 oder war vor der Messung angesagt. **Acht
Befunde an der Vorschrift**; alle sind in dieser Fassung berichtigt, jede
Stelle mit einem Vermerk.

### Block 0, H und 0b — 8. Oktober

Block 0 lief nach dem Update, das `dpkg.log` um 11:45:30 verzeichnet. Der
letzte Lauf davor war der Nachtlauf um 00:04:05, noch mit 9 Prüfungen; der
nächste stand auf den 9. Oktober um 00:31:34.

- **Empfänger der Kundenmail:** an `p6-abnahme.invalid` Konto 6, `p…@d….de`,
  an `p6-b.invalid` Konto 5, `p…@p….de`. Beide Domains sind nicht reserviert,
  und beide Postfächer liest der Betreiber, bestätigt in voller Länge. Seine
  eigene Adresse ist eine dritte.
- **Hochladen: ja** an beiden Abonnements, **Sicherungen automatisch: nein.**
  Damit galt die Fassung mit „Sie können es auf der Seite der Domain im Panel
  hochladen." und ohne den Satz über die nächste Nacht (§6).
- **Befunde insgesamt: 1**, `tls.file · p6-b.invalid · expired`, **seit
  2026-09-14 00:49:42**, gemeldet über Mail und Webhook, die Mail am
  21. September um 22:05:07 (Befund 1). Das Meldeziel ist `cloudlab24.de`,
  `generic` und signiert.
- `p1139_b9` gab es nicht, `/var/tmp/b9-tls` fehlte, `Keine ungebrauchten
  Zertifikate.`, Streuung 1h, Genauigkeit 1min.

**H0** fand genau eine Datei, `/var/www/vhosts/p6-b.invalid/tmp/haken.log`,
`p1136`, `-rw-r-----`, 23 Zeilen, zuletzt geschrieben am 7. Oktober um 12:43,
also beim letzten Lauf für B5 (`docs/141 §7`, N4). Seitdem war beim Empfänger
nichts angekommen. **H** gab `Werkzeuge: 5 von 5`, **0b** `204` und 23 → 24
Zeilen. Damit war belegt, dass `LOG` die Datei ist, in die der Empfänger
schreibt (Befund 2).

### Punkt 1 — erfüllt in Fall B, T1 = 2026-10-09 00:31:34

Teil 1 begann am 9. Oktober um 08:47, mit dem Block für Fall B in der
Fassung, die §3 jetzt zeigt. Der Timer hatte auf die Sekunde zum Termin aus
Block 0 ausgelöst:

```
Jetzt: 2026-10-09 08:47:15 · Timer zuletzt: Fri 2026-10-09 00:31:34 CEST
2026-10-09T00:31:34+02:00 cloudsrv24 php[494605]: 10 Prüfung(en) gefahren, 2026-10-09 00:31:34.
2026-10-09T00:31:35+02:00 cloudsrv24 php[494629]:   mail: 0 Nachricht(en) über 0 Befund(e).
2026-10-09T00:31:35+02:00 cloudsrv24 php[494629]:   webhook: 0 Nachricht(en) über 0 Befund(e).
2026-10-09T00:31:35+02:00 cloudsrv24 php[494629]:   webhook: 1 Entwarnung(en) verschickt.
Empfängerprotokoll: 25 Zeile(n)
{"kind":"resolved","subject":"p6-b.invalid","befunde":[["tls.file","expired"]],"satz":["Das Zertifikat ist abgelaufen."]}
Befunde: 2
  tls.expiry    p6-b.invalid           expired  seit 2026-10-09 00:31:34 · fällig ab 2026-10-09 20:31:34 · zuletzt 2026-10-09 00:31:34
      gültig bis 2026-09-13 19:10 UTC
  tls.expiry    p6-b.invalid           expiring seit 2026-10-09 00:31:34 · fällig ab 2026-10-09 20:31:34 · zuletzt 2026-10-09 00:31:34
      gültig bis 2026-09-13 19:10 UTC
Entwarnungen, die noch ausstehen: 0
Befunde insgesamt: 2
```

Die Entwarnung trägt den alten Schlüssel und den alten Satz, wie gebaut
(`FindingCheck::retired()`). **`mail: 0` in diesem ersten Lauf unter `rc.15`**
sagt mehr, als Punkt 1 fragt: `backup.latest` lief hier zum ersten Mal über
alle Abonnements, und keines hatte eine gescheiterte jüngste Sicherung; ohne
Haltezeit wäre ihre Mail sonst in derselben Nacht hinausgegangen. Die 25 Zeilen
beim Empfänger sind die 23 von vorher, die Probe aus 0b und die Entwarnung.

**1a ist nicht gefahren worden** und war danach nicht mehr herzustellen, denn
die alte Zeile gibt es nicht mehr. Dass die Seite sie lesen kann, hält
`RetiredReasonTest`; gesehen hat es auf dem Server niemand.

Die Datenbank `b9` entstand über `/subscriptions/140/databases/create`, und
zwar ohne Vorgang: `MariaDbDriver` legt sie mit einem Aufruf beim Agenten
direkt an. Die Abfrage danach gab `p1139_b9`.

### Punkt 2 — erfüllt, T2 = 09:02:05

Die Sicht stand als einzige Zeile in `p1139_b9`. Der Klick um 09:01:12 ergab:

```
Vorgang 1067 · db.dump.create        · failed    · Die Sicherung ist gescheitert: mysqldump: Couldn't execute 'SHOW FIELDS FROM `b9_kaputt`': View 'p1139_b9.b9_kaputt' references invalid table(s) or column(s) or function(s) or definer/invoker of view lack rights to use them (1356)
Vorgang 1068 · backup.create         · failed    · Der Dump p1139-b9-20261009-070112-b2ba980f der Datenbank p1139_b9 liegt nicht — die Sicherung wäre ohne ihre Datenbanken und von einer vollständigen nicht zu unterscheiden.
Sicherung 21 · failed   · erstellt 2026-10-09 09:01:12 · Der Dump p1139-b9-20261009-070112-b2ba980f der Datenbank p1139_b9 liegt nicht — …
Jüngste gelungene: keine
```

Beide Sätze stehen Wort für Wort wie in §6. **`Jüngste gelungene: keine`:**
Das Abonnement hatte bis dahin keine einzige Sicherung (Befund 3).

Der Lauf um 09:02:05 meldete `mail: 1 Nachricht(en) über 1 Befund(e).` und
`webhook: 1 Nachricht(en) über 1 Befund(e).`. In `befunde` stand
`backup.latest · failed` mit `seit` gleich `fällig ab`, 09:02:05, also ohne
Haltezeit, dazu das Detail `erstellt 2026-10-09 09:01 CEST (UTC+02:00): Der
Dump …` und die Buchungen über Mail und Webhook um 09:02:05. Beim Empfänger
standen 26 Zeilen, `seit` war `2026-10-09T07:02:05+00:00`.

`kundenmail p6-abnahme.invalid` druckte `SrvPanel — Sicherung fehlgeschlagen:
p6-abnahme.invalid` und `Zeilen: 18 · die längste: 76 Zeichen`, mit „Eine
gelungene Sicherung dieses Abonnements gibt es nicht." an der Stelle des
Datums. **Im Postfach von Konto 6 lag genau eine Mail**, um 09:02 von der
Absenderadresse des Panels, in „An" nur die Adresse des Kunden, der Text Wort
für Wort wie in `kundenmail`. **Im Postfach des Betreibers lag nichts.** Auf
der Seite:

```
Seite /diagnose · Befunde 3 · davon an den Orten des Laufs 3
  Jüngste Sicherung · p6-abnahme.invalid · Kaputt → Gemeldet über Mailversand: 2026-10-09 09:02:05 / Gemeldet über Meldeziel (Webhook): 2026-10-09 09:02:05
  Laufzeit eines Zertifikats · p6-b.invalid · Kaputt → Gemeldet wird ab 2026-10-09 20:31:34.
  Laufzeit eines Zertifikats · p6-b.invalid · Auffällig → Gemeldet wird ab 2026-10-09 20:31:34.
```

### Punkt 3 — erfüllt

Der Lauf um 09:06:50 meldete `mail: 0` und `webhook: 0`; die Buchungen standen
weiter auf 09:02:05.

### Punkt 4 — erfüllt, T3 = 09:08:32

Ohne die Sicht gelangen die Vorgänge 1069 und 1070, und Sicherung 22 war um
09:07:44 `ready`. Der Lauf um 09:08:32 meldete `webhook: 1 Entwarnung(en)
verschickt.` und sonst nichts. `backup.latest` war fort, und beim Empfänger
standen 27 Zeilen, die letzte die Entwarnung für `backup.latest / failed`.
**In beiden Postfächern kam seit 09:02 nichts dazu.** Gefahren ist 4b in zwei
Blöcken: erst `vorgaenge`, bis die Vorgänge fertig waren, dann der Lauf
(Befund 4).

### Punkt 5 — erfüllt, T0 = 2026-10-09 09:15:43

Die Subdomain brachte die Vorgänge 1071 `php.pool.apply` und 1072
`web.site.apply`, beide `succeeded`, und 1073 `acme.certificate.issue` mit
`failed`: „Die Zertifizierungsstelle lehnte ab (rejectedIdentifier). — Invalid
identifiers requested :: Cannot issue for "b9.p6-abnahme.invalid": Domain name
does not end with a valid public suffix (TLD)" (Befund 5).

Das Zertifikat entstand auf dem Server mit `notAfter=Oct 29 07:14:59 2026 GMT`
und wurde abgelegt, „gültig bis 29.10.2026". Danach liefen 1074
`php.pool.apply` und 1075 `web.site.apply`, ohne neue Bestellung. Der Lauf um
09:15:43:

```
10 Prüfung(en) gefahren, 2026-10-09 09:15:43.
  mail: 0 Nachricht(en) über 0 Befund(e).
  webhook: 0 Nachricht(en) über 0 Befund(e).
Befunde: 3
  tls.expiry    b9.p6-abnahme.invalid  expiring seit 2026-10-09 09:15:43 · fällig ab 2026-10-10 05:15:43 · zuletzt 2026-10-09 09:15:43
      gültig bis 2026-10-29 07:14 UTC
  …
```

An der Subdomain stand nur `expiring`, kein `expired`, kein `tls.file` und kein
`tls.wire`. `kundenmail` druckte `SrvPanel — Zertifikat läuft ab:
p6-abnahme.invalid` und `Zeilen: 17 · die längste: 75 Zeichen`, beides am
Morgen desselben Tages im Container nachgemessen, mit `Gültig bis: 2026-10-29
08:14 CET (UTC+01:00)`. Die Seite zeigte `p6-b.invalid · Kaputt`, dann
`b9.p6-abnahme.invalid · Auffällig → Gemeldet wird ab 2026-10-10 05:15:43.` und
`p6-b.invalid · Auffällig`, in dieser Reihenfolge (Befund 6). Teil 1 war damit
um 09:16 fertig, über elf Stunden vor der Frist für Fall B (Befund 8).

### Punkt 6 — erfüllt, der Nachtlauf um 00:56:26

```
2026-10-10T00:56:26+02:00 cloudsrv24 php[560706]: 10 Prüfung(en) gefahren, 2026-10-10 00:56:26.
2026-10-10T00:56:31+02:00 cloudsrv24 php[560731]:   mail: 1 Nachricht(en) über 2 Befund(e).
2026-10-10T00:56:31+02:00 cloudsrv24 php[560731]:   webhook: 1 Nachricht(en) über 2 Befund(e).
```

Genau ein Lauf, und zwar in der Fassung für Fall B: Die beiden Zeilen von
`p6-b.invalid` waren seit 20:31:34 fällig und tragen ihre Buchungen von
00:56:27. **Die Zeile der Subdomain blieb ohne Buchung**, mit `zuletzt`
00:56:26 und fällig erst ab 05:15:43. Beim Empfänger standen 28 Zeilen, die
letzte eine Meldung für `p6-b.invalid` mit beiden Gründen und `seit` gleich T1
in UTC.

`kundenmail p6-b.invalid` druckte `SrvPanel — Zertifikat abgelaufen:
p6-b.invalid` und `Zeilen: 20 · die längste: 75 Zeichen`, Wort für Wort wie in
§6 für „Hochladen: ja". **Im Postfach von Konto 5 lag genau eine Mail**, um
00:56, in „An" genau ein Empfänger, Betreff und Text wie in `kundenmail`, die
festen Umbrüche an denselben Stellen. Der Lauf zählte eine Nachricht; in die
anderen Postfächer ging also nichts. Nur „abgelaufen" steht da, obwohl
`expiring` daneben gebucht ist (`CertificateSection::shown()`).

Gelesen ist Punkt 6 mit `haken` und `kundenmail p6-b.invalid` dazu. In Fall B
kommt die Mail an `p6-b.invalid` in dieser Nacht, und ihr Text gehört neben
das Postfach, solange beide frisch sind.

### Punkt 7 — erfüllt, T7 = 2026-10-10 09:01:17

Die Sicht entstand neu. Der Klick um 09:00:28 ergab die Vorgänge 1076 und
1077, beide `failed`, mit dem Dump `p1139-b9-20261010-070028-41a7e8cb`, und
Sicherung 23. `Jüngste gelungene` blieb 09:07:44 vom Vortag; die Nacht legte
keine an. Der Lauf:

```
10 Prüfung(en) gefahren, 2026-10-10 09:01:17.
  mail: 1 Nachricht(en) über 2 Befund(e).
  webhook: 2 Nachricht(en) über 2 Befund(e).
```

**Zwei Zustände desselben Abonnements in einem Lauf, und eine Mail.** In
`befunde` trugen `backup.latest` und die Subdomain ihre Buchungen von 09:01:18,
eine Sekunde nach T7 (Befund 7); die beiden Zeilen von `p6-b.invalid` behielten
00:56:27. Beim Empfänger standen 30 Zeilen, eine Meldung je Gegenstand: zuerst
`p6-abnahme.invalid` mit `backup.latest / failed` und `seit` gleich T7 in UTC,
dann `b9.p6-abnahme.invalid` mit `tls.expiry / expiring` und `seit` gleich T0
in UTC.

`kundenmail p6-abnahme.invalid` druckte `SrvPanel — Zertifikat läuft ab und
Sicherung fehlgeschlagen: p6-abnahme.invalid` und `Zeilen: 26 · die längste:
76 Zeichen`: erst das Zertifikat, dann die Sicherung mit „Die jüngste gelungene
Sicherung dieses Abonnements ist vom 2026-10-09 09:07", der Schlusssatz einmal.
**Im Postfach von Konto 6 lag genau diese eine Mail**, um 09:01, in „An" nur
die Adresse des Kunden, Wort für Wort wie in `kundenmail`. An Konto 5 und an
den Betreiber ging nichts; der Lauf zählte eine Nachricht.

Auf der Seite trug die Karte der Sicherung „Gemeldet über Mailversand:
2026-10-10 09:01:18" und „Gemeldet über Meldeziel (Webhook): 2026-10-10
09:01:18", auf die Sekunde wie `befunde`.

### Punkt 8 — erfüllt

Der Lauf um 09:04:50 meldete `mail: 0` und `webhook: 0`. Alle vier Zeilen
behielten ihre Buchungen.

### Punkt 9 — erfüllt, T9 = 09:06:34

Ohne die Sicht gelangen 1078 und 1079, und Sicherung 24 war um 09:05:52
`ready`. Der Lauf meldete `webhook: 1 Entwarnung(en) verschickt.`.
`backup.latest` war fort, und **die Zeile der Subdomain blieb** mit ihren
Buchungen von 09:01:18. Beim Empfänger standen 31 Zeilen. Eine Mail gab es
nicht.

### Punkt 10 — erfüllt, T10 = 09:10:33

Das Zertifikat für ein Jahr wurde abgelegt, „gültig bis 10.10.2027". Nach 1080
`php.pool.apply` und 1081 `web.site.apply` lieferte der Server
`notAfter=Oct 10 07:09:38 2027 GMT` aus, und erst dann lief der Lauf:
`webhook: 1 Entwarnung(en) verschickt.`, an der Subdomain nichts mehr, auch
kein `tls.wire`. Beim Empfänger standen 32 Zeilen, die letzte die Entwarnung
für `b9.p6-abnahme.invalid` mit „Das Zertifikat läuft demnächst ab.".

### Punkt 11 — erfüllt

Datenbank und Subdomain sind über die Vorgänge 1082 `db.database.remove`,
1083 `web.site.remove` und 1084 `php.pool.remove` entfernt, alle `succeeded`.
Der Trockenlauf von `srvpanel tls --prune` nannte wörtlich, was §3 erwartet:
zwei Zeilen ohne Domain und einen Ablageort. Bestätigt mit `yes` kamen
`entfernt` und `Aufgeräumt.`. Der Lauf um 09:27:47 meldete nichts, auch keine
Entwarnung, und übrig blieben die beiden gemeldeten Zeilen von `p6-b.invalid`,
**auch über den ganzen Server gezählt**. Danach stand `Keine ungebrauchten
Zertifikate.` da, `0` für `p1139_b9`, und `/var/tmp/b9-tls` und
`/root/b9-werkzeuge.sh` gibt es nicht mehr. Die Zählung über den ganzen Server
und `haken` sind in 11c dazugenommen.

Zurück bleibt, was §2 nennt: die Sicherungen 21 bis 24, zwei davon
gescheitert, die Vorgänge des Laufs und an `p6-b.invalid` die gemeldeten
`tls.expiry`. Die folgenden Nächte schicken dazu keine Mail mehr.

### Die acht Befunde

| | wo | was | gefunden | Stand |
|---|---|---|---|---|
| 1 | Vorschrift | §0 Punkt 1 nannte den 21. September als den Tag, seit dem `tls.file / expired` an `p6-b.invalid` steht. Die Zeile steht seit dem 14. September um 00:49:42; am 21. ist sie gemeldet worden. Dieselbe Angabe stand in `docs/142 §10` und in CLAUDE.md | Block 0 | berichtigt, §0 Punkt 1; nachgezogen in `docs/142 §10` und in CLAUDE.md |
| 2 | Vorschrift | Block H nannte für `HAKEN` und `LOG` „wie in `docs/137 §2`", und dort stehen dieselben Platzhalter. Den Pfad des Empfängers nannte kein Dokument | vor Block H | mit H0 gemessen; berichtigt, §1 Block H und 0b |
| 3 | Vorschrift | Punkt 2 kannte 21 und 19 Zeilen. Ohne jede gelungene Sicherung steht „Eine gelungene Sicherung dieses Abonnements gibt es nicht." an der Stelle des Datums, und die Mail hat 18. Der Prüfstand im Container hatte eine Sicherung aus der Nacht davor, der Server keine | 2b, angesagt vor der Messung | berichtigt, §3 Punkt 2 |
| 4 | Vorschrift | 4b, 5c und 10b stellten `vorgaenge` und `lauf` in einen Block, mit der Anweisung, `vorgaenge` notfalls zu wiederholen. Eingefügt fährt der Block den Lauf, bevor die Vorgänge fertig sind; in 10b schriebe er dann `tls.wire / not_served` | beim Anweisen von 4b | in zwei Blöcken gefahren; berichtigt, §3 Punkte 4, 5 und 10 |
| 5 | Vorschrift | 5a nannte `web.site.apply` und die Bestellung. Eine Subdomain mit PHP legt auch ihren Pool an, beim Anlegen, nach jedem Hochladen noch einmal, und Punkt 11 entfernt ihn mit `php.pool.remove` | 5a | berichtigt, §3 Punkte 5, 10 und 11 |
| 6 | Vorschrift | Punkt 2 begründete die Reihenfolge der Seite mit „nach Prüfung und Ort". `DiagnoseController` ordnet zuerst nach dem Zustand, Kaputt vor Auffällig. Die Erwartungen stimmten trotzdem, nur die Begründung nicht | beim Vorbereiten von Punkt 5, im Container nachgemessen | berichtigt, §3 Punkt 2; nachgetragen in `docs/141 §3`, Punkt 2 |
| 7 | Vorschrift | Die Punkte 2 und 7 erwarteten die Buchungen auf T2 und T7. Sie tragen die Zeit des Meldelaufs, und der ist das zweite `ExecStart` der Unit und ein eigener Prozess: in 2c dieselbe Sekunde, in der Nacht und in 7c eine Sekunde später | Punkt 6, angesagt vor Punkt 7 | berichtigt, §3 Punkte 2 und 7 |
| 8 | Vorschrift | §2 sagte für Fall B, wie sich die Zahlen der Punkte 6 und 7 ändern, und nicht, dass Teil 1 dann eine Frist hat. Zwanzig Stunden nach dem Nachtlauf ist die Mail an `p6-b.invalid` fällig, und jeder Lauf danach schickt sie mit; hier um 20:31:34 | beim Planen von Teil 1 | berichtigt, §2 und §3 Punkte 1 und 5 |

### Beobachtungen, keine Befunde

1. **Fall B kam aus der Reihenfolge eines Arbeitstages.** Das Update lief am
   8. Oktober um 11:45, Block 0, H und 0b am selben Tag, Teil 1 erst am
   nächsten Morgen. §2 rät, das Update vor Teil 1 und nicht in die Nacht davor
   zu legen; hier lag die Nacht zwischen Block 0 und Punkt 1. Gekostet hat es
   1a, sonst nichts.
2. **Die Datenbank entsteht ohne Vorgang und vergeht mit einem.**
   `MariaDbDriver` legt sie mit einem direkten Aufruf beim Agenten an;
   entfernt wird sie über `db.database.remove`. `vorgaenge` zeigte deshalb nur
   das Entfernen.
3. **Das Bild der Seite zeigt die Karte der Subdomain ohne ihre Buchungen.**
   Am Telefon lagen die Zeilen „Gemeldet über" unter dem Bildrand, und nach
   Punkt 10 gibt es die Karte nicht mehr. Die Buchungen von 09:01:18 belegen
   `befunde` in den Punkten 7 bis 9 und der Empfänger, und die Zeilen kommen
   aus derselben Zelle wie die der Sicherung, die abgebildet ist.
4. **`mariadb` druckt am Terminal einen Kasten**, auch mit `-N`; §2 und §3
   zeigen die Ausgabe ohne ihn. Der Lauf für B5 hatte das schon vermerkt.

### Was der Lauf über sich selbst gelernt hat

**Der Tag einer Meldung stand als Tag des Zustands da.** „Seit dem
21. September" kam aus der Buchung der Mail, und von dort in drei Dokumente.
Die Zeile selbst stand seit dem ersten Lauf nach dem Ablauf des Zertifikats,
dem 14. September; gemeldet wurde sie erst, als es den Meldeweg gab.

> **Seit wann ein Befund gemeldet ist, sagt nicht, seit wann er besteht.**

**Ein Verweis hat eine Lücke weitergereicht.** Block H nannte für den Pfad
des Empfängers `docs/137 §2`, und dort steht derselbe Platzhalter, in
`docs/141` auch. Im Lauf für B5 blieb er einmal stehen (`docs/141 §7`,
Befund 4). Diesmal hat H0 den Pfad gemessen, bevor Block H ihn brauchte, und
0b hat ihn bestätigt.

> **Ein Verweis auf eine Stelle, an der derselbe Platzhalter steht, ist kein
> Wert — er reicht die Lücke weiter.**

**Ein Block, der seine Bedingung abliest und im selben Zug weiterfährt, prüft
sie nicht.** 4b, 5c und 10b sagten, `vorgaenge` sei notfalls zu wiederholen,
und standen mit dem Lauf in einem Block. In 10b hätte das einen Befund für den
Betreiber erzeugt, zu Recht, denn der Server lieferte dann noch das alte
Zertifikat aus.

> **Eine Anweisung, eine Ablesung zu wiederholen, gilt nicht in einem Block,
> der danach von selbst weiterfährt.**

**Der Prüfstand im Container hatte eine Vorgeschichte, die der Server nicht
hatte.** Die Erwartung von 21 und 19 Zeilen setzte eine gelungene Sicherung
voraus, weil der Wegwerf-Test eine aus der Nacht davor angelegt hatte. Ins
Protokoll kam das nicht als Abweichung, weil `Jüngste gelungene: keine` vor
dem Lauf abgelesen und die Mail mit 18 Zeilen vorher angesagt war.

> **Ein Prüfstand, dem man eine Vorgeschichte gibt, misst sie mit — eine
> Erwartung daraus gilt auf dem Server erst, wenn dessen Vorgeschichte
> abgelesen ist.**

**Und ein zweiter Fall braucht seine eigene Uhr.** §0 Punkt 1 hat aus dem
Update eine Frist gemacht. Für Fall B nannte §2 die Zahlen und nicht die
Frist, die daraus für Teil 1 folgt; ausgerechnet ist sie erst beim Planen
dieses Morgens.

> **Ein Fall, für den die Vorschrift die Zahlen nennt, ist erst beschrieben,
> wenn auch seine Uhr dasteht.**

### Die Abnahme

**Der Betreiber hat B9 am 10. Oktober 2026 abgenommen**, nach diesem Lauf
gegen `0.9.0-rc.15`. Am selben Tag hat er die Frage aus §0 entschieden. Der
Satz, der in der Mail auf „Vorgänge" zeigt, ist für `0.9.0-rc.16` gebaut und
gehört nicht zum Kriterium.

### Was aussteht

- **Die Frage aus §0, Beobachtung:** Die Mail nennt, dass der Dump fehlt, und
  nicht, warum. Der Lauf hat das zweimal gezeigt; in beiden Mails stand „Der
  Dump … liegt nicht", und den Grund, Fehler 1356 an der Sicht, trugen die
  Vorgänge 1067 und 1076 unter „Vorgänge". *Entschieden am selben Tag, nach
  diesem Protokoll:* Ein Satz in der Mail zeigt dorthin, gebaut für
  `0.9.0-rc.16` (§0, Beobachtung). In einer Mail auf dem Server gesehen hat
  ihn noch niemand.
