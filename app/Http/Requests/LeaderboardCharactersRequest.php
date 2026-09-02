<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Ranks\CharacterLeaderboards;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaderboardCharactersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('scope')) {
            $this->merge(['scope' => 'region']);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(CharacterLeaderboards::SCOPES)],
            'region' => ['required_unless:scope,world', 'nullable', Rule::in((array) config('blizzard.regions'))],
            'realm' => ['required_if:scope,realm', 'nullable', 'string', 'max:100'],
            'class_id' => ['required_if:scope,class', 'nullable', 'integer', 'min:1'],
            'spec_id' => ['required_if:scope,spec', 'nullable', 'integer', 'min:1'],
        ];
    }
}
