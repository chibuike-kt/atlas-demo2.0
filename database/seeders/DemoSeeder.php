<?php

namespace Database\Seeders;

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\EncryptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $encryption = new EncryptionService();

        // Create demo user
        $user = User::updateOrCreate(
            ['email' => 'demo@atlas.io'],
            [
                'full_name'       => 'Emeka Okonkwo',
                'phone'           => '+2348012345678',
                'password'        => bcrypt('demo1234'),
                'encryption_salt' => bin2hex(random_bytes(32)),
                'role'            => 'user',
                'is_verified'     => true,
            ]
        );

        // Connect a GTB account
        ConnectedAccount::updateOrCreate(
            ['mono_account_id' => 'mono_demo_gtb_001'],
            [
                'user_id'            => $user->id,
                'mono_account_id'    => 'mono_demo_gtb_001',
                'institution_name'   => 'Guaranty Trust Bank',
                'institution_code'   => '058',
                'account_name'       => 'Emeka Okonkwo',
                'account_number_enc' => $encryption->encrypt('0123456789'),
                'account_type'       => 'CURRENT',
                'currency'           => 'NGN',
                'balance'            => 2450000.00,
                'balance_synced_at'  => now(),
                'is_primary'         => true,
            ]
        );

        // Connect an Access Bank account
        ConnectedAccount::updateOrCreate(
            ['mono_account_id' => 'mono_demo_access_001'],
            [
                'user_id'            => $user->id,
                'mono_account_id'    => 'mono_demo_access_001',
                'institution_name'   => 'Access Bank',
                'institution_code'   => '044',
                'account_name'       => 'Emeka Okonkwo',
                'account_number_enc' => $encryption->encrypt('0987654321'),
                'account_type'       => 'SAVINGS',
                'currency'           => 'NGN',
                'balance'            => 850000.00,
                'balance_synced_at'  => now(),
                'is_primary'         => false,
            ]
        );

        $this->command->info('Demo user created: demo@atlas.io / demo1234');
        $this->command->info('GTB account: ₦2,450,000');
        $this->command->info('Access Bank account: ₦850,000');
    }
}
