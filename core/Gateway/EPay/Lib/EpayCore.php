<?php

declare(strict_types=1);

namespace Core\Gateway\EPay\Lib;

use Exception;

/**
 * EPay 支付网关核心类
 * 提供易支付接口的完整实现，包括支付、查询、退款等功能
 */
class EpayCore
{
    /** @var string API 基础地址 */
    private string $api_url;

    /** @var string 商户ID（merchant_id） */
    private string $merchant_id;

    /** @var string 平台公钥 */
    private string $public_key;

    /** @var string 商户私钥 */
    private string $private_key;

    /**
     * 构造函数
     * 初始化支付网关配置参数
     *
     * @param array $channel
     * @throws Exception 当必需参数缺失时抛出异常
     */
    public function __construct(array $channel)
    {
        $this->api_url     = rtrim((string)$channel['api_url'], '/') . '/';
        $this->merchant_id = (string)$channel['merchant_id'];
        $this->public_key  = (string)$channel['public_key'];
        $this->private_key = (string)$channel['private_key'];

        if (empty($this->merchant_id)) {
            throw new Exception('请先设置商户ID');
        }

        if (empty($this->private_key) || empty($this->public_key)) {
            throw new Exception('请先设置 平台公钥 和 商户私钥');
        }
    }

    /**
     * 发起支付（页面跳转）
     * 生成自动提交的HTML表单，用于页面跳转支付
     *
     * @param array  $biz_content 业务参数数组
     * @param string $button      提交按钮显示文本
     * @return string HTML表单代码
     * @throws Exception 当请求构建失败时抛出异常
     */
    public function pagePay(array $biz_content, string $button = '正在跳转'): string
    {
        $requrl = $this->api_url . 'api/pay/submit';
        $param  = $this->buildRequestParam($biz_content);

        $html = '<form id="pagePay" action="' . $requrl . '" method="post">';
        foreach ($param as $k => $v) {
            $html .= '<input type="hidden" name="' . $k . '" value=\'' . $v . '\'/>';
        }
        $html .= '<input type="submit" value="' . $button . '"></form><script>document.getElementById("pagePay").submit();</script>';

        return $html;
    }

    /**
     * 发起支付（获取链接）
     * 构建支付跳转链接
     *
     * @param array $param_tmp 业务参数数组
     * @return string 支付跳转链接
     * @throws Exception 当请求构建失败时抛出异常
     */
    public function getPayLink(array $param_tmp): string
    {
        $requrl = $this->api_url . 'api/pay/submit';
        $param  = $this->buildRequestParam($param_tmp);
        return $requrl . '?' . http_build_query($param);
    }

    /**
     * 发起支付（API接口）
     * 通过API方式发起支付
     *
     * @param array $params 业务参数数组
     * @return array API响应结果
     * @throws Exception 当API调用失败时抛出异常
     */
    public function apiPay(array $params): array
    {
        return $this->execute('api/pay/create', $params);
    }

    /**
     * 发起API请求
     * 执行通用的API请求并处理响应
     *
     * @param string $path   API路径
     * @param array  $params 请求参数
     * @return array API响应结果
     * @throws Exception 当请求失败或验签失败时抛出异常
     */
    public function execute(string $path, array $params): array
    {
        $path     = ltrim($path, '/');
        $requrl   = $this->api_url . $path;
        $param    = $this->buildRequestParam($params);
        $response = $this->getHttpResponse($requrl, http_build_query($param));
        $arr      = json_decode($response, true);
        if ($arr && $arr['code'] === 0) {
            if (!$this->verify($arr)) {
                throw new Exception('返回数据验签失败');
            }
            return $arr;
        } else {
            throw new Exception($arr ? $arr['msg'] : '请求失败');
        }
    }

    /**
     * 回调验证
     * 验证回调数据的签名有效性
     *
     * @param array $arr 回调数据数组
     * @return bool 验证结果
     * @throws Exception 当验签过程出现错误时抛出异常
     */
    public function verify(array $arr): bool
    {
        if (empty($arr) || empty($arr['sign'])) return false;

        if (empty($arr['timestamp']) || abs(time() - $arr['timestamp']) > 300) return false;

        $sign = $arr['sign'];

        return $this->rsaPublicVerify($this->getSignContent($arr), $sign);
    }

    /**
     * 构建请求参数
     * 添加必要的系统参数并生成签名
     *
     * @param array $params 业务参数
     * @return array 完整的请求参数
     * @throws Exception 当签名生成失败时抛出异常
     */
    private function buildRequestParam(array $params): array
    {
        $params['pid']       = $this->merchant_id;
        $params['timestamp'] = (string)time();
        $mysign              = $this->getSign($params);
        $params['sign']      = $mysign;
        $params['sign_type'] = 'RSA';
        return $params;
    }

    /**
     * 生成签名
     * 使用商户私钥对参数进行RSA签名
     *
     * @param array $params 待签名参数
     * @return string 签名字符串
     * @throws Exception 当签名生成失败时抛出异常
     */
    private function getSign(array $params): string
    {
        return $this->rsaPrivateSign($this->getSignContent($params));
    }

    /**
     * 获取待签名字符串
     * 按照规范格式化参数为待签名字符串
     *
     * @param array $params 参数数组
     * @return string 待签名字符串
     */
    private function getSignContent(array $params): string
    {
        ksort($params);
        $signstr = '';
        foreach ($params as $k => $v) {
            if (is_array($v) || ($v === null || trim($v) === '') || $k === 'sign' || $k === 'sign_type') continue;
            $signstr .= '&' . $k . '=' . $v;
        }
        return substr($signstr, 1);
    }

    /**
     * 商户私钥签名
     * 使用RSA私钥对数据进行SHA256签名
     *
     * @param string $data 待签名数据
     * @return string Base64编码的签名
     * @throws Exception 当私钥无效或签名失败时抛出异常
     */
    private function rsaPrivateSign(string $data): string
    {
        $key        = "-----BEGIN PRIVATE KEY-----\n" . wordwrap($this->private_key, 64, "\n", true) . "\n-----END PRIVATE KEY-----";
        $privatekey = openssl_get_privatekey($key);
        if (!$privatekey) {
            throw new Exception('签名失败，商户私钥错误');
        }
        openssl_sign($data, $sign, $privatekey, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    /**
     * 平台公钥验签
     * 使用RSA公钥验证数据签名
     *
     * @param string $data 待验证数据
     * @param string $sign 签名字符串
     * @return bool 验证结果
     * @throws Exception 当公钥无效时抛出异常
     */
    private function rsaPublicVerify(string $data, string $sign): bool
    {
        $key       = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($this->public_key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        $publickey = openssl_get_publickey($key);
        if (!$publickey) {
            throw new Exception("验签失败，平台公钥错误");
        }
        return openssl_verify($data, base64_decode($sign), $publickey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 请求外部资源
     * 发送HTTP请求到指定URL
     *
     * @param string       $url  请求URL
     * @param string|false $post POST数据，false表示GET请求
     * @return string|bool 响应内容，失败时返回false
     */
    private function getHttpResponse(string $url, string|false $post = false): string|bool
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $response = curl_exec($ch);
        unset($ch);
        return $response;
    }
}
