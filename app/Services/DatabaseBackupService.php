<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;

class DatabaseBackupService
{
    /**
     * Human-readable filename stem (no extension).
     */
    public function suggestedFilenameStem(): string
    {
        return 'saloon-backup-'.now()->format('Y-m-d-His');
    }

    /**
     * Absolute path to SQLite database file, or null if not using sqlite.
     */
    public function sqliteDatabasePath(): ?string
    {
        if (config('database.default') !== 'sqlite') {
            return null;
        }

        $path = config('database.connections.sqlite.database');
        if ($path === ':memory:' || $path === null || $path === '') {
            return null;
        }

        return $path;
    }

    /**
     * Stream SQL dump for MySQL (for streamDownload callback).
     */
    public function streamMysqlDump(callable $writer): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $database = $connection->getDatabaseName();

        $writer("-- Saloon database backup\n");
        $writer('-- Generated at '.now()->toIso8601String()."\n");
        $writer("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");

        $tables = $connection->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            [$database, 'BASE TABLE']
        );

        foreach ($tables as $tableRow) {
            $table = $tableRow->TABLE_NAME;
            $create = (array) $connection->selectOne('SHOW CREATE TABLE `'.str_replace('`', '``', $table).'`');
            $createSql = (string) end($create);
            $writer("\n".$createSql.";\n");

            foreach (DB::table($table)->cursor() as $row) {
                $row = (array) $row;
                if ($row === []) {
                    continue;
                }
                $cols = array_keys($row);
                $vals = array_map(fn ($v) => $this->sqlLiteral($v, $pdo), $row);
                $writer('INSERT INTO `'.$table.'` (`'.implode('`,`', $cols).'`) VALUES ('.implode(',', $vals).");\n");
            }
        }

        $writer("\nSET FOREIGN_KEY_CHECKS=1;\n");
    }

    private function sqlLiteral(mixed $value, PDO $pdo): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $pdo->quote($value->format('Y-m-d H:i:s'));
        }

        if (is_string($value)) {
            return $pdo->quote($value);
        }

        return $pdo->quote((string) json_encode($value));
    }
}
