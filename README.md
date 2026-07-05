# DO Account Manager Bot

ربات تلگرامی (Laravel + [nutgram/laravel](https://github.com/nutgram/laravel)) برای مدیریت اکانت‌های DigitalOcean: ساخت سرور، روشن/خاموش/ریبوت، ریسایز، ریبیلد، آی‌پی اضافه و تغییر آی‌پی.

معماری طوری طراحی شده که افزودن دیتاسنترهای دیگر (Vultr، Linode، ...) در آینده فقط نیاز به یک کلاینت جدید دارد، بدون تغییر در فلوی ربات.

## پیش‌نیاز

- PHP 8.3+‎ و Composer
- MySQL
- برای اجرای واقعی: یک دامنه با SSL معتبر (Telegram webhook باید HTTPS باشد). برای تست لوکال می‌توانید از ngrok استفاده کنید.

## نصب

```bash
composer install
cp .env.example .env
php artisan key:generate
```

مقادیر زیر را در `.env` تنظیم کنید:

```
DB_DATABASE=account_manager
DB_USERNAME=root
DB_PASSWORD=

TELEGRAM_TOKEN=<توکن ربات از @BotFather>
ADMIN_TELEGRAM_IDS=<آیدی عددی تلگرام شما، با کاما جدا برای چند نفر>
```

سپس دیتابیس را بسازید و مایگریشن را اجرا کنید:

```bash
mysql -u root -e "CREATE DATABASE account_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate
```

## اجرا

دو پردازش باید همزمان در حال اجرا باشند:

```bash
# پردازش صف؛ برای پیگیری وضعیت عملیات‌های async روی DigitalOcean (ساخت/ریسایز/ریبیلد/...) ضروری است
php artisan queue:work

# وب‌سرور (یا از nginx/apache روی دامنه‌ی خودتان استفاده کنید)
php artisan serve
```

سپس وبهوک تلگرام را روی آدرس عمومی پروژه ثبت کنید:

```bash
php artisan nutgram:hook:set https://yourdomain.com/api/telegram/webhook
```

برای توسعه/تست لوکال بدون دامنه، می‌توانید به‌جای وبهوک از حالت polling استفاده کنید:

```bash
php artisan nutgram:run
```

## استفاده از ربات

- `/start` — نمایش منوی اصلی (پنل‌های من / ساخت سرور / سرورهای من)
- `/cancel` — لغو هر عملیات نیمه‌کاره

هنگام افزودن پنل، ابتدا دیتاسنتر (فعلاً فقط DigitalOcean فعال است) و سپس توکن API را از شما می‌پرسد. توکن را می‌توانید از اینجا بسازید:
https://cloud.digitalocean.com/account/api/tokens (دسترسی Read & Write لازم است)

توکن پس از اعتبارسنجی به‌صورت رمزنگاری‌شده (`encrypted` cast روی مدل `Panel`) در دیتابیس ذخیره می‌شود و پیام حاوی توکن از تاریخچه چت پاک می‌شود.

## افزودن یک دیتاسنتر جدید (مثلاً Vultr یا Linode)

1. یک کلاینت جدید در `app/Services/Providers/<Name>/<Name>Client.php` بسازید که `App\Services\Providers\ProviderClient` را پیاده‌سازی کند (متدهای regions/sizes/images/createServer/... مطابق API آن سرویس).
2. در `app/Enums/Provider.php` متد `isAvailable()` را برای آن مورد `true` کنید.
3. کلاینت جدید را به نگاشت `ProviderManager::$clients` در `app/Services/Providers/ProviderManager.php` اضافه کنید.

فلوی ربات (افزودن پنل، ساخت سرور، مدیریت سرور) بدون هیچ تغییری با دیتاسنتر جدید کار خواهد کرد.

## تست

```bash
php artisan test
```

تست‌ها فلوی افزودن پنل، ساخت سرور و اکشن‌های سرور را با موک کردن API دیجیتال اوشن (`Http::fake`) و ربات تلگرام (`Nutgram::fake`) پوشش می‌دهند. تست‌ها روی یک دیتابیس MySQL جدا (`account_manager_test`) اجرا می‌شوند؛ در صورت نیاز آن را بسازید:

```bash
mysql -u root -e "CREATE DATABASE account_manager_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```
"# account_manager" 
