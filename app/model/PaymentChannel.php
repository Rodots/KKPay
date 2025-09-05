<?php

declare(strict_types = 1);

namespace app\model;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Log;
use support\Model;
use Throwable;

/**
 * 支付通道表
 */
class PaymentChannel extends Model
{
    /**
     * 启用软删除。
     */
    use SoftDeletes;

    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'payment_channel';

    /**
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'costs'        => 'decimal:4',
            'fixed_costs'  => 'decimal:2',
            'rate'         => 'decimal:4',
            'fixed_fee'    => 'decimal:2',
            'min_fee'      => 'decimal:2',
            'max_fee'      => 'decimal:2',
            'min_amount'   => 'decimal:2',
            'max_amount'   => 'decimal:2',
            'daily_limit'  => 'decimal:2',
            'roll_mode'    => 'integer',
            'settle_cycle' => 'integer',
            'status'       => 'boolean'
        ];
    }

    // 支付方式枚举
    const string PAYMENT_TYPE_ALIPAY    = 'Alipay';
    const string PAYMENT_TYPE_WECHATPAY = 'WechatPay';
    const string PAYMENT_TYPE_BANK      = 'Bank';
    const string PAYMENT_TYPE_UNIONPAY  = 'UnionPay';
    const string PAYMENT_TYPE_QQWALLET  = 'QQWallet';
    const string PAYMENT_TYPE_JDPAY     = 'JDPay';
    const string PAYMENT_TYPE_PAYPAL    = 'PayPal';

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
     * 获取器【轮询模式文本】
     *
     * @return Attribute
     */
    protected function rollModeText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    0 => '按顺序依次轮询',
                    1 => '随机轮询',
                    2 => '按权重随机轮询',
                    3 => '仅使用第一个可用账户',
                ];
                return $enum[$this->getOriginal('roll_mode')] ?? '未知';
            }
        );
    }

    /***
     * 获取器【结算周期文本】
     *
     * @return Attribute
     */
    protected function settleCycleText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    0  => '【实时】笔笔结算',
                    1  => '【D0】即日结算',
                    2  => '【D1】次日结算',
                    3  => '【D2】隔日结算',
                    4  => '【T0】工作日即日结算',
                    5  => '【T1】工作日次日结算',
                    6  => '【T2】工作日隔日结算',
                    7  => '【D3】交易后3个自然日结算',
                    8  => '【D7】交易后7个自然日结算',
                    9  => '【D14】交易后14个自然日结算',
                    10 => '【D30】交易后30个自然日结算',
                    11 => '【T3】交易后3个工作日结算',
                    12 => '【T7】交易后7个工作日结算',
                    13 => '【T14】交易后14个工作日结算',
                    14 => '【T30】交易后30个工作日结算',
                    15 => '【测试】直接吃单，结算但不加减余额'
                ];
                return $enum[$this->getOriginal('settle_cycle')] ?? '未知';
            }
        );
    }

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
     * 获取该支付通道下的所有子账户
     *
     * @return HasMany
     */
    public function paymentChannelAccount(): HasMany
    {
        return $this->hasMany(PaymentChannelAccount::class);
    }

    /**
     * 创建支付通道
     *
     * @param array $data 数据
     * @return PaymentChannel 成功返回模型对象，失败抛出错误
     * @throws Exception
     */
    public static function createPaymentChannel(array $data): PaymentChannel
    {
        if (self::where('code', $data['code'])->exists()) {
            throw new Exception('该通道编码已被使用');
        }
        try {
            $paymentChannelRow = new self();

            // 基本字段处理
            $paymentChannelRow->code         = strtoupper(trim($data['code'] ?? ''));
            $paymentChannelRow->name         = trim($data['name'] ?? '');
            $paymentChannelRow->payment_type = $data['payment_type'];
            $paymentChannelRow->gateway      = trim($data['gateway'] ?? '');

            // 成本与费率处理（百分比 → 四位小数）
            $paymentChannelRow->costs = isset($data['costs']) ? bcdiv(bcround((string)$data['costs'], 4), '100', 4) : 0.0000;
            $paymentChannelRow->rate  = isset($data['rate']) ? bcdiv(bcround((string)$data['rate'], 4), '100', 4) : 0.0000;

            // 固定费用相关字段
            $paymentChannelRow->fixed_costs = bcround((string)$data['fixed_costs'], 2);
            $paymentChannelRow->fixed_fee   = bcround((string)$data['fixed_fee'], 2);
            $paymentChannelRow->min_fee     = empty($data['min_fee']) ? null : bcround($data['min_fee'], 2);
            $paymentChannelRow->max_fee     = empty($data['max_fee']) ? null : bcround($data['max_fee'], 2);

            // 金额限制字段
            $paymentChannelRow->min_amount  = empty($data['min_amount']) ? null : bcround($data['min_amount'], 2);
            $paymentChannelRow->max_amount  = empty($data['max_amount']) ? null : bcround($data['max_amount'], 2);
            $paymentChannelRow->daily_limit = empty($data['daily_limit']) ? null : bcround($data['daily_limit'], 2);

            // 时间段
            $paymentChannelRow->earliest_time = $data['earliest_time'] ?? null;
            $paymentChannelRow->latest_time   = $data['latest_time'] ?? null;

            // 整型字段
            $paymentChannelRow->roll_mode    = (int)($data['roll_mode'] ?? 0);
            $paymentChannelRow->settle_cycle = (int)($data['settle_cycle'] ?? 0);

            // 状态字段（bit(1) 类型）
            $paymentChannelRow->status = (bool)$data['status'];

            // 保存
            $paymentChannelRow->save();
        } catch (Throwable $e) {
            Log::error('创建支付通道失败: ' . $e->getMessage());
            throw new Exception('创建失败');
        }

        return $paymentChannelRow;
    }

    /**
     * 更新支付通道
     *
     * @param int   $id   支付通道ID
     * @param array $data 数据
     * @return true 更新是否成功
     * @throws Exception
     */
    /**
     * 更新支付通道
     *
     * @param int   $id   支付通道ID
     * @param array $data 数据
     * @return true 更新是否成功
     * @throws Exception
     */
    public static function updatePaymentChannel(int $id, array $data): true
    {
        $paymentChannel = self::find($id);
        if (!$paymentChannel) {
            throw new Exception('该支付通道不存在');
        }

        $code = strtoupper(trim($data['code'] ?? ''));

        // 检查通道编码是否已被其他记录使用
        if ($paymentChannel->code !== $code && self::where('code', $code)->exists()) {
            throw new Exception('该通道编码已存在');
        }

        try {
            // 基本字段处理
            if ($paymentChannel->code !== $code) {
                $paymentChannel->code = $code;
            }
            $paymentChannel->name         = trim($data['name'] ?? '');
            $paymentChannel->payment_type = $data['payment_type'];
            $paymentChannel->gateway      = trim($data['gateway'] ?? '');

            // 成本与费率处理（百分比 → 四位小数）
            $paymentChannel->costs = isset($data['costs']) ? bcdiv(bcround((string)$data['costs'], 4), '100', 4) : 0.0000;
            $paymentChannel->rate  = isset($data['rate']) ? bcdiv(bcround((string)$data['rate'], 4), '100', 4) : 0.0000;

            // 固定费用相关字段
            $paymentChannel->fixed_costs = bcround((string)$data['fixed_costs'], 2);
            $paymentChannel->fixed_fee   = bcround((string)$data['fixed_fee'], 2);
            $paymentChannel->min_fee     = empty($data['min_fee']) ? null : bcround($data['min_fee'], 2);
            $paymentChannel->max_fee     = empty($data['max_fee']) ? null : bcround($data['max_fee'], 2);

            // 金额限制字段
            $paymentChannel->min_amount  = empty($data['min_amount']) ? null : bcround($data['min_amount'], 2);
            $paymentChannel->max_amount  = empty($data['max_amount']) ? null : bcround($data['max_amount'], 2);
            $paymentChannel->daily_limit = empty($data['daily_limit']) ? null : bcround($data['daily_limit'], 2);

            // 时间段
            $paymentChannel->earliest_time = $data['earliest_time'] ?? null;
            $paymentChannel->latest_time   = $data['latest_time'] ?? null;

            // 整型字段
            $paymentChannel->roll_mode    = (int)($data['roll_mode'] ?? 0);
            $paymentChannel->settle_cycle = (int)($data['settle_cycle'] ?? 0);

            // 状态字段（bit(1) 类型）
            $paymentChannel->status = (bool)$data['status'];

            // 保存
            $paymentChannel->save();
        } catch (Throwable $e) {
            Log::error('编辑支付通道失败: ' . $e->getMessage());
            throw new Exception('编辑失败');
        }

        return true;
    }
}
