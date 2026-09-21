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
--}}

--
{{ $brand->name }}
@if ($brand->footer)
{{ $brand->footer }}
@endif
