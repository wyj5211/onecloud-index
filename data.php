<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// 禁止显示错误（生产环境可开启）
error_reporting(0);
ini_set('display_errors', 0);

// 辅助函数：获取CPU使用率（通过两次采样/proc/stat，间隔100ms）
function getCpuUsage() {
    $stat1 = @file_get_contents('/proc/stat');
    if ($stat1 === false) return null;
    usleep(100000); // 100ms
    $stat2 = @file_get_contents('/proc/stat');
    if ($stat2 === false) return null;

    preg_match('/cpu\s+(.+)/', $stat1, $m1);
    preg_match('/cpu\s+(.+)/', $stat2, $m2);
    if (empty($m1) || empty($m2)) return null;

    $fields1 = explode(' ', trim($m1[1]));
    $fields2 = explode(' ', trim($m2[1]));
    // 取 user, nice, system, idle, iowait, irq, softirq, steal
    $tot1 = array_sum($fields1);
    $idle1 = $fields1[3] + ($fields1[4] ?? 0); // idle + iowait
    $tot2 = array_sum($fields2);
    $idle2 = $fields2[3] + ($fields2[4] ?? 0);

    $totalDiff = $tot2 - $tot1;
    $idleDiff = $idle2 - $idle1;
    if ($totalDiff <= 0) return 0;
    $usage = (1 - $idleDiff / $totalDiff) * 100;
    return round($usage, 1);
}

// 获取内存信息（MB）
function getMemoryInfo() {
    $meminfo = @file_get_contents('/proc/meminfo');
    if (!$meminfo) return null;
    $total = preg_match('/MemTotal:\s+(\d+)/', $meminfo, $t) ? $t[1] : 0;
    $available = preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $a) ? $a[1] : 0;
    // 如果没有 MemAvailable，则用 MemFree+Buffers+Cached 近似
    if ($available == 0) {
        $free = preg_match('/MemFree:\s+(\d+)/', $meminfo, $f) ? $f[1] : 0;
        $buffers = preg_match('/Buffers:\s+(\d+)/', $meminfo, $b) ? $b[1] : 0;
        $cached = preg_match('/(?:Cached|SReclaimable):\s+(\d+)/', $meminfo, $c) ? $c[1] : 0;
        $available = $free + $buffers + $cached;
    }
    $total_mb = round($total / 1024, 0);
    $available_mb = round($available / 1024, 0);
    $used_mb = $total_mb - $available_mb;
    $percent = ($total_mb > 0) ? ($used_mb / $total_mb) * 100 : 0;
    return [
        'used_mb' => $used_mb,
        'total_mb' => $total_mb,
        'percent' => round($percent, 1)
    ];
}

// 获取磁盘信息（GB）
function getDiskInfo($path) {
    if (!file_exists($path) || !is_dir($path)) {
        return ['error' => true, 'used_gb' => 0, 'total_gb' => 0, 'percent' => 0];
    }
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false) {
        return ['error' => true, 'used_gb' => 0, 'total_gb' => 0, 'percent' => 0];
    }
    $used = $total - $free;
    $total_gb = $total / 1024 / 1024 / 1024;
    $used_gb = $used / 1024 / 1024 / 1024;
    $percent = ($total_gb > 0) ? ($used_gb / $total_gb) * 100 : 0;
    return [
        'error' => false,
        'used_gb' => round($used_gb, 1),
        'total_gb' => round($total_gb, 1),
        'percent' => round($percent, 1)
    ];
}

// 组装响应数据
$cpu = getCpuUsage();
$ram = getMemoryInfo();
$diskRoot = getDiskInfo('/');
$diskSdcard = getDiskInfo('/mnt/sdcard');

$response = [
    'cpu' => $cpu !== null ? ['percent' => $cpu] : ['percent' => 0],
    'ram' => $ram ?: ['used_mb' => 0, 'total_mb' => 0, 'percent' => 0],
    'disks' => [
        [
            'mount' => '/',
            'used_gb' => $diskRoot['used_gb'],
            'total_gb' => $diskRoot['total_gb'],
            'percent' => $diskRoot['percent'],
            'error' => $diskRoot['error']
        ],
        [
            'mount' => '/mnt/sdcard',
            'used_gb' => $diskSdcard['used_gb'],
            'total_gb' => $diskSdcard['total_gb'],
            'percent' => $diskSdcard['percent'],
            'error' => $diskSdcard['error']
        ]
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>