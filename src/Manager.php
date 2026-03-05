<?php

declare(strict_types=1);

namespace yii2tech\balance;

use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\helpers\VarDumper;
use yii\i18n\PhpMessageSource;

/**
 * Manager — базовый класс для менеджеров баланса.
 *
 * @see ManagerInterface
 *
 * @since 1.0
 */
abstract class Manager extends Component implements ManagerInterface
{
    /**
     * Категория сообщений i18n для расширения.
     */
    public const I18N_CATEGORY = 'yii2tech.balance';

    /**
     * @event TransactionEvent событие перед созданием новой транзакции.
     */
    public const EVENT_BEFORE_CREATE_TRANSACTION = 'beforeCreateTransaction';

    /**
     * @event TransactionEvent событие после создания новой транзакции.
     */
    public const EVENT_AFTER_CREATE_TRANSACTION = 'afterCreateTransaction';

    /**
     * @var bool автоматически создавать запрошенный счёт, если он ещё не существует.
     */
    public bool $autoCreateAccount = true;

    /**
     * @var string имя атрибута сущности транзакции, в котором хранится сумма.
     */
    public string $amountAttribute = 'amount';

    /**
     * @var string имя атрибута сущности транзакции для связи транзакции со счётом.
     */
    public string $accountLinkAttribute = 'accountId';

    /**
     * @var string|null атрибут для хранения ID второго счёта в операции перевода.
     */
    public ?string $extraAccountLinkAttribute = null;

    /**
     * @var string|null имя атрибута сущности счёта с текущим значением баланса.
     */
    public ?string $accountBalanceAttribute = null;

    /**
     * @var string имя атрибута сущности транзакции для хранения даты.
     */
    public string $dateAttribute = 'date';

    /**
     * @var mixed значение или колбэк для формирования даты.
     */
    public mixed $dateAttributeValue = null;

    /**
     * Возвращает локализованное сообщение расширения.
     *
     * @param array<string, mixed> $params
     */
    public static function t(string $key, array $params = []): string
    {
        self::ensureI18nCategory();

        return \Yii::t(self::I18N_CATEGORY, $key, $params);
    }

    /**
     * @param mixed $account
     * @param int|float $amount
     * @param array<string, mixed> $data
     */
    public function increase(mixed $account, int|float $amount, array $data = []): mixed
    {
        $accountId = $this->fetchAccountId($account);

        if (!isset($data[$this->dateAttribute])) {
            $data[$this->dateAttribute] = $this->getDateAttributeValue();
        }
        $data[$this->amountAttribute] = $amount;
        $data[$this->accountLinkAttribute] = $accountId;

        $data = $this->beforeCreateTransaction($accountId, $data);

        if ($this->accountBalanceAttribute !== null) {
            $this->incrementAccountBalance($accountId, $this->normalizeAmount($data[$this->amountAttribute]));
        }
        $transactionId = $this->createTransaction($data);

        $this->afterCreateTransaction($transactionId, $accountId, $data);

        return $transactionId;
    }

    /**
     * @param mixed $account
     * @param int|float $amount
     * @param array<string, mixed> $data
     */
    public function decrease(mixed $account, int|float $amount, array $data = []): mixed
    {
        return $this->increase($account, -$amount, $data);
    }

    /**
     * @param mixed $from
     * @param mixed $to
     * @param int|float $amount
     * @param array<string, mixed> $data
     * @return array<int, mixed>
     */
    public function transfer(mixed $from, mixed $to, int|float $amount, array $data = []): array
    {
        $fromId = $this->fetchAccountId($from);
        $toId = $this->fetchAccountId($to);

        $data[$this->dateAttribute] = $this->getDateAttributeValue();
        $fromData = $data;
        $toData = $data;

        if ($this->extraAccountLinkAttribute !== null) {
            $fromData[$this->extraAccountLinkAttribute] = $toId;
            $toData[$this->extraAccountLinkAttribute] = $fromId;
        }

        return [
            $this->decrease($fromId, $amount, $fromData),
            $this->increase($toId, $amount, $toData),
        ];
    }

    /**
     * @param mixed $transactionId
     * @param array<string, mixed> $data
     * @return mixed
     */
    public function revert(mixed $transactionId, array $data = []): mixed
    {
        $transaction = $this->findTransaction($transactionId);
        if (empty($transaction)) {
            throw new InvalidArgumentException(self::t('error.transaction_not_found', [
                'id' => (string) $transactionId,
            ]));
        }

        $amount = $this->normalizeAmount($transaction[$this->amountAttribute]);

        if ($this->extraAccountLinkAttribute !== null && isset($transaction[$this->extraAccountLinkAttribute])) {
            $fromId = $transaction[$this->accountLinkAttribute];
            $toId = $transaction[$this->extraAccountLinkAttribute];

            return $this->transfer($fromId, $toId, $amount, $data);
        }

        $accountId = $transaction[$this->accountLinkAttribute];

        return $this->decrease($accountId, $amount, $data);
    }

    /**
     * @param mixed $idOrFilter ID счёта или условие фильтра.
     */
    protected function fetchAccountId(mixed $idOrFilter): mixed
    {
        if (is_array($idOrFilter)) {
            $accountId = $this->findAccountId($idOrFilter);
            if ($accountId === null) {
                if ($this->autoCreateAccount) {
                    $accountId = $this->createAccount($idOrFilter);
                } else {
                    throw new InvalidArgumentException(
                        self::t('error.account_not_found_by_filter', [
                            'filter' => VarDumper::export($idOrFilter),
                        ])
                    );
                }
            }

            return $accountId;
        }

        return $idOrFilter;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    abstract protected function findAccountId(array $attributes): mixed;

    /**
     * @return array<string, mixed>|null
     */
    abstract protected function findTransaction(mixed $id): ?array;

    /**
     * @param array<string, mixed> $attributes
     */
    abstract protected function createAccount(array $attributes): mixed;

    /**
     * @param array<string, mixed> $attributes
     */
    abstract protected function createTransaction(array $attributes): mixed;

    abstract protected function incrementAccountBalance(mixed $accountId, int|float $amount): void;

    protected function getDateAttributeValue(): mixed
    {
        if ($this->dateAttributeValue === null) {
            return time();
        }

        if (is_callable($this->dateAttributeValue)) {
            return ($this->dateAttributeValue)();
        }

        return $this->dateAttributeValue;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function normalizeAmount(mixed $amount): int|float
    {
        if (is_int($amount) || is_float($amount)) {
            return $amount;
        }
        if (is_numeric($amount)) {
            return str_contains((string) $amount, '.') ? (float) $amount : (int) $amount;
        }

        throw new InvalidArgumentException(self::t('error.amount_not_numeric'));
    }

    /**
     * @param mixed $accountId
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function beforeCreateTransaction(mixed $accountId, array $data): array
    {
        $event = new TransactionEvent([
            'accountId' => $accountId,
            'transactionData' => $data,
        ]);
        $this->trigger(self::EVENT_BEFORE_CREATE_TRANSACTION, $event);

        return $event->transactionData;
    }

    /**
     * @param mixed $transactionId
     * @param mixed $accountId
     * @param array<string, mixed> $data
     */
    protected function afterCreateTransaction(mixed $transactionId, mixed $accountId, array $data): void
    {
        $event = new TransactionEvent([
            'transactionId' => $transactionId,
            'accountId' => $accountId,
            'transactionData' => $data,
        ]);
        $this->trigger(self::EVENT_AFTER_CREATE_TRANSACTION, $event);
    }

    private static function ensureI18nCategory(): void
    {
        if (!isset(\Yii::$app->i18n)) {
            return;
        }

        if (isset(\Yii::$app->i18n->translations[self::I18N_CATEGORY])) {
            return;
        }

        \Yii::$app->i18n->translations[self::I18N_CATEGORY] = [
            'class' => PhpMessageSource::class,
            'basePath' => '@yii2tech/balance/messages',
            'sourceLanguage' => 'xx-XX',
            'fileMap' => [
                self::I18N_CATEGORY => 'yii2tech.balance.php',
            ],
        ];
    }
}
