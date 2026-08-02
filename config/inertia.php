<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Wo die Seiten liegen
    |--------------------------------------------------------------------------
    |
    | Ohne diese Angabe weiß der Sucher nicht, wo er nachsehen soll — und die
    | Prüfung „gibt es diese Seite überhaupt?" schlägt dann in jedem Test fehl,
    | auch bei Seiten, die es gibt. Das ist keine Formalie: Genau diese Prüfung
    | fängt eine umbenannte Vue-Datei ab, zu der noch ein Controller zeigt.
    | Ein Panel, das darauf mit einer weißen Seite antwortet, hat den Fehler
    | erst beim Nutzer.
    |
    */

    'pages' => [
        'paths' => [resource_path('js/Pages')],
        'extensions' => ['vue'],
        'ensure_pages_exist' => false,
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],

];
