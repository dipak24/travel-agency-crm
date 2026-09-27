<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Travel document types a booking can ask for. Each package and booking marks every type as
 * required or optional; customers are only asked to upload the required ones.
 */
enum DocumentType: string implements HasLabel
{
    case Passport = 'passport';
    case PpPhoto = 'pp_photo';
    case Visa = 'visa';
    case Insurance = 'insurance';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Passport => 'Passport',
            self::PpPhoto => 'PP size photo',
            self::Visa => 'Visa',
            self::Insurance => 'Insurance',
            self::Other => 'Other documents',
        };
    }

    /**
     * "Other documents" are extras a customer may add; they can never be made required.
     */
    public function isAlwaysOptional(): bool
    {
        return $this === self::Other;
    }

    /**
     * The types staff can mark as required.
     *
     * @return array<int, self>
     */
    public static function requirable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => ! $type->isAlwaysOptional()));
    }

    /**
     * Forces the always-optional types off in a requirement map.
     *
     * @param  array<string, bool>  $requirements
     * @return array<string, bool>
     */
    public static function normalizeRequirements(array $requirements): array
    {
        foreach (self::cases() as $type) {
            if ($type->isAlwaysOptional()) {
                $requirements[$type->value] = false;
            }
        }

        return $requirements;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }

    /**
     * The default requirement map used when a package doesn't define its own.
     *
     * @return array<string, bool>
     */
    public static function defaultRequirements(): array
    {
        return [
            self::Passport->value => true,
            self::PpPhoto->value => true,
            self::Visa->value => false,
            self::Insurance->value => true,
            self::Other->value => false,
        ];
    }
}
