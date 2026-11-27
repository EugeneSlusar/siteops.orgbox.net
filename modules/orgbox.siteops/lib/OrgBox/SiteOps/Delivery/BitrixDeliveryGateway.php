<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Delivery;

use Bitrix\Main\Loader;
use RuntimeException;

/** Реализация через D7 API модуля sale. Не возвращает CONFIG обработчика доставки. */
final class BitrixDeliveryGateway implements DeliveryGateway
{
    private function sale(): void
    {
        if (!Loader::includeModule('sale')) {
            throw new RuntimeException('Модуль sale недоступен.');
        }
    }

    public function listServices(): array
    {
        $this->sale();
        $items = [];
        $rows = \Bitrix\Sale\Delivery\Services\Manager::getList(['select' => ['ID', 'NAME', 'ACTIVE', 'DESCRIPTION', 'PRICE', 'CURRENCY', 'PERIOD_FROM', 'PERIOD_TO', 'SORT']]);
        while ($row = $rows->fetch()) {
            $items[] = $this->service($row);
        }
        return $items;
    }

    public function getService(int $id): ?array
    {
        foreach ($this->listServices() as $service) {
            if ($service['id'] === $id) {
                return $service;
            }
        }
        return null;
    }

    public function updateService(int $id, array $fields): void
    {
        $this->sale();
        $map = ['name' => 'NAME', 'active' => 'ACTIVE', 'description' => 'DESCRIPTION', 'price' => 'PRICE', 'currency' => 'CURRENCY', 'period_from' => 'PERIOD_FROM', 'period_to' => 'PERIOD_TO'];
        $data = [];
        foreach ($fields as $key => $value) {
            if (isset($map[$key])) {
                $data[$map[$key]] = $key === 'active' ? ($value ? 'Y' : 'N') : $value;
            }
        }
        $result = \Bitrix\Sale\Delivery\Services\Manager::update($id, $data);
        if (!$result->isSuccess()) {
            throw new RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    public function listRestrictions(int $serviceId): array
    {
        $this->sale();
        $items = [];
        $rows = \Bitrix\Sale\Delivery\Restrictions\Manager::getList(['filter' => ['=SERVICE_ID' => $serviceId], 'order' => ['SORT' => 'ASC', 'ID' => 'ASC']]);
        while ($row = $rows->fetch()) {
            $items[] = ['id' => (int) $row['ID'], 'service_id' => (int) $row['SERVICE_ID'], 'class' => (string) $row['CLASS_NAME'], 'params' => is_array($row['PARAMS'] ?? null) ? $row['PARAMS'] : [], 'sort' => (int) ($row['SORT'] ?? 100)];
        }
        return $items;
    }

    public function createRestriction(int $serviceId, array $fields): int
    {
        $this->sale();
        $result = \Bitrix\Sale\Delivery\Restrictions\Manager::add(['SERVICE_ID' => $serviceId, 'CLASS_NAME' => $fields['class'], 'PARAMS' => $fields['params'], 'SORT' => $fields['sort'] ?? 100]);
        if (!$result->isSuccess()) {
            throw new RuntimeException(implode('; ', $result->getErrorMessages()));
        }
        return (int) $result->getId();
    }

    public function updateRestriction(int $id, array $fields): void
    {
        $this->sale();
        $result = \Bitrix\Sale\Delivery\Restrictions\Manager::update($id, ['PARAMS' => $fields['params'], 'SORT' => $fields['sort'] ?? 100]);
        if (!$result->isSuccess()) {
            throw new RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    public function deleteRestriction(int $id): void
    {
        $this->sale();
        $result = \Bitrix\Sale\Delivery\Restrictions\Manager::delete($id);
        if (!$result->isSuccess()) {
            throw new RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function service(array $row): array
    {
        return ['id' => (int) $row['ID'], 'name' => (string) $row['NAME'], 'active' => $row['ACTIVE'] === 'Y', 'description' => (string) ($row['DESCRIPTION'] ?? ''), 'price' => (float) ($row['PRICE'] ?? 0), 'currency' => (string) ($row['CURRENCY'] ?? ''), 'period_from' => (string) ($row['PERIOD_FROM'] ?? ''), 'period_to' => (string) ($row['PERIOD_TO'] ?? ''), 'sort' => (int) ($row['SORT'] ?? 100)];
    }
}
