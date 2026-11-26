{{-- On-screen FBR controls (hidden when printing via the .actions class). --}}
@php $fbrSetting = App\Models\BusinessSetting::first(); @endphp
@if($fbrSetting?->fbr_enabled && $sale->type === 'sale')
<div class="actions" style="margin-top:12px;padding:10px;border:1px solid #d0d7de;border-radius:8px;font-size:13px">
    <strong>FBR Digital Invoicing</strong>
    ({{ $fbrSetting->fbr_environment === 'production' ? 'Production' : 'Sandbox' }})
    @if(session('success'))<div style="color:#15803d;margin-top:6px">{{ session('success') }}</div>@endif
    @if(session('error'))<div style="color:#b91c1c;margin-top:6px">{{ session('error') }}</div>@endif

    @if($sale->isPostedToFbr())
        <div style="color:#15803d;margin-top:6px">Posted: {{ $sale->fbr_invoice_number }}
            ({{ optional($sale->fbr_posted_at)->format('d M Y h:i A') }})</div>
    @else
        @if($sale->fbr_status === 'failed' && $sale->fbr_error)
            <div style="color:#b91c1c;margin-top:6px">Last error: {{ $sale->fbr_error }}</div>
        @endif
        <div style="margin-top:8px;display:flex;gap:8px">
            <form method="POST" action="{{ route('sales.fbr.validate', $sale) }}">@csrf
                <button type="submit" style="padding:6px 12px">Validate with FBR</button>
            </form>
            <form method="POST" action="{{ route('sales.fbr.post', $sale) }}"
                  onsubmit="return confirm('Invoice FBR par post karein? Post hone ke baad wapas nahi hoti.')">@csrf
                <button type="submit" style="padding:6px 12px;background:#1E3A8A;color:#fff;border:0;border-radius:4px">Post to FBR</button>
            </form>
        </div>
    @endif
</div>
@endif
