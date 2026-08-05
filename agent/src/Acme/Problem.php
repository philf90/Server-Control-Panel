<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

/**
 * Der Fehler der Gegenseite, übersetzt in einen Satz.
 *
 * ACME antwortet auf einen Fehler mit `application/problem+json` nach RFC 7807:
 * ein Typ als URN, dazu ein englischer Freitext. Der Typ ist die Angabe, auf
 * die sich verlassen lässt — der Freitext ändert sich, wenn die
 * Zertifizierungsstelle ihn umformuliert.
 *
 * **Warum die Übersetzung hier steht und nicht in der Oberfläche.** Ein
 * Betreiber, der `urn:ietf:params:acme:error:connection` liest, weiss nicht,
 * was er tun soll; „die Prüfstelle konnte diesen Server nicht erreichen"
 * beantwortet die Frage, mit der er hierhergekommen ist. Der englische Freitext
 * bleibt angehängt, weil er oft die Adresse nennt, an der es scheiterte.
 */
final class Problem
{
    public const PREFIX = 'urn:ietf:params:acme:error:';

    /**
     * Die Fehler, die im Betrieb tatsächlich vorkommen.
     *
     * Was nicht in dieser Liste steht, wird nicht verschwiegen — es kommt mit
     * seinem Kurznamen durch. Eine Liste, die vollständig sein will, wäre eine,
     * die beim nächsten neuen Fehlertyp still das Falsche sagt.
     */
    private const REASONS = [
        'badNonce' => 'Die Zertifizierungsstelle hat einen verbrauchten Einmalwert gemeldet.',
        'rateLimited' => 'Die Zertifizierungsstelle hat die Anfragen begrenzt. Was jetzt hilft, ist Abwarten und kein zweiter Anlauf.',
        'connection' => 'Die Prüfstelle konnte diesen Server nicht erreichen. Zeigt der Name hierher, und ist Port 80 offen?',
        'dns' => 'Der Name liess sich nicht auflösen.',
        'caa' => 'Ein CAA-Eintrag der Domain verbietet dieser Zertifizierungsstelle das Ausstellen.',
        'unauthorized' => 'Die Prüfung ist fehlgeschlagen — was unter der Prüfadresse steht, passt nicht.',
        'malformed' => 'Die Zertifizierungsstelle hat die Anfrage nicht verstanden. Das ist ein Fehler im Panel.',
        'orderNotReady' => 'Die Bestellung ist noch nicht so weit — es fehlt eine bestandene Prüfung.',
        'accountDoesNotExist' => 'Das ACME-Konto ist der Zertifizierungsstelle unbekannt.',
        'userActionRequired' => 'Die Zertifizierungsstelle verlangt eine Zustimmung, die noch nicht vorliegt.',
        'serverInternal' => 'Die Zertifizierungsstelle hat einen eigenen Fehler gemeldet.',
    ];

    private function __construct(
        public readonly string $type,
        public readonly string $detail,
    ) {}

    /**
     * Aus einer Antwort — oder `null`, wenn sie keinen Fehler beschreibt.
     *
     * Der `Content-Type` entscheidet und nicht der Status: Ein Fehler ohne
     * `problem+json` ist ein Ausfall der Gegenstelle und kein ACME-Fehler, und
     * ihn als solchen auszugeben hiesse, einen Typ zu erfinden.
     */
    public static function from(Response $response): ?self
    {
        if (! str_contains($response->header('content-type') ?? '', 'application/problem+json')) {
            return null;
        }

        $fields = $response->json();
        $type = $fields['type'] ?? null;
        $detail = $fields['detail'] ?? null;

        return new self(
            is_string($type) ? $type : 'unbekannt',
            is_string($detail) ? $detail : '',
        );
    }

    public function isBadNonce(): bool
    {
        return $this->type === self::PREFIX.'badNonce';
    }

    public function isRateLimited(): bool
    {
        return $this->type === self::PREFIX.'rateLimited';
    }

    public function message(): string
    {
        $short = str_starts_with($this->type, self::PREFIX)
            ? substr($this->type, strlen(self::PREFIX))
            : $this->type;

        $reason = self::REASONS[$short] ?? sprintf('Die Zertifizierungsstelle lehnte ab (%s).', $short);

        return $this->detail === '' ? $reason : $reason.' — '.$this->detail;
    }
}
