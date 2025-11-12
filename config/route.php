<?php

declare(strict_types = 1);

use Webman\Route;

Route::any('/.well-known/appspecific/com.chrome.devtools.json', function () {
    return new \support\Response(404);
});

// 收银台页面
Route::get('/checkout/{orderNo}.html', [\app\api\controller\CheckoutController::class, 'index']);

// 网关扩展方法路由 - 支持 /pay/[方法名]/订单号/ 格式
Route::any('/pay/{method}/{orderNo}.html', [\app\api\v1\controller\PayController::class, 'handleExtensionMethod']);

// 管理后台路由
Route::group('/admin', function () {
    // 管理员相关路由
});
