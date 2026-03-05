<?php

declare(strict_types=1);

namespace nazbav\balance;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\db\Connection;
use yii\db\Exception;
use yii\db\Expression;
use yii\db\IntegrityException;
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
        $attributes = $this->filterAttributesByTableSchema($this->accountTable, $attributes);
        if ($attributes === []) {
            return null;
        }

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
     */
    protected function createAccount(array $attributes): mixed
    {
        $attributes = $this->filterAttributesByTableSchema($this->accountTable, $attributes);
        if ($attributes === []) {
            throw new InvalidArgumentException(Manager::t('error.account_attributes_empty_after_filter'));
        }

        try {
            $primaryKeys = $this->getDbConnection()->getSchema()->insert($this->accountTable, $attributes);
        } catch (IntegrityException $integrityException) {
            // При гонке на уникальном ключе счёт уже мог быть создан параллельным процессом.
            $existingAccountId = $this->findAccountId($attributes);
            if ($existingAccountId !== null) {
                return $existingAccountId;
            }

            throw $integrityException;
        }

        if (!is_array($primaryKeys) || $primaryKeys === []) {
            throw new InvalidConfigException(Manager::t('error.account_primary_key_not_received'));
        }

        return count($primaryKeys) > 1 ? implode(',', $primaryKeys) : array_shift($primaryKeys);
    }

    /**
     * @param array<string, mixed> $attributes
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    protected function createTransaction(array $attributes): mixed
    {
        $allowedAttributes = [];
        foreach ($this->getRequiredTableSchema($this->transactionTable)->columns as $column) {
            if ($column->isPrimaryKey === true && $column->autoIncrement === true) {
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
        $balanceExpression = new Expression($quotedBalanceColumn . ' + :amount', ['amount' => $amount]);

        if ($this->forbidNegativeBalance && $amount < 0) {
            $affectedRows = $this->getDbConnection()->createCommand()->update(
                $this->accountTable,
                [$balanceColumn => $balanceExpression],
                sprintf('%s = :accountId AND %s + :amount >= :minimumBalance', $quotedAccountIdColumn, $quotedBalanceColumn),
                [
                    'accountId' => $accountId,
                    'amount' => $amount,
                    'minimumBalance' => $this->getNormalizedMinimumAllowedBalance(),
                ],
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
            [$accountIdColumn => $accountId],
        )->execute();
    }

    /**
     * @throws InvalidConfigException
     */
    public function calculateBalance(mixed $account): int|float|null
    {
        $accountId = $this->fetchAccountId($account);
        $accountLinkColumn = $this->ensureSafeColumnName($this->accountLinkAttribute);
        $amountColumn = $this->ensureSafeColumnName($this->amountAttribute);
        $this->ensureTransactionColumnExists($accountLinkColumn);
        $this->ensureTransactionColumnExists($amountColumn);

        $balance = (new Query())
            ->from($this->transactionTable)
            ->andWhere([$accountLinkColumn => $accountId])
            ->sum($this->getDbConnection()->quoteColumnName($amountColumn), $this->getDbConnection());

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
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $columnName) !== 1) {
            throw new InvalidConfigException(self::t('error.invalid_column_name', [
                'column' => $columnName,
            ]));
        }

        return $columnName;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     * @throws InvalidConfigException
     */
    private function filterAttributesByTableSchema(string $tableName, array $attributes): array
    {
        $allowedAttributes = [];
        foreach ($this->getRequiredTableSchema($tableName)->columns as $column) {
            $allowedAttributes[] = $column->name;
        }

        return array_filter(
            $attributes,
            static fn (string $attributeName): bool => in_array($attributeName, $allowedAttributes, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * {@inheritDoc}
     * @throws InvalidConfigException
     */
    protected function hasOperationIdInAccountHistory(mixed $accountId, string $operationId): bool
    {
        $operationIdColumn = $this->ensureSafeColumnName($this->operationIdAttribute);
        $accountLinkColumn = $this->ensureSafeColumnName($this->accountLinkAttribute);
        $this->ensureTransactionColumnExists($operationIdColumn);
        $this->ensureTransactionColumnExists($accountLinkColumn);

        $row = (new Query())
            ->select([$this->getTransactionIdAttribute()])
            ->from($this->transactionTable)
            ->andWhere([
                $accountLinkColumn => $accountId,
                $operationIdColumn => $operationId,
            ])
            ->one($this->getDbConnection());

        return is_array($row);
    }

    /**
     * @throws InvalidConfigException
     */
    private function ensureTransactionColumnExists(string $columnName): void
    {
        if (!$this->getRequiredTableSchema($this->transactionTable)->getColumn($columnName) instanceof \yii\db\ColumnSchema) {
            throw new InvalidConfigException(self::t('error.operation_id_attribute_not_found', [
                'attribute' => $columnName,
                'table' => $this->transactionTable,
            ]));
        }
    }
}
