<?php

namespace App\Support;

use App\Models\Invoice;

/** Printed-invoice wording in one language (ar or en), so a document never mixes the two. */
class InvoiceText
{
    public const LANGS = ['ar' => 'العربية', 'en' => 'English'];

    private const T = [
        'no' => ['الرقم', 'No.'], 'po_no' => ['رقم الطلب', 'PO No.'], 'date' => ['التاريخ', 'Date'], 'valid' => ['صالح حتى', 'Valid until'],
        'delivery' => ['التسليم المتوقع', 'Expected delivery'], 'due' => ['تاريخ الاستحقاق', 'Due date'], 'payment' => ['طريقة الدفع', 'Payment'],
        'cash' => ['نقدي', 'Cash'], 'credit' => ['آجل', 'Credit'], 'original' => ['الفاتورة الأصلية', 'Original invoice'], 'supplier_ref' => ['رقم فاتورة المورد', 'Supplier ref.'],
        'bill_to' => ['العميل', 'Bill to'], 'supplier' => ['المورد', 'Supplier'], 'details' => ['التفاصيل', 'Details'], 'branch' => ['الفرع', 'Branch'],
        'currency' => ['العملة', 'Currency'], 'note' => ['البيان', 'Note'], 'tel' => ['هاتف', 'Tel'], 'trn' => ['الرقم الضريبي', 'TRN'],
        'description' => ['الوصف', 'Description'], 'qty' => ['الكمية', 'Qty'], 'unit_price' => ['سعر الوحدة', 'Unit price'], 'net' => ['قبل الضريبة', 'Net'],
        'rate' => ['النسبة', 'Rate'], 'vat' => ['الضريبة', 'VAT'], 'line_total' => ['الإجمالي', 'Total'],
        'subtotal' => ['الإجمالي قبل الضريبة', 'Total excl. VAT'], 'vat_total' => ['ضريبة القيمة المضافة', 'VAT'], 'total' => ['الإجمالي شامل الضريبة', 'Total incl. VAT'],
        'reverse' => ['الاحتساب العكسي مطبق', 'Reverse charge applies'], 'received_by' => ['توقيع المستلم', 'Received by'], 'issued_by' => ['توقيع البائع', 'Issued by'],
        'approved_by' => ['المعتمد', 'Approved by'], 'cancelled' => ['ملغاة', 'CANCELLED'], 'draft' => ['مسودة — ليست فاتورة ضريبية', 'DRAFT — not a tax invoice'],
        'print' => ['طباعة', 'Print'], 'view_link' => ['عرض وطباعة المستند', 'View and print the document'],
        'attached' => ['المستند مرفق أيضاً بصيغة PDF. الرابط صالح لمدة :d يوماً.', 'The document is also attached as a PDF. The link is valid for :d days.'],
    ];

    public static function lang(?string $lang): string
    {
        return array_key_exists((string) $lang, self::LANGS) ? $lang : 'ar';
    }

    /** @return array<string,string> */
    public static function labels(string $lang): array
    {
        $i = $lang === 'en' ? 1 : 0;

        return array_map(fn ($pair) => $pair[$i], self::T);
    }

    public static function title(Invoice $inv, string $lang): string
    {
        $trn = (bool) $inv->party?->trn;
        $t = match ($inv->kind) {
            'sale' => $trn ? ['فاتورة ضريبية', 'Tax Invoice'] : ['فاتورة ضريبية مبسطة', 'Simplified Tax Invoice'],
            'sale_return' => ['إشعار دائن ضريبي', 'Tax Credit Note'], 'quotation' => ['عرض سعر', 'Quotation'],
            'purchase_order' => ['طلب شراء', 'Purchase Order'], 'purchase' => ['فاتورة شراء', 'Purchase Bill'], default => ['إشعار مدين', 'Debit Note'],
        };

        return $t[$lang === 'en' ? 1 : 0];
    }

    /** Arabic or English name of a company / party, falling back to the other one. */
    public static function name(?object $model, string $lang): string
    {
        if (! $model) {
            return '';
        }

        return (string) ($lang === 'en' ? ($model->name_en ?: $model->name_ar) : ($model->name_ar ?: $model->name_en));
    }
}
