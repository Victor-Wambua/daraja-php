<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Services;

use Daraja\Exception\ConfigurationException;
use Daraja\Support\SecurityCredential;
use PHPUnit\Framework\TestCase;

final class SecurityCredentialTest extends TestCase
{
    public function testEncryptsWithPublicKeyAndOnlyThePrivateKeyCanRead(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            self::markTestSkipped('OpenSSL could not generate a key on this machine.');
        }
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        $credential = SecurityCredential::fromInitiatorPassword('initiator-password', $details['key']);
        $raw = base64_decode($credential, true);
        self::assertIsString($raw);
        self::assertSame(256, \strlen($raw));

        $plain = '';
        self::assertTrue(openssl_private_decrypt($raw, $plain, $key, OPENSSL_PKCS1_PADDING));
        self::assertSame('initiator-password', $plain);
    }

    public function testRejectsAnInvalidCertificate(): void
    {
        $this->expectException(ConfigurationException::class);
        SecurityCredential::fromInitiatorPassword('x', 'not a certificate');
    }
}
