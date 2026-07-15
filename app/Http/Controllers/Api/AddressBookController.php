<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AddressBook;
use App\Models\AddressBookCollaborator;
use App\Models\AddressBookPeer;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Address book endpoints for the RustDesk client
 * (docs/modernization/02-client-api-contract.md §6).
 *
 * Supports both transports:
 *   - Legacy Sciter clients: POST /api/ab/get + POST /api/ab with a single
 *     {"data":"<json string>"} blob carrying tags + peers + tag_colors.
 *   - Newer Flutter clients: the granular per-collection routes
 *     (/api/ab/personal, /api/ab/peers, /api/ab/tags/:guid, /api/ab/peer/*, /api/ab/tag/*).
 *
 * Each user owns a default personal AddressBook collection. The collection's integer id,
 * stringified, is the "guid" used in the granular routes; the literal "personal" and any
 * unknown guid both resolve to that default collection.
 */
class AddressBookController extends Controller
{
    // --- Legacy blob transport ----------------------------------------------------------

    /**
     * POST /api/ab/get — legacy fetch of the whole address book as a JSON-string blob.
     */
    public function getLegacy(Request $request): JsonResponse
    {
        $book = $this->personalBook($request->user());

        return response()->json([
            'data' => json_encode($this->bookBlob($book), JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * POST /api/ab — legacy replace of the whole address book from a JSON-string blob.
     * Newer clients still POST here for full sync; we accept either form.
     */
    public function updateLegacy(Request $request): JsonResponse|Response
    {
        $book = $this->personalBook($request->user());
        $raw = $request->input('data');

        if (! is_string($raw) || $raw === '') {
            return $this->ack();
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'Invalid address book data']);
        }

        $this->replaceBook($book, $payload);

        return $this->ack();
    }

    // --- Granular Flutter transport -----------------------------------------------------

    /**
     * POST /api/ab/personal — returns the personal collection descriptor.
     */
    public function personal(Request $request): JsonResponse
    {
        $book = $this->personalBook($request->user());

        return response()->json([
            'guid' => (string) $book->id,
            'name' => $book->name,
            'owner' => (string) $request->user()->username,
            'note' => '',
            'tag_colors' => $this->tagColorsJson($book),
        ]);
    }

    /**
     * POST /api/ab/settings — address book capability flags the client reads.
     */
    public function settings(Request $request): JsonResponse
    {
        return response()->json([
            'max_peer_one_ab' => (int) config('rustdesk.ab_max_peers', 0), // 0 = unlimited
            'allow_ab_personal' => (bool) config('rustdesk.personal_address_book', true),
        ]);
    }

    /**
     * POST /api/ab/shared/profiles — shared (non-personal) collections accessible to the user:
     * the shared books they own, plus books shared with them as a collaborator. Each profile
     * carries the user's `rule` (ShareRule) so the client knows whether to allow edits.
     * Returns the standard paginated DataResponse shape.
     */
    public function sharedProfiles(Request $request): JsonResponse
    {
        $user = $request->user();
        $profiles = [];

        // Shared books the user owns (full control).
        foreach (AddressBook::where('user_id', $user->id)->where('is_shared', true)->get() as $book) {
            $profiles[] = $this->profileShape($book, (string) $user->username, AddressBookCollaborator::RULE_FULL);
        }

        // Books shared with the user by someone else.
        $collabs = AddressBookCollaborator::with('addressBook.user')
            ->where('user_id', $user->id)
            ->get();
        foreach ($collabs as $collab) {
            $book = $collab->addressBook;
            if ($book !== null && $book->is_shared) {
                $profiles[] = $this->profileShape($book, (string) ($book->user->username ?? ''), (int) $collab->rule);
            }
        }

        return response()->json([
            'total' => count($profiles),
            'data' => $profiles,
        ]);
    }

    /**
     * POST /api/ab/peers — paginated peers for a collection (query: current, pageSize, ab).
     */
    public function peers(Request $request): JsonResponse
    {
        $book = $this->resolveBook($request->user(), (string) $request->query('ab', ''));

        $page = max(1, (int) $request->query('current', 1));
        $pageSize = (int) $request->query('pageSize', 0);

        $query = AddressBookPeer::where('address_book_id', $book->id)->orderBy('id');
        $total = $query->count();

        if ($pageSize > 0) {
            $query->forPage($page, $pageSize);
        }

        $peers = $query->get()->map(fn (AddressBookPeer $peer) => $this->peerShape($peer))->all();

        return response()->json([
            'total' => $total,
            'data' => $peers,
            'tag_colors' => $this->tagColorsJson($book),
        ]);
    }

    /**
     * POST /api/ab/tags/:guid — the tag list for a collection.
     */
    public function tags(Request $request, string $guid): JsonResponse|Response
    {
        $book = $this->resolveBook($request->user(), $guid);

        $tags = Tag::where('address_book_id', $book->id)->orderBy('id')->get()
            ->map(fn (Tag $tag) => $this->tagShape($tag))->all();

        return response()->json($tags);
    }

    // --- Peer mutations -----------------------------------------------------------------

    /**
     * POST /api/ab/peer/add/:guid — add a peer to a collection.
     */
    public function peerAdd(Request $request, string $guid): JsonResponse|Response
    {
        $book = $this->resolveBook($request->user(), $guid);
        if ($deny = $this->denyIfReadOnly($book, $request->user())) {
            return $deny;
        }
        $data = $this->peerInput($request);

        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            return response()->json(['error' => 'Peer id is required']);
        }

        if (AddressBookPeer::where('address_book_id', $book->id)->where('rustdesk_id', $id)->exists()) {
            return response()->json(['error' => 'ID already exists']);
        }

        if ($book->isFull()) {
            return response()->json(['error' => 'Address book is full ('.$book->effectiveMaxPeers().' max)']);
        }

        AddressBookPeer::create($this->mapPeer($book, $data));

        return $this->ack();
    }

    /**
     * PUT /api/ab/peer/update/:guid — update an existing peer in a collection.
     */
    public function peerUpdate(Request $request, string $guid): JsonResponse|Response
    {
        $book = $this->resolveBook($request->user(), $guid);
        if ($deny = $this->denyIfReadOnly($book, $request->user())) {
            return $deny;
        }
        $data = $this->peerInput($request);

        $id = (string) ($data['id'] ?? '');
        $peer = AddressBookPeer::where('address_book_id', $book->id)
            ->where('rustdesk_id', $id)
            ->first();

        if (! $peer) {
            return response()->json(['error' => 'Peer not found']);
        }

        $peer->fill($this->mapPeer($book, $data))->save();

        return $this->ack();
    }

    /**
     * DELETE /api/ab/peer/:guid — delete peers by id from a collection.
     * The client sends the peer id(s) in the JSON body (array of ids).
     */
    public function peerDelete(Request $request, string $guid): JsonResponse|Response
    {
        $book = $this->resolveBook($request->user(), $guid);
        if ($deny = $this->denyIfReadOnly($book, $request->user())) {
            return $deny;
        }
        $ids = $this->idList($request);

        if ($ids !== []) {
            AddressBookPeer::where('address_book_id', $book->id)
                ->whereIn('rustdesk_id', $ids)
                ->delete();
        }

        return $this->ack();
    }

    // --- Tag mutations ------------------------------------------------------------------

    /**
     * POST /api/ab/tag/add/:guid — add a tag to a collection.
     */
    public function tagAdd(Request $request, string $guid): JsonResponse|Response
    {
        $book = $this->resolveBook($request->user(), $guid);
        if ($deny = $this->denyIfReadOnly($book, $request->user())) {
            return $deny;
        }
        $name = $this->tagName($request);

        if ($name === '') {
            return response()->json(['error' => 'Tag name is required']);
        }

        Tag::firstOrCreate(
            ['address_book_id' => $book->id, 'name' => $name],
            ['user_id' => $request->user()->id, 'color' => $this->tagColor($request)]
        );

        return $this->ack();
    }

    /**
     * PUT /api/ab/tag/update/:guid — update a tag's colour.
     */
    public function tagUpdate(Request $request, string $guid): JsonResponse|Response
    {
        $book = $this->resolveBook($request->user(), $guid);
        if ($deny = $this->denyIfReadOnly($book, $request->user())) {
            return $deny;
        }
        $name = $this->tagName($request);

        $tag = Tag::where('address_book_id', $book->id)->where('name', $name)->first();
        if (! $tag) {
            return response()->json(['error' => 'Tag not found']);
        }

        $tag->forceFill(['color' => $this->tagColor($request)])->save();

        return $this->ack();
    }

    /**
     * PUT /api/ab/tag/rename/:guid — rename a tag (body: {old, new}).
     */
    public function tagRename(Request $request, string $guid): JsonResponse|Response
    {
        $book = $this->resolveBook($request->user(), $guid);
        if ($deny = $this->denyIfReadOnly($book, $request->user())) {
            return $deny;
        }

        $old = (string) ($request->input('old') ?? $request->input('oldName') ?? '');
        $new = (string) ($request->input('new') ?? $request->input('newName') ?? '');

        if ($old === '' || $new === '') {
            return response()->json(['error' => 'Both old and new tag names are required']);
        }

        $tag = Tag::where('address_book_id', $book->id)->where('name', $old)->first();
        if (! $tag) {
            return response()->json(['error' => 'Tag not found']);
        }

        $changes = ['name' => $new];
        // A rename may also carry an updated colour (the client sends it alongside).
        if ($request->input('color') !== null && $request->input('color') !== '') {
            $changes['color'] = $this->tagColor($request);
        }
        $tag->forceFill($changes)->save();

        // Carry the rename through any peers that referenced the old tag name.
        foreach (AddressBookPeer::where('address_book_id', $book->id)->get() as $peer) {
            $peerTags = (array) ($peer->tags ?? []);
            if (in_array($old, $peerTags, true)) {
                $peer->tags = array_values(array_map(
                    static fn ($t) => $t === $old ? $new : $t,
                    $peerTags
                ));
                $peer->save();
            }
        }

        return $this->ack();
    }

    /**
     * DELETE /api/ab/tag/:guid — delete tag(s) by name from a collection.
     */
    public function tagDelete(Request $request, string $guid): JsonResponse|Response
    {
        $book = $this->resolveBook($request->user(), $guid);
        if ($deny = $this->denyIfReadOnly($book, $request->user())) {
            return $deny;
        }
        $names = $this->idList($request);

        if ($names === [] && ($single = $this->tagName($request)) !== '') {
            $names = [$single];
        }

        if ($names !== []) {
            Tag::where('address_book_id', $book->id)->whereIn('name', $names)->delete();

            // Strip the deleted tags from peers.
            foreach (AddressBookPeer::where('address_book_id', $book->id)->get() as $peer) {
                $peerTags = (array) ($peer->tags ?? []);
                $kept = array_values(array_diff($peerTags, $names));
                if (count($kept) !== count($peerTags)) {
                    $peer->tags = $kept;
                    $peer->save();
                }
            }
        }

        return $this->ack();
    }

    // --- Helpers ------------------------------------------------------------------------

    /**
     * Success ack for the granular mutation endpoints. The Flutter client's
     * `_jsonDecodeActionResp` (ab_model.dart) treats success as **HTTP 200 with an EMPTY
     * body**; any non-empty body (e.g. "{}" or "[]") makes it stringify a missing `error`
     * key — "{}" → null.toString() → "null", "[]" → the raw "[]" — and surface that as the
     * error. So a mutation ack MUST be an empty 200, never a JSON value. Errors stay as
     * `{"error": "..."}`. See docs/modernization/16-response-contract.md §0.4.
     */
    private function ack(): Response
    {
        return response('', 200);
    }

    /**
     * The user's default personal address book, created on first use.
     */
    private function personalBook(User $user): AddressBook
    {
        return AddressBook::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'My address book'],
        );
    }

    /**
     * Resolve a collection guid to an AddressBook the user can at least read — their own book,
     * or a shared book they collaborate on. Unknown / "personal" guids, and guids the user has
     * no access to, fall back to the personal collection.
     */
    private function resolveBook(User $user, string $guid): AddressBook
    {
        if ($guid !== '' && $guid !== 'personal' && ctype_digit($guid)) {
            $book = AddressBook::find((int) $guid);
            if ($book !== null && $book->canRead($user)) {
                return $book;
            }
        }

        return $this->personalBook($user);
    }

    /**
     * Guard a mutation: returns an error response when the user lacks write permission on the
     * resolved book (a read-only collaborator), or null when the write may proceed.
     */
    private function denyIfReadOnly(AddressBook $book, User $user): ?JsonResponse
    {
        if ($book->canWrite($user)) {
            return null;
        }

        return response()->json(['error' => 'You do not have permission to modify this address book']);
    }

    /**
     * Shape a shared address book as the AbProfile the client lists under shared/profiles.
     *
     * @return array<string, mixed>
     */
    private function profileShape(AddressBook $book, string $owner, int $rule): array
    {
        return [
            'guid' => (string) $book->id,
            'name' => (string) $book->name,
            'owner' => $owner,
            'note' => (string) ($book->note ?? ''),
            'rule' => $rule,
        ];
    }

    /**
     * Pull the per-peer input from either the body or a nested {data:{...}} wrapper.
     *
     * @return array<string, mixed>
     */
    private function peerInput(Request $request): array
    {
        $data = $request->input('data');
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($data)) {
            return $data;
        }

        return $request->all();
    }

    /**
     * Map client peer fields onto AddressBookPeer columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapPeer(AddressBook $book, array $data): array
    {
        $mapped = [
            'address_book_id' => $book->id,
            'user_id' => $book->user_id,
            'rustdesk_id' => (string) ($data['id'] ?? ''),
        ];

        $fields = [
            'username' => 'username',
            'hostname' => 'hostname',
            'platform' => 'platform',
            'alias' => 'alias',
            'rdpPort' => 'rdp_port',
            'rdpUsername' => 'rdp_username',
            'loginName' => 'login_name',
            'note' => 'note',
            'hash' => 'hash',
            'password' => 'password',
        ];

        foreach ($fields as $in => $col) {
            if (array_key_exists($in, $data)) {
                $mapped[$col] = $data[$in];
            }
        }

        // RustDesk sends this boolean as the literal strings "true" and "false". Normalize
        // it before persistence because MariaDB's strict mode rejects those strings for a
        // TINYINT column (the response remains string-shaped for wire compatibility).
        if (array_key_exists('forceAlwaysRelay', $data)) {
            $mapped['force_always_relay'] = filter_var(
                $data['forceAlwaysRelay'],
                FILTER_VALIDATE_BOOL,
            );
        }

        if (array_key_exists('tags', $data)) {
            $mapped['tags'] = is_array($data['tags']) ? array_values($data['tags']) : [];
        }

        return $mapped;
    }

    /**
     * Render a peer in the shape the client round-trips (contract §6).
     *
     * @return array<string, mixed>
     */
    private function peerShape(AddressBookPeer $peer): array
    {
        return [
            'id' => (string) $peer->rustdesk_id,
            'username' => (string) ($peer->username ?? ''),
            'hostname' => (string) ($peer->hostname ?? ''),
            'platform' => (string) ($peer->platform ?? ''),
            'alias' => (string) ($peer->alias ?? ''),
            'tags' => (array) ($peer->tags ?? []),
            // The client compares this to the literal string 'true' (peer_model.dart:
            // `json['forceAlwaysRelay'] == 'true'`), so a JSON boolean is silently dropped.
            // Emit the string form — see docs/modernization/16-response-contract.md §3.
            'forceAlwaysRelay' => $peer->force_always_relay ? 'true' : 'false',
            'rdpPort' => (string) ($peer->rdp_port ?? ''),
            'rdpUsername' => (string) ($peer->rdp_username ?? ''),
            'loginName' => (string) ($peer->login_name ?? ''),
            'hash' => (string) ($peer->hash ?? ''),
            'note' => (string) ($peer->note ?? ''),
        ];
    }

    /**
     * Render a tag in the granular client shape.
     *
     * @return array<string, mixed>
     */
    private function tagShape(Tag $tag): array
    {
        return [
            'name' => (string) $tag->name,
            'color' => $tag->color !== null ? (int) $tag->color : 0,
        ];
    }

    /**
     * Assemble the legacy blob (tags + peers + tag_colors) for /api/ab/get.
     *
     * @return array<string, mixed>
     */
    private function bookBlob(AddressBook $book): array
    {
        $tags = Tag::where('address_book_id', $book->id)->get();

        $peers = AddressBookPeer::where('address_book_id', $book->id)->get()
            ->map(fn (AddressBookPeer $peer) => $this->peerShape($peer))->all();

        return [
            'tags' => $tags->pluck('name')->values()->all(),
            'peers' => $peers,
            'tag_colors' => $this->tagColorsJson($book),
        ];
    }

    /**
     * The tag-name → color-int map for a collection, JSON-encoded as a string. This is the
     * shape the RustDesk client round-trips as `tag_colors` (flutter ab_model.dart). Returns
     * the encoding of an empty object ("{}") when the collection has no coloured tags.
     */
    private function tagColorsJson(AddressBook $book): string
    {
        $tagColors = [];
        foreach (Tag::where('address_book_id', $book->id)->get() as $tag) {
            $tagColors[$tag->name] = $tag->color !== null ? (int) $tag->color : 0;
        }

        return (string) json_encode((object) $tagColors, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Replace a collection's tags + peers from a decoded legacy blob.
     *
     * @param  array<string, mixed>  $payload
     */
    private function replaceBook(AddressBook $book, array $payload): void
    {
        $tagColors = [];
        if (isset($payload['tag_colors'])) {
            $decoded = is_string($payload['tag_colors'])
                ? json_decode($payload['tag_colors'], true)
                : $payload['tag_colors'];
            if (is_array($decoded)) {
                $tagColors = $decoded;
            }
        }

        // Rebuild tags.
        Tag::where('address_book_id', $book->id)->delete();
        foreach ((array) ($payload['tags'] ?? []) as $name) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            Tag::create([
                'address_book_id' => $book->id,
                'user_id' => $book->user_id,
                'name' => $name,
                'color' => isset($tagColors[$name]) ? (int) $tagColors[$name] : null,
            ]);
        }

        // Rebuild peers.
        AddressBookPeer::where('address_book_id', $book->id)->delete();
        foreach ((array) ($payload['peers'] ?? []) as $peer) {
            if (! is_array($peer) || ($peer['id'] ?? '') === '') {
                continue;
            }
            AddressBookPeer::create($this->mapPeer($book, $peer));
        }
    }

    /**
     * Extract a list of ids/names from the request body (array, or comma string, or single).
     *
     * @return list<string>
     */
    private function idList(Request $request): array
    {
        $body = $request->json()->all();
        if (is_array($body) && array_is_list($body) && $body !== []) {
            return array_values(array_map('strval', $body));
        }

        $value = $request->input('id') ?? $request->input('ids') ?? $request->input('name');
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }

    private function tagName(Request $request): string
    {
        return trim((string) ($request->input('name') ?? $request->input('tag') ?? ''));
    }

    private function tagColor(Request $request): ?int
    {
        $color = $request->input('color');

        return $color === null || $color === '' ? null : (int) $color;
    }
}
