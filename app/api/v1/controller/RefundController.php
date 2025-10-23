<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use Core\Service\PaymentService;
use Core\Service\OrderService;
use Core\Exception\PaymentException;
use Core\Traits\ApiResponse;
use support\Request;
use support\Response;
use support\Log;
use Throwable;

/**
 * 退款控制器
 * 处理订单退款相关操作
 */
class RefundController
{
    use ApiResponse;

    private PaymentService $paymentService;
    private OrderService $orderService;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
        $this->orderService = new OrderService();
    }

    /**
     * 申请退款
     */
    public function create(Request $request): Response
    {
        try {
            $params = $request->post();
            
            // 参数验证
            $this->validateRefundParams($params);

            Log::info('收到退款申请', [
                'params' => $params,
                'ip' => $request->getRealIp(),
                'merchant_id' => $request->merchant_id ?? null
            ]);

            // 处理退款申请
            $result = $this->paymentService->processRefund([
                'merchant_id' => $request->merchant_id,
                'out_trade_no' => $params['out_trade_no'],
                'refund_amount' => $params['refund_amount'],
                'refund_reason' => $params['refund_reason'] ?? '商户申请退款',
                'out_refund_no' => $params['out_refund_no'] ?? null,
                'notify_url' => $params['notify_url'] ?? null,
            ]);

            if ($result['success']) {
                Log::info('退款申请成功', [
                    'out_trade_no' => $params['out_trade_no'],
                    'refund_amount' => $params['refund_amount'],
                    'refund_id' => $result['refund_id'] ?? null
                ]);

                return $this->success([
                    'refund_id' => $result['refund_id'],
                    'out_refund_no' => $result['out_refund_no'],
                    'refund_status' => $result['refund_status'],
                    'refund_amount' => $result['refund_amount'],
                    'refund_time' => $result['refund_time'] ?? null,
                ], '退款申请成功');
            } else {
                Log::warning('退款申请失败', [
                    'out_trade_no' => $params['out_trade_no'],
                    'error' => $result['message'] ?? '未知错误'
                ]);

                return $this->fail($result['message'] ?? '退款申请失败');
            }

        } catch (PaymentException $e) {
            Log::error('退款申请异常', [
                'params' => $request->post(),
                'error' => $e->getMessage()
            ]);
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::error('退款申请系统异常', [
                'params' => $request->post(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 查询退款状态
     */
    public function query(Request $request): Response
    {
        try {
            $params = $request->post();
            
            // 参数验证
            if (empty($params['out_trade_no']) && empty($params['out_refund_no'])) {
                return $this->fail('订单号或退款单号不能为空');
            }

            Log::info('查询退款状态', [
                'params' => $params,
                'merchant_id' => $request->merchant_id ?? null
            ]);

            // 查询退款状态
            $result = $this->paymentService->queryRefund([
                'merchant_id' => $request->merchant_id,
                'out_trade_no' => $params['out_trade_no'] ?? null,
                'out_refund_no' => $params['out_refund_no'] ?? null,
            ]);

            if ($result['success']) {
                return $this->success([
                    'refund_id' => $result['refund_id'],
                    'out_trade_no' => $result['out_trade_no'],
                    'out_refund_no' => $result['out_refund_no'],
                    'refund_status' => $result['refund_status'],
                    'refund_amount' => $result['refund_amount'],
                    'refund_time' => $result['refund_time'],
                    'refund_reason' => $result['refund_reason'],
                ], '查询成功');
            } else {
                return $this->fail($result['message'] ?? '查询失败');
            }

        } catch (Throwable $e) {
            Log::error('退款查询系统异常', [
                'params' => $request->get(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->error('系统异常，请稍后重试');
        }
    }



    /**
     * 验证退款参数
     */
    private function validateRefundParams(array $params): void
    {
        if (empty($params['out_trade_no'])) {
            throw new PaymentException('订单号不能为空');
        }

        if (empty($params['refund_amount']) || !is_numeric($params['refund_amount'])) {
            throw new PaymentException('退款金额无效');
        }

        if ((float)$params['refund_amount'] <= 0) {
            throw new PaymentException('退款金额必须大于0');
        }

        // 验证退款金额格式（最多2位小数）
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $params['refund_amount'])) {
            throw new PaymentException('退款金额格式错误，最多支持2位小数');
        }
    }
}
