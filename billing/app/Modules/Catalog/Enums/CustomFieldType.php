<?php

namespace App\Modules\Catalog\Enums;

enum CustomFieldType: string
{
    case Text = 'text';
    case Link = 'link';
    case Password = 'password';
    case Dropdown = 'dropdown';
    case Checkbox = 'checkbox';
    case Textarea = 'textarea';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Link => 'Link',
            self::Password => 'Password',
            self::Dropdown => 'Dropdown',
            self::Checkbox => 'Checkbox',
            self::Textarea => 'Text area',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
