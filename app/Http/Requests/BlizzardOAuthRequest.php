<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlizzardOAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'redirectUri' => [
                'required',
                'url',
                Rule::in((array) config('blizzard.oauth.redirect_uris', [])),
            ],
            'state' => ['required', 'string', 'min:32', 'max:128'],
        ];
    }
}
