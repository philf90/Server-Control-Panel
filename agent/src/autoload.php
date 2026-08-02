<?php

declare(strict_types=1);

/*
 * Eigener Autoloader statt Composer.
 *
 * Der Agent läuft als root. Was er lädt, soll vollständig aus diesem
 * Verzeichnis kommen und nicht aus einem vendor/-Baum, den ein Update der
 * Anwendung mitbewegt. Das ist der Grund für die zwölf Zeilen hier: Sie sind
 * die einzige Stelle, an der entschieden wird, welcher Code als root läuft.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'SrvPanel\\Agent\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $rest = substr($class, strlen($prefix));
    $file = __DIR__.'/'.str_replace('\\', '/', $rest).'.php';

    if (is_file($file)) {
        require $file;
    }
});
