<?php

namespace Globalis\MysqlDataAnonymizer;

class Blueprint
{
    /**
     * Default primary key for all blueprints.
     *
     * @var array
     */
    protected static $defaultPrimary = ['id'];

    /**
     * Database to blueprint.
     *
     * @var string
     */
    public $db_name;

    /**
     * Table to blueprint.
     *
     * @var string
     */
    public $table;

    /**
     * Array of columns.
     *
     * @var array
     */
    public $columns = [];

    /**
     * Table primary key.
     *
     * @var array
     */
    public $primary;

    /**
     * Global Where statement.
     *
     * @var array
     */
    public $globalWhere = null;

    /**
     * Current column.
     *
     * @var array
     */
    protected $currentColumn = [];

    /**
     * The columns that need be synchronized.
     *
     * @var array
     */
    public $synchroColumns = [];

    /**
     * Names of triggers created.
     *
     * @var array
     */
    public $triggers = [];

    /**
     * Callback that builds blueprint.
     *
     * @var callable
     */
    protected $callback;

    /**
     * Blueprint constructor.
     *
     * @param string        $table
     * @param callable|null $callback
     */
    public function __construct($table, $db_name, callable $callback = NULL)
    {
        $this->table = $table;
        $this->$db_name = $db_name;
        $this->callback = $callback;
    }

    /**
     * Setter for default primary key.
     *
     * @param string|array $key
     */
    public static function setDefaultPrimary($key)
    {
        self::$defaultPrimary = (array) $key;
    }

    /**
     * Add a column to blueprint.
     *
     * @param string $name
     *
     * @return $this
     */
    public function column($name)
    {
        $this->currentColumn = [
            'name'            => $name,
            'where'           => null,
            'replace'         => null,
            'replaceByFields' => null
        ];

        return $this;
    }

    /**
     * Add where to the current column.
     *
     * @param string $rawSql
     *
     * @return $this
     */
    public function where($rawSql)
    {
        $this->currentColumn['where'] = $rawSql;

        return $this;
    }

    /**
     * Add where to the global query
     *
     * @param string $rawSql
     *
     * @return $this
     */
    public function globalWhere($rawSql)
    {
        $this->globalWhere[] = $rawSql;

        return $this;
    }

    /**
     * Set how data should be replaced.
     *
     * @param callable|string $callback
     *
     * @return void
     */
    public function replaceWith($callback)
    {
        $this->currentColumn['replace'] = $callback;

        $this->columns[] = $this->currentColumn;

        return $this;
    }

    /**
     * A simple method to set data with generator
     *
     * @param string  $data_type
     * @param boolean $is_unique
     *
     * @return void
     */
    public function replaceWithGenerator($data_type, $is_unique = false)
    {
        if($is_unique) {
            $closure = function ($generator) use($data_type) {
                return $generator->unique()->$data_type;
            };
        } else {
            $closure = function ($generator) use($data_type) {
                return $generator->$data_type;
            };
        }

        return $this->replaceWith($closure);
    }

    /**
     * Save all columns that need to be synchronized..
     *
     * @param array $synchroData
     *
     * @return void
     */
    public function synchronizeColumn()
    {
        $synchroData = (array) func_get_args();

        if (!isset($this->synchroColumns[$this->currentColumn['name']])) {
            $this->synchroColumns[$this->currentColumn['name']] = [];
        }

        foreach ($synchroData as $synchroField) {
            $this->synchroColumns[$this->currentColumn['name']][] = [
                'field'           => $synchroField[0],
                'table'           => $synchroField[1] ?? $this->table,
                'database'        => $synchroField[2] ?? $this->db_name,
            ];
        }

        return $this;
    }

    /**
     * Set how data should be replaced.
     *
     * @param callable|string $callback
     *
     * @return void
     */
    public function replaceByFields($callback)
    {
        $this->currentColumn['replaceByFields'] = $callback;

        $this->columns[] = $this->currentColumn;
    }

    /**
     * Build the current blueprint.
     *
     * @return $this
     */
    public function build()
    {
        $callback = $this->callback;

        if(is_callable($callback)) {
            $callback($this);
        }

        if (is_null($this->primary)) {
            $this->primary = self::$defaultPrimary;
        }

        return $this;
    }

    /**
     * Setter for a primary key.
     *
     * @param string|array $key
     *
     * @return $this
     */
    public function primary($key)
    {
        $this->primary = (array) $key;

        return $this;
    }
}
