<div class="row g-3">
    <div class="col-md-8"><label class="form-label" for="name">Name</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $vendor?->name) }}" required maxlength="255"></div>
    <div class="col-md-4"><label class="form-label" for="code">Code</label><input class="form-control text-uppercase @error('code') is-invalid @enderror" id="code" name="code" value="{{ old('code', $vendor?->code) }}" maxlength="50"></div>
    <div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email', $vendor?->email) }}" maxlength="255"></div>
    <div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone', $vendor?->phone) }}" maxlength="50"></div>
    <div class="col-md-4"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status" required>@foreach (App\MasterDataStatus::cases() as $status)<option value="{{ $status->value }}" @selected(old('status', $vendor?->status?->value ?? App\MasterDataStatus::Active->value) === $status->value)>{{ ucfirst(strtolower($status->value)) }}</option>@endforeach</select></div>
</div>
<div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit">Save vendor</button><a class="btn btn-outline-secondary" href="{{ route('vendors.index') }}">Cancel</a></div>
