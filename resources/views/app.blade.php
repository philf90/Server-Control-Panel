<!DOCTYPE html>
{{--
    Theme und Dichte stehen am Wurzelelement — beide Achsen aus §7.2 des Plans.

    Die Dichte hängt am Kontotyp: Die Adminfläche ist dicht, die Kundenfläche
    ruhig. Alle Werte hängen in app.css an diesem Attribut, deshalb genügt
    hier ein Wort — und deshalb steht es hier und nicht an dreißig Stellen.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ config('srvpanel.ui.theme') }}"
      data-density="{{ auth()->user()?->isAdmin() === false ? 'customer' : config('srvpanel.ui.density') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title inertia>SrvPanel</title>

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
