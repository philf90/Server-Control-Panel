<?php

declare(strict_types=1);

use App\Enums\AdminRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Die Rolle innerhalb der Admin-Ebene — Betreiber oder Administrator.
 *
 * **Eine zweite Achse und kein vierter `AccountType`.** Die Begründung steht
 * an {@see AdminRole}; die Kurzform: `AccountType` beantwortet „wen sieht
 * dieses Konto", und darauf antworten beide Rollen gleich. Ein vierter Fall
 * machte `isAdmin()` an 52 Stellen zweideutig.
 *
 * ## `null` heisst „kein Admin" und nicht „noch nichts gewählt"
 *
 * Die Spalte trägt nur für Adminkonten eine Bedeutung. Für Kunden und
 * Zusatzbenutzer bleibt sie leer — ein Vorgabewert wie `administrator` für
 * jedes Kundenkonto wäre eine Angabe, die etwas behauptet, das niemand
 * entschieden hat.
 *
 * **Und die Rolle allein gewährt nichts.** `Account::isOperator()` verlangt
 * beides: die Ebene **und** die Rolle. Ein Kundenkonto, das durch einen Fehler
 * `operator` trüge, ist damit trotzdem keiner.
 *
 * ## Bestehende Adminkonten werden Betreiber
 *
 * Jedes vorhandene Adminkonto darf heute alles. Es als Administrator zu
 * migrieren wäre eine **stille Rechteentziehung auf einem laufenden Server**:
 * Der Betreiber käme am Montag nicht mehr an seine Mailkonfiguration, und die
 * Meldung dazu sagte nichts über eine Migration.
 *
 * > **Eine Migration, die Rechte wegnimmt, sperrt jemanden aus, der gestern
 * > noch hineinkam.**
 *
 * Wer danach Administratoren will, legt sie an oder stuft sie herab — und das
 * ist eine Handlung mit einem Protokolleintrag statt eines Nebeneffekts.
 *
 * ## Warum kein `enum` in der Datenbank
 *
 * Dieselbe Begründung wie bei `theme` und `status`: Die zulässigen Werte stehen
 * in {@see AdminRole}, und eine Aufzählung im Schema wäre eine zweite Liste,
 * die bei der dritten Rolle nachgezogen werden müsste — von jemandem, der an
 * die Migration denkt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('role', 32)->nullable()->after('type');
        });

        /*
         * **Der Wert wird gesetzt und nicht als Vorgabe gestellt.** Eine
         * `default('operator')` an der Spalte träfe auch jedes Kundenkonto,
         * das danach entsteht — und `null` soll dort etwas bedeuten.
         *
         * Über den Query Builder und nicht über das Model: Eine Migration, die
         * ein Model lädt, hängt an dessen heutiger Gestalt. `Account` trägt die
         * Mandantenklammer, und die verweigert im Grundzustand alles.
         */
        DB::table('accounts')
            ->where('type', 'admin')
            ->update(['role' => AdminRole::Operator->value]);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
