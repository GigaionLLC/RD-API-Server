<?php

namespace Tests\Feature;

use App\Models\Recording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RecordingUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_ONE = '203.0.113.10';

    private const SOURCE_TWO = '203.0.113.11';

    private string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStoragePath = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'rustdesk-recording-tests-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->testStoragePath);
        $this->app->useStoragePath($this->testStoragePath);

        config()->set([
            'recordings.upload.enabled' => true,
            'recordings.upload.allowed_ips' => ['203.0.113.0/24'],
            'recordings.upload.token' => '',
            'recordings.upload.rate_limit_per_minute' => 120,
            'recordings.upload.max_chunk_bytes' => 64,
            'recordings.upload.max_file_bytes' => 1024,
            'recordings.upload.max_total_bytes' => 4096,
            'recordings.upload.max_total_files' => 20,
            'recordings.upload.max_active_per_source' => 4,
        ]);

        RateLimiter::clear($this->rateLimitKey(self::SOURCE_ONE));
        RateLimiter::clear($this->rateLimitKey(self::SOURCE_TWO));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testStoragePath);

        parent::tearDown();
    }

    public function test_upload_is_disabled_by_default_and_requires_an_explicit_boundary(): void
    {
        config()->set('recordings.upload.enabled', false);

        $this->recordRequest(['type' => 'new', 'file' => 'disabled.webm'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'Recording upload is disabled']);

        config()->set([
            'recordings.upload.enabled' => true,
            'recordings.upload.allowed_ips' => [],
            'recordings.upload.token' => '',
        ]);

        $this->recordRequest(['type' => 'new', 'file' => 'unauthorized.webm'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'Recording upload is not authorized']);

        $this->assertDatabaseCount('recordings', 0);
    }

    public function test_dedicated_header_token_can_authorize_a_trusted_uploader(): void
    {
        $token = str_repeat('a', 32);
        config()->set([
            'recordings.upload.allowed_ips' => [],
            'recordings.upload.token' => $token,
        ]);

        $this->recordRequest(
            ['type' => 'new', 'file' => 'bad-token.webm'],
            headers: ['HTTP_X_RECORDING_TOKEN' => 'too-short'],
        )->assertForbidden();

        $this->recordRequest(
            ['type' => 'new', 'file' => 'token.webm'],
            headers: ['HTTP_X_RECORDING_TOKEN' => $token],
        )->assertOk()->assertContent('{}');

        $this->assertDatabaseHas('recordings', [
            'filename' => 'token.webm',
            'source_ip' => self::SOURCE_ONE,
            'status' => 'recording',
        ]);
    }

    public function test_one_malformed_allowlist_entry_fails_the_ip_boundary_closed(): void
    {
        config()->set('recordings.upload.allowed_ips', ['203.0.113.0/24', 'not-a-cidr']);

        $this->recordRequest(['type' => 'new', 'file' => 'invalid-allowlist.webm'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'Recording upload is not authorized']);

        $this->assertDatabaseCount('recordings', 0);
    }

    public function test_all_address_cidrs_are_never_accepted_as_an_upload_boundary(): void
    {
        $cases = [
            ['range' => '0.0.0.0/0', 'source' => self::SOURCE_ONE, 'file' => 'all-v4.webm'],
            ['range' => '::/0', 'source' => '2001:db8::10', 'file' => 'all-v6.webm'],
        ];

        foreach ($cases as $case) {
            config()->set('recordings.upload.allowed_ips', [$case['range']]);
            RateLimiter::clear($this->rateLimitKey($case['source']));

            $this->recordRequest(
                ['type' => 'new', 'file' => $case['file']],
                sourceIp: $case['source'],
            )->assertForbidden()
                ->assertExactJson(['error' => 'Recording upload is not authorized']);
        }

        $this->assertDatabaseCount('recordings', 0);
    }

    public function test_ipv6_cidr_authorizes_and_binds_a_stock_client(): void
    {
        $sourceIp = '2001:db8:1234::10';
        config()->set('recordings.upload.allowed_ips', ['2001:db8:1234::/48']);
        RateLimiter::clear($this->rateLimitKey($sourceIp));

        $this->recordRequest(
            ['type' => 'new', 'file' => 'ipv6.webm'],
            sourceIp: $sourceIp,
        )->assertOk()->assertContent('{}');

        $this->assertDatabaseHas('recordings', [
            'filename' => 'ipv6.webm',
            'source_ip' => $sourceIp,
        ]);
    }

    public function test_allowlisted_stock_client_can_complete_the_wire_protocol(): void
    {
        $this->recordRequest(['type' => 'new', 'file' => 'session.webm'])
            ->assertOk()->assertContent('{}');
        $this->recordRequest(
            ['type' => 'part', 'file' => 'session.webm', 'offset' => '0', 'length' => '4'],
            'DATA',
        )->assertOk()->assertContent('{}');
        $this->recordRequest(
            ['type' => 'part', 'file' => 'session.webm', 'offset' => '4', 'length' => '4'],
            'BODY',
        )->assertOk()->assertContent('{}');
        $this->recordRequest(
            ['type' => 'tail', 'file' => 'session.webm', 'offset' => '0', 'length' => '4'],
            'HEAD',
        )->assertOk()->assertContent('{}');

        $path = $this->recordingPath('session.webm');
        $this->assertSame('HEADBODY', File::get($path));
        $this->assertDatabaseHas('recordings', [
            'filename' => 'session.webm',
            'source_ip' => self::SOURCE_ONE,
            'status' => 'finished',
            'size' => 8,
        ]);

        $this->recordRequest(['type' => 'remove', 'file' => 'session.webm'])
            ->assertOk()
            ->assertJsonPath('error', 'Recording not started');
        $this->assertFileExists($path);
    }

    public function test_start_never_truncates_or_reuses_an_existing_recording(): void
    {
        $this->recordRequest(['type' => 'new', 'file' => 'keep.webm'])->assertOk();
        $this->recordRequest(
            ['type' => 'part', 'file' => 'keep.webm', 'offset' => '0', 'length' => '4'],
            'KEEP',
        )->assertOk();
        $this->recordRequest(
            ['type' => 'tail', 'file' => 'keep.webm', 'offset' => '0', 'length' => '4'],
            'SAFE',
        )->assertOk();

        $this->recordRequest(['type' => 'new', 'file' => 'keep.webm'])
            ->assertOk()
            ->assertJsonPath('error', 'Recording already exists');

        $this->assertSame('SAFE', File::get($this->recordingPath('keep.webm')));
        $this->assertSame(1, Recording::where('filename', 'keep.webm')->count());
    }

    public function test_active_upload_mutations_are_bound_to_the_starting_source(): void
    {
        $this->recordRequest(['type' => 'new', 'file' => 'owned.webm'])->assertOk();

        $this->recordRequest(
            ['type' => 'part', 'file' => 'owned.webm', 'offset' => '0', 'length' => '4'],
            'EVIL',
            self::SOURCE_TWO,
        )->assertOk()->assertJsonPath('error', 'Recording not started');

        $this->recordRequest(
            ['type' => 'remove', 'file' => 'owned.webm'],
            sourceIp: self::SOURCE_TWO,
        )->assertOk()->assertJsonPath('error', 'Recording not started');

        $this->assertFileExists($this->recordingPath('owned.webm'));
        $this->assertDatabaseHas('recordings', ['filename' => 'owned.webm']);

        $this->recordRequest(['type' => 'remove', 'file' => 'owned.webm'])
            ->assertOk()->assertContent('{}');
        $this->assertFileDoesNotExist($this->recordingPath('owned.webm'));
        $this->assertDatabaseMissing('recordings', ['filename' => 'owned.webm']);
    }

    public function test_chunks_require_exact_bodies_sequential_offsets_and_finite_file_size(): void
    {
        config()->set([
            'recordings.upload.max_chunk_bytes' => 4,
            'recordings.upload.max_file_bytes' => 5,
        ]);
        $this->recordRequest(['type' => 'new', 'file' => 'strict.webm'])->assertOk();

        $this->recordRequest(
            ['type' => 'part', 'file' => 'strict.webm', 'offset' => '0', 'length' => '3'],
            'FOUR',
        )->assertOk()->assertJsonPath('error', 'Recording chunk length does not match its body');
        $this->recordRequest(
            ['type' => 'part', 'file' => 'strict.webm', 'offset' => '0', 'length' => '4'],
            'EXTRA',
            includeContentLength: false,
        )->assertOk()->assertJsonPath('error', 'Recording chunk length does not match its body');
        $this->recordRequest(
            ['type' => 'part', 'file' => 'strict.webm', 'offset' => '1', 'length' => '4'],
            'DATA',
        )->assertOk()->assertJsonPath('error', 'Invalid recording offset');
        $this->recordRequest(
            ['type' => 'part', 'file' => 'strict.webm', 'offset' => '0', 'length' => '5'],
            'LARGE',
        )->assertStatus(413)->assertJsonStructure(['error']);

        $this->recordRequest(
            ['type' => 'part', 'file' => 'strict.webm', 'offset' => '0', 'length' => '4'],
            'DATA',
        )->assertOk()->assertContent('{}');
        $this->recordRequest(
            ['type' => 'part', 'file' => 'strict.webm', 'offset' => '4', 'length' => '2'],
            'XX',
        )->assertOk()->assertJsonPath('error', 'Recording file size limit exceeded');
        $this->recordRequest(
            ['type' => 'tail', 'file' => 'strict.webm', 'offset' => '1', 'length' => '1'],
            'H',
        )->assertOk()->assertJsonPath('error', 'Invalid recording header');
        $this->recordRequest(
            ['type' => 'tail', 'file' => 'strict.webm', 'offset' => '0', 'length' => '5'],
            'HEADER',
        )->assertStatus(413)->assertJsonStructure(['error']);

        $this->assertSame('DATA', File::get($this->recordingPath('strict.webm')));
    }

    public function test_global_byte_file_and_active_upload_quotas_are_enforced(): void
    {
        config()->set([
            'recordings.upload.max_total_bytes' => 5,
            'recordings.upload.max_total_files' => 2,
            'recordings.upload.max_active_per_source' => 2,
        ]);

        $this->recordRequest(['type' => 'new', 'file' => 'one.webm'])->assertOk();
        $this->recordRequest(
            ['type' => 'part', 'file' => 'one.webm', 'offset' => '0', 'length' => '4'],
            'DATA',
        )->assertOk();
        $this->recordRequest(['type' => 'new', 'file' => 'two.webm'])->assertOk();
        $this->recordRequest(
            ['type' => 'part', 'file' => 'two.webm', 'offset' => '0', 'length' => '2'],
            'XX',
        )->assertOk()->assertJsonPath('error', 'Recording storage quota exceeded');

        $this->recordRequest(['type' => 'new', 'file' => 'three.webm'])
            ->assertOk()->assertJsonPath('error', 'Recording file quota exceeded');

        config()->set('recordings.upload.max_total_files', 3);
        $this->recordRequest(['type' => 'new', 'file' => 'three.webm'])
            ->assertOk()->assertJsonPath('error', 'Too many active recording uploads');
    }

    public function test_traversal_names_are_rejected_without_aliasing_an_existing_file(): void
    {
        File::ensureDirectoryExists(dirname($this->recordingPath('victim.webm')));
        File::put($this->recordingPath('victim.webm'), 'sentinel');

        $this->recordRequest(['type' => 'new', 'file' => '../victim.webm'])
            ->assertOk()->assertJsonPath('error', 'Invalid file name');

        $this->assertSame('sentinel', File::get($this->recordingPath('victim.webm')));
        $this->assertDatabaseCount('recordings', 0);
    }

    public function test_per_source_rate_limit_returns_the_clients_error_shape(): void
    {
        config()->set('recordings.upload.rate_limit_per_minute', 2);

        $this->recordRequest(['type' => 'new', 'file' => 'rate-one.webm'])->assertOk();
        $this->recordRequest(['type' => 'new', 'file' => 'rate-two.webm'])->assertOk();
        $this->recordRequest(['type' => 'new', 'file' => 'rate-three.webm'])
            ->assertStatus(429)
            ->assertExactJson(['error' => 'Too many recording upload requests'])
            ->assertHeader('Retry-After');
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     */
    private function recordRequest(
        array $query,
        string $body = '',
        string $sourceIp = self::SOURCE_ONE,
        array $headers = [],
        bool $includeContentLength = true,
    ): TestResponse {
        $server = [
            'REMOTE_ADDR' => $sourceIp,
            'CONTENT_TYPE' => 'application/octet-stream',
        ];
        if ($includeContentLength) {
            $server['CONTENT_LENGTH'] = (string) strlen($body);
        }
        $server = array_merge($server, $headers);

        return $this->call(
            'POST',
            '/api/record?'.http_build_query($query),
            [],
            [],
            [],
            $server,
            $body,
        );
    }

    private function recordingPath(string $filename): string
    {
        return $this->testStoragePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR
            .'recordings'.DIRECTORY_SEPARATOR.$filename;
    }

    private function rateLimitKey(string $sourceIp): string
    {
        return 'recording-upload:'.hash('sha256', $sourceIp);
    }
}
