<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Files;

use Socket;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Sandbox;

/**
 * Der Arbeitsbereich eines Abonnements: seine Wurzel, sein Benutzer, seine Pfade.
 *
 * **Hier steht der Satz, mit dem P6 vom bisherigen Muster abweicht.** Bis P5c
 * nahm keine Operation einen Pfad entgegen — sie baute ihn. Diese hier nimmt
 * einen, und das ist zulässig, weil er nicht mehr *geprüft*, sondern
 * *eingesperrt* wird: Jeder Pfad wird innerhalb eines Chroots auf die Wurzel des
 * Abonnements gedeutet ({@see Sandbox}), und dort kann kein Pfad etwas
 * ausserhalb bezeichnen.
 *
 * **Was die Prüfung hier deshalb ist und was nicht.** Sie ist keine Schranke.
 * Ein `..` zu viel wäre harmlos — im Chroot führt es nirgendwohin. Sie steht
 * da, damit die Oberfläche vernünftige Pfade anzeigt und ein Tippfehler eine
 * Meldung bekommt statt eines überraschenden Ergebnisses. Wer sie für die
 * Sicherheit hält, baut die nächste Fassung ohne Sandbox.
 *
 * > **Eine Prüfung, die neben einer Schranke steht, wird für die Schranke
 * > gehalten.** Deshalb steht es hier im Kopf und nicht in einer Fussnote.
 *
 * Die einzige Ausnahme ist das Nullbyte: PHP schneidet einen Pfad daran ab, und
 * ein abgeschnittener Pfad bezeichnet etwas anderes als der eingegebene. Das
 * ist kein Anzeigeproblem, sondern eines der Deutung, und es wird abgewiesen.
 */
final class Workspace
{
    /**
     * Wie tief ein Pfad geschachtelt sein darf.
     *
     * Nicht aus Sorge um das Dateisystem, sondern um den rekursiven Abstieg im
     * Kind: Ein Baum, den jemand tausendfach schachtelt, bringt sonst den
     * Stapel zum Überlaufen — und ein Kind, das an einem Stapelüberlauf stirbt,
     * meldet keinen Fehler, sondern ein Signal.
     */
    public const MAX_DEPTH = 64;

    private function __construct(
        public readonly string $root,
        public readonly string $user,
    ) {}

    /**
     * Aus den Argumenten einer Operation.
     *
     * `subscription` ist der **Name** des Abonnements und nicht sein Pfad — die
     * Wurzel entsteht hier, aus derselben Konstante wie in
     * {@see SubscriptionProvision}. Das ist der Teil des alten Musters, der
     * bleibt: Was gebaut werden kann, wird gebaut.
     *
     * @param  array<string,mixed>  $args
     */
    public static function fromArgs(array $args): self
    {
        $name = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);

        return new self(SubscriptionProvision::VHOSTS.'/'.$name, $user);
    }

    /**
     * Ein Pfad, wie ihn das Kind sieht.
     *
     * Er kommt vom Kunden und ist relativ zur Wurzel des Abonnements — was im
     * Chroot heisst: Er *ist* der absolute Pfad. Es gibt hier nichts
     * umzurechnen, und das ist der ganze Gewinn dieser Bauweise.
     *
     * Normalisiert wird trotzdem: `.` und `..` fliegen heraus, doppelte
     * Schrägstriche auch. Nicht der Sicherheit wegen — im Chroot führt `..` an
     * der Wurzel auf die Wurzel zurück —, sondern damit die Oberfläche für
     * dasselbe Verzeichnis nicht zwei Schreibweisen kennt.
     */
    public static function path(mixed $value, string $field = 'path'): string
    {
        $raw = Guard::string($value, $field);

        if (str_contains($raw, "\0")) {
            throw AgentException::badRequest('Ein Pfad mit Nullbyte bezeichnet nicht, was er zeigt.', [$field => $raw]);
        }

        $parts = [];

        foreach (explode('/', $raw) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        if (count($parts) > self::MAX_DEPTH) {
            throw AgentException::badRequest('Der Pfad ist tiefer verschachtelt als erlaubt.', [
                $field => $raw,
                'max_depth' => self::MAX_DEPTH,
            ]);
        }

        return '/'.implode('/', $parts);
    }

    /**
     * Die Arbeit im Chroot ausführen, ohne Rechte.
     *
     * @param  callable():mixed  $work
     * @param  list<Socket|resource>  $close
     */
    public function run(callable $work, array $close = []): mixed
    {
        return Sandbox::run($this->root, $this->user, $work, $close);
    }
}
