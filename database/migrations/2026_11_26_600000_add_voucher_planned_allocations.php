<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invoices a draft receipt/payment voucher will settle when it is posted: [{invoice_id, amount}]. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', fn (Blueprint $t) => $t->json('planned_allocations')->nullable()->after('pays_invoice_id'));
    }

    public function down(): void
    {
        Schema::table('vouchers', fn (Blueprint $t) => $t->dropColumn('planned_allocations'));
    }
};
