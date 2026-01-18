<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStock;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Charge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * SyncController - Optimized for RN Expo (infoshop-rnexpo)
 *
 * This controller provides normalized data endpoints for the React Native Expo mobile app.
 * Only includes endpoints actively used by the mobile app for optimal performance.
 *
 * Endpoints:
 * - GET /api/sync/health - Health check
 * - GET /api/sync?table=products - Product master data
 * - GET /api/sync?table=batches - Batch pricing/cost data
 * - GET /api/sync?table=stocks&store_id=1 - Store-specific stock quantities
 * - GET /api/sync?table=contacts - Customer/vendor contacts
 * - GET /api/sync?table=settings - Application settings
 * - GET /api/sync?table=stores - Store information
 * - GET /api/sync?table=charges - Taxes/fees/discounts
 */
class SyncController extends Controller
{
    /**
     * Allowed tables for sync endpoints
     * Only includes tables actively used by RN Expo app
     */
    private const ALLOWED_TABLES = [
        'products',   // Product master data
        'batches',    // Batch pricing/cost
        'stocks',     // Store quantities
        'contacts',   // Customers/vendors
        'settings',   // Application settings
        'stores',     // Store information
        'charges',    // Taxes/fees/discounts
        'sales',      // Sales/invoices (read-only for display)
    ];

    /**
     * Health check endpoint
     * GET /api/sync/health
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function healthCheck()
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Verify sync configuration endpoint
     * GET /api/sync/verify
     *
     * This endpoint is used by mobile apps to verify that the sync URL is correct
     * and that the server is reachable before saving configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function verify()
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Sync API is reachable and working correctly',
            'server_time' => now()->toIso8601String(),
            'timestamp' => (int) (now()->getTimestamp() * 1000),
            'version' => '1.0.0',
        ]);
    }

    /**
     * Main fetch endpoint - routes to specific data handlers
     * GET /api/sync?table={products|batches|stocks|contacts|settings|stores|charges}&last_sync={timestamp}&store_id={id}
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetch(Request $request)
    {
        $table = $request->query('table');

        if (!$table || !in_array($table, self::ALLOWED_TABLES)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid table. Allowed: ' . implode(', ', self::ALLOWED_TABLES),
            ], 400);
        }

        return match($table) {
            'products' => $this->getProductsOnly($request),
            'batches' => $this->getBatches($request),
            'stocks' => $this->getStocksOnly($request),
            'contacts' => $this->getContacts($request),
            'settings' => $this->getSettings($request),
            'stores' => $this->getStores($request),
            'charges' => $this->getCharges($request),
            'sales' => $this->getSales($request),
        };
    }

    /**
     * Get products only (normalized - master data without batches/stocks)
     * Returns core product information without pricing or inventory
     * 
     * Query params:
     * - last_sync (optional): Unix timestamp in milliseconds for incremental sync
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getProductsOnly(Request $request)
    {
        $lastSync = $this->parseTimestamp($request->query('last_sync'));

        $query = Product::query()
            ->select(
                'products.id',
                'products.name',
                'products.description',
                'products.sku',
                'products.barcode',
                'products.image_url',
                'products.unit',
                'products.brand_id',
                'products.category_id',
                'products.product_type',
                'products.quantity',
                'products.alert_quantity',
                'products.is_stock_managed',
                'products.is_active',
                'products.is_featured',
                'products.discount',
                'products.meta_data',
                'products.attachment_id',
                'products.updated_at'
            )
            ->where('is_active', 1);

        if ($lastSync) {
            $query->whereRaw('products.updated_at >= ?', [$lastSync->toDateTimeString()]);
        }

        $products = $query->get()->map(function ($product) {
            // Convert image_url to storage URL
            $imageUrl = $product->image_url;
            if (!empty($imageUrl)) {
                $imageUrl = Storage::url($imageUrl);
            }

            return [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'image_url' => $imageUrl,
                'unit' => $product->unit,
                'brand_id' => $product->brand_id,
                'category_id' => $product->category_id,
                'product_type' => $product->product_type,
                'quantity' => (float) $product->quantity,
                'alert_quantity' => (int) $product->alert_quantity,
                'is_stock_managed' => (bool) $product->is_stock_managed,
                'is_active' => (bool) $product->is_active,
                'is_featured' => (bool) $product->is_featured,
                'discount' => (float) $product->discount,
                'meta_data' => $product->meta_data,
                'attachment_id' => $product->attachment_id,
                'updated_at' => $product->updated_at instanceof Carbon
                    ? $product->updated_at->toIso8601String()
                    : Carbon::parse($product->updated_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $products,
            'count' => $products->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get batches only (normalized - batch pricing/cost data)
     * Returns pricing, cost, and batch-specific information
     * 
     * Query params:
     * - last_sync (optional): Unix timestamp in milliseconds for incremental sync
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getBatches(Request $request)
    {
        $lastSync = $this->parseTimestamp($request->query('last_sync'));

        $query = ProductBatch::query()
            ->select(
                'product_batches.id AS batch_id',
                'product_batches.product_id',
                'product_batches.contact_id',
                'product_batches.batch_number',
                'product_batches.expiry_date',
                'product_batches.cost',
                'product_batches.price',
                'product_batches.discount',
                'product_batches.discount_percentage',
                'product_batches.is_active',
                'product_batches.is_featured',
                'product_batches.updated_at'
            )
            ->where('is_active', 1);

        if ($lastSync) {
            $query->whereRaw('product_batches.updated_at >= ?', [$lastSync->toDateTimeString()]);
        }

        $batches = $query->get()->map(function ($batch) {
            return [
                'batch_id' => $batch->batch_id,
                'product_id' => $batch->product_id,
                'contact_id' => $batch->contact_id,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date
                    ? Carbon::parse($batch->expiry_date)->toIso8601String()
                    : null,
                'cost' => (float) $batch->cost,
                'price' => (float) $batch->price,
                'discount' => (float) $batch->discount,
                'discount_percentage' => (float) $batch->discount_percentage,
                'is_active' => (bool) $batch->is_active,
                'is_featured' => (bool) $batch->is_featured,
                'updated_at' => $batch->updated_at instanceof Carbon
                    ? $batch->updated_at->toIso8601String()
                    : Carbon::parse($batch->updated_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $batches,
            'count' => $batches->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get stocks only (normalized - store-level quantities)
     * Returns quantity information per batch per store
     * 
     * Query params:
     * - store_id (optional): Filter stocks by specific store
     * - last_sync (optional): Unix timestamp in milliseconds for incremental sync
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getStocksOnly(Request $request)
    {
        $storeId = $request->query('store_id');
        $lastSync = $this->parseTimestamp($request->query('last_sync'));

        $query = ProductStock::query()
            ->select(
                'product_stocks.id',
                'product_stocks.batch_id',
                'product_stocks.product_id',
                'product_stocks.store_id',
                'product_stocks.quantity',
                'product_stocks.updated_at'
            );

        // Filter by store if provided
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        if ($lastSync) {
            $query->whereRaw('product_stocks.updated_at >= ?', [$lastSync->toDateTimeString()]);
        }

        $stocks = $query->get()->map(function ($stock) {
            return [
                'id' => $stock->id,
                'batch_id' => $stock->batch_id,
                'product_id' => $stock->product_id,
                'store_id' => $stock->store_id,
                'quantity' => (float) $stock->quantity,
                'updated_at' => $stock->updated_at instanceof Carbon
                    ? $stock->updated_at->toIso8601String()
                    : Carbon::parse($stock->updated_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $stocks,
            'count' => $stocks->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get contacts (customers and vendors)
     * Returns contact information including balance and loyalty points
     * 
     * Query params:
     * - last_sync (optional): Unix timestamp in milliseconds for incremental sync
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getContacts(Request $request)
    {
        $lastSync = $this->parseTimestamp($request->query('last_sync'));

        $query = Contact::query();

        if ($lastSync) {
            $query->whereRaw('updated_at >= ?', [$lastSync->toDateTimeString()]);
        }

        $contacts = $query->get()->map(function ($contact) {
            return [
                'id' => (string) $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'type' => $contact->type ?? 'customer',
                'address' => $contact->address,
                'balance' => (float) ($contact->balance ?? 0),
                'loyalty_points' => $contact->loyalty_points ?? null,
                'whatsapp' => $contact->whatsapp ?? null,
                'updated_at' => $contact->updated_at instanceof Carbon
                    ? $contact->updated_at->toIso8601String()
                    : Carbon::parse($contact->updated_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $contacts,
            'count' => $contacts->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get settings (application settings)
     * Returns all application settings including store-specific configurations
     *
     * Query params:
     * - store_id (optional): Filter settings by specific store
     * - last_sync (optional): Unix timestamp in milliseconds for incremental sync
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getSettings(Request $request)
    {
        $storeId = $request->query('store_id');
        $lastSync = $this->parseTimestamp($request->query('last_sync'));

        $query = Setting::query();

        // Filter by store if provided
        if ($storeId) {
            $query->where(function($q) use ($storeId) {
                $q->where('store_id', $storeId)
                  ->orWhereNull('store_id');
            });
        }

        if ($lastSync) {
            $query->whereRaw('updated_at >= ?', [$lastSync->toDateTimeString()]);
        }

        $settings = $query->get()->map(function ($setting) {
            // Parse JSON meta_value if it's a string
            $metaValue = $setting->meta_value;
            if (is_string($metaValue)) {
                $decoded = json_decode($metaValue, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $metaValue = $decoded;
                }
            }

            return [
                'id' => $setting->id,
                'meta_key' => $setting->meta_key,
                'meta_value' => $metaValue,
                'store_id' => $setting->store_id,
                'updated_at' => $setting->updated_at instanceof Carbon
                    ? $setting->updated_at->toIso8601String()
                    : Carbon::parse($setting->updated_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $settings,
            'count' => $settings->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get stores (branch/location information)
     * Returns all store/location information
     *
     * Query params:
     * - last_sync (optional): Unix timestamp in milliseconds for incremental sync
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getStores(Request $request)
    {
        $lastSync = $this->parseTimestamp($request->query('last_sync'));

        $query = Store::query();

        if ($lastSync) {
            $query->whereRaw('updated_at >= ?', [$lastSync->toDateTimeString()]);
        }

        $stores = $query->get()->map(function ($store) {
            return [
                'id' => $store->id,
                'name' => $store->name,
                'address' => $store->address,
                'contact_number' => $store->contact_number,
                'sale_prefix' => $store->sale_prefix,
                'current_sale_number' => $store->current_sale_number,
                'updated_at' => $store->updated_at instanceof Carbon
                    ? $store->updated_at->toIso8601String()
                    : Carbon::parse($store->updated_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $stores,
            'count' => $stores->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get charges (taxes, fees, discounts)
     * Returns charge information for POS calculations
     *
     * Query params:
     * - last_sync (optional): Unix timestamp in milliseconds for incremental sync
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getCharges(Request $request)
    {
        $lastSync = $this->parseTimestamp($request->query('last_sync'));

        $query = Charge::query()->where('is_active', 1);

        if ($lastSync) {
            $query->whereRaw('updated_at >= ?', [$lastSync->toDateTimeString()]);
        }

        $charges = $query->get()->map(function ($charge) {
            return [
                'id' => $charge->id,
                'name' => $charge->name,
                'charge_type' => $charge->charge_type,
                'rate_value' => (float) $charge->rate_value,
                'rate_type' => $charge->rate_type,
                'description' => $charge->description,
                'is_active' => (bool) $charge->is_active,
                'is_default' => (bool) $charge->is_default,
                'updated_at' => $charge->updated_at instanceof Carbon
                    ? $charge->updated_at->toIso8601String()
                    : Carbon::parse($charge->updated_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $charges,
            'count' => $charges->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get sales (invoices/transactions) - read-only for display
     * Returns sales with items and payment transactions
     *
     * Query params:
     * - page (optional): Page number (default: 1)
     * - per_page (optional): Items per page (default: 50, max: 100)
     * - store_id (optional): Filter by specific store
     * - date_from (optional): Filter sales from date (ISO 8601 or timestamp)
     * - date_to (optional): Filter sales to date (ISO 8601 or timestamp)
     * - status (optional): Filter by status (completed, pending, cancelled)
     * - sync_id (optional): Filter by mobile sync_id (for deduplication)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function getSales(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $storeId = $request->query('store_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $status = $request->query('status');
        $syncId = $request->query('sync_id');

        $query = \App\Models\Sale::query()
            ->with(['items', 'transactions'])
            ->orderBy('created_at', 'desc');

        // Filter by store
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        // Filter by date range
        if ($dateFrom) {
            $dateFromParsed = $this->parseTimestamp($dateFrom);
            if ($dateFromParsed) {
                $query->where('created_at', '>=', $dateFromParsed);
            }
        }

        if ($dateTo) {
            $dateToParsed = $this->parseTimestamp($dateTo);
            if ($dateToParsed) {
                $query->where('created_at', '<=', $dateToParsed);
            }
        }

        // Filter by status
        if ($status) {
            $query->where('status', $status);
        }

        // Filter by sync_id (for deduplication checks)
        if ($syncId) {
            $query->where('sync_id', $syncId);
        }

        // Use Laravel's native pagination
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform the paginated data
        $sales = $paginator->getCollection()->map(function ($sale) {
                return [
                    'sale_id' => $sale->id,
                    'reference_id' => $sale->reference_id,
                    'invoice_number' => $sale->invoice_number,
                    'sync_id' => $sale->sync_id,
                    'store_id' => $sale->store_id,
                    'contact_id' => $sale->contact_id,
                    'sale_date' => $sale->created_at instanceof Carbon
                        ? $sale->created_at->toIso8601String()
                        : Carbon::parse($sale->created_at)->toIso8601String(),
                    'sale_time' => $sale->created_at instanceof Carbon
                        ? $sale->created_at->format('H:i:s')
                        : Carbon::parse($sale->created_at)->format('H:i:s'),
                    'total_amount' => (float) $sale->total_amount,
                    'total_charge_amount' => (float) ($sale->total_charge_amount ?? 0),
                    'discount' => (float) ($sale->discount ?? 0),
                    'amount_received' => (float) ($sale->amount_received ?? 0),
                    'profit_amount' => (float) ($sale->profit_amount ?? 0),
                    'status' => $sale->status,
                    'payment_status' => $sale->payment_status,
                    'note' => $sale->note,
                    'updated_at' => $sale->updated_at instanceof Carbon
                        ? $sale->updated_at->toIso8601String()
                        : Carbon::parse($sale->updated_at)->toIso8601String(),
                    // Related data
                    'items' => $sale->items->map(function ($item) {
                        return [
                            'sale_item_id' => $item->id,
                            'sale_id' => $item->sale_id,
                            'item_type' => $item->item_type ?? 'product',
                            'product_id' => $item->product_id,
                            'batch_id' => $item->batch_id,
                            'charge_id' => $item->charge_id,
                            'description' => $item->description ?? $item->product?->name,
                            'quantity' => (float) ($item->quantity ?? 0),
                            'free_quantity' => (float) ($item->free_quantity ?? 0),
                            'is_free' => (bool) ($item->is_free ?? false),
                            'unit_price' => (float) ($item->unit_price ?? 0),
                            'unit_cost' => (float) ($item->unit_cost ?? 0),
                            'discount' => (float) ($item->discount ?? 0),
                            'flat_discount' => (float) ($item->flat_discount ?? 0),
                            'charge_type' => $item->charge_type,
                            'rate_value' => $item->rate_value ? (float) $item->rate_value : null,
                            'rate_type' => $item->rate_type,
                            'base_amount' => $item->base_amount ? (float) $item->base_amount : null,
                        ];
                    }),
                    'transactions' => $sale->transactions->map(function ($txn) {
                        return [
                            'transaction_id' => $txn->id,
                            'sales_id' => $txn->sales_id,
                            'store_id' => $txn->store_id,
                            'contact_id' => $txn->contact_id,
                            'transaction_date' => $txn->created_at instanceof Carbon
                                ? $txn->created_at->toIso8601String()
                                : Carbon::parse($txn->created_at)->toIso8601String(),
                            'amount' => (float) $txn->amount,
                            'payment_method' => $txn->payment_method,
                            'transaction_type' => $txn->transaction_type,
                            'note' => $txn->note,
                        ];
                    }),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $sales,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Push sales from mobile POS to backend
     * Creates sales one by one using POSController::checkout()
     *
     * POST /api/sync/sales
     * Body: {
     *   "store_id": 1,
     *   "sales": [
     *     { ...sale payload... }
     *   ]
     * }
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function pushSales(Request $request)
    {
        $storeId = $request->input('store_id');
        $sales = $request->input('sales', []);

        $synced = [];
        $errors = [];

        foreach ($sales as $saleData) {
            try {
                $syncId = $saleData['sync_id'] ?? null;

                // Check if sale with this sync_id already exists (deduplication)
                if ($syncId) {
                    $existingSale = \App\Models\Sale::where('sync_id', $syncId)->first();

                    if ($existingSale) {
                        // Already synced - return existing info (idempotent)
                        $synced[] = [
                            'sync_id' => $syncId,
                            'sale_id' => $existingSale->id,
                            'invoice_number' => $existingSale->invoice_number,
                            'message' => 'Sale already exists (duplicate sync)'
                        ];
                        continue; // Skip to next sale
                    }
                }

                // Create new request with sale data
                $saleRequest = new Request($saleData);
                $saleRequest->merge(['store_id' => $storeId]);

                // REUSE POSController::checkout() - NO REDUNDANCY!
                $posController = new \App\Http\Controllers\POSController();
                $response = $posController->checkout($saleRequest);

                $responseData = $response->getData(true);

                if (isset($responseData['status']) && $responseData['status'] === 'success') {
                    $synced[] = [
                        'sync_id' => $syncId,
                        'sale_id' => $responseData['sale_id'] ?? null,
                        'invoice_number' => $responseData['invoice_number'] ?? null,
                        'message' => $responseData['message'] ?? 'Sale created successfully'
                    ];
                } else {
                    $errors[] = [
                        'sync_id' => $syncId,
                        'error' => $responseData['message'] ?? 'Unknown error'
                    ];
                }

            } catch (\Exception $e) {
                $errors[] = [
                    'sync_id' => $saleData['sync_id'] ?? null,
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'synced' => $synced,
            'errors' => $errors,
            'synced_count' => count($synced),
            'error_count' => count($errors),
            'timestamp' => now()->toIso8601String()
        ]);
    }

    /**
     * Parse timestamp - handles milliseconds from JavaScript
     * Converts JavaScript timestamps (milliseconds) to Carbon instances
     *
     * @param mixed $timestamp Unix timestamp in milliseconds or date string
     * @return Carbon|null
     */
    private function parseTimestamp($timestamp)
    {
        if (!$timestamp) {
            return null;
        }

        // If numeric and > 10 digits, it's milliseconds - convert to seconds
        if (is_numeric($timestamp) && $timestamp > 9999999999) {
            $timestamp = intval($timestamp / 1000);
        }

        // If still numeric, create from Unix timestamp and convert to server timezone
        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestamp($timestamp)->timezone(config('app.timezone'));
        }

        // Otherwise parse as date string in server timezone
        return Carbon::parse($timestamp)->timezone(config('app.timezone'));
    }
}
