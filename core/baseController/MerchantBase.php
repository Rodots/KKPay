<?php

declare(strict_types=1);

namespace Core\baseController;

use Core\Traits\AdminResponse;
use SodiumException;
use support\Request;
use support\Rodots\Crypto\XChaCha20;

/**
 * 商户端控制器基类
 *
 * 提供商户端公共方法：加解密（仅登录接口使用）、操作日志记录、统一响应格式。
 * 商户端无权限管理设计，不引入 AdminRole。
 */
class MerchantBase
{
    use AdminResponse;

    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = [];

    /**
     * 解密加密的请求载荷（仅登录接口使用）
     *
     * @param Request $request HTTP请求对象
     * @return array|null 解密后的参数数组，失败返回null
     * @throws SodiumException
     */
    protected function decryptPayload(Request $request): ?array
    {
        $payload = $request->post('payload');
        if (empty($payload)) {
            return null;
        }
        return new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);
    }

    /**
     * 记录商户操作日志
     *
     * @param string   $content     日志内容
     * @param int|null $merchant_id 商户ID，默认从请求上下文获取
     * @return void
     */
    protected function merchantLog(string $content, ?int $merchant_id = null): void
    {
        $merchant_id = $merchant_id ?: (request()->MerchantInfo['id'] ?? 0);
        if (empty($merchant_id)) {
            return;
        }
        $row = new \app\model\MerchantLog();

        $row->merchant_id = $merchant_id;
        $row->content     = $content;
        $row->ip          = get_client_ip();
        $row->user_agent  = request()->header('user-agent', 'unknown');
        $row->save();
    }
}
