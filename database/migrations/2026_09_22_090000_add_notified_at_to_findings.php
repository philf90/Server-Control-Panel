<?php

declare(strict_types=1);

use App\Support\Diagnose\FindingLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Erinnerung an die Zustellung (B5, `docs/129 §4` Punkt 1).
 *
 * **Eine Spalte und keine eigene Tabelle**, und das ist die erste der beiden
 * Formen, die der Plan anbietet. Die zweite — eine kleine Tabelle je Kanal —
 * braucht es erst, wenn **mehrere** Kanäle je Befund getrennt buchen sollen;
 * heute gibt es einen (Mail), und der zweite steht als Webhook in `docs/129 §7`
 * unter B1.
 *
 * > **Eine Ablage, die eine Unterscheidung vorsieht, die es nicht gibt, ist
 * > keine Vorsorge — sie ist eine Spalte, die bei jedem Lesen erklärt werden
 * > muss.**
 *
 * **`null` heisst „noch nicht gemeldet" und nicht „nicht zustellbar".** Der
 * Unterschied steht daneben: Ob der Kanal überhaupt durchkommt, ist eine Frage
 * je Kanal und keine je Befund — sie wird als „zuletzt erfolgreich zugestellt"
 * in den Einstellungen beantwortet (`docs/80`).
 *
 * **Die Entprellung fällt damit ab und braucht keine eigene Mechanik.** Gemeldet
 * wird, was `now − first_seen_at ≥ Haltezeit` **und** `notified_at is null` ist.
 * Und dass ein behobener Befund wieder melden darf, hält bereits
 * {@see FindingLog::forgetMissing()}: Was ein Lauf nicht
 * mehr nennt, wird gelöscht — mitsamt dieser Spalte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            $table->dateTime('notified_at')->nullable()->after('measured_at');
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
