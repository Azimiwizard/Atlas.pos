<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinanceRequest;
use App\Jobs\GenerateFinanceExport;
use App\Models\FinanceExport;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function __construct(private FinanceService $finance)
    {
    }

    public function summary(FinanceRequest $request)
    {
        $query = $request->toFinanceQuery();

        return response()->json(
            $this->finance->summary($query)
        );
    }

    public function flow(FinanceRequest $request)
    {
        $query = $request->toFinanceQuery();

        return response()->json(
            $this->finance->flow($query)
        );
    }

    public function expenses(FinanceRequest $request)
    {
        $query = $request->toFinanceQuery();

        return response()->json(
            $this->finance->expenses($query)
        );
    }

    public function health(FinanceRequest $request)
    {
        $query = $request->toFinanceQuery();

        return response()->json(
            $this->finance->health($query)
        );
    }

    public function meta(FinanceRequest $request): JsonResponse
    {
        $query = $request->toFinanceQuery();

        return response()->json(
            $this->finance->meta($query)
        );
    }

    public function exportCsv(FinanceRequest $request): JsonResponse
    {
        return $this->dispatchExport($request, 'csv');
    }

    public function exportPdf(FinanceRequest $request): JsonResponse
    {
        return $this->dispatchExport($request, 'pdf');
    }

    public function exportStatus(FinanceExport $financeExport): JsonResponse
    {
        $this->authorizeExport($financeExport);

        $payload = [
            'job_id' => $financeExport->id,
            'status' => $financeExport->status,
            'type' => $financeExport->type,
        ];

        if ($financeExport->status === 'failed') {
            $payload['error'] = $financeExport->error;
        }

        if ($this->downloadAvailable($financeExport)) {
            $payload['download_url'] = URL::temporarySignedRoute(
                'finance.export.download',
                now()->addDay(),
                ['finance_export' => $financeExport->id]
            );
        }

        return response()->json($payload);
    }

    public function downloadExport(string $financeExport): StreamedResponse
    {
        $export = FinanceExport::withoutGlobalScopes()->findOrFail($financeExport);

        abort_unless($this->downloadAvailable($export), 404);

        $filename = sprintf('finance-export-%s.%s', $export->id, $export->type);

        $disk = Storage::disk('local');
        $mime = $disk->mimeType($export->path) ?? 'application/octet-stream';
        $stream = $disk->readStream($export->path);
        abort_if($stream === false, 404);

        return response()->streamDownload(function () use ($stream) {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => $mime,
        ]);
    }

    protected function dispatchExport(FinanceRequest $request, string $type): JsonResponse
    {
        $query = $request->toFinanceQuery();
        $user = $request->user();

        $export = FinanceExport::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => $type,
            'status' => 'pending',
            'options' => [
                'filters' => $this->queryFilters($query),
                'locale' => app()->getLocale(),
            ],
        ]);

        GenerateFinanceExport::dispatch($export->id);

        return response()->json([
            'job_id' => $export->id,
            'status_url' => route('finance.export.status', $export),
        ], 202);
    }

    protected function queryFilters($query): array
    {
        return [
            'tenant_id' => $query->tenantId,
            'date_from' => $query->rangeStartLocal->toDateString(),
            'date_to' => $query->rangeEndLocal->toDateString(),
            'timezone' => $query->timezone,
            'store_id' => $query->storeId,
            'currency' => $query->currency,
            'limit' => $query->limit,
            'bucket' => $query->bucket,
        ];
    }

    protected function authorizeExport(FinanceExport $financeExport): void
    {
        $user = request()->user();

        if (!$user || $user->tenant_id !== $financeExport->tenant_id) {
            abort(404);
        }
    }

    protected function downloadAvailable(FinanceExport $export): bool
    {
        if ($export->status !== 'completed') {
            return false;
        }

        if (!$export->path || !Storage::disk('local')->exists($export->path)) {
            return false;
        }

        if ($export->available_until && now()->greaterThan($export->available_until)) {
            return false;
        }

        return true;
    }
}
