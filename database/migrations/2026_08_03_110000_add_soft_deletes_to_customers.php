<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunden werden nicht mehr gelöscht, sondern zurückgezogen.
 *
 * **Warum das eine Migration wert ist.** Die Kundennummer ist der Bezeichner,
 * unter dem ein Kunde in Rechnungen, Verzeichnisnamen und Systembenutzern
 * auftaucht. Solange ein `DELETE` die Zeile entfernt, wird die Nummer wieder
 * frei — und der nächste Kunde bekommt sie. Danach tragen zwei verschiedene
 * Vertragspartner in zwei Rechnungen dieselbe Nummer, und beim Nachsehen
 * findet man einen davon.
 *
 * Mit `deleted_at` bleibt die Zeile stehen. Der eindeutige Index über `number`
 * gilt weiter für sie, die Nummer ist damit dauerhaft verbraucht — und die
 * Vergabe muss ausdrücklich `withTrashed()` fragen, um sie zu sehen.
 *
 * **Als eigene Migration und nicht in `create_core_tables`.** Es gibt bereits
 * eine ausgelieferte Installation mit Daten (0.2.0-rc.6). Eine geänderte
 * Migration, die dort schon gelaufen ist, läuft nie wieder — die Spalte fehlte
 * genau auf dem Server, auf dem sie gebraucht wird.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
