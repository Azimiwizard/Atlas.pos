<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyticsRequest;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(private AnalyticsService $analytics)
    {
    }

    public function summary(AnalyticsRequest $request): JsonResponse
    {
        $filters = $request->toAnalyticsQuery();

        return response()->json(
            $this->analytics->summary($filters)
        );
    }

    public function hourlyHeatmap(AnalyticsRequest $request): JsonResponse
    {
        $filters = $request->toAnalyticsQuery();

        return response()->json(
            $this->analytics->hourlyHeatmap($filters)
        );
    }

    public function cashiers(AnalyticsRequest $request): JsonResponse
    {
        $filters = $request->toAnalyticsQuery();

        return response()->json(
            $this->analytics->cashiers($filters)
        );
    }

    public function refunds(AnalyticsRequest $request): JsonResponse
    {
        $filters = $request->toAnalyticsQuery();

        return response()->json(
            $this->analytics->refunds($filters)
        );
    }

    public function export(AnalyticsRequest $request): StreamedResponse
    {
        $filters = $request->toAnalyticsQuery();
        $range = $filters->rangeArray();

        $filename = sprintf(
            'analytics-%s-%s-to-%s.csv',
            $filters->filtersArray()['store_id'] ?? 'all',
            $range['from'],
            $range['to']
        );

        return response()->streamDownload(function () use ($filters) {
            $this->analytics->streamOrdersCsv($filters);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
