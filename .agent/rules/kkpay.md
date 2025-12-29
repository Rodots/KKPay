---
trigger: always_on
---

# KKPay 聚合支付系统 - 开发指南

---

## 一、项目概述

### 1.1 简介
KKPay 是基于 **Webman v2.1** (PHP 8.4+) 的高性能聚合支付系统。系统集成了支付宝、微信、云闪付等主流渠道，提供订单管理、智能路由、风控检测、自动结算及商户资金管理等核心能力。

### 1.2 技术栈
- **核心框架**：Webman v2.1 (常驻内存)
- **开发语言**：PHP 8.4+ (Strict Typing)
- **数据库**：MySQL 8.4+ (Laravel Eloquent ORM)
- **缓存/队列**：Redis / webman-redis-queue
- **加密安全**：OpenSSL (RSA2), Sodium, AES-256-GCM

### 1.3 角色设定
你是一名精通 PHP 8.4+ 的专家级后端工程师、熟练掌握Webman框架用法。

---

## 二、开发规范与原则

### 2.1 核心原则
- **完整性**：输出完整的、可运行的代码，严禁使用伪代码或“此处省略逻辑”。
- **语言**：所有文档、注释、变量命名思路、计划及沟通均使用 **简体中文**。
- **技术栈**：严格遵守 PHP 8.4+ 标准，优先使用新特性（如只读类、枚举、箭头函数），禁止使用已弃用语法。

### 2.2 代码规范
- **命名规范**：
    - 变量/函数：`snake_case` (如 `$user_id`, `get_user_info`)
    - 类/接口/异常：`PascalCase` (如 `UserController`, `invalidArgumentException`)
    - 常量：`UPPER_SNAKE_CASE`
- **代码风格**：
    - **导入顺序**：标准库 -> 第三方库 -> 本地模块（组间空一行）。
    - **空行规则**：类/函数定义/逻辑块之间空 **1行**。
    - **简洁性**：优先使用链式调用和箭头函数，链式调用与传参需一行写完，避免冗余换行。如必要时可换行，但需保持代码的可读性和简洁性。
    - **严格类型模式**：所有PHP文件头部必须声明`declare(strict_types=1);`。
- **类型系统**：严格声明参数类型和返回类型（Strict Typing）。
- **文档注释**：所有类、方法必须包含 PHPDoc，详细说明功能、参数及返回值。

### 2.3 架构与性能
- **单一职责**：严格遵守 SRP 原则，每个文件/类/函数仅负责一项任务。
- **内存优化**：针对常驻内存场景优化，防止内存溢出。
- **异常处理**：使用明确的 try-catch-finally 结构，统一使用Throwable捕获异常。
- **安全性**：如必要时需考虑输入验证和输出过滤机制。

### 2.4 开发工作流
1. 思考代码结构与依赖。
2. 编写代码（包含完整逻辑、类型声明及中文注释）。
3. 再次检查是否符合 PHP 8.4+ 特性及上述命名/空行规范。

---

## 三、目录结构

```text
KKPay/
├── app/
│   ├── admin/controller/      # 后台控制器 (Capital, Merchant, Order等)
│   ├── api/
│   │   ├── controller/        # 公共/收银台控制器 (Checkout, Standard)
│   │   ├── v1/controller/     # 商户API v1 (Pay, Trade, Merchant, Withdrawal)
│   │   └── view/              # 视图 (收银台，付款页面)
│   ├── middleware/            # 全局中间件
│   ├── model/                 # 数据模型 (Order, Merchant, PaymentChannel)
│   ├── queue/redis/           # 消费者 (OrderNotification, OrderSettle)
│   └── functions.php          # 全局函数
├── core/
│   ├── baseController/        # 控制器基类 (AdminBase, ApiBase)
│   ├── Service/               # 核心业务 (OrderService, PaymentService)
│   ├── Utils/                 # 工具类 (SignatureUtil, Helper)
│   └── Constants/             # 系统常量
├── support/
│   ├── Gateway/               # 支付网关实现 (Alipay, Wechat)
│   └── Rodots/                # 基础库 (Crypto, JWT)
├── config/                    # 系统配置
└── public/                    # 静态资源
```

---

## 四、API 开发规范 (v1)

### 4.1 ApiBase 基类 (Core\baseController\ApiBase)
所有商户 API 控制器均继承自 `Core\baseController\ApiBase`，主要提供以下能力：
- **签名验证**：自动挂载 `ApiSignatureVerification` 中间件。
- **参数解析与获取**：
  - `parseBizContent(Request $req)`: 解密并解析 JSON 业务参数。
  - `getString($data, 'key')`: 类型安全的字符串提取。
  - `getAmount($data, 'key')`: 金额格式化与校验。
  - `getInt($data, 'key')`: 获取整数参数。
- **商户上下文**：
  - `getMerchantId(Request $req)`: 获取当前商户ID。
  - `getMerchantNumber(Request $req)`: 获取当前商户编号。
- **响应封装**：`success()`, `fail()`, `error()`。

### 4.2 关键控制器
| 控制器 | 路径 | 职责 |
|---|---|---|
| `PayController` | `/api/v1/pay/*` | 收单核心：`submit` (跳页), `create` (统一下单) |
| `TradeController` | `/api/v1/trade/*` | 交易管理：`query` (查单), `refund` (退款), `close` (关单) |
| `MerchantApiController` | `/api/v1/merchant/*` | 商户信息：`info` (信息), `balance` (余额) |
| `WithdrawalController` | `/api/v1/withdrawal/*` | 提现管理：`apply` (申请), `query` (查询), `cancel` (取消) |
| `CheckoutController` | `/api/checkout/*` | 收银台：`index` (页面), `selectPayment` (支付选择) |
| `StandardController` | `/api/standard/*` | 公共接口：`queryQRStatus` (状态查询) |

### 4.3 路由机制
采用 Webman **自动路由**，无需手动配置。
- 规则：`/app/controller/action` => `App\Controller\ActionController::action()`
- 示例：`/api/v1/pay/create` => `app\api\v1\controller\PayController::create()`

---

## 4.4 响应格式

后台接口统一采用 `Core\Traits\AdminResponse` 封装响应，API接口统一采用 `Core\Traits\ApiResponse` 封装响应，设计开发文档时需严格按照对应格式

---

## 五、核心业务服务 (Core\Service)

### 5.1 订单体系 (OrderService/OrderCreationService)
- **创建流程**：参数校验 -> 路由选择 -> 费率计算 -> 落库 -> 调用网关。
- **状态流转**：
  - `WAIT_PAY` (待支付)
  - `SUCCESS` (成功/待结算)
  - `FROZEN` (冻结) / `REFUND` (已退款) / `CLOSED` (已关闭)
- **通知机制**：支付成功后异步触发 `OrderNotification` 队列。

### 5.2 资金与结算 (Capital & Settlement)
- **自动结算**：订单完成后进入 `OrderSettle` 队列，根据 T+N 规则自动入账商户钱包。
- **清账 (Settle)**：管理员通过 `CapitalController::settleAccount` 手动触发提现/转账逻辑。
- **余额类型**：
  - `available`: 可用余额
  - `unavailable`: 冻结/不可用余额
  - `prepaid`: 预付金 (用于代付产生的费用扣除)

### 5.3 风控系统 (RiskService)
调用支付前自动检查：
- IP/黑名单检测
- 单笔/单日限额校验 (PaymentChannelAccount)
- 频次限制

---

## 六、数据模型 (app/model)

基于 `app/model` 及数据库设计 (`update/sql/install.sql`) 的完整模型体系：

### 6.1 系统管理
| 模型 | 表名 | 描述 |
|---|---|---|
| `Admin` | kkpay_admin | 管理员账户 |
| `AdminLog` | kkpay_admin_log | 管理员操作日志 |
| `Config` | kkpay_config | 系统配置 (g, k, v) |
| `Blacklist` | kkpay_blacklist | 黑名单 (IP/Card/User) |

### 6.2 商户体系 (Merchant)
| 模型 | 表名 | 描述 | 关键关联 |
|---|---|---|---|
| `Merchant` | kkpay_merchant | 商户基础信息 | HasOne: Wallet, Encryption, Security |
| `MerchantEncryption` | kkpay_merchant_encryption | 商户密钥配置 | (AES, RSA2, Hash Key) |
| `MerchantSecurity` | kkpay_merchant_security | 商户安全设置 | (2FA, Phishing Code, OpenIDs) |
| `MerchantLog` | kkpay_merchant_log | 商户操作日志 | - |
| `MerchantEmailLog` | kkpay_merchant_email_log | 邮件发送日志 | - |

### 6.3 资金管理 (Capital)
| 模型 | 表名 | 描述 |
|---|---|---|
| `MerchantWallet` | kkpay_merchant_wallet | 商户余额 (Available, Unavailable, Prepaid) |
| `MerchantWalletRecord` | kkpay_merchant_wallet_record | 余额变动流水 |
| `MerchantWalletPrepaidRecord` | kkpay_merchant_wallet_prepaid_record | 预付金变动流水 |
| `MerchantWithdrawalRecord` | kkpay_merchant_withdrawal_record | 商户提款记录 |
| `MerchantPayee` | kkpay_merchant_payee | 商户收款账户信息 |

### 6.4 交易订单 (Order)
| 模型 | 表名 | 描述 | 状态枚举 (trade_state) |
|---|---|---|---|
| `Order` | kkpay_order | 核心交易表 | WAIT_PAY, SUCCESS, CLOSED, FROZEN |
| `OrderBuyer` | kkpay_order_buyer | 买家/环境信息 | IP, UA, RealName |
| `OrderRefund` | kkpay_order_refund | 退款记录 | initiate_type (system/api) |
| `OrderNotification`| kkpay_order_notification | 异步通知记录 | - |
| `RiskLog` | kkpay_risk_log | 风控拦截日志 | - |

### 6.5 支付通道 (Channel)
| 模型 | 表名 | 描述 |
|---|---|---|
| `PaymentChannel` | kkpay_payment_channel | 支付通道定义 (Alipay, Wechat) |
| `PaymentChannelAccount` | kkpay_payment_channel_account | 通道子账户 (轮询/权重) |

> **提示**：所有模型均位于 `app\model` 命名空间，继承自 `support\Model` (Laravel Eloquent)。

---

## 七、其他重要组件

### 7.1 队列 (Redis Queue)
- **OrderNotification**: 异步发送 HTTP 通知给商户。
- **OrderSettle**: 处理订单资金结算与分润。

### 7.2 定时任务 (Process)
- **Crontab**: 定期执行数据清理、统计报表生成、异常订单同步。

### 7.3 辅助函数 (app/functions.php)
- `sys_config($group, $key)`: 读取配置（带缓存）。
- `random($len, $mode)`: 生成随机串。
- `get_client_ip()`: 获取真实 IP。

---

> **注意**：修改此文档请务必通过 Pull Request 流程，并同步更新相关代码注释。
