<?php

declare(strict_types=1);

namespace GlacierDB;

use MessagePack\BufferUnpacker;
use MessagePack\Packer;
use GlacierDB\Exception\CoreException;
use GlacierDB\Exception\ProtocolException;
use RuntimeException;

final class Client
{
    /** Largest load request sent, as the Node and Python drivers do. */
    private const MAX_LOAD_REQUEST_BYTES = 256 * 1024;
    private const LOAD_REQUEST_HEADROOM_BYTES = 1024;

    /** @var resource|null */
    private $socket = null;

    private int $nextRequestId = 1;
    private readonly Packer $packer;
    private ?Identity $identity = null;
    private ?string $identityFile = null;
    private ?string $identityPassword = null;
    private bool $authenticated = false;

    /**
     * @param Identity|string|array<string,mixed>|null $identity
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 7878,
        Identity|string|array|null $identity = null,
        private readonly float $timeout = 30.0,
        ?string $identityPassword = null,
    ) {
        $this->packer = new Packer();
        if (is_string($identity) && strtolower(pathinfo($identity, PATHINFO_EXTENSION)) === 'ogid') {
            $this->identityFile = $identity;
            $this->identityPassword = $identityPassword;
        } else {
            $this->identity = $this->normalizeIdentity($identity);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): self
    {
        if (is_resource($this->socket)) {
            return $this;
        }

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new RuntimeException("Unable to connect to glacierdb: {$error} ({$errno})");
        }

        stream_set_timeout(
            $socket,
            (int) floor($this->timeout),
            (int) (($this->timeout - floor($this->timeout)) * 1_000_000),
        );

        $this->socket = $socket;

        if ($this->identityFile !== null) {
            if ($this->identityPassword === null) {
                throw new \InvalidArgumentException('identityPassword is required for .ogid authentication.');
            }
            $this->identityOpen($this->identityFile, $this->identityPassword);
        } elseif ($this->identity !== null) {
            $this->authenticate($this->identity);
        }

        return $this;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
        $this->authenticated = false;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * Execute a query and collect every partial document.
     *
     * @param callable(mixed):void|null $onDocument
     */
    public function execute(string $query, ?callable $onDocument = null): Result
    {
        $this->ensureConnected();

        $id = $this->requestId();
        $this->send([
            'version' => 1,
            'id' => $id,
            'op' => 'query.execute',
            'data' => ['query' => $query],
        ]);

        try {
            return $this->readQueryResult($id, $onDocument);
        } catch (ProtocolException $exception) {
            // The rest of the stream can no longer be matched to a request.
            $this->close();
            throw $exception;
        }
    }

    private function readQueryResult(string $id, ?callable $onDocument): Result
    {
        $documents = [];

        while (true) {
            $message = $this->readResponseFor($id);
            $status = $message['status'] ?? null;

            if ($status === 'partial') {
                $document = $message['data'] ?? null;

                if ($onDocument !== null) {
                    $onDocument($document);
                } else {
                    $documents[] = $document;
                }

                continue;
            }

            if ($status === 'complete') {
                $statistics = $message['statistics'] ?? null;
                return new Result(
                    $documents,
                    is_array($statistics) ? $statistics : null,
                );
            }

            if ($status === 'ok') {
                $data = $message['data'] ?? [];
                $statistics = $data['statistics'] ?? null;
                return new Result(
                    is_array($data['documents'] ?? null) ? $data['documents'] : [],
                    is_array($statistics) ? $statistics : null,
                );
            }

            if ($status === 'error') {
                $this->throwCoreError($message);
            }

            throw new ProtocolException('Unexpected query response status.');
        }
    }

    public function query(string $query): Result
    {
        return $this->execute($query);
    }

    /**
     * Loads documents in merge mode, in requests of at most $batchSize documents
     * and 256 KiB.
     *
     * @param iterable<array<string,mixed>> $documents
     */
    public function load(string $collection, iterable $documents, int $batchSize = 500): void
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batchSize must be >= 1.');
        }

        $collection = $this->collectionName($collection);
        $budget = self::MAX_LOAD_REQUEST_BYTES - self::LOAD_REQUEST_HEADROOM_BYTES
            - strlen($this->loadQuery($collection, ''));
        $encoded = [];
        $bytes = 0;

        foreach ($documents as $document) {
            $json = json_encode((object) $document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($json) > $budget) {
                throw new \InvalidArgumentException(
                    sprintf('One document is %d bytes; the request budget is %d.', strlen($json), $budget)
                );
            }

            if ($encoded !== [] && (count($encoded) >= $batchSize || $bytes + 1 + strlen($json) > $budget)) {
                $this->execute($this->loadQuery($collection, implode(',', $encoded)));
                $encoded = [];
                $bytes = 0;
            }

            $bytes += ($encoded === [] ? 0 : 1) + strlen($json);
            $encoded[] = $json;
        }

        if ($encoded !== []) {
            $this->execute($this->loadQuery($collection, implode(',', $encoded)));
        }
    }

    private function loadQuery(string $collection, string $documents): string
    {
        return "on {$collection}\n| load\n    | with merge\n    | chunk [{$documents}]\n| end";
    }

    public function loadCsv(
        string $collection,
        string $path,
        int $batchSize = 500,
        string $delimiter = ',',
    ): int {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV file: {$path}");
        }

        try {
            $header = fgetcsv($handle, separator: $delimiter);
            if ($header === false) {
                return 0;
            }

            $batch = [];
            $loaded = 0;

            while (($row = fgetcsv($handle, separator: $delimiter)) !== false) {
                if (count($row) !== count($header)) {
                    throw new RuntimeException('CSV row has a different column count than the header.');
                }

                $batch[] = array_combine($header, $row);

                if (count($batch) >= $batchSize) {
                    $this->load($collection, $batch, $batchSize);
                    $loaded += count($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->load($collection, $batch, $batchSize);
                $loaded += count($batch);
            }

            return $loaded;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function operation(string $op, array $data = []): array
    {
        $this->ensureConnected();

        $id = $this->requestId();
        $this->send([
            'version' => 1,
            'id' => $id,
            'op' => $op,
            'data' => $data,
        ]);

        try {
            $response = $this->readResponseFor($id);
        } catch (ProtocolException $exception) {
            $this->close();
            throw $exception;
        }

        if (($response['status'] ?? null) === 'error') {
            $this->throwCoreError($response);
        }

        return $response;
    }

    public function identityOpen(string $path, string $password): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \InvalidArgumentException("Unable to read identity file: {$path}");
        }

        $response = $this->operation('identity.open', [
            'file' => base64_encode($bytes),
            'password' => $password,
        ]);
        if (($response['data']['authenticated'] ?? false) !== true) {
            throw new ProtocolException('identity.open did not confirm authentication.');
        }

        $this->authenticated = true;
        return $this;
    }

    public function identityGet(string $password, ?string $destination = null): string
    {
        $response = $this->operation('identity.get', ['password' => $password]);
        return $this->writeIdentityResult($response, $destination);
    }

    public function identityRenew(string $password, ?string $destination = null): string
    {
        $response = $this->operation('identity.renew', ['password' => $password]);
        $this->authenticated = true;
        return $this->writeIdentityResult($response, $destination);
    }

    /**
     * @param array<string,mixed> $response
     */
    private function writeIdentityResult(array $response, ?string $destination): string
    {
        $data = $response['data'] ?? null;
        if (!is_array($data) || !is_string($data['file'] ?? null)) {
            throw new ProtocolException('Identity operation returned no encrypted file.');
        }

        $bytes = base64_decode($data['file'], true);
        if ($bytes === false) {
            throw new ProtocolException('Identity operation returned invalid base64 file data.');
        }

        if ($destination === null) {
            return $bytes;
        }

        if (is_dir($destination)) {
            $name = is_string($data['fileName'] ?? null) ? $data['fileName'] : 'identity.ogid';
            $destination = rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        }

        if (@file_put_contents($destination, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write identity file: {$destination}");
        }

        return $destination;
    }

    private function authenticate(Identity $identity): void
    {
        $begin = $this->operation('auth.begin', [
            'identityId' => $identity->identityId,
            'deviceId' => $identity->deviceId,
        ]);

        $data = $begin['data'] ?? null;
        if (!is_array($data)) {
            throw new ProtocolException('auth.begin response has no data object.');
        }

        $challengeId = $data['challengeId'] ?? null;
        $challenge = $data['challenge'] ?? null;

        if (!is_string($challengeId) || !is_string($challenge)) {
            throw new ProtocolException('auth.begin returned an invalid challenge.');
        }

        $challengeBytes = base64_decode($challenge, true);
        if ($challengeBytes === false) {
            throw new ProtocolException('auth.begin returned invalid base64 challenge data.');
        }

        $keypair = sodium_crypto_sign_seed_keypair($identity->seed());
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $signature = sodium_crypto_sign_detached($challengeBytes, $secretKey);

        $complete = $this->operation('auth.complete', [
            'challengeId' => $challengeId,
            'signature' => base64_encode($signature),
        ]);

        $authenticated = $complete['data']['authenticated'] ?? false;
        if ($authenticated !== true) {
            throw new ProtocolException('Core did not confirm authentication.');
        }

        $this->authenticated = true;
    }

    private function requestId(): string
    {
        return sprintf(
            '%d-%d',
            (int) floor(microtime(true) * 1000),
            $this->nextRequestId++,
        );
    }

    /**
     * @param array<string,mixed> $message
     */
    private function send(array $message): void
    {
        $payload = $this->packer->pack($message);
        $this->writeAll(pack('N', strlen($payload)) . $payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function readResponseFor(string $id): array
    {
        while (true) {
            $message = $this->readMessage();

            // The same Core connection is multiplexed. Heartbeats and other
            // events may arrive between request responses.
            if (($message['kind'] ?? null) === 'event') {
                continue;
            }

            if (($message['kind'] ?? null) !== 'response') {
                throw new ProtocolException('Unexpected message kind from Core.');
            }

            $receivedId = $message['id'] ?? null;
            if ((string) $receivedId !== $id) {
                throw new ProtocolException(
                    sprintf('Response id mismatch: expected %s, received %s.', $id, (string) $receivedId)
                );
            }

            return $message;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readMessage(): array
    {
        $header = $this->readExact(4);
        $length = unpack('Nlength', $header)['length'];

        if ($length < 1 || $length > 8 * 1024 * 1024) {
            throw new ProtocolException("Invalid MessagePack frame length: {$length}.");
        }

        $payload = $this->readExact($length);
        $unpacker = new BufferUnpacker($payload);
        $message = $unpacker->unpack();

        if (!is_array($message)) {
            throw new ProtocolException('Core MessagePack payload is not an object.');
        }

        return $message;
    }

    private function writeAll(string $bytes): void
    {
        $this->ensureConnected();

        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($this->socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new ProtocolException('Connection closed while sending request.');
            }
            $offset += $written;
        }
    }

    private function readExact(int $length): string
    {
        $this->ensureConnected();

        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($this->socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new ProtocolException('Timed out while reading from Core.');
                }
                throw new ProtocolException('Connection closed while reading from Core.');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param array<string,mixed> $message
     */
    private function throwCoreError(array $message): never
    {
        $error = $message['error'] ?? [];
        throw new CoreException(
            (string) ($error['code'] ?? 'operation.failed'),
            (string) ($error['message'] ?? 'Operation rejected by Core.'),
            is_array($error['details'] ?? null) ? $error['details'] : [],
        );
    }

    private function ensureConnected(): void
    {
        if (!is_resource($this->socket)) {
            $this->connect();
        }
    }

    /**
     * @param Identity|string|array<string,mixed>|null $identity
     */
    private function normalizeIdentity(Identity|string|array|null $identity): ?Identity
    {
        if ($identity === null) {
            return null;
        }

        if ($identity instanceof Identity) {
            return $identity;
        }

        if (is_string($identity)) {
            return Identity::fromFile($identity);
        }

        return new Identity(
            (int) ($identity['version'] ?? 1),
            (string) ($identity['identityId'] ?? ''),
            (string) ($identity['deviceId'] ?? ''),
            (string) ($identity['privateKey'] ?? ''),
        );
    }

    private function collectionName(string $collection): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $collection)) {
            throw new \InvalidArgumentException('Invalid collection name.');
        }

        return $collection;
    }
}
