<!DOCTYPE html>
{{--
    Die Hülle der Fehlerseiten.

    **Warum es sie gibt.** Bis zum 25. August 2026 hatte dieses Panel keine
    eigenen Fehlerseiten: Ein 403 war Laravels Vorgabe — *„This action is
    unauthorized."*, englisch, in Times, ausserhalb von „Kontor". `docs/19` ist
    bindend und verlangt deutsche Oberflächentexte; `WordChoiceTest` setzt das
    durch, wo jemand etwas geschrieben hat, und genau dort war es auch
    eingehalten.

    > **Ein Wächter, der die geschriebenen Seiten prüft, sagt nichts über die,
    > die niemand geschrieben hat.**

    **A9 hat es wichtig gemacht.** Vor der Rollentrennung bekam kaum jemand
    einen 403 zu sehen; seit Schritt 2 ist er der **entworfene** Zustand für
    acht Seiten (`docs/84`, Befund 3).

    ## Warum Blade und nicht Inertia

    Eine Fehlerseite muss gerade dann tragen, wenn etwas kaputt ist. Über
    Inertia gerendert liefe sie durch `HandleInertiaRequests::share()` und damit
    an die Datenbank — bei einem 500, den eine tote Verbindung ausgelöst hat,
    scheiterte sie ein zweites Mal und der Betrachter bekäme gar nichts.

    Deshalb: kein Inertia, kein `auth()`, keine Abfrage. Nur das Stylesheet und
    die Zeilen, die schon dastehen.

    > **Eine Seite, die es gerade dann geben muss, wenn etwas kaputt ist, lädt
    > so wenig wie möglich.**

    Aus demselben Grund steht `data-theme-mode="system"`: Ein Konto nach seiner
    Themewahl zu fragen, hiesse fragen, wer angemeldet ist — und das ist eine
    Abfrage mehr. Der Browser beantwortet es ohne uns.
--}}
<html lang="de"
      data-theme="{{ config('srvpanel.ui.theme', 'light') }}"
      data-theme-mode="system"
      data-density="admin">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>@yield('title') · SrvPanel</title>

    {{-- Dasselbe Skript wie in app.blade.php, und aus demselben Grund: ohne
         `defer` mitten im Kopf, damit die Fläche nicht erst dunkel ist und dann
         hell wird. --}}
    <script>
        (function () {
            var wurzel = document.documentElement
            var hell = window.matchMedia('(prefers-color-scheme: light)')

            if (wurzel.getAttribute('data-theme-mode') === 'system') {
                wurzel.setAttribute('data-theme', hell.matches ? 'light' : 'dark')
            }
        })()
    </script>

    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite('resources/css/app.css')
</head>
<body>
{{--
    **Keine geliehene Hülle.** Der erste Wurf schrieb `<main class="page">` —
    eine Klasse, die es nicht gibt; die Layouts benutzen `.content`, und die
    steht in deren `<style scoped>` und ist für Blade nicht da. Gefunden hat es
    `ClassReachTest` beim ersten Lauf über Blade, und das ist genau der Grund,
    aus dem er es jetzt liest.

    `.failure` trägt seinen Rand deshalb selbst.
--}}
<main>
    <div class="failure">
        <p class="failure-code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p class="hint">@yield('message')</p>

        {{-- Ein Weg zurück, und zwar einer, der immer existiert. Ein Link auf
             die vorige Seite wäre der Weg, der gerade nicht funktioniert hat. --}}
        <p class="failure-back"><a class="link" href="/">Zur Übersicht</a></p>
    </div>
</main>
</body>
</html>
