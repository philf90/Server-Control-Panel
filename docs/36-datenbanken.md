# 36 — P5: Datenbanken

> Der Plan für die fünfte Ausbaustufe (`docs/20 §9 P5`). Er ist zu lesen wie
> `docs/34` und `docs/35`: erst die Entscheidungen mit ihren Gründen, dann die
> Schritte in zwingender Reihenfolge, dann die Wächter samt ihren Brüchen, dann
> das Abnahmekriterium als Befehlsfolge — und am Ende die Fragen, die der
> Betreiber beantwortet, **bevor** gebaut wird.
>
> Stand: P0 bis P4 abgenommen, beide Würfe. Zuletzt `docs/35` (Verzeichnis der
> Systembenutzer), abgenommen am 7. August 2026 auf `cloudsrv24`.
> Ausgeliefert wird `v0.4.1-rc.2`.

---

## 1. Der Auftrag

Aus `docs/20 §9`:

- MariaDB: Datenbanken und Benutzer je Abo, Namenspräfix, Rechte begrenzt
- Zugriff nur lokal, optional Fernzugriff je Benutzer mit IP-Beschränkung
- Kontingente: Anzahl, Größe (gemessen)
- Import/Export über die Oberfläche, mit Größenbegrenzung und als Vorgang
- Adminer als eingebettetes Werkzeug, Anmeldung ohne Passwortweitergabe
- PostgreSQL im selben Zuschnitt, als zweiter Schritt der Stufe

> **Fertig, wenn** ein Kunde eine Datenbank anlegt, benutzt, sichert und
> zurückspielt, und ein Datenbankbenutzer nachweislich keine fremde Datenbank
> sieht.

Zwei Beobachtungen zum Kriterium, bevor irgendetwas gebaut wird.

**Erstens: „nachweislich" ist das Wort, an dem die Stufe hängt.** Es verlangt
keine Zusicherung im Quelltext, sondern eine Verbindung, die aufgebaut und
abgewiesen wurde. Das ist derselbe Zuschnitt wie `web.isolation.probe` in P3 —
und der Grund, warum P5 eine eigene Operation `db.isolation.probe` bekommt und
kein Häkchen in einer Prüfliste.

**Zweitens gilt die Lehre aus dem TLS-Abnahmelauf** (CLAUDE.md): *Ein Kriterium,
das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.* Ein Probelauf, der
„1 Datenbank sichtbar" meldet, hat nichts bewiesen — es könnte die falsche sein.
`db.isolation.probe` gibt deshalb **die Namen** zurück, die der fremde Benutzer
gesehen hat, und der Abnahmelauf vergleicht Namen. Nirgends in P5 entscheidet
eine Zahl über ein Kriterium.

---

## 2. Der Weg zurück wird zuerst gebaut

`docs/35` hat einen Fehler freigelegt, der älter und schwerer wog als der, den
es beheben sollte: **Zertifikate liessen sich in diesem System nie löschen.**
Jedes zurückgebaute Abonnement liess seinen privaten Schlüssel unter
`/etc/srvpanel/tls/certs` liegen, und gemerkt hat es niemand, weil ein Grabstein
die Zeile am Leben hielt.

P5 legt **drei** weitere Dinge an, die auf dem System bleiben: Schemata in
`/var/lib/mysql`, Benutzerzeilen in `mysql.global_priv`, und Sicherungsdateien
unter `/var/lib/srvpanel/dumps`. Deshalb die härteste Regel dieses Plans:

> **`db.database.remove`, `db.user.remove` und `db.dump.remove` gehören in
> denselben Beitrag wie ihre `create`-Hälfte. Nicht in einen späteren.**

Und damit die Regel nicht wieder nur ein Satz in einem Dokument ist, bekommt sie
einen Wächter, der **nicht** datenbankspezifisch ist: `RemovalPathTest` (§16.1).
Er hält die Registratur des Agenten gegen sich selbst — zu jeder Operation, die
etwas Dauerhaftes anlegt, muss es eine geben, die es wieder entfernt. Dieser
Wächter hätte die Zertifikatslücke im August 2026 gefunden, ein Jahr bevor eine
Datenmigration danach fragte.

---

## 3. Namen: das Präfix ist der Systembenutzer

Der Plan verlangt ein „Namenspräfix". Die Wahl ist **der Systembenutzer des
Abonnements** — `p1001`, nicht der Abonnementname.

```
Datenbank:  p1001_shop
Benutzer:   p1001_web
```

Vier Gründe, und der erste ist der, der ohne `docs/35` nicht zu haben wäre:

1. **Ein Systembenutzer wird nie zweimal vergeben.** Seit `docs/35` steht die
   Reservierung in `system_users`, und `Lifecycle::claim()` verbraucht die
   Nummer endgültig. Damit kann ein Schemaname eines neuen Abonnements niemals
   auf ein Verzeichnis in `/var/lib/mysql` treffen, das ein zurückgebautes
   hinterlassen hat. Mit dem Abonnementnamen als Präfix wäre genau das möglich:
   Namen dürfen wiederverwendet werden, seit ein zurückgebautes Abonnement hart
   gelöscht wird.
2. **`p` plus vier bis neun Ziffern ist bereits ein gültiger unquoted
   Bezeichner** in MariaDB. Der Abonnementname ist ein Domainname und enthält
   Punkte und Bindestriche; er müsste an jeder Stelle in Backticks stehen, und
   „an jeder Stelle" ist die Formulierung, aus der Lücken entstehen.
3. **Er ist kurz.** Höchstens zehn Zeichen, damit bleibt Raum unter den Grenzen,
   die MariaDB setzt: 64 Zeichen für einen Schemanamen, 80 für einen
   Benutzernamen (MariaDB ≥ 10.6; MySQL kennt nur 32 — die engere Zahl gilt).
4. **Die Regel steht schon im Agenten.** `SubscriptionProvision::systemUser()`
   erzwingt `^p[0-9]{4,9}$`. Das Präfix wird nicht neu geprüft, es wird durch
   dieselbe Funktion geschickt — dieselbe Entscheidung wie in `docs/26 §3`, wo
   der Abonnementname mit der Funktion des Agenten selbst geprüft wird und nicht
   mit einer zweiten Formulierung derselben Regel.

Der Nachteil ist ehrlich zu nennen: `p1001_shop` sagt dem Kunden nicht, zu
welchem Abonnement es gehört. Ihn trifft das nicht — er sieht nur seine eigenen,
und daneben steht der Abonnementname. Den Betreiber trifft es auf der
Kommandozeile, und dafür gibt es `srvpanel db list`.

**Der Zusatz** — was hinter dem Unterstrich steht — ist `^[a-z][a-z0-9_]{0,15}$`.
Kleinbuchstaben, Ziffern, Unterstrich, beginnend mit einem Buchstaben, höchstens
sechzehn Zeichen. Damit ist der ganze Name höchstens 27 Zeichen lang und passt
unter jede der drei Grenzen.

**Der Zusatz kommt aus dem Formular, das Präfix nie.** Der Browser schickt
`shop`; `p1001` liest die Anwendung aus der abgelegten Zeile des Abonnements,
das durch die Mandantenklammer gekommen ist. Das ist dieselbe Regel wie in
`Lifecycle::payload()` und in `WebLifecycle::payload()`: **Kein Wert aus der
Anfrage erreicht den Agenten**, und der Teil des Namens, der über die
Mandantengrenze entscheidet, erst recht nicht.

### 3.1 Die Unterstrich-Falle in `GRANT`

Das ist der teuerste Fund dieses Entwurfs, und er wäre im Betrieb nie
aufgefallen.

**In `GRANT ... ON <db>.*` ist `<db>` ein Muster und kein Name.** `_` steht dort
für ein beliebiges Zeichen, `%` für beliebig viele. Der naheliegende Weg, einem
Abonnement seine Datenbanken freizugeben, wäre:

```sql
GRANT ALL PRIVILEGES ON `p1001_%`.* TO 'p1001_web'@'localhost';
```

Das sieht aus wie „alle Datenbanken von p1001" und ist es nicht. `p1001_%`
trifft auch `p10012_shop` — fünf Zeichen `p1001`, dann `_` für die `2`, dann `%`
für den Rest. **Das ist ein Zugriff über die Mandantengrenze hinweg, und zwar
genau der, den das Abnahmekriterium ausschliesst.**

Deshalb zwei Festlegungen:

1. **Es wird nie auf ein Muster berechtigt, immer auf genau eine Datenbank.**
   Ein Benutzer, der zwei Datenbanken braucht, bekommt zwei `GRANT`-Anweisungen.
2. **Der Name wird auch dann maskiert, wenn er ein Name ist:**

   ```sql
   GRANT ALL PRIVILEGES ON `p1001\_shop`.* TO 'p1001_web'@'localhost';
   ```

   Ohne die Maskierung träfe `p1001_shop` auch `p1001Xshop`. Ein solcher Name
   kann heute nicht entstehen — die Zusatzregel verlangt einen Unterstrich an
   genau dieser Stelle. **Genau diese Sorte Begründung lehnt dieses Projekt ab.**
   Eine Regel, die zufällig gilt, gilt bis zur nächsten Änderung an einer ganz
   anderen Stelle. Die Maskierung kostet eine Zeile und macht die Aussage wahr,
   statt sie stimmen zu lassen.

Beides prüft `GrantPatternTest` (§16.2), und der Bruch dazu ist das Entfernen
des Backslash.

**Was `ALL PRIVILEGES` auf Schemaebene *nicht* enthält**, ist die andere Hälfte
von „Rechte begrenzt": `SUPER`, `FILE`, `PROCESS`, `SHUTDOWN`, `RELOAD` und
`CREATE USER` sind globale Rechte und stehen in `*.*`. Sie werden nie vergeben,
und **`WITH GRANT OPTION` ebenfalls nicht** — ein Kunde, der Rechte
weiterreichen darf, kann sich selbst welche geben. `DbIsolationTest` (§16.3)
liest die erzeugten Anweisungen als Text und besteht darauf, dass darin weder
`*.*` noch `WITH GRANT OPTION` vorkommt. Das ist dieselbe Prüfart wie
`SiteTemplateTest` und `PhpIsolationTest`: *Der Schutz ist eine Eigenschaft der
erzeugten Zeichenkette*, und dieser Container hat keine MariaDB, an der man es
anders prüfen könnte.

---

## 4. Wo das Passwort liegt

Das ist die eine Entscheidung in P5 mit Folgen bis in die Oberfläche. Sie ist am
7. August 2026 gefallen — **nirgends** (§19, Entscheidung 3). Hier stehen die
drei Möglichkeiten und der Grund, weil eine Entscheidung ohne ihre Alternativen
in einem Jahr wie eine Selbstverständlichkeit aussieht.

Ein Datenbankpasswort wird an vier Stellen gebraucht:

| Wer | Wann | Wie oft |
|---|---|---|
| der Agent | beim Anlegen und beim Zurücksetzen | einmal je Vorgang |
| der Kunde | für die Konfigurationsdatei seiner Anwendung | einmal |
| Adminer | bei jeder Anmeldung | dauernd |
| das Zurückspielen | für die Sitzung, in der der Dump läuft | je Vorgang |

### Die drei Möglichkeiten

**(a) Nirgends abgelegt.** Das Panel erzeugt es, schickt es in einem
unmittelbaren Aufruf an den Agenten, zeigt es genau einmal an und vergisst es.
Zurücksetzen erzeugt ein neues. Adminer und das Zurückspielen bekommen einen
**befristeten Datenbankbenutzer** mit denselben Rechten auf genau diese
Datenbank, der nach Gebrauch fällt (§10.2).

**(b) Im Agenten abgelegt**, unter `/etc/srvpanel/db/`, `root:root 0600` —
dasselbe Muster wie `dns.credential.store` aus `docs/34 §5`. Das Panel kennt es
nie, kann es aber für Adminer anfordern.

**(c) Im Panel abgelegt**, als `encrypted`-Spalte wie `accounts.two_factor_secret`.
Der Kunde kann es jederzeit ansehen, Adminer meldet sich damit an.

### Gewählt: (a)

Der Massstab dafür steht im Abnahmelauf von P4: *„und das DNS-Token steht
nirgends"* war eines von sieben Kriterien. Ein Geheimnis, das man nicht
aufbewahrt, kann man nicht verlieren — und die beiden anderen Wege bezahlen ihre
Bequemlichkeit mit einer Ablage, die es vorher nicht gab.

- Gegen (c) spricht, dass eine Sicherung der Panel-Datenbank damit die
  Datenbankpasswörter **aller** Kunden enthält, im Klartext für jeden, der den
  `APP_KEY` aus `/etc/srvpanel/panel.env` dazu hat. Beide liegen auf demselben
  Server.
- Gegen (b) spricht weniger, aber es kauft im Wesentlichen nur Adminer — und der
  ist mit Entscheidung 4 aufgeschoben.

**Der Preis von (a) ist ehrlich zu nennen:** Wer sein Passwort verliert, setzt es
zurück und trägt es in seine Anwendung ein. Das ist eine Handlung mehr als bei
Plesk. Umgekehrt ist es die einzige der drei Möglichkeiten, bei der die Antwort
auf „wo liegen die Datenbankpasswörter meiner Kunden" *nirgends* lautet.

**Der Kunde wählt sein Passwort nicht.** Es wird erzeugt — 32 Zeichen aus dem
Alphabet, das `PanelProvision::secret()` schon benutzt, ohne Sonderzeichen. Der
Grund steht dort und gilt hier genauso: Das Passwort steht später in einer
SQL-Anweisung, und Zeichen, die dort Bedeutung haben, sind kein Gewinn an
Stärke, sondern eine Fehlerquelle. 32 Zeichen aus 62 sind rund 190 Bit; die
Anforderungen aus `docs/22` gelten für Anmeldekonten und nicht für einen
Maschinenzugang, den kein Mensch tippt.

### Was daraus für die Architektur folgt

**`db.user.create` und `db.user.password` laufen nicht über die Warteschlange.**
Ein eingereihter Vorgang legt seine Argumente in `operations.payload` ab; ein
Datenbankpasswort gehört dort nicht hin. Das ist keine neue Regel — sie steht
seit P4 in `AgentOperationReachTest::WITHOUT_LIFECYCLE` für
`tls.certificate.upload` und `dns.credential.store`. P5 macht sie zum dritten Mal
nötig, und deshalb bekommt sie in §16.4 endlich einen Wächter statt einer
Gewohnheit.

Beide laufen als **unmittelbarer Aufruf** (`Client::call`), und die Anwendung
schreibt ihre Zeile danach selbst — genau wie `CertificateRecord` es nach
`tls.certificate.upload` tut.

---

## 5. Was mit einer Datenbank geschieht, wenn ihr Abonnement zurückgebaut wird

Die Frage, die `docs/35` für Vorgänge und Zertifikate beantwortet hat, gestellt
für den dritten Fall. Die Antwort ist **nicht** dieselbe, und der Unterschied ist
wichtig.

Ein Zertifikat überlebt sein Abonnement als **Wegweiser**: Die Zeile bleibt
auffindbar (`subscription_id` null, `subscription_name` als Abschrift), damit
`srvpanel tls prune` die Datei findet, die niemand mehr kennt. Eine Datenbank ist
etwas anderes — sie enthält die Daten des Kunden, und `docs/20 §2` Leitbild 4
sagt: *„Was ein Modul anlegt, muss es beim Löschen eines Abonnements auch wieder
vollständig entfernen."*

### Die Reihenfolge

1. **Vor** `subscription.remove` reiht `SubscriptionController::destroy` je
   Datenbank einen Vorgang `db.database.remove` ein. Die Warteschlange hat einen
   Arbeiter und arbeitet der Reihe nach — dasselbe Mittel wie bei
   `WebLifecycle::apply()`, wo der FPM-Pool vor dem Server-Block liegen muss.
2. Jeder dieser Vorgänge wirft das Schema und die Benutzer, die **nur** an ihm
   hängen. `DbLifecycle::afterSuccess()` löscht die Zeile danach — der Zustand
   folgt dem Agenten, nicht dem Klick.
3. Danach läuft `subscription.remove` wie bisher.

### Wenn einer davon scheitert

Dann bleibt die Zeile stehen, und `subscriptions.id` ist gleich fort. **Genau
dafür bekommt `databases` dieselbe Abschrift wie `certificates`:**
`subscription_name`, dazu ein Fremdschlüssel auf `nullOnDelete`. Ohne sie nähme
die Kaskade die Zeile mit, und das Schema läge in `/var/lib/mysql`, ohne dass
noch irgendetwas darauf zeigt — Wort für Wort der Zustand, in dem der Zielserver
am 7. August 2026 war, nur mit Kundendaten statt mit einem privaten Schlüssel.

`srvpanel db prune` findet diese Zeilen, zeigt sie mit Namen und Grösse und
räumt auf Nachfrage auf. Dieselbe Form wie `srvpanel tls prune`.

### Die Gegenprobe

`subscription.remove` **meldet**, was mit dem Präfix des Abonnements noch da ist
— es löscht es nicht. Das ist die Entsprechung zu `orphansOf()`, das nach dem
`userdel` nachsieht, was der UID noch gehört, und die Liste in den Vorgang
schreibt.

**Melden und nicht löschen, mit Absicht.** Die Operation, die das Konto entfernt,
kennt die Zeilen des Panels nicht. Ein `DROP DATABASE` auf alles, was einem
Präfix entspricht, wäre die eine Stelle, an der ein Fehler in der Präfixbildung
die Daten eines fremden Kunden kostet — und Präfixe sind, wie §3.1 zeigt,
genau die Sorte Sache, bei der man sich verrechnet. Wer löscht, weiss, was er
löscht: Das ist `db.database.remove` mit einem Namen aus einer Zeile.

---

## 6. Die Sperre eines Abonnements erreicht die Datenbank

Aus `docs/20 §9 P2`: *„Abo sperren (Webseiten aus, Zugänge aus, Daten
bleiben)."* Eine Datenbank ist ein Zugang.

Bis P4 nimmt `subscription.suspend` dem Abo-Verzeichnis das Ausführungsbit und
`WebLifecycle` schreibt jeden Server-Block auf 503 um. Die Datenbank bliebe davon
unberührt — und damit wäre ein gesperrtes Abonnement eines, dessen Webseite
abgeschaltet ist und dessen Datenbank jede Anwendung weiterbedient, die die
Zugangsdaten hat. Auf demselben Server über den Socket, und bei freigeschaltetem
Fernzugriff von überall. **Das ist keine Sperre, sondern eine abgeschaltete
Webseite.**

Deshalb: `DbLifecycle` beantwortet `subscription.suspend` und
`subscription.resume` und schickt `db.user.lock` beziehungsweise
`db.user.unlock` für jeden Benutzer des Abonnements — genau so, wie
`WebLifecycle::afterSubscription()` es für die Server-Blöcke tut, und aus
demselben Grund an derselben Stelle: Der Lebenslauf des Abonnements läuft in
`Lifecycles::HANDLERS` zuerst und hat den Zustand schon gesetzt.

`ALTER USER ... ACCOUNT LOCK` gibt es in MariaDB seit 10.4.2. Die vier
Zielplattformen liefern 10.6 (Ubuntu 22.04), 10.11 (Debian 12, Ubuntu 24.04) und
11.x (Debian 13) — alle darüber. `db.server.info` liest die Version trotzdem, und
das Panel bietet Datenbanken auf einem älteren Server **gar nicht erst an**,
statt eine Sperre anzubieten, die nicht sperrt. Das ist Leitbild 3: erst prüfen,
dann übernehmen.

**Die Sperre kommt zurück, die Daten bleiben.** `ACCOUNT LOCK` nimmt die
Anmeldung und lässt Schema, Tabellen und Rechte unberührt; `UNLOCK` ist die
vollständige Umkehrung. Ein `REVOKE` wäre die Alternative gewesen und die
schlechtere: Es müsste sich merken, was es weggenommen hat, um es
zurückgeben zu können — also einen zweiten Zustand neben `status` führen, und
der zweite Zustand ist der, der veraltet.

---

## 7. Das Datenmodell

Zwei Tabellen. `docs/20 §5.1` nennt sie: `Database` mit `DbUser` darunter.

### 7.1 `database/migrations/2026_08_XX_100000_create_databases_tables.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datenbanken und ihre Benutzer (P5, docs/36).
 *
 * **`subscription_name` als Abschrift und `nullOnDelete` als Fremdschlüssel** —
 * dieselbe Form wie bei `operations` und `certificates` (docs/35 §3.3), und aus
 * einem schärferen Grund als dort. Ein Schema liegt in `/var/lib/mysql` und
 * damit ausserhalb von allem, was `subscription.remove` anfasst. Kaskadierte
 * die Zeile, wäre nach einem gescheiterten `db.database.remove` das Schema da
 * und die Zeile fort — und niemand fände die Daten eines Kunden wieder, die
 * dort weiterliegen.
 *
 * **Kein `password` und keine Spalte, die eines aufnehmen könnte.** Das
 * Passwort wird erzeugt, einmal angezeigt und vergessen (docs/36 §4).
 * `SecretsStayOutOfTheStoreTest` besteht darauf.
 *
 * **`name` ist der vollständige Name samt Präfix und ist serverweit
 * eindeutig.** Nicht nur je Abonnement: Der Name ist ein Schema in MariaDB, und
 * MariaDB kennt keine Abonnements. Ein eindeutiger Index hier ist die
 * Sicherung darunter — dieselbe Rolle wie `system_users.number`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('subscription_name')->nullable();

            $table->string('name', 64)->unique();

            // Der Zusatz hinter dem Präfix — für die Oberfläche, die den
            // Kunden nicht mit `p1001_` behelligen soll. Der vollständige Name
            // steht in `name` und wird nicht aus zwei Spalten zusammengesetzt:
            // Ein Name, der an zwei Stellen entsteht, lautet irgendwann an
            // einer davon anders.
            $table->string('label', 16);

            $table->string('status', 24)->default('provisioning');
            $table->string('charset', 32)->default('utf8mb4');
            $table->string('collation', 64)->default('utf8mb4_unicode_ci');

            // Zwei Spalten und nicht eine, aus dem Grund aus docs/26 §8: Eine
            // Grösse ohne Zeitpunkt sieht aus wie eine Messung von vorhin, auch
            // wenn sie drei Tage alt ist. `null` heisst „noch nie gemessen" und
            // ist etwas anderes als 0 MB.
            $table->unsignedBigInteger('size_mb')->nullable();
            $table->timestamp('size_measured_at')->nullable();

            $table->timestamps();

            $table->index(['subscription_id', 'name']);
        });

        Schema::create('db_users', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('subscription_name')->nullable();

            $table->string('name', 32)->unique();
            $table->string('label', 16);

            // Der Wirt aus Sicht von MariaDB. `localhost` ist der Grundfall;
            // ein Fernzugriff trägt hier eine IP oder ein Netz (docs/36 §12).
            // Er steht in der Zeile und nicht als Kennzeichen, weil
            // 'p1001_web'@'localhost' und 'p1001_web'@'203.0.113.5' in MariaDB
            // zwei verschiedene Benutzer sind — mit zwei Passwörtern.
            $table->string('host', 64)->default('localhost');

            $table->string('status', 24)->default('active');
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            $table->unique(['name', 'host']);
        });

        /*
         * Die Zuordnung. Ein Benutzer kann an mehreren Datenbanken hängen —
         * eine Anwendung mit zwei Schemata ist der Normalfall, nicht die
         * Ausnahme. Und ein GRANT gilt je Paar; die Tabelle ist damit die
         * Abschrift dessen, was in MariaDB steht, und nicht eine Bequemlichkeit.
         */
        Schema::create('database_db_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_id')->constrained('databases')->cascadeOnDelete();
            $table->foreignId('db_user_id')->constrained('db_users')->cascadeOnDelete();
            $table->unique(['database_id', 'db_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_db_user');
        Schema::dropIfExists('db_users');
        Schema::dropIfExists('databases');
    }
};
```

### 7.2 `database/migrations/2026_08_XX_100100_create_database_dumps_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Ablage einer Sicherung (P5, docs/36 §10).
 *
 * **Eine Zeile je Datei, damit es einen Weg zurück gibt.** Ein Dump ist die
 * dritte Sache, die P5 auf dem System hinterlässt — und die einzige, die
 * beliebig gross wird. Ohne diese Tabelle wüsste niemand, welche Dateien unter
 * `/var/lib/srvpanel/dumps` zu welchem Abonnement gehören, und `srvpanel db
 * prune` hätte nichts, wogegen es abgleichen könnte.
 *
 * **`storage_name` und kein Pfad.** Derselbe Zuschnitt wie
 * `certificates.storage_name`: Die Anwendung nennt einen Namen, der Agent baut
 * daraus den Ablageort. Ein Prozess mit Systemrechten nimmt keinen Pfad
 * entgegen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_dumps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('subscription_name')->nullable();

            // Die Datenbank darf fort sein, der Dump bleibt — er ist ja gerade
            // das, was man nach einem Versehen noch hat.
            $table->foreignId('database_id')->nullable()
                ->constrained('databases')->nullOnDelete();
            $table->string('database_name', 64);

            $table->string('storage_name', 96)->unique();
            $table->string('kind', 16);          // 'export' | 'import'
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_dumps');
    }
};
```

### 7.3 Die Modelle

| Datei | Inhalt |
|---|---|
| `app/Models/Database.php` | `BelongsToSubscription`, `booted()` schreibt `subscription_name` ab (wie `Certificate`), `belongsToMany(DbUser)`, `orphaned()` |
| `app/Models/DbUser.php` | dito, `belongsToMany(Database)` |
| `app/Models/DatabaseDump.php` | dito, ohne Mandantenklammer für den Aufräumlauf lesbar |
| `app/Enums/DatabaseStatus.php` | `Provisioning`, `Active`, `Removing` — kein `Suspended`: Ob eine Datenbank erreichbar ist, steht am **Benutzer** (§6), nicht am Schema. Zwei Zustände für eine Frage wären zwei Antworten. |
| `app/Enums/DbUserStatus.php` | `Active`, `Locked` |

**Alle drei tragen `BelongsToSubscription`** — die Mandantenklammer verweigert
im Grundzustand alles, und ein Modell mit `subscription_id` ohne den Trait ist
genau die Lücke, die `docs/26 §5` beschreibt: *„`Subscription::query()` in einem
Controller fragt sie nie — und ein Kunde sah damit jedes Abonnement des
Servers."*

`OperationSubject` bekommt `Database = 'database'`; die Aufzählung sagt selbst,
dass sie das in P5 erwartet.

---

## 8. Die Operationen des Agenten

Alle unter `agent/src/Ops/`, eingetragen in `agent/src/Registry.php` unter einem
neuen Abschnitt `// P5 — Datenbanken.`

| Operation | Was sie tut | Warteschlange? |
|---|---|---|
| `db.server.info` | Version, Geschmacksrichtung, `bind-address`, ob der Socket antwortet | nein — liest nur |
| `db.database.create` | `CREATE DATABASE IF NOT EXISTS`, wiederholbar | nein — Millisekunden, und die Antwort schreibt die Zeile |
| `db.database.remove` | `DROP DATABASE IF EXISTS`, danach die Benutzer, die nur daran hingen | **ja** — ein `DROP` über 40 GB dauert |
| `db.user.create` | `CREATE USER` + `GRANT` auf genau die genannten Datenbanken | nein — **Passwort** (§4) |
| `db.user.password` | `ALTER USER ... IDENTIFIED BY` | nein — **Passwort** |
| `db.user.grant` | `GRANT`/`REVOKE` für ein Paar | nein |
| `db.user.remove` | `DROP USER IF EXISTS` | nein |
| `db.user.lock` | `ACCOUNT LOCK` / `UNLOCK` (§6) | **ja** — folgt einem Abonnementvorgang |
| `db.usage` | Grössen **aller** Schemata in einem Aufruf | nein — wie `subscription.usage` |
| `db.dump` | `mysqldump` in die Ablage, DEFINER wird gestrichen (§10.1) | **ja** |
| `db.restore` | Einspielen unter einem befristeten Benutzer (§10.2) | **ja** |
| `db.dump.remove` | Die Ablage wieder weg | **ja** |
| `db.isolation.probe` | Die Gegenprobe zum Abnahmekriterium (§17) | nein |

**Der Aufbau folgt `Acme/`.** Unter `agent/src/Db/` liegen die Bausteine, die
keine Operation sind:

```
agent/src/Db/Names.php      Präfix, Zusatz, Zusammensetzung, Maskierung für GRANT
agent/src/Db/Sql.php        Bezeichner in Backticks, Zeichenketten maskiert, nichts sonst
agent/src/Db/Server.php     Version und Geschmacksrichtung, einmal gelesen
agent/src/Db/Session.php    Ein Lauf gegen `mysql --protocol=socket --batch`
agent/src/Db/Dump.php       Der DEFINER-Filter und die Ablage
agent/src/Db/Ephemeral.php  Der befristete Benutzer für Restore und Probe
```

**`mysql` und `mysqldump` stehen bereits auf der Positivliste** von
`Runner::PROGRAMS` — seit P0, für `panel.provision`. P5 fügt dort **nichts**
hinzu. Das ist kein Zufall, sondern der Grund, aus dem die Liste so klein ist:
Die Angriffsfläche des Agenten wächst mit P5 um keinen einzigen Pfad.

**SQL geht über die Standardeingabe, nie als Argument.** `PanelProvision` macht
es vor, und der Grund steht dort: Ein Passwort in der Kommandozeile stünde für
jeden in der Prozessliste. Für den befristeten Benutzer in §10.2 gilt dasselbe
— sein Passwort geht über eine Optionsdatei, die der Agent mit `0600` schreibt
und im `finally` wieder entfernt, nicht über `--password=`.

---

## 9. Kontingente und Messung

`Quota::Databases` gibt es seit P2 samt Beschriftung und Hinweis („MariaDB-Schemata.
Der zugehörige Datenbankbenutzer zählt nicht getrennt."). P5 setzt es zum ersten
Mal durch — `docs/26 §13` sagt genau das voraus: *„Die übrigen Kontingente
durchsetzen. […] werden gezählt, sobald es die Objekte gibt, die sie zählen."*

**Serverseitig beim Anlegen**, wie `docs/20 §5.2` verlangt; die Oberfläche zeigt
den Stand nur an. Und nach `docs/23 §5`: Eine gesenkte Grenze verbietet die
nächste Datenbank und wirft keine vorhandene weg.

Neu dazu kommt **`Quota::DatabaseMb`** — „Grösse (gemessen)" aus dem Plan.

```php
case DatabaseMb = 'database_mb';
```

- `label()`: „Datenbankgrösse"
- `hint()`: „Über alle Datenbanken des Abonnements zusammen. Gemessen, nicht
  erzwungen — MariaDB kennt keine Obergrenze je Schema. Die Überschreitung
  erscheint in der Übersicht."
- `unit()`: `MB`, `default()`: `2_048`, `allowsUnlimited()`: **ja**

Der letzte Punkt braucht einen Satz, weil `docs/23 §3` und
`QuotaCatalogTest::test_only_shared_resources_have_no_unlimited` genau hier
zubeissen könnten: Unbegrenzt bleibt erlaubt, weil das Kontingent nichts
durchsetzt. Was den Datenträger tatsächlich begrenzt, ist `disk_mb` — nur liegt
`/var/lib/mysql` ausserhalb des Abo-Verzeichnisses und damit ausserhalb der
Dateisystem-Quota des Systembenutzers. **Das ist eine Lücke, und sie gehört
benannt statt kaschiert:** Ein Kunde kann seinen Speicherplatz einhalten und den
Datenträger über seine Datenbank füllen. `database_mb` misst es und macht es
sichtbar; erzwungen wird es in dieser Stufe nicht. (Der Weg dahin wäre eine
eigene Dateisystem-Quota für den `mysql`-Benutzer je Verzeichnis — die gibt es
nicht — oder ein Zwangsschreibschutz beim Überschreiten. Das ist eine
Entscheidung für P9, wo die Schwellen und Benachrichtigungen entstehen.)

**Ein Fund am Rande, beim Nachsehen entstanden.** `docs/23 §3` nennt den Wächter
`test_the_two_shared_resources_have_no_unlimited`; er heisst seit P3
`test_only_shared_resources_have_no_unlimited` — umbenannt, als aus zwei
Kontingenten fünf wurden, und das Dokument nicht nachgezogen. Folgenlos, aber es
ist wortwörtlich das Muster aus CLAUDE.md: *eine Zeichenkette, die auf etwas
verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft* — nur
diesmal in einem Dokument statt im Quelltext. `docs/23` wird in Schritt 5
berichtigt. Ob es sich lohnt, Testnamen in Dokumenten mechanisch zu prüfen, ist
eine eigene Frage; sie steht nicht in P5.

### Die Messung

`db.usage` — **ein Aufruf für alle Datenbanken, nicht einer je Datenbank**.
Wörtlich dieselbe Entscheidung wie bei `subscription.usage` (`docs/26 §8`), und
aus demselben Grund: Bei hundert Abonnements ist der Unterschied hundert
Prozessgründungen je Viertelstunde auf einem Server, der nebenbei Webseiten
ausliefert. Die Operation nimmt **keine Argumente**.

```sql
SELECT table_schema, SUM(data_length + index_length) AS bytes
  FROM information_schema.tables
 GROUP BY table_schema
```

**Sie meldet nur die Schemata des Panels.** `information_schema` gibt jedes
Schema des Servers aus, auch `mysql` und das der Panel-Datenbank selbst.
Herausgegeben wird nur, was der Form `p` + vier bis neun Ziffern + `_` + Zusatz
entspricht — dieselbe Regel, die der Agent beim Anlegen erzwingt, und derselbe
Satz, der in `docs/26 §8` über `repquota` steht: *„Eine Operation, die die
Benutzerliste des Servers ausliefert, wäre eine Auskunft, die niemand bestellt
hat."*

**Was gemessen wird, ist der belegte Platz und nicht die Nutzdatenmenge.**
`data_length + index_length` ist bei InnoDB der zugeteilte Platz in den
Tabellendateien, einschliesslich Freiraum nach gelöschten Zeilen. Das ist die
Zahl, um die es bei einem Kontingent geht — was auf dem Datenträger liegt. Sie
kann nach einem grossen `DELETE` über der Nutzdatenmenge stehen, und die
Oberfläche sagt „belegt" und nicht „Daten".

Gestartet wird sie vom bestehenden `srvpanel-usage.timer`: `srvpanel usage` misst
ab P5 beides. **Ein Timer und nicht zwei** — zwei Timer im Viertelstundentakt
wären zwei Dinge, die jemand überwachen muss, für eine Messung, die derselbe
Anlass auslöst.

---

## 10. Sichern und Zurückspielen

Das ist die Hälfte des Abnahmekriteriums („sichert und zurückspielt") und der
Teil mit den meisten Fallen.

**Die Ablage:** `/var/lib/srvpanel/dumps/<abonnement>/<storage_name>.sql.gz`,
Verzeichnis `root:srvpanel 0750`, Dateien `root:srvpanel 0640`.

- **Nicht unter `/var/www/vhosts/<abo>/`.** Ein Dump ist die vollständige
  Datenbank des Kunden; im Abo-Verzeichnis läge er einen Verzeichniswechsel vom
  DocumentRoot entfernt, und der Kunde darf den DocumentRoot einstellen. Ein
  Panel, das die Daten seiner Kunden in einen Ordner legt, den ein Webserver
  ausliefern kann, hat sie veröffentlicht.
- **`root:srvpanel 0640`, damit das Panel herunterladen kann, ohne den Agenten
  zu fragen.** Eine Datei von zwei Gigabyte über den Unix-Socket
  zurückzureichen, wäre der Weg, auf dem der Agent den Speicher des Servers
  füllt. Er schreibt, das Panel liest.

### 10.1 Die DEFINER-Falle

`mysqldump` schreibt zu jeder Prozedur, jedem Trigger und jeder Sicht eine
`DEFINER`-Angabe:

```sql
/*!50003 CREATE*/ /*!50017 DEFINER=`p1001_web`@`localhost`*/ /*!50003 TRIGGER ...
```

Beim Zurückspielen **unter einem anderen Benutzer** — und genau das ist der Fall,
wenn der Kunde sein Passwort zurückgesetzt hat oder wenn der Dump aus einem
anderen Abonnement stammt — bricht MariaDB mit „Access denied; you need SUPER
privileges" ab. Der Kunde sähe einen Fehlschlag, den er nicht deuten kann, bei
einer Sicherung, die er selbst angelegt hat.

Deshalb streicht `Db\Dump` die Angabe **beim Schreiben**, nicht beim Einspielen:
Ein Dump ohne DEFINER lässt sich überall einspielen, einer mit nur an genau einer
Stelle.

**Die Falle in der Falle:** Ein blindes Suchen-und-Ersetzen über den ganzen Dump
verändert auch Nutzdaten. Eine Tabelle mit dem Text `DEFINER=` in einer Spalte —
ein Forum, in dem jemand über MySQL schreibt — käme verändert zurück, und das
fiele erst auf, wenn ein Kunde seine Daten vermisst. Der Filter greift deshalb
nur auf Zeilen, die mit `/*!5` oder `CREATE ` beginnen, und nur auf die
`DEFINER=`-Angabe in einem versionierten Kommentar. `DefinerStripTest` prüft
**beide** Richtungen: Die Angabe verschwindet, und eine `INSERT`-Zeile mit
demselben Text bleibt Byte für Byte stehen.

### 10.2 Der befristete Benutzer

**Das Zurückspielen darf nicht als Datenbank-`root` laufen.** Ein Dump ist
beliebiges SQL, und der Kunde lädt ihn hoch. Als `root` über den Socket
eingespielt, wäre `GRANT ALL PRIVILEGES ON *.* TO 'p1001_web'@'localhost';` in
einer Zeile des Dumps genau der Ausbruch, den das Abnahmekriterium ausschliesst
— und er stünde nicht einmal in einem Angriff, sondern in einem Dump, den jemand
von einem anderen Server mitgebracht hat.

Es läuft deshalb als Benutzer mit Rechten auf **genau die eine Zieldatenbank**.
Weil das Passwort des Kundenbenutzers nirgends liegt (§4), erzeugt `Db\Ephemeral`
für die Dauer des Vorgangs einen eigenen:

```
p1001_r<8 zufällige Zeichen>@localhost
GRANT ALL PRIVILEGES ON `p1001\_shop`.* TO ...
… der Dump läuft …
DROP USER
```

`finally`, nicht am Ende des Erfolgspfads. Ein abgebrochener Vorgang, der einen
Benutzer stehenlässt, ist ein Zugang ohne Besitzer — und `db.server.info` sucht
beim nächsten Lauf nach Namen dieser Form, die älter als eine Stunde sind, und
meldet sie. **Auch hier: melden, nicht löschen** (§5).

Dass ein Dump `CREATE DATABASE` oder `USE andere_datenbank` enthält, ist damit
kein Sonderfall mehr, den jemand abfangen muss: Er scheitert an den Rechten,
laut und mit der Meldung des Systems.

### 10.3 Die Grössenbegrenzung

Der Plan verlangt sie („mit Größenbegrenzung"), und sie ist der Ort, an dem drei
Zahlen zusammenpassen müssen, die an drei Stellen stehen:

| Zahl | Wo sie steht | Wer sie setzt |
|---|---|---|
| `client_max_body_size` | Server-Block der Oberfläche | `agent/src/Ops/PanelVhost.php` |
| `upload_max_filesize` / `post_max_size` | FPM-Pool der Oberfläche | `agent/src/Ops/PanelProvision.php` |
| die Prüfregel am Formular | `DatabaseController` | `App\Support\Databases\ImportLimit` |

Eine davon zu ändern und die anderen nicht, ergibt einen Upload, der bei 90 %
abbricht — mit einer nginx-Fehlerseite, die von PHP nichts weiss. `UploadLimitTest`
(§16.5) liest alle drei aus ihren Quellen und besteht darauf, dass sie
zusammenpassen und dass die Prüfregel die kleinste ist: Wer abgewiesen wird, soll
die Meldung des Panels sehen und nicht die des Webservers.

Vorschlag für den Wert: **512 MB**, als Einstellung über `Settings` änderbar.
Grösseres gehört in P8 (Sicherungen mit Zielen und Aufbewahrung), nicht durch ein
Formularfeld.

---

## 11. Was in der Oberfläche entsteht

| Datei | Inhalt |
|---|---|
| `resources/js/Pages/Databases/Index.vue` | Liste über alle sichtbaren Abonnements, mit Blättern (`PaginationTest`) |
| `resources/js/Pages/Databases/Create.vue` | Zusatz, Zeichensatz, wahlweise gleich ein Benutzer |
| `resources/js/Pages/Databases/Show.vue` | Benutzer, Grösse, Sicherungen, Zurückspielen |
| `resources/js/Pages/Databases/Password.vue` | Die **einmalige** Anzeige nach dem Anlegen und nach dem Zurücksetzen |
| `resources/js/Pages/Subscriptions/Show.vue` | ein Abschnitt „Datenbanken" mit Stand gegen Kontingent |

`Password.vue` ist eine eigene Seite und kein Kasten in der Liste, und das ist
Absicht: Sie sagt in einem Satz, dass dieses Passwort **hier und nie wieder**
steht, und sie zwingt zu einem Klick, bevor man weitergeht. Ein Wert, der neben
zwölf anderen Zeilen in einer Tabelle auftaucht, wird überscrollt.

Alles Sichtbare bekommt **Screenshots in beiden Themes und bei 390 px**, und nach
jeder Aufnahme wird `scrollWidth - clientWidth` gemessen. Der Grund steht in
CLAUDE.md und hat `v0.4.0-rc.4` gekostet: eine Kennung im Fliesstext, die die
Seite um 83 px aus dem Bildschirm schob, auf einer vollständig grün getesteten
Seite. **Ein Datenbankname ist genau so eine Kennung**, und `p1001_shop` steht in
P5 auf jeder dieser Seiten.

---

## 12. Fernzugriff — der Teil, der den Server verändert

Aus dem Plan: „Zugriff nur lokal, optional Fernzugriff je Benutzer mit
IP-Beschränkung."

Der lokale Teil ist gebaut, sobald §7 steht: `'p1001_web'@'localhost'`.

Der Fernzugriff verlangt, dass MariaDB überhaupt auf einer erreichbaren Adresse
horcht — `bind-address`. **Das ist eine serverweite Änderung, und Leitbild 1
sagt: „Der Bestand ist Gesetz."** Das Panel schaltet das nicht nebenbei ein, weil
ein Kunde ein Häkchen gesetzt hat.

**Entschieden am 7. August 2026: Fernzugriff kommt, aber nur nach einem
ausdrücklichen Schalter des Betreibers** (§19, Entscheidung 5). Konkret:

1. `db.server.info` meldet die tatsächliche `bind-address` und ob der Port offen
   ist. Solange sie auf `127.0.0.1` steht, zeigt das Panel das Häkchen **nicht**
   an — mit dem Grund daneben, nicht ausgeblendet. (`AbilityReachTest`: Ein
   Knopf, den man nicht drücken darf, wird nicht gezeigt.)
2. `srvpanel db --remote=on` schreibt eine **eigene** Datei unter
   `/etc/mysql/mariadb.conf.d/60-srvpanel.cnf` — ein Include-Punkt, keine
   Distributionsdatei. Leitbild 1, und `PackagingTest` bekommt den Pfad.
3. Erst dann trägt ein Kunde je Benutzer eine IP oder ein Netz ein; daraus wird
   ein zweiter MariaDB-Benutzer `'p1001_web'@'203.0.113.5'` mit **eigenem**
   Passwort. Zwei Wirte sind in MariaDB zwei Benutzer — deshalb steht `host` in
   `db_users` und nicht als Kennzeichen.
4. `%` als Wirt wird **abgewiesen**. Ein Datenbankbenutzer, der von überall
   erreichbar ist, ist die Vorlage für den nächsten Vorfallsbericht. Wer das
   will, tippt es in `mysql` — dann ist es seine Entscheidung und nicht ein
   Feld, das wir angeboten haben.

Die Firewall (nftables) kommt erst in P9. Bis dahin sagt die Oberfläche
ausdrücklich, dass die IP-Beschränkung in MariaDB gilt und **nicht** im Paketfilter.

---

## 13. Adminer — der Teil mit der grössten neuen Angriffsfläche

> **Entschieden am 7. August 2026: aufgeschoben** (§19, Entscheidung 4). Dieser
> Abschnitt bleibt als Begründung stehen; gebaut wird er in P5 nicht.

Der Plan nennt ihn: „Adminer als eingebettetes Werkzeug, Anmeldung ohne
Passwortweitergabe." Er ist **nicht** Teil des Abnahmekriteriums, und deshalb
stand er zur Entscheidung.

Was dafür spricht: Ein Kunde ohne SSH braucht einen Weg, in seine Tabellen zu
sehen. Ohne ihn ist „Datenbanken" im Panel eine Verwaltung von Namen.

Was dagegen spricht, und es ist mehr als üblich:

- **Es ist fremder PHP-Code auf dem Panel-Host**, mit Datenbankzugangsdaten. Das
  Panel hat bisher jede Abhängigkeit dieser Art vermieden — es hat einen eigenen
  ACME-Client gebaut, statt `certbot` zu benutzen, und `certbot` ist mit P4
  wieder von der Positivliste verschwunden. Die Begründung dort gilt hier
  wörtlich: *„Ein Programm, das der Agent als root starten darf und nie startet,
  ist Angriffsfläche mit Erlaubnisschein."*
- **Es wird mit ausgeliefert und muss mit aktualisiert werden.** Eine
  Sicherheitslücke in Adminer ist ab dann eine Sicherheitslücke in SrvPanel, mit
  unserem Freigabelauf und unserer apt-Quelle. Adminer hat solche gehabt.
- **„Anmeldung ohne Passwortweitergabe" hängt an Entscheidung 3.** Das Passwort
  liegt nirgends; Adminer braucht damit denselben befristeten Benutzer wie das
  Zurückspielen (§10.2), also einen Mechanismus, der eine Sitzung lang einen
  Datenbankzugang aufmacht. Das ist baubar und es ist nicht wenig.

**Er wird nach P5b entschieden**, nicht davor: Ein eingebettetes Werkzeug für
zwei Datenbanksysteme ist eine andere Aufgabe als eines für eines. Bis dahin
steht er als Punkt in `docs/20 §15`.

---

## 14. PostgreSQL — beantwortet: eigene Stufe P5b

`docs/20 §15` führte sie als offenen Punkt 5: **„PostgreSQL wirklich in der 1.0
oder nach hinten?"**, fällig „vor P5". **Beantwortet am 7. August 2026: in der
1.0, aber als eigene Stufe mit eigenem Plan (`docs/37`) und eigener Abnahme** —
nicht als „zweiter Schritt der Stufe", wie `§9 P5` es formuliert hatte.

Was in diesem Abschnitt steht, ist damit keine Abwägung mehr, sondern die
Übergabe an P5b — und die Liste dessen, was P5 dafür **unterlassen** muss:

**Die Trennung ist die einzige Vorleistung, und sie ist eine Unterlassung.**
`agent/src/Db/` ist nach Bausteinen getrennt, `Db\Session` ist die einzige
Stelle, die `mysql` aufruft, und weder ein Modell noch eine Tabelle noch eine
Spalte trägt `mysql` im Namen. Mehr baut P5 nicht vor: keine `engine`-Spalte auf
Verdacht, keine Schnittstelle mit einer einzigen Umsetzung, keine Aufzählung mit
einem Fall. **Eine Abstraktion für ein zweites System, das es noch nicht gibt,
ist geraten** — und dieses Projekt hat mit `Feature::permission()` gerade erst
aufgeschrieben, dass ein Nullfall in der falschen Richtung teurer ist als keiner.

Was P5b zu leisten hat, steht in `docs/37` und nicht hier. Drei Punkte gehören
aber jetzt schon aufgeschrieben, weil sie die Erwartung geraderücken, P5b sei
„dasselbe noch einmal":

- **Ein eigenes Rechtemodell.** `GRANT ALL ON DATABASE` erlaubt in PostgreSQL
  kein Lesen der Tabellen; das läuft über Schemata und `ALTER DEFAULT
  PRIVILEGES`. Die Isolationszusage aus §3.1 muss dort **neu** bewiesen werden,
  nicht übertragen.
- **„Sieht keine fremde Datenbank" bedeutet dort etwas anderes.** `pg_database`
  ist für jeden lesbar; `REVOKE CONNECT ON DATABASE ... FROM PUBLIC` nimmt die
  Verbindung und nicht die Sichtbarkeit des **Namens**. Das Abnahmekriterium
  braucht in P5b eine eigene Formulierung — und genau deshalb ist eine eigene
  Abnahme die richtige Bauform.
- **Ein zweiter Dienst und zwei Pfade mehr** auf der Positivliste des Runners
  (`pg_dump`, `pg_restore`). P5 fügt dort nichts hinzu (§8); P5b tut es, und das
  ist der sichtbare Preis.

---

## 15. Die Schritte, in dieser Reihenfolge

Kein Schritt beginnt, bevor der vorige grün ist. Die CI läuft über
`workflow_dispatch` auf dem Zweig — das ist hier die Testsuite (§18).

### Schritt 0 — Die Fragen aus §19 beantworten ✓

**Erledigt am 7. August 2026**, alle vier vorgelegten. Die Antworten stehen in
§19 und sind in die Schritte eingearbeitet: Adminer entfällt, PostgreSQL wird
`docs/37`, das Passwort liegt nirgends, der Fernzugriff braucht einen
Betreiberschalter.

### Schritt 1 — Der Agent, und zwar der Weg zurück zuerst

```
agent/src/Db/Names.php           agent/src/Db/Sql.php
agent/src/Db/Server.php          agent/src/Db/Session.php
agent/src/Ops/DbServerInfo.php
agent/src/Ops/DbDatabaseRemove.php   ← vor …
agent/src/Ops/DbDatabaseCreate.php   ← … dieser Zeile geschrieben
agent/src/Ops/DbUserRemove.php
agent/src/Ops/DbUserCreate.php
agent/src/Ops/DbUserGrant.php
agent/src/Ops/DbUserLock.php
agent/src/Registry.php               (Abschnitt „P5 — Datenbanken")
tests/Unit/DbNameTest.php
tests/Unit/GrantPatternTest.php
tests/Unit/DbIsolationTest.php
```

Die Reihenfolge in der Dateiliste ist keine Koketterie. Wer `create` zuerst
schreibt, hat danach etwas, das funktioniert, und `remove` wird zur Nacharbeit —
das ist die Mechanik, aus der die Zertifikatslücke entstanden ist.

**Hier prüfbar, ohne PHPUnit:** `agent/src/autoload.php` ist framework- und
abhängigkeitsfrei. Ein Wegwerfskript im Scratchpad lädt es, ruft `Db\Names` und
`Db\Sql` auf und prüft die Behauptungen aus §3.1 als `if`. Das hat in `docs/35`
die Eindämmung einer löschenden Operation belegt, bevor die CI sie je gesehen
hat. Ausserdem läuft `phpstan.phar` Stufe 6 über `agent/src` und `tests/Support`
sauber durch.

### Schritt 2 — Die Tabellen und die Modelle

`§7` als Code, dazu die Modelle, die Aufzählungen und `OperationSubject::Database`.
`RemovalPathTest` (§16.1) kommt in diesem Schritt, nicht später — er prüft
Schritt 1 nachträglich mit.

### Schritt 3 — Die Anwendung

```
app/Support/Databases/Databases.php     Anlegen, Entfernen, Kontingentprüfung
app/Support/Databases/DbLifecycle.php   AfterOperation für db.* und subscription.*
app/Support/Databases/Credentials.php   Passworterzeugung, unmittelbarer Aufruf
app/Support/Operations/Lifecycles.php   DbLifecycle eintragen
app/Http/Controllers/DatabaseController.php
app/Policies/DatabasePolicy.php
routes/web.php
```

Der Rückbau wird **in diesem Schritt** verdrahtet:
`SubscriptionController::destroy` reiht die `db.database.remove` ein, bevor es
`subscription.remove` einreiht (§5). Ein Beitrag, der die Datenbanken anlegbar
macht und den Rückbau in Schritt 5 verschiebt, ist derselbe Fehler wie 2026 bei
den Zertifikaten.

### Schritt 4 — Die Sperre (§6)

`DbLifecycle` beantwortet `subscription.suspend` und `subscription.resume`.

### Schritt 5 — Die Messung (§9)

`agent/src/Ops/DbUsage.php`, `app/Support/Databases/Usage.php`,
`Quota::DatabaseMb`, `srvpanel usage` misst beides, Anzeige an Abonnement und
Datenbank.

### Schritt 6 — Sichern und Zurückspielen (§10)

```
agent/src/Db/Dump.php          agent/src/Db/Ephemeral.php
agent/src/Ops/DbDumpRemove.php ← wieder zuerst
agent/src/Ops/DbDump.php
agent/src/Ops/DbRestore.php
app/Support/Databases/ImportLimit.php
app/Console/Commands/Databases.php      (`srvpanel db list|prune|remote`)
packaging/bin/srvpanel                  (`db`, `acceptance-db`)
tests/Unit/DefinerStripTest.php
tests/Feature/UploadLimitTest.php
```

### Schritt 7 — Die Oberfläche und die Screenshots (§11)

Erst `npm run build`, dann aufnehmen — der Entwicklungsserver liefert aus
`public/build`, und darauf ist dieses Projekt zweimal hereingefallen.

### Schritt 8 — Die Wächter brechen

Jeder Eintrag aus §16 kommt als Bruch nach `tests/waechter-brechen.sh`, jeder mit
`vorher_datei` und `griff_datei` abgesichert. **Und dann wird er gelaufen** —
falls ein `vendor/` erreichbar ist. Falls nicht, siehe §18 und §20.

### Schritt 9 — Der Abnahmelauf (§17)

`srvpanel acceptance-db` auf dem Zielserver.

### Schritt 10 — Fernzugriff (§12)

**Ganz am Ende und in einem eigenen Beitrag**, weil er als einziger Schritt dazu
führt, dass ein Dienst auf einer erreichbaren Adresse horcht. Ohne ihn ist die
Stufe abnehmbar; er gehört nicht in denselben Beitrag wie das Anlegen der ersten
Datenbank.

```
agent/src/Ops/DbRemoteAccess.php     schreibt /etc/mysql/mariadb.conf.d/60-srvpanel.cnf
app/Console/Commands/Databases.php   `srvpanel db --remote=on|off`
packaging/                           der Include-Punkt in PackagingTest
```

### Nicht mehr in P5

- **Adminer** — aufgeschoben (§13), als Punkt in `docs/20 §15`.
- **PostgreSQL** — eigene Stufe P5b mit eigenem Plan `docs/37` (§14).

---

## 16. Wächter und ihre Brüche

Für jede Regel einer, und jeder wird absichtlich gebrochen. Ein Wächter, der nie
rot war, ist kein Wächter.

### 16.1 `tests/Feature/RemovalPathTest.php` — der wichtigste

**Nicht datenbankspezifisch.** Er hält die Registratur des Agenten gegen sich
selbst: Zu jeder Operation `<bereich>.create` (und `.apply`, `.store`) gibt es
`<bereich>.remove` (beziehungsweise `.forget`). Ausnahmen tragen ihren Grund als
Wert, wie in `WITHOUT_LIFECYCLE`.

*Warum er zählt:* Er hätte im August 2026 gemeldet, dass es zu
`acme.certificate` kein `remove` gibt — ein Jahr, bevor eine Datenmigration
danach fragte, und bevor zwölf private Schlüssel auf dem Zielserver lagen.

*Die Untergrenze zählt mit.* CLAUDE.md nennt die Falle, in die dieses Vorgehen
selbst dreimal gelaufen ist: Ein Wächter, der seine Treffer nur dort zählt, wo
die Regel gerade steht, meldet nach dem Aufräumen Rot für die Ordnung, die er
durchsetzen soll. `RemovalPathTest` behauptet deshalb eine Mindestzahl gefundener
Paare — sonst wäre er nach einer Umbenennung leer und grün.

*Bruch:* `db.database.remove` aus `Registry.php` streichen → rot.

### 16.2 `tests/Unit/GrantPatternTest.php`

Jede `GRANT`-Anweisung, die `Db\Sql` erzeugt, maskiert `_` im Datenbanknamen und
enthält an der Datenbankstelle kein `%` (§3.1).

*Bruch:* In `Db\Sql::grantTarget()` die Maskierung entfernen → rot.
*Zweiter Bruch:* Ein `GRANT ... ON \`p1001_%\`.*` in `DbUserCreate` einsetzen → rot.

### 16.3 `tests/Unit/DbIsolationTest.php`

Die erzeugten Anweisungen enthalten kein `*.*` und kein `WITH GRANT OPTION`.
Textprüfung, wie `SiteTemplateTest` und `PhpIsolationTest` — dieser Container hat
keine MariaDB, und der Schutz ist eine Eigenschaft der erzeugten Zeichenkette.

*Bruch:* `WITH GRANT OPTION` anhängen → rot.

### 16.4 `tests/Feature/SecretsStayOutOfTheQueueTest.php`

**Eine Regel, die seit P4 gilt und bisher nur eine Gewohnheit war.** Operationen,
die ein Geheimnis tragen — `tls.certificate.upload`, `dns.credential.store`,
`db.user.create`, `db.user.password` —, dürfen nie über die Warteschlange laufen,
weil `operations.payload` in der Datenbank liegt. Der Test hält zwei Listen
zusammen: die erklärte Liste der geheimnistragenden Operationen und alles, was
`Lifecycles::handled()`, `Task::operation()` und die `dispatch`-Pfade abschicken
können. Die Schnittmenge muss leer sein.

Dazu die zweite Hälfte, `SecretsStayOutOfTheStoreTest`: Weder `db_users` noch
`databases` hat eine Spalte, deren Name `password`, `secret` oder `token`
enthält.

*Bruch:* `db.user.create` in `DbLifecycle::handles()` eintragen und über
`dispatch()` einreihen → rot.

### 16.5 `tests/Feature/UploadLimitTest.php`

Die drei Grenzen aus §10.3 passen zusammen, und die Prüfregel am Formular ist die
kleinste.

*Bruch:* `client_max_body_size` in `PanelVhost` halbieren → rot.

### 16.6 `tests/Unit/DefinerStripTest.php`

Beide Richtungen (§10.1): Die `DEFINER`-Angabe verschwindet, eine `INSERT`-Zeile
mit demselben Text bleibt unverändert.

*Bruch:* Den Filter auf `str_replace` über den ganzen Dump umstellen → die zweite
Behauptung wird rot.

### 16.7 `tests/Feature/DbTenancyTest.php`

Ein Kunde sieht die Datenbanken eines fremden Abonnements nicht, kann sie nicht
umbenennen, nicht sichern und nicht löschen — die Entsprechung zu
`DomainTenancyTest`.

*Bruch:* `BelongsToSubscription` aus `App\Models\Database` entfernen → rot.

### 16.8 `tests/Feature/DbSuspensionTest.php`

Eine Sperre erreicht die Datenbankbenutzer, eine Freigabe nimmt sie zurück (§6).

*Bruch:* `subscription.suspend` aus `DbLifecycle::handles()` streichen → rot.

### 16.9 `tests/Feature/DbTeardownTest.php`

Der Rückbau eines Abonnements reiht je Datenbank ein `db.database.remove` ein,
**vor** `subscription.remove`; und eine Zeile, deren Abonnement fort ist, bleibt
über `subscription_name` auffindbar (§5).

*Bruch:* Die Einreihung aus `SubscriptionController::destroy` entfernen → rot.
*Zweiter Bruch:* `nullOnDelete` auf `cascadeOnDelete` stellen → rot.

### 16.10 `tests/Unit/DbNameTest.php`

Das Präfix kommt aus `SubscriptionProvision::systemUser()` und nicht aus einem
zweiten Ausdruck; ein Zusatz mit Backtick, Anführungszeichen, `..`, Grossbuchstabe
oder über sechzehn Zeichen wird abgewiesen.

*Bruch:* Das Muster des Zusatzes um `.` erweitern → rot.

### Wächter, die von selbst mitlaufen

`RouteAuthorizationTest`, `PolicyReachTest`, `AbilityReachTest`,
`AgentOperationReachTest`, `LifecycleReachTest`, `InertiaPagesTest`,
`ClassReachTest`, `ClassNameTest`, `TableStyleTest`, `PaginationTest`,
`NavIconTest`, `MobileLayoutTest`, `WordChoiceTest`, `FormLabelTest`,
`RedirectTargetTest`, `PackagingTest`, `ChangelogTest`. Jeder neue Menüpunkt
braucht ein gezeichnetes Zeichen, jede neue Route eine Prüfung, jede neue
Operation einen Eintrag in `WITHOUT_LIFECYCLE` **mit Grund** — und `srvpanel db`
sowie `srvpanel acceptance-db` gehören in `packaging/bin/srvpanel`, sonst meldet
`PackagingTest`.

---

## 17. Das Abnahmekriterium — als Befehlsfolge

> Fertig, wenn ein Kunde eine Datenbank anlegt, benutzt, sichert und
> zurückspielt, und ein Datenbankbenutzer nachweislich keine fremde Datenbank
> sieht.

**Warum das ein Kommando ist und kein Test.** Wörtlich der Grund aus `docs/26
§10`: Ein Test läuft gegen SQLite im Arbeitsspeicher und einen erfundenen
Agenten. Das Kriterium fragt nach dem Gegenteil — nach einer echten Verbindung,
die MariaDB abweist.

```bash
# Voraussetzung: zwei Abonnements desselben oder verschiedener Kunden.
# Der Lauf legt sie NICHT selbst an — eine Kundennummer ist auf Dauer
# verbraucht, und ein Systembenutzer erst recht (docs/35).

sudo srvpanel acceptance-db --a=abnahme-db-1.invalid --b=abnahme-db-2.invalid
```

Die sieben Kriterien, die der Lauf einzeln meldet:

```
# 1  ANLEGEN
#    In Abo A eine Datenbank und einen Benutzer anlegen.
#    erwartet: Schema p<A>_shop existiert, Benutzer 'p<A>_web'@'localhost'
#              existiert, das Passwort steht GENAU EINMAL auf dem Bildschirm.
#    Gegenprobe: es steht in keiner Zeile von `operations.payload`
#      SELECT id FROM operations WHERE payload LIKE '%<passwort>%';
#      → 0 Zeilen. Nicht die Anzahl der Vorgänge zählen — nach dem Passwort
#        suchen.

# 2  BENUTZEN
#    Als 'p<A>_web' verbinden und eine Tabelle anlegen, füllen, lesen.
#    erwartet: geht.

# 3  KEINE FREMDE DATENBANK  ← das eigentliche Kriterium
#    In Abo B ebenfalls eine Datenbank anlegen. Dann als 'p<A>_web':
#      SHOW DATABASES;
#    erwartet: die AUSGEGEBENEN NAMEN sind genau {p<A>_shop, information_schema}.
#    Nicht „eine Datenbank sichtbar" — die Namen. (CLAUDE.md: Ein Kriterium,
#    das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.)
#      USE p<B>_shop;
#    erwartet: ERROR 1044 Access denied.
#      SELECT * FROM p<B>_shop.irgendwas;
#    erwartet: ERROR 1142 oder 1044. Beide Wege, nicht nur SHOW DATABASES:
#    SHOW DATABASES ist eine Anzeige, das SELECT ist der Zugriff.

# 4  SICHERN
#    Im Panel exportieren, warten bis der Vorgang durch ist, herunterladen.
#    erwartet: die Datei liegt unter /var/lib/srvpanel/dumps/<abo>/ mit
#              root:srvpanel 0640, und sie enthält die Zeilen aus Kriterium 2.
#    Gegenprobe: sie liegt NICHT unter /var/www/vhosts/<abo>/ und ist über
#                HTTP nicht erreichbar.

# 5  ZURÜCKSPIELEN
#    Die Tabelle löschen, den Dump im Panel wieder einspielen.
#    erwartet: die Zeilen sind zurück, Byte für Byte.
#    Und: während des Laufs entsteht ein Benutzer p<A>_r<zufall> und ist
#    danach fort:
#      SELECT user FROM mysql.user WHERE user LIKE 'p%\\_r%';  → 0 Zeilen.

# 6  DER DUMP DARF KEINE RECHTE VERGEBEN
#    Einen Dump von Hand um eine Zeile ergänzen:
#      GRANT ALL PRIVILEGES ON *.* TO 'p<A>_web'@'localhost';
#    und einspielen.
#    erwartet: der Vorgang SCHEITERT mit „Access denied", und
#      SHOW GRANTS FOR 'p<A>_web'@'localhost';
#    nennt danach unverändert genau eine Datenbank.

# 7  DER RÜCKBAU LÄSST NICHTS LIEGEN
#    Abo A zurückbauen, warten bis alle Vorgänge durch sind.
#    erwartet, alle drei:
#      SHOW DATABASES LIKE 'p<A>\\_%';                        → leer
#      SELECT user,host FROM mysql.user WHERE user LIKE 'p<A>\\_%';  → leer
#      ls /var/lib/srvpanel/dumps/<abo>/                      → nicht vorhanden
#    Und die Gegenprobe, dass Abo B unberührt ist:
#      SHOW DATABASES LIKE 'p<B>\\_%';                        → p<B>_shop
#    Der letzte ist der, den man ohne Werkzeug übersieht: Ein Rückbau, der zu
#    viel wegnimmt, sieht genauso erfolgreich aus wie einer, der es richtig
#    macht.
```

**Kriterium 6 ist neu gegenüber dem Wortlaut des Plans**, und es gehört dazu:
Ohne es wäre „zurückspielen" bewiesen und die Isolation dabei aufgehoben.

---

## 18. Diese Umgebung — was hier prüfbar ist und was nicht

| | hier |
|---|---|
| `vendor/` | **nein.** `composer install` scheitert nicht an einer Laune: Die Metadaten von packagist antworten durch den Proxy mit **200**, `codeload.github.com` mit **403**. Composer löst also auf und scheitert danach beim Herunterladen. Gemessen am 7. August 2026, nicht angenommen. |
| PHPUnit | nein — Folge davon |
| PHPStan | `phpstan.phar` von den GitHub-Releases, Stufe 6 über `agent/src` **und `tests/Support`** (getrennt zu laufen kostet die teuerste Meldung, siehe CLAUDE.md) |
| Pint | `pint.phar` von den Releases; `php-cs-fixer` ist **nicht** Pint |
| MariaDB | nein — deshalb ist jede Prüfung in §16.2, §16.3 und §16.6 eine Textprüfung |
| `agent/` fahren | **ja**, über `agent/src/autoload.php` aus einem Wegwerfskript im Scratchpad |
| npm | ja — `npm run types`, `npm run build`, und damit Screenshots über das gebaute Stylesheet |
| die Testsuite | **`workflow_dispatch` auf `ci.yml`**, auf dem Zweig. Ein Lauf bringt dasselbe Ergebnis wie ein PR, ohne einen zu öffnen. |

Jede Änderung an `app/`, `agent/` oder `tests/` kostet damit eine Runde CI. Das
ist eingeplant und kein Grund, Schritte zusammenzuziehen.

---

## 19. Die Entscheidungen des Betreibers

Getroffen am 7. August 2026, alle vier vorgelegten:

1. **PostgreSQL bleibt in der 1.0 — aber als eigene Stufe P5b**, mit eigenem
   Plan und eigener Abnahme. Damit ist `docs/20 §15` Punkt 5 beantwortet, und
   zwar in einer dritten Form, die im Entwurf nicht stand: nicht „mitgeschleift
   als zweiter Schritt der Stufe" und nicht „nach hinten", sondern **getrennt
   abnehmbar**.

   Das ist die Antwort, die den Zuschnitt aus §14 einlöst statt ihn nur zu
   behaupten. Der Punkt dort war, dass ein zweites System eine Erweiterung ist
   und kein Umbau — eine eigene Stufe ist die Bauform, in der das überprüfbar
   wird: Wenn P5b `agent/src/Db/` nicht aufreissen muss, war die Trennung
   richtig; wenn doch, fällt es auf, bevor MariaDB darunter leidet. Und die
   Abnahme von P5 hängt nicht mehr an einem halbfertigen zweiten System.

   **Folge für diesen Plan:** Schritt C entfällt hier und wird `docs/37`.
   Was P5 dafür schuldet, ist keine Vorleistung, sondern eine Unterlassung —
   nichts in `app/` oder in den Tabellen darf `mysql` im Namen tragen, und
   `Db\Session` bleibt die einzige Stelle, die `mysql` aufruft. §21 rechnet P5b
   nicht mit; es ist eine eigene Stufe mit eigenem Umfang.

2. **Eine Datenbank wird beim Rückbau des Abonnements geworfen** — über eigene
   Vorgänge vor `subscription.remove`, mit der Abschrift und `nullOnDelete` als
   Auffanglinie und `srvpanel db prune` als Weg zurück (§5). Leitbild 4 lässt
   wenig anderes zu; eine Sicherung davor kommt mit P8, so wie `docs/26 §13` es
   für den übrigen Rückbau schon festhält.

3. **Das Datenbankpasswort liegt nirgends** (§4, Möglichkeit a). Das Panel
   erzeugt es, schickt es in einem unmittelbaren Aufruf an den Agenten, zeigt es
   genau einmal und vergisst es. Zurücksetzen erzeugt ein neues. Das
   Zurückspielen bekommt den befristeten Benutzer aus §10.2.

   Damit ist auch entschieden, was §16.4 prüft: `db.user.create` und
   `db.user.password` laufen nie über die Warteschlange, und weder `databases`
   noch `db_users` bekommt eine Spalte, die ein Geheimnis aufnehmen könnte.

4. **Adminer wird aufgeschoben** und als Punkt in `docs/20 §15` aufgenommen
   (§13). Schritt B entfällt aus P5.

   Mit Entscheidung 1 zusammen wird daraus mehr als ein Aufschub: Ein
   eingebettetes Werkzeug für **zwei** Datenbanksysteme ist eine andere Aufgabe
   als eines für eines, und es gehört nach P5b entschieden, nicht davor.

5. **Fernzugriff kommt in die 1.0, aber nur nach einem Betreiberschalter**
   (§12): `srvpanel db --remote=on` schreibt einen Include-Punkt unter
   `/etc/mysql/mariadb.conf.d/`, nie eine Distributionsdatei. Solange
   `bind-address` auf `127.0.0.1` steht, zeigt das Panel das Häkchen gar nicht
   an — mit dem Grund daneben, nicht ausgeblendet. `%` als Wirt wird abgewiesen.
   Die Oberfläche sagt ausdrücklich, dass die Beschränkung in MariaDB gilt und
   nicht im Paketfilter; der kommt mit P9.

   Schritt A bleibt damit, wandert aber ans Ende: Ohne ihn ist die Stufe
   abnehmbar, mit ihm horcht ein Dienst auf einer erreichbaren Adresse. Das
   gehört nicht in denselben Beitrag wie das Anlegen der ersten Datenbank.

**Nicht vorgelegt, weil entscheidbar — und deshalb hier zum Widerspruch:**

6. **Die Grenze für den Import ist 512 MB**, über `Settings` änderbar (§10.3).
   Grösseres gehört in P8, wo es Sicherungsziele und Aufbewahrung gibt, und
   nicht in ein Formularfeld.
7. **Der Kunde wählt sein Datenbankpasswort nicht**, es wird erzeugt (§4). Das
   ist umkehrbar, falls jemand beim Umzug einer Anwendung ein bestimmtes
   braucht — dann ist es ein Feld mit einer Prüfregel und keine Architekturfrage.

---

## 20. Risiken, ehrlich benannt

1. **`waechter-brechen.sh` bleibt zur Hälfte ungeprüft — und P5 macht es
   schlimmer.** `docs/35 §12.4` hält fest, dass die zwölf Eingriffe jenes Umbaus
   nachweislich in ihre Zieldatei greifen, dass aber niemand gesehen hat, ob die
   Wächter danach rot werden. Dafür braucht es ein lokales PHPUnit, und
   `composer install` scheitert hier aus einem Grund, der sich nicht aussitzt
   (§18). P5 legt zehn weitere Brüche darauf. **Diese Schuld wird nicht kleiner,
   indem man sie weiterreicht.** Der Ausweg, der bleibt: Jeder Bruch wird über
   `griff_datei` abgesichert (dass er greift, ist damit gemessen), und die
   Wächter selbst laufen in der CI. Was fehlt, ist die Aussage „der Wächter wird
   *durch diesen Bruch* rot" — und die gehört nachgeholt, sobald ein `vendor/`
   erreichbar ist. Sie steht bis dahin in `docs/36` und in `docs/35 §12.4`.
2. **Die Datenbankgrösse steht ausserhalb der Speicherquota** (§9). Ein Kunde
   kann sein `disk_mb` einhalten und den Datenträger über `/var/lib/mysql`
   füllen. P5 misst und zeigt es; erzwungen wird es nicht. Wer das für
   untragbar hält, muss es sagen, bevor die Stufe abgenommen wird — es ist keine
   Nachlässigkeit, sondern eine Grenze von MariaDB.
3. **Ein Dump kann grösser sein als der freie Platz.** `db.dump` prüft vorher
   gegen den freien Platz auf dem Dateisystem der Ablage und weist ab, statt es
   zu versuchen — ein Panel, das beim Sichern den Datenträger füllt, nimmt jeden
   anderen Kunden mit. Die Prüfung ist eine Schätzung (die Grösse aus §9 gegen
   `disk_free_space`) und deshalb mit Reserve.
4. **`information_schema` liefert bei InnoDB zugeteilten und nicht belegten
   Platz** (§9). Für ein Kontingent ist das die richtige Zahl; für die Frage
   „wie viele Daten habe ich" ist sie es nicht, und die Oberfläche muss „belegt"
   sagen und nicht „Daten".
5. **`ACCOUNT LOCK` gibt es erst ab MariaDB 10.4.2.** Alle vier Zielplattformen
   liegen darüber, aber ein Server mit einer selbst gebauten älteren Fassung
   bekommt keine Datenbanken angeboten — mit dem Grund im Klartext, nicht
   ausgeblendet.
6. **Der Abnahmelauf braucht zwei Abonnements und verbraucht zwei
   Systembenutzer**, endgültig (`docs/35`). Das ist eingerechnet und nicht
   umkehrbar; auf einem Server, auf dem das stört, gehört der Lauf auf einen
   Testserver.

---

## 21. Umfang

| Bereich | neue Dateien | geänderte |
|---|---|---|
| `agent/` | 6 Bausteine, 12 Operationen | `Registry.php` |
| `app/` | 3 Modelle, 2 Aufzählungen, 4 Dienste, 1 Controller, 1 Policy, 1 Kommando | `Lifecycles`, `Quota`, `OperationSubject`, `SubscriptionController`, `routes/web.php` |
| `database/` | 2 Migrationen | — |
| `resources/` | 4 Seiten | `Subscriptions/Show.vue`, `app.css`, Menü |
| `tests/` | 10 Wächter | `waechter-brechen.sh`, `AgentOperationReachTest` |
| `packaging/` | — | `bin/srvpanel`, `srvpanel-usage` |
| `docs/` | dieses | `20 §15`, `23`, `CHANGELOG`, `CLAUDE.md` |

Geschätzt zwei bis drei Wochen — dieselbe Grössenordnung, die `docs/20 §9` für
P5 nennt. **PostgreSQL ist darin nicht enthalten**; es ist mit Entscheidung 1
eine eigene Stufe P5b mit eigenem Plan, eigenem Umfang und eigener Abnahme.

---

## 22. Umsetzung — was beim Bauen anders war als im Plan

Geschrieben am 7. August 2026, nach Schritt 1 bis 3 und den Wächtern. Der Plan
oben steht unverändert; hier steht, was er nicht wusste.

### 22.1 Fünf Funde, die der Plan nicht vorhergesehen hat

1. **Die Form des befristeten Benutzers war für Kunden wählbar.** §10.2 nennt
   `p1001_r<8 Hexziffern>`; die Zusatzregel aus §3 lässt `r3f9a20c1` zu —
   Kleinbuchstaben und Ziffern, beginnend mit einem Buchstaben. Ein Kunde hätte
   seinen Zugang so nennen dürfen, `db.server.info` hätte ihn eine Stunde später
   als Rest eines abgebrochenen Zurückspielens gemeldet, und `srvpanel db prune`
   hätte ihn weggeworfen. **Ohne dass irgendetwas falsch programmiert wäre.**
   Die Form ist jetzt in `Names::suffix()` reserviert.

   Aufgefallen ist es beim **Schreiben des Tests**, nicht beim Schreiben des
   Codes: `DbNameTest` sollte behaupten, `p1001_r12345678` sei ein befristeter
   Name — und dabei fiel auf, dass genau dieser Name auch aus dem Formular
   kommen kann.

2. **Ein Name, der der Basisklasse gehört — zweimal.** `DatabaseFactory::for()`
   (Laravels `Factory::for()` hat eine andere Signatur) und
   `GrantPatternTest::matches()` (`PHPUnit\Framework\Assert::matches()` ist
   `final` und `static`). Beide brechen beim **Laden** der Klasse. Den ersten
   hat ein Blick in die Basisklasse gefangen, den zweiten nicht — und er hat
   `php artisan test` mit Rückgabewert 255 beendet, bevor ein einziger Test
   lief. Nicht eine Datei stand still, sondern alle vierundsiebzig.

3. **`RemovalPathTest` musste eine Ausnahme mehr tragen, als der Plan
   annahm**, und eine weniger: `php.version.install` findet über die Wurzel von
   selbst `php.version.remove` (die Endungen sind verschieden, die Wurzel ist
   dieselbe), und `acme.account.ensure` braucht einen Eintrag in `PAIRS`, weil
   ein ACME-Konto nicht entfernt wird, sein Zertifikat aber schon.

4. **Der Bruch für das Schema fasst `database/` an**, und `wiederherstellen()`
   in `tests/waechter-brechen.sh` kannte nur `resources/ app/ agent/ packaging/
   .github/`. CLAUDE.md benennt genau das: *„Ein Bruch in einem Verzeichnis, das
   `wiederherstellen` nicht kennt, ist keine Probe, sondern eine Änderung."*
   `database/` steht jetzt in beiden Listen.

5. **`docs/23 §3` nannte einen Wächter unter seinem alten Namen** (§9). Kleiner
   Fund, dasselbe Muster.

6. **Zwei Gestaltungsfehler, die kein Test der Anwendung gesehen hätte** — und
   beide auf derselben Seite:

   - Drei Bereiche ohne `<div class="sections">`. In Kontor hat ein Bereich
     keinen eigenen Aussenabstand; ohne den Behälter bekommen sie **gar
     keinen**. Das hat `SectionSpacingTest` gemeldet — ein Wächter, den es erst
     seit dem 7. August gibt, und er hat beim ersten Neuling zugebissen.
   - Eine Bezeichnungstabelle in `<div class="scrolls">`. Der Rollbehälter gibt
     ihr Raum, breiter zu werden als ihr Bereich — und macht damit
     `table.pairs td.ident { white-space: normal }` wirkungslos, also genau die
     Regel, die app.css am 7. August für diesen Fall bekommen hat.
     `p123456789_aaaaaaaaaaaaaaaa` schob die Tabelle bei 390 px um **52 px**
     hinaus, statt umzubrechen. **Der Seitenüberlauf war dabei 0** — der Kasten
     rollt, die Seite nicht; ein Wächter, der nur `scrollWidth - clientWidth`
     misst, sieht davon nichts. Gemessen wurde die Zelle gegen den Bildschirm.

   Beide sind über den Weg aus CLAUDE.md gefunden worden, und der brauchte
   selbst eine Korrektur: **`chromium --headless --window-size=390,…` rendert
   nicht bei 390 px.** Chrome erzwingt eine Mindestbreite von 500; der
   Screenshot wird auf 390 beschnitten, gerechnet wird gegen 500. Damit sind
   auch die `@media`-Blöcke für die schmale Fläche nie eingesprungen — die
   Aufnahme sah aus wie 390 px und war es an keiner Stelle. Playwright
   (`newPage({ viewport: { width: 390 } })`) mit dem vorinstallierten Chromium
   setzt die Breite wirklich.

### 22.2 Zwei Abweichungen vom Plan, bewusst

- **Die Abschrift `subscription_name` steht in `booted()` und nicht in einem
  Trait** — jetzt an vier Modellen (`Operation`, `Certificate`, `Database`,
  `DbUser`). Vier Fassungen derselben Schleife sind in diesem Projekt sonst der
  Anlass, sie einzusammeln. Der Grund dagegen steht in `Operation::booted()`:
  `BelongsToSubscription` setzt `subscription_id` selbst, wenn genau ein Mandant
  aktiv ist, und das muss vorher geschehen sein. Ein Trait hinge damit an der
  Reihenfolge zweier `creating`-Zuhörer — an einer Eigenschaft, die niemand beim
  Lesen sieht und die ein umsortiertes `use` still kippt. **Aufgeschrieben statt
  behoben:** Der Weg wäre ein Trait, dessen Reihenfolge ein Wächter festhält.
  Das ist eine eigene Änderung an vier Modellen.

- **Der Zeichensatz ist nur `utf8mb4`**, statt einer Auswahl. `utf8` in MySQL
  ist drei Byte breit und kann kein Emoji speichern — es ist der Zeichensatz,
  der eine Anwendung genau einmal überrascht, und zwar in der Produktion.
  Gewählt wird nur die Sortierung.

### 22.3 Was noch fehlt

Schritt 4 bis 10 aus §15 stehen aus: die Sperre ist **gebaut**
(`DbLifecycle`), die Messung (§9), Sichern und Zurückspielen (§10), die
Screenshots (§11), `srvpanel db` und `acceptance-db` (§17) sowie der Fernzugriff
(§12) nicht. Das Abnahmekriterium von P5 ist damit **nicht** erfüllt: Anlegen
und Benutzen gehen, Sichern und Zurückspielen nicht, und die Gegenprobe zur
Mandantentrennung ist bisher eine Eigenschaft der erzeugten Zeichenkette
(`DbIsolationTest`, `GrantPatternTest`) und keine Verbindung, die MariaDB
abgewiesen hat.

**Und die Bringschuld aus §20 Punkt 1 ist gewachsen.** Alle acht neuen Eingriffe
in `tests/waechter-brechen.sh` greifen nachweislich in ihre Zieldatei — gemessen
mit einem Wegwerfskript, das jeden einzeln anwendet und `cmp` gegen die Fassung
davor hält. Ob die Wächter danach rot werden, braucht weiterhin ein lokales
PHPUnit.

### 22.4 Eine Umgebungsnotiz, die künftig Runden spart

**`pint.phar` lässt sich von den GitHub-Releases holen, und es *ist* Pint.**
CLAUDE.md stand bis hierher auf `php-cs-fixer.phar` als Behelf, mit der
richtigen Warnung, dass das nicht dasselbe ist. `pint.phar` in der Fassung, die
`composer.json` verlangt, sagt über das ganze Repo dasselbe wie die CI —
gegengeprüft, beide grün. Damit fällt eine ganze Klasse von CI-Runden weg.

Und die Trennlinie des Proxys ist jetzt vermessen statt vermutet: packagist
antwortet mit **200**, `codeload.github.com` mit **403**. Composer löst auf und
scheitert beim Herunterladen — deshalb kein `vendor/`, und deshalb funktionieren
einzelne `.phar`-Dateien trotzdem.
