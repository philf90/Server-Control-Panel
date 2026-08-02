<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Kern aus §5.1: Kunde → Abonnement → alles Weitere, dazu die Konten.
 *
 * **Die Reihenfolge in dieser Datei folgt den Fremdschlüsseln.** `customers`
 * zuerst, weil `plans`, `subscriptions` und `accounts` darauf zeigen. In vier
 * getrennte Dateien zerlegt wäre dieselbe Reihenfolge über Dateinamen
 * erzwungen — eine fragile Kopplung an Zeitstempel für vier Tabellen, die
 * ohnehin nur gemeinsam Sinn ergeben.
 *
 * **Die Tabelle `users` des Gerüsts fällt weg.** Sie hat in 0.1.0-rc.1 nie
 * einen Datensatz getragen: Es gab keine Anmeldung. Was hier entsteht, ist
 * keine Umbenennung, sondern die erste Fassung — `accounts` trägt einen Typ,
 * eine Kundenbindung und einen Zustand, und nichts davon ließe sich sinnvoll
 * aus einem leeren `users` herüberretten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('users');

        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // Vorbereitung Reseller (§5.4). Bleibt in der 1.0 leer und ist in
            // der Oberfläche unsichtbar — aber die Rechteprüfung läuft von
            // Anfang an über die Kette statt über einen festen Vergleich. Das
            // ist der Unterschied zwischen „später erweiterbar" und „später
            // Umbau".
            $table->foreignId('parent_customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();

            $table->string('number')->unique();
            $table->string('company')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('status', 32)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_name');
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();

            // Ebenfalls Reseller-Vorbereitung: Ein Plan kann später einem
            // Reseller gehören statt dem Betreiber.
            $table->foreignId('owner_customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();

            $table->string('name')->unique();
            $table->text('description')->nullable();

            // Kontingente und Freigaben als JSON, nicht als Spalten.
            //
            // Der Grund steht in §5.2: Ein Abonnement darf einzelne Werte
            // übersteuern und muss dabei „nicht gesetzt" von „auf 0 gesetzt"
            // unterscheiden können. Mit Spalten bräuchte jede Übersteuerung
            // eine zweite, nullable Spalte; mit JSON ist die Abwesenheit eines
            // Schlüssels genau die Aussage „gilt wie im Plan".
            $table->json('quotas');
            $table->json('features');

            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->string('name');

            // Genau ein Systembenutzer, genau eine Hauptdomain (§5.1). Beide
            // bleiben leer, bis P2 und P3 sie mit Systemwirkung füllen — die
            // Eindeutigkeit gilt trotzdem schon, damit sie später nicht gegen
            // vorhandene Daten nachgerüstet werden muss.
            $table->string('system_user', 32)->nullable()->unique();
            $table->string('main_domain')->nullable()->unique();

            $table->string('status', 32)->default('active');
            $table->json('quota_overrides')->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);

            // Leer genau dann, wenn der Typ „admin" ist. Daran erkennt die
            // Mandantenklammer, dass hier nicht eingeschränkt wird.
            $table->foreignId('customer_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('locale', 8)->default('de');
            $table->string('status', 32)->default('active');

            // Zweiter Faktor. Die Spalten entstehen jetzt, weil eine
            // Anmeldung, die später um 2FA erweitert wird, sonst eine
            // Migration über eine Tabelle mit echten Konten braucht.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('type');
        });

        // Was ein Zusatzbenutzer darf, steht hier — nicht am Konto.
        //
        // Derselbe Mensch kann in einem Abonnement Dateien schreiben und in
        // einem anderen nur die Statistik sehen. Der Rechtekatalog aus §6.1
        // liegt als JSON daneben, samt der optionalen Einschränkung auf
        // einzelne Domains.
        Schema::create('account_subscription', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->json('permissions');
            $table->json('domain_ids')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_subscription');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('customers');

        // Das Gerüst zurückbauen, damit ein Rückwärtslauf nicht auf halber
        // Strecke eine Anwendung ohne Benutzertabelle hinterlässt.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
};
