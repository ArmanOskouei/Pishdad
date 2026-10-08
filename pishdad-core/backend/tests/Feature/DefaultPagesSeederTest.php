<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\DefaultPagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** کار دوم — سیدر صفحات پیش‌فرض: خنثی، draft، idempotent. */
class DefaultPagesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_three_neutral_draft_pages(): void
    {
        User::query()->create([
            'name' => 'ادمین', 'email' => 'admin@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);

        $this->artisan('db:seed', ['--class' => DefaultPagesSeeder::class])->assertOk();

        foreach (['home', 'about', 'contact'] as $slug) {
            $page = Page::query()->where('slug', $slug)->first();
            $this->assertNotNull($page, "missing page: {$slug}");
            $this->assertSame('draft', $page->status);
            $this->assertNull($page->published_revision_id);
            $this->assertNotEmpty($page->blocks);
        }

        $contact = Page::query()->where('slug', 'contact')->first();
        $types = collect($contact->blocks)->pluck('type')->all();
        $this->assertContains('contact-form', $types);
    }

    public function test_rerun_is_idempotent_and_keeps_published(): void
    {
        $this->artisan('db:seed', ['--class' => DefaultPagesSeeder::class])->assertOk();
        $this->artisan('db:seed', ['--class' => DefaultPagesSeeder::class])->assertOk();

        $this->assertSame(3, Page::query()->whereIn('slug', ['home', 'about', 'contact'])->count());

        // صفحه منتشرشده توسط مدیر نباید بازنویسی شود.
        $home = Page::query()->where('slug', 'home')->first();
        $home->forceFill(['status' => 'published', 'title' => 'خانه ما'])->save();

        $this->artisan('db:seed', ['--class' => DefaultPagesSeeder::class])->assertOk();

        $this->assertSame('خانه ما', $home->fresh()->title);
        $this->assertSame(3, Page::query()->whereIn('slug', ['home', 'about', 'contact'])->count());
    }
}
