<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die öffentlichen Schlüssel für den SFTP-Zugang (P6 Schritt 8, `docs/51 §6`).
 *
 * **Der Fingerabdruck ist eindeutig je Abonnement** und nicht global: Zwei
 * Kunden dürfen denselben Schlüssel benutzen — das ist ihre Sache, und ein
 * global eindeutiger Index machte daraus einen Fehler mit einer Meldung, die
 * über einen fremden Bestand spräche.
 *
 * **`cascadeOnDelete` und nicht `nullOnDelete`.** Anders als bei `databases`
 * (docs/35 §3.3) liegt hier nichts ausserhalb dessen, was
 * `subscription.remove` anfasst: Die Schlüsseldatei gehört dem Panel, liegt
 * unter `/etc/srvpanel/ssh` und geht mit dem Abonnement. Eine Zeile mit
 * Grabstein wäre ein Rest, der auf nichts zeigt.
 *
 * **Keine Spalte für einen privaten Schlüssel**, und das ist keine
 * Selbstverständlichkeit, sondern dieselbe Vorsicht wie bei den
 * Datenbankpasswörtern: Was es nicht gibt, lässt sich nicht versehentlich
 * füllen. `public_key` nimmt die Zeile aus der `.pub`-Datei auf, und
 * `SrvPanel\Agent\Ssh\PublicKey` weist eine Eingabe ab, die mit `-----BEGIN`
 * anfängt — mit genau diesem Satz.
 *
 * **`label` ist Pflicht.** Eine Liste von Fingerabdrücken ohne Beschriftung ist
 * eine Liste, aus der niemand einen Schlüssel wieder entfernen kann, weil er
 * nicht weiss, welcher Rechner dahintersteckt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            // Der Typ, wie er in der Zeile steht — `ssh-ed25519`,
            // `ecdsa-sha2-nistp521`, `ssh-rsa`. Der längste davon hat 19
            // Zeichen; die Spalte ist weit genug für einen, den es noch nicht
            // gibt.
            $table->string('type', 64);

            // `SHA256:` plus 43 Zeichen Base64 ohne Auffüllung — gemessen an
            // dem, was `ssh-keygen -lf` ausgibt (docs/57 §12).
            $table->string('fingerprint', 64);

            $table->unsignedInteger('bits');

            /*
             * **`text` und nicht `string`.** Ein RSA-8192 wird rund 1,5 KB
             * lang, und eine zu kurze Spalte ist der Fehler aus docs/48: Die
             * PDOException reisst den Zweig mit, der den Fehlschlag festhalten
             * soll. Die Grenze steht im Code (`PublicKey::MAX_LENGTH`) und
             * nicht an der Spaltenbreite — diese Tests laufen gegen SQLite, der
             * Server gegen MariaDB, und dort nähme ein `varchar(255)` jede
             * Länge.
             */
            $table->text('public_key');

            // Wer ihn eingetragen hat. Ohne Fremdschlüssel und als Abschrift:
            // Ein gelöschtes Konto soll die Zeile nicht mitnehmen, und wer
            // fragt „wer war das", fragt nach einem Namen und nicht nach einer
            // Nummer.
            $table->string('created_by')->nullable();

            $table->timestamps();

            $table->unique(['subscription_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_keys');
    }
};
