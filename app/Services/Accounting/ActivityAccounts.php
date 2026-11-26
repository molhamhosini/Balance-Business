<?php

namespace App\Services\Accounting;

/**
 * Business-specific accounts added on top of the client's chart (assumption, editable in
 * the UI and in this file). Codes follow the client's 4-digit scheme.
 * 'for' => which company activities get the account: slaughterhouse, restaurant, all.
 */
class ActivityAccounts
{
    public static function rows(string $activity, array $sisterCompanies = []): array
    {
        $rows = [
            // Cash boxes and card clearing
            ['1114', 'صندوق الفرع', 'Branch Cash Box', '1110', 'debit', 'all', ['is_cash' => true]],
            ['1117', 'مدفوعات البطاقات تحت التحصيل', 'Card Payments Clearing', '1110', 'debit', 'all'],
            ['1119', 'شيكات برسم التحصيل', 'Cheques Under Collection', '1110', 'debit', 'all'],
            ['2126', 'شيكات مؤجلة الدفع', 'Post-dated Cheques Payable', '2120', 'credit', 'all'],
            ['1118', 'مستحقات منصات التوصيل', 'Delivery Platforms Receivable', '1130', 'debit', 'restaurant'],
            // Inventory
            ['1145', 'مخزون المواشي الحية', 'Livestock Inventory', '1140', 'debit', 'slaughterhouse'],
            ['1146', 'مخزون اللحوم المبردة والمجمدة', 'Chilled & Frozen Meat Inventory', '1140', 'debit', 'all'],
            ['1147', 'مخزون المواد الغذائية', 'Food Supplies Inventory', '1140', 'debit', 'restaurant'],
            ['1148', 'مخزون مواد التغليف', 'Packaging Inventory', '1140', 'debit', 'all'],
            // Intercompany parent
            ['1170', 'الحسابات الجارية مع الشركات الشقيقة', 'Intercompany Current Accounts', '1100', 'debit', 'all'],
            // Corporate tax
            ['2150', 'ضريبة الشركات المستحقة', 'Corporate Tax Payable', '2100', 'credit', 'all'],
            // Revenue
            ['4116', 'مبيعات لحوم بالجملة', 'Wholesale Meat Sales', '4110', 'credit', 'slaughterhouse'],
            ['4117', 'مبيعات لحوم بالتجزئة', 'Retail Meat Sales', '4110', 'credit', 'all'],
            ['4118', 'مبيعات للشركات الشقيقة', 'Sales to Sister Companies', '4110', 'credit', 'slaughterhouse', ['is_related_party' => true]],
            ['4130', 'مبيعات المطعم', 'Restaurant Sales', '4100', 'credit', 'restaurant'],
            ['4131', 'مبيعات الصالة والاستلام', 'Dine-in & Takeaway Sales', '4130', 'credit', 'restaurant'],
            ['4132', 'مبيعات التوصيل المباشر', 'Direct Delivery Sales', '4130', 'credit', 'restaurant'],
            ['4133', 'مبيعات منصات التوصيل', 'Delivery Platform Sales', '4130', 'credit', 'restaurant'],
            ['4134', 'مبيعات الطلبيات والمناسبات', 'Catering Sales', '4130', 'credit', 'restaurant'],
            ['4250', 'إيرادات بيع الجلود والمخلفات', 'Hides & By-products Income', '4200', 'credit', 'slaughterhouse'],
            // Cost of sales
            ['5140', 'تكاليف الذبح والإنتاج', 'Slaughter & Production Costs', '5100', 'debit', 'slaughterhouse'],
            ['5141', 'تكلفة المواشي المذبوحة', 'Cost of Livestock Slaughtered', '5140', 'debit', 'slaughterhouse'],
            ['5142', 'أجور الذبح المباشرة', 'Direct Slaughter Labour', '5140', 'debit', 'slaughterhouse'],
            ['5143', 'العلف ورعاية المواشي', 'Feed & Livestock Care', '5140', 'debit', 'slaughterhouse'],
            ['5144', 'رسوم المسلخ والفحص البيطري', 'Abattoir & Veterinary Fees', '5140', 'debit', 'slaughterhouse'],
            ['5145', 'نفوق وهالك المواشي', 'Livestock Mortality & Shrinkage', '5140', 'debit', 'slaughterhouse'],
            ['5150', 'تكلفة مبيعات المطعم', 'Restaurant Cost of Sales', '5100', 'debit', 'restaurant'],
            ['5151', 'تكلفة اللحوم المستخدمة', 'Meat Used', '5150', 'debit', 'restaurant'],
            ['5152', 'تكلفة المواد الغذائية', 'Food Supplies Used', '5150', 'debit', 'restaurant'],
            ['5153', 'تكلفة التغليف والتعبئة', 'Packaging Used', '5150', 'debit', 'restaurant'],
            ['5154', 'هالك وتالف الطعام', 'Food Waste', '5150', 'debit', 'restaurant'],
            // Operating expenses
            ['6340', 'عمولات منصات التوصيل', 'Delivery Platform Commissions', '6300', 'debit', 'restaurant'],
            ['6350', 'مصروف التغليف', 'Packaging Expense', '6300', 'debit', 'slaughterhouse'],
            ['6470', 'الضيافة', 'Hospitality', '6400', 'debit', 'all', ['is_non_deductible' => true]],
            ['6480', 'النظافة والتعقيم', 'Cleaning & Sanitation', '6400', 'debit', 'all'],
            ['6490', 'وقود ومصروفات المركبات المبردة', 'Refrigerated Vehicle Fuel & Costs', '6400', 'debit', 'slaughterhouse'],
            ['6800', 'ضريبة الدخل', 'Income Tax', '6000', 'debit', 'all'],
            ['6810', 'مصروف ضريبة الشركات', 'Corporate Tax Expense', '6800', 'debit', 'all', ['is_non_deductible' => true]],
        ];

        $out = [];
        foreach ($rows as $r) {
            if ($r[5] !== 'all' && $r[5] !== $activity) {
                continue;
            }
            $out[] = ['code' => $r[0], 'name_ar' => $r[1], 'name_en' => $r[2], 'parent' => $r[3], 'nature' => $r[4], 'statement_line' => null, 'notes' => 'حساب خاص بالنشاط (مقترح)'] + ($r[6] ?? []);
        }

        // One current account per sister company (receivable or payable as the balance falls).
        $n = 1;
        foreach ($sisterCompanies as $name) {
            $out[] = ['code' => (string) (1170 + $n++), 'name_ar' => 'جاري '.$name, 'name_en' => null, 'parent' => '1170', 'nature' => 'debit', 'statement_line' => null, 'notes' => 'حساب جاري مع شركة شقيقة', 'is_related_party' => true];
        }

        return $out;
    }
}
