<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

use InvalidArgumentException;

final readonly class SearchQuery
{
    /** @var list<string> */
    public array $accessScopes;

    /** @param array<mixed> $accessScopes */
    public function __construct(
        public string $term,
        public ?string $providerId = null,
        public ?string $locale = null,
        array $accessScopes = ['public'],
        public int $limit = 20,
    ) {
        if (trim($this->term) === '' || mb_strlen($this->term) > 200) {
            throw new InvalidArgumentException('search_query_term_invalid');
        }
        if ($this->providerId !== null && preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $this->providerId) !== 1) {
            throw new InvalidArgumentException('search_query_provider_invalid');
        }
        if ($this->locale !== null && preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/', $this->locale) !== 1) {
            throw new InvalidArgumentException('search_query_locale_invalid');
        }
        if ($accessScopes === [] || count($accessScopes) > 20 || $this->limit < 1 || $this->limit > 100) {
            throw new InvalidArgumentException('search_query_bounds_invalid');
        }
        $safeScopes = [];
        foreach ($accessScopes as $scope) {
            if (!is_string($scope) || trim($scope) === '' || strlen($scope) > 120) {
                throw new InvalidArgumentException('search_query_access_scope_invalid');
            }
            $safeScopes[] = $scope;
        }

        $this->accessScopes = $safeScopes;
    }
}
