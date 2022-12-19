<?php

namespace Outl1ne\NovaMediaHub\Filters;

use Closure;

class Collection
{
    public function handle($query, Closure $next)
    {
        if (empty(request()->get('collection')) || request()->get('collection') === config('nova-media-hub.show_all_collections_text')) {
            return $next($query);
        }

        return $next($query)->where('collection_name', request()->get('collection'));
    }
}
