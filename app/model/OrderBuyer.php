<?php

declare(strict_types=1);

namespace app\model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 订单买家表
 */
class OrderBuyer extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'order_buyer';

    /**
     * 与表关联的主键。
     *
     * @var string
     */
    protected $primaryKey = 'trade_no';

    /**
     * 指示模型的 ID 是否是自动递增的。
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * 主键 ID 的数据类型。
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * 可以被批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'trade_no',
        'ip',
        'user_agent',
        'user_id',
        'mobile'
    ];

    /**
     * 访问器：创建时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：更新时间
     */
    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 该买家属于这个订单
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'trade_no', 'trade_no');
    }
}
