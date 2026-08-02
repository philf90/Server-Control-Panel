<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der zuletzt verbrauchte Zeitschritt des zweiten Faktors.
 *
 * Ohne diese Spalte lässt sich derselbe Code innerhalb seines Fensters ein
 * zweites Mal verwenden — und das Fenster ist neunzig Sekunden breit, weil es
 * ungenaue Uhren abfangen muss. Wer einen Code über die Schulter mitliest,
 * hätte damit anderthalb Minuten Zeit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_step');
        });
    }
};
