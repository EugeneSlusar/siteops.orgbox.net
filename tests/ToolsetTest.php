<?php

declare(strict_types=1);

use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Mcp\McpServer;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use OrgBox\SiteOps\Tools\IblockToolset;
use Tests\Support\ArrayOptionStore;
use Tests\Support\FakeGateway;
use Tests\Support\MemoryAudit;

/** @return array{0:McpServer,1:FakeGateway,2:MemoryAudit} */
function toolsetServer(array $options = []): array
{
    $settings = new Settings(new ArrayOptionStore($options));
    $gateway = new FakeGateway();
    $audit = new MemoryAudit();
    $registry = new ToolRegistry();
    (new IblockToolset($gateway, $settings, $audit))->registerAll($registry);

    return [new McpServer($registry, $settings->writesEnabled(), '1.0.0'), $gateway, $audit];
}

/** @return array{isError:bool,text:string,data:mixed} */
function callTool(McpServer $server, string $name, array $arguments): array
{
    $r = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]));
    if (isset($r['data']['error'])) {
        return ['isError' => true, 'text' => 'RPC: ' . $r['data']['error']['message'], 'data' => null];
    }
    $text = $r['data']['result']['content'][0]['text'];

    return ['isError' => $r['data']['result']['isError'], 'text' => $text, 'data' => json_decode($text, true)];
}

$rw = ['mode' => 'readwrite', 'allowed_iblocks' => '1'];

test('чтение: список инфоблоков и режим', function (): void {
    [$server] = toolsetServer();
    $r = callTool($server, 'iblock_list', []);
    assertSame(false, $r['isError']);
    assertSame('readonly', $r['data']['mode']);
    assertSame(2, count($r['data']['iblocks']));
});

test('чтение: список разрешённых ограничивает и список инфоблоков', function (): void {
    [$server] = toolsetServer(['allowed_iblocks' => '2']);
    assertSame([2], array_column(callTool($server, 'iblock_list', [])['data']['iblocks'], 'id'));
});

test('чтение: недоступный инфоблок отклоняется', function (): void {
    [$server] = toolsetServer(['allowed_iblocks' => '2']);
    $r = callTool($server, 'element_list', ['iblock_id' => 1]);
    assertTrue($r['isError']);
    assertContains('недоступен', $r['text']);
});

test('чтение: элемент из недоступного инфоблока тоже закрыт', function (): void {
    [$server] = toolsetServer(['allowed_iblocks' => '2']);
    assertTrue(callTool($server, 'element_get', ['id' => 10])['isError']);
});

test('чтение: поиск и фильтр активности', function (): void {
    [$server] = toolsetServer();
    $r = callTool($server, 'element_list', ['iblock_id' => 1, 'query' => 'лак', 'active' => false]);
    assertSame([11], array_column($r['data']['items'], 'id'));
});

test('чтение: лимит ограничен 100, страница по умолчанию 1', function (): void {
    [$server] = toolsetServer();
    $r = callTool($server, 'element_list', ['iblock_id' => 1, 'limit' => 5000]);
    assertSame(100, $r['data']['limit']);
    assertSame(1, $r['data']['page']);
});

test('чтение: элемент вместе со свойствами', function (): void {
    [$server] = toolsetServer();
    $r = callTool($server, 'element_get', ['id' => 10]);
    assertSame('Помада', $r['data']['name']);
    assertSame('Красный', $r['data']['properties']['COLOR']);
});

test('чтение: несуществующий элемент', function (): void {
    [$server] = toolsetServer();
    assertContains('не найден', callTool($server, 'element_get', ['id' => 999])['text']);
});

test('чтение: строковый id приводится, мусор отклоняется', function (): void {
    [$server] = toolsetServer();
    assertSame('Помада', callTool($server, 'element_get', ['id' => '10'])['data']['name']);
    assertTrue(callTool($server, 'element_get', ['id' => 'abc'])['isError']);
    assertTrue(callTool($server, 'element_get', ['id' => -5])['isError']);
});

test('запись: в режиме чтения инструменты записи недоступны и не вызываются', function (): void {
    [$server, $gateway] = toolsetServer(['allowed_iblocks' => '1']);
    $r = callTool($server, 'element_update', ['id' => 10, 'fields' => ['NAME' => 'X']]);
    assertTrue($r['isError']);
    assertSame([], $gateway->calls);
});

test('запись: режим readwrite без списка разрешённых запрещает запись', function (): void {
    [$server, $gateway] = toolsetServer(['mode' => 'readwrite']);
    $r = callTool($server, 'element_update', ['id' => 10, 'fields' => ['NAME' => 'X']]);
    assertContains('запрещена', $r['text']);
    assertSame([], $gateway->calls);
});

test('запись: инфоблок вне списка запрещён даже в режиме записи', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $r = callTool($server, 'element_update', ['id' => 20, 'fields' => ['NAME' => 'X']]);
    assertTrue($r['isError']);
    assertSame([], $gateway->calls);
});

test('запись: изменение поля, журнал и результат', function () use ($rw): void {
    [$server, $gateway, $audit] = toolsetServer($rw);
    $r = callTool($server, 'element_update', ['id' => 10, 'fields' => ['NAME' => 'Помада матовая', 'ACTIVE' => false]]);
    assertSame(false, $r['isError']);
    assertSame(true, $r['data']['updated']);
    assertSame('Помада', $r['data']['changes']['NAME']['before']);
    assertSame('Помада матовая', $gateway->elements[10]['name']);
    assertSame(false, $gateway->elements[10]['active']);
    assertSame('element.update', $audit->entries[0][0]);
    assertSame(10, $audit->entries[0][1]['id']);
});

test('запись: dry_run ничего не меняет и не пишет в журнал', function () use ($rw): void {
    [$server, $gateway, $audit] = toolsetServer($rw);
    $r = callTool($server, 'element_update', ['id' => 10, 'fields' => ['NAME' => 'Новое'], 'dry_run' => true]);
    assertSame(true, $r['data']['dry_run']);
    assertSame('Помада', $gateway->elements[10]['name']);
    assertSame([], $gateway->calls);
    assertSame([], $audit->entries);
});

test('запись: те же значения — изменений нет, запись не выполняется', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $r = callTool($server, 'element_update', ['id' => 10, 'fields' => ['NAME' => 'Помада']]);
    assertSame(false, $r['data']['updated']);
    assertSame([], $gateway->calls);
});

test('запись: поле вне белого списка отклоняется (нельзя менять IBLOCK_ID, ID и т. п.)', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    foreach (['IBLOCK_ID', 'ID', 'CREATED_BY', 'XML_ID'] as $field) {
        $r = callTool($server, 'element_update', ['id' => 10, 'fields' => [$field => 5]]);
        assertTrue($r['isError'], $field);
    }
    assertSame([], $gateway->calls);
});

test('запись: проверка типов и длины полей', function () use ($rw): void {
    [$server] = toolsetServer($rw);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'fields' => ['NAME' => '']])['isError']);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'fields' => ['NAME' => str_repeat('я', 256)]])['isError']);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'fields' => ['ACTIVE' => 'Y']])['isError']);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'fields' => ['SORT' => -1]])['isError']);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'fields' => ['CODE' => 'плохой код']])['isError']);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'fields' => ['DETAIL_TEXT_TYPE' => 'php']])['isError']);
});

test('запись: свойство-список принимает текст значения и переводит его в идентификатор', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['COLOR' => 'Синий']]);
    assertSame(false, $r['isError'], $r['text']);
    assertSame('Красный', $r['data']['changes']['PROPERTY_COLOR']['before']);
    assertSame('Синий', $r['data']['changes']['PROPERTY_COLOR']['after']);
    assertSame('Синий', $gateway->elements[10]['properties']['COLOR']);
});

test('запись: свойство-список — повтор текущего значения не считается изменением', function () use ($rw): void {
    [$server] = toolsetServer($rw);
    $r = callTool($server, 'element_update', ['id' => 10, 'properties' => ['COLOR' => 'Красный']]);
    assertSame(false, $r['data']['updated']);
});

test('запись: недопустимое значение списка, неизвестное и файловое свойство отклоняются', function () use ($rw): void {
    [$server] = toolsetServer($rw);
    assertContains('допустимые значения', callTool($server, 'element_update', ['id' => 10, 'properties' => ['COLOR' => 'Зелёный']])['text']);
    assertContains('не найдено', callTool($server, 'element_update', ['id' => 10, 'properties' => ['NOPE' => 1]])['text']);
    assertContains('Файловое', callTool($server, 'element_update', ['id' => 10, 'properties' => ['PHOTO' => 'x']])['text']);
});

test('запись: несколько значений в не множественное свойство отклоняются', function () use ($rw): void {
    [$server] = toolsetServer($rw);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'properties' => ['BRAND' => ['a', 'b']]])['isError']);
    assertSame(false, callTool($server, 'element_update', ['id' => 10, 'properties' => ['TAGS' => ['a', 'b']], 'dry_run' => true])['isError']);
});

test('запись: создание — по умолчанию неактивный элемент, нужен NAME', function () use ($rw): void {
    [$server, $gateway, $audit] = toolsetServer($rw);
    assertTrue(callTool($server, 'element_create', ['iblock_id' => 1, 'fields' => ['CODE' => 'x']])['isError']);
    $r = callTool($server, 'element_create', ['iblock_id' => 1, 'fields' => ['NAME' => 'Тушь']]);
    assertSame(true, $r['data']['created']);
    assertSame(false, $gateway->elements[$r['data']['id']]['active']);
    assertSame('element.create', $audit->entries[0][0]);
});

test('запись: создание в инфоблоке вне списка запрещено', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    assertTrue(callTool($server, 'element_create', ['iblock_id' => 2, 'fields' => ['NAME' => 'X']])['isError']);
    assertSame([], $gateway->calls);
});

test('запись: раздел — изменение и dry_run', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw);
    callTool($server, 'section_update', ['id' => 5, 'fields' => ['NAME' => 'Новое'], 'dry_run' => true]);
    assertSame('Макияж', $gateway->sections[5]['name']);
    $r = callTool($server, 'section_update', ['id' => 5, 'fields' => ['NAME' => 'Новое']]);
    assertSame(true, $r['data']['updated']);
    assertSame('Новое', $gateway->sections[5]['name']);
});

test('SEO: чтение и запись с проверкой ключей и длины', function () use ($rw): void {
    [$server, $gateway] = toolsetServer($rw + ['default_iblock_id' => '1']);
    assertSame('{=this.NAME}', callTool($server, 'seo_templates_get', [])['data']['templates']['ELEMENT_META_TITLE']);
    assertTrue(callTool($server, 'seo_templates_update', ['templates' => ['EVIL' => 'x']])['isError']);
    assertTrue(callTool($server, 'seo_templates_update', ['templates' => ['ELEMENT_META_TITLE' => str_repeat('x', 501)]])['isError']);
    $r = callTool($server, 'seo_templates_update', ['templates' => ['ELEMENT_META_TITLE' => '{=this.NAME} | Магазин']]);
    assertSame(true, $r['data']['updated']);
    assertSame('{=this.NAME} | Магазин', $gateway->seo['ELEMENT_META_TITLE']);
});

test('нет инструмента удаления: ни в одном режиме', function () use ($rw): void {
    [$server] = toolsetServer($rw);
    $tools = json_decode(json_encode($server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')['data']['result']['tools']), true);
    foreach (array_column($tools, 'name') as $name) {
        assertTrue(!preg_match('/delete|remove|drop|destroy/i', $name), $name);
    }
});
