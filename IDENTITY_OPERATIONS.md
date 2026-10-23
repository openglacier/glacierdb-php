# `.ogid` via core operations

The PHP driver never decrypts `.ogid` files.

```php
$client = (new Client(
    identity: '/run/secrets/admin.ogid',
    identityPassword: 'secret',
))->connect();

$client->identityGet('export-password', 'backup.ogid');
$client->identityRenew('new-password', 'renewed.ogid');
```

The driver uses only `identity.open`, `identity.get`, and `identity.renew`.
No Argon2/XChaCha dependency is required in PHP for `.ogid`.
