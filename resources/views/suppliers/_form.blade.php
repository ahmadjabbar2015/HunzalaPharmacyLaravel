{{--
    Shared by the supplier create and edit screens.

    $supplier is null when creating. The only field that differs is the opening
    balance: settable once, then fixed, because it is the agreed starting position
    and editing it would silently rewrite every balance computed since.
--}}
@php($editing = isset($supplier) && $supplier !== null)

<div class="row g-3">
    <div class="col-12 col-md-7">
        <label for="supplier_name" class="form-label">Name</label>
        <input type="text" class="form-control @error('supplier_name') is-invalid @enderror"
               id="supplier_name" name="supplier_name"
               value="{{ old('supplier_name', $editing ? $supplier->supplier_name : '') }}"
               required autofocus maxlength="120">
        @error('supplier_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-5">
        <label for="contact_person" class="form-label">
            Contact <span class="text-secondary">(optional)</span>
        </label>
        <input type="text" class="form-control" id="contact_person" name="contact_person"
               value="{{ old('contact_person', $editing ? $supplier->contact_person : '') }}" maxlength="120">
    </div>

    <div class="col-12 col-md-5">
        <label for="phone" class="form-label">Phone</label>
        <input type="text" inputmode="tel" class="form-control" id="phone" name="phone"
               value="{{ old('phone', $editing ? $supplier->phone : '') }}" maxlength="30">
    </div>

    <div class="col-12 col-md-7">
        <label for="payment_terms" class="form-label">Payment terms</label>
        <input type="text" class="form-control" id="payment_terms" name="payment_terms"
               value="{{ old('payment_terms', $editing ? $supplier->payment_terms : '') }}"
               maxlength="120" placeholder="30 days, cash on delivery">
    </div>

    @unless ($editing)
        <div class="col-12 col-md-5">
            <label for="opening_balance" class="form-label">Opening balance</label>
            <input type="number" step="0.01" min="0" inputmode="decimal"
                   class="form-control money @error('opening_balance') is-invalid @enderror"
                   id="opening_balance" name="opening_balance"
                   value="{{ old('opening_balance', '0.00') }}">
            @error('opening_balance')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text">
                What is already owed to them today. Cannot be changed later — every
                balance after this is worked out from it.
            </div>
        </div>
    @else
        <div class="col-12 col-md-5">
            <label class="form-label">Opening balance</label>
            <input type="text" class="form-control money" value="{{ $supplier->opening_balance }}" disabled>
            <div class="form-text">
                Fixed. Changing it would rewrite every balance computed since, with
                nothing to say it had happened.
            </div>
        </div>
    @endunless

    <div class="col-12">
        <label for="address" class="form-label">Address</label>
        <textarea class="form-control" id="address" name="address"
                  rows="2">{{ old('address', $editing ? $supplier->address : '') }}</textarea>
    </div>

    @if ($editing)
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active"
                       @checked(old('is_active', $supplier->is_active))>
                <label class="form-check-label" for="is_active">Active</label>
            </div>
            <div class="form-text">
                {{-- Kept, not deleted: old purchase history still points at them. --}}
                Unticking hides them from the supplier list. Their purchase history stays.
            </div>
        </div>
    @endif
</div>
