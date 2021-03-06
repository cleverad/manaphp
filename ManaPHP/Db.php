<?php

namespace ManaPHP;

use ManaPHP\Db\AssignmentInterface;
use ManaPHP\Db\ConnectionException;
use ManaPHP\Db\Exception as DbException;
use ManaPHP\Exception\InvalidArgumentException;
use ManaPHP\Exception\InvalidValueException;
use ManaPHP\Exception\NotSupportedException;
use ManaPHP\Exception\RuntimeException;

/**
 * Class ManaPHP\Db
 *
 * @package db
 */
abstract class Db extends Component implements DbInterface
{
    const METADATA_ATTRIBUTES = 0;
    const METADATA_PRIMARY_KEY = 1;
    const METADATA_AUTO_INCREMENT_KEY = 3;
    const METADATA_INT_TYPE_ATTRIBUTES = 5;

    /**
     * @var string
     */
    protected $_dsn;

    /**
     * @var string
     */
    protected $_username;

    /**
     * @var string
     */
    protected $_password;

    /**
     * @var array
     */
    protected $_options = [];

    /**
     * Active SQL Statement
     *
     * @var string
     */
    protected $_sql;

    /**
     * Active SQL bound parameter variables
     *
     * @var array
     */
    protected $_bind = [];

    /**
     * Current transaction level
     *
     * @var int
     */
    protected $_transactionLevel = 0;

    /**
     * @var \PDO
     */
    protected $_pdo;

    /**
     * Last affected rows
     *
     * @var int
     */
    protected $_affectedRows;

    /**
     * @var int
     */
    protected $_timeout = 3;

    /**
     * @var \PDOStatement[]
     */
    protected $_prepared = [];

    /**
     * \ManaPHP\Db\Adapter constructor
     *
     */
    public function __construct()
    {
        $this->_options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;
        $this->_options[\PDO::ATTR_EMULATE_PREPARES] = false;
        $this->_options[\PDO::ATTR_TIMEOUT] = $this->_timeout;
    }

    /**
     * @return \PDO
     */
    protected function _getPdo()
    {
        if ($this->_pdo === null) {
            try {
                $this->fireEvent('db:beforeConnect', ['dsn' => $this->_dsn]);
                $this->_pdo = @new \PDO($this->_dsn, $this->_username, $this->_password, $this->_options);
                $this->fireEvent('db:afterConnect');
            } catch (\PDOException $e) {
                /** @noinspection PhpUnhandledExceptionInspection */
                /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
                throw new ConnectionException(['connect `:dsn` failed: :message', 'message' => $e->getMessage(), 'dsn' => $this->_dsn], $e->getCode(), $e);
            }
        }

        return $this->_pdo;
    }

    /**
     * Pings a server connection, or tries to reconnect if the connection has gone down
     *
     * @return void
     * @throws \ManaPHP\Db\ConnectionException
     */
    public function ping()
    {
        $sql = 'SELECT 1';

        if ($this->_pdo) {
            try {
                $this->_getPdo()->query($sql)->fetchAll();
            } catch (\Exception $e) {
                $this->close();
                try {
                    $this->_getPdo()->query($sql)->fetchAll();
                } catch (\Exception $exception) {
                    throw new ConnectionException(['connection failed: `:url`', 'url' => $this->_dsn], 0, $exception);
                }
            }
        } else {
            try {
                $this->_getPdo()->query($sql)->fetchAll();
            } catch (\Exception $exception) {
                throw new ConnectionException(['connection failed: `:url`', 'url' => $this->_dsn], 0, $exception);
            }
        }
    }

    /**
     * @return \ManaPHP\DbInterface
     */
    public function getMasterConnection()
    {
        return $this;
    }

    /**
     * @return \ManaPHP\DbInterface
     */
    public function getSlaveConnection()
    {
        return $this;
    }

    protected function _escapeIdentifier($identifier)
    {
        $list = [];
        foreach (explode('.', $identifier) as $id) {
            if ($identifier[0] === '[') {
                $list[] = $id;
            } else {
                $list[] = "[$id]";
            }
        }

        return implode('.', $list);
    }

    /**
     * Executes a prepared statement binding. This function uses integer indexes starting from zero
     *<code>
     * $statement = $db->prepare('SELECT * FROM robots WHERE name = :name');
     * $result = $connection->executePrepared($statement, array('name' => 'mana'));
     *</code>
     *
     * @param string|\PDOStatement $statement
     * @param array                $bind
     *
     * @return \PDOStatement
     */
    protected function _execute($statement, $bind)
    {
        if (is_string($statement)) {
            $statement = $this->prepare($statement);
        }

        foreach ($bind as $parameter => $value) {
            if (is_string($value)) {
                $type = \PDO::PARAM_STR;
            } elseif (is_int($value)) {
                $type = \PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = \PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $type = \PDO::PARAM_NULL;
            } elseif (is_float($value)) {
                $type = \PDO::PARAM_STR;
            } elseif (is_array($value) || $value instanceof \JsonSerializable) {
                $type = \PDO::PARAM_STR;
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                throw new NotSupportedException(['The `:type` type of `:parameter` parameter is not support', 'parameter' => $parameter, 'type' => gettype($value)]);
            }

            if (is_int($parameter)) {
                $statement->bindValue($parameter + 1, $value, $type);
            } else {
                if ($parameter[0] === ':') {
                    throw new InvalidValueException(['Bind does not require started with `:` for `:parameter` parameter', 'parameter' => $parameter]);
                }

                $statement->bindValue(':' . $parameter, $value, $type);
            }
        }

        $statement->execute();

        return $statement;
    }

    /**
     * Sends SQL statements to the database server returning the success state.
     * Use this method only when the SQL statement sent to the server is returning rows
     *<code>
     *    //Querying data
     *    $resultset = $connection->query("SELECT * FROM robots WHERE type='mechanical'");
     *    $resultset = $connection->query("SELECT * FROM robots WHERE type=?", array("mechanical"));
     *</code>
     *
     * @param string|\PDOStatement $statement
     * @param array                $bind
     * @param int                  $fetchMode
     *
     * @return \PdoStatement
     * @throws \ManaPHP\Db\Exception
     */
    public function query($statement, $bind = [], $fetchMode = \PDO::FETCH_ASSOC)
    {
        $this->_sql = $sql = is_string($statement) ? $this->replaceQuoteCharacters($statement) : $statement->queryString;
        $this->_bind = $bind;
        $this->_affectedRows = 0;

        $this->fireEvent('db:beforeQuery');
        $start_time = microtime(true);
        try {
            if ($bind) {
                $result = $this->_execute($statement, $bind);
            } else {
                $result = $this->_getPdo()->query($this->_sql);
            }

            $this->_affectedRows = $result->rowCount();
            $result->setFetchMode($fetchMode);
        } catch (\PDOException $e) {
            throw new DbException([
                ':message => ' . PHP_EOL . 'SQL: ":sql"' . PHP_EOL . ' BIND: :bind',
                'message' => $e->getMessage(),
                'sql' => $this->_sql,
                'bind' => json_encode($this->_bind, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ]);
        }

        $elapsed = round(microtime(true) - $start_time, 3);
        $count = $this->_affectedRows;
        $this->fireEvent('db:afterQuery', ['elapsed' => $elapsed]);

        $this->trace(compact('count', 'sql', 'bind', 'elapsed'), 'db.query');
        return $result;
    }

    /**
     * @return \ManaPHP\Db\QueryInterface
     */
    public function createQuery()
    {
        return $this->_di->get('ManaPHP\Db\Query', [$this]);
    }

    /**
     * @param string $sql
     *
     * @return \PDOStatement
     */
    public function prepare($sql)
    {
        if (isset($this->_prepared[$sql])) {
            return $this->_prepared[$sql];
        }

        if (count($this->_prepared) > 120) {
            $this->_prepared = array_slice($this->_prepared, -100);
        }

        return $this->_prepared[$sql] = $this->_getPdo()->prepare($this->replaceQuoteCharacters($sql));
    }

    /**
     * Sends SQL statements to the database server returning the success state.
     * Use this method only when the SQL statement sent to the server does n't return any rows
     *<code>
     *    //Inserting data
     *    $success = $connection->execute("INSERT INTO robots VALUES (1, 'Boy')");
     *    $success = $connection->execute("INSERT INTO robots VALUES (?, ?)", array(1, 'Boy'));
     *</code>
     *
     * @param string|\PDOStatement $statement
     * @param array                $bind
     *
     * @return int
     * @throws \ManaPHP\Db\Exception
     */
    public function execute($statement, $bind = [])
    {
        $this->_sql = $sql = is_string($statement) ? $this->replaceQuoteCharacters($statement) : $statement->queryString;
        $this->_bind = $bind;

        $this->_affectedRows = 0;

        $this->fireEvent('db:beforeQuery');
        $start_time = microtime(true);
        try {
            if ($bind) {
                $result = $this->_execute($statement, $bind);
                $this->_affectedRows = $result->rowCount();
            } else {
                $this->_affectedRows = $this->_getPdo()->exec($this->_sql);
            }
        } catch (\PDOException $e) {
            throw new DbException([
                ':message => ' . PHP_EOL . 'SQL: ":sql"' . PHP_EOL . ' BIND: :bind',
                'message' => $e->getMessage(),
                'sql' => $this->_sql,
                'bind' => json_encode($this->_bind, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ]);
        }

        if (is_int($this->_affectedRows)) {
            $elapsed = round(microtime(true) - $start_time, 3);
            $this->fireEvent('db:afterQuery', ['elapsed' => $elapsed]);
        }
        $count = $this->_affectedRows;

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        if (!isset($backtrace['function']) || !in_array($backtrace['function'], ['insert', 'delete', 'update'], true)) {
            $this->trace(compact('count', 'sql', 'bind', 'elapsed'), 'db.execute');
        }
        return $count;
    }

    /**
     * Returns the number of affected rows by the last INSERT/UPDATE/DELETE reported by the database system
     *
     * @return int
     */
    public function affectedRows()
    {
        return $this->_affectedRows;
    }

    /**
     * Returns the first row in a SQL query result
     *<code>
     *    //Getting first robot
     *    $robot = $connection->fetchOne("SELECT * FROM robots");
     *    print_r($robot);
     *    //Getting first robot with associative indexes only
     *    $robot = $connection->fetchOne("SELECT * FROM robots", \ManaPHP\Db::FETCH_ASSOC);
     *    print_r($robot);
     *</code>
     *
     * @param string|\PDOStatement $statement
     * @param array                $bind
     * @param int                  $fetchMode
     *
     * @return array|false
     * @throws \ManaPHP\Db\Exception
     */
    public function fetchOne($statement, $bind = [], $fetchMode = \PDO::FETCH_ASSOC)
    {
        $result = $this->query($statement, $bind, $fetchMode);

        return $result->fetch();
    }

    /**
     * Dumps the complete result of a query into an array
     *<code>
     *    //Getting all robots with associative indexes only
     *    $robots = $connection->fetchAll("SELECT * FROM robots", \ManaPHP\Db::FETCH_ASSOC);
     *    foreach ($robots as $robot) {
     *        print_r($robot);
     *    }
     *  //Getting all robots that contains word "robot" withing the name
     *  $robots = $connection->fetchAll("SELECT * FROM robots WHERE name LIKE :name",
     *        ManaPHP\Db::FETCH_ASSOC,
     *        array('name' => '%robot%')
     *  );
     *    foreach($robots as $robot){
     *        print_r($robot);
     *    }
     *</code>
     *
     * @param string|\PDOStatement  $statement
     * @param array                 $bind
     * @param int                   $fetchMode
     * @param string|callable|array $indexBy
     *
     * @return array
     * @throws \ManaPHP\Db\Exception
     */
    public function fetchAll($statement, $bind = [], $fetchMode = \PDO::FETCH_ASSOC, $indexBy = null)
    {
        $result = $this->query($statement, $bind, $fetchMode);

        if ($indexBy === null) {
            $rows = $result->fetchAll($fetchMode);
        } elseif (is_scalar($indexBy)) {
            $rows = [];
            while ($row = $result->fetch($fetchMode)) {
                $rows[$row[$indexBy]] = $row;
            }
        } elseif (is_array($indexBy)) {
            $rows = [];
            $k = key($indexBy);
            $v = current($indexBy);
            while ($row = $result->fetch($fetchMode)) {
                $rows[$row[$k]] = $row[$v];
            }
        } else {
            $rows = [];
            while ($row = $result->fetch($fetchMode)) {
                $rows[$indexBy($row)] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param string $table
     * @param array  $record
     * @param string $primaryKey
     * @param bool   $skipIfExists
     *
     * @return int
     * @throws \ManaPHP\Db\Exception
     */
    public function insert($table, $record, $primaryKey = null, $skipIfExists = false)
    {
        if (!$record) {
            throw new InvalidArgumentException(['Unable to insert into :table table without data', 'table' => $table]);
        }
        $fields = array_keys($record);
        $insertedValues = ':' . implode(',:', $fields);
        $insertedFields = '[' . implode('],[', $fields) . ']';

        $sql = 'INSERT' . ($skipIfExists ? ' IGNORE' : '') . ' INTO ' . $this->_escapeIdentifier($table) . " ($insertedFields) VALUES ($insertedValues)";

        $count = $this->execute($sql, $record);
        $this->trace(compact('count', 'table', 'record', 'skipIfExists'), 'db.insert');

        return $count;
    }

    /**
     * @param string  $table
     * @param array[] $records
     * @param null    $primaryKey
     * @param bool    $skipIfExists
     *
     * @return int
     * @throws \ManaPHP\Db\Exception
     */
    public function bulkInsert($table, $records, $primaryKey = null, $skipIfExists = false)
    {
        $count = 0;
        foreach ($records as $record) {
            $count += $this->insert($table, $record, $primaryKey, $skipIfExists);
        }

        return $count;
    }

    /**
     * Updates data on a table using custom SQL syntax
     * <code>
     * //Updating existing robot
     * $success = $connection->update(
     *     "robots",
     *     array("name"),
     *     array("New Boy"),
     *     "id = 101"
     * );
     * //Next SQL sentence is sent to the database system
     * UPDATE `robots` SET `name` = "boy" WHERE id = 101
     * </code>
     *
     * @param    string       $table
     * @param    array        $fieldValues
     * @param    string|array $conditions
     * @param    array        $bind
     *
     * @return    int
     * @throws \ManaPHP\Db\Exception
     */
    public function update($table, $fieldValues, $conditions, $bind = [])
    {
        if (!$fieldValues) {
            throw new InvalidArgumentException(['Unable to update :table table without data', 'table' => $table]);
        }

        if (is_string($conditions)) {
            $conditions = [$conditions];
        }

        $wheres = [];

        /** @noinspection ForeachSourceInspection */
        foreach ($conditions as $k => $v) {
            if (is_int($k)) {
                $wheres[] = stripos($v, ' or ') ? "($v)" : $v;
            } else {
                $wheres[] = "[$k]=:$k";
                $bind[$k] = $v;
            }
        }

        $setFields = [];
        foreach ($fieldValues as $k => $v) {
            if (is_int($k)) {
                $setFields[] = $v;
            } else {
                if ($v instanceof AssignmentInterface) {
                    $v->setFieldName($k);
                    $setFields[] = $v->getSql();
                    /** @noinspection SlowArrayOperationsInLoopInspection */
                    $bind = array_merge($bind, $v->getBind());
                } else {
                    $setFields[] = "[$k]=:$k";
                    $bind[$k] = $v;
                }
            }
        }

        $sql = 'UPDATE ' . $this->_escapeIdentifier($table) . ' SET ' . implode(',', $setFields) . ' WHERE ' . implode(' AND ', $wheres);

        $count = $this->execute($sql, $bind);
        $this->trace(compact('count', 'table', 'fieldValues', 'conditions', 'bind'), 'db.update');
        return $count;
    }

    /**
     * Deletes data from a table using custom SQL syntax
     * <code>
     * //Deleting existing robot
     * $success = $connection->delete(
     *     "robots",
     *     "id = 101"
     * );
     * //Next SQL sentence is generated
     * DELETE FROM `robots` WHERE `id` = 101
     * </code>
     *
     * @param  string       $table
     * @param  string|array $conditions
     * @param  array        $bind
     *
     * @return int
     * @throws \ManaPHP\Db\Exception
     */
    public function delete($table, $conditions, $bind = [])
    {
        if (is_string($conditions)) {
            $conditions = [$conditions];
        }

        $wheres = [];
        /** @noinspection ForeachSourceInspection */
        foreach ($conditions as $k => $v) {
            if (is_int($k)) {
                $wheres[] = stripos($v, ' or ') ? "($v)" : $v;
            } else {
                $wheres[] = "[$k]=:$k";
                $bind[$k] = $v;
            }
        }

        $sql = /**@lang Text */
            'DELETE FROM ' . $this->_escapeIdentifier($table) . ' WHERE ' . implode(' AND ', $wheres);
        $count = $this->execute($sql, $bind);
        $this->trace(compact('count', 'table', 'conditions', 'bind'), 'db.delete');
        return $count;
    }

    /**
     * Active SQL statement in the object
     *
     * @return string
     */
    public function getSQL()
    {
        return $this->_sql;
    }

    /**
     * @param mixed $value
     * @param int   $preservedStrLength
     *
     * @return int|string
     */
    protected function _parseBindValue($value, $preservedStrLength)
    {
        if (is_string($value)) {
            if ($preservedStrLength > 0 && strlen($value) >= $preservedStrLength) {
                return $this->_getPdo()->quote(substr($value, 0, $preservedStrLength) . '...');
            } else {
                return $this->_getPdo()->quote($value);
            }
        } elseif (is_int($value)) {
            return $value;
        } elseif ($value === null) {
            return 'NULL';
        } elseif (is_bool($value)) {
            return (int)$value;
        } else {
            return $value;
        }
    }

    /**
     * Active SQL statement in the object with replace the bind with value
     *
     * @param int $preservedStrLength
     *
     * @return string
     */
    public function getEmulatedSQL($preservedStrLength = -1)
    {
        if (!$this->_bind) {
            return (string)$this->_sql;
        }

        $bind = $this->_bind;
        if (isset($bind[0])) {
            return (string)$this->_sql;
        } else {
            $replaces = [];
            foreach ($bind as $key => $value) {
                $replaces[':' . $key] = $this->_parseBindValue($value, $preservedStrLength);
            }

            return strtr($this->_sql, $replaces);
        }
    }

    /**
     * Active SQL statement in the object
     *
     * @return array
     */
    public function getBind()
    {
        return $this->_bind;
    }

    /**
     * Starts a transaction in the connection
     *
     * @return void
     * @throws \ManaPHP\Db\Exception
     */
    public function begin()
    {
        $this->trace('transaction begin', 'db.transaction.begin');

        if ($this->_transactionLevel === 0) {
            $this->fireEvent('db:beginTransaction');

            try {
                if (!$this->_getPdo()->beginTransaction()) {
                    throw new DbException('beginTransaction failed.');
                }
            } catch (\PDOException $exception) {
                throw new DbException('beginTransaction failed: ' . $exception->getMessage(), $exception->getCode(), $exception);
            }
        }

        $this->_transactionLevel++;
    }

    /**
     * Checks whether the connection is under a transaction
     *<code>
     *    $connection->begin();
     *    var_dump($connection->isUnderTransaction()); //true
     *</code>
     *
     * @return bool
     */
    public function isUnderTransaction()
    {
        return $this->_transactionLevel !== 0;
    }

    /**
     * Rollbacks the active transaction in the connection
     *
     * @return void
     * @throws \ManaPHP\Db\Exception
     */
    public function rollback()
    {
        $this->trace('transaction rollback', 'db.transaction.rollback');

        if ($this->_transactionLevel === 0) {
            throw new RuntimeException('There is no active transaction');
        }

        $this->_transactionLevel--;

        if ($this->_transactionLevel === 0) {
            $this->fireEvent('db:rollbackTransaction');

            if (!$this->_getPdo()->rollBack()) {
                throw new DbException('rollBack failed.');
            }
        }
    }

    /**
     * Commits the active transaction in the connection
     *
     * @return void
     * @throws \ManaPHP\Db\Exception
     */
    public function commit()
    {
        $this->trace('transaction commit', 'db.transaction.commit');

        if ($this->_transactionLevel === 0) {
            throw new RuntimeException('There is no active transaction');
        }

        $this->_transactionLevel--;

        if ($this->_transactionLevel === 0) {
            $this->fireEvent('db:commitTransaction');

            if (!$this->_getPdo()->commit()) {
                throw new DbException('commit failed.');
            }
        }
    }

    /**
     * Returns insert id for the auto_increment field inserted in the last SQL statement
     *
     * @return int
     */
    public function lastInsertId()
    {
        return (int)$this->_getPdo()->lastInsertId();
    }

    /**
     * @return string
     */
    public function getLastSql()
    {
        return $this->_sql;
    }

    public function close()
    {
        $this->_pdo = null;
        $this->_prepared = [];
    }
}