<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der gemessene Speicherverbrauch eines Abonnements.
 *
 * **Zwei Spalten und nicht eine.** Der Wert allein wäre eine Zahl ohne
 * Haltbarkeit: Steht das Messen seit drei Tagen (Timer aus, Agent weg, Quota
 * auf dem Mount abgeschaltet), zeigte die Oberfläche weiter „412 MB" und sähe
 * dabei genauso aus wie eine Messung von vor einer Minute. Mit
 * `disk_usage_measured_at` kann sie sagen, wovon sie redet — und „seit dem
 * 1. August nicht gemessen" ist eine Auskunft, „412 MB" wäre eine Behauptung.
 *
 * **`null` heisst nicht gemessen und nicht null Byte.** Ein frisch angelegtes
 * Abonnement hat noch keinen Messwert; eine Installation ohne Dateisystemquota
 * bekommt nie einen. Beides ist etwas anderes als ein leeres Verzeichnis.
 *
 * Der Wert steht in MB, wie das Kontingent `disk_mb` — damit Verbrauch und
 * Grenze ohne Umrechnung nebeneinander stehen. `unsignedBigInteger`, weil ein
 * Abonnement mit 100 TB Kontingent (der Höchstwert aus dem Katalog) einen
 * Verbrauch hat, der nicht mehr in vier Byte passt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('disk_used_mb')->nullable()->after('quota_overrides');
            $table->timestamp('disk_usage_measured_at')->nullable()->after('disk_used_mb');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['disk_used_mb', 'disk_usage_measured_at']);
        });
    }
};
