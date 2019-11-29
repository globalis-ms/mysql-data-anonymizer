<?php

namespace Globalis\MysqlDataAnonymizer;

use Amp;
use Amp\Promise;
use Amp\Mysql;
use Exception;
use Globalis\MysqlDataAnonymizer\Helpers;

require 'ControlledConnectionPool.php';

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
    public function __construct($config)
    {
        if (empty($config)) {
            $this->load_config();
        } else {
            $this->config = $config;
        }
        $this->is_remote = $this->config['IS_REMOTE'];
        $this->load_helpers();

        $this->mysql_pool = controlledConnectionPool(Mysql\ConnectionConfig::fromString("host=".$this->config['DB_HOST'].";user=".$this->config['DB_USER'].";pass=".$this->config['DB_PASSWORD'].";db=". $this->config['DB_NAME']), $this->config['NB_MAX_MYSQL_CLIENT']);

        $this->disableForeignKeyCheck();
        
        if ($this->is_remote) {
            $this->mysql_pool_source = Mysql\pool(Mysql\ConnectionConfig::fromString("host=".$this->config['DB_HOST_SOURCE'].";user=".$this->config['DB_USER_SOURCE'].";pass=".$this->config['DB_PASSWORD_SOURCE'].";db=". $this->config['DB_NAME_SOURCE']), $this->config['NB_MAX_MYSQL_CLIENT_SOURCE']);
        }

        try {
            if (!class_exists("\Faker\Factory")) {
                throw new Exception("Fzaninotto/Faker can not be found.");
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
                'DEFAULT_GENERATOR_LOCALE'  => $config['DEFAULT_GENERATOR_LOCALE'] ?? 'en_US',
                'IS_REMOTE'                 => $config['IS_REMOTE'] ?? false
             ];

            foreach ($this->config as $parameter => $value) {
                if (!isset($value) || $value === '') {
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

            if ($config['IS_REMOTE']) {
                $remote_config = [
                    'DB_HOST_SOURCE'                => $config['DB_HOST_SOURCE'] ?? '',
                    'DB_NAME_SOURCE'                => $config['DB_NAME_SOURCE'] ?? '',
                    'DB_USER_SOURCE'                => $config['DB_USER_SOURCE'] ?? '',
                    'DB_PASSWORD_SOURCE'            => $config['DB_PASSWORD_SOURCE'] ?? '',
                    'NB_MAX_MYSQL_CLIENT_SOURCE'    => $config['NB_MAX_MYSQL_CLIENT_SOURCE'] ?? 50,
                ];

                foreach ($remote_config as $parameter => $value) {
                    if (!isset($value) || $value === '') {
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
        Amp\Loop::run(function () {
            $promises = [];
            $promise_count = 0;
            $this->sortBlueprints();

            if ($this->is_remote) {
                $tables = array_column($this->blueprints, 'table');
                foreach ($tables as $table) {
                    yield $this->mysql_pool->query('DROP TABLE IF EXISTS '. $table);
                }

                foreach (array_reverse($tables) as $table) {
                    $create_table_request = yield $this->getCreateTableRequest($table);
                    if (yield $create_table_request->advance()) {
                        $create_table_request = $create_table_request->getCurrent()['Create Table'];
                        yield $this->mysql_pool->query($create_table_request);
                    }
                }
            }

            foreach ($this->blueprints as $index => $blueprint) {
                if (empty($blueprint->columns) && !$this->is_remote) {
                    continue;
                }

                $table = $blueprint->table;
                $this->setGenerator(\Faker\Factory::create($this->config['DEFAULT_GENERATOR_LOCALE']));
                $this->load_providers();

                $selectData = yield $this->getSelectData($table, $blueprint, true);
                $rowNum = 0;

                if ($this->is_remote) {
                    $method = 'insertLine';
                } else {
                    $method = 'updateByPrimary';
                }

                //Update every line selected
                while (yield $selectData->advance()) {

                    $row = $selectData->getCurrent();

                    $current_promises = $this->$method(
                        $blueprint,
                        Helpers\GeneralHelper::arrayOnly($row, $blueprint->primary),
                        $blueprint->columns,
                        $rowNum,
                        $row
                    );

                    foreach ($current_promises as $promise) {
                        $promises[] = $promise;
                        $promise_count ++;
                        $rowNum ++;

                        //Wait for all the results of SQL queries and clear the promise table
                        if ($promise_count > $this->config['NB_MAX_PROMISE_IN_LOOP']) {
                            yield \Amp\Promise\all($promises);
                            $promises = [];
                            $promise_count = 0;
                        }
                    }
                }
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
     * Describe a table with a given callback.
     *
     * @param Blueprint   $table
     *
     * @return void
     */
    public function addTable(Blueprint $table)
    {
        $this->blueprints[] = $table->build();
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

        $set_and_after = $this->buildSetForArray($columns, $rowNum, $row, $blueprint);

        $sql = "UPDATE
                    {$blueprint->table}
                SET
                    {$set_and_after['set']}
                WHERE
                    {$where}";

        $returnPromises = [];
        $returnPromises[] = $this->mysql_pool->query($sql);
        if (!empty($set_and_after['afterQueries'])) {
            foreach ($set_and_after['afterQueries'] as $afterQuery) {
                $returnPromises[] = $this->mysql_pool->query($afterQuery);
            }
        }

        return $returnPromises;
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
        $set_and_after = $this->buildSetForArray($columns, $rowNum, $row, $blueprint);

        $sql = "INSERT INTO
                    {$blueprint->table}
                SET
                    {$set_and_after['set']}";

        $returnPromises = [];
        $returnPromises[] = $this->mysql_pool->query($sql);
        if (!empty($set_and_after['afterQueries'])) {
            foreach ($set_and_after['afterQueries'] as $afterQuery) {
                $returnPromises[] = $this->mysql_pool->query($afterQuery);
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

        if (!($columns ?? false)) {
            $columns = implode(',', array_merge($blueprint->primary, array_column($blueprint->columns, 'name')));
        }
        $sql = "SELECT {$columns} FROM {$table}";

        if ($blueprint->globalWhere) {
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
            $value = addslashes($value);
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
    protected function buildSetForArray($columns, $rowNum, $row, $blueprint)
    {
        $set = [];
        $originalData = $row;
        foreach ($columns as $column) {
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
        }

        if ($this->is_remote) {

            $updated_columns = array_column($columns, 'name');
            foreach ($row as $name => $value) {
                if (!in_array($name, $updated_columns)) {
                    if (is_null($value)) {
                        $set[] = "{$name} = NULL";
                    } else {
                        $value = addslashes($value);
                        $set[] = "{$name} = '{$value}'";
                    }
                }
            }
        }

        $afterQueries = [];
        foreach ($blueprint->after as $afterQuery) {
            if (is_callable($afterQuery)) {
                $afterQueries = call_user_func($afterQuery, $originalData, $row, $this->generator);
            }
        }

        return [
            'set' => implode(' ,', $set),
            'afterQueries' => $afterQueries
        ];
    }

    /**
     * Disable the foreign key check for this sesssion
     *
     * @return Promise
     */
    protected function disableForeignKeyCheck()
    {
        Amp\Loop::run(function () {
            $connections = [];
            for ($i = 0; $i < $this->config['NB_MAX_MYSQL_CLIENT']; $i++) {
                $connections[] = yield $this->mysql_pool->extractConnection();
            }

            foreach ($connections as $connection) {
                yield $connection->query("SET FOREIGN_KEY_CHECKS=0;");
                $this->mysql_pool->pushToPool($connection);
            }
        });
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
     *
     * Build a dependency tree
     *
     * @param array $tree
     *
     * @return array
     */
    protected function constructDependencyTree()
    {
        $tree = [];
        foreach ($this->blueprints as $blueprint) {
            if (!empty($blueprint->dependencies)) {
                $tree[$blueprint->table] = $blueprint->dependencies;
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

        while (!empty($tables_to_be_deleted)) {
            $new_tables_to_be_delected = [];
            foreach ($tables_to_be_deleted as $table_to_be_deleted) {
                foreach ($tree as $index => $node) {
                    $tree[$index] = array_diff($node, [$table_to_be_deleted]);
                    if (empty($tree[$index])) {
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
     * @return array
     */
    protected function sortBlueprints()
    {
        $tree   = $this->constructDependencyTree();
        $result = $this->testDependencyTree($tree);

        try {
            if (!$result['success']) {
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
