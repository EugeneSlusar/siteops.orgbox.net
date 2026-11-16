<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Iblock;

/**
 * Значения полей из строки, полученной через CDBResult::GetNext(true, true).
 * GetNext экранирует текстовые поля для вывода в HTML (&lt;p&gt;), а исходные значения кладёт рядом с префиксом «~».
 * Для чтения и записи через API нужны именно исходные значения, иначе при записи текст экранировался бы повторно.
 */
final class RawRow
{
    /** @param array<string,mixed> $row */
    public static function get(array $row, string $field): mixed
    {
        return array_key_exists('~' . $field, $row) ? $row['~' . $field] : ($row[$field] ?? null);
    }

    /** @param array<string,mixed> $row */
    public static function string(array $row, string $field): string
    {
        $value = self::get($row, $field);

        return is_scalar($value) ? (string) $value : '';
    }
}
