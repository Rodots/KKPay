<?php

declare(strict_types=1);

namespace app\model;

use Exception;
use support\Model;

/**
 * 商户密钥表
 */
class MerchantEncryption extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_encryption';

    /**
     * 与表关联的主键。
     *
     * @var string
     */
    protected $primaryKey = 'merchant_id';

    /**
     * 指示模型是否应被时间戳。
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = ['mode', 'aes_key', 'hash_key', 'rsa2_key'];

    // 签名算法类型常量
    const string SIGN_TYPE_SHA256withRSA = 'rsa2';
    const string SIGN_TYPE_SHA3_256      = 'sha3';
    const string SIGN_TYPE_SM3           = 'sm3';
    const string SIGN_TYPE_XXH128        = 'xxh';

    // 对接模式常量
    const string MODE_OPEN      = 'open';       // 不限制签名模式
    const string MODE_ONLY_XXH  = 'only_xxh';   // 仅 XXH128
    const string MODE_ONLY_SHA3 = 'only_sha3';  // 仅 SHA3-256
    const string MODE_ONLY_SM3  = 'only_sm3';   // 仅 SM3
    const string MODE_ONLY_RSA2 = 'only_rsa2';  // 仅 RSA2

    /**
     * 支持的签名算法
     */
    public const array SUPPORTED_SIGN_TYPES = [
        self::SIGN_TYPE_XXH128,
        self::SIGN_TYPE_SHA3_256,
        self::SIGN_TYPE_SM3,
        self::SIGN_TYPE_SHA256withRSA
    ];

    /**
     * 支持的对接模式
     */
    public const array SUPPORTED_MODES = [
        self::MODE_OPEN,
        self::MODE_ONLY_XXH,
        self::MODE_ONLY_SHA3,
        self::MODE_ONLY_SM3,
        self::MODE_ONLY_RSA2
    ];

    /**
     * 对接模式文本映射
     */
    public const array MODE_TEXT_MAP = [
        self::MODE_OPEN      => '不限制',
        self::MODE_ONLY_XXH  => '仅 XXH128',
        self::MODE_ONLY_SHA3 => '仅 SHA3-256',
        self::MODE_ONLY_SM3  => '仅 SM3',
        self::MODE_ONLY_RSA2 => '仅 RSA2'
    ];

    /**
     * 生成 RSA2 密钥对并保存公钥
     *
     * @param int $merchant_id 商户ID
     * @return array ['private_key' => PEM私钥, 'public_key' => PEM公钥]
     * @throws Exception 生成失败时抛出异常
     */
    public static function generateRsa2KeyPair(int $merchant_id): array
    {
        // 生成 2048 位 RSA 密钥对
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        // Windows 环境下确保 OpenSSL 能找到配置文件
        if (PHP_OS_FAMILY === 'Windows') {
            $config['config'] = config_path('openssl.cnf');
        }

        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new Exception('生成密钥对失败: ' . openssl_error_string());
        }

        // 导出私钥
        if (!openssl_pkey_export($res, $private_key)) {
            throw new Exception('导出私钥失败: ' . openssl_error_string());
        }

        // 获取公钥
        $details = openssl_pkey_get_details($res);
        if ($details === false) {
            throw new Exception('获取公钥失败: ' . openssl_error_string());
        }

        // 保存公钥到数据库（仅保存 Base64 内容部分，去除 PEM 头尾）
        $public_key_base64 = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"], '', $details['key']);
        self::where('merchant_id', $merchant_id)->update(['rsa2_key' => $public_key_base64]);

        // 返回私钥（仅返回 Base64 内容部分，去除 PEM 头尾）
        return ['private_key' => str_replace(['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\n", "\r"], '', $private_key)];
    }
}
