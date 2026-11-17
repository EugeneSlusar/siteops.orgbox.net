<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Files;

/** Проверенная картинка, готовая к записи в файловое поле элемента. */
final class FileUpload
{
    public function __construct(
        public readonly string $name,
        public readonly string $mime,
        public readonly string $bytes,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    /** Краткое описание без содержимого: для ответа ИИ и журнала событий. @return array<string,mixed> */
    public function describe(): array
    {
        return ['name' => $this->name, 'mime' => $this->mime, 'size' => strlen($this->bytes), 'width' => $this->width, 'height' => $this->height];
    }
}
