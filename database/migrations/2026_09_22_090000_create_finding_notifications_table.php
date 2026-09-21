<?php

declare(strict_types=1);

use App\Support\Diagnose\FindingLog;
use App\Support\Notify\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Erinnerung an die Zustellung, je Kanal (B5 und B1, `docs/129 §4` Punkt 1).
 *
 * **Hier stand bis zum 24. September 2026 eine Spalte `notified_at` an
 * `findings`**, und `docs/129 §4` Punkt 1 bietet beide Formen an: *„Eine Spalte
 * `notified_at` an `findings` — oder, wenn mehrere Kanäle je Befund getrennt
 * buchen sollen, eine eigene kleine Tabelle."* Die Spalte war richtig, solange
 * es **einen** Kanal gab; mit dem Webhook aus `docs/129 §7` gibt es zwei, und
 * die Frage „ist dieser Befund gemeldet" hat ab da zwei Antworten.
 *
 * **Warum eine Spalte das nicht mehr trägt, ist keine Geschmacksfrage.** Mit
 * einem gemeinsamen `notified_at` gibt es genau zwei Regeln, und beide sind
 * falsch:
 *
 * - *Gesetzt, wenn **einer** zustellte* — dann ist die Meldung des anderen
 *   dauerhaft fort. Die Zeile wird nie wieder fällig, und der Kanal, der gerade
 *   nicht durchkam, holt sie nie nach.
 * - *Gesetzt, wenn **alle** zustellten* — dann hält ein kaputter Mailweg den
 *   Webhook fest, und der meldet denselben Befund jede Nacht neu. Das ist genau
 *   das, wogegen die Entprellung geschrieben ist.
 *
 * > **Ein Kanal, der für einen anderen mitbucht, verliert dessen Meldung — und
 * > zwar dauerhaft.**
 *
 * **Die Entprellung bleibt, wo sie war, und braucht weiterhin keine eigene
 * Mechanik.** Gemeldet wird, was `now − first_seen_at ≥ Haltezeit` **und** für
 * diesen Kanal ohne Zeile ist. Den ersten Teil hält {@see FindingLog} seit A10;
 * den zweiten diese Tabelle.
 *
 * **`cascadeOnDelete` und nicht ein Aufräumer daneben.** Dass ein behobener
 * Befund **wieder** melden darf, hält {@see FindingLog::forgetMissing()}: Was
 * ein Lauf nicht mehr nennt, wird gelöscht. Das nahm bisher die Spalte mit; ab
 * jetzt nimmt es diese Zeilen mit, und zwar in der Datenbank und nicht in
 * einer zweiten Schreibstelle, die jemand vergessen kann.
 *
 * **Und `channel` ist eine Zeichenkette und kein Fremdschlüssel.** Ein Kanal
 * ist Code und keine Zeile: {@see Channel}. Eine Tabelle
 * der Kanäle wäre eine zweite Fassung dessen, was
 * `ChannelReachTest` an den Umsetzungen misst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finding_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->dateTime('notified_at');
            $table->timestamps();

            /*
             * **Die Datenbank hält es und nicht der Code, der schreibt** —
             * derselbe Grund wie beim `unique` an `findings`: Sonst hinge die
             * Zusage „höchstens eine Meldung je Kanal" daran, dass niemand
             * eine zweite Schreibstelle baut.
             */
            $table->unique(['finding_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_notifications');
    }
};
