<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

final class Version
{
    /** Fassung des Agenten. Wird beim Paketbau ersetzt. */
    public const AGENT = '0.1.0-dev';

    /**
     * Fassung des Protokolls.
     *
     * Sie steigt nur, wenn sich die Bedeutung bestehender Felder ändert — neue
     * Operationen und neue optionale Felder ändern sie nicht. Anwendung und
     * Agent werden gemeinsam ausgeliefert; die Zahl ist trotzdem da, weil ein
     * Update genau zwischen dem Tausch der beiden abbrechen kann.
     */
    public const PROTOCOL = 1;
}
