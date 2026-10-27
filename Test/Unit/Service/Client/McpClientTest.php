<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Higgsfield\Test\Unit\Service\Client;

use GuzzleHttp\Psr7\Response;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Higgsfield\Service\Client\McpClient;
use MagoAssistant\Higgsfield\Service\Client\OAuthService;
use MagoAssistant\Higgsfield\Test\Unit\Fakes\RecordingHttpClient;
use MagoAssistant\Mago\Logger\ErrorLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class McpClientTest extends TestCase
{
    /**
     * @var RecordingHttpClient
     */
    private RecordingHttpClient $http;

    /**
     * @var OAuthService&Stub
     */
    private OAuthService $oauth;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->oauth = $this->createStub(OAuthService::class);
        $this->oauth->method('accessToken')->willReturn('token-1');
    }

    #[Test]
    public function itInitializesOnceAndSendsTheSessionId(): void
    {
        $this->http->queue(
            self::rpc(1, ['protocolVersion' => '2025-06-18'], ['Mcp-Session-Id' => 'session-1']),
            new Response(202),
            self::rpc(2, ['structuredContent' => ['ok' => 1], 'content' => []]),
            self::rpc(3, ['structuredContent' => ['ok' => 2], 'content' => []])
        );
        $client = $this->client();

        self::assertSame(['ok' => 1], $client->callTool('job_status', ['jobId' => 'x'])['structured']);
        self::assertSame(['ok' => 2], $client->callTool('job_status', ['jobId' => 'y'])['structured']);

        self::assertCount(4, $this->http->requests);
        self::assertSame('initialize', self::body($this->http->requests[0])['method']);
        self::assertSame('notifications/initialized', self::body($this->http->requests[1])['method']);
        self::assertSame('Bearer token-1', $this->http->requests[2]['options']['headers']['Authorization']);
        self::assertSame('session-1', $this->http->requests[2]['options']['headers']['Mcp-Session-Id']);
        self::assertSame(OAuthService::RESOURCE, $this->http->requests[2]['uri']);
    }

    #[Test]
    public function itReadsTheAnswerFromAnEventStream(): void
    {
        $stream = "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\n"
            . "event: message\ndata: " . json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                'content' => [['type' => 'text', 'text' => '{"cost":{"credits":3}}']],
            ]]) . "\n\n";
        $this->http->queue(
            self::rpc(1, []),
            new Response(202),
            new Response(200, ['Content-Type' => 'text/event-stream'], $stream)
        );

        $result = $this->client()->callTool('generate_image', ['params' => ['get_cost' => true]]);

        self::assertSame(['cost' => ['credits' => 3]], $result['structured']);
        self::assertFalse($result['is_error']);
    }

    #[Test]
    public function itRefreshesTheTokenOnceAfterA401(): void
    {
        $oauth = $this->createMock(OAuthService::class);
        $oauth->method('accessToken')->willReturn('token-1');
        $oauth->expects(self::once())->method('refresh')->willReturn(true);
        $this->oauth = $oauth;
        $this->http->queue(new Response(401), self::rpc(1, []), new Response(202), self::rpc(2, ['content' => []]));

        $result = $this->client()->callTool('job_status', []);

        self::assertArrayNotHasKey('error', $result);
        self::assertCount(4, $this->http->requests);
    }

    #[Test]
    public function itAsksToReconnectWhenTheTokenStaysRejected(): void
    {
        $this->oauth->method('refresh')->willReturn(false);
        $this->http->queue(new Response(401));

        $result = $this->client()->callTool('job_status', []);

        self::assertStringStartsWith('The Higgsfield connection has expired.', $result['error']);
        self::assertStringContainsString(OAuthService::SETTINGS_PATH, $result['error']);
    }

    #[Test]
    public function itPassesAJsonRpcErrorOn(): void
    {
        $this->http->queue(
            self::rpc(1, []),
            new Response(202),
            new Response(200, ['Content-Type' => 'application/json'], (string)json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'error' => ['code' => -32602, 'message' => 'Unknown tool'],
            ]))
        );

        self::assertSame(
            ['error' => 'Higgsfield refused the request: Unknown tool'],
            $this->client()->callTool('nope', [])
        );
    }

    #[Test]
    public function itOpensANewSessionWhenTheServerDropsTheOldOne(): void
    {
        $this->http->queue(
            self::rpc(1, [], ['Mcp-Session-Id' => 'session-1']),
            new Response(202),
            new Response(404),
            self::rpc(3, [], ['Mcp-Session-Id' => 'session-2']),
            new Response(202),
            self::rpc(4, ['structuredContent' => ['ok' => 1], 'content' => []])
        );

        $result = $this->client()->callTool('job_status', []);

        self::assertSame(['ok' => 1], $result['structured']);
        self::assertSame('session-2', $this->http->requests[5]['options']['headers']['Mcp-Session-Id']);
    }

    #[Test]
    public function itGivesUpWhenTheNewSessionIsDroppedToo(): void
    {
        $this->http->queue(
            self::rpc(1, [], ['Mcp-Session-Id' => 'session-1']),
            new Response(202),
            new Response(404),
            self::rpc(3, [], ['Mcp-Session-Id' => 'session-2']),
            new Response(202),
            new Response(404)
        );

        $result = $this->client()->callTool('job_status', []);

        self::assertSame(['error' => 'Higgsfield ended the session.'], $result);
        self::assertCount(6, $this->http->requests);
    }

    #[Test]
    public function itSendsNothingWhenNotConnected(): void
    {
        $this->oauth = $this->createStub(OAuthService::class);
        $this->oauth->method('accessToken')->willReturn(null);

        self::assertSame(['error' => 'Higgsfield could not be reached.'], $this->client()->callTool('job_status', []));
        self::assertSame([], $this->http->requests);
    }

    /**
     * @param int $id
     * @param array<string,mixed> $result
     * @param array<string,string> $headers
     */
    private static function rpc(int $id, array $result, array $headers = []): Response
    {
        return new Response(
            200,
            $headers + ['Content-Type' => 'application/json'],
            (string)json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result])
        );
    }

    /**
     * @param array{options:array<string,mixed>} $request
     * @return array<string,mixed>
     */
    private static function body(array $request): array
    {
        return json_decode((string)$request['options']['body'], true);
    }

    private function client(): McpClient
    {
        return new McpClient($this->http, $this->oauth, new Json(), $this->createStub(ErrorLogger::class));
    }
}
