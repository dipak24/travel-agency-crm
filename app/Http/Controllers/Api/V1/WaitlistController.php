<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PublicInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaitlistController extends Controller
{
    public function __construct(private PublicInquiry $publicInquiry) {}

    /**
     * Self-service waitlist entry on a full fixed departure. Re-submitting with the same email
     * returns the existing entry rather than queueing the same person twice.
     */
    public function store(Request $request, int $departure): JsonResponse
    {
        $data = $request->validate([
            ...InquiryController::contactRules(),
            'pax_count' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $entry = $this->publicInquiry->joinWaitlist($departure, $data);

        return response()->json([
            'message' => 'You have been added to the waitlist. We will contact you if seats open up.',
            'data' => [
                'position' => $entry->position,
                'pax_requested' => $entry->pax_requested,
            ],
        ], $entry->wasRecentlyCreated ? 201 : 200);
    }
}
