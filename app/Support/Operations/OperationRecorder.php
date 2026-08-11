<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Enums\OperationStatus;
use App\Models\Operation;
use SrvPanel\Agent\Frame;

/**
 * Schreibt den Verlauf eines Vorgangs fort.
 *
 * **Die Ausgabe wird gepuffert, der Fortschritt nicht.** Ein Paketupdate
 * schreibt Hunderte Zeilen in Sekunden; je Zeile ein UPDATE wäre ein
 * Abfragesturm auf derselben Zeile, an der gleichzeitig die offenen
 * SSE-Verbindungen lesen. Gesammelt wird deshalb im Speicher und höchstens
 * viermal je Sekunde geschrieben. Der Fortschritt dagegen ist ein einzelner
 * kleiner Wert und darf sofort sichtbar werden — er ist das, worauf jemand
 * wartet.
 *
 * **Die Ausgabe ist gedeckelt.** Ein Programm mit endloser Ausgabe würde sonst
 * eine Zeile in der Datenbank auf beliebige Größe treiben. Bei Erreichen der
 * Grenze wird abgeschnitten und das vermerkt; das Protokoll des Agenten hat
 * den vollständigen Text ohnehin.
 */
final class OperationRecorder
{
    /** Höchstens so viel Ausgabe landet in der Datenbank. */
    public const OUTPUT_MAX = 256 * 1024;

    /**
     * Und höchstens so viel Begründung — dieselbe Regel, elf Tage später.
     *
     * **Hier fehlte sie, und das hat ein Abnahmekriterium gekostet.** Die
     * Ausgabe war seit dem ersten Tag gedeckelt; die Meldung nicht, weil ihre
     * Spalte `varchar(255)` war und niemand vorhatte, mehr hineinzuschreiben.
     * Am 11. August 2026 wies der Agent einen Dump ab und begründete es auf
     * 260 Zeichen — MariaDB nahm sie nicht, die `PDOException` riss den
     * Fehlerweg mit, und der Vorgang bekam vom Warteschlangen-Handler die
     * Meldung „vermutlich Zeitüberschreitung" nach einer Sekunde Laufzeit.
     *
     * > **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**
     *
     * Die Spalte ist jetzt `text`, und trotzdem steht diese Grenze hier: Was
     * ein Agent an Ausgabe eines fehlgeschlagenen Kommandos durchreicht, hat
     * keine Obergrenze, die dieses Panel kennt. Wer eine Grenze setzt, hält
     * sie selbst ein — sonst wandert der Fehlschlag nur an die nächste.
     *
     * Achttausend Byte, weil eine Fehlermeldung darüber niemand mehr liest und
     * das Protokoll des Agenten den vollständigen Text ohnehin führt.
     */
    public const MESSAGE_MAX = 8 * 1024;

    private const FLUSH_INTERVAL = 0.25;

    private string $buffer = '';

    private float $lastFlush = 0.0;

    private bool $truncated = false;

    public function __construct(private readonly Operation $operation) {}

    public function start(): void
    {
        $this->operation->forceFill([
            'status' => OperationStatus::Running,
            'started_at' => now(),
            'progress' => 0,
        ])->save();

        $this->lastFlush = microtime(true);
    }

    /**
     * Einen Frame des Agenten verarbeiten.
     *
     * **Diese Methode stand bis zum 9. August 2026 als `private` im Arbeiter,
     * und genau deshalb war der Fehler unsichtbar** (`docs/36 §22.3w`): Sie las
     * `percent` und `message`, wo der Agent `pct` und `text` schickt, und
     * prüfte auf `type === 'output'`, wo er `log` sendet. Kein Test kam an sie
     * heran, und ihr Fehlverhalten war eine Anzeige, die stillstand.
     *
     * Jetzt liest sie über {@see Frame} — dieselbe Klasse, mit der die
     * Gegenseite baut — und sie ist erreichbar, damit `FrameContractTest` einen
     * echten Frame hindurchschicken kann.
     *
     * @param  array<string, mixed>  $frame
     */
    public function consume(array $frame): void
    {
        $kind = Frame::kindOf($frame);

        if ($kind === Frame::PROGRESS) {
            $percent = Frame::percentOf($frame);

            // Ohne Prozentzahl wird nichts geschrieben. Die alte Fassung setzte
            // hier `0` ein — und schrieb damit bei jedem einzelnen Frame den
            // Fortschritt auf null zurück.
            if ($percent !== null) {
                $this->progress($percent, Frame::textOf($frame));
            }

            return;
        }

        if ($kind === Frame::LOG) {
            $line = Frame::lineOf($frame);

            if ($line !== null && $line !== '') {
                $this->output($line);
            }
        }
    }

    public function progress(int $percent, ?string $message = null): void
    {
        // Erst die gesammelte Ausgabe, dann der Fortschritt. Sonst stünde
        // kurzzeitig „80 %" an einer Ausgabe, die noch bei 40 % endet — und
        // wer in diesem Moment liest, sieht einen Widerspruch.
        $this->flush();

        $this->operation->forceFill(array_filter([
            'progress' => max(0, min(100, $percent)),
            'message' => $message,
        ], static fn ($value): bool => $value !== null))->save();
    }

    public function output(string $text): void
    {
        if ($text === '' || $this->truncated) {
            return;
        }

        $this->buffer .= rtrim($text, "\n")."\n";

        // Auch der Puffer ist gedeckelt. Ohne das könnte ein Programm, das in
        // einer Sekunde Megabytes ausgibt, den Speicher des Arbeiters füllen,
        // bevor die Zeitschranke unten überhaupt greift.
        if (strlen($this->buffer) >= self::OUTPUT_MAX
            || microtime(true) - $this->lastFlush >= self::FLUSH_INTERVAL) {
            $this->flush();
        }
    }

    /** @param  array<string,mixed>  $result */
    public function succeed(array $result = []): void
    {
        $this->finish(OperationStatus::Succeeded, $result, null);
    }

    /** @param  array<string,mixed>  $context */
    public function fail(string $message, array $context = []): void
    {
        $this->finish(OperationStatus::Failed, $context, $message);
    }

    public function cancel(): void
    {
        $this->finish(OperationStatus::Cancelled, [], 'Abgebrochen.');
    }

    /**
     * Die Begründung auf ein Mass, das die Spalte sicher trägt.
     *
     * **Geschnitten wird nach Byte und nicht nach Zeichen**, denn die Grenze
     * der Spalte ist eine in Byte. `mb_strcut` schneidet dabei nicht mitten in
     * ein Zeichen — bei `str_split` stünde am Ende ein halbes Umlaut-Byte, und
     * die Meldung wäre kein gültiges UTF-8 mehr.
     *
     * Der Vermerk gehört dazu: Eine Begründung, die still endet, sieht aus wie
     * eine vollständige.
     */
    private static function shorten(string $message): string
    {
        if (strlen($message) <= self::MESSAGE_MAX) {
            return $message;
        }

        return mb_strcut($message, 0, self::MESSAGE_MAX - 64).
            "\n… gekürzt; der vollständige Text steht im Protokoll des Agenten.";
    }

    /** @param  array<string,mixed>  $result */
    private function finish(OperationStatus $status, array $result, ?string $message): void
    {
        $this->flush();

        $this->operation->forceFill([
            'status' => $status,
            'result' => $result === [] ? null : $result,
            'message' => $message === null ? $this->operation->message : self::shorten($message),
            'progress' => $status === OperationStatus::Succeeded ? 100 : $this->operation->progress,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Den Puffer in die Datenbank schreiben.
     *
     * Angehängt wird in der Datenbank, nicht im Speicher: Der Vorgang läuft im
     * Arbeiter, gelesen wird gleichzeitig aus dem Webprozess. Würde hier der
     * gesamte bisherige Text geladen, ergänzt und zurückgeschrieben, ginge
     * jede Zeile verloren, die zwischen Laden und Schreiben dazukam.
     */
    private function flush(): void
    {
        if ($this->buffer === '' || $this->truncated) {
            $this->buffer = '';

            return;
        }

        $chunk = $this->buffer;
        $this->buffer = '';
        $this->lastFlush = microtime(true);

        $connection = $this->operation->getConnection();
        $table = $connection->getQueryGrammar()->wrapTable($this->operation->getTable());

        $length = (int) $connection->table($this->operation->getTable())
            ->where('id', $this->operation->getKey())
            ->selectRaw('COALESCE(LENGTH(output), 0) as len')
            ->value('len');

        // Geprüft wird gegen das, was ankommt, nicht nur gegen das, was schon
        // dasteht. Ein einzelnes großes Stück — und genau so kommt Ausgabe aus
        // einem Programm — würde sonst an der Grenze vorbeilaufen, weil die
        // Zeile davor noch leer war.
        $remaining = self::OUTPUT_MAX - $length;
        $notice = "\n[Ausgabe gekürzt — der vollständige Text steht im Protokoll des Agenten.]\n";

        if ($remaining <= 0) {
            $this->truncated = true;
            $chunk = $notice;
        } elseif (strlen($chunk) > $remaining) {
            $this->truncated = true;
            $chunk = substr($chunk, 0, $remaining).$notice;
        }

        $connection->update(
            sprintf('update %s set output = %s where id = ?', $table, self::concatExpression($connection->getDriverName())),
            [$chunk, $this->operation->getKey()],
        );

        $this->operation->refresh();
    }

    /**
     * Der Ausdruck zum Anhängen — und der Grund, warum er nicht fest steht.
     *
     * `||` verkettet in SQLite und PostgreSQL. In MariaDB ist es ein logisches
     * Oder: `output || '…'` ergäbe dort 0 oder 1, und die gesammelte Ausgabe
     * jedes Vorgangs wäre nach dem ersten Anhängen eine Ziffer. Die Tests
     * laufen gegen SQLite, der Betrieb gegen MariaDB — dieser Fehler wäre
     * grün durch die Prüfung gegangen und im Betrieb aufgefallen.
     *
     * Öffentlich, damit ein Test beide Zweige belegen kann, ohne dass beide
     * Datenbanken installiert sein müssen.
     */
    public static function concatExpression(string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => "CONCAT(COALESCE(output, ''), ?)",
            default => "COALESCE(output, '') || ?",
        };
    }
}
