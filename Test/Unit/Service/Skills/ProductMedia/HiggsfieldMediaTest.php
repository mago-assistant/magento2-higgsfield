<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Higgsfield\Test\Unit\Service\Skills\ProductMedia;

use GuzzleHttp\Psr7\Response;
use MagoAssistant\Higgsfield\Service\Skills\ProductMedia\HiggsfieldMedia;
use MagoAssistant\Higgsfield\Test\Unit\Fakes\FakeMcpClient;
use MagoAssistant\Higgsfield\Test\Unit\Fakes\RecordingHttpClient;
use MagoAssistant\Mago\Logger\ErrorLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HiggsfieldMediaTest extends TestCase
{
    private const JOB = 'b2cfbf3c-c847-4a2f-b0f9-c0b5740fc4ab';
    private const UPLOAD_URL = 'https://bucket.s3.amazonaws.com/in.jpg?X-Amz-Signature=x';

    /**
     * @var FakeMcpClient
     */
    private FakeMcpClient $mcp;

    /**
     * @var RecordingHttpClient
     */
    private RecordingHttpClient $http;

    protected function setUp(): void
    {
        $this->mcp = new FakeMcpClient();
        $this->http = new RecordingHttpClient();
    }

    #[Test]
    public function itUploadsToThePresignedUrlWithoutTheTokenAndConfirms(): void
    {
        $this->mcp
            ->withOutput('media_upload', self::toolOutput(['uploads' => [[
                'upload_url' => self::UPLOAD_URL,
                'media_id' => 'media-1',
                'content_type' => 'image/jpeg',
            ]]]))
            ->withOutput('media_confirm', self::toolOutput(['confirmed' => true]));
        $this->http->queue(new Response(200));

        $result = $this->media()->uploadImage('bytes', 'image/jpeg', 'in.jpg');

        self::assertSame(['media_id' => 'media-1'], $result);
        self::assertSame(['media_upload', 'media_confirm'], array_column($this->mcp->calls, 'name'));
        self::assertSame(['type' => 'image', 'media_id' => 'media-1'], $this->mcp->calls[1]['arguments']);
        self::assertCount(1, $this->http->requests);
        self::assertSame('PUT', $this->http->requests[0]['method']);
        self::assertSame(self::UPLOAD_URL, $this->http->requests[0]['uri']);
        self::assertSame(['Content-Type' => 'image/jpeg'], $this->http->requests[0]['options']['headers']);
        self::assertSame('bytes', $this->http->requests[0]['options']['body']);
    }

    #[Test]
    public function itSubmitsOneCreditPaidJobAndReadsItsId(): void
    {
        $this->mcp->withOutput('generate_image', self::toolOutput(['results' => [[
            'id' => strtoupper(self::JOB),
            'type' => 'image',
            'status' => 'queued',
            'model' => 'nano_banana_2',
        ]]]));

        $result = $this->media()->submit('generate_image', ['model' => 'nano_banana_2']);

        self::assertSame(['job_id' => self::JOB, 'status' => 'queued'], $result);
        self::assertSame(
            [['name' => 'generate_image', 'arguments' => ['params' => [
                'model' => 'nano_banana_2',
                'count' => 1,
                'use_unlim' => false,
            ]]]],
            $this->mcp->calls
        );
    }

    #[Test]
    public function itReportsAToolErrorWithItsText(): void
    {
        $this->mcp->withOutput('generate_video', [
            'structured' => null,
            'text' => 'Insufficient credits',
            'is_error' => true,
        ]);

        $result = $this->media()->submit('generate_video', ['model' => 'seedance_2_0']);

        self::assertSame(['error' => 'Higgsfield refused the request: Insufficient credits'], $result);
    }

    #[Test]
    public function itReadsTheFullSizeOutputOfACompletedJob(): void
    {
        $this->mcp->withOutput('job_status', self::toolOutput(['generation' => [
            'id' => self::JOB,
            'type' => 'image',
            'status' => 'completed',
            'results' => [
                'rawUrl' => 'https://cdn.example.com/out.png',
                'minUrl' => 'https://cdn.example.com/out_min.webp',
            ],
        ]]));

        $result = $this->media()->status(self::JOB);

        self::assertSame(
            ['status' => 'completed', 'type' => 'image', 'urls' => ['https://cdn.example.com/out.png']],
            $result
        );
    }

    #[Test]
    public function itReadsThePreflightCost(): void
    {
        $this->mcp->withOutput('generate_video', self::toolOutput(['cost' => ['credits' => 22.5]]));

        $cost = $this->media()->cost('generate_video', ['model' => 'seedance_2_0']);

        self::assertSame(22.5, $cost);
    }

    /**
     * Tool output as McpClient returns it.
     *
     * @param array<string,mixed> $structured
     * @return array<string,mixed>
     */
    private static function toolOutput(array $structured): array
    {
        return ['structured' => $structured, 'text' => '', 'is_error' => false];
    }

    private function media(): HiggsfieldMedia
    {
        return new HiggsfieldMedia($this->mcp, $this->http, $this->createStub(ErrorLogger::class));
    }
}
