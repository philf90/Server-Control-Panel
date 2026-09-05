<?php

declare(strict_types=1);

use App\Models\Announcement;
use App\Models\Concerns\BelongsToSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was der Betreiber im Panel ankündigt (A14, `docs/103 §6`).
 *
 * **Kein Agent, keine Datei, kein Neuladen von nginx.** A14 fasst nichts an,
 * was Systemrechte braucht — das ist der Zuschnitt, mit dem der Betreiber A14
 * von A12 getrennt hat: „A12 schreibt neun Vhost-Dateien, eine Ankündigung ist
 * Text in einer Tabelle" (`docs/81 §11`).
 *
 * **Keine `subscription_id` und kein {@see BelongsToSubscription}.** Dieselbe
 * Ausnahme wie bei `findings`, und aus demselben Grund: Eine Ankündigung gehört
 * dem Betrieb und nicht einem Kunden. Wer sie sieht, entscheidet `audiences`
 * und nicht die Mandantenklammer; die Adressierung je Abonnement ist
 * ausdrücklich kein Teil von A14 (`docs/103 §10`) und gehört eher zu A7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();

            $table->string('category', 16);

            /*
             * **500 Zeichen, und das ist eine Grenze der Ablage und nicht des
             * Aussehens.** Gemessen (`docs/81 §2.3q` M8): Eine Grenze in
             * Zeichen ist auf zwei Breiten zwei verschiedene Grenzen — rund 40
             * Zeichen je Zeile bei 390 px, rund 160 bei 1440 px. Gedeckelt wird
             * die Anzeige deshalb über eine Zeilenklammer; hier steht nur, was
             * überhaupt in die Zeile passt.
             *
             * `text` und nicht `varchar(500)`: Die Länge prüft das Formular,
             * und eine Spalte, die kürzt oder wirft, hat `docs/45` schon einmal
             * teuer bezahlt — dort riss die `PDOException` genau den
             * `catch`-Zweig mit, der den Fehlschlag festhalten sollte.
             */
            $table->text('body');

            /*
             * **Das Sichtbarkeitsfenster, beide Enden freilassbar.**
             *
             * Es ist ein **Filter beim Lesen und kein Zeitgeber** — der
             * Unterschied zu A12 und der Grund, warum es hier gefahrlos ist
             * (`docs/81 §11`). Ein Fenster, dessen Ende ein Zeitgeber
             * herstellt, endet nicht, wenn der Zeitgeber ausfällt.
             *
             * **`dateTime` und nicht `timestamp`, und das ist gemessen** —
             * dieselbe Falle, die `findings` schon einmal gekostet hat: Steht
             * `explicit_defaults_for_timestamp` auf `0`, gibt MariaDB der
             * ersten `TIMESTAMP NOT NULL` einer Tabelle ein
             * `ON UPDATE current_timestamp()`, das niemand geschrieben hat, und
             * der zweiten ein `DEFAULT '0000-00-00 00:00:00'`, das mindestens
             * eine Zielplattform abweist.
             *
             * > **Eine Vorgabe, die die Datenbank selbst einsetzt, steht in
             * > keiner Migration — und der Test gegen SQLite sieht sie nie.**
             *
             * Abgelegt wird **UTC**; die Umrechnung macht `Clock`, und zwar an
             * beiden Enden. Gemessen (`docs/81 §2.3q` M7): Ein Filter, der in
             * der Anzeigezone rechnet, ist genau während seiner eigenen
             * Laufzeit unsichtbar.
             */
            $table->dateTime('visible_from')->nullable();
            $table->dateTime('visible_until')->nullable();

            /*
             * Wer sie sieht — Betreiber, Administrator, Kunde, mehrfach.
             *
             * Als JSON und nicht als drei Spalten: Die Frage lautet „enthält
             * die Liste das Publikum dieses Kontos?" und nicht „welche der drei
             * Flaggen steht". Drei Spalten wären dieselbe Antwort in einer
             * Form, die bei einem vierten Publikum eine Migration kostet.
             */
            $table->json('audiences');

            $table->timestamps();

            /*
             * Gelesen wird bei **jedem** Seitenaufruf des Panels
             * ({@see Announcement::visibleTo()}) — die Ankündigungen hängen im
             * geteilten Teil der Nutzlast. Der Index deckt genau diese Abfrage:
             * das Fenster, danach die Reihenfolge.
             */
            $table->index(['visible_from', 'visible_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
