<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Wunsch, einen Vorgang abzubrechen — und wer ihn geäußert hat.
 *
 * **Warum eine eigene Spalte und nicht einfach der Zustand „abgebrochen".**
 * Wer den Zustand sofort setzte, schriebe eine Behauptung in die Datenbank:
 * Der Arbeiter läuft in einem anderen Prozess, und das Programm auf dem Server
 * läuft weiter, bis jemand es beendet. „Abgebrochen" darf erst dastehen, wenn
 * es zutrifft.
 *
 * Zwischen Wunsch und Vollzug liegen bei einem laufenden Vorgang bis zu ein,
 * zwei Sekunden: Der Arbeiter fragt beim Warten auf die nächste Antwort des
 * Agenten nach, schließt dann die Verbindung, der Agent bemerkt das und
 * beendet das Programm. Diese Spalte ist die Nachricht über diese Strecke.
 *
 * `cancelled_by` bleibt beim Löschen des Kontos stehen. Wer einen Vorgang
 * abgebrochen hat, ist eine Auskunft, die ihren Wert verliert, wenn sie mit
 * dem Konto verschwindet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('finished_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancel_requested_at')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancel_requested_at');
        });
    }
};
