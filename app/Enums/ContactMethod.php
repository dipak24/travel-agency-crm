<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContactMethod: string implements HasLabel
{
    case Email = 'email';
    case Phone = 'phone';
    case Whatsapp = 'whatsapp';
    case Sms = 'sms';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Phone => 'Phone call',
            self::Whatsapp => 'WhatsApp',
            self::Sms => 'SMS',
        };
    }
}
