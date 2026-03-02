<?php

declare(strict_types=1);

namespace app\model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use support\Model;

/**
 * 支付网关错误日志表
 */
class PaymentGatewayLog extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'payment_gateway_log';

    /**
     * 禁用自动写入updated_at
     *
     * @var null
     */
    const null UPDATED_AT = null;

    /**
     * 获取触发时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 记录支付网关错误日志
     *
     * @param string      $gateway       网关代码
     * @param string      $method        调用方法
     * @param string      $error_message 错误信息
     * @param string|null $trade_no      关联平台订单号
     * @return void
     */
    public static function record(string $gateway, string $method, string $error_message, ?string $trade_no = null): void
    {
        $row                = new self();
        $row->trade_no      = $trade_no;
        $row->gateway       = $gateway;
        $row->method        = $method;
        $row->error_message = mb_substr($error_message, 0, 2048);
        $row->save();
    }
}
