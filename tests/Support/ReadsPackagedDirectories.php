<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\Unit\ChallengeReachTest;
use Tests\Unit\ServiceDirectoryTest;

/**
 * Was die Paketierung über Verzeichnisse sagt — aus beiden Quellen.
 *
 * **Warum das ein eigener Baustein ist.** Zwei Wächter stellen an dieselbe
 * Auslese zwei verschiedene Fragen: {@see ServiceDirectoryTest}
 * vergleicht sie mit den Absichten der systemd-Units,
 * {@see ChallengeReachTest} fragt, ob ein fremder Prozess bis zu
 * einem Verzeichnis durchkommt. Eine zweite Fassung des Ausdrucks wäre die,
 * die veraltet — und der Fehler, den dieses Projekt am häufigsten gemacht hat.
 *
 * `postinstall.sh` steht **hinter** `nfpm.yaml`: Es läuft später und ist damit
 * die Stelle, die im Zweifel gilt.
 */
trait ReadsPackagedDirectories
{
    /**
     * @return array<array-key,array{pfad: string, mode: string, owner: string, quelle: string}>
     */
    private function packagedDirectories(bool $alle = false): array
    {
        $gefunden = [];
        $liste = [];
        $nfpm = (string) file_get_contents($this->packagingRoot().'/packaging/nfpm.yaml');

        preg_match_all(
            '/-\s+dst:\s*(\S+)\s*\n\s*type:\s*dir\s*\n\s*file_info:\s*\n\s*mode:\s*(\S+)\s*\n\s*owner:\s*(\S+)/',
            $nfpm,
            $treffer,
            PREG_SET_ORDER,
        );

        foreach ($treffer as $t) {
            $gefunden[$t[1]] = $liste[] = [
                'pfad' => $t[1],
                'mode' => $this->normalise($t[2]),
                'owner' => $t[3],
                'quelle' => 'nfpm.yaml',
            ];
        }

        $postinst = $this->withoutHashComments(
            (string) file_get_contents($this->packagingRoot().'/packaging/scripts/postinstall.sh')
        );

        preg_match_all(
            '/install -d -o (\S+) -g \S+ -m (\S+) (\S+)/',
            $postinst,
            $treffer,
            PREG_SET_ORDER,
        );

        foreach ($treffer as $t) {
            $gefunden[$t[3]] = $liste[] = [
                'pfad' => $t[3],
                'mode' => $this->normalise($t[2]),
                'owner' => $t[1],
                'quelle' => 'postinstall.sh',
            ];
        }

        // `$alle` zählt beide Quellen einzeln; `$gefunden` ist die Sicht für
        // den Vergleich, in der postinstall.sh nfpm.yaml überschreibt.
        return $alle ? $liste : $gefunden;
    }

    /** `750` und `0750` sind dasselbe — verglichen wird vierstellig. */
    private function normalise(string $mode): string
    {
        return str_pad(ltrim($mode, '0') === '' ? '0' : ltrim($mode, '0'), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Eine Datei ohne ihre Rautenkommentare — für Shell und für Units.
     *
     * **Sonst zählt die Erklärung mit.** In den Units steht seit der Behebung
     * von `docs/67` Befund 1 wortwörtlich, welche Direktiven dort *nicht* mehr
     * stehen — und ein Ausdruck, der die Datei roh liest, findet genau die und
     * meldet den behobenen Fehler als bestehend.
     *
     * > **Ein Wächter, der eine Datei liest, liest auch, was jemand über sie
     * > geschrieben hat.**
     */
    private function withoutHashComments(string $quelle): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $quelle);
    }

    private function packagingRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
