<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * U90: an unauthenticated API request must answer 401 JSON, never hang.
 *
 * This path had no test and that is exactly why it survived. Every other test
 * in the suite authenticates first, so `Authenticate::unauthenticated()` never
 * ran for a guest. Laravel's default is
 * `redirectGuestsTo(fn () => route('login'))` (ApplicationBuilder.php:291) and
 * this backend has no `login` route — sign-in lives in the Next.js app — so the
 * default closure threw RouteNotFoundException from inside the middleware and
 * the request hung until the client gave up.
 *
 * The requests below deliberately send NO `Accept: application/json`. That is
 * what curl, wget and a browser address bar send, and it is the case that broke:
 * with the JSON header Laravel answered 401 correctly, so the real frontend
 * never saw the bug.
 */
class UnauthenticatedApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function protectedEndpointProvider(): array
    {
        return [
            'admin blocks schema' => ['/api/v1/admin/blocks/schema'],
            'admin dashboard stats' => ['/api/v1/admin/dashboard/stats'],
            'admin plugins' => ['/api/v1/admin/plugins'],
        ];
    }

    #[DataProvider('protectedEndpointProvider')]
    public function test_guest_gets_401_json_without_an_accept_header(string $uri): void
    {
        $this->get($uri, ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_guest_gets_401_when_sending_the_json_accept_header(): void
    {
        // The path the Next.js frontend actually takes — kept so a change to the
        // non-JSON branch cannot quietly break the working one.
        $this->getJson('/api/v1/admin/blocks/schema')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_guest_post_gets_401_too(): void
    {
        // A real protected POST route. An earlier draft used
        // /api/v1/admin/plugins, which is GET-only, so the middleware never ran
        // and the request died at routing with 405 — a green test that proved
        // nothing.
        $this->post('/api/v1/admin/managers', [], ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_public_endpoints_stay_reachable_for_guests(): void
    {
        // Guards against over-broadening the fix: if `redirectGuestsTo` or the
        // exception handler were allowed to swallow everything, a public route
        // would start returning 401 as well.
        $this->get('/up')->assertOk();
    }
}
