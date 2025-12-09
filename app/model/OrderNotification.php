<?php

declare(strict_types = 1);

namespace app\model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use support\Model;

/**
 * 订单异步通知表
 */
class OrderNotification extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'order_notification';

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
     * 禁用自动写入updated_at
     *
     * @var null
     */
    const null UPDATED_AT = null;

    /**
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'status'           => 'boolean',
            'request_duration' => 'integer'
        ];
    }

    /**
     * 访问器：通知时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::rawParse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }
}
