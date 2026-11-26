<?php

namespace App\Support;

/** Permission names and default roles (requirements §6, roles table). Roles are editable in the UI. */
final class Perm
{
    public const ENTRIES_VIEW = 'entries.view';
    public const ENTRIES_DRAFT = 'entries.draft';
    public const ENTRIES_POST = 'entries.post';
    public const ENTRIES_REVERSE = 'entries.reverse';
    public const VOUCHERS_VIEW = 'vouchers.view';
    public const VOUCHERS_DRAFT = 'vouchers.draft';
    public const VOUCHERS_POST = 'vouchers.post';
    public const VOUCHERS_CANCEL = 'vouchers.cancel';
    public const VOUCHERS_OVER_LIMIT = 'vouchers.approve_over_limit';
    public const ACCOUNTS_MANAGE = 'accounts.manage';
    public const PERIODS_CLOSE = 'periods.close';
    public const PERIODS_REOPEN = 'periods.reopen';
    public const YEAR_CLOSE = 'year.close';
    public const REPORTS_VIEW = 'reports.view';
    public const REPORTS_LIMITED = 'reports.limited';
    public const SETTINGS_MANAGE = 'settings.manage';
    public const USERS_MANAGE = 'users.manage';
    public const AUDIT_VIEW = 'audit.view';

    public const LABELS = [
        self::ENTRIES_VIEW => 'عرض القيود',
        self::ENTRIES_DRAFT => 'إدخال مسودات القيود',
        self::ENTRIES_POST => 'اعتماد وترحيل القيود',
        self::ENTRIES_REVERSE => 'عكس القيود',
        self::VOUCHERS_VIEW => 'عرض السندات',
        self::VOUCHERS_DRAFT => 'إدخال مسودات السندات',
        self::VOUCHERS_POST => 'اعتماد وترحيل السندات',
        self::VOUCHERS_CANCEL => 'إلغاء السندات بقيد عكسي',
        self::VOUCHERS_OVER_LIMIT => 'اعتماد سندات فوق حد الاعتماد',
        self::ACCOUNTS_MANAGE => 'إدارة دليل الحسابات',
        self::PERIODS_CLOSE => 'إقفال الفترات',
        self::PERIODS_REOPEN => 'إعادة فتح الفترات',
        self::YEAR_CLOSE => 'إقفال السنة المالية',
        self::REPORTS_VIEW => 'كل التقارير',
        self::REPORTS_LIMITED => 'تقارير محدودة (كشف حساب ودفتر اليومية)',
        self::SETTINGS_MANAGE => 'الإعدادات العامة',
        self::USERS_MANAGE => 'إدارة المستخدمين والأدوار',
        self::AUDIT_VIEW => 'سجل التدقيق',
    ];

    public const ROLES = [
        'data_entry' => ['label' => 'مدخل بيانات', 'perms' => [self::ENTRIES_VIEW, self::ENTRIES_DRAFT, self::VOUCHERS_VIEW, self::VOUCHERS_DRAFT, self::REPORTS_LIMITED]],
        'accountant' => ['label' => 'محاسب', 'perms' => [self::ENTRIES_VIEW, self::ENTRIES_DRAFT, self::ENTRIES_POST, self::VOUCHERS_VIEW, self::VOUCHERS_DRAFT, self::VOUCHERS_POST, self::ACCOUNTS_MANAGE, self::REPORTS_VIEW, self::REPORTS_LIMITED]],
        'chief_accountant' => ['label' => 'رئيس الحسابات', 'perms' => [self::ENTRIES_VIEW, self::ENTRIES_DRAFT, self::ENTRIES_POST, self::ENTRIES_REVERSE, self::VOUCHERS_VIEW, self::VOUCHERS_DRAFT, self::VOUCHERS_POST, self::VOUCHERS_CANCEL, self::VOUCHERS_OVER_LIMIT, self::ACCOUNTS_MANAGE, self::PERIODS_CLOSE, self::PERIODS_REOPEN, self::YEAR_CLOSE, self::REPORTS_VIEW, self::REPORTS_LIMITED, self::AUDIT_VIEW]],
        'management' => ['label' => 'الإدارة', 'perms' => [self::ENTRIES_VIEW, self::VOUCHERS_VIEW, self::REPORTS_VIEW, self::REPORTS_LIMITED, self::AUDIT_VIEW]],
        'system_admin' => ['label' => 'مدير النظام', 'perms' => [self::SETTINGS_MANAGE, self::USERS_MANAGE]],
    ];
}
