<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\Exceptions\ValidationException;
use App\Middleware\AuthMiddleware;
use PDO;

final class AdminNotificationController
{
    public function index(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $page = max(0, (int) ($request->query('page') ?? '0'));
        $limit = min(100, max(1, (int) ($request->query('limit') ?? '20')));
        $offset = $page * $limit;

        $titleSearch = trim((string) ($request->query('title') ?? ''));
        $subtitleSearch = trim((string) ($request->query('subtitle') ?? ''));

        $where = [];
        $params = [];

        if ($titleSearch !== '') {
            $where[] = 'n.title LIKE :title';
            $params['title'] = '%' . $titleSearch . '%';
        }
        if ($subtitleSearch !== '') {
            $where[] = 'n.subtitle LIKE :subtitle';
            $params['subtitle'] = '%' . $subtitleSearch . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $db = Database::connection();

        // Get total count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM sent_notifications n $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get notifications with user info
        $sql = "
            SELECT 
                n.*, 
                u.full_name as user_name, 
                u.phone as user_phone 
            FROM sent_notifications n
            LEFT JOIN users u ON n.user_id = u.id
            $whereSql
            ORDER BY n.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::json([
            'notifications' => $notifications,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ]);
    }

    public function send(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $userId = trim((string) ($body['user_id'] ?? ''));
        $title = trim((string) ($body['title'] ?? ''));
        $subtitle = trim((string) ($body['subtitle'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));
        $data = $body['data'] ?? [];

        if ($userId === '' || !\App\Core\Uuid::isValid($userId)) {
             throw new ValidationException('Invalid user_id', ['user_id' => 'Required.']);
        }
        if ($title === '') {
             throw new ValidationException('Invalid title', ['title' => 'Required.']);
        }
        if ($message === '') {
             throw new ValidationException('Invalid message', ['message' => 'Required.']);
        }

        \App\Services\PushNotificationService::sendToUser($userId, $title, $message, $data, $subtitle === '' ? null : $subtitle);

        Response::json(['ok' => true]);
    }
}
