<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ssh;

/**
 * Besitz und Rechte einer Verzeichniskette — die Prüfung, die `sshd -t` nicht macht.
 *
 * ## Warum es sie gibt
 *
 * `docs/50 §6` hat gemessen, dass OpenSSH die **ganze Kette oberhalb** der
 * Chroot-Wurzel prüft und dass der Klient den Grund **nicht erfährt**: Er
 * bekommt `Broken pipe`, und die Auskunft steht nur im Serverprotokoll. Ein
 * Betreiber, der das nicht weiss, sucht am falschen Ende — am Schlüssel, an der
 * Firewall, am Passwort.
 *
 * Und `docs/57 §8` hat gemessen, dass der Prüfer des Dienstes hier nichts
 * meldet: `ChrootDirectory` auf ein Verzeichnis, das es nicht gibt, auf eines
 * mit falschen Rechten, `AuthorizedKeysFile` auf eine fehlende Datei — jedes
 * Mal `sshd -t` mit `rc=0`.
 *
 * > **Was der Prüfer des Dienstes nicht sieht, muss das Panel sehen — sonst
 * > sieht es niemand, bis ein Kunde anruft.**
 *
 * ## Zwei Ketten und nicht eine
 *
 * Die Schlüsseldatei hängt an ihrer **eigenen** Kette, und die scheitert
 * *früher* — bei der Anmeldung statt beim Chroot — und mit anderem Wortlaut
 * (`docs/57 §9`). Gemessen an einem gruppenschreibbaren `/`: Der Server meldet
 * dann **gar nichts** über das Chroot.
 *
 * > **Eine Prüfung, die nur die eine Kette abläuft, meldet „alles in Ordnung",
 * > während niemand hereinkommt.**
 *
 * ## Und die Regel für die Schlüsseldatei ist unsere
 *
 * `StrictModes` fragt „gehört sie root **oder** dem Benutzer" — gemessen
 * (`docs/57 §10`): Eine Schlüsseldatei, die dem Kunden gehört, nimmt OpenSSH
 * an. Für diesen Zugang ist das zu wenig: Wem sie gehört, entscheidet, wer
 * Schlüssel hinzufügen kann, und das soll ausschliesslich der Agent sein.
 *
 * > **Was der Prüfling durchgehen lässt, ist keine Zusage darüber, wie es sein
 * > soll.**
 */
final class Chain
{
    /**
     * Die Kette zu einem Pfad, Glied für Glied, mit Urteil.
     *
     * **Von `/` abwärts und einschliesslich des Ziels.** Ein Glied fehlt nie
     * aus Versehen: Wer die Kette bei `/var` anfangen lässt, weil „`/` gehört ja
     * root", hat den Fall nicht gemessen, in dem es das nicht tut — und genau
     * der ist in `docs/57 §9` derjenige, bei dem der Server über das Chroot
     * schweigt.
     *
     * @return list<array{path: string, owner: string, group: string, mode: string, ok: bool, reason: string}>
     */
    public static function of(string $path): array
    {
        $glieder = [];

        foreach (self::components($path) as $glied) {
            $glieder[] = self::judge($glied);
        }

        return $glieder;
    }

    /**
     * Das erste Glied, das klemmt — oder `null`.
     *
     * Das ist die Antwort, die das Panel anzeigt: **welches Verzeichnis**, nicht
     * „irgendwo in der Kette".
     *
     * @param  list<array{path: string, owner: string, group: string, mode: string, ok: bool, reason: string}>  $chain
     * @return array{path: string, owner: string, group: string, mode: string, ok: bool, reason: string}|null
     */
    public static function firstProblem(array $chain): ?array
    {
        foreach ($chain as $glied) {
            if (! $glied['ok']) {
                return $glied;
            }
        }

        return null;
    }

    /**
     * Ein einzelnes Glied beurteilen.
     *
     * Die Regel ist die gemessene: **root muss es gehören, und für Gruppe oder
     * Andere darf es nicht schreibbar sein** (`st_mode & 022`). Alle vier
     * Abweichungen aus `docs/57 §9` fallen darunter, und jede hat hier ihren
     * eigenen Satz — „stimmt nicht" schickt jemanden dreimal in die falsche
     * Richtung.
     *
     * @return array{path: string, owner: string, group: string, mode: string, ok: bool, reason: string}
     */
    private static function judge(string $path): array
    {
        $stat = @stat($path);

        if (! is_array($stat)) {
            return [
                'path' => $path,
                'owner' => '?',
                'group' => '?',
                'mode' => '?',
                'ok' => false,
                'reason' => 'gibt es nicht',
            ];
        }

        $mode = $stat['mode'] & 0o7777;
        $owner = self::userName($stat['uid']);
        $group = self::groupName($stat['gid']);

        $gruende = [];

        if ($stat['uid'] !== 0) {
            $gruende[] = sprintf('gehört %s und nicht root', $owner);
        }

        /*
         * **Die beiden Schreibrechte sind ein Grund und nicht zwei.** Im
         * Abnahmelauf stand für ein `0777` auf der Seite „ist für die Gruppe
         * schreibbar **und ist** für alle schreibbar" (`docs/59`, Befund 9) —
         * zweimal dasselbe Prädikat, aneinandergehängt von einem `implode`, das
         * nichts über Sprache weiss.
         *
         * > **Eine Aufzählung, die aus zwei fertigen Sätzen entsteht, ist keiner.**
         */
        $wer = [];

        if (($mode & 0o020) !== 0) {
            $wer[] = 'für die Gruppe';
        }

        if (($mode & 0o002) !== 0) {
            $wer[] = 'für alle';
        }

        if ($wer !== []) {
            $gruende[] = 'ist '.implode(' und ', $wer).' schreibbar';
        }

        return [
            'path' => $path,
            'owner' => $owner,
            'group' => $group,
            'mode' => sprintf('%04o', $mode),
            'ok' => $gruende === [],
            /*
             * Komma und nicht `und`: Bei zwei Gründen trägt jeder schon ein
             * eigenes `und` („gehört p1136 **und** nicht root"), und ein drittes
             * dazwischen macht aus der Aufzählung eine Kette.
             */
            'reason' => implode(', ', $gruende),
        ];
    }

    /**
     * Die Glieder eines Pfades, von `/` bis zum Ziel.
     *
     * **Ohne `realpath()`.** Was hier interessiert, ist der Pfad, den OpenSSH
     * in seiner Konfiguration stehen hat — nicht der, auf den er nach dem
     * Auflösen von Symlinks zeigt. Wären die beiden verschieden, wäre *das* der
     * Befund und nicht etwas, das man stillschweigend wegrechnet.
     *
     * @return list<string>
     */
    public static function components(string $path): array
    {
        $teile = array_values(array_filter(explode('/', $path), static fn (string $t): bool => $t !== ''));
        $glieder = ['/'];
        $gebaut = '';

        foreach ($teile as $teil) {
            $gebaut .= '/'.$teil;
            $glieder[] = $gebaut;
        }

        return $glieder;
    }

    private static function userName(int $uid): string
    {
        $eintrag = posix_getpwuid($uid);

        return is_array($eintrag) ? (string) $eintrag['name'] : (string) $uid;
    }

    private static function groupName(int $gid): string
    {
        $eintrag = posix_getgrgid($gid);

        return is_array($eintrag) ? (string) $eintrag['name'] : (string) $gid;
    }
}
