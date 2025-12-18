<?php

declare(strict_types = 1);

namespace app\model;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 商户钱包余额变动记录表
 */
class MerchantWalletRecord extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_wallet_record';

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
            'merchant_id'             => 'integer',
            'old_available_balance'   => 'decimal:2',
            'available_amount'        => 'decimal:2',
            'new_available_balance'   => 'decimal:2',
            'old_unavailable_balance' => 'decimal:2',
            'unavailable_amount'      => 'decimal:2',
            'new_unavailable_balance' => 'decimal:2'
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
        'old_available_balance',
        'available_amount',
        'new_available_balance',
        'old_unavailable_balance',
        'unavailable_amount',
        'new_unavailable_balance',
        'trade_no',
        'remark',
    ];

    /**
     * 访问器：操作时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
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
     * 商户可用余额变更（全程 bcmath，无分/元转换）
     *
     * @param int         $merchantId        商户ID
     * @param string      $amount            变更金额（单位：元，正数=加款，负数=扣款）
     * @param string      $type              业务类型
     * @param string|null $tradeNo           关联订单号
     * @param string|null $remark            备注
     * @param bool        $reduceUnavailable 是否同时减少不可用余额（仅在加款时生效）
     * @return void
     * @throws Exception
     */
    public static function changeAvailable(int $merchantId, string $amount, string $type, ?string $tradeNo = null, ?string $remark = null, bool $reduceUnavailable = false): void
    {
        // 验证金额不能为0
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }

        // 查询商户钱包并加锁防止并发
        if (!$wallet = MerchantWallet::where('merchant_id', $merchantId)->lockForUpdate()->first()) {
            throw new Exception('商户钱包不存在');
        }

        // 判断是加款还是扣款
        $is_add = bccomp($amount, '0.00', 2) === 1;

        // 计算变更后的可用余额
        $oldBalance = $wallet->available_balance;
        $newBalance = bcadd($oldBalance, $amount, 2);

        // 计算不可用余额变更
        $oldUnavailableBalance = $wallet->unavailable_balance;
        $unavailableDelta      = '0.00';
        $newUnavailableBalance = $oldUnavailableBalance;

        // 只有在加款时才可能减少不可用余额
        if ($reduceUnavailable && $is_add) {
            // 使用金额的绝对值取反作为不可用余额的变更量
            $unavailableDelta      = bcsub('0.00', $amount, 2);
            $newUnavailableBalance = bcadd($oldUnavailableBalance, $unavailableDelta, 2);
        }

        // 更新商户钱包余额
        $wallet->available_balance = $newBalance;
        if ($reduceUnavailable && $is_add) {
            $wallet->unavailable_balance = $newUnavailableBalance;
        }
        $wallet->save();

        // 创建余额变更记录
        self::create([
            'merchant_id'             => $merchantId,
            'type'                    => $type,
            'old_available_balance'   => $oldBalance,
            'available_amount'        => $amount,
            'new_available_balance'   => $newBalance,
            'old_unavailable_balance' => $oldUnavailableBalance,
            'unavailable_amount'      => $unavailableDelta,
            'new_unavailable_balance' => $newUnavailableBalance,
            'trade_no'                => $tradeNo,
            'remark'                  => $remark,
        ]);
    }

    /**
     * 商户不可用余额变更（全程 bcmath，无分/元转换）
     *
     * @param int         $merchantId      商户ID
     * @param string      $amount          变更金额（单位：元，正数=加款，负数=扣款）
     * @param string      $type            业务类型
     * @param string|null $tradeNo         关联订单号
     * @param string|null $remark          备注
     * @param bool        $reduceAvailable 是否同时减少可用余额（仅在加款时生效）
     * @return void
     * @throws Exception
     */
    public static function changeUnAvailable(int $merchantId, string $amount, string $type, ?string $tradeNo = null, ?string $remark = null, bool $reduceAvailable = false): void
    {
        // 验证金额不能为0
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }

        // 查询商户钱包并加锁防止并发
        if (!$wallet = MerchantWallet::where('merchant_id', $merchantId)->lockForUpdate()->first()) {
            throw new Exception('商户钱包不存在');
        }

        // 判断是加款还是扣款
        $is_add = bccomp($amount, '0.00', 2) === 1;

        // 检查扣款时余额是否充足（金额为负数时表示扣款）
        if (!$is_add) {
            // 取绝对值进行比较
            $abs_amount = bcmul($amount, '-1', 2);
            if (bccomp($wallet->unavailable_balance, $abs_amount, 2) === -1) {
                throw new Exception('不可用余额不足');
            }
        }

        // 计算变更后的不可用余额
        $oldUnavailableBalance = $wallet->unavailable_balance;
        $newUnavailableBalance = bcadd($oldUnavailableBalance, $amount, 2);

        // 计算可用余额变更
        $oldAvailableBalance = $wallet->available_balance;
        $availableDelta      = '0.00';
        $newAvailableBalance = $oldAvailableBalance;

        // 只有在加款时才可能减少可用余额
        if ($reduceAvailable && $is_add) {
            if (bccomp($oldAvailableBalance, $amount, 2) === -1) {
                throw new Exception('可用余额不足');
            }
            // 使用金额的绝对值取反作为可用余额的变更量
            $availableDelta      = bcsub('0.00', $amount, 2);
            $newAvailableBalance = bcadd($oldAvailableBalance, $availableDelta, 2);
        }

        // 更新商户钱包余额
        $wallet->unavailable_balance = $newUnavailableBalance;
        if ($reduceAvailable && $is_add) {
            $wallet->available_balance = $newAvailableBalance;
        }
        $wallet->save();

        // 创建余额变更记录
        self::create([
            'merchant_id'             => $merchantId,
            'type'                    => $type,
            'old_available_balance'   => $oldAvailableBalance,
            'available_amount'        => $availableDelta,
            'new_available_balance'   => $newAvailableBalance,
            'old_unavailable_balance' => $oldUnavailableBalance,
            'unavailable_amount'      => $amount,
            'new_unavailable_balance' => $newUnavailableBalance,
            'trade_no'                => $tradeNo,
            'remark'                  => $remark,
        ]);
    }
}
