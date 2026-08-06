<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eine Wahl ist etwas anderes als eine Zuweisung.
 *
 * **Ohne diesen Unterschied nimmt die nächste Bestellung die Wahl still
 * zurück** (`docs/34 §8`). `domains.certificate_id` sagt seit dem zweiten Wurf
 * von P4, welches Zertifikat ein Server-Block ausliefert — geschrieben hat das
 * bisher nur der Lebenslauf. Sobald es zwei Zertifikate für dieselbe Domain
 * geben kann (ein bestelltes und ein hochgeladenes, oder einen Platzhalter),
 * ist dieselbe Spalte auch die Antwort eines Menschen auf die Frage „welches
 * denn?". Und die darf keine Automatik überschreiben, ohne es zu sagen.
 *
 * **Ein Zeitstempel und kein Ja/Nein.** Er beantwortet dieselbe Frage — `null`
 * heisst „niemand hat gewählt" — und zusätzlich die, die man danach stellt:
 * seit wann. Das steht sonst nur im Protokoll, und dort sucht es niemand.
 *
 * **Rückmigration ohne Datenverlust.** Fällt die Spalte weg, fällt nur die
 * Auskunft weg, wer gewählt hat; die Zuordnung selbst bleibt stehen. Verträglich
 * mit §8.1: Der Rückweg beim Update legt nur den Symlink um.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->timestamp('certificate_pinned_at')->nullable()->after('certificate_id');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('certificate_pinned_at');
        });
    }
};
