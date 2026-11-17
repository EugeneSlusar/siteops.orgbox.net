#!/usr/bin/env python3
"""Вход в orgBox SiteOps по коду (OAuth Device Flow, RFC 8628), без браузера на этой машине.

  python3 tools/device_login.py login https://ваш-сайт   # показывает ссылку и код, ждёт подтверждения
  python3 tools/device_login.py token                    # печатает действующий токен доступа (при необходимости обновляет)

Токены хранятся в файле с правами 600 (по умолчанию ~/.config/siteops/token.json, путь можно задать в SITEOPS_TOKEN_FILE).
Только стандартная библиотека Python 3.8+.
"""
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

GRANT = "urn:ietf:params:oauth:grant-type:device_code"
PATH = os.environ.get("SITEOPS_TOKEN_FILE") or os.path.expanduser("~/.config/siteops/token.json")


def post(url, fields):
    data = urllib.parse.urlencode(fields).encode()
    request = urllib.request.Request(url, data=data, headers={"Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        try:
            return error.code, json.load(error)
        except ValueError:
            return error.code, {"error": "http_%d" % error.code}


def save(state):
    os.makedirs(os.path.dirname(PATH), mode=0o700, exist_ok=True)
    fd = os.open(PATH, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as handle:
        json.dump(state, handle)
    os.chmod(PATH, 0o600)


def store_tokens(site, client_id, tokens):
    save({"site": site, "client_id": client_id, "access_token": tokens["access_token"],
          "refresh_token": tokens["refresh_token"], "expires_at": int(time.time()) + int(tokens["expires_in"]) - 60})


def login(site):
    site = site.rstrip("/")
    status, start = post(site + "/site.ops/oauth/device/", {"client_name": "orgBox SiteOps CLI"})
    if status != 200:
        sys.exit("Не удалось начать подключение: %s" % start)
    print("Откройте ссылку и подтвердите код под вашей учётной записью администратора:\n")
    print("  " + start["verification_uri_complete"])
    print("  Код: " + start["user_code"] + "\n")
    interval = int(start.get("interval", 5))
    deadline = time.time() + int(start["expires_in"])
    while time.time() < deadline:
        time.sleep(interval)
        status, reply = post(site + "/site.ops/oauth/token/", {
            "grant_type": GRANT, "device_code": start["device_code"], "client_id": start["client_id"]})
        error = reply.get("error")
        if status == 200:
            store_tokens(site, start["client_id"], reply)
            print("Готово. Токен сохранён в " + PATH)
            return
        if error == "authorization_pending":
            continue
        if error == "slow_down":
            interval += 5
            continue
        sys.exit("Подключение не выполнено: %s (%s)" % (error, reply.get("error_description", "")))
    sys.exit("Время на подтверждение вышло. Запустите вход заново.")


def token():
    try:
        with open(PATH) as handle:
            state = json.load(handle)
    except OSError:
        sys.exit("Нет сохранённого токена. Выполните: device_login.py login https://ваш-сайт")
    if state["expires_at"] <= time.time():
        status, reply = post(state["site"] + "/site.ops/oauth/token/", {
            "grant_type": "refresh_token", "refresh_token": state["refresh_token"], "client_id": state["client_id"]})
        if status != 200:
            sys.exit("Не удалось обновить токен (%s). Выполните вход заново." % reply.get("error"))
        store_tokens(state["site"], state["client_id"], reply)
        state["access_token"] = reply["access_token"]
    print(state["access_token"])


if __name__ == "__main__":
    if len(sys.argv) == 3 and sys.argv[1] == "login":
        login(sys.argv[2])
    elif len(sys.argv) == 2 and sys.argv[1] == "token":
        token()
    else:
        sys.exit(__doc__)
