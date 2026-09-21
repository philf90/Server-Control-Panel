<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die verdichteten Tageszahlen — B3, `docs/129 §6`.
 *
 * ## Lang und nicht breit
 *
 * Gemessen (`docs/128` M1) über 30 Tage und 2000 Abonnements: die lange Form
 * 300 000 Zeilen und **32 MiB**, die breite 60 000 und 16. Der Plan nimmt die
 * lange, und die 16 MiB Unterschied sind kein Argument gegen eine Form, die
 * eine sechste Kennzahl **ohne Migration** aufnimmt.
 *
 * > **Die Summe der Feldbreiten ist keine Dateigrösse.** Gerechnet wären es
 * > 5,72 MiB; auf der Platte liegen 32.
 *
 * ## Zwei Tabellen und nicht eine — und das ist gemessen
 *
 * Der naheliegende Entwurf ist **eine** Tabelle mit einer nullbaren
 * `domain_id`: `NULL` hiesse „die Zahl des Abonnements selbst". Dann trüge der
 * eindeutige Index `(subscription_id, domain_id, day, metric)`, und der
 * Nachtlauf könnte je Tag überschreiben statt zu addieren — die Zusage, die
 * `web.access.count` in seinem eigenen Kopf macht.
 *
 * **Er trüge nicht.** Gemessen am 21. September 2026 gegen SQLite, mit
 * Gegenprobe: Dieselbe Zeile mit `domain_id = NULL` ging **zweimal** durch,
 * dieselbe Zeile mit einer echten Kennung wurde abgewiesen. Das ist kein
 * Fehler von SQLite, sondern die Regel von SQL — zwei `NULL` gelten als
 * verschieden, und MariaDB hält es genauso.
 *
 * > **Ein `NULL` in einem eindeutigen Index verhindert nichts — und der
 * > Schaden ist eine zweite Zeile je Nacht, die wie ein Messwert aussieht.**
 *
 * Zwei Tabellen brauchen die Frage gar nicht zu stellen: Jede Spalte ihres
 * Schlüssels ist `NOT NULL`.
 *
 * ## Keine Zeitstempel
 *
 * `created_at` und `updated_at` kosten bei 300 000 Zeilen Platz und beantworten
 * nichts: Der **Tag** ist der Zeitpunkt, und wann die Zeile geschrieben wurde,
 * sagt über die Zahl darin nichts. Eine Zeile wird ohnehin jede Nacht neu
 * geschrieben, solange ihr Tag in Reichweite des Nachtlaufs liegt.
 *
 * ## Die Klammer sitzt auf beiden Tabellen
 *
 * Auch die Domaintabelle führt `subscription_id`, obwohl die Domain es schon
 * weiss. Das ist die dritte Grenze und keine Bequemlichkeit: {@see
 * BelongsToSubscription} klammert über genau diese Spalte, und ein Weg über
 * einen Verbund wäre eine zweite Fassung derselben Regel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_metrics', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            /*
             * **Der Tag in der Zeitrechnung des Servers**, nicht in UTC.
             *
             * Er kommt aus der Zeile des Zugriffsprotokolls, und die schreibt
             * nginx in der Ortszeit der Maschine. Eine Umrechnung hier wäre die
             * zweite Fassung der Frage, die `App\Support\Cron\ServerZone`
             * beantwortet.
             */
            $table->date('day');

            $table->string('metric', 32);

            /*
             * **Unsigned und gross.** Traffic sind Bytes: Ein Abonnement mit
             * 500 GB an einem Tag ist 5,4e11 — jenseits von `int`. Negativ
             * kann keine dieser Kennzahlen werden; was sie zählen, kann nur
             * mehr werden oder null sein.
             */
            $table->unsignedBigInteger('value');

            $table->unique(['subscription_id', 'day', 'metric']);

            /* Für das Abräumen nach 30 Tagen (Entscheidung 4). */
            $table->index('day');
        });

        Schema::create('domain_metrics', function (Blueprint $table): void {
            $table->id();

            /*
             * **Beide Kennungen, und beide mit `cascadeOnDelete`.**
             *
             * Eine zurückgebaute Domain nimmt ihre Zeilen mit. Verloren geht
             * dabei nichts, was das Abonnement braucht: Dessen eigene Zahlen
             * stehen in der Tabelle darüber und sind keine Summe über diese
             * hier — sie kommen aus demselben Lauf, nur eine Ebene höher.
             */
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();

            $table->date('day');
            $table->string('metric', 32);
            $table->unsignedBigInteger('value');

            $table->unique(['domain_id', 'day', 'metric']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_metrics');
        Schema::dropIfExists('subscription_metrics');
    }
};
