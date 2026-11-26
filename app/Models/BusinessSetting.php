<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    protected $fillable = [
        'business_name', 'tagline', 'address',
        'contact1_name', 'contact1_phone',
        'contact2_name', 'contact2_phone',
        'contact3_name', 'contact3_phone',
        'bank_name', 'bank_account_title',
        'bank_account_number', 'bank_iban',
         'ntn',
        'notes', 'thank_you_message',
        'fbr_enabled', 'fbr_environment', 'fbr_token', 'seller_province',
        'fbr_scenario_id', 'fbr_prices_include_tax', 'fbr_auto_post',
    ];

    protected $casts = [
        'fbr_enabled'            => 'boolean',
        'fbr_prices_include_tax' => 'boolean',
        'fbr_auto_post'          => 'boolean',
        'fbr_token'              => 'encrypted',
    ];

    protected $hidden = ['fbr_token'];


}