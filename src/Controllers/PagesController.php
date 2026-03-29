<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\PageRepository;
use JsonException;
use PDOException;

final class PagesController
{
    /** List all pages, or filter by ?page_name= — or single ?id=uuid */
    public function index(Request $request): void
    {
        $id = $request->query('id');
        if ($id !== null && trim($id) !== '') {
            $id = trim($id);
            if (!Uuid::isValid($id)) {
                Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Must be a valid UUID']], 422);
                return;
            }

            $page = PageRepository::findById($id);
            if ($page === null) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }

            Response::json(['page' => $page]);
            return;
        }

        $pageName = $request->query('page_name');
        $pages = PageRepository::findAll($pageName);
        Response::json(['pages' => $pages]);
    }

    public function create(Request $request): void
    {
        if (!$this->requireAdmin($request)) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $pageName = trim((string) ($body['page_name'] ?? ''));
        $type = trim((string) ($body['type'] ?? ''));
        $content = $body['content'] ?? null;

        if ($pageName === '') {
            throw new ValidationException('Invalid page_name', ['page_name' => 'Required.']);
        }
        if ($type === '') {
            throw new ValidationException('Invalid type', ['type' => 'Required.']);
        }
        if (!is_array($content)) {
            throw new ValidationException('Invalid content', ['content' => 'Must be a JSON object or array.']);
        }

        $cardIndex = (int) ($body['card_index'] ?? 0);

        $id = Uuid::v4();

        try {
            PageRepository::insert($id, $pageName, $type, $content, $cardIndex);
        } catch (JsonException $e) {
            throw new ValidationException('Invalid content', ['content' => 'Could not encode JSON.']);
        } catch (PDOException $e) {
            throw new HttpException('Could not create page', 500);
        }

        $page = PageRepository::findById($id);
        if ($page === null) {
            throw new HttpException('Could not create page', 500);
        }

        Response::json(['page' => $page], 201);
    }

    public function update(Request $request): void
    {
        if (!$this->requireAdmin($request)) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        }

        if (PageRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $pageName = null;
        if (array_key_exists('page_name', $body)) {
            $v = trim((string) $body['page_name']);
            if ($v === '') {
                throw new ValidationException('Invalid page_name', ['page_name' => 'Cannot be empty.']);
            }
            $pageName = $v;
        }

        $type = null;
        if (array_key_exists('type', $body)) {
            $v = trim((string) $body['type']);
            if ($v === '') {
                throw new ValidationException('Invalid type', ['type' => 'Cannot be empty.']);
            }
            $type = $v;
        }

        $content = null;
        if (array_key_exists('content', $body)) {
            if (!is_array($body['content'])) {
                throw new ValidationException('Invalid content', ['content' => 'Must be a JSON object or array.']);
            }
            $content = $body['content'];
        }

        $cardIndex = null;
        if (array_key_exists('card_index', $body)) {
            $cardIndex = (int) $body['card_index'];
        }

        if ($pageName === null && $type === null && $content === null && $cardIndex === null) {
            throw new ValidationException('Nothing to update', [
                'body' => 'Provide at least one of: page_name, type, content, card_index.',
            ]);
        }

        try {
            PageRepository::update($id, $pageName, $type, $content, $cardIndex);
        } catch (JsonException $e) {
            throw new ValidationException('Invalid content', ['content' => 'Could not encode JSON.']);
        }

        $page = PageRepository::findById($id);
        if ($page === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['page' => $page]);
    }

    public function delete(Request $request): void
    {
        if (!$this->requireAdmin($request)) {
            return;
        }

        $id = trim((string) ($request->query('id') ?? ''));
        if ($id === '') {
            $body = $request->json();
            $id = trim((string) ($body['id'] ?? ''));
        }

        if ($id === '' || !Uuid::isValid($id)) {
            Response::json([
                'error' => 'Invalid id',
                'errors' => ['id' => 'Provide a valid UUID via ?id= or JSON body.'],
            ], 422);
            return;
        }

        if (!PageRepository::delete($id)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['ok' => true]);
    }

    private function requireAdmin(Request $request): bool
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return false;
        }

        return AuthMiddleware::requireRole($claims, 'admin');
    }
}
