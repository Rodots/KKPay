<?php

declare(strict_types = 1);

namespace Gateway\Alipay;

use Exception;
use Gateway\Alipay\Util\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * 支付宝开放平台 API 客户端，用于发起服务端 API 调用（如支付、查询等）以及生成前端跳转表单。
 *
 * 该客户端支持支付宝 API v3（基于 HTTP/2 + JSON + RSA2 签名）和传统表单跳转（v1.0）两种调用方式：
 * - execute()：用于服务端到服务端的 API 调用（POST JSON）
 * - pageExecute()：用于生成 HTML 表单，引导用户跳转至支付宝收银台页面（如电脑网站支付）
 *
 * 支持请求/响应内容加密（AES）与签名验证（RSA2），并自动注入 trace ID 用于链路追踪。
 */
readonly class AlipayClient
{
    /**
     * 默认 HTTP 请求超时时间（秒）
     */
    private const int DEFAULT_TIMEOUT = 5;

    /**
     * 支付宝 API 版本标识，当前固定为 'v3'
     */
    private const string API_VERSION = 'v3';

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
     * 工厂方法：创建 AlipayClient 实例
     *
     * @param array|AlipayConfig $config     支付宝配置，可为数组或 AlipayConfig 对象
     * @param Client|null        $httpClient 可选的 Guzzle 客户端，若未提供则自动创建
     * @return self 返回 AlipayClient 实例
     */
    public static function create(array|AlipayConfig $config, ?Client $httpClient = null): self
    {
        $alipayConfig = $config instanceof AlipayConfig ? $config : AlipayConfig::fromArray($config);

        $client = $httpClient ?? new Client([
            // 'base_uri' => 'https://openapi-sandbox.dl.alipaydev.com/',
            'base_uri' => 'https://openapi.alipay.com/',
            'timeout'  => self::DEFAULT_TIMEOUT,
        ]);

        return new self($alipayConfig, $client);
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
     * @throws JsonException 当 JSON 编码/解码失败时抛出
     * @throws GuzzleException 当 HTTP 请求失败时抛出
     */
    public function execute(array $params, string $methodName): array
    {
        // 构造请求路径，将方法名中的点号替换为斜杠
        $requestPath = self::API_VERSION . '/' . str_replace('.', '/', $methodName);
        // 准备请求体，根据配置决定是否加密
        $requestBody = $this->prepareRequestBody($params);
        // 构建请求头并附加签名
        $headers = $this->buildRequestHeaders($requestPath, $requestBody);

        // 发送 POST 请求到支付宝 API
        $response = $this->httpClient->post($requestPath, [
            'headers' => $headers,
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
     */
    public function pageExecute(array $params, string $methodName, string $returnUrl, string $notifyUrl): string
    {
        $commonParams = $this->configManager->buildRequestParams($params, $methodName, $returnUrl, $notifyUrl);

        $html = '<form id="alipaysubmit" name="alipaysubmit" action="https://openapi.alipay.com/gateway.do?charset=utf-8" method="POST">';
        foreach ($commonParams as $key => $value) {
            $value = htmlentities($value, ENT_QUOTES | ENT_HTML5);
            $html  .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
        }
        $html .= '<input type="submit" value="ok" style="display:none"></form>';
        $html .= '<script>document.forms["alipaysubmit"].submit();</script>';

        return $html;
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
        $commonParams = $this->configManager->buildRequestParams($params, $methodName, $returnUrl, $notifyUrl);

        // 发送 POST 请求到支付宝 API
        $response = $this->httpClient->post('gateway.do?charset=utf-8', [
            'headers'     => [
                'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8',
                'Accept'       => 'application/json',
            ],
            'form_params' => $commonParams,
        ]);

        $responseBody = $response->getBody()->getContents();

        $result = $this->configManager->verifyResponseV1($responseBody, $methodName);

        if ($methodName == 'alipay.system.oauth.token' && isset($result['access_token'])) {
            return $result;
        } elseif (isset($result['code']) && $result['code'] == '10000') {
            return $result;
        } else {
            if (isset($result['sub_msg'])) {
                $message = '[' . $result['sub_code'] . ']' . $result['sub_msg'];
            } elseif (isset($result['msg'])) {
                $message = '[' . $result['code'] . ']' . $result['msg'];
            } else {
                $message = '未知错误';
            }
            throw new Exception($message);
        }
    }

    /**
     * 准备请求体内容
     *
     * 若启用了请求加密，则对 JSON 字符串进行 AES 加密；否则直接返回 JSON 字符串。
     *
     * @param array $params 业务参数数组
     * @return string JSON 编码后的请求体（可能已加密）
     * @throws JsonException 当 JSON 编码失败时抛出
     */
    private function prepareRequestBody(array $params): string
    {
        $requestBody = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($this->config->isEncryptEnabled()) {
            return $this->configManager->encryptRequest($requestBody);
        }

        return $requestBody;
    }

    /**
     * 构建带有签名的请求头
     *
     * 根据是否启用加密设置 Content-Type 和 alipay-encrypt-type，
     * 并调用签名逻辑注入 Authorization 头（符合 Alipay v3 签名规范）。
     *
     * @param string $requestPath 请求路径（不含域名），如 'v3/alipay/trade/page/pay'
     * @param string $requestBody 原始请求体（可能已加密）
     * @return array 包含必要头信息的关联数组
     * @throws Exception
     */
    private function buildRequestHeaders(string $requestPath, string $requestBody): array
    {
        $headers = [
            'alipay-request-id' => date('YmdHis') . uniqid(),
            'Content-Type'      => 'application/json',
        ];

        if ($this->config->isEncryptEnabled()) {
            $headers['Content-Type']        = 'text/plain;charset=utf-8';
            $headers['alipay-encrypt-type'] = 'AES';
        }

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
     * @throws JsonException 当响应体不是合法 JSON 时抛出
     */
    private function processResponse(ResponseInterface $response): array
    {
        $responseBody    = $response->getBody()->getContents();
        $responseHeaders = $response->getHeaders();
        $statusCode      = $response->getStatusCode();

        // 处理加密响应
        // 通过ConfigManager获取头部值
        $encryptType = $this->configManager->getHeaderValue($responseHeaders, 'alipay-encrypt-type');
        if ($this->config->isEncryptEnabled() && $statusCode >= 200 && $statusCode < 300 && !empty($encryptType)) {
            $responseBody = $this->configManager->decryptResponse($responseBody);
        }

        $this->configManager->verifyResponseV3($responseBody, $responseHeaders);

        return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    }
}
