<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Privacy\Category;
use Quire\Privacy\Cookie;
use Quire\Privacy\Service;

final class ServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): Service, string}>
     */
    public static function mistakes(): iterable
    {
        $service = static fn (string $id = 'acme-video', string $vendor = 'Acme Video Ltd', ?string $directive = null, string $host = '', ?string $policyUrl = null, ?string $script = null, Category $category = Category::Marketing): Service => new Service($id, 'Acme Video', $category, $vendor, 'Plays videos.', hosts: null === $directive ? [] : [$directive => [$host]], policyUrl: $policyUrl, script: $script);

        yield 'an ID with capitals' => [static fn (): Service => $service(id: 'GoogleAnalytics'), 'isn\'t a service\'s ID'];
        yield 'no vendor' => [static fn (): Service => $service(vendor: ' '), 'needs a vendor'];
        yield 'a keyword as a host' => [static fn (): Service => $service(directive: 'script-src', host: "'unsafe-inline'"), 'only hosts such as'];
        yield 'a whole scheme' => [static fn (): Service => $service(directive: 'script-src', host: 'https:'), 'only hosts such as'];
        yield 'a directive that takes no hosts' => [static fn (): Service => $service(directive: 'form-action', host: 'https://collector.example'), 'only fetch directives'];
        yield 'a policy over http' => [static fn (): Service => $service(policyUrl: 'http://vendor.example/privacy'), 'https:// address'];
        yield 'a script that isn\'t an asset' => [static fn (): Service => $service(script: 'https://vendor.example/tag.js'), 'among the assets'];
        yield 'a necessary service with a script' => [static fn (): Service => $service(category: Category::Necessary, script: 'acme/start.js'), 'is necessary and has a script'];
    }

    /**
     * @param \Closure(): Service $declare
     */
    #[DataProvider('mistakes')]
    public function testMistakesAreSaidWhenDeclared(\Closure $declare, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $declare();
    }

    public function testACookieFamilyEndsWithAStar(): void
    {
        self::assertSame('_ga_*', new Cookie('_ga_*', '2 years', 'Keeps the session.')->name);

        $this->expectException(\InvalidArgumentException::class);
        new Cookie('_ga *', '2 years', 'Keeps the session.');
    }
}
