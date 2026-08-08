<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Der gemessene Platz einer Datenbank wird in Bytes abgelegt (docs/36 §22.3j).
 *
 * **Der Anlass ist ein Widerspruch im eigenen Werk.** `DbUsageScopeTest`
 * begründet ausdrücklich, warum der Agent Bytes liefert: *„Wer hier durch 1024²
 * teilte, verlöre für jede Datenbank unter einem Megabyte die Unterscheidung
 * zwischen ‚leer' und ‚klein' — und das ist genau die Unterscheidung, nach der
 * jemand sucht, der eine Sicherung vermisst."* Genau diese Division stand eine
 * Zeile später in `Usage::apply()`, und die Oberfläche zeigte für jede
 * Datenbank unter einem Megabyte „0 MB" — dasselbe wie für eine leere.
 *
 * Aufgefallen ist es am dritten Abnahmelauf vom 8. August 2026: Die Messung
 * meldete zwei Schemata, beide zugeordnet — und die Selbsttest-Tabelle mit
 * ihrer einen Zeile belegt rund 16 KB, also `0 MB`. Ein vertauschtes
 * Spaltenpaar, ein `NULL` statt der Summe, ein Faktor daneben: alles hätte
 * dieselbe Null ergeben. **Die Zuordnung war belegt und die Zahl nicht.**
 *
 * **Warum eine eigene Migration und nicht die Spalte in der ersten.** Die
 * Tabelle steht seit `v0.5.0-rc.1` auf einem laufenden Server. Eine bereits
 * ausgelieferte Migration nachträglich zu ändern hiesse, dass zwei
 * Installationen mit gleichem Stand verschiedene Schemata haben — und die
 * Abweichung fiele erst dort auf, wo jemand den Unterschied nicht mehr erklären
 * kann.
 *
 * **Die vorhandenen Werte werden hochgerechnet und nicht verworfen.** Sie sind
 * gerundete Megabyte; mal 1024² ergibt eine Byte-Zahl, die zu grob ist, aber
 * nicht falsch. Genauer wird sie beim nächsten Lauf des Zeitgebers von selbst —
 * spätestens in einer Viertelstunde. Auf `null` zu setzen wäre die schlechtere
 * Wahl: Das hiesse „noch nie gemessen" und wäre eine Unwahrheit über einen
 * Zustand, den es gab.
 */
return new class extends Migration
{
    private const FACTOR = 1024 * 1024;

    public function up(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->renameColumn('size_mb', 'size_bytes');
        });

        DB::table('databases')
            ->whereNotNull('size_bytes')
            ->update(['size_bytes' => DB::raw('size_bytes * '.self::FACTOR)]);
    }

    public function down(): void
    {
        DB::table('databases')
            ->whereNotNull('size_bytes')
            ->update(['size_bytes' => DB::raw('size_bytes / '.self::FACTOR)]);

        Schema::table('databases', function (Blueprint $table) {
            $table->renameColumn('size_bytes', 'size_mb');
        });
    }
};
