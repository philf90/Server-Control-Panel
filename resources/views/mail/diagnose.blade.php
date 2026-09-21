{{-- Reiner Text. Zeilen unter 78 Zeichen, damit kein Klient umbricht, wo er will. --}}
Guten Tag,

die nächtliche Bestandsdiagnose auf {{ $host }} meldet Folgendes:

@foreach ($findings as $finding)
- {{ $finding['label'] }}
  {{ $finding['subject'] }}
  {{ $finding['detail'] }}
  steht seit {{ $finding['since'] }}

@endforeach
Die vollständige Liste mit dem ungekürzten Wortlaut der Werkzeuge steht auf
der Seite „Bestand" im Panel.

Sie bekommen jede dieser Zeilen einmal. Verschwindet ein Befund und kommt
später wieder, meldet sich das Panel erneut. Gemeldet wird, was zwei Läufe
hintereinander dasteht — ein Zustand, der sich in derselben Nacht wieder
einrenkt, erzeugt keine Nachricht.
@include('mail.signature')
