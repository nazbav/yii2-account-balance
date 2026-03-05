<?php

return [
    'error.transaction_not_found' => 'Не удалось найти транзакцию с идентификатором "{id}".',
    'error.account_not_found_by_filter' => 'Не удалось найти счёт по фильтру: {filter}',
    'error.amount_not_numeric' => 'Сумма операции должна быть числом.',
    'error.serialized_data_must_be_array' => 'Сериализованные данные транзакции должны восстанавливаться в массив.',
    'error.account_primary_key_not_received' => 'Не удалось получить первичный ключ после создания счёта.',
    'error.transaction_primary_key_not_received' => 'Не удалось получить первичный ключ после создания транзакции.',
    'error.table_not_found' => 'Таблица "{table}" не найдена в схеме БД.',
    'error.table_pk_required' => 'Таблица "{table}" должна иметь первичный ключ.',
    'error.account_class_pk_required' => 'Класс счёта должен иметь первичный ключ.',
    'error.property_must_be_active_record_class' => 'Свойство "{property}" должно содержать класс ActiveRecord.',
];
