<?php

namespace Tests\Feature;

use App\Models\ContentImportMap;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-C6 — برون‌بری/درون‌بری محتوا.
 * DoD: JSON شامل صفحات+تنظیمات، WXR معتبر حاوی نوشته، درون‌بری با نگاشت بلوک،
 * idempotency با جدول نگاشت، و اجبارِ دسترسی.
 */
class ContentExportImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(array $perms): User
    {
        $user = User::query()->create([
            'name' => 'مدیر محتوا',
            'email' => 'ci'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo(...$perms);

        return $user->fresh();
    }

    private function page(User $user, string $slug, array $blocks, string $status = 'draft', bool $isSingle = false): Page
    {
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'عنوان '.$slug,
            'slug' => $slug,
            'locale' => 'fa',
            'status' => $status,
            'is_single' => $isSingle,
            'blocks' => $blocks,
            'meta' => ['description' => 'توضیح '.$slug],
        ]);
        $revision = $page->snapshot($blocks, $page->meta, $user->id, 'ایجاد');
        if ($status === 'published') {
            $page->forceFill(['published_revision_id' => $revision->id, 'published_at' => now()])->save();
        }

        return $page;
    }

    private function wxr(string $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8" ?>'
            .'<rss version="2.0" '
            .'xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" '
            .'xmlns:content="http://purl.org/rss/1.0/modules/content/" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            .'xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>سایت تست</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$items
            .'</channel></rss>';
    }

    private function item(int $id, string $type, string $title, string $slug, string $content, string $status = 'publish', string $creator = 'admin'): string
    {
        return '<item>'
            .'<title>'.htmlspecialchars($title, ENT_XML1).'</title>'
            .'<dc:creator>'.htmlspecialchars($creator, ENT_XML1).'</dc:creator>'
            .'<content:encoded><![CDATA['.$content.']]></content:encoded>'
            .'<wp:post_id>'.$id.'</wp:post_id>'
            .'<wp:post_name>'.htmlspecialchars($slug, ENT_XML1).'</wp:post_name>'
            .'<wp:status>'.$status.'</wp:status>'
            .'<wp:post_type>'.$type.'</wp:post_type>'
            .'</item>';
    }

    public function test_json_export_contains_pages_revisions_and_settings(): void
    {
        $user = $this->user(['pages.edit', 'settings.edit']);
        Setting::set('site', 'global', ['title' => 'سایت من', 'site_url' => 'https://example.ir']);
        $this->page($user, 'home', [['type' => 'text', 'data' => ['body' => 'سلام']]], 'published', true);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/content/export/json');

        $res->assertOk();
        $this->assertSame('pishdad-content-bundle', $res->json('format'));
        $this->assertSame('home', $res->json('pages.0.slug'));
        $this->assertSame('text', $res->json('pages.0.blocks.0.type'));
        $this->assertSame(1, $res->json('pages.0.revisions.0.version'));
        $this->assertSame('سایت من', $res->json('settings.site.global.title'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_wxr_export_is_well_formed_and_contains_post(): void
    {
        $user = $this->user(['settings.edit']);
        Setting::set('site', 'global', ['title' => 'سایت من', 'site_url' => 'https://example.ir']);
        $this->page($user, 'about', [['type' => 'hero', 'data' => ['title' => 'درباره ما']]], 'published', true);

        $res = $this->actingAs($user, 'sanctum')->get('/api/v1/admin/content/export/wxr');

        $res->assertOk();
        $this->assertStringContainsString('application/xml', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));

        $xml = simplexml_load_string((string) $res->getContent());
        $this->assertNotFalse($xml, 'WXR باید XMLِ معتبر باشد.');

        $xml->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');
        $xml->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $items = $xml->xpath('//item');
        $this->assertNotEmpty($items);
        $this->assertSame('عنوان about', (string) $items[0]->title);
        $this->assertSame('about', (string) $items[0]->children('http://wordpress.org/export/1.2/')->post_name);
        $this->assertStringContainsString('درباره ما', (string) $items[0]->children('http://purl.org/rss/1.0/modules/content/')->encoded);
    }

    public function test_import_creates_pages_with_mapped_blocks(): void
    {
        $user = $this->user(['settings.edit']);
        $xml = $this->wxr(
            $this->item(1, 'page', 'درباره ما', 'about', '<p>سلام دنیا</p><blockquote>نقل قول</blockquote>')
            .$this->item(2, 'post', 'اولین نوشته', 'first-post', '<p>متن نوشته</p>')
        );

        $res = $this->actingAs($user, 'sanctum')->post('/api/v1/admin/content/import/wxr', [
            'file' => UploadedFile::fake()->createWithContent('import.xml', $xml),
        ]);

        $res->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.errors', 0);

        $page = Page::query()->where('slug', 'about')->firstOrFail();
        $this->assertSame('published', $page->status);
        $this->assertTrue($page->is_single);
        $types = array_column($page->blocks, 'type');
        $this->assertContains('text', $types);
        $this->assertContains('quote', $types);

        $post = Page::query()->where('slug', 'first-post')->firstOrFail();
        $this->assertFalse($post->is_single);
        $this->assertSame('text', $post->blocks[0]['type']);
        $this->assertStringContainsString('متن نوشته', $post->blocks[0]['data']['body']);

        $this->assertDatabaseHas('content_import_maps', [
            'source' => 'wxr',
            'source_id' => 'post:1',
            'target_type' => 'page',
            'target_id' => $page->id,
        ]);
    }

    public function test_reimport_is_idempotent_via_mapping_table(): void
    {
        $user = $this->user(['settings.edit']);
        $xml = $this->wxr($this->item(7, 'page', 'تماس', 'contact', '<p>تماس با ما</p>'));

        $first = $this->actingAs($user, 'sanctum')->post('/api/v1/admin/content/import/wxr', [
            'file' => UploadedFile::fake()->createWithContent('a.xml', $xml),
        ]);
        $first->assertOk()->assertJsonPath('data.created', 1);

        $second = $this->actingAs($user, 'sanctum')->post('/api/v1/admin/content/import/wxr', [
            'file' => UploadedFile::fake()->createWithContent('b.xml', $xml),
        ]);
        $second->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, Page::query()->where('slug', 'contact')->count());
        $this->assertSame(1, ContentImportMap::query()
            ->where('source', 'wxr')
            ->where('source_id', 'post:7')
            ->count());
    }

    public function test_import_rejects_non_wxr_payload(): void
    {
        $user = $this->user(['settings.edit']);

        $this->actingAs($user, 'sanctum')->post('/api/v1/admin/content/import/wxr', [
            'file' => UploadedFile::fake()->createWithContent('bad.xml', '<hello>world</hello>'),
        ])->assertStatus(422);
    }

    public function test_export_and_import_permission_enforced(): void
    {
        $viewer = $this->user(['pages.view']);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/admin/content/export/json')->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')->get('/api/v1/admin/content/export/wxr')->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')->post('/api/v1/admin/content/import/wxr', [
            'file' => UploadedFile::fake()->createWithContent('x.xml', $this->wxr('')),
        ])->assertStatus(403);
    }
}
