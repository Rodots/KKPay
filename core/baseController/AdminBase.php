<?php

declare(strict_types=1);

namespace Core\baseController;

use Core\Traits\AdminResponse;
use Core\Traits\AdminRole;
use SodiumException;
use support\Request;
use support\Rodots\Crypto\XChaCha20;

class AdminBase
{
    use AdminResponse, AdminRole;

    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = [];

    /**
     * 解密加密的请求载荷
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

    protected function adminLog(string $content, ?int $admin_id = null): void
    {
        $admin_id = $admin_id ?: (request()->AdminInfo['id'] ?? 0);
        if (empty($admin_id)) {
            return;
        }
        $row = new \app\model\AdminLog();

        $row->admin_id   = $admin_id;
        $row->content    = $content;
        $row->ip         = get_client_ip();
        $row->user_agent = request()->header('user-agent', 'unknown');
        $row->save();
    }
}
