<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vorgänge und Protokoll (§5.3).
 *
 * **Warum `operations` und nicht `jobs`.** Der Plan nennt sie „Vorgänge", das
 * Modell heißt englisch — und `jobs` ist bereits die Warteschlangentabelle von
 * Laravel. Zwei Bedeutungen unter einem Namen wären genau die Sorte
 * Verwechslung, die man um drei Uhr nachts nicht braucht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();

            // Leer bei Vorgängen des Betreibers (Paketinstallation,
            // Dienstneustart). Für Kunden sind die dadurch unsichtbar: Der
            // globale Filter fragt `subscription_id in (…)`, und NULL ist in
            // keiner Liste enthalten. Der Admin sieht sie, weil bei ihm gar
            // nicht gefiltert wird.
            $table->foreignId('subscription_id')->nullable()
                ->constrained()->cascadeOnDelete();

            // Wer ihn ausgelöst hat. Bleibt beim Löschen des Kontos stehen —
            // ein Protokolleintrag, der mit dem Konto verschwindet, ist als
            // Protokoll wertlos.
            $table->foreignId('account_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('type');
            $table->string('status', 32)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message')->nullable();

            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->longText('output')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('type');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->nullable()
                ->constrained()->nullOnDelete();

            // „Anmelden als" (§6.3): Jede Aktion wird doppelt vermerkt — wer
            // wirklich gehandelt hat und in wessen Kontext. Ohne dieses Feld
            // steht im Protokoll der Kunde, und der Admin ist unsichtbar.
            $table->foreignId('acting_as_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();

            $table->foreignId('subscription_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('action');
            $table->nullableMorphs('target');
            $table->string('result', 32);

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('operations');
    }
};
