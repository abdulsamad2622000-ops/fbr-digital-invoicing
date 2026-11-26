<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'memo_no', 'type', 'customer_id', 'vendor_id',
        'user_id', 'date', 'subtotal', 'discount',
        'total', 'paid', 'balance',
        'payment_type', 'description',
        'fbr_status', 'fbr_invoice_number', 'fbr_environment',
        'fbr_posted_at', 'fbr_error', 'fbr_response',
    ];

    protected $casts = [
        'fbr_posted_at' => 'datetime',
        'fbr_response'  => 'array',
    ];

    public function isPostedToFbr(): bool
    {
        return $this->fbr_status === 'posted' && !empty($this->fbr_invoice_number);
    }

    public function customer() {
        return $this->belongsTo(Customer::class);
    }
    public function vendor() {
        return $this->belongsTo(Vendor::class);
    }
    public function items() {
        return $this->hasMany(SaleItem::class);
    }
    public function salesperson() {
        return $this->belongsTo(User::class, 'user_id');
    }
}