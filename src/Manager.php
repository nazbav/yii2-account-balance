<?php

declare(strict_types=1);

namespace yii2tech\balance;

use Yii;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
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
     * @var bool требовать положительную сумму во всех публичных операциях.
     */
    public bool $requirePositiveAmount = true;

    /**
     * @var bool запрещать перевод между одинаковыми счетами.
     */
    public bool $forbidTransferToSameAccount = true;

    /**
     * @var bool запрещать уход баланса ниже установленного порога.
     */
    public bool $forbidNegativeBalance = false;

    /**
     * @var int|float минимально допустимый баланс, если включён запрет отрицательного баланса.
     */
    public int|float $minimumAllowedBalance = 0;

    /**
     * Возвращает локализованное сообщение расширения.
     *
     * @param array<string, mixed> $params
     */
    public static function t(string $key, array $params = []): string
    {
        self::ensureI18nCategory();

        return Yii::t(self::I18N_CATEGORY, $key, $params);
    }

    /**
     * @param mixed $account
     * @param int|float $amount
     * @param array<string, mixed> $data
     * @return mixed
     */
    public function increase(mixed $account, int|float $amount, array $data = []): mixed
    {
        $normalizedAmount = $this->normalizeAmount($amount);
        if ($this->requirePositiveAmount) {
            $this->assertRequestedAmountIsPositive($normalizedAmount);
        }

        return $this->createSignedTransaction($account, $normalizedAmount, $data);
    }

    /**
     * @param mixed $account
     * @param int|float $amount
     * @param array<string, mixed> $data
     * @return mixed
     */
    public function decrease(mixed $account, int|float $amount, array $data = []): mixed
    {
        $normalizedAmount = $this->normalizeAmount($amount);
        if ($this->requirePositiveAmount) {
            $this->assertRequestedAmountIsPositive($normalizedAmount);
        }

        return $this->createSignedTransaction($account, -$normalizedAmount, $data);
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
        $normalizedAmount = $this->normalizeAmount($amount);
        if ($this->requirePositiveAmount) {
            $this->assertRequestedAmountIsPositive($normalizedAmount);
        }

        $fromId = $this->fetchAccountId($from);
        $toId = $this->fetchAccountId($to);
        if ($this->forbidTransferToSameAccount && $this->isSameAccount($fromId, $toId)) {
            throw new InvalidArgumentException(self::t('error.transfer_same_account_forbidden'));
        }

        $data[$this->dateAttribute] = $this->getDateAttributeValue();
        $fromData = $data;
        $toData = $data;

        if ($this->extraAccountLinkAttribute !== null) {
            $fromData[$this->extraAccountLinkAttribute] = $toId;
            $toData[$this->extraAccountLinkAttribute] = $fromId;
        }

        return [
            $this->decrease($fromId, $normalizedAmount, $fromData),
            $this->increase($toId, $normalizedAmount, $toData),
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
            $accountId = $transaction[$this->accountLinkAttribute];
            $extraAccountId = $transaction[$this->extraAccountLinkAttribute];
            $absoluteAmount = abs($amount);

            if ($amount < 0) {
                return $this->transfer($extraAccountId, $accountId, $absoluteAmount, $data);
            }

            return $this->transfer($accountId, $extraAccountId, $absoluteAmount, $data);
        }

        $accountId = $transaction[$this->accountLinkAttribute];
        if ($amount < 0) {
            return $this->increase($accountId, abs($amount), $data);
        }

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

    /**
     * @param mixed $account
     * @param int|float $signedAmount
     * @param array<string, mixed> $data
     * @throws InvalidConfigException
     */
    protected function createSignedTransaction(mixed $account, int|float $signedAmount, array $data = []): mixed
    {
        $accountId = $this->fetchAccountId($account);

        if (!isset($data[$this->dateAttribute])) {
            $data[$this->dateAttribute] = $this->getDateAttributeValue();
        }
        $data[$this->amountAttribute] = $signedAmount;
        $data[$this->accountLinkAttribute] = $accountId;

        $data = $this->beforeCreateTransaction($accountId, $data);

        if ($this->accountBalanceAttribute !== null) {
            $this->incrementAccountBalance($accountId, $this->normalizeAmount($data[$this->amountAttribute]));
        } elseif ($this->forbidNegativeBalance && $signedAmount < 0) {
            throw new InvalidConfigException(self::t('error.account_balance_attribute_required_for_negative_protection'));
        }

        $transactionId = $this->createTransaction($data);

        $this->afterCreateTransaction($transactionId, $accountId, $data);

        return $transactionId;
    }

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
     * @throws InvalidArgumentException
     */
    protected function assertRequestedAmountIsPositive(int|float $amount): void
    {
        if (is_float($amount) && !is_finite($amount)) {
            throw new InvalidArgumentException(self::t('error.amount_must_be_finite'));
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException(self::t('error.amount_must_be_positive'));
        }
    }

    protected function isSameAccount(mixed $firstAccountId, mixed $secondAccountId): bool
    {
        return (string) $firstAccountId === (string) $secondAccountId;
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
        $i18n = Yii::$app->getI18n();
        if (isset($i18n->translations[self::I18N_CATEGORY])) {
            return;
        }

        $i18n->translations[self::I18N_CATEGORY] = [
            'class' => PhpMessageSource::class,
            'basePath' => dirname(__DIR__) . '/messages',
            'sourceLanguage' => 'xx-XX',
            'fileMap' => [
                self::I18N_CATEGORY => 'yii2tech.balance.php',
            ],
        ];
    }
}
