<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\LogViewer;

/**
 * Shared log directory reader used by the log-viewer API controller. 1:1 port of
 * `LogViewer\LogReader` from `liquiddesign/nette-log-viewer` — same algorithm, same
 * field names, so the JSON contract stays byte-for-byte identical between the Nette
 * and Laravel connectors.
 */
final class LogReader
{
    public const DEFAULT_CHUNK_SIZE = 102400;

    public const DEFAULT_ITEMS_PER_PAGE = 100;

    public function __construct(private readonly string $logDir) {}

    public function getLogDir(): string
    {
        return $this->logDir;
    }

    /**
     * @return array<int, array{name: string, path: string, is_dir: bool, size: int|null, modified: int|null, extension: string|null, type: string}>
     */
    public function listDirectory(string $relativePath): array
    {
        $fullPath = $this->resolveDirectory($relativePath);

        $entries = \scandir($fullPath);

        if ($entries === false) {
            return [];
        }

        $items = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || \str_starts_with($entry, '.')) {
                continue;
            }

            $itemFullPath = $fullPath.'/'.$entry;
            $itemRelativePath = $relativePath !== '' ? $relativePath.'/'.$entry : $entry;
            $isDir = \is_dir($itemFullPath);
            $modified = \filemtime($itemFullPath);

            if ($isDir) {
                $items[] = [
                    'name' => $entry,
                    'path' => $itemRelativePath,
                    'is_dir' => true,
                    'size' => null,
                    'modified' => $modified !== false ? $modified : null,
                    'extension' => null,
                    'type' => 'directory',
                ];
            } else {
                $extension = \pathinfo($entry, \PATHINFO_EXTENSION);
                $size = \filesize($itemFullPath);

                $items[] = [
                    'name' => $entry,
                    'path' => $itemRelativePath,
                    'is_dir' => false,
                    'size' => $size !== false ? $size : null,
                    'modified' => $modified !== false ? $modified : null,
                    'extension' => $extension,
                    'type' => $this->getFileType($extension),
                ];
            }
        }

        return $items;
    }

    /**
     * Read a chunk of a text file aligned on line boundaries.
     *
     * @return array{content: string, displayedSize: int, currentPage: int, totalPages: int, chunkSize: int, fileSize: int}
     */
    public function readChunk(string $relativeFile, int $page, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $fullPath = $this->resolveFile($relativeFile);

        if ($chunkSize < 1) {
            $chunkSize = self::DEFAULT_CHUNK_SIZE;
        }

        $fileSize = \filesize($fullPath);

        if ($fileSize === false) {
            $fileSize = 0;
        }

        $totalPages = $fileSize > 0 ? (int) \ceil($fileSize / $chunkSize) : 0;

        if ($page < 1) {
            $page = 1;
        }

        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }

        $handle = \fopen($fullPath, 'r');

        if ($handle === false) {
            throw new InvalidPathException('Could not open file');
        }

        try {
            $offset = ($page - 1) * $chunkSize;
            \fseek($handle, $offset);
            $chunk = \fread($handle, $chunkSize);
            $content = $chunk !== false ? $chunk : '';

            // Complete the last line (max 10KB extra) so chunks split on line boundaries
            if ($content !== '' && ! \feof($handle) && ! \str_ends_with($content, "\n")) {
                $extraChars = '';
                $maxExtra = 10000;
                $readCount = 0;

                while (! \feof($handle) && $readCount < $maxExtra) {
                    $char = \fgetc($handle);

                    if ($char === false) {
                        break;
                    }

                    $extraChars .= $char;
                    $readCount++;

                    if ($char === "\n") {
                        break;
                    }
                }

                $content .= $extraChars;
            }

            // Skip partial first line on subsequent pages
            if ($page > 1 && $content !== '') {
                $firstNewline = \mb_strpos($content, "\n");

                if ($firstNewline !== false) {
                    $content = \mb_substr($content, $firstNewline + 1);
                }
            }
        } finally {
            \fclose($handle);
        }

        return [
            'content' => $content,
            'displayedSize' => \mb_strlen($content),
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'chunkSize' => $chunkSize,
            'fileSize' => $fileSize,
        ];
    }

    /**
     * Read whole file (use only for known-small files like Tracy/exception HTML dumps).
     */
    public function readAll(string $relativeFile): string
    {
        $fullPath = $this->resolveFile($relativeFile);
        $content = \file_get_contents($fullPath);

        if ($content === false) {
            throw new InvalidPathException('Could not read file');
        }

        return $content;
    }

    /**
     * Search for text in file and return context around first match.
     *
     * @return array{content: string, lineNumber: int}|null
     */
    public function search(string $relativeFile, string $query, int $contextLines = 5, string $direction = 'both'): ?array
    {
        $fullPath = $this->resolveFile($relativeFile);

        $handle = \fopen($fullPath, 'r');

        if ($handle === false) {
            throw new InvalidPathException('Could not open file');
        }

        $buffer = [];
        $lineNumber = 0;
        $foundAtLine = null;
        $linesAfterFound = 0;

        try {
            while (($line = \fgets($handle)) !== false) {
                $lineNumber++;

                if ($foundAtLine === null) {
                    if ($direction === 'both' || $direction === 'before') {
                        $buffer[] = $line;

                        if (\count($buffer) > $contextLines) {
                            \array_shift($buffer);
                        }
                    }

                    if (\stripos($line, $query) !== false) {
                        $foundAtLine = $lineNumber;

                        if ($direction === 'after') {
                            $buffer = [$line];
                        }

                        if ($direction === 'before') {
                            break;
                        }

                        continue;
                    }
                } else {
                    if ($direction === 'both' || $direction === 'after') {
                        $buffer[] = $line;
                        $linesAfterFound++;

                        if ($linesAfterFound >= $contextLines) {
                            break;
                        }
                    }
                }
            }
        } finally {
            \fclose($handle);
        }

        if ($foundAtLine === null) {
            return null;
        }

        return [
            'content' => \implode('', $buffer),
            'lineNumber' => $foundAtLine,
        ];
    }

    /**
     * @return array{size: int, modified: int|null, extension: string, type: string, isHtml: bool, totalPages: int, chunkSize: int}
     */
    public function stat(string $relativeFile, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $fullPath = $this->resolveFile($relativeFile);

        $size = \filesize($fullPath);
        $modified = \filemtime($fullPath);
        $extension = \pathinfo($fullPath, \PATHINFO_EXTENSION);
        $resolvedSize = $size !== false ? $size : 0;
        $isHtml = $extension === 'html';
        $totalPages = $isHtml ? 1 : ($resolvedSize > 0 ? (int) \ceil($resolvedSize / $chunkSize) : 0);

        return [
            'size' => $resolvedSize,
            'modified' => $modified !== false ? $modified : null,
            'extension' => $extension,
            'type' => $this->getFileType($extension),
            'isHtml' => $isHtml,
            'totalPages' => $totalPages,
            'chunkSize' => $chunkSize,
        ];
    }

    public function fullPath(string $relativeFile): string
    {
        return $this->resolveFile($relativeFile);
    }

    /**
     * Validate and sanitize path (directory or file) to prevent traversal.
     */
    public function validatePath(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        $path = \str_replace(['..', '\\'], '', $path);

        return \trim($path, '/');
    }

    /**
     * Validate file path and ensure it resolves inside the log directory.
     */
    public function validateFilePath(string $file): string
    {
        $file = $this->validatePath($file);

        if ($file === '') {
            throw new InvalidPathException('Invalid file path');
        }

        $fullPath = $this->logDir.'/'.$file;
        $realPath = \realpath($fullPath);
        $logDirRealPath = \realpath($this->logDir);

        if ($realPath === false || $logDirRealPath === false || ! $this->isInside($realPath, $logDirRealPath)) {
            throw new InvalidPathException('Invalid file path');
        }

        return $file;
    }

    public function getFileType(string $extension): string
    {
        return match ($extension) {
            'log' => 'log',
            'html' => 'html',
            'json' => 'json',
            'txt' => 'text',
            default => 'file',
        };
    }

    /**
     * Strict containment check: `$candidate` must equal `$root` or be inside it.
     * Appending the directory separator prevents sibling-directory bypass
     * (e.g. `/var/log` matching `/var/log-other`).
     */
    private function isInside(string $candidate, string $root): bool
    {
        return $candidate === $root || \str_starts_with($candidate, $root.\DIRECTORY_SEPARATOR);
    }

    private function resolveDirectory(string $relativePath): string
    {
        $relativePath = $this->validatePath($relativePath);
        $fullPath = $this->logDir.($relativePath !== '' ? '/'.$relativePath : '');

        if (! \is_dir($fullPath)) {
            throw new InvalidPathException('Invalid directory');
        }

        $realPath = \realpath($fullPath);
        $logDirRealPath = \realpath($this->logDir);

        if ($realPath === false || $logDirRealPath === false || ! $this->isInside($realPath, $logDirRealPath)) {
            throw new InvalidPathException('Invalid directory');
        }

        return $fullPath;
    }

    private function resolveFile(string $relativeFile): string
    {
        $relativeFile = $this->validateFilePath($relativeFile);
        $fullPath = $this->logDir.'/'.$relativeFile;

        if (! \is_file($fullPath)) {
            throw new InvalidPathException('File not found');
        }

        return $fullPath;
    }
}
