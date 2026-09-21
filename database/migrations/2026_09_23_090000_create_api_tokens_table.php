<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Zugangsmarken für `api/v1` (B7, `docs/131 §3`).
 *
 * ## Gehasht mit `sha256` und nicht mit bcrypt
 *
 * Das ist gemessen und keine Vorliebe (`docs/130` A5): Eine Prüfung mit
 * `sha256` kostet **0,00011 ms**, eine mit bcrypt bei Kostenfaktor 12
 * **193 ms** — der Faktor liegt bei rund 1,7 Millionen. Ein Token sind
 * achtundvierzig Zeichen aus `random_bytes()` und kein Passwort eines
 * Menschen; der Arbeitsfaktor von bcrypt gleicht eine fehlende Entropie aus,
 * und hier fehlt keine.
 *
 * > **Ein Arbeitsfaktor, der eine fehlende Entropie ausgleicht, ist dort, wo
 * > sie nicht fehlt, nur noch Preis.**
 *
 * Und weil der Hash für einen gegebenen Klartext feststeht, findet ihn ein
 * **eindeutiger Index** — bei bcrypt müsste man über jede Zeile rechnen.
 *
 * ## `cascadeOnDelete` und nicht `nullOnDelete`
 *
 * Hier geht dieses Repo bewusst den anderen Weg als bei `audit_events`
 * (`docs/901`). Dort ist die Zeile ein **Protokoll**, und was jemand getan
 * hat, darf nicht verschwinden, nur weil sein Konto verschwindet. Eine
 * Zugangsmarke ist kein Protokoll, sondern ein Schlüssel — ohne sein Schloss
 * ist er nichts, und ein Schlüssel, der ein gelöschtes Konto überlebt, ist
 * genau das, was niemand will.
 *
 * > **Ein Protokolleintrag ohne seinen Handelnden ist eine Auskunft. Ein
 * > Schlüssel ohne sein Schloss ist ein Risiko.**
 *
 * ## Was hier **nicht** steht
 *
 * Kein Ablaufdatum und keine Fähigkeitenliste je Marke — beides steht in
 * `docs/131 §9` als Entscheidung und nicht als Lücke. Eine Marke kann, was ihr
 * Konto kann; eine zweite Rechteachse wäre die zweite Fassung der Policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // Was der Kunde in seiner Liste liest. Der Klartext steht nirgends.
            $table->string('name', 64);

            // 64 Hexzeichen aus `hash('sha256', …)`. Eindeutig, damit die
            // Prüfung ein Index-Zugriff ist und keine Rechnung über den Bestand.
            $table->string('token_hash', 64)->unique();

            // **Nur zum Anzeigen.** Die ersten Zeichen des Klartexts, damit
            // jemand mit drei Marken sagen kann, welche davon in welchem Skript
            // steckt. Sie allein trägt nichts: Was fehlt, sind die übrigen
            // zweiundvierzig.
            $table->string('preview', 12);

            $table->dateTime('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
