<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\MerchantPayee;
use app\model\MerchantWithdrawalRecord;
use Core\baseController\ApiBase;
use Core\Service\MerchantWithdrawalService;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提款控制器
 *
 * 提供提款申请、取消、查询等API接口
 */
class WithdrawalController extends ApiBase
{
    /**
     * 提款申请接口
     *
     * biz_content 参数：
     * - amount: 提款金额
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function apply(Request $request): Response
    {
        try {
            $data = $this->parseBizContent($request);
            if (is_string($data)) {
                return $this->fail($data);
            }

            // 提取参数
            $amount = $this->getAmount($data, 'amount');

            // 验证参数
            if ($amount === '0' || bccomp($amount, '0', 2) <= 0) {
                return $this->fail('提款金额(amount)必须大于0');
            }

            $merchantId = $this->getMerchantId($request);

            // 获取商户的默认收款账户
            $payee = MerchantPayee::where('merchant_id', $merchantId)->where('is_default', true)->first();
            if ($payee === null) {
                return $this->fail('商户未设置默认收款账户，请先添加收款账户');
            }

            // 调用提款服务
            $result = MerchantWithdrawalService::applyWithdrawal($merchantId, $payee->id, $amount);

            if (!$result['success']) {
                return $this->fail($result['message']);
            }

            return $this->success([
                'withdrawal_id' => $result['withdrawal_id'],
                'amount'        => $amount,
                'status'        => 'PENDING',
            ], '提款申请成功');
        } catch (Exception $e) {
            Log::warning('提款申请失败:' . $e->getMessage());
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::error('提款申请异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 取消提款接口
     *
     * biz_content 参数：
     * - withdrawal_id: 提款流水号
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function cancel(Request $request): Response
    {
        try {
            $data = $this->parseBizContent($request);
            if (is_string($data)) {
                return $this->fail($data);
            }

            $withdrawalId = $this->getString($data, 'withdrawal_id');

            // 验证参数
            if (empty($withdrawalId)) {
                return $this->fail('提款流水号(withdrawal_id)无效');
            }

            // 验证提款记录是否属于该商户
            $withdrawal = MerchantWithdrawalRecord::where('id', $withdrawalId)->where('merchant_id', $this->getMerchantId($request))->first();
            if ($withdrawal === null) {
                return $this->fail('提款记录不存在或不属于当前商户');
            }

            // 调用取消服务
            $result = MerchantWithdrawalService::changeStatus($withdrawalId, 'CANCELED');

            if (!$result['success']) {
                return $this->fail($result['message']);
            }

            return $this->success([
                'withdrawal_id' => $withdrawalId,
                'status'        => 'CANCELED',
            ], '取消提款成功');
        } catch (Throwable $e) {
            Log::error('取消提款异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 提款记录查询接口
     *
     * biz_content 参数：
     * - withdrawal_id: 提款流水号（可选，不传则查询列表）
     * - page: 页码（可选，默认1）
     * - page_size: 每页数量（可选，默认20，最大100）
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function query(Request $request): Response
    {
        try {
            $data = $this->parseBizContent($request);
            if (is_string($data)) {
                return $this->fail($data);
            }

            // 提取参数
            $bizContent = [
                'withdrawal_id' => $this->getString($data, 'withdrawal_id'),
                'page'          => $this->getInt($data, 'page', 1),
                'page_size'     => $this->getInt($data, 'page_size', 20),
            ];

            $merchantId = $this->getMerchantId($request);

            // 如果指定了提款流水号，查询单条记录
            if (!empty($bizContent['withdrawal_id'])) {
                $withdrawal = MerchantWithdrawalRecord::where('id', $bizContent['withdrawal_id'])
                    ->where('merchant_id', $merchantId)
                    ->first(['id', 'amount', 'prepaid_deducted', 'received_amount', 'fee', 'status', 'reject_reason', 'created_at', 'updated_at']);

                if ($withdrawal === null) {
                    return $this->fail('提款记录不存在或不属于当前商户');
                }

                return $this->success($withdrawal->toArray(), '查询成功');
            }

            // 查询列表
            $page     = max(1, $bizContent['page']);
            $pageSize = min(100, max(1, $bizContent['page_size']));

            $query = MerchantWithdrawalRecord::where('merchant_id', $merchantId)->orderByDesc('id');
            $total = $query->count();
            $list  = $query->offset(($page - 1) * $pageSize)->limit($pageSize)->get(['id', 'amount', 'prepaid_deducted', 'received_amount', 'fee', 'status', 'created_at', 'updated_at']);

            return $this->success([
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
                'list'      => $list->toArray(),
            ], '查询成功');
        } catch (Throwable $e) {
            Log::error('提款记录查询异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 收款账户列表接口
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function payeeList(Request $request): Response
    {
        try {
            $list = MerchantPayee::where('merchant_id', $this->getMerchantId($request))->get(['id', 'payee_info', 'is_default', 'created_at']);

            return $this->success([
                'list' => $list->toArray(),
            ], '查询成功');
        } catch (Throwable $e) {
            Log::error('收款账户列表查询异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }
}
