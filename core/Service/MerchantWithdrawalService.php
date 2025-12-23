<?php

declare(strict_types=1);

namespace Core\Service;

use app\model\Merchant;
use app\model\MerchantPayee;
use app\model\MerchantWallet;
use app\model\MerchantWalletPrepaidRecord;
use app\model\MerchantWalletRecord;
use app\model\MerchantWithdrawalRecord;
use Exception;
use support\Db;
use support\Log;
use Throwable;

/**
 * 商户提款服务类
 *
 * 负责商户提款业务逻辑，包括清账、申请提款、状态变更等
 */
class MerchantWithdrawalService
{
    /**
     * 后台清账操作
     *
     * 根据商户可用余额与预付金的关系进行清账：
     * - 可用余额 ≤ 预付金：同时扣减相等金额，不创建提款记录
     * - 可用余额 > 预付金：创建提款记录(PROCESSING)，清零双余额
     *
     * @param int $merchantId 商户ID
     * @param int $payeeId    收款信息ID
     * @return array ['success' => bool, 'message' => string, 'withdrawal_id' => ?string]
     */
    public static function settleAccount(int $merchantId, int $payeeId): array
    {
        Db::beginTransaction();
        try {
            // 验证商户是否存在
            if (!Merchant::where('id', $merchantId)->exists()) {
                throw new Exception('商户不存在');
            }

            // 验证收款信息存在且属于该商户
            $payee = MerchantPayee::where('id', $payeeId)->where('merchant_id', $merchantId)->first();
            if (!$payee) {
                throw new Exception('收款信息不存在或不属于该商户');
            }

            // 加锁获取商户钱包
            $wallet = MerchantWallet::where('merchant_id', $merchantId)->lockForUpdate()->first();
            if (!$wallet) {
                throw new Exception('商户钱包不存在');
            }

            $availableBalance = $wallet->available_balance;
            $prepaid          = $wallet->prepaid;

            // 判断可用余额与预付金的关系
            if (bccomp($availableBalance, '0.00', 2) <= 0) {
                throw new Exception('这个逼的账户可用余额一分钱都没有，还清个毛的账');
            }

            $withdrawalId = null;

            if (bccomp($availableBalance, $prepaid, 2) <= 0) {
                // 可用余额 ≤ 预付金：同时扣减相等金额，不创建提款记录
                $deductAmount = $availableBalance;

                // 扣减可用余额
                MerchantWalletRecord::changeAvailable($merchantId, bcsub('0.00', $deductAmount, 2), '清账抵扣', null, '可用余额全部用于抵扣预付金');

                // 扣减预付金
                MerchantWalletPrepaidRecord::changePrepaid($merchantId, bcsub('0.00', $deductAmount, 2), '清账抵扣');

                $message = "清账完成：可用余额 $deductAmount 元全部用于抵扣预付金，无需生成提款记录";
            } else {
                // 可用余额 > 预付金：创建提款记录
                // 提款金额 = 可用余额（即清账总金额）
                // 实际到账 = 可用余额 - 预付金抵扣
                $withdrawalAmount  = $availableBalance;
                $prepaidDeducted   = $prepaid;
                $receivedAmount    = bcsub($availableBalance, $prepaid, 2);

                // 创建提款记录
                $withdrawal = MerchantWithdrawalRecord::create([
                    'merchant_id'      => $merchantId,
                    'payee_info'       => $payee->payee_info,
                    'amount'           => $withdrawalAmount,
                    'prepaid_deducted' => $prepaidDeducted,
                    'received_amount'  => $receivedAmount,
                    'fee'              => '0.00',
                    'fee_type'         => false,
                    'status'           => MerchantWithdrawalRecord::STATUS_PROCESSING,
                ]);
                $withdrawalId = $withdrawal->id;

                // 扣减可用余额（清零）
                MerchantWalletRecord::changeAvailable($merchantId, bcsub('0.00', $availableBalance, 2), '清账提款', null, "清账提款 $withdrawalAmount 元，抵扣预付金 $prepaidDeducted 元，实际到账 $receivedAmount 元");

                // 扣减预付金（清零）
                if (bccomp($prepaidDeducted, '0.00', 2) > 0) {
                    MerchantWalletPrepaidRecord::changePrepaid($merchantId, bcsub('0.00', $prepaidDeducted, 2), '清账抵扣');
                }

                $message = "清账完成：提款 $withdrawalAmount 元，抵扣预付金 $prepaidDeducted 元，实际到账 $receivedAmount 元";
            }

            Db::commit();

            return ['success' => true, 'message' => $message, 'withdrawal_id' => $withdrawalId];
        } catch (Throwable $e) {
            Db::rollBack();
            Log::error("商户清账失败: merchant_id=$merchantId, error=" . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'withdrawal_id' => null];
        }
    }

    /**
     * 申请提款
     *
     * @param int    $merchantId 商户ID
     * @param int    $payeeId    收款信息ID
     * @param string $amount     提款金额
     * @return array ['success' => bool, 'message' => string, 'withdrawal_id' => ?string]
     */
    public static function applyWithdrawal(int $merchantId, int $payeeId, string $amount): array
    {
        Db::beginTransaction();
        try {
            // 验证商户是否存在
            if (!Merchant::where('id', $merchantId)->exists()) {
                throw new Exception('商户不存在');
            }

            // 验证收款信息存在且属于该商户
            $payee = MerchantPayee::where('id', $payeeId)->where('merchant_id', $merchantId)->first();
            if (!$payee) {
                throw new Exception('收款信息不存在或不属于该商户');
            }

            // 验证金额
            if (bccomp($amount, '0.00', 2) <= 0) {
                throw new Exception('提款金额必须大于0');
            }

            // 加锁获取商户钱包
            $wallet = MerchantWallet::where('merchant_id', $merchantId)->lockForUpdate()->first();
            if (!$wallet) {
                throw new Exception('商户钱包不存在');
            }

            // 验证可用余额是否足够
            if (bccomp($wallet->available_balance, $amount, 2) < 0) {
                throw new Exception('可用余额不足');
            }

            // 创建提款记录（待审核状态）
            $withdrawal = MerchantWithdrawalRecord::create([
                'merchant_id'      => $merchantId,
                'payee_info'       => $payee->payee_info,
                'amount'           => $amount,
                'prepaid_deducted' => '0.00',
                'received_amount'  => $amount,
                'fee'              => '0.00',
                'fee_type'         => false,
                'status'           => MerchantWithdrawalRecord::STATUS_PENDING,
            ]);

            // 扣减可用余额
            MerchantWalletRecord::changeAvailable($merchantId, bcsub('0.00', $amount, 2), '申请提款', null, "申请提款 $amount 元，等待审核");

            Db::commit();

            return ['success' => true, 'message' => '提款申请已提交，等待审核', 'withdrawal_id' => $withdrawal->id];
        } catch (Throwable $e) {
            Db::rollBack();
            Log::error("商户申请提款失败: merchant_id=$merchantId, error=" . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'withdrawal_id' => null];
        }
    }

    /**
     * 统一状态修改
     *
     * 根据目标状态自动处理相关业务逻辑（如驳回/取消时退款）
     *
     * @param int         $id     提款记录ID
     * @param string      $status 目标状态
     * @param string|null $reason 原因（驳回/失败时使用）
     * @return array ['success' => bool, 'message' => string]
     */
    public static function changeStatus(string $id, string $status, ?string $reason = null): array
    {
        // 验证状态值有效性
        $validStatuses = [
            MerchantWithdrawalRecord::STATUS_PENDING,
            MerchantWithdrawalRecord::STATUS_PROCESSING,
            MerchantWithdrawalRecord::STATUS_COMPLETED,
            MerchantWithdrawalRecord::STATUS_FAILED,
            MerchantWithdrawalRecord::STATUS_REJECTED,
            MerchantWithdrawalRecord::STATUS_CANCELED,
        ];
        if (!in_array($status, $validStatuses)) {
            return ['success' => false, 'message' => '无效的状态值'];
        }

        Db::beginTransaction();
        try {
            // 查找提款记录
            $withdrawal = MerchantWithdrawalRecord::lockForUpdate()->find($id);
            if (!$withdrawal) {
                throw new Exception('提款记录不存在');
            }

            $oldStatus  = $withdrawal->status;
            $merchantId = $withdrawal->merchant_id;

            // 状态未变化
            if ($oldStatus === $status) {
                throw new Exception('状态未发生变化');
            }

            // 验证状态流转合法性
            $allowedTransitions = [
                MerchantWithdrawalRecord::STATUS_PENDING    => [MerchantWithdrawalRecord::STATUS_PROCESSING, MerchantWithdrawalRecord::STATUS_REJECTED, MerchantWithdrawalRecord::STATUS_CANCELED],
                MerchantWithdrawalRecord::STATUS_PROCESSING => [MerchantWithdrawalRecord::STATUS_COMPLETED, MerchantWithdrawalRecord::STATUS_FAILED, MerchantWithdrawalRecord::STATUS_CANCELED],
            ];

            if (!isset($allowedTransitions[$oldStatus]) || !in_array($status, $allowedTransitions[$oldStatus])) {
                throw new Exception("不允许从 $oldStatus 状态变更为 $status");
            }

            // 需要退款的状态：REJECTED, CANCELED, FAILED
            $refundStatuses = [
                MerchantWithdrawalRecord::STATUS_REJECTED,
                MerchantWithdrawalRecord::STATUS_CANCELED,
                MerchantWithdrawalRecord::STATUS_FAILED,
            ];

            if (in_array($status, $refundStatuses)) {
                // 退款金额说明：
                // - 可用余额退还 = amount（清账时扣减的可用余额）
                // - 预付金退还 = prepaid_deducted（清账时扣减的预付金）
                $refundAmount  = $withdrawal->amount;
                $prepaidRefund = $withdrawal->prepaid_deducted;

                $statusText = match ($status) {
                    MerchantWithdrawalRecord::STATUS_REJECTED => '驳回',
                    MerchantWithdrawalRecord::STATUS_CANCELED => '取消',
                    MerchantWithdrawalRecord::STATUS_FAILED   => '失败',
                };

                // 退还可用余额
                if (bccomp($refundAmount, '0.00', 2) > 0) {
                    MerchantWalletRecord::changeAvailable($merchantId, $refundAmount, "提款{$statusText}退款", null, "提款{$statusText}，退还可用余额 $refundAmount 元");
                }

                // 退还预付金
                if (bccomp($prepaidRefund, '0.00', 2) > 0) {
                    MerchantWalletPrepaidRecord::changePrepaid($merchantId, $prepaidRefund, "提款{$statusText}，退还预付金 $prepaidRefund 元");
                }
            }

            // 更新提款记录状态
            $withdrawal->status = $status;
            if ($reason !== null && in_array($status, [MerchantWithdrawalRecord::STATUS_REJECTED, MerchantWithdrawalRecord::STATUS_FAILED])) {
                $withdrawal->reject_reason = $reason;
            }
            $withdrawal->save();

            Db::commit();

            return ['success' => true, 'message' => '状态变更成功'];
        } catch (Throwable $e) {
            Db::rollBack();
            Log::error("提款状态变更失败: id=$id, error=" . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
