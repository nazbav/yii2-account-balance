<?php

declare(strict_types=1);

namespace nazbav\balance;

/**
 * BalanceRules инкапсулирует набор правил выполнения операций баланса.
 */
final class BalanceRules
{
    public function __construct(
        public readonly bool $requirePositiveAmount = true,
        public readonly bool $forbidTransferToSameAccount = true,
        public readonly bool $forbidNegativeBalance = false,
        public readonly int|float $minimumAllowedBalance = 0,
    ) {
    }

    /**
     * Возвращает строгий профиль правил для денежных и бонусных сценариев.
     */
    public static function strict(int|float $minimumAllowedBalance = 0): self
    {
        return new self(
            requirePositiveAmount: true,
            forbidTransferToSameAccount: true,
            forbidNegativeBalance: true,
            minimumAllowedBalance: $minimumAllowedBalance,
        );
    }
}
