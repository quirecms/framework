<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Quire\Content\Content;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\RelationLoaderInterface;

/**
 * A relation from the extension's own table: the names of who liked each post, in one query per page.
 */
final readonly class LikesLoader implements RelationLoaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function load(array $items, ContentQuery $query): array
    {
        $likes = array_fill_keys(array_map(static fn (Content $item): string => $item->id, $items), []);

        /** @var list<array{content_id: string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT content_id, name FROM quire.quire_fixture_hello_like WHERE content_id IN (?) ORDER BY id',
            [array_keys($likes)],
            [ArrayParameterType::STRING],
        );

        foreach ($rows as $row) {
            $likes[$row['content_id']][] = $row['name'];
        }

        return $likes;
    }
}
