<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\FileRepository;
use App\Repositories\OrderSupportRepository;

final class OrderSupportController
{
    private const MAX_ATTACHMENT_BYTES = 10_000_000;
    private const ALLOWED_ATTACHMENT_MIME = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public function byOrder(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        $orderId = trim((string) ($request->query('order_id') ?? ''));
        if ($orderId === '') {
            Response::json(['queries' => OrderSupportRepository::findAllForUser($userId)]);
            return;
        }
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            Response::json(['error' => 'Invalid order_id'], 422);
            return;
        }
        if (!OrderSupportRepository::findOrderOwnerForUser($orderId, $userId)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        $query = OrderSupportRepository::findQueryByOrderIdForUser($orderId, $userId);
        if ($query === null) {
            Response::json(['query' => null, 'messages' => []]);
            return;
        }
        Response::json(['query' => $query, 'messages' => OrderSupportRepository::findMessages((string) $query['id'])]);
    }

    public function createOrMessage(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $subject = trim((string) ($body['subject'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));
        $attachments = $this->attachmentsFromBody($body);
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'Must be a valid UUID.']);
        }
        if ($message === '') {
            throw new ValidationException('Invalid message', ['message' => 'Message is required.']);
        }
        if ($this->textLength($message) > 5000) {
            throw new ValidationException('Invalid message', ['message' => 'Message must be 5000 characters or less.']);
        }
        if ($subject !== '' && $this->textLength($subject) > 255) {
            throw new ValidationException('Invalid subject', ['subject' => 'Subject must be 255 characters or less.']);
        }
        if (!OrderSupportRepository::findOrderOwnerForUser($orderId, $userId)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $query = OrderSupportRepository::findQueryByOrderIdForUser($orderId, $userId);
        if ($query === null) {
            $queryId = Uuid::v4();
            OrderSupportRepository::createQuery(
                $queryId,
                $orderId,
                $userId,
                $subject === '' ? null : $subject,
                OrderSupportRepository::STATUS_OPEN
            );
        } else {
            $queryId = (string) $query['id'];
        }
        OrderSupportRepository::addMessage(Uuid::v4(), $queryId, $userId, 'customer', $message, $attachments);
        OrderSupportRepository::updateStatus($queryId, OrderSupportRepository::STATUS_WAITING_ADMIN, null);

        $query = OrderSupportRepository::findQueryByOrderIdForUser($orderId, $userId);
        Response::json([
            'query' => $query,
            'messages' => $query === null ? [] : OrderSupportRepository::findMessages((string) $query['id']),
        ], 201);
    }

    public function uploadAttachment(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        if (!isset($_FILES['file'])) {
            Response::json(['error' => 'Missing file', 'errors' => ['file' => 'Provide multipart field "file".']], 422);
            return;
        }

        $f = $_FILES['file'];
        if (!is_array($f) || !isset($f['tmp_name'], $f['size'], $f['error'])) {
            Response::json(['error' => 'Invalid upload'], 422);
            return;
        }
        if ((int) $f['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'Upload failed', 'errors' => ['file' => 'Upload error code: ' . (string) $f['error']]], 422);
            return;
        }
        $size = (int) ($f['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_ATTACHMENT_BYTES) {
            Response::json(['error' => 'Invalid file size', 'errors' => ['file' => 'Max 10MB.']], 422);
            return;
        }
        $tmp = (string) $f['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::json(['error' => 'Invalid file'], 422);
            return;
        }
        $mime = $this->detectMime($tmp);
        if ($mime === null || !array_key_exists($mime, self::ALLOWED_ATTACHMENT_MIME)) {
            Response::json(['error' => 'Unsupported file type', 'errors' => ['file' => 'Only images are allowed.']], 422);
            return;
        }

        $storageRoot = __DIR__ . '/../../storage';
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0775, true) && !is_dir($storageRoot)) {
            throw new HttpException('Upload storage unavailable', 500);
        }

        $relativeDir = 'uploads/support';
        $destDir = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeDir;
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            throw new HttpException('Could not create upload directory', 500);
        }

        $ext = self::ALLOWED_ATTACHMENT_MIME[$mime];
        $fileName = time() . '-' . bin2hex(random_bytes(10)) . '.' . $ext;
        $destPath = $destDir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($tmp, $destPath)) {
            throw new HttpException('Could not save file', 500);
        }
        @chmod($destPath, 0644);

        $id = Uuid::v4();
        $accessKey = bin2hex(random_bytes(32));
        $createdBy = (string) ($claims['sub'] ?? '');
        if ($createdBy === '') {
            $createdBy = null;
        }
        $originalName = isset($f['name']) && is_string($f['name']) ? $f['name'] : null;
        FileRepository::insert(
            $id,
            $createdBy,
            'support',
            $relativeDir . '/' . $fileName,
            $originalName,
            $mime,
            $size,
            $accessKey
        );

        Response::json([
            'id' => $id,
            'url' => $this->publicFileUrl($id, $accessKey),
            'mime' => $mime,
            'size' => $size,
        ], 201);
    }

    public function adminIndex(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }
        $orderId = trim((string) ($request->query('order_id') ?? ''));
        if ($orderId !== '') {
            if (!Uuid::isValid($orderId)) {
                Response::json(['error' => 'Invalid order_id'], 422);
                return;
            }
            $query = OrderSupportRepository::findQueryByOrderId($orderId);
            Response::json([
                'query' => $query,
                'messages' => $query === null ? [] : OrderSupportRepository::findMessages((string) $query['id']),
            ]);
            return;
        }

        $page = max(0, (int) ($request->query('page') ?? '0'));
        $limit = min(50, max(1, (int) ($request->query('limit') ?? '20')));
        $status = trim((string) ($request->query('status') ?? ''));
        $statusFilter = $status === '' ? null : $status;
        if ($statusFilter !== null && !in_array($statusFilter, OrderSupportRepository::statuses(), true)) {
            Response::json(['error' => 'Invalid status'], 422);
            return;
        }
        Response::json([
            'queries' => OrderSupportRepository::findAllForAdmin($page * $limit, $limit, $statusFilter),
            'total' => OrderSupportRepository::countAllForAdmin($statusFilter),
            'page' => $page,
            'limit' => $limit,
            'statuses' => OrderSupportRepository::statuses(),
        ]);
    }

    public function adminReply(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'Must be a valid UUID.']);
        }
        if ($message === '') {
            throw new ValidationException('Invalid message', ['message' => 'Message is required.']);
        }
        if ($this->textLength($message) > 5000) {
            throw new ValidationException('Invalid message', ['message' => 'Message must be 5000 characters or less.']);
        }
        $query = OrderSupportRepository::findQueryByOrderId($orderId);
        if ($query === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        $queryId = (string) $query['id'];
        $actor = (string) ($claims['sub'] ?? '');
        $role = (string) ($claims['role'] ?? 'admin');
        OrderSupportRepository::addMessage(Uuid::v4(), $queryId, $actor, $role === '' ? 'admin' : $role, $message);
        OrderSupportRepository::updateStatus($queryId, OrderSupportRepository::STATUS_ANSWERED, null);

        $query = OrderSupportRepository::findQueryByOrderId($orderId);
        Response::json([
            'query' => $query,
            'messages' => $query === null ? [] : OrderSupportRepository::findMessages((string) $query['id']),
        ]);
    }

    public function adminPatch(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $status = trim((string) ($body['status'] ?? ''));
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'Must be a valid UUID.']);
        }
        if (!in_array($status, OrderSupportRepository::statuses(), true)) {
            throw new ValidationException('Invalid status', ['status' => 'Unsupported support status.']);
        }
        $query = OrderSupportRepository::findQueryByOrderId($orderId);
        if ($query === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        OrderSupportRepository::updateStatus((string) $query['id'], $status, (string) ($claims['sub'] ?? ''));
        $query = OrderSupportRepository::findQueryByOrderId($orderId);
        Response::json([
            'query' => $query,
            'messages' => $query === null ? [] : OrderSupportRepository::findMessages((string) $query['id']),
        ]);
    }

    /** @return list<string> */
    private function attachmentsFromBody(array $body): array
    {
        $raw = $body['attachments'] ?? [];
        if (!is_array($raw)) {
            throw new ValidationException('Invalid attachments', ['attachments' => 'Must be an array of image URLs.']);
        }
        if (count($raw) > 6) {
            throw new ValidationException('Invalid attachments', ['attachments' => 'Attach up to 6 images.']);
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item) || trim($item) === '') {
                continue;
            }
            $url = trim($item);
            if (strlen($url) > 2048) {
                throw new ValidationException('Invalid attachment', ['attachments' => 'Attachment URL is too long.']);
            }
            $out[] = $url;
        }
        return $out;
    }

    private function detectMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $path);
        if (function_exists('finfo_close')) {
            @finfo_close($finfo);
        }
        return is_string($mime) ? $mime : null;
    }

    private function publicFileUrl(string $id, string $accessKey): string
    {
        $appUrl = Env::get('APP_URL', '');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocalHost = is_string($host) && ($host === 'localhost' || str_starts_with($host, 'localhost:') || str_starts_with($host, '127.0.0.1'));
        $base = ($appUrl !== '' && !$isLocalHost) ? rtrim($appUrl, '/') : $this->inferBaseUrl();
        return $base . '/v1/files?id=' . $id . '&key=' . $accessKey;
    }

    private function inferBaseUrl(): string
    {
        $proto = 'https';
        $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (is_string($xfp) && $xfp !== '') {
            $proto = explode(',', $xfp)[0] ?: $proto;
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $proto = 'https';
        } else {
            $proto = 'http';
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!is_string($host) || $host === '') {
            $host = 'localhost';
        }
        return $proto . '://' . $host;
    }
}
