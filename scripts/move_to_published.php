<?php
/**
 * move_to_published.php
 * 入库成功后，将文章从「待入库」移到「已发布」，并更新文章索引CSV
 *
 * 用法：php move_to_published.php
 * 前置条件：先运行 sync_from_github.php，生成 import_results.json
 */

// ==================== 配置区 ====================
$config = [
    'repo_path'      => __DIR__ . '/..',
    'pending_dir'    => '待入库',
    'published_dir'  => '已发布',
    'results_file'   => __DIR__ . '/import_results.json',
    'index_file'     => __DIR__ . '/../文章索引.csv',
];

// ==================== 主逻辑 ====================

echo "========================================\n";
echo "  文件移动 & 索引更新脚本\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// 读取入库结果
if (!file_exists($config['results_file'])) {
    die("未找到入库结果文件: {$config['results_file']}\n请先运行 sync_from_github.php\n");
}

$results = json_decode(file_get_contents($config['results_file']), true);
$successResults = array_filter($results, fn($r) => $r['status'] === 'success');

if (empty($successResults)) {
    echo "没有成功入库的文章，无需移动。\n";
    exit(0);
}

echo "需要移动 " . count($successResults) . " 篇文章\n\n";

// 移动文件
$movedCount = 0;
foreach ($successResults as $result) {
    $oldPath = $config['repo_path'] . '/' . $result['file_path'];

    // 计算新路径：待入库 → 已发布
    $newPath = str_replace(
        $config['pending_dir'] . '/',
        $config['published_dir'] . '/',
        $oldPath
    );

    if (!file_exists($oldPath)) {
        echo "[跳过] 文件不存在: {$result['file_path']}\n";
        continue;
    }

    // 创建目标目录
    $newDir = dirname($newPath);
    if (!is_dir($newDir)) {
        mkdir($newDir, 0755, true);
    }

    if (rename($oldPath, $newPath)) {
        echo "[移动] {$result['article_id']} → 已发布/ (DB ID: {$result['db_id']})\n";
        $movedCount++;
    } else {
        echo "[失败] 无法移动: {$result['file_path']}\n";
    }
}

echo "\n移动完成: {$movedCount} 篇\n";

// 更新文章索引CSV
echo "\n更新文章索引CSV...\n";

if (file_exists($config['index_file'])) {
    $csvContent = file_get_contents($config['index_file']);

    // 移除BOM（如果有）
    $bom = pack('H*', 'EFBBBF');
    $csvContent = str_replace($bom, '', $csvContent);
    $hasBom = true;

    $lines = explode("\n", $csvContent);
    $header = array_shift($lines);

    // 找到各列的索引
    $columns = str_getcsv($header);
    $colArticleId = array_search('文章编号', $columns);
    $colStatus    = array_search('文章状态', $columns);
    $colPath      = array_search('存放路径', $columns);
    $colImported  = array_search('是否入库', $columns);
    $colDbId      = array_search('入库ID', $columns);
    $colUpdated   = array_search('更新日期', $columns);

    foreach ($lines as &$line) {
        if (empty(trim($line))) continue;
        $fields = str_getcsv($line);

        foreach ($successResults as $result) {
            if ($fields[$colArticleId] === $result['article_id']) {
                $fields[$colStatus]   = '已发布';
                $fields[$colImported] = '是';
                $fields[$colDbId]     = $result['db_id'];
                $fields[$colUpdated]  = date('Y-m-d');
                // 更新路径
                $fields[$colPath] = str_replace(
                    $config['pending_dir'] . '/',
                    $config['published_dir'] . '/',
                    $fields[$colPath]
                );
                $line = implode(',', array_map(fn($f) => strpos($f, ',') !== false ? '"' . $f . '"' : $f, $fields));
                break;
            }
        }
    }

    // 重新组装CSV
    $newCsv = ($hasBom ? $bom : '') . $header . "\n" . implode("\n", $lines);
    file_put_contents($config['index_file'], $newCsv);

    echo "索引CSV已更新\n";
} else {
    echo "[警告] 索引文件不存在: {$config['index_file']}\n";
}

echo "\n========================================\n";
echo "  全部完成！\n";
echo "  请 git add -A && git commit && git push\n";
echo "========================================\n";
