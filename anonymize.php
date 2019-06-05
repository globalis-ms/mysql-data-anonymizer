<?php

require './vendor/autoload.php';
use Globalis\MysqlDataAnonymizer\Anonymizer;
use Globalis\MysqlDataAnonymizer\Blueprint;
use Globalis\MysqlDataAnonymizer\Helpers;

class Anonymize
{
	/**
     * Configuration array.
     *
     * @var array
     */
    protected $config = [];

    /**
     * Root directory of the project.
     *
     * @var array
     */
    protected $project_directory = null;


    /**
     * Anonymizer.$path_info
     *
     * @var array
     */
    protected $anonymizer;

    /**
     * Constructor.
     *
     * @param string $project_directory
     */
    public function __construct($project_directory)
    {	
    	$this->project_directory = $project_directory;
    	$this->load_config();
        $this->anonymizer = new Anonymizer($this->config);
    }

	/**
     * Load configuration file
     */
    protected function load_config()
    {
        try {
        	if (!is_dir("workspace/" . $this->project_directory)) {
        		throw new Exception('Project directory not found.');
        	}
            if (is_callable("yaml_parse_file") && file_exists(__DIR__ . "/config/" . $this->project_directory . ".yaml")) {
                $config = yaml_parse_file(__DIR__ . "/config/" . $this->project_directory . ".yaml");
            } elseif (file_exists(__DIR__ . "/config/" . $this->project_directory . ".php")) {
                $config = require __DIR__ . "/config/" . $this->project_directory . ".php";
            } else {
            	if (!file_exists(__DIR__ . "/config/config.php")) {
		            throw new Exception('No configuration file found in the directory.');
		        }
		        $config = require __DIR__ . "/config/config.php";
            }
            $this->_load_config($config);
        } catch (Exception $e) {
            echo 'Error: ' . $e->getMessage(). PHP_EOL;
            exit(1);
        }
    }

    /**
     * Load configuration file
     */
    protected function _load_config($config)
    {
        $this->config = [
            'DB_HOST'                   => $config['DB_HOST'] ?? '127.0.0.1',
            'DB_NAME'                   => $config['DB_NAME'] ?? '',
            'DB_USER'                   => $config['DB_USER'] ?? '',
            'DB_PASSWORD'               => $config['DB_PASSWORD'] ?? '',
            'NB_MAX_MYSQL_CLIENT'       => $config['NB_MAX_MYSQL_CLIENT'] ?? 20,
            'NB_MAX_PROMISE_IN_LOOP'    => $config['NB_MAX_PROMISE_IN_LOOP'] ?? 20,
            'DEFAULT_GENERATOR_LOCALE'  => $config['DEFAULT_GENERATOR_LOCALE'] ?? 'en_US',
            'IS_REMOTE'  				=> $config['IS_REMOTE'] ?? false
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
    }

    /**
     * Construct a blueprints from a YAML file
     */
    protected function parseYamlAnonymization($file_path)
    {
        $configuration = yaml_parse_file($file_path);
        if ($configuration && $tables = $configuration['tables']) {
            foreach ($tables as $table_name => $table_detail) {
                $table = new Blueprint($table_name, $this->config['DB_NAME']);
                $table->primary($table_detail["primary_keys"]);
                if (!empty($table_detail["columns"])) {
                    foreach ($table_detail["columns"] as $column_name => $data) {
                        $table->column($column_name);
                        switch ($data["type"]) {
                            case "generator":
                                $table->replaceWithGenerator($data['value'], $data['unique'] ?? false, $data['optional'] ?? false, $data['defaultValue'] ?? null, $data['optionalWeight'] ?? null);
                                break;
                            case "string":
                                $table->replaceWith($data['value']);
                                break;
                        }
                        if (!empty($data["synchronizeColumn"]["column"])) {
                            $synchronizeInfo = [$data["synchronizeColumn"]["column"]];
                            if (!empty($data["synchronizeColumn"]["table"])) {
                                $synchronizeInfo[] = $data["synchronizeColumn"]["table"];
                                if (!empty($data["synchronizeColumn"]["database"])) {
                                    $synchronizeInfo[] = $data["synchronizeColumn"]["database"];
                                }
                            }
                            $table->synchronizeColumn($synchronizeInfo);
                        }
                    }
                }
                $this->anonymizer->addTable($table);
            }
        }
    }

    /**
     * Construct the liste of blueprints
     */
    public function constructBluePrint()
    {
    	$files = array_diff(scandir("workspace/" . $this->project_directory), array('.', '..'));
    	foreach ($files as $file) {
    		$path_info = pathinfo("workspace/" . $this->project_directory . "/" . $file);
    		if ($path_info) {
                if ($path_info['extension'] === 'php') {
                    $table = new Blueprint($path_info['filename'], $this->config['DB_NAME']);
                    include "workspace/" . $this->project_directory . "/" . $file;
                    $this->anonymizer->addTable($table);
                } elseif (is_callable("yaml_parse_file") && $path_info['extension'] === 'yaml') {
                    $this->parseYamlAnonymization("workspace/" . $this->project_directory . "/" . $file);
                }
            }
    	}
    }

    /**
     * Start the anonymization process
     */
    public static function run($database_name)
    {
        $anonymize = new self($database_name);
        $anonymize->constructBluePrint();
        $anonymize->anonymizer->run();
    }
}

$start = microtime(true);

Anonymize::run($argv[1]);

echo 'Anonymization has been completed!'.PHP_EOL;

echo (microtime(true) - $start).PHP_EOL;