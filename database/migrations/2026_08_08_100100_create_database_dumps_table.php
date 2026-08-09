<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Ablage einer Sicherung (P5, docs/36 §10).
 *
 * **Eine Zeile je Datei, damit es einen Weg zurück gibt.** Ein Dump ist die
 * dritte Sache, die P5 auf dem System hinterlässt — und die einzige, die
 * beliebig gross wird. Ohne diese Tabelle wüsste niemand, welche Dateien unter
 * `/var/lib/srvpanel/dumps` zu welchem Abonnement gehören, und `srvpanel db
 * prune` hätte nichts, wogegen es abgleichen könnte.
 *
 * **`storage_name` und kein Pfad.** Derselbe Zuschnitt wie
 * `certificates.storage_name`: Die Anwendung nennt einen Namen, der Agent baut
 * daraus den Ablageort. Ein Prozess mit Systemrechten nimmt keinen Pfad
 * entgegen.
 *
 * **Der Dump überlebt seine Datenbank.** `database_id` steht auf
 * `nullOnDelete`, und der Name ist abgeschrieben — er ist ja gerade das, was
 * man nach einem Versehen noch hat. Eine Sicherung, die mit der Datenbank
 * verschwindet, ist keine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_dumps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('subscription_name')->nullable();

            $table->foreignId('database_id')->nullable()
                ->constrained('databases')->nullOnDelete();
            $table->string('database_name', 64);

            $table->string('storage_name', 96)->unique();

            // Woher die Sicherung stammt — die Werte stehen in
            // App\Enums\DumpKind. Der Unterschied entscheidet beim
            // Zurückspielen: Eine hochgeladene Datei hat niemand geprüft, und
            // das Zurückspielen leert die Datenbank vorher.
            //
            // **Hier stand, 'export' sei in P5 der einzige Wert und die Spalte
            // nehme später 'import' auf.** Beides ist seit Schritt 11 falsch:
            // Es gibt zwei Werte, und der zweite heisst 'imported'. Ein
            // Kommentar, der eine Behauptung über die Zukunft macht, wird von
            // dieser Zukunft nicht benachrichtigt — gefunden im Abnahmelauf
            // (docs/36 §22.3w).
            $table->string('kind', 16);

            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();

            $table->index(['subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_dumps');
    }
};
