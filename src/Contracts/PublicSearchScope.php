<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

use Illuminate\Http\Request;

/**
 * The access scope a public search page reads: the application binds it over its site resolution, so a site's page
 * searches that site's documents. Without a binding the page reads `public`, as a single site does.
 */
interface PublicSearchScope
{
    public function accessScope(Request $request): string;
}
