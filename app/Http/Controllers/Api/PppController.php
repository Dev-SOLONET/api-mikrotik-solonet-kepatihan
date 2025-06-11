<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Log;
use Illuminate\Support\Facades\Validator;
use RouterOS\Query;
use App\Models\Router;
use App\Models\SaleRegister;
use App\Models\RouterSaleRegister;
use App\Models\UserTagihanKhusus;
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
            'id_register'   => $request->id_register ?? null,
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
        if ($request->has('id_register')) {
            $username = RouterSaleRegister::where('id_register', $request->id_register)
                ->where('id_router', $router->id)
                ->first();

            if (!$username) {
                return response()->json([
                    'status'    => false,
                    'message'   => 'PPP Secret not found for the specified register',
                ], 404);
            }

            $query          = new Query('/ppp/secret/print');
            $query->where('name', $username->username ?? '');
            $response       = $client->query($query)->read();

            // FIND ACTIVE CONNECTIONS
            $query_active = new Query('/ppp/active/print');
            $query_active->where('name', $username->username ?? '');
            $active_connections = $client->query($query_active)->read();

            // RESPONSE
            $response = [
                'router'            => $identity[0]['name'],
                'secrets'           => $response[0] ?? [],
                'active_connections' => $active_connections[0] ?? [],
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

    public function findSecrets($id_register)
    {
        // Get router data
        $router_sale_register = RouterSaleRegister::where('id_register', $id_register)->get();

        if ($router_sale_register->isEmpty()) {
            return response()->json([
                'status'    => false,
                'message'   => 'Router Sale Register not found',
            ], 404);
        }

        // show secrets for each router
        $result = [];
        foreach ($router_sale_register as $register) {
            $router = Router::select('id', 'name', 'port', DB::raw('INET_NTOA(ip) as ip'))
                ->where('id', $register->id_router)
                ->first();

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
            $query->where('name', $register->username);
            $secrets = $client->query($query)->read();

            if (empty($secrets)) {
                return response()->json([
                    'status'    => false,
                    'message'   => 'PPP Secret not found',
                ], 404);
            }

            // FIND ACTIVE CONNECTIONS
            $query_active = new Query('/ppp/active/print');
            $query_active->where('name', $register->username);
            $active_connections = $client->query($query_active)->read();

            // add to result
            $result[] = [
                'router' => [
                    'id' => $router->id,
                    'name' => $router->name,
                    'ip' => $router->ip,
                    'port' => $router->port,
                ],
                'secrets' => $secrets[0],
                'active_connections' => !empty($active_connections) ? $active_connections[0] : null,
            ];
        }

        return response()->json([
            'status'    => true,
            'data'      => $result,
        ], 200);
    }

    public function disableSecrets(Request $request)
    {
        // VALIDATE
        $validator = Validator::make($request->all(), [
            'id_register'   => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'    => false,
                'message'   => $validator->errors()->first(),
            ], 422);
        }

        // add log
        $log = Log::create([
            'id_register'   => $request->id_register ?? null,
            'ip'            => request()->ip(),
            'user_agent'    => request()->userAgent(),
            'url'           => request()->url(),
            'request'       => json_encode(request()->all()),
        ]);

        // Get router data
        $router_sale_register = RouterSaleRegister::where('id_register', $request->id_register)->get();

        if ($router_sale_register->isEmpty()) {
            // update log
            $log->update([
                'response'      => 'Router Sale Register not found',
                'status_code'   => 404,
            ]);

            return response()->json([
                'status'    => false,
                'message'   => 'Router Sale Register not found',
            ], 404);
        }

        $result_secrets = [];

        foreach ($router_sale_register as $register) {

            $router = Router::select('id', 'name', 'port', DB::raw('INET_NTOA(ip) as ip'))
                ->where('id', $register->id_router)
                ->first();

            if (!$router) {
                // update log
                $log->update([
                    'response'      => 'Router not found',
                    'status_code'   => 404,
                ]);

                return response()->json([
                    'status'    => false,
                    'message'   => 'Router not found',
                ], 404);
            }

            // INITIATE CLIENT
            $client = ConnectionMikrotik($router->ip, $router->port);

            // FIND PPP SECRET
            $query = new Query('/ppp/secret/print');
            $query->where('name', $register->username);
            $secrets = $client->query($query)->read();

            if (empty($secrets)) {
                // update log
                $log->update([
                    'response'      => 'PPP Secret not found',
                    'status_code'   => 404,
                ]);

                return response()->json([
                    'status'    => false,
                    'message'   => 'PPP Secret not found',
                ], 404);
            }

            // MAKE QUERY
            $query = new Query('/ppp/secret/set');
            $query->equal('.id', $secrets[0]['.id']);
            $query->equal('disabled', 'true');

            // FIND ACTIVE CONNECTIONS
            $query_active = new Query('/ppp/active/print');
            $query_active->where('name', $register->username);
            $active_connections = $client->query($query_active)->read();

            // REMOVE ACTIVE CONNECTIONS
            if (!empty($active_connections)) {
                $query_remove = new Query('/ppp/active/remove');
                $query_remove->equal('.id', $active_connections[0]['.id']);
                try {
                    $client->query($query_remove)->read();
                } catch (\Exception $e) {
                    // Log the error and return a response
                    $log->update([
                        'response'      => 'Failed to remove active connections: ' . $e->getMessage(),
                        'status_code'   => 500,
                    ]);

                    return response()->json([
                        'status'    => false,
                        'message'   => 'Failed to remove active connections: ' . $e->getMessage(),
                    ], 500);
                }
            }

            try {
                $client->query($query)->read();

                // FIND PPP SECRET AGAIN
                $query = new Query('/ppp/secret/print');
                $query->where('name', $register->username);
                $secrets = $client->query($query)->read();
                if (empty($secrets)) {
                    // update log
                    $log->update([
                        'response'      => 'Failed to disable PPP Secret, it was not found after disabling',
                        'status_code'   => 500,
                    ]);

                    return response()->json([
                        'status'    => false,
                        'message'   => 'Failed to disable PPP Secret, it was not found after disabling',
                    ], 500);
                }

                // update log
                $log->update([
                    'response'      => json_encode($secrets[0]),
                    'status_code'   => 200,
                ]);

                // update sale register status
                SaleRegister::where('id_register', $register->id_register)
                    ->update(['status' => 'Suspended']);

                $result_secrets[] = $secrets[0];
            } catch (\Exception $e) {
                // Log the error and return a response
                $log->update([
                    'response'      => 'Failed to disable PPP Secret: ' . $e->getMessage(),
                    'status_code'   => 500,
                ]);

                return response()->json([
                    'status'    => false,
                    'message'   => 'Failed to disable PPP Secret: ' . $e->getMessage(),
                ], 500);
            }
        }

        return response()->json([
            'status'    => true,
            'message'   => 'PPP Secret disabled successfully',
            'data'      => [
                'secrets' => $result_secrets,
            ],
        ], 200);
    }

    public function enableSecrets(Request $request)
    {
        // VALIDATE
        $validator = Validator::make($request->all(), [
            'id_register'   => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'    => false,
                'message'   => $validator->errors()->first(),
            ], 422);
        }

        // add log
        $log = Log::create([
            'id_register'   => $request->id_register ?? null,
            'ip'            => request()->ip(),
            'user_agent'    => request()->userAgent(),
            'url'           => request()->url(),
            'request'       => json_encode(request()->all()),
        ]);

        // Get router data
        $router_sale_register = RouterSaleRegister::where('id_register', $request->id_register)->get();
        if ($router_sale_register->isEmpty()) {
            // update log
            $log->update([
                'response'      => 'Router Sale Register not found',
                'status_code'   => 404,
            ]);

            return response()->json([
                'status'    => false,
                'message'   => 'Router Sale Register not found',
            ], 404);
        }

        $result_secrets = [];
        $result_active_connections = [];

        foreach ($router_sale_register as $register) {

            $router = Router::select('id', 'name', 'port', DB::raw('INET_NTOA(ip) as ip'))
                ->where('id', $register->id_router)
                ->first();

            if (!$router) {
                // update log
                $log->update([
                    'response'      => 'Router not found',
                    'status_code'   => 404,
                ]);

                return response()->json([
                    'status'    => false,
                    'message'   => 'Router not found',
                ], 404);
            }

            // INITIATE CLIENT
            $client = ConnectionMikrotik($router->ip, $router->port);

            // FIND PPP SECRET
            $query = new Query('/ppp/secret/print');
            $query->where('name', $register->username);
            $secrets = $client->query($query)->read();
            if (empty($secrets)) {
                // update log
                $log->update([
                    'response'      => 'PPP Secret not found',
                    'status_code'   => 404,
                ]);

                return response()->json([
                    'status'    => false,
                    'message'   => 'PPP Secret not found',
                ], 404);
            }

            // MAKE QUERY
            $query = new Query('/ppp/secret/set');
            $query->equal('.id', $secrets[0]['.id']);
            $query->equal('disabled', 'false');
            try {

                $client->query($query)->read();

                // FIND PPP SECRET AGAIN
                $query = new Query('/ppp/secret/print');
                $query->where('name', $register->username);
                $secrets = $client->query($query)->read();
                if (empty($secrets)) {
                    // update log
                    $log->update([
                        'response'      => 'Failed to enable PPP Secret, it was not found after enabling',
                        'status_code'   => 500,
                    ]);

                    return response()->json([
                        'status'    => false,
                        'message'   => 'Failed to enable PPP Secret, it was not found after enabling',
                    ], 500);
                }

                // update log
                $log->update([
                    'response'      => json_encode($secrets[0]),
                    'status_code'   => 200,
                ]);

                SaleRegister::where('id_register', $register->id_register)
                    ->update(['status' => 'Close']);

                $result_secrets[] = $secrets[0];
                $result_active_connections[] = [];
            } catch (\Exception $e) {
                // Log the error and return a response
                $log->update([
                    'response'      => 'Failed to enable PPP Secret: ' . $e->getMessage(),
                    'status_code'   => 500,
                ]);

                return response()->json([
                    'status'    => false,
                    'message'   => 'Failed to enable PPP Secret: ' . $e->getMessage(),
                ], 500);
            }
        }

        return response()->json([
            'status'    => true,
            'message'   => 'PPP Secret enabled successfully',
            'data'      => [
                'secrets' => $result_secrets,
                'active_connections' => $result_active_connections,
            ],
        ], 200);
    }

    public function multipleDisableSecrets(Request $request)
    {
        // VALIDATE
        $validator = Validator::make($request->all(), [
            'id_register' => 'required|array',
            'id_register.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'    => false,
                'message'   => $validator->errors()->first(),
            ], 422);
        }

        foreach ($request->id_register as $id_register) {
            $this->disableSecrets(new Request(['id_register' => $id_register]));
        }

        return response()->json([
            'status'    => true,
            'message'   => 'All specified PPP Secrets have been disabled successfully',
        ], 200);
    }

    public function multipleEnableSecrets(Request $request)
    {
        // VALIDATE
        $validator = Validator::make($request->all(), [
            'id_register' => 'required|array',
            'id_register.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'    => false,
                'message'   => $validator->errors()->first(),
            ], 422);
        }

        foreach ($request->id_register as $id_register) {
            $this->enableSecrets(new Request(['id_register' => $id_register]));
        }

        return response()->json([
            'status'    => true,
            'message'   => 'All specified PPP Secrets have been enabled successfully',
        ], 200);
    }

    public function suspendUser()
    {
        $user_tagihan_khusus = UserTagihanKhusus::select('id_register')->pluck('id_register')->toArray();

        $customer = SaleRegister::with([
            'salePaketInternet' => function ($query) {
                $query->select('id_paket', 'nama_paket', 'harga');
            },
        ])->select(
            'id_register',
            'nama_user',
            'status',
            'id_paket',
            DB::raw('billing_bulan_berjalan - billing_bulan_terbayar as tunggakan'),
        )
            ->where(DB::raw('billing_bulan_berjalan - billing_bulan_terbayar'), '>', 0)
            ->where('nama_user', 'not like', '%MAGELANG%')
            ->whereNotIn('id_register', $user_tagihan_khusus)
            ->whereNotIn('status', ['Trial', 'Pasca-Trial'])
            ->whereHas('routerSaleRegister', function ($query) {
                $query->where('username', '!=', '');
            })
            ->get();

        // map sum tunggakan * nominal
        $customer->map(function ($item) {
            $item->tagihan = $item->tunggakan * $item->salePaketInternet->harga;
            return $item;
        });

        // order by tagihan desc
        $customer = $customer->sortByDesc('tagihan')->values()->all();

        // Kirim ke Discord
        $webhookUrl = "https://discordapp.com/api/webhooks/883884047393243146/adTK8cFESrmaNwgIeLS0vboKhMzjKWydFlf-UTbUgPvdbbO7FSTo3szfRG5q3iXUn0uz";
        $totalTagihan = array_sum(array_column($customer, 'tagihan'));

        $discordMessage = [
            'content' => "**Laporan Tagihan Pelanggan**",
            'embeds' => [
                [
                    'title' => 'Ringkasan Tagihan',
                    'description' => "Total pelanggan: " . count($customer) . "\nTotal tagihan: Rp " . number_format($totalTagihan, 0, ',', '.'),
                    'color' => 5814783,
                    'fields' => [
                        [
                            'name' => 'Pelanggan dengan Tagihan Tertinggi',
                            'value' => "{$customer[0]->nama_user} - Rp " . number_format($customer[0]->tagihan, 0, ',', '.'),
                            'inline' => false
                        ]
                    ],
                    'timestamp' => date('c')
                ]
            ]
        ];

        // Jika ingin mengirim semua data sebagai file JSON
        $jsonData = json_encode(['data' => $customer], JSON_PRETTY_PRINT);
        $fileName = 'tagihan_pelanggan_' . date('Ymd_His') . '.json';
        file_put_contents($fileName, $jsonData);

        $payload = [
            'payload_json' => json_encode($discordMessage),
            'file' => new \CURLFile(realpath($fileName), 'application/json', $fileName)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        unlink($fileName);

        return response()->json([
            'status'    => true,
            'message'   => 'List of customers with pending payments',
            'total'     => count($customer),
            'data'      => $customer,
        ], 200);
    }
}
