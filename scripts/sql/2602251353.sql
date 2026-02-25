ALTER TABLE `kkpay_merchant`
ADD COLUMN `nickname` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '商户昵称' AFTER `merchant_number`;
