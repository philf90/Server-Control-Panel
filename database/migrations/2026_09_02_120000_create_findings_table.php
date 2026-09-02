<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToSubscription;
use App\Models\Finding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was die Diagnose des Bestands gefunden hat (A10, `docs/98 §2`).
 *
 * **Der gegenwärtige Zustand und keine Geschichte.** Eine Zeile je Befund, und
 * ein Befund, den der nächste Lauf nicht mehr findet, wird gelöscht. Ein
 * Verlauf beantwortete „wie oft war das schon so"; danach hat niemand gefragt,
 * und die Falle, die `docs/98 §4` benennt, wäre damit grösser statt kleiner —
 * ein Lauf, der jede Nacht Zeilen anhäuft, wird nach zwei Wochen nicht mehr
 * gelesen. Dieselbe Überlegung wie bei `domain_dns_checks`.
 *
 * **Keine `subscription_id` und kein {@see BelongsToSubscription}.**
 * Das ist die Ausnahme und keine Nachlässigkeit: Ein Befund gehört dem Server
 * und nicht einem Kunden, und die Seite gehört dem Administrator und dem
 * Betreiber (`docs/98 §9` Frage 5). Ein Kunde sieht sie gar nicht — die Grenze
 * sitzt an der Route und nicht an der Abfrage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table): void {
            $table->id();

            /*
             * Die drei Spalten der Kennung.
             *
             * **Warum genau diese drei** (`docs/98 §2`): Der Wortlaut des
             * Werkzeugs taugt nicht dafür. Jede `[emerg]`-Zeile von nginx trägt
             * Datum und Prozessnummer, jede Zeile von php-fpm ein Datum
             * (`docs/81 §2.3o` M9) — zwei Läufe an derselben kaputten Datei
             * ergäben zwei Zeilen, und „steht seit" wäre wertlos.
             */
            $table->string('check', 32);
            $table->string('subject', 255);
            $table->string('reason', 64);

            /*
             * **`varchar(255)` ist hier belegt und nicht geraten.** `docs/45`
             * hat teuer gelernt, was eine zu kurze Spalte anrichtet: Die
             * Begründung eines abgewiesenen Dumps passte nicht, die
             * `PDOException` riss den `catch`-Zweig mit, und der Vorgang meldete
             * „vermutlich Zeitüberschreitung". Der Unterschied: Dort stand der
             * Wortlaut eines fremden Werkzeugs in der Spalte, hier steht
             * **unsere** Form — ein Domainname (höchstens 253), ein Unitname,
             * ein Pfad, den das Panel selbst gelegt hat.
             *
             * Der Wortlaut des Werkzeugs steht in `detail`, und der ist `text`.
             */

            /*
             * Der ungekürzte Wortlaut des Werkzeugs — für den, der nachsieht.
             * Er trägt den Ort in der Form des Werkzeugs, und die ist je
             * Programm und je Fehlerart verschieden (`docs/81 §2.3o` M8): nginx
             * schreibt `… in datei:zeile`, php-fpm einmal `[datei:zeile]` und
             * einmal `[pool name]`, sshd `datei: line n:` und `datei line n:`.
             *
             * Gekürzt wird beim Schreiben ({@see Finding::DETAIL_MAX}) und
             * nicht von der Spalte: Eine Spalte, die kürzt, wirft und reisst
             * den Fehlerweg mit.
             */
            $table->text('detail')->nullable();

            /*
             * **Zwei Zeitstempel und keine `timestamps()`-Semantik.**
             *
             * `first_seen_at` ist das „steht seit" und überlebt jeden weiteren
             * Lauf, der denselben Befund wiederfindet — genau dafür gibt es die
             * Kennung. `measured_at` sagt, wann er zuletzt bestätigt wurde.
             *
             * Nicht `updated_at`: Der Zeitpunkt gehört zur Messung und nicht
             * zur Zeile. Ein späterer Umbau, der die Zeile aus einem anderen
             * Grund anfasst, verschöbe sonst die Auskunft — und niemand merkte
             * es. Derselbe Grund wie bei `domain_dns_checks.checked_at`.
             */
            $table->timestamp('first_seen_at');
            $table->timestamp('measured_at');

            $table->timestamps();

            /*
             * **Die Kennung als Zusage der Datenbank und nicht als Absicht des
             * Codes.** Ohne diesen Index wäre „ein Befund, zwei Nächte, eine
             * Zeile" eine Eigenschaft der Schreibstelle — und Punkt 8 des
             * Abnahmekriteriums hinge daran, dass niemand daneben eine zweite
             * Schreibstelle baut.
             */
            $table->unique(['check', 'subject', 'reason']);

            // Die Seite sortiert nach Dringlichkeit und liest je Prüfung.
            $table->index('check');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
