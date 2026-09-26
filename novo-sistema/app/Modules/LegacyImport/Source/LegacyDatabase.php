<?php

namespace App\Modules\LegacyImport\Source;

use Generator;
use PDO;
use RuntimeException;

/**
 * Banco SQLite do sistema antigo, aberto SOMENTE LEITURA.
 *
 * - PDO::SQLITE_OPEN_READONLY: qualquer escrita falha no proprio SQLite.
 * - ATTR_STRINGIFY_FETCHES: tudo chega como texto (dinheiro nunca vira float).
 * - O schema varia por instalacao: colunas ausentes chegam como null.
 */
final class LegacyDatabase
{
    private PDO $pdo;

    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @var list<string>|null */
    private ?array $tables = null;

    public function __construct(public readonly string $path)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Banco antigo nao encontrado ou sem leitura: {$path}");
        }

        $this->pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $this->pdo->exec('PRAGMA query_only = ON');

        $ok = $this->pdo->query('PRAGMA quick_check')->fetchColumn();
        if ($ok !== 'ok') {
            throw new RuntimeException("Banco antigo corrompido (quick_check: {$ok}).");
        }
    }

    public function sha256(): string
    {
        return hash_file('sha256', $this->path);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->tables ??= $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function hasTable(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        return $this->columns[$table] ??= array_column(
            $this->pdo->query('PRAGMA table_info('.$this->quote($table).')')->fetchAll(),
            'name',
        );
    }

    public function count(string $table): int
    {
        return $this->hasTable($table) ? (int) $this->pdo->query('SELECT COUNT(*) FROM '.$this->quote($table))->fetchColumn() : 0;
    }

    /**
     * Linhas em ordem de insercao (rowid), em streaming.
     *
     * @return Generator<int, array<string, ?string>>
     */
    public function rows(string $table): Generator
    {
        if (! $this->hasTable($table)) {
            return;
        }
        $stmt = $this->pdo->query('SELECT * FROM '.$this->quote($table).' ORDER BY rowid');
        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    /** @return list<array<string, ?string>> */
    public function all(string $table): array
    {
        return iterator_to_array($this->rows($table), false);
    }

    private function quote(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new RuntimeException("Nome de tabela invalido: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
}
