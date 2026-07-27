<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Enums;

enum VerifyFailure: string
{
    case InvalidCode     = 'invalid_code';
    case Expired         = 'expired';
    case AlreadyUsed     = 'already_used';
    case TooManyAttempts = 'too_many_attempts';
    case NotFound        = 'not_found';
}
