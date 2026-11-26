<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $t) {
            $t->foreignId('tax_code_id')->nullable()->after('trn')->comment('default VAT treatment on invoice lines (5% / 0% / exempt ...)')->constrained('tax_codes')->nullOnDelete();
        });
        Schema::table('companies', function (Blueprint $t) {
            $t->text('invoice_footer')->nullable()->after('logo_path')->comment('printed at the bottom of invoices: bank details, terms');
        });
    }

    public function down(): void
    {
        Schema::table('parties', fn (Blueprint $t) => $t->dropConstrainedForeignId('tax_code_id'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('invoice_footer'));
    }
};
