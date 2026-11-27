<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Tools;

use OrgBox\SiteOps\Audit\AuditLogger;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Delivery\DeliveryGateway;
use OrgBox\SiteOps\Mcp\Tool;
use OrgBox\SiteOps\Mcp\ToolException;
use OrgBox\SiteOps\Mcp\ToolRegistry;

/** Ограниченное управление штатными службами доставки sale без CONFIG и секретов обработчиков. */
final class DeliveryToolset
{
    private const RESTRICTIONS = [
        'site' => ['class' => '\\Bitrix\\Sale\\Delivery\\Restrictions\\BySite', 'params' => ['site_id']],
        'price' => ['class' => '\\Bitrix\\Sale\\Delivery\\Restrictions\\ByPrice', 'params' => ['min_price', 'max_price']],
        'location' => ['class' => '\\Bitrix\\Sale\\Delivery\\Restrictions\\ByLocation', 'params' => ['locations']],
    ];

    public function __construct(private DeliveryGateway $gateway, private Settings $settings, private AuditLogger $audit)
    {
    }

    public function registerAll(ToolRegistry $registry): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $registry->register(new Tool('delivery_service_list', 'Список служб доставки Bitrix sale: цена, активность и ограничения. Конфигурации обработчиков и секреты не возвращаются.', ['type' => 'object', 'properties' => []], false, fn (): array => $this->list()));
        $registry->register(new Tool('delivery_service_get', 'Одна служба доставки и её ограничения без конфигурации обработчика.', ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']], false, fn (array $a): array => $this->get($a)));
        $registry->register(new Tool('delivery_service_update', 'Изменить безопасные поля службы доставки из allow-list: название, активность, описание, базовую цену и срок. После записи выполняется проверка.', ['type' => 'object', 'properties' => ['id' => $id, 'fields' => ['type' => 'object'], 'dry_run' => ['type' => 'boolean']], 'required' => ['id', 'fields']], true, fn (array $a): array => $this->update($a)));
        foreach (['list', 'create', 'update', 'delete'] as $action) {
            $schema = ['type' => 'object', 'properties' => ['service_id' => $id], 'required' => ['service_id']];
            if ($action === 'create') {
                $schema['properties']['type'] = ['type' => 'string', 'enum' => array_keys(self::RESTRICTIONS)];
                $schema['properties']['params'] = ['type' => 'object'];
                $schema['properties']['sort'] = ['type' => 'integer', 'minimum' => 0];
                $schema['properties']['dry_run'] = ['type' => 'boolean'];
                $schema['required'] = ['service_id', 'type', 'params'];
            } elseif ($action === 'update') {
                $schema['properties'] += ['id' => $id, 'params' => ['type' => 'object'], 'sort' => ['type' => 'integer', 'minimum' => 0], 'dry_run' => ['type' => 'boolean']];
                $schema['required'] = ['service_id', 'id', 'params'];
            } elseif ($action === 'delete') {
                $schema['properties'] += ['id' => $id, 'dry_run' => ['type' => 'boolean']];
                $schema['required'] = ['service_id', 'id'];
            }
            $registry->register(new Tool('delivery_restriction_' . $action, match ($action) {
                'list' => 'Ограничения службы доставки: сайт, сумма корзины, местоположение.',
                'create' => 'Создать ограничение службы доставки из allow-list. dry_run не записывает данные.',
                'update' => 'Изменить параметры ограничения службы доставки из allow-list с проверкой.',
                default => 'Удалить ограничение службы доставки из allow-list. dry_run не удаляет данные.',
            }, $schema, $action !== 'list', fn (array $a): array => $this->restriction($action, $a)));
        }
    }

    private function list(): array
    {
        $items = [];
        foreach ($this->gateway->listServices() as $service) {
            $service['restrictions'] = $this->gateway->listRestrictions((int) $service['id']);
            $items[] = $service;
        }
        return ['services' => $items];
    }

    private function get(array $args): array
    {
        $id = $this->id($args['id'] ?? null, 'id');
        $service = $this->gateway->getService($id) ?? throw new ToolException('Служба доставки не найдена.');
        $service['restrictions'] = $this->gateway->listRestrictions($id);
        return $service;
    }

    private function update(array $args): array
    {
        $id = $this->writable($args['id'] ?? null);
        $before = $this->gateway->getService($id) ?? throw new ToolException('Служба доставки не найдена.');
        $fields = $this->serviceFields($args['fields'] ?? null);
        if ($fields === []) throw new ToolException('Передайте хотя бы одно разрешённое поле.');
        $changes = [];
        foreach ($fields as $key => $value) if (($before[$key] ?? null) !== $value) $changes[$key] = ['before' => $before[$key] ?? null, 'after' => $value];
        if (!empty($args['dry_run'])) return ['dry_run' => true, 'id' => $id, 'changes' => $changes];
        if ($changes !== []) $this->gateway->updateService($id, $fields);
        $after = $this->gateway->getService($id);
        $verified = $after !== null;
        foreach ($fields as $key => $value) $verified = $verified && ($after[$key] ?? null) === $value;
        $this->audit->log('delivery.service.update', ['id' => $id, 'fields' => array_keys($fields), 'verified' => $verified]);
        return ['updated' => $changes !== [], 'id' => $id, 'changes' => $changes, 'verified' => $verified];
    }

    private function restriction(string $action, array $args): array
    {
        $serviceId = $action === 'list' ? $this->id($args['service_id'] ?? null, 'service_id') : $this->writable($args['service_id'] ?? null);
        if ($action === 'list') return ['service_id' => $serviceId, 'restrictions' => $this->gateway->listRestrictions($serviceId)];
        $id = $action === 'create' ? null : $this->id($args['id'] ?? null, 'id');
        $existing = $id === null ? null : $this->findRestriction($serviceId, $id);
        if ($id !== null && $existing === null) throw new ToolException('Ограничение этой службы доставки не найдено.');
        if ($action === 'delete') {
            if (!empty($args['dry_run'])) return ['dry_run' => true, 'id' => $id, 'deleted' => false];
            $this->gateway->deleteRestriction($id);
            $verified = $this->findRestriction($serviceId, $id) === null;
            $this->audit->log('delivery.restriction.delete', ['service_id' => $serviceId, 'id' => $id, 'verified' => $verified]);
            return ['deleted' => $verified, 'id' => $id, 'verified' => $verified];
        }
        $type = $action === 'create' ? (string) ($args['type'] ?? '') : $this->typeOf((string) $existing['class']);
        if (!isset(self::RESTRICTIONS[$type])) throw new ToolException('Поддерживаются только ограничения site, price и location.');
        $fields = ['class' => self::RESTRICTIONS[$type]['class'], 'params' => $this->restrictionParams($type, $args['params'] ?? null), 'sort' => isset($args['sort']) ? $this->id($args['sort'], 'sort', 0) : (int) ($existing['sort'] ?? 100)];
        if (!empty($args['dry_run'])) return ['dry_run' => true, 'service_id' => $serviceId, 'restriction' => $fields + ['type' => $type]];
        if ($action === 'create') $id = $this->gateway->createRestriction($serviceId, $fields); else $this->gateway->updateRestriction($id, $fields);
        $after = $this->findRestriction($serviceId, $id);
        $verified = $after !== null && $after['params'] === $fields['params'] && (int) $after['sort'] === $fields['sort'];
        $this->audit->log('delivery.restriction.' . $action, ['service_id' => $serviceId, 'id' => $id, 'type' => $type, 'verified' => $verified]);
        return ['id' => $id, 'service_id' => $serviceId, 'type' => $type, 'verified' => $verified];
    }

    private function writable(mixed $value): int
    {
        if (!$this->settings->writesEnabled()) throw new ToolException('Запись отключена: включите режим «чтение и запись».');
        $id = $this->id($value, 'service_id');
        if (!in_array($id, $this->settings->allowedDeliveryServices(), true)) throw new ToolException('Служба доставки не входит в allow-list для записи.');
        return $id;
    }

    private function id(mixed $value, string $name, int $min = 1): int
    {
        if (is_string($value) && ctype_digit($value)) $value = (int) $value;
        if (!is_int($value) || $value < $min) throw new ToolException($name . ' должен быть целым числом.');
        return $value;
    }

    /** @return array<string,mixed> */
    private function serviceFields(mixed $value): array
    {
        if (!is_array($value)) throw new ToolException('fields должен быть объектом.');
        $allowed = ['name', 'active', 'description', 'price', 'currency', 'period_from', 'period_to'];
        $out = [];
        foreach ($value as $key => $item) {
            if (!in_array($key, $allowed, true)) throw new ToolException('Поле ' . $key . ' запрещено.');
            if (($key === 'price' && (!is_numeric($item) || (float) $item < 0)) || ($key === 'active' && !is_bool($item))) {
                throw new ToolException('Некорректное значение ' . $key . '.');
            }
            if ($key !== 'price' && $key !== 'active' && (!is_string($item) || mb_strlen($item) > 1000)) throw new ToolException('Некорректное значение ' . $key . '.');
            $out[$key] = $key === 'price' ? (float) $item : $item;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function restrictionParams(string $type, mixed $params): array
    {
        if (!is_array($params)) throw new ToolException('params должен быть объектом.');
        $keys = self::RESTRICTIONS[$type]['params'];
        if (array_diff(array_keys($params), $keys) !== [] || array_diff($keys, array_keys($params)) !== []) throw new ToolException('Недопустимые параметры ограничения.');
        if ($type === 'site' && (!is_string($params['site_id']) || !preg_match('/^[a-z0-9_]{1,10}$/i', $params['site_id']))) throw new ToolException('site_id некорректен.');
        if ($type === 'price') foreach (['min_price', 'max_price'] as $k) if ($params[$k] !== null && (!is_numeric($params[$k]) || (float) $params[$k] < 0)) throw new ToolException($k . ' некорректен.');
        if ($type === 'price' && $params['min_price'] !== null && $params['max_price'] !== null && (float) $params['min_price'] > (float) $params['max_price']) throw new ToolException('min_price не может быть больше max_price.');
        if ($type === 'location' && (!is_array($params['locations']) || $params['locations'] === [] || array_filter($params['locations'], static fn ($v): bool => !is_int($v) || $v < 1) !== [])) throw new ToolException('locations должен быть непустым списком ID местоположений.');
        return $params;
    }

    private function findRestriction(int $serviceId, int $id): ?array
    {
        foreach ($this->gateway->listRestrictions($serviceId) as $item) if ((int) $item['id'] === $id) return $item;
        return null;
    }

    private function typeOf(string $class): string
    {
        foreach (self::RESTRICTIONS as $type => $definition) if ($class === $definition['class']) return $type;
        return '';
    }
}
