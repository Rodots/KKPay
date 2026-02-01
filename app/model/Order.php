<?php

declare(strict_types=1);

namespace app\model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use support\Model;

/**
 * 订单表
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
            'settle_cycle'               => 'integer',
            'notify_retry_count'         => 'integer',
            'notify_next_retry_time'     => 'integer',
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
        'fee_amount',
        'profit_amount',
        'notify_url',
        'return_url',
        'attach',
        'settle_cycle',
        'sign_type',
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

    // 支付方式枚举值与中文名称映射
    const array PAYMENT_TYPE_MAP = [
        self::PAYMENT_TYPE_ALIPAY    => '支付宝',
        self::PAYMENT_TYPE_WECHATPAY => '微信支付',
        self::PAYMENT_TYPE_BANK      => '银联/银行卡',
        self::PAYMENT_TYPE_UNIONPAY  => '云闪付',
        self::PAYMENT_TYPE_QQWALLET  => 'QQ钱包',
        self::PAYMENT_TYPE_JDPAY     => '京东支付',
        self::PAYMENT_TYPE_PAYPAL    => 'PayPal',
    ];

    // 交易状态枚举
    const string TRADE_STATE_WAIT_PAY = 'WAIT_PAY'; // 交易创建，等待买家付款。
    const string TRADE_STATE_CLOSED   = 'TRADE_CLOSED'; // 未付款交易超时关闭。
    const string TRADE_STATE_SUCCESS  = 'TRADE_SUCCESS'; // 交易支付成功。
    const string TRADE_STATE_REFUND   = 'TRADE_REFUND'; // 交易部分退款（仍可继续退款）。
    const string TRADE_STATE_FINISHED = 'TRADE_FINISHED'; // 全额退款（不支持退款、已超过可退款期限，已全额退款）。
    const string TRADE_STATE_FROZEN   = 'TRADE_FROZEN'; // 交易冻结（暂停结算、退款等操作）。

    // 结算状态枚举
    const string SETTLE_STATE_PENDING    = 'PENDING';
    const string SETTLE_STATE_PROCESSING = 'PROCESSING';
    const string SETTLE_STATE_COMPLETED  = 'COMPLETED';
    const string SETTLE_STATE_FAILED     = 'FAILED';

    // 通知状态枚举
    const string NOTIFY_STATE_WAITING = 'WAITING';
    const string NOTIFY_STATE_SUCCESS = 'SUCCESS';
    const string NOTIFY_STATE_FAILED  = 'FAILED';

    /**
     * 模型启动方法，用于注册模型事件
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($row) {
            // 生成一个24位的时间序列号作为订单号，并确保不重复
            $now     = microtime(true);
            $seconds = (int)$now;
            $micros  = (int)(($now - $seconds) * 1000000); // 取微秒级后6位
            // 组合：业务类型(1) + 时间(12) + 微秒(6) + 随机英文字母(5) = 24位
            $row->trade_no    = 'P' . date('ymdHis', $seconds) . str_pad((string)$micros, 6, '0', STR_PAD_LEFT) . random(5, 'upper');
            $row->create_time = Carbon::now();
        });
    }

    /**
     * 访问器：交易创建时间
     */
    protected function createTime(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::rawParse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：交易创建时间（含时区）
     */
    protected function createTimeWithZone(): Attribute
    {
        // 遵循rfc3339标准格式: yyyy-MM-DDTHH:mm:ss+TIMEZONE
        return Attribute::make(
            get: fn(?string $value, array $attributes) => $attributes['create_time'] ? Carbon::rawParse($attributes['create_time'])->timezone(config('app.default_timezone'))->format('Y-m-d\TH:i:sP') : null,
        );
    }

    /**
     * 访问器/修改器：交易付款时间
     */
    protected function paymentTime(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::rawParse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
            set: fn(string|int|null $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：交易付款时间（含时区）
     */
    protected function paymentTimeWithZone(): Attribute
    {
        // 遵循rfc3339标准格式: yyyy-MM-DDTHH:mm:ss+TIMEZONE
        return Attribute::make(
            get: fn(?string $value, array $attributes) => $attributes['payment_time'] ? Carbon::rawParse($attributes['payment_time'])->timezone(config('app.default_timezone'))->format('Y-m-d\TH:i:sP') : null,
        );
    }

    /**
     * 访问/修改器：交易结束/关闭时间
     */
    protected function closeTime(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::rawParse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
            set: fn(string|int|null $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：订单最后更新时间
     */
    protected function updateTime(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::rawParse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：支付方式文本
     */
    protected function paymentTypeText(): Attribute
    {
        return Attribute::make(get: fn() => self::PAYMENT_TYPE_MAP[$this->getOriginal('payment_type')] ?? ($this->getOriginal('payment_type') === self::PAYMENT_TYPE_NONE ? '未选择' : '未知'));
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
                    self::TRADE_STATE_REFUND   => '部分退款',
                    self::TRADE_STATE_FINISHED => '全额退款',
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
     * 访问器：通知状态文本
     *
     * @return Attribute
     */
    protected function notifyStateText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::NOTIFY_STATE_WAITING => '等待通知',
                    self::NOTIFY_STATE_SUCCESS => '通知成功',
                    self::NOTIFY_STATE_FAILED  => '通知失败',
                ];
                return $enum[$this->getOriginal('notify_state')] ?? '未知';
            }
        );
    }

    /**
     * 访问器：付款耗时
     *
     * @return Attribute
     */
    protected function paymentDuration(): Attribute
    {
        return Attribute::make(
            get: function (?string $value, array $attributes) {
                if (!$attributes['payment_time']) {
                    return '0秒';
                }
                $create       = Carbon::parse($attributes['create_time']);
                $payment      = Carbon::parse($attributes['payment_time']);
                $totalSeconds = $create->diffInSeconds($payment);
                // 格式化时间
                return $this->formatPaymentDuration($totalSeconds);
            }
        );
    }

    /**
     * 将秒数格式化为易读的时间格式
     *
     * @param float $seconds
     * @return string
     */
    private function formatPaymentDuration(float $seconds): string
    {
        if ($seconds <= 0) {
            return '异常';
        }

        $days    = floor($seconds / 86400);
        $hours   = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs    = $seconds % 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = $days . '天';
        }
        if ($hours > 0) {
            $parts[] = $hours . '时';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . '分';
        }
        if ($secs > 0 || empty($parts)) { // 如果没有其他单位，秒数必须显示
            $parts[] = $secs . '秒';
        }

        return implode('', $parts);
    }

    /**
     * 访问器：是否为黑名单订单
     *
     * @return Attribute
     */
    protected function isBlacklist(): Attribute
    {
        return Attribute::make(
            get: function () {
                $buyer = $this->buyer ?? $this->getRelationValue('buyer');
                if (!$buyer) {
                    return false;
                }
                return $this->checkBuyerBlacklist($buyer->ip ?? null, $buyer->user_id ?? null, $buyer->buyer_open_id ?? null, $buyer->mobile ?? null);
            }
        );
    }

    /**
     * 访问器：用户行为摘要
     *
     * @return Attribute
     */
    protected function userBehaviorSummary(): Attribute
    {
        return Attribute::make(
            get: function () {
                // 加载买家信息
                $buyer = $this->buyer ?? $this->getRelationValue('buyer');

                if (!$buyer) {
                    return [
                        'is_blacklist' => false,
                        'total_orders' => 0,
                        'paid_orders'  => 0,
                        'success_rate' => '0.00%',
                        'message'      => '无买家信息',
                    ];
                }

                $ip          = $buyer->ip ?? null;
                $userId      = $buyer->user_id ?? null;
                $buyerOpenId = $buyer->buyer_open_id ?? null;
                $mobile      = $buyer->mobile ?? null;

                // 1. 检查黑名单
                $isBlacklist = $this->checkBuyerBlacklist($ip, $userId, $buyerOpenId, $mobile);

                // 2. 统计订单数
                [$totalOrders, $paidOrders] = $this->countUserOrders($ip, $userId, $buyerOpenId, $mobile);

                // 3. 计算成功率
                $successRate = $totalOrders > 0 ? bcmul(bcdiv((string)$paidOrders, (string)$totalOrders, 4), '100', 2) . '%' : '0.00%';

                // 4. 拼接统计文案
                $message = ($isBlacklist ? '黑名单用户，' : '') . "累计下单 $totalOrders 笔，成功支付 $paidOrders 笔，成功率 $successRate";

                return [
                    'is_blacklist' => $isBlacklist,
                    'total_orders' => $totalOrders,
                    'paid_orders'  => $paidOrders,
                    'success_rate' => $successRate,
                    'message'      => $message,
                ];
            }
        );
    }

    /**
     * 检查买家是否命中黑名单（排除已过期）
     */
    private function checkBuyerBlacklist(?string $ip, ?string $userId, ?string $buyerOpenId, ?string $mobile): bool
    {
        $now = Carbon::now()->timezone(config('app.default_timezone'));

        $checkBlacklist = function (string $entityType, ?string $entityValue) use ($now): bool {
            if (empty($entityValue)) {
                return false;
            }
            $entityHash = hash('sha3-224', $entityType . $entityValue);
            return Blacklist::where('entity_hash', $entityHash)->where(fn($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', $now))->exists();
        };

        // 检查 IP 地址
        if ($checkBlacklist(Blacklist::ENTITY_TYPE_IP_ADDRESS, $ip)) {
            return true;
        }
        // 检查用户 ID
        if ($checkBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $userId)) {
            return true;
        }
        // 检查买家 OpenID
        if ($checkBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $buyerOpenId)) {
            return true;
        }
        // 检查手机号
        if ($checkBlacklist(Blacklist::ENTITY_TYPE_MOBILE, $mobile)) {
            return true;
        }

        return false;
    }

    /**
     * 统计用户历史订单数
     *
     * @return array [总订单数, 成功付款订单数]
     */
    private function countUserOrders(?string $ip, ?string $userId, ?string $buyerOpenId, ?string $mobile): array
    {
        // 如果没有任何标识信息，返回当前订单的统计
        if (empty($ip) && empty($userId) && empty($buyerOpenId) && empty($mobile)) {
            $isPaid = in_array($this->trade_state, [self::TRADE_STATE_SUCCESS, self::TRADE_STATE_REFUND, self::TRADE_STATE_FINISHED, self::TRADE_STATE_FROZEN]);
            return [1, $isPaid ? 1 : 0];
        }

        // 构建查询条件，匹配任意一个标识，使用 distinct 去重
        $tradeNos = OrderBuyer::query()
            ->where(function ($q) use ($ip, $userId, $buyerOpenId, $mobile) {
                if (!empty($ip)) {
                    $q->orWhere('ip', $ip);
                }
                if (!empty($userId)) {
                    $q->orWhere('user_id', $userId);
                }
                if (!empty($buyerOpenId)) {
                    $q->orWhere('buyer_open_id', $buyerOpenId);
                }
                if (!empty($mobile)) {
                    $q->orWhere('mobile', $mobile);
                }
            })
            ->distinct()
            ->pluck('trade_no');

        $totalOrders = $tradeNos->count();

        if ($totalOrders === 0) {
            return [0, 0];
        }

        // 统计成功付款的订单
        $paidOrders = Order::whereIn('trade_no', $tradeNos)
            ->whereIn('trade_state', [self::TRADE_STATE_SUCCESS, self::TRADE_STATE_REFUND, self::TRADE_STATE_FINISHED, self::TRADE_STATE_FROZEN])
            ->count();

        return [$totalOrders, $paidOrders];
    }

    /**
     * 该订单属于这个商户
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
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
    public function buyer(): HasOne
    {
        return $this->hasOne(OrderBuyer::class, 'trade_no', 'trade_no');
    }

    /**
     * 一个订单可以存在多次退款
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class, 'trade_no', 'trade_no');
    }

    /**
     * 一个订单可以进行多次通知
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(OrderNotification::class, 'trade_no', 'trade_no');
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
     * 批量赋值创建订单
     */
    public static function createOrderRecord(array $fillData): Order
    {
        $order = new self();
        $order->fill($fillData);
        $order->save();

        return $order;
    }
}
