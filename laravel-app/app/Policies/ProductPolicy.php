<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::PRODUCT_READ);
    }

    public function view(User $user, Product $product): Response
    {
        if (! $user->hasPermission(Permission::PRODUCT_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin() || $user->company_id === $product->company_id) {
            return Response::allow();
        }

        // Buyers may only view active catalog products.
        if ($user->isBuyerUser() && $product->status === Product::STATUS_ACTIVE) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::PRODUCT_CREATE)
            && $user->isSupplierUser()
            && $user->company?->supplierProfile !== null;
    }

    public function update(User $user, Product $product): Response
    {
        if (! $user->hasPermission(Permission::PRODUCT_UPDATE)) {
            return Response::deny();
        }

        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            return Response::deny();
        }

        return $this->ownerResponse($user, $product);
    }

    public function delete(User $user, Product $product): Response
    {
        if (! $user->hasPermission(Permission::PRODUCT_DELETE)) {
            return Response::deny();
        }

        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            return Response::deny();
        }

        return $this->ownerResponse($user, $product);
    }

    private function ownerResponse(User $user, Product $product): Response
    {
        if ($user->isAdmin() || $user->company_id === $product->company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
