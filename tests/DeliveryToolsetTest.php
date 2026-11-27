<?php

declare(strict_types=1);

use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Delivery\DeliveryGateway;
use OrgBox\SiteOps\Mcp\McpServer;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use OrgBox\SiteOps\Tools\DeliveryToolset;
use Tests\Support\ArrayOptionStore;
use Tests\Support\MemoryAudit;

final class FakeDeliveryGateway implements DeliveryGateway
{
    public array $services = [10 => ['id' => 10, 'name' => 'Курьер', 'active' => true, 'description' => '', 'price' => 500.0, 'currency' => 'RUB', 'period_from' => '1', 'period_to' => '2', 'sort' => 100]];
    public array $restrictions = [];
    private int $next = 100;
    public function listServices(): array { return array_values($this->services); }
    public function getService(int $id): ?array { return $this->services[$id] ?? null; }
    public function updateService(int $id, array $fields): void { $this->services[$id] = $fields + $this->services[$id]; }
    public function listRestrictions(int $serviceId): array { return array_values(array_filter($this->restrictions, static fn ($r) => $r['service_id'] === $serviceId)); }
    public function createRestriction(int $serviceId, array $fields): int { $id = $this->next++; $this->restrictions[$id] = ['id' => $id, 'service_id' => $serviceId] + $fields; return $id; }
    public function updateRestriction(int $id, array $fields): void { $this->restrictions[$id] = $fields + $this->restrictions[$id]; }
    public function deleteRestriction(int $id): void { unset($this->restrictions[$id]); }
}

function deliveryServer(array $options = []): array
{
    $settings = new Settings(new ArrayOptionStore($options));
    $gateway = new FakeDeliveryGateway();
    $audit = new MemoryAudit();
    $registry = new ToolRegistry();
    (new DeliveryToolset($gateway, $settings, $audit))->registerAll($registry);
    return [new McpServer($registry, $settings->writesEnabled(), '1.0.0'), $gateway, $audit];
}

test('доставка: список и get не раскрывают конфигурацию обработчика', function (): void {
    [$server] = deliveryServer();
    $list = callTool($server, 'delivery_service_list', []);
    assertSame(false, $list['isError']);
    assertSame('Курьер', $list['data']['services'][0]['name']);
    assertTrue(!isset($list['data']['services'][0]['config']));
    assertSame(10, callTool($server, 'delivery_service_get', ['id' => 10])['data']['id']);
});

test('доставка: запись только в allow-list, dry_run и верификация', function (): void {
    [$server, $gateway, $audit] = deliveryServer(['mode' => 'readwrite', 'allowed_delivery_services' => '10']);
    $dry = callTool($server, 'delivery_service_update', ['id' => 10, 'fields' => ['price' => 0], 'dry_run' => true]);
    assertSame(true, $dry['data']['dry_run']);
    assertSame(500.0, $gateway->services[10]['price']);
    $saved = callTool($server, 'delivery_service_update', ['id' => 10, 'fields' => ['price' => 0, 'active' => false]]);
    assertSame(true, $saved['data']['verified']);
    assertSame(0.0, $gateway->services[10]['price']);
    assertSame('delivery.service.update', $audit->entries[0][0]);
    assertSame(true, callTool($server, 'delivery_service_update', ['id' => 11, 'fields' => ['price' => 1]])['isError']);
});

test('доставка: ограничения суммы и местоположения создаются и проверяются', function (): void {
    [$server, $gateway] = deliveryServer(['mode' => 'readwrite', 'allowed_delivery_services' => '10']);
    $price = callTool($server, 'delivery_restriction_create', ['service_id' => 10, 'type' => 'price', 'params' => ['min_price' => 0, 'max_price' => 5000]]);
    assertSame(true, $price['data']['verified']);
    $location = callTool($server, 'delivery_restriction_create', ['service_id' => 10, 'type' => 'location', 'params' => ['locations' => [123]]]);
    assertSame(true, $location['data']['verified']);
    $list = callTool($server, 'delivery_restriction_list', ['service_id' => 10]);
    assertSame(2, count($list['data']['restrictions']));
    assertSame(true, callTool($server, 'delivery_restriction_delete', ['service_id' => 10, 'id' => $price['data']['id'], 'dry_run' => true])['data']['dry_run']);
    assertSame(true, callTool($server, 'delivery_restriction_delete', ['service_id' => 10, 'id' => $price['data']['id']])['data']['verified']);
    assertSame(1, count($gateway->restrictions));
});
