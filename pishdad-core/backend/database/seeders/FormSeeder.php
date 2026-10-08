<?php

namespace Database\Seeders;

use App\Models\Form;
use Illuminate\Database\Seeder;

/**
 * WF-H10 — فرم تماس پیش‌فرض، مهاجرت‌یافته از فرم تماس ثابت.
 * بلوک `contact-form` بدون `form_slug` به همین فرم (اسلاگ `contact`) وصل می‌شود.
 */
class FormSeeder extends Seeder
{
    public function run(): void
    {
        Form::query()->updateOrCreate(
            ['slug' => 'contact'],
            [
                'name' => 'فرم تماس',
                'destination' => Form::DEST_TICKET,
                'active' => true,
                'success_message' => 'پیام شما ثبت شد. به‌زودی پاسخ می‌دهیم.',
                'fields' => [
                    ['key' => 'name', 'label' => 'نام', 'type' => 'text', 'required' => true, 'max_length' => 150],
                    ['key' => 'email', 'label' => 'ایمیل', 'type' => 'email', 'required' => true],
                    ['key' => 'phone', 'label' => 'تلفن', 'type' => 'tel', 'required' => false],
                    ['key' => 'message', 'label' => 'متن پیام', 'type' => 'textarea', 'required' => true, 'max_length' => 5000],
                ],
            ],
        );
    }
}
