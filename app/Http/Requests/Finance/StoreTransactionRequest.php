<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
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
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $userId)],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'is_unknown' => ['nullable', 'boolean'],
            'exclude_from_reports' => ['nullable', 'boolean'],
            'description' => [Rule::requiredIf(! $this->boolean('is_unknown')), 'nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
        ];
    }
}
