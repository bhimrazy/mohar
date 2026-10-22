<?php

namespace App\Modules\Kyc\Enums;

/**
 * Identity documents a client can upload (Nepal first).
 */
enum DocumentType: string
{
    case Citizenship = 'citizenship';
    case NationalId = 'national_id';
    case DrivingLicence = 'driving_licence';

    public function label(): string
    {
        return match ($this) {
            self::Citizenship => 'Citizenship certificate',
            self::NationalId => 'National ID (NID)',
            self::DrivingLicence => 'Driving licence',
        };
    }

    /**
     * Whether the document has a back side with details on it, so an image of the back is required.
     */
    public function hasBack(): bool
    {
        return true;
    }
}
