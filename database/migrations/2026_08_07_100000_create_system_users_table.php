<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Verzeichnis für verbrauchte Systembenutzer (docs/35).
 *
 * **Bis hierher war die Reservierung ein Nebeneffekt von `deleted_at`.** Ein
 * zurückgebautes Abonnement blieb als Zeile liegen — nicht, weil an ihr etwas
 * hing, sondern damit `Lifecycle::nextSystemUser()` seinen Namen noch sah. Auf
 * dem Zielserver waren das am 7. August 2026 einhunderteinundzwanzig Zeilen für
 * eine einzige `MAX()`-Abfrage. Sie hielten dabei einen Fremdschlüssel auf
 * `plans` fest (der 500er vom selben Tag), und sie zwangen jede Zählung, zwei
 * Filter abzuziehen, die die Datenbank nicht kennt.
 *
 * Ab hier steht die Reservierung für sich: eine Tabelle, die man lesen und
 * gegen `/etc/passwd` abgleichen kann.
 *
 * **Warum Abonnements hart gelöscht werden und Kunden weiter weich.** Das ist
 * keine Inkonsequenz, sondern der Unterschied zwischen einer Reservierung und
 * einem Geschäftsvorfall. Der Kundengrabstein wird von zwei Stellen gelesen —
 * `nextNumber()` und der Anmeldung, die Konten eines zurückgezogenen Kunden
 * abweist —, an ihm hängen diese Konten, und seine Nummer steht in Rechnungen.
 * Der Abonnementgrabstein trug eine Zahl. Nur der zweite lässt sich durch ein
 * Verzeichnis ersetzen, ohne etwas zu verlieren.
 *
 * **Warum `number` als Zahl und nicht `name` als Zeichenkette.** Die höchste
 * Nummer wurde bisher in PHP gesucht, weil `CAST(SUBSTRING(...))` auf MariaDB
 * und SQLite verschieden ausfällt. Mit einer Zahlspalte ist `MAX(number)` auf
 * beiden dasselbe — und der PHP-Umweg entfällt samt seinem Grund. Der Name
 * entsteht aus der Zahl (`'p'.$number`) an genau einer Stelle, in `Lifecycle`;
 * beides zu speichern wäre eine zweite Fassung derselben Wahrheit.
 *
 * **Kein `released_at`, kein `uid`.** Eine Nummer wird nie freigegeben, sonst
 * wäre das ganze Verzeichnis sinnlos. Die echte UID vergibt das Betriebssystem;
 * sie hier zu führen hiesse, eine Tatsache des Systems in der Datenbank zu
 * behaupten.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * **Erst lesen und prüfen, dann das Schema anfassen.**
         *
         * DDL ist auf MariaDB nicht transaktional: Ein Abbruch nach dem
         * `CREATE` liesse eine halb gefüllte Tabelle zurück, und der zweite
         * Lauf scheiterte an ihr statt am eigentlichen Grund. Was nicht
         * aufgeht, fällt deshalb auf, bevor irgendetwas entstanden ist.
         */
        [$entries, $unreadable] = $this->readNames();

        if ($unreadable !== []) {
            throw new RuntimeException(implode("\n", [
                'Diese Systembenutzer folgen nicht dem Muster p<Zahl> und lassen sich',
                'nicht ins Verzeichnis übernehmen: '.implode(', ', $unreadable),
                '',
                'Geraten wird hier nichts — eine falsche Nummer im Verzeichnis gibt',
                'eine UID ein zweites Mal frei. Die Zeilen gehören von Hand geklärt',
                '(docs/35, Schritt 1), danach läuft die Migration erneut.',
            ]));
        }

        Schema::create('system_users', function (Blueprint $table) {
            $table->id();

            // Eindeutig, und das ist die Sicherung und nicht die Zierde:
            // `Lifecycle::claim()` verlässt sich darauf, dass zwei
            // gleichzeitige Anlagen nicht denselben Namen bekommen.
            $table->unsignedInteger('number')->unique();

            // Eine Abschrift für die Nachschau („welcher Kunde hatte p1043"),
            // kein Fremdschlüssel — genau wie `subscriptions.main_domain` und
            // aus demselben Grund: Das Abonnement darf verschwinden, der Name
            // bleibt vergeben. Nullable, weil eine Zeile auch dann entstehen
            // muss, wenn der Name fehlt.
            $table->string('subscription')->nullable();

            $table->timestamp('claimed_at')->useCurrent();
        });

        foreach ($entries as $entry) {
            // `insertOrIgnore` und nicht `insert`: Der eindeutige Index ist die
            // Sicherung, und ein doppelter Name im Bestand darf die Migration
            // nicht abbrechen lassen, nachdem sie schon die Hälfte geschrieben
            // hat. Die Zählung darunter meldet den Fall trotzdem.
            DB::table('system_users')->insertOrIgnore($entry);
        }

        // **Die Migration prüft ihre eigene Arbeit.** Ohne diese Zeilen ist sie
        // eine Behauptung — und eine, deren Fehler erst auffiele, wenn ein
        // neuer Kunde einen alten Namen bekommt.
        $written = DB::table('system_users')->count();

        if ($written !== count($entries)) {
            throw new RuntimeException(sprintf(
                'Verzeichnis unvollständig: %d von %d Namen übernommen.',
                $written,
                count($entries),
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_users');
    }

    /**
     * Die Namen aus dem Bestand — als Zeilen und als Fundliste des Unlesbaren.
     *
     * Über `DB::table()` und nicht über das Modell: Gebraucht wird
     * `withTrashed`-Semantik ohne Mandantenklammer, und beides hat der Erbauer
     * von Haus aus. Zum Zeitpunkt dieser Migration trägt `subscriptions` noch
     * sein `deleted_at`; die Zeilen der Grabsteine sind genau die, deren Namen
     * sonst verloren gingen.
     *
     * Doppelte Nummern werden hier und nicht erst im Index abgefangen, damit
     * die Zählung oben exakt aufgeht. Es gewinnt die kleinste `id` — die erste
     * Vergabe, und die ist die, nach der man später sucht.
     *
     * @return array{0: list<array{number: int, subscription: string|null, claimed_at: mixed}>, 1: list<string>}
     */
    private function readNames(): array
    {
        $entries = [];
        $unreadable = [];

        $rows = DB::table('subscriptions')
            ->whereNotNull('system_user')
            ->where('system_user', 'like', 'p%')
            ->orderBy('id')
            ->get(['system_user', 'name', 'created_at']);

        foreach ($rows as $row) {
            $name = (string) $row->system_user;
            $number = (int) mb_substr($name, 1);

            if ($number <= 0) {
                $unreadable[] = $name;

                continue;
            }

            if (array_key_exists($number, $entries)) {
                continue;
            }

            $entries[$number] = [
                'number' => $number,
                'subscription' => $row->name === null ? null : (string) $row->name,

                // Der Zeitpunkt der Anlage und nicht `now()`: Das Verzeichnis
                // soll sagen, wann ein Name vergeben wurde, nicht wann diese
                // Migration lief.
                'claimed_at' => $row->created_at,
            ];
        }

        return [array_values($entries), $unreadable];
    }
};
