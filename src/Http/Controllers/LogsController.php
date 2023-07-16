<?php

namespace Opcodes\LogViewer\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Opcodes\LogViewer\Exceptions\InvalidRegularExpression;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\Http\Resources\BaseLogResource;
use Opcodes\LogViewer\Http\Resources\LevelCountResource;
use Opcodes\LogViewer\Http\Resources\LogFileResource;
use Opcodes\LogViewer\Http\Resources\LogResource;
use Opcodes\LogViewer\HttpAccessLog;
use Opcodes\LogViewer\HttpApacheErrorLog;
use Opcodes\LogViewer\HttpNginxErrorLog;

class LogsController
{
    const OLDEST_FIRST = 'asc';

    const NEWEST_FIRST = 'desc';

    public function index(Request $request)
    {
        $fileIdentifier = $request->query('file', '');
        $query = $request->query('query', '');
        $direction = $request->query('direction', 'desc');
        $log = $request->query('log', null);
        $excludedLevels = $request->query('exclude_levels', []);
        $perPage = $request->query('per_page', 25);
        session()->put('log-viewer:shorter-stack-traces', $request->boolean('shorter_stack_traces', false));
        $hasMoreResults = false;
        $percentScanned = 0;

        if ($request->query('page', 1) < 1) {
            $request->replace(['page' => 1]);
        }

        if ($file = LogViewer::getFile($fileIdentifier)) {
            $logQuery = $file->logs();
        } elseif (! empty($query)) {
            $logQuery = LogViewer::getFiles()->logs();
        }

        if (isset($logQuery)) {
            $supportsLevels = $logQuery->supportsLevels();

            try {
                $logQuery->search($query);

                if (isset($file) && Str::startsWith($query, 'log-index:')) {
                    $logIndex = explode(':', $query)[1];
                    $expandAutomatically = intval($logIndex) || $logIndex === '0';
                }

                if ($direction === self::NEWEST_FIRST) {
                    $logQuery->reverse();
                }

                $logQuery->scan();
                $logQuery->exceptLevels($excludedLevels);
                $logs = $logQuery->paginate($perPage);
                $levels = array_values($logQuery->getLevelCounts());

                if ($logs->lastPage() < $request->input('page', 1)) {
                    $request->replace(['page' => $logs->lastPage() ?? 1]);
                    // re-create the paginator instance to fix a bug
                    $logs = $logQuery->paginate($perPage);
                }

                $hasMoreResults = $logQuery->requiresScan();
                $percentScanned = $logQuery->percentScanned();
            } catch (InvalidRegularExpression $exception) {
                $queryError = $exception->getMessage();
            }
        }

        $logClass = $this->getLogClass($logs ?? []);

        return response()->json([
            'file' => isset($file) ? new LogFileResource($file) : null,
            'levelCounts' => LevelCountResource::collection($levels ?? []),
            'logs' => $this->logsToResources($logs ?? []),
            'columns' => isset($logClass) ? ($logClass::$columns ?? null) : null,
            'pagination' => isset($logs) ? [
                'current_page' => $logs->currentPage(),
                'first_page_url' => $logs->url(1),
                'from' => $logs->firstItem(),
                'last_page' => $logs->lastPage(),
                'last_page_url' => $logs->url($logs->lastPage()),
                'links' => $logs->linkCollection()->toArray(),
                'links_short' => $logs->onEachSide(0)->linkCollection()->toArray(),
                'next_page_url' => $logs->nextPageUrl(),
                'path' => $logs->path(),
                'per_page' => $logs->perPage(),
                'prev_page_url' => $logs->previousPageUrl(),
                'to' => $logs->lastItem(),
                'total' => $logs->total(),
            ] : null,
            'expandAutomatically' => $expandAutomatically ?? false,
            'cacheRecentlyCleared' => $this->cacheRecentlyCleared ?? false,
            'hasMoreResults' => $hasMoreResults,
            'percentScanned' => $percentScanned,
            'supportsLevels' => $supportsLevels ?? false,
            'performance' => $this->getRequestPerformanceInfo(),
        ]);
    }

    protected function logsToResources(LengthAwarePaginator|array $logs): JsonResource
    {
        if (is_array($logs) && empty($logs)) {
            return JsonResource::collection([]);
        }

        if (empty($logs->items())) {
            return JsonResource::collection([]);
        }

        return match (get_class($logs->items()[0])) {
            HttpAccessLog::class,
            HttpNginxErrorLog::class,
            HttpApacheErrorLog::class => BaseLogResource::collection($logs),
            default => LogResource::collection($logs),
        };
    }

    protected function getLogClass(LengthAwarePaginator|array $logs): ?string
    {
        if (is_array($logs) && empty($logs)) {
            return null;
        }

        if (empty($logs->items())) {
            return null;
        }

        return get_class($logs->items()[0]);
    }

    protected function getRequestPerformanceInfo(): array
    {
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : request()->server('REQUEST_TIME_FLOAT');
        $memoryUsage = number_format(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB';
        $requestTime = number_format((microtime(true) - $startTime) * 1000, 0).'ms';

        return [
            'memoryUsage' => $memoryUsage,
            'requestTime' => $requestTime,
            'version' => LogViewer::version(),
        ];
    }
}
