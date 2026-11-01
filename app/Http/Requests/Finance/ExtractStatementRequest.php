<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExtractStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'kind' => ['required', Rule::in(['credit', 'debit'])],
            'institution_id' => ['required', Rule::exists('institutions', 'id')],
            'credit_card_id' => [
                'required_if:kind,credit',
                'nullable',
                Rule::exists('credit_cards', 'id')
                    ->where('user_id', $userId)
                    ->where('institution_id', $this->input('institution_id')),
            ],
            'file' => ['required', 'file', 'mimes:pdf', 'max:15360'],
        ];
    }
}
