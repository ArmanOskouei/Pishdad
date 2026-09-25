<?php

namespace Tests\Unit;

use App\Services\RevalidateSigner;
use Tests\TestCase;

class RevalidateSignerTest extends TestCase
{
    private function signer(string $secret = 'test-secret', int $leeway = 300): RevalidateSigner
    {
        return new RevalidateSigner($secret, $leeway);
    }

    public function test_sign_then_verify_roundtrip(): void
    {
        $signer = $this->signer();

        $payload = $signer->sign(['pages', 'home']);

        $this->assertArrayHasKey('signature', $payload);
        $this->assertTrue($signer->verify($payload));
    }

    public function test_verify_fails_with_wrong_secret(): void
    {
        $payload = $this->signer('correct')->sign(['pages']);

        $this->assertFalse($this->signer('wrong')->verify($payload));
    }

    public function test_verify_fails_when_tags_tampered(): void
    {
        $signer = $this->signer();
        $payload = $signer->sign(['pages']);
        $payload['tags'][] = 'admin';

        $this->assertFalse($signer->verify($payload));
    }

    public function test_verify_fails_when_timestamp_expired(): void
    {
        $signer = $this->signer();
        $payload = $signer->sign(['pages'], time() - 3600);

        $this->assertFalse($signer->verify($payload));
    }

    public function test_nonce_is_single_use_replay_rejected(): void
    {
        $signer = $this->signer();
        $payload = $signer->sign(['pages']);

        $this->assertTrue($signer->verify($payload));
        $this->assertFalse($signer->verify($payload)); // replay
    }

    public function test_verify_fails_on_malformed_payload(): void
    {
        $this->assertFalse($this->signer()->verify(['tags' => ['x']]));
    }
}
