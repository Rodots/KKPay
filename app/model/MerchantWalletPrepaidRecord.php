<?php

declare(strict_types = 1);

namespace app\model;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 商户钱包预付金变动记录表
 */
class MerchantWalletPrepaidRecord extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_wallet_prepaid_record';

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
            'merchant_id' => 'integer',
            'old_balance' => 'decimal:2',
            'amount'      => 'decimal:2',
            'new_balance' => 'decimal:2'
        ];
    }

    /**
     * 可批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'merchant_id',
        'old_balance',
        'amount',
        'new_balance',
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
     * 商户预付金变更（全程 bcmath，无分/元转换）
     *
     * @param int         $merchantId 商户ID
     * @param string      $amount     变更金额（单位：元，正数=加款，负数=扣款）
     * @param string|null $remark     备注
     * @return void
     * @throws Exception
     */
    public static function changePrepaid(int $merchantId, string $amount, ?string $remark = null): void
    {
        // 验证金额不能为0
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }

        // 查询商户钱包并加锁防止并发
        $wallet = MerchantWallet::where('merchant_id', $merchantId)->lockForUpdate()->first();

        if (!$wallet) {
            throw new Exception('商户钱包不存在');
        }

        // 判断是加款还是扣款
        $is_add = bccomp($amount, '0.00', 2) === 1;

        // 检查扣款时余额是否充足（金额为负数时表示扣款）
        if (!$is_add) {
            // 取绝对值进行比较
            $abs_amount = bcmul($amount, '-1', 2);
            if (bccomp($wallet->prepaid, $abs_amount, 2) === -1) {
                throw new Exception('预付金余额不足');
            }
        }

        // 计算变更后的预付金余额
        $oldBalance = $wallet->prepaid;
        $newBalance = bcadd($oldBalance, $amount, 2);

        // 更新商户钱包预付金
        $wallet->prepaid = $newBalance;
        $wallet->save();

        // 创建预付金变更记录
        self::create([
            'merchant_id' => $merchantId,
            'old_balance' => $oldBalance,
            'amount'      => $amount,
            'new_balance' => $newBalance,
            'remark'      => $remark,
        ]);
    }
}
