<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Plans\Quotas;

/**
 * Welche Kennzahlen die verdichtete Tabelle je Tag führt (`docs/129 §6`).
 *
 * **Eine geschlossene Menge und keine freie Zeichenkette.** Die Tabelle ist
 * lang und nicht breit — je Abonnement, Kennzahl und Tag eine Zeile —, und
 * genau das macht eine sechste Kennzahl billig: keine Migration, ein Eintrag
 * hier. Der Preis dafür ist, dass die Spalte sonst alles aufnähme, was jemand
 * hineinschreibt, und eine Tabelle mit `trafic_sent` neben `traffic_sent` ist
 * von aussen nicht von einer heilen zu unterscheiden.
 *
 * > **Was eine Migration nicht mehr erzwingt, muss ein Typ erzwingen.**
 *
 * **Die Einheit steht im Namen und nicht in einem Kommentar.** `…_bytes`,
 * `…_mb`, oder gar nichts für eine blosse Anzahl. Eine Zahl ohne Einheit ist
 * an dem Tag falsch, an dem jemand sie in einer anderen liest — und das ist
 * spätestens {@see Quotas}, das Kontingente in MB führt.
 */
enum DailyMetric: string
{
    /**
     * Was über die Leitung gegangen ist — Kopf **und** Rumpf.
     *
     * Gemessen (`docs/128` M2): Bei einem `304` schreibt `combined` eine Null,
     * während 189 Bytes hinausgehen. Die Zahl hier kommt deshalb aus
     * `$bytes_sent` und nicht aus `$body_bytes_sent`; das Format dafür trägt
     * jede Kundendomain seit B2.
     */
    case TrafficSentBytes = 'traffic_sent_bytes';

    /** Und was hereinkam — `$request_length`, ebenfalls aus B2. */
    case TrafficReceivedBytes = 'traffic_received_bytes';

    /** Wie viele Anfragen. Eine Anzahl, keine Besucher — das wäre ein eigenes Merkmal. */
    case Requests = 'requests';

    /**
     * Wie viele davon mit `4xx` oder `5xx` beantwortet wurden.
     *
     * **Die Fehlerquote steht nicht in der Tabelle, sie wird gerechnet.** Eine
     * Quote ist keine ganze Zahl, und zwei abgelegte Zahlen, aus denen sich die
     * dritte ergibt, sind besser als drei, von denen eine veralten kann.
     */
    case Errors = 'errors';

    /**
     * Der belegte Platz des Abonnements.
     *
     * Er steht seit P1 als **gegenwärtiger** Wert an `subscriptions`; hier
     * bekommt er einen Verlauf. `disk_used_mb` ist die Quelle, und die Einheit
     * wandert mit dem Namen mit.
     */
    case DiskMb = 'disk_mb';

    /**
     * Die Summe über die Datenbanken des Abonnements.
     *
     * Quelle ist `databases.size_bytes`, gemessen von `srvpanel:usage`. Auch
     * das ist heute ein gegenwärtiger Wert je Datenbank und bekommt hier seinen
     * Verlauf.
     */
    case DatabaseBytes = 'database_bytes';

    /**
     * Die Kennzahlen, die eine **Domain** führt.
     *
     * `docs/129 §6` nennt drei — Traffic, Zugriffe, Fehlerquote —, und alle
     * drei kommen aus B2. Platz und Datenbanken gehören dem Abonnement und
     * nicht einer seiner Domains.
     *
     * @return list<self>
     */
    public static function ofADomain(): array
    {
        return [self::TrafficSentBytes, self::TrafficReceivedBytes, self::Requests, self::Errors];
    }

    /**
     * Und die, die ein **Abonnement** führt.
     *
     * **Vier statt der fünf aus `docs/129 §6`.** Die fünfte — die FPM-Prozesse
     * — ist auf keiner Maschine dieses Projekts gemessen: `Quota::PhpProcesses`
     * führt sie als Kontingent, und **gezählt** hat sie noch nie jemand. Sie
     * hier einzutragen hiesse, eine Spalte für eine Zahl zu öffnen, von der
     * niemand weiss, woher sie kommt.
     *
     * > **Eine Entscheidung, die eine Messung vorwegnimmt, ist keine
     * > Entscheidung — sie ist eine Messung, die niemand gefahren hat.**
     *
     * Die Form der Tabelle ist genau dafür gewählt: Wer sie misst, trägt einen
     * Fall hier ein und braucht keine Migration.
     *
     * @return list<self>
     */
    public static function ofASubscription(): array
    {
        return [
            self::TrafficSentBytes,
            self::TrafficReceivedBytes,
            self::Requests,
            self::Errors,
            self::DiskMb,
            self::DatabaseBytes,
        ];
    }
}
