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
     * Vorgaben der Oberfläche. Beide Achsen aus §7.2 des Plans; die
     * Kundenfläche setzt die Dichte selbst auf „kunde".
     */
    'ui' => [
        'theme' => env('SRVPANEL_THEME', 'dark'),
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
