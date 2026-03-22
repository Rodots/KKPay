<?php

declare(strict_types=1);

namespace app\model;

use DateTimeInterface;
use support\Model;

/**
 * 商户邮件发送日志表
 */
class MerchantEmailLog extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_email_log';

    /**
     * 获取应该转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'send_time'   => 'datetime'
        ];
    }

    /**
     * 为数组 / JSON 序列化准备日期。
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
