<?php

declare(strict_types=1);

namespace app\model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 商户结算收款信息表
 */
class MerchantPayee extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_payee';

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
            'merchant_id' => 'integer',
            'payee_info'  => 'array',
            'is_default'  => 'boolean'
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
     * 可批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'merchant_id',
        'payee_info',
        'is_default',
    ];

    /**
     * 模型启动方法，用于注册模型事件
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // 创建时自动将新收款人设为默认，并将该商户其他收款人置为非默认
        static::creating(function ($row) {
            // 将该商户下所有现有收款人设为非默认
            self::where('merchant_id', $row->merchant_id)->update(['is_default' => false]);
            // 新增的收款人设为默认
            $row->is_default = true;
        });
    }

    /**
     * 关联商户
     *
     * @return BelongsTo
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id')->withTrashed();
    }
}
