<?php

namespace Database\Seeders;

use App\Models\ConsentVersion;
use Illuminate\Database\Seeder;

class ConsentTextSeeder extends Seeder
{
    public function run(): void
    {
        $texts = [
            ['type' => 'video_call', 'locale' => 'fa', 'title' => 'اجازه استفاده از دوربین و میکروفون برای تماس تصویری',
                'body' => 'با فعال کردن این گزینه، تصویر و صدای شما فقط برای درمانگر شما در همین جلسه پخش می‌شود. تصویر و صدای خام ذخیره نمی‌شود. می‌توانید هر زمان دوربین را خاموش کنید.',
                'bullet_points' => ['پخش زنده فقط برای درمانگر', 'بدون ذخیره ویدئو یا صدای خام', 'قابل خاموش کردن در هر لحظه']],
            ['type' => 'behavior_analysis', 'locale' => 'fa', 'title' => 'اجازه تحلیل خودکار حالات بدن و صدا در طول جلسه',
                'body' => 'اگر موافقت کنید، نرم‌افزار روی دستگاه شما فقط اعداد توصیفی (مثلاً جهت سر، وضعیت بدن، نرخ گفتار) را استخراج می‌کند و به سامانه می‌فرستد. هیچ تصویر یا صدای خامی ارسال یا ذخیره نمی‌شود. این اعداد فقط با رفتار خودتان در همین جلسه مقایسه می‌شوند و فقط به درمانگر شما به‌عنوان «مشاهده» نشان داده می‌شوند، نه به‌عنوان تشخیص. شما می‌توانید این تحلیل را در هر لحظه بدون قطع جلسه متوقف کنید و با پس گرفتن رضایت، داده‌های مشتق‌شده این جلسه حذف می‌شوند.',
                'bullet_points' => ['فقط اعداد توصیفی، نه ویدئو', 'مقایسه فقط با خود شما در همین جلسه', 'فقط برای درمانگر و فقط به‌عنوان مشاهده', 'توقف در هر لحظه بدون قطع جلسه', 'حذف داده‌ها با پس گرفتن رضایت']],
            ['type' => 'transcription', 'locale' => 'fa', 'title' => 'اجازه رونویسی متنی گفت‌وگو',
                'body' => 'با موافقت شما، گفت‌وگو به متن تبدیل و برای شما و درمانگرتان نگهداری می‌شود تا بتوانید به آن مراجعه کنید. متن مطابق سیاست نگهداری پس از مدت مشخص حذف می‌شود.',
                'bullet_points' => ['متن برای شما و درمانگر قابل مشاهده است', 'حذف طبق سیاست نگهداری']],
            ['type' => 'video_call', 'locale' => 'en', 'title' => 'Camera and microphone for the video call',
                'body' => 'Your video and audio are streamed only to your clinician for this session. Raw video and audio are not stored. You can turn the camera off at any time.',
                'bullet_points' => ['Live only to your clinician', 'No raw video/audio stored', 'Can be switched off any time']],
            ['type' => 'behavior_analysis', 'locale' => 'en', 'title' => 'Automatic analysis of body and voice during the session',
                'body' => 'If you agree, software on your device extracts only descriptive numbers (e.g. head direction, posture, speech rate) and sends them to the platform. No raw image or audio is sent or stored. These numbers are compared only with your own behaviour in this session and shown only to your clinician as observations, never as a diagnosis. You can pause this at any moment without ending the call, and withdrawing consent deletes the derived data for this session.',
                'bullet_points' => ['Numbers only, never video', 'Compared only with yourself, this session', 'Clinician-only, observations not diagnoses', 'Pause any time without ending the call', 'Withdrawal deletes derived data']],
            ['type' => 'transcription', 'locale' => 'en', 'title' => 'Transcription of the conversation',
                'body' => 'With your agreement the conversation is transcribed and kept for you and your clinician to refer back to. It is deleted according to the retention policy.',
                'bullet_points' => ['Visible to you and your clinician', 'Deleted per retention policy']],
        ];
        foreach ($texts as $t) {
            ConsentVersion::updateOrCreate(
                ['type' => $t['type'], 'version' => config('ravan.consent.current_versions')[$t['type']], 'locale' => $t['locale']],
                $t + ['is_current' => true],
            );
        }
    }
}
