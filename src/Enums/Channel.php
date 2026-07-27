<?php

declare(strict_types=1);

namespace MyOtpWay\Laravel\Enums;

enum Channel: string
{
    case Whatsapp = 'whatsapp';
    case Sms      = 'sms';
}
