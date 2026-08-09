<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL kommt dazu (P5b, docs/38 §9).
 *
 * **Eine `engine`-Spalte und kein zweiter Satz Tabellen.** Die Unterschiede
 * zwischen den beiden Systemen sitzen unterhalb des Agenten-Protokolls
 * (`docs/38 §8`); oben sind es Datenbanken mit Namen, Grösse und Zugängen. Zwei
 * Tabellen wären zwei Policies, zwei Controller und zwei Seiten für einen
 * Unterschied, den der Kunde nicht sieht.
 *
 * **`default('mariadb')` und nicht `nullable()`.** Jede Zeile, die es beim
 * Migrieren schon gibt, *ist* eine MariaDB-Datenbank — das ist kein Vorgabewert
 * auf Verdacht, sondern eine Tatsache über den Bestand. Ein `null` hiesse
 * „unbekannt", und unbekannt ist hier nichts.
 *
 * **`db_prefix` gehört zu `system_users` und nicht zu `subscriptions`.** Es darf
 * nie zweimal vergeben werden — sonst könnte der Name einer neuen Datenbank auf
 * ein Datenverzeichnis treffen, das ein gescheitertes `DROP DATABASE`
 * hinterlassen hat. Die Tabelle, die genau das leistet, gibt es seit `docs/35`:
 * Eine Zeile darin wird nie freigegeben, auch wenn ihr Abonnement hart gelöscht
 * wird.
 *
 * **Und `db_user_networks` ist die eine Stelle, an der das Datenmodell von P5
 * bricht.** `docs/37 §4` hat sie als „die teuerste Zeile" der Übergabetabelle
 * angekündigt, und sie ist es geworden: In MariaDB sind
 * `'p1001_web'@'localhost'` und `'p1001_web'@'203.0.113.5'` zwei Benutzer mit
 * zwei Passwörtern, weshalb `(name, host)` dort eindeutig und richtig ist. In
 * PostgreSQL ist es **eine** Rolle mit einem Passwort und mehreren erlaubten
 * Netzen (`docs/38 §14.3`). Zwei Zeilen mit demselben Namen wären hier nicht
 * zwei Zugänge, sondern zwei Regeln für einen — und `pg.role.create` liefe
 * zweimal und setzte ein zweites Passwort auf dieselbe Rolle.
 */
return new class extends Migration
{
    /**
     * Die Form eines Präfixes — dieselbe wie in `SrvPanel\Agent\Pg\Names`.
     *
     * **Sie steht hier ein zweites Mal, und das ist die Ausnahme, die eine
     * Migration rechtfertigt.** Eine Migration darf nicht von einer Klasse
     * abhängen, die sich morgen ändert: Sie läuft einmal, und was sie schreibt,
     * muss zu dem passen, was an dem Tag galt. Dieselbe Überlegung wie bei
     * `kind` in `create_database_dumps_table`, wo die Werte als Zeichenketten
     * stehen und nicht als Aufzählung.
     */
    private const PREFIX_BYTES = 8;

    public function up(): void
    {
        /*
         * **Erst die Spalten, dann der Bestand.** DDL ist auf MariaDB nicht
         * transaktional — dieselbe Überlegung, die schon
         * `create_system_users_table` aufschreibt. Ein Abbruch zwischen
         * `ALTER TABLE` und dem Nachtragen liesse Zeilen ohne Präfix zurück,
         * und der zweite Lauf scheiterte an der vorhandenen Spalte statt am
         * eigentlichen Grund. Deshalb ist das Nachtragen unten so geschrieben,
         * dass es einen zweiten Lauf überlebt.
         */
        foreach (['databases', 'db_users', 'database_dumps'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('engine', 16)->default('mariadb')->after('subscription_name');

                // Der Index trägt dieselbe Frage wie der aus P5 — „was gehört
                // diesem Abonnement" —, nur eine Spalte breiter: Die Liste im
                // Panel zeigt beide Systeme nebeneinander, das Kontingent zählt
                // über beide (docs/38 §12), und der Rückbau geht je System vor.
                $blueprint->index(['subscription_id', 'engine'], $table.'_subscription_engine_index');
            });
        }

        Schema::table('system_users', function (Blueprint $table): void {
            /*
             * **Eindeutig, aber nicht `NOT NULL`.** Der Bestand wird unten
             * vollständig nachgetragen; eine Zeile ohne Präfix gibt es danach
             * nicht. Die Spalte trotzdem auf `NOT NULL` zu ziehen wäre eine
             * zweite Schemaänderung nach dem Füllen — und die kann auf MariaDB
             * zwischen den beiden Schritten abbrechen, weil DDL dort nicht
             * transaktional ist. Was die Vollständigkeit sichert, ist das
             * Nachtragen und `Lifecycle::claim()`, nicht eine Zusicherung, die
             * beim Anlegen nicht zu haben ist.
             */
            $table->string('db_prefix', 24)->nullable()->unique();
        });

        Schema::create('db_user_networks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('db_user_id')->constrained('db_users')->cascadeOnDelete();

            /*
             * **Als Text und nicht als zwei Spalten.** Was hier steht, geht
             * Zeichen für Zeichen in eine Zeile von `pg_hba.conf`, und die
             * Schreibweise ist die von PostgreSQL. Zerlegt und wieder
             * zusammengesetzt wäre es eine zweite Fassung derselben Regel — und
             * die zweite ist die, die veraltet. 43 Zeichen sind die längste
             * IPv6-Angabe mit Präfixlänge.
             */
            $table->string('cidr', 43);

            $table->timestamps();
            $table->unique(['db_user_id', 'cidr']);
        });

        $this->fillPrefixes();
    }

    public function down(): void
    {
        Schema::dropIfExists('db_user_networks');

        Schema::table('system_users', function (Blueprint $table): void {
            $table->dropUnique(['db_prefix']);
            $table->dropColumn('db_prefix');
        });

        foreach (['databases', 'db_users', 'database_dumps'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex($table.'_subscription_engine_index');
                $blueprint->dropColumn('engine');
            });
        }
    }

    /**
     * Jedem vorhandenen Systembenutzer ein Präfix geben.
     *
     * **Jetzt und nicht beim ersten Gebrauch.** Ein Präfix, das erst entsteht,
     * wenn jemand die erste PostgreSQL-Datenbank anlegt, braucht einen zweiten
     * Weg — und der müsste gegen zwei gleichzeitige Anlagen abgesichert sein,
     * die beide dasselbe leere Feld sehen. Hier ist es eine Schleife, die
     * einmal läuft.
     *
     * **Zeilenweise und nicht in einer Anweisung.** Jede Zeile bekommt eigene
     * Zufallsbytes; ein `UPDATE … SET db_prefix = …` gäbe allen denselben Wert
     * und liefe in den eindeutigen Index. Auf hundert Abonnements sind das
     * hundert kleine Schreibvorgänge — einmal, bei einem Update.
     *
     * **Und es überlebt einen zweiten Lauf**, weil nur genommen wird, was noch
     * keins hat.
     */
    private function fillPrefixes(): void
    {
        DB::table('system_users')->whereNull('db_prefix')->orderBy('id')
            ->each(function (object $row): void {
                DB::table('system_users')
                    ->where('id', $row->id)
                    ->update(['db_prefix' => 'x'.bin2hex(random_bytes(self::PREFIX_BYTES))]);
            });
    }
};
