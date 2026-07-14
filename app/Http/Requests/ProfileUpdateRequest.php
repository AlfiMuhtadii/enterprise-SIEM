<?php

namespace App\Http\Requests;

use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],
            // I18N-LOCALIZATION: nullable -- "no preference set" (falls
            // through SetUserLocale's session/default resolution) is valid.
            'locale' => ['nullable', Rule::in(SetUserLocale::SUPPORTED_LOCALES)],
        ];
    }
}
