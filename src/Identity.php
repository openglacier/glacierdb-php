<?php

declare(strict_types=1);

namespace GlacierDB;

use InvalidArgumentException;

/** Legacy clear identity credential. Encrypted .ogid files are handled by Core operations. */
final class Identity
{
    public function __construct(
        public readonly int $version,
        public readonly string $identityId,
        public readonly string $deviceId,
        public readonly string $privateKey,
    ) {}

    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new InvalidArgumentException("Unable to read identity file: {$path}");
        }

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        foreach (['version', 'identityId', 'deviceId', 'privateKey'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Identity file is missing `{$field}`.");
            }
        }

        return new self(
            (int) $data['version'],
            (string) $data['identityId'],
            (string) $data['deviceId'],
            (string) $data['privateKey'],
        );
    }

    public function seed(): string
    {
        $der = base64_decode($this->privateKey, true);
        if ($der === false || strlen($der) < SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new InvalidArgumentException('Invalid Ed25519 PKCS#8 private key.');
        }

        // glacierdb bootstrap keys use an Ed25519 PKCS#8 DER value whose
        // trailing 32 bytes contain the seed, matching ogcli and the Python driver.
        return substr($der, -SODIUM_CRYPTO_SIGN_SEEDBYTES);
    }
}
