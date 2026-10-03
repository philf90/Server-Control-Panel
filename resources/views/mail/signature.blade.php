{{--
    Die Unterschrift jeder Mail dieses Panels (B6).

    **Eine Datei und kein Textbaustein in zwei Vorlagen.** Zwei Fassungen
    derselben Unterschrift liefen beim nächsten Merkmal auseinander, und welche
    der beiden die gepflegte ist, sähe man erst an der Mail, die man gerade
    nicht liest.

    **Was hier fehlt, fehlt mit Grund.** Logo und Farbe stehen auf der
    Anmeldeseite und nicht hier: Diese Mails sind reiner Text, und das ist seit
    P2 eine Entscheidung — HTML kann auf dem Weg verändert werden, Text nicht.
    Eine Unterschrift mit Bild und Farbe hiesse, jede Meldung dieses Panels in
    ein Format zu heben, dessen Ankunft man nicht mehr beurteilen kann.

    `$brand` kommt aus dem View-Composer für `mail.*`; keine Vorlage muss ihn
    durchreichen, und keine kann ihn vergessen.

    **Die Trennzeile ist `-- `, mit Leerzeichen** (RFC 3676 §4.3), entschieden
    am 3. Oktober 2026 (`docs/140 §7`). Erst daran erkennt ein Mailprogramm die
    Unterschrift: Es setzt sie ab und lässt sie beim Antworten weg. Sie steht als
    Ausgabe da und nicht als Text, weil `.editorconfig` Leerzeichen am Zeilenende
    entfernt, und mit ihnen das eine, auf das es ankommt.

    **Die Leerzeile davor steht in der einbindenden Vorlage und nicht hier.**
    Laravel schneidet jeder gerenderten Ansicht den Leerraum am Anfang ab
    (`ltrim(ob_get_clean())` in `PhpEngine`), auch einer eingebundenen. Die
    Leerzeile, die hier bis dahin vor der Trennzeile stand, kam deshalb in keiner
    Mail an. `MailSignatureTest` hält beides.
--}}
{!! '-- ' !!}
{!! $brand->name !!}
@if ($brand->footer)
{!! $brand->footer !!}
@endif
