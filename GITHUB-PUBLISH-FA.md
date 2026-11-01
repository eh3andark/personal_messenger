# راهنمای انتشار رسمی «چت امن» در GitHub

**صاحب پروژه و منتشرکننده:** [eh3andark](https://github.com/eh3andark)  
**مخزن مقصد:** **https://github.com/eh3andark/personal_messenger**  
**مجوز کد اصلی:** [MIT](LICENSE) — © 2026 eh3andark · [اطلاعیهٔ دارایی‌های شخص ثالث](NOTICE.md) · [راهنمای نصب](README-FA.md)

> **وضعیت هنگام آماده‌سازی این راهنما (۳۱ اکتبر ۲۰۲۶):** نشانی بالا به یک مخزن عمومی موجود با شاخهٔ پیش‌فرض `main` اشاره می‌کرد، اما مخزن در بررسی عمومی **خالی بود** و Release نداشت. این وضعیت می‌تواند بعداً عوض شود. **مخزن تازه‌ای با همان هدف نساز**؛ ابتدا صفحهٔ فعلی را باز کن و فقط اگر هنوز خالی است، مراحل اولین push زیر را انجام بده. ZIPهایی که در اینجا آماده شده‌اند خودبه‌خود commit، push، tag یا Release GitHub ایجاد نمی‌کنند. اجرای مراحل نیازمند ورود خودت به GitHub است.

## نقشهٔ سادهٔ انتشار

| فایل | جای درست | برای چه کسی؟ |
| --- | --- | --- |
| **محتوای داخل** `hamrah-messenger-source.zip` | فایل‌های واقعی شاخهٔ `main`، نه خود ZIP به‌عنوان تنها فایل مخزن | بازبینی کد، مشارکت، آزمون و ساخت نسخه |
| `hamrah-messenger-cpanel.zip` | پیوست یک نسخه در **Releases → Assets** | نصب **تازه** در cPanel |
| «Source code (zip)» خودکار GitHub | خود GitHub آن را از tag می‌سازد | دیدن کد همان نسخه؛ **نه بستهٔ آمادهٔ cPanel** |

لینک دانلود نهاییِ کاربران پس از انتشار: [**Releases پروژه**](https://github.com/eh3andark/personal_messenger/releases) → نسخهٔ موردنظر → **Assets → `hamrah-messenger-cpanel.zip`**. تا قبل از وجود این پیوست، نباید بگویی نسخهٔ نصب از GitHub قابل دریافت است.

## ۱. آماده‌سازی و بازرسی قبل از عمومی‌کردن

1. اگر ZIPهای همراه پروژه را داری، روی **رایانهٔ خودت** `hamrah-messenger-source.zip` را Extract کن. پوشهٔ داخلی آن به نام `hamrah-messenger/` است؛ ترمینال را **داخل همان پوشه** باز کن. این پوشه باید `index.php`، `LICENSE`، `README.md`، `.gitignore`، `.github/`، `tests/`، `scripts/` و `data/` بی‌خطر را داشته باشد. نام پوشهٔ داخلی بخشی از آدرس GitHub نمی‌شود.
2. در `LICENSE` باید **`Copyright (c) 2026 eh3andark`** باشد؛ [AUTHORS.md](AUTHORS.md) نام منتشرکننده را در مستندات ثبت می‌کند. انتخاب نهایی این بسته **MIT** است. MIT اجازهٔ استفاده، تغییر، کپی و حتی بازنشر تجاری را با حفظ متن مجوز و اطلاعیه می‌دهد؛ از دیگران نمی‌خواهد نامت را در **رابط کاربری** نشان دهند و جلو هر کپی‌ای را نمی‌گیرد. اگر مدل حقوقی دیگری می‌خواهی، پیش از انتشار عمومی و با بررسی حق‌نشر/مشارکت‌های واقعی تصمیم جداگانه لازم است؛ مجوز قبلیِ نسخه‌های توزیع‌شده را بعداً نمی‌توان یک‌طرفه پس گرفت.
3. [NOTICE.md](NOTICE.md) و متن مجوز قلم‌ها و آیکن‌ها را حذف نکن: فونت‌ها SIL OFL 1.1 و آیکن‌ها Lucide ISC/Feather MIT هستند؛ صاحب حق آن‌ها **eh3andark نیست**. IranSans اختصاصی در بسته نیست. نام/نشان سرویس‌های ثالث و محتوای آن‌ها با MIT کد آزاد نمی‌شود.
4. داخل **`data/` منبع** فقط `.htaccess`، `index.php` و `.gitkeep` مجازند. هرگز `data/` سایت واقعی را به سورس کپی نکن: فایل‌های `users.json`، **`user_activity.json` و `presence.json` (آمار خصوصی زمان‌های حساب)**، `config.json`، `sessions.json`، `.master_secret.php`، `.setup_token.php`، چت‌ها، رسانه، لاگ، بکاپ، کلید AI و گذرواژه‌های تست خصوصی هستند. `.gitignore` محافظ کمکی است، نه تضمین؛ پیش از هر push فایل‌های مرحله‌بندی‌شده را ببین. اگر چیزی قبلاً commit شده، پاک‌شدن از آخرین commit **تاریخچه را پاک نمی‌کند**.
5. ZIP نصب تازه را هم بازبینی کن: `index.php` و `setup.php` باید در **ریشهٔ ZIP** باشند و `data/` فقط `.htaccess` و `index.php` داشته باشد. `tests/`، `.github/` و `scripts/` نباید در ZIP نصب باشند. اگر بسته‌ها را از سورس محلی دوباره می‌سازی، از ریشهٔ سورس این دو دستور را بزن: `python3 scripts/build_source_zip.py` و `python3 scripts/build_cpanel_zip.py`. خروجی در پوشهٔ **بالادستی سورس** ایجاد می‌شود. بستهٔ نصب تازه هرگز برای بازنویسی `data/` نصب فعال نیست.

## ۲. اولین commit و push به **مخزن موجود**

حساب GitHub خودت را با احراز هویت دومرحله‌ای امن کن؛ Git را روی رایانه نصب کن. ابتدا https://github.com/eh3andark/personal_messenger را بازبینی کن: اگر هنوز عبارت **«This repository is empty»** می‌بینی، در ترمینالِ **داخل پوشهٔ `hamrah-messenger/` استخراج‌شده**:

```bash
git init -b main
# اگر Git هنگام commit نام/ایمیل خواست، هویت خودت را فقط در همین مخزن تنظیم کن:
# git config user.name "eh3andark"
# git config user.email "YOUR-VERIFIED-OR-GITHUB-NOREPLY-EMAIL"

git status --short
git add -A
git diff --cached --name-only
# توقف و بررسی: هیچ فایل واقعی data، توکن، رمز، بکاپ، بستهٔ ZIP یا .env نباید در این فهرست باشد.
git diff --cached --stat
git commit -m "Release Chat Amn 4.2.0"
git remote add origin https://github.com/eh3andark/personal_messenger.git
git remote -v
git push -u origin main
```

- **هرگز** رمز، PAT یا کلید خصوصی را در URL فرمان (`https://TOKEN@...`)، فایل پروژه، اسکرین‌شات یا چت قرار نده. برای HTTPS از Git Credential Manager یا `gh auth login` روی دستگاه خودت استفاده کن؛ SSH هم با کلید حساب خودت گزینهٔ امنی است. مخزن مقصد باید متعلق به حسابی باشد که با آن وارد شده‌ای. راهنمای رسمی: [افزودن پروژهٔ محلی به GitHub](https://docs.github.com/en/migrations/importing-source-code/using-the-command-line-to-import-source-code/adding-locally-hosted-code-to-github).
- اگر از قبل `origin` داری، به‌جای اجرای دوبارهٔ `git remote add`، `git remote -v` را ببین و مطمئن شو دقیقاً به **`eh3andark/personal_messenger`** اشاره می‌کند. اگر شاخهٔ remote دیگر خالی نیست، **force push نکن**؛ ابتدا تاریخچه و تغییرات موجود را بررسی و با احتیاط هماهنگ کن. اگر Git نام/ایمیل خواست، ایمیل تأییدشده یا noreply خودت را از تنظیمات GitHub بگیر؛ در راهنما عمداً ایمیل ساختگیِ شخصی جاگذاری نشده است.
- روش بدون ترمینال: GitHub Desktop می‌تواند پوشهٔ استخراج‌شده را به مخزن محلی تبدیل کند؛ در **Repository settings / Remote** نشانی **همین مخزن موجود** را انتخاب و تغییرات را پس از بازبینی commit و push کن. «Publish repository» برای ساخت مخزن تازه را بی‌دلیل نزن. رابط وب GitHub برای فایل‌های کم ممکن است کار کند، ولی فایل‌های مخفی و ساختار پوشه‌ها را آسان‌تر جا می‌اندازد؛ آپلود **یک ZIP به‌عنوان تنها فایل** مخزن قابل مرور نمی‌سازد.

## ۳. بعد از push، نتیجه را واقعاً بررسی کن

1. صفحهٔ [مخزن](https://github.com/eh3andark/personal_messenger) را Refresh کن؛ شاخهٔ `main` باید `README.md` فارسی، `LICENSE`، `AUTHORS.md`، `NOTICE.md`، `.github/workflows/ci.yml`، `tests/` و سورس برنامه را نشان دهد؛ `data/` فقط سه فایل امن داشته باشد. اگر هنوز «empty» است، push به این مخزن انجام نشده است.
2. برگهٔ **Actions** را ببین؛ اگر CI خطا دارد، علت را بررسی و اصلاح کن، نه اینکه صرفاً بگویی آزمون‌ها پاس شده‌اند. آزمون‌های مخرب/HTTP را **فقط روی کپی یک‌بارمصرف** اجرا کن، نه روی دادهٔ کاربران. قبل از استفادهٔ عمومی نیز نصب واقعی cPanel، PHP نسخهٔ هاست، HTTPS و قواعد 403/404 برای فایل‌های خصوصی را جدا بیازما؛ آزمون محلی جای این بررسی را نمی‌گیرد.
3. در **Settings → Security** (نام دقیق منو ممکن است تغییر کند)، امکانات موجودِ secret scanning/push protection، گزارش خصوصی آسیب‌پذیری و حفاظت از شاخه را بررسی کن. [SECURITY.md](SECURITY.md) راهنمای گزارش امن است؛ توکن یا دادهٔ کاربران را در Issue عمومی نپذیر.

## ۴. انتشار ZIP نصب در **Releases** (گام ضروری برای کاربران cPanel)

پس از اینکه کد روی `main` دیده شد:

1. در [Releases](https://github.com/eh3andark/personal_messenger/releases) روی **Draft a new release** بزن. **Choose a tag** → یک tag جدید مثل **`v4.2.0`** بساز و **Target: `main`** (commit بررسی‌شدهٔ همین نسخه) را انتخاب کن. عنوان پیشنهادی: **«چت امن 4.2.0 — بستهٔ نصب cPanel»**.
2. در توضیحات، خلاصهٔ تغییرات از [CHANGELOG.md](CHANGELOG.md) (از جمله زبانهٔ خصوصی آمار استفاده برای مدیر اصلی و نامشخص‌بودن تاریخ ساخت حساب‌های قدیمی)، پیش‌نیازها (PHP 7.4+، OpenSSL/mbstring/fileinfo، HTTPS، Apache/LiteSpeed)، نکتهٔ **عدم بازنویسی `data/` در ارتقا** و این جمله را بنویس: «نصب: از Assets فایل `hamrah-messenger-cpanel.zip` را بگیرید؛ Source code (zip) بستهٔ نصب نیست.» محدودیت E2EE نبودن و وابستگی ابزارهای تلگرام/نرخ به منبع بیرونی را پنهان نکن.
3. فایل **`hamrah-messenger-cpanel.zip`** بررسی‌شده را با کشیدن به قسمت **Attach binaries / Assets** اضافه کن. می‌توانی **`hamrah-messenger-source.zip`** همان نسخه را هم اختیاری اضافه کنی. ZIPهای خودکار Source code مربوط به tag را GitHub خودش اضافه می‌کند؛ آن‌ها را با بستهٔ cPanel اشتباه نگیر. تا وقتی Asset cPanel آپلود نشده Release را به‌عنوان «دانلود نصب آماده» معرفی نکن.
4. در کامپیوتر خودت هش‌ها را بگیر و در متن توضیحات Release قرار بده؛ مثلاً در لینوکس/مک `sha256sum hamrah-messenger-cpanel.zip hamrah-messenger-source.zip` (در PowerShell ویندوز: `Get-FileHash .\\hamrah-messenger-cpanel.zip -Algorithm SHA256`). هش باید متعلق به **همین فایل‌هایی** باشد که پیوست کرده‌ای، نه ZIP قدیمی.
5. **Publish release** را بزن. بعد صفحهٔ Release را بدون ورود یا در پنجرهٔ خصوصی باز کن و دانلود **Assets → `hamrah-messenger-cpanel.zip`** را آزمایش کن. فقط در این مرحله مسیر Releases برای کاربران واقعاً کار می‌کند. [راهنمای رسمی GitHub Releases](https://docs.github.com/en/repositories/releasing-projects-on-github/managing-releases-in-a-repository).

## ۵. نصب کاربران و لینک داخل برنامه

نصب‌کننده طبق [README-FA.md](README-FA.md) بستهٔ cPanel را در **پوشهٔ خالیِ نصب تازه** Extract می‌کند، با HTTPS به `setup.php` می‌رود و توکن تصادفی **متعلق به همان نصب** را از `data/.setup_token.php` در File Manager خودش می‌خواند. هیچ گذرواژه/توکن عمومی یا مشترکی در GitHub وجود ندارد. روی نصب فعال از **کل کد و `data/`** باهم بکاپ می‌گیرد، ارتقا را روی کپی آزمایشی بررسی می‌کند و **`data/` را بازنویسی نمی‌کند**.

در **پنل مدیرِ نصب خودت** و **بعد از انتشار واقعی کد در مخزن** می‌توانی `https://github.com/eh3andark/personal_messenger` را در «تنظیمات عمومی → آدرس مخزن GitHub» ذخیره کنی. تا قبل از ذخیرهٔ آدرس واقعی، هیچ لینک GitHub نمایشی در صفحهٔ ورود نیست. اعتبار پروژه در اسناد است؛ انتخاب تو برای **عدم افزودن امضای اجباری به UI** رعایت شده است.

## ۶. خطاهای رایج و نگهداری حق‌نشر

- **Releases خالی است:** تنها push کافی نیست. باید tag و Release بسازی و ZIP cPanel را واقعاً به Assets پیوست کنی.
- **احراز هویت push رد می‌شود:** نام حساب/سطح دسترسی/Remote را چک کن و با روش امن GitHub دوباره وارد شو؛ رمز حساب GitHub را به‌عنوان password قدیمی Git در URL ننویس.
- **`remote origin already exists` / `non-fast-forward`:** Remote/تاریخچهٔ موجود را با `git remote -v` و وضعیت شاخه بررسی کن؛ **force push** به مخزن عمومی نکن.
- **نشت ناخواستهٔ کلید/داده:** فوراً دسترسی را محدود و رمز/توکن قابل تعویض را باطل کن؛ حذف یک فایل از آخرین commit تاریخچهٔ Git، forkها و ZIPها را پاک نمی‌کند. در مورد افشای کلید اصلی همراه داده، احتمال افشای پیام‌های قدیمی را جدی بگیر و با متخصص امنیت مشورت کن.
- **فکر می‌کنی MIT کپی را ممنوع کرده؟** خیر؛ رعایت اطلاعیهٔ حق نشر و متن مجوز شرط است، ولی استفاده/فروش طبق MIT مجاز می‌ماند. ثبت تاریخچهٔ انتشار برای اثبات انتساب کمک می‌کند؛ نقض مجوز/علامت تجاری موضوع جداگانه‌ای است. نام شخص ثالث را به‌عنوان صاحب حق اصلی کد یا دارایی‌های همراه ننویس.

این راهنما مشاورهٔ حقوقی نیست؛ برای اختلاف مالکیت، علامت تجاری و مشارکت‌های دیگران از متخصص حوزهٔ قضایی خود مشورت بگیر.
