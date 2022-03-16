<?php

namespace Monolog\Handler;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use PDOStatement;
use yii\db\mssql\PDO;

/**
 * This class is a handler for Monolog, which can be used
 * to write records in a MySQL table
 *
 * Class MySQLHandler
 * @package wazaari\MysqlHandler
 */
class MysqlHandler extends AbstractProcessingHandler
{

    /**
     * @var bool defines whether the MySQL connection is been initialized
     */
    private $initialized = false;

    /**
     * @var PDO pdo object of database connection
     */
    protected $pdo;

    /**
     * @var PDOStatement statement to insert a new record
     */
    private $statement;

    /**
     * @var string the table to store the logs in
     */
    private $table = 'logs';

    /**
     * @var array default fields that are stored in db
     */
    private $defaultfields = array('id', 'channel', 'level', 'message', 'time');

    /**
     * @var string[] additional fields to be stored in the database
     *
     * For each field $field, an additional context field with the name $field
     * is expected along the message, and further the database needs to have these fields
     * as the values are stored in the column name $field.
     */
    private $additionalFields = array();

    /**
     * @var array
     */
    private $fields = array();


    /**
     * Constructor of this class, sets the PDO and calls parent constructor
     *
     * @param PDO $pdo PDO Connector for the database
     * @param bool $table Table in the database to store the logs in
     * @param array $additionalFields Additional Context Parameters to store in database
     * @param bool|int $level Debug level which this handler should store
     * @param bool $bubble
     * @param bool $skipDatabaseModifications Defines whether attempts to alter database should be skipped
     */
    public function __construct(
        $table = 'logs',
        $additionalFields = array(),
        $level = Logger::DEBUG,
        $bubble = true,
        $skipDatabaseModifications = false
    )
    {
        $this->pdo = \Yii::$app->db->getMasterPdo();
        $this->table = $table;
        $this->additionalFields = $additionalFields;
        parent::__construct($level, $bubble);

        if ($skipDatabaseModifications) {
            $this->mergeDefaultAndAdditionalFields();
            $this->initialized = true;
        }
    }

    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize()
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . $this->table . '` '
            . '(id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, channel VARCHAR(255), level INTEGER, message LONGTEXT, time DATETIME, INDEX(channel) USING HASH, INDEX(level) USING HASH, INDEX(time) USING BTREE)'
        );

        //Read out actual columns
        $actualFields = array();
        $rs = $this->pdo->query('SELECT * FROM `' . $this->table . '` LIMIT 0');
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);
            $actualFields[] = $col['name'];
        }

        //Calculate changed entries
        $removedColumns = array_diff(
            $actualFields,
            $this->additionalFields,
            $this->defaultfields
        );
        $addedColumns = array_diff($this->additionalFields, $actualFields);

        //Remove columns
        if (!empty($removedColumns)) {
            foreach ($removedColumns as $c) {
                $this->pdo->exec('ALTER TABLE `' . $this->table . '` DROP `' . $c . '`;');
            }
        }

        //Add columns
        if (!empty($addedColumns)) {
            foreach ($addedColumns as $c) {
                $this->pdo->exec('ALTER TABLE `' . $this->table . '` add `' . $c . '` TEXT NULL DEFAULT NULL;');
            }
        }

        $this->mergeDefaultAndAdditionalFields();

        $this->initialized = true;
    }

    /**
     * Prepare the sql statment depending on the fields that should be written to the database
     */
    private function prepareStatement()
    {
        //Prepare statement
        $columns = "";
        $fields = "";
        foreach ($this->fields as $key => $f) {
            if ($f == 'id') {
                continue;
            }
            if ($key == 1) {
                $columns .= "$f";
                $fields .= ":$f";
                continue;
            }

            $columns .= ", $f";
            $fields .= ", :$f";
        }

        $this->statement = $this->pdo->prepare(
            'INSERT INTO `' . $this->table . '` (' . $columns . ') VALUES (' . $fields . ')'
        );
    }


    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param  $record []
     * @return void
     */
    protected function write(array $record): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        /**
         * reset $fields with default values
         */
        $this->fields = $this->defaultfields;

        /*
         * merge $record['context'] and $record['extra'] as additional info of Processors
         * getting added to $record['extra']
         * @see https://github.com/Seldaek/monolog/blob/master/doc/02-handlers-formatters-processors.md
         */
        if (isset($record['extra'])) {
            $record['context'] = array_merge($record['context'], $record['extra']);
        }

        //'context' contains the array

        $contentArray = array_merge(array(
            'channel' => $record['channel'],
            'level' => $record['level'],
            'message' => $record['message'],
            'time' => $record['datetime']
        ), $record['context']);

        // unset array keys that are passed put not defined to be stored, to prevent sql errors
        foreach ($contentArray as $key => $context) {
            if (!in_array($key, $this->fields)) {
                unset($contentArray[$key]);
                unset($this->fields[array_search($key, $this->fields)]);
                continue;
            }

            if ($context === null) {
                unset($contentArray[$key]);
                unset($this->fields[array_search($key, $this->fields)]);
            }
        }

        $this->prepareStatement();

        //Remove unused keys
        foreach ($this->additionalFields as $key => $context) {
            if (!isset($contentArray[$key])) {
                unset($this->additionalFields[$key]);
            }
        }

        //Fill content array with "null" values if not provided
        $contentArray = $contentArray + array_combine(
                $this->additionalFields,
                array_fill(0, count($this->additionalFields), null)
            );

        $this->statement->execute($contentArray);
    }

    /**
     * Merges default and additional fields into one array
     */
    private function mergeDefaultAndAdditionalFields()
    {
        $this->defaultfields = array_merge($this->defaultfields, $this->additionalFields);
    }
}

