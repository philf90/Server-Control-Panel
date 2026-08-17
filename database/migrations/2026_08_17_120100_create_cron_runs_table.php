<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die aufgezeichneten Läufe eines Cronjobs (P6 Schritt 9, `docs/51 §6`).
 *
 * **Warum es diese Tabelle überhaupt gibt.** Gemessen (`docs/60 §10`): Ohne MTA
 * geht die Ausgabe eines Cronjobs **nirgendwohin** — kein Fehler, keine Meldung,
 * keine Datei —, und einen MTA hat ein frisch aufgesetzter Server nicht. Die
 * Aufzeichnung durch `cron-run` ist deshalb nicht die bequemere Art, an die
 * Ausgabe zu kommen, sondern die einzige.
 *
 * **`output` ist `mediumtext`** (`docs/51 §6`). 64 KB passten auch in `text`,
 * aber die Kappung steht im Code und nicht an der Spaltenbreite, und zwischen
 * „der Code kappt bei 64 KB" und „die Spalte hört bei 64 KB auf" liegt der
 * Fehler aus `docs/48`: Die `PDOException` riss den `catch`-Zweig mit, der den
 * Fehlschlag festhalten sollte. Diese Tests laufen gegen SQLite, wo jede Länge
 * durchgeht; der Server läuft gegen MariaDB, wo sie es nicht tut.
 *
 * > **Ein Test, der gegen eine andere Datenbank läuft als der Server, prüft die
 * > Grenzen der falschen.**
 *
 * **`truncated` ist eine eigene Spalte und kein Vergleich der Länge.** Eine
 * Anzeige, die eine abgeschnittene Ausgabe wie eine vollständige aussehen lässt,
 * behauptet etwas, das sie nicht weiss — derselbe Satz wie in `docs/48` über
 * `a\tb` und `a b`. Wer das aus `strlen($output) === 65536` erschlösse, läge bei
 * einer Ausgabe falsch, die zufällig genau so lang ist.
 *
 * **`status` und `exit_code` stehen nebeneinander**, weil sie zwei Fragen
 * beantworten. `timeout(1)` meldet die abgelaufene Frist als Rückgabewert 124;
 * dem Kunden „der Befehl endete mit 124" zu sagen, wo „er lief zu lange" gemeint
 * ist, wäre eine Auskunft, die in die Irre führt. Und `skipped` hat gar keinen
 * Rückgabewert — dieser Lauf hat nie stattgefunden, weil der vorige noch lief.
 *
 * > **Eine Reihe ausgefallener Läufe sähe ohne Eintrag aus wie eine Reihe, die
 * > es nie gab.**
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cron_job_id')->constrained()->cascadeOnDelete();

            /*
             * **Der Mandant steht mit dabei, obwohl der Job ihn schon kennt.**
             * Dieselbe Bauart wie bei `database_dumps`, und aus dem Grund, der
             * die dritte Grenze trägt: {@see BelongsToSubscription} klammert
             * über `subscription_id`, und ohne diese Spalte wäre die
             * Voreinstellung dieser Tabelle „alles sichtbar" statt „nichts".
             *
             * > **Die Mandantenklammer verweigert im Grundzustand alles — aber
             * > nur dort, wo sie greifen kann.**
             *
             * Ein `whereHas('job')` an jeder Leseabfrage wäre die zweite
             * Fassung derselben Regel, und die zweite ist die, die beim nächsten
             * Umbau vergessen wird.
             */
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            $table->timestamp('started_at');
            $table->unsignedInteger('duration_ms');

            /*
             * `nullable`, weil ein übersprungener Lauf keinen hat. `0` wäre
             * hier „erfolgreich beendet" und damit das Gegenteil dessen, was
             * gemeint ist.
             */
            $table->integer('exit_code')->nullable();

            // ok, failed, timeout, skipped — die Aufzählung steht im Modell.
            $table->string('status', 16);

            $table->mediumText('output')->nullable();
            $table->boolean('truncated')->default(false);

            $table->timestamps();

            /*
             * Gelesen wird immer „die letzten Läufe dieses Jobs", und
             * beschnitten wird auf 50 je Job (Entscheidung 4, `docs/51 §3`) —
             * beides derselbe Index. Beschnitten wird beim Einpflegen und nicht
             * in einem Tageslauf: Ein Job, der jede Minute läuft, soll die
             * Tabelle nicht bis zum nächsten Aufräumen füllen dürfen.
             */
            $table->index(['cron_job_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_runs');
    }
};
