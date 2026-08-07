<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Server;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Db\Sql;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Eine Datenbank anlegen.
 *
 * **Der Name entsteht hier und wird nicht entgegengenommen** — übergeben werden
 * der Systembenutzer des Abonnements und der Zusatz, und `p1001_shop` entsteht
 * in {@see Names::database()}. Dieselbe Regel wie in
 * {@see SubscriptionProvision}, wo aus dem Namen ein Pfad wird: Eine Operation,
 * die den fertigen Bezeichner annimmt und ihn danach prüft, ist eine Operation,
 * deren Prüfung irgendwann eine Lücke hat.
 *
 * **Zeichensatz und Sortierung stehen auf einer Positivliste.** Sie kommen aus
 * dem Formular und werden zu einem Teil einer SQL-Anweisung; eine Maskierung
 * wäre hier die falsche Antwort, weil es nur eine Handvoll sinnvoller Werte
 * gibt. Wer `utf8mb4_unicode_ci` und `utf8mb4_general_ci` unterscheidet, findet
 * beide in der Liste; wer etwas anderes braucht, bekommt eine Ergänzung im
 * Quelltext und keine Freitexteingabe.
 *
 * **Wiederholbar.** `IF NOT EXISTS`: Ein zweiter Lauf nach einem abgebrochenen
 * Vorgang findet die Datenbank vor und wirft nichts weg. Er rückt aber auch
 * **nichts zurecht** — ein `ALTER DATABASE … CHARACTER SET` auf eine Datenbank
 * mit Tabellen ändert die Vorgabe für neue Tabellen und lässt die alten stehen,
 * und das wäre ein halber Zustand, den niemand bestellt hat.
 */
final class DbDatabaseCreate implements Op
{
    /**
     * Die Zeichensätze, die das Panel anbietet.
     *
     * `utf8mb4` und sonst nichts. `utf8` in MySQL ist drei Byte breit und kann
     * kein Emoji speichern — es ist der Zeichensatz, der eine Anwendung genau
     * einmal überrascht, und zwar in der Produktion. `latin1` steht hier nicht,
     * weil eine neue Datenbank im Jahr 2026 keinen Grund dafür hat.
     *
     * @var array<string, list<string>> Zeichensatz => erlaubte Sortierungen
     */
    private const CHARSETS = [
        'utf8mb4' => [
            // Die Vorgabe. `unicode_ci` sortiert nach dem Unicode-Algorithmus
            // und behandelt „ä" wie „a"; `general_ci` ist schneller und
            // schlichter. `bin` ist für den, der wirklich Byte für Byte
            // vergleichen will.
            'utf8mb4_unicode_ci',
            'utf8mb4_general_ci',
            'utf8mb4_unicode_520_ci',
            'utf8mb4_bin',
        ],
    ];

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
    ) {}

    public static function name(): string
    {
        return 'db.database.create';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $prefix = Names::prefix($args['user'] ?? null);
        $database = Names::database($prefix, $args['suffix'] ?? null);

        [$charset, $collation] = self::charset($args['charset'] ?? 'utf8mb4', $args['collation'] ?? null);

        $context->progress(20, 'Datenbankserver prüfen');
        $this->server->require($context, $this->session);

        $context->progress(60, 'Datenbank anlegen');
        $this->session->execute($context, [sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s',
            Sql::identifier($database),
            $charset,
            $collation,
        )]);

        $context->progress(100, 'fertig');

        return [
            'name' => $database,
            'charset' => $charset,
            'collation' => $collation,
        ];
    }

    /**
     * Zeichensatz und Sortierung, gegen die Positivliste.
     *
     * @return array{0: string, 1: string}
     */
    private static function charset(mixed $charset, mixed $collation): array
    {
        $name = Guard::enum($charset, array_keys(self::CHARSETS), 'charset');
        $allowed = self::CHARSETS[$name];

        if ($collation === null) {
            return [$name, $allowed[0]];
        }

        $chosen = Guard::enum($collation, $allowed, 'collation');

        // Die Zugehörigkeit steht schon in der Tabelle — dieselbe Prüfung noch
        // einmal wäre eine zweite Fassung derselben Regel. Was hier bleibt, ist
        // die Zusicherung für den Leser.
        if (! str_starts_with($chosen, $name.'_')) {
            throw AgentException::badRequest('Sortierung und Zeichensatz passen nicht zusammen.', [
                'charset' => $name,
                'collation' => $chosen,
            ]);
        }

        return [$name, $chosen];
    }

    /**
     * Die Auswahl für die Oberfläche.
     *
     * Sie steht hier und nicht im Panel, aus demselben Grund wie
     * `SubscriptionProvision::reservedDirectories()`: Wächst die Liste, wächst
     * das Formular mit. Eine abgetippte zweite Fassung wäre bei der ersten
     * Erweiterung falsch.
     *
     * @return array<string, list<string>>
     */
    public static function charsets(): array
    {
        return self::CHARSETS;
    }
}
