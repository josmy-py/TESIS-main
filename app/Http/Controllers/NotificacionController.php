<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use Illuminate\Http\Request;

/**
 * M6.1 (D-M6): avisos de la cuenta autenticada (tabla estándar
 * `notifications`), por ejemplo matrícula aprobada o rechazada con su motivo.
 */
class NotificacionController extends Controller
{
    use RespondeErrores;

    public function index(Request $request)
    {
        return $this->responder(function () use ($request) {
            $usuario = $request->user();
            $avisos = $usuario->notifications()
                ->when($request->boolean('no_leidas'), fn ($q) => $q->whereNull('read_at'))
                ->limit(50)
                ->get(['id', 'data', 'read_at', 'created_at'])
                ->map(fn ($n) => ['id' => $n->id, 'leida' => $n->read_at !== null, 'fecha' => $n->created_at] + $n->data);

            return response()->json([
                'notificaciones' => $avisos,
                'no_leidas' => $usuario->unreadNotifications()->count(),
            ]);
        }, 'Error al obtener las notificaciones');
    }

    public function marcarLeida(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $request->user()->notifications()->findOrFail($id)->markAsRead();

            return response()->json(['message' => 'Notificación marcada como leída.']);
        }, 'Error al actualizar la notificación', 'Notificación no encontrada');
    }
}
