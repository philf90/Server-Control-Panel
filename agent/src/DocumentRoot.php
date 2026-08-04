<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Was ein DocumentRoot innerhalb eines Abonnements sein darf.
 *
 * **Die Regel stand zuerst im Panel und war damit am falschen Ort.** Sie
 * gehört dorthin, wo aus ihr ein Pfad wird — und das ist der Agent. Das Panel
 * fragt jetzt diese Klasse, so wie es bei {@see DomainName} und bei
 * {@see SubscriptionProvision::subscriptionName()} schon der Fall ist. Zwei
 * Formulierungen derselben Regel wären zwei Gelegenheiten, sie
 * auseinanderlaufen zu lassen; welche der beiden beim nächsten Mal nachgezogen
 * wird, ist erfahrungsgemäss nicht die im Agenten.
 *
 * **Relativ, immer.** Übergeben wird `httpdocs` oder `beispiel.de/public`,
 * niemals `/var/www/vhosts/…`. Den absoluten Pfad baut {@see Site::root()} aus
 * dem Namen des Abonnements — dieselbe Entscheidung wie in
 * `subscription.provision`, und sie ist der Grund, warum es dort keinen
 * Pfadausbruch gibt: Was nie übergeben wird, muss nicht geprüft werden.
 *
 * Vier Bedingungen, und jede hat einen Fall hinter sich:
 *
 * 1. **Kein führender Schrägstrich.** Sonst wäre es ein absoluter Pfad.
 * 2. **Kein Bestandteil beginnt mit einem Punkt.** Damit sind `..` und `.ssh`
 *    ausgeschlossen — der Ausbruch nach oben und das Verzeichnis mit den
 *    Schlüsseln.
 * 3. **Kein reserviertes Verzeichnis des Schemas** (§4.5). Ein DocumentRoot
 *    auf `logs` liefert die Zugriffsprotokolle des Kunden über HTTP aus, eines
 *    auf `conf` die erzeugten Includes.
 * 4. **Begrenzte Tiefe und Länge.** Acht Ebenen sind mehr, als ein
 *    DocumentRoot braucht; alles darüber ist ein Vertipper oder ein Versuch.
 */
final class DocumentRoot
{
    public const MAX_DEPTH = 8;

    public const MAX_LENGTH = 255;

    /** Prüft den relativen Pfad und gibt ihn zurück. */
    public static function normalize(mixed $value, string $field = 'document_root'): string
    {
        $path = Guard::string($value, $field);

        if (! self::valid($path)) {
            throw AgentException::badRequest('Unzulässiges DocumentRoot.', [$field => $path]);
        }

        return $path;
    }

    /** Dieselbe Frage ohne Ausnahme — für das Formular im Panel. */
    public static function valid(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_LENGTH) {
            return false;
        }

        if (str_starts_with($path, '/') || str_ends_with($path, '/') || str_contains($path, '//')) {
            return false;
        }

        $segments = explode('/', $path);

        if (count($segments) > self::MAX_DEPTH) {
            return false;
        }

        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]*$/D', $segment)) {
                return false;
            }
        }

        return ! in_array($segments[0], SubscriptionProvision::reservedDirectories(), true);
    }

    /**
     * Das Verzeichnis, das ausgeliefert wird, wenn niemand etwas anderes sagt.
     *
     * Für die Hauptdomain `httpdocs` — das Verzeichnis, das
     * `subscription.provision` anlegt. Für jede weitere Domain ein Verzeichnis
     * mit ihrem eigenen Namen; §4.5 sieht `<weitere-domain>/` genau dafür vor.
     */
    public static function forDomain(string $domain, bool $isMain): string
    {
        return $isMain ? SubscriptionProvision::DOCUMENT_ROOT : $domain;
    }
}
