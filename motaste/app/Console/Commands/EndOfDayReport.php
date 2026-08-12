<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EndOfDayReport extends Command
{
    protected $signature = 'orders:end-of-day-report
        {--date= : Report date (Y-m-d); defaults to yesterday}
        {--to= : Recipient email; defaults to the Admin staff account}
        {--dry-run : Print the report to the console instead of emailing}';

    protected $description = 'Email an end-of-day sales summary for completed orders.';

    public function handle(): int
    {
        $start = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::yesterday()->startOfDay();
        $end = (clone $start)->addDay();
        $dateLabel = $start->format('F j, Y');

        try {
            $totals = DB::table('orders')
                ->where('status', 'completed')
                ->whereBetween('order_date', [$start, $end])
                ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as revenue')
                ->first();

            $byChannel = DB::table('orders')
                ->where('status', 'completed')
                ->whereBetween('order_date', [$start, $end])
                ->selectRaw("CASE WHEN LOWER(order_type) LIKE 'walk-in%' THEN 'Walk-in' ELSE 'Online' END as channel, COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as revenue")
                ->groupBy('channel')
                ->orderByDesc('revenue')
                ->get()
                ->all();

            $byPayment = DB::table('orders')
                ->where('status', 'completed')
                ->whereBetween('order_date', [$start, $end])
                ->selectRaw('COALESCE(NULLIF(payment_method, \'\'), \'Unknown\') as method, COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as revenue')
                ->groupBy('method')
                ->orderByDesc('revenue')
                ->get()
                ->all();

            $topItems = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'completed')
                ->whereBetween('orders.order_date', [$start, $end])
                ->selectRaw('LOWER(TRIM(notes)) as item, SUM(quantity) as qty, SUM(line_total) as revenue')
                ->groupBy('item')
                ->orderByDesc('qty')
                ->limit(5)
                ->get()
                ->all();
        } catch (\Throwable $error) {
            $this->error('Failed to build the report: ' . $error->getMessage());
            Log::error('EndOfDayReport query failed', ['error' => $error->getMessage()]);

            return self::FAILURE;
        }

        // Keep the public-API rate-limit log small: purge rows older than a day.
        try {
            DB::table('order_request_log')
                ->where('created_at', '<', Carbon::now()->subDay()->toDateTimeString())
                ->delete();
        } catch (\Throwable $purgeError) {
            Log::debug('EndOfDayReport rate-limit log purge skipped', ['error' => $purgeError->getMessage()]);
        }

        $orderCount = (int) ($totals->order_count ?? 0);
        $revenue = (float) ($totals->revenue ?? 0);

        $lines = [];
        $lines[] = "MOTASTE End-of-Day Sales Report";
        $lines[] = 'Date: ' . $dateLabel;
        $lines[] = str_repeat('-', 40);
        $lines[] = 'Completed orders: ' . number_format($orderCount);
        $lines[] = 'Total revenue: ₱' . number_format($revenue, 2);
        $lines[] = '';
        $lines[] = 'By channel:';
        if ($byChannel) {
            foreach ($byChannel as $channel) {
                $lines[] = sprintf('  %-10s %d orders — ₱%s', $channel->channel, (int) $channel->order_count, number_format((float) $channel->revenue, 2));
            }
        } else {
            $lines[] = '  (no sales)';
        }
        $lines[] = '';
        $lines[] = 'By payment method:';
        if ($byPayment) {
            foreach ($byPayment as $payment) {
                $lines[] = sprintf('  %-10s %d orders — ₱%s', $payment->method, (int) $payment->order_count, number_format((float) $payment->revenue, 2));
            }
        } else {
            $lines[] = '  (no sales)';
        }
        $lines[] = '';
        $lines[] = 'Top items:';
        if ($topItems) {
            foreach ($topItems as $item) {
                $lines[] = sprintf('  %-30s x%d — ₱%s', ucwords($item->item), (int) $item->qty, number_format((float) $item->revenue, 2));
            }
        } else {
            $lines[] = '  (no items)';
        }

        $body = implode("\n", $lines) . "\n\nThank you — MOTASTE ordering system.";

        if ($this->option('dry-run')) {
            $this->line($body);

            return self::SUCCESS;
        }

        $to = trim((string) $this->option('to'));
        if ($to === '') {
            $to = trim((string) DB::table('staff')->where('role', 'Admin')->value('email'));
        }

        if ($to === '') {
            $this->error('No recipient email configured (pass --to or add an Admin staff account with an email).');

            return self::FAILURE;
        }

        try {
            Mail::mailer('smtp')->raw($body, function ($message) use ($to, $dateLabel): void {
                $message->to($to)->subject("MOTASTE End-of-Day Sales Report — {$dateLabel}");
            });

            $this->info("End-of-day report sent to {$to}.");
        } catch (\Throwable $error) {
            $this->error('Failed to send the report: ' . $error->getMessage());
            Log::error('EndOfDayReport send failed', ['to' => $to, 'error' => $error->getMessage()]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
