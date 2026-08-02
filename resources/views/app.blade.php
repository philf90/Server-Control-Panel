<!DOCTYPE html>
{{--
    Theme und Dichte stehen am Wurzelelement — beide Achsen aus §7.2 des
    Plans. Wer die Kundenfläche baut, setzt hier „customer"; sonst ändert sich
    nichts, weil alle Werte in app.css daran hängen.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ config('cloudsrv.ui.theme') }}"
      data-density="{{ config('cloudsrv.ui.density') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title inertia>CloudSrv</title>
    @vite('resources/js/app.ts')
    @inertia
</head>
<body>
@inertia
</body>
</html>
