<?php

declare(strict_types=1);

namespace app\model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 商户操作日志表
 */
class MerchantLog extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_log';

    /**
     * 禁用自动写入updated_at
     *
     * @var null
     */
    const null UPDATED_AT = null;

    /**
     * 获取应该转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer'
        ];
    }

    /**
     * 为数组 / JSON 序列化准备日期。
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * 获取对应商户信息
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }
}
