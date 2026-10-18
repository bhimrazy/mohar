<?php

namespace App\Modules\Services\Http\Requests;

use App\Modules\Services\Enums\CancellationType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancellationRequestForm extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CancellationType::class)],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function type(): CancellationType
    {
        return CancellationType::from($this->string('type')->toString());
    }
}
