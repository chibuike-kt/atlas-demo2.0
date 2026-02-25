<?php

namespace Database\Seeders;

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\EncryptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Rule;
use App\Models\RuleAction;

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

        // Saved contacts
        $encryption = new EncryptionService();

        $contacts = [
            [
                'label'          => 'Mama',
                'type'           => 'bank',
                'account_name'   => 'Grace Okonkwo',
                'account_number' => '0112345678',
                'bank_code'      => '011',
                'bank_name'      => 'First Bank of Nigeria',
            ],
            [
                'label'          => 'Ada (Wife)',
                'type'           => 'bank',
                'account_name'   => 'Adaeze Okonkwo',
                'account_number' => '0587654321',
                'bank_code'      => '058',
                'bank_name'      => 'Guaranty Trust Bank (GTB)',
            ],
            [
                'label'          => 'Landlord Musa',
                'type'           => 'bank',
                'account_name'   => 'Musa Ibrahim',
                'account_number' => '0441234567',
                'bank_code'      => '044',
                'bank_name'      => 'Access Bank',
            ],
        ];

        foreach ($contacts as $contact) {
            \App\Models\SavedContact::updateOrCreate(
                ['user_id' => $user->id, 'label' => $contact['label']],
                [
                    'type'               => $contact['type'],
                    'account_name_enc'   => $encryption->encrypt($contact['account_name']),
                    'account_number_enc' => $encryption->encrypt($contact['account_number']),
                    'bank_code'          => $contact['bank_code'],
                    'bank_name'          => $contact['bank_name'],
                ]
            );
        }

        // Demo rule — Salary Day Split
        $gtbAccount = \App\Models\ConnectedAccount::where('mono_account_id', 'mono_demo_gtb_001')->first();

        $rule = Rule::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'Salary Day Split'],
            [
                'connected_account_id' => $gtbAccount->id,
                'description'          => 'Automatically split salary on the 25th of every month',
                'trigger_type'         => 'manual',
                'trigger_config'       => ['day' => 25],
                'total_amount_type'    => 'fixed',
                'total_amount'         => 1000000,
                'currency'             => 'NGN',
                'on_failure'           => 'rollback',
                'is_active'            => true,
            ]
        );

        // Delete existing actions to avoid duplicates
        $rule->actions()->delete();

        $mama    = \App\Models\SavedContact::where('user_id', $user->id)->where('label', 'Mama')->first();
        $ada     = \App\Models\SavedContact::where('user_id', $user->id)->where('label', 'Ada (Wife)')->first();

        $actions = [
            [
                'step_order'  => 1,
                'action_type' => 'save_piggyvest',
                'amount_type' => 'percentage',
                'amount'      => 30,
                'label'       => 'Save 30% to PiggyVest',
                'config'      => ['plan' => 'Salary Savings', 'note' => 'Monthly auto-save'],
            ],
            [
                'step_order'  => 2,
                'action_type' => 'send_bank',
                'amount_type' => 'fixed',
                'amount'      => 10000,
                'label'       => 'Send ₦10,000 to Mama',
                'config'      => ['contact_id' => $mama?->id, 'narration' => 'Monthly upkeep'],
            ],
            [
                'step_order'  => 3,
                'action_type' => 'send_bank',
                'amount_type' => 'fixed',
                'amount'      => 200000,
                'label'       => 'Send ₦200,000 to Ada',
                'config'      => ['contact_id' => $ada?->id, 'narration' => 'House allowance'],
            ],
            [
                'step_order'  => 4,
                'action_type' => 'convert_crypto',
                'amount_type' => 'percentage',
                'amount'      => 10,
                'label'       => 'Convert 10% to USDT',
                'config'      => ['network' => 'tron', 'wallet' => 'TRX_DEMO_WALLET'],
            ],
            [
                'step_order'  => 5,
                'action_type' => 'pay_bill',
                'amount_type' => 'fixed',
                'amount'      => 24500,
                'label'       => 'Pay DSTV Subscription',
                'config'      => ['provider' => 'dstv', 'smart_card' => '1234567890', 'package' => 'Premium'],
            ],
        ];

        foreach ($actions as $action) {
            RuleAction::create(array_merge($action, ['rule_id' => $rule->id]));
        }

        $this->command->info('Demo rule created: Salary Day Split (5 actions)');

        $this->command->info('Saved contacts: Mama, Ada (Wife), Landlord Musa');

        $this->command->info('Demo user created: demo@atlas.io / demo1234');
        $this->command->info('GTB account: ₦2,450,000');
        $this->command->info('Access Bank account: ₦850,000');
    }
}
