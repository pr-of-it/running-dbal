<?php

namespace Runn\Dbal\Drivers\Sqlite;

use Runn\Dbal\Column;
use Runn\Dbal\Columns;
use Runn\Dbal\Connection;
use Runn\Dbal\DriverQueryBuilderInterface;
use Runn\Dbal\Index;
use Runn\Dbal\Indexes;
use Runn\Dbal\Query;

/**
 * DBAL sqlite driver
 *
 * Class Sqlite
 * @package Runn\Dbal\Drivers
 */
class Driver
    extends \Runn\Dbal\Driver
{

    /**
     * @return \Runn\Dbal\DriverQueryBuilderInterface
     */
    public function getQueryBuilder(): DriverQueryBuilderInterface
    {
        return new QueryBuilder;
    }

    /**
     * @param \Runn\Dbal\Column $column
     * @return string
     */
    public function getColumnDDL(Column $column): string
    {
        switch (get_class($column)) {
            case \Runn\Dbal\Columns\SerialColumn::class:
                $ddl =  'INTEGER AUTOINCREMENT';
                break;
            case \Runn\Dbal\Columns\PkColumn::class:
                $ddl =  'INTEGER PRIMARY KEY AUTOINCREMENT';
                break;
            case \Runn\Dbal\Columns\LinkColumn::class:
                $ddl = 'INTEGER DEFAULT NULL';
                break;
            case \Runn\Dbal\Columns\BooleanColumn::class:
                $ddl = 'INTEGER';
                $default = isset($column->default) ? (null === $column->default ? 'NULL' : (int)(bool)$column->default) : null;
                break;
            case \Runn\Dbal\Columns\IntColumn::class:
                $ddl = 'INTEGER';
                $default = isset($column->default) ? (null === $column->default ? 'NULL' : $column->default) : null;
                break;
            case \Runn\Dbal\Columns\FloatColumn::class:
                $ddl = 'REAL';
                $default = isset($column->default) ? (null === $column->default ? 'NULL' : $column->default) : null;
                break;
            case \Runn\Dbal\Columns\CharColumn::class:
            case \Runn\Dbal\Columns\StringColumn::class:
            case \Runn\Dbal\Columns\TimeColumn::class:
            case \Runn\Dbal\Columns\DateColumn::class:
            case \Runn\Dbal\Columns\DateTimeColumn::class:
                $ddl = 'TEXT';
                $default = isset($column->default) ? (null === $column->default ? 'NULL' : "'" . $column->default . "'") : null;
                break;
            default:
                $ddl = $column->getColumnDdlByDriver($this);
                break;
        }

        if (isset($default)) {
            $ddl .= ' DEFAULT ' . $default;
        }

        return $ddl;
    }

    /**
     * @param \Runn\Dbal\Index $index
     * @return string
     */
    public function getIndexDDL(Index $index): string
    {
        switch (get_class($index)) {
            case \Runn\Dbal\Indexes\UniqueIndex::class:
                $ddl = 'UNIQUE INDEX ';
                break;
            case \Runn\Dbal\Indexes\SimpleIndex::class:
                $ddl = 'INDEX ';
                break;
            default:
                return $index->getIndexDdlByDriver($this);
        }

        // @todo: check required "columns" and "table"

        $columns = [];
        $columnNames = [];
        foreach ($index->columns as $column) {
            preg_match('~^([\S]+)(\s+(asc|desc))?~i', $column, $m);
            $columnName = trim($m[1], '`" ');
            $columnNames[] = $columnName;
            $columns[] = $this->getQueryBuilder()->quoteName($columnName) . (!empty($m[3]) ? ' ' . strtoupper($m[3]) : '');
        }

        $index->name  = $index->name ?? implode('_', $columnNames) . '_idx';

        $ddl .= $this->getQueryBuilder()->quoteName($index->name) . ' ON ' . $this->getQueryBuilder()->quoteName($index->table);
        $ddl .= ' (' . implode(', ', $columns) . ')';

        return $ddl;
    }

    public function processValueAfterLoad(Column $column, $value)
    {
        return $value;
    }

    public function processValueBeforeSave(Column $column, $value)
    {
        return $value;
    }

    /**
     * @param string $tableName
     * @return \Runn\Dbal\Query
     */
    public function getExistsTableQuery(string $tableName): Query
    {
        return (new Query())->select('count(*)>0')->from('sqlite_master')->where('type=:type AND name=:name')->params([
            ':type'=>'table',
            ':name'=>$tableName,
        ]);
    }

    /**
     * @param string $tableName
     * @param \Runn\Dbal\Columns|null $columns
     * @param \Runn\Dbal\Indexes|null $indexes
     * @param array $extensions
     * @return \Runn\Dbal\Query
     */
    public function getCreateTableQuery(string $tableName, Columns $columns = null, Indexes $indexes = null, $extensions = []): Query
    {
        $sql = 'CREATE TABLE ' . $this->getQueryBuilder()->quoteName($tableName) . "\n";

        $columnsDDL = [];

        foreach ($columns as $name => $column) {
            $columnsDDL[] = $this->getQueryBuilder()->quoteName($name) . ' ' . $this->getColumnDDL($column);
        }

        $sql .=
            "(\n" .
                implode(",\n", array_unique($columnsDDL)) .
            "\n)";
        return new Query($sql);
    }

    public function renameTable(Connection $connection, string $oldTableName, string $newTableName): bool
    {
        $query = new Query('ALTER TABLE ' . $this->getQueryBuilder()->quoteName($oldTableName) . ' RENAME TO ' . $this->getQueryBuilder()->quoteName($newTableName));
        return $connection->execute($query);
    }

    public function truncateTable(Connection $connection, string $tableName): bool
    {
        $query = new Query('DELETE FROM ' . $this->getQueryBuilder()->quoteName($tableName));
        return $connection->execute($query);
    }

    public function dropTable(Connection $connection, string $tableName): bool
    {
        $query = new Query('DROP TABLE ' . $this->getQueryBuilder()->quoteName($tableName));
        return $connection->execute($query);
    }

    public function addColumn(Connection $connection, $tableName, array $columns)
    {
        // TODO: Implement addColumn() method.
    }

    public function dropColumn(Connection $connection, $tableName, array $columns)
    {
        // TODO: Implement dropColumn() method.
    }

    public function renameColumn(Connection $connection, $tableName, $oldName, $newName)
    {
        // TODO: Implement renameColumn() method.
    }

    public function addIndex(Connection $connection, $tableName, array $indexes)
    {
        $result = true;
        foreach ($indexes as $index) {
            $result = $result && $connection->execute(new Query('CREATE ' . $this->getIndexDDL($tableName, $index)));
        }
        return $result;
    }

    public function dropIndex(Connection $connection, $tableName, array $indexes)
    {
        $result = true;
        foreach ($indexes as $index) {
            $order = $index->order ? $index->order . '.' : '';
            $result = $result && $connection->execute(new Query('DROP INDEX ' . $order . $index->name));
        }
        return $result;
    }

}