<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\V2UserConnectLog;
class UniProxyController extends Controller
{
    private $nodeType;
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    public function __construct(Request $request)
    {
        $token = $request->input('token');
        if (empty($token)) {
            abort(500, 'token is null');
        }
        if ($token !== config('v2board.server_token')) {
            abort(500, 'token is error');
        }
        $this->nodeType = $request->input('node_type');
        if ($this->nodeType === 'v2ray')
            $this->nodeType = 'vmess';
        if ($this->nodeType === 'hysteria2')
            $this->nodeType = 'hysteria';
        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, $this->nodeType);
        if (!$this->nodeInfo)
            abort(500, 'server is not exist');
    }

    // 后端获取用户
    public function user(Request $request)
    {
        ini_set('memory_limit', -1);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);
        $cacheSeconds = (int) config('v2board.server_pull_interval', 60);
        $users = $this->serverService->getAvailableUsers($this->nodeInfo->group_id);
        $users = $users->toArray();

        $response['users'] = $users;

        $eTag = sha1(json_encode($response));
        if (strpos($request->header('If-None-Match'), $eTag) !== false) {
            abort(304);
        }
        return response($response)->header('ETag', "\"{$eTag}\"")->header('Cache-Control', "public, max-age={$cacheSeconds}");
    }

    // 后端提交数据
    public function push(Request $request)
    {
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // JSON decoding error
            return response([
                'error' => 'Invalid traffic data'
            ], 400);
        }
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_ONLINE_USER', $this->nodeInfo->id), count($data), 3600);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_PUSH_AT', $this->nodeInfo->id), time(), 3600);
        $userService = new UserService();
        $userService->trafficFetch($this->nodeInfo->toArray(), $this->nodeType, $data);

        return response([
            'data' => true
        ]);
    }

    // 后端获取在线数据
    public function alivelist(Request $request)
    {
        $alive = Cache::remember('ALIVE_LIST', 60, function () {
            $userService = new UserService();
            $users = $userService->getDeviceLimitedUsers();

            if ($users->isEmpty()) {
                return [];
            }

            $keys = [];
            $idMap = [];
            foreach ($users as $user) {
                $key = 'ALIVE_IP_USER:' . $user->id;
                $keys[] = $key;
                $idMap[$key] = $user->id;
            }

            $results = Cache::many($keys);
            $alive = [];
            foreach ($results as $key => $data) {
                if (is_array($data) && isset($data['alive_ip'])) {
                    $alive[$idMap[$key]] = $data['alive_ip'];
                }
            }
            return $alive;
        });
        return response()->json(['alive' => (object) $alive]);
    }

    // 后端提交在线数据
    public function alive(Request $request)
    {
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return response([
                'error' => 'Invalid online data'
            ], 400);
        }

        $updateAt = time();
        $recordsToProcess = [];

        foreach ($data as $uid => $ips) {
            $ips_array = Cache::get('ALIVE_IP_USER:' . $uid) ?? [];
            // 更新节点数据
            $ips_array[$this->nodeType . $this->nodeId] = ['aliveips' => $ips, 'lastupdateAt' => $updateAt];

            // 清理过期数据
            foreach ($ips_array as $nodetypeid => $oldips) {
                if (!is_int($oldips) && ($updateAt - $oldips['lastupdateAt'] > 100)) {
                    unset($ips_array[$nodetypeid]);
                }
            }

            $count = 0;
            if (config('v2board.device_limit_mode', 0) == 1) {
                $ipmap = [];
                foreach ($ips_array as $nodetypeid => $newdata) {
                    if (!is_int($newdata) && isset($newdata['aliveips'])) {
                        foreach ($newdata['aliveips'] as $ip_NodeId) {
                            $ip = explode("_", $ip_NodeId)[0];
                            $ipmap[$ip] = 1;
                        }
                    }
                }
                $count = count($ipmap);
            } else {
                foreach ($ips_array as $nodetypeid => $newdata) {
                    if (!is_int($newdata) && isset($newdata['aliveips'])) {
                        $count += count($newdata['aliveips']);
                    }
                }
            }

            $ips_array['alive_ip'] = $count;
            Cache::put('ALIVE_IP_USER:' . $uid, $ips_array, 120);

            // 收集当前用户的所有活跃IP用于后续处理
            $userIps = [];
            foreach ($ips_array as $nodetypeid => $newdata) {
                if (!is_int($newdata) && isset($newdata['aliveips'])) {
                    foreach ($newdata['aliveips'] as $ip_NodeId) {
                        $ip = explode("_", $ip_NodeId)[0];
                        if (filter_var($ip, FILTER_VALIDATE_IP)) {
                            $userIps[] = $ip;
                        }
                    }
                }
            }
            if (!empty($userIps)) {
                $recordsToProcess[$uid] = array_unique($userIps);
            }
        }

        // 处理数据库记录 - 修复重复插入问题
        foreach ($recordsToProcess as $uid => $ips) {
            $user = User::find($uid);
            if (!$user) {
                continue;
            }

            foreach ($ips as $ip) {
                try {
                    // 检查缓存
                    $cacheKey = "IP_GEO_DATA:{$ip}";
                    $ipData = Cache::get($cacheKey);

                    if (!$ipData) {
                        // 缓存不存在，请求API
                        $response = Http::timeout(3)->get("https://ip.bt3.one/{$ip}");
                        if ($response->successful()) {
                            $ipData = $response->json();
                            // 缓存24小时
                            Cache::put($cacheKey, $ipData, 1440);
                        } else {
                            // API请求失败，缓存空结果5分钟避免频繁请求
                            $ipData = [];
                            Cache::put($cacheKey, $ipData, 5);
                        }
                    }

                    V2UserConnectLog::updateOrCreate(
                        [
                            'user_id' => $uid,
                            'ip' => $ip
                        ],
                        [
                            'email' => $user->email,
                            'as_number' => $ipData['as']['number'] ?? null,
                            'as_name' => $ipData['as']['name'] ?? null,
                            'country' => $ipData['country']['name'] ?? null,
                            'region' => implode(',', $ipData['regions_short'] ?? []),
                            'updated_at' => now()  // 更新时间戳
                        ]
                    );

                } catch (\Exception $e) {
                }
            }
        }

        return response([
            'data' => true
        ]);
    }

    public function config(Request $request)
    {
        switch ($this->nodeType) {
            case 'shadowsocks':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'cipher' => $this->nodeInfo->cipher,
                    'obfs' => $this->nodeInfo->obfs,
                    'obfs_settings' => $this->nodeInfo->obfs_settings
                ];

                if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
                }
                if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
                }
                break;
            case 'vmess':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->networkSettings,
                    'tls' => $this->nodeInfo->tls
                ];
                break;
            case 'vless':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'tls' => $this->nodeInfo->tls,
                    'flow' => $this->nodeInfo->flow,
                    'tls_settings' => $this->nodeInfo->tls_settings
                ];
                break;
            case 'trojan':
                $response = [
                    'host' => $this->nodeInfo->host,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                ];
                break;
            case 'tuic':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'congestion_control' => $this->nodeInfo->congestion_control,
                    'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
                ];
                break;
            case 'hysteria':
                $response = [
                    'version' => $this->nodeInfo->version,
                    'host' => $this->nodeInfo->host,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'up_mbps' => $this->nodeInfo->up_mbps,
                    'down_mbps' => $this->nodeInfo->down_mbps
                ];
                if ($this->nodeInfo->version == 1) {
                    $response['obfs'] = $this->nodeInfo->obfs_password ?? null;
                } elseif ($this->nodeInfo->version == 2) {
                    if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
                        $response['ignore_client_bandwidth'] = true;
                    } else {
                        $response['ignore_client_bandwidth'] = false;
                    }
                    $response['obfs'] = $this->nodeInfo->obfs ?? null;
                    $response['obfs-password'] = $this->nodeInfo->obfs_password ?? null;
                }
                break;
            case 'anytls':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'padding_scheme' => $this->nodeInfo->padding_scheme
                ];
                break;
        }
        $response['base_config'] = [
            'push_interval' => (int) config('v2board.server_push_interval', 60),
            'pull_interval' => (int) config('v2board.server_pull_interval', 60)
        ];
        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }
        $eTag = sha1(json_encode($response));
        if (strpos($request->header('If-None-Match'), $eTag) !== false) {
            abort(304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }
}
