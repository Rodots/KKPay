<?php

declare(strict_types = 1);

namespace app\model;

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
            'balance'            => 'decimal:6',
            'old_balance'        => 'decimal:6',
            'new_balance'        => 'decimal:6',
            'freeze_balance'     => 'decimal:6',
            'old_freeze_balance' => 'decimal:6',
            'new_freeze_balance' => 'decimal:6'
        ];
    }

    /**
     * 商户余额变更函数
     *
     * 该函数用于根据指定的类型和操作，对商户的余额进行增加或减少，并记录相应的流水信息
     * 如果商户ID不存在，或者变更的金额为负数（对于某些操作），则函数返回false
     *
     * @param int         $user_id  商户ID，用于标识哪个商户的余额需要变更
     * @param float       $balance  变更的金额
     * @param string      $type     变更的类型，用于描述为什么进行余额变更
     * @param int         $action   操作类型，0表示减少余额，非0表示增加余额，默认为0
     * @param string|null $trade_no 关联订单号，默认为null
     * @param string|null $remark   备注信息，用于进一步描述余额变更的原因，默认为null
     *
     * @return bool 返回true表示余额变更成功，false表示失败
     */
    public static function change(int $user_id, float $balance, string $type, int $action = 0, ?string $trade_no = null, ?string $remark = null): bool
    {
        // 根据商户ID查找商户
        $user = Merchant::find($user_id);
        if (!$user) {
            return false;
        }

        // 使用整数进行金额计算，以避免浮点数精度问题
        $old_balance    = $user->getAttribute('balance');
        $user_balance   = intval($old_balance * 100);
        $change_balance = intval($balance * 100);

        // 检查金额是否为负数，如果是，则不允许进行操作
        if ($change_balance < 0) {
            return false;
        }

        // 根据操作类型计算新的余额
        if ($action === 0) {
            $new_balance = $user_balance - $change_balance;
        } else {
            $new_balance = $user_balance + $change_balance;
        }

        try {
            // 创建余额变更记录
            self::create([
                'user_id'     => $user_id,
                'action'      => $action,
                'balance'     => $balance,
                'old_balance' => $old_balance,
                'new_balance' => $new_balance / 100,
                'type'        => $type,
                'trade_no'    => $trade_no,
                'remark'      => $remark,
            ]);

            // 更新商户余额
            $user->balance = $new_balance / 100;
            $user->save();

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
