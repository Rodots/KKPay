<?php

declare(strict_types = 1);

namespace app\model;

use support\Db;
use support\Log;
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
            'merchant_id'        => 'integer',
            'balance'            => 'decimal:2',
            'old_balance'        => 'decimal:2',
            'new_balance'        => 'decimal:2',
            'freeze_balance'     => 'decimal:2',
            'old_freeze_balance' => 'decimal:2',
            'new_freeze_balance' => 'decimal:2'
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
        'balance',
        'old_balance',
        'new_balance',
        'freeze_balance',
        'old_freeze_balance',
        'new_freeze_balance',
        'trade_no',
        'remark',
    ];

    /**
     * 商户可用余额变更（全程 bcmath，无分/元转换）
     *
     * @param int         $merchantId 商户ID
     * @param string      $amount     变更金额（单位：元，正数）
     * @param string      $type       业务类型
     * @param bool        $action     false=扣款，true=加款
     * @param string|null $tradeNo    关联订单号
     * @param string|null $remark     备注
     *
     * @return bool
     */
    public static function change(int $merchantId, string $amount, string $type, bool $action = false, ?string $tradeNo = null, ?string $remark = null): bool
    {
        // 验证金额必须大于0
        if (bccomp($amount, '0.00', 2) !== 1) {
            return false;
        }

        try {
            // 开启数据库事务处理
            return DB::transaction(function () use ($merchantId, $amount, $type, $action, $tradeNo, $remark) {
                // 查询商户钱包并加锁防止并发
                $wallet = MerchantWallet::where('merchant_id', $merchantId)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    throw new \Exception('商户钱包不存在');
                }

                // 计算变更金额和新余额
                $oldBalance = $wallet->balance;
                $delta      = $action ? $amount : bcsub('0.00', $amount, 2);
                $newBalance = bcadd($oldBalance, $delta, 2);

                // 检查余额是否足够
                if (bccomp($newBalance, '0.00', 2) === -1) {
                    throw new \Exception('余额不足');
                }

                // 更新商户钱包余额
                MerchantWallet::where('merchant_id', $merchantId)
                    ->update(['balance' => $newBalance]);

                // 创建余额变更记录
                self::create([
                    'merchant_id'        => $merchantId,
                    'type'               => $type,
                    'balance'            => $delta,
                    'old_balance'        => $oldBalance,
                    'new_balance'        => $newBalance,
                    'freeze_balance'     => '0.00',
                    'old_freeze_balance' => $wallet->freeze_balance,
                    'new_freeze_balance' => $wallet->freeze_balance,
                    'trade_no'           => $tradeNo,
                    'remark'             => $remark,
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            // 记录错误日志
            Log::error("商户{$merchantId}余额变更失败：" . $e->getMessage());
            return false;
        }
    }
}
