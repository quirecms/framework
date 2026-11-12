<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Quire\Storage\Internal\DiskFactory;
use Quire\Storage\Internal\DiskSources;
use Quire\Storage\StorageDisks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\UriSigner;

/**
 * The site's public disks that are elsewhere get into its pages' policy, so their images show.
 */
final class DiskSourcesTest extends KernelTestCase
{
    public function testPublicDisksElsewhereAreAllowedForImagesAndMedia(): void
    {
        self::bootKernel();
        $s3 = ['adapter' => 's3', 'bucket' => 'photos', 'region' => 'eu-central-1', 'endpoint' => null, 'access_key' => 'test', 'secret_key' => 'test', 'path_style' => false, 'url' => null, 'label' => null, 'owner' => 'site', 'prefix' => '', 'path' => null, 'service' => null];
        $local = ['adapter' => 'local', 'url' => null, 'label' => null, 'owner' => 'site', 'path' => '/tmp/quire-disk-sources', 'service' => null];
        $disks = new StorageDisks([
            'photos' => [...$s3, 'public' => true],
            'behind-cdn' => [...$s3, 'bucket' => 'videos', 'public' => true, 'url' => 'https://Media.Example-CDN.com:8443/videos'],
            'downloads' => [...$s3, 'bucket' => 'downloads', 'public' => false],
            'uploads' => [...$local, 'public' => true],
        ], new DiskFactory(self::getContainer()->get('router'), new UriSigner('test')), new ServiceLocator([]));

        $sources = new DiskSources($disks)->sources();

        self::assertSame(['img-src', 'media-src'], array_keys($sources));
        self::assertEqualsCanonicalizing(['https://photos.s3.eu-central-1.amazonaws.com', 'https://media.example-cdn.com:8443'], $sources['img-src']);
        self::assertSame($sources['img-src'], $sources['media-src']);
    }

    public function testOnlyLocalDisksNeedNothing(): void
    {
        self::bootKernel();
        $disks = new StorageDisks(['uploads' => ['adapter' => 'local', 'public' => true, 'url' => null, 'label' => null, 'owner' => 'site', 'path' => '/tmp/quire-disk-sources', 'service' => null]], new DiskFactory(self::getContainer()->get('router'), new UriSigner('test')), new ServiceLocator([]));

        self::assertSame([], new DiskSources($disks)->sources(), 'Local disks are served by the site itself.');
    }
}
