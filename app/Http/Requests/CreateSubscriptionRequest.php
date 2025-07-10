<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price_id' => 'required|string',
            'payment_method_id' => 'nullable|string',
            'trial_days' => 'nullable|integer|min:0|max:365',
        ];
    }

    public function messages(): array
    {
        return [
            'price_id.required' => 'El plan es requerido',
            'trial_days.integer' => 'Los días de prueba deben ser un número entero',
            'trial_days.max' => 'El período de prueba no puede exceder 365 días',
        ];
    }
}