<?php

declare(strict_types=1);

use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Mcp\McpServer;
use OrgBox\SiteOps\Mcp\Tool;
use OrgBox\SiteOps\Mcp\ToolException;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use Tests\Support\ArrayOptionStore;

function makeServer(bool $writes = false, ?array &$logged = null): McpServer
{
    $registry = new ToolRegistry();
    $registry->register(new Tool('echo', 'Возвращает аргументы', ['type' => 'object'], false, static fn (array $a): array => $a));
    $registry->register(new Tool('boom', 'Ошибка инструмента', ['type' => 'object'], false, static function (): never {
        throw new ToolException('понятная ошибка');
    }));
    $registry->register(new Tool('crash', 'Сбой', ['type' => 'object'], false, static function (): never {
        throw new RuntimeException('секретные детали');
    }));
    $registry->register(new Tool('write', 'Запись', ['type' => 'object'], true, static fn (): array => ['written' => true]));

    $logged = [];

    return new McpServer($registry, $writes, '9.9.9', static function (Throwable $e) use (&$logged): void {
        $logged[] = $e->getMessage();
    });
}

function rpc(McpServer $server, array $message): array
{
    $result = $server->handle(json_encode($message, JSON_UNESCAPED_UNICODE));

    return $result;
}

test('initialize: согласует версию протокола и сообщает возможности', function (): void {
    $r = rpc(makeServer(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
    assertSame(200, $r['status']);
    assertSame('2025-03-26', $r['data']['result']['protocolVersion']);
    assertSame('9.9.9', $r['data']['result']['serverInfo']['version']);
    assertTrue(isset($r['data']['result']['capabilities']['tools']));
});

test('initialize: неизвестная версия получает последнюю поддерживаемую', function (): void {
    $r = rpc(makeServer(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
    assertSame('2025-06-18', $r['data']['result']['protocolVersion']);
});

test('уведомление без id даёт 202 и пустой ответ', function (): void {
    $r = rpc(makeServer(), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    assertSame(202, $r['status']);
    assertSame(null, $r['data']);
});

test('ping возвращает пустой объект', function (): void {
    $r = rpc(makeServer(), ['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping']);
    assertSame('a', $r['data']['id']);
    assertTrue($r['data']['result'] instanceof stdClass);
});

test('tools/list в режиме чтения скрывает инструменты записи', function (): void {
    $names = array_column(rpc(makeServer(false), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['data']['result']['tools'], 'name');
    assertSame(['echo', 'boom', 'crash'], $names);
    $names = array_column(rpc(makeServer(true), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['data']['result']['tools'], 'name');
    assertTrue(in_array('write', $names, true));
});

test('tools/list помечает инструменты записи в annotations', function (): void {
    $tools = rpc(makeServer(true), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['data']['result']['tools'];
    $byName = array_column($tools, null, 'name');
    assertSame(true, $byName['echo']['annotations']['readOnlyHint']);
    assertSame(false, $byName['write']['annotations']['readOnlyHint']);
});

test('tools/call: успешный вызов', function (): void {
    $r = rpc(makeServer(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['x' => 'Привет']]]);
    $result = $r['data']['result'];
    assertSame(false, $result['isError']);
    assertContains('Привет', $result['content'][0]['text']);
});

test('tools/call: ToolException возвращается как isError с понятным текстом', function (): void {
    $r = rpc(makeServer(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'boom']]);
    assertSame(true, $r['data']['result']['isError']);
    assertSame('понятная ошибка', $r['data']['result']['content'][0]['text']);
});

test('tools/call: непредвиденный сбой не раскрывает детали, но попадает в журнал', function (): void {
    $logged = [];
    $server = makeServer(false, $logged);
    $r = rpc($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'crash']]);
    assertSame(true, $r['data']['result']['isError']);
    assertTrue(!str_contains($r['data']['result']['content'][0]['text'], 'секретные'));
    assertSame(['секретные детали'], $logged);
});

test('tools/call: инструмент записи недоступен в режиме чтения', function (): void {
    $r = rpc(makeServer(false), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'write']]);
    assertSame(-32602, $r['data']['error']['code']);
});

test('tools/call: нет name — ошибка параметров', function (): void {
    $r = rpc(makeServer(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => []]);
    assertSame(-32602, $r['data']['error']['code']);
});

test('неизвестный метод — -32601', function (): void {
    $r = rpc(makeServer(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list']);
    assertSame(-32601, $r['data']['error']['code']);
});

test('битый JSON — -32700', function (): void {
    $r = makeServer()->handle('{oops');
    assertSame(-32700, $r['data']['error']['code']);
});

test('не JSON-RPC 2.0 — -32600', function (): void {
    $r = rpc(makeServer(), ['id' => 1, 'method' => 'ping']);
    assertSame(-32600, $r['data']['error']['code']);
});

test('пакетный запрос: ответы только на запросы с id', function (): void {
    $r = makeServer()->handle(json_encode([
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
    ]));
    assertSame(2, count($r['data']));
});

test('пакет из одних уведомлений даёт 202', function (): void {
    $r = makeServer()->handle(json_encode([['jsonrpc' => '2.0', 'method' => 'notifications/initialized']]));
    assertSame(202, $r['status']);
});
