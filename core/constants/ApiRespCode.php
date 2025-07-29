<?php

declare(strict_types = 1);

namespace core\constants;

enum ApiRespCode: int
{
    case SUCCESS = 20000;
    case FAIL    = 40000;
    case ERROR   = 50000;
}
