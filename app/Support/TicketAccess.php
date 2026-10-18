<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class TicketAccess
{
    public static function admin(Request $request): ?User
    {
        if ($request->user()?->is_admin) {
            return $request->user();
        }

        $id = $request->session()->get('admin_user_id');

        return $id ? User::whereKey($id)->where('is_admin', true)->first() : null;
    }

    public static function userId(Request $request): ?int
    {
        return self::admin($request)?->id ?? $request->user()?->id;
    }

    public static function canAccess(Request $request, ?int $ownerId): bool
    {
        return self::admin($request) !== null || ($ownerId !== null && (int) $ownerId === $request->user()?->id);
    }
}
