@php($t = \App\Support\InvoiceText::labels($lang ?? 'ar'))
<!doctype html>
<html dir="{{ ($lang ?? 'ar') === 'en' ? 'ltr' : 'rtl' }}" lang="{{ $lang ?? 'ar' }}">
<body style="font-family: Tahoma, Arial, sans-serif; color:#111; line-height:1.7">
    <p style="white-space: pre-line">{{ $text }}</p>
    <p>
        <a href="{{ $link }}" style="display:inline-block;background:#0f766e;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">{{ $t['view_link'] }}</a>
    </p>
    <p style="color:#666;font-size:12px">{{ str_replace(':d', \App\Services\Accounting\InvoiceSharing::LINK_DAYS, $t['attached']) }}</p>
</body>
</html>
