<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{

    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))->first();
            if (!$ticket) {
                abort(500, '工单不存在');
            }

            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();

            foreach ($ticket['message'] as $msg) {
                $msg['is_me'] = $msg['user_id'] !== $ticket->user_id;
            }

            return response([
                'data' => $ticket
            ]);
        }

        $current = $request->input('current') ?: 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;

        $model = Ticket::orderBy('updated_at', 'DESC');
        if ($request->input('status') !== null) {
            switch ((int) $request->input('status')) {
                case 0: // 待回复
                    $model->where('status', 0)->where('reply_status', 0);
                    break;
                case 1: // 已回复
                    $model->where('status', 0)->where('reply_status', 1);
                    break;
                case 2: // 已关闭
                    $model->where('status', 1);
                    break;
            }
        }

        if ($request->input('reply_status') !== null) {
            $model->whereIn('reply_status', $request->input('reply_status'));
        }

        if ($request->input('email') !== null) {
            $user = User::where('email', $request->input('email'))->first();
            if ($user)
                $model->where('user_id', $user->id);
        }

        $total = $model->count();
        $res = $model->forPage($current, $pageSize)->get();

        return response([
            'data' => $res,
            'total' => $total
        ]);
    }




    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        if (empty($request->input('message'))) {
            abort(500, '消息不能为空');
        }
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            $request->input('message'),
            $request->user['id']
        );
        return response([
            'data' => true
        ]);
    }

    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->first();
        if (!$ticket) {
            abort(500, '工单不存在');
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, '关闭失败');
        }
        return response([
            'data' => true
        ]);
    }
}
