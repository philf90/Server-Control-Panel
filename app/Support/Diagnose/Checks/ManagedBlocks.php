<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\FindingCheck;
use App\Support\Databases\RemoteAccess;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Files\Sftp;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Ops\SystemDiagnose;
use SrvPanel\Agent\Ssh\SshdConfig;

/**
 * C · Die verwalteten Bereiche — Form **und** Inhalt (`docs/98 §3 C`).
 *
 * ## Warum Form und Inhalt in einer Klasse stehen
 *
 * Nicht aus Bequemlichkeit, sondern weil {@see FindingLog::replace()} **alle**
 * Zeilen einer Prüfung ersetzt: Was der Lauf nicht mehr nennt, ist behoben und
 * wird gelöscht. Zwei Klassen, die beide `block.integrity` schrieben, löschten
 * einander die Befunde weg — die zweite den Fund der ersten, jede Nacht, und
 * welche zuletzt liefe, entschiede der Zufall der Reihenfolge.
 *
 * > **Eine Prüfung hat genau einen Schreiber.** Sie darf mehrere Quellen haben.
 *
 * Die Form beantwortet der Agent ({@see SystemDiagnose}): steht das Markenpaar
 * vollständig da, steht es genau einmal da. Den Inhalt beantwortet diese
 * Klasse, weil nur das Panel den Sollzustand kennt.
 *
 * ## Der Fund, für den es diese Prüfung gibt
 *
 * Gemessen in der Messrunde (`docs/81 §2.3o` M16): Eine fremde Zeile
 * **innerhalb** der Marken kommt heute als unsere zurück. Ein
 * `host all all 0.0.0.0/0 trust` in `pg_hba.conf` läse sich als Zeile des
 * Panels — und öffnete jede Datenbank dieses Servers für jeden.
 *
 * ## Zwei Dateien, zwei Sollzustände, dieselbe Frage
 *
 * Für `pg_hba.conf` gibt es die Maschinerie seit P5b:
 * {@see RemoteAccess::orphans()} und {@see RemoteAccess::missing()} — dieselben
 * Methoden, die `srvpanel db` benutzt. Eine eigene Abfrage hier wäre die zweite
 * Fassung derselben Regel.
 *
 * Für `sshd_config` baut {@see SshdConfig::lines()} aus den Zugängen genau die
 * Zeilen, die `sftp.access` schreiben würde. Verglichen wird also das Erzeugte
 * mit dem Dastehenden — und nicht eine nachgebaute Erwartung mit der Datei.
 *
 * > **Ein Sollzustand, den man für den Vergleich neu formuliert, ist eine
 * > zweite Fassung — und die zweite ist die, die veraltet.**
 *
 * ## Was sie nicht sagt
 *
 * Nichts über die Datei **ausserhalb** der Marken. Dort steht der Bestand des
 * Betreibers, und der ist Gesetz (`docs/98 §3 C`).
 */
final class ManagedBlocks implements Check
{
    /**
     * Die Gründe, die diese Prüfung ausspricht — die des Agenten eingeschlossen.
     *
     * Sie reicht die Befunde zur **Form** durch, statt sie noch einmal zu
     * fällen; ausgesprochen hat sie sie damit trotzdem, denn sie ist es, die
     * sie schreibt.
     */
    public const REASONS = [
        'block.integrity' => [
            'begin_without_end', 'end_without_begin', 'duplicate_block',
            'block_missing', 'foreign_line', 'line_missing',
            FindingCheck::UNREACHABLE,
        ],
    ];

    public function __construct(
        private readonly Client $agent,
        private readonly RemoteAccess $remote,
        private readonly Sftp $sftp,
    ) {}

    public function writes(): array
    {
        return [FindingCheck::BlockIntegrity];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        try {
            $answer = $this->agent->call('system.diagnose', ['checks' => ['block.integrity']]);
        } catch (AgentException $e) {
            // Ohne Agent ist über **keine** der beiden Dateien etwas bekannt —
            // auch nicht über die, die das Panel selbst lesen könnte. Der Ort
            // bleibt der, um den es ging.
            $log->unreachable(FindingCheck::BlockIntegrity, [SshdConfig::FILE, 'pg_hba.conf'], $measuredAt, $e->getMessage());

            return;
        }

        $form = $answer['checks']['block.integrity'] ?? [];
        $findings = is_array($form) ? array_values($form) : [];

        foreach (is_array($answer['managed'] ?? null) ? $answer['managed'] : [] as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                continue;
            }

            foreach ($this->drift($file) as $finding) {
                $findings[] = $finding;
            }
        }

        $log->replace(FindingCheck::BlockIntegrity, $findings, $measuredAt);
    }

    /**
     * Was in einer Datei zuviel steht und was fehlt — je nach Rolle gefragt.
     *
     * Diese Methode wählt den Sollzustand; das Urteil fällt {@see self::judge()}.
     * Getrennt, weil das eine den Bestand braucht und das andere nicht: So hat
     * die Regel „ein Befund je Art" einen Wächter, der ohne Datenbank läuft.
     *
     * @param  array<string, mixed>  $file
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function drift(array $file): array
    {
        $path = (string) $file['path'];
        $role = is_string($file['role'] ?? null) ? $file['role'] : '';
        $present = ($file['present'] ?? false) === true;

        $managed = [];

        foreach (is_array($file['lines'] ?? null) ? $file['lines'] : [] as $line) {
            if (is_string($line)) {
                $managed[] = $line;
            }
        }

        [$foreign, $missing] = match ($role) {
            SystemDiagnose::ROLE_HBA => [$this->remote->orphans($managed), $this->remote->missing($managed)],
            SystemDiagnose::ROLE_SSHD => self::compare($managed, SshdConfig::lines($this->sftp->accesses())),
            // Eine Rolle, die dieses Panel nicht kennt: Der Agent ist neuer als
            // das Panel. Über den Inhalt sagen wir dann nichts — die Form hat er
            // schon beurteilt.
            default => [[], []],
        };

        return self::judge($path, $present, $foreign, $missing);
    }

    /**
     * Das Urteil über eine Datei — ohne Agent, ohne Bestand, ohne Rolle.
     *
     * **Ein Befund je Art und nicht je Zeile.** Die Kennung eines Befundes ist
     * `check`+`subject`+`reason` (`docs/98 §2`); drei fremde Zeilen in einer
     * Datei sind ein Schaden und keine drei. Welche es sind, steht im Wortlaut.
     *
     * @param  list<string>  $foreign
     * @param  list<string>  $missing
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    public static function judge(string $path, bool $present, array $foreign, array $missing): array
    {
        // **Kein Block und nichts zu tun ist der Normalzustand.** Ein Server
        // ohne Fernzugriff und ohne SFTP-Schlüssel hat keinen Bereich in diesen
        // Dateien, und jede Nacht eine Zeile darüber wäre die Falle aus §4.
        if (! $present) {
            return $missing === []
                ? []
                : [self::finding($path, 'block_missing', self::wortlaut('Im Bestand stehen', $missing))];
        }

        $findings = [];

        if ($foreign !== []) {
            $findings[] = self::finding($path, 'foreign_line', self::wortlaut('Nicht aus dem Bestand', $foreign));
        }

        if ($missing !== []) {
            $findings[] = self::finding($path, 'line_missing', self::wortlaut('Im Bestand und nicht in der Datei', $missing));
        }

        return $findings;
    }

    /**
     * Der Mengenvergleich für den sshd — beide Richtungen.
     *
     * **Das ist keine zweite Fassung von {@see RemoteAccess::orphans()}.** Was
     * dort die Regel ist, ist der Aufbau des Sollzustands aus dem Bestand; der
     * Vergleich selbst ist eine Differenz zweier Listen. Für `pg_hba.conf`
     * bleibt er dort, wo er seit P5b steht.
     *
     * @param  list<string>  $managed
     * @param  list<string>  $wanted
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function compare(array $managed, array $wanted): array
    {
        /*
         * **Verglichen wird der Inhalt einer Zeile und nicht ihre Einrückung.**
         *
         * Die beiden Seiten formatieren dieselbe Zeile verschieden, und zwar
         * mit Absicht: {@see \SrvPanel\Agent\Ssh\SshdConfig::block()} rückt
         * den Rumpf eines `Match`-Blocks um vier Leerzeichen ein, weil sshd so
         * gelesen wird, und {@see \SrvPanel\Agent\ManagedBlock::managed()}
         * gibt jede Zeile **getrimmt** zurück, weil es die Marken finden und
         * Kommentare überspringen muss.
         *
         * **Ohne diese Normalisierung fiel jede Rumpfzeile durch** — und zwar
         * in beide Richtungen zugleich: Am 3. September 2026 meldete der erste
         * Lauf auf `cloudsrv24` (`docs/99`, Punkt 1) genau dieselben sechzehn
         * Zeilen einmal als `foreign_line` und einmal als `line_missing`.
         * `Match User p1136` stand in keiner der beiden Listen: Die Zeile steht
         * auf Spalte 0 und passte deshalb zusammen.
         *
         * > **Zwei Leser derselben Zeilen, von denen einer die Einrückung
         * > wegwirft, vergleichen zwei Schreibweisen desselben Inhalts.**
         *
         * Das ist M22 eine Ebene tiefer: Dort zählten zwei Leser die **Marken**
         * verschieden, hier formatieren zwei Schreiber dieselbe **Zeile**
         * verschieden.
         *
         * Verloren geht dabei nichts: Eine fremde Zeile bleibt fremd, wenn sie
         * anderen Inhalt hat. Nur eine, die sich allein in ihrer Einrückung
         * unterscheidet, gilt jetzt als dieselbe — und das ist sie auch.
         */
        $managed = array_map(trim(...), $managed);
        $wanted = array_map(trim(...), $wanted);

        $inWanted = array_fill_keys($wanted, true);
        $inManaged = array_fill_keys($managed, true);

        return [
            array_values(array_filter($managed, static fn (string $line): bool => ! isset($inWanted[$line]))),
            array_values(array_filter($wanted, static fn (string $line): bool => ! isset($inManaged[$line]))),
        ];
    }

    /**
     * Der Wortlaut eines Befundes — die Zeilen selbst.
     *
     * Sie stehen im `detail` und nicht im Grund: Der Grund ist der stabile Teil
     * der Kennung, und die Zeilen ändern sich mit jedem Kunden.
     *
     * @param  list<string>  $lines
     */
    private static function wortlaut(string $kopf, array $lines): string
    {
        return $kopf.":\n".implode("\n", $lines);
    }

    /** @return array{subject: string, reason: string, detail: null|string} */
    private static function finding(string $subject, string $reason, ?string $detail): array
    {
        return ['subject' => $subject, 'reason' => $reason, 'detail' => $detail];
    }
}
