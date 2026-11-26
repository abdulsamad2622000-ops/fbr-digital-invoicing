{{-- FBR Digital Invoicing block: FBR invoice number, QR (Version 2) and the FBR DI logo.
     Shown only after the invoice is posted. Works in the browser and in DomPDF. --}}
@if($sale->isPostedToFbr())
@php
    $fbrLogo = public_path('images/fbr-di-logo.png');
    $fbrLogoSrc = file_exists($fbrLogo) ? 'data:image/png;base64,' . base64_encode(file_get_contents($fbrLogo)) : null;
@endphp
<table style="width:100%;margin-top:10px;border-top:1px dashed #9aa5b1;padding-top:8px" cellpadding="0" cellspacing="0">
    <tr>
        <td style="vertical-align:middle;width:1.1in">
            <img src="{{ \App\Support\QrCode::dataUri($sale->fbr_invoice_number, 96) }}"
                 alt="FBR QR" style="width:1in;height:1in">
        </td>
        <td style="vertical-align:middle;padding-left:8px;font-size:11px;line-height:1.5">
            <strong>FBR Invoice No:</strong> {{ $sale->fbr_invoice_number }}<br>
            <span style="color:#555">Verify via FBR Tax Asaan app</span>
            @if($sale->fbr_environment === 'sandbox')
                <br><strong style="color:#b45309">SANDBOX / TEST INVOICE</strong>
            @endif
        </td>
        @if($fbrLogoSrc)
        <td style="vertical-align:middle;text-align:right">
            <img src="{{ $fbrLogoSrc }}" alt="FBR Digital Invoicing" style="height:0.8in">
        </td>
        @endif
    </tr>
</table>
@endif
