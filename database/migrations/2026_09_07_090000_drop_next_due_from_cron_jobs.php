<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `next_due` fällt weg — der Wert wird beim Lesen gerechnet.
 *
 * ## Warum
 *
 * Die Spalte wurde beim Anlegen und beim Ändern eines Jobs geschrieben und
 * sonst nie. Als die Rechnung dahinter am 7. September 2026 berichtigt wurde
 * (`docs/107 §0c`), blieben die alten Werte stehen: Auf `cloudsrv24` sagte der
 * Satz über der Liste `Europe/Berlin` und die Zeile darunter `05:15` für einen
 * Job, der um 03:15 läuft.
 *
 * > **Ein Wert, der einmal gerechnet und dann abgelegt wird, wird von einer
 * > Behebung an der Rechnung nicht mitgenommen.**
 *
 * Und das war kein einmaliger Rest: Der Wert folgt aus „jetzt", und niemand hat
 * ihn nachgezogen — auch nicht, nachdem ein Job gelaufen war.
 *
 * > **Ein Wert, der aus „jetzt" folgt und abgelegt wird, ist ab dem nächsten
 * > Augenblick falsch — die Frage ist nur, wie schnell es auffällt.**
 *
 * Ihre eigene Migration nannte sie „eine Bequemlichkeit für die Liste".
 * Gemessen kostet die Rechnung **0,03 bis 0,14 ms** je Job — 2,6 ms im
 * Sonderfall eines Zeitplans, den es nie gibt — bei höchstens zehn Jobs je
 * Abonnement. Die Bequemlichkeit war den Preis nicht wert.
 *
 * ## Der Rückweg legt sie leer wieder an
 *
 * Ein `down()`, das Werte erfände, stellte den Fehler wieder her. Wer zurück
 * muss, bekommt die Spalte und keine Zahlen darin — die alte Fassung füllt sie
 * beim nächsten Ändern eines Jobs selbst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cron_jobs', function (Blueprint $table): void {
            $table->dropColumn('next_due');
        });
    }

    public function down(): void
    {
        Schema::table('cron_jobs', function (Blueprint $table): void {
            $table->timestamp('next_due')->nullable();
        });
    }
};
