{{--
    The till.

    Two columns: find things on the left, the cart and the money on the right.
    On a phone they stack, with search first - a staff member checking a price
    from the shop floor wants the search box and nothing else.
--}}
<div class="row g-3">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Left: search and results                                          --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="col-12 col-lg-5">

        @unless ($drawer)
            {{--
                A sale with no open drawer is still recorded, but it belongs to
                no session and cannot be reconciled against a count. Warned, not
                blocked: refusing would stop the shop trading over bookkeeping.
            --}}
            <div class="alert alert-warning py-2 small d-flex justify-content-between align-items-center">
                <span>No drawer is open. Sales will not be attributed to a session.</span>
                <a href="{{ route('drawer.show') }}" class="btn btn-sm btn-warning">Open it</a>
            </div>
        @endunless

        <div class="card mb-3">
            <div class="card-body">
                {{--
                    wire:submit handles the scanner: it types the whole barcode
                    and sends Enter in one burst, so submit means "add this now".
                    wire:model.live.debounce shows the search list as someone
                    types instead, and 200ms is short enough to feel immediate
                    without a request per keystroke.
                --}}
                <form wire:submit="submitSearch">
                    <input type="text"
                           class="form-control pos-search"
                           wire:model.live.debounce.200ms="search"
                           placeholder="Search or scan a barcode"
                           autofocus autocomplete="off" spellcheck="false">
                </form>
            </div>
        </div>

        @if (strlen($this->search) >= 2)
            <div class="card">
                <div class="list-group list-group-flush">
                    @forelse ($this->results as $item)
                        @php($available = $this->stockLevels[$item->uuid] ?? 0)

                        <button type="button"
                                class="list-group-item list-group-item-action d-flex justify-content-between align-items-center text-start"
                                wire:click="addItem('{{ $item->uuid }}')"
                                wire:key="result-{{ $item->uuid }}">
                            <span>
                                <span class="fw-semibold d-block">{{ $item->item_name }}</span>
                                <span class="small text-secondary">
                                    {{ $item->item_code }}
                                    @if ($item->location) &middot; {{ $item->location }} @endif
                                    @if ($item->is_narcotic)
                                        &middot; <span class="text-danger">controlled</span>
                                    @endif
                                </span>
                            </span>
                            <span class="text-end">
                                <span class="money d-block">{{ $item->sales_price }}</span>
                                <span class="small {{ $available <= 0 ? 'stock-negative' : 'text-secondary' }}">
                                    {{ $available }} in stock
                                </span>
                            </span>
                        </button>
                    @empty
                        <div class="list-group-item text-secondary">
                            Nothing matches &ldquo;{{ $this->search }}&rdquo;.
                        </div>
                    @endforelse
                </div>
            </div>
        @else
            <p class="text-secondary small">
                Type at least two characters, or scan a barcode.
            </p>
        @endif
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Right: the cart and the money                                     --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="col-12 col-lg-7">

        @if (session('status'))
            <div class="alert alert-success d-flex justify-content-between align-items-center">
                <span>{{ session('status') }}</span>
                @if ($this->completedSale)
                    <a href="{{ route('sales.receipt', $this->completedSale) }}"
                       class="btn btn-sm btn-success" target="_blank">Receipt</a>
                @endif
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Cart</span>
                @if ($cart !== [])
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            wire:click="clearCart"
                            wire:confirm="Clear the whole cart?">Clear</button>
                @endif
            </div>

            {{-- The customer is optional: most sales are to nobody in particular. --}}
            <div class="card-body border-bottom py-2">
                @if ($this->customer)
                    <div class="d-flex justify-content-between align-items-center">
                        <span>
                            <span class="fw-semibold">
                                {{ $this->customer->primary_contact_name ?? $this->customer->phone_number }}
                            </span>
                            <span class="small text-secondary">{{ $this->customer->phone_number }}</span>
                        </span>
                        <button type="button" class="btn btn-sm btn-link" wire:click="clearCustomer">Remove</button>
                    </div>
                @else
                    <form wire:submit="lookUpCustomer" class="row g-2 align-items-center">
                        <div class="col">
                            <input type="text" class="form-control form-control-sm @error('customerPhone') is-invalid @enderror"
                                   wire:model="customerPhone" placeholder="Customer phone (optional)">
                            @error('customerPhone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Find</button>
                        </div>
                    </form>
                @endif
            </div>

            @error('cart')
                <div class="alert alert-danger m-3 mb-0 py-2">{{ $message }}</div>
            @enderror

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-center" style="width: 9rem;">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cart as $lineId => $line)
                            <tr wire:key="line-{{ $lineId }}">
                                <td>
                                    <span class="d-block">{{ $line['item_name'] }}</span>
                                    <span class="small text-secondary">{{ $line['item_code'] }}</span>

                                    @if (array_key_exists($lineId, $this->shortLines))
                                        {{--
                                            Advisory only. The shelf is the
                                            authority on what can be sold - if
                                            stock says 2 and the shelf has 5,
                                            refusing loses a real customer over a
                                            data error.
                                        --}}
                                        <span class="d-block small stock-negative">
                                            only {{ $this->shortLines[$lineId] }} in stock — check the shelf
                                        </span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-secondary"
                                                wire:click="decrementLine('{{ $lineId }}')">−</button>
                                        <input type="number" min="1"
                                               class="form-control form-control-sm text-center qty"
                                               style="max-width: 4rem;"
                                               wire:model.live="cart.{{ $lineId }}.quantity">
                                        <button type="button" class="btn btn-outline-secondary"
                                                wire:click="incrementLine('{{ $lineId }}')">+</button>
                                    </div>
                                </td>
                                <td class="text-end">
                                    {{-- Editable: a price override is a real thing at a counter. --}}
                                    <input type="text" inputmode="decimal"
                                           class="form-control form-control-sm money"
                                           style="max-width: 6rem;"
                                           wire:model.live.debounce.400ms="cart.{{ $lineId }}.rate">
                                </td>
                                <td class="money">{{ $this->lineAmounts[$lineId] ?? '0.00' }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-link text-danger"
                                            wire:click="removeLine('{{ $lineId }}')">×</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-secondary py-4 text-center">
                                    Search or scan to add the first item.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($cart !== [])
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <div class="card h-100">
                        <div class="card-header">Discount &amp; payment</div>
                        <div class="card-body">
                            <div class="row g-2 mb-3">
                                <div class="col-5">
                                    <label for="discountType" class="form-label small">Discount</label>
                                    <select class="form-select form-select-sm" id="discountType"
                                            wire:model.live="discountType">
                                        <option value="fixed">Rupees</option>
                                        <option value="percentage">Percent</option>
                                    </select>
                                </div>
                                <div class="col-7">
                                    <label for="discountAmount" class="form-label small">&nbsp;</label>
                                    <input type="text" inputmode="decimal" id="discountAmount"
                                           class="form-control form-control-sm money @error('discountAmount') is-invalid @enderror"
                                           wire:model.live.debounce.400ms="discountAmount" placeholder="0.00">
                                    @error('discountAmount')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            @if (! \App\Support\Money::isZero($this->discount))
                                <div class="mb-3">
                                    <label for="discountReason" class="form-label small">Reason</label>
                                    <input type="text" id="discountReason" class="form-control form-control-sm"
                                           wire:model="discountReason" placeholder="e.g. regular customer">
                                </div>
                            @endif

                            <div class="mb-3">
                                <label for="paymentMethod" class="form-label small">Paid by</label>
                                <select class="form-select form-select-sm" id="paymentMethod"
                                        wire:model.live="paymentMethod">
                                    @foreach (config('pharmacy.payment_methods') as $method)
                                        <option value="{{ $method }}">{{ ucfirst($method) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            @if ($paymentMethod !== 'cash')
                                {{--
                                    Only for non-cash: a mobile payment needs its
                                    transaction reference to be reconcilable, and
                                    cash has nothing to reference.
                                --}}
                                <div class="mb-0">
                                    <label for="paymentReference" class="form-label small">Reference</label>
                                    <input type="text" id="paymentReference" class="form-control form-control-sm"
                                           wire:model="paymentReference" placeholder="transaction id">
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <dl class="row mb-3">
                                <dt class="col-6">Subtotal</dt>
                                <dd class="col-6 money">{{ $this->subtotal }}</dd>

                                @if (! \App\Support\Money::isZero($this->discount))
                                    <dt class="col-6">Discount</dt>
                                    <dd class="col-6 money text-danger">−{{ $this->discount }}</dd>
                                @endif

                                <dt class="col-6 h5 border-top pt-2">To pay</dt>
                                <dd class="col-6 h5 border-top pt-2 money">{{ $this->net }}</dd>
                            </dl>

                            @if ($this->discountTooLarge)
                                <div class="alert alert-danger py-2 small">
                                    The discount is more than the sale total.
                                </div>
                            @endif

                            <form wire:submit="save">
                                @if ($this->needsAuthorisation)
                                    {{--
                                        A discount above the shop's threshold
                                        needs a manager. Small goodwill discounts
                                        stay a staff judgement call - a threshold
                                        set too low just teaches everyone to
                                        fetch a manager for every sale.
                                    --}}
                                    <div class="mb-3">
                                        <label for="authorisingPin" class="form-label small fw-semibold">
                                            Manager's PIN to authorise this discount
                                        </label>
                                        <input type="password" inputmode="numeric" id="authorisingPin"
                                               class="form-control @error('authorisingPin') is-invalid @enderror"
                                               wire:model="authorisingPin" autocomplete="off"
                                               maxlength="{{ config('pharmacy.pin.length') }}">
                                        @error('authorisingPin')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                @endif

                                <div class="mb-3">
                                    <label for="pin" class="form-label fw-semibold">Your PIN</label>
                                    <input type="password" inputmode="numeric" id="pin"
                                           class="form-control form-control-lg @error('pin') is-invalid @enderror"
                                           wire:model="pin" autocomplete="off"
                                           maxlength="{{ config('pharmacy.pin.length') }}">
                                    @error('pin')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text">
                                        Records who made this sale. The cart is kept if the PIN is wrong.
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg w-100"
                                        @disabled($this->discountTooLarge)>
                                    <span wire:loading.remove wire:target="save">Save the sale</span>
                                    <span wire:loading wire:target="save">Saving…</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
