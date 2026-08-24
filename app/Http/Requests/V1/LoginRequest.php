<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'password' => ['required', 'string', 'min:4'],
        ];
    }

    /**
     * Normalize the Libyan phone number to canonical international format (+218...).
     */
    public function normalizedPhone(): string
    {
        $phone = preg_replace('/\D+/', '', (string) $this->input('phone')) ?? '';

        if (str_starts_with($phone, '00218')) {
            return '+'.substr($phone, 2);
        }

        if (str_starts_with($phone, '218')) {
            return '+'.$phone;
        }

        if (str_starts_with($phone, '0')) {
            return '+218'.substr($phone, 1);
        }

        if (str_starts_with($phone, '9')) {
            return '+218'.$phone;
        }

        return '+'.$phone;
    }
}
