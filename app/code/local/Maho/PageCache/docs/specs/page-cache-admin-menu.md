# 功能规格：Page Cache 一级菜单和 Warm Now

> 状态：已交付@v0.5.5
> 关联 PRD：FR-13、FR-16、FR-18
> 分支：`feat/page-cache-phase2`

## 1. 范围

- Page Cache 从 `Reports > Page Cache` 移到后台一级菜单 `Page Cache`。
- 统计面板改成标准后台 `content-header` 和 `content-buttons`，内容使用四个标准报表 tab 和 grid，带分页、列过滤和 CSV/Excel XML 导出。
- 页面新增 `Warm Now`，处理一个预热队列批次。
- 页面新增 `Configuration`，跳转 `System > Configuration > Page Cache`。
- ACL 保留 `admin/report/page_cache`，避免升级后现有角色失去权限。
- 不新增手工 URL/sitemap 预热表单；手工预热仍用 CLI。

## 2. 设计

### 2.1 菜单

`etc/adminhtml.xml`：

- 一级菜单 `page_cache`，sort_order 85，action `adminhtml/page_cache/stats`。
- 显式 `<resource>report/page_cache</resource>`，菜单权限沿用旧 ACL。
- 删除 `report` 下的菜单节点，ACL 节点保留。

### 2.2 容器和按钮

`Maho_PageCache_Block_Adminhtml_Dashboard` 继承 `Mage_Adminhtml_Block_Widget_Container`：

- header `Page Cache`。
- 子 block `stats` 延迟创建。
- `Configuration` 按钮只在有 `admin/system/config/page_cache` 权限时显示。
- `Warm Now` 是 POST 表单，带 form key，只在 scheduled warmup 开启时显示。

### 2.3 Warm Now

`warmAction()`：

- POST only，ACL 仍为 `report/page_cache`。
- 调用 `Mage::getService('page_cache/warm')->consumeQueue(batchSize)`。
- 成功、空队列、warmup 关闭、异常分别写 admin notice/success/error。
- 完成后重定向回 stats。

## 3. 验收标准

- 后台一级菜单出现 `Page Cache`，`Reports` 下不再出现。
- 打开页面显示标准 content header、按钮、过滤器和 Overview/Trend/Top Missed/Recent Events 四个 grid tab；每个 grid 有分页、列过滤和 CSV/Excel XML 导出。
- 有配置权限时显示 `Configuration`，点击进入 Page Cache 配置。
- scheduled warmup 开启时显示 `Warm Now`；关闭时显示说明并隐藏按钮。
- `Warm Now` 是 POST，缺 form key 被拒绝；队列为空显示 notice。
- 现有角色仅持有 `admin/report/page_cache` 仍能打开页面。
- 现有测试、PHPStan、php-cs-fixer、Rector 通过。

## 4. 实现记录

- 代码：`etc/adminhtml.xml`、`controllers/Adminhtml/PageCacheController.php`、`Block/Adminhtml/Dashboard.php`、`template/maho/pagecache/dashboard.phtml`。
- 测试：`AdminMenuTest`、`ReportGridTest`；手工验证一级菜单、content header、四张 grid、分页/列过滤、CSV/Excel 导出、Warm Now POST 和空队列提示。
- 测试结果：201 passed（575 assertions）。
- 交叉验证：模块 CI run 45391144326 在 SQLite、MySQL、PostgreSQL 三个任务上全部通过（2026-11-24）。
