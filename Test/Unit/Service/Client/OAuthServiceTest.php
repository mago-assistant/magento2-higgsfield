<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Higgsfield\Test\Unit\Service\Client;

use GuzzleHttp\Psr7\Response;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Higgsfield\Service\Client\OAuthService;
use MagoAssistant\Higgsfield\Test\Unit\Fakes\FakeTokenStore;
use MagoAssistant\Higgsfield\Test\Unit\Fakes\RecordingHttpClient;
use MagoAssistant\Mago\Logger\ErrorLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OAuthServiceTest extends TestCase
{
    private const REDIRECT = 'https://shop.test/admin/mago_higgsfield/oauth/callback/';
    private const OTHER_REDIRECT = 'https://admin.shop.test/admin/mago_higgsfield/oauth/callback/';
    private const METADATA = [
        'authorization_endpoint' => 'https://auth.higgsfield.test/authorize',
        'token_endpoint' => 'https://auth.higgsfield.test/token',
        'registration_endpoint' => 'https://auth.higgsfield.test/register',
    ];

    /**
     * @var RecordingHttpClient
     */
    private RecordingHttpClient $http;

    /**
     * @var FakeTokenStore
     */
    private FakeTokenStore $store;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->store = new FakeTokenStore();
    }

    #[Test]
    public function itRegistersTheShopAndBuildsAPkceAuthorizationUrl(): void
    {
        $this->http->queue(self::json(self::METADATA), self::json(['client_id' => 'client-1', 'client_secret' => 's']));

        $start = $this->oauth()->startAuthorization(self::REDIRECT);

        self::assertSame('POST', $this->http->requests[1]['method']);
        self::assertSame([self::REDIRECT], $this->http->requests[1]['options']['json']['redirect_uris']);

        parse_str((string)parse_url($start['url'], PHP_URL_QUERY), $query);
        self::assertStringStartsWith(self::METADATA['authorization_endpoint'] . '?', $start['url']);
        self::assertSame('client-1', $query['client_id']);
        self::assertSame($start['state'], $query['state']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $start['verifier'], true)), '+/', '-_'), '='),
            $query['code_challenge']
        );
        self::assertSame(OAuthService::RESOURCE, $query['resource']);
        self::assertSame('client-1', $this->store->data['client_id']);
        self::assertSame(self::METADATA['token_endpoint'], $this->store->data['token_endpoint']);
    }

    #[Test]
    public function itReusesTheRegisteredClientForTheSameRedirectUri(): void
    {
        $this->store->data = ['client_id' => 'client-1', 'redirect_uri' => self::REDIRECT, 'access_token' => 'kept'];
        $this->http->queue(self::json(self::METADATA));

        $start = $this->oauth()->startAuthorization(self::REDIRECT);

        self::assertCount(1, $this->http->requests);
        self::assertStringContainsString('client_id=client-1', $start['url']);
        self::assertSame('kept', $this->store->data['access_token']);
    }

    #[Test]
    public function itKeepsTheWorkingConnectionWhileAReconnectFromAnotherUrlIsPending(): void
    {
        $this->store->data = [
            'client_id' => 'client-1',
            'redirect_uri' => self::OTHER_REDIRECT,
            'token_endpoint' => self::METADATA['token_endpoint'],
            'access_token' => 'kept',
        ];
        $this->http->queue(
            self::json(self::METADATA),
            self::json(['client_id' => 'client-2', 'client_secret' => 's2'])
        );

        $start = $this->oauth()->startAuthorization(self::REDIRECT);

        self::assertStringContainsString('client_id=client-2', $start['url']);
        self::assertSame('client-1', $this->store->data['client_id']);
        self::assertSame('kept', $this->oauth()->accessToken());
    }

    #[Test]
    public function itSwitchesToTheNewClientOnceTheReconnectCompletes(): void
    {
        $this->store->data = [
            'client_id' => 'client-1',
            'redirect_uri' => self::OTHER_REDIRECT,
            'token_endpoint' => self::METADATA['token_endpoint'],
            'access_token' => 'old',
            'email' => 'old@example.com',
            'pending_client_id' => 'client-2',
            'pending_client_secret' => 's2',
            'pending_redirect_uri' => self::REDIRECT,
        ];
        $this->http->queue(self::json(['access_token' => 'new', 'expires_in' => 3600]));

        self::assertNull($this->oauth()->completeAuthorization('code-1', 'verifier-1', self::REDIRECT));

        $sent = $this->http->requests[0]['options']['form_params'];
        self::assertSame(['client-2', 's2'], [$sent['client_id'], $sent['client_secret']]);
        self::assertSame('client-2', $this->store->data['client_id']);
        self::assertSame(self::REDIRECT, $this->store->data['redirect_uri']);
        self::assertSame('new', $this->store->data['access_token']);
        self::assertArrayNotHasKey('pending_client_id', $this->store->data);
        self::assertArrayNotHasKey('email', $this->store->data);
    }

    #[Test]
    public function itReportsIncompleteMetadata(): void
    {
        $this->http->queue(self::json(['authorization_endpoint' => 'https://auth.higgsfield.test/authorize']));

        self::assertSame(
            ['error' => 'Higgsfield returned incomplete OAuth metadata.'],
            $this->oauth()->startAuthorization(self::REDIRECT)
        );
    }

    #[Test]
    public function itExchangesTheCodeAndKeepsTheAccountEmail(): void
    {
        $this->store->data = [
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
            'token_endpoint' => self::METADATA['token_endpoint'],
        ];
        $idToken = 'h.' . rtrim(strtr(base64_encode('{"email":"shop@example.com"}'), '+/', '-_'), '=') . '.s';
        $this->http->queue(self::json([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
            'id_token' => $idToken,
        ]));

        self::assertNull($this->oauth()->completeAuthorization('code-1', 'verifier-1', self::REDIRECT));

        $sent = $this->http->requests[0]['options']['form_params'];
        self::assertSame('authorization_code', $sent['grant_type']);
        self::assertSame('verifier-1', $sent['code_verifier']);
        self::assertSame('secret-1', $sent['client_secret']);
        self::assertSame('access-1', $this->store->data['access_token']);
        self::assertSame('refresh-1', $this->store->data['refresh_token']);
        self::assertSame('shop@example.com', $this->store->data['email']);
        self::assertSame(
            ['connected' => true, 'email' => 'shop@example.com', 'expires_at' => $this->store->data['expires_at'],
                'can_refresh' => true],
            $this->oauth()->status()
        );
    }

    #[Test]
    public function itRefusesACallbackThatWasNotStartedHere(): void
    {
        self::assertSame(
            'The Higgsfield connection was not started from this shop. Try connecting again.',
            $this->oauth()->completeAuthorization('code-1', 'verifier-1', self::REDIRECT)
        );
        self::assertSame([], $this->http->requests);
    }

    #[Test]
    public function itRefreshesAnExpiringTokenAndKeepsTheRefreshToken(): void
    {
        $this->store->data = [
            'client_id' => 'client-1',
            'token_endpoint' => self::METADATA['token_endpoint'],
            'access_token' => 'old',
            'refresh_token' => 'refresh-1',
            'expires_at' => time() + 10,
        ];
        $this->http->queue(self::json(['access_token' => 'new', 'expires_in' => 3600]));

        self::assertSame('new', $this->oauth()->accessToken());
        self::assertSame('refresh_token', $this->http->requests[0]['options']['form_params']['grant_type']);
        self::assertSame('refresh-1', $this->store->data['refresh_token']);
    }

    #[Test]
    public function itUsesAValidTokenWithoutCallingHiggsfield(): void
    {
        $this->store->data = ['access_token' => 'valid', 'expires_at' => time() + 3600];

        self::assertSame('valid', $this->oauth()->accessToken());
        self::assertSame([], $this->http->requests);
    }

    #[Test]
    public function itIsNotConnectedWhenAnExpiredTokenCannotBeRefreshed(): void
    {
        $this->store->data = ['access_token' => 'old', 'expires_at' => time() - 10];

        self::assertFalse($this->oauth()->isConnected());
    }

    #[Test]
    public function itShowsAnExpiredTokenWithoutRefreshTokenAsNotConnected(): void
    {
        $this->store->data = ['access_token' => 'old', 'expires_at' => time() - 10];

        self::assertFalse($this->oauth()->status()['connected']);
    }

    #[Test]
    public function itShowsAnExpiredTokenThatCanBeRefreshedAsConnected(): void
    {
        $this->store->data = ['access_token' => 'old', 'refresh_token' => 'r', 'expires_at' => time() - 10];

        self::assertTrue($this->oauth()->status()['connected']);
    }

    #[Test]
    public function itReportsTheOAuthErrorCode(): void
    {
        $this->http->queue(new Response(400, [], '{"error":"invalid_client"}'));

        self::assertSame(
            ['error' => 'Higgsfield refused the connection (HTTP 400, invalid_client).'],
            $this->oauth()->startAuthorization(self::REDIRECT)
        );
    }

    #[Test]
    public function itForgetsEverythingOnDisconnect(): void
    {
        $this->store->data = ['client_id' => 'client-1', 'access_token' => 'a'];

        $this->oauth()->disconnect();

        self::assertSame([], $this->store->data);
        self::assertFalse($this->oauth()->status()['connected']);
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function json(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($data));
    }

    private function oauth(): OAuthService
    {
        return new OAuthService($this->http, $this->store, new Json(), $this->createStub(ErrorLogger::class));
    }
}
