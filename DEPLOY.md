# النشر على Hostinger (Cloud Startup)

خطة Cloud Startup تدعم PHP 8.3 وMySQL وSSH وComposer وCron، وهذا كل ما يحتاجه النظام.

## مرة واحدة

1. **hPanel ← Websites ← Manage ← Advanced ← PHP Configuration**: اختر PHP 8.3 وفعّل `intl`, `zip`, `gd`, `pdo_mysql`, `fileinfo`.
2. **Databases ← MySQL Databases**: أنشئ قاعدة بيانات ومستخدماً، واحفظ الاسم وكلمة المرور.
3. **Advanced ← SSH Access**: فعّل SSH ثم اتصل:

```bash
cd ~/domains/YOUR-DOMAIN
git clone https://github.com/molhamhosini/Balance-Business.git app
cd app
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
nano .env   # APP_ENV=production, APP_DEBUG=false, APP_URL=https://YOUR-DOMAIN, DB_*, SEED_PASSWORD
php artisan migrate --seed --force
php artisan storage:link
php artisan filament:optimize && php artisan optimize
```

4. اجعل مجلد الموقع يشير إلى `public`:

```bash
cd ~/domains/YOUR-DOMAIN
mv public_html public_html_old
ln -s app/public public_html
```

5. **Advanced ← Cron Jobs**: أضف مهمة كل دقيقة (لازمة للنسخ الاحتياطي اليومي):

```
/usr/bin/php /home/USER/domains/YOUR-DOMAIN/app/artisan schedule:run
```

6. سجّل الدخول على `https://YOUR-DOMAIN/admin` بحساب `admin@example.com` وغيّر كلمات المرور، ثم من
   **إعدادات الشركة** أدخل الاسم والرقم الضريبي والشعار لكل شركة، ومن **الفروع** تأكد من الإمارة.

## التحديثات

```bash
cd ~/domains/YOUR-DOMAIN/app
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan up
```

## النسخ الاحتياطي والاسترجاع

- تلقائياً كل يوم الساعة 2:00 صباحاً (توقيت الإمارات) إلى `storage/app/backups` مع الاحتفاظ بآخر 30 نسخة.
- يدوياً: `php artisan backup:database`
- استرجاع: `php artisan backup:restore storage/app/backups/FILE.sql.gz`
- يُنصح أيضاً بتفعيل النسخ الاحتياطي اليومي من Hostinger ونسخ المجلد خارج الخادم دورياً.
- معيار القبول يطلب تجربة استرجاع ناجحة مرة واحدة على الأقل: جرّبها على قاعدة بيانات تجريبية.

## ملاحظات

- إذا رفض الخادم إنشاء الـ Triggers أثناء `migrate` فسيستمر التثبيت، ويبقى سجل التدقيق محمياً من داخل التطبيق.
- لا تضع `APP_DEBUG=true` على الخادم الحقيقي.
