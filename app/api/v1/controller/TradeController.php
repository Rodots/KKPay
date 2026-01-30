<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use app\api\v1\middleware\ApiSignatureVerification;
use app\model\Order;
use Core\baseController\ApiBase;
use Core\Exception\PaymentException;
use Core\Service\OrderService;
use Core\Service\RefundService;
use support\annotation\Middleware;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 订单交易控制器
 *
 * 提供订单查询、退款、关闭等API接口
 */
#[Middleware(ApiSignatureVerification::class)]
class TradeController extends ApiBase
{
    /**
     * 订单查询接口
     *
     * biz_content 参数：
     * - trade_no: 平台订单号（与 out_trade_no 二选一）
     * - out_trade_no: 商户订单号（与 trade_no 二选一）
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
                'trade_no'     => $this->getString($data, 'trade_no', true),
                'out_trade_no' => $this->getString($data, 'out_trade_no', true),
            ];

            $order = $this->findOrder($request, $bizContent, [
                'trade_no',
                'out_trade_no',
                'api_trade_no',
                'bill_trade_no',
                'payment_type',
                'subject',
                'total_amount',
                'buyer_pay_amount',
                'receipt_amount',
                'fee_amount',
                'attach',
                'trade_state',
                'create_time',
                'payment_time',
                'close_time'
            ]);

            if ($order === null) {
                return $this->fail('订单不存在');
            }

            // 加载付款人信息和退款记录（仅关键字段）
            $order->load(['buyer', 'refunds' => function ($query) {
                $query->select(['id', 'trade_no', 'amount', 'reason', 'created_at']);
            }]);

            $result = $order->toArray();
            // 格式化退款记录输出
            $result['refunds'] = array_map(function ($refund) {
                return [
                    'refund_id'   => $refund['id'],
                    'amount'      => $refund['amount'],
                    'reason'      => $refund['reason'],
                    'refund_time' => $refund['created_at'],
                ];
            }, $result['refunds'] ?? []);

            return $this->success($result, '查询成功');
        } catch (Throwable $e) {
            Log::error('订单查询异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 订单退款接口
     *
     * biz_content 参数：
     * - trade_no: 平台订单号（与 out_trade_no 二选一）
     * - out_trade_no: 商户订单号（与 trade_no 二选一）
     * - refund_amount: 退款金额
     * - refund_reason: 退款原因（可选）
     * - out_biz_no: 商户退款业务号（可选，用于幂等）
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function refund(Request $request): Response
    {
        try {
            // 验证商户是否拥有退款权限
            if (!$request->merchant->hasPermission('refund')) {
                return $this->fail('商户无退款权限');
            }

            $data = $this->parseBizContent($request);
            if (is_string($data)) {
                return $this->fail($data);
            }

            // 提取参数
            $bizContent = [
                'trade_no'      => $this->getString($data, 'trade_no'),
                'out_trade_no'  => $this->getString($data, 'out_trade_no'),
                'refund_amount' => $this->getAmount($data, 'refund_amount'),
                'refund_reason' => $this->getString($data, 'refund_reason'),
                'out_biz_no'    => $this->getString($data, 'out_biz_no'),
            ];

            if ($bizContent['refund_amount'] === '0' || bccomp($bizContent['refund_amount'], '0', 2) <= 0) {
                return $this->fail('退款金额(refund_amount)必须大于0');
            }

            $order = $this->findOrder($request, $bizContent, ['trade_no', 'trade_state', 'total_amount', 'buyer_pay_amount']);
            if ($order === null) {
                return $this->fail('订单不存在');
            }

            $result = RefundService::apiRefund($order->trade_no, $bizContent['refund_amount'], $bizContent['refund_reason'] ?? '商户发起退款', $bizContent['out_biz_no'], $this->getMerchantId($request));

            if (!$result['success']) {
                return $this->fail($result['message']);
            }

            return $this->success([
                'refund_id'     => $result['refund_id'],
                'trade_no'      => $order->trade_no,
                'refund_amount' => $bizContent['refund_amount'],
            ], '退款处理成功');
        } catch (PaymentException $e) {
            Log::warning('订单退款失败:' . $e->getMessage());
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::error('订单退款异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 关闭订单接口
     *
     * biz_content 参数：
     * - trade_no: 平台订单号（与 out_trade_no 二选一）
     * - out_trade_no: 商户订单号（与 trade_no 二选一）
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function close(Request $request): Response
    {
        try {
            $data = $this->parseBizContent($request);
            if (is_string($data)) {
                return $this->fail($data);
            }

            // 提取参数
            $bizContent = [
                'trade_no'     => $this->getString($data, 'trade_no', true),
                'out_trade_no' => $this->getString($data, 'out_trade_no', true),
            ];

            $order = $this->findOrder($request, $bizContent, ['trade_no', 'trade_state', 'api_trade_no', 'payment_channel_account_id']);
            if ($order === null) {
                return $this->fail('订单不存在');
            }

            // 调用 OrderService 关闭订单（支持调用支付网关）
            $result = OrderService::handleOrderClose($order->trade_no, callGateway: true);

            if (!$result['state']) {
                return $this->fail($result['message']);
            }

            return $this->success([
                'trade_no'       => $order->trade_no,
                'trade_state'    => 'TRADE_CLOSED',
                'gateway_return' => $result['gateway_return'],
            ], '订单关闭成功');
        } catch (Throwable $e) {
            Log::error('关闭订单异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 退款查询接口
     *
     * biz_content 参数：
     * - trade_no: 平台订单号（与 out_trade_no 二选一）
     * - out_trade_no: 商户订单号（与 trade_no 二选一）
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function refundQuery(Request $request): Response
    {
        try {
            $data = $this->parseBizContent($request);
            if (is_string($data)) {
                return $this->fail($data);
            }

            // 提取参数
            $bizContent = [
                'trade_no'     => $this->getString($data, 'trade_no', true),
                'out_trade_no' => $this->getString($data, 'out_trade_no', true),
            ];

            $order = $this->findOrder($request, $bizContent, ['trade_no', 'out_trade_no', 'total_amount']);
            if ($order === null) {
                return $this->fail('订单不存在');
            }

            // 查询退款记录详情
            $refunds = $order->refunds()
                ->select([
                    'id',
                    'trade_no',
                    'out_biz_no',
                    'api_refund_no',
                    'amount',
                    'refund_fee_amount',
                    'reason',
                    'created_at'
                ])
                ->get();

            // 格式化退款记录
            $refundList = $refunds->map(function ($refund) {
                return [
                    'refund_id'         => $refund->id,
                    'out_biz_no'        => $refund->out_biz_no,
                    'api_refund_no'     => $refund->api_refund_no,
                    'refund_amount'     => $refund->amount,
                    'refund_fee_amount' => $refund->refund_fee_amount,
                    'reason'            => $refund->reason,
                    'refund_time'       => $refund->created_at,
                ];
            })->toArray();

            return $this->success([
                'trade_no'      => $order->trade_no,
                'out_trade_no'  => $order->out_trade_no,
                'total_amount'  => $order->total_amount,
                'refund_count'  => count($refundList),
                'refund_amount' => $refunds->sum('amount'),
                'refunds'       => $refundList,
            ], '查询成功');
        } catch (Throwable $e) {
            Log::error('退款查询异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 查找订单
     *
     * 根据 trade_no 或 out_trade_no 查找订单
     *
     * @param Request $request    请求对象
     * @param array   $bizContent 业务参数 (必须包含经过验证的 trade_no 或 out_trade_no)
     * @param array   $columns    查询字段
     * @return Order|null 订单模型或null
     */
    private function findOrder(Request $request, array $bizContent, array $columns = ['*']): ?Order
    {
        if (empty($bizContent['trade_no']) && empty($bizContent['out_trade_no'])) {
            return null;
        }

        $query = Order::where('merchant_id', $this->getMerchantId($request));

        // 优先使用 trade_no
        if (!empty($bizContent['trade_no'])) {
            $query->where('trade_no', $bizContent['trade_no']);
        } else {
            $query->where('out_trade_no', $bizContent['out_trade_no']);
        }

        return $query->first($columns);
    }
}
