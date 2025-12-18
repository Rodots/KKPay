<?php

declare(strict_types=1);

namespace app\model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 管理员操作日志表
 */
class RiskLog extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'risk_log';

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
     * 可批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'merchant_id',
        'type',
        'content',
    ];

    const int TYPE_BLACKLIST          = 0; // 命中黑名单
    const int TYPE_SUBJECT_KEYWORD    = 1; // 命中禁售商品关键词
    const int TYPE_ORDER_SUCCESS_RATE = 2; // 订单成功率过低

    /***
     * 访问器：风控类型文本
     *
     * @return Attribute
     */
    protected function typeText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::TYPE_BLACKLIST          => '命中黑名单',
                    self::TYPE_SUBJECT_KEYWORD    => '禁售商品',
                    self::TYPE_ORDER_SUCCESS_RATE => '成功率过低',
                ];
                return $enum[$this->getOriginal('type')] ?? '未知';
            }
        );
    }

    /**
     * 获取创建时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 获取对应商户信息
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }
}
