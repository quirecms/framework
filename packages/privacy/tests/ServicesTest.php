<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use PHPUnit\Framework\TestCase;
use Quire\Privacy\Category;
use Quire\Privacy\Services;

final class ServicesTest extends TestCase
{
    public function testOnlyTheCategoriesInUseInTheirOrder(): void
    {
        $services = $this->services([['id' => 'video', 'category' => 'marketing'], ['id' => 'analytics', 'category' => 'statistics'], ['id' => 'session', 'category' => 'necessary']]);

        self::assertSame([Category::Statistics, Category::Marketing], $services->categories(), 'Not the necessary one, and not empty ones.');
        self::assertSame(['video', 'analytics'], array_map(static fn (\Quire\Privacy\Service $service): string => $service->id, $services->askedAbout()));
        self::assertTrue($services->needConsent());
    }

    public function testNecessaryServicesAloneAskNothing(): void
    {
        self::assertFalse($this->services([['id' => 'session', 'category' => 'necessary']])->needConsent());
        self::assertFalse(new Services()->needConsent());
    }

    public function testTheRevisionChangesWithWhatVisitorsAreAskedAbout(): void
    {
        $revision = $this->services([['id' => 'video']])->revision();

        self::assertSame($revision, $this->services([['id' => 'video', 'label' => 'Another name', 'purpose' => 'Other words.']])->revision(), 'Wording isn\'t a reason to ask again.');
        self::assertNotSame($revision, $this->services([['id' => 'video', 'hosts' => ['frame-src' => ['https://new.video.example']]]])->revision(), 'A new host is.');
        self::assertNotSame($revision, $this->services([['id' => 'video', 'cookies' => [['new_cookie', '1 day', 'Something new.']]]])->revision(), 'A new cookie is.');
        self::assertNotSame($revision, $this->services([['id' => 'video'], ['id' => 'fonts', 'category' => 'preferences']])->revision(), 'A new service is.');
    }

    public function testHostsOfEveryServiceByDirective(): void
    {
        $services = $this->services([
            ['id' => 'video', 'hosts' => ['frame-src' => ['https://player.video.example'], 'img-src' => ['https://cdn.example']]],
            ['id' => 'fonts', 'category' => 'preferences', 'hosts' => ['img-src' => ['https://cdn.example', 'https://fonts.example']]],
        ]);

        self::assertSame(['frame-src' => ['https://player.video.example'], 'img-src' => ['https://cdn.example', 'https://fonts.example']], $services->hosts());
    }

    public function testAServiceIsDeclaredOnce(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The service "video" is declared by "acme-one" and by "acme-two"');

        $this->services([['id' => 'video', 'owner' => 'acme-one'], ['id' => 'video', 'owner' => 'acme-two']]);
    }

    /**
     * @param list<array<string, mixed>> $declarations
     */
    private function services(array $declarations): Services
    {
        /** @var list<array{id: string, label: string, category: string, vendor: string, purpose: string, cookies: list<array{string, string, string}>, hosts: array<string, list<string>>, retention: ?string, policy_url: ?string, script: ?string, config: array<string, scalar>, owner: string}> $full */
        $full = array_map(static fn (array $declaration): array => [
            'id' => 'video',
            'label' => 'Video',
            'category' => 'marketing',
            'vendor' => 'Video Ltd',
            'purpose' => 'Plays videos.',
            'cookies' => [],
            'hosts' => [],
            'retention' => null,
            'policy_url' => null,
            'script' => null,
            'config' => [],
            'owner' => 'acme',
            ...$declaration,
        ], $declarations);

        return new Services($full);
    }
}
