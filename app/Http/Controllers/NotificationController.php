<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $authId = $request->user()->id;
        $limit = (int) $request->query('limit', 30);
        $limit = max(1, min($limit, 100));

        $notifications = UserNotification::query()
            ->with('sender:id,nome,email')
            ->where('recipient_id', $authId)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'title' => $item->title,
                    'body' => $item->body,
                    'payload' => $item->payload,
                    'read_at' => $item->read_at,
                    'created_at' => $item->created_at,
                    'sender' => $item->sender ? [
                        'id' => $item->sender->id,
                        'nome' => $item->sender->nome,
                        'email' => $item->sender->email,
                    ] : null,
                ];
            });

        return response()->json($notifications);
    }

    public function unreadCount(Request $request)
    {
        $authId = $request->user()->id;

        $count = UserNotification::query()
            ->where('recipient_id', $authId)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request)
    {
        $authId = $request->user()->id;

        $validated = $request->validate([
            'notification_ids' => 'nullable|array',
            'notification_ids.*' => 'integer',
            'all' => 'nullable|boolean',
        ]);

        $query = UserNotification::query()
            ->where('recipient_id', $authId)
            ->whereNull('read_at');

        if (!empty($validated['notification_ids'])) {
            $query->whereIn('id', $validated['notification_ids']);
        }

        if (empty($validated['all']) && empty($validated['notification_ids'])) {
            return response()->json(['message' => 'Informe notificacoes para marcar como lidas.'], 422);
        }

        $updated = $query->update(['read_at' => Carbon::now()]);

        return response()->json([
            'message' => 'Notificacoes atualizadas com sucesso.',
            'updated' => $updated,
        ]);
    }

    public function send(Request $request)
    {
        $sender = $request->user();

        $validated = $request->validate([
            'recipient_id' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:120',
            'body' => 'nullable|string|max:2000',
            'type' => 'nullable|string|max:40',
        ]);

        if ((int) $validated['recipient_id'] === (int) $sender->id) {
            return response()->json(['message' => 'Nao e possivel enviar notificacao para voce mesmo.'], 422);
        }

        $recipient = User::query()->findOrFail($validated['recipient_id']);

        $notification = UserNotification::create([
            'recipient_id' => $recipient->id,
            'sender_id' => $sender->id,
            'type' => $validated['type'] ?? 'alert',
            'title' => trim($validated['title']),
            'body' => isset($validated['body']) ? trim($validated['body']) : null,
            'payload' => [
                'manual' => true,
            ],
        ]);

        return response()->json([
            'message' => 'Notificacao enviada com sucesso.',
            'data' => [
                'id' => $notification->id,
                'recipient_id' => $notification->recipient_id,
                'title' => $notification->title,
                'body' => $notification->body,
                'type' => $notification->type,
                'created_at' => $notification->created_at,
            ],
        ], 201);
    }
}

