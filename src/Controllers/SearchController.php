<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\GlobalSearchRepository;

final class SearchController
{
    /** Global catalog search (brands, categories, subcategories, variants) with fuzzy ranking. */
    public function index(Request $request): void
    {
        if (AuthMiddleware::requireAuth($request) === null) {
            return;
        }

        $raw = (string) ($request->query('q') ?? $request->query('query') ?? '');
        $limit = (int) ($request->query('limit') ?? 18);
        $limit = max(5, min(40, $limit));

        $out = GlobalSearchRepository::globalSearch($raw, $limit);
        Response::json($out);
    }
}
