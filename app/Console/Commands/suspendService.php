<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Log;
use RouterOS\Query;
use App\Models\Router;
use App\Models\SaleRegister;
use App\Models\RouterSaleRegister;
use App\Models\UserTagihanKhusus;
use Illuminate\Support\Facades\DB;

class suspendService extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:suspend';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Suspend users with expired subscriptions';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
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
            ->whereNotIn('status', ['Trial', 'Pasca-Trial', 'Hold'])
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

        foreach ($customer as $item) {

            // add log
            $log = Log::create([
                'id_register'   => $item->id_register,
                'ip'            => 'CRONJOB SUSPEND',
                'user_agent'    => 'CRONJOB SUSPEND',
                'url'           => 'CRONJOB SUSPEND',
                'request'       => 'CRONJOB SUSPEND',
            ]);

            // Get router data
            $router_sale_register = RouterSaleRegister::where('id_register', $item->id_register)->get();

            if ($router_sale_register->isEmpty()) {
                // update log
                $log->update([
                    'response'      => 'Router Sale Register not found',
                    'status_code'   => 404,
                ]);

                continue; // Skip to the next customer if no router is found
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

                    continue; // Skip to the next router if not found
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

                    continue; // Skip to the next router if PPP Secret is not found
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

                        continue; // Skip to the next router if unable to remove active connections
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

                        continue; // Skip to the next router if PPP Secret is not found after disabling
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

                    continue; // Skip to the next router if unable to disable PPP Secret
                }
            }
        }

        // check failed secrets in log
        $failed_secrets = Log::where('status_code', '!=', '200')
            ->where('created_at', 'like', now()->format('Y-m-d') . '%')
            ->where('ip', 'CRONJOB SUSPEND')
            ->get();

        $success_secret = Log::where('status_code', '200')
            ->where('created_at', 'like', now()->format('Y-m-d') . '%')
            ->where('ip', 'CRONJOB SUSPEND')
            ->get();

        /// Kirim ke Discord
        $webhookUrl = "https://discordapp.com/api/webhooks/883884047393243146/adTK8cFESrmaNwgIeLS0vboKhMzjKWydFlf-UTbUgPvdbbO7FSTo3szfRG5q3iXUn0uz";
        $totalTagihan = array_sum(array_column($customer, 'tagihan'));

        $discordMessage = [
            'content' => "**Laporan Cronjob SUSPEND USERS**",
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
                        ],
                        [
                            'name' => 'Successfully Suspended',
                            'value' => count($success_secret) . " users",
                            'inline' => true
                        ],
                        [
                            'name' => 'Failed to Suspend',
                            'value' => count($failed_secrets) . " users",
                            'inline' => true
                        ]
                    ],
                    'timestamp' => date('c')
                ]
            ]
        ];

        // Jika ingin mengirim semua data sebagai file JSON
        $jsonData = json_encode(['data' => $customer], JSON_PRETTY_PRINT);
        $fileName = 'data_suspend_' . date('Ymd_His') . '.json';
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
        // Hapus file temporary
        unlink($fileName);

        // Log the response
        if ($response) {
            $this->info('Discord notification sent successfully.');
        } else {
            $this->error('Failed to send Discord notification: ' . $response->body());
            // debug request
            $this->error('Request: ' . json_encode($payload, JSON_PRETTY_PRINT));
        }
        return 0;
    }
}
