<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Delivery;

/** Шлюз к штатному API sale.delivery, отделённый для тестирования MCP-логики. */
interface DeliveryGateway
{
    /** @return list<array<string,mixed>> */
    public function listServices(): array;

    /** @return array<string,mixed>|null */
    public function getService(int $id): ?array;

    /** @param array<string,mixed> $fields */
    public function updateService(int $id, array $fields): void;

    /** @return list<array<string,mixed>> */
    public function listRestrictions(int $serviceId): array;

    /** @param array<string,mixed> $fields */
    public function createRestriction(int $serviceId, array $fields): int;

    /** @param array<string,mixed> $fields */
    public function updateRestriction(int $id, array $fields): void;

    public function deleteRestriction(int $id): void;
}
