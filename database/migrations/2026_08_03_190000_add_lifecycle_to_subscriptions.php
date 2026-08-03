<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnements werden zurückgezogen, nicht gelöscht — aus demselben Grund wie
 * Kunden, aber mit einer schärferen Folge.
 *
 * Am Abonnement hängt der Systembenutzer, und der hat eine UID. `userdel` gibt
 * sie frei; das nächste `useradd` vergibt sie wieder. Läge die Zeile nicht
 * mehr in der Datenbank, könnte das Panel denselben Namen `p1000` ein zweites
 * Mal vergeben — und dann erbt ein neuer Kunde alles, was auf dem Dateisystem
 * noch der alten UID gehört: Dateien in einem Verzeichnis, das der Rückbau
 * nicht erwischt hat, Einträge in `at`- oder `cron`-Warteschlangen, offene
 * Sockets. Genau diese Verwechslung sucht `subscription.remove` am Ende mit
 * seiner Suche nach verwaisten UIDs.
 *
 * Mit `deleted_at` bleibt die Zeile stehen, der eindeutige Index auf
 * `system_user` gilt weiter für sie, und die Vergabe sieht sie. Ein Name wird
 * damit genau einmal vergeben, solange dieses Panel läuft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
