<?php

namespace Globalis\MysqlDataAnonymizer;

use Amp;
use Amp\Promise;
use Amp\Mysql;
use Exception;
use Globalis\MysqlDataAnonymizer\Helpers;

class Anonymizer
{
    /**
     * whether fetch data from or deploy anonimized data to a serveur in distance
     *
     * @var DatabaseInterface
     */
    public $is_remote = false;

    /**
     * Database interactions object.
     *
     * @var DatabaseInterface
     */
    protected $mysql_pool = null;

    /**
     * Remote database interactions object.
     *
     * @var DatabaseInterface
     */
    protected $mysql_pool_source = null;

    /**
     * Generator object (e.g \Faker\Factory).
     *
     * @var mixed
     */
    protected $generator;

    /**
     * Configuration array.
     *
     * @var array
     */
    protected $config = [];

    /**
     * Blueprints for tables.
     *
     * @var array
     */
    protected $blueprints = [];

    /**
     * Constructor.
     *
     * @param boolean $is_remote
     */
    public function __construct($is_remote = false)
    {
        $this->is_remote = $is_remote;
    	$this->load_config();
        $this->load_helpers();

        $this->mysql_pool = Mysql\pool(Mysql\ConnectionConfig::fromString("host=".$this->config['DB_HOST'].";user=".$this->config['DB_USER'].";pass=".$this->config['DB_PASSWORD'].";db=". $this->config['DB_NAME']), $this->config['NB_MAX_MYSQL_CLIENT']);

        if ($this->is_remote) {
            $this->mysql_pool_source = Mysql\pool(Mysql\ConnectionConfig::fromString("host=".$this->config['DB_HOST_SOURCE'].";user=".$this->config['DB_USER_SOURCE'].";pass=".$this->config['DB_PASSWORD_SOURCE'].";db=". $this->config['DB_NAME_SOURCE']), $this->config['NB_MAX_MYSQL_CLIENT_SOURCE']);
        }

        try {
            if (!class_exists('\Faker\Factory')) {
                throw new Exception('Fzaninotto can not be found.');
            }
        } catch (Exception $e) {
            echo 'Error: ' . $e->getMessage(). PHP_EOL;
            exit(1);
        }
    }

    /**
     * Load configuration file
     */
    protected function load_config()
    {
        try {
            if (!file_exists(__DIR__ . "/../config/config.php")) {
                throw new Exception('config.php not found in the directory.');
            }
            $config = require __DIR__ . "/../config/config.php";

             $this->config = [
                'DB_HOST'                   => $config['DB_HOST'] ?? '127.0.0.1',
                'DB_NAME'                   => $config['DB_NAME'] ?? '',
                'DB_USER'                   => $config['DB_USER'] ?? '',
                'DB_PASSWORD'               => $config['DB_PASSWORD'] ?? '',
                'NB_MAX_MYSQL_CLIENT'       => $config['NB_MAX_MYSQL_CLIENT'] ?? 20,
                'NB_MAX_PROMISE_IN_LOOP'    => $config['NB_MAX_PROMISE_IN_LOOP'] ?? 20,
                'DEFAULT_GENERATOR_LOCALE'  => $config['DEFAULT_GENERATOR_LOCALE'] ?? 'en_US'
             ];

            foreach ($this->config as $parameter => $value) {
                if (!$value) {
                    throw new Exception($parameter . ' can not be empty.');
                    continue;
                }
                if (in_array($parameter, ['NB_MAX_MYSQL_CLIENT', 'NB_MAX_MYSQL_CLIENT'])) {
                    if (!is_int($value)) {
                        throw new Exception($parameter . ' should be integer.');
                    }
                }
            }

            if (!filter_var($this->config['DB_HOST'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new Exception('DB_HOST is not valid.');
            }

            if ($this->is_remote) {
                $remote_config = [
                    'DB_HOST_SOURCE'                => $config['DB_HOST_SOURCE'] ?? '',
                    'DB_NAME_SOURCE'                => $config['DB_NAME_SOURCE'] ?? '',
                    'DB_USER_SOURCE'                => $config['DB_USER_SOURCE'] ?? '',
                    'DB_PASSWORD_SOURCE'            => $config['DB_PASSWORD_SOURCE'] ?? '',
                    'NB_MAX_MYSQL_CLIENT_SOURCE'    => $config['NB_MAX_MYSQL_CLIENT_SOURCE'] ?? 50,
                ];

                foreach ($remote_config as $parameter => $value) {
                    if (!$value) {
                        throw new Exception($parameter . ' can not be empty.');
                        continue;
                    }
                    if ($parameter === 'NB_MAX_MYSQL_CLIENT_SOURCE' && !is_int($value)) {
                        throw new Exception($parameter . ' should be integer.');
                    }
                }

                if (!filter_var($remote_config['DB_HOST_SOURCE'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new Exception('DB_HOST_SOURCE is not valid.');
                }

                $this->config = array_merge($this->config, $remote_config);
            }

        } catch (Exception $e) {
            echo 'Error: ' . $e->getMessage(). PHP_EOL;
            exit(1);
        }
    }

    /**
     * Load helper functions
     */
    protected function load_helpers()
    {
        foreach (glob(__DIR__ . "/helpers/*Helper.php") as $filename)
        {
            require_once $filename;
        }
    }

    /**
     * Load provider functions
     */
    protected function load_providers()
    {
        foreach (glob(__DIR__ . "/providers/*Provider.php") as $filename)
        {
            require_once $filename;
            $className = "\\Globalis\\MysqlDataAnonymizer\\Provider\\" . basename($filename, ".php");
            if (class_exists($className)) {
                $this->generator->addProvider(new $className($this->generator));
            }
        }
    }

    /**
     * Setter for generator.
     *
     * @param mixed $generator
     *
     * @return $this
     */
    public function setGenerator($generator)
    {
        $this->generator = $generator;

        return $this;
    }

    /**
     * Getter for generator.
     *
     * @return mixed
     */
    public function getGenerator()
    {
        return $this->generator;
    }

    /**
     * Perform data anonymization.
     *
     * @return void
     */
    public function run()
    {
        if ($this->is_remote) {
            $this->remote_run();
        }
        else {
            Amp\Loop::run(function () {
                $promises = [];
                $promise_count = 0;
                yield $this->disableForeignKeyCheck();
                foreach ($this->blueprints as $index => $blueprint) {
                    if (empty($blueprint->columns)) {
                        continue;
                    }

                    $table = $blueprint->table;
                    $this->setGenerator(\Faker\Factory::create($this->config['DEFAULT_GENERATOR_LOCALE']));
                    $this->load_providers();
                    foreach ($blueprint->synchroColumns as $column_name => $data) {
                        $trigger_name = "mysql_data_anonymizer_trigger_" . count($blueprint->triggers);
                        yield $this->mysql_pool->query("DROP TRIGGER IF EXISTS {$trigger_name}");
                        try {
                            yield $this->addUpdateTrigger($blueprint, $column_name, $data);
                        } catch (TypeError $e) {
                            echo "Caught as type error: ".$e->getMessage().PHP_EOL;
                        }
                    }

                    $selectData = yield $this->getSelectData($table, $blueprint);
                    $rowNum = 0;

                    //Update every line selected
                    while (yield $selectData->advance()) {
                        $row = $selectData->getCurrent();
                        $promises[] = $this->updateByPrimary(
                            $blueprint,
                            Helpers\GeneralHelper::arrayOnly($row, $blueprint->primary),
                            $blueprint->columns,
                            $rowNum,
                            $row);

                        $promise_count ++;
                        $rowNum ++;

                        //Wait for all the results of SQL queries and clear the promise table
                        if($promise_count > $this->config['NB_MAX_PROMISE_IN_LOOP']) {
                            yield \Amp\Promise\all($promises);
                            $promises = [];
                            $promise_count = 0;
                        }
                    }

                    foreach ($blueprint->triggers as $key => $trigger) {
                        yield $this->deleteTrigger($trigger);
                        unset($blueprint->triggers[$key]);
                    }
                }
            });
        }
    }


    /**
     * Perform data anonymization cross 2 MySQL servers.
     *
     * @return void
     */
    protected function remote_run()
    {
        Amp\Loop::run(function () {
            $promises = [];
            $promise_count = 0;
            yield $this->disableForeignKeyCheck();

            $foreignKeyTable = [];
            $foreignKeys = yield $this->getRelatedForeignKeys(true);
            while (yield $foreignKeys->advance()) {
                $foreignKeyTable[] = $foreignKeys->getCurrent();
            }
            
            $this->reorderBlueprints($foreignKeyTable);

            foreach ($this->blueprints as $index => $blueprint) {
                $table = $blueprint->table;
                $this->setGenerator(\Faker\Factory::create($this->config['DEFAULT_GENERATOR_LOCALE']));
                $this->load_providers();
                $foreign_keys = yield $this->getRelatedForeignKeys(false, [$table]);
                $exclude_foreign_keys = [];
                while(yield $foreign_keys->advance()) {
                    $exclude_foreign_keys[] = $this->getForeignKeyString($foreign_keys->getCurrent());
                }

                $create_table_request = yield $this->getCreateTableRequest($table);
                if(yield $create_table_request->advance()) {
                    $create_table_request = $create_table_request->getCurrent()['Create Table'];

                    $create_table_request = str_replace($exclude_foreign_keys, '', $create_table_request);

                    yield $this->mysql_pool->query('DROP TABLE IF EXISTS '. $table);
                    yield $this->mysql_pool->query($create_table_request);
                }

                $selectData = yield $this->getSelectData($table, $blueprint, true);
                $rowNum = 0;

                //Update every line selected
                while (yield $selectData->advance()) {
                    $row = $selectData->getCurrent();
                    yield $this->disableForeignKeyCheck();
                    foreach($this->insertLine(
                        $blueprint,
                        Helpers\GeneralHelper::arrayOnly($row, $blueprint->primary),
                        $blueprint->columns,
                        $rowNum,
                        $row) as $promise) {
                        $promises[] = $promise;

                        $promise_count ++;
                        $rowNum ++;

                        //Wait for all the results of SQL queries and clear the promise table
                        if($promise_count > $this->config['NB_MAX_PROMISE_IN_LOOP']) {
                            yield \Amp\Promise\all($promises);
                            $promises = [];
                            $promise_count = 0;
                        }
                    }
                }
            }

            foreach($foreignKeyTable as $row) {
                yield $this->restoreForeignKey($row);
            }
        });
    }


    /**
     * Describe a table with a given callback.
     *
     * @param string   $name
     * @param callable $callback
     *
     * @return void
     */
    public function table($name, callable $callback = NULL)
    {
        $blueprint = new Blueprint($name, $this->config['DB_NAME'], $callback);

        $this->blueprints[] = $blueprint->build();
    }


    /**
     * Calculate new value for each row.
     *
     * @param string|callable $replace
     * @param int             $rowNum
     *
     * @return string
     */
    protected function calculateNewValue($replace, $rowNum)
    {
        $value = $this->handlePossibleClosure($replace);

        return $this->replacePlaceholders($value, $rowNum);
    }

    /**
     * Replace placeholders.
     *
     * @param mixed $value
     * @param int   $rowNum
     *
     * @return mixed
     */
    protected function replacePlaceholders($value, $rowNum)
    {
        if (!is_string($value)) {
            return $value;
        }

        return str_replace('#row#', $rowNum, $value);
    }

    /**
     * @param $replace
     *
     * @return mixed
     */
    protected function handlePossibleClosure($replace)
    {
        if (!is_callable($replace)) {
            return $replace;
        }

        if ($this->generator === null) {
            throw new Exception('You forgot to set a generator');
        }

        return call_user_func($replace, $this->generator);
    }

    /**
     * Update a line by primary key given
     *
     * @param array $blueprint
     * @param array $primaryKeyValues
     * @param array $columns
     * @param int $rowNum
     * @param array $row
     *
     * @return promise
     */
    public function updateByPrimary($blueprint, $primaryKeyValues, $columns, $rowNum, $row)
    {
        $where = $this->buildWhereForArray($primaryKeyValues);

        $set = $this->buildSetForArray($columns, $rowNum, $row);

        $sql = "UPDATE
                    {$blueprint->table}
                SET
                    {$set}
                WHERE
                    {$where}";

        return $this->mysql_pool->query($sql);
    }

    /**
     * (Remote operation only)
     * Insert a line
     *
     * @param array $blueprint
     * @param array $primaryKeyValues
     * @param array $columns
     * @param int $rowNum
     * @param array $row
     *
     * @return promise
     */
    public function insertLine($blueprint, $primaryKeyValues, $columns, $rowNum, $row)
    {
        $set_and_synchro = $this->buildSetForArray($columns, $rowNum, $row, $blueprint);

        $sql = "INSERT INTO 
                    {$blueprint->table}
                SET
                    {$set_and_synchro['set']}";

        $returnPromises = [];
        $returnPromises[] = $this->mysql_pool->query($sql);
        if(!empty($set_and_synchro['synchro'])) {
            foreach ($set_and_synchro['synchro'] as $synchroStatement) {
                $returnPromises[] = $this->mysql_pool->query($synchroStatement);
            }
        }

        return $returnPromises;
    }

    /**
     * Get lines that need to be updated
     *
     * @param array $table
     * @param Blueprint $blueprint
     *
     * @return Promise
     */
    protected function getSelectData($table, $blueprint)
    {
        if ($this->is_remote) {
            $columns = '*';
        } else {
            foreach ($blueprint->columns as $column) {
                if ($column['replaceByFields']) {
                    $columns = '*';
                    break;
                }
            }
        }

        if(!($columns ?? false)) {
            $columns = implode(',', array_merge($blueprint->primary, array_column($blueprint->columns, 'name')));
        }
        $sql = "SELECT {$columns} FROM {$table}";

        if($blueprint->globalWhere) {
            $sql .= " WHERE " . $blueprint->globalWhere;
        }

        if ($this->is_remote) {
            return $this->mysql_pool_source->query($sql);
        }

        return $this->mysql_pool->query($sql);
    }

     /**
     * Build SQL where for key-value array.
     *
     * @param array $primaryKeyValue
     *
     * @return string
     */
    protected function buildWhereForArray($primaryKeyValue)
    {
        $where = [];
        foreach ($primaryKeyValue as $key => $value) {
            $where[] = "{$key}='{$value}'";
        }

        return implode(' AND ', $where);
    }

    /**
     * Build SQL set for key-value array.
     *
     * @param array $columns
     * @param int $rowNum
     * @param array $row
     * @param Blueprint $blueprint
     *
     * @return string
     */
    protected function buildSetForArray($columns, $rowNum, $row, $blueprint = null)
    {
        $set = [];
        $synchroStatements = [];
        foreach ($columns as $column) {
            $originalData = $row[$column['name']];
            if (is_callable($column['replaceByFields'])) {
                $row[$column['name']] = call_user_func($column['replaceByFields'], $row, $this->generator);
            }

            if ($column['replace']) {
                $row[$column['name']] = $this->calculateNewValue($column['replace'], $rowNum);
            }

            $row[$column['name']] = addslashes($row[$column['name']]);

            if (empty($column['where'])) {
                $set[] = "{$column['name']}='{$row[$column['name']]}'";
            } else {
                $set[] = "{$column['name']}=(
                    CASE 
                      WHEN {$column['where']} THEN '{$row[$column['name']]}'
                      ELSE {$column['name']}
                    END)";
            }

            if ($this->is_remote && isset($blueprint->synchroColumns[$column['name']]) && $originalData !== $row[$column['name']]) {
                foreach($blueprint->synchroColumns[$column['name']] as $index => $columnInfo) {
                    $synchroStatements[] = $this->buildSynchroStatement($columnInfo, $row[$column['name']], $originalData);
                }
            }
        }

        if ($this->is_remote) {

            $updated_columns = array_column($columns, 'name');
            foreach ($row as $name => $value) {
                if(!in_array($name, $updated_columns)) {
                    if(is_null($value)) {
                        $set[] = "{$name} = NULL";
                    } else {
                        $value = addslashes($value);
                        $set[] = "{$name} = '{$value}'";
                    }
                }
            }
            
            return [
                'set' => implode(' ,', $set),
                'synchro' => $synchroStatements
            ];
        }

        return implode(' ,', $set);
    }

    /**
     * Build SQL statement for fields need synchonization
     *
     * @param array  $columnInfo
     * @param string $newData
     * @param string $originalData
     *
     * @return string
     */
    protected function buildSynchroStatement($columnInfo, $newData, $originalData)
    {
        if($columnInfo['table'] && $columnInfo['database']) {
            $table = $columnInfo['database'] . '.' . $columnInfo['table']; 
        } else {
            $table = $columnInfo['table'];
        }
        $sql = "UPDATE
                    {$table}
                SET
                    {$columnInfo['field']}='{$newData}'
                WHERE
                    {$columnInfo['field']}='{$originalData}'";
        return $sql;
    }

    /**
     * Add a trigger to automatically update related fields when a field is updated
     *
     * @param Blueprint $blueprint
     * @param string $column_name
     * @param array $data
     *
     * @return Promise
     */
    protected function addUpdateTrigger(&$blueprint, $column_name, $data)
    {
        $trigger_name = "mysql_data_anonymizer_trigger_" . count($blueprint->triggers);
        $blueprint->triggers[] = $trigger_name; 

        $sql = "
                DROP TRIGGER IF EXISTS {$trigger_name};
                CREATE TRIGGER {$trigger_name} AFTER UPDATE 
                ON {$blueprint->table} FOR EACH ROW BEGIN ";

        foreach ($data as $column_update) {
            $sql .= "
                    UPDATE {$column_update['table']}
                    SET {$column_update['table']}.{$column_update['field']} = NEW.{$column_name}
                    WHERE {$column_update['table']}.{$column_update['field']} = OLD.{$column_name};
                ";
        }

        $sql .= " END";
        return $this->mysql_pool->query($sql);
    }

    /**
     * Drop a foreign key by the name
     *
     * @param string $trigger_name
     *
     * @return Promise
     */
    protected function deleteTrigger($trigger_name)
    {
        $sql = "DROP TRIGGER IF EXISTS {$trigger_name}";
        return $this->mysql_pool->query($sql);
    }


    /**
     * Disable the foreign key check for this sesssion
     *
     * @return Promise
     */
    protected function disableForeignKeyCheck()
    {
        $sql = "SET FOREIGN_KEY_CHECKS=0;";
        return $this->mysql_pool->query($sql);
    }

    /**
     * (Remote operation only)
     * Get a query for creating an existing table
     *
     * @param string $table_name
     *
     * @return Promise
     */
    protected function getCreateTableRequest($table_name)
    {
        $sql = "SHOW CREATE TABLE {$table_name}";
        return $this->mysql_pool_source->query($sql);
    }

    /**
     * (Remote operation only)
     * Restore a foreign key
     *
     * @param string $foreignKey
     *
     * @return Promise
     */
    protected function restoreForeignKey($foreignKey)
    {
        $table_name = $this->config['DB_NAME'] . '.' . $foreignKey['TABLE_NAME'];
        $source_table_name = $this->config['DB_NAME'] . '.' . $foreignKey['REFERENCED_TABLE_NAME'];
        $sql = "
                ALTER TABLE {$table_name}
                ADD CONSTRAINT {$foreignKey['CONSTRAINT_NAME']}
                FOREIGN KEY ({$foreignKey['COLUMN_NAME']}) 
                REFERENCES {$source_table_name}({$foreignKey['REFERENCED_COLUMN_NAME']}) 
                ON DELETE {$foreignKey['DELETE_RULE']} 
                ON UPDATE {$foreignKey['UPDATE_RULE']}
        ";
        return $this->mysql_pool->query($sql);
    }

    /**
     * (Remote operation only)
     * Get string parts to create a foreign key
     *
     * @param string $row
     *
     * @return string
     */
    protected function getForeignKeyString($row)
    {
        return ",\n  CONSTRAINT `{$row['CONSTRAINT_NAME']}` FOREIGN KEY (`{$row['COLUMN_NAME']}`) REFERENCES `{$row['REFERENCED_TABLE_NAME']}` (`{$row['REFERENCED_COLUMN_NAME']}`) ON DELETE {$row['DELETE_RULE']} ON UPDATE {$row['UPDATE_RULE']}";
    }

    /**
     * (Remote operation only)
     * Get the information of all foreign keys related to selected tables or all tables
     *
     * @param boolean $include_reference
     * @param array   $tables
     *
     * @return Promise
     */
    protected function getRelatedForeignKeys($include_reference = false, $tables = null)
    {
        if(!$tables) {
            $tables = array_column($this->blueprints, 'table');
        }

        $tables = "'" . implode("','", $tables) . "'";

        $sql = "
            SELECT
                key_column.CONSTRAINT_NAME,
                key_column.TABLE_SCHEMA,
                key_column.TABLE_NAME,
                key_column.COLUMN_NAME,
                key_column.REFERENCED_TABLE_SCHEMA,
                key_column.REFERENCED_TABLE_NAME,
                key_column.REFERENCED_COLUMN_NAME,
                referential_constraints.UPDATE_RULE,
                referential_constraints.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE key_column
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS referential_constraints ON key_column.CONSTRAINT_NAME = referential_constraints.CONSTRAINT_NAME
            WHERE key_column.REFERENCED_TABLE_SCHEMA = '{$this->config['DB_NAME_SOURCE']}'
                AND referential_constraints.UNIQUE_CONSTRAINT_SCHEMA = '{$this->config['DB_NAME_SOURCE']}'
                AND key_column.TABLE_NAME IN ({$tables})
        ";

        if($include_reference) {
            $sql .= " AND key_column.REFERENCED_TABLE_NAME IN ({$tables})";
        }
        return $this->mysql_pool_source->query($sql);
    }


    /**
     * (Remote operation only)
     * Buid a tree from given foreign keys
     *
     * @param array $foreign_keys
     *
     * @return array
     */
    protected function constructDependencyTree($foreign_keys)
    {
        $tree = [];
        foreach ($foreign_keys as $foreign_key) {
            if(!isset($tree[$foreign_key['REFERENCED_TABLE_NAME']])) {
                $tree[$foreign_key['REFERENCED_TABLE_NAME']] = [];
            }
            if(!in_array($foreign_key['TABLE_NAME'], $tree[$foreign_key['REFERENCED_TABLE_NAME']])) {
                 $tree[$foreign_key['REFERENCED_TABLE_NAME']][] = $foreign_key['TABLE_NAME'];
            }
        }
        return $tree;
    }

    /**
     * (Remote operation only)
     * Add dependency relationships to a tree
     *
     * @param array $tree
     *
     * @return array
     */
    protected function addExtraDependenceRelations($tree)
    {
        foreach ($this->blueprints as $blueprint) {
            foreach ($blueprint->synchroColumns as $synchroColumns) {
                foreach ($synchroColumns as $synchroColumn) {
                    if ($synchroColumn['database'] && $synchroColumn['database'] != $this->config['DB_NAME_SOURCE']) {
                        continue;
                    }
                    if (!isset($tree[$blueprint->table])) {
                        $tree[$blueprint->table] = [];
                    }
                    if (!in_array($synchroColumn['table'], $tree[$blueprint->table])) {
                         $tree[$blueprint->table][] = $synchroColumn['table'];
                    }
                }
            }
        }
        return $tree;
    }


    /**
     * (Remote operation only)
     * Test a dependency tree to make sure there is no circle in it
     *
     * @param array $tree
     *
     * @return array
     */
    protected function testDependencyTree($tree)
    {
        $resolved = [];
        $tables = array_column($this->blueprints, 'table');

        $tables_to_be_deleted = array_diff($tables, array_keys($tree));

        while(!empty($tables_to_be_deleted)) {
            $new_tables_to_be_delected = [];
            foreach($tables_to_be_deleted as $table_to_be_deleted) {
                foreach ($tree as $index => $node) {
                    $tree[$index] = array_diff($node, [$table_to_be_deleted]);
                    if(empty($tree[$index])) {
                        unset($tree[$index]);
                        $new_tables_to_be_delected[] = $index;
                    }
                }
                $resolved[] = $table_to_be_deleted;
            }
            $tables_to_be_deleted = $new_tables_to_be_delected;
        }

        return [
            'success' => empty($tree),
            'order'   => $resolved,
            'round'   => $tree
        ];
    }

    /**
     * (Remote operation only)
     * Sort the anonymization process according to given foreign keys
     *
     * @param array $foreign_keys
     *
     * @return array
     */
    protected function reorderBlueprints($foreign_keys)
    {
        $tree   = $this->constructDependencyTree($foreign_keys);
        $tree   = $this->addExtraDependenceRelations($tree);
        $result = $this->testDependencyTree($tree);

        try {
            if(!$result['success']) {
                throw new Exception(' The dependent relationship of these tables can not be resolved : ' . implode(",", array_keys($result['round'])));
            } else {
                $new_order = $result['order'];
            }
        } catch(Exception $e) {
            echo 'Error: ' . $e->getMessage(). PHP_EOL;
            exit(1);
        }

        $new_blueprints = [];
        foreach ($this->blueprints as $key => $blueprint) {
            $new_blueprints[array_search($blueprint->table, $new_order)] = $blueprint;
        }

        ksort($new_blueprints);
        $this->blueprints = $new_blueprints;
    }
}
