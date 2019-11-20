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
     * The operations after line anonymized.
     *
     * @var array
     */
    public $after = [];

    /**
     * Tables the current table depend on.
     *
     * @var array
     */
    public $dependencies = [];

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
     * @return $this
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
     * @param array   $parameters
     * @param boolean $is_unique
     * @param boolean $is_optional
     * @param mixed   $default_value
     * @param decimal $optional_weight
     *
     * @return $this
     */
    public function replaceWithGenerator($data_type, $parameters = [], $is_unique = false, $is_optional = false, $default_value = null, $optional_weight = null)
    {
        $closure = function ($generator) use($data_type, $parameters, $is_unique, $is_optional, $default_value, $optional_weight) {
            $final_generator = $generator;
            if ($is_unique) {
                $final_generator = $final_generator->unique();
            }
            if ($is_optional) {
                if ($default_value && $optional_weight) {
                    $final_generator = $final_generator->optional($weight = $optional_weight, $default = $default_value);
                } elseif ($default_value) {
                    $final_generator = $final_generator->optional($default = $default_value);
                } elseif ($optional_weight) {
                    $final_generator = $final_generator->optional($weight = $optional_weight);
                } else {
                    $final_generator = $final_generator->optional();
                }
            }

            if (empty($parameters)) {
                return $final_generator->$data_type;
            }
            return $final_generator->$data_type(...$parameters);
        };

        return $this->replaceWith($closure);
    }

    /**
     * Do whatever after the anonymization of every line
     *
     * @param callable|string $callback
     *
     * @return void
     */
    public function doAfterUpdate($callback, $dependencies = [])
    {
        $this->after[] = $callback;
        if ($index = array_search($this->table, $dependencies)) {
            unset($dependencies[$index]);
        }
        $this->dependencies = array_unique(array_merge($dependencies, $this->dependencies));
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

        if (is_callable($callback)) {
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
