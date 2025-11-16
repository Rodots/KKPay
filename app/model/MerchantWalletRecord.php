<?php

declare(strict_types = 1);

namespace app\model;

use Exception;
use support\Model;

/**
 * 商户钱包余额变动记录表
 */
class MerchantWalletRecord extends Model
{
    /**
     * 与模型关联的表
     *
     * @var strings
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
     * 商户可用余额变更（全程 bcmath，无分/元转换）
     *
     * @param int         $merchantId        商户ID
     * @param string      $amount            变更金额（单位：元，正数）
     * @param string      $type              业务类型
     * @param bool        $action            false=扣款，true=加款
     * @param string|null $tradeNo           关联订单号
     * @param string|null $remark            备注
     * @param bool        $reduceUnavailable 是否同时减少不可用余额
     * @return void
     * @throws Exception
     */
    public static function changeAvailable(int $merchantId, string $amount, string $type, bool $action = false, ?string $tradeNo = null, ?string $remark = null, bool $reduceUnavailable = false): void
    {
        // 验证金额必须大于0
        if (bccomp($amount, '0.00', 2) !== 1) {
            return;
        }

        // 查询商户钱包并加锁防止并发
        $wallet = MerchantWallet::where('merchant_id', $merchantId)->lockForUpdate()->first();

        if (!$wallet) {
            throw new Exception('商户钱包不存在');
        }

        // 计算变更金额和新余额
        $oldBalance = $wallet->available_balance;
        $delta      = $action ? $amount : bcsub('0.00', $amount, 2);
        $newBalance = bcadd($oldBalance, $delta, 2);

        // 计算不可用余额变更
        $oldUnavailableBalance = $wallet->unavailable_balance;
        $unavailableDelta      = '0.00';
        $newUnavailableBalance = $oldUnavailableBalance;

        if ($reduceUnavailable && $action) { // 只有在加款时才可能减少不可用余额
            $unavailableDelta      = bcsub('0.00', $amount, 2);
            $newUnavailableBalance = bcadd($oldUnavailableBalance, $unavailableDelta, 2);
        }

        // 更新商户钱包余额
        $updateData = ['available_balance' => $newBalance];
        if ($reduceUnavailable) {
            $updateData['unavailable_balance'] = $newUnavailableBalance;
        }
        MerchantWallet::where('merchant_id', $merchantId)->update($updateData);

        // 创建余额变更记录
        self::create([
            'merchant_id'             => $merchantId,
            'type'                    => $type,
            'old_available_balance'   => $oldBalance,
            'available_amount'        => $delta,
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
     * @param string      $amount          变更金额（单位：元，正数）
     * @param string      $type            业务类型
     * @param bool        $action          false=扣款，true=加款
     * @param string|null $tradeNo         关联订单号
     * @param string|null $remark          备注
     * @param bool        $reduceAvailable 是否同时减少可用余额
     * @return void
     * @throws Exception
     */
    public static function changeUnAvailable(int $merchantId, string $amount, string $type, bool $action = false, ?string $tradeNo = null, ?string $remark = null, bool $reduceAvailable = false): void
    {
        // 验证金额必须大于0
        if (bccomp($amount, '0.00', 2) !== 1) {
            return;
        }

        // 查询商户钱包并加锁防止并发
        $wallet = MerchantWallet::where('merchant_id', $merchantId)->lockForUpdate()->first();

        if (!$wallet) {
            throw new Exception('商户钱包不存在');
        }

        // 检查扣款时余额是否充足
        if (!$action) { // 扣款操作
            if (bccomp($wallet->unavailable_balance, $amount, 2) === -1) {
                throw new Exception('不可用余额不足');
            }
        }

        // 计算变更金额和新余额
        $oldUnavailableBalance = $wallet->unavailable_balance;
        $delta                 = $action ? $amount : bcsub('0.00', $amount, 2);
        $newUnavailableBalance = bcadd($oldUnavailableBalance, $delta, 2);

        // 计算可用余额变更
        $oldAvailableBalance = $wallet->available_balance;
        $availableDelta      = '0.00';
        $newAvailableBalance = $oldAvailableBalance;

        if ($reduceAvailable && $action) { // 只有在加款时才可能减少可用余额
            if (bccomp($oldAvailableBalance, $amount, 2) === -1) {
                throw new Exception('可用余额不足');
            }
            $availableDelta      = bcsub('0.00', $amount, 2);
            $newAvailableBalance = bcadd($oldAvailableBalance, $availableDelta, 2);
        }

        // 更新商户钱包余额
        $updateData = ['unavailable_balance' => $newUnavailableBalance];
        if ($reduceAvailable) {
            $updateData['available_balance'] = $newAvailableBalance;
        }
        MerchantWallet::where('merchant_id', $merchantId)->update($updateData);

        // 创建余额变更记录
        self::create([
            'merchant_id'             => $merchantId,
            'type'                    => $type,
            'old_available_balance'   => $oldAvailableBalance,
            'available_amount'        => $availableDelta,
            'new_available_balance'   => $newAvailableBalance,
            'old_unavailable_balance' => $oldUnavailableBalance,
            'unavailable_amount'      => $delta,
            'new_unavailable_balance' => $newUnavailableBalance,
            'trade_no'                => $tradeNo,
            'remark'                  => $remark,
        ]);
    }
}
