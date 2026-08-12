<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EndOfDayReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_succeeds_for_a_day_without_sales(): void
    {
        $this->artisan('orders:end-of-day-report', ['--date' => '2026-08-01', '--dry-run' => true])
            ->assertSuccessful();
    }

    public function test_dry_run_includes_completed_order_totals(): void
    {
        DB::table('orders')->insert([
            'order_number' => 'report-test-1',
            'order_date' => '2026-08-01 12:00:00',
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'Cash',
            'order_type' => 'Dine In',
            'subtotal' => 100,
            'total_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id' => 1,
            'quantity' => 2,
            'unit_price' => 50,
            'line_total' => 100,
            'notes' => 'Silog Special',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('orders:end-of-day-report', ['--date' => '2026-08-01', '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Total revenue: ₱100.00');
    }
}
