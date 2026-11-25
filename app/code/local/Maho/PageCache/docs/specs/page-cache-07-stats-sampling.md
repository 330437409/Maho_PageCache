# 功能规格：Maho_PageCache 0.7.0 Stats 采样模式

> 状态：已实现，待 0.7.0 发版
> 关联 RFC：RFC-007 PERF-03
> 关联 ADR：ADR-0023
> 目标版本：0.7.0 Phase 4

## 1. 背景

`page_cache/stats/enabled` 默认关闭。开启后 `Stat::record()` 先写 PHP 内存 buffer，响应结束或 buffer 达到 50 条时批量写入 `page_cache_stat`。这已经避免每个事件一次 INSERT，但高流量下写入量仍随请求量增长。

本功能增加 `off`、`full`、`sampled` 三种模式：

- `off`：完全不记录。
- `full`：保持现有精确记录。
- `sampled`：按请求采样，减少数据库写入，面板明确标注估算。

## 2. 范围

### 2.1 范围内

- 新增 `page_cache/stats/mode` 和 `page_cache/stats/sample_rate`。
- 保留 `page_cache/stats/enabled` 兼容。
- 请求级采样：同一请求的所有请求事件使用同一个决策。
- Purge、Flush、Warmup 系统事件始终完整记录。
- 后台面板显示当前模式和采样率；sampled 时提示数据为估算。
- 不新增表、不改 schema、不改 rollup 结构。

### 2.2 范围外

- 不引入 Redis 计数器或消息队列。
- 不改 buffer + `insertMultiple()`。
- 不改原始事件保留和小时聚合字段。
- 不对 HIT/MISS/BYPASS 使用不同采样率。
- 不把采样数据反推为精确总量。
- 不改变代理/静态命中不计入 PHP 统计的契约。

## 3. 配置

| 配置 | 默认 | 说明 |
|---|---|---|
| `page_cache/stats/enabled` | 0 | 总开关；0 永远关闭 |
| `page_cache/stats/mode` | `full` | `off` / `full` / `sampled` |
| `page_cache/stats/sample_rate` | 0.05 | 0 到 1；只对 sampled 生效 |

兼容规则：

- `enabled=0` 时 `getStatsMode()` 返回 `off`，无论 mode 是什么。
- `enabled=1` 且 mode 为空或非法时按 `full` 处理，避免旧安装被静默关闭。
- `enabled=1` 且 mode=`off` 表示显式关闭记录，但保留配置，便于恢复。
- `sample_rate` 小于 0 按 0，大于 1 按 1。

## 4. 采样

### 4.1 决策

`Stat` 是请求级 singleton，增加一个 `?bool $sampled` 字段：

```php
private function isSampledRequest(): bool
{
    if ($this->sampled === null) {
        $rate = max(0.0, min(1.0, $this->helper()->getStatsSampleRate()));
        $this->sampled = $rate >= 1.0
            || ($rate > 0.0 && random_int(1, 1_000_000) <= (int) round($rate * 1_000_000));
    }

    return $this->sampled;
}
```

- 决策在第一次 `record()` 时确定。
- 同一请求的 HIT/MISS/BYPASS/REVALIDATED/DYNAMIC 共用这个决策。
- `sample_rate=0` 不记录任何采样请求；`1` 记录全部。
- 采样只影响请求事件；系统事件不调用采样判断。

### 4.2 系统事件

以下事件始终记录，不受采样影响：

- `purge`
- `flush`
- `warmup`

它们代表系统操作，不是流量样本，缺失会误导运维。

### 4.3 记录流程

`Stat::record()`：

1. 读取 mode；`off` 直接返回。
2. 校验事件类型。
3. 判断是否为系统事件。
4. 非系统事件且 mode=`sampled` 时，检查请求采样；未命中直接返回。
5. 按现有逻辑写 buffer，达到 50 条提前 flush。

## 5. Dashboard

`Block\Adminhtml\Dashboard` 增加：

```php
public function getStatsMode(): string;
public function getStatsModeLabel(): string; // Off / Full / Sampled 5%
public function isStatsSampled(): bool;
```

`dashboard.phtml`：

- 状态行显示 `Statistics: Enabled (Full)` 或 `Enabled (Sampled 5%)`。
- sampled 时显示 warning 提示：
  - 数据为估算，不能当作精确总量。
  - Purge、Flush、Warmup 仍完整记录。
- `enabled=0` 时保留现有“statistics disabled”提示。
- 不改变后台 grid 和导出。

## 6. 任务拆分

- [x] `Helper/Data.php`：`getStatsMode()`、`getStatsSampleRate()`。
- [x] `Model/Stat.php`：mode 判断、请求级采样、系统事件豁免。
- [x] `etc/config.xml`：mode、sample_rate 默认值。
- [x] `etc/system.xml`：后台字段和说明。
- [x] `Block/Adminhtml/Dashboard.php`、`dashboard.phtml`：模式显示和采样提示。
- [x] `app/locale/en_US`、`zh_CN`：新增后台文案。
- [x] `docs/adr/0023-stats-sampling.md`：ADR。
- [x] 文档：ARCHITECTURE、API、README、CHANGELOG、RFC 状态。
- [x] 测试：off/full/sampled、系统事件、采样率边界、dashboard 标签。

## 7. 验收标准

### AC-01 模式

- `enabled=0` 时任何 mode 都不记录。
- `enabled=1, mode=full` 保持现有精确记录。
- `enabled=1, mode=off` 不记录，但配置保留。
- `enabled=1, mode` 缺失或非法时按 full，不能静默关闭旧安装。
- `enabled=1, mode=sampled` 按采样率记录。

### AC-02 采样

- `sample_rate=0` 时 sampled 请求事件为零。
- `sample_rate=1` 时所有请求事件记录。
- 同一请求的多个请求事件共用一个决策，不出现 HIT 记录、MISS 不记录。
- 系统事件 `purge`、`flush`、`warmup` 在 sample_rate=0 时仍记录。
- 采样决策不写数据库、不读 store config 多次（每次请求一次即可）。

### AC-03 Dashboard

- off 显示统计关闭。
- full 显示 `Enabled (Full)`。
- sampled 显示 `Enabled (Sampled 5%)` 和估算提示。
- 现有面板、筛选、导出不回归。

### AC-04 回归与隐私

- 统计关闭时零 `page_cache_stat` 写入。
- 统计失败不影响页面响应。
- 不记录客户、cookie、完整 query 或 PII。
- 现有 PageCache 测试全绿。
- PHPStan、php-cs-fixer、Rector 通过。
- SQLite、MySQL、PostgreSQL CI 通过。

## 8. 风险

- 采样数据会让面板的 hit rate 和总量变成估算；必须明确标注，不能用于精确计费或 SLA。
- 采样率太低会让小流量站点统计失去意义；默认 0.05 只建议高流量生产使用。
- 请求级采样不会让同一请求的不同事件分裂，但不同请求的样本量仍随机波动。
- `enabled=1` 旧安装默认 full，会保留原写入量；需要管理员主动切到 sampled。
- 系统事件高频时仍可能产生写入；本 RFC 不做系统事件采样。

## 9. 已确认的选择

1. `enabled=0` 永远关闭；mode 只在 enabled=1 时生效。
2. mode 默认 full，保证旧安装升级后行为不变。
3. 请求级采样，不是按事件采样。
4. 系统事件完整记录。
5. 不新增表、不引入 Redis/队列。
