<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;

/**
 * Die Zertifizierungsstellen, mit denen dieser Agent spricht — als
 * Positivliste.
 *
 * **Das Panel nennt einen Schlüssel, keine Adresse.** Dieselbe Entscheidung
 * wie im Aufgabenkatalog des Panels: Nähme der Agent eine URL entgegen, wäre
 * die Anwendung eine Fernsteuerung dafür, wohin ein Prozess als root eine
 * TLS-Verbindung aufbaut und wem er den Fingerabdruck seines Kontoschlüssels
 * zeigt. Die Prüfung „fängt mit https an" ist dabei keine Schranke, sondern
 * eine Formalie.
 *
 * Hier stehen zwei Einträge, und sie sind vollständig. Wer eine dritte
 * Zertifizierungsstelle braucht, ändert diese Datei — eine Änderung am Code,
 * die jemand liest, kein Feld in einem Formular.
 */
final class Directories
{
    /** Der Testbetrieb. Er stellt Zertifikate aus, denen kein Browser traut. */
    public const STAGING = 'staging';

    public const PRODUCTION = 'production';

    /** @var array<string, string> */
    private const URLS = [
        self::STAGING => 'https://acme-staging-v02.api.letsencrypt.org/directory',
        self::PRODUCTION => 'https://acme-v02.api.letsencrypt.org/directory',
    ];

    /**
     * Wie sich eine Zertifizierungsstelle in einem CAA-Satz nennt.
     *
     * **Sie steht neben ihrer Adresse und nicht in einer zweiten Liste.** Wer
     * eine dritte Stelle einträgt, muss ihre Kennung mit nennen — sonst hätte
     * `dns.check` eine Zeichenkette, die auf nichts zeigt, und der CAA-Hinweis
     * meldete stillschweigend „darf nicht", weil er den Namen nicht kennt.
     *
     * **Testbetrieb und Produktivbetrieb tragen dieselbe Kennung.** Let's
     * Encrypt unterscheidet in CAA nicht zwischen beiden; wer das anders
     * einträgt, bekommt im Testbetrieb einen Hinweis, den es im Ernstfall
     * nicht gibt — und gewöhnt sich an, ihn zu übergehen.
     *
     * @var array<string, string>
     */
    private const CAA = [
        self::STAGING => 'letsencrypt.org',
        self::PRODUCTION => 'letsencrypt.org',
    ];

    /**
     * Die CAA-Kennung zu einem Schlüssel.
     *
     * Unbekannt heisst `null` und nicht „leer": Eine leere Kennung wäre in
     * einem CAA-Satz die Aussage „niemand darf", und das ist etwas ganz
     * anderes als „wir wissen es nicht".
     */
    public static function caa(mixed $key): ?string
    {
        return is_string($key) && isset(self::CAA[$key]) ? self::CAA[$key] : null;
    }

    /**
     * Die Adresse zu einem Schlüssel.
     *
     * **Der Testbetrieb ist die Vorgabe, und zwar bis jemand ausdrücklich
     * etwas anderes sagt.** Let's Encrypt begrenzt produktiv hart — unter
     * anderem fünf Fehlversuche je Konto und Stunde. Ein fehlender oder
     * vertippter Wert, der still produktiv landet, ist der Weg in eine Sperre,
     * die Stunden hält.
     */
    public static function url(mixed $key): string
    {
        if ($key === null) {
            return self::URLS[self::STAGING];
        }

        if (! is_string($key) || ! isset(self::URLS[$key])) {
            throw AgentException::badRequest(
                'Unbekannte Zertifizierungsstelle.',
                ['directory' => $key, 'known' => array_keys(self::URLS)],
            );
        }

        return self::URLS[$key];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::URLS);
    }
}
