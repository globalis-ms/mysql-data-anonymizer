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
     * Database interactions object.
     *
     * @var DatabaseInterface
     */
    protected $mysql_pool;

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
     * @param mixed             $generator
     */
    public function __construct($generator = null)
    {
    	$this->load_config();
        $this->load_helpers();

        $this->mysql_pool = Mysql\pool(Mysql\ConnectionConfig::fromString("host=".$this->config['DB_HOST'].";user=".$this->config['DB_USER'].";pass=".$this->config['DB_PASSWORD'].";db=". $this->config['DB_NAME']), $this->config['NB_MAX_MYSQL_CLIENT']);

        if (is_null($generator) && class_exists('\Faker\Factory')) {
            $generator = \Faker\Factory::create($this->config['DEFAULT_GENERATOR_LOCALE']);
        }

        if (!is_null($generator)) {
            $this->setGenerator($generator);
        }
        $this->load_providers();
    }

    protected function load_config()
    {
        try {
            if (!file_exists(__DIR__ . "/../config/config.php")) {
                throw new Exception('config.php not found in the directory.');
            }
            $this->config = require __DIR__ . "/../config/config.php";

             $this->config = [
                'DB_HOST'                   => $this->config['DB_NAME'] ?? '127.0.0.1',
                'DB_NAME'                   => $this->config['DB_NAME'] ?? '',
                'DB_USER'                   => $this->config['DB_USER'] ?? '',
                'DB_PASSWORD'               => $this->config['DB_PASSWORD'] ?? '',
                'NB_MAX_MYSQL_CLIENT'       => $this->config['NB_MAX_MYSQL_CLIENT'] ?? 20,
                'NB_MAX_PROMISE_IN_LOOP'    => $this->config['NB_MAX_PROMISE_IN_LOOP'] ?? 20,
                'DEFAULT_GENERATOR_LOCALE'  => $this->config['DEFAULT_GENERATOR_LOCALE'] ?? 'en_US'
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
        } catch (Exception $e) {
            echo 'Exception: ' . $e->getMessage(). PHP_EOL;
            exit(1);
        }
    }


    protected function load_helpers()
    {
        foreach (glob(__DIR__ . "/helpers/*Helper.php") as $filename)
        {
            require_once $filename;
        }
    }

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
            foreach ($this->blueprints as $table => $blueprint) {
                $results = [];
                $foreign_keys = yield $this->getRelatedForeignKeys($blueprint);
                while (yield $foreign_keys->advance()) {
                    $results[] = $foreign_keys->getCurrent();
                }

                $blueprint = $this->filterAndBackUpForeignKeys($results, $blueprint);

                foreach ($blueprint->foreignKeys as $foreignKey) {
                    yield $this->removeForeignKey($foreignKey);
                }

                foreach ($blueprint->synchroColumns as $column_name => $data) {
                    yield $this->addUpdateTrigger($blueprint, $column_name, $data);
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

                foreach ($blueprint->foreignKeys as $foreignKey) {
                    yield $this->restoreForeignKey($foreignKey);
                }
                $blueprint->foreignKeys = [];

                foreach ($blueprint->triggers as $key => $trigger) {
                    yield $this->deleteTrigger($trigger);
                    unset($blueprint->triggers[$key]);
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
    public function table($name, callable $callback)
    {
        $blueprint = new Blueprint($name, $callback);

        $this->blueprints[$name] = $blueprint->build();
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

        return addslashes($this->replacePlaceholders($value, $rowNum));
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
     * Get lines that need to be updated
     *
     * @param array $table
     * @param Blueprint $blueprint
     *
     * @return Promise
     */
    protected function getSelectData($table, $blueprint)
    {
        foreach ($blueprint->columns as $column) {
            if ($column['replaceByFields']) {
                $columns = '*';
                break;
            }
        }

        if($columns ?? false) {
            $columns = implode(',', array_merge($blueprint->primary, array_column($blueprint->columns, 'name')));
        }
        $sql = "SELECT {$columns} FROM {$table}";

        if($blueprint->globalWhere) {
            $sql .= " WHERE " . $blueprint->globalWhere;
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
     *
     * @return string
     */
    protected function buildSetForArray($columns, $rowNum, $row)
    {
        $set = [];
        $synchroStatements = [];
        foreach ($columns as $column) {

            $originalData = $row[$column['name']];
            if ($column['replaceByFields']) {
                $row[$column['name']] = call_user_func($column['replaceByFields'], $row, $this->generator);
            }

            if ($column['replace']) {
                $row[$column['name']] = $this->calculateNewValue($column['replace'], $rowNum);
            }

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

        return implode(' ,', $set);
    }


    /**
     * Get the information of all foreign keys related to current table
     *
     * @param Blueprint $blueprint
     *
     * @return Promise
     */
    protected function getRelatedForeignKeys($blueprint)
    {
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
            WHERE key_column.REFERENCED_TABLE_SCHEMA = '{$this->config['DB_NAME']}'
                AND referential_constraints.UNIQUE_CONSTRAINT_SCHEMA = '{$this->config['DB_NAME']}'
                AND key_column.REFERENCED_TABLE_NAME = '{$blueprint->table}'
        ";

        return $this->mysql_pool->query($sql);
    }

    /**
     * Ignore all foreign keys which automatically update the values and save other foreign keys' info into an array 
     *
     * @param array $foreignKey
     * @param Blueprint $blueprint
     *
     * @return Promise
     */
    protected function filterAndBackUpForeignKeys($foreignKey, $blueprint)
    {
        foreach($blueprint->synchroColumns as $currentColumnName => &$synchroColumn) {
            foreach ($synchroColumn as $index => &$column) {
                if(!$column['database']) {
                    $column['database'] = $this->config['DB_NAME'];
                }

                $search = array_filter($foreignKey, function($value) use ($currentColumnName, $column, $blueprint) {
                    return $value['TABLE_SCHEMA'] === $column['database'] && $value['TABLE_NAME'] === $column['table'] && $value['COLUMN_NAME'] === $column['field'] && $value['REFERENCED_COLUMN_NAME'] === $currentColumnName && $value['REFERENCED_TABLE_NAME'] === $blueprint->table && $value['REFERENCED_TABLE_SCHEMA'] === $this->config['DB_NAME'];
                });

                if(count($search)) {
                    if($search[0]['UPDATE_RULE'] === 'CASCADE') {
                        unset($synchroColumn[$index]);
                    } else {
                        $blueprint->backUpForeignKey($search[0]);
                    }
                }
            }

            if(empty($synchroColumn)) {
                unset($blueprint->synchroColumns[$currentColumnName]);
            }
        }

        return $blueprint;
    }

    /**
     * Drop a foreign key
     *
     * @param array $foreignKey
     *
     * @return Promise
     */
    protected function removeForeignKey($foreignKey)
    {
        $table_name = $foreignKey['TABLE_SCHEMA'] . '.' . $foreignKey['TABLE_NAME'];
        $sql = "
                ALTER TABLE {$table_name}
                DROP FOREIGN KEY {$foreignKey['CONSTRAINT_NAME']}
        ";

        return $this->mysql_pool->query($sql);
    }


     /**
     * Retore a foreign key by the information stored in the blueprint
     *
     * @param array $foreignKey
     *
     * @return Promise
     */
    protected function restoreForeignKey($foreignKey)
    {
        $table_name = $foreignKey['TABLE_SCHEMA'] . '.' . $foreignKey['TABLE_NAME'];
        $source_table_name = $foreignKey['REFERENCED_TABLE_SCHEMA'] . '.' . $foreignKey['REFERENCED_TABLE_NAME'];
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
}
