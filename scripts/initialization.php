<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../support/bootstrap.php';

use support\Db;

// 检查是否在CLI模式下运行
if (PHP_SAPI !== 'cli') {
    return 'Run in CLI mode only.';
}

// 解析命令行参数
// --reinstall: 重装模式 (可选)
// --admin: 自定义管理员账号 (可选, 默认为 admin)
$opts         = getopt('', ['reinstall', 'admin::']);
$isReinstall  = isset($opts['reinstall']);
$adminAccount = !empty($opts['admin']) ? $opts['admin'] : 'admin';

echo '======欢迎使用卡卡聚合支付系统======' . PHP_EOL . PHP_EOL;
echo 'Info: 开始初始化...' . PHP_EOL;

try {
    // 如果是重装模式，先导入数据库
    if ($isReinstall) {
        echo 'Info: 检测到重装参数，正在导入数据库结构...' . PHP_EOL;
        $sqlFile = __DIR__ . '/sql/install.sql';

        if (!file_exists($sqlFile)) {
            echo 'Error: SQL文件不存在: ' . $sqlFile . PHP_EOL;
            return;
        }

        // 读取并执行SQL文件
        // 使用 unprepared 执行原始SQL文件内容
        $sqlContent = file_get_contents($sqlFile);
        Db::unprepared($sqlContent);

        echo 'Info: 数据库导入成功！' . PHP_EOL;
    } else {
        // 如果不是重装，检查是否已安装
        // 尝试检查 admin 表是否存在以及是否有数据
        try {
            if (Db::table('admin')->exists() && Db::table('admin')->where('id', 1)->exists()) {
                echo 'Warning: 您已初始化过了！' . PHP_EOL . PHP_EOL;
                echo '============祝您使用愉快============' . PHP_EOL;
                return 'Warning: 您已初始化过了！';
            }
        } catch (Throwable $e) {
            // 如果表不存在，且未指定重装，提示用户
            if (str_contains($e->getMessage(), 'exist')) {
                echo 'Error: 数据库表尚未安装。请使用 --reinstall 参数进行首次安装。' . PHP_EOL;
                return 'Error: Database not installed.';
            }
            throw $e;
        }
    }

    Db::beginTransaction();

    // 再次确认是否已存在管理员 (防止重装模式下的并发或其他异常，虽然重装会清空)
    // 如果是重装，这里肯定是空的。如果是非重装且通过了上面的检查，这里也是空的(或者表刚建好)
    if (Db::table('admin')->where('id', 1)->exists()) {
        // 理论上不会走到这里，除非并发
        Db::rollBack();
        echo 'Warning: 初始化已被抢占！' . PHP_EOL;
        return;
    }

    $login_salt             = random(4);
    $fund_salt              = random(4);
    $default_login_password = random(6, 'lower');

    // 插入管理员账号
    Db::table('admin')->insert([
        'id'             => 1, // 显式指定ID为1
        'role'           => 0,
        'account'        => $adminAccount,
        'nickname'       => '超级管理员',
        'status'         => true,
        'login_salt'     => $login_salt,
        'login_password' => password_hash('login' . $login_salt . hash('xxh128', $default_login_password) . 'kkpay', PASSWORD_BCRYPT),
        'fund_salt'      => $fund_salt,
        'fund_password'  => password_hash('fund' . $fund_salt . '4c2b6eecc66547d595102682557afd52kkpay', PASSWORD_BCRYPT), // Default fund pass
    ]);

    $api_crypto_key  = random(32);
    $totp_crypto_key = random(32);

    // 生成 2048 位 RSA 密钥对
    $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

    // Windows 环境下确保 OpenSSL 能找到配置文件
    if (PHP_OS_FAMILY === 'Windows') {
        $opensslConf = config_path('openssl.cnf');
        if (file_exists($opensslConf)) {
            $config['config'] = $opensslConf;
        }
    }

    $res = openssl_pkey_new($config);
    if ($res === false) {
        throw new Exception('生成密钥对失败: ' . openssl_error_string());
    }

    // 导出私钥
    if (!openssl_pkey_export($res, $private_key, null, $config)) {
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
    echo 'Error: 初始化失败（数据库错误）' . $e->getMessage() . PHP_EOL;
    return 'Error: 初始化失败' . $e->getMessage();
} catch (Throwable $e) {
    Db::rollBack();
    echo 'Error: 初始化失败' . $e->getMessage() . PHP_EOL;
    return 'Error: 初始化失败' . $e->getMessage();
}

echo <<<EOF
Success: 初始化成功！

已为您开通超级管理员账户，系统内只允许一个超级管理员存在，请登录系统后台后第一时间修改账号密码等信息，不要告诉别人哦！
------------------------
后台默认登录账号：$adminAccount
后台默认登录密码：$default_login_password
------------------------

请将该密钥配置提供给前端技术人员到前端源码中编译！
------------------------
后台敏感接口传输密钥：$api_crypto_key

------------------------

============祝您使用愉快============

EOF;

return 'Success: 初始化成功！';
