<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHoldRequest;
use App\Services\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HoldController extends Controller
{
    public function __construct(
        private readonly SlotService $slotService
    ) {}

    public function store(StoreHoldRequest $request, int $slotId): JsonResponse
    {
        try {
            $hold = $this->slotService->createHold($slotId, $request->idempotency_key);

            return response()->json([
                'id' => $hold->id,
                'slot_id' => $hold->slot_id,
                'status' => $hold->status,
                'expires_at' => $hold->expires_at->toIso8601String(),
                'created_at' => $hold->created_at->toIso8601String()
            ], 201);

        } catch (NotFoundHttpException $exception) {
            return response()->json(['error' => $exception->getMessage()], 404);
        } catch (ConflictHttpException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        } catch (\Exception $exception) {
            Log::error('Hold creation failed', [
                'slot_id' => $slotId,
                'error' => $exception->getMessage()
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    public function confirm(int $holdId): JsonResponse
    {
        try {
            $hold = $this->slotService->confirmHold($holdId);

            return response()->json([
                'id' => $hold->id,
                'slot_id' => $hold->slot_id,
                'status' => $hold->status,
                'confirmed_at' => now()->toIso8601String()
            ]);

        } catch (NotFoundHttpException $exception) {
            return response()->json(['error' => $exception->getMessage()], 404);
        } catch (ConflictHttpException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        } catch (\Exception $exception) {
            Log::error('Hold confirmation failed', [
                'hold_id' => $holdId,
                'error' => $exception->getMessage()
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    public function destroy(int $holdId): JsonResponse
    {
        try {
            $hold = $this->slotService->cancelHold($holdId);

            return response()->json([
                'id' => $hold->id,
                'slot_id' => $hold->slot_id,
                'status' => $hold->status,
                'cancelled_at' => now()->toIso8601String()
            ]);

        } catch (NotFoundHttpException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (ConflictHttpException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            Log::error('Hold cancellation failed', [
                'hold_id' => $holdId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}