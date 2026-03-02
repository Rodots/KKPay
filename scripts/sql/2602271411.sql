ALTER TABLE `kkpay_merchant`
ADD COLUMN `nickname` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '商户昵称' AFTER `merchant_number`;

UPDATE `kkpay_order_buyer` SET `min_age` = 0 WHERE `min_age` = NULL;

ALTER TABLE `kkpay_order_buyer`
MODIFY COLUMN `min_age` tinyint UNSIGNED NOT NULL DEFAULT 1 COMMENT '最小年龄' AFTER `cert_type`;

CREATE TABLE IF NOT EXISTS `kkpay_payment_gateway_log`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `trade_no` char(24) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT '关联平台订单号',
  `gateway` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '网关代码',
  `method` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '调用方法',
  `error_message` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '错误信息',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '触发时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_trade_no`(`trade_no` ASC) USING BTREE,
  INDEX `idx_gateway`(`gateway` ASC) USING BTREE,
  INDEX `idx_created`(`created_at` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '支付网关错误日志表' ROW_FORMAT = DYNAMIC;
