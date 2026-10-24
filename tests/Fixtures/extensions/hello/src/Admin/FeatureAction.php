<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Admin;

use Quire\Admin\Content\ContentActionException;
use Quire\Admin\Content\ContentActionInterface;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Features a post: for those who may publish posts, on posts that aren't featured. Posts titled
 * "Unfeaturable" refuse, to show how an action reports why.
 */
final readonly class FeatureAction implements ContentActionInterface
{
    public function __construct(
        private ContentWriter $writer,
        private Security $security,
    ) {
    }

    public function label(): string
    {
        return 'Feature';
    }

    public function isAvailable(Content $item): bool
    {
        return true !== ($item->fields['featured'] ?? false) && $this->security->isGranted('content.post.publish');
    }

    public function apply(Content $item): void
    {
        if ('Unfeaturable' === $item->title) {
            throw new ContentActionException('This post can\'t be featured.');
        }

        $this->writer->update(new UpdateContent($item->id, $item->version)->field('featured', true));
    }
}
