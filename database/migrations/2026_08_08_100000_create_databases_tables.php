<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datenbanken und ihre Benutzer (P5, docs/36 §7).
 *
 * **`subscription_name` als Abschrift und `nullOnDelete` als Fremdschlüssel** —
 * dieselbe Form wie bei `operations` und `certificates` (docs/35 §3.3), und aus
 * einem schärferen Grund als dort. Ein Schema liegt in `/var/lib/mysql` und
 * damit ausserhalb von allem, was `subscription.remove` anfasst. Kaskadierte
 * die Zeile, wäre nach einem gescheiterten `db.database.remove` das Schema da
 * und die Zeile fort — und niemand fände die Daten eines Kunden wieder, die
 * dort weiterliegen. Genau dieser Zustand lag am 7. August 2026 auf dem
 * Zielserver vor, nur mit privaten Schlüsseln statt mit Kundendaten.
 *
 * **Keine Spalte, die ein Passwort aufnehmen könnte.** Das Passwort wird
 * erzeugt, einmal angezeigt und vergessen (docs/36 §4, Entscheidung 3 des
 * Betreibers). `SecretsStayOutOfTheStoreTest` besteht darauf — und zwar am
 * Schema und nicht an einer Absicht: Eine Spalte, die es nicht gibt, lässt sich
 * nicht versehentlich füllen.
 *
 * **`name` ist der vollständige Name samt Präfix und serverweit eindeutig.**
 * Nicht nur je Abonnement: Der Name ist ein Schema in MariaDB, und MariaDB
 * kennt keine Abonnements. Der eindeutige Index ist die Sicherung darunter —
 * dieselbe Rolle wie `system_users.number`.
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

            /*
             * Der Zusatz hinter dem Präfix — für die Oberfläche, die den Kunden
             * nicht mit `p1001_` behelligen soll.
             *
             * Der vollständige Name steht in `name` und wird **nicht** aus zwei
             * Spalten zusammengesetzt: Ein Name, der an zwei Stellen entsteht,
             * lautet irgendwann an einer davon anders. `label` ist eine
             * Abschrift für die Anzeige, so wie `subscriptions.main_domain`
             * eine ist.
             */
            $table->string('label', 16);

            $table->string('status', 24)->default('provisioning');
            $table->string('charset', 32)->default('utf8mb4');
            $table->string('collation', 64)->default('utf8mb4_unicode_ci');

            /*
             * Zwei Spalten und nicht eine, aus dem Grund aus docs/26 §8: Eine
             * Grösse ohne Zeitpunkt sieht aus wie eine Messung von vorhin, auch
             * wenn sie drei Tage alt ist. `null` heisst „noch nie gemessen" und
             * ist etwas anderes als 0 MB.
             */
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

            $table->string('name', 32);
            $table->string('label', 16);

            /*
             * Der Wirt aus Sicht von MariaDB. `localhost` ist der Grundfall;
             * ein Fernzugriff trägt hier eine IP oder ein Netz (docs/36 §12).
             *
             * Er steht als eigene Spalte und nicht als Kennzeichen, weil
             * `'p1001_web'@'localhost'` und `'p1001_web'@'203.0.113.5'` in
             * MariaDB **zwei verschiedene Benutzer** sind — mit zwei
             * Passwörtern und zwei Rechtelisten. Ein Kennzeichen „darf von
             * aussen" wäre die Sorte Vereinfachung, die beim ersten Zurücksetzen
             * eines Passworts auffliegt.
             */
            $table->string('host', 64)->default('localhost');

            $table->string('status', 24)->default('active');
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            // Das Paar ist eindeutig, der Name allein nicht — siehe oben.
            $table->unique(['name', 'host']);
            $table->index('subscription_id');
        });

        /*
         * Die Zuordnung. Ein Benutzer kann an mehreren Datenbanken hängen —
         * eine Anwendung mit zwei Schemata ist der Normalfall, nicht die
         * Ausnahme. Und ein `GRANT` gilt je Paar; diese Tabelle ist damit die
         * Abschrift dessen, was in MariaDB steht, und keine Bequemlichkeit.
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
