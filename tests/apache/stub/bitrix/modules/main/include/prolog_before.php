<?php

declare(strict_types=1);

// Заглушка Битрикса для проверки .htaccess на настоящем Apache (tests/apache/verify.sh).
// Реализует ровно то, что нужно точкам входа site.ops. Состояние хранится в файлах, потому что
// каждый запрос — отдельный процесс PHP.

namespace Bitrix\Main\Config {
    final class Option
    {
        private static function file(): string
        {
            $dir = $_SERVER['DOCUMENT_ROOT'] . '/stub-state';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            return $dir . '/options.json';
        }

        /** @return array<string,string> */
        private static function all(): array
        {
            return is_file(self::file()) ? (json_decode((string) file_get_contents(self::file()), true) ?: []) : [];
        }

        public static function get(string $module, string $name, string $default = '', string|false $siteId = false): string
        {
            return self::all()[$module . '|' . ($siteId === false ? '' : $siteId) . '|' . $name] ?? $default;
        }

        public static function set(string $module, string $name, string $value = '', string $siteId = ''): void
        {
            $all = self::all();
            $all[$module . '|' . $siteId . '|' . $name] = $value;
            file_put_contents(self::file(), json_encode($all));
        }
    }
}

namespace Bitrix\Main {
    final class Loader
    {
        public static function includeModule(string $module): bool
        {
            return true;
        }
    }

    final class Application
    {
        public static function getConnection(): DB\Connection
        {
            return new DB\Connection();
        }
    }
}

namespace Bitrix\Main\DB {
    final class SqlHelper
    {
        public function forSql(string $value): string
        {
            return addslashes($value);
        }
    }

    final class Result
    {
        public function fetch(): array|false
        {
            return false;
        }
    }

    final class Connection
    {
        public function queryExecute(string $sql): void
        {
        }

        public function query(string $sql): Result
        {
            return new Result();
        }

        public function getAffectedRowsCount(): int
        {
            return 0;
        }

        public function getSqlHelper(): SqlHelper
        {
            return new SqlHelper();
        }
    }
}

namespace Bitrix\Main\Data {
    final class Cache
    {
        private string $file = '';
        /** @var array<string,mixed> */
        private array $vars = [];

        public static function createInstance(): self
        {
            return new self();
        }

        public function forceRewriting(bool $flag): void
        {
        }

        private function path(string $id, string $dir): string
        {
            $base = $_SERVER['DOCUMENT_ROOT'] . '/stub-state/cache' . $dir;
            if (!is_dir($base)) {
                mkdir($base, 0777, true);
            }

            return $base . '/' . md5($id);
        }

        public function initCache(int $ttl, string $id, string $dir): bool
        {
            $this->file = $this->path($id, $dir);
            if (is_file($this->file) && filemtime($this->file) > time() - $ttl) {
                $this->vars = unserialize((string) file_get_contents($this->file), ['allowed_classes' => false]) ?: [];

                return true;
            }

            return false;
        }

        public function startDataCache(int $ttl = 0, string $id = '', string $dir = ''): bool
        {
            if ($id !== '') {
                $this->file = $this->path($id, $dir);
            }

            return true;
        }

        /** @param array<string,mixed> $vars */
        public function endDataCache(array $vars): void
        {
            file_put_contents($this->file, serialize($vars));
        }

        /** @return array<string,mixed> */
        public function getVars(): array
        {
            return $this->vars;
        }
    }
}

namespace {
    if (!defined('SITE_ID')) {
        define('SITE_ID', 's2');
    }

    final class CEventLog
    {
        /** @param array<string,mixed> $fields */
        public static function Add(array $fields): void
        {
        }
    }
}
