<?php

namespace App\Models;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable([
    'campus_id',
    'academic_year',
    'title',
    'status',
    'current_position',
    'current_phase',
    'round_number',
    'position_results',
    'opens_at',
    'closes_at',
    'auto_archived_at',
    'created_by_user_id',
])]
class Election extends Model
{
    public const LIVE_CHAT_CACHE_STORE = 'ephemeral_chat';

    public static function liveChatCache(): CacheRepository
    {
        return Cache::store(self::LIVE_CHAT_CACHE_STORE);
    }

    public static function liveChatCacheKey(int $electionId): string
    {
        return "election:{$electionId}:live_chat";
    }

    public static function flushLiveChatCache(int $electionId): void
    {
        self::liveChatCache()->forget(self::liveChatCacheKey($electionId));
    }

    protected static function booted(): void
    {
        static::saved(function (Election $election): void {
            if (
                $election->status !== 'open'
                || $election->current_phase === 'completed'
                || ($election->closes_at !== null && now()->gt($election->closes_at))
            ) {
                self::flushLiveChatCache((int) $election->id);
            }
        });

        static::deleted(function (Election $election): void {
            self::flushLiveChatCache((int) $election->id);
        });
    }

    public function isSessionActive(): bool
    {
        if ($this->status !== 'open' || $this->current_phase === 'completed') {
            return false;
        }

        if ($this->opens_at !== null && now()->lt($this->opens_at)) {
            return false;
        }

        if ($this->closes_at !== null && now()->gt($this->closes_at)) {
            return false;
        }

        return true;
    }

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'auto_archived_at' => 'datetime',
            'round_number' => 'integer',
            'position_results' => 'array',
        ];
    }
}
