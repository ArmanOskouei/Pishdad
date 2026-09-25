<?php

namespace Tests\Unit;

use App\Services\Sessions\SessionDeviceParser;
use PHPUnit\Framework\TestCase;

class SessionDeviceParserTest extends TestCase
{
    public function test_chrome_on_windows(): void
    {
        $parsed = SessionDeviceParser::parse(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        $this->assertSame('Windows', $parsed['os']);
        $this->assertSame('10.0', $parsed['os_version']);
        $this->assertSame('Chrome', $parsed['browser']);
        $this->assertSame('120.0.0.0', $parsed['browser_version']);
        $this->assertSame('Chrome روی Windows', $parsed['device']);
        $this->assertSame('دسکتاپ', $parsed['device_type']);
    }

    public function test_safari_on_iphone_is_mobile(): void
    {
        $parsed = SessionDeviceParser::parse(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1'
        );

        $this->assertSame('iOS', $parsed['os']);
        $this->assertSame('17.2', $parsed['os_version']);
        $this->assertSame('Safari', $parsed['browser']);
        $this->assertSame('Safari روی iOS', $parsed['device']);
        $this->assertSame('موبایل', $parsed['device_type']);
    }

    public function test_firefox_on_linux_is_named(): void
    {
        $parsed = SessionDeviceParser::parse(
            'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'
        );

        $this->assertSame('Linux', $parsed['os']);
        $this->assertSame('Firefox', $parsed['browser']);
        $this->assertSame('Firefox روی Linux', $parsed['device']);
    }

    public function test_empty_user_agent_is_unknown_without_error(): void
    {
        $parsed = SessionDeviceParser::parse(null);

        $this->assertNull($parsed['os']);
        $this->assertNull($parsed['browser']);
        $this->assertSame('نامشخص', $parsed['device']);
        $this->assertSame('نامشخص', $parsed['device_type']);
    }
}
