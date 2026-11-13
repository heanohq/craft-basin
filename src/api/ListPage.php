<?php

namespace heano\basin\api;

/**
 * One page of a Basin list endpoint.
 *
 * Basin wraps lists as `{"<resource>": [...], "meta": {"count", "page", "per_page", ...}}`.
 * Page size is fixed at 100 (`per_page` is ignored), and `meta.page` comes back
 * as a string when it was passed in the query.
 */
final class ListPage
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $meta Raw `meta` object (submissions also include `inbox_count`, `spam_count`, `trash_count`).
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page = 1,
        public readonly ?int $perPage = null,
        public readonly ?int $total = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public static function fromMeta(array $items, mixed $meta, int $requestedPage): self
    {
        $meta = is_array($meta) ? $meta : [];
        $int = fn(string $key) => isset($meta[$key]) && is_numeric($meta[$key]) ? (int)$meta[$key] : null;

        return new self(
            items: $items,
            page: $int('page') ?? $requestedPage,
            perPage: $int('per_page'),
            total: $int('count'),
            meta: $meta,
        );
    }

    /**
     * Whether another page is worth requesting. Without metadata, only a
     * non-empty page suggests there may be more.
     */
    public function hasMore(): bool
    {
        if ($this->items === []) {
            return false;
        }
        if ($this->total !== null && $this->perPage !== null && $this->perPage > 0) {
            return $this->page * $this->perPage < $this->total;
        }
        return true;
    }
}
