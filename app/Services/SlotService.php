<?php

namespace App\Services;

use App\Enums\HoldStatuses;
use App\Models\Hold;
use App\Models\Slot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SlotService
{
    /**
     * Получение доступных слотов с защитой от cache stampede через Mutex
     */
    public function getAvailableSlots(): array
    {
        // Пробуем получить данные из кеша
        $slots = Cache::get(config('slot.cache_key'));

        if ($slots !== null) {
            return $slots;
        }

        // Кеша нет - используем Mutex для защиты от множественных запросов к БД
        $lock = Cache::lock(config('slot.cache_key') . '_lock', (int) config('slot.lock_timeout'));

        try {
            // Пытаемся получить блокировку
            return $lock->block((int) config('slot.lock_timeout'), function () {
                // Проверяем еще раз - может другой процесс уже заполнил кеш
                $slots = Cache::get(config('slot.cache_key'));

                if ($slots !== null) {
                    return $slots;
                }

                // Получаем данные из БД
                $slots = $this->fetchAvailableSlots();

                // Сохраняем в кеш
                Cache::put(config('slot.cache_key'), $slots, now()->addSeconds((int) config('slot.cache_ttl')));

                Log::info('Cache refreshed via mutex', [
                    'slots_count' => count($slots)
                ]);

                return $slots;
            });

        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $exception) {
            // Не удалось получить блокировку - делаем прямой запрос к БД
            Log::warning('Mutex timeout - direct DB query', [
                'lock_key' => config('slot.cache_key') . '_lock'
            ]);

            return $this->fetchAvailableSlots();
        }
    }

    /**
     * Получение данных из БД
     */
    private function fetchAvailableSlots(): array
    {
        return Slot::select('id', 'capacity', 'remaining')
            ->orderBy('id')
            ->get()
            ->map(fn($slot) => [
                'slot_id' => $slot->id,
                'capacity' => $slot->capacity,
                'remaining' => $slot->remaining
            ])
            ->toArray();
    }

    /**
     * Создание холда с идемпотентностью
     */
    public function createHold(int $slotId, string $idempotencyKey): Hold
    {
        return DB::transaction(function () use ($slotId, $idempotencyKey) {
            // Проверяем существующий холд с блокировкой
            $existingHold = Hold::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingHold) {
                // Проверяем статус существующего холда
                if ($existingHold->status === HoldStatuses::STATUS_CANCELLED->value) {
                    throw new ConflictHttpException('Idempotency key was used for a cancelled hold');
                }

                if ($existingHold->isExpired()) {
                    // Автоматически отменяем истекший холд
                    $existingHold->update(['status' => HoldStatuses::STATUS_CANCELLED->value]);
                    throw new ConflictHttpException('Previous hold expired and was cancelled');
                }

                return $existingHold;
            }

            // Блокируем слот для проверки
            $slot = Slot::query()
                ->select('id', 'capacity', 'remaining')
                ->lockForUpdate()
                ->find($slotId);

            if (!$slot) {
                throw new NotFoundHttpException('Slot not found');
            }

            // Проверяем доступность мест
            if (!$slot->hasAvailableCapacity()) {
                throw new ConflictHttpException('Slot capacity exhausted');
            }

            // Создаем холд
            $hold = Hold::create([
                'slot_id' => $slotId,
                'idempotency_key' => $idempotencyKey,
                'status' => HoldStatuses::STATUS_HELD->value,
                'expires_at' => now()->addMinutes(5)
            ]);

            Log::info('Hold created', [
                'hold_id' => $hold->id,
                'slot_id' => $slotId,
                'expires_at' => $hold->expires_at->toDateTimeString()
            ]);

            return $hold;
        });
    }

    /**
     * Подтверждение холда
     */
    public function confirmHold(int $holdId): Hold
    {
        return DB::transaction(function () use ($holdId) {
            // Блокируем холд
            $hold = Hold::query()->lockForUpdate()->find($holdId);

            if (!$hold) {
                throw new NotFoundHttpException('Hold not found');
            }

            // Проверяем статус
            if ($hold->status !== HoldStatuses::STATUS_HELD->value) {
                throw new ConflictHttpException(
                    $hold->status === HoldStatuses::STATUS_CONFIRMED->value
                        ? 'Hold already confirmed'
                        : 'Hold is cancelled'
                );
            }

            // Проверяем не истек ли холд
            if ($hold->isExpired()) {
                $hold->update(['status' => HoldStatuses::STATUS_CANCELLED->value]);
                throw new ConflictHttpException('Hold has expired');
            }

            // Блокируем слот
            $slot = Slot::query()
                ->select('id', 'capacity', 'remaining')
                ->lockForUpdate()
                ->find($hold->slot_id);

            if (!$slot) {
                throw new NotFoundHttpException('Associated slot not found');
            }

            // Атомарно уменьшаем остаток
            if (!$slot->decrementRemaining()) {
                throw new ConflictHttpException('No available capacity in slot');
            }

            // Обновляем статус холда
            $hold->update(['status' => HoldStatuses::STATUS_CONFIRMED->value]);

            // Инвалидируем кеш
            $this->invalidateCache();

            Log::info('Hold confirmed', [
                'hold_id' => $holdId,
                'slot_id' => $slot->id,
                'remaining_after' => $slot->remaining
            ]);

            return $hold;
        });
    }

    /**
     * Отмена холда
     */
    public function cancelHold(int $holdId): Hold
    {
        return DB::transaction(function () use ($holdId) {
            // Блокируем холд
            $hold = Hold::query()->lockForUpdate()->find($holdId);

            if (!$hold) {
                throw new NotFoundHttpException('Hold not found');
            }

            // Проверяем можно ли отменить
            if ($hold->status === HoldStatuses::STATUS_CANCELLED->value) {
                throw new ConflictHttpException('Hold already cancelled');
            }

            // Если холд истек, просто меняем статус
            if ($hold->isExpired()) {
                $hold->update(['status' => HoldStatuses::STATUS_CANCELLED->value]);
                Log::info('Expired hold cancelled', ['hold_id' => $holdId]);
                return $hold;
            }

            // Возвращаем место в слот
            $slot = Slot::query()
                ->select('id', 'capacity', 'remaining')
                ->lockForUpdate()
                ->find($hold->slot_id);

            if ($slot) {
                $slot->incrementRemaining();
            }

            // Обновляем статус
            $hold->update(['status' => HoldStatuses::STATUS_CANCELLED->value]);

            // Инвалидируем кеш
            $this->invalidateCache();

            Log::info('Hold cancelled', [
                'hold_id' => $holdId,
                'slot_id' => $hold->slot_id
            ]);

            return $hold;
        });
    }

    /**
     * Инвалидация кеша
     */
    public function invalidateCache(): void
    {
        Cache::forget(config('slot.cache_key'));
        Log::debug('Cache invalidated');
    }
}