<?php

declare(strict_types=1);

// Торговые предложения (SKU): смена типа товара, создание предложения, права и проверка по базе.
// Заглушки: инфоблок 1 — товары, инфоблок 3 — предложения (связь CML2_LINK), инфоблок 2 — новости.

/** Разрешены и товарный (1), и инфоблок предложений (3): обычный сценарий. */
$skuRw = ['mode' => 'readwrite', 'allowed_iblocks' => '1,3'];

/** Простой товар в инфоблоке без связи с предложениями (инфоблок 2) — для отказа по связи. */
function plainProductInNewsIblock(\Tests\Support\FakeGateway $gateway): void
{
    $gateway->elements[20]['iblock_id'] = 2;
    $gateway->products[20] = ['quantity' => 1.0, 'reserved' => 0.0, 'available' => true, 'type' => 1, 'prices' => [], 'stores' => [], 'offers' => []];
}

// ---- смена типа товара ---------------------------------------------------------------------------

test('тип: simple → with_offers, ответ перечитан из базы и записан в журнал', function () use ($skuRw): void {
    [$server, $gateway, $audit] = toolsetServer($skuRw);
    $r = callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    assertSame(false, $r['isError']);
    assertSame(true, $r['data']['updated']);
    assertSame(true, $r['data']['verified']);
    assertSame(['before' => 'simple', 'after' => 'with_offers'], $r['data']['product_type']);
    assertTrue(str_contains($r['data']['note'], 'offer_create'), 'подсказка про предложения');
    assertContains('setProductType:10:3', implode(' ', $gateway->calls));
    assertSame('with_offers', callTool($server, 'product_get', ['id' => 10])['data']['product_type']);
    assertSame('product.type', $audit->entries[0][0]);
});

test('тип: dry_run меняет только ответ, в базе тип не меняется', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    $r = callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers', 'dry_run' => true]);
    assertSame(true, $r['data']['dry_run']);
    assertSame(['before' => 'simple', 'after' => 'with_offers'], $r['data']['product_type']);
    assertSame(1, $gateway->products[10]['type']);
    assertSame([], array_filter($gateway->calls, static fn (string $c): bool => str_starts_with($c, 'setProductType')));
});

test('тип: товар уже такого типа — изменений нет', function () use ($skuRw): void {
    [$server] = toolsetServer($skuRw);
    $r = callTool($server, 'product_set_type', ['id' => 10, 'type' => 'simple']);
    assertSame(false, $r['data']['updated']);
});

test('тип: перевод в with_offers без связи инфоблока с предложениями отказывает до записи', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer(['mode' => 'readwrite', 'allowed_iblocks' => '1,2']);
    plainProductInNewsIblock($gateway);
    $r = callTool($server, 'product_set_type', ['id' => 20, 'type' => 'with_offers']);
    assertSame(true, $r['isError']);
    assertTrue(str_contains($r['text'], 'не связан с инфоблоком торговых предложений'), $r['text']);
    assertSame(1, $gateway->products[20]['type']);
});

test('тип: обратно в simple при наличии предложений отказывает с понятной ошибкой', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'Белая']]);
    $r = callTool($server, 'product_set_type', ['id' => 10, 'type' => 'simple']);
    assertSame(true, $r['isError']);
    assertTrue(str_contains($r['text'], 'есть торговые предложения (1'), $r['text']);
    assertSame(3, $gateway->products[10]['type']);
});

test('тип: обратно в simple без предложений разрешено', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    $r = callTool($server, 'product_set_type', ['id' => 10, 'type' => 'simple']);
    assertSame(true, $r['data']['verified']);
    assertSame(1, $gateway->products[10]['type']);
});

test('тип: чужой инфоблок и неверный тип отклоняются', function () use ($skuRw): void {
    [$server] = toolsetServer(['mode' => 'readwrite', 'allowed_iblocks' => '3']);
    assertSame(true, callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers'])['isError']);
    [$server] = toolsetServer($skuRw);
    assertSame(true, callTool($server, 'product_set_type', ['id' => 10, 'type' => 'set'])['isError']);
    assertSame(true, callTool($server, 'product_set_type', ['id' => 10])['isError']);
});

test('тип: в режиме чтения инструмент записи недоступен', function (): void {
    [$server, $gateway] = toolsetServer(['mode' => 'readonly', 'allowed_iblocks' => '1,3']);
    assertSame(true, callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers'])['isError']);
    assertSame(1, $gateway->products[10]['type']);
});

// ---- создание предложения -----------------------------------------------------------------------

test('предложение: создаётся в инфоблоке предложений, связано с товаром и видно у товара', function () use ($skuRw): void {
    [$server, $gateway, $audit] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    $r = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'Педикюрная тёрка, белая', 'CODE' => 'white'],
        'properties' => ['COLOR_REF' => 'Белый', 'ARTICLE' => 'MR-02']]);
    assertSame(false, $r['isError'], $r['text']);
    assertSame(true, $r['data']['created']);
    assertSame(true, $r['data']['verified']);
    $id = $r['data']['id'];
    assertSame(3, $r['data']['iblock_id']);
    assertSame(false, $gateway->elements[$id]['active'], 'по умолчанию неактивно');
    assertSame('10', $gateway->elements[$id]['properties']['CML2_LINK']);
    assertSame('MR-02', $gateway->elements[$id]['properties']['ARTICLE']);
    assertSame(4, $gateway->products[$id]['type']);
    assertSame([$id], array_column(callTool($server, 'product_get', ['id' => 10])['data']['offers'], 'id'));
    assertSame('offer', callTool($server, 'product_get', ['id' => $id])['data']['product_type']);
    assertSame('offer.create', $audit->entries[array_key_last($audit->entries)][0]);
});

test('предложение: свойство связи нельзя передать вручную', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    $r = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'X'], 'properties' => ['CML2_LINK' => 11]]);
    assertSame(true, $r['isError']);
    assertTrue(str_contains($r['text'], 'задаёт сам инструмент'), $r['text']);
    assertSame([], array_filter($gateway->calls, static fn (string $c): bool => str_starts_with($c, 'createOffer')));
});

test('предложение: у товара не тип with_offers — отказ до создания', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    $r = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'X']]);
    assertSame(true, $r['isError']);
    assertTrue(str_contains($r['text'], 'with_offers'), $r['text']);
    assertSame([], array_filter($gateway->calls, static fn (string $c): bool => str_starts_with($c, 'createOffer')));
});

test('предложение: инфоблок предложений не разрешён для записи — отказ до создания', function (): void {
    [$server, $gateway] = toolsetServer(['mode' => 'readwrite', 'allowed_iblocks' => '1']);
    $gateway->products[10]['type'] = 3;
    $r = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'X']]);
    assertSame(true, $r['isError']);
    assertTrue(str_contains($r['text'], 'Запись в инфоблок 3 запрещена'), $r['text']);
    assertSame([], array_filter($gateway->calls, static fn (string $c): bool => str_starts_with($c, 'createOffer')));
});

test('предложение: родитель в инфоблоке без права записи — отказ', function (): void {
    [$server, $gateway] = toolsetServer(['mode' => 'readwrite', 'allowed_iblocks' => '3']);
    $gateway->products[10]['type'] = 3;
    $r = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'X']]);
    assertSame(true, $r['isError']);
    assertTrue(str_contains($r['text'], 'Запись в инфоблок 1 запрещена'), $r['text']);
});

test('предложение: без NAME отказ; неизвестное свойство отказ', function () use ($skuRw): void {
    [$server] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    assertSame(true, callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['SORT' => 5]])['isError']);
    $r = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'X'], 'properties' => ['NOPE' => 'a']]);
    assertTrue(str_contains($r['text'], 'не найдено в инфоблоке 3'), $r['text']);
});

test('предложение: dry_run показывает, что будет создано, и ничего не создаёт', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    $r = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'Чёрная'], 'properties' => ['ARTICLE' => 'MR-01'], 'dry_run' => true]);
    assertSame(true, $r['data']['dry_run']);
    assertSame(10, $r['data']['would_create']['properties']['CML2_LINK']);
    assertSame([], array_filter($gateway->calls, static fn (string $c): bool => str_starts_with($c, 'createOffer')));
});

test('предложение: сбой регистрации в каталоге — элемент не удалён, причина и id видны пользователю', function () use ($skuRw): void {
    [$server, $gateway, $audit] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    $gateway->failOfferRegistration = true;
    $r = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'Белая']]);
    assertSame(true, $r['isError']);
    assertTrue(str_contains($r['text'], 'id 100'), $r['text']);
    assertTrue(str_contains($r['text'], 'Элемент не удалён'), $r['text']);
    assertSame(false, $gateway->elements[100]['active'], 'элемент остался, неактивным');
    assertSame('offer.create.failed', $audit->entries[array_key_last($audit->entries)][0]);
});

test('предложение: запись не применилась — verified=false и перечень несовпавшего', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    $gateway->dropWrites = true;
    $r = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'Белая']]);
    assertSame(false, $r['isError']);
    assertSame(false, $r['data']['verified']);
    assertSame(['link', 'parent_offers'], $r['data']['not_applied']);
});

// ---- цена и остаток предложения --------------------------------------------------------------------

test('предложение: product_update пишет базовую цену; без свойств мин./макс. синхронизация пропускается и это видно', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    $id = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'Белая']])['data']['id'];
    $gateway->hiddenProperties = ['MINIMUM_PRICE', 'MAXIMUM_PRICE'];
    $r = callTool($server, 'product_update', ['id' => $id, 'prices' => ['BASE' => 5500], 'quantity' => 581]);
    assertSame(false, $r['isError'], $r['text']);
    assertSame(true, $r['data']['verified']);
    assertSame(['MINIMUM_PRICE', 'MAXIMUM_PRICE'], $r['data']['not_synced']);
    assertTrue(str_contains($r['data']['note'], 'пропущена'), $r['data']['note']);
    assertSame(5500.0, $gateway->products[$id]['prices'][0]['price']);
});

test('предложение: product_update с мин./макс. свойствами синхронизирует их (строкой, той же записью)', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    $id = callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'Чёрная']])['data']['id'];
    $r = callTool($server, 'product_update', ['id' => $id, 'prices' => ['BASE' => 5500]]);
    assertSame(false, $r['isError'], $r['text']);
    assertSame(true, $r['data']['verified']);
    assertSame(false, array_key_exists('not_synced', $r['data']), 'свойства есть — пропуска нет');
    assertSame('5500', $gateway->elements[$id]['properties']['MINIMUM_PRICE']);
    assertSame('5500', $gateway->elements[$id]['properties']['MAXIMUM_PRICE']);
});

test('товар (не предложение) без свойств мин./макс. по-прежнему отказывает до записи', function () use ($skuRw): void {
    [$server, $gateway] = toolsetServer($skuRw);
    $gateway->hiddenProperties = ['MINIMUM_PRICE'];
    $r = callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => 3500]]);
    assertSame(true, $r['isError']);
    assertSame(1, $gateway->products[10]['prices'][0]['type_id']);
    assertSame(100.0, $gateway->products[10]['prices'][0]['price'], 'ничего не записано');
});

test('предложение: product_get родителя перечисляет предложения, в режиме чтения все инструменты чтения работают', function () use ($skuRw): void {
    [$server] = toolsetServer($skuRw);
    callTool($server, 'product_set_type', ['id' => 10, 'type' => 'with_offers']);
    callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'Белая']]);
    callTool($server, 'offer_create', ['parent_id' => 10, 'fields' => ['NAME' => 'Чёрная']]);
    $offers = callTool($server, 'product_get', ['id' => 10])['data']['offers'];
    assertSame(['Белая', 'Чёрная'], array_column($offers, 'name'));
});
