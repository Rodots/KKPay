<?php

declare(strict_types = 1);

namespace Core\constants;

enum AdminRespCode: int
{
    // 通用成功响应码
    case SUCCESS = 20000;
    // 通用失败响应码
    case FAIL = 40000;
    // 通用错误响应码
    case ERROR = 50000;

    // 业务相关响应码
    case INVALID_TOKEN  = 40001; // 无效Token
    case NOT_FOUND      = 40002; // 未找到
    case ALREADY_EXISTS = 40003; // 已存在
}
