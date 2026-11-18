<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Tools;

use OrgBox\SiteOps\Audit\AuditLogger;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Files\FilePropertyChange;
use OrgBox\SiteOps\Files\FileUpload;
use OrgBox\SiteOps\Files\ImageLoader;
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
    private const ELEMENT_FIELDS = [
        'NAME' => 'string255', 'CODE' => 'code', 'ACTIVE' => 'bool', 'SORT' => 'sort',
        'PREVIEW_TEXT' => 'text', 'DETAIL_TEXT' => 'text',
        'PREVIEW_TEXT_TYPE' => 'texttype', 'DETAIL_TEXT_TYPE' => 'texttype',
        'IBLOCK_SECTION_ID' => 'id0',
        'PREVIEW_PICTURE' => 'image', 'DETAIL_PICTURE' => 'image',
        'ACTIVE_FROM' => 'datetime', 'ACTIVE_TO' => 'datetime',
    ];

    private const SECTION_FIELDS = [
        'NAME' => 'string255', 'CODE' => 'code', 'ACTIVE' => 'bool', 'SORT' => 'sort',
        'DESCRIPTION' => 'text', 'DESCRIPTION_TYPE' => 'texttype',
    ];

    private const MAX_TEXT = 200000;

    private const FIELDS_HELP = 'Допустимые поля: NAME, CODE, ACTIVE, SORT, PREVIEW_TEXT, DETAIL_TEXT, PREVIEW_TEXT_TYPE, DETAIL_TEXT_TYPE, IBLOCK_SECTION_ID, '
        . 'PREVIEW_PICTURE, DETAIL_PICTURE, ACTIVE_FROM, ACTIVE_TO. Даты ACTIVE_FROM и ACTIVE_TO (начало и конец активности, на сайте это, например, дата отзыва или новости) — строка «ГГГГ-ММ-ДД» или «ГГГГ-ММ-ДД ЧЧ:ММ[:СС]», пустая строка очищает. Картинка — объект {"url": "https://…"} или {"base64": "…", "name": "photo.jpg"} '
        . '(JPEG, PNG, WebP, GIF, до 10 МБ); прежний файл заменяется. Удалить картинку нельзя.';

    private const PROPERTIES_HELP = 'Свойства: код => значение. Строка/число — одно значение; список [..] — множественное свойство, '
        . 'пустой список [] или null очищает свойство. Свойство-список (L) задаётся текстом значения. '
        . 'Файловые свойства (тип F, например MORE_PHOTO, INSTRUCTIONS): {"base64": "…", "name": "a.jpg"} или {"url": "https://…"} добавляет файл '
        . '(у не множественного свойства заменяет прежний), список таких объектов добавляет несколько; '
        . '{"delete": [ID файлов]} удаляет отдельные файлы (ID берите из element_get), можно вместе: {"add": [...], "delete": [...]}. '
        . 'Остальные файлы свойства сохраняются. Допустимы JPEG, PNG, WebP, GIF и PDF, до 10 МБ. '
        . 'В ответе значения читаются из базы заново; verified=false и not_applied говорят, что запись не применилась.';

    public function __construct(
        private IblockGateway $gateway,
        private Settings $settings,
        private AuditLogger $audit,
        private ?ImageLoader $images = null,
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

        $registry->register(new Tool('element_filled_properties', 'Только заполненные свойства элемента, с названиями (код, название, тип, значение). Удобно для каталога, где свойств больше 150.', [
            'type' => 'object', 'properties' => ['id' => $int], 'required' => ['id'],
        ], false, fn (array $a): array => $this->elementFilledProperties($a)));

        $registry->register(new Tool('product_get', 'Цены и остатки товара или торгового предложения из модуля catalog (id элемента): цены по типам, общий остаток, остатки по складам, список торговых предложений. Цена лежит в каталоге, а не в свойствах.', [
            'type' => 'object', 'properties' => ['id' => $int], 'required' => ['id'],
        ], false, fn (array $a): array => $this->productGet($a)));

        $registry->register(new Tool('product_update', 'Записать цены и остатки товара или торгового предложения. '
            . 'Что записывается: prices — цены в модуле catalog (таблица цен), по типам: {"тип цены (id или код, например BASE)": число или {"price": число, "currency": "RUB"}}; '
            . 'quantity — общий остаток товара; stores — остатки по складам: {"id склада": количество}. При складском учёте общий остаток считается из складов, задавайте stores. '
            . 'Базовая цена (тип с признаком «базовый», обычно BASE) дополнительно записывается в свойства элемента MINIMUM_PRICE и MAXIMUM_PRICE (строкой, той же записью). '
            . 'Если этих свойств в инфоблоке нет, запись отклоняется до изменений. Другие свойства элемента этот инструмент не трогает (для них element_update). '
            . 'После записи цены, остатки и свойства перечитываются из базы: verified=true только если всё совпало, иначе not_applied перечисляет несовпавшее.', [
            'type' => 'object',
            'properties' => [
                'id' => $int,
                'prices' => ['type' => 'object'],
                'quantity' => ['type' => 'number', 'minimum' => 0],
                'stores' => ['type' => 'object'],
                'dry_run' => $dryRun,
            ],
            'required' => ['id'],
        ], true, fn (array $a): array => $this->productUpdate($a)));

        $registry->register(new Tool('product_set_type', 'Сменить тип товара каталога: simple (простой) или with_offers (с торговыми предложениями). '
            . 'Перевод в with_offers требует связи инфоблока с инфоблоком предложений в модуле catalog. '
            . 'Обратно в simple — только если предложений нет. Цена и остаток у товара с предложениями задаются у предложений. Удаления нет.', [
            'type' => 'object',
            'properties' => [
                'id' => $int,
                'type' => ['type' => 'string', 'enum' => ['simple', 'with_offers']],
                'dry_run' => $dryRun,
            ],
            'required' => ['id', 'type'],
        ], true, fn (array $a): array => $this->productSetType($a)));

        $registry->register(new Tool('offer_create', 'Создать торговое предложение (SKU) у товара с типом with_offers: элемент в инфоблоке предложений, '
            . 'привязка к товару (свойство связи задаёт инструмент), запись в каталоге как предложение. Элемент создаётся неактивным, пока не указано иное. '
            . 'Свойства: цвет, артикул, картинки и т. д. по коду инфоблока предложений. Цену и остаток задайте через product_update с id предложения. '
            . 'Удаления нет. Инфоблок предложений должен быть разрешён для записи.', [
            'type' => 'object',
            'properties' => [
                'parent_id' => $int,
                'fields' => ['type' => 'object', 'description' => 'NAME обязателен. ' . self::FIELDS_HELP],
                'properties' => ['type' => 'object', 'description' => self::PROPERTIES_HELP],
                'dry_run' => $dryRun,
            ],
            'required' => ['parent_id', 'fields'],
        ], true, fn (array $a): array => $this->offerCreate($a)));

        $registry->register(new Tool('element_update', 'Изменить поля, свойства и файлы элемента. Удаления элемента нет; снять с публикации можно через ACTIVE=false. После записи значения перечитываются из базы.', [
            'type' => 'object',
            'properties' => [
                'id' => $int,
                'fields' => ['type' => 'object', 'description' => self::FIELDS_HELP],
                'properties' => ['type' => 'object', 'description' => self::PROPERTIES_HELP],
                'dry_run' => $dryRun,
            ],
            'required' => ['id'],
        ], true, fn (array $a): array => $this->elementUpdate($a)));

        $registry->register(new Tool('element_create', 'Создать элемент в инфоблоке (по умолчанию неактивный, пока не указано иное).', [
            'type' => 'object',
            'properties' => [
                'iblock_id' => $int,
                'fields' => ['type' => 'object', 'description' => 'NAME обязателен. ' . self::FIELDS_HELP],
                'properties' => ['type' => 'object', 'description' => self::PROPERTIES_HELP],
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

        $registry->register(new Tool('cache_clear', 'Сбросить кеш сайта, чтобы изменения появились на страницах. scope=iblock (по умолчанию): кеш одного инфоблока; scope=all: весь кеш сайта (на время сайт может работать медленнее).', [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => ['iblock', 'all']],
                'iblock_id' => $int,
                'dry_run' => $dryRun,
            ],
        ], true, fn (array $a): array => $this->cacheClear($a)));

    }

    // ---- чтение -------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function iblockList(array $args): array
    {
        $type = isset($args['type']) ? (string) $args['type'] : null;
        $items = array_values(array_filter(
            $this->gateway->listIblocks($type),
            fn (array $row): bool => $this->settings->canReadIblock((int) $row['id']),
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
    private function elementFilledProperties(array $args): array
    {
        $element = $this->gateway->getElement($this->intArg($args['id'] ?? null, 'id'));
        if ($element === null) {
            throw new ToolException('Элемент не найден.');
        }
        $iblockId = $this->readableIblock($element['iblock_id'] ?? null);

        $meta = [];
        foreach ($this->gateway->getProperties($iblockId) as $property) {
            $meta[$property['code']] = $property;
        }
        $values = $element['properties'] ?? [];
        $filled = [];
        // Сначала в порядке свойств инфоблока, затем те, что в описании не нашлись (например, неактивные).
        foreach (array_unique(array_merge(array_keys($meta), array_keys($values))) as $code) {
            if ($this->canonical($values[$code] ?? null) === null) {
                continue;
            }
            $filled[] = [
                'code' => (string) $code,
                'name' => $meta[$code]['name'] ?? (string) $code,
                'type' => $meta[$code]['type'] ?? '',
                'multiple' => $meta[$code]['multiple'] ?? is_array($values[$code]) && array_is_list($values[$code]),
                'value' => $values[$code],
            ];
        }

        return ['id' => $element['id'], 'iblock_id' => $iblockId, 'name' => $element['name'], 'count' => count($filled), 'properties' => $filled];
    }

    private const PRODUCT_TYPES = [1 => 'simple', 2 => 'set', 3 => 'with_offers', 4 => 'offer', 5 => 'empty_with_offers', 6 => 'service'];

    /** @return array<string,mixed> */
    private function productGet(array $args): array
    {
        $id = $this->intArg($args['id'] ?? null, 'id');
        $element = $this->gateway->getElement($id) ?? throw new ToolException('Элемент не найден.');
        $this->readableIblock($element['iblock_id'] ?? null);
        $product = $this->gateway->getProduct($id) ?? throw new ToolException('Элемент ' . $id . ' не товар каталога: цен и остатков у него нет.');

        $types = [];
        foreach ($this->gateway->listPriceTypes() as $type) {
            $types[$type['id']] = $type;
        }
        $prices = [];
        foreach ($product['prices'] as $price) {
            $type = $types[$price['type_id']] ?? ['code' => '', 'name' => '', 'base' => false];
            $prices[] = [
                'type_id' => $price['type_id'], 'type' => $type['code'], 'type_name' => $type['name'], 'base' => $type['base'],
                'price' => $price['price'], 'currency' => $price['currency'],
                'quantity_from' => $price['quantity_from'], 'quantity_to' => $price['quantity_to'],
            ];
        }
        $stores = [];
        foreach ($product['stores'] as $storeId => $store) {
            $stores[] = ['id' => $storeId, 'title' => $store['title'], 'amount' => $store['amount']];
        }

        return [
            'id' => $id, 'iblock_id' => $element['iblock_id'], 'name' => $element['name'],
            'product_type' => self::PRODUCT_TYPES[$product['type']] ?? 'other',
            'quantity' => $product['quantity'], 'reserved' => $product['reserved'], 'available' => $product['available'],
            'prices' => $prices, 'stores' => $stores, 'offers' => $product['offers'],
            'price_types' => array_values($types),
        ];
    }

    // ---- запись -------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function elementUpdate(array $args): array
    {
        $id = $this->intArg($args['id'] ?? null, 'id');
        $before = $this->gateway->getElement($id) ?? throw new ToolException('Элемент не найден.');
        $iblockId = $this->writableIblock($before['iblock_id'] ?? null);

        $fields = $this->normalizeFields($this->objectArg($args, 'fields'), self::ELEMENT_FIELDS);
        [$properties, $shownProperties] = $this->normalizeProperties($iblockId, $this->objectArg($args, 'properties'), $before['properties'] ?? []);
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

        // Ответ строится по тому, что реально лежит в базе, а не по тому, что мы отправили.
        $after = $this->gateway->getElement($id) ?? throw new ToolException('Элемент пропал после записи.');
        [$report, $notApplied] = $this->verify($changes, $before, $after);
        $this->audit->log('element.update', ['id' => $id, 'iblock_id' => $iblockId, 'changes' => $this->shorten($report), 'not_applied' => $notApplied]);

        return ['updated' => true, 'id' => $id, 'changes' => $report, 'verified' => $notApplied === []]
            + ($notApplied === [] ? [] : ['not_applied' => $notApplied]);
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
            return ['dry_run' => true, 'iblock_id' => $iblockId, 'would_create' => ['fields' => $this->shorten($fields), 'properties' => $this->shorten($shownProperties)]];
        }

        $id = $this->gateway->createElement($iblockId, $fields, $properties);
        $this->audit->log('element.create', ['id' => $id, 'iblock_id' => $iblockId, 'fields' => $this->shorten($fields)]);

        return ['created' => true, 'id' => $id];
    }

    /** @return array<string,mixed> */
    private function productUpdate(array $args): array
    {
        $id = $this->intArg($args['id'] ?? null, 'id');
        $element = $this->gateway->getElement($id) ?? throw new ToolException('Элемент не найден.');
        $iblockId = $this->writableIblock($element['iblock_id'] ?? null);
        $before = $this->gateway->getProduct($id) ?? throw new ToolException('Элемент ' . $id . ' не товар каталога: цен и остатков у него нет.');

        $prices = $this->priceArgs($this->objectArg($args, 'prices'));
        $quantity = array_key_exists('quantity', $args) && $args['quantity'] !== null ? $this->amount($args['quantity'], 'quantity') : null;
        $stores = [];
        foreach ($this->objectArg($args, 'stores') as $storeId => $amount) {
            if (!ctype_digit((string) $storeId) || !isset($before['stores'][(int) $storeId])) {
                throw new ToolException('Склад ' . $storeId . ' не найден. Доступные: ' . implode(', ', array_map(static fn ($k, $v): string => $k . ' (' . $v['title'] . ')', array_keys($before['stores']), $before['stores'])) . '.');
            }
            $stores[(int) $storeId] = $this->amount($amount, 'stores.' . $storeId);
        }
        if ($prices === [] && $quantity === null && $stores === []) {
            throw new ToolException('Нечего изменять: передайте prices, quantity или stores.');
        }

        $changes = [];
        foreach ($prices as $typeId => $item) {
            $old = $this->generalPrice($before, $typeId);
            $planned = ['price' => $item['price'], 'currency' => $item['currency'] ?? ($old['currency'] ?? null)];
            if ($old === null || abs($old['price'] - $planned['price']) > 1e-9 || ($planned['currency'] !== null && $old['currency'] !== $planned['currency'])) {
                $changes['PRICE_' . $typeId] = ['before' => $old === null ? null : ['price' => $old['price'], 'currency' => $old['currency']], 'after' => $planned];
            }
        }
        if ($quantity !== null && abs($before['quantity'] - $quantity) > 1e-9) {
            $changes['QUANTITY'] = ['before' => $before['quantity'], 'after' => $quantity];
        }
        foreach ($stores as $storeId => $amount) {
            if (abs($before['stores'][$storeId]['amount'] - $amount) > 1e-9) {
                $changes['STORE_' . $storeId] = ['before' => $before['stores'][$storeId]['amount'], 'after' => $amount];
            }
        }

        // Базовая цена дублируется в свойства элемента MINIMUM_PRICE и MAXIMUM_PRICE (их читают другие модули сайта).
        $properties = [];
        $notSynced = [];
        $basePrice = $this->basePriceFrom($prices);
        if ($basePrice !== null) {
            $value = self::priceProperty($basePrice);
            foreach (self::PRICE_PROPERTIES as $code) {
                if (!$this->hasProperty($iblockId, $code)) {
                    // У торгового предложения свойств мин./макс. цены может не быть: цену пишем, а синхронизацию пропускаем и говорим об этом.
                    if ((self::PRODUCT_TYPES[$before['type']] ?? '') === 'offer') {
                        $notSynced[] = $code;
                        continue;
                    }
                    throw new ToolException('В инфоблоке ' . $iblockId . ' нет свойства ' . $code . ' (Минимальная/Максимальная цена): базовую цену без него не записать. Ничего не записано.');
                }
                $current = $element['properties'][$code] ?? null;
                if (!$this->samePrice($current, $value)) {
                    $changes['PROP_' . $code] = ['before' => $current, 'after' => $value];
                    $properties[$code] = $value;
                }
            }
        }

        if ($changes === []) {
            return ['updated' => false, 'id' => $id, 'message' => 'Значения уже совпадают, изменений нет.'];
        }
        if (!empty($args['dry_run'])) {
            return ['dry_run' => true, 'id' => $id, 'changes' => $changes];
        }

        $this->gateway->updateProduct($id, $iblockId, $prices, $quantity, $stores, $properties);

        $after = $this->gateway->getProduct($id) ?? throw new ToolException('Товар пропал после записи.');
        $afterElement = $this->gateway->getElement($id) ?? throw new ToolException('Элемент пропал после записи.');
        $report = [];
        $notApplied = [];
        foreach ($changes as $key => $change) {
            if (str_starts_with($key, 'PRICE_')) {
                $actual = $this->generalPrice($after, (int) substr($key, 6));
                $actual = $actual === null ? null : ['price' => $actual['price'], 'currency' => $actual['currency']];
                $expected = $change['after'];
                $applied = $actual !== null && abs($actual['price'] - $expected['price']) < 1e-9
                    && ($expected['currency'] === null || $expected['currency'] === $actual['currency']);
            } elseif (str_starts_with($key, 'PROP_')) {
                $actual = $afterElement['properties'][substr($key, 5)] ?? null;
                $applied = $this->samePrice($actual, $change['after']);
            } elseif ($key === 'QUANTITY') {
                $actual = $after['quantity'];
                $applied = abs($actual - $change['after']) < 1e-9;
            } else {
                $actual = $after['stores'][(int) substr($key, 6)]['amount'] ?? null;
                $applied = $actual !== null && abs($actual - $change['after']) < 1e-9;
            }
            $report[$key] = ['before' => $change['before'], 'after' => $actual];
            if (!$applied) {
                $report[$key]['expected'] = $change['after'];
                $notApplied[] = $key;
            }
        }
        $this->audit->log('product.update', ['id' => $id, 'iblock_id' => $element['iblock_id'], 'changes' => $report, 'not_applied' => $notApplied, 'not_synced' => $notSynced]);

        return ['updated' => true, 'id' => $id, 'changes' => $report, 'verified' => $notApplied === []]
            + ($notApplied === [] ? [] : ['not_applied' => $notApplied])
            + ($notSynced === [] ? [] : ['not_synced' => $notSynced, 'note' => 'В инфоблоке предложений нет свойств ' . implode(' и ', $notSynced) . ': базовая цена записана в каталог, синхронизация с этими свойствами пропущена.']);
    }

    /** Типы товара, которые переключает product_set_type: simple ↔ with_offers. */
    private const PRODUCT_TYPE_CODES = ['simple' => 1, 'with_offers' => 3];

    /** @return array<string,mixed> */
    private function productSetType(array $args): array
    {
        $id = $this->intArg($args['id'] ?? null, 'id');
        $target = $args['type'] ?? null;
        if (!is_string($target) || !isset(self::PRODUCT_TYPE_CODES[$target])) {
            throw new ToolException('Параметр type: simple (простой товар) или with_offers (товар с торговыми предложениями).');
        }
        $element = $this->gateway->getElement($id) ?? throw new ToolException('Элемент не найден.');
        $iblockId = $this->writableIblock($element['iblock_id'] ?? null);
        $before = $this->gateway->getProduct($id) ?? throw new ToolException('Элемент ' . $id . ' не товар каталога.');
        $current = self::PRODUCT_TYPES[$before['type']] ?? 'other';

        if ($current === $target) {
            return ['updated' => false, 'id' => $id, 'message' => 'Тип уже ' . $target . '.'];
        }
        if (!in_array($current, ['simple', 'with_offers'], true)) {
            throw new ToolException('Тип «' . $current . '» этим инструментом не меняется: только simple ↔ with_offers.');
        }
        if ($target === 'simple' && $before['offers'] !== []) {
            throw new ToolException('У товара ' . $id . ' есть торговые предложения (' . count($before['offers']) . ': '
                . implode(', ', array_column($before['offers'], 'id')) . '). В простой товар переводить нельзя, пока они есть. Ничего не записано.');
        }
        if ($target === 'with_offers' && $this->gateway->offerRelation($iblockId) === null) {
            throw new ToolException('Инфоблок ' . $iblockId . ' не связан с инфоблоком торговых предложений в модуле catalog. '
                . 'Настройте связь в админке (Торговый каталог → Настройки → Торговые предложения) и повторите. Ничего не записано.');
        }
        if (!empty($args['dry_run'])) {
            return ['dry_run' => true, 'id' => $id, 'product_type' => ['before' => $current, 'after' => $target]];
        }

        $this->gateway->setProductType($id, self::PRODUCT_TYPE_CODES[$target]);
        $after = $this->gateway->getProduct($id) ?? throw new ToolException('Товар пропал после записи.');
        $actual = self::PRODUCT_TYPES[$after['type']] ?? 'other';
        $verified = $actual === $target;
        $this->audit->log('product.type', ['id' => $id, 'iblock_id' => $iblockId, 'before' => $current, 'after' => $actual, 'verified' => $verified]);

        $result = ['updated' => true, 'id' => $id, 'product_type' => ['before' => $current, 'after' => $actual], 'verified' => $verified];
        if (!$verified) {
            $result['expected'] = $target;
        }
        if ($target === 'with_offers') {
            $result['note'] = 'Цена и остаток теперь задаются у торговых предложений: offer_create, затем product_update с id предложения.';
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function offerCreate(array $args): array
    {
        $parentId = $this->intArg($args['parent_id'] ?? null, 'parent_id');
        $parent = $this->gateway->getElement($parentId) ?? throw new ToolException('Родительский товар не найден.');
        $parentIblock = $this->writableIblock($parent['iblock_id'] ?? null);
        $product = $this->gateway->getProduct($parentId) ?? throw new ToolException('Элемент ' . $parentId . ' не товар каталога.');
        $type = self::PRODUCT_TYPES[$product['type']] ?? 'other';
        if ($type !== 'with_offers') {
            throw new ToolException('Товар ' . $parentId . ' не с торговыми предложениями (тип: ' . $type . '). Сначала переведите его: product_set_type с type=with_offers.');
        }
        $relation = $this->gateway->offerRelation($parentIblock)
            ?? throw new ToolException('Инфоблок ' . $parentIblock . ' не связан с инфоблоком торговых предложений в модуле catalog.');
        $offerIblock = $this->writableIblock($relation['offers_iblock_id']);
        $link = $relation['link_code'];

        $fields = $this->normalizeFields($this->objectArg($args, 'fields'), self::ELEMENT_FIELDS);
        if (!isset($fields['NAME']) || trim((string) $fields['NAME']) === '') {
            throw new ToolException('Для создания предложения нужен fields.NAME.');
        }
        $fields += ['ACTIVE' => false];

        $properties = $this->objectArg($args, 'properties');
        if (array_key_exists($link, $properties)) {
            throw new ToolException('Свойство связи ' . $link . ' задаёт сам инструмент (привязка к товару ' . $parentId . '). Уберите его из properties.');
        }
        [$write, $shown] = $this->normalizeProperties($offerIblock, $properties);
        $shown[$link] = $parentId;

        if (!empty($args['dry_run'])) {
            return ['dry_run' => true, 'parent_id' => $parentId, 'iblock_id' => $offerIblock,
                'would_create' => ['fields' => $this->shorten($fields), 'properties' => $this->shorten($shown)]];
        }

        try {
            $id = $this->gateway->createOffer($offerIblock, $parentId, $link, $fields, $write);
        } catch (\RuntimeException $exception) {
            // Сообщение шлюза содержит причину и id элемента, который мог остаться неактивным: отдаём его пользователю, а не общую ошибку.
            $this->audit->log('offer.create.failed', ['parent_id' => $parentId, 'iblock_id' => $offerIblock, 'error' => $exception->getMessage()]);
            throw new ToolException($exception->getMessage());
        }

        // Проверка по базе: свойство связи, список предложений товара и запись в каталоге как предложение.
        $after = $this->gateway->getElement($id) ?? throw new ToolException('Предложение пропало после записи.');
        $parentAfter = $this->gateway->getProduct($parentId) ?? throw new ToolException('Товар пропал после записи.');
        $offerAfter = $this->gateway->getProduct($id);
        $checks = [
            'link' => (string) ($after['properties'][$link] ?? '') === (string) $parentId,
            'parent_offers' => in_array($id, array_column($parentAfter['offers'], 'id'), true),
            'catalog' => $offerAfter !== null && (self::PRODUCT_TYPES[$offerAfter['type']] ?? '') === 'offer',
        ];
        $notApplied = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
        $this->audit->log('offer.create', ['id' => $id, 'parent_id' => $parentId, 'iblock_id' => $offerIblock,
            'fields' => $this->shorten($fields), 'properties' => $this->shorten($shown), 'not_applied' => $notApplied]);

        $result = ['created' => true, 'id' => $id, 'parent_id' => $parentId, 'iblock_id' => $offerIblock,
            'verified' => $notApplied === [], 'next' => 'Цена и остаток предложения: product_update с id ' . $id . '.'];
        if ($notApplied !== []) {
            $result['not_applied'] = $notApplied;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $prices
     * @return array<int,array{price:float,currency:?string}>
     */
    private function priceArgs(array $prices): array
    {
        if ($prices === []) {
            return [];
        }
        $types = $this->gateway->listPriceTypes();
        $result = [];
        foreach ($prices as $key => $value) {
            $typeId = null;
            foreach ($types as $type) {
                if ((string) $type['id'] === (string) $key || strcasecmp($type['code'], (string) $key) === 0) {
                    $typeId = $type['id'];
                    break;
                }
            }
            if ($typeId === null) {
                throw new ToolException('Тип цены ' . $key . ' не найден. Доступные: ' . implode(', ', array_map(static fn (array $t): string => $t['id'] . ' (' . $t['code'] . ')', $types)) . '.');
            }
            $currency = null;
            if (is_array($value)) {
                $unknown = array_diff(array_keys($value), ['price', 'currency']);
                if ($unknown !== [] || !array_key_exists('price', $value)) {
                    throw new ToolException('Цена ' . $key . ': нужен объект {"price": число, "currency": "RUB"} или просто число.');
                }
                if (array_key_exists('currency', $value) && $value['currency'] !== null) {
                    if (!is_string($value['currency']) || !preg_match('/^[A-Z]{3}$/', $value['currency'])) {
                        throw new ToolException('Цена ' . $key . ': валюта — трёхбуквенный код, например RUB.');
                    }
                    $currency = $value['currency'];
                }
                $value = $value['price'];
            }
            $result[$typeId] = ['price' => round($this->amount($value, 'prices.' . $key), 2), 'currency' => $currency];
        }

        return $result;
    }

    private function amount(mixed $value, string $name): float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }
        if ((!is_int($value) && !is_float($value)) || $value < 0 || $value > 1000000000) {
            throw new ToolException('Параметр ' . $name . ' должен быть числом от 0 до 1000000000.');
        }

        return (float) $value;
    }

    /**
     * Обычная цена типа (без ограничений по количеству).
     *
     * @param array<string,mixed> $product
     * @return array{price:float,currency:string}|null
     */
    private function generalPrice(array $product, int $typeId): ?array
    {
        foreach ($product['prices'] as $price) {
            if ($price['type_id'] === $typeId && $price['quantity_from'] === null && $price['quantity_to'] === null) {
                return ['price' => $price['price'], 'currency' => $price['currency']];
            }
        }

        return null;
    }

    /** Свойства элемента, в которые дублируется базовая цена. Значения хранятся строкой. */
    private const PRICE_PROPERTIES = ['MINIMUM_PRICE', 'MAXIMUM_PRICE'];

    /**
     * Цена типа с признаком «базовый» (на сайте это BASE), если она есть в записи, иначе null.
     *
     * @param array<int,array{price:float,currency:?string}> $prices ключ — id типа цены
     */
    private function basePriceFrom(array $prices): ?float
    {
        $baseTypeIds = [];
        foreach ($this->gateway->listPriceTypes() as $type) {
            if (!empty($type['base'])) {
                $baseTypeIds[] = (string) $type['id'];
            }
        }
        foreach ($prices as $typeId => $item) {
            if (in_array((string) $typeId, $baseTypeIds, true)) {
                return $item['price'];
            }
        }

        return null;
    }

    /** Цена как строка для свойства типа «число»: 3500 → «3500», 90.5 → «90.5». */
    private static function priceProperty(float $price): string
    {
        return rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
    }

    /** Совпадают ли значения свойства с числовой точностью (пустое значение равно только пустому). */
    private function samePrice(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $actual === '' || $expected === null) {
            return (string) $actual === (string) $expected;
        }
        if (!is_numeric($actual) || !is_numeric($expected)) {
            return (string) $actual === (string) $expected;
        }

        return abs((float) $actual - (float) $expected) < 1e-9;
    }

    private function hasProperty(int $iblockId, string $code): bool
    {
        foreach ($this->gateway->getProperties($iblockId) as $property) {
            if ($property['code'] === $code) {
                return true;
            }
        }

        return false;
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
    private function cacheClear(array $args): array
    {
        $scope = $args['scope'] ?? 'iblock';
        if (!in_array($scope, ['iblock', 'all'], true)) {
            throw new ToolException('Параметр scope должен быть iblock или all.');
        }
        if (!$this->settings->writesEnabled()) {
            throw new ToolException('Запись отключена: включите режим «чтение и запись» в настройках модуля.');
        }
        $iblockId = null;
        if ($scope === 'iblock') {
            $iblockId = $this->writableIblock($args['iblock_id'] ?? null);
        }
        if (!empty($args['dry_run'])) {
            return ['dry_run' => true, 'scope' => $scope, 'iblock_id' => $iblockId];
        }

        $this->gateway->clearCache($iblockId);
        $this->audit->log('cache.clear', ['scope' => $scope, 'iblock_id' => $iblockId]);

        return ['cleared' => true, 'scope' => $scope, 'iblock_id' => $iblockId];
    }

    // ---- проверки и нормализация --------------------------------------------------------------

    private function readableIblock(mixed $value): int
    {
        $id = $this->intArg($value, 'iblock_id');
        if (!$this->settings->canReadIblock($id)) {
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
                'image' => $this->imageValue($name, $value),
                'datetime' => $this->datetimeValue($name, $value),
            };
        }

        return $result;
    }

    /** Дата в виде «ГГГГ-ММ-ДД ЧЧ:ММ:СС» (время по умолчанию 00:00:00); пустая строка или null — очистить. */
    private function datetimeValue(string $name, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', trim($value), $m)) {
            throw new ToolException('Поле ' . $name . ' должно быть датой «ГГГГ-ММ-ДД» или «ГГГГ-ММ-ДД ЧЧ:ММ:СС» (пустая строка очищает).');
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        [$h, $mi, $se] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $se > 59) {
            throw new ToolException('Поле ' . $name . ': такой даты не существует.');
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $se);
    }

    private function imageValue(string $name, mixed $value): FileUpload
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ToolException('Поле ' . $name . ' должно быть объектом {"url": "https://…"} или {"base64": "…"}.');
        }

        return ($this->images ?? new ImageLoader())->load($name, $value);
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
     * @param array<string,mixed> $current текущие значения свойств элемента (для проверки удаляемых файлов)
     * @return array{0:array<string,mixed>,1:array<string,mixed>} [значения для записи, значения для сравнения и показа]
     */
    private function normalizeProperties(int $iblockId, array $properties, array $current = []): array
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
                $change = $this->fileChange((string) $code, $property, $value, $this->fileIds($current[$code] ?? null));
                $result[$code] = $change;
                $display[$code] = $change;
                continue;
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
            // Пустой список очищает множественное свойство.
            $result[$code] = $property['multiple'] ? $mapped : ($mapped[0] ?? null);
            $display[$code] = $property['multiple'] ? $shown : ($shown[0] ?? null);
        }

        return [$result, $display];
    }

    /**
     * @param array<string,mixed> $property
     * @param list<int> $currentIds ID файлов, которые сейчас лежат в свойстве
     */
    private function fileChange(string $code, array $property, mixed $value, array $currentIds): FilePropertyChange
    {
        $hint = 'Свойство ' . $code . ' файловое: добавить — {"base64": "…"} или {"url": "https://…"}, удалить — {"delete": [ID файлов]} (ID виден в element_get).';
        if (!is_array($value) || $value === []) {
            throw new ToolException($hint . ' Очистить свойство целиком через null или [] нельзя.');
        }

        $addSpecs = [];
        $deleteSpec = [];
        if (array_is_list($value)) {
            $addSpecs = $value;
        } elseif (array_key_exists('add', $value) || array_key_exists('delete', $value)) {
            $unknown = array_diff(array_keys($value), ['add', 'delete']);
            if ($unknown !== []) {
                throw new ToolException('Свойство ' . $code . ': неизвестные ключи ' . implode(', ', $unknown) . '. ' . $hint);
            }
            $add = $value['add'] ?? [];
            $addSpecs = is_array($add) && !array_is_list($add) ? [$add] : (array) $add;
            $deleteSpec = $value['delete'] ?? [];
            $deleteSpec = is_array($deleteSpec) ? $deleteSpec : [$deleteSpec];
        } else {
            $addSpecs = [$value];
        }

        $loader = $this->images ?? new ImageLoader();
        $uploads = [];
        foreach ($addSpecs as $spec) {
            if (!is_array($spec) || $spec === [] || array_is_list($spec)) {
                throw new ToolException($hint);
            }
            $uploads[] = $loader->load($code, $spec, true);
        }
        if (!$property['multiple'] && count($uploads) > 1) {
            throw new ToolException('Свойство ' . $code . ' не множественное: можно загрузить только один файл.');
        }

        $delete = [];
        foreach ($deleteSpec as $fileId) {
            if (is_string($fileId) && ctype_digit($fileId)) {
                $fileId = (int) $fileId;
            }
            if (!is_int($fileId) || !in_array($fileId, $currentIds, true)) {
                throw new ToolException('Свойство ' . $code . ': файла ' . (is_scalar($fileId) ? $fileId : '?') . ' в этом элементе нет. Сейчас в свойстве: ' . ($currentIds === [] ? 'пусто' : implode(', ', $currentIds)) . '.');
            }
            $delete[] = $fileId;
        }
        $delete = array_values(array_unique($delete));
        if ($uploads === [] && $delete === []) {
            throw new ToolException($hint);
        }

        return new FilePropertyChange($uploads, $delete);
    }

    /** @return list<int> ID файлов из значения файлового свойства (описание файла или список описаний) */
    private function fileIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $items = isset($value['id']) ? [$value] : $value;
        $ids = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $ids[] = (int) $item['id'];
            }
        }

        return $ids;
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
            if ($value instanceof FileUpload) {
                // Файл всегда считается изменением: содержимое прежнего не сравнить, показываем только описание.
                $changes[$name] = ['before' => $old, 'after' => $value->describe()];
            } elseif ($old !== $value) {
                $changes[$name] = ['before' => $old, 'after' => $value];
            }
        }
        foreach ($properties as $code => $value) {
            $old = $before['properties'][$code] ?? null;
            if ($value instanceof FilePropertyChange) {
                $changes['PROPERTY_' . $code] = ['before' => $old, 'after' => $value->describe()];
            } elseif (!$this->sameValue($old, $value)) {
                $changes['PROPERTY_' . $code] = ['before' => $old, 'after' => $value];
            }
        }

        return $changes;
    }

    /**
     * Сверка запланированного с тем, что реально прочитано из базы после записи.
     *
     * @param array<string,array{before:mixed,after:mixed}> $changes
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array{0:array<string,array<string,mixed>>,1:list<string>} [отчёт по изменениям, ключи, которые не применились]
     */
    private function verify(array $changes, array $before, array $after): array
    {
        $map = ['IBLOCK_SECTION_ID' => 'section_id'];
        $report = [];
        $notApplied = [];
        foreach ($changes as $key => $change) {
            $expected = $change['after'];
            if (str_starts_with($key, 'PROPERTY_')) {
                $code = substr($key, 9);
                $actual = $after['properties'][$code] ?? null;
                if (is_array($expected) && array_key_exists('add', $expected)) {
                    $oldIds = $this->fileIds($before['properties'][$code] ?? null);
                    $newIds = $this->fileIds($actual);
                    $added = count(array_diff($newIds, $oldIds));
                    $applied = $added >= count($expected['add']) && array_intersect($expected['delete'], $newIds) === [];
                } else {
                    $applied = $this->sameValue($actual, $expected);
                }
            } else {
                $field = $map[$key] ?? strtolower($key);
                $actual = $after[$field] ?? null;
                if (is_array($expected) && array_key_exists('size', $expected)) {
                    // Картинка: применено, если в поле теперь другой файл.
                    $applied = is_array($actual) && ($actual['id'] ?? null) !== ($before[$field]['id'] ?? null);
                } else {
                    $applied = $actual === $expected;
                }
            }
            $report[$key] = ['before' => $change['before'], 'after' => $actual];
            if (!$applied) {
                $report[$key]['expected'] = $expected;
                $notApplied[] = $key;
            }
        }

        return [$report, $notApplied];
    }

    /** Значения равны, если пустые одинаково (null, '', []), а списки совпадают без учёта порядка и типа чисел. */
    private function sameValue(mixed $a, mixed $b): bool
    {
        return $this->canonical($a) === $this->canonical($b);
    }

    private function canonical(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_array($value)) {
            $items = [];
            foreach (array_values($value) as $item) {
                $item = $this->canonical($item);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            if ($items !== [] && array_filter($items, 'is_scalar') === $items) {
                sort($items, SORT_STRING);
            }

            return $items === [] ? null : $items;
        }

        return is_scalar($value) ? (is_bool($value) ? $value : (string) $value) : $value;
    }

    private function shorten(mixed $value): mixed
    {
        if ($value instanceof FileUpload || $value instanceof FilePropertyChange) {
            return $value->describe();
        }
        if (is_array($value)) {
            return array_map(fn ($item) => $this->shorten($item), $value);
        }
        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500) . '…';
        }

        return $value;
    }
}
