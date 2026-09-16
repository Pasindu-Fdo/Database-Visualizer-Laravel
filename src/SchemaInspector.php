<?php
namespace DatabaseVisualizer\Laravel;

use Illuminate\Database\DatabaseManager;
use Throwable;

class SchemaInspector
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function inspect(?string $connection = null): array
    {
        $connection = $this->database->connection($connection);
        $databaseName = $connection->getDatabaseName();
        $builder = $connection->getSchemaBuilder();
        $tables = []; $relationships = []; $warnings = [];
        
        $driver = $connection->getDriverName();
        $pdo = $connection->getPdo();
        $tableListing = [];
        
        try {
            if ($driver === 'sqlite') {
                $tableListing = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED')");
                $stmt->execute([$databaseName ?: $pdo->query('SELECT DATABASE()')->fetchColumn()]);
                $tableListing = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } elseif ($driver === 'pgsql') {
                $stmt = $pdo->prepare("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema()");
                $stmt->execute();
                $tableListing = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } else {
                $rawTables = method_exists($builder, 'getTables')
                    ? $builder->getTables($databaseName)
                    : (method_exists($builder, 'getTableListing')
                        ? ((new \ReflectionMethod($builder, 'getTableListing'))->getNumberOfParameters() > 0 ? $builder->getTableListing($databaseName) : $builder->getTableListing())
                        : []);
                foreach ($rawTables as $item) {
                    $name = is_array($item) ? ($item['name'] ?? reset($item)) : (is_object($item) ? ($item->name ?? reset($item)) : (string) $item);
                    $schema = is_array($item) ? ($item['schema'] ?? null) : null;
                    if ($schema && $schema !== $databaseName) continue;
                    if (is_string($item) && str_contains($item, '.')) {
                        $parts = explode('.', $item, 2);
                        if (count($parts) === 2 && $parts[0] !== $databaseName) continue;
                        $name = $parts[1] ?? $name;
                    }
                    $tableListing[] = $name;
                }
            }
        } catch (Throwable) {}

        foreach ($tableListing as $tableName) {
            try {
                $details = method_exists($builder, 'getColumns') ? $builder->getColumns($tableName) : [];
                $columns = [];
                if ($details) {
                    foreach ($details as $column) {
                        $name = $column['name'];
                        $columns[] = ['name' => $name, 'dataType' => $column['type_name'] ?? $column['type'] ?? 'unknown', 'nullable' => (bool) ($column['nullable'] ?? true), 'isPrimaryKey' => false];
                    }
                } else {
                    foreach ($builder->getColumnListing($tableName) as $name) {
                        $columns[] = ['name' => $name, 'dataType' => $builder->getColumnType($tableName, $name), 'nullable' => true, 'isPrimaryKey' => false];
                    }
                }
                $foreignKeys = [];
                if (method_exists($builder, 'getForeignKeys')) {
                    foreach ($builder->getForeignKeys($tableName) as $foreign) {
                        foreach (($foreign['columns'] ?? []) as $index => $column) {
                            $key = ['column' => $column, 'referencesTable' => $foreign['foreign_table'], 'referencesColumn' => $foreign['foreign_columns'][$index] ?? 'id'];
                            $foreignKeys[] = $key;
                            $relationships[] = ['from' => "$tableName.$column", 'to' => $key['referencesTable'].'.'.$key['referencesColumn'], 'type' => 'many-to-one'];
                        }
                    }
                }
                $primary = $this->primaryKeys($connection->getDriverName(), $connection->getPdo(), $tableName, $databaseName);
                foreach ($columns as &$column) $column['isPrimaryKey'] = in_array($column['name'], $primary, true);
                $tables[] = ['name' => $tableName, 'columns' => $columns, 'primaryKey' => $primary, 'foreignKeys' => $foreignKeys];
            } catch (Throwable $error) { $warnings[] = "$tableName: {$error->getMessage()}"; }
        }
        return ['dialect' => $connection->getDriverName(), 'tables' => $tables, 'relationships' => $relationships, 'warnings' => $warnings];
    }

    private function primaryKeys(string $driver, \PDO $pdo, string $table, ?string $databaseName = null): array
    {
        try {
            if ($driver === 'sqlite') {
                $quoted = str_replace("'", "''", $table);
                $rows = $pdo->query("PRAGMA table_info('$quoted')")->fetchAll(\PDO::FETCH_ASSOC);
                return array_values(array_map(fn ($row) => $row['name'], array_filter($rows, fn ($row) => (int) $row['pk'] > 0)));
            }
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $statement = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
                $statement->execute([$databaseName ?: $pdo->query('SELECT DATABASE()')->fetchColumn(), $table]); return $statement->fetchAll(\PDO::FETCH_COLUMN);
            }
            if ($driver === 'pgsql') {
                $statement = $pdo->prepare("SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=ANY(i.indkey) WHERE i.indrelid=?::regclass AND i.indisprimary");
                $statement->execute([$table]); return $statement->fetchAll(\PDO::FETCH_COLUMN);
            }
        } catch (Throwable) {}
        return [];
    }
}
