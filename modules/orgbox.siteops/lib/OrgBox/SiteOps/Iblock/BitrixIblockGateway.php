<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Iblock;

use Bitrix\Main\Loader;
use OrgBox\SiteOps\Files\FilePropertyChange;
use OrgBox\SiteOps\Files\FileUpload;
use RuntimeException;

/**
 * Работа с инфоблоками через штатный API Битрикса (CIBlock*). Запросы выполняются без пользователя,
 * поэтому проверка прав отключена (CHECK_PERMISSIONS = N): доступ ограничен токеном и политикой модуля.
 */
final class BitrixIblockGateway implements IblockGateway
{
    public function __construct()
    {
        if (!Loader::includeModule('iblock')) {
            throw new RuntimeException('Модуль инфоблоков недоступен.');
        }
    }

    public function listIblocks(?string $type): array
    {
        $filter = ['CHECK_PERMISSIONS' => 'N'];
        if ($type !== null && $type !== '') {
            $filter['TYPE'] = $type;
        }
        $result = [];
        $rows = \CIBlock::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], $filter);
        while ($row = $rows->Fetch()) {
            $result[] = [
                'id' => (int) $row['ID'],
                'type' => (string) $row['IBLOCK_TYPE_ID'],
                'code' => (string) $row['CODE'],
                'name' => (string) $row['NAME'],
                'active' => $row['ACTIVE'] === 'Y',
                'elements' => (int) \CIBlock::GetElementCount((int) $row['ID']),
            ];
        }

        return $result;
    }

    public function getIblock(int $id): ?array
    {
        $row = \CIBlock::GetList([], ['ID' => $id, 'CHECK_PERMISSIONS' => 'N'])->Fetch();

        return $row ? ['id' => (int) $row['ID'], 'type' => (string) $row['IBLOCK_TYPE_ID'], 'code' => (string) $row['CODE'], 'name' => (string) $row['NAME']] : null;
    }

    public function getProperties(int $iblockId): array
    {
        $result = [];
        $rows = \CIBlockProperty::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
        while ($row = $rows->Fetch()) {
            $code = (string) $row['CODE'];
            if ($code === '') {
                continue;
            }
            $enum = [];
            if ($row['PROPERTY_TYPE'] === 'L') {
                $values = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => (int) $row['ID']]);
                while ($value = $values->Fetch()) {
                    $enum[(int) $value['ID']] = (string) $value['VALUE'];
                }
            }
            $result[] = [
                'code' => $code,
                'name' => (string) $row['NAME'],
                'type' => (string) $row['PROPERTY_TYPE'],
                'multiple' => $row['MULTIPLE'] === 'Y',
                'required' => $row['IS_REQUIRED'] === 'Y',
                'enum' => $enum,
            ];
        }

        return $result;
    }

    public function listSections(int $iblockId, ?int $parentId, int $limit, int $page): array
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'];
        if ($parentId !== null) {
            $filter['SECTION_ID'] = $parentId > 0 ? $parentId : false;
        }
        $rows = \CIBlockSection::GetList(
            ['LEFT_MARGIN' => 'ASC'],
            $filter,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL'],
            ['nPageSize' => $limit, 'iNumPage' => $page],
        );
        $items = [];
        while ($row = $rows->Fetch()) {
            $items[] = $this->sectionRow($row);
        }

        return ['items' => $items, 'total' => (int) $rows->SelectedRowsCount()];
    }

    public function getSection(int $id): ?array
    {
        $row = \CIBlockSection::GetList(
            [],
            ['ID' => $id, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'DESCRIPTION', 'DESCRIPTION_TYPE'],
        )->Fetch();
        if (!$row) {
            return null;
        }

        return $this->sectionRow($row) + [
            'description' => (string) $row['DESCRIPTION'],
            'description_type' => (string) $row['DESCRIPTION_TYPE'],
        ];
    }

    public function listElements(int $iblockId, array $filter, int $limit, int $page): array
    {
        $bx = ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'];
        if (isset($filter['section_id'])) {
            $bx['SECTION_ID'] = $filter['section_id'];
            $bx['INCLUDE_SUBSECTIONS'] = !empty($filter['include_subsections']) ? 'Y' : 'N';
        }
        if (array_key_exists('active', $filter)) {
            $bx['ACTIVE'] = $filter['active'] ? 'Y' : 'N';
        }
        if (isset($filter['query'])) {
            $bx['%NAME'] = $filter['query'];
        }

        $rows = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $bx,
            false,
            ['nPageSize' => $limit, 'iNumPage' => $page],
            ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT', 'IBLOCK_SECTION_ID', 'TIMESTAMP_X', 'DETAIL_PAGE_URL'],
        );
        $items = [];
        // GetNext(true, true): экранированные значения для HTML + исходные в полях с префиксом «~» (их и берём).
        while ($row = $rows->GetNext(true, true)) {
            $items[] = [
                'id' => (int) $row['ID'],
                'iblock_id' => (int) $row['IBLOCK_ID'],
                'name' => RawRow::string($row, 'NAME'),
                'code' => RawRow::string($row, 'CODE'),
                'active' => RawRow::string($row, 'ACTIVE') === 'Y',
                'sort' => (int) $row['SORT'],
                'section_id' => (int) $row['IBLOCK_SECTION_ID'],
                'modified' => RawRow::string($row, 'TIMESTAMP_X'),
                'url' => (string) $row['DETAIL_PAGE_URL'],
            ];
        }

        return ['items' => $items, 'total' => (int) $rows->NavRecordCount];
    }

    public function getElement(int $id): ?array
    {
        $row = \CIBlockElement::GetList(
            [],
            ['ID' => $id, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT', 'IBLOCK_SECTION_ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE', 'DATE_ACTIVE_FROM', 'DATE_ACTIVE_TO', 'TIMESTAMP_X', 'DETAIL_PAGE_URL'],
        )->GetNext(true, true);
        if (!$row) {
            return null;
        }

        $enumMap = [];
        $properties = [];
        $props = \CIBlockElement::GetProperty((int) $row['IBLOCK_ID'], $id, ['SORT' => 'ASC'], ['EMPTY' => 'N']);
        while ($prop = $props->Fetch()) {
            $code = (string) $prop['CODE'];
            if ($code === '') {
                continue;
            }
            if ($prop['PROPERTY_TYPE'] === 'F') {
                // Файл: вместо числа — описание (id, адрес, имя, размер), чтобы файл можно было опознать и удалить по id.
                $value = $this->pictureInfo($prop['VALUE']);
                if ($value === null) {
                    continue;
                }
            } else {
                $value = $prop['PROPERTY_TYPE'] === 'L' ? (string) $prop['VALUE_ENUM'] : $prop['VALUE'];
            }
            if ($prop['MULTIPLE'] === 'Y') {
                $properties[$code][] = $value;
            } else {
                $properties[$code] = $value;
            }
        }

        return [
            'id' => (int) $row['ID'],
            'iblock_id' => (int) $row['IBLOCK_ID'],
            'name' => RawRow::string($row, 'NAME'),
            'code' => RawRow::string($row, 'CODE'),
            'active' => RawRow::string($row, 'ACTIVE') === 'Y',
            'sort' => (int) $row['SORT'],
            'section_id' => (int) $row['IBLOCK_SECTION_ID'],
            'preview_picture' => $this->pictureInfo($row['~PREVIEW_PICTURE'] ?? $row['PREVIEW_PICTURE'] ?? null),
            'detail_picture' => $this->pictureInfo($row['~DETAIL_PICTURE'] ?? $row['DETAIL_PICTURE'] ?? null),
            'preview_text' => RawRow::string($row, 'PREVIEW_TEXT'),
            'preview_text_type' => RawRow::string($row, 'PREVIEW_TEXT_TYPE'),
            'detail_text' => RawRow::string($row, 'DETAIL_TEXT'),
            'detail_text_type' => RawRow::string($row, 'DETAIL_TEXT_TYPE'),
            'active_from' => $this->isoDate(RawRow::string($row, 'DATE_ACTIVE_FROM')),
            'active_to' => $this->isoDate(RawRow::string($row, 'DATE_ACTIVE_TO')),
            'modified' => RawRow::string($row, 'TIMESTAMP_X'),
            'url' => (string) $row['DETAIL_PAGE_URL'],
            'properties' => $properties,
        ];
    }

    public function updateElement(int $id, array $fields, array $properties): void
    {
        $element = new \CIBlockElement();
        $temp = [];
        try {
            if ($fields !== [] && !$element->Update($id, $this->toBitrixFields($fields, $temp))) {
                throw new RuntimeException('Не удалось сохранить элемент: ' . $element->LAST_ERROR);
            }
            if ($properties !== []) {
                $current = $this->getElement($id);
                if ($current === null) {
                    throw new RuntimeException('Элемент исчез при сохранении.');
                }
                $iblockId = (int) $current['iblock_id'];
                \CIBlockElement::SetPropertyValuesEx($id, $iblockId, $this->toBitrixProperties($id, $iblockId, $properties, $temp));
            }
        } finally {
            $this->cleanup($temp);
        }
    }

    public function createElement(int $iblockId, array $fields, array $properties): int
    {
        $element = new \CIBlockElement();
        $temp = [];
        try {
            $id = (int) $element->Add(['IBLOCK_ID' => $iblockId] + $this->toBitrixFields($fields, $temp));
            if ($id <= 0) {
                throw new RuntimeException('Не удалось создать элемент: ' . $element->LAST_ERROR);
            }
            if ($properties !== []) {
                \CIBlockElement::SetPropertyValuesEx($id, $iblockId, $this->toBitrixProperties($id, $iblockId, $properties, $temp));
            }
        } finally {
            $this->cleanup($temp);
        }

        return $id;
    }

    public function updateSection(int $id, array $fields): void
    {
        $section = new \CIBlockSection();
        if (!$section->Update($id, $this->toBitrixFields($fields))) {
            throw new RuntimeException('Не удалось сохранить раздел: ' . $section->LAST_ERROR);
        }
    }

    public function listPriceTypes(): array
    {
        $this->catalog();
        $result = [];
        $rows = \CCatalogGroup::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], [], false, false, ['ID', 'NAME', 'BASE', 'NAME_LANG']);
        while ($row = $rows->Fetch()) {
            $result[] = [
                'id' => (int) $row['ID'],
                'code' => (string) $row['NAME'],
                'name' => (string) ($row['NAME_LANG'] ?? '') !== '' ? (string) $row['NAME_LANG'] : (string) $row['NAME'],
                'base' => $row['BASE'] === 'Y',
            ];
        }

        return $result;
    }

    public function getProduct(int $id): ?array
    {
        $this->catalog();
        $product = \CCatalogProduct::GetByID($id);
        if (!is_array($product)) {
            return null;
        }

        $prices = [];
        $rows = \CPrice::GetListEx(['CATALOG_GROUP_ID' => 'ASC', 'ID' => 'ASC'], ['PRODUCT_ID' => $id], false, false, ['ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'QUANTITY_FROM', 'QUANTITY_TO']);
        while ($row = $rows->Fetch()) {
            $prices[] = [
                'type_id' => (int) $row['CATALOG_GROUP_ID'],
                'price' => (float) $row['PRICE'],
                'currency' => (string) $row['CURRENCY'],
                'quantity_from' => $row['QUANTITY_FROM'] !== null && $row['QUANTITY_FROM'] !== '' ? (float) $row['QUANTITY_FROM'] : null,
                'quantity_to' => $row['QUANTITY_TO'] !== null && $row['QUANTITY_TO'] !== '' ? (float) $row['QUANTITY_TO'] : null,
            ];
        }

        $stores = [];
        $rows = \CCatalogStore::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['ACTIVE' => 'Y'], false, false, ['ID', 'TITLE', 'ADDRESS']);
        while ($row = $rows->Fetch()) {
            $title = (string) $row['TITLE'] !== '' ? (string) $row['TITLE'] : (string) $row['ADDRESS'];
            $stores[(int) $row['ID']] = ['title' => $title !== '' ? $title : 'Склад ' . $row['ID'], 'amount' => 0.0];
        }
        $rows = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $id], false, false, ['STORE_ID', 'AMOUNT']);
        while ($row = $rows->Fetch()) {
            if (isset($stores[(int) $row['STORE_ID']])) {
                $stores[(int) $row['STORE_ID']]['amount'] = (float) $row['AMOUNT'];
            }
        }

        $offers = [];
        if ((int) $product['TYPE'] === 3 && class_exists('\\CCatalogSKU')) { // 3 — товар с торговыми предложениями
            $list = \CCatalogSKU::getOffersList([$id], 0, [], ['ID', 'NAME']);
            foreach (($list[$id] ?? []) as $offer) {
                $offers[] = ['id' => (int) $offer['ID'], 'name' => (string) ($offer['NAME'] ?? '')];
            }
        }

        return [
            'quantity' => (float) $product['QUANTITY'],
            'reserved' => (float) ($product['QUANTITY_RESERVED'] ?? 0),
            'available' => ($product['AVAILABLE'] ?? 'N') === 'Y',
            'type' => (int) $product['TYPE'],
            'prices' => $prices,
            'stores' => $stores,
            'offers' => $offers,
        ];
    }

    public function updateProduct(int $id, int $iblockId, array $prices, ?float $quantity, array $stores, array $properties): void
    {
        $this->catalog();
        if (!is_array(\CCatalogProduct::GetByID($id))) {
            throw new RuntimeException('Элемент ' . $id . ' не является товаром каталога.');
        }

        $base = [];
        foreach ($this->listPriceTypes() as $type) {
            $base[$type['id']] = $type['base'];
        }
        foreach ($prices as $typeId => $item) {
            $existing = null;
            $rows = \CPrice::GetListEx([], ['PRODUCT_ID' => $id, 'CATALOG_GROUP_ID' => $typeId], false, false, ['ID', 'CURRENCY', 'QUANTITY_FROM', 'QUANTITY_TO']);
            while ($row = $rows->Fetch()) {
                $general = ($row['QUANTITY_FROM'] === null || $row['QUANTITY_FROM'] === '') && ($row['QUANTITY_TO'] === null || $row['QUANTITY_TO'] === '');
                if ($general || $existing === null) {
                    $existing = $row;
                }
            }
            $currency = $item['currency'] ?? ($existing['CURRENCY'] ?? null) ?? \Bitrix\Currency\CurrencyManager::getBaseCurrency();
            $fields = ['PRODUCT_ID' => $id, 'CATALOG_GROUP_ID' => $typeId, 'PRICE' => $item['price'], 'CURRENCY' => $currency];

            if (!empty($base[$typeId])) {
                $ok = \CPrice::SetBasePrice($id, $item['price'], $currency);
            } elseif ($existing !== null) {
                $ok = \CPrice::Update((int) $existing['ID'], $fields);
            } else {
                $ok = \CPrice::Add($fields);
            }
            if (!$ok) {
                throw new RuntimeException('Не удалось записать цену типа ' . $typeId . ': ' . $this->catalogError());
            }
        }

        foreach ($stores as $storeId => $amount) {
            $row = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $id, 'STORE_ID' => $storeId], false, false, ['ID'])->Fetch();
            $ok = $row
                ? \CCatalogStoreProduct::Update((int) $row['ID'], ['AMOUNT' => $amount])
                : \CCatalogStoreProduct::Add(['PRODUCT_ID' => $id, 'STORE_ID' => $storeId, 'AMOUNT' => $amount]);
            if (!$ok) {
                throw new RuntimeException('Не удалось записать остаток на складе ' . $storeId . ': ' . $this->catalogError());
            }
        }

        if ($quantity !== null && !\CCatalogProduct::Update($id, ['QUANTITY' => $quantity])) {
            throw new RuntimeException('Не удалось записать остаток: ' . $this->catalogError());
        }

        if ($properties !== []) {
            \CIBlockElement::SetPropertyValuesEx($id, $iblockId, $properties);
        }
    }

    private function catalog(): void
    {
        if (!Loader::includeModule('catalog')) {
            throw new RuntimeException('Модуль торгового каталога (catalog) недоступен.');
        }
    }

    private function catalogError(): string
    {
        $exception = $GLOBALS['APPLICATION']->GetException();

        return is_object($exception) ? (string) $exception->GetString() : 'причина не указана';
    }

    public function clearCache(?int $iblockId): void
    {
        if ($iblockId !== null) {
            \CIBlock::clearIblockTagCache($iblockId);

            return;
        }
        if (!function_exists('BXClearCache')) {
            throw new RuntimeException('Сброс кеша недоступен: функция BXClearCache не найдена.');
        }
        BXClearCache(true);
        \Bitrix\Main\Application::getInstance()->getManagedCache()->cleanAll();
        if (isset($GLOBALS['stackCacheManager']) && is_object($GLOBALS['stackCacheManager'])) {
            $GLOBALS['stackCacheManager']->CleanAll();
        }
    }

    /**
     * Свойства → формат SetPropertyValuesEx.
     *  - пустой список ([]) очищает свойство: Битрикс игнорирует пустой массив, очищает его только null;
     *  - изменение файлового свойства превращается в значения «по ID значения»: оставшиеся файлы сохраняются,
     *    удаляемые помечаются del, новые получают ключи n0, n1…
     *
     * @param array<string,mixed> $properties
     * @param list<string> $temp
     * @return array<string,mixed>
     */
    private function toBitrixProperties(int $id, int $iblockId, array $properties, array &$temp): array
    {
        $result = [];
        foreach ($properties as $code => $value) {
            if ($value instanceof FilePropertyChange) {
                $result[$code] = $this->fileValues($id, $iblockId, (string) $code, $value, $temp);
            } elseif ($value === [] || (is_array($value) && array_filter($value, static fn ($v): bool => $v !== null) === [])) {
                $result[$code] = null;
            } else {
                $result[$code] = $value;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $temp
     * @return array<string|int,array<string,mixed>>
     */
    private function fileValues(int $id, int $iblockId, string $code, FilePropertyChange $change, array &$temp): array
    {
        $property = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
        if (!$property || $property['PROPERTY_TYPE'] !== 'F') {
            throw new RuntimeException('Свойство ' . $code . ' не файловое.');
        }
        $multiple = $property['MULTIPLE'] === 'Y';
        $replaceAll = !$multiple && $change->add !== [];

        $values = [];
        $rows = \CIBlockElement::GetProperty($iblockId, $id, ['SORT' => 'ASC', 'ID' => 'ASC'], ['CODE' => $code]);
        while ($row = $rows->Fetch()) {
            $fileId = (int) $row['VALUE'];
            if ($fileId <= 0) {
                continue;
            }
            $valueId = (int) $row['PROPERTY_VALUE_ID'];
            $values[$valueId] = in_array($fileId, $change->delete, true) || $replaceAll
                ? ['VALUE' => ['del' => 'Y']]
                : ['VALUE' => $fileId, 'DESCRIPTION' => (string) $row['DESCRIPTION']];
        }
        foreach ($change->add as $index => $upload) {
            $values['n' . $index] = ['VALUE' => $this->fileArray($upload, $temp)];
        }

        return $values;
    }

    /**
     * Временный файл → массив для CIBlockElement (старый файл Битрикс удаляет сам при замене).
     *
     * @param list<string> $temp
     * @return array<string,mixed>
     */
    private function fileArray(FileUpload $upload, array &$temp): array
    {
        $dir = sys_get_temp_dir() . '/orgbox-siteops-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать временную папку для файла.');
        }
        $temp[] = $dir;
        $path = $dir . '/' . $upload->name;
        if (file_put_contents($path, $upload->bytes) === false) {
            throw new RuntimeException('Не удалось записать временный файл.');
        }
        $file = \CFile::MakeFileArray($path);
        if (!is_array($file)) {
            throw new RuntimeException('Битрикс не принял файл ' . $upload->name . '.');
        }
        $file['name'] = $upload->name;
        $file['type'] = $upload->mime;
        $file['MODULE_ID'] = 'iblock';

        return $file;
    }

    /** @param list<string> $dirs */
    private function cleanup(array $dirs): void
    {
        foreach ($dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /** @return array{id:int,src:string,name:string,width:int,height:int,size:int}|null */
    private function pictureInfo(mixed $fileId): ?array
    {
        $fileId = (int) $fileId;
        $file = $fileId > 0 ? \CFile::GetFileArray($fileId) : null;
        if (!is_array($file)) {
            return null;
        }

        return [
            'id' => $fileId,
            'src' => (string) $file['SRC'],
            'name' => (string) $file['ORIGINAL_NAME'],
            'width' => (int) $file['WIDTH'],
            'height' => (int) $file['HEIGHT'],
            'size' => (int) $file['FILE_SIZE'],
        ];
    }

    /** Дата из формата сайта в «ГГГГ-ММ-ДД ЧЧ:ММ:СС»; пустая или нечитаемая — пустая строка. */
    private function isoDate(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $timestamp = \MakeTimeStamp($value);

        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function toBitrixFields(array $fields, array &$temp = []): array
    {
        foreach (['PREVIEW_PICTURE', 'DETAIL_PICTURE'] as $name) {
            if (($fields[$name] ?? null) instanceof FileUpload) {
                $fields[$name] = $this->fileArray($fields[$name], $temp);
            }
        }
        if (array_key_exists('ACTIVE', $fields)) {
            $fields['ACTIVE'] = $fields['ACTIVE'] ? 'Y' : 'N';
        }
        foreach (['ACTIVE_FROM' => 'DATE_ACTIVE_FROM', 'ACTIVE_TO' => 'DATE_ACTIVE_TO'] as $name => $bitrixName) {
            if (array_key_exists($name, $fields)) {
                $value = (string) $fields[$name];
                unset($fields[$name]);
                $fields[$bitrixName] = $value === '' ? '' : \ConvertTimeStamp((new \DateTimeImmutable($value))->getTimestamp(), 'FULL');
            }
        }
        if (array_key_exists('IBLOCK_SECTION_ID', $fields) && $fields['IBLOCK_SECTION_ID'] === 0) {
            $fields['IBLOCK_SECTION_ID'] = false;
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function sectionRow(array $row): array
    {
        return [
            'id' => (int) $row['ID'],
            'iblock_id' => (int) $row['IBLOCK_ID'],
            'name' => (string) $row['NAME'],
            'code' => (string) $row['CODE'],
            'active' => $row['ACTIVE'] === 'Y',
            'sort' => (int) $row['SORT'],
            'parent_id' => (int) $row['IBLOCK_SECTION_ID'],
            'depth' => (int) $row['DEPTH_LEVEL'],
        ];
    }
}
