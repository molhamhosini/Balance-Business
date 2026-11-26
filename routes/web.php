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
