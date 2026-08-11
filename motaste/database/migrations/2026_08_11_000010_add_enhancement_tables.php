<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('login_attempts')) {
            Schema::create('login_attempts', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191)->index();
                $table->string('ip_address', 45)->nullable();
                $table->boolean('success')->default(false);
                $table->timestamp('attempted_at')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('api_event_logs')) {
            Schema::create('api_event_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event', 100)->index();
                $table->text('details')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('loyalty_accounts')) {
            Schema::create('loyalty_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('phone', 40)->unique();
                $table->string('name', 191)->nullable();
                $table->integer('points')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('loyalty_transactions')) {
            Schema::create('loyalty_transactions', function (Blueprint $table) {
                $table->id();
                $table->string('phone', 40)->index();
                $table->integer('points_delta')->default(0);
                $table->string('reason', 100)->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('order_number', 191)->nullable();
                $table->timestamps();

                $table->index('phone', 'loyalty_transactions_phone_idx');
                $table->index('order_id', 'loyalty_transactions_order_id_idx');
            });
        }

        if (Schema::hasTable('inventory_items')) {
            if (!Schema::hasColumn('inventory_items', 'unit_cost')) {
                Schema::table('inventory_items', function (Blueprint $table) {
                    $table->decimal('unit_cost', 10, 2)->default(0)->after('price');
                });
            }
            if (!Schema::hasColumn('inventory_items', 'reorder_level')) {
                Schema::table('inventory_items', function (Blueprint $table) {
                    $table->integer('reorder_level')->default(0)->after('unit_cost');
                });
            }
            if (!Schema::hasColumn('inventory_items', 'is_available')) {
                Schema::table('inventory_items', function (Blueprint $table) {
                    $table->boolean('is_available')->default(true)->after('reorder_level');
                });
            }
        }

        if (Schema::hasTable('orders')) {
            if (!Schema::hasColumn('orders', 'customer_email')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('customer_email', 191)->nullable()->after('delivery_address');
                });
            }
            if (!Schema::hasColumn('orders', 'customer_phone')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('customer_phone', 40)->nullable()->after('customer_email');
                });
            }
            if (!Schema::hasColumn('orders', 'discount')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->decimal('discount', 10, 2)->default(0)->after('total_amount');
                });
            }
            if (!Schema::hasColumn('orders', 'loyalty_points_redeemed')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->integer('loyalty_points_redeemed')->default(0)->after('discount');
                });
            }
            if (!Schema::hasColumn('orders', 'cancelled_at')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->timestamp('cancelled_at')->nullable();
                });
            }
            if (!Schema::hasColumn('orders', 'refunded_at')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->timestamp('refunded_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            foreach (['refunded_at', 'cancelled_at', 'loyalty_points_redeemed', 'discount', 'customer_email', 'customer_phone'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    Schema::table('orders', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        if (Schema::hasTable('inventory_items')) {
            foreach (['is_available', 'reorder_level', 'unit_cost'] as $column) {
                if (Schema::hasColumn('inventory_items', $column)) {
                    Schema::table('inventory_items', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('api_event_logs');
        Schema::dropIfExists('login_attempts');
    }
};
