<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * The currencies tenants and platform billing may use. ALL is the master list a Super Admin picks
 * from (admin Platform → System Settings); options() narrows it to the enabled ones.
 */
class Currencies
{
    /**
     * @var array<string, string>
     */
    public const ALL = [
        'USD' => 'US Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'AUD' => 'Australian Dollar',
        'CAD' => 'Canadian Dollar',
        'NZD' => 'New Zealand Dollar',
        'CHF' => 'Swiss Franc',
        'AED' => 'UAE Dirham',
        'SAR' => 'Saudi Riyal',
        'QAR' => 'Qatari Riyal',
        'INR' => 'Indian Rupee',
        'NPR' => 'Nepalese Rupee',
        'BDT' => 'Bangladeshi Taka',
        'LKR' => 'Sri Lankan Rupee',
        'PKR' => 'Pakistani Rupee',
        'SGD' => 'Singapore Dollar',
        'MYR' => 'Malaysian Ringgit',
        'THB' => 'Thai Baht',
        'HKD' => 'Hong Kong Dollar',
        'CNY' => 'Chinese Yuan',
        'JPY' => 'Japanese Yen',
        'KRW' => 'South Korean Won',
        'ZAR' => 'South African Rand',
    ];

    /**
     * Enabled until a Super Admin saves System Settings — the list the app shipped with.
     *
     * @var list<string>
     */
    public const DEFAULT_ENABLED = ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'AED', 'INR', 'NPR', 'JPY'];

    /**
     * Enabled currencies as select options ("USD - US Dollar"). A record's current currency is
     * always kept in the list, so disabling a currency never makes an existing record unsavable.
     *
     * @return array<string, string>
     */
    public static function options(?string $keep = null): array
    {
        $codes = static::enabled();

        if ($keep !== null && $keep !== '' && ! in_array($keep, $codes, true)) {
            $codes[] = $keep;
        }

        $options = [];

        foreach ($codes as $code) {
            $options[$code] = isset(self::ALL[$code]) ? "{$code} - ".self::ALL[$code] : $code;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function enabled(): array
    {
        $codes = PlatformSetting::current()->currencies;

        return is_array($codes) && $codes !== [] ? array_values($codes) : self::DEFAULT_ENABLED;
    }

    public static function defaultCode(): string
    {
        $default = PlatformSetting::current()->default_currency;

        return in_array($default, static::enabled(), true) ? $default : static::enabled()[0];
    }
}
