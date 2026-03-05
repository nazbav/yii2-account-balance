<?php

declare(strict_types=1);

namespace nazbav\tests\unit\balance\data;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $date
 * @property int $accountId
 * @property int $amount
 * @property string $data
 */
class BalanceTransaction extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'BalanceTransaction';
    }
}
