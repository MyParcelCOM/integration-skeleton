<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authentication\Domain\AuthorizationSession;
use App\Authentication\Domain\AuthServer;
use App\Authentication\Domain\AuthServerInterface;
use App\Authentication\Domain\Exceptions\AuthSessionExpiredException;
use App\Authentication\Domain\ShopId;
use App\Http\ExactAuthClient;
use Faker\Factory;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Utils;
use Illuminate\Contracts\Cache\Repository;
use Mockery;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;
use function config;
use function http_build_query;
use function parse_str;
use function preg_quote;

class AuthenticationTest extends TestCase
{
    public function test_should_authenticate(): void
    {
        $faker = Factory::create();

        $this->app->singleton(AuthServerInterface::class, fn() => new AuthServer(
            Mockery::mock(ExactAuthClient::class, [
                'post' => Mockery::mock(ResponseInterface::class, [
                    'getBody' => Utils::jsonEncode([
                        'refresh_token' => $faker->text,
                        'access_token'  => $faker->text,
                        'expires_in'    => 600,
                        'token_type'    => 'bearer',
                    ]),
                ]),
            ]),
            $faker->uuid,
            $faker->password,
            $faker->url,
        ));

        $shopId = $faker->uuid;
        $redirectUri = $faker->url;

        $this->app->singleton(AuthorizationSession::class, fn() => Mockery::mock(AuthorizationSession::class, [
            'fetch' => [
                'shop_id'      => new ShopId(Uuid::fromString($shopId)),
                'redirect_uri' => $redirectUri,
            ],
        ]));

        $query = http_build_query([
            'session_token' => $faker->word,
            'code'          => $faker->text,
        ]);

        $response = $this->get("/public/authenticate?${query}");

        $response->assertStatus(302);
        $response->assertRedirect($redirectUri);

        $this->assertDatabaseHas('tokens', [
            'shop_id' => $shopId,
        ]);
    }

    public function test_should_fail_authentication_upon_unknown_bad_request(): void
    {
        $faker = Factory::create();
        $shopId = $faker->uuid;

        $clientMock = Mockery::mock(ExactAuthClient::class);
        $clientMock->shouldReceive('post')->andThrow(
            Mockery::mock(RequestException::class, [
                'getResponse' => null,
            ])
        );
        $this->app->singleton(AuthServerInterface::class, fn() => new AuthServer(
            $clientMock,
            $faker->uuid,
            $faker->password,
            $faker->url,
        ));
        $this->app->singleton(AuthorizationSession::class, fn() => Mockery::mock(AuthorizationSession::class, [
            'fetch' => [
                'shop_id'      => new ShopId(Uuid::fromString($shopId)),
                'redirect_uri' => $faker->url,
            ],
        ]));

        $query = http_build_query([
            'session_token' => $faker->word,
            'code'          => $faker->text,
        ]);

        $response = $this->get("/public/authenticate?${query}");

        $response->assertStatus(400);
        $response->assertExactJson([
            'errors' => [
                [
                    'status' => '400',
                    'title'  => 'Authentication error',
                    'detail' => 'Unknown request exception',
                ],
            ],
        ]);

        $this->assertDatabaseMissing('tokens', [
            'shop_id' => $shopId,
        ]);
    }

    public function test_should_fail_authentication_upon_precise_bad_request(): void
    {
        $faker = Factory::create();
        $shopId = $faker->uuid;

        $clientMock = Mockery::mock(ExactAuthClient::class);
        $clientMock->shouldReceive('post')->andThrow(
            Mockery::mock(RequestException::class, [
                'getResponse' => Mockery::mock(ResponseInterface::class, [
                    'getBody'       => Utils::jsonEncode([
                        'error'             => 'test_error',
                        'error_description' => 'Testing errors',
                    ]),
                    'getStatusCode' => 400,
                ]),
            ])
        );
        $this->app->singleton(AuthServerInterface::class, fn() => new AuthServer(
            $clientMock,
            $faker->uuid,
            $faker->password,
            $faker->url,
        ));
        $this->app->singleton(AuthorizationSession::class, fn() => Mockery::mock(AuthorizationSession::class, [
            'fetch' => [
                'shop_id'      => new ShopId(Uuid::fromString($shopId)),
                'redirect_uri' => $faker->url,
            ],
        ]));

        $query = http_build_query([
            'session_token' => $faker->word,
            'code'          => $faker->text,
        ]);

        $response = $this->get("/public/authenticate?${query}");

        $response->assertStatus(400);
        $response->assertExactJson([
            'errors' => [
                [
                    'status' => '400',
                    'title'  => 'test_error',
                    'detail' => 'Testing errors',
                ],
            ],
        ]);

        $this->assertDatabaseMissing('tokens', [
            'shop_id' => $shopId,
        ]);
    }

    public function test_should_fail_authentication_when_auth_session_expired(): void
    {
        $faker = Factory::create();

        $clientMock = Mockery::mock(ExactAuthClient::class);
        $clientMock->shouldReceive('post')->andThrow(
            Mockery::mock(RequestException::class, [
                'getResponse' => Mockery::mock(ResponseInterface::class, [
                    'getBody'       => Utils::jsonEncode([
                        'error'             => 'test_error',
                        'error_description' => 'Testing errors',
                    ]),
                    'getStatusCode' => 400,
                ]),
            ])
        );
        $this->app->singleton(Repository::class, fn() => Mockery::mock(Repository::class, [
            'has' => false
        ]));

        $query = http_build_query([
            'session_token' => $faker->word,
            'code'          => $faker->text,
        ]);

        $response = $this->get("/public/authenticate?${query}");
        $response->assertStatus(400);
    }

    public function test_should_create_authorization_link(): void
    {
        $faker = Factory::create();

        $clientId = $faker->uuid;
        $redirectUri = $faker->url;
        $shopId = $faker->uuid;
        $v2RedirectUri = $faker->url;
        $token = $faker->word;

        config()->set('exact.auth.client_id', $clientId);
        config()->set('exact.auth.redirect_uri', $redirectUri);
        $this->app->singleton(AuthorizationSession::class, fn() => Mockery::mock(AuthorizationSession::class, [
            'save' => $token,
        ]));

        $response = $this->post('/public/init-auth', [
            'data' => [
                'redirect_uri' => $v2RedirectUri,
                'shop_id'      => $shopId,
            ],
        ]);

        $response->assertStatus(200);
        $authLink = new Uri($response->json('data.authorization_link'));
        parse_str($authLink->getQuery(), $query);

        self::assertEquals('https', $authLink->getScheme());
        self::assertEquals('start.exactonline.nl', $authLink->getHost());
        self::assertEquals('/api/oauth2/auth', $authLink->getPath());
        self::assertEquals($clientId, $query['client_id']);
        self::assertEquals('code', $query['response_type']);
        self::assertMatchesRegularExpression('/^(' . preg_quote($redirectUri, '/') . ')\?session_token=.+?/', $query['redirect_uri']);
    }
}
