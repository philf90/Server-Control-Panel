{{-- Reiner Text. Zeilen unter 78 Zeichen, damit kein Klient umbricht, wo er will. --}}
{{--
    **Jede Ausgabe mit {!! !!} und keine mit der maskierenden Form.** Eine
    Textmail ist kein HTML: Die maskierende Form schrieb im Abnahmelauf vom
    27. September 2026 `&quot;No space left on device&quot;` in den Satz
    über die Inodes (`docs/137 §7`, Befund 2). `PlainTextMailTest` hält es.

    **Kein Absender, den die Mail nicht hat** (Befund 1). Dieselbe Vorlage
    trägt die Befunde der Nacht und die der Messung alle fünf Minuten; hier
    stand „die nächtliche Bestandsdiagnose", und die Mail über eine volle
    Platte kam am Abend. Die Regel nennt deshalb beide Takte, und
    `DiskCadenceTest` hält die Zahlen darin an Zeitgeber und Haltezeit.
--}}
Guten Tag,

die Bestandsdiagnose auf {!! $host !!} meldet Folgendes:

@foreach ($findings as $finding)
- {!! $finding['label'] !!}
  {!! $finding['subject'] !!}
  {!! $finding['detail'] !!}
  steht seit {!! $finding['since'] !!}

@endforeach
Die vollständige Liste mit dem ungekürzten Wortlaut der Werkzeuge steht auf
der Seite „Diagnose" im Panel.

Sie bekommen jede dieser Zeilen einmal. Verschwindet ein Befund und kommt
später wieder, meldet sich das Panel erneut. Gemeldet wird, was mehrere
Läufe hintereinander dasteht: zwei Nächte bei den nächtlichen Prüfungen,
drei Läufe im Abstand von fünf Minuten bei der Belegung der Dateisysteme.
Ein Zustand, der sich vorher wieder einrenkt, erzeugt keine Nachricht.

@include('mail.signature')
