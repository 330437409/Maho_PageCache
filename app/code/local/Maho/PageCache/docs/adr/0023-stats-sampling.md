# ADR-0023：Stats 使用请求级采样，系统事件不采样

## 状态

已接受，随 RFC-007 0.7.0 Phase 4 实现。

## 背景

统计默认关闭，开启后使用内存 buffer 和 `insertMultiple()` 批量写入。高流量生产开启完整统计时，写入量仍随请求量增长。RFC-007 要求增加 `off`、`full`、`sampled` 三种模式，同时保留现有 `enabled` 语义和精确计数能力。

## 决策

- 新增 `page_cache/stats/mode`，取值 `off`、`full`、`sampled`，默认 `full`。
- 新增 `page_cache/stats/sample_rate`，默认 `0.05`，范围 0 到 1。
- `enabled=0` 永远关闭；`enabled=1` 时才读取 mode。
- mode 缺失或非法时按 `full`，避免旧安装升级后被静默关闭。
- 采样决策在请求级确定，同一请求的 HIT/MISS/BYPASS/REVALIDATED/DYNAMIC 共用一个决策。
- `purge`、`flush`、`warmup` 系统事件始终完整记录，不参与采样。
- 采样使用 `random_int()`，不引入 Redis 计数器或消息队列。
- 后台报表显示当前模式和采样率；sampled 时明确标注估算。
- 不新增表、不改 schema、不改 rollup。

## 理由

- 请求级采样避免同一请求的事件被部分记录，HIT/MISS 比例不会因采样粒度产生偏差。
- 系统事件量小但运维价值高，不能因为采样丢失。
- `enabled=0` 已经是事实上的生产默认；mode 默认 full 只为兼容旧安装，避免静默改变行为。
- 现有 buffer + `insertMultiple()` 已经足够；增加 Redis/队列会扩大依赖和故障面。
- 面板标注估算比追求“看起来精确”的采样数据更诚实。

## 后果

- sampled 模式的 hit rate、总量和趋势是估算，不能当精确总量。
- 旧安装 `enabled=1` 仍为 full，需要管理员主动切换到 sampled。
- 系统事件仍可能产生写入，但数量远小于请求事件。
- 采样率过低会让小流量站点报表失去意义。
- 需要新增配置文案、dashboard 提示和测试。

## 备选方案

- **按事件采样**：实现简单，但同一请求可能只记录 HIT 不记录 MISS，比例失真，不采用。
- **Redis 计数器**：写入少，但引入新的强制依赖和状态系统，不符合 0.7.0 范围，不采用。
- **消息队列异步写入**：吞吐高，但增加运维复杂度，不采用。
- **默认 sampled**：会改变旧安装 `enabled=1` 的行为，不采用。
- **系统事件也采样**：会丢失 purge/flush/warmup 的完整记录，不采用。
