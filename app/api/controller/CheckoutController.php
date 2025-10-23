<?php

declare(strict_types = 1);

namespace app\api\controller;

use app\model\Order;
use app\model\PaymentChannel;
use Core\Exception\PaymentException;
use Core\Service\PaymentService;
use Core\Traits\ApiResponse;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 收银台控制器
 * 处理收银台页面展示和支付方式选择
 */
class CheckoutController
{
    use ApiResponse;

    /**
     * 收银台页面
     * 路由：/api/checkout/{order_no}
     */
    public function index(Request $request, string $orderNo): Response
    {
        try {
            if (empty($orderNo)) {
                return $this->pageMsg('订单号不能为空');
            }

            // 查找订单
            $order = Order::where('trade_no', $orderNo)->first();
            if (!$order) {
                return $this->pageMsg('订单不存在');
            }

            // 检查订单状态
            if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY) {
                return $this->pageMsg('订单状态异常，无法支付');
            }

            // 检查订单是否已过期（30分钟）
            if (strtotime($order->create_time) < time() - 1800) {
                return $this->pageMsg('订单已过期');
            }

            // 获取可用的支付方式
            $paymentTypes = $this->getAvailablePaymentTypes($order->total_amount);

            // 渲染收银台页面
            return $this->renderCheckoutPage($order, $paymentTypes);

        } catch (Throwable $e) {
            Log::error('收银台页面加载异常', [
                'order_no' => $orderNo ?? '',
                'error'    => $e->getMessage(),
                'ip'       => $request->getRealIp()
            ]);
            return $this->pageMsg('页面加载失败，请稍后重试');
        }
    }

    /**
     * 选择支付方式
     * 处理用户在收银台选择的支付方式
     */
    public function selectPayment(Request $request): Response
    {
        try {
            $orderNo            = $request->route('order_no');
            $paymentType        = $request->post('payment_type');
            $paymentChannelCode = $request->post('payment_channel_code');

            if (empty($orderNo)) {
                return $this->fail('订单号不能为空');
            }

            if (empty($paymentType)) {
                return $this->fail('请选择支付方式');
            }

            // 查找订单
            $order = Order::where('trade_no', $orderNo)->first();
            if (!$order) {
                return $this->fail('订单不存在');
            }

            // 检查订单状态
            if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY) {
                return $this->fail('订单状态异常，无法支付');
            }

            // 检查订单是否已过期
            if (strtotime($order->create_time) < time() - 1800) {
                return $this->fail('订单已过期');
            }

            // 验证支付方式
            if (!Order::checkPaymentType($paymentType)) {
                return $this->fail('不支持的支付方式');
            }

            // 更新订单支付方式和通道
            $this->updateOrderPaymentInfo($order, $paymentType, $paymentChannelCode);

            // 发起支付
            $paymentResult = PaymentService::initiatePayment($order);

            // 返回支付结果
            $responseData = [
                'trade_no'     => $order->trade_no,
                'payment_type' => $order->payment_type,
                'order_status' => $order->trade_state,
            ];

            if (!empty($paymentResult['qr_code'])) {
                $responseData['qr_code']        = $paymentResult['qr_code'];
                $responseData['payment_method'] = 'qr_code';
            } elseif (!empty($paymentResult['payment_url'])) {
                $responseData['payment_url']    = $paymentResult['payment_url'];
                $responseData['payment_method'] = 'redirect';
            } elseif (!empty($paymentResult['form_data'])) {
                $responseData['form_data']      = $paymentResult['form_data'];
                $responseData['payment_method'] = 'form';
            }

            return $this->success($responseData, '支付发起成功');

        } catch (PaymentException $e) {
            Log::warning('收银台支付选择失败', [
                'order_no'     => $orderNo ?? '',
                'payment_type' => $paymentType ?? '',
                'error'        => $e->getMessage(),
                'ip'           => $request->getRealIp()
            ]);
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::error('收银台支付选择异常', [
                'order_no'     => $orderNo ?? '',
                'payment_type' => $paymentType ?? '',
                'error'        => $e->getMessage(),
                'ip'           => $request->getRealIp()
            ]);
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 获取可用的支付方式
     */
    private function getAvailablePaymentTypes(float $amount): array
    {
        $paymentChannels = PaymentChannel::where('status', true)
            ->where(function ($query) use ($amount) {
                $query->where(function ($q) use ($amount) {
                    $q->whereNull('min_amount')->orWhere('min_amount', '<=', $amount);
                })->where(function ($q) use ($amount) {
                    $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount);
                });
            })
            ->get();

        $paymentTypes = [];
        foreach ($paymentChannels as $channel) {
            $paymentTypes[$channel->payment_type] = [
                'type'     => $channel->payment_type,
                'name'     => $channel->payment_type_text,
                'channels' => []
            ];
        }

        // 添加通道信息
        foreach ($paymentChannels as $channel) {
            $paymentTypes[$channel->payment_type]['channels'][] = [
                'code'       => $channel->code,
                'name'       => $channel->name,
                'rate'       => $channel->rate,
                'min_amount' => $channel->min_amount,
                'max_amount' => $channel->max_amount,
            ];
        }

        return array_values($paymentTypes);
    }

    /**
     * 更新订单支付信息
     */
    private function updateOrderPaymentInfo(Order $order, string $paymentType, ?string $paymentChannelCode): void
    {
        // 选择支付通道账户
        if (!empty($paymentChannelCode)) {
            $paymentChannelAccount = PaymentService::selectPaymentChannelByCode(
                $paymentChannelCode,
                $paymentType,
                $order->total_amount
            );
        } else {
            $paymentChannelAccount = PaymentService::selectPaymentChannelByType(
                $paymentType,
                $order->total_amount
            );
        }

        // 更新订单
        $order->payment_type               = $paymentType;
        $order->payment_channel_account_id = $paymentChannelAccount->id;
        $order->save();
    }

    /**
     * 渲染收银台页面
     */
    private function renderCheckoutPage(Order $order, array $paymentTypes): Response
    {
        $html = $this->generateCheckoutHtml($order, $paymentTypes);
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * 生成收银台HTML
     */
    private function generateCheckoutHtml(Order $order, array $paymentTypes): string
    {
        $paymentTypesJson = json_encode($paymentTypes, JSON_UNESCAPED_UNICODE);

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>收银台 - 选择支付方式</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .checkout-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .checkout-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 24px;
            text-align: center;
        }
        .checkout-header h1 { font-size: 24px; margin-bottom: 8px; }
        .checkout-header p { opacity: 0.9; font-size: 14px; }
        .order-info {
            padding: 24px;
            border-bottom: 1px solid #f0f0f0;
        }
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .order-item:last-child { margin-bottom: 0; }
        .order-label { color: #666; font-size: 14px; }
        .order-value { font-weight: 500; }
        .amount { color: #e74c3c; font-size: 24px; font-weight: bold; }
        .payment-methods {
            padding: 24px;
        }
        .payment-method {
            border: 2px solid #f0f0f0;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        .payment-method:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .payment-method.selected {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .payment-method input[type="radio"] {
            margin-right: 12px;
            transform: scale(1.2);
        }
        .payment-method-info {
            flex: 1;
        }
        .payment-method-name {
            font-weight: 500;
            margin-bottom: 4px;
        }
        .payment-method-desc {
            color: #666;
            font-size: 12px;
        }
        .checkout-footer {
            padding: 24px;
            background: #f8f9fa;
        }
        .pay-button {
            width: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 16px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .pay-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }
        .pay-button:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="checkout-container">
        <div class="checkout-header">
            <h1>收银台</h1>
            <p>请选择支付方式完成付款</p>
        </div>
        
        <div class="order-info">
            <div class="order-item">
                <span class="order-label">订单号</span>
                <span class="order-value">{$order->trade_no}</span>
            </div>
            <div class="order-item">
                <span class="order-label">商品名称</span>
                <span class="order-value">{$order->subject}</span>
            </div>
            <div class="order-item">
                <span class="order-label">支付金额</span>
                <span class="order-value amount">¥{$order->total_amount}</span>
            </div>
        </div>

        <div class="payment-methods" id="paymentMethods">
            <!-- 支付方式将通过JavaScript动态生成 -->
        </div>

        <div class="checkout-footer">
            <button class="pay-button" id="payButton" onclick="submitPayment()" disabled>
                立即支付 ¥{$order->total_amount}
            </button>
        </div>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p>正在处理支付请求...</p>
        </div>
    </div>

    <script>
        const paymentTypes = {$paymentTypesJson};
        let selectedPaymentType = null;
        let selectedChannelCode = null;

        // 渲染支付方式
        function renderPaymentMethods() {
            const container = document.getElementById('paymentMethods');
            container.innerHTML = '';

            paymentTypes.forEach((type, index) => {
                const methodDiv = document.createElement('div');
                methodDiv.className = 'payment-method';
                methodDiv.onclick = () => selectPaymentMethod(type.type, type.channels[0]?.code);
                
                methodDiv.innerHTML = `
                    <input type="radio" name="payment_type" value="\${type.type}" id="payment_\${index}">
                    <div class="payment-method-info">
                        <div class="payment-method-name">\${type.name}</div>
                        <div class="payment-method-desc">安全快捷的\${type.name}支付</div>
                    </div>
                `;
                
                container.appendChild(methodDiv);
            });
        }

        // 选择支付方式
        function selectPaymentMethod(paymentType, channelCode) {
            selectedPaymentType = paymentType;
            selectedChannelCode = channelCode;
            
            // 更新UI
            document.querySelectorAll('.payment-method').forEach(el => {
                el.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            // 选中对应的radio
            document.querySelector(`input[value="\${paymentType}"]`).checked = true;
            
            // 启用支付按钮
            document.getElementById('payButton').disabled = false;
        }

        // 提交支付
        async function submitPayment() {
            if (!selectedPaymentType) {
                alert('请选择支付方式');
                return;
            }

            const payButton = document.getElementById('payButton');
            const loading = document.getElementById('loading');
            
            payButton.style.display = 'none';
            loading.style.display = 'block';

            try {
                const response = await fetch('/api/checkout/{$order->trade_no}/select', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `payment_type=\${selectedPaymentType}&payment_channel_code=\${selectedChannelCode || ''}`
                });

                const result = await response.json();
                
                if (result.state) {
                    // 支付成功，处理跳转
                    if (result.data.payment_method === 'redirect' && result.data.payment_url) {
                        window.location.href = result.data.payment_url;
                    } else if (result.data.payment_method === 'qr_code' && result.data.qr_code) {
                        // 显示二维码
                        showQRCode(result.data.qr_code);
                    } else {
                        alert('支付方式暂不支持，请选择其他方式');
                    }
                } else {
                    alert(result.message || '支付发起失败');
                }
            } catch (error) {
                console.error('支付请求失败:', error);
                alert('网络错误，请稍后重试');
            } finally {
                payButton.style.display = 'block';
                loading.style.display = 'none';
            }
        }

        // 显示二维码
        function showQRCode(qrCode) {
            // 这里可以实现二维码显示逻辑
            alert('请使用手机扫描二维码完成支付\\n' + qrCode);
        }

        // 初始化
        document.addEventListener('DOMContentLoaded', function() {
            renderPaymentMethods();
        });
    </script>
</body>
</html>
HTML;
    }
}
