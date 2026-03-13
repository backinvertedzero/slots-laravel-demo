<?php

namespace App\Http\Controllers;

use App\Services\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AvailabilityController extends Controller
{
    public function __construct(
        private readonly SlotService $slotService
    ) {}

    public function index(): JsonResponse
    {
        try {
            $slots = $this->slotService->getAvailableSlots();

            return response()->json($slots);

        } catch (\Exception $exception) {
            Log::error('Failed to get availability', [
                'error' => $exception->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve availability'
            ], 500);
        }
    }
}