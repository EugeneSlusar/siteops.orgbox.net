# Установка и подключение

Уже распаковали и хотите быстро подключиться? Идите в [QUICKSTART.md](QUICKSTART.md).

Есть две формы установки. Код один и тот же, различается место и хранение настроек.

| | **Папка `site.ops`** (рекомендуется) | Модуль Битрикса |
|---|---|---|
| Куда | `<корень сайта>/site.ops/` (рядом с `bitrix/`, `upload/`) | `/local/modules/orgbox.siteops/` |
| Архив | `site.ops-X.Y.Z.zip` | `orgbox.siteops-X.Y.Z.zip` |
| Настройки и токен | **отдельные для каждого сайта** (по `SITE_ID`) | общие для всей установки Битрикса |
| Подключение по ссылке (OAuth) | **есть** | нет (только токен) |
| Если `local/` и `bitrix/` — симлинки на общую папку | не зависит от них | ставится в общую папку |
| Адрес MCP | `/site.ops/mcp` | `/bitrix/tools/orgbox_siteops/mcp.php` |
| Страница настроек | `/site.ops/admin/` | **Настройки → orgBox: SiteOps** |

Выбирайте `site.ops`, если на одном Битриксе несколько сайтов, `local/` и `bitrix/` у вас симлинки или нужно подключение по ссылке.

## Требования

- 1С-Битрикс с модулем «Информационные блоки» (`iblock`), PHP 8.1 или новее.
- HTTPS на сайте. MCP-адрес нельзя использовать по обычному HTTP.
- Расширения PHP: `curl` и `zip` (для обновлений), `sodium` (проверка подписи).
- Apache с `.htaccess` и `AllowOverride All` (как требует и сам Битрикс). См. ниже про nginx.

## Вариант 1. Папка `site.ops`

1. Скачайте `site.ops-X.Y.Z.zip` со страницы Releases.
2. Распакуйте в **корень сайта** (папка, где лежит `bitrix/`), чтобы получилось `<корень сайта>/site.ops/`:

   ```bash
   cd ~/путь/к/public_html && unzip site.ops-X.Y.Z.zip
   ```

3. Откройте `https://ваш-сайт/site.ops/admin/`, войдите администратором Битрикса.
4. На странице настроек:
   - включите **MCP-сервер**;
   - включите **подключение по ссылке (OAuth)** и проверьте «Адрес сайта» (`https://домен`);
   - в **ID инфоблоков** перечислите нужные. Пусто = чтение любых инфоблоков, но запись запрещена;
   - режим оставьте «только чтение», пока не проверите работу.
5. **Проверьте, что адреса работают, а код закрыт:**

   ```bash
   # должен ответить 401 в JSON (а не 301 и не 404):
   curl -si -X POST -H 'Content-Type: application/json' -d '{}' https://ваш-сайт/site.ops/mcp | head -1
   # должен ответить 403:
   curl -sI https://ваш-сайт/site.ops/lib/OrgBox/SiteOps/Bootstrap.php | head -1
   ```

### Что делает `.htaccess` в `site.ops`

- Всё закрыто, кроме папок `mcp/`, `admin/`, `oauth/` (в каждой свой `.htaccess` с разрешением). Код (`lib/`) и резервные копии (`_updates/`) недоступны из браузера.
- `DirectorySlash Off` и правила `RewriteRule` дают адреса без `.php` и без слэша (`/site.ops/mcp`). Без этого Apache отвечает на запрос папки без слэша редиректом 301, а клиенты MCP теряют тело POST-запроса.
- Заголовок `Authorization` передаётся в PHP (на Apache с CGI/FastCGI он иначе пропадает). Если ваш хостинг всё равно его не передаёт, клиенту можно настроить заголовок `X-SiteOps-Token`.

Это проверено автотестом на настоящем Apache (`tests/apache/verify.sh`, 45 проверок). Если хостинг ограничивает `AllowOverride` (нет `Indexes`/`FileInfo`), страница вернёт 500: попросите разрешить или перенесите правила в конфиг сервера.

**На nginx без Apache `.htaccess` не работает**, закройте служебные файлы и задайте адреса вручную:

```nginx
location ~ ^/site\.ops/(?!(mcp|admin|oauth)(/|$)) { deny all; }
location ~ ^/site\.ops/(mcp|admin)$ { rewrite ^(.*)$ $1/index.php last; }
location ~ ^/site\.ops/oauth/(authorize|token|register|revoke)$ { rewrite ^(.*)$ $1/index.php last; }
```

Секретов в файлах нет (токены и настройки хранятся в базе), но закрыть код всё равно нужно.

**Деплой через git.** Папка `site.ops` не относится к репозиторию сайта и не отслеживается им. Обновляйте её кнопкой в админке (см. [UPDATES.md](UPDATES.md)). Добавьте `/public_html/site.ops/` в `.gitignore` сайта, чтобы это было явно.

## Вариант 2. Модуль Битрикса

1. Скачайте `orgbox.siteops-X.Y.Z.zip` и распакуйте в `/local/modules/` (получится `/local/modules/orgbox.siteops/`).
   Если сайт использует `/bitrix/modules/`, распакуйте туда.
2. **Настройки → Настройки продукта → Модули** → «orgBox: SiteOps» → **Установить**.
3. **Настройки → orgBox: SiteOps**: включите MCP, создайте токен, укажите ID инфоблоков.

Подключение по ссылке (OAuth) в этой форме недоступно. Если `local/` и `bitrix/` у вас симлинки, модуль окажется общим для всех сайтов, а настройки и токен общими для всей установки.

## Подключение клиентов

### По ссылке (OAuth, только `site.ops`)

Это основной способ. Токен копировать не нужно.

**Claude Code**

```bash
claude mcp add --transport http siteops https://ваш-сайт/site.ops/mcp
```

Затем в сессии `/mcp`, выберите `siteops` → **Authenticate**. Claude Code откроет (или покажет) ссылку на страницу согласия вашего сайта.
Войдите администратором Битрикса и нажмите **Разрешить**. Подключение появится в списке.

**claude.ai**: **Settings → Connectors → Add custom connector**, адрес `https://ваш-сайт/site.ops/mcp`. Дальше то же самое: ссылка, вход администратором, «Разрешить».

> Ограничение: сервер реализует OAuth 2.1 по спецификации MCP (код авторизации + PKCE, динамическая регистрация клиента, документы обнаружения),
> но **не проверялся с живыми клиентами Claude**: проверка идёт симулятором клиента по спецификации. Пройдите [чек-лист](VERIFY-ON-SITE.md) и сообщите, если клиент не подключается.

Подключения видны на странице настроек («Подключения (OAuth)») и отзываются кнопкой. Токен доступа живёт 1 час и обновляется сам, токен обновления 30 суток.

**Почему не Device Flow.** Device Flow (как в `gh auth login`) нужен устройствам без браузера. Клиенты MCP используют код авторизации с PKCE:
пользовательский опыт тот же (ссылка → вход → «Разрешить»), но протокол другой.

**Необязательно: корневой `.well-known`.** Если клиент не находит документы OAuth, добавьте в `.htaccess` корня сайта (до правил Битрикса) фрагмент из
[`standalone/root-well-known.htaccess`](../standalone/root-well-known.htaccess):

```apache
RewriteEngine On
RewriteRule ^\.well-known/oauth-authorization-server(/site\.ops/oauth)?/?$ site.ops/oauth/.well-known/oauth-authorization-server/index.php [L]
RewriteRule ^\.well-known/openid-configuration(/site\.ops/oauth)?/?$ site.ops/oauth/.well-known/openid-configuration/index.php [L]
RewriteRule ^\.well-known/oauth-protected-resource(/site\.ops/mcp)?/?$ site.ops/oauth/.well-known/oauth-protected-resource/index.php [L]
```

### По токену (любая форма установки)

Создайте токен на странице настроек (показывается один раз) и передавайте его в заголовке `Authorization: Bearer sop_…`.

**Claude Code**

```bash
claude mcp add --transport http siteops https://ваш-сайт/site.ops/mcp \
  --header "Authorization: Bearer sop_ВАШ_ТОКЕН"
```

**Claude Desktop** (через мост `mcp-remote`, нужен Node.js): в `claude_desktop_config.json`

```json
{
  "mcpServers": {
    "siteops": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://ваш-сайт/site.ops/mcp",
               "--header", "Authorization: Bearer sop_ВАШ_ТОКЕН"]
    }
  }
}
```

## Проверка

```bash
curl -s -X POST https://ваш-сайт/site.ops/mcp \
  -H 'Content-Type: application/json' -H 'Authorization: Bearer sop_ВАШ_ТОКЕН' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Ожидается JSON со списком инструментов. Затем пройдите [чек-лист проверки на сайте](VERIFY-ON-SITE.md).

## Несколько сайтов на одном Битриксе

Настройте каждый сайт отдельно: у каждого свой `site.ops/`, свой токен, свои OAuth-подключения, список инфоблоков и режим.
Токен и подключения сайта `s1` не действуют на сайте `s2`, даже при общей базе.

## Удаление

- `site.ops`: удалите папку `<корень сайта>/site.ops/`. Настройки и таблицы OAuth (`orgbox_siteops_oauth_*`) остаются в базе.
- Модуль: **Настройки → Настройки продукта → Модули** → «orgBox: SiteOps» → **Удалить**.
