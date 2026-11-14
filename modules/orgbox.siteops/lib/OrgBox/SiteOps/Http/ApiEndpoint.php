<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Http;

use JsonException;
use OrgBox\SiteOps\Auth\AuthException;
use OrgBox\SiteOps\Auth\HmacAuthenticator;
use OrgBox\SiteOps\Mcp\ToolException;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use OrgBox\SiteOps\Config\Settings;
use Throwable;

/**
 * Старый подписанный JSON API (HMAC). Сохранён для совместимости с orgbox.siteops 0.1:
 * операции seo.templates.get / seo.templates.update, а также tools.list и tools.call для всех инструментов.
 */
final class ApiEndpoint
{
    public function __construct(
        private Settings $settings,
        private RequestGuard $guard,
        private HmacAuthenticator $auth,
        private ToolRegistry $tools,
        private string $uri = '/bitrix/tools/orgbox_siteops/api.php',
    ) {
    }

    public function handle(string $method, array $server, string $body): HttpResponse
    {
        $common = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (strtoupper($method) !== 'POST') {
            return HttpResponse::json(405, ['ok' => false, 'error' => 'Допускается только POST.'], $common + ['Allow' => 'POST']);
        }

        try {
            $this->guard->assertAllowed($server);
            $this->auth->assertValid('POST', $this->uri, $body, $server);
            $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($request)) {
                throw new ToolException('Тело запроса должно быть JSON-объектом.');
            }

            return HttpResponse::json(200, ['ok' => true, 'result' => $this->dispatch($request)], $common);
        } catch (AuthException $exception) {
            return HttpResponse::json($exception->httpStatus(), ['ok' => false, 'error' => $exception->getMessage()], $common);
        } catch (JsonException) {
            return HttpResponse::json(400, ['ok' => false, 'error' => 'Некорректный JSON.'], $common);
        } catch (ToolException $exception) {
            return HttpResponse::json(400, ['ok' => false, 'error' => $exception->getMessage()], $common);
        } catch (Throwable) {
            return HttpResponse::json(500, ['ok' => false, 'error' => 'Внутренняя ошибка.'], $common);
        }
    }

    /** @return mixed */
    private function dispatch(array $request): mixed
    {
        $operation = (string) ($request['operation'] ?? '');
        $writes = $this->settings->writesEnabled();

        if ($operation === 'tools.list') {
            return ['tools' => array_map(
                static fn ($tool): array => $tool->describe(),
                $this->tools->available($writes),
            )];
        }

        $alias = [
            'seo.templates.get' => 'seo_templates_get',
            'seo.templates.update' => 'seo_templates_update',
        ];
        if ($operation === 'tools.call') {
            $name = (string) ($request['name'] ?? '');
            $arguments = (array) ($request['arguments'] ?? []);
        } elseif (isset($alias[$operation])) {
            $name = $alias[$operation];
            $arguments = array_filter(['templates' => $request['templates'] ?? null], static fn ($v) => $v !== null);
            if (isset($request['iblock_id'])) {
                $arguments['iblock_id'] = $request['iblock_id'];
            }
        } else {
            throw new ToolException('Неизвестная операция.');
        }

        $tool = $this->tools->find($name, $writes) ?? throw new ToolException('Неизвестный инструмент или запись отключена.');

        return $tool->call($arguments);
    }
}
