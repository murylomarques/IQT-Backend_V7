<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ChatController extends Controller
{
    public function users(Request $request)
    {
        $authId = $request->user()->id;

        $unreadBySender = ChatMessage::query()
            ->select('sender_id', DB::raw('COUNT(*) as unread_total'))
            ->where('recipient_id', $authId)
            ->whereNull('read_at')
            ->groupBy('sender_id')
            ->pluck('unread_total', 'sender_id');

        $users = User::query()
            ->select(['id', 'nome', 'email', 'cargo_id', 'status'])
            ->where('id', '!=', $authId)
            ->where('status', 'ativo')
            ->orderBy('nome')
            ->get()
            ->map(function ($user) use ($unreadBySender) {
                return [
                    'id' => $user->id,
                    'nome' => $user->nome,
                    'email' => $user->email,
                    'cargo_id' => $user->cargo_id,
                    'status' => $user->status,
                    'unread_messages' => (int) ($unreadBySender[$user->id] ?? 0),
                ];
            });

        return response()->json($users);
    }

    public function conversation(Request $request, User $user)
    {
        $authId = $request->user()->id;
        $sinceId = (int) $request->query('since_id', 0);

        if ($user->id === $authId) {
            return response()->json(['message' => 'Conversa invalida.'], 422);
        }

        // marca como lidas as mensagens que o usuario atual recebeu deste contato
        ChatMessage::query()
            ->where('sender_id', $user->id)
            ->where('recipient_id', $authId)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        $query = ChatMessage::query()
            ->where(function ($q) use ($authId, $user) {
                $q->where('sender_id', $authId)
                    ->where('recipient_id', $user->id);
            })
            ->orWhere(function ($q) use ($authId, $user) {
                $q->where('sender_id', $user->id)
                    ->where('recipient_id', $authId);
            });

        if ($sinceId > 0) {
            $query->where('id', '>', $sinceId)->orderBy('id');
        } else {
            $query->orderByDesc('id')->limit(150);
        }

        $messages = $query
            ->get()
            ->when($sinceId <= 0, fn($collection) => $collection->reverse()->values())
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'sender_id' => $item->sender_id,
                    'recipient_id' => $item->recipient_id,
                    'message' => $item->message,
                    'read_at' => $item->read_at,
                    'created_at' => $item->created_at,
                ];
            });

        return response()->json([
            'contact' => [
                'id' => $user->id,
                'nome' => $user->nome,
                'email' => $user->email,
            ],
            'messages' => $messages,
        ]);
    }

    public function sendMessage(Request $request, User $user)
    {
        $authUser = $request->user();

        if ($user->id === $authUser->id) {
            return response()->json(['message' => 'Nao e possivel enviar mensagem para voce mesmo.'], 422);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:3000',
        ]);

        $message = ChatMessage::create([
            'sender_id' => $authUser->id,
            'recipient_id' => $user->id,
            'message' => trim($validated['message']),
        ]);

        UserNotification::create([
            'recipient_id' => $user->id,
            'sender_id' => $authUser->id,
            'type' => 'chat',
            'title' => 'Nova mensagem',
            'body' => "Voce recebeu uma mensagem de {$authUser->nome}.",
            'payload' => [
                'chat_user_id' => $authUser->id,
                'chat_message_id' => $message->id,
            ],
        ]);

        return response()->json([
            'message' => 'Mensagem enviada com sucesso.',
            'data' => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'recipient_id' => $message->recipient_id,
                'message' => $message->message,
                'created_at' => $message->created_at,
            ],
        ], 201);
    }
}
