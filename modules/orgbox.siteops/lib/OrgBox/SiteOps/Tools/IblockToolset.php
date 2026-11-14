<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Tools;

use OrgBox\SiteOps\Audit\AuditLogger;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Iblock\IblockGateway;
use OrgBox\SiteOps\Mcp\Tool;
use OrgBox\SiteOps\Mcp\ToolException;
use OrgBox\SiteOps\Mcp\ToolRegistry;

/**
 * MCP-инструменты для инфоблоков. Правила безопасности:
 *  - запись доступна только в режиме «чтение и запись» и только для инфоблоков из явного списка;
 *  - удаления нет совсем, только деактивация (ACTIVE = false);
 *  - любое изменение сначала можно проверить через dry_run и записывается в журнал событий.
 */
final class IblockToolset
{
    public const SEO_KEYS = [
        'SECTION_META_TITLE', 'SECTION_META_DESCRIPTION', 'SECTION_META_KEYWORDS', 'SECTION_PAGE_TITLE',
        'ELEMENT_META_TITLE', 'ELEMENT_META_DESCRIPTION', 'ELEMENT_META_KEYWORDS', 'ELEMENT_PAGE_TITLE',
    ];

    private const ELEMENT_FIELDS = [
        'NAME' => 'string255', 'CODE' => 'code', 'ACTIVE' => 'bool', 'SORT' => 'sort',
        'PREVIEW_TEXT' => 'text', 'DETAIL_TEXT' => 'text',
        'PREVIEW_TEXT_TYPE' => 'texttype', 'DETAIL_TEXT_TYPE' => 'texttype',
        'IBLOCK_SECTION_ID' => 'id0',
    ];

    private const SECTION_FIELDS = [
        'NAME' => 'string255', 'CODE' => 'code', 'ACTIVE' => 'bool', 'SORT' => 'sort',
        'DESCRIPTION' => 'text', 'DESCRIPTION_TYPE' => 'texttype',
    ];

    private const MAX_TEXT = 200000;

    public function __construct(
        private IblockGateway $gateway,
        private Settings $settings,
        private AuditLogger $audit,
    ) {
    }

    public function registerAll(ToolRegistry $registry): void
    {
        $int = ['type' => 'integer', 'minimum' => 1];
        $page = [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Сколько записей вернуть (по умолчанию 20).'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Номер страницы, с 1.'],
        ];
        $dryRun = ['type' => 'boolean', 'description' => 'Только показать изменения, ничего не записывая.'];

        $registry->register(new Tool('iblock_list', 'Список инфоблоков сайта: id, тип, код, название, число элементов.', [
            'type' => 'object', 'properties' => ['type' => ['type' => 'string', 'description' => 'Фильтр по ID типа инфоблоков.']],
        ], false, fn (array $a): array => $this->iblockList($a)));

        $registry->register(new Tool('iblock_properties', 'Свойства инфоблока: код, название, тип, множественность, значения списка.', [
            'type' => 'object', 'properties' => ['iblock_id' => $int], 'required' => ['iblock_id'],
        ], false, fn (array $a): array => $this->iblockProperties($a)));

        $registry->register(new Tool('section_list', 'Разделы инфоблока (с уровнем вложенности и родителем).', [
            'type' => 'object',
            'properties' => ['iblock_id' => $int, 'parent_id' => ['type' => 'integer', 'minimum' => 0, 'description' => 'ID родительского раздела; 0 — корень.']] + $page,
            'required' => ['iblock_id'],
        ], false, fn (array $a): array => $this->sectionList($a)));

        $registry->register(new Tool('element_list', 'Элементы инфоблока: поиск по названию, раздел, активность. Без текстов и свойств.', [
            'type' => 'object',
            'properties' => [
                'iblock_id' => $int,
                'section_id' => ['type' => 'integer', 'minimum' => 1],
                'include_subsections' => ['type' => 'boolean'],
                'active' => ['type' => 'boolean', 'description' => 'true — только активные, false — только неактивные.'],
                'query' => ['type' => 'string', 'description' => 'Подстрока в названии.'],
            ] + $page,
            'required' => ['iblock_id'],
        ], false, fn (array $a): array => $this->elementList($a)));

        $registry->register(new Tool('element_get', 'Один элемент целиком: поля, тексты и значения свойств.', [
            'type' => 'object', 'properties' => ['id' => $int], 'required' => ['id'],
        ], false, fn (array $a): array => $this->elementGet($a)));

        $registry->register(new Tool('seo_templates_get', 'SEO-шаблоны инфоблока (заголовки и описания разделов и элементов).', [
            'type' => 'object', 'properties' => ['iblock_id' => $int],
        ], false, fn (array $a): array => $this->seoGet($a)));

        $registry->register(new Tool('element_update', 'Изменить поля и свойства элемента. Удаления нет; снять с публикации можно через active=false.', [
            'type' => 'object',
            'properties' => [
                'id' => $int,
                'fields' => ['type' => 'object', 'description' => 'Допустимые поля: ' . implode(', ', array_keys(self::ELEMENT_FIELDS))],
                'properties' => ['type' => 'object', 'description' => 'Свойства: код => значение. Файловые свойства не поддерживаются.'],
                'dry_run' => $dryRun,
            ],
            'required' => ['id'],
        ], true, fn (array $a): array => $this->elementUpdate($a)));

        $registry->register(new Tool('element_create', 'Создать элемент в инфоблоке (по умолчанию неактивный, пока не указано иное).', [
            'type' => 'object',
            'properties' => [
                'iblock_id' => $int,
                'fields' => ['type' => 'object', 'description' => 'NAME обязателен. Допустимые поля: ' . implode(', ', array_keys(self::ELEMENT_FIELDS))],
                'properties' => ['type' => 'object'],
                'dry_run' => $dryRun,
            ],
            'required' => ['iblock_id', 'fields'],
        ], true, fn (array $a): array => $this->elementCreate($a)));

        $registry->register(new Tool('section_update', 'Изменить поля раздела инфоблока.', [
            'type' => 'object',
            'properties' => [
                'id' => $int,
                'fields' => ['type' => 'object', 'description' => 'Допустимые поля: ' . implode(', ', array_keys(self::SECTION_FIELDS))],
                'dry_run' => $dryRun,
            ],
            'required' => ['id', 'fields'],
        ], true, fn (array $a): array => $this->sectionUpdate($a)));

        $registry->register(new Tool('seo_templates_update', 'Изменить SEO-шаблоны инфоблока. Допустимые ключи: ' . implode(', ', self::SEO_KEYS), [
            'type' => 'object',
            'properties' => ['iblock_id' => $int, 'templates' => ['type' => 'object'], 'dry_run' => $dryRun],
            'required' => ['templates'],
        ], true, fn (array $a): array => $this->seoUpdate($a)));
    }

    // ---- чтение -------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function iblockList(array $args): array
    {
        $type = isset($args['type']) ? (string) $args['type'] : null;
        $allowed = $this->settings->allowedIblocks();
        $items = array_values(array_filter(
            $this->gateway->listIblocks($type),
            static fn (array $row): bool => $allowed === [] || in_array($row['id'], $allowed, true),
        ));

        return ['mode' => $this->settings->mode(), 'iblocks' => $items];
    }

    /** @return array<string,mixed> */
    private function iblockProperties(array $args): array
    {
        $id = $this->readableIblock($args['iblock_id'] ?? null);

        return ['iblock_id' => $id, 'properties' => $this->gateway->getProperties($id)];
    }

    /** @return array<string,mixed> */
    private function sectionList(array $args): array
    {
        $id = $this->readableIblock($args['iblock_id'] ?? null);
        $parent = array_key_exists('parent_id', $args) ? $this->intArg($args['parent_id'], 'parent_id', 0) : null;
        [$limit, $page] = $this->paging($args);

        return $this->gateway->listSections($id, $parent, $limit, $page) + ['limit' => $limit, 'page' => $page];
    }

    /** @return array<string,mixed> */
    private function elementList(array $args): array
    {
        $id = $this->readableIblock($args['iblock_id'] ?? null);
        [$limit, $page] = $this->paging($args);
        $filter = [];
        if (isset($args['section_id'])) {
            $filter['section_id'] = $this->intArg($args['section_id'], 'section_id');
            $filter['include_subsections'] = (bool) ($args['include_subsections'] ?? false);
        }
        if (array_key_exists('active', $args) && $args['active'] !== null) {
            $filter['active'] = (bool) $args['active'];
        }
        if (isset($args['query']) && trim((string) $args['query']) !== '') {
            $filter['query'] = mb_substr(trim((string) $args['query']), 0, 200);
        }

        return $this->gateway->listElements($id, $filter, $limit, $page) + ['limit' => $limit, 'page' => $page];
    }

    /** @return array<string,mixed> */
    private function elementGet(array $args): array
    {
        $element = $this->gateway->getElement($this->intArg($args['id'] ?? null, 'id'));
        if ($element === null) {
            throw new ToolException('Элемент не найден.');
        }
        $this->readableIblock($element['iblock_id'] ?? null);

        return $element;
    }

    /** @return array<string,mixed> */
    private function seoGet(array $args): array
    {
        $id = $this->readableIblock($args['iblock_id'] ?? $this->defaultIblock());

        return ['iblock_id' => $id, 'templates' => $this->gateway->getSeoTemplates($id)];
    }

    // ---- запись -------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function elementUpdate(array $args): array
    {
        $id = $this->intArg($args['id'] ?? null, 'id');
        $before = $this->gateway->getElement($id) ?? throw new ToolException('Элемент не найден.');
        $iblockId = $this->writableIblock($before['iblock_id'] ?? null);

        $fields = $this->normalizeFields($this->objectArg($args, 'fields'), self::ELEMENT_FIELDS);
        [$properties, $shownProperties] = $this->normalizeProperties($iblockId, $this->objectArg($args, 'properties'));
        if ($fields === [] && $properties === []) {
            throw new ToolException('Нечего изменять: передайте fields или properties.');
        }

        $changes = $this->diff($before, $fields, $shownProperties);
        if ($changes === []) {
            return ['updated' => false, 'id' => $id, 'message' => 'Значения уже совпадают, изменений нет.'];
        }
        if (!empty($args['dry_run'])) {
            return ['dry_run' => true, 'id' => $id, 'changes' => $changes];
        }

        $this->gateway->updateElement($id, $fields, $properties);
        $this->audit->log('element.update', ['id' => $id, 'iblock_id' => $iblockId, 'changes' => $this->shorten($changes)]);

        return ['updated' => true, 'id' => $id, 'changes' => $changes];
    }

    /** @return array<string,mixed> */
    private function elementCreate(array $args): array
    {
        $iblockId = $this->writableIblock($args['iblock_id'] ?? null);
        $fields = $this->normalizeFields($this->objectArg($args, 'fields'), self::ELEMENT_FIELDS);
        if (!isset($fields['NAME']) || trim((string) $fields['NAME']) === '') {
            throw new ToolException('Для создания нужен fields.NAME.');
        }
        $fields += ['ACTIVE' => false];
        [$properties, $shownProperties] = $this->normalizeProperties($iblockId, $this->objectArg($args, 'properties'));

        if (!empty($args['dry_run'])) {
            return ['dry_run' => true, 'iblock_id' => $iblockId, 'would_create' => ['fields' => $fields, 'properties' => $shownProperties]];
        }

        $id = $this->gateway->createElement($iblockId, $fields, $properties);
        $this->audit->log('element.create', ['id' => $id, 'iblock_id' => $iblockId, 'fields' => $this->shorten($fields)]);

        return ['created' => true, 'id' => $id];
    }

    /** @return array<string,mixed> */
    private function sectionUpdate(array $args): array
    {
        $id = $this->intArg($args['id'] ?? null, 'id');
        $before = $this->gateway->getSection($id) ?? throw new ToolException('Раздел не найден.');
        $this->writableIblock($before['iblock_id'] ?? null);
        $fields = $this->normalizeFields($this->objectArg($args, 'fields'), self::SECTION_FIELDS);
        if ($fields === []) {
            throw new ToolException('Нечего изменять: передайте fields.');
        }

        $changes = $this->diff($before, $fields, []);
        if ($changes === []) {
            return ['updated' => false, 'id' => $id, 'message' => 'Значения уже совпадают, изменений нет.'];
        }
        if (!empty($args['dry_run'])) {
            return ['dry_run' => true, 'id' => $id, 'changes' => $changes];
        }

        $this->gateway->updateSection($id, $fields);
        $this->audit->log('section.update', ['id' => $id, 'iblock_id' => $before['iblock_id'], 'changes' => $this->shorten($changes)]);

        return ['updated' => true, 'id' => $id, 'changes' => $changes];
    }

    /** @return array<string,mixed> */
    private function seoUpdate(array $args): array
    {
        $iblockId = $this->writableIblock($args['iblock_id'] ?? $this->defaultIblock());
        $templates = $this->objectArg($args, 'templates');
        $clean = [];
        foreach ($templates as $key => $value) {
            if (!in_array($key, self::SEO_KEYS, true) || !is_string($value)) {
                throw new ToolException('Недопустимое SEO-поле: ' . $key . '. Допустимые: ' . implode(', ', self::SEO_KEYS));
            }
            if (mb_strlen($value) > 500) {
                throw new ToolException('SEO-шаблон не может быть длиннее 500 символов: ' . $key);
            }
            $clean[$key] = trim($value);
        }
        if ($clean === []) {
            throw new ToolException('Нечего изменять: передайте templates.');
        }

        $current = $this->gateway->getSeoTemplates($iblockId);
        $changes = [];
        foreach ($clean as $key => $value) {
            if (($current[$key] ?? '') !== $value) {
                $changes[$key] = ['before' => $current[$key] ?? '', 'after' => $value];
            }
        }
        if ($changes === []) {
            return ['updated' => false, 'iblock_id' => $iblockId, 'message' => 'Значения уже совпадают, изменений нет.'];
        }
        if (!empty($args['dry_run'])) {
            return ['dry_run' => true, 'iblock_id' => $iblockId, 'changes' => $changes];
        }

        $this->gateway->setSeoTemplates($iblockId, $clean);
        $this->audit->log('seo.update', ['iblock_id' => $iblockId, 'changes' => $this->shorten($changes)]);

        return ['updated' => true, 'iblock_id' => $iblockId, 'changes' => $changes];
    }

    // ---- проверки и нормализация --------------------------------------------------------------

    private function readableIblock(mixed $value): int
    {
        $id = $this->intArg($value, 'iblock_id');
        $allowed = $this->settings->allowedIblocks();
        if ($allowed !== [] && !in_array($id, $allowed, true)) {
            throw new ToolException('Инфоблок ' . $id . ' недоступен: его нет в списке разрешённых.');
        }
        if ($this->gateway->getIblock($id) === null) {
            throw new ToolException('Инфоблок ' . $id . ' не найден.');
        }

        return $id;
    }

    private function writableIblock(mixed $value): int
    {
        if (!$this->settings->writesEnabled()) {
            throw new ToolException('Запись отключена: включите режим «чтение и запись» в настройках модуля.');
        }
        $id = $this->intArg($value, 'iblock_id');
        if (!in_array($id, $this->settings->allowedIblocks(), true)) {
            throw new ToolException('Запись в инфоблок ' . $id . ' запрещена: добавьте его в список разрешённых для записи.');
        }

        return $this->readableIblock($id);
    }

    private function defaultIblock(): ?int
    {
        $id = $this->settings->defaultIblockId();

        return $id > 0 ? $id : null;
    }

    private function intArg(mixed $value, string $name, int $min = 1): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min) {
            throw new ToolException('Параметр ' . $name . ' обязателен и должен быть целым числом' . ($min > 0 ? ' больше нуля.' : '.'));
        }

        return $value;
    }

    /** @return array{0:int,1:int} */
    private function paging(array $args): array
    {
        $limit = isset($args['limit']) ? $this->intArg($args['limit'], 'limit') : 20;
        $page = isset($args['page']) ? $this->intArg($args['page'], 'page') : 1;

        return [min($limit, 100), $page];
    }

    /** @return array<string,mixed> */
    private function objectArg(array $args, string $name): array
    {
        $value = $args[$name] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ToolException('Параметр ' . $name . ' должен быть объектом.');
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,string> $rules
     * @return array<string,mixed>
     */
    private function normalizeFields(array $fields, array $rules): array
    {
        $result = [];
        foreach ($fields as $name => $value) {
            $rule = $rules[$name] ?? null;
            if ($rule === null) {
                throw new ToolException('Поле ' . $name . ' изменять нельзя. Допустимые: ' . implode(', ', array_keys($rules)));
            }
            $result[$name] = match ($rule) {
                'string255' => $this->stringValue($name, $value, 255, true),
                'code' => $this->codeValue($name, $value),
                'bool' => is_bool($value) ? $value : throw new ToolException('Поле ' . $name . ' должно быть true или false.'),
                'sort' => is_int($value) && $value >= 0 && $value <= 1000000 ? $value : throw new ToolException('Поле ' . $name . ' должно быть целым от 0 до 1000000.'),
                'text' => $this->stringValue($name, $value, self::MAX_TEXT, false),
                'texttype' => in_array($value, ['text', 'html'], true) ? $value : throw new ToolException('Поле ' . $name . ' должно быть text или html.'),
                'id0' => is_int($value) && $value >= 0 ? $value : throw new ToolException('Поле ' . $name . ' должно быть целым числом, 0 — без раздела.'),
            };
        }

        return $result;
    }

    private function stringValue(string $name, mixed $value, int $max, bool $notEmpty): string
    {
        if (!is_string($value)) {
            throw new ToolException('Поле ' . $name . ' должно быть строкой.');
        }
        if (mb_strlen($value) > $max) {
            throw new ToolException('Поле ' . $name . ' длиннее ' . $max . ' символов.');
        }
        if ($notEmpty && trim($value) === '') {
            throw new ToolException('Поле ' . $name . ' не может быть пустым.');
        }

        return $value;
    }

    private function codeValue(string $name, mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{0,255}$/', $value)) {
            throw new ToolException('Поле ' . $name . ': только латиница, цифры, _ и -.');
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $properties
     * @return array{0:array<string,mixed>,1:array<string,mixed>} [значения для записи, значения для сравнения и показа]
     */
    private function normalizeProperties(int $iblockId, array $properties): array
    {
        if ($properties === []) {
            return [[], []];
        }
        $known = [];
        foreach ($this->gateway->getProperties($iblockId) as $property) {
            $known[$property['code']] = $property;
        }

        $result = [];
        $display = [];
        foreach ($properties as $code => $value) {
            $property = $known[$code] ?? throw new ToolException('Свойство ' . $code . ' не найдено в инфоблоке ' . $iblockId . '.');
            if ($property['type'] === 'F') {
                throw new ToolException('Файловое свойство ' . $code . ' через этот интерфейс изменять нельзя.');
            }
            $values = is_array($value) ? array_values($value) : [$value];
            if (!$property['multiple'] && count($values) > 1) {
                throw new ToolException('Свойство ' . $code . ' не множественное: ожидается одно значение.');
            }
            $mapped = [];
            $shown = [];
            foreach ($values as $item) {
                $writeValue = $this->propertyValue($code, $property, $item);
                $mapped[] = $writeValue;
                // Список: Битрикс отдаёт текст значения, а пишется идентификатор.
                $shown[] = $property['type'] === 'L' && $writeValue !== null ? $property['enum'][$writeValue] : $writeValue;
            }
            $result[$code] = $property['multiple'] ? $mapped : ($mapped[0] ?? null);
            $display[$code] = $property['multiple'] ? $shown : ($shown[0] ?? null);
        }

        return [$result, $display];
    }

    /** @param array<string,mixed> $property */
    private function propertyValue(string $code, array $property, mixed $item): mixed
    {
        if ($item === null) {
            return null;
        }
        if (!is_scalar($item)) {
            throw new ToolException('Значение свойства ' . $code . ' должно быть строкой, числом или списком таких значений.');
        }
        if (is_string($item) && mb_strlen($item) > 100000) {
            throw new ToolException('Значение свойства ' . $code . ' слишком длинное.');
        }
        if ($property['type'] === 'L') {
            $enum = $property['enum'];
            if (is_int($item) && isset($enum[$item])) {
                return $item;
            }
            $id = array_search((string) $item, $enum, true);
            if ($id === false) {
                throw new ToolException('Свойство ' . $code . ': допустимые значения: ' . implode(', ', $enum));
            }

            return (int) $id;
        }

        return $item;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $properties
     * @return array<string,array{before:mixed,after:mixed}>
     */
    private function diff(array $before, array $fields, array $properties): array
    {
        $map = ['IBLOCK_SECTION_ID' => 'section_id'];
        $changes = [];
        foreach ($fields as $name => $value) {
            $key = $map[$name] ?? strtolower($name);
            $old = $before[$key] ?? null;
            if ($old !== $value) {
                $changes[$name] = ['before' => $old, 'after' => $value];
            }
        }
        foreach ($properties as $code => $value) {
            $old = $before['properties'][$code] ?? null;
            if ($old !== $value) {
                $changes['PROPERTY_' . $code] = ['before' => $old, 'after' => $value];
            }
        }

        return $changes;
    }

    private function shorten(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->shorten($item), $value);
        }
        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500) . '…';
        }

        return $value;
    }
}
