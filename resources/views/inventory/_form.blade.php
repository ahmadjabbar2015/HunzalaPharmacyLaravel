{{--
    Shared by the create and edit screens.

    $item is null when creating. The only field that differs is item_code, which
    is settable once and then fixed: it is the human key and may already be
    printed on a receipt or a shelf label, so changing it would orphan both.
--}}
@php($editing = isset($item) && $item !== null)

<div class="row g-3">
    <div class="col-12 col-md-8">
        <label for="item_name" class="form-label">Name</label>
        <input type="text" class="form-control @error('item_name') is-invalid @enderror"
               id="item_name" name="item_name"
               value="{{ old('item_name', $editing ? $item->item_name : '') }}"
               required autofocus maxlength="200">
        @error('item_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-4">
        <label for="item_code" class="form-label">Code</label>
        @if ($editing)
            <input type="text" class="form-control" id="item_code" value="{{ $item->item_code }}" disabled>
            <div class="form-text">Fixed — it may already be on a printed receipt.</div>
        @else
            <input type="text" class="form-control @error('item_code') is-invalid @enderror"
                   id="item_code" name="item_code" value="{{ old('item_code') }}"
                   required maxlength="50" autocapitalize="characters" spellcheck="false">
            @error('item_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text">Cannot be changed later.</div>
        @endif
    </div>

    <div class="col-12 col-md-6">
        <label for="barcode" class="form-label">
            Barcode <span class="text-secondary">(optional)</span>
        </label>
        <input type="text" class="form-control @error('barcode') is-invalid @enderror"
               id="barcode" name="barcode"
               value="{{ old('barcode', $editing ? $item->barcode : '') }}"
               maxlength="64" spellcheck="false">
        @error('barcode')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">
            Scan into this field. Must not be one another item already has — a shared
            barcode would let the till charge for the wrong medicine.
        </div>
    </div>

    <div class="col-12 col-md-6">
        <label for="manufacturer" class="form-label">Manufacturer</label>
        <input type="text" class="form-control" id="manufacturer" name="manufacturer"
               value="{{ old('manufacturer', $editing ? $item->manufacturer : '') }}" maxlength="200">
    </div>

    <div class="col-6 col-md-3">
        <label for="pack_size" class="form-label">Pieces in packing</label>
        <input type="number" min="1" step="1" inputmode="numeric"
               class="form-control @error('pack_size') is-invalid @enderror"
               id="pack_size" name="pack_size"
               value="{{ old('pack_size', $editing ? $item->pack_size : '') }}"
               placeholder="1">
        @error('pack_size')<div class="invalid-feedback">{{ $message }}</div>@enderror
        {{-- Left blank it is 1: an item sold one at a time. --}}
        <div class="form-text">Tablets in a box, ml in a bottle. Blank means 1.</div>
    </div>

    <div class="col-6 col-md-3">
        <label for="unit_of_measure" class="form-label">Piece is a</label>
        <input type="text" class="form-control" id="unit_of_measure" name="unit_of_measure"
               value="{{ old('unit_of_measure', $editing ? $item->unit_of_measure : '') }}"
               maxlength="30" placeholder="tablet, ml, sachet">
    </div>

    <div class="col-6 col-md-3">
        <label for="purchase_price" class="form-label">Cost per pack</label>
        <input type="number" step="0.01" min="0" inputmode="decimal"
               class="form-control money @error('purchase_price') is-invalid @enderror"
               id="purchase_price" name="purchase_price"
               value="{{ old('purchase_price', $editing ? $item->purchase_price : '0.00') }}" required>
        @error('purchase_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">What the supplier invoices for a whole pack.</div>
    </div>

    <div class="col-6 col-md-3">
        <label for="sales_price" class="form-label">Pack sells for</label>
        <input type="number" step="0.01" min="0" inputmode="decimal"
               class="form-control money @error('sales_price') is-invalid @enderror"
               id="sales_price" name="sales_price"
               value="{{ old('sales_price', $editing ? $item->sales_price : '0.00') }}" required>
        @error('sales_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-6 col-md-3">
        {{--
            The price the till actually charges. Left blank it is the pack price
            divided by the pack size, which is right until it is 8.33 - so it is
            overridable, and shops override it constantly.
        --}}
        <label for="retail_price" class="form-label">
            Price per piece <span class="text-secondary">(optional)</span>
        </label>
        <input type="number" step="0.01" min="0" inputmode="decimal"
               class="form-control money @error('retail_price') is-invalid @enderror"
               id="retail_price" name="retail_price"
               value="{{ old('retail_price', $editing ? $item->retail_price : '') }}"
               placeholder="auto">
        @error('retail_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text" id="piece-price-hint">
            @if ($editing)
                Now charging {{ $item->piecePrice() }} a {{ $item->unit_of_measure ?: 'piece' }}.
            @else
                Blank splits the pack price evenly.
            @endif
        </div>
    </div>

    <div class="col-6 col-md-3">
        <label for="max_discount_percent" class="form-label">
            Max discount <span class="text-secondary">(optional)</span>
        </label>
        <div class="input-group">
            <input type="number" step="0.01" min="0" max="100" inputmode="decimal"
                   class="form-control @error('max_discount_percent') is-invalid @enderror"
                   id="max_discount_percent" name="max_discount_percent"
                   value="{{ old('max_discount_percent', $editing ? $item->max_discount_percent : '') }}"
                   placeholder="none">
            <span class="input-group-text">%</span>
            @error('max_discount_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        {{-- Blank and 0 are different answers, and the difference matters. --}}
        <div class="form-text">Blank means no limit. 0 means never discount this one.</div>
    </div>

    <div class="col-6 col-md-3">
        <label for="reorder_level" class="form-label">Reorder at</label>
        <input type="number" min="0" class="form-control"
               id="reorder_level" name="reorder_level"
               value="{{ old('reorder_level', $editing ? $item->reorder_level : 0) }}">
        <div class="form-text">In pieces. 0 means not tracked.</div>
    </div>

    <div class="col-12 col-md-4">
        <label for="category" class="form-label">Category</label>
        <input type="text" class="form-control" id="category" name="category"
               value="{{ old('category', $editing ? $item->category : '') }}" maxlength="100">
    </div>

    <div class="col-12 col-md-4">
        <label for="location" class="form-label">Shelf</label>
        <input type="text" class="form-control" id="location" name="location"
               value="{{ old('location', $editing ? $item->location : '') }}"
               maxlength="60" placeholder="A3, fridge">
        <div class="form-text">Where to find it when picking.</div>
    </div>

    <div class="col-12 col-md-4 d-flex align-items-end">
        <div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="is_narcotic" name="is_narcotic"
                       @checked(old('is_narcotic', $editing ? $item->is_narcotic : false))>
                <label class="form-check-label" for="is_narcotic">Controlled substance</label>
            </div>

            @if ($editing)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active"
                           @checked(old('is_active', $item->is_active))>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
                <div class="form-text">
                    {{-- Deactivated, not deleted: old sales still reference it. --}}
                    Unticking hides it from search and the till. History is kept.
                </div>
            @endif
        </div>
    </div>

    <div class="col-12">
        <label for="description" class="form-label">
            Notes <span class="text-secondary">(optional)</span>
        </label>
        <textarea class="form-control" id="description" name="description"
                  rows="2" maxlength="2000">{{ old('description', $editing ? $item->description : '') }}</textarea>
    </div>
</div>
