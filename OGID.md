# `.ogid` support

The PHP driver supports the encrypted identity v2 format produced by `og-core`.

Crypto is provided entirely by the already-required `ext-sodium`:

- Argon2id v1.3, 64 MiB, 3 iterations
- XChaCha20-Poly1305
- AAD: `og.identity.v2`
- MessagePack encrypted payload

No Composer dependency was added.

Example:

```php
$client = new GlacierDB\Client(
    identity: 'admin.ogid',
    identityPassword: getenv('OG_IDENTITY_PASSWORD') ?: null,
);
$client->connect();
```

The password is only needed while constructing/loading the identity. The client keeps the
resulting Ed25519 credential in memory and does not rerun Argon2id on reconnects.
