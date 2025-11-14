<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports)
    {
    }

    protected function dates(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        return [$data['from'], $data['to']];
    }

    public function summary(Request $request)
    {
        [$from, $to] = $this->dates($request);
        return response()->json($this->reports->salesSummary($from, $to));
    }

    public function byDay(Request $request)
    {
        [$from, $to] = $this->dates($request);
        return response()->json($this->reports->salesByDay($from, $to));
    }

    public function topProducts(Request $request)
    {
        [$from, $to] = $this->dates($request);
        $limit = (int) $request->query('limit', 10);
        return response()->json($this->reports->topProducts($from, $to, $limit));
    }

    public function topCustomers(Request $request)
    {
        [$from, $to] = $this->dates($request);
        $limit = (int) $request->query('limit', 10);
        return response()->json($this->reports->topCustomers($from, $to, $limit));
    }

    public function paymentMix(Request $request)
    {
        [$from, $to] = $this->dates($request);
        return response()->json($this->reports->paymentMix($from, $to));
    }

    public function taxBreakdown(Request $request)
    {
        [$from, $to] = $this->dates($request);
        return response()->json($this->reports->taxBreakdown($from, $to));
    }

    public function exportCsv(Request $request)
    {
        [$from, $to] = $this->dates($request);
        $kind = (string) $request->query('kind', 'summary');

        $filename = "report-{$kind}-{$from}-to-{$to}.csv";

        $callback = function () use ($kind, $from, $to) {
            $out = fopen('php://output', 'w');
            $write = fn($row) => fputcsv($out, $row);

            $data = match ($kind) {
                'summary' => [$this->reports->salesSummary($from, $to)],
                'by-day' => $this->reports->salesByDay($from, $to),
                'top-products' => $this->reports->topProducts($from, $to, 100),
                'top-customers' => $this->reports->topCustomers($from, $to, 100),
                'payment-mix' => $this->reports->paymentMix($from, $to),
                'tax-breakdown' => $this->reports->taxBreakdown($from, $to),
                default => [],
            };

            if (empty($data)) {
                $write(['No data']);
                fclose($out);
                return;
            }

            // Header
            $write(array_keys($data[0]));
            foreach ($data as $row) {
                $write(array_values($row));
            }

            fclose($out);
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}

