<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlizzardOAuthStateRequest extends FormRequest
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
            'redirectUri' => [
                'required',
                'url',
                Rule::in((array) config('blizzard.oauth.redirect_uris', [])),
            ],
        ];
    }
}
