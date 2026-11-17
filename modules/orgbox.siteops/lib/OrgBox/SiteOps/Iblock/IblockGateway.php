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

    /** Сброс кеша: инфоблока (по тегу) или всего кеша сайта, если $iblockId = null. */
    public function clearCache(?int $iblockId): void;
}
