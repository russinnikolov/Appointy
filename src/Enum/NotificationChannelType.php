<?php

namespace App\Enum;

enum NotificationChannelType: string
{
    case TELEGRAM = 'telegram';
    case WHATSAPP = 'whatsapp';
    case VIBER    = 'viber';
    case SMS      = 'sms';
}
