<?php

namespace Tests\Feature;

use App\Models\Escrow;
use App\Models\Order;
use App\Models\User;
use App\Services\ChainReader;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SafePayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_demo_seeder_creates_five_multi_device_accounts(): void
    {
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('wallets', 5);
        $this->assertDatabaseHas('users', ['email' => 'andi@safepay.test', 'role' => 'buyer']);
        $this->assertDatabaseHas('users', ['email' => 'budi@safepay.test', 'role' => 'seller']);
        $this->assertDatabaseHas('users', ['email' => 'citra@safepay.test', 'role' => 'validator']);
        $this->assertDatabaseHas('users', ['email' => 'dinda@safepay.test', 'role' => 'auditor']);
        $this->assertDatabaseHas('users', ['email' => 'eko@safepay.test', 'role' => 'auditor']);
    }

    public function test_user_can_register_and_access_dashboard(): void
    {
        $res = $this->post('/register', [
            'name' => 'Rina',
            'email' => 'rina@safepay.test',
            'role' => 'buyer',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $res->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'rina@safepay.test', 'role' => 'buyer']);
    }

    public function test_bind_wallet_rejects_duplicate_address_owned_by_another_user(): void
    {
        $budi = User::where('email', 'budi@safepay.test')->firstOrFail();
        $andiAddress = User::where('email', 'andi@safepay.test')->firstOrFail()->address();

        $res = $this->actingAs($budi)->postJson(route('wallet.bind'), [
            'wallet_address' => $andiAddress,
        ]);

        $res->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'code' => 'WALLET_IN_USE',
            ]);
    }

    public function test_bind_wallet_protects_validator_arbiter_address(): void
    {
        config()->set('safepay.arbiter', '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC');
        $citra = User::where('email', 'citra@safepay.test')->firstOrFail();

        $res = $this->actingAs($citra)->postJson(route('wallet.bind'), [
            'wallet_address' => '0x9965507D1a55bcC2695C58ba16FB37d819B0A4dc',
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'code' => 'ARBITER_MISMATCH',
            ]);
    }

    public function test_only_buyer_can_create_order(): void
    {
        $andi = User::where('email', 'andi@safepay.test')->firstOrFail();
        $budi = User::where('email', 'budi@safepay.test')->firstOrFail();

        $this->actingAs($budi)->post(route('orders.store'), [
            'seller_id' => $andi->id,
            'product_name' => 'Test',
            'amount' => 100000,
        ])->assertForbidden();

        $this->actingAs($andi)->post(route('orders.store'), [
            'seller_id' => $budi->id,
            'product_name' => 'Sepatu',
            'amount' => 200000,
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('orders', [
            'buyer_id' => $andi->id,
            'seller_id' => $budi->id,
            'product_name' => 'Sepatu',
            'amount' => 200000,
        ]);
    }

    public function test_seller_can_record_dispute_and_auditor_is_forbidden(): void
    {
        $andi = User::where('email', 'andi@safepay.test')->firstOrFail();
        $budi = User::where('email', 'budi@safepay.test')->firstOrFail();
        $dinda = User::where('email', 'dinda@safepay.test')->firstOrFail();

        $order = Order::create([
            'buyer_id' => $andi->id,
            'seller_id' => $budi->id,
            'product_name' => 'Laptop',
            'amount' => 200000,
        ]);

        $escrow = Escrow::create([
            'order_id' => $order->id,
            'contract_id' => 0,
            'status' => 'SHIPPED',
        ]);

        $txHash = '0x'.str_repeat('a1', 32);

        $mock = Mockery::mock(ChainReader::class);
        $mock->shouldReceive('verify')
            ->once()
            ->with($txHash, [ChainReader::TOPIC['Disputed']], 0)
            ->andReturn(true);
        $mock->shouldReceive('payment')
            ->with(0)
            ->andReturn([
                'buyer' => $andi->address(),
                'seller' => $budi->address(),
                'amount' => 200000,
                'status' => 'DISPUTED',
                'deadline' => time() + 3600,
            ]);
        $mock->shouldReceive('receipt')
            ->once()
            ->with($txHash)
            ->andReturn(['blockNumber' => '0x5']);

        $this->app->instance(ChainReader::class, $mock);

        // Auditor ditolak 403
        $this->actingAs($dinda)->post(route('orders.tx', $order), [
            'tx_hash' => $txHash,
            'event_type' => 'Disputed',
            'reason' => 'Test',
        ])->assertForbidden();

        // Penjual diperbolehkan mengajukan sengketa sesuai smart contract
        $this->actingAs($budi)->post(route('orders.tx', $order), [
            'tx_hash' => $txHash,
            'event_type' => 'Disputed',
            'reason' => 'Pembeli tidak merespons',
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('disputes', [
            'escrow_id' => $escrow->id,
            'reason' => 'Pembeli tidak merespons',
            'status' => 'OPEN',
        ]);
    }

    public function test_fake_transaction_hash_is_rejected(): void
    {
        $andi = User::where('email', 'andi@safepay.test')->firstOrFail();
        $budi = User::where('email', 'budi@safepay.test')->firstOrFail();

        $order = Order::create([
            'buyer_id' => $andi->id,
            'seller_id' => $budi->id,
            'product_name' => 'Kamera',
            'amount' => 200000,
        ]);

        $fakeHash = '0x'.str_repeat('ff', 32);

        $mock = Mockery::mock(ChainReader::class);
        $mock->shouldReceive('paymentIdFromReceipt')->once()->with($fakeHash)->andReturn(null);
        $this->app->instance(ChainReader::class, $mock);

        $res = $this->actingAs($andi)->post(route('orders.tx', $order), [
            'tx_hash' => $fakeHash,
            'event_type' => 'PaymentCreated',
        ]);

        $res->assertSessionHas('error');
        $this->assertDatabaseCount('blockchain_transactions', 0);
        $this->assertDatabaseCount('escrows', 0);
    }

    public function test_live_state_endpoint_returns_sync_payload(): void
    {
        $andi = User::where('email', 'andi@safepay.test')->firstOrFail();

        $mock = Mockery::mock(ChainReader::class);
        $mock->shouldReceive('health')->once()->andReturn([
            'node_ok' => true,
            'deployed' => true,
            'block_number' => 12,
            'error' => null,
        ]);
        $mock->shouldReceive('escrowBalance')->once()->andReturn(200000);
        $mock->shouldReceive('tokenBalance')->once()->andReturn(800000);
        $this->app->instance(ChainReader::class, $mock);

        $res = $this->actingAs($andi)->getJson(route('live.state'));

        $res->assertOk()
            ->assertJsonStructure([
                'state_hash',
                'latest_tx_id',
                'tx_count',
                'order_count',
                'dispute_open_count',
                'health' => ['node_ok', 'deployed', 'block_number'],
                'chain_balance',
                'my_balance',
            ])
            ->assertJson([
                'chain_balance' => 200000,
                'my_balance' => 800000,
            ]);
    }

    public function test_all_pages_render_without_blade_errors(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Masuk SafePay');
        $this->get(route('register'))->assertOk()->assertSee('Daftar Akun SafePay');

        $mock = Mockery::mock(ChainReader::class);
        $mock->shouldReceive('health')->andReturn([
            'node_ok' => true,
            'deployed' => true,
            'block_number' => 5,
            'error' => null,
        ]);
        $mock->shouldReceive('escrowBalance')->andReturn(200000);
        $mock->shouldReceive('tokenBalance')->andReturn(1000000);
        $mock->shouldReceive('payment')->andReturn([
            'buyer' => '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
            'seller' => '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            'amount' => 200000,
            'status' => 'FUNDED',
            'deadline' => time() + 3600,
        ]);
        $mock->shouldReceive('receipt')->andReturn([]);
        $this->app->instance(ChainReader::class, $mock);

        foreach (['andi@safepay.test', 'budi@safepay.test', 'citra@safepay.test', 'dinda@safepay.test'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('mode wallet — MetaMask (ETH)');
        }

        $dinda = User::where('email', 'dinda@safepay.test')->firstOrFail();
        $this->actingAs($dinda)->get(route('explorer'))->assertOk()->assertSee('Blockchain Explorer');
        $this->actingAs($dinda)->get(route('explorer.verify', ['tx' => '0x'.str_repeat('ab', 32)]))
            ->assertOk()
            ->assertSee('VERIFIKASI GAGAL');
    }

    public function test_eth_faucet_sends_simulated_eth_to_user_wallet(): void
    {
        $andi = User::where('email', 'andi@safepay.test')->firstOrFail();
        $txHash = '0x'.str_repeat('99', 32);

        $mock = Mockery::mock(ChainReader::class);
        $mock->shouldReceive('faucetEth')->once()->with($andi->address(), 1)->andReturn($txHash);
        $this->app->instance(ChainReader::class, $mock);

        $this->actingAs($andi)
            ->postJson(route('wallet.faucet'))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'tx_hash' => $txHash,
            ]);
    }

    public function test_full_payment_escrow_lifecycle_pay_ship_confirm(): void
    {
        $andi = User::where('email', 'andi@safepay.test')->firstOrFail();
        $budi = User::where('email', 'budi@safepay.test')->firstOrFail();

        $order = Order::create([
            'buyer_id' => $andi->id,
            'seller_id' => $budi->id,
            'product_name' => 'Headset Gaming',
            'amount' => 200000,
        ]);

        $payHash = '0x'.str_repeat('11', 32);
        $shipHash = '0x'.str_repeat('22', 32);
        $relHash = '0x'.str_repeat('33', 32);
        $deadline = time() + 86400;

        $mock = Mockery::mock(ChainReader::class);
        // Step 1: PaymentCreated
        $mock->shouldReceive('paymentIdFromReceipt')->once()->with($payHash)->andReturn(0);
        $mock->shouldReceive('receipt')->once()->with($payHash)->andReturn(['blockNumber' => '0x2']);
        // Step 2: Shipped
        $mock->shouldReceive('verify')->once()->with($shipHash, [ChainReader::TOPIC['Shipped']], 0)->andReturn(true);
        $mock->shouldReceive('receipt')->once()->with($shipHash)->andReturn(['blockNumber' => '0x3']);
        // Step 3: Released
        $mock->shouldReceive('verify')->once()->with($relHash, [ChainReader::TOPIC['Released']], 0)->andReturn(true);
        $mock->shouldReceive('receipt')->once()->with($relHash)->andReturn(['blockNumber' => '0x4']);

        $status = 'FUNDED';
        $mock->shouldReceive('payment')->with(0)->andReturnUsing(function () use ($andi, $budi, $deadline, &$status) {
            return [
                'buyer' => $andi->address(),
                'seller' => $budi->address(),
                'amount' => 200000,
                'status' => $status,
                'deadline' => $deadline,
            ];
        });
        $this->app->instance(ChainReader::class, $mock);

        // 1. Pembeli bayar -> dana terkunci (FUNDED)
        $this->actingAs($andi)->post(route('orders.tx', $order), [
            'tx_hash' => $payHash,
            'event_type' => 'PaymentCreated',
            'deadline' => $deadline,
        ])->assertRedirect(route('dashboard'))->assertSessionHas('status');

        $this->assertDatabaseHas('escrows', ['order_id' => $order->id, 'contract_id' => 0, 'status' => 'FUNDED']);
        $this->assertDatabaseHas('blockchain_transactions', ['tx_hash' => $payHash, 'event_type' => 'PaymentCreated', 'block_number' => 2]);

        // 2. Penjual tandai dikirim -> SHIPPED
        $status = 'SHIPPED';
        $this->actingAs($budi)->post(route('orders.tx', $order), [
            'tx_hash' => $shipHash,
            'event_type' => 'Shipped',
        ])->assertRedirect(route('dashboard'))->assertSessionHas('status');

        $this->assertDatabaseHas('escrows', ['order_id' => $order->id, 'status' => 'SHIPPED']);

        // 3. Pembeli konfirmasi diterima -> RELEASED
        $status = 'RELEASED';
        $this->actingAs($andi)->post(route('orders.tx', $order), [
            'tx_hash' => $relHash,
            'event_type' => 'Released',
        ])->assertRedirect(route('dashboard'))->assertSessionHas('status');

        $this->assertDatabaseHas('escrows', ['order_id' => $order->id, 'status' => 'RELEASED']);
        $this->assertDatabaseCount('blockchain_transactions', 3);
    }
}
