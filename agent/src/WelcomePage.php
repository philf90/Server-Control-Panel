<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die Seite, die eine eingerichtete Domain zeigt, solange nichts darin liegt.
 *
 * **Warum sie ab P4 hier steht und nicht mehr in `subscription.provision`.**
 * Dort entstand sie für das erste DocumentRoot eines Abonnements — und nur
 * dafür. Jede weitere Domain bekam ein leeres Verzeichnis, und nginx
 * antwortete darauf mit „403 Forbidden". Das ist dieselbe falsche Auskunft wie
 * bei der Sperre, die zuerst 403 statt 503 gab: „du darfst nicht" statt „hier
 * ist noch nichts". Aufgefallen ist es im Abnahmelauf für P4, an einer Domain,
 * die gerade ein gültiges Zertifikat bekommen hatte.
 *
 * **Geschrieben wird nur in ein leeres Verzeichnis.** Das ist keine Vorsicht,
 * sondern die Bedingung dafür, dass die aufrufenden Operationen wiederholbar
 * bleiben dürfen: Ein zweiter Lauf — nach einem abgebrochenen Vorgang, nach
 * einer Kontingentänderung, nach einem Umzug — träfe sonst auf eine fertige
 * Webseite und legte eine `index.html` daneben, die vor `index.php` gefunden
 * wird. Der Kunde sähe statt seiner Seite wieder den Platzhalter, und niemand
 * käme auf den Gedanken, dass das Panel das war.
 *
 * Geprüft wird das ganze Verzeichnis und nicht nur die Datei: Wer seine
 * `index.html` gelöscht hat und mit `index.php` arbeitet, hat damit eine
 * Entscheidung getroffen.
 */
final class WelcomePage
{
    /**
     * Die Seite in dieses DocumentRoot legen, wenn es leer ist.
     *
     * @return bool Wurde sie in diesem Lauf geschrieben?
     */
    public static function into(string $documentRoot, string $user): bool
    {
        $entries = @scandir($documentRoot);

        if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
            return false;
        }

        $path = $documentRoot.'/index.html';

        if (@file_put_contents($path, self::html(basename($documentRoot))) === false) {
            return false;
        }

        // Lesbar für den Webserver, schreibbar für den Kunden — dieselbe
        // Aufteilung wie beim Verzeichnis darüber.
        chown($path, $user);
        chgrp($path, posix_getgrnam('www-data') !== false ? 'www-data' : $user);
        chmod($path, 0o640);

        return true;
    }

    /**
     * Der Inhalt.
     *
     * **Er nennt weder den Abonnementnamen noch den Systembenutzer noch das
     * Panel.** Sobald eine Domain hierher zeigt, ist sie öffentlich, und was
     * öffentlich ist, sollte über den Server nichts erzählen: Ein Platzhalter,
     * auf dem „Abonnement kunde-example.de, Systembenutzer p1003" steht, ist
     * eine Einladung, in der Suchmaschine nach weiteren zu suchen. Wer die
     * Seite sieht, weiss ohnehin, wessen Domain er aufgerufen hat.
     *
     * **Das Verzeichnis steht als Angabe da und nicht als Wort im Text.** Es
     * hiess `httpdocs`, solange nur das erste DocumentRoot eine Seite bekam;
     * eine zweite Domain liegt woanders, und ein Hinweis auf ein Verzeichnis,
     * das es für sie nicht gibt, ist schlechter als keiner.
     *
     * Alles in einer Datei: keine Schrift, kein Bild, kein Stylesheet von
     * aussen. Ein Platzhalter, der beim ersten Aufruf eine fremde Adresse
     * kontaktiert, ist ein Platzhalter, der etwas verrät.
     */
    public static function html(string $directory): string
    {
        $name = htmlspecialchars($directory, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="de">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>Diese Domain ist eingerichtet</title>
            <style>
            :root { color-scheme: light dark; }
            body {
              margin: 0; min-height: 100vh;
              display: grid; place-items: center;
              padding: 24px;
              font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
              line-height: 1.55;
            }
            main { max-width: 34rem; }
            h1 { margin: 0 0 12px; font-size: 1.35rem; font-weight: 600; }
            p { margin: 0 0 10px; }
            .leise { opacity: .7; font-size: .9rem; }
            </style>
            </head>
            <body>
            <main>
            <h1>Diese Domain ist eingerichtet</h1>
            <p>
              Der Webspace steht bereit und liefert aus — es liegen nur noch
              keine Inhalte darin.
            </p>
            <p>
              Wer hier Inhalte ablegen möchte, findet den Zugang in seinen
              Vertragsunterlagen. Die Dateien gehören in das Verzeichnis
              <code>{$name}</code>; sobald dort eine eigene Startseite liegt,
              verschwindet diese Seite.
            </p>
            <p class="leise">
              Diese Seite wurde beim Einrichten des Webspace erzeugt.
            </p>
            </main>
            </body>
            </html>
            HTML;
    }
}
