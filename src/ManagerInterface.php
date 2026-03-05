<?php

declare(strict_types=1);

namespace yii2tech\balance;

/**
 * ManagerInterface определяет интерфейс менеджера баланса.
 *
 * @since 1.0
 */
interface ManagerInterface
{
    /**
     * Увеличивает текущий баланс счёта (операция дебета).
     *
     * @param mixed $account ID счёта или фильтр.
     * @param int|float $amount сумма.
     * @param array<string, mixed> $data дополнительные данные транзакции.
     */
    public function increase(mixed $account, int|float $amount, array $data = []): mixed;

    /**
     * Уменьшает текущий баланс счёта (операция кредита).
     *
     * @param mixed $account ID счёта или фильтр.
     * @param int|float $amount сумма.
     * @param array<string, mixed> $data дополнительные данные транзакции.
     */
    public function decrease(mixed $account, int|float $amount, array $data = []): mixed;

    /**
     * Переводит сумму с одного счёта на другой.
     *
     * @param mixed $from ID счёта-источника или фильтр.
     * @param mixed $to ID счёта-получателя или фильтр.
     * @param int|float $amount сумма.
     * @param array<string, mixed> $data дополнительные данные транзакции.
     * @return array<int, mixed> список ID созданных транзакций.
     */
    public function transfer(mixed $from, mixed $to, int|float $amount, array $data = []): array;

    /**
     * Откатывает указанную транзакцию.
     *
     * @param mixed $transactionId ID транзакции для отката.
     * @param array<string, mixed> $data дополнительные данные транзакции.
     */
    public function revert(mixed $transactionId, array $data = []): mixed;

    /**
     * Вычисляет текущий баланс счёта по всем связанным транзакциям.
     *
     * @param mixed $account ID счёта или фильтр.
     */
    public function calculateBalance(mixed $account): int|float|null;
}
