---
trigger: always_on
---

# 开发指南

## 一、项目概述

### 1.1 简介
KKPay 是基于 **Webman v2.2** (PHP 8.5+) 的高性能聚合支付系统。系统集成了支付宝、微信、云闪付等主流渠道，提供订单管理、智能路由、风控检测、自动结算及商户资金管理等核心能力。

### 1.2 技术栈
- **核心框架**：Webman v2.2 (常驻内存框架)
- **开发语言**：PHP 8.5+ (Strict Typing)
- **数据库**：MySQL 8.4+ (Laravel Eloquent ORM)
- **缓存/队列**：Redis (webman-redis-queue)

---

## 二、开发规范与原则

### 2.1 核心原则
- **完整性**：输出完整的、可运行的代码，严禁使用伪代码或TODO。
- **语言**：所有文档、注释、变量命名思路以及我与你对话的计划和沟通均使用 **简体中文**。
- **技术栈**：严格遵守 PHP 8.5+ 标准，优先使用新特性语法糖，禁止使用已被弃用的语法。

### 2.2 代码规范
- **命名规范**：
    - 变量/函数：`snake_case` (如 `$user_id`, `get_user_info`)
    - 类/接口/异常：`PascalCase` (如 `UserController`, `invalidArgumentException`)
    - 常量：`UPPER_SNAKE_CASE`
- **代码风格**：
    - **导入顺序**：标准库 -> 第三方库 -> 本地模块。
    - **空行规则**：类/函数定义/逻辑块之间空一行。
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
3. 再次检查是否符合 PHP 8.5+ 特性及上述命名/空行规范。
4. 编写开发计划时，无需规划验证方案

---

## 三、目录结构

```text
KKPay/
├── app/
│   ├── admin/controller/   # '后台'控制器
│   ├── api/
│   │   ├── controller/     # 公共/收银台控制器 (Checkout, Standard)
│   │   ├── v1/controller/  # 对外API (Transaction, Order, Merchant, Withdrawal)
│   │   └── view/           # 视图 (收银台，付款页面)
│   ├── middleware/         # 全局中间件
│   ├── model/              # 数据模型 (Order, Merchant...)
│   ├── process/            # 定时任务
│   ├── queue/redis/        # Redis队列消费者
│   └── functions.php       # 全局函数
├── core/
│   ├── baseController/     # 控制器基类 (AdminBase, ApiBase)
│   └── Constants/          # 系统常量
│   ├── Gateway/            # 支付网关实现
│   ├── Service/            # 核心服务
│   ├── Traits/             # 特征（统一响应格式、公共方法）
│   ├── Utils/              # 工具类
├── support/                # 第三方类库
├── config/                 # 系统配置
└── public/                 # 静态资源
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

后台接口输出统一采用 `Core\Traits\AdminResponse` 封装响应，API接口输出统一采用 `Core\Traits\ApiResponse` 封装响应，生成文档时需先了解具体的响应格式再生成。

---

## 五、数据模型 (app/model)

基于 `app/model` 及数据库设计 (`/script/sql/install.sql`) 的完整模型体系：

> 所有模型均继承自 `support\Model`。

---

## 六、系统公共助手函数 (app/functions.php)

- `sys_config($group, $key)`: 从数据库中获取系统配置。
- `random($len, $mode)`: 生成随机字符串。
- `get_client_ip()`: 获取真实 IP。
