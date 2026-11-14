<?php

declare(strict_types=1);

namespace Tests\Support;

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
    /** @var array<string,string> */
    public array $seo = ['ELEMENT_META_TITLE' => '{=this.NAME}'];
    /** @var list<string> */
    public array $calls = [];
    private int $nextId = 100;

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
            $this->elements[$id][$name === 'IBLOCK_SECTION_ID' ? 'section_id' : strtolower($name)] = $value;
        }
        foreach ($properties as $code => $value) {
            $enum = $this->getProperties($this->elements[$id]['iblock_id'])[0]['enum'];
            $this->elements[$id]['properties'][$code] = $code === 'COLOR' ? $enum[$value] : $value;
        }
    }

    public function createElement(int $iblockId, array $fields, array $properties): int
    {
        $this->calls[] = 'createElement';
        $id = $this->nextId++;
        $this->elements[$id] = ['id' => $id, 'iblock_id' => $iblockId, 'name' => $fields['NAME'], 'active' => $fields['ACTIVE'], 'properties' => $properties] + $this->elements[10];

        return $id;
    }

    public function updateSection(int $id, array $fields): void
    {
        $this->calls[] = 'updateSection:' . $id;
        foreach ($fields as $name => $value) {
            $this->sections[$id][strtolower($name)] = $value;
        }
    }

    public function getSeoTemplates(int $iblockId): array
    {
        return $this->seo;
    }

    public function setSeoTemplates(int $iblockId, array $templates): void
    {
        $this->calls[] = 'setSeo';
        $this->seo = $templates + $this->seo;
    }
}
