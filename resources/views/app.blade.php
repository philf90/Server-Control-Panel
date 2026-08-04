<!DOCTYPE html>
@php
    /*
     * Die Themewahl des angemeldeten Kontos — oder nichts.
     *
     * `null` heisst „dem Betriebssystem folgen". Ohne Konto gibt es niemanden
     * zu fragen: Anmeldung und zweiter Faktor nehmen deshalb weiter den Wert
     * aus `SRVPANEL_THEME`, und das ist die Aufgabe, die dieser Einstellung
     * bleibt.
     */
    $gewaehlt = auth()->user()?->theme;
    $vorgabe = config('srvpanel.ui.theme');
@endphp
{{--
    Theme und Dichte stehen am Wurzelelement — beide Achsen aus §7.2 des Plans.

    Die Dichte hängt am Kontotyp: Die Adminfläche ist dicht, die Kundenfläche
    ruhig. Alle Werte hängen in app.css an diesem Attribut, deshalb genügt
    hier ein Wort — und deshalb steht es hier und nicht an dreißig Stellen.

    `data-theme` trägt immer ein fertiges Theme und nie „system": Die CSS-Reihe
    kennt zwei Fassungen, und welche gilt, muss entschieden sein, bevor der
    Browser das erste Mal zeichnet. Was gewählt wurde, steht daneben in
    `data-theme-mode` — das liest das Skript unten und sonst niemand.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ $gewaehlt ?? $vorgabe }}"
      data-theme-mode="{{ $gewaehlt ?? (auth()->check() ? 'system' : 'fixed') }}"
      data-density="{{ auth()->user()?->isAdmin() === false ? 'customer' : config('srvpanel.ui.density') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title inertia>SrvPanel</title>

    {{--
        Das Betriebssystem fragen — vor dem ersten Zeichnen.

        **Warum das hier steht und nicht in der Anwendung.** Ein Konto, das dem
        Betriebssystem folgt, kann der Server nicht auflösen: Ob dort gerade
        hell oder dunkel gilt, weiss nur der Browser. Würde die Anwendung das
        nachtragen, sähe man bei *jedem* Seitenaufruf für einen Sekundenbruchteil
        die dunkle Fläche, bevor sie hell wird — Vite lädt sein Bündel mit
        `defer`, und das läuft erst nach dem ersten Zeichnen.

        Dieses Skript steht ohne `defer` und ohne `async` mitten im Kopf. Der
        Browser hält an, führt es aus und zeichnet danach. Es kostet eine
        Zeile Auswertung und erspart das Blinken.

        **Es hört auch weiter zu.** Wer sein Betriebssystem abends auf dunkel
        stellt, während das Panel offen ist, hätte sonst bis zum nächsten
        Seitenaufruf die falsche Fläche.

        **Und es reicht die Umschaltung nach aussen.** Diese Attribute stehen
        am `<html>`, und das rendert Inertia bei einer Navigation nie neu — die
        Seite wechselt, das Gerüst bleibt. Ohne diesen Weg hätte ein Klick auf
        „Dunkel" gar nichts getan, bis jemand die Seite neu lädt. Gefunden im
        Browser, nachdem der Test schon grün war: Gespeichert wurde richtig,
        zu sehen war nichts.
    --}}
    <script>
        (function () {
            var wurzel = document.documentElement
            var hell = window.matchMedia('(prefers-color-scheme: light)')

            /*
             * `system` fragt den Browser, alles andere ist schon die Antwort.
             * `fixed` — die Seiten ohne Konto — bleibt unangetastet: Dort gilt
             * die Vorgabe des Betreibers und niemand darf sie überschreiben.
             */
            function anwenden(modus) {
                if (modus !== 'system' && modus !== 'light' && modus !== 'dark') return

                wurzel.setAttribute('data-theme-mode', modus)
                wurzel.setAttribute(
                    'data-theme',
                    modus === 'system' ? (hell.matches ? 'light' : 'dark') : modus,
                )
            }

            window.srvpanelTheme = anwenden

            /*
             * Der Hörer fragt bei jedem Auslösen nach dem aktuellen Modus und
             * nicht nach dem von damals. Wer während der Sitzung von „System"
             * auf „Hell" wechselt, bekäme sonst beim nächsten Wechsel seines
             * Betriebssystems die Wahl wieder weggenommen.
             */
            hell.addEventListener('change', function () {
                if (wurzel.getAttribute('data-theme-mode') === 'system') anwenden('system')
            })

            if (wurzel.getAttribute('data-theme-mode') === 'system') anwenden('system')
        })()
    </script>

    {{--
        Das Zeichen des Panels.

        **Vorher stand hier nichts.** `public/favicon.ico` gab es zwar — mit
        null Byte, dem Platzhalter, den Laravel mitbringt. Ein Reiter im
        Browser trug damit das leere Blatt, und wer mehrere Panels offen hat,
        unterscheidet sie am Titel oder gar nicht.

        **Die SVG steht nach der .ico und gewinnt trotzdem.** Browser, die
        `image/svg+xml` können, nehmen sie; die anderen bleiben bei der .ico,
        weil sie den Typ nicht kennen. Die Reihenfolge ist Absicht: Ein alter
        Browser, der die SVG-Zeile nicht versteht, hat davor schon etwas
        gefunden.

        **Das Apple-Zeichen ist ein eigenes Bild und keine Kopie.** iOS
        schneidet die Ecken selbst zu und legt nichts darunter — eine Grafik
        mit durchsichtigen Ecken bekäme dort schwarze Ränder.

        Geprüft von tests/Feature/IconTest.php: Jede Adresse hier zeigt auf
        eine Datei, die es gibt und die nicht leer ist.
    --}}
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    @vite('resources/js/app.ts')
    @inertia
</head>
<body>
@inertia
</body>
</html>
