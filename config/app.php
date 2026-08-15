<?php

use App\Support\Panel\Release;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    /*
     * **Die Fassung kommt aus dem Dateisystem und nicht aus der Umgebung.**
     *
     * Hier stand `env('SRVPANEL_VERSION', '0.1.0-dev')`, und die Variable wird
     * nirgends gesetzt — nicht im Paket, nicht in der Einrichtung, nicht in der
     * `.env`. Das Panel meldete damit seit seiner ersten Woche `0.1.0-dev`, und
     * zwar sichtbar: Die Marke im Menü zeigt diesen Wert, und der Kommentar
     * dort nennt sie „die erste Frage bei jedem Fehlerbericht".
     *
     * Ein Vorgabewert für eine Variable, die niemand setzt, ist kein
     * Vorgabewert — er ist die Antwort. Die Begründung für den neuen Weg steht
     * in {@see \App\Support\Panel\Release}.
     */
    'version' => Release::version(),

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    /*
     * **`de` als Voreinstellung, und das ist eine Korrektur.**
     *
     * Hier stand `'en'`. `.env.example` setzt zwar `APP_LOCALE=de`, aber die
     * Datei, die auf dem Server gilt, ist `/etc/srvpanel/panel.env` — und die
     * schreibt `PanelProvision`, ohne `APP_LOCALE` zu setzen. Auf jedem
     * installierten Panel griff damit diese Zeile, und das Gebietsschema stand
     * seit P0 auf Englisch.
     *
     * Gemerkt hat es niemand, weil jede Meldung dieses Panels von Hand
     * geschrieben ist. Erst als ein Formular an einer **Prüfregel** scheiterte
     * statt an einer Absage des Agenten, kam ein Satz von Laravel durch —
     * „The content field must be a string." (`docs/55`, Befund 7).
     *
     * > **Eine Beispieldatei und die Datei, die gilt, sind zwei Quellen — und
     * > die zweite ist die, nach der niemand sieht.**
     *
     * Die Voreinstellung steht deshalb hier und nicht als weitere Zeile in
     * `panel.env`: Dieses Panel ist deutsch (`docs/19 §4a`), und eine Angabe,
     * die in jeder Installation dieselbe ist, gehört nicht in eine Datei, die
     * je Installation entsteht.
     */
    'locale' => env('APP_LOCALE', 'de'),

    /*
     * **Der Rückfall bleibt Englisch, und zwar mit Absicht.** Fehlt ein
     * Schlüssel in `lang/de`, kommt Laravels eingebauter Satz — englisch, aber
     * lesbar. Stünde hier `de`, käme der rohe Schlüssel („validation.mimes"),
     * und das ist die schlechtere von zwei schlechten Auskünften.
     *
     * Welche Regeln eine deutsche Fassung haben **müssen**, hält
     * {@see \Tests\Feature\ValidationLanguageTest} fest — der Rückfall ist die
     * Notbremse und nicht der Plan.
     */
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
