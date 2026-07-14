<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Models\AddressBook;
use App\Models\AddressBookCollaborator;
use App\Models\AddressBookPeer;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Address book manager: a RustDesk-client-style view of any user's address book, with full
 * add / edit / delete of peers and tags. Admin operates directly on the models, so it can
 * manage other users' books (not just its own bearer-scoped one like the client API).
 */
class AddressBookController extends Controller
{
    use ExportsCsv;

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
        $addressBook->load('user:id,username', 'tags', 'collaborators.user:id,username');

        // Sibling books owned by the same user, for the client-style book switcher.
        $ownerBooks = AddressBook::query()
            ->where('user_id', $addressBook->user_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $peers = $addressBook->peers()->orderBy('rustdesk_id')->paginate(60);

        return view('admin.address_books.show', [
            'addressBook' => $addressBook,
            'ownerBooks' => $ownerBooks,
            'peers' => $peers,
            'ruleList' => AddressBookCollaborator::RULES,
        ]);
    }

    // --- Import / export ----------------------------------------------------------------

    /**
     * Export a book's peers as CSV (columns: id, alias, note, tags) — the same shape `import`
     * accepts, so an export round-trips.
     */
    public function exportPeers(AddressBook $addressBook): StreamedResponse
    {
        $query = AddressBookPeer::where('address_book_id', $addressBook->id)->orderBy('rustdesk_id');

        return $this->streamCsv('address-book-'.$addressBook->id, ['id', 'alias', 'note', 'tags'], $query,
            fn (AddressBookPeer $p): array => [
                $p->rustdesk_id, $p->alias, $p->note, implode(';', (array) ($p->tags ?? [])),
            ]);
    }

    /**
     * Import peers from an uploaded CSV (columns: id, alias, note, tags; tags `;`-separated).
     * Existing ids and rows beyond the per-book cap are skipped.
     */
    public function importPeers(Request $request, AddressBook $addressBook): RedirectResponse
    {
        $this->validateForModal(
            $request,
            'import',
            ['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']],
            ['id' => 'importModal'],
            route('admin.address-books.show', $addressBook),
        );

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return redirect()
                ->route('admin.address-books.show', $addressBook)
                ->withErrors(['file' => 'Could not read the uploaded file.'], 'import')
                ->with('address_book_modal', ['id' => 'importModal']);
        }

        $limit = $addressBook->effectiveMaxPeers();
        $existing = AddressBookPeer::where('address_book_id', $addressBook->id)
            ->pluck('rustdesk_id')->map('strval')->all();
        $count = count($existing);
        $added = 0;
        $skipped = 0;
        $first = true;

        while (($cols = fgetcsv($handle)) !== false) {
            $id = trim((string) ($cols[0] ?? ''));

            // Skip an optional header row.
            if ($first) {
                $first = false;
                if (strtolower($id) === 'id') {
                    continue;
                }
            }

            if ($id === '') {
                continue;
            }
            if (in_array($id, $existing, true) || ($limit > 0 && $count >= $limit)) {
                $skipped++;

                continue;
            }

            AddressBookPeer::create([
                'address_book_id' => $addressBook->id,
                'user_id' => $addressBook->user_id,
                'rustdesk_id' => $id,
                'alias' => trim((string) ($cols[1] ?? '')) ?: null,
                'note' => trim((string) ($cols[2] ?? '')) ?: null,
                'tags' => array_values(array_filter(array_map('trim', explode(';', (string) ($cols[3] ?? ''))))),
            ]);

            $existing[] = $id;
            $count++;
            $added++;
        }

        fclose($handle);

        return redirect()
            ->route('admin.address-books.show', $addressBook)
            ->with('status', "Imported {$added} peer(s); skipped {$skipped}.");
    }

    // --- Sharing ------------------------------------------------------------------------

    /**
     * Toggle whether a book is a shared team book, and set its description note.
     */
    public function updateSharing(Request $request, AddressBook $addressBook): RedirectResponse
    {
        $data = $this->validateForModal(
            $request,
            'sharing',
            [
                'note' => ['nullable', 'string', 'max:255'],
                'max_peers' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            ],
            ['id' => 'shareModal', 'section' => 'sharing'],
            route('admin.address-books.show', $addressBook),
        );

        $addressBook->forceFill([
            'is_shared' => $request->boolean('is_shared'),
            'note' => $data['note'] ?? null,
            // Blank field → null → use the server-wide default.
            'max_peers' => $data['max_peers'] ?? null,
        ])->save();

        return redirect()
            ->route('admin.address-books.show', $addressBook)
            ->with('status', $addressBook->is_shared ? 'Sharing enabled.' : 'Sharing disabled.');
    }

    /**
     * Grant a user access to a shared book at a given rule (read / read-write / full control).
     */
    public function storeCollaborator(Request $request, AddressBook $addressBook): RedirectResponse
    {
        $modalState = ['id' => 'shareModal', 'section' => 'collaborator'];
        $data = $this->validateForModal(
            $request,
            'collaborator',
            [
                'user_id' => ['required', 'integer', 'exists:users,id'],
                'user_search' => ['nullable', 'string', 'max:255'],
                'rule' => ['required', 'integer', Rule::in(array_keys(AddressBookCollaborator::RULES))],
            ],
            $modalState,
            route('admin.address-books.show', $addressBook),
        );

        if ((int) $data['user_id'] === (int) $addressBook->user_id) {
            return redirect()
                ->route('admin.address-books.show', $addressBook)
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['user_id' => 'The owner already has full control.'], 'collaborator')
                ->with('address_book_modal', $modalState);
        }

        AddressBookCollaborator::updateOrCreate(
            ['address_book_id' => $addressBook->id, 'user_id' => $data['user_id']],
            ['rule' => $data['rule']],
        );

        // Sharing implies the book is shared; flip the flag on so it surfaces to the client.
        if (! $addressBook->is_shared) {
            $addressBook->forceFill(['is_shared' => true])->save();
        }

        $username = User::whereKey($data['user_id'])->value('username');

        return redirect()
            ->route('admin.address-books.show', $addressBook)
            ->with('status', "Shared with {$username}.");
    }

    public function destroyCollaborator(AddressBookCollaborator $collaborator): RedirectResponse
    {
        $bookId = $collaborator->address_book_id;
        $collaborator->delete();

        return redirect()
            ->route('admin.address-books.show', $bookId)
            ->with('status', 'Collaborator removed.');
    }

    // --- Peers --------------------------------------------------------------------------

    public function storePeer(Request $request, AddressBook $addressBook): RedirectResponse
    {
        $modalState = ['id' => 'peerModal', 'mode' => 'add'];
        $data = $this->validatePeer(
            $request,
            $modalState,
            route('admin.address-books.show', $addressBook),
        );
        $id = trim((string) $data['rustdesk_id']);

        if (AddressBookPeer::where('address_book_id', $addressBook->id)->where('rustdesk_id', $id)->exists()) {
            return redirect()
                ->route('admin.address-books.show', $addressBook)
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['rustdesk_id' => "ID {$id} already exists in this address book."], 'peer')
                ->with('address_book_modal', $modalState);
        }

        if ($addressBook->isFull()) {
            return redirect()
                ->route('admin.address-books.show', $addressBook)
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['rustdesk_id' => "This address book is full ({$addressBook->effectiveMaxPeers()} max)."], 'peer')
                ->with('address_book_modal', $modalState);
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
        $data = $this->validatePeer(
            $request,
            ['id' => 'peerModal', 'mode' => 'edit', 'record_id' => $peer->getKey()],
            route('admin.address-books.show', $peer->address_book_id),
        );
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
        $data = $this->validateForModal(
            $request,
            'tag',
            [
                'name' => ['required', 'string', 'max:255'],
                'color' => ['nullable', 'string', 'max:16'],
            ],
            ['id' => 'tagModal', 'mode' => 'add'],
            route('admin.address-books.show', $addressBook),
        );

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
        $data = $this->validateForModal(
            $request,
            'tag',
            [
                'name' => ['required', 'string', 'max:255'],
                'color' => ['nullable', 'string', 'max:16'],
            ],
            ['id' => 'tagModal', 'mode' => 'edit', 'record_id' => $tag->getKey()],
            route('admin.address-books.show', $tag->address_book_id),
        );

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
    private function validatePeer(Request $request, array $modalState, string $redirectUrl): array
    {
        return $this->validateForModal(
            $request,
            'peer',
            [
                'rustdesk_id' => ['required', 'string', 'max:255'],
                'alias' => ['nullable', 'string', 'max:255'],
                'note' => ['nullable', 'string', 'max:300'],
                'password' => ['nullable', 'string', 'max:255'],
                'tags' => ['nullable', 'array'],
                'tags.*' => ['string', 'max:255'],
            ],
            $modalState,
            $redirectUrl,
        );
    }

    /**
     * Validate a modal form in its own error bag and remember which dialog must be restored.
     * The explicit redirect keeps direct form submissions on the address-book manager instead
     * of relying on a Referer header.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $modalState
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateForModal(
        Request $request,
        string $errorBag,
        array $rules,
        array $modalState,
        string $redirectUrl,
    ): array {
        try {
            return $request->validateWithBag($errorBag, $rules);
        } catch (ValidationException $exception) {
            $request->session()->flash('address_book_modal', $modalState);
            $exception->redirectTo($redirectUrl);

            throw $exception;
        }
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
