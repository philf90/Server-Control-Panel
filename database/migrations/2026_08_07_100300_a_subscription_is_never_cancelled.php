<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cancelled_at` fällt weg — es gibt keinen gekündigten Zustand mehr (docs/35 §4, Schritt 9).
 *
 * **Der Zustand war schon vorher tot, nur nicht sichtbar.** `Lifecycle::withdraw()`
 * setzte `status = cancelled` und `cancelled_at`, und zwar auf einer Zeile, die
 * im selben Atemzug unsichtbar wurde: Gelesen hat beides nie wieder jemand. Seit
 * dem Verzeichnis der Systembenutzer gibt es die Zeile gar nicht mehr, und damit
 * kann kein Abonnement diesen Zustand überhaupt noch tragen.
 *
 * **In einem eigenen Schritt und nicht im Umbau selbst.** Eine Aufzählung zu
 * verkleinern, während eine Datenmigration läuft, mischt zwei Risiken, die man
 * einzeln beurteilen können will — der Purge ist unumkehrbar, dies hier nicht.
 *
 * Die Spalte wird geleert und nicht gelesen: Ob irgendwo noch ein `cancelled`
 * steht, spielt keine Rolle, weil die Zeilen, die es tragen konnten, in der
 * Migration davor gelöscht wurden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
        });
    }
};
