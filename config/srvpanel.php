<?php

declare(strict_types=1);

return [
    /*
     * Der Socket des Agenten. Alles Privilegierte geht hier durch; die
     * Anwendung kennt keinen zweiten Weg ins System.
     */
    'agent' => [
        'socket' => env('SRVPANEL_AGENT_SOCKET', '/run/srvpanel/agent.sock'),
        'timeout' => (int) env('SRVPANEL_AGENT_TIMEOUT', 300),
    ],

    /*
     * Kennzahlen: RingBuffer fester Größe, ein Takt von zehn Sekunden, 24
     * Stunden Vorhalt. 8640 Stützstellen je Kennzahl — die Datei wächst nicht,
     * sie dreht sich.
     */
    'metrics' => [
        'directory' => env('SRVPANEL_METRICS_DIR', storage_path('srvpanel/metrics')),
        'interval_s' => 10,
        'retention' => 8640,
    ],

    /*
     * Vorgänge und ihre Live-Ausgabe (§5.3).
     *
     * `stream_seconds` ist keine Schönheitsgrenze: Jede offene SSE-Verbindung
     * belegt einen PHP-FPM-Arbeiter, und der Pool hat eine feste Größe. Nach
     * dieser Zeit endet der Strom von selbst und der Browser baut ihn neu auf
     * — der Platz ist zwischendurch frei. Ohne die Grenze könnten ein paar
     * vergessene Browserreiter das Panel für alle unerreichbar machen.
     */
    'operations' => [
        'poll_ms' => (int) env('SRVPANEL_OPERATION_POLL_MS', 500),
        'stream_seconds' => (int) env('SRVPANEL_OPERATION_STREAM_SECONDS', 300),
    ],

    /*
     * Protokoll (§5.3).
     *
     * Der Export ist gedeckelt, weil er sonst einen PHP-FPM-Arbeiter beliebig
     * lange belegt — dieselbe Überlegung wie bei der Live-Ausgabe. Wird die
     * Grenze erreicht, sagt die letzte Zeile der Datei das; eine Datei, die
     * aussieht wie das ganze Protokoll und es nicht ist, wäre die schlechtere
     * Antwort auf dieselbe Grenze.
     */
    'audit' => [
        'export_max' => (int) env('SRVPANEL_AUDIT_EXPORT_MAX', 50000),
    ],

    /*
     * Sitzungen (§6.4).
     *
     * Zwei Grenzen, und sie tun Verschiedenes: Die gleitende steht in
     * config/session.php („wer nichts tut, fliegt raus"), die absolute hier
     * („auch wer etwas tut, muss sich irgendwann neu anmelden"). Ohne die
     * zweite läuft eine Sitzung, in der jemand alle zehn Minuten klickt,
     * wochenlang weiter — bei einem Panel, das als root arbeitet, ist das der
     * Unterschied zwischen einem vergessenen Browser bis Feierabend und einem
     * bis auf Weiteres.
     *
     * 12 Stunden ist ein Arbeitstag. 0 schaltet die Grenze ab.
     */
    'session' => [
        'absolute_lifetime' => (int) env('SRVPANEL_SESSION_ABSOLUTE_LIFETIME', 43200),
    ],

    /*
     * Vorgaben der Oberfläche. Beide Achsen aus dem Gestaltungssystem; die
     * Kundenfläche setzt die Dichte selbst auf „kunde".
     *
     * `theme` gilt nur für die Seiten **ohne** Konto — Anmeldung und zweiter
     * Faktor. Wer angemeldet ist, bekommt die Wahl aus seinem Konto oder,
     * wenn er keine getroffen hat, die seines Betriebssystems.
     *
     * **Die Vorgabe ist hell, seit „Kontor" gilt.** Bei „Leitstand" war sie
     * dunkel, weil dort die dunkle Fassung die war, in der die Gestaltung
     * ihren Charakter hatte. Kontor ist hell entworfen und dunkel mitgeführt;
     * eine dunkle Vorgabe hiesse, jedem Unangemeldeten zuerst die
     * Zweitfassung zu zeigen.
     */
    'ui' => [
        'theme' => env('SRVPANEL_THEME', 'light'),
        'density' => 'admin',
    ],

    /*
     * Auflage aus Abschnitt 13 der AGPL: Wer die Software über das Netz
     * benutzt, muss an den Quelltext kommen — und zwar an den der laufenden
     * Fassung, nicht bloß an das Repository. Der Link steht in der Fußzeile
     * beider Flächen und bleibt auch bei eigenem Branding stehen.
     */
    'source' => [
        'repository' => 'https://github.com/philf90/Server-Control-Panel',
        'commit' => env('SRVPANEL_COMMIT', ''),
    ],
];
