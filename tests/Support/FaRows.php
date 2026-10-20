<?php

namespace SGW_Sales\Tests\Support;

/**
 * Removes the rows a test wrote, through FrontAccounting or sgw_sales, and only
 * those. Generating an invoice writes across many tables (deliveries and
 * invoices, GL and stock moves, refs, audit trail), so this marks every table
 * rather than a list of them: mark() records each table's highest auto-increment
 * value, or, for a table without one, its primary keys, or for a table without
 * those either (comments) its rows; purge() deletes every row beyond the mark.
 *
 * Rows that existed at the mark and were updated are not put back, except in
 * the tables in RESTORED, which are copied whole at the mark and put back whole.
 * (Copied from sgw_import's tests/Support/FaRows.php, with its own RESTORED.)
 */
final class FaRows
{
    /**
     * Tables FrontAccounting updates as it writes: a delivery moves on the order's
     * quantities sent and its version, and a closed order its lines' quantities.
     */
    private const RESTORED = ['sales_orders', 'sales_order_details', 'stock_master'];

    private \PDO $pdo;

    /** @var array<string, array{0: string, 1: int}> table => [auto-increment column, max] */
    private array $marks = [];

    /** @var array<string, array{0: string[], 1: array<string, int>}> table => [key columns, count of each key] */
    private array $keys = [];

    /** @var array<string, list<array<string, mixed>>> table => its rows at the mark */
    private array $copies = [];

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function mark(\PDO $pdo, string $tb = '0_'): self
    {
        $rows = new self($pdo);
        $columns = $pdo->query(
            "SELECT c.TABLE_NAME, c.COLUMN_NAME, c.EXTRA, c.COLUMN_KEY
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $auto = [];
        $primary = [];
        $all = [];
        foreach ($columns as $c) {
            if (strpos($c['TABLE_NAME'], $tb) !== 0) {
                continue;
            }
            $all[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
            if (strpos($c['EXTRA'], 'auto_increment') !== false) {
                $auto[$c['TABLE_NAME']] = $c['COLUMN_NAME'];
            }
            if ($c['COLUMN_KEY'] === 'PRI') {
                $primary[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
            }
        }
        foreach ($auto as $table => $column) {
            $max = (int) $pdo->query("SELECT COALESCE(MAX(`$column`), 0) FROM `$table`")->fetchColumn();
            $rows->marks[$table] = [$column, $max];
        }
        foreach ($all as $table => $every) {
            if (isset($auto[$table])) {
                continue;
            }
            $key = $primary[$table] ?? $every;
            $rows->keys[$table] = [$key, array_count_values($rows->keysOf($table, $key))];
        }

        foreach (self::RESTORED as $table) {
            $rows->copies[$tb . $table] = $pdo->query("SELECT * FROM `$tb$table`")->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $rows;
    }

    public function purge(): void
    {
        foreach ($this->copies as $table => $copy) {
            $this->pdo->exec("DELETE FROM `$table`");
            foreach ($copy as $row) {
                $columns = '`' . implode('`, `', array_keys($row)) . '`';
                $this->pdo->prepare(
                    "INSERT INTO `$table` ($columns) VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ')'
                )->execute(array_values($row));
            }
        }
        foreach ($this->marks as $table => [$column, $max]) {
            $this->pdo->prepare("DELETE FROM `$table` WHERE `$column` > ?")->execute([$max]);
        }
        foreach ($this->keys as $table => [$key, $existing]) {
            $where = implode(' AND ', array_map(function (string $c): string {
                return "`$c` <=> ?";
            }, $key));
            foreach (array_count_values($this->keysOf($table, $key)) as $k => $count) {
                $extra = $count - ($existing[$k] ?? 0);
                if ($extra > 0) {
                    $this->pdo->prepare("DELETE FROM `$table` WHERE $where LIMIT $extra")
                        ->execute(json_decode($k, true));
                }
            }
        }
    }

    /**
     * @param string[] $key
     * @return string[] each row's key, JSON-encoded
     */
    private function keysOf(string $table, array $key): array
    {
        $list = implode(', ', array_map(function (string $c): string {
            return "`$c`";
        }, $key));
        $keys = [];
        foreach ($this->pdo->query("SELECT $list FROM `$table`", \PDO::FETCH_NUM) as $row) {
            $keys[] = json_encode($row);
        }

        return $keys;
    }
}
