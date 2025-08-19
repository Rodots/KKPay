SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for kkpay_admin
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_admin`;
CREATE TABLE `kkpay_admin`  (
  `id` tinyint UNSIGNED NOT NULL AUTO_INCREMENT,
  `role` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色',
  `account` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '账号/用户名',
  `nickname` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '昵称',
  `email` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '电子邮箱',
  `status` bit(1) NOT NULL DEFAULT b'1' COMMENT '状态 0:禁用 1:启用',
  `password` char(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '密码哈希',
  `salt` char(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '密码盐',
  `totp_secret` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT 'TOTP共享密钥',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `idx_account`(`account` ASC) USING BTREE COMMENT '账号是唯一的'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '管理员表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of kkpay_admin
-- ----------------------------
INSERT INTO `kkpay_admin` VALUES (1, 0, 'admin', 'Boss', NULL, b'1', '$2y$12$s63FU4x70ro2rPIGxB6eS.Fah8nNpHStHhSWmLgWC/M5ap4KzB1c2', 'do9V', NULL, '2025-08-01 00:00:00', '2025-08-01 00:00:00');

-- ----------------------------
-- Table structure for kkpay_admin_log
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_admin_log`;
CREATE TABLE `kkpay_admin_log`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` tinyint UNSIGNED NOT NULL COMMENT '管理员ID',
  `content` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '操作内容',
  `ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'IP地址',
  `user_agent` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '浏览器标识',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_created`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '管理员操作日志表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_blacklist
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_blacklist`;
CREATE TABLE `kkpay_blacklist`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` enum('USER_ID','BANK_CARD','ID_CARD','MOBILE','IP_ADDRESS','DEVICE_FINGERPRINT') CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '实体类型',
  `entity_value` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '实体值',
  `entity_hash` char(56) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '实体值的SHA3-224哈希',
  `risk_level` tinyint UNSIGNED NOT NULL DEFAULT 1 COMMENT '风险等级：1-低危, 2-中危, 3-高危, 4-极高危',
  `reason` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '封禁原因',
  `origin` enum('RISK_ENGINE','MANUAL_REVIEW','THIRD_PARTY_DATA','COMPLIANCE','REGULATOR') CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '来源',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `expired_at` timestamp NULL DEFAULT NULL COMMENT '过期时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_entity_hash`(`entity_hash` ASC) USING BTREE,
  INDEX `idx_entity_type_value`(`entity_type` ASC, `entity_value` ASC) USING BTREE,
  INDEX `idx_expired_at`(`expired_at` ASC) USING BTREE,
  INDEX `idx_created_at`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '用户/客户黑名单表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_config
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_config`;
CREATE TABLE `kkpay_config`  (
  `g` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '组别',
  `k` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '标识',
  `v` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '内容',
  PRIMARY KEY (`g`) USING BTREE,
  UNIQUE INDEX `group_key`(`g` ASC, `k` ASC) USING BTREE COMMENT '同组别下配置项是唯一的'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '站点配置表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant`;
CREATE TABLE `kkpay_merchant`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_number` char(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '商户编号',
  `email` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '邮箱',
  `phone` char(11) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '手机号',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT '' COMMENT '备注',
  `diy_order_subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '自定义订单标题',
  `password` char(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '密码哈希',
  `salt` char(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '密码盐',
  `status` bit(1) NOT NULL DEFAULT b'1' COMMENT '账户状态',
  `risk_status` bit(1) NOT NULL DEFAULT b'0' COMMENT '风控状态',
  `competence` json NULL COMMENT '权限',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `merchant_number_uindex`(`merchant_number` ASC) USING BTREE COMMENT '商户编号是唯一的',
  UNIQUE INDEX `email_uindex`(`email` ASC) USING BTREE COMMENT '邮箱是唯一的',
  UNIQUE INDEX `phone_uindex`(`phone` ASC) USING BTREE COMMENT '手机号是唯一的',
  INDEX `idx_softdelete`(`deleted_at` ASC) USING BTREE COMMENT '软删除'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant_email_log
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant_email_log`;
CREATE TABLE `kkpay_merchant_email_log`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '邮件标题',
  `content` varchar(5096) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '邮件正文（纯文本）',
  `mail_type` enum('single','bulk') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'single' COMMENT 'single=单发 bulk=群发',
  `template_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '模板编号（可选）',
  `receiver_email` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '收件人邮箱',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `status` enum('pending','sent','failed','bounced','opened','clicked') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '当前状态',
  `fail_reason` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '发送失败/退信原因',
  `send_time` timestamp NULL DEFAULT NULL COMMENT '实际发出时间',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_receiver_email`(`receiver_email` ASC) USING BTREE,
  INDEX `idx_merchant_id`(`merchant_id` ASC) USING BTREE,
  INDEX `idx_status_sendtime`(`status` ASC, `send_time` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户邮件发送日志表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant_encryption
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant_encryption`;
CREATE TABLE `kkpay_merchant_encryption`  (
  `merchant_id` int UNSIGNED NOT NULL,
  `mode` enum('open','only_sha3','only_rsa2') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open' COMMENT '对接模式',
  `aes_key` char(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '传输密钥(AES)',
  `sha3_key` char(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '对接密钥(SHA3-256)',
  `rsa2_key` varchar(512) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '对接公钥(RSA-2048)',
  PRIMARY KEY (`merchant_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户密钥表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant_log
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant_log`;
CREATE TABLE `kkpay_merchant_log`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `content` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '操作内容',
  `ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'IP地址',
  `user_agent` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '浏览器标识',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_created`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户操作日志表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant_payee
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant_payee`;
CREATE TABLE `kkpay_merchant_payee`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `payee_info` json NOT NULL COMMENT '收款信息',
  `status` bit(1) NOT NULL DEFAULT b'0' COMMENT '状态',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_merchant_id`(`merchant_id` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户结算收款人信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant_security
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant_security`;
CREATE TABLE `kkpay_merchant_security`  (
  `merchant_id` int UNSIGNED NOT NULL,
  `anti_phishing_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '防钓鱼码',
  `totp_secret` char(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT 'TOTP共享密钥',
  `weixin_openid` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '微信OpenId',
  `qq_openid` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT 'QQOpenId',
  `alipay_openid` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '支付宝OpenId',
  `google_sub` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '谷歌用户标识符',
  `last_login_ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '最后登录IP',
  `last_login_time` timestamp NULL DEFAULT NULL COMMENT '最后登录时间',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`merchant_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户安全表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant_wallet
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant_wallet`;
CREATE TABLE `kkpay_merchant_wallet`  (
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `balance` decimal(20, 6) NOT NULL DEFAULT 0.000000 COMMENT '可用余额',
  `freeze_balance` decimal(20, 6) NOT NULL DEFAULT 0.000000 COMMENT '冻结余额',
  `margin` decimal(20, 6) NOT NULL DEFAULT 0.000000 COMMENT '保证金/押金',
  `prepaid` decimal(20, 6) UNSIGNED NOT NULL DEFAULT 0.000000 COMMENT '预付金',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`merchant_id`) USING BTREE,
  UNIQUE INDEX `merchant_currency_uindex`(`merchant_id` ASC) USING BTREE COMMENT '商户和货币唯一'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户钱包表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant_wallet_prepaid_record
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant_wallet_prepaid_record`;
CREATE TABLE `kkpay_merchant_wallet_prepaid_record`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `balance` decimal(20, 6) NOT NULL COMMENT '变更金额',
  `old_balance` decimal(20, 6) UNSIGNED NOT NULL COMMENT '变更前余额',
  `new_balance` decimal(20, 6) UNSIGNED NOT NULL COMMENT '变更后余额',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '备注',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_merchant_wallet_id`(`merchant_id` ASC) USING BTREE,
  INDEX `idx_type_created`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户钱包预付金变动记录表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant_wallet_record
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant_wallet_record`;
CREATE TABLE `kkpay_merchant_wallet_record`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '操作类型',
  `balance` decimal(20, 6) NOT NULL COMMENT '变更金额',
  `old_balance` decimal(20, 6) NOT NULL COMMENT '变更前余额',
  `new_balance` decimal(20, 6) NOT NULL COMMENT '变更后余额',
  `freeze_balance` decimal(20, 6) NOT NULL COMMENT '变更冻结金额',
  `old_freeze_balance` decimal(20, 6) NOT NULL COMMENT '变更前冻结余额',
  `new_freeze_balance` decimal(20, 6) NOT NULL COMMENT '变更后冻结余额',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '备注',
  `trade_no` char(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '关联平台订单号',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_merchant_wallet_id`(`merchant_id` ASC) USING BTREE,
  INDEX `idx_type_created`(`type` ASC, `created_at` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户钱包余额变动记录表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_merchant_withdrawal_record
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_merchant_withdrawal_record`;
CREATE TABLE `kkpay_merchant_withdrawal_record`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `payee_info` json NOT NULL COMMENT '收款信息',
  `amount` decimal(20, 6) UNSIGNED NOT NULL COMMENT '提款金额',
  `received_amount` decimal(20, 6) UNSIGNED NOT NULL DEFAULT 0.000000 COMMENT '到账金额',
  `fee` decimal(20, 6) UNSIGNED NOT NULL DEFAULT 0.000000 COMMENT '手续费',
  `fee_type` bit(1) NOT NULL DEFAULT b'0' COMMENT '手续费收取方式',
  `status` enum('PENDING','PROCESSING','COMPLETED','FAILED','REJECTED','CANCELED') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '状态',
  `reject_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '驳回理由',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '处理时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_merchant_id`(`merchant_id` ASC) USING BTREE,
  INDEX `idx_status_created`(`status` ASC, `created_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '商户提款记录表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_order
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_order`;
CREATE TABLE `kkpay_order`  (
  `trade_no` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '平台订单号',
  `out_trade_no` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '商户订单号',
  `api_trade_no` varchar(256) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '上游订单号',
  `bill_trade_no` varchar(256) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '商户交易单号',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `payment_type` enum('Alipay','WechatPay','Bank','UnionPay','QQWallet','JDPay','PayPal') CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '支付方式',
  `payment_channel_account_id` int NOT NULL COMMENT '支付子账户ID',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '订单标题',
  `total_amount` decimal(20, 6) NOT NULL COMMENT '订单总金额',
  `buyer_pay_amount` decimal(20, 6) NULL DEFAULT NULL COMMENT '用户在交易中支付的金额',
  `receipt_amount` decimal(20, 6) NULL DEFAULT NULL COMMENT '商户实收金额(分成后)',
  `fee_amount` decimal(20, 6) NULL DEFAULT NULL COMMENT '平台手续费金额',
  `profit_amount` decimal(20, 6) NULL DEFAULT NULL COMMENT '订单利润',
  `notify_url` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '异步通知地址',
  `return_url` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '同步通知地址',
  `attach` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '附加参数（原样返回）',
  `quit_url` varchar(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT '' COMMENT '中途退出地址',
  `trade_state` enum('WAIT_BUYER_PAY','TRADE_CLOSED','TRADE_SUCCESS','TRADE_FINISHED','TRADE_FROZEN') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'WAIT_BUYER_PAY' COMMENT '交易状态',
  `settle_state` enum('PENDING','PROCESSING','COMPLETED','FAILED') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '结算状态',
  `payment_url` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '记忆支付地址',
  `notify_state` bit(1) NOT NULL DEFAULT b'0' COMMENT '通知状态 0:失败 1:成功',
  `notify_retry_count` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '重试次数',
  `notify_next_retry_time` timestamp NULL DEFAULT NULL COMMENT '通知下次重试时间',
  `create_time` timestamp NOT NULL COMMENT '交易创建时间',
  `payment_time` timestamp NULL DEFAULT NULL COMMENT '交易付款时间',
  `close_time` timestamp NULL DEFAULT NULL COMMENT '交易结束/关闭时间',
  `update_time` timestamp NULL DEFAULT NULL COMMENT '订单最后更新时间',
  PRIMARY KEY (`trade_no`) USING BTREE,
  INDEX `idx_out_trade_no`(`out_trade_no` ASC) USING BTREE,
  INDEX `idx_api_trade_no`(`api_trade_no` ASC) USING BTREE,
  INDEX `idx_bill_trade_no`(`bill_trade_no` ASC) USING BTREE,
  INDEX `idx_payment_channel_account_id`(`payment_channel_account_id` ASC) USING BTREE,
  INDEX `idx_create_time`(`create_time` ASC) USING BTREE,
  INDEX `idx_trade_state_ctime`(`trade_state` ASC, `create_time` ASC) USING BTREE,
  INDEX `idx_settle_state_ctime`(`settle_state` ASC, `create_time` ASC) USING BTREE,
  INDEX `idx_merchant_ctime`(`merchant_id` ASC, `create_time` ASC) USING BTREE,
  INDEX `idx_merchant_state_ctime`(`merchant_id` ASC, `trade_state` ASC, `create_time` ASC) USING BTREE,
  INDEX `idx_state_retry_nextretry`(`trade_state` ASC, `notify_state` ASC, `notify_retry_count` ASC, `notify_next_retry_time` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '订单表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_order_buyer_info
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_order_buyer_info`;
CREATE TABLE `kkpay_order_buyer_info`  (
  `trade_no` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '平台订单号',
  `ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'IP地址',
  `user_agent` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '浏览器标识',
  `buyer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '付款人信息',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`trade_no`) USING BTREE,
  INDEX `idx_ip`(`ip` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '订单买家信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_order_notification
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_order_notification`;
CREATE TABLE `kkpay_order_notification`  (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '通知校验ID',
  `trade_no` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '平台订单号',
  `status` bit(1) NOT NULL DEFAULT b'0' COMMENT '状态 0:失败 1:成功',
  `content` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT '' COMMENT '返回内容',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_trade_no`(`trade_no` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '订单异步通知表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_order_refund
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_order_refund`;
CREATE TABLE `kkpay_order_refund`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '退款流水号',
  `merchant_id` int UNSIGNED NOT NULL COMMENT '商户ID',
  `initiate_type` enum('admin','api','merchant','system') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'system' COMMENT '发起类型',
  `trade_no` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '平台订单号',
  `out_trade_no` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '商户订单号',
  `api_trade_no` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '上游订单号',
  `out_biz_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '商家业务号',
  `amount` decimal(20, 6) NOT NULL COMMENT '退款金额',
  `refund_state` enum('PENDING','PROCESSING','COMPLETED','FAILED','REJECTED','CANCELED') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '状态',
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '退款原因',
  `reject_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '驳回理由',
  `admin_id` tinyint UNSIGNED NOT NULL COMMENT '操作管理员ID',
  `notify_state` bit(1) NOT NULL DEFAULT b'1' COMMENT '通知状态 0:失败 1:成功',
  `notify_retry_count` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '重试次数',
  `notify_next_retry_time` timestamp NULL DEFAULT NULL COMMENT '通知下次重试时间',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_trade_no`(`trade_no` ASC) USING BTREE,
  INDEX `idx_state_retry_nextretry`(`refund_state` ASC, `notify_state` ASC, `notify_retry_count` ASC, `notify_next_retry_time` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '订单退款表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_payment_channel
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_payment_channel`;
CREATE TABLE `kkpay_payment_channel`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '通道编码',
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '通道名称',
  `payment_type` enum('Alipay','WechatPay','Bank','UnionPay','QQWallet','JDPay','PayPal') CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '支付方式',
  `gateway` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '网关代码(插件类名)',
  `costs` decimal(5, 4) NOT NULL COMMENT '费率成本',
  `fixed_costs` decimal(20, 6) NOT NULL DEFAULT 0.000000 COMMENT '固定成本',
  `rate` decimal(5, 4) NOT NULL COMMENT '费率',
  `fixed_fee` decimal(20, 6) NULL DEFAULT NULL COMMENT '固定手续费',
  `min_fee` decimal(20, 6) NULL DEFAULT NULL COMMENT '最低手续费',
  `max_fee` decimal(20, 6) NULL DEFAULT NULL COMMENT '最高手续费',
  `min_amount` decimal(20, 6) UNSIGNED NULL DEFAULT NULL COMMENT '单笔最小金额',
  `max_amount` decimal(20, 6) UNSIGNED NULL DEFAULT NULL COMMENT '单笔最大金额',
  `daily_limit` decimal(20, 6) UNSIGNED NULL DEFAULT NULL COMMENT '单日收款限额',
  `earliest_time` char(5) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '最早可用时间',
  `latest_time` char(5) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '最晚可用时间',
  `roll_mode` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '子账户轮询模式',
  `settle_cycle` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '结算周期',
  `status` bit(1) NOT NULL DEFAULT b'0' COMMENT '状态',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_code`(`code` ASC) USING BTREE,
  INDEX `idx_softdelete`(`deleted_at` ASC) USING BTREE COMMENT '软删除'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '支付通道表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for kkpay_payment_channel_account
-- ----------------------------
DROP TABLE IF EXISTS `kkpay_payment_channel_account`;
CREATE TABLE `kkpay_payment_channel_account`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '子账户名称',
  `payment_channel_id` int UNSIGNED NOT NULL COMMENT '支付通道ID',
  `rate_mode` bit(1) NOT NULL DEFAULT b'0' COMMENT '费率模式 0:继承 1:自定义',
  `rate` decimal(5, 4) NULL DEFAULT NULL COMMENT '费率',
  `min_amount` decimal(20, 6) NULL DEFAULT NULL COMMENT '单笔最小金额(留空继承)',
  `max_amount` decimal(20, 6) NULL DEFAULT NULL COMMENT '单笔最大金额(留空继承)',
  `daily_limit` decimal(20, 6) NULL DEFAULT NULL COMMENT '单日收款限额(留空继承)',
  `earliest_time` char(5) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '最早可用时间(留空继承)',
  `latest_time` char(5) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '最晚可用时间(留空继承)',
  `config` json NULL COMMENT '配置',
  `status` bit(1) NOT NULL DEFAULT b'0' COMMENT '状态',
  `maintenance` bit(1) NOT NULL DEFAULT b'0' COMMENT '维护状态',
  `remark` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '备注',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `payment_channel_id`(`payment_channel_id` ASC) USING BTREE,
  INDEX `idx_softdelete`(`deleted_at` ASC) USING BTREE COMMENT '软删除'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '支付通道子账户表' ROW_FORMAT = DYNAMIC;

SET FOREIGN_KEY_CHECKS = 1;
