<?php

declare(strict_types=1);

use OrgBox\SiteOps\Files\ImageLoader;
use OrgBox\SiteOps\Mcp\ToolException;

$rw = ['mode' => 'readwrite', 'allowed_iblocks' => '1'];

function pdfBase64(): string
{
    return base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

// ---- очистка свойств и перечитывание ------------------------------------------------------------

test('свойство: [] очищает множественное свойство, это видно при повторном чтении', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    callTool($server, 'element_update', ['id' => 10, 'properties' => ['TAGS' => ['a', 'b']]]);
    assertSame(['a', 'b'], $gateway->elements[10]['properties']['TAGS']);

    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['TAGS' => []]]);
    assertSame(true, $r['data']['updated']);
    assertSame(true, $r['data']['verified']);
    assertSame(['a', 'b'], $r['data']['changes']['PROPERTY_TAGS']['before']);
    assertSame(null, $r['data']['changes']['PROPERTY_TAGS']['after']);
    assertTrue(!isset($gateway->elements[10]['properties']['TAGS']));
    assertSame(null, callTool($server, 'element_get', ['id' => 10])['data']['properties']['TAGS'] ?? null);
});

test('свойство: null очищает, повторная очистка пустого — без изменений', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['BRAND' => null]]);
    assertSame(true, $r['data']['verified']);
    assertTrue(!isset($gateway->elements[10]['properties']['BRAND']));
    assertSame(false, callTool($server, 'element_update', ['id' => 10, 'properties' => ['BRAND' => null]])['data']['updated']);
    assertSame(false, callTool($server, 'element_update', ['id' => 10, 'properties' => ['TAGS' => []]])['data']['updated']);
});

test('запись: ответ строится по перечитанному из базы, а не по отправленному', function () use ($rw): void {
    [$server, $gateway, $audit] = toolsetServer($rw);
    $gateway->dropWrites = true;
    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['BRAND' => 'Другой']]);
    assertSame(false, $r['isError']);
    assertSame(false, $r['data']['verified']);
    assertSame(['PROPERTY_BRAND'], $r['data']['not_applied']);
    assertSame('Acme', $r['data']['changes']['PROPERTY_BRAND']['after']);
    assertSame('Другой', $r['data']['changes']['PROPERTY_BRAND']['expected']);
});

test('запись: порядок значений множественного свойства не важен для проверки', function () use ($rw): void {
    [$server] = toolsetServer($rw);
    callTool($server, 'element_update', ['id' => 10, 'properties' => ['TAGS' => ['b', 'a']]]);
    assertSame(false, callTool($server, 'element_update', ['id' => 10, 'properties' => ['TAGS' => ['a', 'b']]])['data']['updated']);
});

// ---- файловые свойства --------------------------------------------------------------------------

test('файлы: загрузка base64 в множественное свойство добавляет, не трогая прежние', function (): void {
    [$server, $gateway, $audit] = imageServer();
    $a = callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['base64' => PNG_1X1, 'name' => 'one.png']]]);
    assertSame(true, $a['data']['updated']);
    assertSame(true, $a['data']['verified']);
    $b = callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['base64' => PNG_1X1, 'name' => 'two.png']]]);
    assertSame(true, $b['data']['verified']);
    assertSame(['one.png', 'two.png'], array_column($gateway->elements[10]['properties']['MORE_PHOTO'], 'name'));
    assertSame(2, count($b['data']['changes']['PROPERTY_MORE_PHOTO']['after']));
    assertTrue(!str_contains($b['text'], PNG_1X1), 'содержимое файла не попадает в ответ');
    assertTrue(!str_contains(json_encode($audit->entries), PNG_1X1), 'и в журнал');
});

test('файлы: список файлов и загрузка по url', function (): void {
    [$server, $gateway, , $http] = imageServer();
    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => [
        ['base64' => PNG_1X1, 'name' => 'a.png'],
        ['url' => 'https://example.com/img/Alla Photo.png'],
    ]]]);
    assertSame(true, $r['data']['verified']);
    assertSame(2, count($gateway->elements[10]['properties']['MORE_PHOTO']));
    assertSame(['https://example.com/img/Alla Photo.png'], $http->requests);
});

test('файлы: удаление отдельного файла по id, остальные остаются', function (): void {
    [$server, $gateway] = imageServer();
    callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => [['base64' => PNG_1X1, 'name' => 'a.png'], ['base64' => PNG_1X1, 'name' => 'b.png']]]]);
    [$first, $second] = array_column($gateway->elements[10]['properties']['MORE_PHOTO'], 'id');

    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['delete' => [$first]]]]);
    assertSame(true, $r['data']['updated']);
    assertSame(true, $r['data']['verified']);
    assertSame([$second], array_column($gateway->elements[10]['properties']['MORE_PHOTO'], 'id'));

    // добавить и удалить одним вызовом
    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['add' => ['base64' => PNG_1X1, 'name' => 'c.png'], 'delete' => [$second]]]]);
    assertSame(true, $r['data']['verified']);
    assertSame(['c.png'], array_column($gateway->elements[10]['properties']['MORE_PHOTO'], 'name'));
});

test('файлы: не множественное свойство — новый файл заменяет прежний; удаление по id', function (): void {
    [$server, $gateway] = imageServer();
    callTool($server, 'element_update', ['id' => 10, 'properties' => ['PHOTO' => ['base64' => PNG_1X1, 'name' => 'old.png']]]);
    callTool($server, 'element_update', ['id' => 10, 'properties' => ['PHOTO' => ['base64' => PNG_1X1, 'name' => 'new.png']]]);
    assertSame('new.png', $gateway->elements[10]['properties']['PHOTO']['name']);
    $id = $gateway->elements[10]['properties']['PHOTO']['id'];
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'properties' => ['PHOTO' => [['base64' => PNG_1X1], ['base64' => PNG_1X1]]]])['isError'], 'два файла в одно значение');
    callTool($server, 'element_update', ['id' => 10, 'properties' => ['PHOTO' => ['delete' => [$id]]]]);
    assertTrue(!isset($gateway->elements[10]['properties']['PHOTO']));
});

test('файлы: чужой id, пустое значение и неверный формат отклоняются, ничего не пишется', function (): void {
    [$server, $gateway] = imageServer();
    $before = $gateway->calls;
    assertContains('нет', callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['delete' => [999]]]])['text']);
    assertContains('нельзя', callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => null]])['text']);
    assertContains('нельзя', callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => []]])['text']);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => 'x']])['isError']);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['add' => ['base64' => PNG_1X1], 'zzz' => 1]]])['isError']);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['base64' => base64_encode('<?php echo 1;')]]])['isError'], 'не файл допустимого типа');
    assertSame($before, $gateway->calls);
});

test('файлы: PDF принимается в файловое свойство, но не в картинку', function (): void {
    [$server, $gateway] = imageServer();
    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['base64' => pdfBase64(), 'name' => 'Инструкция.pdf']]]);
    assertSame(true, $r['data']['verified']);
    assertTrue(str_ends_with($gateway->elements[10]['properties']['MORE_PHOTO'][0]['name'], '.pdf'));
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'fields' => ['PREVIEW_PICTURE' => ['base64' => pdfBase64()]]])['isError']);
    assertThrows(ToolException::class, fn () => (new ImageLoader())->load('X', ['base64' => pdfBase64()]), 'не JPEG');
});

test('файлы: dry_run показывает описание, ничего не пишет', function (): void {
    [$server, $gateway] = imageServer();
    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['base64' => PNG_1X1, 'name' => 'a.png']], 'dry_run' => true]);
    assertSame(true, $r['data']['dry_run']);
    assertSame('a.png', $r['data']['changes']['PROPERTY_MORE_PHOTO']['after']['add'][0]['name']);
    assertTrue(!str_contains($r['text'], PNG_1X1));
    assertTrue(!isset($gateway->elements[10]['properties']['MORE_PHOTO']));
    $c = callTool($server, 'element_create', ['iblock_id' => 1, 'fields' => ['NAME' => 'X'], 'properties' => ['MORE_PHOTO' => ['base64' => PNG_1X1]], 'dry_run' => true]);
    assertTrue(!str_contains($c['text'], PNG_1X1));
});

test('файлы: при создании элемента файловое свойство загружается', function (): void {
    [$server, $gateway] = imageServer();
    $r = callTool($server, 'element_create', ['iblock_id' => 1, 'fields' => ['NAME' => 'Новый'], 'properties' => ['MORE_PHOTO' => ['base64' => PNG_1X1, 'name' => 'n.png']]]);
    assertSame(true, $r['data']['created']);
    assertSame(['n.png'], array_column($gateway->elements[$r['data']['id']]['properties']['MORE_PHOTO'], 'name'));
});

test('файлы: не удавшаяся загрузка видна как verified=false', function (): void {
    [$server, $gateway] = imageServer();
    $gateway->dropWrites = true;
    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['MORE_PHOTO' => ['base64' => PNG_1X1]]]);
    assertSame(false, $r['data']['verified']);
    assertSame(['PROPERTY_MORE_PHOTO'], $r['data']['not_applied']);
});

// ---- заполненные свойства -----------------------------------------------------------------------

test('element_filled_properties: только заполненные, с названиями', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $gateway->elements[10]['properties']['EMPTY_PROP'] = '';
    $gateway->elements[10]['properties']['TAGS'] = [];
    $r = callTool($server, 'element_filled_properties', ['id' => 10]);
    assertSame(false, $r['isError']);
    assertSame(2, $r['data']['count']);
    assertSame(['COLOR', 'BRAND'], array_column($r['data']['properties'], 'code'));
    assertSame(['Цвет', 'Бренд'], array_column($r['data']['properties'], 'name'));
    assertSame('Красный', $r['data']['properties'][0]['value']);
});

test('element_filled_properties: доступен в режиме чтения, чужой инфоблок закрыт', function (): void {
    [$server] = toolsetServer(['mode' => 'read', 'allowed_iblocks' => '2']);
    assertTrue(callTool($server, 'element_filled_properties', ['id' => 10])['isError']);
    assertSame(false, callTool($server, 'element_filled_properties', ['id' => 20])['isError']);
    assertTrue(callTool($server, 'element_filled_properties', ['id' => 9999])['isError']);
});

// ---- цены и остатки -----------------------------------------------------------------------------

test('товар: чтение цен, остатков, складов и типов цен', function () use ($rw): void {
    [$server] = toolsetServer($rw);
    $r = callTool($server, 'product_get', ['id' => 10]);
    assertSame(false, $r['isError']);
    assertSame(5, $r['data']['quantity']);
    assertSame('BASE', $r['data']['prices'][0]['type']);
    assertSame(100, $r['data']['prices'][0]['price']);
    assertSame(true, $r['data']['prices'][0]['base']);
    assertSame([1, 2], array_column($r['data']['stores'], 'id'));
    assertSame(['BASE', 'OPT'], array_column($r['data']['price_types'], 'code'));
    assertTrue(callTool($server, 'product_get', ['id' => 11])['isError'], 'не товар каталога');
    assertTrue(callTool($server, 'product_get', ['id' => 20])['isError'], 'инфоблок вне списка нужен только при ограничении');
});

test('товар: запись цены по коду типа, новой цены, остатка и склада; ответ перечитан', function () use ($rw): void {
    [$server, $gateway, $audit] = toolsetServer($rw);
    $r = callTool($server, 'product_update', ['id' => 10, 'prices' => ['base' => 120, 'OPT' => ['price' => 90.5, 'currency' => 'RUB']], 'stores' => ['2' => 7], 'quantity' => 12]);
    assertSame(false, $r['isError']);
    assertSame(true, $r['data']['updated']);
    assertSame(true, $r['data']['verified']);
    assertSame(['PRICE_1', 'PRICE_2', 'QUANTITY', 'STORE_2', 'PROP_MINIMUM_PRICE', 'PROP_MAXIMUM_PRICE'], array_keys($r['data']['changes']));
    assertSame(['price' => 100, 'currency' => 'RUB'], $r['data']['changes']['PRICE_1']['before']);
    assertSame(null, $r['data']['changes']['PRICE_2']['before']);
    assertSame(120.0, $gateway->products[10]['prices'][0]['price']);
    assertSame(12, callTool($server, 'product_get', ['id' => 10])['data']['quantity']);
    assertSame(7.0, $gateway->products[10]['stores'][2]['amount']);
    assertSame('120', $gateway->elements[10]['properties']['MINIMUM_PRICE']);
    assertSame('120', $gateway->elements[10]['properties']['MAXIMUM_PRICE']);
    assertSame(1, count(array_filter($gateway->calls, static fn (string $c): bool => $c === 'setProperties:10')), 'свойства записаны одной операцией');
    assertSame('product.update', $audit->entries[count($audit->entries) - 1][0]);
});

test('товар: базовая цена (голое число и объект) пишется в MINIMUM_PRICE и MAXIMUM_PRICE строкой', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $bare = callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => 3500]]);
    assertSame(true, $bare['data']['verified']);
    assertSame(3500.0, $gateway->products[10]['prices'][0]['price']);
    assertSame('3500', $gateway->elements[10]['properties']['MINIMUM_PRICE']);
    assertSame('3500', $gateway->elements[10]['properties']['MAXIMUM_PRICE']);

    $object = callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => ['price' => 90.5, 'currency' => 'RUB']]]);
    assertSame(true, $object['data']['verified']);
    assertSame(90.5, $gateway->products[10]['prices'][0]['price']);
    assertSame('90.5', $gateway->elements[10]['properties']['MINIMUM_PRICE']);
    assertSame('90.5', $gateway->elements[10]['properties']['MAXIMUM_PRICE']);
});

test('товар: цена не базовая (OPT) свойства не трогает', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $r = callTool($server, 'product_update', ['id' => 10, 'prices' => ['OPT' => 70]]);
    assertSame(true, $r['data']['verified']);
    assertSame(['PRICE_2'], array_keys($r['data']['changes']));
    assertSame(false, isset($gateway->elements[10]['properties']['MINIMUM_PRICE']));
});

test('товар: без свойств MINIMUM_PRICE/MAXIMUM_PRICE базовая цена не пишется, отказ до записи', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $gateway->hiddenProperties = ['MAXIMUM_PRICE'];
    $calls = $gateway->calls;
    $r = callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => 150]]);
    assertTrue($r['isError'], 'нет свойства — ошибка');
    assertContains('MAXIMUM_PRICE', $r['text']);
    assertSame($calls, $gateway->calls);
    assertSame(100.0, $gateway->products[10]['prices'][0]['price']);
});

test('товар: базовая цена уже верна, а свойства расходятся — свойства исправляются', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $gateway->elements[10]['properties']['MINIMUM_PRICE'] = '54000';
    $gateway->elements[10]['properties']['MAXIMUM_PRICE'] = '100';
    $r = callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => 100]]);
    assertSame(true, $r['data']['updated']);
    assertSame(['PROP_MINIMUM_PRICE'], array_keys($r['data']['changes']), 'MAXIMUM_PRICE уже верен, не трогается');
    assertSame('100', $gateway->elements[10]['properties']['MINIMUM_PRICE']);
    assertSame(true, $r['data']['verified']);
    assertSame(false, callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => 100]])['data']['updated']);
});

test('товар: dry_run показывает свойства, совпадающие значения и невозможная запись', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $dry = callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => 150], 'dry_run' => true]);
    assertSame(true, $dry['data']['dry_run']);
    assertSame(['PRICE_1', 'PROP_MINIMUM_PRICE', 'PROP_MAXIMUM_PRICE'], array_keys($dry['data']['changes']));
    assertSame(100.0, $gateway->products[10]['prices'][0]['price']);
    assertSame(false, isset($gateway->elements[10]['properties']['MINIMUM_PRICE']));
    $gateway->dropWrites = true;
    $r = callTool($server, 'product_update', ['id' => 10, 'quantity' => 1]);
    assertSame(false, $r['data']['verified']);
    assertSame(['QUANTITY'], $r['data']['not_applied']);
    assertSame(5, $r['data']['changes']['QUANTITY']['after']);
    $r = callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => 150]]);
    assertSame(false, $r['data']['verified']);
    assertSame(['PRICE_1', 'PROP_MINIMUM_PRICE', 'PROP_MAXIMUM_PRICE'], $r['data']['not_applied']);
});

test('товар: проверка аргументов и прав', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $calls = $gateway->calls;
    assertContains('Тип цены', callTool($server, 'product_update', ['id' => 10, 'prices' => ['NOPE' => 1]])['text']);
    assertContains('Склад', callTool($server, 'product_update', ['id' => 10, 'stores' => ['77' => 1]])['text']);
    assertTrue(callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => -1]])['isError']);
    assertTrue(callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => 'много']])['isError']);
    assertTrue(callTool($server, 'product_update', ['id' => 10, 'prices' => ['BASE' => ['price' => 1, 'currency' => 'rub']]])['isError']);
    assertTrue(callTool($server, 'product_update', ['id' => 10, 'quantity' => -3])['isError']);
    assertTrue(callTool($server, 'product_update', ['id' => 10])['isError'], 'нечего изменять');
    assertTrue(callTool($server, 'product_update', ['id' => 11, 'quantity' => 1])['isError'], 'не товар каталога');
    assertSame($calls, $gateway->calls);

    [$readOnly] = toolsetServer(['mode' => 'read', 'allowed_iblocks' => '1']);
    $names = array_column(json_decode(json_encode($readOnly->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')['data']['result']['tools']), true), 'name');
    assertTrue(in_array('product_get', $names, true) && in_array('element_filled_properties', $names, true));
    assertTrue(!in_array('product_update', $names, true), 'запись цен скрыта в режиме чтения');

    [$other] = toolsetServer(['mode' => 'readwrite', 'allowed_iblocks' => '2']);
    assertTrue(callTool($other, 'product_update', ['id' => 10, 'quantity' => 1])['isError'], 'инфоблок товара не в списке записи');
});
