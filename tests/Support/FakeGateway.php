<?php

declare(strict_types=1);

namespace Tests\Support;

use OrgBox\SiteOps\Files\FilePropertyChange;
use OrgBox\SiteOps\Files\FileUpload;
use OrgBox\SiteOps\Iblock\IblockGateway;

/** Данные в памяти. Реализует тот же контракт, что и BitrixIblockGateway. */
final class FakeGateway implements IblockGateway
{
    /** @var array<int,array<string,mixed>> */
    public array $elements = [
        10 => ['id' => 10, 'iblock_id' => 1, 'name' => 'Помада', 'code' => 'pomada', 'active' => true, 'sort' => 100, 'section_id' => 5,
            'preview_text' => '', 'preview_text_type' => 'text', 'detail_text' => 'Описание', 'detail_text_type' => 'text', 'modified' => '', 'url' => '/p/10/',
            'properties' => ['COLOR' => 'Красный', 'BRAND' => 'Acme']],
        11 => ['id' => 11, 'iblock_id' => 1, 'name' => 'Лак', 'code' => 'lak', 'active' => false, 'sort' => 200, 'section_id' => 5,
            'preview_text' => '', 'preview_text_type' => 'text', 'detail_text' => '', 'detail_text_type' => 'text', 'modified' => '', 'url' => '/p/11/', 'properties' => []],
        20 => ['id' => 20, 'iblock_id' => 2, 'name' => 'Новость', 'code' => 'news', 'active' => true, 'sort' => 1, 'section_id' => 0,
            'preview_text' => '', 'preview_text_type' => 'text', 'detail_text' => '', 'detail_text_type' => 'text', 'modified' => '', 'url' => '/n/20/', 'properties' => []],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $sections = [5 => ['id' => 5, 'iblock_id' => 1, 'name' => 'Макияж', 'code' => 'makeup', 'active' => true, 'sort' => 10, 'parent_id' => 0, 'depth' => 1, 'description' => '', 'description_type' => 'text']];
    /** @var list<string> */
    public array $calls = [];
    /** Имитация сбоя: запись «проходит», но в базе ничего не меняется. */
    public bool $dropWrites = false;
    /** @var array<int,array<string,mixed>> товары каталога: id элемента => данные */
    public array $products = [
        10 => ['quantity' => 5.0, 'reserved' => 0.0, 'available' => true, 'type' => 1,
            'prices' => [['type_id' => 1, 'price' => 100.0, 'currency' => 'RUB', 'quantity_from' => null, 'quantity_to' => null]],
            'stores' => [1 => ['title' => 'Основной', 'amount' => 3.0], 2 => ['title' => 'Магазин', 'amount' => 2.0]], 'offers' => []],
    ];
    private int $nextId = 100;
    private int $nextFileId = 500;

    public function listIblocks(?string $type): array
    {
        $all = [
            ['id' => 1, 'type' => 'catalog', 'code' => 'products', 'name' => 'Товары', 'active' => true, 'elements' => 2],
            ['id' => 2, 'type' => 'content', 'code' => 'news', 'name' => 'Новости', 'active' => true, 'elements' => 1],
        ];

        return array_values(array_filter($all, static fn (array $i): bool => $type === null || $i['type'] === $type));
    }

    public function getIblock(int $id): ?array
    {
        foreach ($this->listIblocks(null) as $row) {
            if ($row['id'] === $id) {
                return ['id' => $row['id'], 'type' => $row['type'], 'code' => $row['code'], 'name' => $row['name']];
            }
        }

        return null;
    }

    public function getProperties(int $iblockId): array
    {
        return $iblockId === 1 ? [
            ['code' => 'COLOR', 'name' => 'Цвет', 'type' => 'L', 'multiple' => false, 'required' => false, 'enum' => [1 => 'Красный', 2 => 'Синий']],
            ['code' => 'BRAND', 'name' => 'Бренд', 'type' => 'S', 'multiple' => false, 'required' => false, 'enum' => []],
            ['code' => 'TAGS', 'name' => 'Теги', 'type' => 'S', 'multiple' => true, 'required' => false, 'enum' => []],
            ['code' => 'PHOTO', 'name' => 'Фото', 'type' => 'F', 'multiple' => false, 'required' => false, 'enum' => []],
            ['code' => 'MORE_PHOTO', 'name' => 'Доп. фото', 'type' => 'F', 'multiple' => true, 'required' => false, 'enum' => []],
            ['code' => 'EMPTY_PROP', 'name' => 'Пустое', 'type' => 'S', 'multiple' => false, 'required' => false, 'enum' => []],
        ] : [];
    }

    public function listSections(int $iblockId, ?int $parentId, int $limit, int $page): array
    {
        $items = array_values(array_filter($this->sections, static fn (array $s): bool => $s['iblock_id'] === $iblockId));

        return ['items' => $items, 'total' => count($items)];
    }

    public function getSection(int $id): ?array
    {
        return $this->sections[$id] ?? null;
    }

    public function listElements(int $iblockId, array $filter, int $limit, int $page): array
    {
        $items = array_values(array_filter($this->elements, static function (array $e) use ($iblockId, $filter): bool {
            return $e['iblock_id'] === $iblockId
                && (!isset($filter['active']) || $e['active'] === $filter['active'])
                && (!isset($filter['query']) || mb_stripos($e['name'], $filter['query']) !== false);
        }));

        return ['items' => array_slice($items, ($page - 1) * $limit, $limit), 'total' => count($items)];
    }

    public function getElement(int $id): ?array
    {
        return $this->elements[$id] ?? null;
    }

    public function updateElement(int $id, array $fields, array $properties): void
    {
        $this->calls[] = 'updateElement:' . $id;
        foreach ($fields as $name => $value) {
            $this->elements[$id][$name === 'IBLOCK_SECTION_ID' ? 'section_id' : strtolower($name)] = $value instanceof FileUpload ? $value->describe() : $value;
        }
        if ($this->dropWrites) {
            return;
        }
        foreach ($properties as $code => $value) {
            $this->applyProperty($id, $code, $value);
        }
    }

    /** @return array{id:int,src:string,name:string,width:int,height:int,size:int} */
    private function newFile(FileUpload $upload): array
    {
        $id = $this->nextFileId++;

        return ['id' => $id, 'src' => '/upload/' . $id . '/' . $upload->name, 'name' => $upload->name, 'width' => $upload->width, 'height' => $upload->height, 'size' => strlen($upload->bytes)];
    }

    private function applyProperty(int $id, string $code, mixed $value): void
    {
        $props = &$this->elements[$id]['properties'];
        if ($value instanceof FilePropertyChange) {
            $multiple = $code === 'MORE_PHOTO';
            $files = isset($props[$code]) ? ($multiple ? $props[$code] : [$props[$code]]) : [];
            $files = array_values(array_filter($files, static fn (array $f): bool => !in_array($f['id'], $value->delete, true)));
            if (!$multiple && $value->add !== []) {
                $files = [];
            }
            foreach ($value->add as $upload) {
                $files[] = $this->newFile($upload);
            }
            if ($files === []) {
                unset($props[$code]);
            } else {
                $props[$code] = $multiple ? $files : $files[0];
            }

            return;
        }
        if ($value === null || $value === []) {
            unset($props[$code]);

            return;
        }
        $enum = $this->getProperties($this->elements[$id]['iblock_id'])[0]['enum'];
        $props[$code] = $code === 'COLOR' ? $enum[$value] : $value;
    }

    public function createElement(int $iblockId, array $fields, array $properties): int
    {
        $this->calls[] = 'createElement';
        $id = $this->nextId++;
        $this->elements[$id] = ['id' => $id, 'iblock_id' => $iblockId, 'name' => $fields['NAME'], 'active' => $fields['ACTIVE'], 'properties' => []]
            + array_map(static fn ($v) => $v instanceof FileUpload ? $v->describe() : $v, array_change_key_case($fields)) + $this->elements[10];
        foreach ($properties as $code => $value) {
            $this->applyProperty($id, $code, $value);
        }

        return $id;
    }

    public function listPriceTypes(): array
    {
        return [
            ['id' => 1, 'code' => 'BASE', 'name' => 'Базовая цена', 'base' => true],
            ['id' => 2, 'code' => 'OPT', 'name' => 'Оптовая цена', 'base' => false],
        ];
    }

    public function getProduct(int $id): ?array
    {
        return $this->products[$id] ?? null;
    }

    public function updateProduct(int $id, array $prices, ?float $quantity, array $stores): void
    {
        $this->calls[] = 'updateProduct:' . $id;
        if ($this->dropWrites) {
            return;
        }
        foreach ($prices as $typeId => $item) {
            $found = false;
            foreach ($this->products[$id]['prices'] as &$row) {
                if ($row['type_id'] === $typeId && $row['quantity_from'] === null) {
                    $row['price'] = $item['price'];
                    $row['currency'] = $item['currency'] ?? $row['currency'];
                    $found = true;
                }
            }
            unset($row);
            if (!$found) {
                $this->products[$id]['prices'][] = ['type_id' => $typeId, 'price' => $item['price'], 'currency' => $item['currency'] ?? 'RUB', 'quantity_from' => null, 'quantity_to' => null];
            }
        }
        foreach ($stores as $storeId => $amount) {
            $this->products[$id]['stores'][$storeId]['amount'] = $amount;
        }
        if ($quantity !== null) {
            $this->products[$id]['quantity'] = $quantity;
        }
    }

    public function clearCache(?int $iblockId): void
    {
        $this->calls[] = 'clearCache:' . ($iblockId ?? 'all');
    }

    public function updateSection(int $id, array $fields): void
    {
        $this->calls[] = 'updateSection:' . $id;
        foreach ($fields as $name => $value) {
            $this->sections[$id][strtolower($name)] = $value;
        }
    }

}
