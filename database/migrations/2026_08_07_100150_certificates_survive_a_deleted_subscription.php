<?php

declare(strict_types=1);

use App\Support\Subscriptions\Lifecycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Zertifikat überlebt das Abonnement, von dem es handelte.
 *
 * **Diese Migration ist nachgereicht, und der Anlass war ein Abbruch.** Der
 * Purge in `..._withdrawn_subscriptions_are_gone` hat auf dem Zielserver
 * angehalten: An zurückgebauten Abonnements hingen noch zwölf Zertifikate, und
 * `certificates.subscription_id` stand auf `cascadeOnDelete`. Der Purge hätte
 * die Zeilen mitgenommen — die Dateien unter `/etc/srvpanel/tls/certs` aber
 * nicht, denn die gehören dem Agenten. Zurückgeblieben wären zwölf
 * Verzeichnisse mit **privaten Schlüsseln**, auf die nichts mehr zeigt.
 *
 * **Der eigentliche Fehler ist älter und liegt woanders:** Dieses System konnte
 * ein Zertifikat nie löschen — weder im Panel noch im Agenten. Ein
 * zurückgebautes Abonnement liess seinen Ablageort liegen, und weil die Zeile
 * bis docs/35 als Grabstein stehenblieb, ist es niemandem aufgefallen. Behoben
 * wird das mit `acme.certificate.remove` und `srvpanel tls prune`; diese
 * Migration sorgt nur dafür, dass die Zeilen so lange **auffindbar** bleiben.
 *
 * **Dieselbe Form wie bei `operations`**, und aus demselben Grund. Danach gilt:
 *
 *   `subscription_id` null, `subscription_name` null  → Zertifikat der Oberfläche
 *   `subscription_id` null, `subscription_name` gesetzt → verwaist, Datei liegt noch
 *
 * Ohne die Abschrift wären die beiden Fälle nicht zu unterscheiden — und ein
 * verwaistes Zertifikat sähe aus wie das der Oberfläche, das niemals angefasst
 * werden darf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('subscription_name')->nullable()->after('subscription_id');
        });

        $this->carryTheNamesOver();

        $this->relaxTheForeignKey();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('certificates', function (Blueprint $table) {
                $table->dropForeign(['subscription_id']);
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            });
        }

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('subscription_name');
        });
    }

    /**
     * Die Namen abschreiben, solange die Abonnements noch da sind.
     *
     * In PHP und nicht als `UPDATE … JOIN` — der läuft auf MariaDB und nicht
     * auf SQLite, und die Tests laufen auf SQLite.
     */
    private function carryTheNamesOver(): void
    {
        DB::table('subscriptions')->orderBy('id')->chunkById(200, function ($subscriptions): void {
            foreach ($subscriptions as $subscription) {
                DB::table('certificates')
                    ->where('subscription_id', $subscription->id)
                    ->update(['subscription_name' => $subscription->name]);
            }
        });
    }

    /**
     * `cascadeOnDelete` wird zu `nullOnDelete` — wo der Treiber das kann.
     *
     * SQLite kann einen Fremdschlüssel überhaupt nicht ändern; es gibt dort
     * kein `ALTER TABLE … DROP FOREIGN KEY`. Deshalb hängt das Verhalten nicht
     * an dieser Zeile: Der Rückbau löst seine Zertifikate selbst ab
     * ({@see Lifecycle::withdraw()}), und der Purge
     * tut vorher dasselbe. Was hier umgestellt wird, ist die Sicherung darunter
     * — ein `DELETE` von Hand, das am Panel vorbeigeht.
     */
    private function relaxTheForeignKey(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
        });
    }
};
