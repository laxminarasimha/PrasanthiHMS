<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public function index(Request $request): JsonResponse
    {
        $patients = Patient::query()
            ->when($request->string('q')->trim()->value(), function ($query, $q) {
                $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                    ->orWhere('mobile', 'like', "{$q}%")
                    ->orWhere('uhid', 'like', "{$q}%"));
            })
            ->latest('id')
            ->paginate(20);

        return response()->json($patients);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['branch_id'] = $request->user()->branch_id;
        $data['created_by'] = $request->user()->id;

        return response()->json(Patient::create($data)->refresh(), 201);
    }

    public function show(Patient $patient): JsonResponse
    {
        return response()->json($patient);
    }

    public function update(Request $request, Patient $patient): JsonResponse
    {
        $patient->update($this->validated($request));

        return response()->json($patient);
    }

    /**
     * Staff give either a date of birth or an age; an age is turned into an estimated birth date.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'required_without:age', 'date', 'before_or_equal:today'],
            'age' => ['nullable', 'required_without:date_of_birth', 'integer', 'min:0', 'max:130'],
            'mobile' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
            'mobile_alt' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'blood_group' => ['nullable', Rule::in(self::BLOOD_GROUPS)],
            'preferred_language' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'pincode' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'care_of' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'referred_by' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', 'string', 'max:50'],
            'tag' => ['nullable', 'string', 'max:50'],
            'existing_id' => ['nullable', 'string', 'max:50'],
        ], [
            'mobile.regex' => 'Enter a 10-digit mobile number.',
            'mobile_alt.regex' => 'Enter a 10-digit mobile number.',
            'pincode.regex' => 'Enter a 6-digit PIN code.',
            'date_of_birth.required_without' => 'Enter an age or a date of birth.',
            'age.required_without' => 'Enter an age or a date of birth.',
        ]);

        if (empty($data['date_of_birth'])) {
            $data['date_of_birth'] = now()->subYears((int) $data['age'])->toDateString();
            $data['dob_estimated'] = true;
        } else {
            $data['dob_estimated'] = false;
        }
        unset($data['age']);

        return $data;
    }
}
