<?php

declare(strict_types=1);

namespace yii2tech\balance;

use yii\base\InvalidConfigException;
use yii\base\InvalidArgumentException;
use yii\base\NotSupportedException;
use yii\db\Connection;
use yii\db\Exception;
use yii\db\Expression;
use yii\db\Query;
use yii\db\TableSchema;
use yii\db\Transaction;
use yii\di\Instance;

/**
 * ManagerDb — менеджер баланса, использующий реляционную базу данных как хранилище.
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
     * @var Connection|array<string, mixed>|string объект подключения к БД или ID компонента приложения.
     */
    public Connection|array|string $db = 'db';

    /**
     * @var string имя таблицы БД для хранения счётов.
     */
    public string $accountTable = '{{%BalanceAccount}}';

    /**
     * @var string имя таблицы БД для хранения транзакций.
     */
    public string $transactionTable = '{{%BalanceTransaction}}';

    private ?string $_accountIdAttribute = null;
    private ?string $_transactionIdAttribute = null;

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();
        $this->db = $this->getDbConnection();
    }

    /**
     * @throws InvalidConfigException
     */
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

    /**
     * @throws InvalidConfigException
     */
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
     * @throws InvalidConfigException
     */
    protected function findAccountId(array $attributes): string|int|null
    {
        $id = (new Query())
            ->select([$this->getAccountIdAttribute()])
            ->from($this->accountTable)
            ->andWhere($attributes)
            ->scalar($this->getDbConnection());

        return $id === false ? null : $id;
    }

    /**
     * @throws InvalidConfigException
     */
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
     * @throws InvalidConfigException
     * @throws NotSupportedException
     * @throws InvalidConfigException
     */
    protected function createAccount(array $attributes): mixed
    {
        $primaryKeys = $this->getDbConnection()->getSchema()->insert($this->accountTable, $attributes);
        if (!is_array($primaryKeys) || $primaryKeys === []) {
            throw new InvalidConfigException(Manager::t('error.account_primary_key_not_received'));
        }

        return count($primaryKeys) > 1 ? implode(',', $primaryKeys) : array_shift($primaryKeys);
    }

    /**
     * @param array<string, mixed> $attributes
     * @throws InvalidConfigException
     * @throws NotSupportedException
     * @throws InvalidConfigException
     * @throws InvalidConfigException
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
            throw new InvalidConfigException(Manager::t('error.transaction_primary_key_not_received'));
        }

        return count($primaryKeys) > 1 ? implode(',', $primaryKeys) : array_shift($primaryKeys);
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     */
    protected function incrementAccountBalance(mixed $accountId, int|float $amount): void
    {
        if ($this->accountBalanceAttribute === null) {
            return;
        }

        $balanceColumn = $this->ensureSafeColumnName($this->accountBalanceAttribute);
        $accountIdColumn = $this->ensureSafeColumnName($this->getAccountIdAttribute());
        $quotedBalanceColumn = $this->getDbConnection()->quoteColumnName($balanceColumn);
        $quotedAccountIdColumn = $this->getDbConnection()->quoteColumnName($accountIdColumn);
        $balanceExpression = new Expression("$quotedBalanceColumn + :amount", ['amount' => $amount]);

        if ($this->forbidNegativeBalance && $amount < 0) {
            $affectedRows = $this->getDbConnection()->createCommand()->update(
                $this->accountTable,
                [$balanceColumn => $balanceExpression],
                "$quotedAccountIdColumn = :accountId AND $quotedBalanceColumn + :amount >= :minimumBalance",
                [
                    'accountId' => $accountId,
                    'amount' => $amount,
                    'minimumBalance' => $this->normalizeAmount($this->minimumAllowedBalance),
                ]
            )->execute();

            if ($affectedRows === 0) {
                throw new InvalidArgumentException(self::t('error.insufficient_funds', [
                    'accountId' => (string) $accountId,
                    'minimumBalance' => (string) $this->minimumAllowedBalance,
                ]));
            }

            return;
        }

        $this->getDbConnection()->createCommand()->update(
            $this->accountTable,
            [$balanceColumn => $balanceExpression],
            [$accountIdColumn => $accountId]
        )->execute();
    }

    /**
     * @throws InvalidConfigException
     */
    public function calculateBalance(mixed $account): int|float|null
    {
        $accountId = $this->fetchAccountId($account);
        $balance = (new Query())
            ->from($this->transactionTable)
            ->andWhere([$this->accountLinkAttribute => $accountId])
            ->sum($this->amountAttribute, $this->getDbConnection());

        return $balance === null ? null : $this->normalizeAmount($balance);
    }

    /**
     * @throws InvalidConfigException
     */
    protected function createDbTransaction(): ?Transaction
    {
        $db = $this->getDbConnection();
        if ($db->getTransaction() !== null) {
            return null;
        }

        return $db->beginTransaction();
    }

    /**
     * @throws InvalidConfigException
     */
    protected function getDbConnection(): Connection
    {
        if (!$this->db instanceof Connection) {
            /** @var Connection $connection */
            $connection = Instance::ensure($this->db, Connection::class);
            $this->db = $connection;
        }

        return $this->db;
    }

    /**
     * @throws InvalidConfigException
     */
    private function getRequiredTableSchema(string $tableName): TableSchema
    {
        $schema = $this->getDbConnection()->getTableSchema($tableName);
        if ($schema === null) {
            throw new InvalidConfigException(Manager::t('error.table_not_found', [
                'table' => $tableName,
            ]));
        }

        return $schema;
    }

    /**
     * @throws InvalidConfigException
     */
    private function detectPrimaryKey(string $tableName): string
    {
        $primaryKeys = $this->getRequiredTableSchema($tableName)->primaryKey;
        $primaryKey = array_shift($primaryKeys);
        if ($primaryKey === null) {
            throw new InvalidConfigException(Manager::t('error.table_pk_required', [
                'table' => $tableName,
            ]));
        }

        return $primaryKey;
    }

    /**
     * @throws InvalidConfigException
     */
    private function ensureSafeColumnName(string $columnName): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $columnName)) {
            throw new InvalidConfigException(self::t('error.invalid_column_name', [
                'column' => $columnName,
            ]));
        }

        return $columnName;
    }
}
