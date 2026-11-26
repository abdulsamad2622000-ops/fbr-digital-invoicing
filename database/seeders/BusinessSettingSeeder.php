<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use Illuminate\Database\Seeder;

class BusinessSettingSeeder extends Seeder
{
    public function run()
    {
        // Placeholder values only. Each client sets their real details in Settings > Business.
        BusinessSetting::create([
            'business_name'       => 'My Business',
            'tagline'             => 'Your tagline here',
            'address'             => 'Business address, City',
            'contact1_name'       => 'Contact Person',
            'contact1_phone'      => '+92 3XX XXXXXXX',
            'bank_name'           => '',
            'bank_account_title'  => '',
            'bank_account_number' => '',
            'bank_iban'           => '',
            'notes'               => '',
            'thank_you_message'   => 'Thank you for your business.',
        ]);
    }
}
