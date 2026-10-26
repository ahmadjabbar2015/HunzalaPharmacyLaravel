{{--
    The delete button, shared by the item screen and the adjust-stock screen
    so both read the same rule rather than two copies drifting apart.

    Needs $item and $canDelete from the caller. Manager/owner only, and only
    reachable when nothing in the ledger names this item (see
    InventoryController / ItemService::delete) - anything received or sold is
    deactivated from the Edit form instead, so its history stays readable.
--}}
@can('delete-item')
    @if ($canDelete)
        <form method="POST" action="{{ route('inventory.destroy', $item) }}" class="d-inline"
              onsubmit="return confirm('Delete {{ $item->item_name }}? This cannot be undone.')">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger">Delete</button>
        </form>
    @else
        <button type="button" class="btn btn-outline-danger" disabled
                title="Has recorded stock activity - mark it inactive from Edit instead.">
            Delete
        </button>
    @endif
@endcan
