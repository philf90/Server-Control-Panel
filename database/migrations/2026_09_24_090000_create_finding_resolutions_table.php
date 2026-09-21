<?php

declare(strict_types=1);

use App\Support\Diagnose\FindingLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dass ein gemeldeter Befund wieder verschwunden ist (B1).
 *
 * ## Warum es diese Tabelle gibt
 *
 * `finding_notifications` sagt, dass gemeldet wurde. Dass etwas **behoben** ist,
 * sagte bis zum 24. September 2026 niemand: {@see FindingLog::forgetMissing()}
 * löscht die Zeile, und damit verschwindet auch die Erinnerung an die
 * Zustellung. Ein Empfänger, der Vorfälle verwaltet, behält den Vorfall dann
 * für immer offen.
 *
 * > **Ein Kanal, der nur meldet, dass etwas kaputt ist, erzieht seinen Leser
 * > dazu, ihn zu ignorieren.**
 *
 * ## Warum sie den Befund **abschreibt** statt auf ihn zu zeigen
 *
 * Es gibt ihn nicht mehr. Ein Fremdschlüssel zeigte auf eine Zeile, die im
 * selben Augenblick gelöscht wird — dieselbe Überlegung wie bei
 * `subscription_name` seit `docs/35` und der Abschrift des Kontonamens seit
 * `docs/901`.
 *
 * > **Löschen und Vergessen sind zwei Dinge. Die Zeile darf verschwinden; was
 * > sie getan hat, darf es nicht.**
 *
 * ## Warum je Kanal
 *
 * Aus demselben Grund wie die Buchung: Ein Vorfall, den nur der Webhook
 * bekommen hat, wird auch nur dort geschlossen. Eine Zeile entsteht deshalb
 * genau für die Kanäle, die den Befund wirklich gemeldet haben — was nie
 * angekündigt wurde, wird nicht abgemeldet.
 *
 * > **Eine Entwarnung ohne vorangegangene Warnung ist eine Meldung über
 * > nichts.**
 *
 * ## Warum sie wieder leer wird
 *
 * Sie ist eine **Warteschlange und kein Protokoll**: `srvpanel:notices` nimmt
 * die Zeilen, stellt zu und löscht sie. Was nicht ankam, bleibt liegen — genau
 * wie ein Befund, der nicht gemeldet werden konnte, fällig bleibt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finding_resolutions', function (Blueprint $table): void {
            $table->id();

            // Die Abschrift des Befundes, den es nicht mehr gibt.
            $table->string('check', 64);
            $table->string('subject');
            $table->string('reason', 64);

            $table->string('channel', 32);
            $table->dateTime('resolved_at');
            $table->timestamps();

            /*
             * **Dieselbe Kennung wie am Befund, plus den Kanal.** Verschwindet
             * derselbe Schaden zweimal, bevor die erste Entwarnung hinausging,
             * bleibt es eine Zeile — und der Empfänger bekommt eine Entwarnung
             * und nicht zwei.
             */
            $table->unique(['check', 'subject', 'reason', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_resolutions');
    }
};
