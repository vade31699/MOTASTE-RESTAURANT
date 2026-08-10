<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NameUtil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated inventory domain endpoints.
 *
 * Replaces: public/api/get_inventory.php, update_inventory.php,
 * delete_inventory_item.php, upload_special_food_image.php
 */
class InventoryController extends Controller
{
    /**
     * Port of get_inventory.php (dedupes by normalized name, drops the legacy
     * 'softdrinks' placeholder, derives status from stock when missing).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Remove the legacy 'softdrinks' placeholder row if present.
            $this->deleteByNormalizedName('softdrinks');

            $rawItems = DB::table('inventory_items')
                ->select('name', 'price', 'stock', 'status', 'category', 'description', 'image')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get()
                ->all();

            $itemsByName = [];
            foreach ($rawItems as $row) {
                $normalizedName = NameUtil::normalizeInventoryName((string) ($row->name ?? ''));
                if ($normalizedName === '' || $normalizedName === 'softdrinks' || isset($itemsByName[$normalizedName])) {
                    continue;
                }

                $stock = (int) ($row->stock ?? 0);
                $itemsByName[$normalizedName] = [
                    'name' => trim((string) $row->name),
                    'price' => (float) ($row->price ?? 0),
                    'stock' => $stock,
                    'status' => $row->status ?: ($stock > 0 ? 'In stock' : 'Out of stock'),
                    'category' => $row->category ?: 'specials',
                    'description' => trim((string) ($row->description ?? '')),
                    'image' => trim((string) ($row->image ?? '')),
                ];
            }

            return response()->json(['success' => true, 'items' => array_values($itemsByName)]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Database query failed'], 500);
        }
    }

    /**
     * Port of update_inventory.php (replace-style upsert keyed by name).
     */
    public function save(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (!is_array($input)) {
            $input = $request->all();
        }

        $name = isset($input['name']) ? trim($input['name']) : '';
        $previousName = isset($input['previousName']) ? trim((string) $input['previousName']) : '';
        $price = isset($input['price']) ? (float) $input['price'] : 0;
        $stock = isset($input['stock']) ? (int) $input['stock'] : 0;
        $category = isset($input['category']) ? trim($input['category']) : 'specials';
        $description = isset($input['description']) ? trim((string) $input['description']) : '';
        $status = isset($input['status']) ? trim($input['status']) : ($stock > 0 ? 'In stock' : 'Out of stock');
        $actor = $this->actorContext($request);

        $canonicalName = trim((string) preg_replace('/\s+/', ' ', $name));

        if ($canonicalName === '') {
            return response()->json(['success' => false, 'error' => 'Item name is required'], 400);
        }

        if (in_array(strtolower($canonicalName), ['softdrinks'], true)) {
            return response()->json(['success' => false, 'error' => 'Softdrinks is no longer allowed in inventory'], 409);
        }

        $normalizedStatus = $stock > 0 ? ($status === 'Out of stock' ? 'In stock' : $status) : 'Out of stock';

        try {
            $normalizedLookup = NameUtil::normalizeInventoryName($canonicalName);
            $normalizedPrevious = NameUtil::normalizeInventoryName($previousName);

            $existingBefore = null;
            if ($normalizedPrevious !== '') {
                $existingBefore = $this->findByNormalizedName($normalizedPrevious);
            }

            if (!$existingBefore) {
                $existingBefore = $this->findByNormalizedName($normalizedLookup);
            }

            $image = isset($input['image']) ? trim((string) $input['image']) : null;

            $itemId = null;
            DB::transaction(function () use ($normalizedLookup, $normalizedPrevious, $canonicalName, $price, $stock, $normalizedStatus, $category, $description, $image, &$itemId) {
                $deleteIds = $this->findIdsByNormalizedNames(array_values(array_filter([$normalizedLookup, $normalizedPrevious])));
                if (!empty($deleteIds)) {
                    DB::table('inventory_items')->whereIn('id', $deleteIds)->delete();
                }

                $itemId = DB::table('inventory_items')->insertGetId([
                    'name' => $canonicalName,
                    'price' => $price,
                    'stock' => $stock,
                    'status' => $normalizedStatus,
                    'category' => $category,
                    'description' => $description !== '' ? $description : null,
                    'image' => $image !== '' ? $image : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $action = 'inventory_item_added';
            if ($existingBefore) {
                $previousStock = (int) ($existingBefore->stock ?? 0);
                $action = $previousStock !== $stock ? 'inventory_stock_changed' : 'inventory_item_updated';
            }

            $this->logActivity([
                'action' => $action,
                'actor_role' => $actor['role'],
                'actor_email' => $actor['email'],
                'summary' => $canonicalName.' x'.$stock,
                'details' => [
                    'name' => $canonicalName,
                    'stock' => $stock,
                    'price' => $price,
                    'category' => $category,
                    'description' => $description,
                    'status' => $normalizedStatus,
                    'previous_name' => $existingBefore ? $existingBefore->name : null,
                    'previous_stock' => $existingBefore ? (int) ($existingBefore->stock ?? 0) : null,
                    'updated_at' => now()->toDateTimeString(),
                ],
            ]);

            return response()->json([
                'success' => true,
                'itemId' => $itemId,
                'stock' => $stock,
                'status' => $normalizedStatus,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Database update failed', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of delete_inventory_item.php (also prunes the custom menu snapshot).
     */
    public function delete(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (!is_array($input)) {
            $input = $request->all();
        }

        $name = trim((string) ($input['name'] ?? ''));
        $actor = $this->actorContext($request);

        if ($name === '') {
            return response()->json(['success' => false, 'error' => 'name is required'], 400);
        }

        try {
            $normalizedName = NameUtil::normalizeItemName($name);

            $existing = $this->findByNormalizedName($normalizedName);
            $removedFromSnapshot = $this->removeFromCustomMenuSnapshot($normalizedName);

            if (!$existing) {
                // Keep delete idempotent: special items may exist only in the snapshot.
                return response()->json([
                    'success' => true,
                    'deletedFromInventory' => false,
                    'deletedFromSnapshot' => $removedFromSnapshot,
                ]);
            }

            DB::table('inventory_items')->where('id', $existing->id)->delete();

            $this->logActivity([
                'action' => 'inventory_item_removed',
                'actor_role' => $actor['role'],
                'actor_email' => $actor['email'],
                'summary' => trim((string) $existing->name),
                'details' => [
                    'name' => trim((string) $existing->name),
                    'stock' => (int) ($existing->stock ?? 0),
                    'price' => (float) ($existing->price ?? 0),
                    'removed_at' => now()->toDateTimeString(),
                ],
            ]);

            return response()->json([
                'success' => true,
                'deletedFromInventory' => true,
                'deletedFromSnapshot' => $removedFromSnapshot,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Unable to delete inventory item', 'details' => $error->getMessage()], 500);
        }
    }

    /**
     * Port of upload_special_food_image.php (multipart `image` field).
     */
    public function uploadImage(Request $request): JsonResponse
    {
        try {
            if (!$request->hasFile('image') || !$request->file('image')->isValid()) {
                return response()->json(['success' => false, 'error' => 'No image file uploaded'], 400);
            }

            $file = $request->file('image');
            $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($extension, $allowed, true)) {
                $extension = 'jpg';
            }

            $fileName = 'special-food-'.time().'-'.bin2hex(random_bytes(6)).'.'.$extension;
            $publicDirectory = public_path('special_food_images');
            if (!is_dir($publicDirectory) && !mkdir($publicDirectory, 0755, true) && !is_dir($publicDirectory)) {
                throw new \RuntimeException('Unable to create public image directory');
            }

            $file->move($publicDirectory, $fileName);

            $relativeUrl = '/special_food_images/'.$fileName;
            $url = url($relativeUrl);

            return response()->json([
                'success' => true,
                'url' => $url,
                'path' => $publicDirectory.DIRECTORY_SEPARATOR.$fileName,
                'relativeUrl' => $relativeUrl,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'error' => 'Image upload failed', 'details' => $error->getMessage()], 500);
        }
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    private function findByNormalizedName(string $normalizedName): ?object
    {
        $items = DB::table('inventory_items')->select('id', 'name', 'stock', 'price', 'status')->get();
        foreach ($items as $item) {
            if (NameUtil::normalizeInventoryName((string) ($item->name ?? '')) === $normalizedName) {
                return $item;
            }
        }

        return null;
    }

    private function findIdsByNormalizedNames(array $normalizedNames): array
    {
        $ids = [];
        $items = DB::table('inventory_items')->select('id', 'name')->get();
        foreach ($items as $item) {
            if (in_array(NameUtil::normalizeInventoryName((string) ($item->name ?? '')), $normalizedNames, true)) {
                $ids[] = (int) ($item->id ?? 0);
            }
        }

        return array_values(array_unique($ids));
    }

    private function deleteByNormalizedName(string $normalizedName): void
    {
        $item = $this->findByNormalizedName($normalizedName);
        if ($item) {
            DB::table('inventory_items')->where('id', $item->id)->delete();
        }
    }

    private function removeFromCustomMenuSnapshot(string $normalizedName): bool
    {
        if ($normalizedName === '') {
            return false;
        }

        $snapshotRow = DB::table('custom_menu_snapshots')
            ->where('snapshot_key', 'motaste-menu')
            ->first();

        if (!$snapshotRow || !isset($snapshotRow->snapshot_payload)) {
            return false;
        }

        $payload = json_decode((string) $snapshotRow->snapshot_payload, true);
        if (!is_array($payload)) {
            return false;
        }

        $removed = false;

        if (isset($payload['specialFoods']) && is_array($payload['specialFoods'])) {
            $before = count($payload['specialFoods']);
            $payload['specialFoods'] = array_values(array_filter($payload['specialFoods'], function ($food) use ($normalizedName) {
                return NameUtil::normalizeItemName((string) ($food['name'] ?? '')) !== $normalizedName;
            }));

            if (count($payload['specialFoods']) !== $before) {
                $removed = true;
            }
        }

        if (isset($payload['menuData']) && is_array($payload['menuData'])) {
            foreach ($payload['menuData'] as $categoryKey => $category) {
                if (!is_array($category) || !isset($category['items']) || !is_array($category['items'])) {
                    continue;
                }

                $before = count($category['items']);
                $category['items'] = array_values(array_filter($category['items'], function ($item) use ($normalizedName) {
                    return NameUtil::normalizeItemName((string) ($item['name'] ?? '')) !== $normalizedName;
                }));

                if (count($category['items']) !== $before) {
                    $removed = true;
                }

                $payload['menuData'][$categoryKey] = $category;
            }
        }

        if ($removed) {
            DB::table('custom_menu_snapshots')
                ->where('snapshot_key', 'motaste-menu')
                ->update([
                    'snapshot_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }

        return $removed;
    }

    private function actorContext(Request $request): array
    {
        $staff = $request->session()->get('staff_session');
        if (is_array($staff) && ($staff['email'] ?? '') !== '') {
            return [
                'role' => (string) ($staff['role'] ?? 'Staff'),
                'email' => strtolower(trim((string) ($staff['email'] ?? ''))),
            ];
        }

        return [
            'role' => trim((string) $request->input('actorRole', 'Staff')) !== '' ? trim((string) $request->input('actorRole', 'Staff')) : 'Staff',
            'email' => strtolower(trim((string) $request->input('actorEmail', ''))) ?: null,
        ];
    }

    private function logActivity(array $entry): void
    {
        try {
            DB::table('order_activity_logs')->insert([
                'order_id' => null,
                'order_number' => null,
                'action' => $entry['action'] ?? '',
                'actor_role' => ($entry['actor_role'] ?? '') !== '' ? $entry['actor_role'] : null,
                'actor_email' => ($entry['actor_email'] ?? '') !== '' ? $entry['actor_email'] : null,
                'summary' => ($entry['summary'] ?? '') !== '' ? $entry['summary'] : null,
                'details' => is_array($entry['details'] ?? null)
                    ? json_encode($entry['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (($entry['details'] ?? null) !== null ? (string) $entry['details'] : null),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never block the primary operation.
        }
    }
}
