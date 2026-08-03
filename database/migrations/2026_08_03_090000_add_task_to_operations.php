<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welche Aufgabe aus dem Katalog einen Vorgang ausgelöst hat.
 *
 * **Warum das nicht aus `type` hervorgeht.** `type` ist die Operation des
 * Agenten. Drei Aufgaben des Katalogs benutzen dieselbe — `service.status` —
 * und unterscheiden sich nur in den Argumenten. Eine Liste, die dreimal
 * „service.status" zeigt, verlangt vom Leser, die Argumente aufzuklappen, um
 * zu erkennen, worum es ging.
 *
 * **Warum nicht in `payload`.** Die Nutzlast geht unverändert an den Agenten.
 * Ein Schlüssel darin, den nur das Panel versteht, wäre ein Wert, den ein
 * Programm als root übergeben bekommt, ohne dass ihn jemand dafür vorgesehen
 * hat.
 *
 * Nullable, weil ein Vorgang nicht aus dem Katalog stammen muss: Was das Panel
 * später selbst auslöst — ein Update, ein Zertifikatswechsel zur Laufzeit —
 * hat keinen Knopf, auf den jemand gedrückt hat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->string('task', 64)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn('task');
        });
    }
};
