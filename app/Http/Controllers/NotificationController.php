<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifs = $request->user()->notifications()->limit(50)->get();

        return view('notifikasi.index', ['notifs' => $notifs]);
    }

    /** Tandai satu notifikasi terbaca lalu arahkan ke tautannya. */
    public function open(Request $request, string $id)
    {
        $n = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $n->markAsRead();

        return redirect($n->data['url'] ?? route('notifikasi.index'));
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
