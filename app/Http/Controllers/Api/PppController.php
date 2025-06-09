<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Log;
use Illuminate\Support\Facades\Validator;
use RouterOS\Query;
use App\Models\Router;
use App\Models\RouterSaleRegister;
use Illuminate\Support\Facades\DB;

class PppController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth.apikey');
    }

    public function showSecrets($router_id, Request $request)
    {

        // VALIDATE
        if (empty($router_id)) {
            return response()->json([
                'status'    => false,
                'message'   => 'Router ID is required',
            ]);
        }

        // add log
        $log = Log::create([
            'ip'            => request()->ip(),
            'user_agent'    => request()->userAgent(),
            'url'           => request()->url(),
            'request'       => json_encode(request()->all()),
        ]);

        // Get router data
        $router = Router::select('id', 'name', 'port', DB::raw('INET_NTOA(ip) as ip'))
            ->where('id', $router_id)
            ->first();

        // validate
        if (!$router) {
            // update log
            $log->update([
                'response'      => 'Router not found',
                'status_code'   => 404,
            ]);

            // response
            return response()->json([
                'status'    => false,
                'message'   => 'Router not found',
                'data'      => $router,
            ], 404);
        }

        // INITIATE CLIENT
        $client = ConnectionMikrotik($router->ip, $router->port);

        // MAKE QUERY ROUTEROS IDENTIFY
        $query          = new Query('/system/identity/print');
        $identity       = $client->query($query)->read();

        // if specified address list
        if ($request->has('name')) {
            $query          = new Query('/ppp/secret/print');
            $query->where('name', $request->name);
            $response       = $client->query($query)->read();

            // RESPONSE
            $response = [
                'router'            => $identity[0]['name'],
                'secrets'           => $response[0] ?? [],
            ];

            // update log
            $log->update([
                'response'      => json_encode($response),
                'status_code'   => 200,
            ]);

            return response()->json([
                'status'    => true,
                'data'      => $response,
            ], 200);
        } else {

            // MAKE QUERY
            $query          = new Query('/ppp/secret/print');
            $response       = $client->query($query)->read();

            // RESPONSE
            $response = [
                'router'            => $identity[0]['name'],
                'secrets'           => $response,
            ];

            // update log
            $log->update([
                'response'      => json_encode($response),
                'status_code'   => 200,
            ]);

            return response()->json([
                'status'    => true,
                'data'      => $response,
            ], 200);
        }
    }

    public function disableSecrets(Request $request)
    {
        // VALIDATE
        $validator = Validator::make($request->all(), [
            'id_register'   => 'required|string|exists:routers_sale_registers,id_register',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'    => false,
                'message'   => $validator->errors()->first(),
            ], 422);
        }

        // Get router data
        $router_sale_register = RouterSaleRegister::where('id_register', $request->id_register)->first();
        $router = Router::find($router_sale_register->id_router);

        if (!$router) {
            return response()->json([
                'status'    => false,
                'message'   => 'Router not found',
            ], 404);
        }

        // INITIATE CLIENT
        $client = ConnectionMikrotik($router->ip, $router->port);

        // FIND PPP SECRET
        $query = new Query('/ppp/secret/print');
        $query->where('name', $router_sale_register->name);
        $secrets = $client->query($query)->read();

        if (empty($secrets)) {
            return response()->json([
                'status'    => false,
                'message'   => 'PPP Secret not found',
            ], 404);
        }

        // MAKE QUERY
        $query = new Query('/ppp/secret/set');
        $query->equal('name', $request->name);
        $query->equal('disabled', 'true');

        try {
            $client->query($query)->read();
            return response()->json([
                'status'    => true,
                'message'   => 'PPP Secret disabled successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'    => false,
                'message'   => 'Failed to disable PPP Secret: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function enableSecrets(Request $request)
    {
        // VALIDATE
        $validator = Validator::make($request->all(), [
            'id_register'   => 'required|string|exists:routers_sale_registers,id_register',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'    => false,
                'message'   => $validator->errors()->first(),
            ], 422);
        }

        // Get router data
        $router_sale_register = RouterSaleRegister::where('id_register', $request->id_register)->first();
        $router = Router::find($router_sale_register->id_router);
        if (!$router) {
            return response()->json([
                'status'    => false,
                'message'   => 'Router not found',
            ], 404);
        }

        // INITIATE CLIENT
        $client = ConnectionMikrotik($router->ip, $router->port);
        
        // FIND PPP SECRET
        $query = new Query('/ppp/secret/print');
        $query->where('name', $router_sale_register->name);
        $secrets = $client->query($query)->read();
        if (empty($secrets)) {
            return response()->json([
                'status'    => false,
                'message'   => 'PPP Secret not found',
            ], 404);
        }

        // MAKE QUERY
        $query = new Query('/ppp/secret/set');
        $query->equal('name', $request->name);
        $query->equal('disabled', 'false');
        try {
            $client->query($query)->read();
            return response()->json([
                'status'    => true,
                'message'   => 'PPP Secret enabled successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'    => false,
                'message'   => 'Failed to enable PPP Secret: ' . $e->getMessage(),
            ], 500);
        }
    }
}
