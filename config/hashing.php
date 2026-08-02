<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Verfahren
    |--------------------------------------------------------------------------
    |
    | Argon2id, wie in §6.4 festgelegt. Das Verfahren gewann die Password
    | Hashing Competition und ist gegen beide Angriffsarten ausgelegt: gegen
    | Grafikkarten durch den Speicherbedarf, gegen Seitenkanäle durch den
    | datenunabhängigen ersten Durchgang. bcrypt — der Standard des Gerüsts —
    | braucht 4 KiB und lässt sich auf einer Grafikkarte massiv parallel
    | rechnen.
    |
    | Dass PHP das Verfahren mitbringt, wird nicht vorausgesetzt, sondern im
    | Integrationslauf auf allen vier Zielplattformen geprüft.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon-Parameter
    |--------------------------------------------------------------------------
    |
    | 64 MiB Speicher, vier Durchgänge, ein Faden. Das liegt deutlich über der
    | Mindestempfehlung des OWASP (19 MiB, zwei Durchgänge) und kostet je
    | Anmeldung einen Bruchteil einer Sekunde.
    |
    | Über Umgebungsvariablen einstellbar, und das ist kein Selbstzweck: Auf
    | einem kleinen Server mit wenigen hundert MiB freiem Speicher würden
    | mehrere gleichzeitige Anmeldeversuche sonst den Arbeitsspeicher
    | belegen. Die Ratenbegrenzung deckelt das, aber ein Betreiber, der
    | herunterregeln muss, soll es können, ohne Code zu ändern.
    |
    */

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
