<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Address book manager: a RustDesk-client-style view of any user's address book, with full
 * add / edit / delete of peers and tags. Admin operates directly on the models, so it can
 * manage other users' books (not just its own bearer-scoped one like the client API).
 */
class AddressBookController extends Controller
{
    public function index(): View
    {
        $addressBooks = AddressBook::query()
            ->with('user:id,username')
            ->withCount(['peers', 'tags'])
            ->orderBy('name')
            ->paginate(20);

        return view('admin.address_books.index', compact('addressBooks'));
    }

    public function show(AddressBook $addressBook): View
    {
        $addressBook->load('user:id,username', 'tags');

        // Sibling books owned by the same user, for the client-style book switcher.
        $ownerBooks = AddressBook::query()
            ->where('user_id', $addressBook->user_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $peers = $addressBook->peers()->orderBy('rustdesk_id')->paginate(60);

        return view('admin.address_books.show', compact('addressBook', 'ownerBooks', 'peers'));
    }

    // --- Peers --------------------------------------------------------------------------

    public function storePeer(Request $request, AddressBook $addressBook): RedirectResponse
    {
        $data = $this->validatePeer($request);
        $id = trim((string) $data['rustdesk_id']);

        if (AddressBookPeer::where('address_book_id', $addressBook->id)->where('rustdesk_id', $id)->exists()) {
            return back()
                ->withInput()
                ->withErrors(['rustdesk_id' => "ID {$id} already exists in this address book."]);
        }

        $peer = new AddressBookPeer([
            'address_book_id' => $addressBook->id,
            'user_id' => $addressBook->user_id,
            'rustdesk_id' => $id,
        ]);
        $this->fillPeer($peer, $data);
        $peer->save();

        return redirect()
            ->route('admin.address-books.show', $addressBook)
            ->with('status', "Added {$id}.");
    }

    public function updatePeer(Request $request, AddressBookPeer $peer): RedirectResponse
    {
        $data = $this->validatePeer($request);
        $this->fillPeer($peer, $data);
        $peer->save();

        return redirect()
            ->route('admin.address-books.show', $peer->address_book_id)
            ->with('status', "Updated {$peer->rustdesk_id}.");
    }

    public function destroyPeer(AddressBookPeer $peer): RedirectResponse
    {
        $bookId = $peer->address_book_id;
        $peer->delete();

        return redirect()
            ->route('admin.address-books.show', $bookId)
            ->with('status', 'Peer removed.');
    }

    // --- Tags ---------------------------------------------------------------------------

    public function storeTag(Request $request, AddressBook $addressBook): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        Tag::firstOrCreate(
            ['address_book_id' => $addressBook->id, 'name' => trim($data['name'])],
            ['user_id' => $addressBook->user_id, 'color' => $this->hexToArgb($data['color'] ?? null)],
        );

        return redirect()
            ->route('admin.address-books.show', $addressBook)
            ->with('status', 'Tag added.');
    }

    public function updateTag(Request $request, Tag $tag): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $old = $tag->name;
        $new = trim($data['name']);

        $tag->forceFill([
            'name' => $new,
            'color' => $this->hexToArgb($data['color'] ?? null),
        ])->save();

        // Carry a rename through every peer that referenced the old tag name.
        if ($old !== $new) {
            foreach (AddressBookPeer::where('address_book_id', $tag->address_book_id)->get() as $peer) {
                $tags = (array) ($peer->tags ?? []);
                if (in_array($old, $tags, true)) {
                    $peer->tags = array_map(static fn ($t) => $t === $old ? $new : $t, $tags);
                    $peer->save();
                }
            }
        }

        return redirect()
            ->route('admin.address-books.show', $tag->address_book_id)
            ->with('status', 'Tag updated.');
    }

    public function destroyTag(Tag $tag): RedirectResponse
    {
        $bookId = $tag->address_book_id;

        // Strip the tag from any peers that carry it, then delete it.
        foreach (AddressBookPeer::where('address_book_id', $bookId)->get() as $peer) {
            $tags = (array) ($peer->tags ?? []);
            $kept = array_values(array_diff($tags, [$tag->name]));
            if (count($kept) !== count($tags)) {
                $peer->tags = $kept;
                $peer->save();
            }
        }

        $tag->delete();

        return redirect()
            ->route('admin.address-books.show', $bookId)
            ->with('status', 'Tag removed.');
    }

    public function destroy(AddressBook $addressBook): RedirectResponse
    {
        $addressBook->peers()->delete();
        $addressBook->tags()->delete();
        $addressBook->delete();

        return redirect()
            ->route('admin.address-books.index')
            ->with('status', 'Address book deleted.');
    }

    // --- Helpers ------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validatePeer(Request $request): array
    {
        return $request->validate([
            'rustdesk_id' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:300'],
            'password' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:255'],
        ]);
    }

    /**
     * Apply the editable fields to a peer. The password is only touched when a value is
     * supplied, so editing other fields never clears it.
     *
     * @param  array<string, mixed>  $data
     */
    private function fillPeer(AddressBookPeer $peer, array $data): void
    {
        $peer->fill([
            'alias' => $data['alias'] ?? null,
            'note' => $data['note'] ?? null,
            'tags' => array_values($data['tags'] ?? []),
        ]);

        if (($data['password'] ?? '') !== '') {
            $peer->password = $data['password'];
        }
    }

    /**
     * Convert a "#rrggbb" hex string to the opaque ARGB integer (stored as text) the client
     * reads as a Flutter Color value. Falls back to a default blue.
     */
    private function hexToArgb(?string $hex): string
    {
        $hex = ltrim((string) $hex, '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            $hex = '1e88e5';
        }

        return (string) (0xFF000000 | hexdec($hex));
    }
}
