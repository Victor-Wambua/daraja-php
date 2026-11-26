<?php

declare(strict_types=1);

namespace Daraja\Support;

use Daraja\Exception\ConfigurationException;

/**
 * Builds the SecurityCredential that B2C, Transaction Status and Reversal need:
 * the initiator password, RSA-encrypted (PKCS#1 v1.5) with Safaricom's public certificate, then base64.
 *
 * Download the certificate that matches your environment from the Daraja portal. It is not bundled
 * here. Encrypt once at deploy time and store the result as DARAJA_SECURITY_CREDENTIAL.
 */
final class SecurityCredential
{
    public static function fromInitiatorPassword(#[\SensitiveParameter] string $password, string $certificatePem): string
    {
        if (!\extension_loaded('openssl')) {
            throw new ConfigurationException('ext-openssl is required to build a SecurityCredential.');
        }
        $key = openssl_pkey_get_public($certificatePem);
        if ($key === false) {
            throw new ConfigurationException('The certificate is not a valid PEM public key or X.509 certificate.');
        }
        $encrypted = null;
        if (!openssl_public_encrypt($password, $encrypted, $key, OPENSSL_PKCS1_PADDING) || !\is_string($encrypted)) {
            throw new ConfigurationException('Could not encrypt the initiator password with the supplied certificate.');
        }

        return base64_encode($encrypted);
    }
}
