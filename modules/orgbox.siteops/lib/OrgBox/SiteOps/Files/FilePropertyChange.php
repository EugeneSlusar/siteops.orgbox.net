<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Files;

/**
 * Изменение файлового свойства элемента: какие файлы добавить и какие (по ID файла) удалить.
 * Остальные файлы свойства остаются как есть. У не множественного свойства добавление заменяет прежний файл.
 */
final class FilePropertyChange
{
    /**
     * @param list<FileUpload> $add
     * @param list<int> $delete ID файлов, как их показывает element_get
     */
    public function __construct(
        public readonly array $add,
        public readonly array $delete,
    ) {
    }

    /** Описание без содержимого файлов: для ответа и журнала событий. @return array<string,mixed> */
    public function describe(): array
    {
        return [
            'add' => array_map(static fn (FileUpload $f): array => $f->describe(), $this->add),
            'delete' => $this->delete,
        ];
    }
}
