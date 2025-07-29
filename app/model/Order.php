<?php

declare(strict_types = 1);

namespace app\model;

use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use support\Model;

/**
 * 站点配置表
 */
class Order extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'order';

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
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'merchant_id'                => 'integer',
            'payment_channel_account_id' => 'integer',
            'total_amount'               => 'decimal:6',
            'buyer_pay_amount'           => 'decimal:6',
            'receipt_amount'             => 'decimal:6',
            'fee_amount'                 => 'decimal:6',
            'profit_amount'              => 'decimal:6',
            'notify_state'               => 'boolean',
            'notify_retry_count'         => 'integer',
            'notify_next_retry_time'     => 'timestamp',
            'create_time'                => 'timestamp',
            'payment_time'               => 'timestamp',
            'close_time'                 => 'timestamp',
            'update_time'                => 'timestamp'
        ];
    }

    // 时间字段配置
    const string CREATED_AT = 'create_time';
    const string UPDATED_AT = 'update_time';

    // 交易状态枚举
    const string TRADE_STATUS_WAIT_BUYER_PAY = 'WAIT_BUYER_PAY';
    const string TRADE_STATUS_CLOSED         = 'TRADE_CLOSED';
    const string TRADE_STATUS_SUCCESS        = 'TRADE_SUCCESS';
    const string TRADE_STATUS_FINISHED       = 'TRADE_FINISHED';
    const string TRADE_STATUS_FROZEN         = 'TRADE_FROZEN';

    // 结算状态枚举
    const string SETTLE_STATUS_PENDING    = 'PENDING';
    const string SETTLE_STATUS_PROCESSING = 'PROCESSING';
    const string SETTLE_STATUS_COMPLETED  = 'COMPLETED';
    const string SETTLE_STATUS_FAILED     = 'FAILED';

    /***
     * 获取器【交易状态文本】
     *
     * @return Attribute
     */
    protected function tradeStatusText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::TRADE_STATUS_WAIT_BUYER_PAY => '等待付款',
                    self::TRADE_STATUS_CLOSED         => '交易关闭',
                    self::TRADE_STATUS_SUCCESS        => '交易成功',
                    self::TRADE_STATUS_FINISHED       => '交易完成',
                    self::TRADE_STATUS_FROZEN         => '交易冻结',
                ];
                return $enum[$this->getOriginal('trade_status')] ?? '未知';
            }
        );
    }

    /**
     * 获取器【结算状态文本】
     *
     * @return Attribute
     */
    protected function settleStatusText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::SETTLE_STATUS_PENDING    => '待结算',
                    self::SETTLE_STATUS_PROCESSING => '结算中',
                    self::SETTLE_STATUS_COMPLETED  => '已结算',
                    self::SETTLE_STATUS_FAILED     => '结算失败',
                ];
                return $enum[$this->getOriginal('settle_status')] ?? '未知';
            }
        );
    }

    /**
     * 一个订单可以存在多次退款。
     */
    public function OrderRefund(): HasMany
    {
        return $this->hasMany(OrderRefund::class, 'trade_no', 'trade_no');
    }

    /**
     * 该订单属于这个通道子账户
     */
    public function paymentChannelAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentChannelAccount::class, 'payment_channel_account_id');
    }

    /**
     * 获取这个订单对应的支付通道
     */
    public function paymentChannel(): HasOneThrough
    {
        return $this->hasOneThrough(
            PaymentChannel::class,
            PaymentChannelAccount::class,
            'id', // PaymentChannelAccount 表中与 Order 表关联的外键
            'id', // PaymentChannel 表中与 PaymentChannelAccount 表关联的外键
            'payment_channel_account_id', // Order 表中与 PaymentChannelAccount 表关联的外键
            'payment_channel_id' // PaymentChannelAccount 表中与 PaymentChannel 表关联的外键
        );
    }

    /**
     * 退款处理
     *
     * @param float      $amount
     * @param int|string $api_trade_no
     * @return void
     * @throws Exception
     */
    public function refundProcessing(float $amount, int|string $api_trade_no = ''): void
    {
        // 刷新数据
        $order = $this->refresh();

        $trade_no = $order->getAttribute('trade_no');
        $user_id  = $order->getAttribute('user_id');

        if (in_array($order->getOriginal('status'), [
            self::TRADE_STATUS_WAIT_BUYER_PAY,
            self::TRADE_STATUS_CLOSED,
            self::TRADE_STATUS_FROZEN,
            self::TRADE_STATUS_CLOSED
        ])) {
            throw new Exception("订单号 $trade_no 的状态不支持退款");
        }

        // 关联订单退款表查询
        $orderWithRefunds = self::withSum('OrderRefund', 'amount')->withSum('OrderRefund', 'real_amount')->find($trade_no);

        // 订单金额
        $order_amount = $order->getAttribute('amount');

        // 商户分成金额
        $get_amount = $order->getAttribute('get_amount');

        // 已退款金额，如果没有记录则默认为0
        $refunded_amount = $orderWithRefunds->OrderRefund_sum_amount ?? 0;

        // 已真实扣除金额，如果没有记录则默认为0
        $real_refunded_amount = $orderWithRefunds->OrderRefund_sum_real_amount ?? 0;

        // 剩余可退款金额
        $residue_refunded_amount = $order_amount - $refunded_amount;

        // 剩余可扣除金额
        $residue_real_refunded_amount = $get_amount - $real_refunded_amount;

        // 使用 bccomp 进行精确比较
        if (bccomp((string)$amount, (string)$residue_refunded_amount, 2) > 0) {
            throw new Exception("订单号 $trade_no 的退款金额不能超过剩余可退款金额");
        }

        $real_amount = min($residue_real_refunded_amount, $amount);

        // 新增订单退款记录
        $this->OrderRefund()->create([
            'trade_no'     => $trade_no,
            'api_trade_no' => $api_trade_no ?: null,
            'user_id'      => $user_id,
            'amount'       => $amount,
            'real_amount'  => $real_amount,
            'status'       => 1
        ]);

        // 判断是全额退款还是部分退款并更新订单状态
        $this->update([
            'status' => (bccomp((string)$amount, (string)$order_amount, 2) === 0) ? self::STATUS_FULL_REFUND : self::STATUS_PARTIAL_REFUND
        ]);

        // 根据这个订单对应支付通道的扣费模式处理商户的余额增减
        if ($this->paymentChannel()->value('mode') === 0) {
            // 【第一种模式】资金由平台代收，然后结算给商户，手续费从每笔订单直接扣除
            if (!BalanceRecord::change($user_id, $real_amount, '订单退款', 1, $trade_no)) {
                throw new Exception("更新商户余额失败");
            }
        }
    }
}
