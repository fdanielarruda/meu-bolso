<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSwapRequest extends FormRequest
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
            'person_a_id' => ['required', 'different:person_b_id', Rule::exists('people', 'id')->where('user_id', $userId)],
            'person_b_id' => ['required', Rule::exists('people', 'id')->where('user_id', $userId)],
            'institution_one_id' => ['nullable', 'different:institution_two_id', 'exists:institutions,id'],
            'institution_two_id' => ['nullable', 'exists:institutions,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_movement_only' => ['nullable', 'boolean'],
        ];
    }
}
