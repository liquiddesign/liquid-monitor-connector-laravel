<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LiquidMonitorConnector\Http\Middleware\AuthorizeMonitorRequest;
use LiquidMonitorConnector\LogViewer\InvalidPathException;
use LiquidMonitorConnector\LogViewer\LogReader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * JSON REST API for browsing application logs, proxied by the monitor's
 * `TriageLogsController`. 1:1 port of `LogViewer\LogViewerApiPresenter` from
 * `liquiddesign/nette-log-viewer` — identical endpoints, params, response shapes
 * and error codes/messages, documented in that package's `log-viewer-api` skill
 * (which therefore applies unmodified to a Laravel-hosted connector too):
 *
 *   GET /list      path?, page=1, search?, itemsPerPage=100  -> directory listing
 *   GET /stat      file                                       -> file metadata
 *   GET /view      file, page=1                                -> paginated content
 *   GET /search    file, q, context=5, direction=both          -> content around first match
 *   GET /download  file                                        -> raw file bytes
 *
 * Access is gated by {@see AuthorizeMonitorRequest}
 * (IP allowlist + mandatory token) rather than Nette's Tracy-debug-mode trick.
 */
final class LogViewerApiController
{
    private const HTML_VIEW_LIMIT = 5 * 1024 * 1024;

    private ?LogReader $cachedReader = null;

    public function list(Request $request): JsonResponse
    {
        $path = $request->query('path');
        $page = (int) $request->query('page', 1);
        $search = $request->query('search');
        $itemsPerPage = (int) $request->query('itemsPerPage', LogReader::DEFAULT_ITEMS_PER_PAGE);

        try {
            $relativePath = $this->reader()->validatePath(\is_string($path) ? $path : null);
            $allItems = $this->reader()->listDirectory($relativePath);
        } catch (InvalidPathException $e) {
            return $this->error(400, $e->getMessage());
        }

        if (\is_string($search) && $search !== '') {
            $searchLower = \mb_strtolower($search);
            $allItems = \array_values(\array_filter(
                $allItems,
                static fn (array $item): bool => \str_contains(\mb_strtolower($item['name']), $searchLower),
            ));
        }

        \usort($allItems, static function (array $a, array $b): int {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $b['is_dir'] <=> $a['is_dir'];
            }

            return ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0);
        });

        if ($itemsPerPage < 1) {
            $itemsPerPage = LogReader::DEFAULT_ITEMS_PER_PAGE;
        }

        if ($itemsPerPage > 1000) {
            $itemsPerPage = 1000;
        }

        $totalItems = \count($allItems);
        $totalPages = (int) \ceil($totalItems / $itemsPerPage);

        if ($page < 1) {
            $page = 1;
        }

        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $itemsPerPage;
        $items = \array_slice($allItems, $offset, $itemsPerPage);

        return response()->json([
            'path' => $relativePath,
            'items' => $items,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'itemsPerPage' => $itemsPerPage,
            'search' => \is_string($search) && $search !== '' ? $search : null,
        ]);
    }

    public function stat(Request $request): JsonResponse
    {
        $file = $this->requireFileParam($request);

        if ($file instanceof JsonResponse) {
            return $file;
        }

        try {
            $relativeFile = $this->reader()->validateFilePath($file);
            $stat = $this->reader()->stat($relativeFile);
        } catch (InvalidPathException $e) {
            return $this->error(400, $e->getMessage());
        }

        return response()->json([
            'file' => $relativeFile,
            'size' => $stat['size'],
            'lastModified' => $stat['modified'],
            'extension' => $stat['extension'],
            'type' => $stat['type'],
            'isHtml' => $stat['isHtml'],
            'totalPages' => $stat['totalPages'],
            'chunkSize' => $stat['chunkSize'],
        ]);
    }

    public function view(Request $request): JsonResponse
    {
        $file = $this->requireFileParam($request);

        if ($file instanceof JsonResponse) {
            return $file;
        }

        $page = (int) $request->query('page', 1);

        try {
            $relativeFile = $this->reader()->validateFilePath($file);
            $stat = $this->reader()->stat($relativeFile);

            if ($stat['isHtml']) {
                if ($stat['size'] > self::HTML_VIEW_LIMIT) {
                    return response()->json([
                        'file' => $relativeFile,
                        'page' => 1,
                        'totalPages' => 1,
                        'chunkSize' => 0,
                        'fileSize' => $stat['size'],
                        'lastModified' => $stat['modified'],
                        'isHtml' => true,
                        'truncated' => true,
                        'displayedSize' => 0,
                        'content' => '',
                        'hint' => 'HTML file exceeds limit; use /download endpoint',
                    ]);
                }

                $content = $this->reader()->readAll($relativeFile);

                return response()->json([
                    'file' => $relativeFile,
                    'page' => 1,
                    'totalPages' => 1,
                    'chunkSize' => 0,
                    'fileSize' => $stat['size'],
                    'lastModified' => $stat['modified'],
                    'isHtml' => true,
                    'truncated' => false,
                    'displayedSize' => \mb_strlen($content),
                    'content' => $content,
                ]);
            }

            $chunk = $this->reader()->readChunk($relativeFile, $page);
        } catch (InvalidPathException $e) {
            return $this->error(400, $e->getMessage());
        }

        return response()->json([
            'file' => $relativeFile,
            'page' => $chunk['currentPage'],
            'totalPages' => $chunk['totalPages'],
            'chunkSize' => $chunk['chunkSize'],
            'fileSize' => $chunk['fileSize'],
            'lastModified' => $stat['modified'],
            'isHtml' => false,
            'truncated' => false,
            'displayedSize' => $chunk['displayedSize'],
            'content' => $chunk['content'],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $file = $this->requireFileParam($request);

        if ($file instanceof JsonResponse) {
            return $file;
        }

        $q = (string) $request->query('q', '');
        $context = (int) $request->query('context', 5);
        $direction = (string) $request->query('direction', 'both');

        if ($q === '') {
            return $this->error(400, 'Missing query parameter "q"');
        }

        if ($context < 1) {
            $context = 1;
        }

        if ($context > 300) {
            $context = 300;
        }

        if (! \in_array($direction, ['both', 'before', 'after'], true)) {
            $direction = 'both';
        }

        try {
            $relativeFile = $this->reader()->validateFilePath($file);
            $stat = $this->reader()->stat($relativeFile);

            if ($stat['isHtml']) {
                return $this->error(400, 'Search is not supported on HTML files');
            }

            $result = $this->reader()->search($relativeFile, $q, $context, $direction);
        } catch (InvalidPathException $e) {
            return $this->error(400, $e->getMessage());
        }

        return response()->json([
            'file' => $relativeFile,
            'query' => $q,
            'context' => $context,
            'direction' => $direction,
            'found' => $result !== null,
            'lineNumber' => $result['lineNumber'] ?? null,
            'content' => $result['content'] ?? '',
        ]);
    }

    public function download(Request $request): JsonResponse|BinaryFileResponse
    {
        $file = $this->requireFileParam($request);

        if ($file instanceof JsonResponse) {
            return $file;
        }

        try {
            $relativeFile = $this->reader()->validateFilePath($file);
            $fullPath = $this->reader()->fullPath($relativeFile);
        } catch (InvalidPathException $e) {
            return $this->error(400, $e->getMessage());
        }

        return response()->download($fullPath, \basename($fullPath));
    }

    private function requireFileParam(Request $request): string|JsonResponse
    {
        $file = $request->query('file');

        if (! \is_string($file) || $file === '') {
            return $this->error(400, 'Missing required parameter "file".');
        }

        return $file;
    }

    private function reader(): LogReader
    {
        $logDir = (string) config('liquid-monitor.log_viewer.log_dir', storage_path('logs'));

        if ($this->cachedReader === null || $this->cachedReader->getLogDir() !== $logDir) {
            $this->cachedReader = new LogReader($logDir);
        }

        return $this->cachedReader;
    }

    private function error(int $code, string $message): JsonResponse
    {
        return response()->json(['error' => $message, 'code' => $code], $code);
    }
}
