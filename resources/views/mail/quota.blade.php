{{-- Reiner Text. Zeilen unter 78 Zeichen, damit kein Klient umbricht, wo er will. --}}
Guten Tag,

für Ihr Abonnement {{ $subscription }} ist ein Kontingent überschritten:

@foreach ($overruns as $overrun)
- {{ $overrun['label'] }}: {{ $overrun['detail'] }}
@endforeach

Diese Kontingente werden gemessen und nicht erzwungen — es wird nichts
abgeschaltet und nichts gesperrt. Die Zahlen stehen mit ihrem Verlauf der
letzten dreissig Tage auf der Seite Ihres Abonnements im Panel.

Beim Verkehr zählt diese Zahl, was der Webserver protokolliert hat. Die
Abrechnung Ihres Anbieters kann höher liegen: Er zählt TCP, TLS und
Wiederholungen mit.

Sie bekommen diese Nachricht einmal je Überschreitung. Sinkt der Wert wieder
unter das Kontingent, meldet sich das Panel erst wieder, wenn es erneut
darüber liegt.
