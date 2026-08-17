<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Cronjobs eines Abonnements (P6 Schritt 9, `docs/51 §6`).
 *
 * **Die fünf Felder stehen einzeln und nicht als eine Zeichenkette.** Das ist
 * die Vorgabe aus `docs/51 §10.3`, und sie hat einen Grund, der über das
 * Formular hinausgeht: Ein einzelnes Feld `"* * * * *"` müsste jede Stelle, die
 * es liest, wieder zerlegen — und die zweite Zerlegung ist die, die von der
 * ersten abweicht. So ist die Aufteilung eine Eigenschaft des Schemas.
 *
 * **`command` ist `text`.** Ein Befehl darf nach `CronApply::COMMAND_MAX` 8192
 * Zeichen lang werden; die Grenze steht im Code und nicht an der Spaltenbreite.
 * `docs/48` hat gemessen, was eine zu kurze Spalte kostet — die `PDOException`
 * riss den `catch`-Zweig mit, der den Fehlschlag festhalten sollte —, und diese
 * Tests laufen gegen SQLite, der Server gegen MariaDB.
 *
 * **`next_due` ist ein gerechneter Wert und keine Quelle.** Was wirklich läuft,
 * entscheidet cron aus der Datei; diese Spalte ist eine Bequemlichkeit für die
 * Liste. Sie steht in UTC wie jeder Zeitstempel dieses Panels — gerechnet wird
 * sie aber aus der **Zeit der Maschine**, weil cron das tut (`docs/60 §11`).
 * Wer die beiden verwechselt, zeigt eine Zeile und findet sie nicht.
 *
 * **`cascadeOnDelete`**, wie bei `ssh_keys` und aus demselben Grund: Was auf der
 * Platte bleibt, liegt unter `/etc/cron.d` und `/etc/srvpanel/cron` und geht
 * mit dem Abonnement (`subscription.remove`). Eine Zeile mit Grabstein wäre ein
 * Rest, der auf nichts zeigt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            /*
             * **Pflicht, wie `label` bei `ssh_keys`.** Eine Liste aus fünf
             * Zahlenfeldern und einer Kommandozeile ist eine Liste, aus der
             * niemand einen Eintrag wieder herausnimmt, weil er nicht weiss,
             * wofür er da war.
             */
            $table->string('label');

            $table->text('command');

            /*
             * 192 Zeichen je Feld — dieselbe Grenze wie `Schedule::FIELD_MAX`
             * im Agenten. `0,1,2,…,59` sind 168 Zeichen, und mehr braucht kein
             * Feld. Die Prüfung selbst steht im Agenten, an der Stelle, die die
             * Zeile schreibt; hier steht nur, was hineinpasst.
             */
            $table->string('minute', 192);
            $table->string('hour', 192);
            $table->string('day_of_month', 192);
            $table->string('month', 192);
            $table->string('day_of_week', 192);

            /*
             * **Aktiv ist der Zustand des Jobs, nicht der des Abonnements.**
             * Ein gesperrtes Abonnement pausiert seine Jobs (Entscheidung 3 des
             * Betreibers, `docs/60 §12`) — aber es tut das, indem die Datei
             * verschwindet, und nicht, indem diese Spalte umgeschrieben wird.
             * Sonst wüsste beim Fortsetzen niemand mehr, welche Jobs der Kunde
             * selbst pausiert hatte.
             */
            $table->boolean('active')->default(true);

            $table->timestamp('next_due')->nullable();

            $table->timestamps();

            // Die Liste wird je Abonnement gelesen, sortiert nach Beschriftung.
            $table->index(['subscription_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_jobs');
    }
};
