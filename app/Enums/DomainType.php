<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Plans\Quota;

/**
 * Die vier Sorten Domain aus §5.1 des Plans.
 *
 * **Der Typ ist keine Beschriftung, er ist die Regel.** An ihm hängt, ob eine
 * Domain ein eigenes DocumentRoot hat, ob sie einen Elternteil braucht, ob
 * sie sich entfernen lässt und auf welches Kontingent sie zählt. Diese vier
 * Fragen standen im Entwurf als Bedingungen im Dienst; sie stehen hier, weil
 * ein `if ($type === 'alias')` an vier Orten vier Gelegenheiten sind, den
 * fünften zu vergessen.
 *
 * **Die Zählregeln sind eine Zusage, die schon geschrieben steht.**
 * {@see Quota::hint()} sagt dem Betreiber im Formular: „Zählt Haupt- und
 * Addon-Domains. Aliasse zählen nicht mit." Genau das steht in
 * {@see self::countsTowards()} — und ein Test hält beide aneinander.
 */
enum DomainType: string
{
    /**
     * Die eine Domain, unter der das Abonnement selbst erreichbar ist.
     *
     * Sie ist nicht entfernbar und liefert aus `httpdocs` aus (§4.5). Wer sie
     * loswerden will, löscht das Abonnement — alles andere hinterliesse ein
     * Abonnement mit einem Verzeichnisschema, dessen Wurzel niemand mehr
     * ausliefert.
     */
    case Main = 'main';

    /** Eine weitere eigenständige Domain im selben Abonnement. */
    case Addon = 'addon';

    /** Ein Name unterhalb einer Domain desselben Abonnements. */
    case Subdomain = 'subdomain';

    /**
     * Ein zweiter Name für dieselben Inhalte.
     *
     * Kein eigenes DocumentRoot, keine eigene PHP-Version: Der Alias steht im
     * `server_name` seiner Elterndomain. Deshalb zählt er auf kein
     * Kontingent — er kostet keine Ressource, die sich zuteilen liesse.
     */
    case Alias = 'alias';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Hauptdomain',
            self::Addon => 'Zusatzdomain',
            self::Subdomain => 'Subdomain',
            self::Alias => 'Alias',
        };
    }

    /**
     * Auf welches Kontingent diese Domain zählt — `null`, wenn auf keines.
     *
     * @see Quota::hint() Dort steht dieselbe Regel in Worten.
     */
    public function countsTowards(): ?Quota
    {
        return match ($this) {
            self::Main, self::Addon => Quota::Domains,
            self::Subdomain => Quota::Subdomains,
            self::Alias => null,
        };
    }

    /** Liefert diese Domain eigene Dateien aus — hat sie also ein DocumentRoot? */
    public function servesOwnContent(): bool
    {
        return $this !== self::Alias;
    }

    /** Braucht diese Domain eine Elterndomain im selben Abonnement? */
    public function requiresParent(): bool
    {
        return $this === self::Subdomain || $this === self::Alias;
    }

    /**
     * Muss der Name unterhalb der Elterndomain liegen?
     *
     * Für die Subdomain ja — `shop.beispiel.de` unter `beispiel.de`. Für den
     * Alias nicht: `beispiel.at` als zweiter Name für `beispiel.de` ist genau
     * der Zweck der Sorte.
     */
    public function requiresNameBelowParent(): bool
    {
        return $this === self::Subdomain;
    }

    /** Lässt sich diese Domain einzeln entfernen? */
    public function removable(): bool
    {
        return $this !== self::Main;
    }

    /**
     * Die Sorten, die ein Kunde selbst anlegen kann.
     *
     * Die Hauptdomain fehlt: Sie entsteht mit dem Abonnement und ist keine
     * Wahl, die jemand trifft.
     *
     * @return list<self>
     */
    public static function creatable(): array
    {
        return [self::Addon, self::Subdomain, self::Alias];
    }
}
