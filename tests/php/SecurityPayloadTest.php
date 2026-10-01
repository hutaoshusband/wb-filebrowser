<?php

declare(strict_types=1);

namespace WbFileBrowser\Tests;

use WbFileBrowser\Security;
use WbFileBrowser\Tests\Support\DatabaseTestCase;
use RuntimeException;

final class SecurityPayloadTest extends DatabaseTestCase
{
    public function testSignPayloadCreatesValidSignature(): void
    {
        $payload = ['user_id' => 123, 'role' => 'admin'];
        $token = Security::signPayload($payload);

        $this->assertIsString($token);

        $parts = explode('.', $token);
        $this->assertCount(2, $parts);

        // Ensure the body part is valid base64url encoded JSON
        $body = $parts[0];
        $json = base64_decode(strtr($body, '-_', '+/'), true);
        $this->assertIsString($json);
        $decodedPayload = json_decode($json, true);

        $this->assertEquals($payload, $decodedPayload);
    }

    public function testVerifySignedPayloadSuccessfullyDecodesValidToken(): void
    {
        // Must include expires_at for verifySignedPayload
        $payload = ['user_id' => 456, 'expires_at' => time() + 3600];
        $token = Security::signPayload($payload);

        $verifiedPayload = Security::verifySignedPayload($token);
        $this->assertEquals($payload, $verifiedPayload);
    }

    public function testVerifySignedPayloadThrowsExceptionForTamperedPayload(): void
    {
        $payload = ['user_id' => 789, 'expires_at' => time() + 3600];
        $token = Security::signPayload($payload);

        $parts = explode('.', $token);

        // Tamper the payload part
        $tamperedPayload = ['user_id' => 999, 'expires_at' => time() + 3600];
        $tamperedBody = rtrim(strtr(base64_encode((string) json_encode($tamperedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');

        $tamperedToken = $tamperedBody . '.' . $parts[1];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shared file not found.');

        Security::verifySignedPayload($tamperedToken);
    }

    public function testVerifySignedPayloadThrowsExceptionForMalformedToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shared file not found.');

        // No dot in token
        Security::verifySignedPayload('invalidtokenstring');
    }

    public function testVerifySignedPayloadThrowsExceptionForExpiredToken(): void
    {
        // Set expiry in the past
        $payload = ['user_id' => 111, 'expires_at' => time() - 3600];
        $token = Security::signPayload($payload);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shared file not found.');

        Security::verifySignedPayload($token);
    }

    public function testVerifySignedPayloadThrowsExceptionForMissingExpiry(): void
    {
        // No expires_at
        $payload = ['user_id' => 222];
        $token = Security::signPayload($payload);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shared file not found.');

        Security::verifySignedPayload($token);
    }
}
