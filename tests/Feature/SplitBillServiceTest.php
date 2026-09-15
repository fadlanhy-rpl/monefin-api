<?php

namespace Tests\Feature;

use App\Models\SplitBill;
use App\Models\SplitBillParticipant;
use App\Models\User;
use App\Services\SplitBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SplitBillServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SplitBillService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->service = app(SplitBillService::class);
    }

    public function test_can_calculate_equal_split(): void
    {
        $data = [
            'subtotal' => 100000,
            'tax_percent' => 10,
            'service_percent' => 5,
            'discount_amount' => 0,
            'split_mode' => 'equal',
            'rounding_mode' => 'none',
            'participants' => [
                ['name' => 'Creator', 'is_creator' => true],
                ['name' => 'Friend A', 'is_creator' => false],
            ],
        ];

        $calculated = $this->service->calculateSplit($data);

        $this->assertEquals(100000, $calculated['subtotal']);
        $this->assertEquals(10000, $calculated['tax_amount']);
        $this->assertEquals(5000, $calculated['service_amount']);
        $this->assertEquals(115000, $calculated['total_amount']);
        $this->assertCount(2, $calculated['participants']);
        // 115,000 / 2 = 57,500 each
        $this->assertEquals(57500, $calculated['participants'][0]['amount_owed']);
        $this->assertEquals(57500, $calculated['participants'][1]['amount_owed']);
    }

    public function test_can_create_and_settle_split_bill(): void
    {
        $billData = [
            'title' => 'Dinner Bukber',
            'subtotal' => 200000,
            'tax_percent' => 0,
            'service_percent' => 0,
            'discount_amount' => 0,
            'split_mode' => 'equal',
            'rounding_mode' => 'none',
            'participants' => [
                ['name' => 'Me', 'is_creator' => true],
                ['name' => 'John', 'is_creator' => false],
            ],
        ];

        $bill = $this->service->createSplitBill($this->user, $billData);

        $this->assertInstanceOf(SplitBill::class, $bill);
        $this->assertEquals('Dinner Bukber', $bill->title);
        $this->assertEquals('active', $bill->status);
        $this->assertCount(2, $bill->participants);

        $summary = $this->service->getSummary($this->user);
        $this->assertEquals(1, $summary['total_active']);
        $this->assertEquals(100000, $summary['total_owed_to_me']);

        // John pays his share
        $john = $bill->participants->firstWhere('name', 'John');
        $this->assertNotNull($john);

        $this->service->markParticipantPayment($bill, $john, 100000);

        $john->refresh();
        $this->assertEquals('paid', $john->status);
        $this->assertEquals(100000, $john->amount_paid);

        $bill->refresh();
        $this->assertEquals('settled', $bill->status);
    }

    public function test_can_generate_share_text(): void
    {
        $billData = [
            'title' => 'Cafe Coffee',
            'subtotal' => 60000,
            'split_mode' => 'equal',
            'payment_info' => [
                'bank_name' => 'BCA',
                'account_number' => '123456',
                'account_holder' => 'Me',
            ],
            'participants' => [
                ['name' => 'Me', 'is_creator' => true],
                ['name' => 'Alice', 'is_creator' => false],
            ],
        ];

        $bill = $this->service->createSplitBill($this->user, $billData);
        $shareText = $this->service->generateWhatsAppMessage($bill);

        $this->assertStringContainsString('Cafe Coffee', $shareText);
        $this->assertStringContainsString('Alice', $shareText);
        $this->assertStringContainsString('123456', $shareText);
    }
}
