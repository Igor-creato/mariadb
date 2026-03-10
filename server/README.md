# CPA Mock Server

Тестовый сервер, эмулирующий API **Admitad** и **EPN** CPA-сетей.

Используется для тестирования модулей `Cashback_API_Client`, `Cashback_Admitad_Adapter`
и `Cashback_Epn_Adapter` без реальных учётных данных.

---

## Запуск через Docker

```bash
cd server/
docker compose up --build
```

Веб-интерфейс: **http://localhost:3000**

---

## Запуск без Docker (локально)

```bash
cd server/
npm install
npm start
```

---

## Реализованные эндпоинты

### Admitad

| Метод | URL | Описание |
|-------|-----|----------|
| `POST` | `/admitad/token/` | OAuth2 (Basic Auth + client_credentials) |
| `GET`  | `/admitad/statistics/actions/` | Список транзакций |

### EPN

| Метод | URL | Описание |
|-------|-----|----------|
| `GET`  | `/epn/ssid` | SSID-токен (шаг 1) |
| `POST` | `/epn/token` | Access-токен (шаг 2) |
| `POST` | `/epn/token/refresh` | Обновление токена |
| `GET`  | `/epn/transactions/user` | Список транзакций |

### Управление данными (Web UI API)

| Метод | URL | Описание |
|-------|-----|----------|
| `GET`    | `/api/status` | Статус сервера |
| `GET`    | `/api/logs` | Последние 100 запросов |
| `GET`    | `/api/transactions` | Список тестовых транзакций |
| `POST`   | `/api/transactions` | Добавить транзакцию (одну или массив) |
| `PUT`    | `/api/transactions/:id` | Обновить транзакцию |
| `DELETE` | `/api/transactions/:id` | Удалить транзакцию |
| `POST`   | `/api/transactions/clear` | Очистить транзакции |
| `GET`    | `/api/credentials` | Текущие credentials |
| `POST`   | `/api/credentials` | Установить credentials |
| `POST`   | `/api/tokens/clear` | Сбросить активные токены |

---

## Настройка плагина

После запуска сервера настройте сети в WordPress Admin → Кэшбэк → API Validation → Credentials.

### Admitad

| Поле | Значение |
|------|----------|
| `api_token_endpoint` | `http://host.docker.internal:3000/admitad/token/` |
| `api_actions_endpoint` | `http://host.docker.internal:3000/admitad/statistics/actions/` |
| `api_user_field` | `subid2` |
| `api_click_field` | `subid1` |
| `api_status_map` | `{"pending":"waiting","approved":"completed","declined":"declined","hold":"waiting"}` |

### EPN

| Поле | Значение |
|------|----------|
| `api_base_url` | `http://host.docker.internal:3000/epn` |
| `api_token_endpoint` | `http://host.docker.internal:3000/epn/token` |
| `api_actions_endpoint` | `http://host.docker.internal:3000/epn/transactions/user` |
| `api_user_field` | `sub2` |
| `api_click_field` | `click_id` |
| `api_status_map` | `{"pending":"waiting","approved":"completed","rejected":"declined","canceled":"declined","hold":"waiting"}` |

> **WordPress в Docker на Linux-сервере** — `host.docker.internal` не работает автоматически.
> Добавьте в сервис `wordpress` вашего `docker-compose.yml`:
> ```yaml
> extra_hosts:
>   - "host.docker.internal:host-gateway"
> ```
> Затем перезапустите контейнер: `docker compose up -d --no-deps wordpress`
>
> **WordPress на хосте (не в Docker)** — используйте `http://localhost:3000/...`
>
> **Проверка доступности из контейнера:**
> ```bash
> docker exec wordpress curl -s http://host.docker.internal:3000/api/status
> ```

---

## Структура тестовой транзакции

```json
{
  "network": "admitad",
  "order_id": "ORD-001",
  "click_id": "<UUID из cashback_click_log>",
  "subid1": "<UUID из cashback_click_log>",
  "subid2": "<WordPress user_id>",
  "status": "approved",
  "order_sum": 5000.00,
  "payment": 300.00,
  "currency": "RUB",
  "action_date": "2024-01-15",
  "advcampaign_name": "Test Shop"
}
```

Поле `click_id` / `subid1` — **ключ матчинга**. Должно совпадать
со значением `click_id` в таблице `cashback_click_log` вашей БД.
