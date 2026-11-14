# Установка и подключение

## Требования

- 1С-Битрикс с модулем «Информационные блоки» (`iblock`), PHP 8.1 или новее.
- HTTPS на сайте. MCP-адрес не должен работать по обычному HTTP: токен передаётся в заголовке.
- Расширения PHP: `curl` и `zip` (для обновлений), `sodium` (проверка подписи).

## Установка модуля

1. Скачайте `orgbox.siteops-X.Y.Z.zip` со страницы Releases и распакуйте в `/local/modules/`.
   Должна получиться папка `/local/modules/orgbox.siteops/` (внутри `include.php`, `install/`, `lib/`).
   Если сайт использует `/bitrix/modules/`, распакуйте туда.
2. **Настройки → Настройки продукта → Модули** → «orgBox: SiteOps» → **Установить**.
   Установщик копирует страницу настроек в `/bitrix/admin/` и точки входа в `/bitrix/tools/orgbox_siteops/`.
3. **Настройки → orgBox: SiteOps**:
   - включите **MCP-сервер**;
   - нажмите **Создать новый токен** и сразу скопируйте его (повторно он не показывается);
   - в **ID инфоблоков** перечислите нужные. Пусто = чтение любых инфоблоков, но запись при этом запрещена;
   - режим оставьте «только чтение», пока не проверите работу.

### Если `local/` и `bitrix/` у вас симлинки на общую папку (несколько сайтов на одном ядре)

Модуль окажется общим для всех сайтов, но **включается и настраивается отдельно** на каждом (настройки в базе сайта).
Если сайты делят и базу, настройки тоже общие, и токен один на всех.
Файлы в такие папки не доставляйте через git: распаковывайте архив вручную или обновляйте кнопкой в админке.

## Заголовок Authorization на Apache/FastCGI

Некоторые хостинги не передают заголовок `Authorization` в PHP. Есть два решения.

1. В `.htaccess` сайта (в корне) до правил Битрикса:

   ```apache
   RewriteEngine On
   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
   ```

2. Или передавайте токен в заголовке **`X-SiteOps-Token: sop_…`**. Модуль принимает оба варианта.

## Подключение клиентов

Адрес: `https://ваш-сайт/bitrix/tools/orgbox_siteops/mcp.php`

**Claude Code**

```bash
claude mcp add --transport http siteops https://ваш-сайт/bitrix/tools/orgbox_siteops/mcp.php \
  --header "Authorization: Bearer sop_ВАШ_ТОКЕН"
```

**Claude Desktop** (через мост `mcp-remote`, нужен Node.js на вашем компьютере): в `claude_desktop_config.json`

```json
{
  "mcpServers": {
    "siteops": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://ваш-сайт/bitrix/tools/orgbox_siteops/mcp.php",
               "--header", "Authorization: Bearer sop_ВАШ_ТОКЕН"]
    }
  }
}
```

**claude.ai (пользовательский коннектор)**: сервер использует токен в заголовке и **не реализует OAuth**,
поэтому подключение через интерфейс claude.ai может не заработать. Это ограничение текущей версии.

## Проверка

```bash
curl -s -X POST https://ваш-сайт/bitrix/tools/orgbox_siteops/mcp.php \
  -H 'Content-Type: application/json' -H 'Authorization: Bearer sop_ВАШ_ТОКЕН' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Ожидается JSON со списком инструментов. Затем пройдите [чек-лист проверки на сайте](VERIFY-ON-SITE.md).

## Удаление

**Настройки → Настройки продукта → Модули** → «orgBox: SiteOps» → **Удалить**. Настройки (включая хэш токена) удаляются; журнал событий остаётся.
