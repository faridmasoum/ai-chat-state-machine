<?php

/**
 * The whole backend for the widget in plain PHP: no framework needed.
 *
 *   php -S localhost:8000          (run from the package root)
 *   open http://localhost:8000/examples/plain-php/index.html
 */

require __DIR__ . '/../../vendor/autoload.php';

use FaridM\AiChatStateMachine\Flow;
use FaridM\AiChatStateMachine\Http\ChatEndpoint;
use FaridM\AiChatStateMachine\Http\FlowRegistry;
use FaridM\AiChatStateMachine\Steps;
use FaridM\AiChatStateMachine\Support\NativeSessionStore;

$flows = (new FlowRegistry())->register('lead', static function (?string $locale): Flow {
    // The widget sends the visitor's chosen language, so the questions can follow it.
    $t = [
        'fa' => [
            'name' => 'نام شما چیست؟', 'email' => 'ایمیل شما؟', 'plan' => 'کدام پلن را می‌خواهید؟',
            'topics' => 'به چه چیزی علاقه دارید؟', 'phone' => 'شماره تلفن (اختیاری)', 'note' => 'توضیحات بیشتر (اختیاری)',
            'plans' => ['starter' => 'پایه', 'pro' => 'حرفه‌ای', 'enterprise' => 'سازمانی'],
            'interests' => ['API', 'قیمت‌ها', 'پشتیبانی', 'امنیت'],
        ],
        'ar' => [
            'name' => 'ما اسمك؟', 'email' => 'بريدك الإلكتروني؟', 'plan' => 'أي خطة تريد؟',
            'topics' => 'ما اهتماماتك؟', 'phone' => 'رقم الهاتف (اختياري)', 'note' => 'ملاحظات إضافية (اختياري)',
            'plans' => ['starter' => 'أساسية', 'pro' => 'احترافية', 'enterprise' => 'للشركات'],
            'interests' => ['واجهة البرمجة', 'الأسعار', 'الدعم', 'الأمان'],
        ],
        'en' => [
            'name' => "What's your name?", 'email' => 'Your email?', 'plan' => 'Which plan are you interested in?',
            'topics' => 'What are you interested in?', 'phone' => 'Phone number (optional)', 'note' => 'Anything else? (optional)',
            'plans' => ['starter' => 'Starter', 'pro' => 'Pro', 'enterprise' => 'Enterprise'],
            'interests' => ['API', 'Pricing', 'Support', 'Security'],
        ],
    ][in_array(substr((string) $locale, 0, 2), ['fa', 'ar'], true) ? substr((string) $locale, 0, 2) : 'en'];

    return Flow::make()
        ->add(Steps::text('name', $t['name']))
        ->add(Steps::email('email', $t['email']))
        ->add(Steps::choice('plan', $t['plan'], $t['plans']))
        ->add(Steps::multiChoice('topics', $t['topics'], $t['interests']))
        ->add(Steps::phone('phone', $t['phone'])->optional())
        ->add(Steps::text('note', $t['note'], ['multiline' => true])->optional())
        ->onComplete(static function (array $answers): void {
            // Save to a database, send an email, call your CRM...
            file_put_contents(sys_get_temp_dir() . '/ai-chat-leads.jsonl', json_encode($answers, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        });
});

(new ChatEndpoint($flows, new NativeSessionStore()))->serve();
