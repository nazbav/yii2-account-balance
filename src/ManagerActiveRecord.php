<?php

declare(strict_types=1);

namespace yii2tech\balance;

use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\db\ActiveRecord;
use yii\db\ActiveQuery;
use yii\db\BaseActiveRecord;
use yii\db\Exception;
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
     * @return mixed
     * @throws Exception
     * @throws InvalidConfigException
     */
    protected function createAccount(array $attributes): mixed
    {
        $class = $this->ensureActiveRecordClass($this->accountClass, 'accountClass');
        $model = new $class();
        $model->setAttributes($attributes, false);
        $model->save(false);

        return $model->getPrimaryKey();
    }

    /**
     * @param array<string, mixed> $attributes
     * @return mixed
     * @throws Exception
     * @throws InvalidConfigException
     */
    protected function createTransaction(array $attributes): mixed
    {
        $class = $this->ensureActiveRecordClass($this->transactionClass, 'transactionClass');
        $model = new $class();
        $model->setAttributes($this->serializeAttributes($attributes, $model->attributes()), false);
        $model->save(false);

        return $model->getPrimaryKey();
    }

    /**
     * @throws NotSupportedException
     * @throws InvalidConfigException
     */
    protected function incrementAccountBalance(mixed $accountId, int|float $amount): void
    {
        $class = $this->ensureActiveRecordClass($this->accountClass, 'accountClass');
        $primaryKeys = $class::primaryKey();
        $primaryKey = array_shift($primaryKeys);
        if ($primaryKey === null) {
            throw new InvalidConfigException(Manager::t('error.account_class_pk_required'));
        }

        $class::updateAllCounters([$this->accountBalanceAttribute => $amount], [$primaryKey => $accountId]);
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
     * @param class-string<BaseActiveRecord>|string $className
     * @return class-string<BaseActiveRecord>
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     */
    private function ensureActiveRecordClass(string $className, string $propertyName): string
    {
        if ($className === '' || !is_subclass_of($className, BaseActiveRecord::class)) {
            throw new InvalidConfigException(Manager::t('error.property_must_be_active_record_class', [
                'property' => $propertyName,
            ]));
        }

        return $className;
    }
}
