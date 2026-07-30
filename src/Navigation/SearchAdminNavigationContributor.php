<?php

declare(strict_types=1);

namespace Larena\Search\Navigation;

use Larena\Admin\Contracts\AdminNavigationContributor;
use Larena\Admin\Navigation\AdminNavigationDescriptor;

final class SearchAdminNavigationContributor implements AdminNavigationContributor
{
    public function ownerPackage(): string { return 'larena/search'; }

    public function navigationDescriptors(): array
    {
        return [new AdminNavigationDescriptor(
            id: 'search.operations', ownerPackage: $this->ownerPackage(), label: 'Search index',
            routeName: 'larena.search.admin.index', routeUri: '/admin/search', category: 'operations',
            state: 'operator_slice', accessScope: 'search.reindex.read', auditEvent: 'search.reindex.index_viewed',
            statusCap: 'public_search_index_operations', order: 50, group: 'operations',
            knownLimitations: ['not_production_ready', 'frontend_not_complete'], surface: 'product',
            labelKey: 'larena-search::admin.navigation', activeRoutePattern: 'larena.search.admin.*',
        )];
    }
}
