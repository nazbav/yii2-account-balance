<?php

declare(strict_types=1);

/**
 * @link https://github.com/yii2tech
 * @copyright Copyright (c) 2015 Yii2tech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace yii2tech\balance;

use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;
use yii\db\TableSchema;
use yii\db\Transaction;
use yii\di\Instance;

/**
 * ManagerDb is a balance manager, which uses relational database as data storage.
 *
 * @see Manager
 *
 * @property string $transactionIdAttribute
 * @property string $accountIdAttribute
 * @property-read Connection $dbConnection
 */
class ManagerDb extends ManagerDbTransaction
{
    use ManagerDataSerializeTrait;

    /**
     * @var Connection|array<string, mixed>|string DB connection object or application component ID.
     */
    public Connection|array|string $db = 'db';

    /**
     * @var string name of the database table, which should store account records.
     */
    public string $accountTable = '{{%BalanceAccount}}';

    /**
     * @var string name of the database table, which should store transaction records.
     */
    public string $transactionTable = '{{%BalanceTransaction}}';

    private ?string $_accountIdAttribute = null;
    private ?string $_transactionIdAttribute = null;

    public function init(): void
    {
        parent::init();
        $this->db = $this->getDbConnection();
    }

    public function getAccountIdAttribute(): string
    {
        if ($this->_accountIdAttribute === null) {
            $this->_accountIdAttribute = $this->detectPrimaryKey($this->accountTable);
        }

        return $this->_accountIdAttribute;
    }

    public function setAccountIdAttribute(string $accountIdAttribute): void
    {
        $this->_accountIdAttribute = $accountIdAttribute;
    }

    public function getTransactionIdAttribute(): string
    {
        if ($this->_transactionIdAttribute === null) {
            $this->_transactionIdAttribute = $this->detectPrimaryKey($this->transactionTable);
        }

        return $this->_transactionIdAttribute;
    }

    public function setTransactionIdAttribute(string $transactionIdAttribute): void
    {
        $this->_transactionIdAttribute = $transactionIdAttribute;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function findAccountId(array $attributes): mixed
    {
        $id = (new Query())
            ->select([$this->getAccountIdAttribute()])
            ->from($this->accountTable)
            ->andWhere($attributes)
            ->scalar($this->getDbConnection());

        return $id === false ? null : $id;
    }

    protected function findTransaction(mixed $id): ?array
    {
        $row = (new Query())
            ->from($this->transactionTable)
            ->andWhere([$this->getTransactionIdAttribute() => $id])
            ->one($this->getDbConnection());

        if (!is_array($row)) {
            return null;
        }

        return $this->unserializeAttributes($row);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createAccount(array $attributes): mixed
    {
        $primaryKeys = $this->getDbConnection()->getSchema()->insert($this->accountTable, $attributes);
        if (!is_array($primaryKeys) || $primaryKeys === []) {
            throw new InvalidConfigException('Не удалось получить первичный ключ после создания счёта.');
        }

        return count($primaryKeys) > 1 ? implode(',', $primaryKeys) : array_shift($primaryKeys);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createTransaction(array $attributes): mixed
    {
        $allowedAttributes = [];
        foreach ($this->getRequiredTableSchema($this->transactionTable)->columns as $column) {
            if ($column->isPrimaryKey && $column->autoIncrement) {
                continue;
            }
            $allowedAttributes[] = $column->name;
        }

        $serializedAttributes = $this->serializeAttributes($attributes, $allowedAttributes);
        $primaryKeys = $this->getDbConnection()->getSchema()->insert($this->transactionTable, $serializedAttributes);
        if (!is_array($primaryKeys) || $primaryKeys === []) {
            throw new InvalidConfigException('Не удалось получить первичный ключ после создания транзакции.');
        }

        return count($primaryKeys) > 1 ? implode(',', $primaryKeys) : array_shift($primaryKeys);
    }

    protected function incrementAccountBalance(mixed $accountId, int|float $amount): void
    {
        if ($this->accountBalanceAttribute === null) {
            return;
        }

        $value = new Expression("[[{$this->accountBalanceAttribute}]]+:amount", ['amount' => $amount]);
        $this->getDbConnection()->createCommand()
            ->update($this->accountTable, [$this->accountBalanceAttribute => $value], [$this->getAccountIdAttribute() => $accountId])
            ->execute();
    }

    public function calculateBalance(mixed $account): int|float|null
    {
        $accountId = $this->fetchAccountId($account);
        $balance = (new Query())
            ->from($this->transactionTable)
            ->andWhere([$this->accountLinkAttribute => $accountId])
            ->sum($this->amountAttribute, $this->getDbConnection());

        return $balance === null ? null : $this->normalizeAmount($balance);
    }

    protected function createDbTransaction(): ?Transaction
    {
        $db = $this->getDbConnection();
        if ($db->getTransaction() !== null) {
            return null;
        }

        return $db->beginTransaction();
    }

    protected function getDbConnection(): Connection
    {
        if (!$this->db instanceof Connection) {
            /** @var Connection $connection */
            $connection = Instance::ensure($this->db, Connection::class);
            $this->db = $connection;
        }

        return $this->db;
    }

    private function getRequiredTableSchema(string $tableName): TableSchema
    {
        $schema = $this->getDbConnection()->getTableSchema($tableName);
        if ($schema === null) {
            throw new InvalidConfigException("Таблица '{$tableName}' не найдена в схеме БД.");
        }

        return $schema;
    }

    private function detectPrimaryKey(string $tableName): string
    {
        $primaryKeys = $this->getRequiredTableSchema($tableName)->primaryKey;
        $primaryKey = array_shift($primaryKeys);
        if ($primaryKey === null) {
            throw new InvalidConfigException("Таблица '{$tableName}' должна иметь первичный ключ.");
        }

        return $primaryKey;
    }
}
