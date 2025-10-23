<?php

declare(strict_types=1);

namespace Core\Exception;

use Exception;

/**
 * 支付异常类
 */
class PaymentException extends Exception
{
    public function __construct(string $message = '支付处理失败', int $code = 5001, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
