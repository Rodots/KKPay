<?php

declare(strict_types = 1);

namespace Core\Service;

use app\model\Order;
use app\model\OrderBuyer;
use app\model\PaymentChannelAccount;
use Core\Exception\PaymentException;
use Core\Utils\PaymentGatewayUtil;
use support\Log;
use support\Response;
use Throwable;

/**
 * 支付服务类
 * 封装核心支付业务逻辑
 */
class PaymentService
{
    /**
     * 发起支付
     *
     * @throws PaymentException
     */
    public static function initiatePayment(array $order, PaymentChannelAccount $paymentChannelAccount, OrderBuyer $orderBuyer, string $mode = 'submit'): array
    {
        try {
            // 获取支付通道信息
            $paymentChannel = $paymentChannelAccount->paymentChannel;
            if (!$paymentChannel) {
                throw new PaymentException('支付通道信息缺失');
            }

            $items = [
                'order'      => $order,
                'channel'    => $paymentChannelAccount->config,
                'buyer'      => $orderBuyer->toArray(),
                'config'     => sys_config(),
                'subject'    => $order['subject'],
                'return_url' => site_url("pay/return/{$order['trade_no']}.html"),
                'notify_url' => site_url("pay/notify/{$order['trade_no']}.html"),
            ];

            // 加载网关
            return PaymentGatewayUtil::loadGateway($paymentChannel->gateway, $mode, $items);
        } catch (Throwable $e) {
            Log::error('支付发起失败，调用网关失败', [
                'trade_no'    => $order['trade_no'],
                'error'       => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);

            throw new PaymentException('支付发起失败');
        }
    }

    /**
     * 查询订单状态
     */
    public static function queryOrderStatus(Order $order): array
    {
        try {
            if (!$order->paymentChannelAccount) {
                throw new PaymentException('订单未绑定支付通道');
            }

            // TODO: 实现网关查询逻辑
            return [
                'success'      => true,
                'trade_status' => $order->trade_state,
                'message'      => '查询成功'
            ];

        } catch (Throwable $e) {
            Log::error('订单状态查询失败', [
                'trade_no' => $order->trade_no,
                'error'    => $e->getMessage()
            ]);

            throw new PaymentException('订单状态查询失败：' . $e->getMessage());
        }
    }

    /**
     * 处理退款申请
     */
    public static function processRefund(array $params): array
    {
        try {
            // 查找订单
            $order = Order::where('merchant_id', $params['merchant_id'])
                ->where('out_trade_no', $params['out_trade_no'])
                ->first();

            if (!$order) {
                throw new PaymentException('订单不存在');
            }

            if ($order->trade_state !== Order::TRADE_STATE_SUCCESS) {
                throw new PaymentException('订单状态不支持退款');
            }

            // 验证退款金额
            if ($params['refund_amount'] > $order->total_amount) {
                throw new PaymentException('退款金额不能超过订单金额');
            }

            // 检查是否已有退款记录
            $existingRefund = $order->refunds()
                ->where('status', 1)
                ->sum('amount');

            if (($existingRefund + $params['refund_amount']) > $order->total_amount) {
                throw new PaymentException('退款总金额不能超过订单金额');
            }

            // 生成退款单号
            $outRefundNo = $params['out_refund_no'] ?? self::generateRefundNo();

            // 创建退款记录
            $refund = $order->refunds()->create([
                'trade_no'      => $order->trade_no,
                'out_refund_no' => $outRefundNo,
                'user_id'       => $order->merchant_id,
                'amount'        => $params['refund_amount'],
                'real_amount'   => $params['refund_amount'],
                'reason'        => $params['refund_reason'] ?? '',
                'status'        => 0, // 0-处理中
                'notify_url'    => $params['notify_url'] ?? null,
            ]);

            // TODO: 调用网关退款接口
            // 这里需要根据实际的网关实现来处理退款
            $refundResult = [
                'success'       => true,
                'refund_status' => 'SUCCESS',
                'api_trade_no'  => 'REFUND_' . time(),
                'message'       => '退款成功'
            ];

            if ($refundResult['success']) {
                // 更新退款状态
                $refund->update([
                    'status'        => $refundResult['refund_status'] === 'SUCCESS' ? 1 : 0,
                    'api_refund_no' => $refundResult['api_trade_no'] ?? null,
                ]);

                // 如果退款成功，更新订单状态
                if ($refundResult['refund_status'] === 'SUCCESS') {
                    self::updateOrderRefundStatus($order);
                }

                Log::info('退款申请成功', [
                    'trade_no'  => $order->trade_no,
                    'refund_id' => $refund->id,
                    'amount'    => $params['refund_amount']
                ]);

                return [
                    'success'       => true,
                    'refund_id'     => $refund->id,
                    'out_refund_no' => $outRefundNo,
                    'refund_status' => $refund->status === 1 ? 'success' : 'processing',
                    'refund_amount' => $params['refund_amount'],
                ];
            } else {
                // 退款失败，更新状态
                $refund->update(['status' => 2]); // 2-失败

                throw new PaymentException('退款申请失败：' . ($refundResult['message'] ?? '未知错误'));
            }

        } catch (PaymentException $e) {
            Log::error('退款申请异常', [
                'params' => $params,
                'error'  => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 查询退款状态
     */
    public static function queryRefund(array $params): array
    {
        try {
            // 构建查询条件
            $query = Order::where('merchant_id', $params['merchant_id']);

            if (!empty($params['out_trade_no'])) {
                $query->where('out_trade_no', $params['out_trade_no']);
            }

            $order = $query->first();
            if (!$order) {
                throw new PaymentException('订单不存在');
            }

            // 查找退款记录
            $refundQuery = $order->refunds();
            if (!empty($params['out_refund_no'])) {
                $refundQuery->where('out_refund_no', $params['out_refund_no']);
            }

            $refund = $refundQuery->latest()->first();
            if (!$refund) {
                throw new PaymentException('退款记录不存在');
            }

            // TODO: 如果退款状态为处理中，查询网关状态
            // 这里需要根据实际的网关实现来查询退款状态

            // 状态文本映射
            $statusText = match ($refund->status) {
                0 => 'processing',
                1 => 'success',
                2 => 'failed',
                default => 'unknown'
            };

            return [
                'success'       => true,
                'refund_id'     => $refund->id,
                'out_trade_no'  => $order->trade_no,
                'out_refund_no' => $refund->out_refund_no,
                'refund_status' => $statusText,
                'refund_amount' => $refund->amount,
                'refund_reason' => $refund->reason ?? '',
            ];

        } catch (PaymentException $e) {
            Log::error('退款查询异常', [
                'params' => $params,
                'error'  => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 更新订单退款状态
     */
    private static function updateOrderRefundStatus(Order $order): void
    {
        $totalRefunded = $order->refunds()->where('status', 1)->sum('amount');

        if ($totalRefunded >= $order->total_amount) {
            $order->update(['trade_state' => Order::TRADE_STATE_FINISHED]);
        } else {
            // 部分退款状态，可以根据业务需要定义新的状态
            $order->update(['trade_state' => Order::TRADE_STATE_SUCCESS]);
        }
    }

    /**
     * 生成退款单号
     */
    private static function generateRefundNo(): string
    {
        return 'RF' . date('YmdHis') . mt_rand(1000, 9999);
    }

    /**
     * @throws PaymentException
     */
    public static function echoSubmit(array $result, array $order): Response
    {
        $type = $result['type'] ?? '';
        if (!$type) {
            throw new PaymentException('支付网关返回了未知的处理类型');
        }
        switch ($type) {
            case 'redirect': //跳转
                $url       = htmlspecialchars($result['url'] ?? '', ENT_QUOTES, 'UTF-8');
                $html_text = '<script>window.location.replace(\'' . $url . '\');</script>';
                return self::redirectTemplate('正在为您跳转到支付页面，请稍候...', $html_text);
            case 'html': //显示html
                $html_text = $result['data'] ?? '';
                if (isset($result['submit']) && $result['submit'] && str_starts_with($html_text, '<form ')) {
                    return self::redirectTemplate('正在为您跳转到支付页面，请稍候...', $html_text);
                } else {
                    return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache'], $html_text);
                }
            case 'json': //显示JSON
                return json($result['data'] ?? []);
            case 'page': //显示指定页面
                $page = $result['page'] ?? '404';
                try {
                    return raw_view("/app/api/view/pay_page/$page", array_merge($result['data'] ?? [], ['order' => $order]));
                } catch (Throwable $e) {
                    Log::error($e->getTraceAsString());
                    throw new PaymentException("页面不存在: $page");
                }
            case 'return': //同步回调
                $url       = htmlspecialchars($result['url'] ?? '', ENT_QUOTES, 'UTF-8');
                $html_text = '<script>window.location.href=window.atob(\'' . $url . '\');</script>';
                return self::redirectTemplate('支付完成，正在跳转请稍候...', $html_text);
            case 'error': //错误提示
            default:
                throw new PaymentException($result['message'] ?? '未知错误');
        }
    }

    private static function redirectTemplate(string $title, string $html_text): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        .loader {
          width: fit-content;
          font-weight: bold;
          font-family: sans-serif;
          font-size: 30px;
          padding: 0 5px 8px 0;
          background: repeating-linear-gradient(90deg,currentColor 0 8%,#0000 0 10%) 200% 100%/200% 3px no-repeat;
          animation: l3 1.5s steps(6) infinite;
        }
        .loader:before {
          content:"{$title}"
        }
        @keyframes l3 {to{background-position: 80% 100%}}
    </style>
</head>
<body>
    <div class="loader"></div>
    {$html_text}
</body>
</html>
HTML;
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache'], $html);
    }
}
