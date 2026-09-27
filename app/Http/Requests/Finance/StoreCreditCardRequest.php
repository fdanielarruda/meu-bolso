<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditCardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'institution_id' => ['required', 'exists:institutions,id'],
            'name' => ['required', 'string', 'max:255'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'closing_day' => ['required', 'integer', 'between:1,31'],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'payment_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'starts_at' => ['nullable', 'date'],
        ];
    }
}
