<?php

declare(strict_types=1);

namespace Core\Exception;

use Exception;

/**
 * 签名验证异常类
 */
class SignatureException extends Exception
{
    public function __construct(string $message = '签名验证失败', int $code = 4001, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
