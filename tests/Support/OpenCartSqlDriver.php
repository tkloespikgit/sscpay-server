<?php

namespace Tests\Support;

use PDO;

/** PDO adapter with the result shape used by the OpenCart MySQL driver. */
class OpenCartSqlDriver
{
    public function __construct(public PDO $pdo) {}

    public function query(string $sql): object
    {
        $statement = $this->pdo->query($sql);
        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];

        return (object) ['row' => $rows[0] ?? [], 'rows' => $rows, 'num_rows' => count($rows)];
    }

    public function escape(string $value): string
    {
        return substr($this->pdo->quote($value), 1, -1);
    }
}
