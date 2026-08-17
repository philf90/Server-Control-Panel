<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Ssh\Chain;
use SrvPanel\Agent\Ssh\SshdConfig;

/**
 * Warum der Zugang klemmt — die Auskunft, die der Klient nicht bekommt.
 *
 * ## Was diese Operation beantwortet
 *
 * Gemessen (`docs/50 §6`): Der Klient bekommt `Broken pipe`, und der Grund
 * steht ausschliesslich im Serverprotokoll. Gemessen (`docs/57 §8`): `sshd -t`
 * sieht davon nichts — ein fehlendes Chroot-Verzeichnis, falsche Rechte, eine
 * fehlende Schlüsseldatei sind für ihn alle `rc=0`.
 *
 * > **Was der Prüfer des Dienstes nicht sieht, muss das Panel sehen — sonst
 * > sieht es niemand, bis ein Kunde anruft.**
 *
 * ## Drei Fragen, und jede an ihre eigene Quelle
 *
 * 1. **Die Kette des Chroots** — Besitz und Rechte von `/` bis zur Abo-Wurzel.
 * 2. **Die Kette der Schlüsseldatei** — sie ist eine *zweite* und scheitert
 *    früher, bei der Anmeldung statt beim Chroot (`docs/57 §9`). Bei
 *    gruppenschreibbarem `/` meldet der Server gar nichts über das Chroot; eine
 *    Prüfung, die nur die erste Kette abliefe, sagte „alles in Ordnung",
 *    während niemand hereinkommt.
 * 3. **Was wirklich gilt** — `sshd -T -C user=…`, also dieselbe Quelle, aus der
 *    sshd es nimmt. Der erste passende `Match`-Block gewinnt (`docs/57 §7`), und
 *    ein Eintrag des Betreibers weiter oben schlägt unseren.
 *    > **Ein Block, den man geschrieben hat, ist keine Auskunft darüber, was
 *    > gilt.**
 *
 * ## Und „keine Schlüsseldatei" ist ein Zustand und kein Defekt
 *
 * Gemessen (`docs/57 §9`): Fehlt sie, meldet der Server nur `Failed publickey`
 * — ohne jede Begründung, und für den Kunden sieht das aus wie ein kaputter
 * Server. Es ist der Zustand „Zugang aus", und er wird hier benannt.
 */
final class SftpCheck implements Op
{
    public static function name(): string
    {
        return 'sftp.check';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);
        $name = SubscriptionProvision::subscriptionName($args['name'] ?? null);

        $root = SubscriptionProvision::VHOSTS.'/'.$name;
        $keyFile = SshdConfig::keyFile($user);

        /*
         * **Was gilt, wird zuerst gefragt — die Kette hängt daran.**
         * Im Abnahmelauf stand auf der Seite „Verzeichnis und Rechte in
         * Ordnung", während `sshd -T` `/var/www` nannte (`docs/59`, Befund 10):
         * Beurteilt worden war die Wurzel des Abonnements, in Betrieb war eine
         * andere. Der Satz war über das falsche Verzeichnis wahr.
         *
         * > **Eine Kette, die am Sollzustand hängt, sagt nichts über den
         * > Zugang, der gerade nicht ihm folgt.**
         */
        $context->progress(40, 'nachsehen, was gilt');
        $effective = $this->effective($context, $user);

        $checked = self::applied($root, $effective);

        $context->progress(70, 'Kette prüfen');
        $chroot = Chain::of($checked);
        $keys = Chain::of($keyFile);

        $context->progress(100, 'fertig');

        return [
            'user' => $user,
            'root' => $root,
            'key_file' => $keyFile,

            /*
             * Welches Verzeichnis beurteilt worden ist. Es steht getrennt von
             * `root` da, weil die beiden auseinanderfallen können — und wer sie
             * zusammenwirft, baut den Befund von oben wieder ein.
             */
            'checked_root' => $checked,

            'chroot_chain' => $chroot,
            'chroot_problem' => Chain::firstProblem($chroot),

            /*
             * **Die zweite Kette steht getrennt da und nicht angehängt.** Sie
             * scheitert an einer anderen Stelle des Anmeldevorgangs und mit
             * einem anderen Wortlaut; zusammengeworfen läse sich der Befund als
             * „irgendwo in der Kette", und genau das ist die Auskunft, die den
             * Betreiber suchen schickt.
             */
            'key_chain' => $keys,
            'key_problem' => Chain::firstProblem($keys),
            'has_keys' => is_file($keyFile),

            'effective' => $effective,
        ];
    }

    /**
     * Das Verzeichnis, das wirklich gilt — oder die Wurzel des Abonnements.
     *
     * **Drei Fälle, und nur einer ist ein anderes Verzeichnis.** `sshd -T`
     * schreibt `none`, wenn nichts gesetzt ist; das ist die Abwesenheit einer
     * Angabe und keine andere. Und OpenSSH lässt in `ChrootDirectory` die Marken
     * `%h`, `%u` und `%%` zu, die `sshd -T` **unaufgelöst** ausgibt — eine
     * Kettenprüfung auf `%h/sftp` meldete „gibt es nicht" und wäre damit eine
     * falsche Aussage statt einer fehlenden.
     *
     * > **Ein Pfad mit einer Marke darin ist kein Pfad, und ein Urteil darüber
     * > ist keines.**
     *
     * **Als reine Funktion**, damit ein Wächter sie ohne Server liest —
     * dieselbe Bauart wie {@see SshdConfig::render()}.
     *
     * @param  array<string,string>  $effective
     */
    public static function applied(string $root, array $effective): string
    {
        $wirksam = $effective['chrootdirectory'] ?? '';

        if ($wirksam === '' || $wirksam === 'none') {
            return $root;
        }

        if (! str_starts_with($wirksam, '/') || str_contains($wirksam, '%')) {
            return $root;
        }

        return $wirksam;
    }

    /**
     * Die wirksame Konfiguration für diesen Benutzer.
     *
     * Gelesen werden nur die vier Angaben, an denen dieser Zugang hängt — der
     * ganze Ausdruck von `sshd -T` sind über hundert Zeilen, und eine Auskunft,
     * die alles zeigt, zeigt nichts.
     *
     * @return array<string,string>
     */
    private function effective(Context $context, string $user): array
    {
        $result = $context->runner->run('sshd', [
            '-T', '-C', 'user='.$user.',host=127.0.0.1,addr=127.0.0.1',
        ], 30);

        $gesucht = ['chrootdirectory', 'forcecommand', 'authorizedkeysfile', 'permitrootlogin'];
        $gefunden = [];

        if (! $result->successful()) {
            /*
             * **Ein Fehler hier ist selbst die Auskunft.** `sshd -T` scheitert
             * genau dann, wenn die Datei nicht lesbar ist — und das ist der
             * Zustand, in dem der nächste Neustart den Zugang zum Server
             * kostet. Er gehört gemeldet und nicht in ein leeres Ergebnis
             * verwandelt; `docs/44` hat vorgeführt, was ein
             * `catch { return []; }` an dieser Stelle anrichtet.
             */
            return ['error' => trim($result->message())];
        }

        foreach ($result->lines() as $line) {
            foreach ($gesucht as $schlüssel) {
                if (str_starts_with($line, $schlüssel.' ')) {
                    $gefunden[$schlüssel] = trim(substr($line, strlen($schlüssel) + 1));
                }
            }
        }

        return $gefunden;
    }
}
