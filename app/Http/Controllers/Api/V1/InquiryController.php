<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PublicInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InquiryController extends Controller
{
    public function __construct(private PublicInquiry $publicInquiry) {}

    /**
     * Public inquiry form → a new `public_website` Lead in the tenant's pipeline.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...self::contactRules(),
            'package_slug' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'trip_type' => ['nullable', 'string', 'max:255'],
            'pax_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'budget_range' => ['nullable', 'string', 'max:255'],
            'travel_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $this->publicInquiry->submitInquiry($data);

        return response()->json(['message' => 'Thank you — our team will be in touch shortly.'], 201);
    }

    /**
     * Contact fields shared by every public submission endpoint.
     *
     * @return array<string, array<int, string>>
     */
    public static function contactRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
