<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\FramImgMap;
use App\Models\Order;
use App\Support\ApiResponse;
use App\Support\Enums\ProductType;

/**
 * Port of graspcraft_backend/src/modules/user/cart/cart.service.ts.
 *
 * The recurring job in here is turning the frame_img_ids stored in
 * cart.combo_json into displayable FILE NAMES, with three different sources
 * depending on the product type:
 *
 *   magnet       -> combo_json's magnet_img_name
 *   certificate  -> combo_json's certificate_img_name  (3 variants)
 *   anything else-> looked up in fram-img-map, preferring edited over merged
 */
class CartService
{
    public function create(array $dto): Cart
    {
        $subOrderId = ApiResponse::generateOrderId(true);

        /*
         * `combo_json` is filled from the incoming `combo_map` key, but the whole
         * DTO is spread in afterwards — so if the request also carries a literal
         * `combo_json` it wins. Preserved from the Node original.
         */
        return Cart::create(array_merge(
            ['combo_json' => $dto['combo_map'] ?? null],
            $dto,
            ['sub_order_id' => $subOrderId],
        ));
    }

    /**
     * The cart screen: every row with its combo, its products, and each
     * product's resolved image names.
     */
    public function findAll(string $customerId)
    {
        $getAllCart = Cart::query()
            ->with(['combo.prodCombMap' => fn ($q) => $q->select(['id', 'combo_id', 'product_id'])->with('products')])
            ->where('customer_id', $customerId)
            ->get();

        foreach ($getAllCart as $cart) {
            $productList = $cart->combo_json['product_list'] ?? [];
            $products = $cart->combo?->prodCombMap ?? [];

            foreach ($products as $p) {
                $productId = $p->products?->id;

                /*
                 * The same product_id can appear several times in product_list
                 * when photo_limit > 1, so every matching entry contributes.
                 */
                $matchedEntries = array_values(array_filter(
                    $productList,
                    fn ($product) => ($product['product_id'] ?? null) === $productId,
                ));

                if ($matchedEntries === []) {
                    continue;
                }

                $productType = $matchedEntries[0]['product_type'] ?? null;

                if ($productType === ProductType::MAGNET->value) {
                    $names = array_values(array_filter(array_map(
                        fn ($e) => $e['magnet_img_name'] ?? null,
                        $matchedEntries,
                    )));
                } elseif (ProductType::isCertificate($productType)) {
                    $names = array_values(array_filter(array_map(
                        fn ($e) => $e['certificate_img_name'] ?? null,
                        $matchedEntries,
                    )));
                } else {
                    $allFrameImgIds = [];
                    foreach ($matchedEntries as $entry) {
                        $allFrameImgIds = array_merge($allFrameImgIds, $entry['frame_img_ids'] ?? []);
                    }

                    if ($allFrameImgIds !== []) {
                        $idToName = FramImgMap::query()
                            ->whereIn('id', $allFrameImgIds)
                            ->get()
                            ->mapWithKeys(fn ($item) => [$item->id => $item->displayName()]);

                        // Mapped over the ORIGINAL id list so duplicates are kept
                        // and order is preserved; a missing id becomes null.
                        $names = array_map(fn ($id) => $idToName[$id] ?? null, $allFrameImgIds);
                    } else {
                        $names = [];
                    }
                }

                $p->products?->setAttribute('framedNames', $names);
            }
        }

        return $getAllCart;
    }

    /** Hard delete — `force: true` in the Node service. */
    public function deleteOneCart(string $cartId): int
    {
        return Cart::query()->where('id', $cartId)->forceDelete();
    }

    /** Hard delete — this is what clears a basket after checkout. */
    public function deleteMultipleCart(string $customerId): int
    {
        return Cart::query()->where('customer_id', $customerId)->forceDelete();
    }

    /**
     * One order with its basket, with frame_img_ids REPLACED by frame_img_names.
     *
     * @throws \RuntimeException when the order does not exist
     */
    public function getOneOrder(string $orderId): Order
    {
        $order = Order::query()
            ->with(['customer.cart.combo.prodCombMap' => fn ($q) => $q->select(['id', 'combo_id', 'product_id'])->with('products')])
            ->where('order_id', $orderId)
            ->first();

        if (! $order) {
            throw new \RuntimeException('Order not found');
        }

        foreach ($order->customer?->cart ?? [] as $cart) {
            $comboJson = $cart->combo_json;
            $products = $comboJson['product_list'] ?? [];

            foreach ($products as $index => $product) {
                $frameImgIds = $product['frame_img_ids'] ?? [];

                $idToName = FramImgMap::query()
                    ->select(['id', 'merged_img_name', 'edited_img_name'])
                    ->whereIn('id', $frameImgIds)
                    ->get()
                    ->mapWithKeys(fn ($item) => [$item->id => $item->displayName()]);

                // Rebuilt from the id list so duplicates survive.
                $frameImgNames = array_map(fn ($id) => $idToName[$id] ?? null, $frameImgIds);

                if (($product['product_type'] ?? null) === ProductType::MAGNET->value) {
                    $products[$index]['frame_img_names'] = [$product['magnet_img_name'] ?? null];
                } elseif (ProductType::isCertificate($product['product_type'] ?? null)) {
                    // certificate composite is stored by name in combo_json
                    $products[$index]['frame_img_names'] = [$product['certificate_img_name'] ?? null];
                } else {
                    $products[$index]['frame_img_names'] = $frameImgNames;
                }

                // The ids are removed once resolved — `delete product.frame_img_ids`.
                unset($products[$index]['frame_img_ids']);
            }

            $comboJson['product_list'] = $products;
            $cart->setAttribute('combo_json', $comboJson);
        }

        return $order;
    }
}
