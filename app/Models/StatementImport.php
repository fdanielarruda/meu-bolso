<?php

namespace App\Models;

use App\Enums\StatementImportStatus;
use App\Support\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'kind', 'institution_id', 'credit_card_id', 'reference_month', 'account_id', 'original_filename', 'file_path',
    'status', 'items', 'error_message', 'extracted_at', 'imported_at',
])]
class StatementImport extends Model
{
    use BelongsToUser, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => StatementImportStatus::class,
            'items' => 'array',
            'reference_month' => 'date',
            'extracted_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
