<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RealmRunsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'region' => ['required', Rule::in((array) config('blizzard.regions'))],
            'realm' => ['required', 'string', 'max:100'],
        ];
    }
}
