<?php

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallJournal;
use Tests\TestCase;

/**
 * رفتارِ ریشه — همان چیزی که `routes/web.php` مستند می‌کند:
 *
 *   1) نصب‌نشده ⇒ ۳۰۲ به `/install` (دروازهٔ نصب‌کننده، عمدی).
 *   2) نصب‌شده  ⇒ ۲۰۰ با صفحهٔ «installed».
 *
 * پیش از این، تستِ اسکلتِ خامِ لاراول (`assertStatus(200)`) روی رفتارِ
 * درستِ اپ قرمز می‌شد — نه اپ خراب بود، انتظار کهنه بود.
 */
class ExampleTest extends TestCase
{
    public function test_the_root_redirects_to_the_installer_when_not_installed(): void
    {
        $this->get('/')->assertRedirect('/install');
    }

    public function test_the_root_serves_the_installed_page_once_installed(): void
    {
        InstallJournal::writeLock();

        $this->get('/')->assertOk();
    }
}
