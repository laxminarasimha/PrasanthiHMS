<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\RepCompany;
use App\Models\RepProduct;
use App\Models\User;
use App\Support\Composition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The catalogues of the medicine companies whose representatives call: each brand, what it contains, and the price
 * and scheme on offer. A brand is set beside the pharmacy's own medicines by what it contains, so the same drug from
 * another company, and what it would cost, can be seen where medicines are ordered.
 *
 * What a brand contains is typed from the company's catalogue by the staff, or loaded from the company's own sheet.
 * Nothing is worked out or guessed here, and the pharmacy's own medicines are never changed by it.
 */
class RepCatalogueController extends Controller
{
    /** The most lines taken from one sheet. */
    public const MAX_LINES = 2000;

    /** The brands in the catalogues, found by brand, by a drug inside, or by company, each with what the pharmacy keeps of the same thing. */
    public function index(Request $request): JsonResponse
    {
        $this->reader($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'company_id' => ['nullable', 'integer']]);
        $term = trim((string) ($data['q'] ?? ''));

        $products = RepProduct::query()
            ->when($data['company_id'] ?? null, fn (Builder $q, $id) => $q->where('rep_company_id', $id))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', "%{$term}%")->orWhere('composition', 'like', "%{$term}%")
                ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"))))
            ->with('company:id,name')->orderBy('name')->limit(500)->get();

        $ours = self::pharmacyHas($products->pluck('composition'));
        $others = self::offered($products->pluck('composition'));

        return response()->json([
            'can_change' => $this->mayChange($request->user()),
            'companies' => RepCompany::orderBy('name')->withCount('products')->get(['id', 'name']),
            'products' => $products->map(fn (RepProduct $p) => $p->toArray() + [
                // The pharmacy's own medicines that contain the same thing, and the same thing from other companies.
                'in_pharmacy' => $ours->get(self::same($p->composition), collect())->values(),
                'also_from' => $others->get(self::same($p->composition), collect())->where('id', '!=', $p->id)->where('company', '!=', $p->company?->name)->values(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->changer($request);
        $data = $this->productData($request);
        $product = DB::transaction(fn () => $this->save(new RepProduct(['created_by' => $user->id]), $data));

        return response()->json($product->load('company:id,name'), 201);
    }

    public function update(Request $request, RepProduct $product): JsonResponse
    {
        $this->changer($request);
        $data = $this->productData($request, $product);
        DB::transaction(fn () => $this->save($product, $data));

        return response()->json($product->load('company:id,name'));
    }

    public function destroy(Request $request, RepProduct $product): JsonResponse
    {
        $this->changer($request);
        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * A company's price list, as lines from its own sheet. Every line is checked and told back first; only with
     * `save` are the good lines put in the catalogue. A brand already there is brought up to date, not added twice.
     */
    public function sheet(Request $request): JsonResponse
    {
        $user = $this->changer($request);
        $data = $request->validate([
            'company_id' => ['required', Rule::exists('rep_companies', 'id')],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'rows.*' => ['array'],
            'save' => ['sometimes', 'boolean'],
        ], ['rows.max' => 'A sheet can have '.self::MAX_LINES.' lines at most. Split it into two.']);

        $there = RepProduct::where('rep_company_id', $data['company_id'])->get()->keyBy(fn (RepProduct $p) => self::same($p->name));
        $seen = [];
        $checked = [];
        foreach (array_values($data['rows']) as $index => $row) {
            $text = fn (string $field) => trim(preg_replace('/\s+/', ' ', (string) (is_scalar($row[$field] ?? null) ? $row[$field] : '')));
            $errors = [];
            $name = $text('name');
            if ($name === '') {
                $errors[] = 'The brand name is missing.';
            } elseif (mb_strlen($name) > 255) {
                $errors[] = 'The brand name is too long.';
            } elseif (isset($seen[self::same($name)])) {
                $errors[] = 'This brand is on the sheet twice (line '.$seen[self::same($name)].').';
            }
            $money = [];
            foreach (['mrp' => 'MRP', 'rate' => 'rate'] as $field => $word) {
                $typed = str_replace([',', '₹', 'Rs.', 'Rs'], '', $text($field));
                if ($typed !== '' && (! is_numeric($typed) || (float) $typed < 0 || (float) $typed > 9999999)) {
                    $errors[] = "The {$word} is not a number.";
                }
                $money[$field] = $typed !== '' && is_numeric($typed) ? round((float) $typed, 2) : null;
            }
            $line = ['name' => $name, 'composition' => Composition::standard($text('composition')), 'form' => mb_substr($text('form'), 0, 60) ?: null, 'pack' => mb_substr($text('pack'), 0, 100) ?: null,
                'scheme' => mb_substr($text('scheme'), 0, 255) ?: null] + $money;
            if ($name !== '' && ! isset($seen[self::same($name)])) {
                $seen[self::same($name)] = $index + 2; // the line on the sheet, under its heading
            }
            $checked[] = ['line' => $index + 2, 'errors' => $errors, 'already' => $there->has(self::same($name)), 'missing_composition' => $line['composition'] === null] + $line;
        }
        $good = collect($checked)->filter(fn (array $row) => $row['errors'] === []);

        $saved = 0;
        if ($request->boolean('save') && $good->isNotEmpty()) {
            DB::transaction(function () use ($good, $there, $data, $user, &$saved) {
                foreach ($good as $row) {
                    $product = $there->get(self::same($row['name'])) ?? new RepProduct(['created_by' => $user->id]);
                    // Only what the sheet says is written: a column the sheet does not have leaves what was there before.
                    $said = array_filter(array_intersect_key($row, array_flip(['composition', 'form', 'pack', 'mrp', 'rate', 'scheme'])), fn ($value) => $value !== null);
                    $this->save($product, ['rep_company_id' => (int) $data['company_id'], 'name' => $product->exists ? $product->name : $row['name']] + $said);
                    $saved++;
                }
            });
        }

        return response()->json([
            'rows' => $checked,
            'totals' => ['lines' => count($checked), 'good' => $good->count(), 'wrong' => count($checked) - $good->count(), 'new' => $good->where('already', false)->count(),
                'updated' => $good->where('already', true)->count(), 'without_composition' => $good->where('missing_composition', true)->count()],
            'saved' => $saved,
        ]);
    }

    /**
     * For the pharmacy's "options to order": every catalogue brand that has a composition, with its company, what is
     * on offer, and whom to ring. The screen puts each under the composition it shares with the pharmacy's medicines.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function offers(): Collection
    {
        return RepProduct::whereNotNull('composition')->where('composition', '!=', '')
            ->with(['company:id,name', 'company.reps' => fn ($q) => $q->where('is_active', true)->select(['id', 'rep_company_id', 'name', 'mobile'])])
            ->orderBy('name')->limit(5000)->get()
            ->map(fn (RepProduct $p) => [
                'id' => $p->id, 'name' => $p->name, 'company' => $p->company?->name, 'composition' => $p->composition, 'form' => $p->form, 'pack' => $p->pack,
                'mrp' => $p->mrp, 'rate' => $p->rate, 'scheme' => $p->scheme,
                'reps' => $p->company?->reps->map(fn ($r) => ['name' => $r->name, 'mobile' => $r->mobile])->values() ?? [],
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(Request $request, ?RepProduct $product = null): array
    {
        return $request->validate([
            'rep_company_id' => ['required', Rule::exists('rep_companies', 'id')],
            // Not twice in one company's catalogue, however the letters are typed.
            'name' => ['required', 'string', 'min:2', 'max:255', function (string $field, mixed $value, \Closure $fail) use ($request, $product) {
                if (RepProduct::where('rep_company_id', $request->input('rep_company_id'))->where('id', '!=', $product?->id ?? 0)->whereRaw('lower(name) = ?', [self::same((string) $value)])->exists()) {
                    $fail('This brand is already in this company’s catalogue.');
                }
            }],
            'composition' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'string', 'max:60'],
            'pack' => ['nullable', 'string', 'max:100'],
            'mrp' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'scheme' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * Saves a brand, links it to its drugs, and to the pharmacy's own medicine of the same name if there is one.
     * A brand typed without its composition takes it from that medicine, where the pharmacy has already recorded it.
     *
     * @param  array<string, mixed>  $data
     */
    private function save(RepProduct $product, array $data): RepProduct
    {
        $ours = Medicine::whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))])->first(['id', 'composition']);
        // Said afresh, the composition is as said; not said at all (a sheet without that column), it stays as it was.
        $composition = array_key_exists('composition', $data) && filled($data['composition']) ? $data['composition']
            : (array_key_exists('composition', $data) ? $ours?->composition : ($product->composition ?? $ours?->composition));
        unset($data['composition']);
        $product->fill(['name' => trim($data['name']), 'medicine_id' => $ours?->id] + $data)->save();
        Composition::applyToProduct($product, $composition);

        return $product;
    }

    /** Two ways of typing the same thing are the same thing. */
    private static function same(?string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $text)));
    }

    /**
     * The pharmacy's own medicines for each of these compositions, with what is on the shelf.
     *
     * @return Collection<string, Collection<int, array{name: string, on_shelf: int}>>
     */
    private static function pharmacyHas(Collection $compositions): Collection
    {
        $wanted = $compositions->filter()->map(fn ($c) => self::same($c))->unique()->values();
        if ($wanted->isEmpty()) {
            return collect();
        }

        return Medicine::where('is_active', true)->whereIn(DB::raw('lower(composition)'), $wanted->all())
            ->withSum(['batches as on_shelf' => fn ($q) => $q->where('units_in_stock', '>', 0)->whereDate('expiry', '>=', today())], 'units_in_stock')
            ->get(['id', 'name', 'composition'])
            ->groupBy(fn (Medicine $m) => self::same($m->composition))
            ->map(fn (Collection $same) => $same->map(fn (Medicine $m) => ['name' => $m->name, 'on_shelf' => (int) $m->on_shelf])->sortByDesc('on_shelf')->values());
    }

    /**
     * Every catalogue brand for each of these compositions, whichever company's.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private static function offered(Collection $compositions): Collection
    {
        $wanted = $compositions->filter()->map(fn ($c) => self::same($c))->unique()->values();
        if ($wanted->isEmpty()) {
            return collect();
        }

        return RepProduct::whereIn(DB::raw('lower(composition)'), $wanted->all())->with('company:id,name')->get()
            ->groupBy(fn (RepProduct $p) => self::same($p->composition))
            ->map(fn (Collection $same) => $same->map(fn (RepProduct $p) => ['id' => $p->id, 'name' => $p->name, 'company' => $p->company?->name, 'mrp' => $p->mrp, 'rate' => $p->rate, 'scheme' => $p->scheme])->values());
    }

    /** The catalogue is kept by the front desk and the pharmacy. */
    private function mayChange(User $user): bool
    {
        return $user->allows('canWorkFrontDesk') || $user->allows('canRunPharmacy');
    }

    private function changer(Request $request): User
    {
        abort_unless($this->mayChange($request->user()), 403, 'The catalogue is kept by the front desk and the pharmacy.');

        return $request->user();
    }

    /** It is read by the front desk, the doctors and the pharmacy. */
    private function reader(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->allows('canWorkFrontDesk') || $user->allows('canConsult') || $user->allows('canRunPharmacy') || $user->allows('canViewPharmacy'), 403, 'You are not allowed to see the companies’ catalogues.');

        return $user;
    }
}
