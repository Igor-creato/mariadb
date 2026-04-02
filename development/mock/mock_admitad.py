"""
Mock Admitad Publisher API v5 — чистый маппер из файла вебхуков.

ПРИНЦИП: файл — единственный источник истины.
Мок читает файл, маппит поля в формат API, ничего не генерирует.

Формат файла (каждая строка — один постбэк):
  click_id=UUID&uniq_id=ЧИСЛОВОЙ&order_number=NUM&offer_name=NAME
  &order_status=STATUS&sum_order=NUM&comission=NUM&user_id=NUM
  &action_date=YYYY-MM-DD HH:MM:SS&click_date=YYYY-MM-DD HH:MM:SS
  &status_updated=YYYY-MM-DD HH:MM:SS

Маппинг файл → API:
  uniq_id      → action_id   (числовой, ключ для lost order claims)
  click_id     → subid1      (UUID, ключ матчинга кэшбэк-сервиса)
  user_id      → subid2      (WP user ID, фильтрация)
  order_number → order_id    (номер заказа рекламодателя)
  order_status → status      (маппинг: waiting→pending, completed→approved, ...)
  comission    → payment     (комиссия вебмастера)
  sum_order    → cart        (сумма заказа)
  action_date  → action_date (из файла, без генерации)
  click_time   → click_date  (из файла, fallback click_date)
  website_id   → website_id  (из файла, числовой)
  status_updated → status_updated (из файла, без генерации)

Документация Admitad Publisher API:
  https://developers.admitad.com/knowledge-base/article/publisher-reports_1

Запуск:
  pip install flask
  python mock_admitad.py [port]

Панель: http://localhost:5000/
"""

import json
import uuid
import random
import os
import sys
from datetime import datetime, timedelta
from flask import Flask, request, jsonify, render_template_string

app = Flask(__name__)

# ═══════════════════════════════════════════════════════════
# Хранилище
# ═══════════════════════════════════════════════════════════

programs = {
    "program_1": {
        "id": 1001,
        "name": "Партнёр 1",
        "actions": [],
        "loaded": False,
    },
    "program_2": {
        "id": 1002,
        "name": "Партнёр 2",
        "actions": [],
        "loaded": False,
    },
}

discrepancies = {
    "status_mismatch": {
        "enabled": False,
        "description": "Расхождения по статусам",
        "detail": "У ~N% записей статус в API будет отличаться от webhook",
        "pct": 15,
    },
    "commission_mismatch": {
        "enabled": False,
        "description": "Расхождения по суммам комиссий",
        "detail": "У ~N% записей комиссия в API будет отличаться (±5-30%)",
        "pct": 10,
    },
    "extra_records": {
        "enabled": False,
        "description": "Записи есть в API, но нет вебхука",
        "detail": "В API появятся N записей без соответствующего вебхука",
        "count": 50,
    },
    "missing_records": {
        "enabled": False,
        "description": "Записи есть в вебхуке, но нет в API",
        "detail": "~N% записей будут скрыты из API (имитация задержки Admitad)",
        "pct": 5,
    },
}

# Webhook status → Admitad API status
# Документация: status = pending / approved / declined / approved_but_stalled
STATUS_MAP = {
    "waiting":   "pending",
    "pending":   "pending",
    "completed": "approved",
    "approved":  "approved",
    "rejected":  "declined",
    "declined":  "declined",
    "open":      "pending",
    "hold":      "pending",
    "new":       "pending",
}


# ═══════════════════════════════════════════════════════════
# Парсинг файла вебхуков — чистый маппинг, без генерации
# ═══════════════════════════════════════════════════════════

def parse_webhook_line(line: str, program_id: int) -> dict | None:
    """Парсит строку вебхука и маппит поля в формат Admitad API.

    Все значения берутся ТОЛЬКО из файла. Мок ничего не генерирует.
    """
    line = line.strip()
    if not line:
        return None

    params = {}
    for pair in line.split("&"):
        if "=" not in pair:
            continue
        key, val = pair.split("=", 1)
        params[key] = val

    # Обязательные поля
    click_id = params.get("click_id", "")
    uniq_id = params.get("uniq_id", "")
    if not click_id or not uniq_id:
        return None

    # Поля из файла
    user_id = params.get("user_id", "")
    order_number = params.get("order_number", "")
    offer_name = params.get("offer_name", "")
    webhook_status = params.get("order_status", "pending")
    sum_order = float(params.get("sum_order", 0))
    commission = float(params.get("comission", 0))
    action_date_str = params.get("action_date", "")
    click_date_str = params.get("click_time", "") or params.get("click_date", "")
    status_updated_str = params.get("status_updated", "")
    website_id = params.get("website_id", "")

    # Маппинг статуса
    api_status = STATUS_MAP.get(webhook_status, "pending")

    # Парсинг дат из файла (для внутренней фильтрации)
    action_time = _parse_dt(action_date_str)
    click_time = _parse_dt(click_date_str)
    status_updated_time = _parse_dt(status_updated_str)

    # action_id = uniq_id из файла (числовой, как в реальном Admitad)
    try:
        action_id = int(uniq_id)
    except ValueError:
        action_id = 0

    return {
        # Внутренние поля для фильтрации и debug (не отдаются клиенту)
        "_webhook_click_id": click_id,
        "_webhook_order_number": order_number,
        "_webhook_status": webhook_status,
        "_webhook_commission": commission,
        "_webhook_uniq_id": uniq_id,
        "_webhook_user_id": user_id,
        "_action_time": action_time,
        "_status_updated": status_updated_time,

        # ═══════════════════════════════════════════════════
        # Поля Admitad Publisher API /statistics/actions/
        # Всё из файла, ничего сгенерированного
        # ═══════════════════════════════════════════════════

        "action_id": action_id,
        "order_id": order_number,
        "status": api_status,
        "payment": commission,
        "cart": sum_order,

        "subid": click_id,
        "subid1": click_id,
        "subid2": user_id,
        "subid3": "",
        "subid4": "",

        "advcampaign_name": offer_name,
        "advcampaign_id": program_id,
        "website_name": "cashback-site",
        "website_id": int(website_id) if website_id.isdigit() else website_id,

        "action_date": action_date_str,
        "click_date": click_date_str,
        "closing_date": "",
        "status_updated": status_updated_str,

        "action": offer_name,
        "action_type": "sale",
        "currency": "RUB",
        "conversion_time": 0,
        "keyword": None,
        "comment": None,
        "click_user_referer": None,
        "tariff_id": 0,
        "banner_id": 0,
        "processed": 1 if api_status == "approved" else 0,
        "paid": 0,
        "promocode": None,
        "positions": [],
    }


def _parse_dt(s: str) -> datetime | None:
    """Парсит дату из файла или API-параметра. Поддерживает Unix timestamps."""
    if not s:
        return None
    s = s.strip()
    # Unix timestamp (10-13 цифр)
    if s.isdigit() and len(s) >= 10:
        ts = int(s)
        if len(s) == 13:
            ts = ts // 1000
        return datetime.fromtimestamp(ts)
    for fmt in ("%Y-%m-%d %H:%M:%S", "%d.%m.%Y %H:%M:%S", "%d.%m.%Y", "%Y-%m-%d"):
        try:
            return datetime.strptime(s, fmt)
        except ValueError:
            continue
    return None


def load_file_data(filepath: str, program_key: str) -> int:
    """Загружает файл вебхуков и маппит в actions."""
    program = programs[program_key]
    program["actions"] = []

    with open(filepath, "r", encoding="utf-8") as f:
        for line in f:
            action = parse_webhook_line(line, program["id"])
            if action:
                program["actions"].append(action)

    program["loaded"] = True
    return len(program["actions"])


# ═══════════════════════════════════════════════════════════
# Расхождения (для тестирования валидации)
# ═══════════════════════════════════════════════════════════

SHOPS = [
    "Яндекс.Маркет", "Ozon", "Wildberries", "Lamoda", "DNS",
    "МВидео", "Золотое Яблоко", "Летуаль", "Skillbox", "Skyeng",
]


def apply_discrepancies(actions: list[dict], program_id: int) -> list[dict]:
    """Возвращает копию actions с наложенными расхождениями.

    Модифицирует КОПИИ, оригиналы остаются нетронутыми.
    Используется только при включённых расхождениях в панели.
    """
    if not any(d["enabled"] for d in discrepancies.values()):
        return actions  # Без расхождений — возвращаем как есть

    rng = random.Random(program_id)
    result = [{**a} for a in actions]  # Shallow copy каждого dict

    # 1. Скрытие записей (есть в webhook, нет в API)
    if discrepancies["missing_records"]["enabled"]:
        pct = discrepancies["missing_records"]["pct"]
        result = [a for a in result if rng.randint(1, 100) > pct]

    # 2. Расхождения по статусам
    if discrepancies["status_mismatch"]["enabled"]:
        pct = discrepancies["status_mismatch"]["pct"]
        for a in result:
            if rng.randint(1, 100) <= pct:
                old = a["status"]
                alts = [s for s in ["pending", "approved", "declined"] if s != old]
                a["status"] = rng.choice(alts)

    # 3. Расхождения по комиссиям
    if discrepancies["commission_mismatch"]["enabled"]:
        pct = discrepancies["commission_mismatch"]["pct"]
        for a in result:
            if rng.randint(1, 100) <= pct:
                original = float(a["payment"])
                factor = 1.0 + rng.uniform(-0.30, 0.30)
                if abs(factor - 1.0) < 0.05:
                    factor = 1.0 + rng.choice([-0.15, 0.15])
                a["payment"] = round(original * factor, 2)

    # 4. Дополнительные записи (есть в API, нет в webhook)
    if discrepancies["extra_records"]["enabled"]:
        count = discrepancies["extra_records"]["count"]
        now = datetime.now()
        for i in range(count):
            days_ago = rng.randint(1, 30)
            action_time = now - timedelta(days=days_ago, hours=rng.randint(0, 23))
            click_time = action_time - timedelta(minutes=rng.randint(1, 60))
            commission = rng.choice([50, 100, 200, 350, 500, 750, 1000])
            status = rng.choice(["pending", "approved", "declined"])
            fake_click_id = uuid.uuid4().hex

            extra = {
                "_webhook_click_id": "",
                "_webhook_order_number": "",
                "_webhook_status": "",
                "_webhook_commission": 0,
                "_webhook_uniq_id": "",
                "_webhook_user_id": "",
                "_action_time": action_time,
                "_status_updated": action_time,

                "action_id": 9000000 + i,
                "order_id": str(9000000 + i),
                "status": status,
                "payment": commission,
                "cart": commission * 10,
                "subid": fake_click_id,
                "subid1": fake_click_id,
                "subid2": str(rng.randint(1, 12)),
                "subid3": "",
                "subid4": "",
                "advcampaign_name": rng.choice(SHOPS),
                "advcampaign_id": program_id,
                "website_name": "cashback-site",
                "action_date": action_time.strftime("%Y-%m-%d %H:%M:%S"),
                "action": rng.choice(SHOPS),
                "click_date": click_time.strftime("%Y-%m-%d %H:%M:%S"),
                "closing_date": "",
                "status_updated": action_time.strftime("%Y-%m-%d %H:%M:%S"),
                "action_type": "sale",
                "currency": "RUB",
                "conversion_time": 0,
                "keyword": None,
                "comment": None,
                "click_user_referer": None,
                "tariff_id": 0,
                "banner_id": 0,
                "processed": 0,
                "paid": 0,
                "promocode": None,
                "positions": [],
            }
            result.append(extra)

    return result


def get_public_action(action: dict) -> dict:
    """Убирает внутренние поля _* перед отдачей клиенту."""
    return {k: v for k, v in action.items() if not k.startswith("_")}


# ═══════════════════════════════════════════════════════════
# Фильтрация по датам
# ═══════════════════════════════════════════════════════════

def filter_by_dates(actions: list[dict], args: dict) -> list[dict]:
    """Фильтрация по date_start/end и status_updated_start/end.

    Даты берутся из _action_time / _status_updated (уже распарсены из файла).
    """
    result = actions

    ds = _parse_dt(args.get("date_start", ""))
    de = _parse_dt(args.get("date_end", ""))
    if ds or de:
        filtered = []
        for a in result:
            ad = a.get("_action_time")
            if ad is None:
                filtered.append(a)
                continue
            if ds and ad < ds:
                continue
            if de and ad > de.replace(hour=23, minute=59, second=59):
                continue
            filtered.append(a)
        result = filtered

    su_start = _parse_dt(args.get("status_updated_start", ""))
    su_end = _parse_dt(args.get("status_updated_end", ""))
    if su_start or su_end:
        filtered = []
        for a in result:
            su = a.get("_status_updated")
            if su is None:
                filtered.append(a)
                continue
            if su_start and su < su_start:
                continue
            if su_end and su > su_end.replace(hour=23, minute=59, second=59):
                continue
            filtered.append(a)
        result = filtered

    return result


# ═══════════════════════════════════════════════════════════
# Auth middleware
# ═══════════════════════════════════════════════════════════

@app.before_request
def check_auth():
    if request.path.startswith("/statistics/"):
        auth = request.headers.get("Authorization", "")
        if not auth.startswith("Bearer "):
            return jsonify({"error": "unauthorized", "message": "Bearer token required"}), 401


# ═══════════════════════════════════════════════════════════
# OAuth2 Token endpoint
# ═══════════════════════════════════════════════════════════

@app.route("/token/", methods=["POST"])
def token():
    """POST /token/ — OAuth2 client_credentials grant."""
    auth = request.headers.get("Authorization", "")
    if not auth.startswith("Basic "):
        return jsonify({"error": "invalid_client", "error_description": "Basic auth required"}), 401

    return jsonify({
        "access_token": "mock-token-" + uuid.uuid4().hex[:16],
        "token_type": "bearer",
        "expires_in": 3600,
        "scope": "statistics",
        "username": "mock_publisher",
        "first_name": "Test",
        "last_name": "User",
        "language": "ru",
        "id": 12345,
    })


# ═══════════════════════════════════════════════════════════
# API: /statistics/actions/
# ═══════════════════════════════════════════════════════════

@app.route("/statistics/actions/")
def get_actions():
    """
    GET /statistics/actions/

    Параметры (документация Admitad Publisher API):
      - date_start, date_end          — dd.mm.YYYY
      - status_updated_start/end      — dd.mm.YYYY HH:MM:SS
      - campaign                      — ID программы
      - website                       — ID площадки
      - subid, subid1..4              — фильтр по SubID
      - action_id                     — фильтр по Payment ID
      - status                        — pending/approved/declined
      - limit, offset                 — пагинация
      - order_by                      — datetime
      - total                         — 1 = агрегат
    """
    campaign = request.args.get("campaign", type=int)
    limit = min(request.args.get("limit", 500, type=int), 500)
    offset = request.args.get("offset", 0, type=int)
    action_id_filter = request.args.get("action_id", type=int)

    # Собираем actions из всех программ
    all_actions = []
    for key, prog in programs.items():
        if not prog["loaded"]:
            continue
        if campaign and prog["id"] != campaign:
            continue
        actions = apply_discrepancies(prog["actions"], prog["id"])
        all_actions.extend(actions)

    # Фильтр по action_id
    if action_id_filter is not None:
        all_actions = [a for a in all_actions if a.get("action_id") == action_id_filter]

    # Фильтр по subid полям
    for subid_key in ["subid", "subid1", "subid2", "subid3", "subid4"]:
        val = request.args.get(subid_key)
        if val is not None:
            all_actions = [a for a in all_actions if str(a.get(subid_key, "")) == val]

    # Фильтр по статусу
    status_filter = request.args.get("status")
    if status_filter is not None:
        all_actions = [a for a in all_actions if a.get("status") == status_filter]

    # Фильтрация по датам
    all_actions = filter_by_dates(all_actions, dict(request.args))

    # total=1 — агрегированные данные
    total_flag = request.args.get("total", "0")
    if total_flag == "1":
        by_currency = {}
        for a in all_actions:
            cur = a.get("currency", "RUB")
            if cur not in by_currency:
                by_currency[cur] = {"currency": cur, "payment_sum": 0.0, "cart": 0.0}
            by_currency[cur]["payment_sum"] += float(a.get("payment", 0))
            by_currency[cur]["cart"] += float(a.get("cart", 0) or 0)
        return jsonify(list(by_currency.values()))

    # Сортировка
    order_by = request.args.get("order_by", "datetime")
    reverse = True
    if order_by.startswith("-"):
        reverse = False
        order_by = order_by[1:]
    sort_key = "action_date" if order_by in ("date", "datetime", "action_date") else order_by
    all_actions.sort(key=lambda x: str(x.get(sort_key, "")), reverse=reverse)

    total = len(all_actions)
    page = all_actions[offset:offset + limit]

    return jsonify({
        "results": [get_public_action(a) for a in page],
        "_meta": {
            "count": total,
            "limit": limit,
            "offset": offset,
        },
    })


@app.route("/statistics/actions/<int:action_id>/")
def get_action_detail(action_id):
    """Одно действие по action_id."""
    for prog in programs.values():
        if not prog["loaded"]:
            continue
        actions = apply_discrepancies(prog["actions"], prog["id"])
        for a in actions:
            if a.get("action_id") == action_id:
                return jsonify(get_public_action(a))

    return jsonify({"error": "not_found", "message": f"Action {action_id} not found"}), 404


# ═══════════════════════════════════════════════════════════
# Управление
# ═══════════════════════════════════════════════════════════

@app.route("/manage/data/")
def manage_data():
    info = {}
    for key, prog in programs.items():
        info[key] = {
            "id": prog["id"],
            "name": prog["name"],
            "loaded": prog["loaded"],
            "actions_count": len(prog["actions"]),
        }
    return jsonify({
        "programs": info,
        "discrepancies": {
            k: {"enabled": v["enabled"], "description": v["description"]}
            for k, v in discrepancies.items()
        },
    })


@app.route("/manage/load/", methods=["POST"])
def manage_load():
    if request.is_json:
        data = request.get_json()
        program_key = data.get("program", "program_1")
        filepath = data.get("file", "")
        if not os.path.exists(filepath):
            return jsonify({"error": f"File not found: {filepath}"}), 400
        count = load_file_data(filepath, program_key)
        return jsonify({"loaded": count, "program": program_key})

    program_key = request.form.get("program", "program_1")
    file = request.files.get("file")
    if not file:
        return jsonify({"error": "No file provided"}), 400

    tmp_path = f"/tmp/mock_upload_{program_key}.txt"
    file.save(tmp_path)
    count = load_file_data(tmp_path, program_key)
    return jsonify({"loaded": count, "program": program_key})


@app.route("/manage/discrepancy/", methods=["POST"])
def manage_discrepancy():
    data = request.get_json()
    dtype = data.get("type")
    if dtype not in discrepancies:
        return jsonify({"error": f"Unknown type: {dtype}"}), 400

    if "enabled" in data:
        discrepancies[dtype]["enabled"] = bool(data["enabled"])
    if "pct" in data:
        discrepancies[dtype]["pct"] = int(data["pct"])
    if "count" in data:
        discrepancies[dtype]["count"] = int(data["count"])

    return jsonify({
        "ok": True,
        "discrepancies": {k: {"enabled": v["enabled"]} for k, v in discrepancies.items()},
    })


# ═══════════════════════════════════════════════════════════
# Debug
# ═══════════════════════════════════════════════════════════

@app.route("/manage/debug/matching/")
def debug_matching():
    """Маппинг полей для проверки reconciliation."""
    user_id_filter = request.args.get("user_id")
    limit = min(request.args.get("limit", 20, type=int), 100)

    samples = []
    for prog in programs.values():
        if not prog["loaded"]:
            continue
        for a in prog["actions"][:500]:
            if user_id_filter and a.get("_webhook_user_id") != user_id_filter:
                continue
            samples.append({
                "file_data": {
                    "click_id": a["_webhook_click_id"],
                    "uniq_id": a["_webhook_uniq_id"],
                    "user_id": a["_webhook_user_id"],
                    "order_number": a["_webhook_order_number"],
                    "order_status": a["_webhook_status"],
                    "comission": a["_webhook_commission"],
                },
                "api_response": {
                    "action_id": a["action_id"],
                    "subid1": a["subid1"],
                    "subid2": a["subid2"],
                    "order_id": a["order_id"],
                    "status": a["status"],
                    "payment": a["payment"],
                    "cart": a["cart"],
                    "action_date": a["action_date"],
                },
                "matching": {
                    "action_id == uniq_id": str(a["action_id"]) == str(a["_webhook_uniq_id"]),
                    "subid1 == click_id": a["subid1"] == a["_webhook_click_id"],
                    "order_id == order_number": a["order_id"] == a["_webhook_order_number"],
                    "payment == comission": a["payment"] == a["_webhook_commission"],
                },
            })
            if len(samples) >= limit:
                break

    return jsonify({
        "total_samples": len(samples),
        "mapping_strategy": {
            "PRIMARY (cashback)": "API.subid1 == DB.click_id (UUID кэшбэк-сервиса)",
            "FALLBACK": "API.order_id == DB.order_number",
            "LOGGING": "API.action_id == DB.uniq_id (для lost order claims)",
        },
        "samples": samples,
    })


@app.route("/manage/debug/sample/")
def debug_sample():
    """Один пример ответа API (как видит плагин)."""
    for prog in programs.values():
        if not prog["loaded"]:
            continue
        if prog["actions"]:
            a = prog["actions"][0]
            return jsonify({
                "description": "Пример одной записи из GET /statistics/actions/",
                "source": "Все данные из файла, ничего сгенерировано",
                "file_line_fields": {
                    "click_id": a["_webhook_click_id"],
                    "uniq_id": a["_webhook_uniq_id"],
                    "order_number": a["_webhook_order_number"],
                    "order_status": a["_webhook_status"],
                    "comission": a["_webhook_commission"],
                },
                "api_response": get_public_action(a),
            })
    return jsonify({"error": "No data loaded"})


# ═══════════════════════════════════════════════════════════
# Веб-панель
# ═══════════════════════════════════════════════════════════

PANEL_HTML = """<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Mock Admitad API v5</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, sans-serif;
         background: #0f172a; color: #e2e8f0; padding: 24px; }
  h1 { color: #38bdf8; margin-bottom: 8px; font-size: 24px; }
  .subtitle { color: #94a3b8; margin-bottom: 24px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
  .card { background: #1e293b; border-radius: 12px; padding: 20px; border: 1px solid #334155; }
  .card h2 { font-size: 16px; color: #38bdf8; margin-bottom: 12px; }
  .status { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
  .status.on { background: #065f46; color: #34d399; }
  .status.off { background: #7f1d1d; color: #fca5a5; }
  .status.loaded { background: #1e3a5f; color: #60a5fa; }
  .btn { padding: 8px 16px; border: none; border-radius: 8px; cursor: pointer;
         font-size: 13px; font-weight: 600; transition: all 0.2s; }
  .btn-on { background: #065f46; color: #34d399; }
  .btn-on:hover { background: #047857; }
  .btn-off { background: #7f1d1d; color: #fca5a5; }
  .btn-off:hover { background: #991b1b; }
  .btn-load { background: #1d4ed8; color: #fff; }
  .btn-load:hover { background: #2563eb; }
  .disc-row { display: flex; align-items: center; justify-content: space-between;
              padding: 12px; background: #0f172a; border-radius: 8px; margin-bottom: 8px; }
  .disc-info { flex: 1; }
  .disc-info .name { font-weight: 600; color: #f1f5f9; }
  .disc-info .detail { font-size: 12px; color: #94a3b8; margin-top: 2px; }
  .param { display: flex; align-items: center; gap: 8px; margin-right: 12px; }
  .param label { font-size: 12px; color: #94a3b8; }
  .param input { width: 50px; padding: 4px; background: #334155; border: 1px solid #475569;
                 color: #e2e8f0; border-radius: 4px; text-align: center; font-size: 12px; }
  .file-input { margin-top: 8px; }
  input[type=file] { font-size: 12px; color: #94a3b8; }
  .log { background: #0f172a; border-radius: 8px; padding: 12px; font-family: monospace;
         font-size: 12px; color: #94a3b8; max-height: 200px; overflow-y: auto; margin-top: 8px; }
  .endpoint { background: #334155; padding: 8px 12px; border-radius: 6px; font-family: monospace;
              font-size: 12px; color: #38bdf8; margin: 4px 0; word-break: break-all; }
  .full { grid-column: 1 / -1; }
  .info-box { background: #1e3a5f; color: #93c5fd; padding: 12px; border-radius: 8px;
              font-size: 13px; margin-bottom: 16px; border: 1px solid #3b82f6; }
  .mapping-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  .mapping-table th, .mapping-table td { padding: 6px 10px; text-align: left; border-bottom: 1px solid #334155; }
  .mapping-table th { color: #94a3b8; font-size: 11px; text-transform: uppercase; }
  .mapping-table td { font-family: monospace; font-size: 12px; color: #e2e8f0; }
  .match-key { color: #34d399; font-weight: bold; }
  .arrow { color: #64748b; }
</style>
</head>
<body>

<h1>Mock Admitad API v5</h1>
<p class="subtitle">Чистый маппер: файл → API. Ничего не генерирует.</p>

<div class="info-box">
  <strong>Принцип:</strong> файл вебхуков — единственный источник данных.
  Мок читает файл и маппит поля в формат Admitad API 1:1.
  action_id = uniq_id из файла (числовой). Даты, суммы, статусы — всё из файла.
</div>

<div class="grid">

  <!-- Маппинг -->
  <div class="card full">
    <h2>Маппинг: Файл → API</h2>
    <table class="mapping-table">
      <tr><th>Поле в файле</th><th></th><th>Поле в API</th><th>Роль</th></tr>
      <tr><td>click_id</td><td class="arrow">→</td><td class="match-key">subid1</td><td>✅ Ключ матчинга</td></tr>
      <tr><td>uniq_id (числовой)</td><td class="arrow">→</td><td class="match-key">action_id</td><td>Lost order claims</td></tr>
      <tr><td>user_id</td><td class="arrow">→</td><td>subid2</td><td>Фильтрация</td></tr>
      <tr><td>order_number</td><td class="arrow">→</td><td>order_id</td><td>Fallback матчинг</td></tr>
      <tr><td>order_status</td><td class="arrow">→</td><td>status</td><td>Сравнение (маппинг)</td></tr>
      <tr><td>comission</td><td class="arrow">→</td><td>payment</td><td>Сравнение</td></tr>
      <tr><td>sum_order</td><td class="arrow">→</td><td>cart</td><td>Сравнение</td></tr>
      <tr><td>action_date</td><td class="arrow">→</td><td>action_date</td><td>Из файла</td></tr>
      <tr><td>click_date</td><td class="arrow">→</td><td>click_date</td><td>Из файла</td></tr>
      <tr><td>status_updated</td><td class="arrow">→</td><td>status_updated</td><td>Из файла</td></tr>
    </table>
  </div>

  <!-- Программы -->
  <div class="card">
    <h2>Партнёр 1 (program_1)</h2>
    <div id="p1-status"></div>
    <div class="file-input">
      <input type="file" id="file1" accept=".txt">
      <button class="btn btn-load" onclick="loadFile('program_1', 'file1')">Загрузить</button>
    </div>
    <div id="p1-log" class="log">Файл не загружен</div>
  </div>

  <div class="card">
    <h2>Партнёр 2 (program_2)</h2>
    <div id="p2-status"></div>
    <div class="file-input">
      <input type="file" id="file2" accept=".txt">
      <button class="btn btn-load" onclick="loadFile('program_2', 'file2')">Загрузить</button>
    </div>
    <div id="p2-log" class="log">Файл не загружен</div>
  </div>

  <!-- Расхождения -->
  <div class="card full">
    <h2>Управление расхождениями</h2>

    <div class="disc-row">
      <div class="disc-info">
        <div class="name">Расхождения по статусам</div>
        <div class="detail">API вернёт другой status (pending/approved/declined)</div>
      </div>
      <div class="param"><label>%</label><input type="number" id="status_pct" value="15" min="1" max="100"></div>
      <span id="status_mismatch_badge" class="status off">OFF</span>
      <button class="btn btn-on" onclick="toggleDisc('status_mismatch', true)" style="margin-left:8px;">ON</button>
      <button class="btn btn-off" onclick="toggleDisc('status_mismatch', false)">OFF</button>
    </div>

    <div class="disc-row">
      <div class="disc-info">
        <div class="name">Расхождения по комиссиям</div>
        <div class="detail">API вернёт другой payment (±5-30%)</div>
      </div>
      <div class="param"><label>%</label><input type="number" id="commission_pct" value="10" min="1" max="100"></div>
      <span id="commission_mismatch_badge" class="status off">OFF</span>
      <button class="btn btn-on" onclick="toggleDisc('commission_mismatch', true)" style="margin-left:8px;">ON</button>
      <button class="btn btn-off" onclick="toggleDisc('commission_mismatch', false)">OFF</button>
    </div>

    <div class="disc-row">
      <div class="disc-info">
        <div class="name">Лишние в API (нет вебхука)</div>
        <div class="detail">Записи с фейковыми click_id — не найдутся в БД</div>
      </div>
      <div class="param"><label>шт</label><input type="number" id="extra_count" value="50" min="1" max="500"></div>
      <span id="extra_records_badge" class="status off">OFF</span>
      <button class="btn btn-on" onclick="toggleDisc('extra_records', true)" style="margin-left:8px;">ON</button>
      <button class="btn btn-off" onclick="toggleDisc('extra_records', false)">OFF</button>
    </div>

    <div class="disc-row">
      <div class="disc-info">
        <div class="name">Пропавшие из API</div>
        <div class="detail">Записи скрыты — имитация задержки Admitad</div>
      </div>
      <div class="param"><label>%</label><input type="number" id="missing_pct" value="5" min="1" max="50"></div>
      <span id="missing_records_badge" class="status off">OFF</span>
      <button class="btn btn-on" onclick="toggleDisc('missing_records', true)" style="margin-left:8px;">ON</button>
      <button class="btn btn-off" onclick="toggleDisc('missing_records', false)">OFF</button>
    </div>
  </div>

  <!-- Endpoints -->
  <div class="card full">
    <h2>API Endpoints</h2>
    <p style="font-size:12px; color:#94a3b8; margin-bottom:8px;">
      Header: <code style="color:#fbbf24">Authorization: Bearer any-token</code>
    </p>
    <div class="endpoint">GET /statistics/actions/?date_start=01.01.2026&limit=500&offset=0</div>
    <div class="endpoint">GET /statistics/actions/?subid2=5 (фильтр по user_id)</div>
    <div class="endpoint">GET /statistics/actions/?subid1=UUID (фильтр по click_id)</div>
    <div class="endpoint">GET /statistics/actions/?action_id=2867825 (фильтр по admitad_id)</div>
    <div class="endpoint">GET /statistics/actions/?status_updated_start=01.01.2026</div>
    <div class="endpoint">GET /statistics/actions/?total=1 (агрегаты)</div>
    <p style="font-size:11px; color:#64748b; margin-top:8px;">
      Debug: <a href="/manage/debug/matching/" style="color:#60a5fa">/manage/debug/matching/</a> |
      <a href="/manage/debug/sample/" style="color:#60a5fa">/manage/debug/sample/</a>
    </p>
  </div>

</div>

<script>
async function loadFile(program, inputId) {
  const input = document.getElementById(inputId);
  if (!input.files.length) return alert('Выберите файл');
  const form = new FormData();
  form.append('program', program);
  form.append('file', input.files[0]);
  const r = await fetch('/manage/load/', { method: 'POST', body: form });
  const data = await r.json();
  const num = program === 'program_1' ? '1' : '2';
  document.getElementById(`p${num}-log`).textContent =
    `Загружено: ${data.loaded} записей (${input.files[0].name})`;
  refreshStatus();
}

async function toggleDisc(type, enabled) {
  const body = { type, enabled };
  if (type === 'status_mismatch') body.pct = parseInt(document.getElementById('status_pct').value);
  if (type === 'commission_mismatch') body.pct = parseInt(document.getElementById('commission_pct').value);
  if (type === 'extra_records') body.count = parseInt(document.getElementById('extra_count').value);
  if (type === 'missing_records') body.pct = parseInt(document.getElementById('missing_pct').value);
  await fetch('/manage/discrepancy/', {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
  });
  refreshStatus();
}

async function refreshStatus() {
  const r = await fetch('/manage/data/');
  const data = await r.json();
  for (const [key, prog] of Object.entries(data.programs)) {
    const num = key === 'program_1' ? '1' : '2';
    const el = document.getElementById(`p${num}-status`);
    el.innerHTML = prog.loaded
      ? `<span class="status loaded">${prog.actions_count} записей</span>`
      : `<span class="status off">не загружен</span>`;
  }
  for (const [key, disc] of Object.entries(data.discrepancies)) {
    const badge = document.getElementById(`${key}_badge`);
    if (badge) {
      badge.className = `status ${disc.enabled ? 'on' : 'off'}`;
      badge.textContent = disc.enabled ? 'ON' : 'OFF';
    }
  }
}
refreshStatus();
</script>
</body>
</html>"""


@app.route("/")
def panel():
    return render_template_string(PANEL_HTML)


# ═══════════════════════════════════════════════════════════
# Автозагрузка
# ═══════════════════════════════════════════════════════════

def auto_load():
    pairs = [
        ("webhooks_test_data.txt", "program_1"),
        ("webhooks_test_data_2.txt", "program_2"),
    ]
    for filename, prog_key in pairs:
        if os.path.exists(filename):
            count = load_file_data(filename, prog_key)
            print(f"[OK] Авто-загрузка {filename} → {prog_key}: {count} записей")


if __name__ == "__main__":
    auto_load()
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 5000
    print(f"\n{'='*55}")
    print(f"Mock Admitad API v5: http://localhost:{port}")
    print(f"Панель управления:  http://localhost:{port}/")
    print(f"Debug matching:     http://localhost:{port}/manage/debug/matching/")
    print(f"{'='*55}\n")
    app.run(host="127.0.0.1", port=port, debug=True)