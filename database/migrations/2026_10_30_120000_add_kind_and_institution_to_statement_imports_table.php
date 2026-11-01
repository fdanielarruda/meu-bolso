<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_imports', function (Blueprint $table) {
            $table->string('kind')->default('credit')->after('user_id');
            $table->foreignId('institution_id')->nullable()->after('kind')->constrained()->nullOnDelete();
            $table->foreignId('credit_card_id')->nullable()->after('institution_id')->constrained()->nullOnDelete();
            $table->date('reference_month')->nullable()->after('credit_card_id');
            $table->foreignId('account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('statement_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_card_id');
            $table->dropConstrainedForeignId('institution_id');
            $table->dropColumn(['kind', 'reference_month']);
        });
    }
};
