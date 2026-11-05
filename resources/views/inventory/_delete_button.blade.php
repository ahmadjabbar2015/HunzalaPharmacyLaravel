{{--
    The delete button, shared by the item screen and the adjust-stock screen
    so both read the same rule rather than two copies drifting apart.

    Needs $item from the caller. Gated by delete-item only (manager/owner, or
    a staff member individually granted it from Staff > Edit) - not by
    whether the item has been received or sold. Deleting one with stock
    history makes old receipts and reports naming it show a blank item name
    from here on; the confirm below says so plainly because that is
    permanent in a way nothing else in this screen is.
--}}
@can('delete-item')
    <form method="POST" action="{{ route('inventory.destroy', $item) }}" class="d-inline"
          onsubmit="return confirm('Delete {{ $item->item_name }}? This cannot be undone, and any past receipt or report naming it will show a blank item name from now on.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-outline-danger">Delete</button>
    </form>
@endcan
