<?php

declare(strict_types = 1);

namespace app\model;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
            'total_amount'               => 'decimal:2',
            'buyer_pay_amount'           => 'decimal:2',
            'receipt_amount'             => 'decimal:2',
            'fee_amount'                 => 'decimal:2',
            'profit_amount'              => 'decimal:2',
            'notify_state'               => 'boolean',
            'notify_retry_count'         => 'integer',
            'notify_next_retry_time'     => 'timestamp',
            'create_time'                => 'timestamp',
            'payment_time'               => 'timestamp',
            'close_time'                 => 'timestamp',
            'update_time'                => 'timestamp'
        ];
    }

    /**
     * 可批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'out_trade_no',
        'merchant_id',
        'payment_type',
        'payment_channel_account_id',
        'subject',
        'total_amount',
        'buyer_pay_amount',
        'receipt_amount',
        'notify_url',
        'return_url',
        'attach',
        'quit_url',
        'domain',
        'close_time',
    ];

    // 时间字段配置
    const string CREATED_AT = 'create_time';
    const string UPDATED_AT = 'update_time';

    // 支付方式枚举
    const string PAYMENT_TYPE_NONE      = 'None';
    const string PAYMENT_TYPE_ALIPAY    = 'Alipay';
    const string PAYMENT_TYPE_WECHATPAY = 'WechatPay';
    const string PAYMENT_TYPE_BANK      = 'Bank';
    const string PAYMENT_TYPE_UNIONPAY  = 'UnionPay';
    const string PAYMENT_TYPE_QQWALLET  = 'QQWallet';
    const string PAYMENT_TYPE_JDPAY     = 'JDPay';
    const string PAYMENT_TYPE_PAYPAL    = 'PayPal';

    // 交易状态枚举
    const string TRADE_STATE_WAIT_PAY = 'WAIT_PAY';
    const string TRADE_STATE_CLOSED   = 'TRADE_CLOSED';
    const string TRADE_STATE_SUCCESS  = 'TRADE_SUCCESS';
    const string TRADE_STATE_FINISHED = 'TRADE_FINISHED';
    const string TRADE_STATE_FROZEN   = 'TRADE_FROZEN';

    // 结算状态枚举
    const string SETTLE_STATE_PENDING    = 'PENDING';
    const string SETTLE_STATE_PROCESSING = 'PROCESSING';
    const string SETTLE_STATE_COMPLETED  = 'COMPLETED';
    const string SETTLE_STATE_FAILED     = 'FAILED';

    /**
     * 模型启动方法，用于注册模型事件
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($order) {
            // 生成一个24位的时间序列号作为订单号，并确保不重复
            $now     = microtime(true);
            $seconds = (int)$now;
            $micros  = (int)(($now - $seconds) * 1000000); // 取微秒级后6位
            // 组合：业务类型(1) + 时间(12) + 微秒(6) + 随机字母(5) = 24位
            $order->trade_no    = 'P' . date('ymdHis', $seconds) . str_pad((string)$micros, 6, '0', STR_PAD_LEFT) . random(5, 'upper');
            $order->create_time = Carbon::now()->timezone(config('app.default_timezone'));
        });
    }

    /**
     * 访问器：交易创建时间
     */
    protected function createTime(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：交易付款时间
     */
    protected function paymentTime(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：交易结束/关闭时间
     */
    protected function closeTime(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：订单最后更新时间
     */
    protected function updateTime(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /***
     * 访问器：交易状态文本
     *
     * @return Attribute
     */
    protected function paymentTypeText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::PAYMENT_TYPE_NONE      => '未选择',
                    self::PAYMENT_TYPE_ALIPAY    => '支付宝',
                    self::PAYMENT_TYPE_WECHATPAY => '微信支付',
                    self::PAYMENT_TYPE_BANK      => '银联/银行卡',
                    self::PAYMENT_TYPE_UNIONPAY  => '云闪付',
                    self::PAYMENT_TYPE_QQWALLET  => 'QQ钱包',
                    self::PAYMENT_TYPE_JDPAY     => '京东支付',
                    self::PAYMENT_TYPE_PAYPAL    => 'PayPal',
                ];
                return $enum[$this->getOriginal('payment_type')] ?? '未知';
            }
        );
    }

    /***
     * 访问器：交易状态文本
     *
     * @return Attribute
     */
    protected function tradeStateText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::TRADE_STATE_WAIT_PAY => '等待付款',
                    self::TRADE_STATE_CLOSED   => '交易关闭',
                    self::TRADE_STATE_SUCCESS  => '交易成功',
                    self::TRADE_STATE_FINISHED => '交易完成',
                    self::TRADE_STATE_FROZEN   => '交易冻结',
                ];
                return $enum[$this->getOriginal('trade_state')] ?? '未知';
            }
        );
    }

    /**
     * 访问器：结算状态文本
     *
     * @return Attribute
     */
    protected function settleStateText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::SETTLE_STATE_PENDING    => '待结算',
                    self::SETTLE_STATE_PROCESSING => '结算中',
                    self::SETTLE_STATE_COMPLETED  => '已结算',
                    self::SETTLE_STATE_FAILED     => '结算失败',
                ];
                return $enum[$this->getOriginal('settle_state')] ?? '未知';
            }
        );
    }

    /**
     * 该订单属于这个商户
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
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
        return $this->hasOneThrough(PaymentChannel::class, PaymentChannelAccount::class, 'id', 'id', 'payment_channel_account_id', 'payment_channel_id');
    }

    /**
     * 订单买家信息
     */
    public function buyerInfo(): HasOne
    {
        return $this->hasOne(OrderBuyer::class, 'trade_no', 'trade_no');
    }

    /**
     * 一个订单可以存在多次退款
     */
    public function OrderRefund(): HasMany
    {
        return $this->hasMany(OrderRefund::class, 'trade_no', 'trade_no');
    }

    /**
     * 获取表名
     *
     * @return string
     */
    public static function getTableName(): string
    {
        return new static()->getTable();
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
            self::TRADE_STATE_WAIT_PAY,
            self::TRADE_STATE_CLOSED,
            self::TRADE_STATE_FINISHED,
            self::TRADE_STATE_FROZEN
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

        // 根据这个订单对应支付通道的扣费模式处理商户的余额增减
        if ($this->paymentChannel()->value('mode') === 0) {
            // 【第一种模式】资金由平台代收，然后结算给商户，手续费从每笔订单直接扣除
            if (!MerchantWalletRecord::change($user_id, $real_amount, '订单退款', 1, $trade_no)) {
                throw new Exception("更新商户余额失败");
            }
        }
    }

    /**
     * 检查传入的支付方式是否合法
     */
    public static function checkPaymentType(?string $payment_type): bool
    {
        if ($payment_type === null) {
            return true;
        }

        return in_array($payment_type, [
            self::PAYMENT_TYPE_ALIPAY,
            self::PAYMENT_TYPE_WECHATPAY,
            self::PAYMENT_TYPE_BANK,
            self::PAYMENT_TYPE_UNIONPAY,
            self::PAYMENT_TYPE_QQWALLET,
            self::PAYMENT_TYPE_JDPAY,
            self::PAYMENT_TYPE_PAYPAL,
        ]);
    }

    /**
     * 创建订单记录
     */
    public static function createOrderRecord(array $fillData): Order
    {
        $order = new self();
        $order->fill($fillData);
        $order->save();

        return $order;
    }
}
