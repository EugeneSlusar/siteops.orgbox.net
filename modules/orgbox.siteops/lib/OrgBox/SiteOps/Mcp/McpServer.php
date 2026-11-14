<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Mcp;

use JsonException;
use Throwable;

/**
 * Минимальный MCP-сервер (JSON-RPC 2.0, Streamable HTTP без потоков): initialize, ping, tools/list, tools/call.
 */
final class McpServer
{
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private ToolRegistry $tools,
        private bool $writesEnabled,
        private string $serverVersion,
        /** @var (callable(Throwable):void)|null */
        private $errorLogger = null,
    ) {
    }

    /** @return array{status:int, data:mixed} data = null означает пустой ответ 202 */
    public function handle(string $body): array
    {
        try {
            $message = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['status' => 200, 'data' => $this->error(null, -32700, 'Некорректный JSON.')];
        }
        if (!is_array($message)) {
            return ['status' => 200, 'data' => $this->error(null, -32600, 'Некорректный запрос.')];
        }

        if (array_is_list($message) && $message !== []) {
            $responses = [];
            foreach ($message as $item) {
                $response = is_array($item) ? $this->handleOne($item) : $this->error(null, -32600, 'Некорректный запрос.');
                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $responses === [] ? ['status' => 202, 'data' => null] : ['status' => 200, 'data' => $responses];
        }

        $response = $this->handleOne($message);

        return $response === null ? ['status' => 202, 'data' => null] : ['status' => 200, 'data' => $response];
    }

    /** @return array<string,mixed>|null */
    private function handleOne(array $message): ?array
    {
        $hasId = array_key_exists('id', $message);
        $id = $hasId ? $message['id'] : null;
        $method = $message['method'] ?? null;

        if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            // Ответы клиента (result/error без method) сервер не ожидает; игнорируем без ошибки.
            return isset($message['result']) || isset($message['error']) ? null : $this->error($id, -32600, 'Некорректный запрос.');
        }
        if (!$hasId) {
            return null; // уведомление
        }
        if (!is_int($id) && !is_string($id)) {
            return $this->error(null, -32600, 'Некорректный идентификатор запроса.');
        }

        $params = $message['params'] ?? [];
        if (!is_array($params)) {
            return $this->error($id, -32602, 'params должен быть объектом.');
        }

        try {
            return match ($method) {
                'initialize' => $this->result($id, $this->initialize($params)),
                'ping' => $this->result($id, new \stdClass()),
                'tools/list' => $this->result($id, ['tools' => array_map(
                    static fn (Tool $tool): array => $tool->describe(),
                    $this->tools->available($this->writesEnabled),
                )]),
                'tools/call' => $this->toolsCall($id, $params),
                default => $this->error($id, -32601, 'Метод не поддерживается: ' . $method),
            };
        } catch (Throwable $exception) {
            $this->logError($exception);

            return $this->error($id, -32603, 'Внутренняя ошибка сервера.');
        }
    }

    /** @return array<string,mixed> */
    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');
        $version = in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'orgbox-siteops', 'title' => 'orgBox SiteOps', 'version' => $this->serverVersion],
            'instructions' => 'Управление данными сайта на 1С-Битрикс. Режим: '
                . ($this->writesEnabled ? 'чтение и запись (используйте dry_run для проверки изменений).' : 'только чтение.'),
        ];
    }

    /** @return array<string,mixed> */
    private function toolsCall(int|string $id, array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !is_array($arguments)) {
            return $this->error($id, -32602, 'Нужны name и arguments.');
        }
        $tool = $this->tools->find($name, $this->writesEnabled);
        if ($tool === null) {
            return $this->error($id, -32602, 'Неизвестный инструмент: ' . $name);
        }

        try {
            $data = $tool->call($arguments);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $this->encode($data)]],
                'isError' => false,
            ]);
        } catch (ToolException $exception) {
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $exception->getMessage()]],
                'isError' => true,
            ]);
        } catch (Throwable $exception) {
            $this->logError($exception);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => 'Не удалось выполнить инструмент. Подробности в журнале событий Битрикса.']],
                'isError' => true,
            ]);
        }
    }

    private function logError(Throwable $exception): void
    {
        if ($this->errorLogger !== null) {
            ($this->errorLogger)($exception);
        }
    }

    private function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /** @return array<string,mixed> */
    private function result(int|string|null $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string,mixed> */
    private function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
