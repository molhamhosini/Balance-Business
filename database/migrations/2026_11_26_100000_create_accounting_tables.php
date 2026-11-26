<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Core accounting schema (Phase 1 + VAT foundations).
 * All money columns are DECIMAL(18,2) — never FLOAT (requirements §6.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('slug')->unique();
            $t->string('trn', 15)->nullable()->comment('UAE Tax Registration Number');
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('logo_path')->nullable();
            $t->char('base_currency', 3)->default('AED');
            $t->boolean('segregation_of_duties')->default(true)->comment('Creator cannot post own document');
            $t->decimal('voucher_approval_limit', 18, 2)->default(10000)->comment('Payment vouchers above this need chief accountant');
            $t->boolean('allow_negative_cash')->default(false);
            $t->timestamps();
        });

        Schema::create('company_user', function (Blueprint $t) {
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['company_id', 'user_id']);
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('emirate', 3)->comment('AUH,DXB,SHJ,AJM,UAQ,RAK,FUJ — drives VAT 201 boxes 1.1-1.7');
            $t->string('activity')->nullable()->comment('slaughterhouse / restaurant / other');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });

        Schema::create('branch_user', function (Blueprint $t) {
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['branch_id', 'user_id']);
        });

        Schema::create('fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 10)->default('open'); // open | closed
            $t->unsignedBigInteger('closing_entry_id')->nullable();
            $t->timestamps();
        });

        Schema::create('fiscal_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('number');
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 10)->default('open'); // open | closed
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['fiscal_year_id', 'number']);
            $t->index(['company_id', 'start_date', 'end_date']);
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->unsignedTinyInteger('level');
            $t->string('class', 12)->comment('asset, liability, equity, revenue, cogs, expense');
            $t->string('nature', 6)->comment('debit | credit');
            $t->string('statement', 16)->comment('balance_sheet | income_statement');
            $t->string('statement_line')->nullable()->comment('Line it rolls into on the financial statements');
            $t->boolean('is_postable')->default(true)->comment('Only leaf accounts accept entries');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_cash')->default(false)->comment('Cash box: may not go negative');
            $t->boolean('is_bank')->default(false);
            $t->boolean('is_non_deductible')->default(false)->comment('Corporate tax: not deductible (hospitality, fines)');
            $t->boolean('is_related_party')->default(false);
            $t->string('system_key', 40)->nullable()->comment('Bound in settings: vat_output, vat_input, retained_earnings ...');
            $t->string('source', 12)->default('user')->comment('excel | activity | user');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->unique(['company_id', 'system_key']);
        });

        Schema::create('tax_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 10);
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('type', 16)->comment('standard, zero, exempt, out_of_scope, reverse_charge');
            $t->decimal('rate', 5, 2)->default(0);
            $t->foreignId('output_account_id')->nullable()->constrained('accounts');
            $t->foreignId('input_account_id')->nullable()->constrained('accounts');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('doc_type', 10);
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_number')->default(0);
            $t->unique(['company_id', 'doc_type', 'year']);
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('fiscal_period_id')->nullable()->constrained();
            $t->string('number', 30)->nullable()->comment('Assigned at posting: gapless per type/year');
            $t->string('type', 12)->default('manual')->comment('manual, voucher, reversal, opening, closing');
            $t->date('date')->comment('Accounting date chosen by user');
            $t->string('description')->nullable();
            $t->string('reference')->nullable();
            $t->string('status', 10)->default('draft'); // draft | posted | reversed
            $t->nullableMorphs('source');
            $t->foreignId('reverses_id')->nullable()->constrained('journal_entries');
            $t->foreignId('reversed_by_id')->nullable()->constrained('journal_entries');
            $t->decimal('total', 18, 2)->default(0);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('posted_by')->nullable()->constrained('users');
            $t->timestamp('posted_at')->nullable();
            $t->timestamps(); // created_at = system entry date
            $t->unique(['company_id', 'number']);
            $t->index(['company_id', 'status', 'date']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained();
            $t->decimal('debit', 18, 2)->default(0);
            $t->decimal('credit', 18, 2)->default(0);
            $t->string('description')->nullable();
            $t->foreignId('tax_code_id')->nullable()->constrained();
            $t->boolean('is_tax_line')->default(false)->comment('The VAT line itself');
            $t->string('tax_direction', 8)->nullable()->comment('sale | purchase — fixed at creation, kept on reversal');
            $t->decimal('tax_base', 18, 2)->nullable()->comment('On VAT lines: the taxable amount');
            $t->char('currency', 3)->default('AED');
            $t->decimal('fx_rate', 18, 6)->default(1);
            $t->decimal('amount_fc', 18, 2)->nullable();
            $t->unsignedBigInteger('cost_center_id')->nullable()->comment('Phase 3');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->index('account_id');
        });

        Schema::create('vouchers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained();
            $t->string('type', 10)->comment('receipt | payment');
            $t->string('number', 30)->nullable();
            $t->date('date');
            $t->foreignId('cash_account_id')->constrained('accounts')->comment('Cash box or bank');
            $t->foreignId('counter_account_id')->constrained('accounts')->comment('Customer, supplier, revenue or expense');
            $t->string('party_name')->nullable();
            $t->decimal('amount', 18, 2)->comment('Before VAT');
            $t->foreignId('tax_code_id')->nullable()->constrained();
            $t->decimal('tax_amount', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->string('payment_method', 20)->default('cash')->comment('cash, bank_transfer, cheque, card');
            $t->string('cheque_number')->nullable();
            $t->date('cheque_due_date')->nullable();
            $t->string('description')->nullable();
            $t->string('status', 10)->default('draft'); // draft | posted | cancelled
            $t->foreignId('journal_entry_id')->nullable()->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('posted_by')->nullable()->constrained('users');
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'number']);
        });

        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->morphs('attachable');
            $t->string('path');
            $t->string('original_name');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action', 30);
            $t->nullableMorphs('auditable');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('reason')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        // Append-only audit trail at the database level (§6.7). Some shared hosts
        // deny CREATE TRIGGER; the application layer also blocks it, so we continue.
        if (DB::getDriverName() === 'mysql') {
            try {
                DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
                DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
            } catch (\Throwable $e) {
                logger()->warning('Could not create audit triggers: '.$e->getMessage());
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        }
        foreach (['audit_logs', 'attachments', 'vouchers', 'journal_lines', 'journal_entries', 'document_sequences', 'tax_codes', 'accounts', 'fiscal_periods', 'fiscal_years', 'branch_user', 'branches', 'company_user', 'companies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
