<?php

namespace Database\Seeders;

use App\Models\DiagnosisCode;
use Illuminate\Database\Seeder;

/**
 * Common ICD-11 chapter 06 (mental, behavioural or neurodevelopmental disorders) codes with
 * trilingual labels, for clinician selection. Extend with the full code set in production.
 */
class DiagnosisCodeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['6A70', 'Single episode depressive disorder', 'اختلال افسردگی تک‌دوره‌ای', 'Tek epizod depresif bozukluk', 'mood'],
            ['6A71', 'Recurrent depressive disorder', 'اختلال افسردگی عودکننده', 'Yineleyici depresif bozukluk', 'mood'],
            ['6A72', 'Dysthymic disorder', 'اختلال دیس‌تایمی', 'Distimik bozukluk', 'mood'],
            ['6A60', 'Bipolar type I disorder', 'اختلال دوقطبی نوع یک', 'Bipolar tip I bozukluk', 'mood'],
            ['6A61', 'Bipolar type II disorder', 'اختلال دوقطبی نوع دو', 'Bipolar tip II bozukluk', 'mood'],
            ['6B00', 'Generalised anxiety disorder', 'اختلال اضطراب فراگیر', 'Yaygın anksiyete bozukluğu', 'anxiety'],
            ['6B01', 'Panic disorder', 'اختلال پانیک', 'Panik bozukluğu', 'anxiety'],
            ['6B02', 'Agoraphobia', 'آگورافوبیا', 'Agorafobi', 'anxiety'],
            ['6B03', 'Specific phobia', 'فوبیای خاص', 'Özgül fobi', 'anxiety'],
            ['6B04', 'Social anxiety disorder', 'اختلال اضطراب اجتماعی', 'Sosyal anksiyete bozukluğu', 'anxiety'],
            ['6B05', 'Separation anxiety disorder', 'اختلال اضطراب جدایی', 'Ayrılma anksiyetesi bozukluğu', 'anxiety'],
            ['6B20', 'Obsessive-compulsive disorder', 'اختلال وسواسی-جبری', 'Obsesif-kompulsif bozukluk', 'ocd'],
            ['6B40', 'Post traumatic stress disorder', 'اختلال استرس پس از سانحه', 'Travma sonrası stres bozukluğu', 'trauma'],
            ['6B41', 'Complex post traumatic stress disorder', 'اختلال استرس پس از سانحه پیچیده', 'Karmaşık travma sonrası stres bozukluğu', 'trauma'],
            ['6B43', 'Adjustment disorder', 'اختلال سازگاری', 'Uyum bozukluğu', 'trauma'],
            ['6A20', 'Schizophrenia', 'اسکیزوفرنی', 'Şizofreni', 'psychosis'],
            ['6A25', 'Schizoaffective disorder', 'اختلال اسکیزوافکتیو', 'Şizoaffektif bozukluk', 'psychosis'],
            ['6B80', 'Anorexia nervosa', 'بی‌اشتهایی عصبی', 'Anoreksiya nervoza', 'eating'],
            ['6B81', 'Bulimia nervosa', 'پرخوری عصبی', 'Bulimiya nervoza', 'eating'],
            ['6B82', 'Binge eating disorder', 'اختلال پرخوری', 'Tıkınırcasına yeme bozukluğu', 'eating'],
            ['7A00', 'Chronic insomnia', 'بی‌خوابی مزمن', 'Kronik insomni', 'sleep'],
            ['6C40', 'Disorders due to use of alcohol', 'اختلالات ناشی از مصرف الکل', 'Alkol kullanımına bağlı bozukluklar', 'substance'],
            ['6C41', 'Disorders due to use of cannabis', 'اختلالات ناشی از مصرف حشیش', 'Kenevir kullanımına bağlı bozukluklar', 'substance'],
            ['6C43', 'Disorders due to use of opioids', 'اختلالات ناشی از مصرف مواد افیونی', 'Opioid kullanımına bağlı bozukluklar', 'substance'],
            ['6A05', 'Attention deficit hyperactivity disorder', 'اختلال نقص توجه/بیش‌فعالی', 'Dikkat eksikliği hiperaktivite bozukluğu', 'neurodevelopmental'],
            ['6A02', 'Autism spectrum disorder', 'اختلال طیف اوتیسم', 'Otizm spektrum bozukluğu', 'neurodevelopmental'],
            ['6D10', 'Personality disorder', 'اختلال شخصیت', 'Kişilik bozukluğu', 'personality'],
            ['6E20', 'Mental or behavioural disorders associated with pregnancy, childbirth or the puerperium', 'اختلالات روانی مرتبط با بارداری و زایمان', 'Gebelik, doğum veya lohusalıkla ilişkili ruhsal bozukluklar', 'perinatal'],
            ['QE', 'No diagnosis / observation only', 'بدون تشخیص / فقط مشاهده', 'Tanı yok / yalnızca gözlem', 'none'],
        ];
        foreach ($rows as [$code, $en, $fa, $tr, $cat]) {
            DiagnosisCode::updateOrCreate(['system' => 'icd11', 'code' => $code], ['label_en' => $en, 'label_fa' => $fa, 'label_tr' => $tr, 'category' => $cat]);
        }
    }
}
