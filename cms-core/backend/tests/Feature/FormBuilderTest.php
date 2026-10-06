<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-H10 — فرم‌ساز: CRUD، ثبت پاسخ عمومی، خروجی CSV و اعمال پرمیشن.
 */
class FormBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    /** @param list<string> $permissions */
    private function user(array $permissions = ['forms.view', 'forms.edit', 'forms.delete']): User
    {
        $user = User::query()->create([
            'name' => 'مدیر فرم',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function fields(): array
    {
        return [
            ['key' => 'name', 'label' => 'نام', 'type' => 'text', 'required' => true, 'max_length' => 150],
            ['key' => 'email', 'label' => 'ایمیل', 'type' => 'email', 'required' => true],
            ['key' => 'message', 'label' => 'پیام', 'type' => 'textarea', 'required' => true, 'max_length' => 2000],
            ['key' => 'topic', 'label' => 'موضوع', 'type' => 'select', 'required' => false, 'options' => ['فروش', 'پشتیبانی']],
        ];
    }

    private function makeForm(array $overrides = []): Form
    {
        return Form::query()->create(array_merge([
            'name' => 'فرم تست',
            'slug' => 'test-form',
            'fields' => $this->fields(),
            'destination' => Form::DEST_TICKET,
            'active' => true,
        ], $overrides));
    }

    public function test_admin_can_create_list_update_and_delete_a_form(): void
    {
        $user = $this->user();

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/forms', [
            'name' => 'فرم تماس',
            'slug' => 'contact-test',
            'fields' => $this->fields(),
            'destination' => 'email',
            'recipients' => ['team@example.com'],
            'success_message' => 'ممنون',
        ]);

        $created->assertCreated()->assertJsonPath('data.slug', 'contact-test');
        $form = Form::query()->where('slug', 'contact-test')->firstOrFail();
        $this->assertSame(Form::DEST_EMAIL, $form->destination);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/forms')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'contact-test');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/admin/forms/{$form->id}", ['name' => 'فرم تازه', 'fields' => $this->fields()])
            ->assertOk()
            ->assertJsonPath('data.name', 'فرم تازه');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/forms/{$form->id}")
            ->assertOk();

        $this->assertDatabaseMissing('forms', ['id' => $form->id]);
    }

    public function test_duplicate_field_keys_are_rejected(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/v1/admin/forms', [
                'name' => 'تکراری',
                'slug' => 'dupe-keys',
                'fields' => [
                    ['key' => 'name', 'label' => 'الف', 'type' => 'text'],
                    ['key' => 'name', 'label' => 'ب', 'type' => 'text'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields');
    }

    public function test_public_submit_stores_submission_and_creates_ticket(): void
    {
        $form = $this->makeForm();

        $res = $this->postJson('/api/v1/site/forms/test-form/submit', [
            'data' => [
                'name' => 'زهرا',
                'email' => 'zahra@example.com',
                'message' => 'سلام',
                'topic' => 'فروش',
            ],
        ]);

        $res->assertCreated()->assertJsonPath('message', 'پاسخ شما ثبت شد. سپاسگزاریم.');

        $this->assertDatabaseCount('form_submissions', 1);
        $submission = FormSubmission::query()->firstOrFail();
        $this->assertSame($form->id, $submission->form_id);
        $this->assertSame('زهرا', $submission->payload['name']);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame('فرم تست', Ticket::query()->firstOrFail()->subject);
    }

    public function test_public_submit_enforces_required_and_select_values(): void
    {
        $this->makeForm();

        $this->postJson('/api/v1/site/forms/test-form/submit', [
            'data' => ['email' => 'bad-email', 'message' => 'x'],
        ])->assertStatus(422)->assertJsonValidationErrors(['data.name', 'data.email']);
    }

    public function test_inactive_form_is_not_public(): void
    {
        $this->makeForm(['active' => false]);

        $this->getJson('/api/v1/site/forms/test-form')->assertNotFound();
        $this->postJson('/api/v1/site/forms/test-form/submit', ['data' => []])->assertNotFound();
    }

    public function test_csv_export_contains_submissions(): void
    {
        $form = $this->makeForm(['destination' => Form::DEST_EMAIL, 'recipients' => ['team@example.com']]);
        $this->postJson('/api/v1/site/forms/test-form/submit', [
            'data' => ['name' => 'رضا', 'email' => 'reza@example.com', 'message' => 'متن'],
        ])->assertCreated();

        $res = $this->actingAs($this->user(), 'sanctum')
            ->get("/api/v1/admin/forms/{$form->id}/submissions.csv");

        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $csv = $res->streamedContent();
        $this->assertStringContainsString('نام', $csv);
        $this->assertStringContainsString('رضا', $csv);
    }

    public function test_read_requires_permission_and_write_requires_edit_permission(): void
    {
        $form = $this->makeForm();

        $this->getJson('/api/v1/admin/forms')->assertUnauthorized();

        $viewerOnly = $this->user(['forms.view']);
        $this->actingAs($viewerOnly, 'sanctum')->getJson('/api/v1/admin/forms')->assertOk();
        $this->actingAs($viewerOnly, 'sanctum')
            ->postJson('/api/v1/admin/forms', [
                'name' => 'ممنوع', 'slug' => 'nope', 'fields' => [['key' => 'a', 'label' => 'الف', 'type' => 'text']],
            ])
            ->assertForbidden();
        $this->actingAs($viewerOnly, 'sanctum')
            ->deleteJson("/api/v1/admin/forms/{$form->id}")
            ->assertForbidden();
    }

    public function test_blocks_schema_registers_form_block(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')->getJson('/api/v1/admin/blocks/schema');

        $res->assertOk();
        $byType = collect($res->json('data'))->keyBy('type');
        $this->assertArrayHasKey('form', $byType->all());
        $this->assertContains('slug', $byType['form']['schema']['required']);
    }
}
