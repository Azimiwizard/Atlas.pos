<?php

namespace App\Jobs;

use App\Models\FinanceExport;
use App\Services\FinanceService;
use App\Services\TenantManager;
use App\Support\Finance\FinanceQueryFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use NumberFormatter;
use Throwable;

class GenerateFinanceExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $financeExportId)
    {
    }

    public function handle(FinanceService $financeService, TenantManager $tenantManager): void
    {
        /** @var FinanceExport $export */
        $export = FinanceExport::query()->findOrFail($this->financeExportId);

        $tenantManager->set($export->tenant_id);

        $export->update(['status' => 'processing', 'error' => null]);

        $options = $export->options;
        $query = FinanceQueryFactory::fromArray($options['filters'] ?? []);

        $dataset = $financeService->exportDataset($query);

        $locale = $options['locale'] ?? App::getLocale() ?? 'en_US';
        $numberFormatter = $this->formatter($locale);

        $path = match ($export->type) {
            'pdf' => $this->generatePdf($export, $dataset, $options, $numberFormatter),
            default => $this->generateCsv($export, $dataset, $options, $numberFormatter),
        };

        $export->update([
            'status' => 'completed',
            'path' => $path,
            'available_until' => now()->addDay(),
        ]);
    }

    protected function formatter(string $locale): ?NumberFormatter
    {
        if (!class_exists(NumberFormatter::class)) {
            return null;
        }

        return new NumberFormatter($locale, NumberFormatter::DECIMAL);
    }

    protected function formatNumber(float $value, ?NumberFormatter $formatter): string
    {
        if ($formatter) {
            $formatted = $formatter->format($value);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return number_format($value, 2, '.', ',');
    }

    protected function generateCsv(FinanceExport $export, array $dataset, array $options, ?NumberFormatter $formatter): string
    {
        $lines = [];
        $filters = $dataset['filters'] ?? [];

        $lines[] = ['Finance Export'];
        $lines[] = ['Period', ($filters['from'] ?? '').' to '.($filters['to'] ?? '')];
        $lines[] = ['Currency', $filters['currency'] ?? ''];
        $lines[] = [];
        $lines[] = ['Summary'];

        foreach ($this->summaryPairs($dataset['summary'] ?? []) as $label => $value) {
            $lines[] = [$label, $this->formatNumber($value, $formatter)];
        }

        $lines[] = [];
        $lines[] = ['Cash Flow'];
        $lines[] = ['Period', 'Cash In', 'Cash Out', 'Net', 'Profit'];

        foreach ($dataset['flow'] ?? [] as $row) {
            $lines[] = [
                $row['period'],
                $this->formatNumber($row['cash_in'], $formatter),
                $this->formatNumber($row['cash_out'], $formatter),
                $this->formatNumber($row['net'], $formatter),
                $this->formatNumber($row['profit'], $formatter),
            ];
        }

        $lines[] = [];
        $lines[] = ['Expenses'];
        $lines[] = ['Category', 'Amount', 'Percent'];

        foreach ($dataset['expenses'] ?? [] as $expense) {
            $lines[] = [
                $expense['category'],
                $this->formatNumber($expense['amount'], $formatter),
                $this->formatNumber($expense['percent'], $formatter).'%',
            ];
        }

        $csv = fopen('php://temp', 'r+');
        foreach ($lines as $line) {
            fputcsv($csv, $line);
        }
        rewind($csv);
        $contents = stream_get_contents($csv);
        fclose($csv);

        $path = 'exports/'.$export->id.'.csv';
        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    protected function generatePdf(FinanceExport $export, array $dataset, array $options, ?NumberFormatter $formatter): string
    {
        $filters = $dataset['filters'] ?? [];
        $viewData = [
            'summary' => $dataset['summary'] ?? [],
            'flow' => $dataset['flow'] ?? [],
            'expenses' => $dataset['expenses'] ?? [],
            'filters' => $filters,
            'numberFormatter' => $formatter,
        ];

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('finance.report', $viewData)->setPaper('A4', 'portrait');

        $path = 'exports/'.$export->id.'.pdf';
        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }

    protected function summaryPairs(array $summary): array
    {
        return [
            'Revenue' => (float) ($summary['revenue'] ?? 0),
            'COGS' => (float) ($summary['cogs'] ?? 0),
            'Gross Profit' => (float) ($summary['gross_profit'] ?? 0),
            'Gross Margin (%)' => (float) ($summary['gross_margin'] ?? 0),
            'Operating Expenses' => (float) ($summary['expenses_total'] ?? 0),
            'Net Profit' => (float) ($summary['net_profit'] ?? 0),
            'Net Margin (%)' => (float) ($summary['net_margin'] ?? 0),
            'Average Ticket' => (float) ($summary['avg_ticket'] ?? 0),
            'Orders Count' => (float) ($summary['orders_count'] ?? 0),
        ];
    }

    public function failed(Throwable $e): void
    {
        if ($export = FinanceExport::query()->find($this->financeExportId)) {
            $export->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
