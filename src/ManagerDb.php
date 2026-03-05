<?php
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
 * Configuration example:
 *
 * ```php
 * return [
 *     'components' => [
 *         'balanceManager' => [
 *             'class' => 'yii2tech\balance\ManagerDb',
 *             'accountTable' => '{{%BalanceAccount}}',
 *             'transactionTable' => '{{%BalanceTransaction}}',
 *             'accountBalanceAttribute' => 'balance',
 *             'extraAccountLinkAttribute' => 'extraAccountId',
 *             'dataAttribute' => 'data',
 *         ],
 *     ],
 *     ...
 * ];
 * ```
 *
 * Database migration example:
 *
 * ```php
 * $this->createTable('BalanceAccount', [
 *     'id' => $this->primaryKey(),
 *     'balance' => $this->integer()->notNull()->defaultValue(0),
 *     // ...
 * ]);
 *
 * $this->createTable('BalanceTransaction', [
 *     'id' => $this->primaryKey(),
 *     'date' => $this->integer()->notNull(),
 *     'accountId' => $this->integer()->notNull(),
 *     'extraAccountId' => $this->integer()->notNull(),
 *     'amount' => $this->integer()->notNull()->defaultValue(0),
 *     'data' => $this->text(),
 *     // ...
 * ]);
 * ```
 *
 * This manager will attempt to save value from transaction data in the table column, which name matches data key.
 * If such column does not exist data will be saved in [[dataAttribute]] column in serialized state.
 *
 * > Note: watch for the keys you use in transaction data: make sure they do not conflict with columns, which are
 *   reserved for other purposes, like primary keys.
 *
 * @see Manager
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
class ManagerDb extends ManagerDbTransaction
{
    use ManagerDataSerializeTrait;

    /**
     * @var Connection|array|string the DB connection object or the application component ID of the DB connection.
     * After the ManagerDb object is created, if you want to change this property, you should only assign it
     * with a DB connection object.
     */
    public $db = 'db';
    /**
     * @var string name of the database table, which should store account records.
     */
    public $accountTable = '{{%BalanceAccount}}';
    /**
     * @var string name of the database table, which should store transaction records.
     */
    public $transactionTable = '{{%BalanceTransaction}}';

    /**
     * @var string name of the account ID attribute at [[accountTable]]
     */
    private $_accountIdAttribute;
    /**
     * @var string name of the transaction ID attribute at [[transactionTable]]
     */
    private $_transactionIdAttribute;


    /**
     * {@inheritdoc}
     */
    public function init(): void
    {
        parent::init();
        $this->db = $this->getDbConnection();
    }

    /**
     * @return string
     */
    public function getAccountIdAttribute(): string
    {
        if ($this->_accountIdAttribute === null) {
            $this->_accountIdAttribute = $this->detectPrimaryKey($this->accountTable);
        }
        return $this->_accountIdAttribute;
    }

    /**
     * @param string $accountIdAttribute
     */
    public function setAccountIdAttribute($accountIdAttribute): void
    {
        $this->_accountIdAttribute = $accountIdAttribute;
    }

    /**
     * @return string
     */
    public function getTransactionIdAttribute(): string
    {
        if ($this->_transactionIdAttribute === null) {
            $this->_transactionIdAttribute = $this->detectPrimaryKey($this->transactionTable);
        }
        return $this->_transactionIdAttribute;
    }

    /**
     * @param string $transactionIdAttribute
     */
    public function setTransactionIdAttribute($transactionIdAttribute): void
    {
        $this->_transactionIdAttribute = $transactionIdAttribute;
    }

    /**
     * {@inheritdoc}
     */
    protected function findAccountId($attributes)
    {
        $db = $this->getDbConnection();
        $id = (new Query())
            ->select([$this->getAccountIdAttribute()])
            ->from($this->accountTable)
            ->andWhere($attributes)
            ->scalar($db);

        if ($id === false) {
            return null;
        }
        return $id;
    }

    /**
     * {@inheritdoc}
     */
    protected function findTransaction($id)
    {
        $idAttribute = $this->getTransactionIdAttribute();
        $db = $this->getDbConnection();

        $row = (new Query())
            ->from($this->transactionTable)
            ->andWhere([$idAttribute => $id])
            ->one($db);

        if (!is_array($row)) {
            return null;
        }
        return $this->unserializeAttributes($row);
    }

    /**
     * {@inheritdoc}
     */
    protected function createAccount($attributes)
    {
        $primaryKeys = $this->getDbConnection()->getSchema()->insert($this->accountTable, $attributes);
        if (!is_array($primaryKeys) || $primaryKeys === []) {
            throw new InvalidConfigException('Не удалось получить первичный ключ после создания счёта.');
        }
        if (count($primaryKeys) > 1) {
            return implode(',', $primaryKeys);
        }
        return array_shift($primaryKeys);
    }

    /**
     * {@inheritdoc}
     */
    protected function createTransaction($attributes)
    {
        $allowedAttributes = [];
        foreach ($this->getRequiredTableSchema($this->transactionTable)->columns as $column) {
            if ($column->isPrimaryKey && $column->autoIncrement) {
                continue;
            }
            $allowedAttributes[] = $column->name;
        }
        $attributes = $this->serializeAttributes($attributes, $allowedAttributes);
        $primaryKeys = $this->getDbConnection()->getSchema()->insert($this->transactionTable, $attributes);
        if (!is_array($primaryKeys) || $primaryKeys === []) {
            throw new InvalidConfigException('Не удалось получить первичный ключ после создания транзакции.');
        }
        if (count($primaryKeys) > 1) {
            return implode(',', $primaryKeys);
        }
        return array_shift($primaryKeys);
    }

    /**
     * {@inheritdoc}
     */
    protected function incrementAccountBalance($accountId, $amount)
    {
        $value = new Expression("[[{$this->accountBalanceAttribute}]]+:amount", ['amount' => $amount]);
        $this->getDbConnection()->createCommand()
            ->update($this->accountTable, [$this->accountBalanceAttribute => $value], [$this->getAccountIdAttribute() => $accountId])
            ->execute();
    }

    /**
     * {@inheritdoc}
     */
    public function calculateBalance($account)
    {
        $accountId = $this->fetchAccountId($account);
        $db = $this->getDbConnection();

        return (new Query())
            ->from($this->transactionTable)
            ->andWhere([$this->accountLinkAttribute => $accountId])
            ->sum($this->amountAttribute, $db);
    }

    /**
     * {@inheritdoc}
     */
    protected function createDbTransaction()
    {
        $db = $this->getDbConnection();
        if ($db->getTransaction() !== null) {
            return null;
        }
        return $db->beginTransaction();
    }

    /**
     * @return Connection
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
     * @param string $tableName
     * @return TableSchema
     */
    private function getRequiredTableSchema($tableName): TableSchema
    {
        $schema = $this->getDbConnection()->getTableSchema($tableName);
        if ($schema === null) {
            throw new InvalidConfigException("Таблица '{$tableName}' не найдена в схеме БД.");
        }

        return $schema;
    }

    /**
     * @param string $tableName
     * @return string
     */
    private function detectPrimaryKey($tableName): string
    {
        $primaryKeys = $this->getRequiredTableSchema($tableName)->primaryKey;
        $primaryKey = array_shift($primaryKeys);
        if ($primaryKey === null) {
            throw new InvalidConfigException("Таблица '{$tableName}' должна иметь первичный ключ.");
        }

        return $primaryKey;
    }
}
