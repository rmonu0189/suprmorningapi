<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Uuid;
use App\Repositories\FileRepository;
use App\Repositories\OrderRepository;

final class InvoiceService
{
    private const MIME = 'application/pdf';

    /** @return array{id:string,url:string,mime:string,size:int,invoice_number:string} */
    public static function ensureForDeliveredOrder(string $orderId): array
    {
        $existing = OrderRepository::findInvoiceFileForOrder($orderId);
        if ($existing !== null) {
            return self::formatInvoicePayload($existing);
        }

        $order = OrderRepository::findByIdForAdmin($orderId);
        if ($order === null) {
            throw new HttpException('Order not found', 404);
        }
        if (strtolower((string) ($order['order_status'] ?? '')) !== 'delivered') {
            throw new HttpException('Invoice is available after delivery', 409);
        }
        if (strtolower((string) ($order['payment_status'] ?? '')) !== 'success') {
            throw new HttpException('Invoice is available only for successful payments', 409);
        }

        $orderCode = (string) (($order['order_code'] ?? '') ?: substr($orderId, 0, 8));
        $invoiceNumber = self::invoiceNumber($orderCode);
        $fileName = self::safeFileName($invoiceNumber) . '.pdf';
        $relativeDir = 'invoices/' . date('Y') . '/' . date('m');
        $storageRoot = __DIR__ . '/../../storage';
        $destDir = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeDir;

        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            throw new HttpException('Invoice storage unavailable', 500);
        }

        $pdf = self::buildPdf($order, $invoiceNumber);
        $destPath = $destDir . DIRECTORY_SEPARATOR . $fileName;
        if (file_put_contents($destPath, $pdf) === false) {
            throw new HttpException('Could not save invoice', 500);
        }
        @chmod($destPath, 0644);

        $fileId = Uuid::v4();
        $accessKey = bin2hex(random_bytes(32));
        $storagePath = $relativeDir . '/' . $fileName;
        $size = filesize($destPath);
        if ($size === false) {
            throw new HttpException('Could not read invoice', 500);
        }

        $createdBy = (string) ($order['user_id'] ?? '');
        FileRepository::insert(
            $fileId,
            $createdBy !== '' ? $createdBy : null,
            'invoice',
            $storagePath,
            $fileName,
            self::MIME,
            (int) $size,
            $accessKey
        );
        OrderRepository::attachInvoice($orderId, $fileId, $invoiceNumber);

        $file = FileRepository::findActiveById($fileId);
        if ($file === null) {
            throw new HttpException('Invoice unavailable', 500);
        }

        return self::formatInvoicePayload($file + ['invoice_number' => $invoiceNumber]);
    }

    /** @return array{id:string,url:string,mime:string,size:int,invoice_number:string}|null */
    public static function tryGenerateForDeliveredOrder(string $orderId): ?array
    {
        try {
            return self::ensureForDeliveredOrder($orderId);
        } catch (\Throwable $e) {
            OrderRepository::markInvoiceFailed($orderId, $e->getMessage());
            return null;
        }
    }

    /** @return array{id:string,url:string,mime:string,size:int,invoice_number:string} */
    public static function retryForDeliveredOrder(string $orderId): array
    {
        $order = OrderRepository::findByIdForAdmin($orderId);
        if ($order === null) {
            throw new HttpException('Order not found', 404);
        }
        if (strtolower((string) ($order['order_status'] ?? '')) !== 'delivered') {
            throw new HttpException('Invoice is available after delivery', 409);
        }
        if (strtolower((string) ($order['payment_status'] ?? '')) !== 'success') {
            throw new HttpException('Invoice is available only for successful payments', 409);
        }
        OrderRepository::markInvoicePending($orderId);
        try {
            return self::ensureForDeliveredOrder($orderId);
        } catch (\Throwable $e) {
            OrderRepository::markInvoiceFailed($orderId, $e->getMessage());
            throw $e;
        }
    }

    /** @return array{id:string,url:string,mime:string,size:int,invoice_number:string} */
    public static function ensureForUserDeliveredOrder(string $orderId, string $userId): array
    {
        if (OrderRepository::findByIdForUser($orderId, $userId) === null) {
            throw new HttpException('Not Found', 404);
        }
        return self::ensureForDeliveredOrder($orderId);
    }

    /** @param array<string,mixed> $file */
    private static function formatInvoicePayload(array $file): array
    {
        $invoiceNumber = (string) ($file['invoice_number'] ?? '');
        return [
            'id' => (string) $file['id'],
            'url' => self::fileUrl((string) $file['id'], (string) $file['access_key']),
            'mime' => (string) $file['mime'],
            'size' => (int) $file['size_bytes'],
            'invoice_number' => $invoiceNumber,
        ];
    }

    private static function invoiceNumber(string $orderCode): string
    {
        $compact = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $orderCode) ?? '');
        if ($compact === '') {
            $compact = strtoupper(bin2hex(random_bytes(4)));
        }
        $year = (int) date('Y');
        $month = (int) date('n');
        $start = $month >= 4 ? $year : $year - 1;
        $end = $start + 1;
        return 'SM/INV/' . $start . '-' . substr((string) $end, -2) . '/' . $compact;
    }

    private static function safeFileName(string $invoiceNumber): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $invoiceNumber) ?? 'invoice', '-'));
    }

    private static function fileUrl(string $id, string $accessKey): string
    {
        return self::baseUrl() . '/v1/files?id=' . rawurlencode($id) . '&key=' . rawurlencode($accessKey) . '&download=1';
    }

    private static function baseUrl(): string
    {
        $appUrl = Env::get('APP_URL', '');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocalHost = is_string($host) && ($host === 'localhost' || str_starts_with($host, 'localhost:') || str_starts_with($host, '127.0.0.1'));
        if ($appUrl !== '' && !$isLocalHost) {
            return rtrim($appUrl, '/');
        }

        $proto = 'http';
        $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (is_string($xfp) && $xfp !== '') {
            $proto = explode(',', $xfp)[0] ?: $proto;
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $proto = 'https';
        }
        $requestHost = is_string($host) && $host !== '' ? $host : 'localhost';
        return $proto . '://' . $requestHost;
    }

    /** @param array<string,mixed> $order */
    private static function buildPdf(array $order, string $invoiceNumber): string
    {
        return self::renderInvoicePdf($order, $invoiceNumber);
    }

    /** @param array<string,mixed> $order */
    private static function renderInvoicePdf(array $order, string $invoiceNumber): string
    {
        $items = is_array($order['order_items'] ?? null) ? $order['order_items'] : [];
        $bill = is_array($order['bill_summary'] ?? null) ? $order['bill_summary'] : [];
        $companyName = Env::get('INVOICE_SELLER_NAME', 'SuprMorning Private Limited');
        $companyAddress = Env::get('INVOICE_SELLER_ADDRESS', 'India');
        $gstin = Env::get('INVOICE_GSTIN', '');
        $fssai = Env::get('INVOICE_FSSAI', '');
        $stateCode = Env::get('INVOICE_STATE_CODE', '');
        $placeOfSupply = Env::get('INVOICE_PLACE_OF_SUPPLY', trim((string) ($order['state'] ?? '')) . ($stateCode !== '' ? ' (' . $stateCode . ')' : ''));
        $hsn = Env::get('INVOICE_DEFAULT_HSN', 'N/A');
        $cgstRate = max(0.0, (float) Env::get('INVOICE_CGST_RATE', '0'));
        $sgstRate = max(0.0, (float) Env::get('INVOICE_SGST_RATE', '0'));
        $orderCode = (string) (($order['order_code'] ?? '') ?: $order['id']);
        $createdAt = self::dateOnly((string) ($order['created_at'] ?? ''));
        $deliveredAt = self::dateOnly((string) (($order['delivered_at'] ?? '') ?: date('Y-m-d')));
        $addressLines = [
            (string) ($order['recipient_name'] ?? ''),
            (string) ($order['full_address'] ?? ''),
            trim((string) ($order['city'] ?? '') . ', ' . (string) ($order['state'] ?? '') . ' ' . (string) ($order['postal_code'] ?? '')),
            (string) ($order['country'] ?? 'India'),
        ];

        $content = '';
        $left = 28.0;
        $right = 567.0;
        $top = 805.0;

        self::text($content, 28, $top, 'Seller Name: ' . $companyName, 15, true);
        self::text($content, 28, $top - 18, $companyAddress, 8.5, true);
        self::text($content, 28, $top - 38, $gstin !== '' ? 'GSTIN: ' . $gstin : 'GSTIN: Not registered', 8.5, true);
        if ($fssai !== '') {
            self::text($content, 28, $top - 52, 'FSSAI: ' . $fssai, 8.5, true);
        }
        self::rect($content, 470, $top - 72, 68, 68);
        self::text($content, 485, $top - 35, 'QR', 18, true);
        self::text($content, 477, $top - 50, 'Invoice', 7, false);

        self::line($content, $left, 672, $right, 672, 1.2);
        self::text($content, 206, 660, 'TAX INVOICE/BILL OF SUPPLY', 10, true);
        self::rect($content, $left, 596, $right - $left, 56);
        self::line($content, 300, 596, 300, 652, 1);
        self::line($content, $left, 624, $right, 624, 1);
        self::text($content, 36, 634, 'Invoice No.: ' . $invoiceNumber, 8, true);
        self::text($content, 306, 634, 'Place Of Supply : ' . ($placeOfSupply !== '' ? strtoupper($placeOfSupply) : 'N/A'), 8, true);
        self::text($content, 36, 607, 'Order No.: ' . $orderCode, 8, true);
        self::text($content, 306, 607, 'Date : ' . $deliveredAt, 8, true);

        self::rect($content, $left, 520, $right - $left, 76);
        self::line($content, 300, 520, 300, 596, 1);
        self::line($content, $left, 576, $right, 576, 1);
        self::text($content, 36, 584, 'Bill To', 8, true);
        self::text($content, 308, 584, 'Ship To', 8, true);
        self::wrappedText($content, 36, 562, $addressLines, 245, 8, true);
        self::wrappedText($content, 308, 562, $addressLines, 245, 8, false);

        $tableTop = 510.0;
        $headers = ['SR', 'Item & Description', 'Unit MRP/RSP', 'HSN', 'Qty', 'Product Rate', 'Disc.', 'Taxable Amt.', 'CGST', 'S/UT GST', 'CGST Amt.', 'S/UT GST Amt.', 'Cess', 'Cess Amt.', 'Total Amt.'];
        $widths = [18, 74, 44, 38, 22, 42, 34, 44, 30, 34, 38, 42, 28, 34, 57];
        $x = $left;
        self::rect($content, $left, $tableTop - 35, array_sum($widths), 35);
        foreach ($headers as $i => $h) {
            self::line($content, $x, $tableTop - 35, $x, $tableTop, 1);
            self::wrappedText($content, $x + 3, $tableTop - 11, [$h], $widths[$i] - 6, 7, true, 8);
            $x += $widths[$i];
        }
        self::line($content, $x, $tableTop - 35, $x, $tableTop, 1);

        $y = $tableTop - 35;
        $sumTaxable = 0.0;
        $sumCgst = 0.0;
        $sumSgst = 0.0;
        $sumCess = 0.0;
        $sumTotal = 0.0;
        foreach (array_values($items) as $idx => $item) {
            if (!is_array($item)) continue;
            $name = trim((string) ($item['brand_name'] ?? '') . ' ' . (string) ($item['product_name'] ?? '') . ' ' . (string) ($item['variant_name'] ?? ''));
            $qty = (int) ($item['quantity'] ?? 0);
            $rate = (float) ($item['unit_price'] ?? 0);
            $mrp = (float) ($item['unit_mrp'] ?? $rate);
            $lineTotal = round($qty * $rate, 2);
            $taxRate = $cgstRate + $sgstRate;
            $taxable = $taxRate > 0 ? round($lineTotal / (1 + ($taxRate / 100)), 2) : $lineTotal;
            $cgst = round($taxable * ($cgstRate / 100), 2);
            $sgst = round($taxable * ($sgstRate / 100), 2);
            $disc = $mrp > 0 ? max(0.0, round((($mrp - $rate) / $mrp) * 100, 2)) : 0.0;
            $sumTaxable += $taxable;
            $sumCgst += $cgst;
            $sumSgst += $sgst;
            $sumTotal += $lineTotal;

            $rowHeight = max(52.0, 22.0 + (count(self::wrap($name, 74, 7)) * 10.0));
            if ($y - $rowHeight < 175) {
                self::text($content, 36, $y - 16, 'Additional items are included in the invoice value.', 8, false);
                break;
            }
            $x = $left;
            self::rect($content, $left, $y - $rowHeight, array_sum($widths), $rowHeight);
            foreach ($widths as $w) {
                self::line($content, $x, $y - $rowHeight, $x, $y, 1);
                $x += $w;
            }
            self::line($content, $x, $y - $rowHeight, $x, $y, 1);
            $vals = [
                (string) ($idx + 1),
                $name,
                self::amount($mrp),
                $hsn,
                (string) $qty,
                self::amount($rate),
                number_format($disc, 2) . '%',
                self::amount($taxable),
                number_format($cgstRate, 2) . '%',
                number_format($sgstRate, 2) . '%',
                self::amount($cgst),
                self::amount($sgst),
                '0.00%',
                self::amount(0),
                self::amount($lineTotal),
            ];
            $x = $left;
            foreach ($vals as $i => $v) {
                $alignCenter = in_array($i, [0, 4], true);
                self::wrappedText($content, $x + 3, $y - 16, [$v], $widths[$i] - 6, 7, false, 9, $alignCenter);
                $x += $widths[$i];
            }
            $y -= $rowHeight;
        }

        $charges = is_array($bill['charges'] ?? null) ? $bill['charges'] : [];
        $chargeTotal = 0.0;
        foreach ($charges as $charge) {
            if (!is_array($charge)) continue;
            $chargeTotal += (float) ($charge['applied_amount'] ?? 0);
        }
        $discount = (float) ($bill['coupon_discount'] ?? $order['coupon_discount'] ?? 0);
        $grandTotal = (float) ($order['grand_total'] ?? 0);
        self::rect($content, $left, $y - 18, array_sum($widths), 18);
        self::text($content, 314, $y - 12, self::amount($sumTaxable), 7, false);
        self::text($content, 420, $y - 12, self::amount($sumCgst), 7, false);
        self::text($content, 468, $y - 12, self::amount($sumSgst), 7, false);
        self::text($content, 510, $y - 12, self::amount($sumCess), 7, false);
        self::text($content, 548, $y - 12, self::amount($sumTotal), 7, false);

        $summaryY = $y - 34;
        self::text($content, $left, $summaryY, 'Item Total', 8.5, true);
        self::textRight($content, $right, $summaryY, self::amount((float) ($bill['items_price'] ?? $order['total_price'] ?? $sumTotal)), 8.5, true);
        $summaryY -= 14;
        if ($chargeTotal > 0) {
            self::text($content, $left, $summaryY, 'Delivery/Other Charges', 8, false);
            self::textRight($content, $right, $summaryY, self::amount($chargeTotal), 8, false);
            $summaryY -= 14;
        }
        if ($discount > 0) {
            self::text($content, $left, $summaryY, 'Discount', 8, false);
            self::textRight($content, $right, $summaryY, '-' . self::amount($discount), 8, false);
            $summaryY -= 14;
        }
        self::line($content, $left, $summaryY + 7, $right, $summaryY + 7, 1);
        self::text($content, $left, $summaryY, 'Invoice Value', 8.5, true);
        self::textRight($content, $right, $summaryY, self::amount($grandTotal), 8.5, true);
        self::line($content, $left, $summaryY - 8, $right, $summaryY - 8, 1);

        self::text($content, $left, 126, 'Whether GST is payable on reverse-charge - No.', 7.2, false);
        self::text($content, $left, 106, 'For IMEI / Serial number information, please refer to packaging / warranty slip.', 7.2, false);
        self::text($content, $left, 86, 'Note: This is a computer generated invoice. Product tax details are calculated from configured invoice tax rates.', 7, false);
        self::text($content, $left, 54, 'Order Delivered From -', 7.5, true);
        self::text($content, $left, 42, $companyName, 7, false);
        self::text($content, $left, 30, self::clip($companyAddress, 58), 7, false);
        self::text($content, 340, 54, 'E-commerce Platform Information -', 7.5, true);
        self::text($content, 340, 42, Env::get('INVOICE_PLATFORM_NAME', 'SuprMorning'), 7, false);
        self::text($content, 340, 30, Env::get('INVOICE_PLATFORM_EMAIL', 'support@suprmorning.com'), 7, false);

        return self::pdfDocument([$content]);
    }

    /** @param list<string> $pageContents */
    private static function pdfDocument(array $pageContents): string
    {
        $kids = [];
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        ];
        $nextObject = 3;
        foreach ($pageContents as $pageContent) {
            $pageId = $nextObject++;
            $contentId = $nextObject++;
            $kids[] = $pageId . ' 0 R';
            $objects[] = $pageId . " 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 100 0 R /F2 101 0 R >> >> /Contents " . $contentId . " 0 R >>\nendobj\n";
            $objects[] = $contentId . " 0 obj\n<< /Length " . strlen($pageContent) . " >>\nstream\n" . $pageContent . "endstream\nendobj\n";
        }
        array_splice($objects, 1, 0, "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($kids) . " >>\nendobj\n");
        $objects[] = "100 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $objects[] = "101 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $object) {
            if (preg_match('/^(\d+) 0 obj/', $object, $m)) {
                $offsets[(int) $m[1]] = strlen($pdf);
            }
            $pdf .= $object;
        }
        $maxObject = max(array_keys($offsets));
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObject; $i++) {
            $off = $offsets[$i] ?? 0;
            $pdf .= str_pad((string) $off, 10, '0', STR_PAD_LEFT) . " 00000 " . ($off > 0 ? 'n' : 'f') . " \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    private static function text(string &$content, float $x, float $y, string $text, float $size = 8, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $content .= "BT /{$font} " . self::num($size) . " Tf " . self::num($x) . ' ' . self::num($y) . ' Td (' . self::pdfEscape(self::ascii($text)) . ") Tj ET\n";
    }

    private static function textRight(string &$content, float $right, float $y, string $text, float $size = 8, bool $bold = false): void
    {
        $x = $right - (strlen(self::ascii($text)) * $size * 0.52);
        self::text($content, max(20, $x), $y, $text, $size, $bold);
    }

    /** @param list<string> $lines */
    private static function wrappedText(string &$content, float $x, float $y, array $lines, float $width, float $size, bool $bold = false, float $lineHeight = 10, bool $center = false): void
    {
        $cursor = $y;
        foreach ($lines as $line) {
            foreach (self::wrap($line, $width, $size) as $part) {
                $tx = $center ? $x + max(0, ($width - strlen($part) * $size * 0.52) / 2) : $x;
                self::text($content, $tx, $cursor, $part, $size, $bold);
                $cursor -= $lineHeight;
            }
        }
    }

    /** @return list<string> */
    private static function wrap(string $text, float $width, float $size): array
    {
        $max = max(6, (int) floor($width / ($size * 0.52)));
        $words = preg_split('/\s+/', trim(self::ascii($text))) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            if ($line === '') {
                $line = $word;
            } elseif (strlen($line . ' ' . $word) <= $max) {
                $line .= ' ' . $word;
            } else {
                $lines[] = self::clip($line, $max);
                $line = $word;
            }
        }
        if ($line !== '') {
            $lines[] = self::clip($line, $max);
        }
        return $lines !== [] ? $lines : [''];
    }

    private static function rect(string &$content, float $x, float $y, float $w, float $h): void
    {
        $content .= self::num($x) . ' ' . self::num($y) . ' ' . self::num($w) . ' ' . self::num($h) . " re S\n";
    }

    private static function line(string &$content, float $x1, float $y1, float $x2, float $y2, float $width = 1): void
    {
        $content .= self::num($width) . ' w ' . self::num($x1) . ' ' . self::num($y1) . ' m ' . self::num($x2) . ' ' . self::num($y2) . " l S\n";
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    private static function ascii(string $s): string
    {
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        return preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
    }

    private static function pdfEscape(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private static function money(float $amount): string
    {
        return 'INR ' . number_format($amount, 2, '.', ',');
    }

    private static function amount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private static function clip(string $s, int $len): string
    {
        return strlen($s) <= $len ? $s : substr($s, 0, max(0, $len - 3)) . '...';
    }

    private static function dateOnly(string $raw): string
    {
        $ts = strtotime($raw);
        return $ts === false ? date('d M Y') : date('d M Y', $ts);
    }
}
