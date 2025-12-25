<?php

declare(strict_types=1);

namespace app\model;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Log;
use support\Model;
use Throwable;

/**
 * 支付通道子账户表
 */
class PaymentChannelAccount extends Model
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
    protected $table = 'payment_channel_account';

    /**
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'payment_channel_id' => 'integer',
            'inherit_config'     => 'boolean',
            'roll_weight'        => 'integer',
            'rate'               => 'decimal:4',
            'min_amount'         => 'decimal:2',
            'max_amount'         => 'decimal:2',
            'daily_limit'        => 'decimal:2',
            'config'             => 'array',
            'status'             => 'boolean',
            'maintenance'        => 'boolean'
        ];
    }

    /**
     * 可批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'status',
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
     * 获取拥有该子账户的支付通道。
     *
     * @return BelongsTo
     */
    public function paymentChannel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class);
    }

    /**
     * 获取该支付通道子账户创建的订单。
     *
     * @return HasMany
     */
    public function order(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * 创建支付通道子账户
     *
     * @param array $data 数据
     * @return true
     * @throws Exception
     */
    public static function createPaymentChannelAccount(array $data): true
    {
        try {
            $paymentChannelAccountRow = new self();

            // 基本字段处理
            $paymentChannelAccountRow->name               = trim($data['name'] ?? '');
            $paymentChannelAccountRow->payment_channel_id = $data['payment_channel_id'];
            $paymentChannelAccountRow->config             = $data['config'];
            $paymentChannelAccountRow->earliest_time      = $data['earliest_time'] ?? null;
            $paymentChannelAccountRow->latest_time        = $data['latest_time'] ?? null;
            $paymentChannelAccountRow->remark             = empty($data['remark']) ? null : html_filter($data['remark']);

            // 成本与费率处理（百分比 → 四位小数）
            $paymentChannelAccountRow->rate = isset($data['rate']) ? bcdiv(bcround((string)$data['rate'], 4), '100', 4) : 0.0000;

            // 金额限制字段
            $paymentChannelAccountRow->min_amount  = empty($data['min_amount']) ? null : bcround((string)$data['min_amount'], 2);
            $paymentChannelAccountRow->max_amount  = empty($data['max_amount']) ? null : bcround((string)$data['max_amount'], 2);
            $paymentChannelAccountRow->daily_limit = empty($data['daily_limit']) ? null : bcround((string)$data['daily_limit'], 2);

            // 自定义商品名称
            $paymentChannelAccountRow->diy_order_subject = empty($data['diy_order_subject']) ? null : trim($data['diy_order_subject']);

            // bit类型字段
            $paymentChannelAccountRow->inherit_config = $data['inherit_config'];
            $paymentChannelAccountRow->status         = $data['status'];

            // 保存
            $paymentChannelAccountRow->save();
        } catch (Throwable $e) {
            Log::error('创建支付通道子账户失败: ' . $e->getMessage());
            throw new Exception('创建失败');
        }

        return true;
    }

    /**
     * 更新支付通道子账户
     *
     * @param int   $id   支付通道子账户ID
     * @param array $data 数据
     * @return true
     * @throws Exception
     */
    public static function updatePaymentChannelAccount(int $id, array $data): true
    {
        $paymentChannelAccount = self::find($id);
        if (!$paymentChannelAccount) {
            throw new Exception('该支付通道子账户不存在');
        }

        try {
            // 基本字段处理
            $paymentChannelAccount->name               = trim($data['name'] ?? '');
            $paymentChannelAccount->payment_channel_id = $data['payment_channel_id'];
            $paymentChannelAccount->config             = $data['config'];
            $paymentChannelAccount->earliest_time      = $data['earliest_time'] ?? null;
            $paymentChannelAccount->latest_time        = $data['latest_time'] ?? null;
            $paymentChannelAccount->remark             = empty($data['remark']) ? null : html_filter($data['remark']);

            // 成本与费率处理（百分比 → 四位小数）
            $paymentChannelAccount->rate = isset($data['rate']) ? bcdiv(bcround((string)$data['rate'], 4), '100', 4) : 0.0000;

            // 金额限制字段
            $paymentChannelAccount->min_amount  = empty($data['min_amount']) ? null : bcround((string)$data['min_amount'], 2);
            $paymentChannelAccount->max_amount  = empty($data['max_amount']) ? null : bcround((string)$data['max_amount'], 2);
            $paymentChannelAccount->daily_limit = empty($data['daily_limit']) ? null : bcround((string)$data['daily_limit'], 2);

            // 自定义商品名称
            $paymentChannelAccount->diy_order_subject = empty($data['diy_order_subject']) ? null : trim($data['diy_order_subject']);

            // bit类型字段
            $paymentChannelAccount->inherit_config = $data['inherit_config'];
            $paymentChannelAccount->status         = $data['status'];

            // 保存
            $paymentChannelAccount->save();
        } catch (Throwable $e) {
            Log::error('编辑支付通道子账户失败: ' . $e->getMessage());
            throw new Exception('编辑失败');
        }

        return true;
    }
}
