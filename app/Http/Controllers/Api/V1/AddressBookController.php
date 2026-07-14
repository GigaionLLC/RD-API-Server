<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin REST API (v1) — address books owned by the API key's user. Read needs
 * address_book.read; peer create/delete need address_book.write.
 */
class AddressBookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $books = AddressBook::query()
            ->where('user_id', $request->user()->id)
            ->withCount(['peers', 'tags'])
            ->orderBy('name')
            ->get(['id', 'name', 'is_shared']);

        return response()->json(['data' => $books]);
    }

    /**
     * POST /api/v1/address-books — create a book owned by the key's user. Needs
     * `address_book.write`.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        $book = AddressBook::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'note' => $data['note'] ?? null,
            'is_shared' => $data['is_shared'] ?? false,
        ]);

        return response()->json(['data' => $book->only(['id', 'name', 'is_shared'])], 201);
    }

    /**
     * DELETE /api/v1/address-books/{addressBook} — delete a book (and its peers + tags). Needs
     * `address_book.write`; the book must belong to the key's user.
     */
    public function destroy(Request $request, AddressBook $addressBook): JsonResponse
    {
        $this->authorizeBook($request, $addressBook);

        $addressBook->peers()->delete();
        $addressBook->tags()->delete();
        $addressBook->delete();

        return response()->json(['data' => true]);
    }

    public function peers(Request $request, AddressBook $addressBook): JsonResponse
    {
        $this->authorizeBook($request, $addressBook);

        $peers = $addressBook->peers()
            ->orderBy('rustdesk_id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))),
                ['id', 'rustdesk_id', 'alias', 'hostname', 'platform', 'tags', 'note']);

        return response()->json($peers);
    }

    public function storePeer(Request $request, AddressBook $addressBook): JsonResponse
    {
        $this->authorizeBook($request, $addressBook);

        $data = $request->validate([
            'id' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:300'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:255'],
        ]);

        if (AddressBookPeer::where('address_book_id', $addressBook->id)->where('rustdesk_id', $data['id'])->exists()) {
            return response()->json(['error' => 'ID already exists in this address book'], 422);
        }

        if ($addressBook->isFull()) {
            return response()->json(['error' => "Address book is full ({$addressBook->effectiveMaxPeers()} max)"], 422);
        }

        $peer = AddressBookPeer::create([
            'address_book_id' => $addressBook->id,
            'user_id' => $addressBook->user_id,
            'rustdesk_id' => $data['id'],
            'alias' => $data['alias'] ?? null,
            'note' => $data['note'] ?? null,
            'tags' => array_values($data['tags'] ?? []),
        ]);

        return response()->json(['data' => ['id' => $peer->rustdesk_id]], 201);
    }

    public function destroyPeer(Request $request, AddressBook $addressBook, AddressBookPeer $peer): JsonResponse
    {
        $this->authorizeBook($request, $addressBook);

        abort_if($peer->address_book_id !== $addressBook->id, 404);
        $peer->delete();

        return response()->json(['data' => true]);
    }

    private function authorizeBook(Request $request, AddressBook $book): void
    {
        abort_if($book->user_id !== $request->user()->id, 403, 'Not your address book');
    }
}
