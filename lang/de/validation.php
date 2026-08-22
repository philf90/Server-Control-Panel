<?php

declare(strict_types=1);

/*
 * Die Prüfmeldungen dieses Panels, auf Deutsch.
 *
 * ## Warum es diese Datei gibt
 *
 * `docs/19 §4a` ist bindend: **alle Texte der Oberfläche sind deutsch.** Bis
 * zum 15. August 2026 gab es dieses Verzeichnis nicht, und damit kamen Laravels
 * eingebaute Prüfmeldungen auf Englisch heraus. Aufgefallen ist es im Prüflauf
 * auf `cloudsrv24` (`docs/55`, Befund 7) — unter der deutschen Zeile „Das
 * Formular wurde nicht gespeichert." stand:
 *
 *     The content field must be a string.
 *
 * **Warum es bis dahin niemandem auffiel:** Jede Meldung, die ein Kunde je zu
 * sehen bekam, stammte aus diesem Projekt und war von Hand geschrieben —
 * `ValidationException::withMessages()` mit eigenem Satz. Eine Regel wie
 * `string`, `max` oder `array` formuliert Laravel selbst, und die griff erst,
 * als ein Formular an einer Regel scheiterte statt an einer Absage des Agenten.
 *
 * > **Eine Sprachvorgabe, die nur für selbstgeschriebene Sätze gilt, hält, bis
 * > der erste fremde Satz durchkommt.**
 *
 * ## Warum hier nicht alle Regeln stehen
 *
 * Laravel bringt über hundert mit. Hier stehen die, die dieses Panel benutzt —
 * und {@see \Tests\Feature\ValidationLanguageTest} zählt sie aus `app/` ab und
 * verlangt für jede eine Zeile. Eine vollständige Übersetzung wäre die
 * grössere Datei und die schlechtere Zusage: Neunzig Sätze, die nie jemand
 * liest, verdecken den einen, der fehlt.
 *
 * > **Eine Liste, die alles enthält, sagt nicht mehr, ob sie stimmt.**
 *
 * ## Was hier noch fehlt und benannt ist
 *
 * `attributes` bleibt vorerst leer. Ohne diese Zuordnung setzt Laravel den
 * Feldnamen ein, wie er im Code steht: „Das Feld content muss eine
 * Zeichenkette sein." Das ist besser als der englische Satz und noch nicht
 * richtig. Es sind **106** Feldnamen; sie bekommen ihre eigene Runde mit
 * eigenem Wächter, damit die Zuordnung vollständig ist und nicht halb.
 */

return [
    'after' => 'Das Feld :attribute muss ein Datum nach :date sein.',
    'alpha' => 'Das Feld :attribute darf nur Buchstaben enthalten.',
    'array' => 'Das Feld :attribute muss eine Liste sein.',
    'before' => 'Das Feld :attribute muss ein Datum vor :date sein.',
    'boolean' => 'Das Feld :attribute muss wahr oder falsch sein.',
    'confirmed' => 'Die Wiederholung von :attribute stimmt nicht überein.',
    'email' => 'Das Feld :attribute muss eine gültige E-Mail-Adresse sein.',
    'exists' => 'Der gewählte Wert für :attribute ist ungültig.',
    'file' => 'Das Feld :attribute muss eine Datei sein.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'integer' => 'Das Feld :attribute muss eine ganze Zahl sein.',
    'ip' => 'Das Feld :attribute muss eine gültige IP-Adresse sein.',
    'lowercase' => 'Das Feld :attribute darf nur Kleinbuchstaben enthalten.',

    /*
     * **Vier Fassungen je Grössenregel, und das ist keine Umständlichkeit.**
     * „darf nicht grösser als 4096 sein" stimmt für eine Zahl; für eine
     * Zeichenkette sind es Zeichen, für eine Datei Kilobyte und für eine Liste
     * Einträge. Laravel wählt anhand des geprüften Werts — eine Fassung für
     * alle wäre in drei von vier Fällen falsch.
     */
    'max' => [
        'array' => 'Das Feld :attribute darf höchstens :max Einträge haben.',
        'file' => 'Das Feld :attribute darf höchstens :max Kilobyte gross sein.',
        'numeric' => 'Das Feld :attribute darf höchstens :max sein.',
        'string' => 'Das Feld :attribute darf höchstens :max Zeichen lang sein.',
    ],

    'min' => [
        'array' => 'Das Feld :attribute muss mindestens :min Einträge haben.',
        'file' => 'Das Feld :attribute muss mindestens :min Kilobyte gross sein.',
        'numeric' => 'Das Feld :attribute muss mindestens :min sein.',
        'string' => 'Das Feld :attribute muss mindestens :min Zeichen lang sein.',
    ],

    'present' => 'Das Feld :attribute muss vorhanden sein.',
    'regex' => 'Das Feld :attribute hat ein ungültiges Format.',
    'required' => 'Das Feld :attribute ist erforderlich.',
    'required_unless' => 'Das Feld :attribute ist erforderlich, solange :other nicht :values ist.',
    'required_with' => 'Das Feld :attribute ist erforderlich, wenn :values vorhanden ist.',

    'size' => [
        'array' => 'Das Feld :attribute muss genau :size Einträge haben.',
        'file' => 'Das Feld :attribute muss genau :size Kilobyte gross sein.',
        'numeric' => 'Das Feld :attribute muss genau :size sein.',
        'string' => 'Das Feld :attribute muss genau :size Zeichen lang sein.',
    ],

    'string' => 'Das Feld :attribute muss eine Zeichenkette sein.',
    'timezone' => 'Das Feld :attribute muss eine gültige Zeitzone sein.',
    'uppercase' => 'Das Feld :attribute darf nur Grossbuchstaben enthalten.',
    'url' => 'Das Feld :attribute muss eine gültige Adresse sein.',

    /*
     * **Die Passwortregel ist ein Objekt und keine Zeichenkette** —
     * `App\Support\Passwords\Policy` setzt `Password::min(…)->letters()
     * ->mixedCase()->numbers()->symbols()`. Ihre Meldungen liegen unter diesem
     * Schlüssel; ohne ihn wären ausgerechnet die Sätze englisch, die beim
     * Anlegen jedes Kontos erscheinen.
     */
    'password' => [
        'letters' => 'Das Feld :attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => 'Das Feld :attribute muss mindestens einen Gross- und einen Kleinbuchstaben enthalten.',
        'numbers' => 'Das Feld :attribute muss mindestens eine Ziffer enthalten.',
        'symbols' => 'Das Feld :attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => 'Das Feld :attribute ist in einem bekannten Datenleck aufgetaucht. Bitte wählen Sie ein anderes.',
    ],

    /**
     * Feldnamen auf Deutsch — noch leer, und das ist benannt.
     *
     * Siehe den Kopf dieser Datei: 106 Feldnamen, die eine eigene Runde
     * bekommen. Solange hier nichts steht, setzt Laravel den Namen aus dem Code
     * ein.
     */
    /*
     * **Die Namen der Felder, wie sie in einer Meldung erscheinen.**
     *
     * Hier stand bis zum 20. August ein leeres Feld — und damit setzte Laravel
     * den **Bezeichner** ein. „Das Feld day of week ist erforderlich":
     * deutscher Satzbau, englische Wörter darin (`docs/64`, Befund 15).
     *
     * Die Bezeichner sind englisch, weil sie das sein sollen (`docs/19 §4a`).
     * Sie waren nie als Anzeige gedacht — und niemand hat je entschieden, dass
     * sie eine werden.
     *
     * > **Ein Wort, das erst beim Ausführen entsteht, steht in keiner Datei —
     * > und kein Wächter, der Dateien liest, findet es.**
     *
     * **Der Name ist der, der am Feld steht.** Wo die Oberfläche eine
     * Beschriftung hat, ist sie hier übernommen; „Straße und Hausnummer" heisst
     * in der Meldung genauso wie im Formular.
     *
     * **Und drei Namen bedeuten an zwei Orten Verschiedenes**, was diese Liste
     * allein nicht auflösen kann: `to` ist das Ziel einer Verschiebung und das
     * Ende eines Zeitraums, `host` ein Mailserver und die Herkunft eines
     * Datenbankzugangs, `mode` sind Dateirechte und die Art einer Änderung in
     * der Konsole. Hier steht der häufigere Fall; der andere übergibt seinen
     * Namen an Ort und Stelle als dritten Wert an `validate()`.
     *
     * > **Eine Liste, die einen Namen einmal vergibt, trägt keine zwei
     * > Bedeutungen — sie trägt die häufigere und einen Ausweg.**
     *
     * Vollständigkeit prüft `AttributeNameTest`: Jedes validierte Feld steht
     * hier, und jeder Eintrag hier wird validiert.
     */
    'attributes' => [
        'action' => 'Aktion',
        'active' => 'Aktiv',
        'algorithm' => 'Verfahren',
        'api_key' => 'API-Schlüssel',
        'api_password' => 'API-Passwort',
        'argument' => 'Argument',
        'certificate' => 'Zertifikat',
        'cidr' => 'Netz',
        'city' => 'Ort',
        'code' => 'Code',
        'collation' => 'Sortierung',
        'column' => 'Spalte',
        'command' => 'Befehl',
        'contact' => 'Kontaktadresse',
        'content' => 'Inhalt',
        'country' => 'Land',
        'current_password' => 'Aktuelles Passwort',
        'customer_id' => 'Kunde',
        'customer_number' => 'Kundennummer',
        'day_of_month' => 'Tag des Monats',
        'day_of_week' => 'Wochentag',
        'description' => 'Beschreibung',
        'direction' => 'Richtung',
        'directory' => 'Verzeichnis',
        'dump' => 'Sicherung',
        'email' => 'E-Mail-Adresse',
        'encryption' => 'Verschlüsselung',
        'engine' => 'Datenbanksystem',
        'files' => 'Dateien',
        'files.*' => 'Datei',
        'filter' => 'Filter',
        'filter.column' => 'Spalte des Filters',
        'filter.operator' => 'Vergleich des Filters',
        'filter.value' => 'Wert des Filters',
        'first_name' => 'Vorname',
        'from' => 'Von',
        'from_address' => 'Absenderadresse',
        'from_name' => 'Anzeigename des Absenders',
        'granted' => 'Rechte',
        'host' => 'Server',
        'hour' => 'Stunde',
        'ip' => 'IP-Adresse',
        'is_default' => 'Standardplan',
        'key' => 'Schlüssel',
        'key_name' => 'Schlüsselname',
        'label' => 'Bezeichnung',
        'last_name' => 'Nachname',
        'login_email' => 'Anmeldeadresse',
        'minute' => 'Minute',
        'mode' => 'Rechte',
        'month' => 'Monat',
        'name' => 'Name',
        'notes' => 'Notizen',
        'offset' => 'Versatz',
        'order' => 'Sortierung',
        'password' => 'Passwort',
        'password_clear' => 'Passwort im Klartext',
        'path' => 'Pfad',
        'paths' => 'Pfade',
        'paths.*' => 'Pfad',
        'phone' => 'Telefon',
        'plan_id' => 'Plan',
        'port' => 'Port',
        'postal_code' => 'Postleitzahl',
        'private_key' => 'Privater Schlüssel',
        'provider' => 'Anbieter',
        'query' => 'Suchbegriff',
        'remember' => 'Angemeldet bleiben',
        'result' => 'Ergebnis',
        'secret' => 'Geheimnis',
        'server' => 'Nameserver',
        'shared_secret' => 'Gemeinsames Geheimnis',
        'street' => 'Straße und Hausnummer',
        'subscription_id' => 'Abonnement',
        'table' => 'Tabelle',
        'target' => 'Ziel',
        'task' => 'Aufgabe',
        'theme' => 'Darstellung',
        'dns_addresses' => 'Eingetragene Adressen',
        'timezone' => 'Zeitzone',
        'to' => 'Ziel',
        'token' => 'Token',
        'user_label' => 'Benutzername',
        'username' => 'Benutzername',
        'values' => 'Werte',
        'zones' => 'Zonen',
    ],
];
