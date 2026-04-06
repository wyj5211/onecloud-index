<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

error_reporting(0);
ini_set('display_errors', 0);

// 获取系统平均负载 (1,5,15分钟)
function getLoadAverage() {
    $load = @file_get_contents('/proc/loadavg');
    if ($load === false) return null;
    $parts = preg_split('/\s+/', trim($load));
    if (count($parts) >= 3) {
        return [
            'load1' => (float)$parts[0],
            'load5' => (float)$parts[1],
            'load15' => (float)$parts[2]
        ];
    }
    return null;
}

// 获取CPU使用率 (采样间隔100ms)
function getCpuUsage() {
    $stat1 = @file_get_contents('/proc/stat');
    if ($stat1 === false) return null;
    usleep(100000);
    $stat2 = @file_get_contents('/proc/stat');
    if ($stat2 === false) return null;

    preg_match('/cpu\s+(.+)/', $stat1, $m1);
    preg_match('/cpu\s+(.+)/', $stat2, $m2);
    if (empty($m1) || empty($m2)) return null;

    $fields1 = explode(' ', trim($m1[1]));
    $fields2 = explode(' ', trim($m2[1]));
    $tot1 = array_sum($fields1);
    $idle1 = $fields1[3] + ($fields1[4] ?? 0);
    $tot2 = array_sum($fields2);
    $idle2 = $fields2[3] + ($fields2[4] ?? 0);

    $totalDiff = $tot2 - $tot1;
    $idleDiff = $idle2 - $idle1;
    if ($totalDiff <= 0) return 0;
    $usage = (1 - $idleDiff / $totalDiff) * 100;
    return round($usage, 1);
}

// 获取内存信息 (单位 MiB, 1 MiB = 1024 KiB)
function getMemoryInfo() {
    $meminfo = @file_get_contents('/proc/meminfo');
    if (!$meminfo) return null;
    $total = preg_match('/MemTotal:\s+(\d+)/', $meminfo, $t) ? $t[1] : 0;
    $available = preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $a) ? $a[1] : 0;
    if ($available == 0) {
        $free = preg_match('/MemFree:\s+(\d+)/', $meminfo, $f) ? $f[1] : 0;
        $buffers = preg_match('/Buffers:\s+(\d+)/', $meminfo, $b) ? $b[1] : 0;
        $cached = preg_match('/(?:Cached|SReclaimable):\s+(\d+)/', $meminfo, $c) ? $c[1] : 0;
        $available = $free + $buffers + $cached;
    }
    $total_mib = round($total / 1024, 0);   // KB → MiB
    $available_mib = round($available / 1024, 0);
    $used_mib = $total_mib - $available_mib;
    $percent = ($total_mib > 0) ? ($used_mib / $total_mib) * 100 : 0;
    return [
        'used_mib' => $used_mib,
        'total_mib' => $total_mib,
        'percent' => round($percent, 1)
    ];
}

// 获取磁盘信息 (单位 GiB)
function getDiskInfo($path) {
    if (!file_exists($path) || !is_dir($path)) {
        return ['error' => true, 'used_gib' => 0, 'total_gib' => 0, 'percent' => 0];
    }
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false) {
        return ['error' => true, 'used_gib' => 0, 'total_gib' => 0, 'percent' => 0];
    }
    $used = $total - $free;
    $total_gib = $total / 1024 / 1024 / 1024;
    $used_gib = $used / 1024 / 1024 / 1024;
    $percent = ($total_gib > 0) ? ($used_gib / $total_gib) * 100 : 0;
    return [
        'error' => false,
        'used_gib' => round($used_gib, 1),
        'total_gib' => round($total_gib, 1),
        'percent' => round($percent, 1)
    ];
}

$load = getLoadAverage();
$cpu = getCpuUsage();
$ram = getMemoryInfo();
$diskRoot = getDiskInfo('/');
$diskSdcard = getDiskInfo('/mnt/sdcard');

$response = [
    'load' => $load ?: ['load1' => 0, 'load5' => 0, 'load15' => 0],
    'cpu' => $cpu !== null ? ['percent' => $cpu] : ['percent' => 0],
    'ram' => $ram ?: ['used_mib' => 0, 'total_mib' => 0, 'percent' => 0],
    'disks' => [
        [
            'mount' => '/',
            'used_gib' => $diskRoot['used_gib'],
            'total_gib' => $diskRoot['total_gib'],
            'percent' => $diskRoot['percent'],
            'error' => $diskRoot['error']
        ],
        [
            'mount' => '/mnt/sdcard',
            'used_gib' => $diskSdcard['used_gib'],
            'total_gib' => $diskSdcard['total_gib'],
            'percent' => $diskSdcard['percent'],
            'error' => $diskSdcard['error']
        ]
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>