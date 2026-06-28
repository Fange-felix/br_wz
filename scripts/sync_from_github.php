<?php
/**
 * sync_from_github.php
 * 从 GitHub 拉取待入库文章 JSON，写入帝国CMS数据库
 *
 * 用法：php sync_from_github.php
 * 配置：修改下方 $config 中的数据库连接信息和仓库路径
 *
 * 依赖：PHP 7.4+，PDO MySQL 扩展
 */

// ==================== 配置区 ====================
$config = [
    // 帝国CMS数据库
    'db_host'     => 'localhost',
    'db_name'     => 'diguicms',
    'db_user'     => 'root',
    'db_pass'     => '',
    'db_charset'  => 'utf8mb4',

    // 仓库本地路径（git clone 后的目录）
    'repo_path'   => __DIR__ . '/..',

    // 待入库目录（相对于仓库根目录）
    'pending_dir' => '待入库',

    // 帝国CMS数据表前缀
    'table_pre'   => 'phome_',

    // 发布者用户ID
    'userid'      => 1,

    // 是否审核通过（1=已审核，0=待审核）
    'checked'     => 1,
];

// ==================== 主逻辑 ====================

/**
 * 扫描待入库目录，读取所有 JSON 文章
 */
function scanPendingArticles($repoPath, $pendingDir) {
    $pendingPath = $repoPath . '/' . $pendingDir;
    $articles = [];

    if (!is_dir($pendingPath)) {
        echo "待入库目录不存在: {$pendingPath}\n";
        return $articles;
    }

    // 递归扫描所有子目录（按行业分类）
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pendingPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'json') {
            $filePath = $file->getRealPath();
            $relativePath = str_replace($repoPath . '/', '', str_replace('\\', '/', $filePath));

            $json = file_get_contents($filePath);
            $data = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "[跳过] JSON解析失败: {$relativePath}\n";
                continue;
            }

            if (isset($data['imported_to_db']) && $data['imported_to_db'] === true) {
                echo "[跳过] 已入库: {$relativePath}\n";
                continue;
            }

            $data['_file_path'] = $relativePath;
            $data['_full_path'] = $filePath;
            $articles[] = $data;
        }
    }

    return $articles;
}

/**
 * 将文章写入帝国CMS主表和数据表
 */
function importToCMS($pdo, $config, $article) {
    $classid   = intval($article['classid']);
    $title     = $article['content']['h1'];
    $titlepic  = isset($article['images']['title_pic']) ? $article['images']['title_pic'] : '';
    $newstime  = strtotime($article['created_at']);
    $keyboard  = $article['meta_info']['primary_keyword'];
    if (!empty($article['meta_info']['secondary_keywords'])) {
        $keyboard .= ',' . implode(',', $article['meta_info']['secondary_keywords']);
    }
    $smalltext = $article['meta_info']['meta_description'];
    $newstext  = $article['content']['html_content'];
    $userid    = $config['userid'];
    $checked   = $config['checked'];

    // 验证栏目ID
    if (!in_array($classid, [14, 15])) {
        echo "[错误] 无效的栏目ID: {$classid}，只允许 14 或 15\n";
        return false;
    }

    $tableNews     = $config['table_pre'] . 'ecms_news';
    $tableNewsData = $config['table_pre'] . 'ecms_news_data_' . $classid;

    try {
        $pdo->beginTransaction();

        // 1. 插入主表
        $sqlNews = "INSERT INTO `{$tableNews}` (
            `classid`, `title`, `titlepic`, `newstime`, `keyboard`,
            `smalltext`, `checked`, `istop`, `isgood`, `userid`,
            `onclick`, `totaldown`, `plnum`, `newspath`, `lastdotime`
        ) VALUES (
            :classid, :title, :titlepic, :newstime, :keyboard,
            :smalltext, :checked, 0, 0, :userid,
            0, 0, 0, '', :lastdotime
        )";
        $stmt = $pdo->prepare($sqlNews);
        $stmt->execute([
            ':classid'    => $classid,
            ':title'      => $title,
            ':titlepic'   => $titlepic,
            ':newstime'   => $newstime,
            ':keyboard'   => $keyboard,
            ':smalltext'  => $smalltext,
            ':checked'    => $checked,
            ':userid'     => $userid,
            ':lastdotime' => time(),
        ]);

        $newsId = $pdo->lastInsertId();

        // 2. 插入数据表
        $sqlData = "INSERT INTO `{$tableNewsData}` (
            `id`, `classid`, `newstext`, `infotags`
        ) VALUES (
            :id, :classid, :newstext, :infotags
        )";
        $stmtData = $pdo->prepare($sqlData);
        $stmtData->execute([
            ':id'       => $newsId,
            ':classid'  => $classid,
            ':newstext' => $newstext,
            ':infotags' => $keyboard,
        ]);

        $pdo->commit();

        echo "[成功] 文章ID:{$newsId} 栏目:{$classid} 标题:{$title}\n";
        return $newsId;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "[失败] {$title} - " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * 更新文章 JSON 的入库标记
 */
function updateArticleStatus($filePath, $dbId) {
    $json = file_get_contents($filePath);
    $data = json_decode($json, true);

    $data['imported_to_db'] = true;
    $data['db_id'] = $dbId;
    $data['status'] = '已发布';
    $data['updated_at'] = date('Y-m-d');

    file_put_contents($filePath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ==================== 执行入口 ====================

echo "========================================\n";
echo "  GitHub文章 → 帝国CMS 入库脚本\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// 连接数据库
try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset={$config['db_charset']}";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "数据库连接成功: {$config['db_host']}/{$config['db_name']}\n\n";
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage() . "\n");
}

// 扫描待入库文章
echo "扫描待入库目录...\n";
$articles = scanPendingArticles($config['repo_path'], $config['pending_dir']);

if (empty($articles)) {
    echo "没有待入库的文章。\n";
    exit(0);
}

echo "找到 " . count($articles) . " 篇待入库文章\n\n";

// 逐篇入库
$successCount = 0;
$failCount = 0;
$results = [];

foreach ($articles as $article) {
    echo "--- 处理: {$article['article_id']} ---\n";

    $dbId = importToCMS($pdo, $config, $article);

    if ($dbId) {
        $successCount++;
        updateArticleStatus($article['_full_path'], $dbId);
        $results[] = [
            'article_id' => $article['article_id'],
            'db_id' => $dbId,
            'status' => 'success',
            'file_path' => $article['_file_path'],
        ];
    } else {
        $failCount++;
        $results[] = [
            'article_id' => $article['article_id'],
            'db_id' => null,
            'status' => 'failed',
            'file_path' => $article['_file_path'],
        ];
    }
}

// 汇总
echo "\n========================================\n";
echo "  入库完成\n";
echo "  成功: {$successCount} 篇\n";
echo "  失败: {$failCount} 篇\n";
echo "========================================\n";

// 输出结果JSON（方便后续更新索引）
$resultsFile = __DIR__ . '/import_results.json';
file_put_contents($resultsFile, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "结果已保存: {$resultsFile}\n";
echo "请将此文件发送给小吴，用于更新文章索引CSV。\n";
