<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Das Ergebnis eines DNS-Abgleichs (P7 Schritt 5, `docs/72 §5`).
 *
 * **Es gibt keinen Spiegel der Zone, und das ist der Punkt.** Was hier steht,
 * ist keine Kopie fremder Einträge, sondern das Ergebnis **einer Messung** —
 * und ein Ergebnis ohne seinen Zeitpunkt wäre eine Behauptung über jetzt.
 *
 * > **Eine Antwort aus dem Zwischenspeicher ist eine Aussage über vorhin** —
 * > und wenn sie das ist, sagt sie es auch.
 *
 * **Eine Zeile je Domain und keine Geschichte.** Der Abgleich beantwortet
 * „zeigt die Domain jetzt hierher"; ein Verlauf beantwortete „seit wann", und
 * danach hat niemand gefragt. Wer ihn eines Tages braucht, legt eine zweite
 * Tabelle an — das ist billiger, als heute eine zu füllen, die keiner liest.
 *
 * **`subscription_id` steht mit dabei**, obwohl die Domain ihn kennt. Dieselbe
 * Bauart wie bei `cron_runs`, und aus demselben Grund:
 * {@see BelongsToSubscription} klammert über diese Spalte,
 * und ohne sie wäre die Voreinstellung dieser Tabelle „alles sichtbar" statt
 * „nichts".
 *
 * > **Die Mandantenklammer verweigert im Grundzustand alles — aber nur dort,
 * > wo sie greifen kann.**
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_dns_checks', function (Blueprint $table): void {
            $table->id();

            /*
             * **Eindeutig je Domain.** Der Abgleich ersetzt sein voriges
             * Ergebnis; zwei Zeilen für dieselbe Domain wären zwei Antworten
             * auf dieselbe Frage, und die Anzeige müsste raten, welche gilt.
             */
            $table->foreignId('domain_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            /*
             * **Nicht `updated_at`.** Der Zeitstempel gehört zur Messung und
             * nicht zur Zeile: Ein späterer Umbau, der die Zeile aus einem
             * anderen Grund anfasst, verschöbe sonst die Auskunft „zuletzt
             * geprüft" — und niemand merkte es.
             */
            $table->timestamp('checked_at');

            /*
             * Der ganze Befund: die gefragten Nameserver, die erwarteten
             * Adressen und je Eintrag sein Zustand. Als JSON, weil die Form
             * mit dem Sollzustand wächst und eine Spalte je Satztyp beim
             * dritten Typ eine Migration kostete.
             */
            $table->json('findings');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_dns_checks');
    }
};
