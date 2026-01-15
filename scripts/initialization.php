<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../support/bootstrap.php';

use support\Db;

echo '======欢迎使用卡卡聚合支付系统======' . PHP_EOL . PHP_EOL;
echo 'Info: 开始初始化...' . PHP_EOL;
Db::beginTransaction();
try {
    if (DB::table('admin')->where('id', 1)->exists()) {
        Db::rollBack();
        echo 'Warning: 您已初始化过了！' . PHP_EOL . PHP_EOL;
        echo '============祝您使用愉快============' . PHP_EOL;
        return 'Warning: 您已初始化过了！';
    }

    $login_salt    = random(4);
    $fund_salt     = random(4);
    $admin_account = random(6, 'lower');
    Db::table('admin')->insert([
        'role'           => 0,
        'account'        => $admin_account,
        'nickname'       => '超级管理员',
        'status'         => true,
        'login_salt'     => $login_salt,
        'login_password' => password_hash('login' . $login_salt . '4c2b6eecc66547d595102682557afd52kkpay', PASSWORD_BCRYPT), // 123456
        'fund_salt'      => $fund_salt,
        'fund_password'  => password_hash('fund' . $fund_salt . '4c2b6eecc66547d595102682557afd52kkpay', PASSWORD_BCRYPT), // 123456
    ]);

    $api_crypto_key  = random(32);
    $totp_crypto_key = random(32);

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
    $public_key_base64  = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"], '', $details['key']);
    $private_key_base64 = str_replace(['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\n", "\r"], '', $private_key);

    // 新建kkpay.php文件
    $kkpay_content = <<<EOF
<?php

declare(strict_types=1);

/***
 * 配置项介绍
 * jwt_expire_time: JWT过期时间（后台登录过期时间）[秒]
 * api_crypto_key: 后台敏感接口传输密钥（32位字符串）
 * totp_crypto_key: TOTP存储加密密钥（32位字符串）
 * payment_rsa2_public_key: 【关键】平台商户对接使用RSA2算法验签时需用到的公钥（可使用支付宝开发平台密钥工具生成）
 * payment_rsa2_private_key: 【关键】平台商户对接使用RSA2算法加签时需用到的私钥（可使用支付宝开发平台密钥工具生成）
 */

return [
    'jwt_expire_time'          => 3600,
    'api_crypto_key'           => '$api_crypto_key',
    'totp_crypto_key'          => '$totp_crypto_key',
    'payment_rsa2_public_key'  => '$public_key_base64',
    'payment_rsa2_private_key' => '$private_key_base64'
];

EOF;
    file_put_contents(config_path('/kkpay.php'), $kkpay_content);

    Db::commit();
} catch (PDOException $e) {
    Db::rollBack();

    echo 'Error: 初始化失败（请检查是不是没提前导入数据表结构）' . $e->getMessage();
    return 'Error: 初始化失败' . $e->getMessage();
} catch (Throwable $e) {
    Db::rollBack();

    echo 'Error: 初始化失败' . $e->getMessage();
    return 'Error: 初始化失败' . $e->getMessage();
}
echo <<<EOF
Success: 初始化成功！

已为您开通超级管理员账户，系统内只允许一个超级管理员存在，请登录系统后台后第一时间修改账号密码等信息，不要告诉别人哦！
------------------------
后台默认登录账号：$admin_account
后台默认登录密码：123456
------------------------

请将该密钥配置提供给前端技术人员到前端源码中编译！
------------------------
后台敏感接口传输密钥：$api_crypto_key

------------------------

============祝您使用愉快============
EOF;

return 'Success: 初始化成功！';
