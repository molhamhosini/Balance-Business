<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('inventory_system', 10)->default('perpetual')->after('allow_negative_stock')->comment('perpetual, periodic');
        });
        // purchase returns account used by the periodic method (5113 in the client chart)
        foreach (DB::table('companies')->pluck('id') as $cid) {
            if (! DB::table('accounts')->where('company_id', $cid)->where('system_key', 'purchase_returns')->exists()) {
                DB::table('accounts')->where('company_id', $cid)->where('code', '5113')->whereNull('system_key')->update(['system_key' => 'purchase_returns']);
            }
        }
    }

    public function down(): void
    {
        DB::table('accounts')->where('system_key', 'purchase_returns')->update(['system_key' => null]);
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('inventory_system'));
    }
};
