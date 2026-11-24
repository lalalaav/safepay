<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Empat akun demo sesuai skenario dosen, dipetakan ke akun Hardhat deterministik:
 * akun 0 = Andi (pembeli), 1 = Budi (penjual), 2 = Citra (validator/arbiter), 3 = Dinda (auditor).
 * Akun 2 harus sama dengan ARBITER di blockchain/deployments.json.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $demo = [
            ['Andi', 'andi@safepay.test', 'buyer', '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266'],
            ['Budi', 'budi@safepay.test', 'seller', '0x70997970C51812dc3A010C7d01b50e0d17dc79C8'],
            ['Citra', 'citra@safepay.test', 'validator', '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC'],
            ['Dinda', 'dinda@safepay.test', 'auditor', '0x90F79bf6EB2c4f870365E785982E1f101E93b906'],
        ];

        foreach ($demo as [$name, $email, $role, $address]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'role' => $role, 'password' => Hash::make('password')],
            );

            Wallet::updateOrCreate(['user_id' => $user->id], ['wallet_address' => strtolower($address)]);
        }
    }
}
