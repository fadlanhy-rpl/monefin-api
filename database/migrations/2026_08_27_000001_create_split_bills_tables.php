<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('split_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('bill_date');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete()->comment('Akun MoneFin untuk pembayaran');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('service_percent', 5, 2)->default(0);
            $table->decimal('service_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('split_mode')->default('equal')->comment('equal, itemized, exact, percentage');
            $table->string('rounding_mode')->default('none')->comment('none, up_100, up_1000, down_100');
            $table->json('payment_info')->nullable()->comment('Bank/e-wallet detail for transfer');
            $table->string('receipt_image_path')->nullable();
            $table->string('status')->default('active')->comment('active, settled, cancelled');
            $table->foreignId('my_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'bill_date']);
        });

        Schema::create('split_bill_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('split_bill_id')->constrained('split_bills')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone_number')->nullable();
            $table->boolean('is_creator')->default(false);
            $table->decimal('amount_owed', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->string('status')->default('unpaid')->comment('unpaid, partial, paid');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['split_bill_id', 'status']);
        });

        Schema::create('split_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('split_bill_id')->constrained('split_bills')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 15, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->timestamps();

            $table->index('split_bill_id');
        });

        Schema::create('split_bill_item_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('split_bill_item_id')->constrained('split_bill_items')->cascadeOnDelete();
            $table->foreignId('split_bill_participant_id')->constrained('split_bill_participants')->cascadeOnDelete();
            $table->decimal('split_fraction', 5, 4)->default(1.0000)->comment('1.0 if 1 person, 0.5 if shared between 2, etc.');
            $table->timestamps();

            $table->unique(['split_bill_item_id', 'split_bill_participant_id'], 'item_participant_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('split_bill_item_participants');
        Schema::dropIfExists('split_bill_items');
        Schema::dropIfExists('split_bill_participants');
        Schema::dropIfExists('split_bills');
    }
};
