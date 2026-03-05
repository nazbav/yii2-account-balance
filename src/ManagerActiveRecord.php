<?php

declare(strict_types=1);

/**
 * @link https://github.com/yii2tech
 * @copyright Copyright (c) 2015 Yii2tech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace yii2tech\balance;

use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
use yii\db\ActiveQuery;
use yii\db\BaseActiveRecord;
use yii\db\Transaction;

/**
 * ManagerActiveRecord is a balance manager, which uses ActiveRecord classes for data storage.
 * This manager allows usage of any storage, which have ActiveRecord interface implemented, such as
 * relational DB, Redis etc. However, it may lack efficiency comparing to the dedicated
 * [[ManagerDb]] manager.
 *
 * @see Manager
 */
class ManagerActiveRecord extends ManagerDbTransaction
{
    use ManagerDataSerializeTrait;

    /**
     * @var string name of the ActiveRecord class, which should store account records.
     */
    public string $accountClass = '';

    /**
     * @var string name of the ActiveRecord class, which should store transaction records.
     */
    public string $transactionClass = '';

    /**
     * @param array<string, mixed> $attributes
     */
    protected function findAccountId(array $attributes): mixed
    {
        $class = $this->ensureActiveRecordClass($this->accountClass, 'accountClass');
        $model = $class::find()->andWhere($attributes)->one();

        return $model instanceof BaseActiveRecord ? $model->getPrimaryKey(false) : null;
    }

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
     */
    protected function createAccount(array $attributes): mixed
    {
        $class = $this->ensureActiveRecordClass($this->accountClass, 'accountClass');
        $model = new $class();
        $model->setAttributes($attributes, false);
        $model->save(false);

        return $model->getPrimaryKey(false);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createTransaction(array $attributes): mixed
    {
        $class = $this->ensureActiveRecordClass($this->transactionClass, 'transactionClass');
        $model = new $class();
        $model->setAttributes($this->serializeAttributes($attributes, $model->attributes()), false);
        $model->save(false);

        return $model->getPrimaryKey(false);
    }

    protected function incrementAccountBalance(mixed $accountId, int|float $amount): void
    {
        $class = $this->ensureActiveRecordClass($this->accountClass, 'accountClass');
        $primaryKeys = $class::primaryKey();
        $primaryKey = array_shift($primaryKeys);
        if ($primaryKey === null) {
            throw new InvalidConfigException('Класс счёта должен иметь первичный ключ.');
        }

        $class::updateAllCounters([$this->accountBalanceAttribute => $amount], [$primaryKey => $accountId]);
    }

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
     */
    private function ensureActiveRecordClass(string $className, string $propertyName): string
    {
        if ($className === '' || !is_subclass_of($className, BaseActiveRecord::class)) {
            throw new InvalidConfigException("Свойство \"{$propertyName}\" должно содержать класс ActiveRecord.");
        }

        return $className;
    }
}
