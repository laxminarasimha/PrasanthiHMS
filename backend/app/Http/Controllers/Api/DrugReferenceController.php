<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacyChange;
use App\Support\Composition;
use App\Support\DrugReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Linking the pharmacy's medicines to the national drug list (Common Drug Codes for India). The list suggests the brand
 * a medicine most likely is; a member of the pharmacy staff picks it, and only then are its codes, what it contains and
 * its maker filled in.
 */
class DrugReferenceController extends Controller
{
    /** How big the national list is and how far the linking has got. */
    public function status(Request $request): JsonResponse
    {
        $this->allowed($request);
        $active = Medicine::where('is_active', true);

        return response()->json([
            'brands' => DB::table('drug_ref_brands')->count(),
            'generics' => DB::table('drug_ref_generics')->count(),
            'latest' => DB::table('drug_ref_brands')->max('updated_on'),
            'medicines' => (clone $active)->count(),
            'linked' => (clone $active)->where(fn ($q) => $q->whereNotNull('ref_brand_code')->orWhereNotNull('ref_generic_code'))->count(),
        ]);
    }

    /**
     * The next medicine not yet linked, in A to Z order after the one given, with the brands it most likely is.
     * By default only medicines with stock on the shelf, since those are the ones in use.
     */
    public function next(Request $request): JsonResponse
    {
        $this->allowed($request);
        $data = $request->validate(['after_name' => ['nullable', 'string', 'max:255'], 'after_id' => ['nullable', 'integer'], 'all' => ['sometimes', 'boolean']]);
        // Surgical items and consumables are not medicines on the list, so they are not asked about.
        $unlinked = fn (): Builder => Medicine::query()->where('is_active', true)->whereNull('ref_brand_code')->whereNull('ref_generic_code')
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'Surgical'))->where(fn ($q) => $q->whereNull('category')->orWhere('category', 'not like', 'Surgical%'))
            ->when(! ($data['all'] ?? false), fn ($q) => $q->whereHas('batches', fn ($b) => $b->where('units_in_stock', '>', 0)->whereDate('expiry', '>=', today())));
        $medicine = $unlinked()
            ->when(isset($data['after_name']), fn ($q) => $q->where(fn ($w) => $w->where('name', '>', $data['after_name'])->orWhere(fn ($same) => $same->where('name', $data['after_name'])->where('id', '>', (int) ($data['after_id'] ?? 0)))))
            ->orderBy('name')->orderBy('id')->first();

        return response()->json([
            'remaining' => $unlinked()->count(),
            'medicine' => $medicine ? $this->medicine($medicine) : null,
            'suggestions' => $medicine ? DrugReference::suggest($medicine) : [],
            'generics' => $medicine ? DrugReference::suggestGenerics($medicine) : [],
        ]);
    }

    /** One medicine with what it is linked to and what it might be: for opening it from the medicines list. */
    public function show(Request $request, Medicine $medicine): JsonResponse
    {
        $this->allowed($request);

        return response()->json(['medicine' => $this->medicine($medicine), 'suggestions' => DrugReference::suggest($medicine), 'generics' => DrugReference::suggestGenerics($medicine)]);
    }

    /** Brands whose name starts with what was typed, and generics that have every word of it. */
    public function search(Request $request): JsonResponse
    {
        $this->allowed($request);
        $term = (string) $request->validate(['q' => ['required', 'string', 'max:100']])['q'];

        return response()->json(['brands' => DrugReference::search($term), 'generics' => DrugReference::searchGenerics($term)]);
    }

    /**
     * Links a medicine to a brand on the list (and so to its generic), or to a generic alone for a brand the list does not
     * have. What it contains is then written from the list, unless the person says to keep what is there.
     */
    public function link(Request $request, Medicine $medicine): JsonResponse
    {
        $this->allowed($request);
        $data = $request->validate([
            'brand_code' => ['nullable', 'string', 'max:20', 'required_without:generic_code'],
            'generic_code' => ['nullable', 'string', 'max:20', 'required_without:brand_code'],
            'use_composition' => ['sometimes', 'boolean'],
        ]);
        $brand = isset($data['brand_code']) ? DB::table('drug_ref_brands')->where('code', $data['brand_code'])->first() : null;
        $generic = DB::table('drug_ref_generics')->where('code', $brand->generic_code ?? $data['generic_code'] ?? '')->first();
        if ((isset($data['brand_code']) && ! $brand) || (! $brand && ! $generic)) {
            throw ValidationException::withMessages(['brand_code' => 'That entry is not on the national drug list.']);
        }

        DB::transaction(function () use ($request, $medicine, $brand, $generic, $data) {
            $was = $medicine->composition;
            $medicine->forceFill(['ref_brand_code' => $brand?->code, 'ref_generic_code' => $generic?->code, 'ref_linked_at' => now()]);
            if (! $medicine->manufacturer && $brand?->supplier) {
                $medicine->manufacturer = mb_substr($brand->supplier, 0, 255);
            }
            $medicine->save();
            $composition = $generic ? DrugReference::composition($generic->name, $generic->form) : null;
            if ($composition && ($data['use_composition'] ?? true)) {
                Composition::apply($medicine, $composition);
            }
            $summary = 'Linked to the national drug list: '.($brand->name ?? $generic->name);
            if ($was !== $medicine->composition) {
                $summary .= '. Composition: '.($was ?? '—').' → '.($medicine->composition ?? '—');
            }
            PharmacyChange::create(['kind' => 'medicine', 'subject_id' => $medicine->id, 'label' => $medicine->name, 'summary' => mb_substr($summary, 0, 1000), 'created_by' => $request->user()->id]);
        });

        return response()->json($this->medicine($medicine->fresh()));
    }

    /** Takes a link off, for one made by mistake. What it contains is left as it is. */
    public function unlink(Request $request, Medicine $medicine): JsonResponse
    {
        $this->allowed($request);
        if ($medicine->ref_brand_code || $medicine->ref_generic_code) {
            $medicine->forceFill(['ref_brand_code' => null, 'ref_generic_code' => null, 'ref_linked_at' => null])->save();
            PharmacyChange::create(['kind' => 'medicine', 'subject_id' => $medicine->id, 'label' => $medicine->name, 'summary' => 'Link to the national drug list taken off', 'created_by' => $request->user()->id]);
        }

        return response()->json($this->medicine($medicine->fresh()));
    }

    /** @return array<string, mixed> */
    private function medicine(Medicine $medicine): array
    {
        $brand = $medicine->ref_brand_code ? DB::table('drug_ref_brands')->where('code', $medicine->ref_brand_code)->get() : collect();
        $generic = $medicine->ref_generic_code ? DB::table('drug_ref_generics')->where('code', $medicine->ref_generic_code)->first() : null;

        return $medicine->only(['id', 'name', 'type', 'manufacturer', 'composition', 'ref_brand_code', 'ref_generic_code']) + [
            'units_in_stock' => (int) $medicine->batches()->whereDate('expiry', '>=', today())->sum('units_in_stock'),
            'brand' => DrugReference::present($brand)->first(),
            'generic' => $generic ? [
                'code' => $generic->code, 'name' => $generic->name, 'form' => $generic->form, 'route' => $generic->route,
                'therapeutic_role' => $generic->therapeutic_role, 'indication' => $generic->indication, 'contra_indication' => $generic->contra_indication, 'interactions' => $generic->interactions,
            ] : null,
        ];
    }

    private function allowed(Request $request): void
    {
        abort_unless($request->user()->mayInPharmacy($request), 403, 'You are not allowed to do this in the pharmacy.');
    }
}
