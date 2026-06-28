# scripts/ 入库脚本说明

## 脚本清单

| 脚本 | 用途 | 运行顺序 |
|------|------|---------|
| `sync_from_github.php` | 从待入库目录读取文章JSON，写入帝国CMS数据库 | 第1步 |
| `move_to_published.php` | 入库成功后移动文件到已发布目录，更新索引CSV | 第2步 |

## 使用方法

### 前置条件
- PHP 7.4+
- PDO MySQL 扩展
- 已 git clone 仓库到本地

### 配置
编辑 `sync_from_github.php` 顶部的 `$config` 数组，填入：
- 数据库主机、库名、用户名、密码
- 仓库本地路径
- 帝国CMS表前缀（通常为 `phome_`）

### 运行

```bash
# 第1步：入库
php scripts/sync_from_github.php

# 第2步：移动文件 + 更新索引
php scripts/move_to_published.php

# 第3步：提交到GitHub
git add -A
git commit -m "feat: 文章入库完成"
git push
```

### 输出
- `scripts/import_results.json` — 入库结果记录（哪些成功、哪些失败、对应DB ID）
- 控制台会打印每篇文章的处理状态

## 栏目ID说明

| ID | 栏目名 |
|----|--------|
| 14 | 公司动态 |
| 15 | 行业动态 |

脚本会自动根据文章JSON中的 `classid` 字段分栏写入。
