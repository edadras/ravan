# روان / Ravan — پلتفرم مشاوره روان‌شناسی آنلاین با تحلیل رفتاری مشاهده‌ای

**سه‌زبانه در همه بخش‌ها: فارسی · English · Türkçe** — رابط کاربری، متن رضایت‌ها، کاتالوگ ۲۷۹ سیگنال، رویدادهای زنده، گزارش پایان جلسه، پیام‌های API و مستندات. جزئیات: [`docs/09-i18n.md`](docs/09-i18n.md).

> **English.** Ravan is an online mental-health counselling platform (Laravel + MySQL backend, Flutter web app, Python analysis service). Patients choose an admin-verified clinician and hold text, audio or video sessions. With the patient's explicit, separately revocable consent, the patient's browser extracts descriptive numbers (head, gaze, face, posture, hands, voice) on device — never raw video — and the analysis service compares them **only with the patient's own baseline in that session**, surfacing *observations* with quantities, quality and benign explanations to the clinician, never diagnoses. Full transcript, clinician review (relevant / dismiss / note) and an end-of-session draft the clinician must accept, edit or reject. Everything is trilingual (fa/en/tr); see [`docs/09-i18n.md`](docs/09-i18n.md).
>
> **Türkçe.** Ravan, çevrim içi bir ruh sağlığı danışmanlık platformudur (Laravel + MySQL arka uç, Flutter web uygulaması, Python analiz hizmeti). Danışanlar yönetici tarafından doğrulanmış bir klinisyen seçer ve yazılı, sesli veya görüntülü seans yapar. Danışanın açık ve ayrıca geri çekilebilir onayıyla, danışanın tarayıcısı cihaz üzerinde betimleyici sayılar (baş, bakış, yüz, duruş, eller, ses) çıkarır — asla ham video değil — ve analiz hizmeti bunları **yalnızca danışanın o seanstaki kendi taban çizgisiyle** karşılaştırarak klinisyene miktar, kalite ve zararsız açıklamalarla *gözlemler* sunar, asla tanı değil. Tam transkript, klinisyen incelemesi (ilgili / reddet / not) ve klinisyenin onaylaması, düzenlemesi veya reddetmesi gereken seans sonu taslağı. Her şey üç dillidir (fa/en/tr); bkz. [`docs/09-i18n.md`](docs/09-i18n.md).

سامانه‌ای برای برگزاری جلسات متنی/صوتی/تصویری بین مراجع و روان‌شناس/روان‌پزشکِ احراز هویت‌شده، که با **رضایت صریح مراجع** حالات بدن، چهره و صدا را در طول جلسه به‌صورت **مشاهده (نه تشخیص)** برای درمانگر توصیف می‌کند، لاگ کامل مکالمه را نگه می‌دارد و در پایان جلسه پیش‌نویس گزارشی برای بازبینی درمانگر می‌سازد.

> اصل طراحی: **مشاهده → شواهد عددی نسبت به خط پایه‌ی خودِ فرد → زمینه‌های محتمل → نمایش به متخصص.** هیچ خروجی‌ای تشخیص، تشخیص دروغ، یا برچسب هیجانی قطعی نیست. تصمیم بالینی با درمانگر می‌ماند.

## ساختار مخزن

| مسیر | محتوا |
|---|---|
| [`catalog/`](catalog/) | **کاتالوگ سیگنال‌های رفتاری** (سه‌زبانه fa/en/tr با اعتبارسنجی کامل بودن): ۲۷۹ سیگنال نام‌گذاری‌شده در ۱۹ گروه (سر، چشم/نگاه، ابرو، دهان/فک، پویایی چهره، بالاتنه، شانه/گردن، دست‌ها، ژست، پایین‌تنه، حرکت کل بدن، عروض گفتار، کیفیت صدا، روانی/زبان، نوبت‌گیری، هم‌زمانی بین‌فردی، خوشه‌های چندوجهی، کیفیت فنی، عملیاتی) با مشخصات آشکارساز، دروازه‌های کیفیت، زمینه‌های بی‌خطر، دلیل بالینی (فارسی/انگلیسی/ترکی) و استنتاج‌های ممنوع؛ به‌علاوه واژه‌نامه ویژگی‌ها (۴۷۸ نقطه چهره، ۳۳ نقطه بدن، ۲×۲۱ نقطه دست، ۵۲ بلندشیپ، ۳۲ واحد حرکتی FACS، ۱۸۱ ویژگی مشتق، توصیف‌گرهای صوتی) و فضای پارامتری با **~۴۵۰ میلیون پارامتر قابل آدرس‌دهی**. |
| [`analysis-service/`](analysis-service/) | سرویس تحلیل (Python/FastAPI): خط پایه شخصی مقاوم، آشکارسازهای مبتنی بر کاتالوگ، تلفیق چندوجهی، پرچم‌های محتوای متن، پیش‌نویس گزارش با نگهبان زبانی؛ **دستیار بالینی** (OpenAI/Anthropic): فرمول‌بندی و فرضیه‌های افتراقی با شواهد موافق/مخالف، تحلیل الگوی پاسخ به سؤال‌ها، پرسش از دستیار. ۲۴ تست. |
| [`asr-service/`](asr-service/) | تشخیص گفتار (faster-whisper محلی یا OpenAI) با گوینده مشخص از هر میکروفون، تشخیص سؤال ساختاری در سه زبان و اندازه‌گیری آهنگ صدا برای سؤال‌های آهنگی. ۱۹ تست. |
| [`deploy/`](deploy/) و [`install.sh`](install.sh) | استقرار تک‌سروری: Caddy با TLS خودکار، php-fpm، کارگر صف، LiveKit و coturn، نصاب با تولید خودکار رمزها، پشتیبان‌گیری. راهنما: [`docs/11-deployment.md`](docs/11-deployment.md). |
| [`tools/accuracy/`](tools/accuracy/) | محک دقت استخراج ویژگی در برابر دادهٔ مرجع، با بودجهٔ افت که در CI بررسی می‌شود. نتایج: [`docs/12-accuracy.md`](docs/12-accuracy.md). |
| [`tools/env/`](tools/env/) | بررسی می‌کند هر تنظیمی که در `.env.example` مستند شده واقعاً به سرویسی که آن را می‌خواند می‌رسد. تنظیمِ سیم‌کشی‌نشده هیچ نشانه‌ای ندارد و سرویس بی‌صدا مقدار پیش‌فرض را به کار می‌برد؛ در CI اجرا می‌شود. |
| [`backend/`](backend/) | بک‌اند Laravel 13 + MySQL: ثبت‌نام/ورود/کد یک‌بارمصرف/بازیابی رمز، فهرست درمانگران تأییدشده توسط ادمین، نوبت‌دهی و پرداخت، جلسه، رضایت‌های نسخه‌دار، وب‌هوک امضاشده، تایم‌لاین رویدادها (فقط درمانگر)، رونویسی، چت جلسه و پیام‌رسانی خارج جلسه با رسید، **پرونده بالینی** (شرح حال، SOAP، تشخیص ICD-11، پرسشنامه‌های نمره‌دار، داروها)، پیشنهادهای هوش مصنوعی با پذیرش/رد درمانگر، اعلان‌ها و یادآوری، حذف طبق سیاست نگهداری، لاگ ممیزی و دسترسی. ۲۸ تست. |
| [`flutter_app/`](flutter_app/) | وب‌اپ Flutter (فارسی RTL / انگلیسی / ترکی): ثبت‌نام، بازیابی رمز، پروفایل، رزرو و پرداخت، جلسه با تحلیل رفتاری و رونویسی زنده، کنسول درمانگر، پرونده مراجع و درمانگر، پرسشنامه‌ها، پنل دستیار بالینی، پیام‌رسانی؛ استخراج ویژگی روی دستگاه با MediaPipe و ضبط قطعه‌ای برای ASR. |
| [`docs/`](docs/) | طرح کامل: معماری، پایگاه داده، API، خط لوله زنده، راهنمای کاتالوگ، حریم خصوصی و امنیت، ارائه‌دهنده هوش مصنوعی، نقشه راه، سه‌زبانه بودن. |

## نصب روی سرور

```bash
git clone https://github.com/edadras/ravan.git /opt/ravan
cd /opt/ravan
./install.sh --domain ravan.example.com --email admin@example.com
docker compose exec backend php artisan ravan:create-admin
```

نصاب همهٔ رمزها را خودش تولید می‌کند، مدل‌های MediaPipe را روی سرور می‌آورد تا وابستگی به CDN بیرونی نماند، و کل استک را پشت Caddy با گواهی خودکار Let's Encrypt بالا می‌آورد. HTTPS اختیاری نیست: مرورگر بدون آن اجازهٔ دسترسی به دوربین و میکروفون نمی‌دهد. جزئیات، پورت‌های فایروال، پشتیبان‌گیری و عیب‌یابی: [`docs/11-deployment.md`](docs/11-deployment.md).

## اجرای سریع (توسعه)

```bash
# ۱) کاتالوگ
python3 catalog/build_catalog.py

# ۲) سرویس تحلیل
pip install -r analysis-service/requirements.txt
cd analysis-service && python -m pytest -q && uvicorn app.main:app --port 8100

# ۳) بک‌اند
cd backend && cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # کاتالوگ + متن رضایت‌ها + داده نمونه (local)
php artisan test && php artisan serve

# ۴) وب‌اپ
cd flutter_app && flutter pub get && flutter run -d chrome --dart-define=RAVAN_API_URL=http://localhost:8000/api
```

یا با Docker:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build
make test        # همهٔ تست‌ها به‌علاوهٔ محک دقت و بررسی تنظیمات
make accuracy    # فقط محک دقت
make check-env   # فقط بررسی رسیدن تنظیمات .env به هر سرویس
```

## جریان یک جلسه تصویری

1. مراجع از فهرست عمومی (فقط درمانگرانِ تأییدشده توسط ادمین) یک نفر را انتخاب و نوبت می‌گیرد.
2. پیش از روشن شدن دوربین دو رضایت جدا نمایش داده می‌شود: «تماس تصویری» و «تحلیل خودکار حالات بدن و صدا». دومی اختیاری است و هر لحظه بدون قطع جلسه قابل توقف یا پس گرفتن است.
3. ویدئو از طریق WebRTC (SFU) فقط بین دو طرف رد و بدل می‌شود. **هیچ ویدئو یا صدای خامی ذخیره یا به سرور تحلیل ارسال نمی‌شود.** مرورگر مراجع با MediaPipe نقاط چهره/بدن/دست و ویژگی‌های صوتی را استخراج و فقط اعداد را می‌فرستد.
4. پنج دقیقه اول، خط پایه شخصی (میانه/MAD به‌تفکیک حالت گوینده) ساخته می‌شود. سپس هر سیگنال فقط نسبت به همان فرد در همان جلسه سنجیده می‌شود.
5. درمانگر روی تایم‌لاین کنار تصویر، کارت‌هایی مثل این می‌بیند:

```
21:16:42   تغییر نسبت به خط پایه   اطمینان ۸۶٪
تأخیر پاسخ طولانی‌تر از خط پایه
خط پایه: 1.4 s   فعلی: 4.7 s   (×3.4)   z=2.9
کیفیت تشخیص: audio=0.95، asr=0.88
حدود ۰ ثانیه پس از سؤال: «رابطه شما با خانواده چطور است؟»
زمینه‌های محتمل (نه نتیجه‌گیری): فکر کردن · تأخیر شبکه · حواس‌پرتی · نوع سؤال · پاسخ مرتبط با موضوع
[مرتبط است] [رد] [یادداشت] [متن این لحظه]
```

6. رونویسی با تفکیک گوینده در کنار رویدادها ذخیره می‌شود و هر رویداد به بخش متن مربوط پیوند می‌خورد.
7. در پایان، گزارش ساخت‌یافته + پیش‌نویس خلاصه (عبور داده‌شده از نگهبان زبانی) به درمانگر داده می‌شود که هر مورد را **تأیید / ویرایش / رد** می‌کند؛ فقط متن درمانگر وارد پرونده می‌شود.

## آنچه سامانه عمداً انجام نمی‌دهد

**تشخیص خودکار**: دستیار هوش مصنوعی فرضیه‌های افتراقی با شواهد له و علیه پیشنهاد می‌دهد؛ تشخیص فقط با ثبت یا تأیید درمانگر وارد پرونده می‌شود. **خواندن افکار**: به‌جای آن، *نحوه* پاسخ به هر سؤال (تأخیر، طول، اجتناب، بار واژگانی، تغییرات بدنی هم‌زمان) اندازه‌گیری و با تفسیرهای محتمل نمایش داده می‌شود. همچنین: تشخیص دروغ، تشخیص اختلال از ویدئو/صدا، تخمین خطر خودکشی از چهره/بدن (فقط عبارات صریح کلامی به متن ارجاع داده می‌شود)، طبقه‌بندی هیجان، «ریزحالت»، لبخند واقعی/ساختگی، مقایسه با هنجار جمعیت، و نوشتن خودکار در پرونده بالینی. فهرست کامل در `catalog/signal_catalog.json → forbidden_inferences`.

## مستندات

- [۰۱ معماری](docs/01-architecture.md) · [۰۲ پایگاه داده](docs/02-database.md) · [۰۳ API](docs/03-api.md) · [۰۴ خط لوله زنده](docs/04-realtime-pipeline.md)
- [۰۵ راهنمای کاتالوگ سیگنال‌ها](docs/05-behavior-catalog.md) · [۰۶ حریم خصوصی و امنیت](docs/06-privacy-security.md) · [۰۷ ارائه‌دهنده هوش مصنوعی](docs/07-ai-provider.md) · [۰۸ نقشه راه](docs/08-roadmap.md) · [۰۹ سه‌زبانه بودن](docs/09-i18n.md) · [۱۰ پرونده بالینی، دستیار هوش مصنوعی، ASR](docs/10-clinical-record-and-ai.md)
- **[۱۱ نصب روی سرور](docs/11-deployment.md)** · **[۱۲ دقت: چه چیزی اندازه‌گیری شده و چه چیزی ادعا نمی‌شود](docs/12-accuracy.md)**
