<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Ablage einer Abonnement-Sicherung (P8, `docs/117 §6` Schritt 3).
 *
 * **Eine Zeile je Datei, damit es einen Weg zurück gibt** — dieselbe Begründung
 * wie bei `database_dumps` und aus demselben Grund: Ohne diese Tabelle wüsste
 * niemand, welche Dateien unter `/var/lib/srvpanel/backups` zu welchem
 * Abonnement gehören, und ein Aufräumlauf hätte nichts, wogegen er abgleichen
 * könnte.
 *
 * **`storage_name` und kein Pfad.** Derselbe Zuschnitt wie bei
 * `database_dumps.storage_name` und `certificates.storage_name`: Die Anwendung
 * nennt einen Namen, der Agent baut daraus den Ablageort. Ein Prozess mit
 * Systemrechten nimmt keinen Pfad entgegen.
 *
 * **Die Sicherung überlebt ihr Abonnement.** `subscription_id` steht auf
 * `nullOnDelete`, und der Name ist abgeschrieben — er ist ja gerade das, was man
 * nach einem Rückbau noch hat. Eine Sicherung, die mit ihrem Abonnement
 * verschwindet, ist keine.
 *
 * **Und die alte Systembenutzernummer steht mit dabei.** Nach Form A
 * (`docs/117 §3`) bekommt eine Wiederherstellung eine **neue**; die alte ist
 * die Auskunft, aus der die Seite hinterher sagen kann, was sich geändert hat.
 * Sie steht auch im Verzeichnis im Archiv — hier, damit die Liste sie zeigen
 * kann, ohne jede Sicherung zu öffnen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('subscription_name');

            $table->string('storage_name', 96)->unique();

            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('bytes')->nullable();

            // Was drin ist — die Zahlen aus der Antwort des Agenten. Sie sind
            // eine Auskunft und keine Zusage: Die Wahrheit steht im Archiv.
            $table->unsignedInteger('files')->nullable();
            $table->unsignedInteger('entries')->nullable();
            $table->unsignedInteger('databases')->nullable();

            // Der Zustand des Abonnements zur Zeit der Sicherung. Nach Form A
            // wechseln beide bei einer Wiederherstellung.
            $table->unsignedInteger('system_user')->nullable();
            $table->string('db_prefix', 32)->nullable();

            $table->string('last_error')->nullable();
            $table->timestamps();

            // Gefragt wird „welche Sicherungen hat dieses Abonnement, neueste
            // zuerst" — die Liste der Seite und der Aufbewahrungslauf stellen
            // beide dieselbe Frage.
            $table->index(['subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
