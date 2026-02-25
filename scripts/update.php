<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../support/bootstrap.php';

use support\Db;

// 检查是否在CLI模式下运行
if (PHP_SAPI !== 'cli') {
    return 'Run in CLI mode only.';
}

echo '======开始更新卡卡聚合支付系统======' . PHP_EOL . PHP_EOL;

try {
    // 尝试获取当前版本
    $currentVersionStr = Db::table('config')->where(['g' => 'common', 'k' => 'version'])->value('v');

    if (empty($currentVersionStr)) {
        echo 'Error: 无法获取当前版本，系统可能未完成初始化。' . PHP_EOL;
        return;
    }

    echo "Info: 系统当前版本为 {$currentVersionStr}" . PHP_EOL;

    // 将版本转换成整数方便比较 (格式：ymdHi，如 2602251353)
    $currentVersion = (int)$currentVersionStr;

    // 获取所有需要执行的 SQL 文件
    $sqlDir            = __DIR__ . '/sql';
    $files             = scandir($sqlDir);
    $toBeExecutedFiles = [];

    foreach ($files as $file) {
        // 匹配纯数字命名的版本号SQL，如 2602251353.sql
        if (preg_match('/^(\d+)\.sql$/', $file, $matches)) {
            $fileVersionStr = $matches[1];
            $fileVersion    = (int)$fileVersionStr;

            // 逻辑上通常是执行版本号“大于”当前版本的补丁文件
            // 如果确实需要“小于”，可自行修改此处的判断，但正常更新必定为大于
            if ($fileVersion > $currentVersion) {
                $toBeExecutedFiles[$fileVersionStr] = $file;
            }
        }
    }

    if (empty($toBeExecutedFiles)) {
        echo 'Info: 没有发现可用的更新文件，系统已是最新版本！' . PHP_EOL;
        return;
    }

    // 按照版本号从小到大排序
    ksort($toBeExecutedFiles);

    $latestVersionStr = $currentVersionStr;

    foreach ($toBeExecutedFiles as $versionStr => $file) {
        $sqlPath = $sqlDir . '/' . $file;
        echo "Info: 正在执行更新脚本：{$file} ..." . PHP_EOL;

        $sqlContent = file_get_contents($sqlPath);
        if (!empty(trim($sqlContent))) {
            Db::unprepared($sqlContent);
        }

        $latestVersionStr = $versionStr;
    }

    // 更新版本号到数据库
    Db::table('config')->where(['g' => 'common', 'k' => 'version'])->update(['v' => $latestVersionStr]);

    echo "Success: 系统更新成功！当前系统版本已升级为 {$latestVersionStr}" . PHP_EOL;

} catch (Throwable $e) {
    echo 'Error: 更新失败，捕获到异常：' . $e->getMessage() . PHP_EOL;
    return;
}
