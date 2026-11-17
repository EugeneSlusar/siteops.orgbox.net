<?php

declare(strict_types=1);

use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Files\CurlImageDownloader;
use OrgBox\SiteOps\Files\ImageLoader;
use OrgBox\SiteOps\Mcp\McpServer;
use OrgBox\SiteOps\Mcp\ToolException;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use OrgBox\SiteOps\Tools\IblockToolset;
use Tests\Support\ArrayOptionStore;
use Tests\Support\FakeGateway;
use Tests\Support\FakeImageDownloader;
use Tests\Support\MemoryAudit;

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** @return array{0:McpServer,1:FakeGateway,2:MemoryAudit,3:FakeImageDownloader} */
function imageServer(): array
{
    $settings = new Settings(new ArrayOptionStore(['mode' => 'readwrite', 'allowed_iblocks' => '1']));
    $gateway = new FakeGateway();
    $audit = new MemoryAudit();
    $http = new FakeImageDownloader(['https://example.com/img/Alla Photo.png' => base64_decode(PNG_1X1)]);
    $registry = new ToolRegistry();
    (new IblockToolset($gateway, $settings, $audit, new ImageLoader($http)))->registerAll($registry);

    return [new McpServer($registry, true, '1.0.0'), $gateway, $audit, $http];
}

test('картинка: base64 принимается, имя очищается, расширение по реальному типу', function (): void {
    $upload = (new ImageLoader())->load('PREVIEW_PICTURE', ['base64' => PNG_1X1, 'name' => '../../evil.php']);
    assertSame('evil.png', $upload->name);
    assertSame('image/png', $upload->mime);
    assertSame(1, $upload->width);
});

test('картинка: data-URI и пустое имя', function (): void {
    $upload = (new ImageLoader())->load('X', ['base64' => 'data:image/png;base64,' . PNG_1X1]);
    assertSame('image.png', $upload->name);
});

test('картинка: не изображение отклоняется, даже с расширением .jpg', function (): void {
    assertThrows(ToolException::class, fn () => (new ImageLoader())->load('X', ['base64' => base64_encode('<?php echo 1;'), 'name' => 'a.jpg']), 'не JPEG');
});

test('картинка: битый base64, лишние ключи и оба источника сразу', function (): void {
    $loader = new ImageLoader();
    assertThrows(ToolException::class, fn () => $loader->load('X', ['base64' => '***']), 'не декодируется');
    assertThrows(ToolException::class, fn () => $loader->load('X', ['base64' => PNG_1X1, 'path' => '/etc/passwd']), 'неизвестные');
    assertThrows(ToolException::class, fn () => $loader->load('X', ['base64' => PNG_1X1, 'url' => 'https://a.b/c.png']), 'ровно одно');
    assertThrows(ToolException::class, fn () => $loader->load('X', []), 'ровно одно');
});

test('картинка: url без загрузчика недоступен', function (): void {
    assertThrows(ToolException::class, fn () => (new ImageLoader())->load('X', ['url' => 'https://a.b/c.png']), 'недоступна');
});

test('SSRF: http, порт, логин и внутренние адреса запрещены', function (): void {
    $d = new CurlImageDownloader();
    foreach (['http://example.com/a.png', 'https://example.com:8443/a.png', 'https://u:p@example.com/a.png', 'file:///etc/passwd'] as $url) {
        assertThrows(ToolException::class, fn () => $d->download($url, 1000));
    }
    foreach (['https://127.0.0.1/a.png', 'https://10.0.0.5/a.png', 'https://169.254.169.254/latest/meta-data', 'https://[::1]/a.png', 'https://192.168.1.1/a.png'] as $url) {
        assertThrows(ToolException::class, fn () => $d->download($url, 1000), '');
    }
});

test('инструмент: замена фото по url, в ответе только описание файла', function (): void {
    [$server, $gateway, $audit] = imageServer();
    $r = callTool($server, 'element_update', ['id' => 10, 'fields' => ['PREVIEW_PICTURE' => ['url' => 'https://example.com/img/Alla Photo.png']]]);
    assertSame(false, $r['isError']);
    assertSame(true, $r['data']['updated']);
    assertSame('Alla-Photo.png', $r['data']['changes']['PREVIEW_PICTURE']['after']['name']);
    assertSame('Alla-Photo.png', $gateway->elements[10]['preview_picture']['name']);
    assertSame('Alla-Photo.png', $audit->entries[0][1]['changes']['PREVIEW_PICTURE']['after']['name']);
    assertTrue(!str_contains(json_encode($audit->entries), 'iVBOR') && !str_contains($r['text'], 'iVBOR'));
});

test('инструмент: dry_run с картинкой ничего не пишет', function (): void {
    [$server, $gateway] = imageServer();
    $r = callTool($server, 'element_update', ['id' => 10, 'dry_run' => true, 'fields' => ['DETAIL_PICTURE' => ['base64' => PNG_1X1]]]);
    assertSame(true, $r['data']['dry_run']);
    assertSame([], $gateway->calls);
});

test('инструмент: создание элемента с картинкой', function (): void {
    [$server, $gateway] = imageServer();
    $r = callTool($server, 'element_create', ['iblock_id' => 1, 'fields' => ['NAME' => 'Фото', 'PREVIEW_PICTURE' => ['base64' => PNG_1X1]]]);
    assertSame(false, $r['isError']);
    assertSame('image/png', $gateway->elements[$r['data']['id']]['preview_picture']['mime']);
});

test('инструмент: картинка не объект и неверный url', function (): void {
    [$server, $gateway] = imageServer();
    assertContains('объектом', callTool($server, 'element_update', ['id' => 10, 'fields' => ['PREVIEW_PICTURE' => 'x.png']])['text']);
    assertTrue(callTool($server, 'element_update', ['id' => 10, 'fields' => ['PREVIEW_PICTURE' => ['url' => 'https://nope.example/a.png']]])['isError']);
    assertSame([], $gateway->calls);
});
