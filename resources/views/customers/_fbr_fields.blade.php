{{-- FBR buyer details. $c is the customer being edited, or null when adding. --}}
<div class="col-12"><h6 class="fw-bold mt-2 mb-0">FBR Digital Invoicing</h6></div>
<div class="col-md-4">
    <label class="form-label">Registration Type</label>
    <select name="registration_type" class="form-select">
        @foreach(['Unregistered', 'Registered'] as $type)
            <option value="{{ $type }}" @selected(old('registration_type', $c?->registration_type ?? 'Unregistered') === $type)>{{ $type }}</option>
        @endforeach
    </select>
</div>
<div class="col-md-4">
    <label class="form-label">NTN</label>
    <input type="text" name="ntn" class="form-control"
           value="{{ old('ntn', $c?->ntn) }}" placeholder="7-digit NTN">
    <small class="text-muted">NTN na ho to CNIC use hoga.</small>
</div>
<div class="col-md-4">
    <label class="form-label">Province</label>
    <select name="province" class="form-select">
        <option value="">— Select —</option>
        @foreach(\App\Services\FbrDigitalInvoiceService::PROVINCES as $province)
            <option value="{{ $province }}" @selected(old('province', $c?->province) === $province)>{{ $province }}</option>
        @endforeach
    </select>
</div>
