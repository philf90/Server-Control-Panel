<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zertifikate — ein eigener Gegenstand und keine Spalte an der Domain.
 *
 * **Das ist die Entscheidung, die nachträglich am teuersten wäre** (docs/32
 * §6). Ein Wildcard `*.example.de` gehört zu keiner einzelnen Domainzeile, und
 * ein Zertifikat trägt mehrere Namen. Modelliert man „eine Domain hat ein
 * Zertifikat", bricht der zweite Wurf mit DNS-01 das Datenmodell auf —
 * mitsamt Mandantenklammer, Policy und Migration.
 *
 * **Zwei getrennte Angaben, und das ist Absicht.** `certificates.names` sagt,
 * was das Zertifikat *behauptet*; `domains.certificate_id` sagt, was nginx für
 * diese Domain *ausliefert*. Beides in einer Spalte hiesse, aus dem
 * Ausliefern auf die Deckung zu schliessen — und genau dort entsteht die
 * Namenswarnung im Browser, die niemand bemerkt, weil die Seite ja lädt.
 *
 * **`subscription_id` darf null sein, und das ist kein Schlupfloch.** Das
 * Zertifikat der Oberfläche gehört keinem Kunden. Die Mandantenklammer liefert
 * für einen Kunden deshalb nur Zeilen mit *seiner* Nummer — ein `null` ist
 * kein Treffer in einem `where subscription_id in (…)`. Sichtbar wird es
 * ausschliesslich für den Betreiber, der ohnehin unbeschränkt liest.
 *
 * **`nullOnDelete` beim Fremdschlüssel an der Domain.** Verschwindet ein
 * Zertifikat, verliert die Domain ihren Verweis — sie darf davon aber nicht
 * mitgehen. Ein `cascadeOnDelete` hätte hier die Domain gelöscht, weil ihr
 * Zertifikat abgelaufen ist, und das ist ein Datenverlust aus einem
 * Wartungsvorgang heraus.
 *
 * **Rückmigration.** Erst die Spalte, dann die Tabelle — anders herum hinge
 * der Fremdschlüssel in der Luft. Verträglich mit §8.1 wie bei den Domains:
 * Der Rückweg beim Update legt nur den Symlink um.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();

            // Null = das Zertifikat der Oberfläche. Siehe oben.
            $table->foreignId('subscription_id')->nullable()->constrained()->cascadeOnDelete();

            // Die Namen, die das Zertifikat deckt — mit Platzhaltern.
            $table->json('names');

            $table->string('status', 20)->index();
            $table->string('source', 20);

            // Was im Zertifikat steht, sobald eines da ist. Vorher null: Eine
            // Bestellung, die noch läuft, hat keinen Aussteller.
            $table->string('issuer')->nullable();
            $table->string('serial', 64)->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable()->index();

            // Der letzte Fehlschlag im Wortlaut. Er steht hier und nicht nur
            // im Protokoll, weil die Seite ihn zeigen muss: „fehlgeschlagen"
            // ohne Grund ist eine Auskunft, mit der niemand weiterkommt.
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();

            // **Der Abstand nach einem Fehlschlag.** Täglich in eine
            // Ratenbegrenzung zu laufen verlängert die Sperre, statt sie
            // abzuwarten — Let's Encrypt lässt fünf Fehlversuche je Konto und
            // Stunde zu. Der Zeitplan aus Schritt 5 liest diese Spalte.
            $table->timestamp('renew_after')->nullable();

            $table->timestamps();
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('certificate_id')
                ->nullable()
                ->after('subscription_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certificate_id');
        });

        Schema::dropIfExists('certificates');
    }
};
