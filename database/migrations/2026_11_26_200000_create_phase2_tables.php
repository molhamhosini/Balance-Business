<?php

use App\Support\Perm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2: customers & suppliers (sub-ledger), sales/purchase invoices and their
 * returns, payment allocation, e-invoicing status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10)->comment('customer | supplier');
            $t->string('code', 20);
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('trn', 15)->nullable();
            $t->string('address')->nullable();
            $t->string('emirate', 3)->nullable();
            $t->string('country', 2)->default('AE');
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->decimal('credit_limit', 18, 2)->default(0)->comment('0 = no limit');
            $t->string('credit_limit_action', 5)->default('warn')->comment('warn | block');
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            $t->char('currency', 3)->default('AED');
            $t->foreignId('account_id')->constrained('accounts')->comment('Control account (1131 / 2111); the party is a sub-ledger on it');
            $t->boolean('is_related_party')->default(false);
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'type', 'code']);
        });

        Schema::table('journal_lines', function (Blueprint $t) {
            $t->foreignId('party_id')->nullable()->after('account_id')->constrained('parties');
        });

        Schema::table('vouchers', function (Blueprint $t) {
            $t->foreignId('party_id')->nullable()->after('counter_account_id')->constrained('parties');
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained();
            $t->string('kind', 16)->comment('sale, sale_return, purchase, purchase_return');
            $t->string('payment', 6)->default('credit')->comment('credit | cash');
            $t->foreignId('party_id')->constrained('parties');
            $t->foreignId('cash_account_id')->nullable()->constrained('accounts');
            $t->string('number', 30)->nullable();
            $t->string('supplier_invoice_number', 60)->nullable();
            $t->date('date');
            $t->date('due_date')->nullable();
            $t->foreignId('original_invoice_id')->nullable()->constrained('invoices');
            $t->string('description')->nullable();
            $t->char('currency', 3)->default('AED');
            $t->decimal('subtotal', 18, 2)->default(0);
            $t->decimal('tax_total', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->string('status', 10)->default('draft'); // draft | posted | cancelled
            $t->foreignId('journal_entry_id')->nullable()->constrained();
            $t->uuid('uuid')->unique();
            $t->string('einvoice_status', 10)->default('not_sent')->comment('not_sent, sent, accepted, rejected');
            $t->string('einvoice_reference')->nullable();
            $t->string('einvoice_reason')->nullable();
            $t->timestamp('einvoice_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('posted_by')->nullable()->constrained('users');
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'number']);
            $t->unique(['company_id', 'party_id', 'kind', 'supplier_invoice_number'], 'invoices_supplier_ref_unique');
            $t->index(['company_id', 'kind', 'status', 'date']);
        });

        Schema::table('vouchers', function (Blueprint $t) {
            $t->foreignId('pays_invoice_id')->nullable()->after('party_id')->comment('allocated to this invoice on posting')->constrained('invoices')->nullOnDelete();
        });

        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('accounts');
            $t->string('description');
            $t->decimal('quantity', 18, 3)->default(1);
            $t->string('unit', 20)->nullable();
            $t->decimal('unit_price', 18, 4)->default(0);
            $t->foreignId('tax_code_id')->nullable()->constrained();
            $t->decimal('net', 18, 2)->default(0);
            $t->decimal('tax', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->comment('The invoice being settled');
            $t->foreignId('voucher_id')->nullable()->constrained()->comment('Settled by a payment');
            $t->foreignId('credit_invoice_id')->nullable()->constrained('invoices')->comment('Or by a credit / debit note');
            $t->decimal('amount', 18, 2);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });

        // New permissions, added to the default roles (existing role edits are kept).
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $grant = [
            Perm::PARTIES_MANAGE => ['data_entry', 'accountant', 'chief_accountant'],
            Perm::INVOICES_VIEW => ['data_entry', 'accountant', 'chief_accountant', 'management'],
            Perm::INVOICES_DRAFT => ['data_entry', 'accountant', 'chief_accountant'],
            Perm::INVOICES_POST => ['accountant', 'chief_accountant'],
            Perm::INVOICES_CANCEL => ['chief_accountant'],
            Perm::CREDIT_LIMIT_OVERRIDE => ['chief_accountant'],
            Perm::EINVOICE_MANAGE => ['accountant', 'chief_accountant'],
        ];
        foreach ($grant as $perm => $roles) {
            Permission::findOrCreate($perm, 'web');
            foreach ($roles as $r) {
                Role::where('name', $r)->first()?->givePermissionTo($perm);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
        Schema::table('vouchers', fn (Blueprint $t) => $t->dropConstrainedForeignId('pays_invoice_id'));
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::table('vouchers', fn (Blueprint $t) => $t->dropConstrainedForeignId('party_id'));
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('party_id'));
        Schema::dropIfExists('parties');
    }
};
