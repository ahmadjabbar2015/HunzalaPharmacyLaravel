<?php

namespace App\Livewire;

use App\Exceptions\SaleException;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\CartLine;
use App\Services\ItemService;
use App\Services\PinService;
use App\Services\SaleService;
use App\Services\SessionService;
use App\Services\StockService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * The till.
 *
 * This is the one screen in the system that has to be fast under a queue, so it
 * is the one place Livewire earns its keep: search, cart edits and totals update
 * without a page reload, and without an API layer or a build step to maintain.
 *
 * Three decisions worth stating, because each is a trade and none is obvious:
 *
 *   The cart lives in the Livewire component, not the database. A cart is not a
 *   business record until it is saved - a half-built cart abandoned when a
 *   customer changes their mind should leave nothing behind. It does mean a lost
 *   session loses the cart, which is the right way round: the alternative is
 *   draft sales accumulating in the sales table forever.
 *
 *   Stock is ADVISORY here. A line whose quantity exceeds stock is flagged, not
 *   refused. The shelf stops a sale, not the database - if stock says 2 and the
 *   shelf has 5, refusing loses a real customer over a data error.
 *
 *   The PIN is collected at SAVE, not at the start. Whoever completes the sale
 *   owns it, and that is not necessarily whoever is logged in or whoever started
 *   ringing it up.
 */
class PointOfSale extends Component
{
    /** The search box. Matches code, name or barcode; two characters minimum. */
    public string $search = '';

    /**
     * The cart, keyed by a line id rather than by item uuid.
     *
     * Keyed by line so the same medicine can appear twice at different rates -
     * a price override on one line, full price on another - which a shop does
     * more often than you would expect.
     *
     * @var array<string, array{item_uuid: string, item_name: string, item_code: string, batch_uuid: ?string, quantity: int, rate: string}>
     */
    public array $cart = [];

    public ?string $customerUuid = null;

    public string $customerPhone = '';

    public string $discountType = 'fixed';

    public string $discountAmount = '';

    public string $discountReason = '';

    public string $paymentMethod = 'cash';

    public string $paymentReference = '';

    /** The staff PIN, entered at save to attribute the sale. Never persisted. */
    public string $pin = '';

    /** The manager PIN, when the discount is above the shop's threshold. */
    public string $authorisingPin = '';

    /** Set after a successful save, so the receipt link can be offered. */
    public ?string $completedSaleUuid = null;

    public function mount(): void
    {
        Gate::authorize('sell');
    }

    // -----------------------------------------------------------------------
    // finding things
    // -----------------------------------------------------------------------

    /**
     * Search results. A computed property, so it is evaluated once per render
     * rather than on every reference from the template.
     *
     * @return Collection<int, Item>
     */
    #[Computed]
    public function results(): Collection
    {
        return app(ItemService::class)->search($this->search, limit: 15);
    }

    /**
     * Derived stock for everything on screen, in ONE query.
     *
     * The search list shows stock per row and the cart checks it per line. Asking
     * per item would make a fifteen-row result fifteen queries on the hot path -
     * LARAVEL_PLAN.md §5's second named trap.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function stockLevels(): array
    {
        $uuids = $this->results->pluck('uuid')
            ->merge(collect($this->cart)->pluck('item_uuid'))
            ->unique()
            ->values()
            ->all();

        return app(StockService::class)->quantitiesFor($uuids);
    }

    /**
     * Scan-and-go.
     *
     * A barcode scanner types the whole code and sends Enter in one burst, so an
     * exact barcode match on submit means "add this now" - the staff member is
     * looking at the customer, not the screen. Anything else falls through to
     * the search list.
     */
    public function submitSearch(): void
    {
        $item = app(ItemService::class)->findByBarcode($this->search);

        if ($item !== null) {
            $this->addItem($item->uuid);
            $this->search = '';

            return;
        }

        // Not a barcode: leave the term in place and let the results list stand.
        unset($this->results, $this->stockLevels);
    }

    public function lookUpCustomer(): void
    {
        $phone = trim($this->customerPhone);

        if ($phone === '') {
            return;
        }

        $customer = Customer::query()->where('phone_number', $phone)->first();

        if ($customer === null) {
            // Not an error. Most sales are to nobody in particular, and a
            // customer record is created deliberately elsewhere, not as a
            // side-effect of a typo at the till.
            $this->addError('customerPhone', 'No customer with that number. The sale can go ahead without one.');

            return;
        }

        $this->customerUuid = $customer->uuid;
        $this->resetErrorBag('customerPhone');
    }

    public function clearCustomer(): void
    {
        $this->customerUuid = null;
        $this->customerPhone = '';
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customerUuid === null ? null : Customer::find($this->customerUuid);
    }

    // -----------------------------------------------------------------------
    // the cart
    // -----------------------------------------------------------------------

    public function addItem(string $itemUuid, bool $wholePack = false): void
    {
        $item = Item::find($itemUuid);

        if ($item === null || ! $item->is_active) {
            return;
        }

        /*
         * The batch is chosen here, by FEFO, rather than asked for. Staff should
         * not be picking lots at a counter: the earliest-expiring stock is
         * almost always the right answer, and making it a choice means the
         * answer is sometimes wrong and always slower.
         */
        $batch = app(ItemService::class)->sellableBatch($item->uuid);

        /*
         * The rate is per PIECE, always, and the quantity is in pieces - because
         * that is the unit the ledger counts in. Selling a whole pack adds
         * pack_size pieces at the piece rate rather than one line at the pack
         * rate, so the stock movement and the money agree with each other and a
         * customer buying a box and a customer buying ten singles are charged
         * the same.
         */
        $rate = $item->piecePrice();
        $quantity = $wholePack ? max(1, (int) $item->pack_size) : 1;

        // An identical line - same item, same batch, same rate - increments
        // rather than repeating, which is what someone scanning three boxes of
        // the same thing expects to see.
        foreach ($this->cart as $lineId => $line) {
            if ($line['item_uuid'] === $item->uuid
                && $line['batch_uuid'] === $batch?->uuid
                && Money::equals($line['rate'], $rate)) {
                $this->cart[$lineId]['quantity'] += $quantity;
                unset($this->stockLevels);

                return;
            }
        }

        $this->cart[(string) str()->uuid()] = [
            'item_uuid' => $item->uuid,
            'item_name' => $item->item_name,
            'item_code' => $item->item_code,
            'batch_uuid' => $batch?->uuid,
            'quantity' => $quantity,
            'rate' => Money::format($rate),
            // Carried on the line so the cart can show "2 packs + 3" without a
            // query per row while the till is being typed into.
            'pack_size' => max(1, (int) $item->pack_size),
            'unit' => $item->unit_of_measure ?: 'pc',
        ];

        unset($this->stockLevels);
    }

    /** Add a whole pack: pack_size pieces in one keystroke. */
    public function addPack(string $itemUuid): void
    {
        $this->addItem($itemUuid, wholePack: true);
    }

    public function removeLine(string $lineId): void
    {
        unset($this->cart[$lineId]);
        unset($this->stockLevels);
    }

    public function incrementLine(string $lineId): void
    {
        if (isset($this->cart[$lineId])) {
            $this->cart[$lineId]['quantity']++;
        }
    }

    public function decrementLine(string $lineId): void
    {
        if (! isset($this->cart[$lineId])) {
            return;
        }

        // Decrementing to zero removes the line, rather than leaving a zero-
        // quantity row the save would then reject.
        if ($this->cart[$lineId]['quantity'] <= 1) {
            $this->removeLine($lineId);

            return;
        }

        $this->cart[$lineId]['quantity']--;
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->clearCustomer();
        $this->reset(['discountAmount', 'discountReason', 'paymentReference', 'pin', 'authorisingPin']);
        $this->resetErrorBag();
        unset($this->stockLevels);
    }

    // -----------------------------------------------------------------------
    // the money
    // -----------------------------------------------------------------------

    /** @return array<string, string> line id => line amount */
    #[Computed]
    public function lineAmounts(): array
    {
        $amounts = [];

        foreach ($this->cart as $lineId => $line) {
            $amounts[$lineId] = Money::multiply($line['rate'], max(0, $line['quantity']));
        }

        return $amounts;
    }

    #[Computed]
    public function subtotal(): string
    {
        return Money::sum($this->lineAmounts);
    }

    /**
     * The discount in rupees, whichever way it was entered.
     *
     * Shown resolved rather than as a percentage, so whoever is at the till sees
     * the figure the customer will see on the receipt before committing to it.
     */
    #[Computed]
    public function discount(): string
    {
        $amount = Money::format($this->discountAmount);

        if (! Money::greaterThan($amount, Money::ZERO)) {
            return Money::ZERO;
        }

        return $this->discountType === 'percentage'
            ? Money::percentOf($this->subtotal, $amount)
            : $amount;
    }

    #[Computed]
    public function net(): string
    {
        return Money::sub($this->subtotal, $this->discount);
    }

    /** True when the discount exceeds the subtotal - a typo, not a giveaway. */
    #[Computed]
    public function discountTooLarge(): bool
    {
        return Money::isNegative($this->net);
    }

    /**
     * The most this cart may be discounted before a manager is needed.
     *
     * null when nothing in the cart carries a limit, which is the normal case.
     */
    #[Computed]
    public function itemDiscountCeiling(): ?string
    {
        if ($this->cart === []) {
            return null;
        }

        return app(SaleService::class)->discountCeiling($this->cartLines());
    }

    /** True when the discount is over what the items themselves allow. */
    #[Computed]
    public function overItemDiscountLimit(): bool
    {
        $ceiling = $this->itemDiscountCeiling;

        return $ceiling !== null && Money::greaterThan($this->discount, $ceiling);
    }

    /**
     * Whether this discount needs a manager's PIN.
     *
     * Two independent reasons, either one enough: the discount is large in
     * rupees, or it is over the limit an item in the cart carries. The second is
     * the one that catches a 90% giveaway on a cheap box, which the rupee
     * threshold never would.
     *
     * The threshold is a shop setting: set too low it simply teaches everyone to
     * fetch a manager for every sale, which trains them to treat the check as
     * noise.
     */
    #[Computed]
    public function needsAuthorisation(): bool
    {
        if ($this->overItemDiscountLimit) {
            return true;
        }

        $threshold = Setting::query()->value('discount_pin_threshold')
            ?? config('pharmacy.defaults.discount_pin_threshold');

        return Money::greaterThan($this->discount, $threshold);
    }

    // -----------------------------------------------------------------------
    // stock advice
    // -----------------------------------------------------------------------

    /**
     * Cart lines asking for more than the ledger says is on the shelf.
     *
     * Advisory. Flagged so whoever is serving can glance at the shelf, never
     * blocking - the shelf is the authority on what can be sold.
     *
     * @return array<string, int> line id => available quantity
     */
    #[Computed]
    public function shortLines(): array
    {
        $wanted = [];

        foreach ($this->cart as $lineId => $line) {
            $wanted[$line['item_uuid']] = ($wanted[$line['item_uuid']] ?? 0) + $line['quantity'];
        }

        $short = [];

        foreach ($this->cart as $lineId => $line) {
            $available = $this->stockLevels[$line['item_uuid']] ?? 0;

            if ($wanted[$line['item_uuid']] > $available) {
                $short[$lineId] = $available;
            }
        }

        return $short;
    }

    // -----------------------------------------------------------------------
    // saving
    // -----------------------------------------------------------------------

    public function save(): void
    {
        $this->resetErrorBag();

        if ($this->cart === []) {
            $this->addError('cart', 'There is nothing in the cart.');

            return;
        }

        if ($this->discountTooLarge) {
            $this->addError('discountAmount', 'The discount is more than the sale total.');

            return;
        }

        $pinService = app(PinService::class);
        $drawer = app(SessionService::class)->openSession();

        /*
         * The PIN identifies the staff member and is the sale's attribution. It
         * is deliberately not the logged-in user: the till is shared, often left
         * signed in, and "who was logged in" is not evidence of who served the
         * customer.
         */
        $result = $pinService->authenticateByPin(
            pin: $this->pin,
            deviceId: config('pharmacy.device_id'),
            sessionUuid: $drawer?->uuid,
        );

        if (! $result->ok) {
            // The cart SURVIVES a wrong PIN. Clearing it would punish the
            // customer for a staff member's mistyped digit, and a colleague can
            // complete the sale with their own PIN.
            $this->addError('pin', $result->message);
            $this->pin = '';

            return;
        }

        $authorisedBy = null;

        if ($this->needsAuthorisation) {
            $authorising = $pinService->authenticateByPin(
                pin: $this->authorisingPin,
                deviceId: config('pharmacy.device_id'),
                sessionUuid: $drawer?->uuid,
            );

            if (! $authorising->ok || ! $authorising->user->can('authorise-discount')) {
                $this->addError('authorisingPin', 'A manager or the owner must authorise a discount this large.');
                $this->authorisingPin = '';

                return;
            }

            $authorisedBy = $authorising->user;
        }

        try {
            $sale = app(SaleService::class)->create(
                lines: $this->cartLines(),
                staffMemberUuid: $result->user->uuid,
                deviceId: config('pharmacy.device_id'),
                sessionUuid: $drawer?->uuid,
                customerUuid: $this->customerUuid,
                discountType: Money::isZero($this->discount) ? null : $this->discountType,
                discountAmount: Money::isZero($this->discount) ? 0 : $this->discountAmount,
                discountReason: $this->discountReason ?: null,
                discountPinVerified: $authorisedBy !== null,
                paymentMethod: $this->paymentMethod,
                paymentReference: $this->paymentReference ?: null,
            );
        } catch (SaleException $e) {
            // A rule the component did not catch first. Shown against the cart
            // rather than thrown, because the customer is still standing there.
            $this->addError('cart', $e->getMessage());
            $this->pin = '';

            return;
        }

        $this->completedSaleUuid = $sale->uuid;

        $this->clearCart();
        $this->reset(['search', 'discountType', 'paymentMethod']);

        session()->flash('status', "Sale {$sale->invoice_number} saved. Net {$sale->net_amount}.");
    }

    /** @return list<CartLine> */
    private function cartLines(): array
    {
        return array_values(array_map(
            fn (array $line) => new CartLine(
                itemUuid: $line['item_uuid'],
                quantity: (int) $line['quantity'],
                rate: $line['rate'],
                batchUuid: $line['batch_uuid'],
            ),
            $this->cart,
        ));
    }

    /** The sale just completed, so its receipt can be offered without a reload. */
    #[Computed]
    public function completedSale(): ?Sale
    {
        return $this->completedSaleUuid === null ? null : Sale::find($this->completedSaleUuid);
    }

    public function render()
    {
        return view('livewire.point-of-sale', [
            'drawer' => app(SessionService::class)->openSession(),
        ]);
    }
}
