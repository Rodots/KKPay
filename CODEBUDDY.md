# CODEBUDDY.md

This file provides guidance to CodeBuddy Code when working with code in this repository.

## Project Overview

KKPay is a high-performance aggregated payment system built on **Webman v2.2** (PHP 8.5+). It integrates Alipay, WeChat, UnionPay and other payment channels, providing order management, smart routing, risk control, auto-settlement, and merchant fund management.

**Language**: All documentation, comments, variable naming rationale, and communication must be in **Simplified Chinese**.

## Commands

```bash
# Install dependencies
composer install --no-dev

# Initialize system (import DB, create admin)
php ./scripts/initialization.php --reinstall
php ./scripts/initialization.php --reinstall --admin=your_name

# Run database migration updates
php ./scripts/update.php

# Start server (Linux daemon)
php ./start.php start -d

# Start server (Windows)
php ./windows.php

# Reload after code changes (Webman is memory-resident — changes require reload)
php ./start.php reload

# Restart daemon
php ./start.php restart -d

# Stop server
php ./start.php stop
```

The server listens on `http://0.0.0.0:6689` by default (configured in `config/process.php`).

## Architecture

### Framework & Runtime

Webman is a **memory-resident** PHP framework (like Workerman). Key implications:
- Code changes require `php ./start.php reload` to take effect
- Avoid using `static` variables to cache mutable data — they persist across the process lifecycle
- `controller_reuse` is disabled (`config/app.php`), so a new controller instance is created per request

### PSR-4 Autoloading

```
app\    → app/
Core\  → core/
support\ → support/
```

### Three App Modules (separate controller groups + middleware)

| Module | Path | Base Controller | Middleware | Purpose |
|--------|------|-----------------|-----------|---------|
| Admin | `app/admin/controller/` | `Core\baseController\AdminBase` | `AuthCheckAdmin` | Backend management panel |
| Merchant | `app/merchant/controller/` | `Core\baseController\MerchantBase` | `AuthCheckMerchant` | Merchant self-service panel |
| API | `app/api/controller/` + `app/api/v1/controller/` | `Core\baseController\ApiBase` | API signature verification | External merchant API |

### Routing

Webman uses **automatic routing**: `/app/controller/action` maps to `App\Controller\ActionController::action()`. Only a few routes are explicitly defined in `config/route.php` (checkout pages, payment extension paths).

### Controller Base Classes

- **`ApiBase`** — Uses `ApiResponse` trait. Provides `parseBizContent()`, `getString()`, `getAmount()`, `getInt()`, `getMerchantId()`, `getMerchantNumber()`, and response methods `success()`, `fail()`, `error()`.
- **`AdminBase`** — Uses `AdminResponse` + `AdminRole` traits. Provides `decryptPayload()` (XChaCha20) and `adminLog()`.
- **`MerchantBase`** — Uses `AdminResponse` trait. Provides `decryptPayload()` and `merchantLog()`.

### Response Formats

Two distinct response traits, not interchangeable:
- **`ApiResponse`** (for API): `success(data, message, code)` — HTTP 200; `fail(message, code, data)` — HTTP 400; `error(message, code, data)` — HTTP 500
- **`AdminResponse`** (for Admin/Merchant): `success(message, data, code)` — HTTP 200; `fail(message, code, data)` — HTTP 200; `error(message, code, data)` — HTTP 500

Note the different parameter order between the two: `ApiResponse::success()` takes `$data` first, while `AdminResponse::success()` takes `$message` first.

### Core Business Layer (`core/`)

- **`Gateway/`** — Payment gateway implementations. Each gateway is a subdirectory (e.g., `Alipay/`, `EPay/`, `BaiExcellent/`, `STIntl/`) containing a `Gateway.php` class extending `GatewayAbstract`. Gateway classes use static methods: `unified()`, `page()`, `notify()`, `refund()`, `close()`. Some gateways also have a `Complaint.php` extending `ComplaintAbstract`.
- **`Service/`** — Core business logic services: `OrderCreationService`, `OrderService`, `PaymentService`, `PaymentChannelSelectionService`, `RefundService`, `ComplaintService`, `RiskService`, `MerchantWithdrawalService`.
- **`Abstract/GatewayAbstract.php`** — Base class for all payment gateways. Provides `lockPaymentExt()` (DB-locked ext data), `processNotify()` (delegates to `OrderService::handlePaymentSuccess`), `returnRedirectUrl()`.
- **`Abstract/ComplaintAbstract.php`** — Base class for complaint handling per gateway.
- **`Utils/PaymentGatewayUtil.php`** — Gateway loader via reflection. Builds FQCN as `\Core\Gateway\{name}\Gateway`, validates static methods, and invokes them. Use `loadGateway()` or `loadGatewayWithSpread()` to call gateway methods.
- **`Utils/SignatureUtil.php`** — Signature generation/verification supporting xxh128, SHA3-256, SM3, and RSA2 algorithms.

### Data Layer

- **Models** (`app/model/`) — Eloquent ORM models with `kkpay_` table prefix. Key models: `Order`, `Merchant`, `PaymentChannel`, `PaymentChannelAccount`, `Admin`, `Config`, and various wallet/record models.
- **Database config** — `config/database.php` (MySQL with connection pooling).
- **Redis** — `config/redis.php` with separate connections for default, cache, and rate_limiter (prefix: `KKPay:`).
- **SQL scripts** — `scripts/sql/install.sql` (initial schema), plus versioned migration files.

### Background Processes (`app/process/`)

Configured in `config/process.php`:
- `Monitor` — File change detection + auto-reload (dev only)
- `OrderSettle` — Order settlement processor
- `AutoWithdraw` — Merchant auto-withdrawal
- `OrderAutoClose` — Auto-close expired orders
- `AutoComplaint` — Auto-fetch complaint data

### Redis Queue (`app/queue/redis/`)

- `OrderNotification` — Async order notification to merchant
- `OrderNotificationManual` — Manual retry for order notifications
- `OrderSettle` — Async order settlement

### Middleware (`app/middleware/`)

- `TraceID` — Global, assigns trace ID to every request
- `AuthCheckAdmin` — Admin panel auth
- `AuthCheckMerchant` — Merchant panel auth
- `StaticFile` — Static file serving

### Global Helper Functions (`app/functions.php`)

- `sys_config($group, $key, $default)` — Get system config from DB (cached in Redis)
- `clear_sys_config_cache($group)` — Invalidate config cache
- `query_cache($key, $callback, $ttl)` — Cache-through query helper
- `random($length, $mode)` — Random string generation (PHP 8.3+ Randomizer)
- `get_client_ip()` — Real IP via CDN headers (Cloudflare, Alibaba, EdgeOne)
- `is_https()`, `site_url()`, `isMobile()`, `isWechat()`, `isAlipay()`, `isQQ()`, `isUnionPay()`, `detectMobileApp()`

### Custom Libraries (`support/Rodots/`)

- `Crypto/` — XChaCha20 encryption, RSA2 signing
- `JWT/` — JWT token handling
- `Functions/` — Utility functions

## Code Standards

- **Strict types**: Every PHP file must have `declare(strict_types=1);`
- **PHP 8.5+**: Use modern syntax (match expressions, named args, readonly, etc.)
- **No TODOs/placeholder code**: All output must be complete, runnable code
- **No pseudocode**: Never use pseudocode or incomplete implementations
- **Single Responsibility**: Each file/class/function handles one task
- **Complete PHPDoc**: All classes and methods must include PHPDoc with description, params, and return types

### Naming Conventions

| Item | Convention | Example |
|------|-----------|---------|
| Variables / Functions | `snake_case` | `$user_id`, `get_user_info` |
| Classes / Interfaces / Exceptions | `PascalCase` | `UserController`, `PaymentException` |
| Constants | `UPPER_SNAKE_CASE` | `APP_PATH` |
| Methods | `camelCase` | `getUserName` |
| DB tables / columns | `lowercase_snake_case` | `kkpay_order`, `trade_no` |
| Directories | `lowercase_snake_case` | `payment_channel/` |

### Code Style

- LF line endings only
- 4-space indentation, no tabs
- One blank line between class/function definitions and logical blocks
- Prefer chained calls and arrow functions; keep chains/params/arrays/ternaries on one line
- Import order: standard library → third-party → local modules

### Git Commit Convention

Format: `<type>(<scope>): <subject>`

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`, `sql`

Example: `feat(api): 实现用户注册接口`

## Adding a New Payment Gateway

1. Create `core/Gateway/{GatewayName}/Gateway.php` extending `GatewayAbstract`
2. Implement required static methods: `unified(array $items)`, `page(array $items)`, `notify(array $items)`
3. Optionally implement `refund()`, `close()`
4. If complaint handling is needed, create `Complaint.php` extending `ComplaintAbstract`
5. Define `$info` static property with gateway metadata and dynamic form config
6. The gateway is loaded via `PaymentGatewayUtil::loadGateway('{GatewayName}', 'methodName', $items)`
