<?php

use App\Events\BroadcastEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nexzan\Shared\Exceptions\CustomException;

if (! function_exists('newUuid')) {
    function newUuid()
    {
        return Str::ulid(); // time sortable
    }
}

if (! function_exists('ResponseSuccess')) {
    function ResponseSuccess($data, $message = null)
    {
        $items = $data;
        $meta  = null;

        if (isset($data['meta'])) {
            $meta = $data['meta'];
        }

        if (isset($data['items'])) {
            $items = $data['items'];
        }

        $response = [
            'success'  => true,
            'message'  => __($message ?? 'Success'),
            'resource' => $items,
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        return response()->json($response, Response::HTTP_OK);
    }
}

function getAuthService()
{
    return config("service-core.service_name",'undefined') == 'gateway'
         ?\Illuminate\Support\Facades\Auth::class
        : \Microservice\Auth\Facades\Auth::class;
}

if (! function_exists('userTeamId')) {
    function userTeamId()
    {
        if (config("service-core.service_name",'undefined') == 'gateway') {

            $user = getAuthService()::user();
            return $user->current_team_id ?? null;
        }
        return getAuthService()::teamId();
    }
}

if (! function_exists('userId')) {
    function userId()
    {
        return getAuthService()::Id();
    }
}

if (! function_exists('ResponseError')) {
    function ResponseError($message = null, $jsonStatus = Response::HTTP_INTERNAL_SERVER_ERROR, $throwable = null, $resource = null)
    {
        if ($throwable) {
            if (! $throwable instanceof CustomException) {
                Log::error($throwable);
                Log::channel('mail')->error($throwable);
            } else {
                Log::error($throwable->getMessage());
            }

        } else {
            $message = __($message ?? 'Something went wrong');
            Log::error($message);
            Log::channel('mail')->error($message);
        }

        if ($throwable && $throwable instanceof CustomException) {
            $jsonStatus = $throwable->getStatusCode();
            $message    = __($throwable->getMessage());
        } elseif (
            class_exists('App\\Exceptions\\CloudPanelException') &&
            $throwable instanceof \App\Exceptions\CloudPanelException
        ) {
            $jsonStatus = $throwable->getStatusCode();
            $response   = $throwable->getMessage();
            $message    = json_decode($response)->message ?? $message;
        } elseif ($throwable && $throwable instanceof QueryException) {
            $message = __('A database error occurred.Please try again');
        }

        if (! is_int($jsonStatus) || $jsonStatus < 100 || $jsonStatus > 599) {
            $jsonStatus = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        return response()->json([
            'success'  => false,
            'message'  => $message,
            'resource' => $resource,
        ], $jsonStatus);
    }
}

if (! function_exists('paginateMetaData')) {
    function paginateMetaData($data)
    {
        if (! ($data instanceof LengthAwarePaginator || $data instanceof Paginator)) {
            return null;
        }

        $total_items = $data->total();
        $per_page    = $data->perPage();
        $total_pages = ceil($total_items / $per_page);

        return [
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'per_page'     => $per_page,
            'total_items'  => $total_items,
            'total_pages'  => $total_pages,
            'from'         => $data->firstItem(),
            'to'           => $data->lastItem(),
        ];
    }
}

if (! function_exists('eventBroadcast')) {
    function eventBroadcast(string $listen, $user_id, array $data, $should_notificaiton = true)
    {
        BroadcastEvent::dispatch($listen, $user_id, $data);
        if ($should_notificaiton) {
            BroadcastEvent::dispatch("global-notification", $user_id, $data);
        }
    }
}

if (! function_exists('getXUserIp')) {
    function getXUserIp()
    {
        $x_user_ip = request()->header('x-user-ip');

        return $x_user_ip ?? request()->ip();
    }
}
