<?php

declare(strict_types=1);

namespace nazbav\balance;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\BaseActiveRecord;
use yii\db\Exception;
use yii\db\Expression;
use yii\db\TableSchema;
use yii\db\Transaction;

/**
 * ManagerActiveRecord — менеджер баланса, использующий классы ActiveRecord для хранения данных.
 * Поддерживает любые хранилища с интерфейсом ActiveRecord (например, реляционные БД, Redis и т.д.).
 * По производительности может уступать специализированному менеджеру [[ManagerDb]].
 *
 * @see Manager
 */
class ManagerActiveRecord extends ManagerDbTransaction
{
    use ManagerDataSerializeTrait;

    /**
     * @var string имя класса ActiveRecord, который хранит записи счётов.
     */
    public string $accountClass = '';

    /**
     * @var string имя класса ActiveRecord, который хранит записи транзакций.
     */
    public string $transactionClass = '';

    /**
     * @param array<string, mixed> $attributes
     * @throws InvalidConfigException
     */
    protected function findAccountId(array $attributes): mixed
    {
        $class = $this->ensureActiveRecordClass($this->accountClass, 'accountClass');
        $model = $class::find()->andWhere($attributes)->one();

        return $model instanceof BaseActiveRecord ? $model->getPrimaryKey() : null;
    }

    /**
     * @throws InvalidConfigException
     */
    protected function findTransaction(mixed $id): ?array
    {
        $class = $this->ensureActiveRecordClass($this->transactionClass, 'transactionClass');
        $model = $class::findOne($id);
        if (!$model instanceof BaseActiveRecord) {
            return null;
        }

        return $this->unserializeAttributes($model->getAttributes());
    }

    /**
     * @param array<string, mixed> $attributes
     * @throws Exception
     * @throws InvalidConfigException
     */
    protected function createAccount(array $attributes): mixed
    {
        $class = $this->ensureActiveRecordClass($this->accountClass, 'accountClass');
        $model = new $class();
        $model->setAttributes($this->filterWritableAttributes($model, $attributes), false);
        $model->save(false);

        return $model->getPrimaryKey();
    }

    /**
     * @param array<string, mixed> $attributes
     * @throws Exception
     * @throws InvalidConfigException
     */
    protected function createTransaction(array $attributes): mixed
    {
        $class = $this->ensureActiveRecordClass($this->transactionClass, 'transactionClass');
        $model = new $class();
        $allowedAttributes = $this->detectWritableAttributeNames($model);
        $model->setAttributes($this->serializeAttributes($attributes, $allowedAttributes), false);
        $model->save(false);

        return $model->getPrimaryKey();
    }

    /**
     * @throws InvalidConfigException|Exception
     */
    protected function incrementAccountBalance(mixed $accountId, int|float $amount): void
    {
        $class = $this->ensureActiveRecordClass($this->accountClass, 'accountClass');
        $primaryKeys = $class::primaryKey();
        $primaryKey = array_shift($primaryKeys);
        if ($primaryKey === null) {
            throw new InvalidConfigException(Manager::t('error.account_class_pk_required'));
        }

        if ($this->accountBalanceAttribute === null) {
            return;
        }

        $db = $class::getDb();
        $balanceColumn = $this->ensureSafeColumnName($this->accountBalanceAttribute);
        $accountIdColumn = $this->ensureSafeColumnName($primaryKey);
        $quotedBalanceColumn = $db->quoteColumnName($balanceColumn);
        $quotedAccountIdColumn = $db->quoteColumnName($accountIdColumn);
        $balanceExpression = new Expression($quotedBalanceColumn . ' + :amount', ['amount' => $amount]);
        $condition = $quotedAccountIdColumn . ' = :accountId';
        $params = [
            'accountId' => $accountId,
            'amount' => $amount,
        ];

        if ($this->forbidNegativeBalance && $amount < 0) {
            $condition .= sprintf(' AND %s + :amount >= :minimumBalance', $quotedBalanceColumn);
            $params['minimumBalance'] = $this->getNormalizedMinimumAllowedBalance();
        }

        $affectedRows = $class::getDb()->createCommand()->update(
            $class::tableName(),
            [$balanceColumn => $balanceExpression],
            $condition,
            $params,
        )->execute();
        if ($this->forbidNegativeBalance && $amount < 0 && $affectedRows === 0) {
            throw new InvalidArgumentException(self::t('error.insufficient_funds', [
                'accountId' => (string) $accountId,
                'minimumBalance' => (string) $this->minimumAllowedBalance,
            ]));
        }
    }

    /**
     * @throws InvalidConfigException
     */
    public function calculateBalance(mixed $account): int|float|null
    {
        $accountId = $this->fetchAccountId($account);
        $class = $this->ensureActiveRecordClass($this->transactionClass, 'transactionClass');

        /** @var ActiveQuery<ActiveRecord> $query */
        $query = $class::find();

        $balance = $query
            ->andWhere([$this->accountLinkAttribute => $accountId])
            ->sum($this->amountAttribute);
        return $balance === null ? null : $this->normalizeAmount($balance);
    }

    /**
     * @throws InvalidConfigException
     */
    protected function createDbTransaction(): ?Transaction
    {
        $class = $this->ensureActiveRecordClass($this->transactionClass, 'transactionClass');
        $db = $class::getDb();

        if ($db->hasMethod('getTransaction') && $db->getTransaction() !== null) {
            return null;
        }

        if ($db->hasMethod('beginTransaction')) {
            return $db->beginTransaction();
        }

        return null;
    }

    /**
     * @param class-string<ActiveRecord>|string $className
     * @return class-string<ActiveRecord>
     * @throws InvalidConfigException
     */
    private function ensureActiveRecordClass(string $className, string $propertyName): string
    {
        if ($className === '' || !is_subclass_of($className, ActiveRecord::class)) {
            throw new InvalidConfigException(Manager::t('error.property_must_be_active_record_class', [
                'property' => $propertyName,
            ]));
        }

        return $className;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function filterWritableAttributes(ActiveRecord $model, array $attributes): array
    {
        $allowedAttributes = $this->detectWritableAttributeNames($model);

        return array_filter($attributes, fn ($name): bool => in_array($name, $allowedAttributes, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array<int, string>
     */
    private function detectWritableAttributeNames(ActiveRecord $model): array
    {
        $allowedAttributes = $model->attributes();
        $schema = $model::getDb()->getTableSchema($model::tableName());
        if (!$schema instanceof TableSchema) {
            return $allowedAttributes;
        }

        foreach ($schema->columns as $column) {
            if ($column->isPrimaryKey === true && $column->autoIncrement === true) {
                $allowedAttributes = array_values(array_filter(
                    $allowedAttributes,
                    static fn (string $attributeName): bool => $attributeName !== $column->name,
                ));
            }
        }

        return $allowedAttributes;
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
}
