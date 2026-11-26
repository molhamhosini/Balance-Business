<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, ['ar', 'en'], true), 404);
    session(['locale' => $locale]);
    auth()->user()?->update(['locale' => $locale]);

    return back();
})->middleware(['web', 'auth'])->name('locale.switch');

Route::get('/vouchers/{voucher}/print', function (\App\Models\Voucher $voucher) {
    abort_unless(auth()->user()->canAccessTenant($voucher->company) && auth()->user()->can(\App\Support\Perm::VOUCHERS_VIEW), 403);

    return view('print.voucher', ['v' => $voucher->load(['company', 'branch', 'cashAccount', 'counterAccount', 'taxCode', 'creator', 'poster'])]);
})->middleware(['web', 'auth'])->name('voucher.print');

// Link sent to the customer by email / WhatsApp: no login, protected by a signed, expiring URL.
Route::get('/i/{invoice}', function (\App\Models\Invoice $invoice) {
    abort_unless($invoice->isSendable(), 404);

    return view('print.invoice', ['inv' => $invoice->load(['company', 'branch', 'party', 'lines.taxCode', 'original'])]);
})->middleware(['web', 'signed'])->name('invoice.shared');

// Report PDF shared by WhatsApp: signed, expiring link to a generated file.
Route::get('/r/{file}', function (string $file) {
    abort_unless(preg_match('/^[0-9a-f-]{36}\.pdf$/', $file) && \Illuminate\Support\Facades\Storage::disk('local')->exists('shared-reports/'.$file), 404);

    return \Illuminate\Support\Facades\Storage::disk('local')->download('shared-reports/'.$file, request('name', 'report.pdf'), ['Content-Type' => 'application/pdf']);
})->middleware(['web', 'signed'])->name('report.shared');

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/invoices/{invoice}/print', function (\App\Models\Invoice $invoice) {
        abort_unless(auth()->user()->canAccessTenant($invoice->company) && auth()->user()->can(\App\Support\Perm::INVOICES_VIEW), 403);

        return view('print.invoice', ['inv' => $invoice->load(['company', 'branch', 'party', 'lines.taxCode', 'original'])]);
    })->name('invoice.print');

    Route::get('/invoices/{invoice}/einvoice.{format}', function (\App\Models\Invoice $invoice, string $format) {
        abort_unless(auth()->user()->canAccessTenant($invoice->company) && auth()->user()->can(\App\Support\Perm::INVOICES_VIEW), 403);
        $svc = app(\App\Services\Accounting\EInvoiceService::class);
        try {
            $body = $format === 'xml' ? $svc->toUbl($invoice) : $svc->toJson($invoice);
        } catch (\App\Exceptions\AccountingException $e) {
            abort(422, $e->getMessage());
        }

        return response($body, 200, [
            'Content-Type' => $format === 'xml' ? 'application/xml' : 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.'.$format.'"',
        ]);
    })->whereIn('format', ['xml', 'json'])->name('invoice.einvoice');
});
