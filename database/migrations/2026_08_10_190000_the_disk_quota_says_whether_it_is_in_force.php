<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ob die Speichergrenze eines Abonnements wirklich gilt.
 *
 * **Der Anlass ist ein Vorgang, der „fertig, 100 %" meldete.** Am 10. August
 * 2026 hat der Betreiber auf `cloudsrv24` zwei Abonnements angelegt; beide
 * Vorgänge waren grün, und in ihrer Ausgabe stand:
 *
 *     setquota: Cannot find mountpoint for device
 *     setquota: No correct mountpoint specified.
 *
 * Gemessen war die Ursache dann eindeutig: `/var/www/vhosts` liegt auf `/`
 * (`/dev/vda3`, ext4) und die Mount-Optionen sind `rw,relatime` — **ohne
 * `usrquota`**. Die Quota ist auf diesem Server nicht eingeschaltet, und das
 * darf der Betreiber so wollen.
 *
 * **Der Agent hat das auch gesagt.** `DiskQuota::apply()` gibt seit jeher
 * `['enforced' => false, 'reason' => …]` zurück und bricht ausdrücklich nicht
 * ab — ein Abonnement soll nicht scheitern, weil ein Dateisystem keine Quota
 * kann. Nur hat diese Antwort in `app/` **niemand gelesen**. Im Panel stand
 * „15360 MB" und meinte es nicht.
 *
 * > **Ein Feld, das niemand liest, ist keine Auskunft, sondern Rechenzeit.**
 *
 * ## Drei Werte und nicht zwei
 *
 * `null` heisst „nicht nachgesehen" und ist weder ja noch nein — dieselbe
 * Entscheidung wie bei `handed_over` (`docs/39`) und beim Kernel. Ein
 * Abonnement aus der Zeit vor dieser Spalte hat keine Auskunft, und die
 * Oberfläche soll dann schweigen statt Entwarnung zu geben.
 *
 * ## Warum je Abonnement und nicht einmal für den Server
 *
 * Weil der Agent es je Abonnement beantwortet, und weil beides auseinanderlaufen
 * kann: Wer die Quota heute einschaltet, hat morgen Abonnements mit Grenze und
 * solche ohne — bis jemand `subscription.quota` für die alten laufen lässt. Ein
 * serverweiter Schalter behauptete eine Einheitlichkeit, die es nicht gibt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->boolean('disk_quota_enforced')->nullable()->after('disk_usage_measured_at');

            // Der Grund kommt vom System und nicht von uns — wörtlich, wie in
            // jeder Fehlermeldung dieses Projekts (Plan §2, Leitbild 2). Ein
            // „konnte nicht gesetzt werden" hilft niemandem beim Beheben.
            $table->string('disk_quota_note')->nullable()->after('disk_quota_enforced');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['disk_quota_enforced', 'disk_quota_note']);
        });
    }
};
