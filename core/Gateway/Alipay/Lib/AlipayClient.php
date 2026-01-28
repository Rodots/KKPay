<?php

declare(strict_types=1);

namespace Core\Gateway\Alipay\Lib;

use Exception;
use Core\Gateway\Alipay\Lib\Util\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * 支付宝开放平台 API 客户端，用于发起服务端 API 调用（如支付、查询等）以及生成前端跳转表单。
 */
readonly class AlipayClient
{
    /**
     * 支付宝开放平台网关地址
     * 正式环境: 'https://openapi.alipay.com'
     * 沙盒环境: 'https://openapi-sandbox.dl.alipaydev.com'
     */
    public const string GATEWAY_URL = 'https://openapi.alipay.com';

    /**
     * 默认 HTTP 请求超时时间（秒）
     */
    public const int DEFAULT_TIMEOUT = 8;

    /**
     * 配置管理器实例，用于处理签名、加密、验签等操作
     */
    private ConfigManager $configManager;

    /**
     * 构造函数，初始化支付宝客户端
     *
     * @param AlipayConfig $config     支付宝接入配置（含 app_id、私钥、公钥等）
     * @param Client       $httpClient Guzzle HTTP 客户端实例
     */
    public function __construct(
        private AlipayConfig $config,
        private Client       $httpClient
    )
    {
        $this->configManager = new ConfigManager($config);
    }

    /**
     * 获取配置管理器实例
     */
    public function getConfigManager(): ConfigManager
    {
        return $this->configManager;
    }

    /**
     * 执行支付宝服务端 API 调用（适用于 v3 接口）
     *
     * 该方法将参数以 JSON 格式发送至支付宝，自动处理签名、加密、验签等流程。
     *
     * @param array  $params     业务参数（如订单信息），将作为 biz_content 的内容（若未加密）或整体请求体（若加密）
     * @param string $methodName 支付宝接口方法名，例如 'alipay.trade.pay'
     * @return array 解析后的响应数据（已解密并验证签名）
     * @throws Exception 当内部处理中发生异常时抛出
     * @throws GuzzleException 当 HTTP 请求失败时抛出
     */
    public function execute(array $params, string $methodName): array
    {
        // 构造请求路径，将方法名中的点号替换为斜杠
        $requestPath = '/v3/' . str_replace('.', '/', $methodName);
        // 准备请求体，根据配置决定是否加密
        $requestBody = $this->prepareRequestBody($params);

        // 发送 POST 请求到支付宝 API
        $response = $this->httpClient->post($requestPath, [
            'headers' => $this->buildRequestHeaders($requestPath, $requestBody),
            'body'    => $requestBody,
        ]);

        // 处理响应并返回解析后的数据
        return $this->processResponse($response);
    }

    /**
     * 生成支付宝页面跳转表单（适用于传统网页支付等场景）
     *
     * 该方法返回一段 HTML 表单代码，前端自动提交后跳转至支付宝收银台。
     * 使用支付宝 v1.0 协议（表单提交 + RSA2 签名），不支持内容加密。
     *
     * @param array  $params     业务参数，将被 JSON 编码为 biz_content
     * @param string $methodName 支付宝接口方法名，例如 'alipay.trade.page.pay'
     * @param string $returnUrl  支付完成后同步跳转回商户页面的 URL
     * @param string $notifyUrl  支付结果异步通知地址（服务器回调）
     * @return string 包含自动提交表单的 HTML 字符串
     * @throws Exception
     */
    public function pageExecute(array $params, string $methodName, string $returnUrl, string $notifyUrl): string
    {
        $inputs = implode('', array_map(
            fn($k, $v) => sprintf('<input type="hidden" name="%s" value="%s"/>', $k, htmlentities($v, ENT_QUOTES | ENT_HTML5)),
            array_keys($commonParams = $this->configManager->buildRequestParams($params, $methodName, $returnUrl, $notifyUrl)),
            $commonParams
        ));

        return '<form id="alipaysubmit" action="' . self::GATEWAY_URL . '/gateway.do?charset=utf-8" method="POST">' . $inputs . '</form><script>document.forms.alipaysubmit.submit()</script>';
    }

    /**
     * 执行支付宝服务端 API 调用（适用于 v1 接口）
     *
     * 使用支付宝 v1.0 协议（表单提交 + RSA2 签名），不支持内容加密。
     *
     * @param array  $params     业务参数，将被 JSON 编码为 biz_content
     * @param string $methodName 支付宝接口方法名，例如 'alipay.trade.page.pay'
     * @param string $returnUrl  支付完成后同步跳转回商户页面的 URL
     * @param string $notifyUrl  支付结果异步通知地址（服务器回调）
     * @return array 解析后的响应数据（已解密并验证签名）
     * @throws Exception 当内部处理中发生异常时抛出
     * @throws GuzzleException 当 HTTP 请求失败时抛出
     */
    public function v1Execute(array $params, string $methodName, string $returnUrl, string $notifyUrl): array
    {
        $response = $this->httpClient->post('/gateway.do?charset=utf-8', [
            'headers'     => ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8', 'Accept' => 'application/json'],
            'form_params' => $this->configManager->buildRequestParams($params, $methodName, $returnUrl, $notifyUrl),
        ]);

        $result = $this->configManager->verifyResponseV1($response->getBody()->getContents(), $methodName);

        // 业务接口返回 code=10000 均视为成功
        if (($result['code'] ?? '') === '10000') {
            return $result;
        }

        throw new Exception(match (true) {
            isset($result['sub_msg']) => "[{$result['sub_code']}] {$result['sub_msg']}",
            isset($result['msg']) => "[{$result['code']}] {$result['msg']}",
            default => '未知错误'
        });
    }

    /**
     * 准备请求体内容
     *
     * 若启用了请求加密，则对 JSON 字符串进行 AES 加密；否则直接返回 JSON 字符串。
     *
     * @param array $params 业务参数数组
     * @return string JSON 编码后的请求体（可能已加密）
     * @throws Exception
     */
    private function prepareRequestBody(array $params): string
    {
        $body = json_encode($params, JSON_UNESCAPED_UNICODE);
        return $this->config->isEncryptEnabled() ? $this->configManager->encryptRequest($body) : $body;
    }

    /**
     * 构建带有签名的请求头
     *
     * 根据是否启用加密设置 Content-Type 和 alipay-encrypt-type，
     * 并调用签名逻辑注入 Authorization 头（符合 Alipay v3 签名规范）。
     *
     * @param string $requestPath 请求路径（不含域名），如 '/v3/alipay/trade/page/pay'
     * @param string $requestBody 原始请求体（可能已加密）
     * @return array 包含必要头信息的关联数组
     * @throws Exception
     */
    private function buildRequestHeaders(string $requestPath, string $requestBody): array
    {
        $isEncrypt = $this->config->isEncryptEnabled();
        $headers   = [
            'alipay-request-id' => date('YmdHis') . uniqid(),
            'Content-Type'      => $isEncrypt ? 'text/plain; charset=utf-8' : 'application/json; charset=utf-8',
            ...($isEncrypt ? ['alipay-encrypt-type' => 'AES'] : []),
        ];

        $this->configManager->signV3('POST', $requestPath, $requestBody, $headers);
        return $headers;
    }

    /**
     * 处理支付宝 API 响应
     *
     * 自动判断是否需要解密响应体，并验证响应签名。
     * 最终返回解析为数组的 JSON 数据。
     *
     * @param ResponseInterface $response Guzzle 响应对象
     * @return array 解析后的响应数据
     * @throws Exception 当校验响应内容非正确时抛出
     */
    private function processResponse(ResponseInterface $response): array
    {
        [$statusCode, $headers, $rawBody] = [$response->getStatusCode(), $response->getHeaders(), $response->getBody()->getContents()];
        $isSuccess = $statusCode >= 200 && $statusCode < 300;

        // 处理加密响应（解密后用于数据解析，原始内容用于签名验证）
        $body = ($this->config->isEncryptEnabled() && $isSuccess) ? $this->configManager->decryptResponse($rawBody) : $rawBody;

        // 解析 JSON 并验证响应
        json_validate($body) || throw new Exception('支付宝返回内容非JSON格式');
        $data = json_decode($body, true);

        if ($isSuccess) {
            $this->configManager->verifyResponseV3($rawBody, $headers);
            return $data;
        }

        throw new Exception("[{$data['code']}] {$data['message']}");
    }
}
