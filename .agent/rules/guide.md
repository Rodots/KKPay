---
trigger: always_on
---

# 开发指南

## 一、项目概述

### 1.1 简介
KKPay 是基于 **Webman v2.1** (PHP 8.4+) 的高性能聚合支付系统。系统集成了支付宝、微信、云闪付等主流渠道，提供订单管理、智能路由、风控检测、自动结算及商户资金管理等核心能力。

### 1.2 技术栈
- **核心框架**：Webman v2.1 (常驻内存框架)
- **开发语言**：PHP 8.4+ (Strict Typing)
- **数据库**：MySQL 8.4+ (Laravel Eloquent ORM)
- **缓存/队列**：Redis / webman-redis-queue

---

## 二、开发规范与原则

### 2.1 核心原则
- **完整性**：输出完整的、可运行的代码，严禁使用伪代码或TODO。
- **语言**：所有文档、注释、变量命名思路以及我与你对话的计划和沟通均使用 **简体中文**。
- **技术栈**：严格遵守 PHP 8.4+ 标准，优先使用新特性语法糖，禁止使用已被弃用的语法。

### 2.2 代码规范
- **命名规范**：
    - 变量/函数：`snake_case` (如 `$user_id`, `get_user_info`)
    - 类/接口/异常：`PascalCase` (如 `UserController`, `invalidArgumentException`)
    - 常量：`UPPER_SNAKE_CASE`
- **代码风格**：
    - **导入顺序**：标准库 -> 第三方库 -> 本地模块（组间空一行）。
    - **空行规则**：类/函数定义/逻辑块之间空 **1行**。
    - **简洁性**：优先使用链式调用和箭头函数，链式调用、传参、数组、三元运算符等需一行写完，避免换行。
    - **严格类型模式**：所有PHP文件头部必须声明`declare(strict_types=1);`。
- **文档注释**：所有类、方法必须包含 PHPDoc，详细说明功能、参数及返回值。

### 2.3 架构与性能
- **单一职责**：严格遵守 SRP 原则，每个文件/类/函数仅负责一项任务。
- **内存优化**：针对常驻内存场景优化，防止内存溢出。
- **异常处理**：使用明确的 try-catch-finally 结构，统一使用Throwable捕获异常。
- **安全性**：如必要时需考虑输入验证和输出过滤机制。
- **常驻内存约束**：谨慎使用 `static` 变量缓存可变数据，`static` 变量会在进程生命周期内持久存在，导致数据无法刷新。

### 2.4 开发工作流
1. 思考代码结构与依赖。
2. 编写代码（包含完整逻辑、类型声明及中文注释）。
3. 再次检查是否符合 PHP 8.4+ 特性及上述命名/空行规范。

---

## 三、目录结构

```text
KKPay/
├── app/
│   ├── admin/controller/      # '后台'控制器
│   ├── api/
│   │   ├── controller/        # 公共/收银台控制器 (Checkout, Standard)
│   │   ├── v1/controller/     # 对外API (Transaction, Order, Merchant, Withdrawal)
│   │   └── view/              # 视图 (收银台，付款页面)
│   ├── middleware/            # 全局中间件
│   ├── model/                 # 数据模型 (Order, Merchant, PaymentChannel)
│   ├── queue/redis/           # Redis队列消费者
│   └── functions.php          # 全局函数
├── core/
│   ├── baseController/        # 控制器基类 (AdminBase, ApiBase)
│   └── Constants/             # 系统常量
│   ├── Gateway/               # 支付网关实现
│   ├── Service/               # 核心服务
│   ├── Traits/                # 特征（统一响应格式、公共方法）
│   ├── Utils/                 # 工具类
├── support/                   # 第三方类库
├── config/                    # 系统配置
└── public/                    # 静态资源
```

---

## 四、API 开发规范

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

### 4.2 路由机制
采用 Webman **自动路由**，无需手动配置。
- 规则：`/app/controller/action` => `App\Controller\ActionController::action()`
- 示例：`/api/v1/transaction/unified` => `app\api\v1\controller\TransactionController::unified()`

### 4.3 响应格式

后台接口统一采用 `Core\Traits\AdminResponse` 封装响应，API接口统一采用 `Core\Traits\ApiResponse` 封装响应，生成文档时需先了解具体的响应格式再生成。

---

## 五、数据模型 (app/model)

基于 `app/model` 及数据库设计 (`/script/sql/install.sql`) 的完整模型体系：

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
| `MerchantEncryption` | kkpay_merchant_encryption | 商户密钥配置 | (RSA2, Hash Key) |
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

> 所有模型均继承自 `support\Model` (Laravel Eloquent)。

---

## 六、系统公共助手函数 (app/functions.php)

- `sys_config($group, $key)`: 读取配置（带缓存）。
- `random($len, $mode)`: 生成随机字符串。
- `get_client_ip()`: 获取真实 IP。
