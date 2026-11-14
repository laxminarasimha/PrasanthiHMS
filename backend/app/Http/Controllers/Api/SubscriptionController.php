<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicineSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The pharmacy's side of monthly medicine lists that patients keep in the mobile app. */
class SubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['show' => ['nullable', 'in:due,active,paused,all']])['show'] ?? 'due';

        $query = MedicineSubscription::with(['patient:id,uhid,title,name,mobile,gender,date_of_birth', 'preparer:id,name'])
            ->when($filter === 'due', fn ($q) => $q->where('status', 'active')->whereDate('next_due_on', '<=', today()->addDays(7)))
            ->when($filter === 'active', fn ($q) => $q->where('status', 'active'))
            ->when($filter === 'paused', fn ($q) => $q->where('status', 'paused'))
            ->when($filter === 'all', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->orderBy('next_due_on')->limit(500);

        return response()->json([
            'subscriptions' => $query->get(),
            'counts' => [
                'due' => MedicineSubscription::where('status', 'active')->whereDate('next_due_on', '<=', today()->addDays(7))->count(),
                'active' => MedicineSubscription::where('status', 'active')->count(),
                'paused' => MedicineSubscription::where('status', 'paused')->count(),
            ],
        ]);
    }

    /** Marks this month's pack as made up; the list comes due again next month. */
    public function prepared(Request $request, MedicineSubscription $subscription): JsonResponse
    {
        if ($subscription->status !== 'active') {
            throw ValidationException::withMessages(['subscription' => 'This list is not active.']);
        }
        if ($subscription->next_due_on->gt(today()->addDays(7))) {
            throw ValidationException::withMessages(['subscription' => 'This list is not due yet.']);
        }

        // Next month's date, counted from the due date so an early or late pack does not shift the cycle.
        $next = MedicineSubscription::dueOnOrAfter($subscription->day_of_month, $subscription->next_due_on->copy()->addDay());
        if ($next->lte(today())) {
            $next = MedicineSubscription::dueOnOrAfter($subscription->day_of_month, today()->addDay());
        }
        $subscription->update(['last_prepared_on' => today(), 'last_prepared_by' => $request->user()->id, 'next_due_on' => $next]);

        return response()->json($subscription->fresh()->load(['patient:id,uhid,title,name,mobile,gender,date_of_birth', 'preparer:id,name']));
    }
}
