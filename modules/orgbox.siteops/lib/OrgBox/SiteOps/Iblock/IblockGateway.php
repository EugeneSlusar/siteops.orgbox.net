<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Iblock;

/**
 * Доступ к данным инфоблоков. Реализация для Битрикса — BitrixIblockGateway;
 * интерфейс позволяет проверять логику инструментов без установленного Битрикса.
 */
interface IblockGateway
{
    /** @return list<array{id:int,type:string,code:string,name:string,active:bool,elements:int}> */
    public function listIblocks(?string $type): array;

    /** @return array{id:int,type:string,code:string,name:string}|null */
    public function getIblock(int $id): ?array;

    /**
     * @return list<array{code:string,name:string,type:string,multiple:bool,required:bool,enum:array<int,string>}>
     */
    public function getProperties(int $iblockId): array;

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function listSections(int $iblockId, ?int $parentId, int $limit, int $page): array;

    /** @return array<string,mixed>|null */
    public function getSection(int $id): ?array;

    /**
     * @param array{section_id?:int,include_subsections?:bool,active?:bool,query?:string} $filter
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function listElements(int $iblockId, array $filter, int $limit, int $page): array;

    /** @return array<string,mixed>|null поля + ключ properties (код => значение) */
    public function getElement(int $id): ?array;

    /**
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $properties
     */
    public function updateElement(int $id, array $fields, array $properties): void;

    /**
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $properties
     */
    public function createElement(int $iblockId, array $fields, array $properties): int;

    /** @param array<string,mixed> $fields */
    public function updateSection(int $id, array $fields): void;

    /** @return list<array{id:int,code:string,name:string,base:bool}> типы цен модуля catalog */
    public function listPriceTypes(): array;

    /**
     * Данные товара или торгового предложения из модуля catalog (цены, остаток, склады); null, если элемент не товар каталога.
     *
     * @return array{
     *   quantity:float,reserved:float,available:bool,type:int,
     *   prices:list<array{type_id:int,price:float,currency:string,quantity_from:?float,quantity_to:?float}>,
     *   stores:array<int,array{title:string,amount:float}>,
     *   offers:list<array{id:int,name:string}>
     * }|null
     */
    public function getProduct(int $id): ?array;

    /**
     * Записать цены (тип цены => цена и валюта), общий остаток и остатки по складам (склад => количество).
     * Цена записывается в строку без ограничений по количеству; создаётся, если её ещё нет.
     * Свойства элемента ($properties: код => значение) записываются тем же вызовом, одной операцией.
     *
     * @param array<int,array{price:float,currency:?string}> $prices
     * @param array<int,float> $stores
     * @param array<string,string> $properties
     */
    public function updateProduct(int $id, int $iblockId, array $prices, ?float $quantity, array $stores, array $properties): void;

    /**
     * Связь «товарный инфоблок → инфоблок торговых предложений» из модуля catalog; null, если связи нет.
     *
     * @return array{offers_iblock_id:int,link_code:string}|null link_code — код свойства, которое привязывает предложение к товару
     */
    public function offerRelation(int $productIblockId): ?array;

    /** Тип товара каталога: 1 — простой, 3 — с торговыми предложениями (штатные значения модуля catalog). */
    public function setProductType(int $id, int $type): void;

    /**
     * Создать торговое предложение: элемент в инфоблоке предложений, свойство связи с товаром и запись в каталоге
     * как предложение (тип 4). Если запись в каталоге не удалась, элемент не удаляется: он остаётся неактивным,
     * а исключение называет его id.
     *
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $properties
     */
    public function createOffer(int $offerIblockId, int $parentId, string $linkCode, array $fields, array $properties): int;

    /** Сброс кеша: инфоблока (по тегу) или всего кеша сайта, если $iblockId = null. */
    public function clearCache(?int $iblockId): void;
}
