<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Routing\ExtensionRouteLoader;
use Quire\User\CreateUser;
use Quire\User\Security\SignInListener;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Symfony's form login already refuses redirects to other sites; ways of signing in that extensions add
 * may build their own redirects, which Quire keeps on the site too.
 */
final class SignInListenerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testRedirectsAfterSigningInStayOnTheSite(): void
    {
        foreach ([
            'https://evil.example/' => '/',
            '//evil.example/' => '/',
            '/\\evil.example' => '/',
            '/about' => '/about',
            'http://localhost/about' => 'http://localhost/about',
        ] as $target => $expected) {
            $event = $this->success($target, quireRoute: true);
            self::getContainer()->get(SignInListener::class)->signedIn($event);

            $response = $event->getResponse();
            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame($expected, $response->getTargetUrl(), $target);
        }
    }

    public function testRedirectsOfTheApplicationsOwnSignInAreItsBusiness(): void
    {
        $event = $this->success('https://partner.example/', quireRoute: false);
        self::getContainer()->get(SignInListener::class)->signedIn($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('https://partner.example/', $event->getResponse()->getTargetUrl());
    }

    private function success(string $target, bool $quireRoute): LoginSuccessEvent
    {
        $user = self::getContainer()->get(UserWriter::class)->create(new CreateUser('Ada', 'ada-'.bin2hex(random_bytes(3)).'@example.com'));
        $request = Request::create('http://localhost/login', 'POST');
        $request->attributes->set(ExtensionRouteLoader::QUIRE_ROUTE, $quireRoute);

        return new LoginSuccessEvent(
            self::createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->id, static fn () => $user)),
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
            $request,
            new RedirectResponse($target),
            'main',
        );
    }
}
