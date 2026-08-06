<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wo das Zertifikat liegt — als Angabe und nicht als Konvention.
 *
 * **Warum die Spalte gebraucht wird.** Bis hierher hat der Agent selbst
 * abgeleitet, welches Zertifikat ein Server-Block ausliefert: Er sah unter dem
 * Namen der Domain nach und nahm, was dort lag. Ab dem zweiten Wurf von P4
 * sagt das Panel es ihm (`docs/34 §2.1`) — und dazu muss es den Schlüssel
 * kennen, unter dem der Agent abgelegt hat.
 *
 * **Sie wird nicht gerechnet, sondern berichtet.** Der Agent gibt sie
 * seit derselben Änderung in der Antwort von `acme.certificate.issue` zurück.
 * Die Regel „Verzeichnis = erster Name" bliebe sonst an zwei Stellen stehen —
 * und sie ändert sich schon im nächsten Schritt, weil ein Platzhalter
 * (`*.example.de`) kein Verzeichnisname sein kann.
 *
 * **Nachgetragen wird nach genau der Regel, die bis heute galt:** der erste
 * Name, kleingeschrieben. Für jede Zeile, die vor dieser Änderung entstanden
 * ist, ist das der Ort, an dem der Agent tatsächlich geschrieben hat. Ohne
 * diesen Nachtrag verlöre jede bestehende Domain beim nächsten Anwenden ihr
 * HTTPS — kein Fehler, keine Meldung, nur eine Website auf Port 80.
 *
 * `nullable`, weil ab Schritt 3 auch hochgeladene Zertifikate hier stehen und
 * eine Zeile denkbar ist, die noch keinen Ablageort hat. Ein Zertifikat ohne
 * Ablageort wird nicht ausgeliefert; das ist der sichere Ausgang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('storage_name')->nullable()->after('names');
        });

        foreach (DB::table('certificates')->select('id', 'names')->get() as $row) {
            $raw = $row->names;
            $names = is_string($raw) ? json_decode($raw, true) : null;

            if (! is_array($names) || ! isset($names[0]) || ! is_string($names[0])) {
                continue;
            }

            $name = strtolower(trim($names[0]));

            if ($name === '') {
                continue;
            }

            DB::table('certificates')->where('id', $row->id)->update(['storage_name' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('storage_name');
        });
    }
};
