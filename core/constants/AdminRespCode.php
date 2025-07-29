<?php

declare(strict_types = 1);

namespace core\constants;

enum AdminRespCode: int
{
    // 通用成功响应码
    case SUCCESS = 20000;
    // 通用失败响应码
    case FAIL = 40000;
    // 通用错误响应码
    case ERROR = 50000;

    // 业务相关响应码
    case NOT_LOGIN      = 40001; // 未登录
    case NO_PERMISSION  = 40002; // 无权限
    case INVALID_PARAM  = 40003; // 无效参数
    case INVALID_TOKEN  = 40004; // 无效Token
    case TOKEN_EXPIRED  = 40005; // Token已过期
    case NOT_FOUND      = 40006; // 未找到
    case ALREADY_EXISTS = 40007; // 已存在
}
