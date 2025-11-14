<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: A4 portrait;
            margin: 20mm;
        }
        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #1f2937;
            font-size: 12px;
        }
        h1, h2 {
            margin-bottom: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            text-align: left;
        }
        th {
            background-color: #f3f4f6;
        }
        .header {
            margin-bottom: 16px;
        }
        .flex {
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }
    </style>
</head>
@php
    $fmt = $numberFormatter ?? null;
    $format = function ($value) use ($fmt) {
        if ($fmt) {
            $formatted = $fmt->format($value);
            if ($formatted !== false) {
                return $formatted;
            }
        }
        return number_format($value, 2, '.', ',');
    };
@endphp
<body>
    <div class="header">
        <h1>Finance Report</h1>
        <div class="flex">
            <div>
                <strong>Period:</strong> {{ $filters['from'] ?? '' }} - {{ $filters['to'] ?? '' }}<br>
                <strong>Currency:</strong> {{ $filters['currency'] ?? '' }}<br>
                <strong>Timezone:</strong> {{ $filters['timezone'] ?? '' }}
            </div>
            <div>
                <strong>Generated:</strong> {{ now()->toDayDateTimeString() }}<br>
                <strong>Store:</strong> {{ $filters['store'] ?? 'All Stores' }}
            </div>
        </div>
    </div>

    <h2>Summary</h2>
    <table>
        <tbody>
        <tr><th>Revenue</th><td>{{ $format($summary['revenue'] ?? 0) }}</td></tr>
        <tr><th>COGS</th><td>{{ $format($summary['cogs'] ?? 0) }}</td></tr>
        <tr><th>Gross Profit</th><td>{{ $format($summary['gross_profit'] ?? 0) }}</td></tr>
        <tr><th>Gross Margin (%)</th><td>{{ $format($summary['gross_margin'] ?? 0) }}</td></tr>
        <tr><th>Operating Expenses</th><td>{{ $format($summary['expenses_total'] ?? 0) }}</td></tr>
        <tr><th>Net Profit</th><td>{{ $format($summary['net_profit'] ?? 0) }}</td></tr>
        <tr><th>Net Margin (%)</th><td>{{ $format($summary['net_margin'] ?? 0) }}</td></tr>
        <tr><th>Average Ticket</th><td>{{ $format($summary['avg_ticket'] ?? 0) }}</td></tr>
        <tr><th>Orders Count</th><td>{{ number_format($summary['orders_count'] ?? 0) }}</td></tr>
        </tbody>
    </table>

    <h2>Cash Flow</h2>
    <table>
        <thead>
        <tr>
            <th>Period</th>
            <th>Cash In</th>
            <th>Cash Out</th>
            <th>Net</th>
            <th>Profit</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($flow as $row)
            <tr>
                <td>{{ $row['period'] }}</td>
                <td>{{ $format($row['cash_in']) }}</td>
                <td>{{ $format($row['cash_out']) }}</td>
                <td>{{ $format($row['net']) }}</td>
                <td>{{ $format($row['profit']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Expenses</h2>
    <table>
        <thead>
        <tr>
            <th>Category</th>
            <th>Amount</th>
            <th>Percent</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($expenses as $expense)
            <tr>
                <td>{{ $expense['category'] }}</td>
                <td>{{ $format($expense['amount']) }}</td>
                <td>{{ $format($expense['percent']) }}%</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
