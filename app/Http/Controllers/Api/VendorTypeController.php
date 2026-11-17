<?php

namespace App\Http\Controllers\Api;

use App\Models\Vendor;
use App\Models\VendorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorTypeController extends LookupController
{
    protected function model(): string
    {
        return VendorType::class;
    }

    protected function inUse(Model $item): bool
    {
        return $item->slug === VendorType::FUEL || Vendor::where('vendor_type', $item->slug)->exists();
    }

    /** The slug is fixed at creation so renaming a type never moves its vendors. */
    protected function values(array $validated): array
    {
        $slug = Str::of($validated['name'])->lower()->slug('_')->limit(45, '')->toString();
        if (VendorType::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => 'A vendor type with this name already exists.']);
        }

        return [...$validated, 'slug' => $slug];
    }
}
