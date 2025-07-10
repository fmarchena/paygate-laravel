<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_price_id' => 'required|string',
            'prorate' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'new_price_id.required' => 'El nuevo plan es requerido',
        ];
    }
}