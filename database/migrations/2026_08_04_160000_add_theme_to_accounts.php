<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Themewahl gehört an das Konto und nicht in den Browser.
 *
 * **Warum es diese Spalte gibt.** Beide Themes stehen seit P1 fertig da —
 * `docs/20 §7.2` verlangt sie ausdrücklich zusammen und die Kontrastprüfungen
 * laufen über beide. Nur schalten konnte sie niemand: Gesetzt wurde
 * `data-theme` an genau einer Stelle, aus `SRVPANEL_THEME` in der `.env`, also
 * serverweit für alle und nur von jemandem mit Zugriff auf die Datei. Dasselbe
 * Muster wie beim Zurückziehen eines Kunden und bei `CustomerStatus::Suspended`
 * — die Mechanik war vollständig gebaut, es fehlte der Weg dorthin.
 *
 * **Am Konto und nicht im Browser.** Ein Betreiber arbeitet an zwei Rechnern;
 * eine Einstellung, die er zweimal treffen muss, ist keine. Der `localStorage`
 * wäre die bequemere Stelle und die falsche.
 *
 * **`null` heisst „dem Betriebssystem folgen".** Das ist der Vorgabewert und
 * die einzige Bedeutung von leer — keine vierte Stufe „noch nichts gewählt".
 * Wer nichts einstellt, bekommt, was sein Rechner ohnehin sagt, und muss dafür
 * nichts tun. `SRVPANEL_THEME` behält seine Aufgabe für die Seiten **ohne**
 * Konto: Anmeldung und zweiter Faktor haben niemanden, den sie fragen könnten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // Kurz gehalten: Es gibt genau zwei zulässige Werte, und die
            // Prüfung steht in der Validierung. Ein `enum` in der Datenbank
            // wäre eine dritte Stelle, an der dieselbe Liste steht.
            $table->string('theme', 10)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }
};
