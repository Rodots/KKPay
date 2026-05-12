ALTER TABLE `kkpay_payment_gateway_log` 
MODIFY COLUMN `method` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '调用方法' AFTER `gateway`;