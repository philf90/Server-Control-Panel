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
    @vite('resources/js/app.ts')
    @inertia
</head>
<body>
@inertia
</body>
</html>
