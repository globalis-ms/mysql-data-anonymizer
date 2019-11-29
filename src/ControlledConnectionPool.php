<?php

namespace Globalis\MysqlDataAnonymizer;

use \Amp\Sql\Common\ConnectionPool;
use \Amp\Sql\Common\StatementPool as SqlStatementPool;
use \Amp\Sql\Connector;
use \Amp\Sql\Pool as SqlPool;
use \Amp\Sql\Link;
use \Amp\Sql\ResultSet as SqlResultSet;
use \Amp\Sql\Statement as SqlStatement;
use \Amp\Sql\Transaction as SqlTransaction;
use \Amp\Mysql\ConnectionConfig;
use \Amp\Mysql\PooledResultSet;
use \Amp\Mysql\PooledStatement;
use \Amp\Mysql\StatementPool;
use \Amp\Mysql\PooledTransaction;

final class ControlledConnectionPool extends ConnectionPool
{
    protected function createDefaultConnector(): Connector
    {
        return connector();
    }

    protected function createResultSet(SqlResultSet $resultSet, callable $release): SqlResultSet
    {
        \assert($resultSet instanceof ResultSet);
        return new PooledResultSet($resultSet, $release);
    }

    protected function createStatement(SqlStatement $statement, callable $release): SqlStatement
    {
        \assert($statement instanceof Statement);
        return new PooledStatement($statement, $release);
    }

    protected function createStatementPool(SqlPool $pool, SqlStatement $statement, callable $prepare): SqlStatementPool
    {
        \assert($statement instanceof Statement);
        return new StatementPool($pool, $statement, $prepare);
    }

    protected function createTransaction(SqlTransaction $transaction, callable $release): SqlTransaction
    {
        return new PooledTransaction($transaction, $release);
    }

    public function pushToPool(Link $connection): void
    {
        $this->push($connection);
    }
}

/**
 * Create a controlled connection pool using the global Connector instance.
 *
 * @param SqlConnectionConfig $config
 * @param int $maxConnections
 * @param int $idleTimeout
 *
 * @return ControlledConnectionPool
 *
 * @throws \Error If the connection string does not contain a host, user, and password.
 */
function controlledConnectionPool(
    ConnectionConfig $config,
    int $maxConnections = ConnectionPool::DEFAULT_MAX_CONNECTIONS,
    int $idleTimeout = ConnectionPool::DEFAULT_IDLE_TIMEOUT
): ControlledConnectionPool {
    return new ControlledConnectionPool($config, $maxConnections, $idleTimeout,  \Amp\Mysql\connector());
}
