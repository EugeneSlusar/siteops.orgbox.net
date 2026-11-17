# Установка и подключение

Уже распаковали и хотите быстро подключиться? Идите в [QUICKSTART.md](QUICKSTART.md).

Есть две формы установки. Код один и тот же, различается место и хранение настроек.

| | **Папка `site.ops`** (рекомендуется) | Модуль Битрикса |
|---|---|---|
| Куда | `<корень сайта>/site.ops/` (рядом с `bitrix/`, `upload/`) | `/local/modules/orgbox.siteops/` |
| Архив | `site.ops-X.Y.Z.zip` | `orgbox.siteops-X.Y.Z.zip` |
| Настройки и токен | **отдельные для каждого сайта**: файл `site.ops/_data/settings.php` | общие для всей установки Битрикса |
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
location ~ ^/site\.ops/oauth/(authorize|verify|token|register|device|revoke)$ { rewrite ^(.*)$ $1/index.php last; }
```

**Где лежат настройки.** В файле `site.ops/_data/settings.php` внутри папки (у каждого сайта свой). В нём хэш токена (SHA-256, не сам токен) и, если вы их ввели, токен GitHub для обновлений.
Папка `_data/` закрыта `.htaccess`, а сам файл начинается со строки `<?php ... exit; ?>`: даже если веб-сервер выполнит его как скрипт, ничего не покажет. Резервные копии обновлений настройки не включают, обновление их не трогает.
Закрыть код и `_data` всё равно нужно. Страница настроек показывает путь к файлу и можно ли в него писать; если нет, выдайте веб-серверу права на папку `site.ops/_data`.

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

**Подключение по коду (Device Flow, RFC 8628)** — как `gh auth login`. Для клиентов без браузера и облачных сессий, где нельзя принять ответ на `localhost`:

1. Клиент делает `POST https://ваш-сайт/site.ops/oauth/device` (поле `client_name` необязательно) и получает `device_code` (секрет), `user_code` вида `ABCD-2345`,
   ссылку `verification_uri_complete`, `expires_in` (15 минут) и `interval` (5 секунд).
2. Клиент показывает вам ссылку и код и раз в `interval` секунд делает `POST /site.ops/oauth/token` с `grant_type=urn:ietf:params:oauth:grant-type:device_code`,
   `device_code` и `client_id` (его вернул первый запрос). Пока вы не подтвердили, ответ `authorization_pending`; при частом опросе `slow_down`.
3. Вы открываете ссылку, входите администратором Битрикса, сверяете код и нажимаете **Разрешить**. Следующий опрос вернёт токены (те же, что и при обычном подключении).

Готовый клиент: `python3 tools/device_login.py login https://ваш-сайт`, затем `python3 tools/device_login.py token` печатает действующий токен доступа (при необходимости обновляет).
Файл с токенами создаётся с правами 600.

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

- `site.ops`: удалите папку `<корень сайта>/site.ops/`. Настройки удаляются вместе с папкой (`_data`). Таблицы OAuth (`orgbox_siteops_oauth_*`) остаются в базе.
- Модуль: **Настройки → Настройки продукта → Модули** → «orgBox: SiteOps» → **Удалить**.
