<!doctype html>
<html dir="rtl" lang="ar">
<body style="font-family: Tahoma, Arial, sans-serif; color:#111; line-height:1.7">
    <p style="white-space: pre-line">{{ $text }}</p>
    <p>
        <a href="{{ $link }}" style="display:inline-block;background:#0f766e;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">{{ match ($inv->kind) { 'purchase_order' => __('View and print the purchase order'), 'quotation' => __('View and print the quotation'), default => __('View and print the invoice') } }}</a>
    </p>
    <p style="color:#666;font-size:12px">{{ __('The invoice is also attached. The link is valid for :d days.', ['d' => \App\Services\Accounting\InvoiceSharing::LINK_DAYS]) }}</p>
</body>
</html>
