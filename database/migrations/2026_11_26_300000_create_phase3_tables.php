<?php

use App\Support\Perm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Phase 3: banks & cheques, reconciliation, inventory, fixed assets, cost centers, currencies. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('allow_negative_stock')->default(false)->after('allow_negative_cash');
        });

        // ---- Cost centers (a tree independent of the chart, linked to journal lines) ----
        Schema::create('cost_centers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('cost_centers');
            $t->string('code', 20);
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->foreign('cost_center_id')->references('id')->on('cost_centers');
        });
        Schema::table('vouchers', function (Blueprint $t) {
            $t->foreignId('cost_center_id')->nullable()->after('tax_code_id')->constrained('cost_centers');
        });

        // ---- Currencies ----
        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->char('currency', 3);
            $t->date('date');
            $t->decimal('rate', 18, 6)->comment('AED per 1 unit of the currency');
            $t->timestamps();
            $t->unique(['company_id', 'currency', 'date']);
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('fx_rate', 18, 6)->default(1)->after('currency');
            $t->decimal('base_total', 18, 2)->default(0)->after('total')->comment('total in AED (sum of converted lines)');
        });
        Schema::table('allocations', function (Blueprint $t) {
            $t->foreignId('journal_entry_id')->nullable()->after('credit_invoice_id')->comment('write-off of a small / FX difference')->constrained('journal_entries');
        });

        // ---- Cheques on vouchers; cash/bank transfers ----
        Schema::table('vouchers', function (Blueprint $t) {
            $t->string('cheque_bank')->nullable()->after('cheque_due_date');
            $t->string('cheque_status', 12)->nullable()->after('cheque_bank')->comment('in_box, deposited, cleared, returned');
            $t->foreignId('cheque_entry_id')->nullable()->after('cheque_status')->constrained('journal_entries')->comment('deposit / clearing / return entry');
        });
        Schema::create('transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained();
            $t->string('number', 30)->nullable();
            $t->date('date');
            $t->foreignId('from_account_id')->constrained('accounts');
            $t->foreignId('to_account_id')->constrained('accounts');
            $t->decimal('amount', 18, 2);
            $t->string('reference')->nullable();
            $t->string('description')->nullable();
            $t->string('status', 12)->default('draft');
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('posted_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'number']);
        });

        // ---- Bank reconciliation ----
        Schema::create('bank_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('accounts');
            $t->date('date_from');
            $t->date('date_to');
            $t->decimal('opening_balance', 18, 2)->default(0);
            $t->decimal('closing_balance', 18, 2)->default(0);
            $t->string('file_name')->nullable();
            $t->string('status', 12)->default('open')->comment('open, reconciled');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('description')->nullable();
            $t->string('reference')->nullable();
            $t->decimal('amount', 18, 2)->comment('+ deposit, - withdrawal');
            $t->foreignId('journal_line_id')->nullable()->unique()->constrained('journal_lines');
            $t->timestamps();
        });

        // ---- Inventory ----
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained();
            $t->string('code', 20);
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('barcode', 60)->nullable();
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('type', 10)->default('stock')->comment('stock, service');
            $t->string('unit', 20)->default('كغ')->comment('base unit');
            $t->decimal('sale_price', 18, 4)->default(0);
            $t->foreignId('tax_code_id')->nullable()->constrained('tax_codes');
            $t->foreignId('inventory_account_id')->nullable()->constrained('accounts');
            $t->foreignId('cogs_account_id')->nullable()->constrained('accounts');
            $t->foreignId('sales_account_id')->nullable()->constrained('accounts');
            $t->foreignId('purchase_account_id')->nullable()->constrained('accounts')->comment('for service items');
            $t->decimal('qty_on_hand', 18, 3)->default(0)->comment('cache: sum of moves');
            $t->decimal('avg_cost', 18, 6)->default(0)->comment('weighted average cost per base unit');
            $t->decimal('stock_value', 18, 2)->default(0)->comment('cache: sum of move values');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('item_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->string('name', 20);
            $t->decimal('factor', 18, 6)->comment('base units in one of this unit');
            $t->string('barcode', 60)->nullable();
            $t->decimal('sale_price', 18, 4)->nullable();
        });
        Schema::create('stock_moves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained();
            $t->foreignId('warehouse_id')->constrained();
            $t->date('date');
            $t->decimal('quantity', 18, 3)->comment('base units, + in / - out');
            $t->decimal('unit_cost', 18, 6);
            $t->decimal('value', 18, 2)->comment('+ in / - out');
            $t->decimal('qty_after', 18, 3)->comment('company-wide quantity after this move');
            $t->decimal('avg_cost_after', 18, 6);
            $t->nullableMorphs('source');
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $t->string('description')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'item_id', 'date']);
        });
        Schema::create('stock_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained();
            $t->string('type', 16)->comment('opening, receipt, issue, transfer, count, landed_cost');
            $t->string('number', 30)->nullable();
            $t->date('date');
            $t->foreignId('warehouse_id')->nullable()->constrained();
            $t->foreignId('to_warehouse_id')->nullable()->constrained('warehouses');
            $t->foreignId('counter_account_id')->nullable()->constrained('accounts')->comment('opening: equity; issue: expense; landed cost: payable');
            $t->decimal('amount', 18, 2)->nullable()->comment('landed cost amount');
            $t->string('allocation', 10)->nullable()->comment('landed cost: value, quantity');
            $t->string('description')->nullable();
            $t->string('status', 12)->default('draft');
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('posted_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'number']);
        });
        Schema::create('stock_document_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->nullable()->constrained();
            $t->decimal('quantity', 18, 3)->default(0)->comment('count: counted quantity');
            $t->decimal('unit_cost', 18, 6)->nullable();
            $t->decimal('system_quantity', 18, 3)->nullable()->comment('count: book quantity at posting');
            $t->foreignId('invoice_id')->nullable()->constrained()->comment('landed cost: the bill being charged');
            $t->unsignedInteger('sort')->default(0);
        });
        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->foreignId('item_id')->nullable()->after('invoice_id')->constrained();
            $t->foreignId('warehouse_id')->nullable()->after('item_id')->constrained();
            $t->decimal('unit_factor', 18, 6)->default(1)->after('unit');
            $t->foreignId('cost_center_id')->nullable()->after('tax_code_id')->constrained('cost_centers');
            $t->decimal('cost', 18, 2)->nullable()->after('total')->comment('cost of goods sold for this line');
        });

        // ---- Fixed assets ----
        Schema::create('asset_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->unsignedInteger('useful_life_months')->default(60);
            $t->foreignId('asset_account_id')->constrained('accounts');
            $t->foreignId('accumulated_account_id')->constrained('accounts');
            $t->foreignId('expense_account_id')->constrained('accounts');
            $t->timestamps();
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('asset_category_id')->constrained();
            $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $t->string('code', 30);
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->date('purchase_date');
            $t->date('depreciation_start');
            $t->decimal('cost', 18, 2);
            $t->decimal('salvage_value', 18, 2)->default(0);
            $t->unsignedInteger('useful_life_months');
            $t->decimal('opening_accumulated', 18, 2)->default(0)->comment('depreciation before go-live');
            $t->string('location')->nullable();
            $t->string('serial')->nullable();
            $t->string('status', 12)->default('running')->comment('running, fully_depreciated, disposed');
            $t->date('disposal_date')->nullable();
            $t->decimal('disposal_proceeds', 18, 2)->nullable();
            $t->foreignId('disposal_entry_id')->nullable()->constrained('journal_entries');
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('depreciation_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('period_end');
            $t->decimal('total', 18, 2);
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'period_end']);
        });
        Schema::create('asset_depreciations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->foreignId('depreciation_run_id')->constrained()->cascadeOnDelete();
            $t->date('period_end');
            $t->decimal('amount', 18, 2);
            $t->unique(['asset_id', 'period_end']);
        });

        foreach ([
            Perm::BANKING_MANAGE => ['accountant', 'chief_accountant'],
            Perm::INVENTORY_VIEW => ['data_entry', 'accountant', 'chief_accountant', 'management'],
            Perm::INVENTORY_MANAGE => ['data_entry', 'accountant', 'chief_accountant'],
            Perm::INVENTORY_POST => ['accountant', 'chief_accountant'],
            Perm::ASSETS_MANAGE => ['accountant', 'chief_accountant'],
            Perm::ASSETS_DEPRECIATE => ['chief_accountant'],
        ] as $perm => $roles) {
            Permission::findOrCreate($perm, 'web');
            foreach ($roles as $r) {
                Role::where('name', $r)->first()?->givePermissionTo($perm);
            }
        }
        app(\App\Services\Accounting\CompanySetup::class)->upgradeAll();
    }

    public function down(): void
    {
        foreach (['asset_depreciations', 'depreciation_runs', 'assets', 'asset_categories'] as $tb) {
            Schema::dropIfExists($tb);
        }
        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('item_id');
            $t->dropConstrainedForeignId('warehouse_id');
            $t->dropConstrainedForeignId('cost_center_id');
            $t->dropColumn(['unit_factor', 'cost']);
        });
        foreach (['stock_document_lines', 'stock_documents', 'stock_moves', 'item_units', 'items', 'warehouses', 'bank_statement_lines', 'bank_statements', 'transfers'] as $tb) {
            Schema::dropIfExists($tb);
        }
        Schema::table('vouchers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cheque_entry_id');
            $t->dropColumn(['cheque_bank', 'cheque_status']);
            $t->dropConstrainedForeignId('cost_center_id');
        });
        Schema::table('allocations', fn (Blueprint $t) => $t->dropConstrainedForeignId('journal_entry_id'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['fx_rate', 'base_total']));
        Schema::dropIfExists('exchange_rates');
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropForeign(['cost_center_id']));
        Schema::dropIfExists('cost_centers');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('allow_negative_stock'));
    }
};
